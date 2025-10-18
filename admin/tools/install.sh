#!/bin/bash

# Customise the email and database credentials for the project to use
email_addr="my.email@gmail.com"
email_hash="GmailAppPassword"
mysql_pass="password"

# Do we want a local machine only server (localhost)
# or one available to a Local Area Network (LAN) via ip address
# or Fully Qualified Domain Name (FQDN), for public internet server - advanced user
#domain="locahost"
#domain=$(ip -4 addr show dev "$(ip route show default | awk '{print $5}' | head -n1)" | awk '/inet / {print $2}' | cut -d/ -f1)
domain=$(ip r get 1 | grep -Eo 'src [^ ]+' | awk '{print $2}')
#domain="my.domain.com"

##wget https://gist.githubusercontent.com/Inspirati/8f17b0799fdaf0ab7b201a5cfd1775a1/raw/install-doctis.sh
#wget --quiet https://raw.githubusercontent.com/Inspirati/doctis/refs/heads/dev/admin/tools/install-doctis.sh
#chmod +x install-doctis.sh
##./install-doctis.sh install project ${domain} ${mysql_pass} ${email_addr} ${email_hash} | tee logfile.txt
#./install-doctis.sh install all ${domain} ${mysql_pass} ${email_addr} ${email_hash} "doctis" | tee logfile.txt

wget --quiet https://raw.githubusercontent.com/Inspirati/doctis/refs/heads/dev/admin/tools/install-option.sh
chmod +x install-option.sh
./install-option.sh install all ${domain} ${mysql_pass} ${email_addr} ${email_hash} "doctis" | tee logfile.txt
