#!/bin/sh
set -e

# ===================================================================
# Scenario: Execution Tracker Visualization
# ===================================================================
# Shows how the execution tracker stores data in Redis and how
# recovery works by visualizing tracker state across 3 phases.
#
# Phase 1: Normal operation (2 min) — fresh tracker data
# Phase 2: Downtime (2 min)         — elapsed time grows
# Phase 3: Recovery (2 min)         — tracker updated after recovery
#
# Uses the default scenario (demo:tick with enableRecovery()).
# Duration: ~7 minutes
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

# -----------------------------------------------
# Phase 1: Normal operation
# -----------------------------------------------
echo ""
echo "========================================="
echo "  Phase 1: Normal Operation (2 minutes)"
echo "========================================="
echo ""

echo "==> Starting graceful worker..."
docker compose up -d --build php

echo "==> Waiting 2 minutes for normal execution..."
sleep 120

echo ""
echo "==> Tracker dashboard (after normal operation):"
docker compose run --rm php php artisan demo:tracker

# -----------------------------------------------
# Phase 2: Downtime
# -----------------------------------------------
echo ""
echo "========================================="
echo "  Phase 2: Downtime (2 minutes)"
echo "========================================="
echo ""

echo "==> Stopping worker (simulating outage)..."
docker compose stop php

echo "==> Waiting 2 minutes (no execution)..."
sleep 120

echo ""
echo "==> Tracker dashboard (after downtime — elapsed should be large):"
docker compose run --rm php php artisan demo:tracker

# -----------------------------------------------
# Phase 3: Recovery
# -----------------------------------------------
echo ""
echo "========================================="
echo "  Phase 3: Recovery (2 minutes)"
echo "========================================="
echo ""

echo "==> Restarting worker (recovery should trigger)..."
docker compose up -d php

echo "==> Waiting 2 minutes for recovery + normal execution..."
sleep 120

echo ""
echo "==> Tracker dashboard (after recovery — last executed should be recent):"
docker compose run --rm php php artisan demo:tracker

echo ""
echo "==> Tick report:"
docker compose run --rm php php artisan demo:report --worker=graceful

echo ""
echo "==> Scenario complete."
