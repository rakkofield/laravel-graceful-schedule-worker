# Laravel Graceful Schedule Worker - アーキテクチャ設計

## 目次

### Part 1: 設計概要

1. [概要と背景](#概要と背景)
2. [実行保証モデル](#実行保証モデル)
3. [アプローチ比較](#アプローチ比較)
4. [課題の詳細](#課題の詳細)
5. [ソリューション設計](#ソリューション設計)

### Part 2: 制約と参考資料

6. [制約と注意点](#制約と注意点)
7. [用語集](#用語集)
8. [参考資料](#参考資料)

---

# Part 1: 設計概要

## 概要と背景

### パッケージの目的

`laravel-graceful-schedule-worker` は、Laravel のスケジュールタスクをグレースフルに実行するための軽量パッケージです。`php artisan schedule:run` を長時間実行プロセスとして動作させ、SIGINT/SIGTERM シグナルを受信した際に実行中のタスクを適切に終了させることができます。

**現在の主な機能:**

- 毎分 `schedule:run` を子プロセスとして起動
- 子プロセスの出力をリアルタイムでコンソールにストリーム
- SIGINT/SIGTERM を受信した際のグレースフルシャットダウン
- 実行中のタスクが完了してから終了

### 拡張の動機

**背景: ECS タスク停止時のジョブ中断問題**

Amazon ECS 環境でこのパッケージを運用する際、以下の課題が発生します：

1. **ECS タスクの停止時**: デプロイやスケールイン時に ECS タスクが停止される
2. **グレースフル期間の制限**: ECS の停止猶予期間（デフォルト 30 秒、最大 120 秒）内に全てのスケジュールタスクを完了させる必要がある
3. **長時間ジョブの中断**: 実行時間が長いタスクが中断され、次回以降も実行されない可能性がある

### 提案するソリューションの全体像

本設計では、**ClockAware Orchestrator パターン**を導入し、以下の問題を解決します：

- **リソース分離**: スケジュールタスクを AWS Step Functions や EventBridge Scheduler を通じて別の ECS タスク/Lambda で実行
- **取りこぼし防止**: 実行履歴をトラッキングし、未実行タスクを検出・リカバリ
- **拡張性**: ローカル環境では従来通り動作し、本番環境では外部オーケストレータを使用する設計
- **柔軟性**: `skip()` / `when()` などの動的条件に対応し、`withGracePeriod()` などの拡張メソッドを提供

---

## 実行保証モデル

### At-least-once セマンティック

本パッケージは **At-least-once セマンティック**を提供します。

**実行保証の定義**:

- **スケジュールされたジョブは、リソースがある限り必ず最低1回は実行される**
- **ネットワーク障害やクラッシュリカバリ時に、稀に重複実行される可能性がある**

### 責任分担

| 層 | 責任内容 |
|---|---------|
| **Orchestrator（スケジューラ）** | At-least-once の起動保証、due 判定、取りこぼしリカバリ、実行履歴の永続化 |
| **Step Functions（実行基盤）** | 実行の信頼性（リトライ、タイムアウト）、必要に応じて DynamoDB ロック等での重複防止 |
| **Worker（アプリロジック）** | ビジネスロジックの冪等性、トランザクション境界の適切な設計 |

※ Step Functions と Worker は「アプリケーション側」の責務に含まれます。

### 業界標準との整合性

この責任分担は、クラウドネイティブシステムの標準的なアプローチです：

- **AWS EventBridge Scheduler**: At-least-once 配信保証（リトライポリシー + DLQ）
- **Apache Airflow**: Catchup/Backfill による自動リカバリ
- **Kubernetes CronJob**: startingDeadlineSeconds による限定的な猶予期間

詳細は [SCHEDULE_EXECUTION_SEMANTICS.md](./SCHEDULE_EXECUTION_SEMANTICS.md) を参照してください。

> **Note**: アプリケーション側の冪等性実装については
> [IDEMPOTENCY_GUIDE.md](./IDEMPOTENCY_GUIDE.md) を参照

---

## アプローチ比較

### スケジューラの選択: EventBridge Scheduler vs ClockAware Orchestrator

Laravel のスケジュールタスクを本番環境で運用する際、**スケジュール管理の方法**として2つのアプローチがあります。

| 観点 | EventBridge Scheduler | ClockAware Orchestrator |
|------|----------------------|------------------------|
| **スケジュール定義** | IaC (Terraform/CDK) で管理 | Kernel.php (PHP) で管理 |
| **動的条件 (when/skip)** | ✗ 不可<br>（cron 式のみ） | ✓ 対応<br>（Laravel の柔軟な構文） |
| **配信保証** | At-least-once<br>（AWS マネージド） | At-least-once<br>（自前実装 + ExecutionTracker） |
| **移行コスト** | 高<br>（スケジュール定義の書き換え） | 低<br>（パッケージ導入のみ） |
| **運用複雑性** | 低<br>（AWS マネージド） | 中<br>（Orchestrator ECS Task + Redis が必要） |
| **拡張性** | 低<br>（cron 式のみ） | 高<br>（withGracePeriod 等の拡張） |
| **テスト容易性** | 低<br>（IaC のテストが必要） | 高<br>（既存の Laravel テスト） |

**重要**: どちらのアプローチでも、**ジョブの実行は Step Functions 経由で行うことを推奨**します。

### ジョブ実行の方法: Dispatcher の選択

スケジューラがジョブを起動する際の実行方法（Dispatcher）を選択できます。

| Dispatcher | 実行環境 | 用途 | リソース分離 |
|-----------|---------|------|------------|
| **LocalDispatcher** | 同一コンテナ内でプロセス起動 | ローカル開発、軽量ジョブ | ✗ なし |
| **StepFunctionsDispatcher** | Step Functions 経由で ECS Task/Lambda | 本番環境、長時間ジョブ | ✓ あり |

**推奨構成**:
```
ClockAware Orchestrator (スケジュール管理)
  + StepFunctionsDispatcher (ジョブ実行)
  + ExecutionTracker (取りこぼし検出)
```

### 推奨判断基準

**ClockAware Orchestrator を推奨する場合**:

- `skip()` / `when()` を使用している（動的条件が必要）
- スケジュール定義を Kernel.php で管理したい
- Laravel の柔軟なスケジューリング構文を活用したい
- 既存のスケジュール定義をそのまま活用したい
- withGracePeriod() などの拡張機能が必要
- **ジョブ実行は StepFunctionsDispatcher で行う**

**EventBridge Scheduler を推奨する場合**:

- 全てのスケジュールが固定 cron 式で表現できる
- 動的条件（skip/when）を使用していない
- 完全マネージドサービスを優先したい
- スケジュール定義を IaC で管理したい
- **スケジュール管理もジョブ実行も AWS に委譲する**

**重要**: 将来的に動的条件を除去できる場合は、EventBridge Scheduler への移行も選択肢として残ります。

### Dispatcher 選択のガイドライン

ジョブごとに適切な Dispatcher を選択することで、コストとパフォーマンスを最適化できます。

| 頻度 | 実行時間 | 推奨 Dispatcher | 理由 |
|------|---------|----------------|------|
| everyMinute | < 30秒 | Local | オーバーヘッド・コストが見合わない |
| everyMinute | 30秒〜5分 | 要検討 | リソース分離の重要度で判断 |
| everyMinute | > 5分 | Step Functions | リソース分離が必須 |
| everyFiveMinutes 以上 | 任意 | Step Functions | コスト効率が良い |
| Closure ジョブ | 任意 | Local | シリアライズ不可のため |

**コスト試算例（everyMinute × 1ジョブ）**:
- 1日: 1,440回
- 1ヶ月: 約43,200回
- Standard Workflow（$0.025/1,000遷移）:
  - 最小構成（Start → Task → End = 3遷移）: 約$3.24/月
- Express Workflow（実行回数 + 実行時間）:
  - 実行時間10秒の場合: 約$0.50/月

**推奨アプローチ**:
- デフォルトは Step Functions（リソース分離のメリット）
- 高頻度・軽量ジョブはローカル実行にオーバーライド
- Closure ジョブは自動的にローカル実行

---

## 課題の詳細

### 課題A: リソース分離の欠如

**現状**: 全てのスケジュールタスクが同一の ECS タスク内で実行される

- スケジューラプロセスと実際のジョブ実行が同じコンテナで動作
- 長時間ジョブが実行中の場合、ECS タスク停止時に強制終了される
- グレースフル期間内に完了しないジョブは中断される

**影響**:

```
[00:00] schedule:graceful-work 起動
[00:01] 長時間ジョブ A 開始（予想実行時間: 5分）
[00:02] ECS タスク停止シグナル受信
[00:02] グレースフル期間開始（最大 120 秒）
[00:04] ジョブ A がまだ実行中...
[00:04] グレースフル期間終了、ジョブ A 強制終了 ❌
```

### 課題B: 取りこぼしリスク

**現状**: グレースフルシャットダウン時に未実行のタスクが失われる

シナリオ例：

```
[00:59:50] schedule:graceful-work 実行中
[00:59:55] SIGTERM 受信、running = false に設定
[01:00:00] この時点で新しい schedule:run は起動されない
           → 1:00 に実行予定だったタスクが実行されない ❌
```

**問題点**:

- シグナル受信後は新しいタスクを起動しない
- 次の ECS タスクが起動するまで、該当分のスケジュールタスクは実行されない
- hourly や daily のタスクの場合、次回実行まで長時間待つ必要がある

### 課題C: Laravel スケジューラの制限

**Laravel の `schedule:run` の仕様**:

- 実行時刻の「分」単位でしか判定しない
- 過去の未実行タスクを自動でリカバリする仕組みがない
- グレースフルシャットダウンや取りこぼしを考慮していない

**例**:

```php
// app/Console/Kernel.php
$schedule->command('report:daily')
    ->dailyAt('03:00');
```

- 03:00 にタスクが実行されなかった場合、次回は翌日の 03:00 まで待つ
- 中間でサーバーが再起動されても、過去の未実行は検出されない

---

## ソリューション設計

### ClockAware Orchestrator パターンの導入

**基本コンセプト**: Laravel の Schedule を拡張し、外部 Clock で due 判定を行う

```
┌─────────────────────────────────────────────────────────┐
│ Kernel.php (タイプヒントのみ変更)                       │
│   use ClockAwareSchedule;                              │
│   protected function schedule(ClockAwareSchedule $schedule)│
│   - skip() / when() が使える（従来通り）                │
│   - withGracePeriod() でリカバリ有効化（明示的）         │
│   - IDE 補完が効く、PHPStan/Psalm も通る               │
│   - デフォルトはリカバリしない（安全性優先）             │
└─────────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────┐
│ ClockAwareSchedule / ClockAwareEvent                    │
│   - 外部 Clock で due 判定                              │
│   - Laravel の Schedule / Event を継承（後方互換）      │
└─────────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────┐
│ Orchestrator (ECS Task)                                 │
│   - サイクル単位でタイマー管理                            │
│   - due 判定 → Dispatcher に dispatch                   │
│   - ExecutionTracker で実行記録                         │
│   - 起動時リカバリ                                       │
└─────────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────┐
│ ScheduleDispatcher                                      │
│   ├─ LocalDispatcher (従来互換)                         │
│   └─ StepFunctionsDispatcher                            │
└─────────────────────────────────────────────────────────┘
```

### ClockAware パターンの詳細

#### Clock による依存性注入

```php
// SystemClock: 本番環境
class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}

// FixedClock: テスト環境
class FixedClock implements ClockInterface
{
    private DateTimeImmutable $fixedTime;

    public function __construct(string $time)
    {
        $this->fixedTime = new DateTimeImmutable($time);
    }

    public function now(): DateTimeImmutable
    {
        return $this->fixedTime;
    }
}
```

#### ClockAwareEvent の拡張メソッド

```php
// Kernel.php での利用例

// デフォルト: リカバリしない（安全）
$schedule->command('heartbeat:send')
    ->everyMinute();

// 重要なジョブのみリカバリを有効化
$schedule->command('metrics:aggregate')
    ->hourly()
    ->withGracePeriod(30);  // リカバリ有効 + 30分の猶予期間

$schedule->command('reports:generate')
    ->dailyAt('03:00')
    ->skip(fn() => Holiday::isToday())
    ->enableRecovery();  // リカバリ有効（猶予期間なし = 無制限）
```

### 取りこぼし検出のタイミング

**推奨**: 起動時のみチェック（コストと確実性のバランス）

```php
// GracefulScheduleWorkCommand::handle()
public function handle()
{
    // 1. 起動時に取りこぼしをチェック
    $this->recoverMissedEvents();

    // 2. 通常のスケジュール実行
    $this->runSchedule();
}

private function recoverMissedEvents(): void
{
    foreach ($this->schedule->events() as $event) {
        if ($this->tracker->wasMissed($event, now())) {
            $missedDue = $this->tracker->calculateMissedDue($event);
            $this->dispatcher->dispatch($event, $missedDue);
        }
    }
}
```

詳細は [SCHEDULE_EXECUTION_SEMANTICS.md](./SCHEDULE_EXECUTION_SEMANTICS.md) を参照してください。

### アーキテクチャフロー

#### LocalDispatcher（既存動作）

```
1. Scheduler: 実行すべきタスクを検出
2. LocalDispatcher: Process::fromShellCommandline() で実行
3. 子プロセスとして同一コンテナ内で実行
```

#### StepFunctionsDispatcher（新規）

```
1. Scheduler: 実行すべきタスクを検出
2. StepFunctionsDispatcher: AWS SDK で Step Functions を起動
   - Input: { "command": "report:daily", "options": [...] }
   - ExecutionName: hash(command + dueAt) で重複防止
3. Step Functions: ECS RunTask または Lambda Invoke を実行
4. ExecutionTracker: 実行を記録
5. Scheduler: 起動を待たずに次のタスク判定へ（Fire & Forget）
```

### ジョブ別 Dispatcher 選択

デフォルト Dispatcher はグローバル設定で指定し、イベントごとにオーバーライド可能です。

#### 設定ファイル

```php
// config/graceful-scheduler.php
return [
    // ローカル開発では local を推奨
    // 本番環境では SCHEDULE_DISPATCH=stepfunctions を設定
    'dispatch' => env('SCHEDULE_DISPATCH', 'local'),
];
```

#### Kernel.php での利用

```php
// デフォルト（Step Functions）を使用
$schedule->command('heavy:job')
    ->hourly()
    ->withGracePeriod(60);

// ローカル実行を強制（高頻度・軽量ジョブ）
$schedule->command('heartbeat:send')
    ->everyMinute()
    ->dispatchVia('local');

// Closure ジョブ（自動的に local）
$schedule->call(fn() => $this->cleanup())
    ->hourly();
```

#### ClockAwareEvent 拡張メソッド

`dispatchVia()` メソッドにより、ジョブごとに Dispatcher を選択できます。

```php
class ClockAwareEvent extends Event
{
    protected ?string $dispatcher = null;  // null = グローバル設定を使用

    public function dispatchVia(string $dispatcher): self
    {
        $this->dispatcher = $dispatcher;
        return $this;
    }

    public function getDispatcher(): ?string
    {
        return $this->dispatcher;
    }
}
```

**実装の詳細**:
- `dispatcher` プロパティが `null` の場合、グローバル設定（`config('graceful-scheduler.dispatch')`）を使用
- Closure ジョブは自動的に `LocalDispatcher` にフォールバック（シリアライズ不可のため）
- `dispatchVia('local')` または `dispatchVia('stepfunctions')` で明示的に指定可能

**判定ロジックの例**:

```php
// ScheduleDispatcherFactory での解決ロジック（想定）
public function resolveDispatcher(ClockAwareEvent $event): ScheduleDispatcher
{
    // 1. Closure ジョブはシリアライズ不可のため強制的に Local
    if ($event->isClosure()) {
        return new LocalDispatcher();
    }

    // 2. イベントに明示的な指定があればそれを使用
    if ($dispatcher = $event->getDispatcher()) {
        return $this->createDispatcher($dispatcher);
    }

    // 3. グローバル設定を使用
    return $this->createDispatcher(config('graceful-scheduler.dispatch'));
}
```

---

# Part 2: Step Functions 実装

> 詳細は [STEPFUNCTIONS_IMPLEMENTATION.md](./STEPFUNCTIONS_IMPLEMENTATION.md) を参照

---

# Part 2: 制約と参考資料

## 制約と注意点

### Laravel バージョンの制約

- **対応バージョン**: Laravel 6.x, 7.x
- **PHP バージョン**: 7.2.5 以上
- Laravel 8 以降は別途検証が必要

### Closure ジョブの制限

**問題**: Closure はシリアライズできないため、StepFunctions に渡せない

```php
// ❌ StepFunctionsDispatcher では実行できない
$schedule->call(function () {
    // ...
})->everyMinute();

// ✅ Artisan コマンドは実行可能
$schedule->command('report:daily')->dailyAt('03:00');
```

**対応策**:

- `StepFunctionsDispatcher` を使用する場合は、全てのスケジュールタスクを Artisan コマンドまたはジョブクラスとして定義する
- Closure を使用している場合は、設定で `dispatch=local` を指定

### Redis/共有キャッシュの要件

**ExecutionTracker の前提条件**:

- 複数の ECS タスク間で実行履歴を共有するため、Redis や DynamoDB などの共有ストレージが必要
- ローカルキャッシュ（file, array）では複数インスタンス間で共有できない

**推奨構成**:

```
ECS Task 1 (Scheduler) ──┐
ECS Task 2 (Scheduler) ──┼──> ElastiCache (Redis)
ECS Task 3 (Scheduler) ──┘
```

### AWS 権限の要件

**StepFunctionsDispatcher を使用する場合**:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "states:StartExecution"
      ],
      "Resource": "arn:aws:states:ap-northeast-1:123456789012:stateMachine:ScheduleExecutor"
    }
  ]
}
```

### パフォーマンスの考慮事項

**StepFunctions の制限**:

- StartExecution API のレート制限:
  - Standard Workflow: 2,000 回/秒（デフォルト、引き上げ可能）
  - Express Workflow: 100,000 回/秒（同期呼び出しの場合）
- 大量のスケジュールタスクがある場合は、バッチ処理を検討

**Tracker のオーバーヘッド**:

- 毎分の実行チェック時に全イベントをスキャン
- イベント数が多い場合は、インデックスやフィルタリングを検討

---

## 用語集

| 用語 | 説明 |
|------|------|
| **グレースフルシャットダウン** | プロセスが SIGTERM を受信した際に、実行中のタスクを完了させてから終了すること |
| **Fire & Forget** | 非同期処理を起動した後、結果を待たずに次の処理に進むパターン |
| **取りこぼし** | スケジュールされたタスクが予定時刻に実行されず、スキップされること |
| **Orchestrator** | 複数のサービスやタスクを調整・管理する役割を持つコンポーネント |
| **ECS RunTask** | Amazon ECS で新しいタスクを起動する API |
| **Step Functions** | AWS のサーバーレスオーケストレーションサービス |

---

## 参考資料

### 内部ドキュメント

- [STEPFUNCTIONS_IMPLEMENTATION.md](./STEPFUNCTIONS_IMPLEMENTATION.md) - Step Functions 実装詳細
- [SCHEDULE_EXECUTION_SEMANTICS.md](./SCHEDULE_EXECUTION_SEMANTICS.md) - 実行保証の理論的背景と責任分担
- [SCHEDULER_COMPARISON.md](./SCHEDULER_COMPARISON.md) - 業界スケジューラーの比較（Kubernetes, Airflow, EventBridge, Celery Beat）
- [IDEMPOTENCY_GUIDE.md](./IDEMPOTENCY_GUIDE.md) - 冪等性ガイドライン

### Laravel

- [Laravel Task Scheduling](https://laravel.com/docs/7.x/scheduling) - Laravel のスケジューリング機能
- [Laravel Database Transactions](https://laravel.com/docs/7.x/database#database-transactions) - トランザクション処理

### AWS

- [AWS Step Functions Developer Guide](https://docs.aws.amazon.com/step-functions/) - Step Functions の公式ガイド
- [Step Functions and Amazon ECS/Fargate](https://docs.aws.amazon.com/step-functions/latest/dg/connect-ecs.html) - Step Functions と ECS/Fargate の統合
- [Step Functions Best Practices](https://docs.aws.amazon.com/step-functions/latest/dg/sfn-best-practices.html) - Step Functions のベストプラクティス
- [Step Functions Service Quotas](https://docs.aws.amazon.com/step-functions/latest/dg/limits.html) - Step Functions のサービスクォータ
- [Amazon ECS Task Lifecycle](https://docs.aws.amazon.com/AmazonECS/latest/developerguide/task-lifecycle.html) - ECS タスクのライフサイクル
- [EventBridge Scheduler](https://docs.aws.amazon.com/eventbridge/latest/userguide/using-eventbridge-scheduler.html) - EventBridge Scheduler の使い方

### 分散システム理論

- [Two Generals' Problem](https://en.wikipedia.org/wiki/Two_Generals%27_Problem) - 分散システムの理論的制約
- [Idempotence - AWS Well-Architected Framework](https://docs.aws.amazon.com/wellarchitected/latest/framework/rel_tracking_change_management_use_automation.html) - AWS の冪等性ガイド

### その他

- [Symfony Process Component](https://symfony.com/doc/current/components/process.html) - プロセス管理
- [cron-expression Library](https://github.com/dragonmantank/cron-expression) - Cron 式パーサー

---

**Last Updated**: 2026-01-25
**Version**: 2.0.0 (ClockAware Orchestrator + Step Functions Integration)
**Author**: Laravel Graceful Schedule Worker Team
