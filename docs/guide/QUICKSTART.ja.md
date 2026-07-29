# クイックスタートガイド

## はじめに

このガイドでは、`laravel-graceful-schedule-worker` の導入手順を段階的に説明します。

### 対象読者

- Laravel 6.x / 7.x でスケジュールタスクを運用している開発者
- ECS / Kubernetes 等のコンテナ環境でスケジューラを動かしたい方

### 前提条件

- PHP ^7.2.5 || ~8.0
- Laravel 6.x / 7.x
- ext-pcntl（シグナルハンドリングに必要）

---

## レベル 1: 最小構成（ローカル実行）

まずは最小限の変更でパッケージを導入します。

### パッケージインストール

```shell
composer require rakko-inc/laravel-graceful-schedule-worker
```

ServiceProvider は自動検出されるため、手動登録は不要です。

### 設定ファイルパブリッシュ

```shell
php artisan vendor:publish --provider="RakkoInc\LaravelGracefulScheduleWorker\Providers\GracefulScheduleWorkerProvider"
```

`config/graceful-scheduler.php` が作成されます。デフォルト設定のままで動作します。

### Kernel の変更

`app/Console/Kernel.php` を以下のように変更します。

**変更前:**

```php
<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('reports:daily')->dailyAt('02:00');
        $schedule->command('emails:send')->everyFiveMinutes();
    }
}
```

**変更後:**

```php
<?php

namespace App\Console;

use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use RakkoInc\LaravelGracefulScheduleWorker\Console\UsesClockAwareSchedule;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;

class Kernel extends ConsoleKernel
{
    use UsesClockAwareSchedule;

    protected function gracefulSchedule(ClockAwareSchedule $schedule)
    {
        $schedule->command('reports:daily')->dailyAt('02:00')
            ->runInBackground();

        $schedule->command('emails:send')->everyFiveMinutes()
            ->runInBackground();
    }
}
```

変更点は 3 つだけです:

1. `UsesClockAwareSchedule` trait を追加
2. `schedule()` を `gracefulSchedule()` にリネーム（中身はほぼそのまま）
3. `runInBackground()` を追加（推奨）

