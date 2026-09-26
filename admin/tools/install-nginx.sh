#!/bin/bash
# Native Debian Doctis-only installation. Run as the development user, not root.
set -euo pipefail
export DOCTIS_BRANCH="${DOCTIS_BRANCH:-dev}"
mode="${1:-all}"
host="${2:-localhost}"
app=/var/www/html/doctis
operator="$(id -un)"
repo="${DOCTIS_GIT_REPO:-https://github.com/Inspirati/doctis.git}"
fail() { echo "ERROR: $*" >&2; exit 1; }
[[ $EUID -ne 0 ]] || fail 'Run as the development user; sudo is used where required.'
[[ $mode == all || $mode == system || $mode == target ]] || fail 'Expected all, system, or target.'
[[ $host =~ ^[A-Za-z0-9][A-Za-z0-9.-]*$ ]] || fail 'Supply a hostname or IPv4 address without scheme, port, or path.'
git check-ref-format --branch "$DOCTIS_BRANCH" >/dev/null
sudo -n true 2>/dev/null || sudo -v
if [[ $mode != target ]]; then
    if systemctl is-active --quiet apache2; then
        fail 'Apache is active. Select a separate VM or explicitly stop the conflicting service first.'
    fi
    sudo apt-get update
    sudo env DEBIAN_FRONTEND=noninteractive apt-get install -y nginx php-fpm php-cli php-mysql php-mbstring php-curl php-gd php-xml php-zip php-intl php-soap mariadb-server composer git graphviz curl unzip cron python3
    sudo systemctl enable --now mariadb cron
fi
[[ $mode != system ]] || exit 0
phpver="$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')"
[[ -d /etc/php/$phpver/fpm/pool.d ]] || fail 'PHP-FPM is not installed for the CLI PHP version.'
if [[ ! -e $app ]]; then
    sudo install -d -o "$operator" -g www-data -m 2755 "$app"
    git clone --branch "$DOCTIS_BRANCH" --single-branch --recurse-submodules "$repo" "$app"
else
    [[ -d $app/.git ]] || fail "$app exists but is not an application checkout."
    [[ $(git -C "$app" branch --show-current) == "$DOCTIS_BRANCH" ]] || fail 'Existing checkout is on another branch; update it explicitly.'
    echo 'Reusing existing application checkout without pull/reset.'
