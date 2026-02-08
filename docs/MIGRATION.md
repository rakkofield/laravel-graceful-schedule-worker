# 既存 Laravel スケジューラからの移行ガイド

## 概要

このガイドでは、既存の Laravel スケジューラ（`schedule:work`）から `laravel-graceful-schedule-worker` への移行手順を段階的に説明します。

**移行のポイント:**

- 既存の `schedule()` メソッドの中身は**ほぼそのままコピー**で動きます
- 最小変更（3 ステップ）で始められます
- リカバリ機能や Step Functions 連携は後から段階的に追加できます
- いつでもロールバック可能です

## 移行の全体像

| フェーズ | 内容 | 必須 |
|---|---|---|
| フェーズ 1 | パッケージ導入 + 基本移行 | はい |
| フェーズ 2 | リカバリ機能の追加 | オプション |
| フェーズ 3 | Step Functions 連携 | オプション |

---

## フェーズ 1: パッケージ導入

### 変更前の Kernel.php

```php
<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('reports:daily')->dailyAt('02:00')
            ->withoutOverlapping(10);

        $schedule->command('emails:send')->everyFiveMinutes()
            ->when(function () {
                return config('app.send_emails');
            });

        $schedule->command('cache:prune')->hourly()
            ->between('01:00', '05:00');
    }

    protected function commands()
    {
        $this->load(__DIR__.'/Commands');
    }
}
```

### 移行方法の選択

#### 方法 A: 一括移行（推奨: タスク数が少ない場合）

`schedule()` の中身を `gracefulSchedule()` にコピーして一括で切り替えます。以下の手順はこの方法を説明しています。

#### 方法 B: 段階的移行（推奨: タスク数が多い・リスクを最小化したい場合）

`schedule()` はそのままにして、タスクを 1 つずつ `gracefulSchedule()` に移動します。

- `schedule()` に残っているタスク → Laravel 標準の `Event`（振る舞い変化なし）
- `gracefulSchedule()` に移動したタスク → `ClockAwareEvent`（新しい振る舞い）

```php
class Kernel extends ConsoleKernel
{
    use UsesClockAwareSchedule;

    // まだ移行していないタスク（振る舞い変化なし）
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('emails:send')->everyFiveMinutes();
        $schedule->command('cache:prune')->hourly();
    }

    // 移行済みタスク（Clock-aware + リカバリ対応）
    protected function gracefulSchedule(ClockAwareSchedule $schedule)
    {
        $schedule->command('reports:daily')->dailyAt('02:00')
            ->runInBackground()
            ->withGracePeriod(30);
    }
}
```

> **注意:** 同じタスクを両方のメソッドに定義しないでください（二重実行になります）。

### 手順

#### ステップ 1: パッケージのインストール

```shell
composer require rakko-inc/laravel-graceful-schedule-worker
```

ServiceProvider は自動検出されます。

#### ステップ 2: 設定ファイルのパブリッシュ

```shell
php artisan vendor:publish --provider="RakkoInc\LaravelGracefulScheduleWorker\Providers\GracefulScheduleWorkerProvider"
```

#### ステップ 3: Kernel の変更

3 つの変更を行います:

1. `UsesClockAwareSchedule` trait を追加
2. `schedule(Schedule $schedule)` を `gracefulSchedule(ClockAwareSchedule $schedule)` にリネーム
3. 各タスクに `runInBackground()` を追加（推奨）

