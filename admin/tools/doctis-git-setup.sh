#!/bin/bash
# =============================================================================
# doctis-git-setup.sh
# =============================================================================
# Sets up the Doctis git document-storage infrastructure on a Debian/Ubuntu
# server running Apache/PHP.  Implements every step in:
#   doc/doctis-git-server-setup.txt
#
# Designed to be sourced and called by install-target.sh as part of the
# standard Doctis install chain.  Can also be run standalone:
#
#   sudo bash /var/www/html/doctis/admin/tools/doctis-git-setup.sh [WEBROOT]
#
#   ssh hcr@vaio "sudo bash /var/www/html/doctis/admin/tools/doctis-git-setup.sh"
#
# Default WEBROOT: /var/www/html/doctis
# Idempotent: safe to re-run on an already-configured server.
# =============================================================================

IFS=$'\n\t'

# ---------------------------------------------------------------------------
# Configuration — must match config_defaults_inc.php and doctis-git-reset.sh
# ---------------------------------------------------------------------------
BARE_ROOT="/var/git/doctis"
WORKTREE_ROOT="/var/www/doctis/worktrees"
GITCONFIG="/var/www/.gitconfig"
WEB_USER="www-data"
GIT_EMAIL="doctis@vaio.local"
GIT_NAME="Doctis"
# ---------------------------------------------------------------------------

# Colour scheme — matches install-target.sh
OFF="\033[0m"
RED="\033[31m"
GREEN="\033[32m"
YELLOW="\033[33m"
CYAN="\033[36m"
FAIL=$RED
INFO=$CYAN
DIAG=$GREEN
WARN=$YELLOW

# Suppress colour when not writing to a terminal
if [ ! -t 1 ]; then
    OFF=""; RED=""; GREEN=""; YELLOW=""; CYAN=""
    FAIL=""; INFO=""; DIAG=""; WARN=""
fi

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

_gst_step()  { printf "\n${INFO}[INFO] ══ %s${OFF}\n" "$*"; }
_gst_ok()    { printf "  ${DIAG}✓${OFF}  %s\n" "$*"; }
_gst_info()  { printf "     %s\n" "$*"; }
_gst_warn()  { printf "  ${WARN}!${OFF}  %s\n" "$*"; }
_gst_fail()  { printf "\n${FAIL}[FAIL] %s${OFF}\n" "$*" >&2; return 1; }

_gst_chk()   { printf "  %-52s" "• $* ..."; }
_gst_pass()  { printf " ${DIAG}OK${OFF}  %s\n" "${1:-}"; }

# Run a command as WEB_USER with HOME set to their passwd home directory.
_as_web() { sudo -H -u "$WEB_USER" "$@"; }

# ---------------------------------------------------------------------------
# set_webroot — reuse from parent script if already defined, otherwise detect
# ---------------------------------------------------------------------------
if ! declare -f set_webroot > /dev/null 2>&1; then
    set_webroot() {
        if command -v apache2ctl >/dev/null 2>&1; then
            webroot="$(apache2ctl -t -D DUMP_RUN_CFG 2>/dev/null | awk '/DocumentRoot/ {print $3; exit}')"
        elif command -v httpd >/dev/null 2>&1; then
            webroot="$(httpd -t -D DUMP_RUN_CFG 2>/dev/null | awk '/DocumentRoot/ {print $3; exit}')"
        else
            webroot="/var/www/html"
        fi
        webroot="${webroot//\"/}"
    }
fi

