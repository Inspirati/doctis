#!/usr/bin/env bash
set -e

echo "Finding unused volumes..."
UNUSED=$(docker volume ls -qf dangling=true)

if [ -z "$UNUSED" ]; then
    echo "No unused volumes found."
    exit 0
fi

echo "Removing unused volumes..."
echo "$UNUSED" | xargs docker volume rm

echo "Done cleaning unused volumes."

