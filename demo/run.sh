#!/bin/sh
# Run the MissCache demo: starts the backend, runs the demo, stops the backend.
cd "$(dirname "$0")" || exit 1

php -S 127.0.0.1:8077 backend.php >/dev/null 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null' EXIT

# wait for the backend to accept connections
i=0
while [ $i -lt 30 ]; do
    if curl -s -o /dev/null "http://127.0.0.1:8077/backend.php"; then break; fi
    i=$((i + 1))
done

php demo.php