### 変更後の Kernel.php

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
            ->runInBackground()
            ->withoutOverlapping(10);

        $schedule->command('emails:send')->everyFiveMinutes()
            ->runInBackground()
            ->when(function () {
                return config('app.send_emails');
            });

        $schedule->command('cache:prune')->hourly()
            ->runInBackground()
            ->between('01:00', '05:00');
    }

    protected function commands()
    {
        $this->load(__DIR__.'/Commands');
    }
}
```

### 振る舞いの変化について

trait を適用すると、全スケジュールイベントが `ClockAwareEvent` になります。`schedule:work` で実行する場合でも以下の振る舞いが変わります:

| 箇所 | 変更前（Laravel 標準） | 変更後（ClockAwareEvent） |
|---|---|---|
| `expressionPasses()` | `Carbon::now()` | `SystemClock::now()`（`DateTimeImmutable`） |
| `between()` / `unlessBetween()` | `Carbon::now()` を定義時に即時評価 | `clock->now()` を毎回遅延評価 |
| `lastDayOfMonth()` | `Carbon::now()` | 変更なし（既知の制限） |

`schedule:work`（毎分 1 回実行）では実質的な影響はほぼありませんが、時刻評価の内部実装が変わる点を認識しておいてください。問題が発生した場合は[ロールバック](#ロールバック方法)で即座に元に戻せます。

### schedule:work から schedule:graceful-work への切り替え

Kernel の変更（trait 適用）とコマンドの切り替えは独立して行えます。段階的に移行する場合は以下の順序を推奨します:

1. **まず Kernel を変更**し、`schedule:work` のまま運用して問題がないことを確認
2. **次にコマンドを切り替え**（`schedule:graceful-work` に変更）

```diff
-php artisan schedule:work
+php artisan schedule:graceful-work
```

### 動作確認方法

1. `php artisan schedule:graceful-work` を起動
2. スケジュールされたタスクが正常に実行されることを確認
3. `Ctrl+C` または `kill <pid>` で SIGTERM を送信し、グレースフル停止を確認

### ロールバック方法

問題が発生した場合、以下の手順で元に戻せます:

1. Kernel.php から `use UsesClockAwareSchedule;` を削除
2. `gracefulSchedule(ClockAwareSchedule $schedule)` を `schedule(Schedule $schedule)` に戻す
3. use 文を `Illuminate\Console\Scheduling\Schedule` に戻す
4. コマンドを `schedule:work` に戻す

パッケージ自体はインストールしたままでも問題ありません。

---

## フェーズ 2: リカバリ機能の追加

フェーズ 1 が安定稼働していることを確認してから進めてください。

### .env の設定追加

```env
SCHEDULE_TRACKER_ENABLED=true
SCHEDULE_TRACKER_STORE=redis
```

### withGracePeriod() の追加

リカバリが必要なタスクにのみ追加します。

```php
protected function gracefulSchedule(ClockAwareSchedule $schedule)
{
    // 重要なタスク → リカバリ有効
    $schedule->command('reports:daily')->dailyAt('02:00')
        ->runInBackground()
        ->withoutOverlapping(10)
        ->withGracePeriod(30);  // 30 分以内の取りこぼしを自動リカバリ

    // リカバリ不要なタスク → そのまま
    $schedule->command('emails:send')->everyFiveMinutes()
        ->runInBackground()
        ->when(function () {
            return config('app.send_emails');
        });

    $schedule->command('cache:prune')->hourly()
        ->runInBackground()
        ->between('01:00', '05:00');
}
```

### 冪等性の確認チェックリスト

`withGracePeriod()` を付けたタスクについて確認してください:

- [ ] 同じタスクが 2 回実行されても結果が変わらないか？
- [ ] DB 操作に `updateOrInsert` やユニーク制約を使っているか？
- [ ] 外部 API 呼び出しに重複排除の仕組みがあるか？
- [ ] ファイル生成は決定論的な名前を使っているか？

詳しくは [IDEMPOTENCY_GUIDE.md](./IDEMPOTENCY_GUIDE.md) を参照してください。

---

## フェーズ 3: Step Functions 連携（オプション）

### 判断基準

以下に当てはまる場合に Step Functions の導入を検討してください:

- タスクのリトライ・タイムアウトを AWS 側で管理したい
- タスク実行状況を Step Functions コンソールで可視化したい
- 個別の ECS Task としてジョブを実行したい

ローカル実行で十分な場合は不要です。

### 概要設定手順

1. `aws/aws-sdk-php` をインストール
2. Step Functions State Machine を作成
3. `.env` に `SCHEDULE_DISPATCH=stepfunctions` と `SCHEDULE_STATE_MACHINE_ARN` を設定
4. 必要なタスクに `dispatchVia('stepfunctions')` を追加

> **注意:** Step Functions 経由では `before()` / `after()` / `onSuccess()` / `onFailure()` / `appendOutputTo()` は動作しません。

詳しくは [STEPFUNCTIONS_IMPLEMENTATION.md](./STEPFUNCTIONS_IMPLEMENTATION.md) を参照してください。

---

## メソッド互換性テーブル

| 既存の使い方 | gracefulSchedule() での対応 | 備考 |
|---|---|---|
| `everyMinute()`, `hourly()`, `daily()` 等 | そのまま使える | |
| `when()` / `skip()` | そのまま使える | |
| `between()` / `unlessBetween()` | そのまま使える | 遅延評価に変更（[振る舞いの変化](#振る舞いの変化について)参照） |
| `withoutOverlapping()` | そのまま使える | |
| `runInBackground()` | そのまま使える（推奨） | |
| `environments()` / `evenInMaintenanceMode()` | そのまま使える | |
| `timezone()` | そのまま使える | |
| `before()` / `after()` / `onSuccess()` / `onFailure()` | Local のみ | Step Functions 非対応 |
| `appendOutputTo()` / `sendOutputTo()` | Local のみ | Step Functions 非対応 |
| `pingBefore()` / `thenPing()` / `emailOutputTo()` | Local のみ | Step Functions 非対応 |
| `$schedule->call(Closure)` | 非対応 | Artisan コマンドに変換が必要 |
| `lastDayOfMonth()` | 制限あり | 月境界で不正確になる可能性 |

---

## よくある質問

### Q: schedule() と gracefulSchedule() を共存できるか？

**はい、共存できます。** `schedule()` に残したタスクは Laravel 標準の `Event` として動作し（振る舞い変化なし）、`gracefulSchedule()` に移動したタスクは `ClockAwareEvent` として動作します。これにより、タスク単位での段階的移行が可能です。

詳しくは[移行方法の選択](#移行方法の選択)を参照してください。

> **注意:** 同じタスクを `schedule()` と `gracefulSchedule()` の両方に定義すると二重実行になります。移動する際は必ず元のメソッドから削除してください。

### Q: schedule:work と schedule:graceful-work を同時に動かせるか？

技術的には可能ですが、**同じタスクの二重実行が発生するため推奨しません**。切り替えは同時に行ってください。

### Q: Closure ジョブ（$schedule->call()）はどうなるか？

`$schedule->call(Closure)` は対応していません。Artisan コマンドに変換してください。

```php
// 変更前（非対応）
$schedule->call(function () {
    DB::table('recent_users')->delete();
})->daily();

// 変更後
// 1. Artisan コマンドを作成
//    php artisan make:command PruneRecentUsers
// 2. スケジュールに登録
$schedule->command('users:prune-recent')->daily()
    ->runInBackground();
```

### Q: withoutOverlapping() はそのまま使えるか？

はい、そのまま使えます。`withoutOverlapping()` は Laravel の `EventMutex` を使用しており、パッケージの `TrackingDispatcher` のロックとは別の仕組みです。両者は問題なく共存します。

### Q: runInBackground() は必須か？

必須ではありませんが、**強く推奨します**。`runInBackground()` を付けないとタスクが直列実行になり、前のタスクが終わるまで次のタスクが開始されません。

---

## 注意事項

- リカバリ機能は **At-least-once セマンティック** で動作します。リカバリ対象のタスクは冪等に設計してください。
- `ext-pcntl` が必須です（シグナルハンドリングに使用）。
- PHP 7.2.5 以上が必要です。
