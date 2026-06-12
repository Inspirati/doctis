#!/bin/bash

MODE="${1:-prod}"    # "dev" or "prod"

if [[ "$MODE" == "dev" ]]; then
    echo "== Starting DOCTIS in DEVELOPMENT mode =="
    COMPOSE_FILES="-f docker-compose.yml -f docker-compose.dev.yml"
else
    echo "== Starting DOCTIS in PRODUCTION mode =="
    COMPOSE_FILES="-f docker-compose.yml"
fi

docker compose $COMPOSE_FILES down
docker image prune -af
docker compose $COMPOSE_FILES down -v
docker compose $COMPOSE_FILES build --no-cache
docker compose $COMPOSE_FILES up -d

docker compose $COMPOSE_FILES exec web bash /bootstrap.sh

echo "== Docker build complete: $MODE mode =="

