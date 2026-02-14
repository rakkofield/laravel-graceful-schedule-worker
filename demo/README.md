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
  2026-02-14T10:05     MISSED         OK           ← recovery
  2026-02-14T10:06     OK             OK
```

The graceful worker executes 1 more tick than cron thanks to recovery.

## Manual Operations

```bash
# Reset demo data (clear ticks + tracker keys)
docker compose --profile compare run --rm graceful php artisan demo:reset

# View single worker report
docker compose --profile compare run --rm graceful php artisan demo:report --worker=graceful
docker compose --profile compare run --rm graceful php artisan demo:report --worker=cron

# View comparison report
docker compose --profile compare run --rm graceful php artisan demo:report --worker=compare
```

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
