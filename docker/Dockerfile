FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    mariadb-client \
    unzip \
    nano \
    && docker-php-ext-install mysqli pdo pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

# Copy Doctis source code into container
COPY --from=doctis_src . /var/www/html/

# Ensure correct permissions for webroot
RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

