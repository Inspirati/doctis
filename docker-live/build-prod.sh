#!/bin/bash
set -e
TAG=${1:-latest}

docker build -f Dockerfile.prod -t doctis/doctis:$TAG ..

