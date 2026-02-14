#!/bin/sh
set -e

# ===================================================================
# Scenario: Step Functions Deployment Resilience
# ===================================================================
# Compares local process vs Step Functions behavior during deployment:
#
#   Local process:  worker kill -> child killed -> task interrupted
#   Step Functions:  worker kill -> SF runs on AWS -> task completes
#
# Flow:
#   1. Start worker with DEMO_SCENARIO=stepfunctions
#   2. Wait for long-task to reach step 3
#   3. Hard-kill the worker (simulating deployment)
#   4. Check: local task interrupted, SF execution succeeded
#
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

echo "==> Starting graceful worker (DEMO_SCENARIO=stepfunctions)..."
DEMO_SCENARIO=stepfunctions docker compose up -d --build php

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

echo "==> Hard-killing worker (simulating deployment)..."
docker compose kill php

echo "==> Waiting for Step Functions to complete..."
sleep 5

echo ""
echo "=== Deployment Impact Comparison ==="
echo ""

# Check local task status
echo "Local Process (demo:long-task):"
docker compose run --rm php php -r "
    require '/app/demo/vendor/autoload.php';
    \$app = require_once '/app/demo/bootstrap/app.php';
    \$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
    \$status = \Illuminate\Support\Facades\Cache::get('demo:longtask:status', 'unknown');
    \$lastStep = \Illuminate\Support\Facades\Cache::get('demo:longtask:last-step', 'unknown');
    \$steps = \Illuminate\Support\Facades\Cache::get('demo:longtask:steps', 'unknown');
    echo \"  Status: \$status at step \$lastStep/\$steps\n\";
    echo \"  Impact: Task was killed during deployment\n\";
"

echo ""
echo "Step Functions:"
docker compose run --rm -e SFN_ENDPOINT=http://moto:5000 php php bin/check-stepfunctions.php

echo ""
echo "Conclusion:"
echo "  Local tasks are tied to the worker process lifecycle."
echo "  Step Functions tasks execute independently on AWS."

echo ""
echo "==> Scenario complete."
