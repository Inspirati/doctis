#!/usr/bin/env bash
set -e

COMPOSE="docker compose"   # works for Compose v2 and v1 if aliased

echo "Bringing down project containers, networks, and anonymous volumes..."
$COMPOSE down -v --remove-orphans || true

echo "Done cleaning project."


# USAGE: If you want to target a specific compose file:
# ./clean-project.sh -f docker-compose.dev.yml
