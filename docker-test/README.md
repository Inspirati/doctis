# Doctis – Official Docker Test Image

This is the official Docker image for **Doctis**, a document + project management application.

## Quick Start

```bash
#docker run -p 8081:80 doctis/doctis:latest


Usage

Start the test environment:

docker compose -f docker-compose.test.yml up -d


Bootstrap the database if needed:

docker exec -it doctis-test-web bash /var/www/html/docker-live/bootstrap.sh


Open your browser:

http://localhost:8081

Tear down when done:

docker compose -f docker-compose.test.yml down -v


✅ This approach lets you:

Test the published prod image exactly.

Avoid local bind mounts or leftover volumes.

Easily recreate a fresh environment for QA or CI/CD.





Perfect — your prod image is now built, bootstrapped, and published. 🎉

To test the published image cleanly, you want to make sure you’re running it as if it’s coming fresh from Docker Hub, without any leftover volumes, containers, or bind mounts. Here’s a good workflow:

1. Pull the published image
docker pull doctis/doctis:prod

This ensures you’re testing the exact image you just published.

2. Run it in a temporary container
docker run -d \
  --name doctis-test \
  -p 8082:80 \
  -e DOCTIS_DB_HOST=db \
  -e DOCTIS_DB_NAME=doctis \
  -e DOCTIS_DB_USER=doctis \
  -e DOCTIS_DB_PASS=password \
  doctis/doctis:prod


Adjust DOCTIS_DB_* as needed. You could also spin up a temporary MariaDB container for testing.

Quick way to spin up with MariaDB
docker network create doctis-test-net

docker run -d --name doctis-test-db --network doctis-test-net \
  -e MARIADB_ROOT_PASSWORD=password \
  -e MARIADB_DATABASE=doctis \
  -e MARIADB_USER=doctis \
  -e MARIADB_PASSWORD=password \
  mariadb:10.6

docker run -d --name doctis-test-web --network doctis-test-net \
  -p 8082:80 \
  -e DOCTIS_DB_HOST=doctis-test-db \
  -e DOCTIS_DB_NAME=doctis \
  -e DOCTIS_DB_USER=doctis \
  -e DOCTIS_DB_PASS=password \
  doctis/doctis:prod


Then visit: http://localhost:8082

3. Optional: Run bootstrap inside the container

If your image doesn’t include a pre-populated database:

docker exec -it doctis-test-web bash /var/www/html/docker-live/bootstrap.sh


This will create the DB and load sample data.

4. Clean up after testing
docker rm -f doctis-test-web doctis-test-db
docker network rm doctis-test-net


💡 Tip: For a fully “production-like” test, you can create a docker-compose.test.yml that mirrors your prod setup but uses the published image. That avoids bind mounts and ensures you’re testing the exact image you push to Docker Hub.
