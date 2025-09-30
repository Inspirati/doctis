#!/bin/bash

git_repository="https://github.com/Inspirati/doctis.git"
database="mariadb"
#database="mysql"
mysqladminname="admin"
dbuserpostfix=""
dbdatapostfix=""
phpMyAdmin_ver="5.2.2"

default_page_fields="
    'additional_info',
    'attachments',
    'category_id',
    'document_id',
    'date_submitted',
    'description',
    'due_date',
    'fixed_in_version',
    'handler',
    'id',
    'last_updated',
    'priority',
    'project',
    'reporter',
    'resolution',
    'severity',
    'status',
    'summary',
    'target_version',
    'view_state',
"

OFF="\033[0m"
RED="\033[31m"
GREEN="\033[32m"
YELLOW="\033[33m"
CYAN="\033[36m"
FAIL=$RED
INFO=$CYAN
DIAG=$GREEN
WARN=$YELLOW

# === Utility functions ===

prompt_delete_dir() {
    local DIR="$1"
    # Safety guard: refuse empty or /
    if [ -z "$DIR" ] || [ "$DIR" = "/" ]; then
        echo -e "${FAIL}Refusing to delete '$DIR' (unsafe)${OFF}"
        return 1
    fi
    # Critical dirs list (exact match only)
    case "$DIR" in
        /bin|/boot|/dev|/etc|/home|/lib|/lib32|/lib64|/libx32|/media|/mnt|/opt|/proc|/root|/run|/sbin|/srv|/sys|/tmp|/usr|/var|/var/www/html)
            echo -e "${FAIL}Refusing to delete critical directory: $DIR ${OFF}"
            return 1
            ;;
    esac
    if [ -d "$DIR" ]; then
        sleep 0.01  # tiny delay to allow earlier stdout echos to flush
        echo -e "${WARN}Directory $DIR already exists.${OFF}" >&2
        read -rp "Type 'yes' to delete it: " answer
        if [ "$answer" = "yes" ]; then
            echo -e "${INFO}Attempting to delete $DIR${OFF}"
            rm -rf "$DIR" 2>/dev/null || true
            if [ -d "$DIR" ]; then
                echo -e "${WARN}Permission denied, retrying with root permission...${OFF}"
                sudo --reset-timestamp
                sudo rm -rf "$DIR"
            fi
            if [ ! -d "$DIR" ]; then
                echo -e "${INFO}Deleted $DIR${OFF}"
            else
                echo -e "${FAIL}Failed to delete $DIR, returning${OFF}"
                return 1
            fi
        else
            echo -e "${FAIL}Skipped deletion, returning.${OFF}"
            return 1
        fi
    fi
    return 0
}

set_webroot() {
    if command -v apache2ctl >/dev/null 2>&1; then
        webroot="$(apache2ctl -t -D DUMP_RUN_CFG 2>/dev/null | awk '/DocumentRoot/ {print $3; exit}')"
        echo -e "${INFO}webroot according to apache2ctl:${OFF}" "$webroot"
    elif command -v httpd >/dev/null 2>&1; then
        webroot="$(httpd -t -D DUMP_RUN_CFG 2>/dev/null | awk '/DocumentRoot/ {print $3; exit}')"
        echo -e "${INFO}webroot according to httpd:${OFF}" "$webroot"
    else
        webroot="/var/www/html"   # safe default
        echo -e "${WARN}webroot default:${OFF}" "$webroot"
    fi
}    

################################################################################
# Part two:  Install and config the various apps for a doctis development system
#   everything from here on should run without sudo
#

configure_vscode() {
    local project="$1"
    # Add a required vscode configuration file for launching Xdebug sessions:
    mkdir ${project}/.vscode
    cat >> ${project}/.vscode/launch.json << EOF
{
    "version": "0.2.0",
    "configurations": [
        {
            "name": "Listen for Xdebug",
            "type": "php",
            "request": "launch",
            "port": 9003
        }
    ]
}
EOF
}

