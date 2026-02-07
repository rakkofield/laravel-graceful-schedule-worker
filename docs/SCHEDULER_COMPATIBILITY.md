# Laravel Scheduler Compatibility

## Overview

Laravel scheduler compatibility analysis for `laravel-graceful-worker`.

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

**Rationale**: `between()`, `unlessBetween()`, and other time-based filters use `Carbon::now()` internally. Recovery targets a past `dueAt` time, so evaluating these filters at the current time would produce incorrect results.

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
| Time range | `between($start, $end)` | Supported |
| Time range exclusion | `unlessBetween($start, $end)` | Supported |
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
