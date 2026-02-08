# rakko-inc/laravel-graceful-schedule-worker

A graceful schedule worker for Laravel 6 & 7 with missed-task recovery and optional AWS Step Functions integration.

## Overview

Replace Laravel's `schedule:work` with a long-running worker that handles shutdown signals gracefully and recovers missed tasks automatically.

```php
// app/Console/Kernel.php
use RakkoInc\LaravelGracefulScheduleWorker\Console\UsesClockAwareSchedule;
use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;

class Kernel extends ConsoleKernel
{
    use UsesClockAwareSchedule;

    protected function gracefulSchedule(ClockAwareSchedule $schedule)
    {
        $schedule->command('reports:daily')->dailyAt('02:00')
            ->runInBackground()
            ->withGracePeriod(30);
    }
}
```

```shell
php artisan schedule:graceful-work
```

## Features

- **Graceful shutdown** — Catches SIGTERM/SIGINT and waits for running tasks to finish before exiting
- **Clock abstraction** — Consistent time evaluation across long-running worker cycles
- **At-least-once semantics** — Detects and recovers missed tasks within a configurable grace period
- **Execution tracking** — Redis or Memcached-backed tracking to prevent duplicate dispatch
- **AWS Step Functions integration** — Optionally delegate task execution to Step Functions for managed retries and timeouts
- **High compatibility** — Works with most Laravel scheduling methods (`everyMinute()`, `when()`, `between()`, `withoutOverlapping()`, etc.)

## Requirements

| Requirement | Version |
|---|---|
| PHP | ^7.2.5 \|\| ~8.0 |
| Laravel | 6.x / 7.x |
| ext-pcntl | required |
| aws/aws-sdk-php | ^3.20.1 (optional, for Step Functions) |
| Redis / Memcached | optional, for execution tracking |

## Installation

### 1. Install the package

```shell
composer require rakko-inc/laravel-graceful-schedule-worker
```

The ServiceProvider is auto-discovered — no manual registration needed.

### 2. Publish the config

```shell
php artisan vendor:publish --provider="RakkoInc\LaravelGracefulScheduleWorker\Providers\GracefulScheduleWorkerProvider"
```

### 3. Update your Kernel

**Before:**

```php
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('reports:daily')->dailyAt('02:00');
    }
}
```

**After:**

```diff
+use RakkoInc\LaravelGracefulScheduleWorker\Console\UsesClockAwareSchedule;
+use RakkoInc\LaravelGracefulScheduleWorker\Scheduling\ClockAwareSchedule;
 use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

 class Kernel extends ConsoleKernel
 {
+    use UsesClockAwareSchedule;
+
-    protected function schedule(Schedule $schedule)
+    protected function gracefulSchedule(ClockAwareSchedule $schedule)
     {
-        $schedule->command('reports:daily')->dailyAt('02:00');
+        $schedule->command('reports:daily')->dailyAt('02:00')
+            ->runInBackground();
     }
 }
```

### 4. Run the worker

```shell
php artisan schedule:graceful-work
```

## Quick Start

### Minimal setup (local dispatch)

The installation steps above give you a fully working graceful worker with local process dispatch. All existing scheduling methods work as-is.

### Enable recovery

Add execution tracking to detect and recover missed tasks:

```env
SCHEDULE_TRACKER_ENABLED=true
SCHEDULE_TRACKER_STORE=redis
```

Then mark tasks as recoverable:

```php
$schedule->command('reports:daily')->dailyAt('02:00')
    ->runInBackground()
    ->withGracePeriod(30); // recover if missed within 30 minutes
```

> **Important:** Recoverable tasks must be idempotent. See [docs/IDEMPOTENCY_GUIDE.md](docs/IDEMPOTENCY_GUIDE.md).

### Step Functions integration

For managed retries and timeouts via AWS Step Functions:

```env
SCHEDULE_DISPATCH=stepfunctions
SCHEDULE_STATE_MACHINE_ARN=arn:aws:states:ap-northeast-1:123456789:stateMachine:my-scheduler
```

