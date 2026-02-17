#!/bin/sh
set -e

# ===================================================================
# Scenario: Overlap Prevention
# ===================================================================
# Demonstrates withoutOverlapping() preventing concurrent execution
# of a slow task (90s) scheduled every minute.
#
# Timeline:
#   0:00 - 5:00  Worker runs with demo:slow-task (90s duration)
#                 ~2-3 executions, ~2 skipped due to overlap
#
# Expected result: EXECUTED and SKIPPED (overlap) alternate in report.
# Duration: ~5 minutes
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

echo "==> Starting graceful worker (DEMO_SCENARIO=overlap)..."
DEMO_SCENARIO=overlap docker compose up -d --build php

echo "==> Waiting 5 minutes for overlap demonstration..."
echo "    (90s task scheduled every minute — some will be skipped)"

for i in 1 2 3 4 5; do
    echo "    ... minute ${i}/5"
    sleep 60
done

echo ""
echo "==> Scheduler log (overlap-related entries):"
docker compose logs php 2>/dev/null | grep -i -E "(slow-task|overlap|skipped)" | tail -20 || true

echo ""
echo "==> Report:"
docker compose run --rm php php artisan demo:report --worker=overlap

echo ""
echo "==> Scenario complete."