> **ヒント:** タスク数が多い場合は、`schedule()` をそのまま残してタスクを段階的に `gracefulSchedule()` に移動することもできます。詳しくは [MIGRATION.md](./MIGRATION.md#移行方法の選択) を参照してください。

### コマンド実行

```shell
php artisan schedule:graceful-work
```

`schedule:work` の代わりにこのコマンドを使います。SIGTERM/SIGINT を受信すると、実行中のタスクが終了するのを待ってから安全に停止します。

### 動作確認

- タスクが正常にスケジュール実行されることを確認
- `Ctrl+C` で停止した際、実行中のタスクが正常終了してから停止することを確認

---

## レベル 2: リカバリ機能の有効化

### なぜリカバリが必要か

ECS タスクの停止やデプロイ時にスケジューラが再起動すると、その間にスケジュールされていたタスクが実行されない可能性があります。リカバリ機能はこの「取りこぼし」を検出して自動的に再実行します。

> **注意:** リカバリ対象は直近の取りこぼし1件のみです。例えば、1時間ごとのタスクが3時間分実行されなかった場合、リカバリされるのは直近の1件（例: 5分前）のみです。それ以前の取りこぼし（1時間前、2時間前）はリカバリされません。これは設計上の意図であり、完全なバックフィルはスコープ外です。

### Redis の準備

リカバリには実行履歴の記録が必要です。Redis または Memcached が利用できる環境を準備してください。

### .env の設定

```env
SCHEDULE_TRACKER_ENABLED=true
SCHEDULE_TRACKER_STORE=redis
```

| 変数 | デフォルト | 説明 |
|---|---|---|
| `SCHEDULE_TRACKER_ENABLED` | `false` | 実行追跡を有効化 |
| `SCHEDULE_TRACKER_STORE` | `null` | キャッシュストア名（`redis` 等） |
| `SCHEDULE_TRACKER_LOCK_TTL` | `3600` | ロックの TTL（秒） |

### withGracePeriod() の追加

リカバリ対象にするタスクに `withGracePeriod()` を追加します。

```php
protected function gracefulSchedule(ClockAwareSchedule $schedule)
{
    // 30 分以内の取りこぼしを自動リカバリ
    $schedule->command('reports:daily')->dailyAt('02:00')
        ->runInBackground()
        ->withGracePeriod(30);

    // リカバリ不要なタスクはそのまま
    $schedule->command('cache:clear')->hourly()
        ->runInBackground();
}
```

> **重要:** リカバリ対象のタスクは冪等である必要があります。ロック TTL と猶予期間の関係については [ロック TTL と猶予期間](./IDEMPOTENCY_GUIDE.ja.md#ロック-ttl-と猶予期間) を参照してください。

### 冪等性の確認

リカバリ機能は **At-least-once セマンティック**で動作するため、リカバリ対象のタスクは冪等である必要があります。

チェックリスト:

- [ ] 同じタスクが 2 回実行されても結果が同じになるか？
- [ ] DB 操作は `updateOrInsert` やユニーク制約で重複を防止しているか？
- [ ] 外部 API 呼び出しにはトランザクション ID 等の重複排除キーがあるか？

詳しくは [IDEMPOTENCY_GUIDE.md](./IDEMPOTENCY_GUIDE.md) を参照してください。

---

## レベル 3: Step Functions 連携

### いつ Step Functions を使うべきか

- タスクのリトライやタイムアウトを AWS 側で管理したい場合
- タスク実行の監視・オブザーバビリティを Step Functions コンソールで行いたい場合
- ECS Task として個別にジョブを実行したい場合

ローカル実行で十分な場合は、この手順は不要です。

### aws/aws-sdk-php のインストール

```shell
composer require aws/aws-sdk-php "^3.20.1"
```

### .env の設定

```env
SCHEDULE_DISPATCH=stepfunctions
SCHEDULE_STATE_MACHINE_ARN=arn:aws:states:ap-northeast-1:123456789:stateMachine:my-scheduler
AWS_DEFAULT_REGION=ap-northeast-1
AWS_ACCESS_KEY_ID=your-key
AWS_SECRET_ACCESS_KEY=your-secret
```

| 変数 | デフォルト | 説明 |
|---|---|---|
| `SCHEDULE_DISPATCH` | `local` | ディスパッチ方法（`local` / `stepfunctions`） |
| `SCHEDULE_STATE_MACHINE_ARN` | `null` | State Machine の ARN |
| `AWS_DEFAULT_REGION` | `ap-northeast-1` | AWS リージョン |

### dispatchVia() の指定

タスクごとに Dispatcher を指定できます。

```php
protected function gracefulSchedule(ClockAwareSchedule $schedule)
{
    // Step Functions で実行
    $schedule->command('reports:daily')->dailyAt('02:00')
        ->dispatchVia('stepfunctions')
        ->withGracePeriod(30);

    // ローカルで実行（タスクごとに切替可能）
    $schedule->command('cache:clear')->hourly()
        ->dispatchVia('local')
        ->runInBackground();
}
```

> **注意:** Step Functions 経由の場合、`before()` / `after()` / `onSuccess()` / `onFailure()` / `appendOutputTo()` は動作しません。

詳細な設定は [STEPFUNCTIONS_IMPLEMENTATION.md](../internals/STEPFUNCTIONS_IMPLEMENTATION.md) を参照してください。

---

## 設定リファレンス

`config/graceful-scheduler.php` の全設定:

| 環境変数 | デフォルト | 説明 |
|---|---|---|
| `SCHEDULE_DISPATCH` | `local` | ディスパッチ方法: `local` / `stepfunctions` |
| `SCHEDULE_TRACKER_ENABLED` | `false` | 実行追跡の有効化 |
| `SCHEDULE_TRACKER_STORE` | `null` | 追跡用キャッシュストア |
| `SCHEDULE_TRACKER_LOCK_TTL` | `3600` | ロック TTL（秒） |
| `SCHEDULE_STATE_MACHINE_ARN` | `null` | Step Functions State Machine ARN |
| `AWS_DEFAULT_REGION` | `ap-northeast-1` | AWS リージョン |

---

## ClockAwareEvent API リファレンス

### 新規メソッド

| メソッド | 説明 |
|---|---|
| `withGracePeriod($minutes)` | リカバリを有効化し、猶予期間を設定。`null` で無制限。 |
| `enableRecovery()` | リカバリを有効化（猶予期間なし） |
| `dispatchVia($type)` | Dispatcher タイプを指定: `'local'` / `'stepfunctions'` |
| `timeoutAfter($seconds)` | ジョブの持ち時間を宣言。Step Functions ディスパッチ専用で、`withoutOverlapping()` のロック寿命より短くする必要がある |

### 互換性テーブル

| メソッド | 対応状況 | 備考 |
|---|---|---|
| `everyMinute()`, `hourly()`, `daily()` 等 | そのまま使える | |
| `when()` / `skip()` | そのまま使える | |
| `between()` / `unlessBetween()` | そのまま使える | Clock-aware に自動対応 |
| `withoutOverlapping()` | そのまま使える | |
| `runInBackground()` | そのまま使える | 推奨 |
| `environments()` / `evenInMaintenanceMode()` | そのまま使える | |
| `timezone()` | そのまま使える | |
| `before()` / `after()` / `onSuccess()` / `onFailure()` | Local のみ | Step Functions 非対応 |
| `appendOutputTo()` / `sendOutputTo()` | Local のみ | Step Functions 非対応 |
| `pingBefore()` / `thenPing()` / `emailOutputTo()` | Local のみ | Step Functions 非対応 |
| `$schedule->call(Closure)` | 非対応 | Artisan コマンドに変換が必要 |
| `lastDayOfMonth()` | 制限あり | 月境界で不正確になる可能性 |

詳しくは [SCHEDULER_COMPATIBILITY.md](../internals/SCHEDULER_COMPATIBILITY.md) を参照してください。

---

## 次のステップ

- [MIGRATION.md](./MIGRATION.md) — 既存 Laravel スケジューラからの移行ガイド
- [IDEMPOTENCY_GUIDE.md](./IDEMPOTENCY_GUIDE.md) — 冪等性ガイドライン
- [ARCHITECTURE.md](../internals/ARCHITECTURE.md) — アーキテクチャ設計
- [DESIGN.md](../internals/DESIGN.md) — 設計仕様
- [SCHEDULER_COMPATIBILITY.md](../internals/SCHEDULER_COMPATIBILITY.md) — メソッド互換性の詳細
- [SCHEDULE_EXECUTION_SEMANTICS.md](../internals/SCHEDULE_EXECUTION_SEMANTICS.md) — 実行保証の詳細
- [STEPFUNCTIONS_IMPLEMENTATION.md](../internals/STEPFUNCTIONS_IMPLEMENTATION.md) — Step Functions 実装詳細
- [STEPFUNCTIONS_CONSIDERATIONS.md](./STEPFUNCTIONS_CONSIDERATIONS.md) — Step Functions 検討事項
- [SCHEDULER_COMPARISON.md](../internals/SCHEDULER_COMPARISON.md) — スケジューラ比較
