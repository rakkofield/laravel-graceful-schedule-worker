#!/bin/sh
set -e

# ===================================================================
# Scenario: Signal Handling Visualization
# ===================================================================
# Demonstrates graceful shutdown when a background process receives SIGTERM.
#
# Flow:
#   1. Start worker with DEMO_SCENARIO=signal
#   2. Wait for long-task to reach step 3
#   3. Send SIGTERM to the worker
#   4. Observe: worker stops loop, child finishes current step, then exits
#
# Expected result: long-task stops gracefully mid-execution.
# Duration: ~2 minutes
# ===================================================================

cd "$(dirname "$0")/.."

cleanup() {
    echo ""
    echo "==> Cleaning up..."
    docker compose down 2>/dev/null || true
}
trap cleanup EXIT

echo "==> Starting Redis and moto..."
docker compose up -d --wait redis moto

echo "==> Resetting demo data..."
docker compose run --rm php php artisan demo:reset

echo "==> Starting graceful worker (DEMO_SCENARIO=signal)..."
DEMO_SCENARIO=signal docker compose up -d --build php

echo "==> Waiting for long-task to reach step 3..."
TIMEOUT=120
ELAPSED=0
while [ "$ELAPSED" -lt "$TIMEOUT" ]; do
    if docker compose logs php 2>/dev/null | grep -q "Step 3/"; then
        echo "==> Step 3 detected!"
        break
    fi
    sleep 2
    ELAPSED=$((ELAPSED + 2))
done

if [ "$ELAPSED" -ge "$TIMEOUT" ]; then
    echo "==> Timeout waiting for step 3. Showing logs:"
    docker compose logs php
    exit 1
fi

echo "==> Sending SIGTERM to worker..."
docker compose kill -s SIGTERM php

echo "==> Waiting for graceful shutdown..."
sleep 5

echo ""
echo "=== Signal Handling Results ==="
echo ""
docker compose logs php 2>/dev/null | grep "\[long-task\]" || true

echo ""
echo "==> Redis status check:"
docker compose run --rm php php -r "
    require '/app/demo/vendor/autoload.php';
    \$app = require_once '/app/demo/bootstrap/app.php';
    \$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
    \$status = \Illuminate\Support\Facades\Cache::get('demo:longtask:status', 'unknown');
    \$lastStep = \Illuminate\Support\Facades\Cache::get('demo:longtask:last-step', 'unknown');
    \$steps = \Illuminate\Support\Facades\Cache::get('demo:longtask:steps', 'unknown');
    echo \"  Status:    \$status\n\";
    echo \"  Last step: \$lastStep/\$steps\n\";
"

echo ""
echo "==> Scenario complete."
