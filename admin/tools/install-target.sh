#!/bin/bash

# DOCTIS_GIT_REPO overrides the default GitHub source, e.g. for LAN installs:
#   export DOCTIS_GIT_REPO="http://10.0.0.10/git/doctis"
git_repository="${DOCTIS_GIT_REPO:-https://github.com/Inspirati/doctis.git}"
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
        if [ "${HEADLESS:-false}" = "true" ] || [ -f /.dockerenv ]; then
            echo -e "${INFO}Headless mode: auto-confirming deletion of $DIR${OFF}"
            answer="yes"
        else
            read -rp "Type 'yes' to delete it: " answer
        fi
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
    webroot=${webroot//\"/}
}

set_headless() {
    # Allow explicit override via environment variable (export HEADLESS=1 before running)
    if [ "${HEADLESS:-}" = "1" ] || [ "${HEADLESS:-}" = "true" ]; then
        echo -e "${INFO}Headless mode forced via HEADLESS environment variable.${OFF}"
        HEADLESS=true
        return
    fi
    # Check if a display server is available (X11 or Wayland)
    if [ -z "$DISPLAY" ] && [ -z "$WAYLAND_DISPLAY" ]; then
        echo -e "${INFO}Headless environment detected (no GUI display).${OFF}"
        HEADLESS=true
    else
        echo -e "${INFO}GUI environment detected.${OFF}"
        HEADLESS=false
    fi
    # Optionally, check for X11 libraries to confirm
    if ! command -v xrandr >/dev/null 2>&1 && ! command -v gnome-shell >/dev/null 2>&1; then
        echo -e "${INFO}No GUI libraries found — likely a headless server.${OFF}"
        HEADLESS=true
    fi
}

################################################################################
# Part two:  Install and config the various apps for a doctis development system
#   everything from here on should run without sudo
#

