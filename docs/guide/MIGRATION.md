# Migration Guide from the Existing Laravel Scheduler

## Overview

This guide provides step-by-step instructions for migrating from the existing Laravel scheduler (`schedule:work`) to `laravel-graceful-schedule-worker`.

**Key Points:**

- The contents of your existing `schedule()` method can be **copied almost as-is**
- You can start with minimal changes (3 steps)
- Recovery features and Step Functions integration can be added incrementally later
- You can roll back at any time

## Migration Overview

| Phase | Description | Required |
|---|---|---|
| Phase 1 | Package installation + basic migration | Yes |
| Phase 2 | Add recovery features | Optional |
| Phase 3 | Step Functions integration | Optional |

---

## Phase 1: Package Installation

### Kernel.php Before Changes

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

### Choosing a Migration Method

#### Method A: All-at-once Migration (Recommended when you have few tasks)

Copy the contents of `schedule()` to `gracefulSchedule()` and switch everything at once. The steps below describe this method.

#### Method B: Gradual Migration (Recommended when you have many tasks or want to minimize risk)

Keep `schedule()` as-is and move tasks one by one to `gracefulSchedule()`.

- Tasks remaining in `schedule()` -> Laravel standard `Event` (no behavior change)
- Tasks moved to `gracefulSchedule()` -> `ClockAwareEvent` (new behavior)

```php
class Kernel extends ConsoleKernel
{
    use UsesClockAwareSchedule;

    // Tasks not yet migrated (no behavior change)
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('emails:send')->everyFiveMinutes();
        $schedule->command('cache:prune')->hourly();
    }

    // Migrated tasks (clock-aware + recovery support)
    protected function gracefulSchedule(ClockAwareSchedule $schedule)
    {
        $schedule->command('reports:daily')->dailyAt('02:00')
            ->runInBackground()
            ->withGracePeriod(30);
    }
}
```

> **Note:** Do not define the same task in both methods (it will result in duplicate execution).

### Steps

#### Step 1: Install the Package

```shell
composer require rakko-inc/laravel-graceful-schedule-worker
```

The ServiceProvider is auto-discovered.

#### Step 2: Publish the Configuration File

```shell
php artisan vendor:publish --provider="RakkoInc\LaravelGracefulScheduleWorker\Providers\GracefulScheduleWorkerProvider"
```

#### Step 3: Modify the Kernel

Make 3 changes:

1. Add the `UsesClockAwareSchedule` trait
2. Rename `schedule(Schedule $schedule)` to `gracefulSchedule(ClockAwareSchedule $schedule)`
3. Add `runInBackground()` to each task (recommended)

### Kernel.php After Changes

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

### About Behavior Changes

When the trait is applied, all schedule events become `ClockAwareEvent`. Even when running with `schedule:work`, the following behaviors change:

| Area | Before (Laravel Standard) | After (ClockAwareEvent) |
|---|---|---|
| `expressionPasses()` | `Carbon::now()` | `SystemClock::now()` (`DateTimeImmutable`) |
| `between()` / `unlessBetween()` | `Carbon::now()` evaluated immediately at definition time | `clock->now()` lazy-evaluated on each check |
| `lastDayOfMonth()` | `Carbon::now()` | No change (known limitation) |

