#!/bin/bash
# doctis-git-import.sh — thin wrapper for the Doctis git repository importer.
#
# Runs admin/import-git-repo.php as www-data (required: the importer clones
# into $g_git_storage_root and writes DB rows under the web-server identity).
#
# Usage (all arguments are passed through — see import-git-repo.php --help):
#   bash doctis-git-import.sh --source /var/git/MyRepo [--dry-run] [options]

DOCTIS_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

exec sudo -u www-data php "${DOCTIS_ROOT}/admin/import-git-repo.php" "$@"
