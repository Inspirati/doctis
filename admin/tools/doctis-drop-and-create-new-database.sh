#!/bin/bash
# -ev

#
# This script requires a "~/.my.cnf" file containing database admin credentials:
#
#[client]
#user=mysqladminname
#password=mysqladminpass
#

#
# If you installed doctis with a non-default name and/or password, you can specify them
# as parameters to this script or edit the entries below
#

project="doctis"
password="password"
#ipaddr=$(ip -4 route get 1.1.1.1 | sed -n 's/.* src \([0-9.]*\).*/\1/p')
#ipaddr="localhost"
ipaddr="swcoh.com"

targetproject="${1:-$project}"
mysqlpassword="${2:-$password}"
domain_idname="${3:-$ipaddr}"

DBHOST="localhost"
#DBHOST="db" # database server name for docker container
#DBUSER="doctis"
DBUSER="${project}"
DBPASS="password"
DBNAME="doctis"

database="mariadb"
#db_cmd="mysql -h $DBHOST --ssl=0 -u $DBUSER -p$DBPASS"
db_cmd="mysql -u $DBUSER -p$DBPASS"

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
    # Ensure MySQL/MariaDB is running and enabled
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
    # Create database & user if they don't already exist
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
    local logfile="${target}_install_$(date +%Y%m%d_%H%M%S).html"

    if [ -d ../../../${target} ]; then
        local install_url="http://${domain_idname}/${target}/admin/install.php"
    else
        local install_url="http://${domain_idname}/admin/install.php"
    fi

    echo -e "${INFO}Running ${target} database install/upgrade...${OFF}"
    echo -e "${INFO}${install_url}${OFF}"
    # Capture the full output with tee, then grep separately
    if curl -fsS -d "install=2" "${install_url}" \
        | tee "$logfile" \
        | grep -q "installed successfully"; then
        echo -e "${INFO}✔ ${target} database install successful.${OFF}"
        echo "  → Full installer output saved to ${logfile}"
    else
        echo -e "${FAIL}⚠ ${target} installer did not confirm success. Check logs.${OFF}"
        echo "  → Full installer output saved to ${logfile}"
    fi
}