With `schedule:work` (runs once per minute), the practical impact is minimal, but be aware that the internal implementation of time evaluation changes. If problems occur, you can immediately revert using the [rollback procedure](#rollback-procedure).

### Switching from schedule:work to schedule:graceful-work

Kernel changes (trait application) and command switching can be done independently. For gradual migration, the following order is recommended:

1. **First, modify the Kernel** and verify there are no issues while still using `schedule:work`
2. **Then switch the command** (change to `schedule:graceful-work`)

```diff
-php artisan schedule:work
+php artisan schedule:graceful-work
```

### Verification

1. Start `php artisan schedule:graceful-work`
2. Verify that scheduled tasks execute normally
3. Send SIGTERM with `Ctrl+C` or `kill <pid>` and verify graceful shutdown

### Rollback Procedure

If problems occur, you can revert with the following steps:

1. Remove `use UsesClockAwareSchedule;` from Kernel.php
2. Change `gracefulSchedule(ClockAwareSchedule $schedule)` back to `schedule(Schedule $schedule)`
3. Change the use statement back to `Illuminate\Console\Scheduling\Schedule`
4. Switch the command back to `schedule:work`

The package itself can remain installed without any issues.

---

## Phase 2: Adding Recovery Features

Proceed after verifying that Phase 1 is running stably.

### Adding .env Configuration

```env
SCHEDULE_TRACKER_ENABLED=true
SCHEDULE_TRACKER_STORE=redis
```

### Adding withGracePeriod()

Add only to tasks that need recovery.

```php
protected function gracefulSchedule(ClockAwareSchedule $schedule)
{
    // Important task -> recovery enabled
    $schedule->command('reports:daily')->dailyAt('02:00')
        ->runInBackground()
        ->withoutOverlapping(10)
        ->withGracePeriod(30);  // Automatically recover missed executions within 30 minutes

    // Task that doesn't need recovery -> leave as-is
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

### Idempotency Verification Checklist

Verify the following for tasks with `withGracePeriod()`:

- [ ] Does the task produce the same result even if executed twice?
- [ ] Do DB operations use `updateOrInsert` or unique constraints?
- [ ] Do external API calls have deduplication mechanisms?
- [ ] Do file generation operations use deterministic names?

See [IDEMPOTENCY_GUIDE.md](./IDEMPOTENCY_GUIDE.md) for details.

---

## Phase 3: Step Functions Integration (Optional)

### Decision Criteria

Consider introducing Step Functions if any of the following apply:

- You want AWS to manage task retries and timeouts
- You want to visualize task execution status through the Step Functions console
- You want to execute jobs as individual ECS Tasks

Not needed if local execution is sufficient.

### Configuration Steps Overview

1. Install `aws/aws-sdk-php`
2. Create a Step Functions State Machine
3. Set `SCHEDULE_DISPATCH=stepfunctions` and `SCHEDULE_STATE_MACHINE_ARN` in `.env`
4. Add `dispatchVia('stepfunctions')` to the necessary tasks

> **Note:** When using Step Functions, `before()` / `after()` / `onSuccess()` / `onFailure()` / `appendOutputTo()` do not work.

See [STEPFUNCTIONS_IMPLEMENTATION.md](../internals/STEPFUNCTIONS_IMPLEMENTATION.md) for details.

---

## Method Compatibility Table

| Existing Usage | Support in gracefulSchedule() | Notes |
|---|---|---|
| `everyMinute()`, `hourly()`, `daily()`, etc. | Works as-is | |
| `when()` / `skip()` | Works as-is | |
| `between()` / `unlessBetween()` | Works as-is | Changed to lazy evaluation (see [Behavior Changes](#about-behavior-changes)) |
| `withoutOverlapping()` | Works as-is | |
| `runInBackground()` | Works as-is (recommended) | |
| `environments()` / `evenInMaintenanceMode()` | Works as-is | |
| `timezone()` | Works as-is | |
| `before()` / `after()` / `onSuccess()` / `onFailure()` | Local only | Not supported with Step Functions |
| `appendOutputTo()` / `sendOutputTo()` | Local only | Not supported with Step Functions |
| `pingBefore()` / `thenPing()` / `emailOutputTo()` | Local only | Not supported with Step Functions |
| `$schedule->call(Closure)` | Not supported | Must be converted to an Artisan command |
| `lastDayOfMonth()` | Limited | May be inaccurate at month boundaries |

---

## FAQ

### Q: Can schedule() and gracefulSchedule() coexist?

**Yes, they can coexist.** Tasks left in `schedule()` operate as Laravel standard `Event` (no behavior change), while tasks moved to `gracefulSchedule()` operate as `ClockAwareEvent`. This allows gradual migration on a per-task basis.

See [Choosing a Migration Method](#choosing-a-migration-method) for details.

> **Note:** Defining the same task in both `schedule()` and `gracefulSchedule()` will cause duplicate execution. Always remove the task from the original method when moving it.

### Q: Can schedule:work and schedule:graceful-work run simultaneously?

Technically possible, but **not recommended as it causes duplicate execution of the same tasks**. Switch them at the same time.

### Q: What about Closure jobs ($schedule->call())?

`$schedule->call(Closure)` is not supported. Convert them to Artisan commands.

```php
// Before (not supported)
$schedule->call(function () {
    DB::table('recent_users')->delete();
})->daily();

// After
// 1. Create an Artisan command
//    php artisan make:command PruneRecentUsers
// 2. Register in the schedule
$schedule->command('users:prune-recent')->daily()
    ->runInBackground();
```

### Q: Can withoutOverlapping() be used as-is?

Yes, it works as-is. `withoutOverlapping()` uses Laravel's `EventMutex`, which is a separate mechanism from the package's `TrackingDispatcher` lock. Both coexist without issues.

### Q: Is runInBackground() required?

It is not required, but **strongly recommended**. Without `runInBackground()`, tasks run sequentially, and the next task won't start until the previous one finishes.

---

## Important Notes

- The recovery feature operates with **at-least-once semantics**. Design recovery-target tasks to be idempotent.
- `ext-pcntl` is required (used for signal handling).
- PHP 7.2.5 or later is required.
