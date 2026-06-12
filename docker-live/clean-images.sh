#!/usr/bin/env bash
set -e

echo "Pruning unused Docker images..."
docker image prune -a -f

echo "Done removing unused images."

