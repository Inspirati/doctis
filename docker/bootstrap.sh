#!/bin/bash
echo "== Doctis Bootstrap Script =="

DBHOST="db"
#DBUSER="doctis"
DBUSER="root"
DBPASS="password"
DBNAME="doctis"

echo "Waiting for DB..."
#until mysql -h $DBHOST -u $DBUSER -p$DBPASS -e "SELECT 1;" &> /dev/null; do
until mysql -h $DBHOST --ssl=0 -u $DBUSER -p$DBPASS -e "SELECT 1;" &> /dev/null; do
  echo "  DB not ready yet..."
  sleep 2
done

echo "Database is ready."

chmod +x /var/www/html/admin/tools/doctis-drop-and-create-new-database.sh
/var/www/html/admin/tools/doctis-drop-and-create-new-database.sh

#chmod +x admin/tools/doctis-drop-and-create-new-database.sh
#admin/tools/doctis-drop-and-create-new-database.sh


# Optional — Import Doctis schema if you have one in admin/sql
if [ -f /var/www/html/admin/sql/install.sql ]; then
    echo "Importing core Doctis schema..."
    mysql -h $DBHOST -u $DBUSER -p$DBPASS $DBNAME < /var/www/html/admin/sql/install.sql
fi

#echo "Creating admin user if not exists..."
#mysql -h $DBHOST -u $DBUSER -p$DBPASS $DBNAME <<EOF
#INSERT IGNORE INTO user (username, realname, email, password, access_level)
#VALUES ('admin', 'Admin', 'admin@example.com', MD5('password'), 90);
#EOF

echo "Bootstrap complete."

