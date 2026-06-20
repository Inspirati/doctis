#!/bin/bash
#
# Doctis — Drop, recreate, and install the database
#
# Drops the existing database, recreates it, and runs the Doctis schema
# installer.  Produces a clean, empty database ready for use.
#
# Sample data (example project, licences, and test user accounts) is NOT
# loaded by this script.  To load it, run afterwards:
#
#   bash doctis-load-sample-data.sh
#
# Requires ~/.my.cnf with database admin credentials:
#   [client]
#   user=mysqladminname
#   password=mysqladminpass
#
# Usage:
#   bash doctis-drop-and-create-new-database.sh [project [mysql-password [domain]]]
#

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

project="doctis"
password="password"
ipaddr=$(ip -4 route get 1.1.1.1 | sed -n 's/.* src \([0-9.]*\).*/\1/p')

targetproject="${1:-$project}"
mysqlpassword="${2:-$password}"
domain_idname="${3:-$ipaddr}"

database="mariadb"
db_cmd="mysql"

mysqladminname="admin"
mysqladminpass=${mysqlpassword}
dbuserpostfix=""
dbdatapostfix=""

OFF="\033[0m"
INFO="\033[36m"

show_parameters() {
    echo -e "${INFO}Configuration parameters:${OFF}"
    echo -e "${INFO}  domain id name:${OFF}" "$domain_idname"
    echo -e "${INFO}  mysql password:${OFF}" "$mysqlpassword"
}

drop_existing_database() {
    local target="$1"
    local mysqldatabase="${target}${dbdatapostfix}"
    echo -e "${INFO}Dropping database ${mysqldatabase}${OFF}"
    ${db_cmd} <<EOF
DROP DATABASE IF EXISTS ${mysqldatabase};
EOF
}

initialise_database() {
    echo -e "${INFO}Initialising database...${OFF}"
    if ! systemctl is-enabled --quiet "${database}"; then
        echo "Enabling ${database} to start on boot..."
        sudo systemctl enable "${database}"
    fi
    if ! systemctl is-active --quiet "${database}"; then
        echo "Starting ${database} service..."
        sudo systemctl start "${database}"
    fi
    ${db_cmd} <<EOF
CREATE USER IF NOT EXISTS '${mysqladminname}'@'localhost' IDENTIFIED BY '${mysqladminpass}';
GRANT ALL PRIVILEGES ON *.* TO '${mysqladminname}'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
EOF
}

configure_database() {
    local target="$1"
    local mysqlusername="${target}${dbuserpostfix}"
    local mysqldatabase="${target}${dbdatapostfix}"
    echo -e "${INFO}Configuring ${database} for ${target}...${OFF}"
    ${db_cmd} <<EOF
CREATE DATABASE IF NOT EXISTS ${mysqldatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
#CREATE USER IF NOT EXISTS '${mysqlusername}'@'localhost' IDENTIFIED BY '${mysqlpassword}';
#GRANT ALL PRIVILEGES ON ${mysqldatabase}.* TO '${mysqlusername}'@'localhost';
##GRANT ALL PRIVILEGES ON ${mysqldatabase}.* TO '${mysqladminname}'@'localhost';
#FLUSH PRIVILEGES;
EOF
    echo -e "${INFO}Database configured for ${target}.${OFF}" >&2
}

run_install() {
    local target="$1"
    local logfile="${SCRIPT_DIR}/${target}_install_$(date +%Y%m%d_%H%M%S).html"

    if [ -d "${SCRIPT_DIR}/../../../${target}" ]; then
        local install_url="http://${domain_idname}/${target}/admin/install.php"
    else
        local install_url="http://${domain_idname}/admin/install.php"
    fi

    echo -e "${INFO}Running ${target} database install/upgrade...${OFF}"
    echo -e "${INFO}${install_url}${OFF}"
    if curl -fsS -d "install=2" "${install_url}" \
        | tee "$logfile" \
        | grep -q "GOOD"; then
        echo -e "${INFO}✔ ${target} database install successful.${OFF}"
        echo "  → Full installer output saved to ${logfile}"
    else
        echo -e "${FAIL}⚠ ${target} installer did not confirm success. Check logs.${OFF}"
        echo "  → Full installer output saved to ${logfile}"
    fi
}

# ── Main ──────────────────────────────────────────────────────────────────────

main() {
    echo -e "${INFO}Attempting to delete ${targetproject} database${OFF}"
    drop_existing_database "${targetproject}"
#    initialise_database
    configure_database "${targetproject}"
#    run_install ${targetproject}
    run_install "doctis"
    echo -e "${INFO}Database ready. To load sample data run:${OFF}"
    echo -e "${INFO}  bash ${SCRIPT_DIR}/doctis-load-sample-data.sh${OFF}"
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    echo -e "${INFO}This script is being invoked directly.${OFF}"
    show_parameters
    sleep 0.01

    if [ -f /.dockerenv ]; then
        echo "Running inside Docker"
        main "$@"
    else
        echo -e "${WARN}This will destroy all data in the ${targetproject} database${OFF}" >&2
        read -rp "Type 'yes' to proceed: " answer
        if [ "$answer" = "yes" ]; then
            main "$@"
        fi
    fi
    echo -e "${INFO}Done: <ctrl-c> to close${OFF}"
else
    echo -e "${INFO}This script is being sourced from ${0}.${OFF}"
    main "$@"
fi
