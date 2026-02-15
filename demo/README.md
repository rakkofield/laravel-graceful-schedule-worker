# Laravel Graceful Schedule Worker — Demo

This demo shows how `schedule:graceful-work` with `enableRecovery()` recovers missed task executions after a deployment or outage, compared to standard `schedule:run` (cron).

## Prerequisites

- Docker and Docker Compose

## Quick Start

Default mode runs `schedule:graceful-work` with moto (Step Functions mock) and Redis:

```bash
docker compose up --build
```

This starts:
- **moto** — AWS Step Functions mock
- **redis** — Cache and tracker store
- **php** — `schedule:graceful-work` with demo:tick and Step Functions example

## Scenario 1: Deploy Simulation (~8 min)

Demonstrates recovery after a simulated deployment:

```bash
bin/scenario-deploy.sh
```

**What happens:**
1. Graceful worker runs normally for 3 minutes
2. Worker receives SIGTERM (simulating deploy) and stops
3. 3 minutes pass with no execution
4. Worker restarts — recovery fires immediately
5. Report shows the recovered tick

## Scenario 2: cron vs graceful Comparison (~8 min)

Runs both `schedule:run` (cron) and `schedule:graceful-work` side by side:

```bash
bin/scenario-compare.sh
```

**Expected output:**

```
  Minute               cron           graceful
  -----------------------------------------------
  2026-02-14T10:01     OK             OK
  2026-02-14T10:02     OK             OK
  2026-02-14T10:03     MISSED         MISSED
  2026-02-14T10:04     MISSED         MISSED
  2026-02-14T10:05     MISSED         RECOVERED    ← recovery
  2026-02-14T10:06     OK             OK
```

The graceful worker executes 1 more tick than cron thanks to recovery.

## Scenario 3: Signal Handling (~2 min)

Visualizes graceful shutdown when a background process receives SIGTERM:

```bash
bin/scenario-signal.sh
```

**What happens:**
1. Worker starts `demo:long-task` (8-step task, 1 second per step) in background
2. After step 3, SIGTERM is sent to the worker
3. Worker stops the loop and sends SIGTERM to the child process
4. Child finishes its current step and exits gracefully
5. Redis shows the final status (`stopped-gracefully`) and last completed step

## Scenario 4: Step Functions Deployment Resilience (~2 min)

Compares local process vs Step Functions behavior during a deployment (worker kill):

```bash
bin/scenario-stepfunctions.sh
```

**What happens:**
1. Worker starts both `demo:long-task` (local) and a Step Functions execution
2. After step 3, the worker is hard-killed (simulating deployment)
3. Local task is interrupted mid-execution
4. Step Functions execution completes independently on moto

**Expected output:**
- Local process: `INTERRUPTED at step 3/8`
- Step Functions: `SUCCEEDED`

## Scenario 5: Overlap Prevention (~5 min)

Demonstrates `withoutOverlapping()` preventing concurrent execution of a slow task:

```bash
bin/scenario-overlap.sh
```

**What happens:**
1. A 90-second task (`demo:slow-task`) is scheduled every minute with `withoutOverlapping()`
2. While the first execution is running, the next scheduled run is skipped
3. After the first execution completes, the next scheduled run executes normally
4. Report shows which minutes executed and which were skipped

**Expected output:**

```
  Minute               Status
  --------------------------------
  2026-02-15T10:01     EXECUTED
  2026-02-15T10:02     SKIPPED (overlap)
  2026-02-15T10:03     EXECUTED
  2026-02-15T10:04     SKIPPED (overlap)
  2026-02-15T10:05     EXECUTED

3 executions, 2 skipped due to overlap
```

## Scenario 6: Execution Tracker Visualization (~7 min)

Shows the execution tracker's Redis data across normal operation, downtime, and recovery:

```bash
bin/scenario-tracker.sh
```

**What happens:**
1. **Phase 1 (Normal):** Worker runs for 2 minutes — tracker shows fresh data with small elapsed times
2. **Phase 2 (Downtime):** Worker stopped for 2 minutes — tracker shows growing elapsed times
3. **Phase 3 (Recovery):** Worker restarts — recovery fires, tracker data updates

**Tracker dashboard shows:**
- `[Last Executed Times]` — Unix timestamps, elapsed time, and TTL for each tracked task
- `[Active Locks]` — Recovery locks that prevent duplicate recovery dispatches
- `[Key Format Guide]` — Explanation of the key naming convention

## Manual Operations

```bash
# Reset demo data (clear ticks + tracker keys + long-task keys)
docker compose run --rm php php artisan demo:reset

# View single worker report
docker compose run --rm php php artisan demo:report --worker=graceful
docker compose run --rm php php artisan demo:report --worker=cron

# View comparison report
docker compose run --rm php php artisan demo:report --worker=compare

# View overlap report
docker compose run --rm php php artisan demo:report --worker=overlap

# View tracker dashboard
docker compose run --rm php php artisan demo:tracker

# Check Step Functions execution history
docker compose run --rm -e SFN_ENDPOINT=http://moto:5000 php php bin/check-stepfunctions.php
```

## DEMO_SCENARIO Environment Variable

The `DEMO_SCENARIO` variable controls which tasks are registered in `gracefulSchedule()`:

| Value | Tasks |
|-------|-------|
| _(empty/default)_ | `demo:tick` with recovery + Step Functions echo |
| `signal` | `demo:long-task` (SIGTERM handling demo) |
| `overlap` | `demo:slow-task` with `withoutOverlapping()` |
| `stepfunctions` | `demo:long-task` + Step Functions echo |

## How Recovery Works

1. `demo:tick --worker=graceful` is registered with `enableRecovery()` in `gracefulSchedule()`
2. Each execution records its minute to Redis via `demo:tick`
3. The `CacheExecutionTracker` also records the last executed due time
4. When the worker restarts after downtime, `checkMissedExecutions()` compares:
   - The previous scheduled run time (based on current time)
   - The last executed due time from the tracker
5. If they differ, the missed event is dispatched immediately (recovery)
6. `demo:report` reads the Redis timeline and shows OK/MISSED for each minute

Standard `schedule:run` (cron) has no tracker — it only checks "is this task due right now?" and cannot detect missed executions.