load_example_data() {
    local target="$1"
    local mysqldatabase="${target}${dbdatapostfix}"
    echo -e "${INFO}Loading example data into ${mysqldatabase}...${OFF}"
    ${db_cmd} <<EOF
USE ${mysqldatabase};
$(cat <<'SQL'
INSERT INTO `project` (`id`, `name`, `status`, `enabled`, `view_state`, `access_min`, `file_path`, `description`, `category_id`, `inherit_global`, `classification`)
VALUES (1, 'example', 10, 1, 10, 10, '', '', 1, 1, '');
SQL
)
EOF
    ${db_cmd} <<EOF
USE ${mysqldatabase};
$(cat <<'SQL'
INSERT INTO `license` (`project_id`, `enabled`, `name`, `match_str`, `type`, `status`, `view_state`, `access_min`, `description`) VALUES
(0, 1, 'DDG - 0283-11/2063-08', 'DDG028311/206308', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - 0296-11/1129-09', 'DDG029611112909', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - 3053-11/1861-09', 'DDG305311186109', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - 4356-11/3992-09', 'DDG435611399209', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - 7777-10/2016-07', 'DDG777710201607', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - 9912-10', 'DDG991210', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - AT-P-GSB', 'DDGATPGSB', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - AT-P-GSC', 'DDGATPGSC', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - AT-P-GSU', 'DDGATPGSU', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - AT-P-LCQ', 'DDGATPLCQ', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - AT-P-LFZ', 'DDGATPLFZ', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - AWD-CS-3664/2009', 'DDGAWDCS36642009', 'IP License', 10, 10, 10, ''),
(0, 1, 'DDG - ECCN 8A609-x 8E609', 'DDGECCN8A609X8E609', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - ECCN 8E992', 'DDGECCN8E992', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - NAUS-2023', 'DDGNAUS2023', 'IP License', 10, 10, 10, ''),
(0, 1, 'DDG - RAPL FMS TPTA', 'DDGRAPLFMSTPTA', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - RSAT 16-5184', 'DDGRSAT165184', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - RSAT 19-6672', 'DDGRSAT196672', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - SEA 4000-1180', 'DDGSEA40001180', 'IP License', 10, 10, 10, ''),
(0, 1, 'DDG - 2212361', 'DDG2212361', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - 9250-10/3211-08', 'DDG925010321108', 'Harpoon License', 10, 10, 10, '');
SQL
)
EOF
    echo -e "${INFO}Example data loaded.${OFF}" >&2
}

load_testing_user() {
    local target="$1"
    local mysqldatabase="${target}${dbdatapostfix}"
    echo -e "${INFO}Loading example data into ${mysqldatabase}...${OFF}"
    ${db_cmd} <<EOF
USE ${mysqldatabase};
$(cat <<'SQL'
INSERT INTO `user` (`username`, `realname`, `email`, `password`, `enabled`, `protected`, `access_level`, `login_count`, `lost_password_request_count`, `failed_login_count`, `cookie_string`, `last_visit`, `date_created`) VALUES
('user', '', 'doctis.user@gmail.com', 'd41d8cd98f00b204e9800998ecf8427e', 1, 0, 25, 3, 0, 0, '2f0adeec1f967ae6c23abf54f8e7487d6ae8ca98185bd228469f5ce4478346f9', 1757927188, 1757927188),
('viewer', '', 'doctis.viewer@gmail.com', 'd41d8cd98f00b204e9800998ecf8427e', 1, 0, 10, 3, 0, 0, '96cf4e972760ca2b25da0883b157808e6ae8ca98185bd228469f5ce4478346f9', 1757927188, 1757927188),
('reporter', '', 'doctis.reporter@gmail.com', 'd41d8cd98f00b204e9800998ecf8427e', 1, 0, 25, 3, 0, 0, '7be89c3bacb19567c52d56ba4d7b12726ae8ca98185bd228469f5ce4478346f9', 1757927188, 1757927188),
('updater', '', 'doctis.updater@gmail.com', 'd41d8cd98f00b204e9800998ecf8427e', 1, 0, 40, 3, 0, 0, '63f66ba20df9c98303fc2ed9b7708fc06ae8ca98185bd228469f5ce4478346f9', 1757927188, 1757927188),
('developer', '', 'doctis.developer@gmail.com', 'd41d8cd98f00b204e9800998ecf8427e', 1, 0, 55, 3, 0, 0, '716bd2ac4467b24752348d1772b4baee6ae8ca98185bd228469f5ce4478346f9', 1757927188, 1757927188),
('manager', '', 'doctis.manager@gmail.com', 'd41d8cd98f00b204e9800998ecf8427e', 1, 0, 70, 3, 0, 0, '9f7dc77b274b9a7466466da2007ef1a26ae8ca98185bd228469f5ce4478346f9', 1757927188, 1757927188),
('admin', '', 'doctis.owner@gmail.com', '5f4dcc3b5aa765d61d8327deb882cf99', 1, 0, 90, 3, 0, 0, 'f79e4810068402b52f4856cd8953f8976ae8ca98185bd228469f5ce4478346f9', 1757927188, 1757927188);
SQL
)
EOF
    echo -e "${INFO}Database ${mysqldatabase} loaded.${OFF}" >&2
}

# blank password ''   : 'd41d8cd98f00b204e9800998ecf8427e'
# password 'pass'     : '1a1dc91c907325c69271ddf0c944bc72'
# password 'password' : '5f4dcc3b5aa765d61d8327deb882cf99'

#ALTER TABLE `user`
#  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;
#COMMIT;

main() {
    echo -e "${INFO}Attempting to delete ${targetproject} database${OFF}"
    drop_existing_database ${targetproject}
#    initialise_database
    configure_database ${targetproject}
#    run_install ${targetproject}
    run_install "doctis"
    load_example_data ${targetproject}
    load_testing_user ${targetproject}
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    echo -e "${DIAG}This script is being invoked directly.${OFF}"
    show_parameters
    sleep 0.01  # tiny delay to allow earlier stdout echos to flush
    
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
    echo -e "${DIAG}Done: <ctrl-c> to close${OFF}"
else
    echo -e "${DIAG}This script is being sourced from ${0}.${OFF}"
    main "$@"
fi

