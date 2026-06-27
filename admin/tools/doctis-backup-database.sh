#!/bin/bash
#
# Doctis — Dump the doctis database to stdout
#
# Streams a mysqldump of the doctis database to stdout.  Intended to be piped
# through gzip and sent directly to the browser by the PHP backup action, so
# nothing is written to disk.
#
# Reads MariaDB credentials from ~/.my.cnf (hcr's credentials).
#
# Run via sudo as hcr from the web process (www-data):
#   sudo -u hcr /var/www/html/doctis/admin/tools/doctis-backup-database.sh | gzip
#
# The sudoers entry in /etc/sudoers.d/doctis-web authorises this.
#

exec /usr/bin/mysqldump \
	--single-transaction \
	--routines \
	--triggers \
	--add-drop-table \
	doctis
