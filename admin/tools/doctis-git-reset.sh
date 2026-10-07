#!/bin/bash
#
# doctis-git-reset.sh
#
# Resets the Doctis git document store to a clean empty state — the
# equivalent of doctis-drop-and-create-new-database.sh for the git side.
#
# What it does:
#   • Removes every per-project bare repository under $BARE_ROOT
#   • Removes every per-project working tree under $WORKTREE_ROOT
#   • Leaves the root directories themselves in place (ownership and
#     mode set by doctis-git-setup.sh are preserved)
#
# IMPORTANT: Run this together with doctis-drop-and-create-new-database.sh.
# Running one without the other leaves the database and the git store out
# of sync: the database will reference content that no longer exists in git,
# or git will contain content that is no longer indexed by the database.
#
# Usage (run directly on the server, or via SSH from a dev machine):
#
#   sudo bash /var/www/html/doctis/admin/tools/doctis-git-reset.sh
#
#   ssh hcr@vaio "sudo bash /var/www/html/doctis/admin/tools/doctis-git-reset.sh"
#

# ---------------------------------------------------------------------------
# Configuration — must match doctis-git-setup.sh
# ---------------------------------------------------------------------------
BARE_ROOT="/var/git/doctis"
WORKTREE_ROOT="/var/www/doctis/worktrees"
WEB_USER="www-data"
# ---------------------------------------------------------------------------

OFF="\033[0m"
RED="\033[31m"
GREEN="\033[32m"
YELLOW="\033[33m"
CYAN="\033[36m"

INFO=$CYAN
DIAG=$GREEN
WARN=$YELLOW
FAIL=$RED

show_parameters() {
    echo -e "${INFO}Configuration:${OFF}"
    echo -e "${INFO}  Bare repo root : ${OFF}${BARE_ROOT}"
    echo -e "${INFO}  Worktree root  : ${OFF}${WORKTREE_ROOT}"
    echo -e "${INFO}  Owner          : ${OFF}${WEB_USER}"
}

# Print a summary of what will be deleted.
show_contents() {
    echo -e "${INFO}Current git store contents:${OFF}"

    local bare_count=0 tree_count=0

    if [ -d "$BARE_ROOT" ]; then
        while IFS= read -r -d '' entry; do
            echo "  [bare]     $entry"
            bare_count=$((bare_count + 1))
        done < <(find "$BARE_ROOT" -mindepth 1 -maxdepth 1 -print0 2>/dev/null)
    else
        echo -e "  ${WARN}$BARE_ROOT does not exist${OFF}"
    fi

    if [ -d "$WORKTREE_ROOT" ]; then
        while IFS= read -r -d '' entry; do
            echo "  [worktree] $entry"
            tree_count=$((tree_count + 1))
        done < <(find "$WORKTREE_ROOT" -mindepth 1 -maxdepth 1 -print0 2>/dev/null)
    else
        echo -e "  ${WARN}$WORKTREE_ROOT does not exist${OFF}"
    fi

    local total=$((bare_count + tree_count))
    if [ "$total" -eq 0 ]; then
        echo "  (already empty — nothing to remove)"
    else
        echo ""
        echo -e "  ${WARN}${total} item(s) will be permanently deleted.${OFF}"
    fi
}

reset_git_store() {
    local removed=0 errors=0

    # Remove all per-project bare repos
    if [ -d "$BARE_ROOT" ]; then
        while IFS= read -r -d '' entry; do
            if rm -rf "$entry"; then
                echo -e "  ${DIAG}Removed:${OFF} $entry"
                removed=$((removed + 1))
            else
                echo -e "  ${FAIL}Failed to remove:${OFF} $entry" >&2
                errors=$((errors + 1))
            fi
        done < <(find "$BARE_ROOT" -mindepth 1 -maxdepth 1 -print0 2>/dev/null)
    fi

    # Remove all per-project working trees
    if [ -d "$WORKTREE_ROOT" ]; then
        while IFS= read -r -d '' entry; do
            if rm -rf "$entry"; then
                echo -e "  ${DIAG}Removed:${OFF} $entry"
                removed=$((removed + 1))
            else
                echo -e "  ${FAIL}Failed to remove:${OFF} $entry" >&2
                errors=$((errors + 1))
            fi
        done < <(find "$WORKTREE_ROOT" -mindepth 1 -maxdepth 1 -print0 2>/dev/null)
    fi

    if [ "$errors" -gt 0 ]; then
        echo -e "${FAIL}Reset completed with ${errors} error(s). Check output above.${OFF}" >&2
        return 1
    fi

    if [ "$removed" -eq 0 ]; then
        echo -e "${DIAG}Git store was already empty — nothing to do.${OFF}"
    else
        echo -e "${DIAG}Git store reset: ${removed} item(s) removed.${OFF}"
    fi
}

main() {
    show_contents
    echo ""
    reset_git_store
}

# ---------------------------------------------------------------------------

if [ "$(id -u)" -ne 0 ]; then
    echo -e "${FAIL}This script must be run as root. Use: sudo bash $0${OFF}" >&2
    exit 1
fi

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    # Script invoked directly — require explicit confirmation
    echo -e "${DIAG}doctis-git-reset.sh — invoked directly${OFF}"
    show_parameters
    echo ""
    echo -e "${WARN}WARNING: This will permanently delete all document content from the git store.${OFF}"
    echo -e "${WARN}Run this together with doctis-drop-and-create-new-database.sh to keep${OFF}"
    echo -e "${WARN}the database and git store in sync.${OFF}"
    echo ""
    read -rp "Type 'yes' to proceed: " answer
    if [ "$answer" = "yes" ]; then
        main "$@"
    else
        echo "Aborted."
    fi
    echo -e "${DIAG}Done: <ctrl-c> to close${OFF}"
else
    # Script sourced from another script — run without prompting
    echo -e "${DIAG}doctis-git-reset.sh sourced from ${0}${OFF}"
    main "$@"
fi