configure_vscode() {
    local target="$1"
    # Add a required vscode configuration file for launching Xdebug sessions:
    mkdir ${target}/.vscode
    cat >> ${target}/.vscode/launch.json << EOF
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
    local target="$1"
    local mysqladminpass=${mysqlpassword}
    local mysqlusername="${target}${dbuserpostfix}"
    local mysqldatabase="${target}${dbdatapostfix}"
    echo -e "${INFO}Configuring ${database} for ${target}...${OFF}"
    # Create database & user if they don't already exist
    mysql -u"${mysqladminname}" -p"${mysqladminpass}" <<EOF
CREATE DATABASE IF NOT EXISTS ${mysqldatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${mysqlusername}'@'localhost' IDENTIFIED BY '${mysqlpassword}';
GRANT ALL PRIVILEGES ON ${mysqldatabase}.* TO '${mysqlusername}'@'localhost';
GRANT ALL PRIVILEGES ON ${mysqldatabase}.* TO '${mysqladminname}'@'localhost';
FLUSH PRIVILEGES;
EOF
    echo -e "${INFO}Database configured for ${target}.${OFF}" >&2
}

configure_target() {
    local target="$1"
    local mysqlusername="${target}${dbuserpostfix}"
    local mysqldatabase="${target}${dbdatapostfix}"
    local config_src=${target}/"config/config_inc.php.sample"
    local config_dst=${target}/"config/config_inc.php"
    echo -e "${INFO}Configuring ${target} instance at ${config_dst}${OFF}"
    echo -e "${INFO}config_src:${OFF}" "$config_src"
    echo -e "${INFO}config_dst:${OFF}" "$config_dst"
    cp ${config_src} ${config_dst}
    # Generate 32 characters of crypto salt:
    cryptosalt=$(openssl rand -hex 16)
    echo -e "${INFO}cryptosalt:${OFF}" "$cryptosalt"
    sed -i "s|# Rename this file to config_inc.php after configuration.|\$g_path = 'http://${domain_idname}/${target}/';|" ${config_dst}
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
    sed -i "s|#[[:space:]]*\$g_from_name[[:space:]]*=[[:space:]]*'Mantis Bug Tracker'|\$g_from_name = '${target^}'|" ${config_dst}
    sed -i "s|#[[:space:]]*\$g_email_receive_own[[:space:]]*=[[:space:]]*OFF|\$g_smtp_port = 587|" ${config_dst}
    sed -i "s|#[[:space:]]*\$g_email_send_using_cronjob[[:space:]]*=[[:space:]]*OFF|\$g_smtp_connection_mode = 'tls'|" ${config_dst}
    sed -i "s|#[[:space:]]*\$g_max_file_size[[:space:]]*=[[:space:]]*5000000|\$g_max_file_size = 2 * 1024 * 1024|" ${config_dst}
    sed -i "/\$g_db_type[[:space:]]*=[[:space:]]*'mysqli';/a\\
\$g_wiki_enable = ON;\\
\$g_wiki_engine = 'dokuwiki';\\
\$g_wiki_root_namespace = 'doctis';\\
\$g_wiki_engine_url = '../doctis-wiki/';\\
\$g_display_bug_padding = 5;\\
\$g_display_dwg_padding = 4;\\
\$g_display_bugnote_padding = 5;\\
\$g_display_dwgnote_padding = 4;
" ${config_dst}
if [ ${target} = "doctis" ]; then
    sed -i "s|#[[:space:]]*\$g_window_title[[:space:]]*=[[:space:]]*'MantisBT'|\$g_window_title = '${target^}'|" ${config_dst}
    sed -i "s|#[[:space:]]*\$g_logo_image[[:space:]]*=[[:space:]]*'images/mantis_logo.png'|\$g_logo_image = 'images/doctis_logo.png'|" ${config_dst}
    sed -i "s|#[[:space:]]*\$g_favicon_image[[:space:]]*=[[:space:]]*'images/favicon.ico'|\$g_favicon_image = 'images/doctis_icon.ico'|" ${config_dst}
    cat >> ${config_dst} << EOF
\$g_reauthentication = OFF;
\$g_reauthentication_expiry = 86400;
\$g_bug_report_page_fields = array(${default_page_fields});
\$g_bug_view_page_fields = array(${default_page_fields});
\$g_bug_update_page_fields = array(${default_page_fields});
\$g_severity_enum_string = '20:comment,30:query,50:minor,60:major';
\$g_default_bug_severity = 20; // Set comment as default
#\$g_reproducibility_enum_string = '';
#\$g_enable_profiles = OFF;
\$USE_LOREM_IPSUM = true;

# --- File / Document storage ---
\$g_file_upload_method  = DATABASE;	# bug/issue attachments
\$g_dwg_upload_method   = GIT;		# document (dwg) attachments
\$g_git_storage_root    = '/var/git/doctis';
\$g_git_worktree_root   = '/var/www/doctis/worktrees';
\$g_git_http_enabled    = ON;		# Smart HTTP remote clone gateway

# --- System Operations ---
# OS account that admin manage_*_action.php pages run privileged operations as
# (git pull, DB rebuild/backup, sample data, config write).  Set to the account
# performing this install — it owns the working tree and holds the sudoers rules.
\$g_updater_run_as_user = '$(whoami)';
EOF
fi
if [ "$2" = "nodbprepostfix" ]; then
    sed -i "/\$g_db_type[[:space:]]*=[[:space:]]*'mysqli';/a\\
\$g_db_table_prefix = '';\\
\$g_db_table_suffix = '';
" ${config_dst}
fi
    echo -e "${INFO}Project ${target} configured.${OFF}" >&2
}

publish_target() {
    local target="$1"
    local publish_dir="${webroot}/${target}/"
    echo -e "${INFO}Publishing ${target} to ${publish_dir}...${OFF}"
    chmod -R g+w ${target}
    find ${target} -type d -exec chmod g+ws {} \;
    prompt_delete_dir ${publish_dir}
    if [[ $? -gt 0 ]]; then
        echo -e "${FAIL}Target ${publish_dir} exists, aborting.${OFF}"
        return $?
    fi
    echo -e "${INFO}Copying ${target} to ${publish_dir}${OFF}" >&2
    cp -R ${target} ${publish_dir}
    echo -e "${INFO}Setting owner to $(whoami):www-data${OFF}" >&2
    sg www-data "chown -R $(whoami):www-data ${publish_dir}"
    echo -e "${INFO}Linking ${target}-www to ${publish_dir}${OFF}" >&2
    ln -s ${publish_dir} ${target}-www
    echo -e "${INFO}Publish complete.${OFF}" >&2
}

install_phpmyadmin() {
    local target="$1"
    echo -e "${INFO}Installing ${target} to ${webroot}/${OFF}"
    wget https://files.phpmyadmin.net/phpMyAdmin/${phpMyAdmin_ver}/phpMyAdmin-${phpMyAdmin_ver}-english.tar.xz
    tar -xf phpMyAdmin-${phpMyAdmin_ver}-english.tar.xz
    rm phpMyAdmin-${phpMyAdmin_ver}-english.tar.xz
    prompt_delete_dir ${target}
    if [[ $? -gt 0 ]]; then
        echo -e "${FAIL}Target ${target} exists, aborting.${OFF}"
        return $?
    fi
    mv phpMyAdmin-${phpMyAdmin_ver}-english ${target}
    local config_src=${target}/"config.sample.inc.php"
    local config_dst=${target}/"config.inc.php"
    cp "${config_src}" "${config_dst}"
    local blowfish=$(openssl rand -hex 16)
    echo -e "${INFO}blowfish:${OFF}" "${blowfish}"
    sed -i "s|\$cfg\['blowfish_secret'\][[:space:]]*=[[:space:]]*''|\$cfg\['blowfish_secret'\] = '${blowfish}'|" ${config_dst}
    prompt_delete_dir ${webroot}/${target}
    if [[ $? -gt 0 ]]; then
        echo -e "${FAIL}Target ${webroot}/${target} exists, aborting.${OFF}"
        return $?
    fi
    mv ${target} ${webroot}
    sg www-data "chown -R $(whoami):www-data ${webroot}/${target}"
    chmod -R g+w ${webroot}/${target}
    find ${webroot}/${target} -type d -exec chmod g+ws {} \;
    echo -e "${INFO}${target} install complete.${OFF}" >&2
}

install_dokuwiki() {
    local target="$1"
    local branch="${2:-stable}"
    local repository="${3:-${git_repository}}"
    local config_src=${target}/"conf/local.php.dist"
    local config_dst=${target}/"conf/local.php"
    echo -e "${INFO}Installing ${target} to ${webroot}/${OFF}"
    prompt_delete_dir ${target}
    if [[ $? -gt 0 ]]; then
        echo -e "${FAIL}Target ${target} exists, aborting.${OFF}"
        return $?
    fi
    git clone --recurse-submodules -b ${branch} --single-branch ${repository} ${target}
    echo -e "${INFO}Configuring ${target} instance at ${config_dst}${OFF}"
    echo -e "${INFO}config_src:${OFF}" "$config_src"
    echo -e "${INFO}config_dst:${OFF}" "$config_dst"
    cp ${config_src} ${config_dst}
    sed -i "s|//\$conf\['title'\][[:space:]]*=[[:space:]]*'My Wiki'|\$conf\['title'\] = '${target^}'|" ${config_dst}
    prompt_delete_dir ${webroot}/${target}
    if [[ $? -gt 0 ]]; then
        echo -e "${FAIL}Target ${webroot}/${target} exists, aborting.${OFF}"
        return $?
    fi
    mv ${target} ${webroot}
    sg www-data "chown -R $(whoami):www-data ${webroot}/${target}"
    chmod -R g+w ${webroot}/${target}
    find ${webroot}/${target} -type d -exec chmod g+ws {} \;
    echo -e "${INFO}${target} install complete.${OFF}" >&2
}

fetch_target() {
    local target="$1"
    local branch="${2:-master}"
    local repository="${3:-${git_repository}}"
    echo -e "${INFO}Cloning ${target} branch ${branch} from git repository: ${repository}...${OFF}"
    prompt_delete_dir "${target}"
    if [[ $? -gt 0 ]]; then
        echo -e "${FAIL}Target ${target} exists, aborting.${OFF}"
        return $?
    fi
    # Clone the specified target branch from git, then compose the php dependencies:
    git clone --recurse-submodules -b ${branch} --single-branch ${repository} ${target}
    cd ${target}
    git submodule update --init
    # Deterministic composer install
    if [[ -f composer.lock ]]; then
        composer install --no-interaction --prefer-dist
    else
        composer update --no-interaction
    fi
    cd ..
    echo -e "${INFO}${target} cloning complete.${OFF}" >&2
}

setup_target() {
    set_headless
    if [ $HEADLESS = false ]; then
        configure_vscode "$@"
    fi
    configure_target "$@"
    configure_database "$@"
    publish_target "$@"
}

run_mantis_install() {
    local target="$1"
    local install_url="http://${domain_idname}/${target}/admin/install.php"
    echo -e "${INFO}Running MantisBT database install/upgrade...${OFF}"
    if curl -fsS -d "install=2" "$install_url" | grep -q "installed successfully"; then
        echo -e "${INFO}✔ MantisBT database install successful.${OFF}"
    else
        echo -e "${FAIL}⚠ MantisBT installer did not confirm success. Check logs.${OFF}"
    fi
}

exec_install() {
    local target="$1"
    local install_url="http://${domain_idname}/${target}/admin/install.php"
    local logfile="${target}_install_log_$(date +%Y%m%d_%H%M%S).html"
    echo -e "${INFO}Running ${target^} database install/upgrade...${OFF}"
    # Capture the full output with tee, then grep separately
    if curl -fsS -d "install=2" "$install_url" \
        | tee "$logfile" \
        | grep -q "installed successfully"; then
        echo -e "${INFO}✔ ${target^} database install successful.${OFF}"
    else
        echo -e "${FAIL}⚠ ${target^} installer did not confirm success. Check logs.${OFF}"
    fi
    echo "  → Full installer output saved to $logfile"
}

load_example() {
    local target="$1"
    local mysqldatabase="${target}${dbdatapostfix}"
    echo -e "${INFO}Loading example data into '${mysqldatabase}'...${OFF}"
    ${database} <<EOF
USE ${mysqldatabase};
$(cat <<'SQL'
INSERT INTO `project` (`id`, `name`, `status`, `enabled`, `view_state`, `access_min`, `file_path`, `description`, `category_id`, `inherit_global`, `classification`)
VALUES (1, 'example', 10, 1, 10, 10, '', '', 1, 1, '');
SQL
)
EOF
    ${database} <<EOF
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
    echo -e "${INFO}Database '${mysqldatabase}' loaded.${OFF}" >&2
}

# blank password ''   : 'd41d8cd98f00b204e9800998ecf8427e'
# password 'pass'     : '1a1dc91c907325c69271ddf0c944bc72'
# password 'password' : '5f4dcc3b5aa765d61d8327deb882cf99'

launch_target() {
    local target="$1"
#    codium ${webroot}/${target} &
#    firefox "http://${domain_idname}/phpMyAdmin" &
    firefox "http://${domain_idname}/${target}" &
    xdg-open ${webroot}/${target}/admin/tools/getting-started.txt &
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

install_webkit() {
    echo -e "${DIAG}Started installing webtools..${OFF}"
    set_webroot
    install_phpmyadmin "phpMyAdmin" &
    install_dokuwiki "doctis-wiki" "stable" "https://github.com/dokuwiki/dokuwiki.git" &
    echo -e "${DIAG}Finished installing webtools.${OFF}"
}

version_info() {
    local repo="$1"  # Path to the git repository
    local output="$repo/version.json"
    echo -e "${DIAG}Generating version information file ${output}.${OFF}"
    # Check if repo is dirty
    local dirty_output
    dirty_output=$(git -C "$repo" status --porcelain 2>/dev/null || true)
    local dirty=false
    [[ -n "$dirty_output" ]] && dirty=true
    # Generate version.json inside the repository
    cat > "${output}" <<EOF
{
  "repo": "${repo}",
  "origin": "$(git -C "$repo" config --get remote.origin.url 2>/dev/null || echo "unknown")",
  "version": "$(git -C "$repo" describe --tags --abbrev=0 2>/dev/null || echo "0.0.0")",
  "commit": "$(git -C "$repo" rev-parse --short=10 HEAD 2>/dev/null || echo "unknown")",
  "branch": "$(git -C "$repo" rev-parse --abbrev-ref HEAD 2>/dev/null || echo "unknown")",
  "tag": "$(git -C "$repo" describe --tags --always --dirty 2>/dev/null || echo "unknown")",
  "build_date": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "dirty": ${dirty}
}
EOF
    echo -e "${DIAG}Finished generating version information file ${output}.${OFF}"
}

install_mantis() {
    echo -e "${DIAG}Started installing mantis..${OFF}"
    local target="mantisbt"
    local branch="original"
    fetch_target ${target} ${branch}
    setup_target ${target}
    exec_install ${target}
    echo -e "${DIAG}Finished installing mantis.${OFF}"
}

install_git_storage() {
    local target="$1"
    local git_setup="${webroot}/${target}/admin/tools/doctis-git-setup.sh"
    echo -e "${DIAG}Setting up git document storage...${OFF}"
    if [ -f "$git_setup" ]; then
        # doctis-git-setup.sh requires root (creates directories, sets ownership,
        # writes /var/www/.gitconfig). install-target.sh runs without sudo, so
        # invoke the script directly under sudo rather than sourcing it.
        sudo bash "$git_setup" "${webroot}/${target}"
        local _exit=$?
        if [ $_exit -ne 0 ]; then
            echo -e "${FAIL}doctis-git-setup.sh exited with code $_exit — git storage setup incomplete.${OFF}" >&2
            echo -e "${FAIL}Re-run manually after install: sudo bash ${git_setup} ${webroot}/${target}${OFF}" >&2
        fi
    else
        echo -e "${WARN}doctis-git-setup.sh not found at ${git_setup} — skipping git storage setup${OFF}"
    fi
}

install_doctis() {
    echo -e "${DIAG}Started installing doctis..${OFF}"
    local target="doctis"
    local branch="dev"
    fetch_target ${target} ${branch}
    version_info ${target}
    setup_target ${target} "nodbprepostfix"
    exec_install ${target}
    load_example ${target}
    install_git_storage ${target}
    set_headless
    if [ $HEADLESS = false ]; then
        launch_target ${target}
#        meld doctis-www mantisbt-www &
    fi
    show_parameters
    echo -e "${DIAG}Finished installing doctis.${OFF}"
    echo -e "${DIAG}Login with username 'administrator' password 'root'.${OFF}"
}

################################################################################
# Function entry point of the same name as the script, useful when source'd

install-target() {
    domain_idname="${1:-localhost}"
    mysqlpassword="${2:-password}"
    email_address="${3:-root@localhost}"
    email_hashtag="${4:-password}"
    show_parameters
    set_webroot
    install_mantis
    if [ "$5" = "doctis" ]; then
        install_webkit
        install_doctis
    fi
    echo -e "${DIAG}Done: <ctrl-c> to close${OFF}"
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    echo -e "${DIAG}This script is being invoked directly.${OFF}"
    install-target "$@"
    echo -e "${DIAG}Done: <ctrl-c> to close${OFF}"
else
    echo -e "${DIAG}This script is being sourced from ${0}.${OFF}"
fi

