# Quick Start Guide

## Introduction

This guide provides step-by-step instructions for setting up `laravel-graceful-schedule-worker`.

### Target Audience

- Developers running scheduled tasks on Laravel 6.x / 7.x
- Those who want to run the scheduler in container environments such as ECS / Kubernetes

### Prerequisites

- PHP ^7.2.5 || ~8.0
- Laravel 6.x / 7.x
- ext-pcntl (required for signal handling)

---

## Level 1: Minimal Configuration (Local Execution)

Start by introducing the package with minimal changes.

### Package Installation

```shell
composer require rakko-inc/laravel-graceful-schedule-worker
```

The ServiceProvider is auto-discovered, so manual registration is not required.

### Publish Configuration File

```shell
php artisan vendor:publish --provider="RakkoInc\LaravelGracefulScheduleWorker\Providers\GracefulScheduleWorkerProvider"
```

This creates `config/graceful-scheduler.php`. It works with the default settings.

### Kernel Changes

Modify `app/Console/Kernel.php` as follows.

**Before:**

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

**After:**

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

There are only 3 changes:

1. Add the `UsesClockAwareSchedule` trait
2. Rename `schedule()` to `gracefulSchedule()` (contents remain mostly the same)
3. Add `runInBackground()` (recommended)

> **Hint:** If you have many tasks, you can keep `schedule()` as-is and gradually move tasks to `gracefulSchedule()`. See [MIGRATION.md](./MIGRATION.md#choosing-a-migration-method) for details.

### Command Execution

```shell
php artisan schedule:graceful-work
```

Use this command instead of `schedule:work`. When SIGTERM/SIGINT is received, it waits for running tasks to complete before shutting down safely.

### Verification

- Verify that tasks execute on schedule
- Verify that when stopped with `Ctrl+C`, running tasks complete normally before the process exits

---

## Level 2: Enabling Recovery

### Why Recovery Is Needed

When the scheduler restarts due to ECS task termination or deployment, tasks that were scheduled during the gap may not execute. The recovery feature detects these "missed executions" and automatically re-executes them.

> **Note:** Recovery targets only the most recent missed execution. For example, if an hourly task missed 3 hours of executions, only the most recent one (e.g., 5 minutes ago) is recovered. Earlier missed executions (1 hour ago, 2 hours ago) are not recovered. This is by design — full backfill is out of scope.

### Redis Setup

Recovery requires recording execution history. Prepare an environment with Redis or Memcached available.

### .env Configuration

```env
SCHEDULE_TRACKER_ENABLED=true
SCHEDULE_TRACKER_STORE=redis
```

| Variable | Default | Description |
|---|---|---|
| `SCHEDULE_TRACKER_ENABLED` | `false` | Enable execution tracking |
| `SCHEDULE_TRACKER_STORE` | `null` | Cache store name (e.g., `redis`) |
| `SCHEDULE_TRACKER_LOCK_TTL` | `3600` | Lock TTL (seconds) |

### Adding withGracePeriod()

Add `withGracePeriod()` to tasks that should be recovery targets.

```php
protected function gracefulSchedule(ClockAwareSchedule $schedule)
{
    // Automatically recover missed executions within 30 minutes
    $schedule->command('reports:daily')->dailyAt('02:00')
        ->runInBackground()
        ->withGracePeriod(30);

    // Tasks that don't need recovery remain as-is
    $schedule->command('cache:clear')->hourly()
        ->runInBackground();
}
```

> **Important:** Recovery-targeted tasks must be idempotent. See [Lock TTL and Grace Period](./IDEMPOTENCY_GUIDE.md#lock-ttl-and-grace-period) for details on the relationship between lock TTL and grace period settings.

### Verifying Idempotency

The recovery feature operates with **at-least-once semantics**, so tasks targeted for recovery must be idempotent.

Checklist:

- [ ] Does the task produce the same result even if executed twice?
- [ ] Do DB operations use `updateOrInsert` or unique constraints to prevent duplicates?
- [ ] Do external API calls have deduplication keys such as transaction IDs?

See [IDEMPOTENCY_GUIDE.md](./IDEMPOTENCY_GUIDE.md) for details.

---

## Level 3: Step Functions Integration

### When to Use Step Functions

- When you want AWS to manage task retries and timeouts
- When you want to monitor task execution through the Step Functions console
- When you want to execute jobs as individual ECS Tasks

If local execution is sufficient, this step is not needed.

### Install aws/aws-sdk-php

```shell
composer require aws/aws-sdk-php "^3.20.1"
```

### .env Configuration

```env
SCHEDULE_DISPATCH=stepfunctions
SCHEDULE_STATE_MACHINE_ARN=arn:aws:states:ap-northeast-1:123456789:stateMachine:my-scheduler
AWS_DEFAULT_REGION=ap-northeast-1
AWS_ACCESS_KEY_ID=your-key
AWS_SECRET_ACCESS_KEY=your-secret
```

| Variable | Default | Description |
|---|---|---|
| `SCHEDULE_DISPATCH` | `local` | Dispatch method (`local` / `stepfunctions`) |
| `SCHEDULE_STATE_MACHINE_ARN` | `null` | State Machine ARN |
| `AWS_DEFAULT_REGION` | `ap-northeast-1` | AWS region |

### Specifying dispatchVia()

You can specify the Dispatcher per task.

```php
protected function gracefulSchedule(ClockAwareSchedule $schedule)
{
    // Execute via Step Functions
    $schedule->command('reports:daily')->dailyAt('02:00')
        ->dispatchVia('stepfunctions')
        ->withGracePeriod(30);

    // Execute locally (can switch per task)
    $schedule->command('cache:clear')->hourly()
        ->dispatchVia('local')
        ->runInBackground();
}
```

> **Note:** When using Step Functions, `before()` / `after()` / `onSuccess()` / `onFailure()` / `appendOutputTo()` do not work.

See [STEPFUNCTIONS_IMPLEMENTATION.md](../internals/STEPFUNCTIONS_IMPLEMENTATION.md) for detailed configuration.

---

## Configuration Reference

All settings in `config/graceful-scheduler.php`:

| Environment Variable | Default | Description |
|---|---|---|
| `SCHEDULE_DISPATCH` | `local` | Dispatch method: `local` / `stepfunctions` |
| `SCHEDULE_TRACKER_ENABLED` | `false` | Enable execution tracking |
| `SCHEDULE_TRACKER_STORE` | `null` | Cache store for tracking |
| `SCHEDULE_TRACKER_LOCK_TTL` | `3600` | Lock TTL (seconds) |
| `SCHEDULE_STATE_MACHINE_ARN` | `null` | Step Functions State Machine ARN |
| `AWS_DEFAULT_REGION` | `ap-northeast-1` | AWS region |

---

## ClockAwareEvent API Reference

### New Methods

| Method | Description |
|---|---|
| `withGracePeriod($minutes)` | Enable recovery and set the grace period. `null` for unlimited. |
| `enableRecovery()` | Enable recovery (no grace period) |
| `dispatchVia($type)` | Specify the Dispatcher type: `'local'` / `'stepfunctions'` |

### Compatibility Table

| Method | Support Status | Notes |
|---|---|---|
| `everyMinute()`, `hourly()`, `daily()`, etc. | Works as-is | |
| `when()` / `skip()` | Works as-is | |
| `between()` / `unlessBetween()` | Works as-is | Automatically clock-aware |
| `withoutOverlapping()` | Works as-is | |
| `runInBackground()` | Works as-is | Recommended |
| `environments()` / `evenInMaintenanceMode()` | Works as-is | |
| `timezone()` | Works as-is | |
| `before()` / `after()` / `onSuccess()` / `onFailure()` | Local only | Not supported with Step Functions |
| `appendOutputTo()` / `sendOutputTo()` | Local only | Not supported with Step Functions |
| `pingBefore()` / `thenPing()` / `emailOutputTo()` | Local only | Not supported with Step Functions |
| `$schedule->call(Closure)` | Not supported | Must be converted to an Artisan command |
| `lastDayOfMonth()` | Limited | May be inaccurate at month boundaries |

See [SCHEDULER_COMPATIBILITY.md](../internals/SCHEDULER_COMPATIBILITY.md) for details.

---

## Next Steps

- [MIGRATION.md](./MIGRATION.md) -- Migration guide from the existing Laravel scheduler
- [IDEMPOTENCY_GUIDE.md](./IDEMPOTENCY_GUIDE.md) -- Idempotency guidelines
- [ARCHITECTURE.md](../internals/ARCHITECTURE.md) -- Architecture design
- [DESIGN.md](../internals/DESIGN.md) -- Design specification
- [SCHEDULER_COMPATIBILITY.md](../internals/SCHEDULER_COMPATIBILITY.md) -- Method compatibility details
- [SCHEDULE_EXECUTION_SEMANTICS.md](../internals/SCHEDULE_EXECUTION_SEMANTICS.md) -- Execution guarantee details
- [STEPFUNCTIONS_IMPLEMENTATION.md](../internals/STEPFUNCTIONS_IMPLEMENTATION.md) -- Step Functions implementation details
- [STEPFUNCTIONS_CONSIDERATIONS.md](./STEPFUNCTIONS_CONSIDERATIONS.md) -- Step Functions considerations
- [SCHEDULER_COMPARISON.md](../internals/SCHEDULER_COMPARISON.md) -- Scheduler comparison
