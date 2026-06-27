#!/bin/bash
#
# Doctis — Write config/config_inc.php from stdin
#
# Receives new config content on stdin, creates a timestamped backup of the
# current config, then overwrites config_inc.php with the new content.
#
# Run via sudo as hcr from the web process (www-data):
#   echo yes | sudo -u hcr /var/www/html/doctis/admin/tools/doctis-write-config.sh
#
# The sudoers entry in /etc/sudoers.d/doctis-web authorises this.
#

CONFIG=/var/www/html/doctis/config/config_inc.php
BACKUP="${CONFIG}.bak.$(date +%Y%m%d_%H%M%S)"

if [ ! -f "$CONFIG" ]; then
	echo "ERROR: config file not found: $CONFIG" >&2
	exit 1
fi

cp "$CONFIG" "$BACKUP"
cat > "$CONFIG"

echo "Config written. Backup: $BACKUP"
