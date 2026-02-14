#!/bin/sh
set -e

# ===================================================================
# Scenario: cron vs graceful Comparison
# ===================================================================
# Runs both cron (schedule:run) and graceful (schedule:graceful-work)
# side by side, then stops and restarts both to compare recovery.
#
# Timeline:
#   0:00 - 3:00  Both running normally
#   3:00 - 6:00  Both stopped (missed executions)
#   6:00 - 8:00  Both restarted (only graceful recovers)
#
# Expected result: graceful has 1 more tick than cron.
# Duration: ~8 minutes
# ===================================================================

cd "$(dirname "$0")/.."

cleanup() {
    echo ""
    echo "==> Cleaning up..."
    docker compose --profile compare down 2>/dev/null || true
}
trap cleanup EXIT

echo "==> Starting Redis..."
docker compose up -d --wait redis

echo "==> Resetting demo data..."
docker compose run --rm php php artisan demo:reset

echo "==> Starting both workers (graceful + cron)..."
docker compose --profile compare up -d --build php cron

echo "==> Waiting 3 minutes for normal execution..."
sleep 180

echo "==> Simulating outage: stopping both workers..."
docker compose --profile compare stop php cron

echo "==> Workers stopped. Waiting 3 minutes (missed executions)..."
sleep 180

echo "==> Restarting both workers..."
docker compose --profile compare up -d php cron

echo "==> Waiting 2 minutes for recovery + normal execution..."
sleep 120

echo ""
echo "==> Comparison Report:"
docker compose run --rm php php artisan demo:report --worker=compare

echo ""
echo "==> Scenario complete."
