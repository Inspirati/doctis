#!/bin/bash

#
# This script requires a "~/.my.cnf" file containing database admin credentials:
#
#[client]
#user=mysqladminname
#password=mysqladminpass
#

#
# If you installed doctis with a non-default password, you can specify it
# as the first parameter to this script or edit the entry below
#

project="doctis"
password="password"
domain=$(ip -4 route get 1.1.1.1 | sed -n 's/.* src \([0-9.]*\).*/\1/p')

mysqlpassword="${1:-$password}"
domain_idname="${2:-$domain}"

database="mariadb"
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
    local mysqldatabase="${project}${dbdatapostfix}"
    echo -e "${INFO}Dropping database ${mysqldatabase}${OFF}"
    ${database} <<EOF
DROP DATABASE ${mysqldatabase};
EOF
}

initialise_database() {
    echo -e "${INFO}Initialising database...${OFF}"
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
CREATE USER IF NOT EXISTS '${mysqladminname}'@'localhost' IDENTIFIED BY '${mysqladminpass}';
GRANT ALL PRIVILEGES ON *.* TO '${mysqladminname}'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
EOF
}

configure_database() {
    local mysqlusername="${project}${dbuserpostfix}"
    local mysqldatabase="${project}${dbdatapostfix}"
    echo -e "${INFO}Configuring ${database} for ${project}...${OFF}"
    # Create database & user if they don't already exist
    mysql <<EOF
CREATE DATABASE IF NOT EXISTS ${mysqldatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${mysqlusername}'@'localhost' IDENTIFIED BY '${mysqlpassword}';
GRANT ALL PRIVILEGES ON ${mysqldatabase}.* TO '${mysqlusername}'@'localhost';
GRANT ALL PRIVILEGES ON ${mysqldatabase}.* TO '${mysqladminname}'@'localhost';
FLUSH PRIVILEGES;
EOF
    echo -e "${INFO}Database configured for ${project}.${OFF}" >&2
}

run_mantis_install_log() {
    local install_url="http://${domain_idname}/${project}/admin/install.php"
    local logfile="mantis_install_$(date +%Y%m%d_%H%M%S).html"

    echo -e "${INFO}Running MantisBT database install/upgrade...${OFF}"

    # Capture the full output with tee, then grep separately
    if curl -fsS -d "install=2" "$install_url" \
        | tee "$logfile" \
        | grep -q "installed successfully"; then
        echo -e "${INFO}✔ MantisBT database install successful.${OFF}"
        echo "  → Full installer output saved to $logfile"
    else
        echo -e "${FAIL}⚠ MantisBT installer did not confirm success. Check logs.${OFF}"
        echo "  → Full installer output saved to $logfile"
    fi
}

load_mantis_example_data() {
    local project="$1"
    local mysqldatabase="${project}${dbdatapostfix}"
    echo -e "${INFO}Loading example data into ${mysqldatabase}...${OFF}"
    ${database} <<EOF
USE ${mysqldatabase};
$(cat <<'SQL'
INSERT INTO `project` (`id`, `name`, `status`, `enabled`, `view_state`, `access_min`, `file_path`, `description`, `category_id`, `inherit_global`, `classification`)
VALUES (1, 'example', 10, 1, 10, 10, '', '', 1, 1, '');
SQL
)
EOF
    echo -e "${INFO}Example data loaded.${OFF}" >&2
}

load_mantis_testing_user() {
    local project="$1"
    local mysqldatabase="${project}${dbdatapostfix}"
    echo -e "${INFO}Loading example data into ${mysqldatabase}...${OFF}"
    ${database} <<EOF
USE ${mysqldatabase};
$(cat <<'SQL'
INSERT INTO `user` (`username`, `realname`, `email`, `password`, `enabled`, `protected`, `access_level`, `login_count`, `lost_password_request_count`, `failed_login_count`, `cookie_string`, `last_visit`, `date_created`) VALUES
('user', '', 'doctis.user@gmail.com', '1a1dc91c907325c69271ddf0c944bc72', 1, 0, 25, 3, 0, 0, '2f0adeec1f967ae6c23abf54f8e7487d6ae8ca98185bd228469f5ce4478346f9', 1757927188, 1757927188),
('viewer', '', 'doctis.viewer@gmail.com', '1a1dc91c907325c69271ddf0c944bc72', 1, 0, 10, 3, 0, 0, '96cf4e972760ca2b25da0883b157808e6ae8ca98185bd228469f5ce4478346f9', 1757927188, 1757927188),
('reporter', '', 'doctis.reporter@gmail.com', '1a1dc91c907325c69271ddf0c944bc72', 1, 0, 25, 3, 0, 0, '7be89c3bacb19567c52d56ba4d7b12726ae8ca98185bd228469f5ce4478346f9', 1757927188, 1757927188),
('updater', '', 'doctis.updater@gmail.com', '1a1dc91c907325c69271ddf0c944bc72', 1, 0, 40, 3, 0, 0, '63f66ba20df9c98303fc2ed9b7708fc06ae8ca98185bd228469f5ce4478346f9', 1757927188, 1757927188),
('developer', '', 'doctis.developer@gmail.com', '1a1dc91c907325c69271ddf0c944bc72', 1, 0, 55, 3, 0, 0, '716bd2ac4467b24752348d1772b4baee6ae8ca98185bd228469f5ce4478346f9', 1757927188, 1757927188),
('manager', '', 'doctis.manager@gmail.com', '1a1dc91c907325c69271ddf0c944bc72', 1, 0, 70, 3, 0, 0, '9f7dc77b274b9a7466466da2007ef1a26ae8ca98185bd228469f5ce4478346f9', 1757927188, 1757927188),
('admin', '', 'doctis.admin@gmail.com', '1a1dc91c907325c69271ddf0c944bc72', 1, 0, 90, 3, 0, 0, 'f79e4810068402b52f4856cd8953f8976ae8ca98185bd228469f5ce4478346f9', 1757927188, 1757927188);
SQL
)
EOF
    echo -e "${INFO}Database ${mysqldatabase} loaded.${OFF}" >&2
}

#ALTER TABLE `user`
#  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;
#COMMIT;

show_parameters
drop_existing_database
initialise_database
configure_database
run_mantis_install_log ${project}
load_mantis_example_data ${project}
load_mantis_testing_user ${project}