```php
$schedule->command('reports:daily')->dailyAt('02:00')
    ->dispatchVia('stepfunctions')
    ->withGracePeriod(30);
```

See [docs/STEPFUNCTIONS_IMPLEMENTATION.md](docs/STEPFUNCTIONS_IMPLEMENTATION.md) for full setup.

## Configuration

| Environment Variable | Default | Description |
|---|---|---|
| `SCHEDULE_DISPATCH` | `local` | Dispatch method: `local` or `stepfunctions` |
| `SCHEDULE_TRACKER_ENABLED` | `false` | Enable execution tracking for recovery |
| `SCHEDULE_TRACKER_STORE` | `null` | Cache store for tracking (`redis`, etc.) |
| `SCHEDULE_TRACKER_LOCK_TTL` | `3600` | Lock TTL in seconds |
| `SCHEDULE_STATE_MACHINE_ARN` | `null` | Step Functions state machine ARN |
| `AWS_DEFAULT_REGION` | `ap-northeast-1` | AWS region |

## ClockAwareEvent API

### Additional methods

| Method | Description |
|---|---|
| `withGracePeriod($minutes)` | Enable recovery with a grace period (minutes). Pass `null` for unlimited. |
| `enableRecovery()` | Enable recovery without a grace period |
| `dispatchVia($type)` | Set dispatcher type: `'local'` or `'stepfunctions'` |

### Compatibility

Most Laravel scheduling methods work unchanged:

| Feature | Status |
|---|---|
| `everyMinute()`, `hourly()`, `daily()`, `cron()`, etc. | Supported |
| `when()` / `skip()` | Supported |
| `between()` / `unlessBetween()` | Supported (clock-aware) |
| `withoutOverlapping()` | Supported |
| `runInBackground()` | Supported (recommended) |
| `environments()` / `evenInMaintenanceMode()` | Supported |
| `timezone()` | Supported |

### Limitations

| Feature | Limitation |
|---|---|
| `before()` / `after()` / `onSuccess()` / `onFailure()` | Local dispatch only (not available with Step Functions) |
| `appendOutputTo()` / `sendOutputTo()` | Local dispatch only |
| `pingBefore()` / `thenPing()` / `emailOutputTo()` | Local dispatch only |
| `$schedule->call(Closure)` | Not supported — convert to Artisan commands |
| `lastDayOfMonth()` | May be inaccurate across month boundaries in long-running workers |

See [docs/SCHEDULER_COMPATIBILITY.md](docs/SCHEDULER_COMPATIBILITY.md) for details.

## Migration from Laravel Scheduler

Migration requires minimal changes — your existing `schedule()` body can be copied almost as-is into `gracefulSchedule()`. For projects with many tasks, gradual migration is also supported: keep `schedule()` as-is and move tasks one by one to `gracefulSchedule()`. See [docs/MIGRATION.md](docs/MIGRATION.md) for a step-by-step guide.

## Documentation

- [Quick Start Guide (Japanese)](docs/QUICKSTART.md)
- [Migration Guide (Japanese)](docs/MIGRATION.md)
- [Design Specification](docs/DESIGN.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Scheduler Compatibility](docs/SCHEDULER_COMPATIBILITY.md)
- [Idempotency Guide](docs/IDEMPOTENCY_GUIDE.md)
- [Execution Semantics](docs/SCHEDULE_EXECUTION_SEMANTICS.md)
- [Step Functions Implementation](docs/STEPFUNCTIONS_IMPLEMENTATION.md)
- [Step Functions Considerations](docs/STEPFUNCTIONS_CONSIDERATIONS.md)
- [Scheduler Comparison](docs/SCHEDULER_COMPARISON.md)

## Demo

A ready-to-run sample application is in the `demo/` directory.

```shell
cd demo
composer install
cp .env.example .env
php artisan key:generate
php artisan schedule:graceful-work
```

Or with Docker:

```shell
cd demo
docker compose up
```

## License

MIT