fi
cd "$app"
composer install --no-interaction --prefer-dist
composer check-platform-reqs
sudo install -d -o www-data -g www-data -m 2770 "$app/uploads" /var/lib/doctis/sessions /var/log/doctis
# Existing application configuration and credentials are deliberately preserved.
if [[ ! -f config/config_inc.php ]]; then
    tables=$(sudo mariadb --batch --skip-column-names -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='doctis';")
    [[ $tables == 0 ]] || fail 'An existing doctis database needs its original configuration restored before proceeding.'
    dbpass=$(openssl rand -hex 24)
    salt=$(openssl rand -hex 32)
    sudo mariadb <<SQL
CREATE DATABASE IF NOT EXISTS doctis CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'doctis'@'127.0.0.1' IDENTIFIED BY '${dbpass}';
ALTER USER 'doctis'@'127.0.0.1' IDENTIFIED BY '${dbpass}';
GRANT ALL ON doctis.* TO 'doctis'@'127.0.0.1';
SQL
    # Hex-only generated values and validated host are safe PHP literal contents.
    umask 077
    cat > config/config_inc.php <<PHP
<?php
\$g_hostname = '127.0.0.1';
\$g_db_username = 'doctis';
\$g_db_password = '$dbpass';
\$g_database_name = 'doctis';
\$g_db_type = 'mysqli';
\$g_db_table_prefix = '';
\$g_db_table_suffix = '';
\$g_crypto_master_salt = '$salt';
\$g_path = 'http://$host/doctis/';
\$g_window_title = 'Doctis';
\$g_logo_image = 'images/doctis_logo.png';
\$g_favicon_image = 'images/doctis_icon.ico';
\$g_wiki_enable = OFF;
\$g_qms_url = '';
\$g_file_upload_method = DISK;
\$g_absolute_path_default_upload_folder = '$app/uploads/';
\$g_dwg_upload_method = GIT;
\$g_git_storage_root = '/var/git/doctis';
\$g_git_worktree_root = '/var/www/doctis/worktrees';
\$g_git_http_enabled = ON;
\$g_updater_run_as_user = '$operator';
\$g_max_file_size = 2 * 1024 * 1024;
// No external mail is sent until SMTP is deliberately configured.
\$g_enable_email_notification = OFF;
PHP
    unset dbpass salt
    umask 022
fi
sudo chgrp www-data config/config_inc.php
chmod 640 config/config_inc.php
sudo tee "/etc/php/$phpver/fpm/pool.d/doctis.conf" >/dev/null <<'POOL'
[doctis]
user = www-data
group = www-data
listen = /run/php/doctis.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 5
pm.process_idle_timeout = 10s
php_admin_value[upload_max_filesize] = 64M
php_admin_value[post_max_size] = 64M
php_admin_value[memory_limit] = 256M
php_admin_value[session.save_path] = /var/lib/doctis/sessions
php_admin_value[error_log] = /var/log/doctis/php_errors.log
php_admin_flag[log_errors] = on
php_admin_value[opcache.revalidate_freq] = 0
POOL
sed "s/@HOST@/$host/" admin/tools/templates/nginx-doctis.conf | sudo tee /etc/nginx/sites-available/doctis >/dev/null
# Retire only Debian's packaged default symlink, not operator-created sites.
if [[ -L /etc/nginx/sites-enabled/default && $(readlink -f /etc/nginx/sites-enabled/default) == /etc/nginx/sites-available/default ]]; then
    sudo unlink /etc/nginx/sites-enabled/default
fi
sudo ln -sf /etc/nginx/sites-available/doctis /etc/nginx/sites-enabled/doctis
sudo nginx -t
sudo "php-fpm$phpver" -t
sudo systemctl enable --now nginx "php$phpver-fpm"
sudo systemctl restart "php$phpver-fpm"
sudo systemctl reload nginx
ready=false
for attempt in {1..30}; do
    if [[ $(curl -fsS --max-time 2 -H "Host: $host" http://127.0.0.1/doctis-health 2>/dev/null) == doctis-nginx ]]; then
        ready=true
        break
    fi
    sleep 1
done
[[ $ready == true ]] || fail 'nginx did not activate the Doctis site within 30 seconds.'
# Restrict initial install to an empty DB. Do not silently upgrade an existing schema.
tables=$(sudo mariadb --batch --skip-column-names -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='doctis';")
if [[ $tables == 0 ]]; then
    logfile=$(mktemp /tmp/doctis-install.XXXXXX.html)
    chmod 600 "$logfile"
    curl --fail --silent --show-error --max-time 180 -H "Host: $host" -d 'install=2' http://127.0.0.1/doctis/admin/install.php > "$logfile"
    grep -qi 'installed successfully' "$logfile" || fail "Schema installer did not confirm success; inspect $logfile."
    echo "Installer report: $logfile"
else
    sudo mariadb doctis -e "SELECT value FROM config WHERE config_id='database_version';" || fail 'Existing schema is not recognized; no reset or automatic upgrade performed.'
fi
sudo env DOCTIS_WEB_SERVER=nginx bash admin/tools/doctis-git-setup.sh "$app"
sudo tee /etc/cron.d/doctis >/dev/null <<'CRON'
SHELL=/bin/sh
PATH=/usr/local/bin:/usr/bin:/bin
* * * * * www-data cd /var/www/html/doctis && /usr/bin/php scripts/send_emails.php >> /var/log/doctis/cron.log 2>&1
CRON
sudo chmod 644 /etc/cron.d/doctis
curl --fail --silent --show-error --max-time 30 -H "Host: $host" http://127.0.0.1/doctis/login_page.php > /dev/null
printf 'Installed commit: '; git rev-parse HEAD
php --version | head -n1
mariadb --version
sudo nginx -v
printf 'Doctis available at http://%s/doctis/\n' "$host"
echo 'Fresh database login: administrator / root. Configure test users and SMTP separately.'
