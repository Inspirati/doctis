#!/bin/bash
# doctis-remote-clone-edit-push.sh
#
# Simulates an external Doctis user working from their own workstation over
# the Smart HTTP gateway (see doc/git/GIT_ARCHITECTURE.md — "Smart HTTP
# Gateway (Remote Clone)"): clone a project's document repository using a
# Doctis API token, edit a tracked file, commit, and push the change back.
#
# This is a CLIENT-side script — run it here, on a workstation, NOT via ssh
# on the Doctis server. It talks to the server only over HTTP(S), exactly as
# a real remote contributor would. It exercises:
#   - API token authentication (git_http_extract_token / api_token_get_user)
#   - read access at $g_git_http_read_threshold (clone)
#   - write access at $g_git_http_write_threshold (push)
#   - the fact that a push advances the repository Draft (HEAD) only — the
#     Doctis On-Record version stays pinned until promoted inside Doctis
#
# Prerequisites:
#   - $g_git_http_enabled = ON on the target Doctis instance
#   - A personal API token, created under My Account -> API Tokens in the
#     Doctis UI, or scripted on the server (prints the token once):
#       ssh hcr@vaio "sudo -u www-data php -r '
#         require \"/var/www/html/doctis/core.php\";
#         require_api(\"api_token_api.php\"); require_api(\"authentication_api.php\");
#         auth_attempt_script_login(\"manager\");
#         echo api_token_create(\"remote-test\", auth_get_current_user_id());
#       '"
#   - The repository basename (e.g. "example-r1") — visible in Doctis on
#     the document's "Advanced: Direct Git Repository Access" page
#     (dwg_primary_head_warn.php), or via: ls /var/git/doctis/ on the server
#
# Usage:
#   doctis-remote-clone-edit-push.sh --host HOST --repo REPO --user USER \
#       [--token TOKEN] [--path FILE] [--message MSG] [--branch BRANCH] \
#       [--https] [--keep] [--workdir DIR]
#
# Examples:
#   ./doctis-remote-clone-edit-push.sh --host 10.0.0.10/doctis \
#       --repo hcrqms-r5 --user manager
#   # (prompts for the API token; picks the first *.md file and appends a
#   #  timestamped marker line, commits, and pushes)
#
#   ./doctis-remote-clone-edit-push.sh --host 10.0.0.10/doctis --repo example-r1 \
#       --user manager --token "$DOCTIS_TOKEN" \
#       --path 1/report.md --message "Remote test edit" --keep
#
# Options:
#   --host HOST        Doctis base URL host[:port][/path], e.g. 10.0.0.10/doctis
#                       (no scheme; use --https to select https instead of http)
#   --repo REPO         Repository basename without ".git", e.g. "example-r1"
#   --user USER         Doctis username to authenticate as
#   --token TOKEN        API token (password over Basic auth). If omitted, read
#                       from $DOCTIS_API_TOKEN or prompted for interactively
#                       (never placed on the command line / process list)
#   --path FILE         Repo-relative file to edit (default: first *.md, else
#                       first tracked text-ish file at HEAD)
#   --message MSG        Commit message (default: "Remote test edit from <host> <ts>")
#   --branch BRANCH      Branch to push (default: the clone's current branch)
#   --https              Use https:// instead of http://
#   --keep                Do not delete the working clone on exit; print its path
#   --workdir DIR         Clone into DIR instead of a fresh temp directory
#   -h, --help            Show this help
#
# Exit codes: 0 success; 1 usage/argument error; 2 clone failed;
#             3 no suitable file found to edit; 4 commit/push failed.

set -u

HOST=""
REPO=""
DOCTIS_USER=""
TOKEN="${DOCTIS_API_TOKEN:-}"
TARGET_PATH=""
MESSAGE=""
BRANCH=""
SCHEME="http"
KEEP=0
WORKDIR=""

usage() {
	sed -n '2,/^set -u/p' "$0" | sed '$d' | sed 's/^# \{0,1\}//'
	exit "${1:-0}"
}

while [ $# -gt 0 ]; do
	case "$1" in
		--host) HOST="$2"; shift 2 ;;
		--repo) REPO="$2"; shift 2 ;;
		--user) DOCTIS_USER="$2"; shift 2 ;;
		--token) TOKEN="$2"; shift 2 ;;
		--path) TARGET_PATH="$2"; shift 2 ;;
		--message) MESSAGE="$2"; shift 2 ;;
		--branch) BRANCH="$2"; shift 2 ;;
		--https) SCHEME="https"; shift ;;
		--keep) KEEP=1; shift ;;
		--workdir) WORKDIR="$2"; shift 2 ;;
		-h|--help) usage 0 ;;
		*) echo "Unknown argument: $1" >&2; usage 1 ;;
	esac
done

if [ -z "$HOST" ] || [ -z "$REPO" ] || [ -z "$DOCTIS_USER" ]; then
	echo "ERROR: --host, --repo, and --user are all required." >&2
	usage 1
fi