# ---------------------------------------------------------------------------
# setup_git_storage — all setup steps; safe to call more than once
# ---------------------------------------------------------------------------
setup_git_storage() {
    local _webroot="$1"

    # ── Step 1 — git installed ───────────────────────────────────────────────
    _gst_step "Step 1 — Verify git is installed"

    _gst_chk "git binary"
    if git --version &>/dev/null; then
        _gst_pass "$(git --version)"
    else
        printf " not found — installing...\n"
        apt-get install -y git &>/dev/null \
            || { _gst_fail "apt-get install git failed"; return 1; }
        _gst_ok "installed: $(git --version)"
    fi

    # ── Step 2 — Storage directories ────────────────────────────────────────
    _gst_step "Step 2 — Create storage root directories"

    local dir
    for dir in "$BARE_ROOT" "$WORKTREE_ROOT"; do
        _gst_chk "$dir"
        [ -d "$dir" ] || mkdir -p "$dir" \
            || { _gst_fail "mkdir -p $dir failed"; return 1; }
        chown "$WEB_USER:$WEB_USER" "$dir" \
            || { _gst_fail "chown $dir failed"; return 1; }
        chmod 2770 "$dir"
        printf " ${DIAG}OK${OFF}  $(stat -c '%U:%G %a' "$dir")\n"
    done

    _gst_chk "ownership verification"
    for dir in "$BARE_ROOT" "$WORKTREE_ROOT"; do
        local owner mode
        owner=$(stat -c '%U:%G' "$dir")
        mode=$(stat -c '%a' "$dir")
        [ "$owner" = "$WEB_USER:$WEB_USER" ] \
            || { _gst_fail "$dir owner is $owner, expected $WEB_USER:$WEB_USER"; return 1; }
        [ "$mode" = "2770" ] \
            || { _gst_fail "$dir mode is $mode, expected 2770"; return 1; }
    done
    _gst_pass

    # ── Step 3 — www-data git identity ──────────────────────────────────────
    _gst_step "Step 3 — Write git identity for $WEB_USER"

    # /var/www/ is root-owned (mode 755); www-data cannot create files there,
    # so 'sudo -u www-data git config --global' fails with a lock-file error.
    # Write the config directly as root; www-data only needs read access at runtime.
    # safe.directory = * allows www-data to operate on repos not owned by
    # www-data.  This is needed wherever the webroot is NFS-mounted (e.g. on
    # the development server) and is harmless on freshly installed VMs where
    # all document repos are created by www-data and therefore owned by it.
    printf "[user]\n\temail = %s\n\tname = %s\n[init]\n\tdefaultBranch = main\n[safe]\n\tdirectory = *\n" \
        "$GIT_EMAIL" "$GIT_NAME" > "$GITCONFIG" \
        || { _gst_fail "Could not write $GITCONFIG"; return 1; }
    chown "$WEB_USER:$WEB_USER" "$GITCONFIG"
    chmod 644 "$GITCONFIG"
    _gst_ok "Wrote $GITCONFIG  ($GIT_NAME <$GIT_EMAIL>, defaultBranch=main, safe.directory=*)"

    _gst_chk "$WEB_USER can read identity"
    local identity
    identity=$(_as_web git config --global --list 2>&1) \
        || { _gst_fail "git config --global --list failed as $WEB_USER: $identity"; return 1; }
    echo "$identity" | grep -q "user.email=$GIT_EMAIL"    \
        || { _gst_fail "user.email not found in gitconfig"; return 1; }
    echo "$identity" | grep -q "user.name=$GIT_NAME"      \
        || { _gst_fail "user.name not found in gitconfig"; return 1; }
    echo "$identity" | grep -qi "init.defaultbranch=main" \
        || { _gst_fail "init.defaultBranch not found in gitconfig"; return 1; }
    _gst_pass

    # NOTE — Step 3a: Apache HOME and ensure_git_home()
    # Apache does not set HOME for www-data worker processes.  The PHP backend
    # compensates in GitFileStorageBackend::ensure_git_home().  No server-side
    # action is required here.

    # ── Step 4 — (Composer handled by fetch_target in install-target.sh) ────

    # ── Step 5 — Git cycle verification ─────────────────────────────────────
    _gst_step "Step 5 — Verify git create/commit/push/retrieve cycle"

    local test_slug="setup-verify-$$"
    local test_bare="$BARE_ROOT/$test_slug.git"
    local test_tree="$WORKTREE_ROOT/$test_slug"
    local test_rel="PROC-TEST-001/source.md"
    local test_content="# Test Document

Content."

    _cleanup_step5() { rm -rf "$test_bare" "$test_tree" 2>/dev/null || true; }
    trap '_cleanup_step5' EXIT

    _gst_chk "5a. git init --bare"
    _as_web git init --bare "$test_bare" &>/dev/null \
        || { _gst_fail "git init --bare $test_bare failed"; _cleanup_step5; return 1; }
    _gst_pass

    _gst_chk "5b. git clone worktree"
    _as_web git clone "$test_bare" "$test_tree" &>/dev/null \
        || { _gst_fail "git clone failed"; _cleanup_step5; return 1; }
    _gst_pass

    _gst_chk "5c. write, stage, commit, push"
    _as_web bash -c "
        set -e
        cd '$test_tree'
        mkdir -p PROC-TEST-001
        printf '%s\n' '$test_content' > '$test_rel'
        git add '$test_rel'
        git commit -m 'setup-verify: test commit'
        git push origin main
    " &>/dev/null || { _gst_fail "commit/push cycle failed"; _cleanup_step5; return 1; }
    _gst_pass

    _gst_chk "5d. bare repo received commit"
    local log
    log=$(_as_web git --git-dir="$test_bare" log --oneline 2>/dev/null)
    [ -n "$log" ] || { _gst_fail "bare repo log empty after push"; _cleanup_step5; return 1; }
    _gst_pass "$log"

    _gst_chk "5e-f. retrieve by HEAD"
    local retrieved
    retrieved=$(_as_web git --git-dir="$test_bare" show HEAD:"$test_rel" 2>/dev/null) \
        || { _gst_fail "git show HEAD failed"; _cleanup_step5; return 1; }
    [ -n "$retrieved" ] || { _gst_fail "retrieved content is empty"; _cleanup_step5; return 1; }
    _gst_pass

    _gst_chk "5g. SHA256 integrity (bare == worktree)"
    local hash_bare hash_tree
    hash_bare=$(_as_web git --git-dir="$test_bare" show HEAD:"$test_rel" 2>/dev/null \
                | sha256sum | awk '{print $1}')
    hash_tree=$(sha256sum "$test_tree/$test_rel" 2>/dev/null | awk '{print $1}')
    [ "$hash_bare" = "$hash_tree" ] \
        || { _gst_fail "SHA256 mismatch — bare: $hash_bare  worktree: $hash_tree"; _cleanup_step5; return 1; }
    _gst_pass "$hash_bare"

    _cleanup_step5
    trap - EXIT
    _gst_ok "Git cycle: all checks passed"

    # ── Step 6 — PHP integration test ───────────────────────────────────────
    _gst_step "Step 6 — PHP integration test (admin/test-git-php.php)"

    local php_test="${_webroot}/admin/test-git-php.php"
    if [ ! -f "$php_test" ]; then
        _gst_warn "$php_test not found — skipping PHP integration test"
    else
        printf "\n"
        _as_web php "$php_test"
        local php_exit=$?
        printf "\n"
        if [ $php_exit -eq 0 ]; then
            _gst_ok "PHP integration test passed (15/15)"
        else
            # Non-fatal: PHP CLI may fail on a fresh install if the database
            # session has not fully settled, or if the CLI php.ini differs from
            # Apache's.  Infrastructure steps (Apache config, sudoers) must
            # still complete.  The operator should re-run the test manually:
            #   sudo -u www-data php /var/www/html/doctis/admin/test-git-php.php
            _gst_warn "PHP integration test failed (exit $php_exit) — continuing."
            _gst_info "  Re-run after install: sudo -u www-data php ${php_test}"
            _gst_info "  Logs: sudo tail -30 /var/log/apache2/error.log | grep -v Xdebug"
        fi
    fi

    # ── Step 7 — config_inc.php ──────────────────────────────────────────────
    _gst_step "Step 7 — config/config_inc.php"

    local config="${_webroot}/config/config_inc.php"
    if [ ! -f "$config" ]; then
        _gst_warn "$config not found — skipping config step"
    else
        _write_config_key "$config" "g_dwg_upload_method"  "\$g_dwg_upload_method  = GIT;"
        _write_config_key "$config" "g_git_storage_root"   "\$g_git_storage_root   = '${BARE_ROOT}';"
        _write_config_key "$config" "g_git_worktree_root"  "\$g_git_worktree_root  = '${WORKTREE_ROOT}';"
        _write_config_key "$config" "g_git_http_enabled"   "\$g_git_http_enabled   = ON;"
    fi

    # ── Step 8 — Apache Smart HTTP gateway ──────────────────────────────────────
    _gst_step "Step 8 — Configure Apache Smart HTTP gateway"

    local conf_src="${_webroot}/admin/tools/git-serve.conf"
    local conf_dst="/etc/apache2/conf-available/git-serve.conf"

    if [ ! -f "$conf_src" ]; then
        _gst_warn "$conf_src not found — skipping Smart HTTP Apache setup"
    else
        _gst_chk "a2enmod alias setenvif"
        a2enmod alias setenvif &>/dev/null \
            || { _gst_fail "a2enmod alias setenvif failed"; return 1; }
        _gst_pass

        _gst_chk "install git-serve.conf"
        cp "$conf_src" "$conf_dst" \
            || { _gst_fail "cp $conf_src $conf_dst failed"; return 1; }
        _gst_pass

        _gst_chk "a2enconf git-serve"
        a2enconf git-serve &>/dev/null \
            || { _gst_fail "a2enconf git-serve failed"; return 1; }
        _gst_pass

        _gst_chk "apache2ctl configtest"
        apache2ctl configtest &>/dev/null \
            || { _gst_fail "Apache config test failed — run: apache2ctl configtest"; return 1; }
        _gst_pass

        _gst_chk "systemctl reload apache2"
        systemctl reload apache2 \
            || { _gst_fail "apache2 reload failed"; return 1; }
        _gst_pass

        _gst_ok "Smart HTTP gateway active: git clone http://<user>:<token>@<host>/git/<slug>.git"
    fi

    # ── Step 9 — Application self-update prerequisites ──────────────────────────
    _gst_step "Step 9 — Application self-update prerequisites"

    _gst_chk "system gitconfig safe.directory"
    if git config --system --get-all safe.directory 2>/dev/null | grep -qxF "${_webroot}"; then
        _gst_pass "(already present)"
    elif git config --system --add safe.directory "${_webroot}" 2>/dev/null; then
        _gst_pass "added (${_webroot})"
    else
        printf "\n"
        _gst_warn "Could not write /etc/gitconfig — 'git pull' page may show 'dubious ownership' errors"
        _gst_info "  Manual fix: sudo git config --system --add safe.directory ${_webroot}"
    fi

    local deploy_user="${SUDO_USER:-}"
    _gst_chk "sudoers rule (/etc/sudoers.d/doctis-web)"
    if [ -z "$deploy_user" ]; then
        printf "\n"
        _gst_warn "SUDO_USER not set — skipping sudoers rule"
        _gst_info "  Manual fix: sudo tee /etc/sudoers.d/doctis-web <<< \"www-data ALL=(\$USER) NOPASSWD: /usr/bin/git -C ${_webroot} pull\""
    else
        local sudoers_file="/etc/sudoers.d/doctis-web"
        if [ -f "$sudoers_file" ] && grep -qF "$deploy_user" "$sudoers_file" 2>/dev/null; then
            _gst_pass "(already present for $deploy_user)"
        else
            printf "www-data ALL=(%s) NOPASSWD: /usr/bin/git -C %s pull\n" \
                "$deploy_user" "${_webroot}" > "$sudoers_file" \
                && chmod 0440 "$sudoers_file" \
                && visudo -c -f "$sudoers_file" &>/dev/null \
                || { rm -f "$sudoers_file"; _gst_fail "sudoers rule creation failed"; return 1; }
            _gst_pass "created for $deploy_user"
        fi
    fi

    printf "\n${DIAG}Git storage setup complete.${OFF}\n"
}

