#!/bin/bash
set -e

# Run Doctis bootstrap script if exists
if [ -f /var/www/html/docker-live/bootstrap.sh ]; then
    echo "== Running Doctis bootstrap =="
    bash /var/www/html/docker-live/bootstrap.sh
fi

# Start Apache in the foreground
exec apache2-foreground

