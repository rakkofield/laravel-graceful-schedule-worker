#!/bin/sh
set -e

# ===================================================================
# Scenario: Deploy Simulation
# ===================================================================
# Demonstrates recovery after a simulated deployment (SIGTERM + restart).
#
# Timeline:
#   0:00 - 3:00  Normal operation (3 ticks)
#   3:00 - 6:00  Worker stopped (3 minutes missed)
#   6:00 - 8:00  Restarted (recovery + normal ticks)
#
# Expected result: recovery tick appears in the report.
# Duration: ~8 minutes
# ===================================================================

COMPOSE="docker compose -f compose.yaml --profile compare"
cd "$(dirname "$0")/.."

cleanup() {
    echo ""
    echo "==> Cleaning up..."
    $COMPOSE down 2>/dev/null || true
}
trap cleanup EXIT

echo "==> Starting Redis..."
$COMPOSE up -d --wait redis

echo "==> Resetting demo data..."
$COMPOSE run --rm graceful php artisan demo:reset

echo "==> Starting graceful worker..."
$COMPOSE up -d graceful

echo "==> Waiting 3 minutes for normal execution..."
sleep 180

echo "==> Simulating deploy: stopping graceful worker (SIGTERM)..."
$COMPOSE stop graceful

echo "==> Worker stopped. Waiting 3 minutes (missed executions)..."
sleep 180

echo "==> Deploy complete: restarting graceful worker..."
$COMPOSE up -d graceful

echo "==> Waiting 2 minutes for recovery + normal execution..."
sleep 120

echo ""
echo "==> Report:"
$COMPOSE run --rm graceful php artisan demo:report --worker=graceful

echo ""
echo "==> Scenario complete."