configure_database() {
    local project="$1"
    local mysqladminpass=${mysqlpassword}
    local mysqlusername="${project}${dbuserpostfix}"
    local mysqldatabase="${project}${dbdatapostfix}"
    echo -e "${INFO}Configuring ${database} for ${project}...${OFF}"
    # Create database & user if they don't already exist
    mysql -u"${mysqladminname}" -p"${mysqladminpass}" <<EOF
CREATE DATABASE IF NOT EXISTS ${mysqldatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${mysqlusername}'@'localhost' IDENTIFIED BY '${mysqlpassword}';
GRANT ALL PRIVILEGES ON ${mysqldatabase}.* TO '${mysqlusername}'@'localhost';
GRANT ALL PRIVILEGES ON ${mysqldatabase}.* TO '${mysqladminname}'@'localhost';
FLUSH PRIVILEGES;
EOF
    echo -e "${INFO}Database configured for ${project}.${OFF}" >&2
}

configure_project() {
    local project="$1"
    local mysqlusername="${project}${dbuserpostfix}"
    local mysqldatabase="${project}${dbdatapostfix}"
    local config_src=${project}/"config/config_inc.php.sample"
    local config_dst=${project}/"config/config_inc.php"
    echo -e "${INFO}Configuring ${project} instance at ${config_dst}${OFF}"
    echo -e "${INFO}config_src:${OFF}" "$config_src"
    echo -e "${INFO}config_dst:${OFF}" "$config_dst"
    cp ${config_src} ${config_dst}
    # Generate 32 characters of crypto salt:
    cryptosalt=$(openssl rand -hex 16)
    echo -e "${INFO}cryptosalt:${OFF}" "$cryptosalt"
    sed -i "s|# Rename this file to config_inc.php after configuration.|\$g_path = 'http://${domain_idname}/${project}/';|" ${config_dst}
    sed -i "s|mantisdbuser|${mysqlusername}|" ${config_dst}
    sed -i "s|bugtracker|${mysqldatabase}|" ${config_dst}
    sed -i "s|\$g_db_password[[:space:]]*=[[:space:]]*''|\$g_db_password   = '${mysqlpassword}'|" ${config_dst}
    sed -i "s|\$g_crypto_master_salt[[:space:]]*=[[:space:]]*''|\$g_crypto_master_salt = '${cryptosalt}'|" ${config_dst}
    sed -i "s|\$g_phpMailer_method[[:space:]]*=[[:space:]]*PHPMAILER_METHOD_MAIL|\$g_phpMailer_method = PHPMAILER_METHOD_SMTP|" ${config_dst}
    sed -i "s|\$g_smtp_host[[:space:]]*=[[:space:]]*'localhost'|\$g_smtp_host = 'smtp.gmail.com'|" ${config_dst}
    sed -i "s|\$g_smtp_username[[:space:]]*=[[:space:]]*''|\$g_smtp_username = '${email_address}'|" ${config_dst}
    sed -i "s|\$g_smtp_password[[:space:]]*=[[:space:]]*''|\$g_smtp_password = '${email_hashtag}'|" ${config_dst}
    sed -i "s|\$g_webmaster_email[[:space:]]*=[[:space:]]*'webmaster@example.com'|\$g_webmaster_email = '${email_address}'|" ${config_dst}
    sed -i "s|\$g_from_email[[:space:]]*=[[:space:]]*'noreply@example.com'|\$g_from_email = '${email_address}'|" ${config_dst}
    sed -i "s|\$g_return_path_email[[:space:]]*=[[:space:]]*'admin@example.com'|\$g_return_path_email = '${email_address}'|" ${config_dst}
    sed -i "s|#[[:space:]]*\$g_from_name[[:space:]]*=[[:space:]]*'Mantis Bug Tracker'|\$g_from_name = '${project^}'|" ${config_dst}
    sed -i "s|#[[:space:]]*\$g_email_receive_own[[:space:]]*=[[:space:]]*OFF|\$g_smtp_port = 587|" ${config_dst}
    sed -i "s|#[[:space:]]*\$g_email_send_using_cronjob[[:space:]]*=[[:space:]]*OFF|\$g_smtp_connection_mode = 'tls'|" ${config_dst}
    sed -i "s|#[[:space:]]*\$g_max_file_size[[:space:]]*=[[:space:]]*5000000|\$g_max_file_size = 2 * 1024 * 1024|" ${config_dst}
    sed -i "s|#[[:space:]]*\$g_window_title[[:space:]]*=[[:space:]]*'MantisBT'|\$g_window_title = '${project^}'|" ${config_dst}
    sed -i "s|#[[:space:]]*\$g_logo_image[[:space:]]*=[[:space:]]*'images/mantis_logo.png'|\$g_logo_image = 'images/doctis_logo.png'|" ${config_dst}
    sed -i "s|#[[:space:]]*\$g_favicon_image[[:space:]]*=[[:space:]]*'images/favicon.ico'|\$g_favicon_image = 'images/doctis_icon.ico'|" ${config_dst}
    sed -i "/\$g_db_type[[:space:]]*=[[:space:]]*'mysqli';/a\\
\$g_db_table_prefix = '';\\
\$g_db_table_suffix = '';\\
\$g_wiki_enable = ON;\\
\$g_wiki_engine = 'dokuwiki';\\
\$g_wiki_root_namespace = 'doctis';\\
\$g_wiki_engine_url = '../doctis-wiki/';
" ${config_dst}
    cat >> ${config_dst} << EOF
\$g_reauthentication = OFF;
\$g_reauthentication_expiry = 86400;
\$g_bug_report_page_fields = array(${default_page_fields});
\$g_bug_view_page_fields = array(${default_page_fields});
\$g_bug_update_page_fields = array(${default_page_fields});
\$g_priority_enum_string = '10:closed,20:pending,30:open';
\$g_severity_enum_string = '30:comment,40:query,50:minor,60:major';
\$g_default_bug_severity = 50; // Set minor as default
\$g_reproducibility_enum_string = '';
\$g_enable_profiles = OFF;
EOF
    echo -e "${INFO}Project ${project} configured.${OFF}" >&2
}

publish_project() {
    local project="$1"
    local publish_dir="${webroot}/${project}/"
    echo -e "${INFO}Publishing ${project} to ${publish_dir}...${OFF}"
    chmod -R g+w ${project}
    find ${project} -type d -exec chmod g+ws {} \;
    prompt_delete_dir ${publish_dir}
    if [[ $? -gt 0 ]]; then
        echo -e "${FAIL}Target ${publish_dir} exists, aborting.${OFF}"
        return $?
    fi    
    echo -e "${INFO}Copying ${project} to ${publish_dir}${OFF}" >&2
    cp -R ${project} ${publish_dir}
    echo -e "${INFO}Setting owner to $(whoami):www-data${OFF}" >&2
    sg www-data "chown -R $(whoami):www-data ${publish_dir}"
    echo -e "${INFO}Linking ${project}-www to ${publish_dir}${OFF}" >&2
    ln -s ${publish_dir} ${project}-www
    echo -e "${INFO}Publish complete.${OFF}" >&2
}

install_phpmyadmin() {
    local project="$1"
    echo -e "${INFO}Installing phpMyAdmin to ${webroot}/${OFF}"
    wget https://files.phpmyadmin.net/phpMyAdmin/${phpMyAdmin_ver}/phpMyAdmin-${phpMyAdmin_ver}-english.tar.xz
    tar -xf phpMyAdmin-${phpMyAdmin_ver}-english.tar.xz
    rm phpMyAdmin-${phpMyAdmin_ver}-english.tar.xz
    prompt_delete_dir ${project}
    if [[ $? -gt 0 ]]; then
        echo -e "${FAIL}Target ${project} exists, aborting.${OFF}"
        return $?
    fi    
    mv phpMyAdmin-${phpMyAdmin_ver}-english ${project}
    local config_src=${project}/"config.sample.inc.php"
    local config_dst=${project}/"config.inc.php"
    cp "${config_src}" "${config_dst}"
    local blowfish=$(openssl rand -hex 16)
    echo -e "${INFO}blowfish:${OFF}" "${blowfish}"
    sed -i "s|\$cfg\['blowfish_secret'\][[:space:]]*=[[:space:]]*''|\$cfg\['blowfish_secret'\] = '${blowfish}'|" ${config_dst}
    prompt_delete_dir ${webroot}/${project}
    if [[ $? -gt 0 ]]; then
        echo -e "${FAIL}Target ${webroot}/${project} exists, aborting.${OFF}"
        return $?
    fi    
    mv ${project} ${webroot}
    sg www-data "chown -R $(whoami):www-data ${webroot}/${project}"
    chmod -R g+w ${webroot}/${project}
    find ${webroot}/${project} -type d -exec chmod g+ws {} \;
}

install_dokuwiki() {
    local project="$1"
    local branch="${2:-stable}"
    local repository="${3:-${git_repository}}"
    local config_src=${project}/"conf/local.php.dist"
    local config_dst=${project}/"conf/local.php"
    echo -e "${INFO}Installing ${project} to ${webroot}/${OFF}"
    prompt_delete_dir ${project}
    if [[ $? -gt 0 ]]; then
        echo -e "${FAIL}Target ${project} exists, aborting.${OFF}"
        return $?
    fi    
    git clone --recurse-submodules -b ${branch} --single-branch ${repository} ${project}
    echo -e "${INFO}Configuring ${project} instance at ${config_dst}${OFF}"
    echo -e "${INFO}config_src:${OFF}" "$config_src"
    echo -e "${INFO}config_dst:${OFF}" "$config_dst"
    cp ${config_src} ${config_dst}
    sed -i "s|//\$conf\['title'\][[:space:]]*=[[:space:]]*'My Wiki'|\$conf\['title'\] = '${project^}'|" ${config_dst}
    prompt_delete_dir ${webroot}/${project}
    if [[ $? -gt 0 ]]; then
        echo -e "${FAIL}Target ${webroot}/${project} exists, aborting.${OFF}"
        return $?
    fi    
    mv ${project} ${webroot}
    chown -R $(whoami):www-data ${webroot}/${project}
    chmod -R g+w ${webroot}/${project}
    find ${webroot}/${project} -type d -exec chmod g+ws {} \;
    echo -e "${INFO}Install complete.${OFF}" >&2
}

fetch_project() {
    local project="$1"
    local branch="${2:-master}"
    local repository="${3:-${git_repository}}"
    echo -e "${INFO}Cloning ${project} branch ${branch} from git repository: ${repository}...${OFF}"
    prompt_delete_dir "${project}"
    if [[ $? -gt 0 ]]; then
        echo -e "${FAIL}Target ${project} exists, aborting.${OFF}"
        return $?
    fi    
    # Clone the specified project branch from git, then compose the php dependencies:
    git clone --recurse-submodules -b ${branch} --single-branch ${repository} ${project}
    cd ${project}
    git submodule update --init
    # Deterministic composer install
    if [[ -f composer.lock ]]; then
        composer install --no-interaction --prefer-dist
    else
        composer update --no-interaction
    fi
    cd ..
    echo -e "${INFO}Cloning complete.${OFF}" >&2
}

install_projx() {
    local project="$1"
    configure_vscode ${project}
    configure_project ${project}
    configure_database ${project}
    publish_project ${project}
}

run_mantis_install() {
    local project="$1"
    local install_url="http://${domain_idname}/${project}/admin/install.php"
    echo -e "${INFO}Running MantisBT database install/upgrade...${OFF}"
    if curl -fsS -d "install=2" "$install_url" | grep -q "installed successfully"; then
        echo -e "${INFO}✔ MantisBT database install successful.${OFF}"
    else
        echo -e "${FAIL}⚠ MantisBT installer did not confirm success. Check logs.${OFF}"
    fi
}

run_mantis_install_log() {
    local project="$1"
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
    echo -e "${INFO}Database ${mysqldatabase} loaded.${OFF}" >&2
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

launch_project() {
    local project="$1"
    code ${webroot}/${project} &
    firefox "http://${domain_idname}/phpMyAdmin" &
#    firefox "http://${domain_idname}/doctis-wiki" &
    firefox "http://${domain_idname}/${project}" &
}

getting_started() {
    local project="$1"
    # Generate and display the getting started hints:
    cat > getting-started.txt << EOF

================== Using Doctis/Mantis ==================
1. As administrator, select sidebar 'Manage' then tab 'Projects'
2. Add a new 'test' project
3. Select sidebar 'Create Document' then submit a new document
4. Select sidebar 'Report Issue' and submit an issue
5. Explore and experiment

================ Development Environment ================
1. If not already open, start vscode and select <File> <Open Folder> ${webroot}/${project}
2. Start a debug session with menu <Run><Start Debugging> or press <F5>
3. Open source file view_all_bug_page.php and set a break-point on or around line 50
    (breakpoints can be set by clicking in the margin to the left of a line number)
4. If required, start Firefox at ${domain_idname}/${project}
5. In ${project} select the 'View Issues' from the sidebar
    (observe that execution has halted in vscode at the set breakpoint)
6. Press <F10> to step through the code, or <F11> to step into function calls
7. View status (diff) of source file changes from the original Mantis source:
    $ meld doctis-www mantisbt-www &
8. Set a tail on the webserver (apache) error log:
    $ trace
9. Optionally, set a tail that only reports thrown exceptions:
    $ tracex

NOTES: phpMyAdmin username is 'admin', password as per install script ie. default is: 'password'
       if you elected to install the VirtualBox Client Tools, a reboot is required for them to take effect
EOF
    xdg-open getting-started.txt &
}

show_parameters() {
    echo ""
    echo -e "${DIAG}Colour Coding Legend:${OFF}"
    echo -e "${FAIL}  Error:${OFF} Something is wrong."
    echo -e "${WARN}  Warning:${OFF} Check your config."
    echo -e "${DIAG}  Diagnostic:${OFF} Script progress."
    echo -e "${INFO}  Information:${OFF} Progress message."
    echo ""
    echo -e "${DIAG}Configuration parameters:${OFF}"
    echo -e "${WARN}  git repository:${OFF}" "$git_repository"
#    echo -e "${WARN}  domain id name:${OFF}" "$domain_idname"
    echo -e "${WARN}  site fqdn name:${OFF}" "$domain_idname"
    echo -e "${WARN}  mysql password:${OFF}" "$mysqlpassword"
    echo -e "${WARN}  email address:${OFF}" "$email_address"
    echo -e "${WARN}  email hashtag:${OFF}" "$email_hashtag"
    echo ""
}

# === Main entry ===

install_project() {
    domain_idname="${1:-localhost}"
    mysqlpassword="${2:-password}"
    email_address="${3:-root@localhost}"
    email_hashtag="${4:-password}"
    show_parameters
    echo -e "${DIAG}Started installing project..${OFF}"
# Fetch and install the projects into the webroot
    set_webroot
    install_phpmyadmin "phpMyAdmin" &
    install_dokuwiki "doctis-wiki" "stable" "https://github.com/dokuwiki/dokuwiki.git" &
# Specify the project(s) & git branch for installation, either mantisbt and/or doctis
    project="mantisbt"
    branch="original"
    fetch_project ${project} ${branch}
    install_projx ${project}
    project="doctis"
    branch="dev"
    fetch_project ${project} ${branch}
    install_projx ${project}
    run_mantis_install_log ${project}
    load_mantis_example_data ${project}
    load_mantis_testing_user ${project}
# Launch selected targets for demonstration
    launch_project ${project}
    meld doctis-www mantisbt-www &
    getting_started ${project}
    show_parameters
    echo -e "${DIAG}Finished installing project.${OFF}"
}

################################################################################
# Function entry point of the same name as the script, useful when source'd

install-project() {
    install_project "$@"
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    echo -e "${DIAG}This script is being invoked directly.${OFF}"
    install_project "$@"
    echo -e "${DIAG}Done: <ctrl-c> to close${OFF}"
else
    echo -e "${DIAG}This script is being sourced from ${0}.${OFF}"
fi

#echo -e "${DIAG}Script 'install-project.sh' included${OFF}"
#show_parameters
#main "$@"
#show_parameters
#echo -e "${DIAG}Finished: <ctrl-c> to close${OFF}"

