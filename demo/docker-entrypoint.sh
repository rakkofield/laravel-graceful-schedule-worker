#!/bin/sh
set -e

# Redirect scheduler log to container stdout (outside volume mount)
ln -sf /proc/1/fd/1 /tmp/scheduler.log

# The image builds a .env (composer post-root-package-install) and generates a key, but
# compose mounts the host demo directory over /app/demo and hides both. Without a .env,
# SCHEDULE_STATE_MACHINE_ARN is unset, the Step Functions dispatcher is never registered,
# and the first dispatchVia('stepfunctions') event kills the worker with
# "Unknown dispatcher type: stepfunctions". Recreate them here, after the mount exists.
if [ ! -f .env ]; then
  cp .env.example .env
  echo "Created .env from .env.example."
fi

if ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --no-interaction
fi

# Create State Machine in moto (idempotent)
if [ -n "$SFN_ENDPOINT" ]; then
  php bin/setup-stepfunctions.php
fi

exec "$@"
