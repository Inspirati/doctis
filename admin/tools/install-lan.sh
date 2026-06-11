#!/bin/bash
#
# install-lan.sh
#
# LAN-local variant of install.sh.  Pulls install scripts and the doctis
# git repository from the development server (vaio) on the local network
# instead of from GitHub.  Everything else is identical to the standard
# install chain:
#
#   install-lan.sh
#     └─ install-option.sh  (fetched from vaio via HTTP)
#           ├─ install-system.sh   (fetched from vaio)
#           └─ install-target.sh   (fetched from vaio; clones repo from vaio)
#
# Usage on a fresh VM:
#
#   wget http://10.0.0.10/doctis/admin/tools/install-lan.sh
#   bash install-lan.sh
#
# Customise the three variables below if needed.
#

# When invoked as 'curl URL | bash', bash's stdin is the pipe and sudo cannot
# prompt for a password.  Reconnect stdin to the terminal before proceeding.
exec </dev/tty

# ---------------------------------------------------------------------------
# LAN server — the vaio development machine
# ---------------------------------------------------------------------------
LAN_SERVER="10.0.0.10"
LAN_SCRIPT_ROOT="http://${LAN_SERVER}/doctis/admin/tools"
LAN_GIT_REPO="http://${LAN_SERVER}/git/doctis"
# ---------------------------------------------------------------------------

# Advertise the overrides to child scripts (install-option.sh, install-target.sh)
export DOCTIS_SCRIPT_URL="$LAN_SCRIPT_ROOT"
export DOCTIS_GIT_REPO="$LAN_GIT_REPO"

# Customise email and database credentials (same as install.sh)
email_addr="my.email@gmail.com"
email_hash="GmailAppPassword"
mysql_pass="password"

domain=$(ip route get 1 | awk '/src/ {print $7}')

echo "LAN install from ${LAN_SERVER}"
echo "  scripts : ${LAN_SCRIPT_ROOT}"
echo "  git repo: ${LAN_GIT_REPO}"
echo "  domain  : ${domain}"

wget --quiet -O install-option.sh "${LAN_SCRIPT_ROOT}/install-option.sh"
chmod +x install-option.sh

cmd="./install-option.sh install all \"${domain}\" \"${mysql_pass}\" \"${email_addr}\" \"${email_hash}\" \"doctis\""
echo "Running: $cmd"
eval "$cmd" | tee logfile.txt

# Example of the command line produced above:
# ./install-option.sh install all "10.0.0.10" "password" "my.email@gmail.com" "GmailAppPassword" "doctis"
