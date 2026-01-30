# スケジューラーシステムの取りこぼし対策 - 業界調査

**調査日**: 2026-01-24

## 概要

Laravel Graceful Schedule Worker の拡張設計を検討するにあたり、他の主要なスケジューラーシステムが「取りこぼし（missed executions）」にどう対処しているかを調査しました。

本ドキュメントでは、以下のシステムを比較分析します：

1. Kubernetes CronJob
2. AWS EventBridge Scheduler
3. Apache Airflow
4. Celery Beat

---

## 1. Kubernetes CronJob

### アプローチ: 猶予期間による限定的なリトライ

Kubernetes CronJob は `startingDeadlineSeconds` フィールドで、スケジュール時刻を過ぎてから何秒以内なら起動を許可するかを指定できます。

#### 設定例

```yaml
apiVersion: batch/v1
kind: CronJob
metadata:
  name: hourly-report
spec:
  schedule: "0 * * * *"  # 毎時0分
  startingDeadlineSeconds: 300  # 5分間の猶予
  concurrencyPolicy: Forbid
  successfulJobsHistoryLimit: 3
  failedJobsHistoryLimit: 1
  jobTemplate:
    spec:
      template:
        spec:
          containers:
          - name: report
            image: my-app:latest
            command: ["php", "artisan", "report:hourly"]
          restartPolicy: OnFailure
```

#### 動作の詳細

**正常ケース**:
```
01:00:00 - スケジュール時刻
01:00:05 - Job 起動成功 ✅
```

**猶予期間内の遅延**:
```
01:00:00 - スケジュール時刻
         - ノードがビジー、Pod が起動できない
01:02:30 - ノードに空きができた
         - 現在時刻 01:02:30 < 01:00:00 + 300秒
         - Job 起動 ✅（遅延実行）
```

**猶予期間超過**:
```
01:00:00 - スケジュール時刻
         - クラスタ全体がダウン
01:05:01 - クラスタ復旧
         - 現在時刻 01:05:01 > 01:00:00 + 300秒
         - 01:00 の Job は実行されない ❌
01:00:00 - 次回（02:00）の Job は通常通り実行される
```

#### 100回制限

CronJob Controller は、最後の実行時刻から現在までに何回のスケジュールを逃したかをカウントします。**100回を超えると**、ジョブを起動せずにエラーログを出力します。

```
Cannot determine if <namespace>/<cronjob> needs to be started:
Too many missed start time (> 100).
Set or decrease .spec.startingDeadlineSeconds or check clock skew.
```

#### 重複実行の防止

`concurrencyPolicy` で制御：

- **Allow** (デフォルト): 複数の Job を並行実行可能
- **Forbid**: 前回の Job が実行中なら新しい Job をスキップ
- **Replace**: 前回の Job を停止して新しい Job を起動

#### 制約と注意点

- **クラスタダウン中は完全に失われる**: Kubernetes 自体が停止していると、その間のスケジュールは記録されない
- **"At most once" セマンティクス**: 最大1回の実行を保証するが、正確に1回ではない
- **取りこぼしの自動リカバリなし**: 猶予期間を過ぎたジョブは二度と実行されない
- **時刻同期が重要**: ノード間でクロックスキューがあると誤動作の原因になる

