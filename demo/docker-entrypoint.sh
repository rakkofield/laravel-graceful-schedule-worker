#!/bin/sh
set -e

# Redirect scheduler log to container stdout (outside volume mount)
ln -sf /proc/1/fd/1 /tmp/scheduler.log

# Create State Machine in moto (idempotent)
if [ -n "$SFN_ENDPOINT" ]; then
  php bin/setup-stepfunctions.php
fi

exec "$@"
