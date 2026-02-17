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

cd "$(dirname "$0")/.."

cleanup() {
    echo ""
    echo "==> Cleaning up..."
    docker compose down -v 2>/dev/null || true
}
trap cleanup EXIT

echo "==> Starting Redis and moto..."
docker compose up -d --wait redis moto

echo "==> Resetting demo data..."
docker compose run --rm php php artisan demo:reset

echo "==> Starting graceful worker..."
docker compose up -d --build php

echo "==> Waiting 3 minutes for normal execution..."
sleep 180

echo "==> Simulating deploy: stopping graceful worker (SIGTERM)..."
docker compose stop php

echo "==> Worker stopped. Waiting 3 minutes (missed executions)..."
sleep 180

echo "==> Deploy complete: restarting graceful worker..."
docker compose up -d php

echo "==> Waiting 2 minutes for recovery + normal execution..."
sleep 120

echo ""
echo "==> Report:"
docker compose run --rm php php artisan demo:report --worker=graceful

echo ""
echo "==> Scenario complete."
