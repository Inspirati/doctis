#!/bin/bash
# Prepare a live `user` table snapshot for loading onto the development VM.
#
# The live server keeps its seeded `administrator` account DISABLED, which is
# correct there.  Loading that snapshot onto the VM disables the VM's
# administrator too, and both test suites then fail at their first access
# check -- PHPUnit with "Login as 'administrator' ... failed", and
# test-git-doctis.php with APPLICATION ERROR #18, neither of which names the
# real cause.  The accounts are wanted on the VM; the disabled flag is not.
#
# This appends one UPDATE to a copy of the snapshot.  It deliberately does not
# edit the data row: that would depend on the column order and on the exact
# quoting the dump used, and would break silently the next time the user table
# gains a column.  A trailing statement is independent of both, and the Load
# Data page runs every statement in the file.
#
# NEVER run the output against the live server.  See CLAUDE.md, "Updating the
# live version with a database rebuild".
#
# Usage: doctis-snapshot-for-vm.sh <live-user-snapshot.sql> [output.sql]
#        default output: <input basename>-vm.sql, beside the input

set -eu

if [ $# -lt 1 ] || [ $# -gt 2 ]; then
    echo "Usage: $(basename "$0") <live-user-snapshot.sql> [output.sql]" >&2
    exit 2
fi

src="$1"
if [ ! -f "$src" ]; then
    echo "ERROR: no such file: $src" >&2
    exit 1
fi

# Guard against being pointed at the wrong snapshot: without the user table
# the appended UPDATE would be the only thing the file did.
if ! grep -qiE 'INTO `?user`? ' "$src"; then
    echo "ERROR: $src does not look like a 'user' table snapshot." >&2
    echo "       Take one from Manage > System Operations > Backup Database," >&2
    echo "       Data Snapshot of the user table, in REPLACE mode." >&2
    exit 1
fi

if [ $# -eq 2 ]; then
    out="$2"
else
    dir=$(dirname "$src")
    base=$(basename "$src" .sql)
    out="$dir/$base-vm.sql"
fi

if [ -e "$out" ]; then
    echo "ERROR: $out already exists; remove it or name another output file." >&2
    exit 1
fi

marker='doctis-snapshot-for-vm'
if grep -q "$marker" "$src"; then
    echo "ERROR: $src has already been prepared for the VM." >&2
    exit 1
fi

cp -- "$src" "$out"
cat >> "$out" <<'EOF'

-- ---------------------------------------------------------------------------
-- doctis-snapshot-for-vm: DEVELOPMENT VM ONLY -- DO NOT LOAD ON THE LIVE SERVER
--
-- Live keeps `administrator` disabled on purpose.  The VM's test suites log in
-- as that account, so re-enable it here rather than remembering to do it by
-- hand after every load.
-- ---------------------------------------------------------------------------
UPDATE `user` SET enabled = 1 WHERE username = 'administrator';
EOF

accounts=$(grep -c "^(" "$out" 2>/dev/null || echo '?')
echo "Wrote $out"
echo "  source   : $src"
echo "  accounts : $accounts row(s)"
echo "  appended : UPDATE enabling 'administrator' (VM only)"