**参考資料**:
- [Kubernetes CronJob Documentation](https://kubernetes.io/docs/concepts/workloads/controllers/cron-jobs/)
- [Fix CronJob missed start time handling PR](https://github.com/kubernetes/kubernetes/pull/81557)
- [Ensure CronJob has a configured deadline - Datree](https://hub.datree.io/built-in-rules/ensure-cronjob-deadline)

---

## 2. AWS EventBridge Scheduler

### アプローチ: リトライポリシー + Dead Letter Queue

EventBridge Scheduler は、**ターゲットの起動失敗時**のリトライに特化しています。

#### RetryPolicy の設定

```json
{
  "Name": "hourly-report",
  "ScheduleExpression": "cron(0 * * * ? *)",
  "Target": {
    "Arn": "arn:aws:ecs:ap-northeast-1:123456789012:cluster/my-cluster",
    "RoleArn": "arn:aws:iam::123456789012:role/EventBridgeSchedulerRole",
    "EcsParameters": {
      "TaskDefinitionArn": "arn:aws:ecs:ap-northeast-1:123456789012:task-definition/report:1",
      "LaunchType": "FARGATE"
    },
    "RetryPolicy": {
      "MaximumRetryAttempts": 185,
      "MaximumEventAgeInSeconds": 86400
    },
    "DeadLetterConfig": {
      "Arn": "arn:aws:sqs:ap-northeast-1:123456789012:scheduler-dlq"
    }
  }
}
```

#### 動作の詳細

**リトライの仕組み**:
```
01:00:00 - スケジュール時刻
         - ECS RunTask API 呼び出し
         - エラー: ThrottlingException（API レート制限）

01:00:01 - 1回目のリトライ（1秒後）
         - エラー: ServiceUnavailableException

01:00:03 - 2回目のリトライ（2秒後、指数バックオフ）
         - エラー: ClusterNotFoundException

01:00:07 - 3回目のリトライ（4秒後）
         - 成功 ✅
```

**最終的な失敗**:
```
01:00:00 - スケジュール時刻
         - ECS RunTask 失敗が継続...

[24時間後 or 185回リトライ後]

02:00:00 (翌日) - 全てのリトライが失敗
                - イベントを DLQ (SQS) に送信
                - アラート発火（CloudWatch Alarms 等）
```

#### 制約と注意点

- **スケジュール自体の取りこぼしは防げない**:
  - 01:00 に起動失敗 → 02:00 のスケジュールは別イベントとして扱われる
  - 01:00 のリトライが 02:00 まで続いても、02:00 のジョブは別途起動される

- **対象は「実行失敗」のみ**:
  - EventBridge Scheduler 自体は高可用性で冗長化されている
  - スケジュールの発火は保証されるが、ターゲットの起動成功は保証されない

- **コスト考慮**:
  - リトライ回数に応じて料金が発生
  - DLQ の SQS メッセージ保持にも料金がかかる

**参考資料**:
- [EventBridge Scheduler - RetryPolicy API Reference](https://docs.aws.amazon.com/scheduler/latest/APIReference/API_RetryPolicy.html)
- [Amazon EventBridge Scheduler User Guide](https://docs.aws.amazon.com/eventbridge/latest/userguide/using-eventbridge-scheduler.html)
- [Configure EventBridge retries and DLQ](https://repost.aws/knowledge-center/eventbridge-resolve-failedinvocation-errors)

---

## 3. Apache Airflow

### アプローチ: Catchup（自動）+ Backfill（手動）

Apache Airflow は、データパイプライン向けのワークフローエンジンで、**過去の未実行タスクを検出・実行する仕組み**を持っています。

#### Catchup（自動リカバリ）

**設定例**:

```python
from datetime import datetime, timedelta
from airflow import DAG
from airflow.operators.bash import BashOperator

default_args = {
    'owner': 'data-team',
    'depends_on_past': False,
    'email_on_failure': True,
    'email_on_retry': False,
    'retries': 1,
    'retry_delay': timedelta(minutes=5),
}

dag = DAG(
    'hourly_report',
    default_args=default_args,
    description='Generate hourly reports',
    schedule_interval='0 * * * *',  # 毎時0分
    start_date=datetime(2024, 1, 1, 0, 0),
    catchup=True,  # ← 重要: デフォルトは False
    max_active_runs=1,
)

task = BashOperator(
    task_id='generate_report',
    bash_command='php /app/artisan report:hourly',
    dag=dag,
)
```

**動作例**:

```
2024-01-01 00:00 - DAG 定義を deploy
                 - start_date = 2024-01-01 00:00
                 - 現在時刻 = 2024-01-10 15:00

→ Airflow Scheduler が検出:
  - 2024-01-01 00:00 ～ 2024-01-10 14:00 が未実行
  - 合計 226 個の DAG Run を作成（10日間 × 24時間 - 10時間）

→ 順次実行（max_active_runs=1 の場合）:
  ✅ 2024-01-01 00:00
  ✅ 2024-01-01 01:00
  ✅ 2024-01-01 02:00
  ...
  ✅ 2024-01-10 14:00
  ✅ 2024-01-10 15:00（現在の実行）
```

**メンテナンス後のシナリオ**:

```
2024-01-05 03:00 - Airflow クラスタをメンテナンスで停止
2024-01-05 05:00 - クラスタ復旧

→ Scheduler が起動後:
  - 最終実行: 2024-01-05 02:00
  - 現在時刻: 2024-01-05 05:00
  - 未実行期間: 03:00, 04:00

→ catchup=True の場合:
  ✅ 2024-01-05 03:00 を実行
  ✅ 2024-01-05 04:00 を実行
  ✅ 2024-01-05 05:00 を実行（現在）

→ catchup=False の場合:
  ❌ 03:00, 04:00 はスキップ
  ✅ 2024-01-05 05:00 のみ実行
```

#### Backfill（手動リカバリ）

**CLI での実行**:

```bash
# 特定期間を再実行
airflow dags backfill \
  --start-date 2024-01-01 \
  --end-date 2024-01-10 \
  hourly_report

# 失敗したタスクのみ再実行
airflow dags backfill \
  --start-date 2024-01-05 \
  --end-date 2024-01-05 \
  --rerun-failed-tasks \
  hourly_report

# ドライラン（実行せずに確認）
airflow dags backfill \
  --start-date 2024-01-01 \
  --end-date 2024-01-10 \
  --dry-run \
  hourly_report
```

**UI での実行（Airflow 3.x）**:

1. DAG ページを開く
2. "Trigger" ボタン → "Backfill" を選択
3. 開始日・終了日を指定
4. 再実行するタスクを選択
5. 実行

#### 実行履歴の管理

Airflow は PostgreSQL または MySQL に以下を記録：

- **DagRun**: 各実行インスタンス
- **TaskInstance**: 各タスクの実行状態
- **execution_date**: そのタスクがカバーするデータ期間

```sql
-- 未実行の DAG Run を検出
SELECT execution_date, state
FROM dag_run
WHERE dag_id = 'hourly_report'
  AND execution_date >= '2024-01-01'
  AND state = 'scheduled';

-- 失敗した TaskInstance を検出
SELECT task_id, execution_date, state
FROM task_instance
WHERE dag_id = 'hourly_report'
  AND state = 'failed';
```

#### 制約と注意点

- **大量の未実行がある場合**:
  - `max_active_runs` を設定しないと、並行実行でリソースを圧迫
  - 数百〜数千の DAG Run を順次実行するため、完了まで時間がかかる

- **データベース依存**:
  - PostgreSQL または MySQL が必須
  - SQLite は本番環境非推奨

- **データ整合性**:
  - `depends_on_past=True` を設定すると、過去の実行が成功していないと次が実行されない
  - データパイプラインには有用だが、独立したジョブには不要

- **コスト**:
  - Airflow クラスタの運用コスト（Scheduler, Webserver, Worker）
  - Managed サービス（AWS MWAA, Google Cloud Composer）は高額

**参考資料**:
- [Airflow DAG Runs Documentation](https://airflow.apache.org/docs/apache-airflow/stable/core-concepts/dag-run.html)
- [Airflow Catchup & Backfill - Demystified](https://medium.com/nerd-for-tech/airflow-catchup-backfill-demystified-355def1b6f92)
- [Understanding the Difference Between Backfill and Catchup](https://medium.com/@seilylook95/understanding-the-difference-between-airflows-backfill-and-catchup-cf6e830588b8)

---

## 4. Celery Beat

### アプローチ: 高可用性の仕組みが存在しない

Celery Beat は、Celery（Python の分散タスクキュー）のスケジューラーコンポーネントですが、**シングルインスタンス前提の設計**となっています。

#### 基本構成

```python
# celery.py
from celery import Celery
from celery.schedules import crontab

app = Celery('tasks', broker='redis://localhost:6379/0')

app.conf.beat_schedule = {
    'hourly-report': {
        'task': 'tasks.generate_report',
        'schedule': crontab(minute=0),  # 毎時0分
    },
}

@app.task
def generate_report():
    # レポート生成処理
    pass
```

```bash
# Celery Worker を起動
celery -A tasks worker --loglevel=info

# Celery Beat を起動（別プロセス）
celery -A tasks beat --loglevel=info
```

#### 問題点1: シングルポイント障害

**構成**:
```
┌─────────────┐
│ Celery Beat │  ← 1つだけ起動可能
└─────────────┘
      │
      ▼
┌─────────────┐
│   Redis     │
└─────────────┘
      │
      ▼
┌─────────────┐  ┌─────────────┐  ┌─────────────┐
│  Worker 1   │  │  Worker 2   │  │  Worker 3   │
└─────────────┘  └─────────────┘  └─────────────┘
```

**問題**:
- Celery Beat が停止すると、全てのスケジュールタスクが実行されない
- Worker は起動していても、タスクがキューに入らない

#### 問題点2: 複数起動すると重複実行

```bash
# 誤って複数の Beat を起動してしまった場合
celery -A tasks beat --loglevel=info  # Beat 1
celery -A tasks beat --loglevel=info  # Beat 2
```

**結果**:
```
01:00:00 - Beat 1 が hourly-report タスクをキューに追加
01:00:00 - Beat 2 も hourly-report タスクをキューに追加
         - Worker 1 が1回目を実行
         - Worker 2 が2回目を実行
         → 重複実行 ❌
```

#### 問題点3: 取りこぼしの検出なし

```
01:00:00 - Beat がダウン
01:30:00 - Beat 復旧
         - 01:00 のタスクは実行されない ❌
         - 02:00 から通常通り実行される
         - 過去の未実行を検出する仕組みなし
```

#### コミュニティの回避策

##### 1. Leader Election（リーダー選出）

Redis や ZooKeeper を使用して、複数の Beat インスタンスから1つをリーダーとして選出：

```python
# redbeat を使用した例
from redbeat.schedulers import RedBeatScheduler

app.conf.beat_scheduler = 'redbeat.schedulers:RedBeatScheduler'
app.conf.redbeat_redis_url = 'redis://localhost:6379/1'
app.conf.redbeat_lock_timeout = 30
```

**動作**:
- 複数の Beat インスタンスが起動
- Redis のロックを取得したインスタンスがリーダーになる
- リーダーがダウンすると、別のインスタンスが引き継ぐ

**制約**:
- フェイルオーバーに時間がかかる（ロックタイムアウトまで待つ）
- 新リーダーが過去の未実行を検出できない

##### 2. Redlock（分散ロック）

全ての Beat インスタンスが動作するが、タスクをキューに追加する際に分散ロックを取得：

```python
from redis import Redis
from redlock import Redlock

redis_client = Redis(host='localhost', port=6379)
dlm = Redlock([{"host": "localhost", "port": 6379, "db": 0}])

@app.on_after_configure.connect
def setup_periodic_tasks(sender, **kwargs):
    sender.add_periodic_task(
        crontab(minute=0),
        hourly_report_with_lock.s(),
    )

@app.task
def hourly_report_with_lock():
    lock_key = 'celery:beat:hourly-report'
    lock = dlm.lock(lock_key, 60000)  # 60秒のロック

    if lock:
        try:
            generate_report()
        finally:
            dlm.unlock(lock)
    else:
        # 別のインスタンスが実行中
        pass
```

**制約**:
- 実装が複雑
- ロック管理のオーバーヘッド

##### 3. 外部スケジューラ（推奨）

Celery Beat を使わず、Kubernetes CronJob や EventBridge から Celery タスクを直接呼び出す：

```yaml
# Kubernetes CronJob で Celery タスクを起動
apiVersion: batch/v1
kind: CronJob
metadata:
  name: hourly-report
spec:
  schedule: "0 * * * *"
  jobTemplate:
    spec:
      template:
        spec:
          containers:
          - name: trigger
            image: my-app:latest
            command:
            - python
            - -c
            - |
              from tasks import generate_report
              generate_report.apply_async()
          restartPolicy: OnFailure
```

**メリット**:
- Kubernetes の高可用性を活用
- Celery Beat のシングルポイント障害を回避

#### 制約と注意点

- **公式の高可用性サポートなし**: Celery Beat は元々シングルインスタンス前提
- **取りこぼし検出なし**: 再起動後に過去の未実行を検出する仕組みがない
- **コミュニティソリューションの複雑さ**: redbeat や Redlock は追加の学習コストがかかる
- **本番環境での推奨**: Celery Beat を避け、外部スケジューラを使用

**参考資料**:
- [Celery Beat High Availability Issue #1495](https://github.com/celery/celery/issues/1495)
- [Distributed Scheduling Gone Wrong: The Celery Beat Trap](https://medium.com/@sudarshaana/distributed-scheduling-gone-wrong-the-celery-beat-trap-and-how-we-escaped-85c7e53828f6)
- [Question: missed schedules in celery beat](https://github.com/celery/celery/issues/6124)

---

## 比較表

| システム | 取りこぼし検出 | 自動リカバリ | 高可用性 | 実装難易度 | 用途 |
|---------|--------------|------------|---------|-----------|------|
| **Kubernetes CronJob** | ⚠️ 部分的<br>（猶予期間のみ） | ❌ なし | ✅ あり<br>（k8s の冗長性） | 中 | インフラタスク |
| **EventBridge Scheduler** | ❌ なし<br>（実行失敗のみリトライ） | ⚠️ 部分的<br>（実行失敗のみ） | ✅ あり<br>（AWS マネージド） | 低 | イベント駆動 |
| **Apache Airflow** | ✅ あり<br>（catchup） | ✅ あり<br>（backfill） | ✅ あり<br>（クラスタ構成） | 高 | データパイプライン |
| **Celery Beat** | ❌ なし | ❌ なし | ❌ なし<br>（シングルインスタンス） | 低（基本）<br>高（HA化） | 軽量タスクキュー |

---

## 結論

1. **完全な保証は困難**: どのシステムも「正確に1回」の実行を保証していない
2. **トレードオフ**: 実装の複雑さとリカバリの確実性のバランスが重要
3. **用途に応じた選択**: データパイプラインと単純なタスク実行では要件が異なる