if [ -z "$TOKEN" ]; then
	read -r -s -p "API token for ${DOCTIS_USER}@${HOST}: " TOKEN
	echo
fi
if [ -z "$TOKEN" ]; then
	echo "ERROR: no API token supplied." >&2
	exit 1
fi

CLONE_URL="${SCHEME}://${DOCTIS_USER}@${HOST}/git/${REPO}.git"

CLEANUP_WORKDIR=""
ASKPASS_SCRIPT=""
cleanup() {
	[ -n "$ASKPASS_SCRIPT" ] && rm -f "$ASKPASS_SCRIPT"
	if [ "$KEEP" -eq 0 ] && [ -n "$CLEANUP_WORKDIR" ] && [ -d "$CLEANUP_WORKDIR" ]; then
		rm -rf "$CLEANUP_WORKDIR"
	fi
}
trap cleanup EXIT

# GIT_ASKPASS keeps the token out of the command line, process list, and
# .git/config (the remote URL carries only the username).
ASKPASS_SCRIPT="$(mktemp)"
cat > "$ASKPASS_SCRIPT" <<EOF
#!/bin/sh
echo '${TOKEN}'
EOF
chmod +x "$ASKPASS_SCRIPT"
export GIT_ASKPASS="$ASKPASS_SCRIPT"
export GIT_TERMINAL_PROMPT=0

if [ -n "$WORKDIR" ]; then
	mkdir -p "$WORKDIR"
else
	WORKDIR="$(mktemp -d)"
fi
CLEANUP_WORKDIR="$WORKDIR"

echo "Cloning ${SCHEME}://${DOCTIS_USER}@${HOST}/git/${REPO}.git ..."
if ! git clone --quiet "$CLONE_URL" "$WORKDIR" 2>/tmp/doctis-clone-err.$$; then
	echo "ERROR: clone failed. Check host/repo/token and that \$g_git_http_enabled is ON." >&2
	cat /tmp/doctis-clone-err.$$ >&2
	rm -f /tmp/doctis-clone-err.$$
	exit 2
fi
rm -f /tmp/doctis-clone-err.$$
echo "Cloned into: $WORKDIR"

cd "$WORKDIR" || exit 2

if [ -z "$BRANCH" ]; then
	BRANCH="$(git branch --show-current)"
fi
echo "Branch: $BRANCH"

BEFORE_SHA="$(git rev-parse HEAD)"
echo "HEAD before push: $BEFORE_SHA"

# Pick a file to edit if one wasn't given: prefer a markdown/text file so an
# appended marker line cannot corrupt binary content (PDFs, images, etc).
if [ -z "$TARGET_PATH" ]; then
	TARGET_PATH="$(git ls-files -- '*.md' '*.txt' | head -1)"
	if [ -z "$TARGET_PATH" ]; then
		echo "ERROR: no *.md/*.txt file found at HEAD; pass --path <file> explicitly" \
			"(and prefer a text file — this script appends a text marker line)." >&2
		exit 3
	fi
fi
if [ ! -f "$TARGET_PATH" ]; then
	echo "ERROR: '$TARGET_PATH' does not exist in the clone." >&2
	exit 3
fi
echo "Editing: $TARGET_PATH"

TS="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
MARKER="<!-- Remote test edit: ${TS} by $(hostname)/${DOCTIS_USER} via doctis-remote-clone-edit-push.sh -->"
printf '\n%s\n' "$MARKER" >> "$TARGET_PATH"

if [ -z "$MESSAGE" ]; then
	MESSAGE="Remote test edit from $(hostname) ${TS}"
fi

git add "$TARGET_PATH"
if ! git -c user.name="$DOCTIS_USER" -c user.email="${DOCTIS_USER}@remote-test.invalid" \
	commit --quiet -m "$MESSAGE"; then
	echo "ERROR: commit failed (nothing to commit?)." >&2
	exit 4
fi
AFTER_SHA="$(git rev-parse HEAD)"

echo "Pushing ${BRANCH}..."
if ! git push --quiet origin "$BRANCH" 2>/tmp/doctis-push-err.$$; then
	echo "ERROR: push failed. Check that ${DOCTIS_USER} has push access" \
		"(\$g_git_http_write_threshold, default MANAGER) to this project." >&2
	cat /tmp/doctis-push-err.$$ >&2
	rm -f /tmp/doctis-push-err.$$
	exit 4
fi
rm -f /tmp/doctis-push-err.$$

echo
echo "── Result ──────────────────────────────────────────────────────────────"
echo "  Before : $BEFORE_SHA"
echo "  After  : $AFTER_SHA"
echo "  Pushed to origin/${BRANCH} — this advances the repository DRAFT only."
echo "  The Doctis On-Record version (if any) remains pinned until a manager"
echo "  promotes the new HEAD from the document's Primary Document panel"
echo "  (\"Sync to HEAD\"), or via dwg_primary_file_sync_head.php."
if [ "$KEEP" -eq 1 ]; then
	echo "  Clone left at: $WORKDIR"
fi
