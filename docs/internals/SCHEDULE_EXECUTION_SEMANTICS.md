# スケジュール実行セマンティクス - 取りこぼし問題の理論的整理

**作成日**: 2026-01-24
**対象**: Laravel Graceful Schedule Worker の拡張設計における実行保証の理論的基盤

---

## 目次

1. [問題の定義](#問題の定義)
2. [実行セマンティクスの理論的基盤](#実行セマンティクスの理論的基盤)
3. [問題が発生する根本原因](#問題が発生する根本原因)
4. [At-least-once を実現するための要件](#at-least-onceを実現するための要件)
5. [トレードオフの整理](#トレードオフの整理)
6. [業界の先行事例](#業界の先行事例)
7. [責任分担の明確化](#責任分担の明確化)
8. [次のステップ](#次のステップ)

---

## 問題の定義

### 達成したいゴール

**At-least-once セマンティック**: スケジュールされたジョブが最低1回は実行されることを保証したい

### 現状

**At-most-once セマンティック**: ジョブは最大1回実行されるが、実行されないこともある

**具体的な問題**:
- ECS タスク入れ替わり時にスケジューラが不在となり、その間のジョブが実行されない
- Laravel の `schedule:run` は「現在時刻」のみを見て判定するため、過去の未実行を検出できない
- グレースフルシャットダウン時に未実行のタスクが失われる

---

## 実行セマンティクスの理論的基盤

分散システムにおける実行保証は、以下の3つに分類される。

### 1. At-most-once（最大1回）

**定義**: ジョブは実行されるかもしれないし、されないかもしれない。重複実行は絶対にない。

**特徴**:
- 「撃ちっ放し（Fire-and-forget）」とも呼ばれる
- 最もシンプルな実装
- データ欠損が許容される場合に使用

**実装例**:
```
1. ジョブをキューに追加
2. 完了を待たずに次の処理へ
3. キューが失われる可能性を許容
```

**用途**:
- データ欠損が許容されるログ収集
- メトリクス送信（多少のデータポイント欠損は許容）
- ベストエフォート通知

**現状の Laravel Graceful Worker**:
```php
// GracefulScheduleWorkCommand の現在の動作
while ($running) {
    if (Carbon::now()->second === 0) {
        // 現在時刻がスケジュールにマッチするかチェック
        // 実行されなかった過去のジョブは検出されない
        $process = Process::fromShellCommandline('schedule:run');
        $process->start();
    }
}
// → At-most-once セマンティック
```

### 2. At-least-once（少なくとも1回）

**定義**: ジョブは必ず成功するまで再試行される。ACK（確認応答）の喪失により、稀に重複実行される可能性がある。

**特徴**:
- クラウドネイティブシステムのデフォルト
- 実行確認（ACK）を待つ仕組みが必要
- 重複実行の可能性を許容

**実装例**:
```
1. ジョブをキューに追加
2. 実行完了の ACK を受信するまでリトライ
3. ACK が喪失した場合、ジョブは再度実行される
   → 重複実行が発生する可能性
```

**用途**:
- 一般的なバッチ処理
- データパイプライン
- トランザクション処理（冪等性が保証されている場合）

**重複実行が発生するシナリオ**:
```
[01:00:00] ジョブ A を実行開始
[01:00:30] ジョブ A が完了
[01:00:31] ACK を送信
[01:00:31] ネットワーク障害で ACK が届かない
[01:00:35] スケジューラが「タイムアウト」と判断
[01:00:36] ジョブ A を再実行（重複実行）
```

### 3. Exactly-once（正確に1回）

**定義**: ジョブは重複なく、かつ欠落なく実行される。

**特徴**:
- 理想的だが、分散システムでは非常に高コスト
- 完全な Exactly-once は理論的に不可能（Two Generals' Problem）
- 実際には「At-least-once + 冪等性」として実装される

**実装方法**:

#### A. トランザクションベース
```
1. ジョブの実行を分散トランザクションで管理
2. 2フェーズコミット（2PC）で一貫性を保証
3. 非常に高コスト（パフォーマンス劣化）
```

#### B. 冪等性による実現（一般的）
```
1. At-least-once で実装（重複実行を許容）
2. ジョブ自体を冪等に設計
3. 結果的に「一度だけ実行されたのと同じ状態」を実現
```

**用途**:
- 金融トランザクション
- 決済処理
- データベースのマイグレーション

**冪等性の実装例**:

```php
// 冪等でない例（危険）
DB::table('balances')->increment('amount', 100);
// → 重複実行されると残高が200増えてしまう

// 冪等な例（安全）
DB::table('transactions')
    ->updateOrInsert(
        [
            'job_id' => $jobId,
            'execution_date' => $dueAt,  // ← ユニークキー
        ],
        [
            'amount' => 100,
            'processed_at' => now(),
        ]
    );
// → 重複実行されても、同じレコードが更新されるだけ
```

### 重要な洞察

> **クラウドネイティブなジョブスケジューラの実行保証とは、多くの場合「冪等なジョブ設計を前提とした、信頼性の高い再試行メカニズムと状態収束の提供」と同義となる**

つまり:
- **スケジューラの責任**: At-least-once を確実に提供（リソースがある限り必ず起動、クラッシュしたら再起動）
- **アプリケーションの責任**: 冪等性による Exactly-once の結果保証（同じ入力で二度実行されてもデータが壊れない）

---

## 問題が発生する根本原因

### 1. Laravel スケジューラの設計上の制約

Laravel の `schedule:run` は「現在時刻」ベースで設計されている。

**Laravel のソースコード（概念）**:

```php
// Illuminate/Console/Scheduling/Schedule.php
public function dueEvents($app)
{
    return collect($this->events)->filter->isDue($app);
}

// Illuminate/Console/Scheduling/Event.php
protected function expressionPasses()
{
    $date = Date::now();  // ← 現在時刻のみ
    return CronExpression::factory($this->expression)->isDue($date);
}
```

**制約**:
- 過去の未実行を検出する仕組みがない
- 実行履歴を記録・参照しない
- 各実行が独立しており、前回の状態を知らない

**対比: Kubernetes の宣言的モデル**:

```
Kubernetes のアプローチ:
- 「あるべき状態（Desired State）」を宣言
- 「現在の状態（Actual State）」を永続的に監視
- 差異を埋めるように動作（Reconciliation Loop）

Laravel のアプローチ:
- 手続き型（Imperative）
- 現在時刻でのスケジュール判定のみ
- 過去の状態を記録しない
```

### 2. コンテナ入れ替わり時のギャップ

ECS/Kubernetes 環境でデプロイ時にスケジューラが不在になる瞬間が発生する。

**タイムライン例**:

```
00:00:00 - 旧コンテナで schedule:run 実行（hourly ジョブ実行）
00:30:00 - デプロイ開始、旧コンテナに SIGTERM
         - $running = false に設定
         - 新しい schedule:run は起動しない（グレースフルシャットダウン中）

01:00:00 - スケジューラ不在（hourly ジョブの実行時刻）
         - この時点では旧コンテナは終了済み
         - 新コンテナはまだ起動していない
         - 01:00 の hourly ジョブは誰も実行しない ❌

01:05:00 - 新コンテナ起動、schedule:run 開始
         - isDue() は 01:05 の時刻で判定
         - hourly (0 * * * *) は 01:00～01:00:59 の間のみ true
         - 01:05 の時点では false
         - 01:00 の hourly ジョブは永久に実行されない ❌

02:00:00 - 次の hourly ジョブは正常に実行される
```

**問題の本質**:
- スケジューラの不在期間が発生する
- 不在期間中のスケジュールは検出されない
- 復旧後も過去の未実行を検出する仕組みがない

### 3. CronExpression の「分単位」判定

`dragonmantank/cron-expression` の `isDue()` メソッドは秒を切り捨てて「分」単位で判定する。

**動作例**:

```php
use Cron\CronExpression;

$cron = CronExpression::factory('0 * * * *'); // hourly

// 01:00:00～01:00:59 の間
$cron->isDue('2024-01-01 01:00:00'); // → true
$cron->isDue('2024-01-01 01:00:30'); // → true
$cron->isDue('2024-01-01 01:00:59'); // → true

// 01:01:00 以降
$cron->isDue('2024-01-01 01:01:00'); // → false
$cron->isDue('2024-01-01 01:01:01'); // → false
```

つまり、**その分（60秒間の猶予）を逃すと二度と検出されない**。

**Laravel での影響**:

```php
// GracefulScheduleWorkCommand
if (Carbon::now()->second === 0) {
    // second === 0 の瞬間（1秒間）しかチェックされない
    // hourly ジョブは 01:00:00 の1秒間のみ検出される
    $process->start();
}
```

実際には、`second === 0` でなくても `isDue()` は 01:00:00～01:00:59 の間は `true` を返すが、現在の実装では秒が 0 の時しかチェックされない。

### 4. グレースフルシャットダウン時の取りこぼし

**シナリオ**:

```
00:59:50 - schedule:graceful-work 実行中
00:59:55 - SIGTERM 受信
         - $running = false に設定
         - 新しい schedule:run は起動しない

01:00:00 - この時刻に hourly ジョブが実行されるべき
         - しかし while ($running) が false なのでループを抜ける
         - 01:00 のジョブは実行されない ❌

01:00:05 - プロセス終了

01:05:00 - 新コンテナ起動
         - 01:00 のジョブは永久に実行されない ❌
```

**根本原因**:
- グレースフルシャットダウン中は新しいジョブを起動しない
- 終了前の最後の分のジョブが失われる
- 次回起動時も過去の未実行を検出しない

---

## At-least-once を実現するための要件

### 要件1: 実行履歴の永続化

「いつ、何を実行したか」を記録する必要がある。

**必要な情報**:

```php
[
    'event_id' => 'hash(command + cron_expression)',  // イベントの一意識別子
    'last_executed_due' => '2024-01-01 01:00:00',    // 最終実行時刻（実行予定時刻ベース）
    'next_due' => '2024-01-01 02:00:00',             // 次回実行予定時刻
]
```

**実装例（Redis）**:

```php
// 実行を記録
$cache->put(
    "schedule:executed:{$eventId}",
    $dueAt->timestamp,
    now()->addDays(7)
);

// 最終実行時刻を取得
$lastDueTimestamp = $cache->get("schedule:executed:{$eventId}");
$lastDue = Carbon::createFromTimestamp($lastDueTimestamp);
```

### 要件2: 取りこぼしの検出

起動時に「実行されるべきだったが、されていないジョブ」を検出する。

**検出ロジック**:

```
1. 最終実行時刻を取得（last_executed_due）
2. Cron 式から次回実行予定時刻を計算（next_due）
3. 現在時刻（now）と比較
4. next_due < now なら取りこぼし
```

**実装例**:

```php
public function wasMissed(Event $event, Carbon $now): bool
{
    $lastDue = $this->getLastExecutedDue($event);

    if ($lastDue === null) {
        return false; // 初回実行
    }

    // Cron 式から次回実行予定時刻を計算
    $nextDue = $this->calculateNextDue($event, $lastDue);

    // 現在時刻が次回実行予定時刻を過ぎている場合は取りこぼし
    return $nextDue && $now->greaterThan($nextDue->addMinutes(1));
}
```

**計算例**:

```
hourly ジョブ (0 * * * *) の場合:

最終実行: 2024-01-01 01:00:00
次回予定: 2024-01-01 02:00:00
現在時刻: 2024-01-01 02:05:00

→ 02:05 > 02:00 → 取りこぼし検出 ✅
```

### 要件3: 効率的なフィルタリング

スケジュール数が多い場合でも、取りこぼしを高速に検出する。

**非効率な実装（O(n)）**:

```php
// 全イベントをループしてチェック
foreach ($schedule->events() as $event) {
    if ($this->wasMissed($event, $now)) {
        $this->recover($event);
    }
}
```

**効率的な実装（O(log n + m)）**:

Redis Sorted Set を使用:

```php
// 実行時に次回予定時刻でスコアを設定
$redis->zadd(
    'schedule:next_due',
    $nextDue->timestamp,
    $eventId
);

// 取りこぼし検出（現在時刻より前の予定のみ取得）
$missedEvents = $redis->zrangebyscore(
    'schedule:next_due',
    '-inf',
    $now->timestamp
);
// → インデックスで高速に抽出
```

**パフォーマンス比較**:

| スケジュール数 | O(n) | O(log n + m) |
|--------------|------|--------------|
| 10 | 10ms | 1ms |
| 100 | 100ms | 5ms |
| 1,000 | 1,000ms | 10ms |
| 10,000 | 10,000ms | 20ms |

### 要件4: 冪等性の確保（アプリケーション側）

At-least-once では重複実行の可能性があるため、ジョブ自体が冪等である必要がある。

**冪等でない例（危険）**:

```php
// 毎回残高を増やす → 重複実行で二重課金
class PaymentJob
{
    public function handle()
    {
        DB::table('balances')->increment('amount', 100);
    }
}
```

**冪等な例（安全）**:

```php
// トランザクション ID で重複排除
class PaymentJob
{
    private $transactionId;
    private $executionDate;

    public function handle()
    {
        DB::table('transactions')
            ->updateOrInsert(
                [
                    'job_id' => $this->transactionId,
                    'execution_date' => $this->executionDate,
                ],
                [
                    'amount' => 100,
                    'processed_at' => now(),
                ]
            );
        // → 重複実行されても同じレコードが更新されるだけ
    }
}
```

**決定論的な名前付け**:

```php
// ジョブの実行ごとに一意の ID を生成
$executionId = hash('sha256', $command . $dueAt->toIso8601String());

// Step Functions の実行名として使用
$this->client->startExecution([
    'stateMachineArn' => $this->stateMachineArn,
    'name' => $executionId,  // ← 同じ入力なら同じ名前
    // ...
]);
// → AWS 側で重複実行を防止（同じ名前の実行は拒否される）
```

### 要件5: 重複実行の防止メカニズム

Kubernetes の Finalizers や楽観的ロックに相当する仕組み。

#### Finalizers パターン（Kubernetes）

```
1. 実行開始前にロックを取得
2. 実行完了後にステータスを更新
3. ステータス更新が永続化されたことを確認
4. ロックを解放
```

**実装例（Redis ロック）**:

```php
public function executeWithLock(Event $event, Carbon $dueAt): void
{
    $lockKey = "schedule:lock:{$eventId}:{$dueAt->timestamp}";

    // ロック取得（30秒間）
    $lock = Cache::lock($lockKey, 30);

    if ($lock->get()) {
        try {
            // ジョブ実行
            $this->dispatchEvent($event, $dueAt);

            // 実行を記録
            $this->tracker->markExecuted($event, $dueAt);
        } finally {
            // ロック解放
            $lock->release();
        }
    } else {
        // 他のインスタンスが実行中
        Log::info("Job already running: {$eventId}");
    }
}
```

#### 楽観的ロック（ResourceVersion）

Kubernetes etcd のアプローチ:

```
1. 読み取った時点のバージョンをリクエストに含める
2. 他のクライアントが先に更新した場合は競合エラー
3. リトライして再取得
```

**実装例（DynamoDB の条件付き書き込み）**:

```php
// バージョン付きで実行記録を保存
$dynamodb->putItem([
    'TableName' => 'schedule_executions',
    'Item' => [
        'event_id' => $eventId,
        'due_at' => $dueAt->timestamp,
        'version' => $currentVersion + 1,
    ],
    'ConditionExpression' => 'version = :current_version',
    'ExpressionAttributeValues' => [
        ':current_version' => $currentVersion,
    ],
]);
// → 他のインスタンスが先に更新した場合は ConditionalCheckFailedException
```

---

## トレードオフの整理

### 複雑さ vs 確実性

| アプローチ | 複雑さ | 確実性 | 適用場面 |
|-----------|-------|--------|---------|
| 現状維持<br>（At-most-once） | 低 | 低 | 取りこぼし許容<br>ログ収集、メトリクス送信 |
| 起動時のみチェック<br>（部分的 At-least-once） | 中 | 中 | デプロイ時のギャップを最小化<br>一般的なバッチ処理 |
| 常時チェック<br>（完全な At-least-once） | 高 | 高 | データパイプライン<br>金融トランザクション |

### チェック頻度 vs コスト

| 頻度 | コスト | メリット | デメリット |
|------|-------|---------|-----------|
| 起動時のみ | 低 | シンプル<br>デプロイ時の取りこぼしを検出 | 実行中のダウンタイムは検出できない |
| 毎分 | 中 | 実行中のダウンタイムも検出 | メインループに影響 |
| 非同期（5分おき） | 中 | メインプロセスに影響なし | 検出に遅延 |

**推奨**: 起動時のみチェック（コストと確実性のバランス）

**理由**:
- デプロイ時のギャップが主な問題
- 実行中のダウンタイムは ECS/Kubernetes の healthcheck で検出可能
- シンプルで理解しやすい

### スケジュール数 vs データ構造

| スケジュール数 | 推奨データ構造 | 理由 |
|--------------|---------------|------|
| ～100 | 単純な key-value<br>（毎回ループ） | シンプル、オーバーヘッド小 |
| 100～1,000 | Redis Sorted Set<br>（インデックス） | O(log n) で高速検索 |
| 1,000～ | 専用 DB + GSI<br>（DynamoDB） | 永続性、クエリ最適化 |

### 整合性モデル vs パフォーマンス

| モデル | 整合性 | パフォーマンス | 例 | トレードオフ |
|-------|-------|--------------|-----|------------|
| 楽観的ロック | 中 | 高 | Kubernetes etcd | 競合時にリトライが必要 |
| 悲観的ロック | 高 | 低 | Airflow の行ロック | ロック待ちでスループット低下 |
| 結果整合性 | 低 | 高 | 最終的に収束 | 一時的な不整合を許容 |

**推奨**: 楽観的ロック（Redis Lock または DynamoDB 条件付き書き込み）

---

## 業界の先行事例

### 比較表

| システム | 取りこぼし対策 | 整合性モデル | データ構造 | 実装難易度 |
|---------|--------------|-------------|-----------|-----------|
| **Kubernetes CronJob** | startingDeadlineSeconds<br>（猶予期間） | etcd + 楽観的ロック | etcd（分散KVS） | 中 |
| **AWS EventBridge** | リトライポリシー + DLQ | 内部管理<br>（マネージド） | 内部管理 | 低 |
| **Apache Airflow** | catchup + backfill | DB 行ロック<br>（悲観的） | PostgreSQL/MySQL | 高 |
| **Argo Workflows** | Memoization + Finalizers | etcd + 楽観的ロック | etcd（分散KVS） | 高 |
| **Celery Beat** | なし<br>（シングルポイント障害） | なし | なし | 低（基本）<br>高（HA化） |

### Airflow の catchup（最も At-least-once に近い）

**特徴**:
- 実行履歴を DB に永続化
- 起動時に未実行期間を検出
- 順次リカバリ実行

**動作例**:

```python
# DAG 定義
dag = DAG(
    'hourly_report',
    schedule_interval='0 * * * *',
    start_date=datetime(2024, 1, 1, 0, 0),
    catchup=True,  # ← 重要
)

# デプロイ時刻: 2024-01-10 15:00
# → 2024-01-01 00:00 ～ 2024-01-10 14:00 の全てを順次実行
```

**Laravel への応用**:

```php
// ExecutionTracker の実装
public function checkMissedEvents(Schedule $schedule, Carbon $now): void
{
    foreach ($schedule->events() as $event) {
        $lastDue = $this->getLastExecutedDue($event);

        if ($lastDue === null) {
            continue; // 初回実行
        }

        // 最終実行から現在までの全ての実行予定時刻を計算
        $missedDues = $this->calculateMissedDues($event, $lastDue, $now);

        foreach ($missedDues as $missedDue) {
            // 順次リカバリ実行
            $this->dispatchEvent($event, $missedDue);
        }
    }
}
```

### Argo Workflows の Memoization

**特徴**:
- ステップの入力パラメータのハッシュをキーにキャッシュ
- 障害復旧時の重複実行を避ける
- 「副作用を伴う計算をスキップすることで、結果的に一度だけ実行されたのと同じ状態を再現する」

**実装例**:

```yaml
apiVersion: argoproj.io/v1alpha1
kind: Workflow
metadata:
  name: hourly-report
spec:
  entrypoint: main
  templates:
  - name: main
    memoize:
      key: "{{inputs.parameters.execution-date}}"
      cache:
        configMap:
          name: workflow-memoization
    inputs:
      parameters:
      - name: execution-date
    container:
      image: my-app:latest
      command: ["php", "artisan", "report:hourly"]
```

**動作**:

```
1回目の実行:
- execution-date: 2024-01-01 01:00
- キャッシュキー: hash("2024-01-01 01:00")
- キャッシュミス → 実行
- 結果をキャッシュに保存

2回目の実行（リトライ）:
- execution-date: 2024-01-01 01:00
- キャッシュキー: hash("2024-01-01 01:00")
- キャッシュヒット → スキップ（キャッシュから結果を返す）
```

**Laravel への応用**:

```php
// 決定論的な実行名を使用
$executionName = hash('sha256', $event->command . $dueAt->toIso8601String());

// Step Functions で実行
try {
    $this->client->startExecution([
        'stateMachineArn' => $this->stateMachineArn,
        'name' => $executionName,  // ← 同じ名前なら拒否される
        'input' => json_encode([/* ... */]),
    ]);
} catch (ExecutionAlreadyExistsException $e) {
    // 既に実行済み → スキップ
    Log::info("Execution already exists: {$executionName}");
}
```

### Kubernetes の Finalizers

**特徴**:
- リソース削除時に、特定の処理が完了するまで削除を保留
- クリーンアップ処理の確実な実行を保証

**動作**:

```yaml
apiVersion: v1
kind: Pod
metadata:
  name: my-pod
  finalizers:
  - cleanup.example.com/finalizer
spec:
  containers:
  - name: app
    image: my-app:latest
```

```
1. Pod 削除リクエスト
2. metadata.deletionTimestamp が設定される
3. Finalizer が残っている間は削除されない
4. Controller がクリーンアップ処理を実行
5. Finalizer を削除
6. Pod が削除される
```

**Laravel への応用**:

```php
// 実行開始時に Finalizer を追加
$this->tracker->addFinalizer($event, $dueAt, 'execution');

try {
    // ジョブ実行
    $this->dispatchEvent($event, $dueAt);

    // 完了を記録
    $this->tracker->markExecuted($event, $dueAt);
} finally {
    // Finalizer を削除
    $this->tracker->removeFinalizer($event, $dueAt, 'execution');
}

// 起動時に Finalizer が残っているジョブを検出
$pendingJobs = $this->tracker->getJobsWithFinalizers();
foreach ($pendingJobs as $job) {
    // 未完了のジョブを再実行
    $this->dispatchEvent($job['event'], $job['due_at']);
}
```

---

## 責任分担の明確化

### シフトレフトの考え方

実行保証の責任をスケジューラだけでなく、アプリケーション設計側にも委譲する。

```
┌─────────────────────────────────────────────────────────────┐
│                    スケジューラの責任                        │
│                                                             │
│  - At-least-once を確実に提供                               │
│  - リソースがある限り必ず起動                                │
│  - クラッシュしたら再起動                                    │
│  - 取りこぼしを検出してリカバリ実行                          │
│  - 実行履歴の永続化                                         │
│  - 重複実行の防止（ロック機構）                              │
│                                                             │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│                 アプリケーションの責任                       │
│                                                             │
│  - 冪等性の確保                                             │
│  - 同じ入力で二度実行されてもデータが壊れない                 │
│  - 決定論的な名前付け（重複排除）                            │
│  - トランザクション境界の適切な設計                          │
│  - ビジネスロジックの整合性保証                              │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### スケジューラの責任

#### 1. At-least-once の提供

```php
// 起動時に取りこぼしを検出
public function boot(): void
{
    $now = Carbon::now();

    foreach ($this->schedule->events() as $event) {
        if ($this->tracker->wasMissed($event, $now)) {
            // 取りこぼしを再実行
            $missedDue = $this->tracker->getLastExecutedDue($event);
            $this->dispatchEvent($event, $missedDue);
        }
    }
}
```

#### 2. 実行履歴の永続化

```php
// 実行後に記録
public function dispatchEvent(Event $event, Carbon $dueAt): void
{
    $this->client->startExecution([/* ... */]);

    // 実行を記録
    $this->tracker->markExecuted($event, $dueAt);
}
```

#### 3. 重複実行の防止

```php
// ロックを使用
public function dispatchEvent(Event $event, Carbon $dueAt): void
{
    $lock = Cache::lock("schedule:{$eventId}:{$dueAt->timestamp}", 30);

    if ($lock->get()) {
        try {
            $this->client->startExecution([/* ... */]);
            $this->tracker->markExecuted($event, $dueAt);
        } finally {
            $lock->release();
        }
    }
}
```

### アプリケーションの責任

#### 1. 冪等性の確保

```php
// 悪い例
class ReportJob
{
    public function handle()
    {
        // 毎回カウントアップ → 重複実行で二重カウント
        DB::table('stats')->increment('count');
    }
}

// 良い例
class ReportJob
{
    private $executionDate;

    public function handle()
    {
        // 実行日をキーに updateOrInsert → 冪等
        DB::table('daily_reports')
            ->updateOrInsert(
                ['date' => $this->executionDate],
                ['count' => $this->calculateCount()]
            );
    }
}
```

#### 2. トランザクション境界の設計

```php
class PaymentJob
{
    public function handle()
    {
        DB::transaction(function () {
            // 1. 重複チェック
            $exists = DB::table('transactions')
                ->where('transaction_id', $this->transactionId)
                ->exists();

            if ($exists) {
                Log::info("Transaction already processed: {$this->transactionId}");
                return;
            }

            // 2. トランザクション記録
            DB::table('transactions')->insert([
                'transaction_id' => $this->transactionId,
                'amount' => $this->amount,
                'processed_at' => now(),
            ]);

            // 3. 残高更新
            DB::table('balances')->increment('amount', $this->amount);
        });
    }
}
```

#### 3. ビジネスロジックの整合性

```php
class InvoiceGenerationJob
{
    public function handle()
    {
        // 期間ごとの一意性を保証
        $invoiceId = hash('sha256', $this->customerId . $this->period);

        DB::table('invoices')
            ->updateOrInsert(
                [
                    'invoice_id' => $invoiceId,
                    'customer_id' => $this->customerId,
                    'period' => $this->period,
                ],
                [
                    'amount' => $this->calculateAmount(),
                    'generated_at' => now(),
                ]
            );
    }
}
```

---

## 次のステップ

### 決定すべき事項

#### 1. チェック頻度

- **推奨**: 起動時のみ（コストと確実性のバランス）
- **代替案**: 定期的（5分おき）にバックグラウンドでチェック

#### 2. データ構造

**スケジュール数が少ない場合（～100）**:
```php
// 単純な key-value
$cache->put("schedule:executed:{$eventId}", $dueAt->timestamp);
```

**スケジュール数が多い場合（100～1,000）**:
```php
// Redis Sorted Set
$redis->zadd('schedule:next_due', $nextDue->timestamp, $eventId);
```

**スケジュール数が非常に多い場合（1,000～）**:
```php
// DynamoDB + GSI
// GSI: next_due-index
```

#### 3. ストレージ

| ストレージ | メリット | デメリット | 推奨シーン |
|-----------|---------|-----------|-----------|
| Redis | 高速、シンプル | 永続性に懸念 | 一般的なユースケース |
| DynamoDB | 永続性、スケーラビリティ | コスト、レイテンシ | 大規模、長期保存 |
| PostgreSQL | ACID、複雑なクエリ | オーバーヘッド | データパイプライン |

**推奨**: Redis（ElastiCache）

#### 4. 冪等性の要件

**オプション A**: ドキュメントで明記

```markdown
# 重要: ジョブの冪等性

Laravel Graceful Schedule Worker は At-least-once セマンティックを提供します。
重複実行の可能性があるため、全てのスケジュールジョブは冪等に設計してください。

詳細は [冪等性ガイド](../guide/IDEMPOTENCY_GUIDE.md) を参照してください。
```

**オプション B**: 実装で強制

```php
// ジョブに IdempotencyKey を要求
interface IdempotentJob
{
    public function getIdempotencyKey(): string;
}

// Dispatcher で検証
if (! $job instanceof IdempotentJob) {
    throw new \RuntimeException('Job must be idempotent');
}
```

**推奨**: オプション A（ドキュメントで明記）
- 柔軟性が高い
- 既存のジョブとの互換性

### 追加で検討すべきトピック

#### 1. ゾンビタスク検出

実行中にクラッシュしたタスクを検出する。

```php
// ハートビート方式
public function dispatchEvent(Event $event, Carbon $dueAt): void
{
    // 実行開始を記録
    $this->tracker->markStarted($event, $dueAt);

    $this->client->startExecution([/* ... */]);

    // 実行完了を記録
    $this->tracker->markCompleted($event, $dueAt);
}

// 別プロセスで監視
public function detectZombies(): void
{
    $zombies = $this->tracker->getStartedButNotCompleted();

    foreach ($zombies as $zombie) {
        // タイムアウトを過ぎたゾンビを再実行
        if ($zombie['started_at']->addMinutes(30)->isPast()) {
            $this->dispatchEvent($zombie['event'], $zombie['due_at']);
        }
    }
}
```

#### 2. リーダー選出

複数のスケジューラインスタンスを起動する場合、リーダーを選出する。

```php
// Redis ロックによるリーダー選出
public function electLeader(): bool
{
    $lock = Cache::lock('schedule:leader', 30);

    if ($lock->get()) {
        // リーダーとして動作
        $this->runAsLeader($lock);
        return true;
    }

    // フォロワーとして待機
    return false;
}

public function runAsLeader(Lock $lock): void
{
    while ($this->running) {
        // スケジュール実行
        $this->runSchedule();

        // ロックを更新（リーダーシップを維持）
        $lock->block(30);
    }

    $lock->release();
}
```

#### 3. バックオフ戦略

リトライ時の指数バックオフ。

```php
public function retryWithBackoff(Event $event, Carbon $dueAt, int $attempt = 0): void
{
    try {
        $this->dispatchEvent($event, $dueAt);
    } catch (\Exception $e) {
        if ($attempt >= 5) {
            // 最大リトライ回数に到達
            Log::error("Failed to dispatch event: {$event->command}", [
                'exception' => $e,
                'attempts' => $attempt,
            ]);
            return;
        }

        // 指数バックオフ: 2^attempt 秒待機
        $delay = pow(2, $attempt);
        sleep($delay);

        // リトライ
        $this->retryWithBackoff($event, $dueAt, $attempt + 1);
    }
}
```

---

## まとめ

### 主要な洞察

1. **At-least-once は業界標準**: クラウドネイティブシステムは At-least-once を提供し、アプリケーションが冪等性を保証する責任分担が一般的

2. **完全な保証は困難**: Exactly-once は理論的に不可能であり、「At-least-once + 冪等性」で実現する

3. **トレードオフの理解**: 複雑さと確実性のバランスを理解し、ユースケースに応じた選択が重要

4. **段階的な実装**: Phase 1（リファクタリング） → Phase 2（StepFunctions） → Phase 3（ExecutionTracker）の段階的アプローチが現実的

### 推奨アプローチ

**Laravel Graceful Schedule Worker の拡張設計**:

1. **ExecutionTracker**: Redis を使用した実行履歴の永続化
2. **起動時チェック**: デプロイ時の取りこぼしを検出・リカバリ
3. **冪等性ガイド**: ドキュメントでベストプラクティスを提供
4. **StepFunctions 統合**: リソース分離とスケーラビリティ

この設計により、**At-least-once セマンティック**を提供し、業界標準のベストプラクティスに沿った実装を実現できる。

---

**参考資料**:
- [SCHEDULER_COMPARISON.md](./SCHEDULER_COMPARISON.md) - 業界のスケジューラー比較
- [Two Generals' Problem](https://en.wikipedia.org/wiki/Two_Generals%27_Problem) - 分散システムの理論的制約
- [Idempotence - AWS Well-Architected Framework](https://docs.aws.amazon.com/wellarchitected/latest/framework/rel_tracking_change_management_use_automation.html)

---

**Last Updated**: 2026-01-24
**Version**: 1.0.0
**Author**: Laravel Graceful Schedule Worker Team
