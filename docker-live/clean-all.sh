#!/usr/bin/env bash
set -e

echo "Stopping all containers..."
docker stop $(docker ps -aq) 2>/dev/null || true

echo "Removing all containers..."
docker rm -f $(docker ps -aq) 2>/dev/null || true

echo "Removing all volumes..."
docker volume rm $(docker volume ls -q) 2>/dev/null || true

echo "Pruning everything (images, networks, build cache, dangling stuff)..."
docker system prune -a --volumes -f

echo "Done. Docker system fully cleaned."

