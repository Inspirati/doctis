#!/usr/bin/env bash
# Reset this native nginx development VM's Doctis database and Git store as a pair.
# This is a whole-instance reset, never a project-scoped production rollback.
set -euo pipefail

app_root=/var/www/html/doctis
bare_root=/var/git/doctis
worktree_root=/var/www/doctis/worktrees
mode="${1:---preview}"

fail() {
	echo "ERROR: $*" >&2
	exit 1
}

[[ $# -le 1 && ( "$mode" == --preview || "$mode" == --execute ) ]] ||
	fail 'Usage: sudo bash doctis-reset-native-test.sh [--preview|--execute]'
[[ $(id -u) -eq 0 ]] || fail 'Run this script as root with sudo.'
[[ -f "$app_root/config/config_inc.php" ]] || fail 'Native Doctis configuration is missing.'
[[ -f /etc/nginx/sites-available/doctis ]] || fail 'Native nginx site is missing.'
[[ -d "$bare_root" && ! -L "$bare_root" ]] || fail 'Unexpected Git bare root.'
[[ -d "$worktree_root" && ! -L "$worktree_root" ]] || fail 'Unexpected Git worktree root.'
command -v mariadb >/dev/null || fail 'MariaDB CLI is missing.'
command -v mysql >/dev/null || fail 'MySQL CLI alias is missing.'
command -v curl >/dev/null || fail 'curl is missing.'

exec 9>/run/lock/doctis-reset-native-test.lock
flock -n 9 || fail 'Another native Doctis reset is running.'

echo 'Whole-instance native Doctis reset target:'
echo '  Database: doctis'
echo "  Bare repositories: $bare_root"
echo "  Worktrees: $worktree_root"
echo "  Application: $app_root"
echo 'Current Git store entries:'
find "$bare_root" "$worktree_root" -mindepth 1 -maxdepth 1 -printf '  %p\n' | sort
echo 'Current database counts (project, document, repository):'
if db_counts=$(mariadb --batch --skip-column-names doctis -e \
	'SELECT (SELECT COUNT(*) FROM project), (SELECT COUNT(*) FROM dwg), (SELECT COUNT(*) FROM repository);' \
	2>/dev/null); then
	echo "$db_counts"
else
	echo '  Database absent or incomplete; --execute can rebuild it.'
fi

if [[ "$mode" == --preview ]]; then
	echo 'Preview only. Use --execute to regenerate both stores and reload sample data.'
	exit 0
fi

# These existing scripts do the work; a failure in either must stop the pair.
# Run as root so MariaDB's local socket authentication needs no copied password.
bash "$app_root/admin/tools/doctis-git-reset.sh" <<< 'yes'
bash "$app_root/admin/tools/doctis-drop-and-create-new-database.sh" \
	doctis not-used 127.0.0.1 <<< 'yes'
bash "$app_root/admin/tools/doctis-load-sample-data.sh" <<< 'yes'

[[ -z $(find "$bare_root" "$worktree_root" -mindepth 1 -maxdepth 1 -type d -print -quit) ]] ||
	fail 'A Git repository/worktree remains after reset.'
[[ $(mariadb --batch --skip-column-names doctis -e 'SELECT COUNT(*) FROM repository;') == 0 ]] ||
	fail 'Repository rows remain after reset.'
[[ $(mariadb --batch --skip-column-names doctis -e 'SELECT COUNT(*) FROM dwg;') == 0 ]] ||
	fail 'Document rows remain after reset.'
[[ $(mariadb --batch --skip-column-names doctis -e "SELECT COUNT(*) FROM project WHERE name='example';") == 1 ]] ||
	fail 'The example project was not restored.'
[[ $(mariadb --batch --skip-column-names doctis -e 'SELECT COUNT(*) FROM user;') -ge 15 ]] ||
	fail 'Sample users were not restored.'
[[ -n $(mariadb --batch --skip-column-names doctis -e \
	"SELECT value FROM config WHERE config_id='database_version' LIMIT 1;") ]] ||
	fail 'The installed schema version is missing.'
curl --fail --silent --show-error --max-time 10 \
	http://127.0.0.1/doctis/login_page.php >/dev/null ||
	fail 'The Doctis login page is unavailable after reset.'

echo 'Reset complete: empty document/repository stores, installed schema, and sample data verified.'