# Append a config key to config_inc.php if it is not already present.
_write_config_key() {
    local config="$1" key="$2" line="$3"
    _gst_chk "$key"
    if grep -q "$key" "$config" 2>/dev/null; then
        local existing
        existing=$(grep "$key" "$config" | head -1 | sed 's/^[[:space:]]*//')
        _gst_pass "(already set: $existing)"
    else
        printf "%s\n" "$line" >> "$config" \
            || { _gst_fail "Could not write $key to $config"; return 1; }
        _gst_pass "added"
    fi
}

# =============================================================================
# Entry point — named to match the script filename (install-option.sh convention)
# =============================================================================
doctis-git-setup() {
    # Parameters follow the same order as other install scripts
    # (domain_idname mysqlpassword email_address email_hashtag target)
    # but git setup does not use them — it derives what it needs from the server.
    local _target="${5:-doctis}"

    # Resolve webroot: reuse from parent script if already set, otherwise detect
    if [ -z "${webroot:-}" ]; then
        set_webroot
    fi
    local _webroot="${webroot}/${_target}"

    echo -e "${INFO}[INFO] Doctis git storage setup${OFF}"
    echo -e "${INFO}[INFO]   webroot  : ${_webroot}${OFF}"
    echo -e "${INFO}[INFO]   bare repos: ${BARE_ROOT}${OFF}"
    echo -e "${INFO}[INFO]   worktrees : ${WORKTREE_ROOT}${OFF}"

    if [ "$(id -u)" -ne 0 ]; then
        echo -e "${FAIL}[FAIL] Must run as root (sudo).${OFF}" >&2
        return 1
    fi

    setup_git_storage "$_webroot"
}

# =============================================================================
# Direct invocation — mirrors pattern used by install-target.sh / install-system.sh
# =============================================================================
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    echo -e "${DIAG}doctis-git-setup.sh — invoked directly${OFF}"
    # Accept explicit webroot as first argument for standalone use
    if [ -n "${1:-}" ] && [ -d "$1" ]; then
        webroot="$(dirname "$1")"
        _override_target="$(basename "$1")"
    fi
    doctis-git-setup "" "" "" "" "${_override_target:-doctis}"
    echo -e "${DIAG}Done: <ctrl-c> to close${OFF}"
else
    echo -e "${DIAG}doctis-git-setup.sh sourced from ${0}${OFF}"
fi
