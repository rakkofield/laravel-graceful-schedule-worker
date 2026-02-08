#!/bin/sh
set -e

# Create State Machine in moto (idempotent)
if [ -n "$SFN_ENDPOINT" ]; then
  php bin/setup-stepfunctions.php
fi

exec "$@"
