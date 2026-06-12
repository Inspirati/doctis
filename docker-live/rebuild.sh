#!/usr/bin/env bash
set -e

# ---------------------------------------------------------
# Doctis Rebuild Script with optional bootstrap
# Usage:
#   ./rebuild.sh [dev|prod] [--skip-bootstrap] [--skip-cleanup]
# ---------------------------------------------------------

MODE="dev"
SKIP_BOOTSTRAP=false
SKIP_CLEANUP=false

# Parse args
for arg in "$@"; do
    case "$arg" in
        prod|dev) MODE="$arg" ;;
        --skip-bootstrap) SKIP_BOOTSTRAP=true ;;
        --skip-cleanup) SKIP_CLEANUP=true ;;
    esac
done

COMPOSE="docker compose"

# Select compose files
if [[ "$MODE" == "prod" ]]; then
    COMPOSE_FILES="-f docker-compose.yml"
else
    COMPOSE_FILES="-f docker-compose.yml -f docker-compose.dev.yml"
fi

echo "== Doctis Rebuild Script =="
echo "Mode: $MODE"
echo "Skip bootstrap: $SKIP_BOOTSTRAP"
echo "Skip cleanup: $SKIP_CLEANUP"
echo

# ---------------------------------------------------------
if ! $SKIP_CLEANUP; then
    echo "1. Bringing down any existing containers..."
    $COMPOSE $COMPOSE_FILES down -v --remove-orphans || true
    echo

    echo "2. Removing leftover Doctis volumes..."
    VOLS=$(docker volume ls -q | grep -E "doctis|docker_db_data|docker_dbdata|doctis-docker" || true)
    if [ -n "$VOLS" ]; then
        echo "$VOLS" | xargs docker volume rm || true
        echo "Removed old volumes."
    else
        echo "No leftover volumes detected."
    fi
    echo

    echo "3. Pruning unused images, networks, and build cache..."
    docker system prune -f > /dev/null
    echo "Prune complete."
    echo
fi

# ---------------------------------------------------------
echo "4. Building images..."
if [[ "$MODE" == "prod" ]]; then
    ./build-prod.sh prod
else
    $COMPOSE $COMPOSE_FILES build --no-cache
fi
echo

# ---------------------------------------------------------
echo "5. Starting containers..."
$COMPOSE $COMPOSE_FILES up -d
echo

# ---------------------------------------------------------
if ! $SKIP_BOOTSTRAP; then
    echo "6. Running bootstrap inside the web container..."
#    $COMPOSE $COMPOSE_FILES exec web bash /bootstrap.sh
    $COMPOSE $COMPOSE_FILES exec web bash /var/www/html/docker-live/bootstrap.sh
    echo
else
    echo "Skipping bootstrap..."
fi

echo "== Rebuild complete =="
echo "To check logs: docker compose logs -f"

# USAGE:
# ./rebuild.sh dev --skip-bootstrap
# ./rebuild.sh prod
# ./rebuild.sh prod --skip-cleanup

