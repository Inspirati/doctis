#!/bin/bash
set -e

IMAGE="doctis/doctis"

TAG=${1:-prod}

echo "Publishing image: $IMAGE:$TAG"
docker push "$IMAGE:$TAG"

# If pushing 'prod', also update latest
if [[ "$TAG" == "prod" ]]; then
    echo "Tagging as latest..."
    docker tag "$IMAGE:$TAG" "$IMAGE:latest"
    docker push "$IMAGE:latest"
fi

# If the tag looks like a version (e.g. v1.2.0)
if [[ "$TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Version detected, publishing version tag..."
    docker push "$IMAGE:$TAG"
fi

echo "Done."

# USAGE:
# ./publish.sh prod
# ./publish.sh v1.0.0
# ./publish.sh customtag

