#!/bin/bash
# -----------------------------------------------------------------------------
# MantisBT CI script - Execute install.php
# -----------------------------------------------------------------------------

OFF="\033[0m"
INFO="\033[36m"

project="mantisbt-test"
database="mariadb"
dbdatapostfix=""

export PORT="/$project"
export DB_NAME="bugtracker"
export DB_USER="root"
export DB_PASSWORD="root"
export DB_HOST="localhost"
export DB_TYPE="mysqli"
export HOSTNAME="${DB_HOST}"

mysqlusername="$DB_USER"
mysqldatabase="$DB_NAME"
mysqladminname="$DB_USER"
mysqladminpass="$DB_PASSWORD"

drop_existing_database() {
    echo -e "${INFO}Dropping database ${mysqldatabase}${OFF}"
    ${database} <<EOF
DROP DATABASE ${mysqldatabase};
DROP USER '${mysqladminname}'@'${DB_HOST}';
EOF
}

initialise_database() {
    echo -e "${INFO}Initialising ${database} database...${OFF}"
    # Ensure MySQL/MariaDB is running and enabled
    if ! systemctl is-enabled --quiet "${database}"; then
        echo "Enabling ${database} to start on boot..."
        sudo systemctl enable "${database}"
    fi
    if ! systemctl is-active --quiet "${database}"; then
        echo "Starting ${database} service..."
        sudo systemctl start "${database}"
    fi
    ${database} <<EOF
CREATE USER IF NOT EXISTS '${mysqladminname}'@'${DB_HOST}' IDENTIFIED BY '${mysqladminpass}';
GRANT ALL PRIVILEGES ON *.* TO '${mysqladminname}'@'${DB_HOST}' WITH GRANT OPTION;
FLUSH PRIVILEGES;
EOF
    echo -e "${INFO}Database initialised.${OFF}" >&2
}

configure_database() {
    echo -e "${INFO}Configuring ${database} for ${project}...${OFF}"
    # Create database & user if they don't already exist
    ${database} <<EOF
GRANT ALL PRIVILEGES ON ${mysqldatabase}.* TO '${mysqladminname}'@'${DB_HOST}';
FLUSH PRIVILEGES;
EOF
    echo -e "${INFO}Database configured for ${project}.${OFF}" >&2
}

drop_existing_database
initialise_database

# Write access to config dir needed for installer to create config_inc.php
chmod 777 config/

DB_CMD="mysql"

$DB_CMD -e "CREATE DATABASE $DB_NAME"

export DB_CMD="$DB_CMD $DB_NAME -e "

rm ./tests/bootstrap.php

# Install MantisBT
./build/ci_install_mantis.sh
# Post-installation steps
./build/ci_post_install.sh
# Run test suite
vendor/bin/phpunit

