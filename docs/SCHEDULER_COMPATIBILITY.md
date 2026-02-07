# Laravel Scheduler Compatibility

## Overview

Laravel scheduler compatibility analysis for `laravel-graceful-worker`.

## Clock Integration

`ClockAwareEvent` overrides the following methods from Laravel's `Event` class to use the injected `ClockInterface` instead of `Carbon::now()`:

### `expressionPasses()`

Uses `$this->clock->now()` instead of `Carbon::now()` for cron expression evaluation. This ensures `isDue()` checks are consistent with the injected clock.

### `between()` / `unlessBetween()`

Overrides the parent's `ManagesFrequencies` trait methods. The parent uses `inTimeInterval()` (private), which evaluates `Carbon::now()` at **definition time** and captures it in a closure. In a long-running worker, this means the time check is frozen at startup.

The `ClockAwareEvent` implementation evaluates `$this->clock->now()` lazily inside the closure, ensuring correct time checks on every evaluation.

### `FreezableClock` — Evaluation Time Consistency

`ClockAwareSchedule` wraps the injected `ClockInterface` in a `FreezableClock` and passes it to all `ClockAwareEvent` instances. During each evaluation cycle, the `DefaultScheduleOrchestrator` calls `ClockAwareSchedule::evaluateAt($now, $callback)`, which freezes the clock for the duration of the callback. This ensures that `dueEvents()` (via `expressionPasses()`) and `filtersPass()` (via `between()` / `unlessBetween()`) all observe the same timestamp within a single evaluation cycle.

Without this mechanism, when using `SystemClock`, the clock could advance across minute boundaries between `dueEvents()` and `filtersPass()` calls, leading to inconsistent time-based decisions.

### Known Limitation: `lastDayOfMonth()`

`lastDayOfMonth()` uses `Carbon::now()` to determine the current month and sets the cron expression's day field statically. In a long-running worker that spans month boundaries, this could become inaccurate. Fixing this would require dynamic cron expression re-evaluation, which adds significant complexity. This is documented as a known limitation.

## Two-Stage Filtering in Laravel Scheduler

Laravel's `schedule:run` uses a two-stage filtering pipeline:

```
1. isDue($app)       -- cron expression + timezone + maintenance mode + environment check (via dueEvents())
2. filtersPass($app)  -- when()/skip()/between()/withoutOverlapping() etc.
3. Event::run()       -- actual command execution
```

`dueEvents($app)` returns events where `isDue()` is true. `isDue()` checks the cron expression, timezone, maintenance mode (`evenInMaintenanceMode()`), and environment (`environments()`).
`filtersPass($app)` evaluates runtime filters registered via `when()`, `skip()`, `between()`, `unlessBetween()`, `withoutOverlapping()`, etc.

## filtersPass and Recovery Dispatch

### Normal dispatch: filtersPass is checked

Normal dispatch (due events at the current minute) checks `filtersPass($app)` before dispatching, matching Laravel's standard behavior.

### Recovery dispatch: filtersPass is NOT checked

Recovery dispatch (missed events detected at startup) does **not** check `filtersPass()`.

**Rationale**: `between()`, `unlessBetween()`, and other time-based filters evaluate the current time. Recovery targets a past `dueAt` time, so evaluating these filters at the current time would produce incorrect results.

## withoutOverlapping vs TrackingDispatcher Lock

These serve different purposes:

| | `withoutOverlapping()` | TrackingDispatcher lock |
|---|---|---|
| **Purpose** | Prevent new execution while previous is still running (cross-minute) | Prevent duplicate dispatch for the same `dueAt` (within-minute idempotency) |
| **Scope** | EventMutex-based, per-event | CacheExecutionTracker-based, per-event-per-dueAt |
| **Mechanism** | `filtersPass()` checks `EventMutex::exists()` | `acquireLock()` before dispatch |

Both can coexist. `withoutOverlapping()` is checked via `filtersPass()` before reaching the dispatcher. The TrackingDispatcher lock provides additional idempotency at the dispatch level.

## Compatibility Status

### Supported (via filtersPass)

| Feature | Method | Status |
|---|---|---|
| Conditional execution | `when($callback)` | Supported |
| Conditional skip | `skip($callback)` | Supported |
| Time range | `between($start, $end)` | Supported (clock-aware, lazy evaluation) |
| Time range exclusion | `unlessBetween($start, $end)` | Supported (clock-aware, lazy evaluation) |
| Overlap prevention | `withoutOverlapping($minutes)` | Supported |

### Supported (via isDue)

| Feature | Method | Status |
|---|---|---|
| Environment filter | `environments($envs)` | Supported |
| Maintenance mode | `evenInMaintenanceMode()` | Supported |

### Inherited from Laravel Event (no additional work needed)

| Feature | Method | Status |
|---|---|---|
| Cron expression | `cron()`, `everyMinute()`, `hourly()`, `daily()`, etc. | Inherited |
| Timezone | `timezone($tz)` | Inherited |
| Day constraints | `weekdays()`, `sundays()`, etc. | Inherited |
| Output handling | `sendOutputTo()`, `appendOutputTo()` | Inherited |
| Background execution | `runInBackground()` | Inherited |

### Not applicable in this library

| Feature | Method | Reason |
|---|---|---|
| Before/After callbacks | `before()`, `after()`, `then()` | Events are dispatched, not run directly by the orchestrator |
| Ping URLs | `pingBefore()`, `thenPing()` | Same as above |
| Email output | `emailOutputTo()` | Same as above |

### Known Limitations

| Feature | Method | Limitation |
|---|---|---|
| Last day of month | `lastDayOfMonth()` | Uses `Carbon::now()` at definition time to set cron day field. May be inaccurate across month boundaries in long-running workers. |
