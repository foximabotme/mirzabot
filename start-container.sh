#!/bin/bash

set -euo pipefail

export MIRZA_DATA_DIR="${MIRZA_DATA_DIR:-/data/mirzabot}"
export MIRZA_INTERNAL_SCHEDULER=1

php /app/railway/prepare_runtime.php
rm -rf /app/install

ready=0
for attempt in $(seq 1 45); do
    if php /app/railway/bootstrap.php; then
        ready=1
        break
    fi
    echo "MirzaBot bootstrap attempt ${attempt}/45 failed; retrying in 2 seconds."
    sleep 2
done

if [ "$ready" -ne 1 ]; then
    echo "MirzaBot could not connect to its database or validate required variables."
    exit 1
fi

(
    while true; do
        php /app/railway/cron_runner.php
        echo "MirzaBot scheduler stopped unexpectedly; restarting in 3 seconds."
        sleep 3
    done
) &
scheduler_pid=$!

cleanup() {
    kill "$scheduler_pid" 2>/dev/null || true
}
trap cleanup EXIT TERM INT

docker-php-entrypoint --config /Caddyfile --adapter caddyfile 2>&1
