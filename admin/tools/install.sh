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
#domain=$(ip r get 1 | grep -Eo 'src [^ ]+' | awk '{print $2}')

# Try DigitalOcean metadata first (timeout 0.5 sec)
domain=$(curl -s --max-time 0.5 http://169.254.169.254/metadata/v1/interfaces/public/0/ipv4/address)

# If metadata failed or empty, fall back to interface parsing
if [ -z "$domain" ]; then
    iface=$(ip route show default 2>/dev/null | awk '{print $5}' | head -n1)
    domain=$(ip -4 -o addr show dev "$iface" 2>/dev/null \
             | awk '{print $4}' \
             | cut -d/ -f1 \
             | grep -Ev '^(10\.|172\.1[6-9]\.|172\.2[0-9]\.|172\.3[0-1]\.|192\.168\.)' \
             | head -n1)
fi

# If still empty (local network only), allow private IP last
if [ -z "$domain" ]; then
    domain=$(ip -4 -o addr show dev "$iface" 2>/dev/null \
             | awk '{print $4}' \
             | cut -d/ -f1 \
             | head -n1)
fi

echo "$domain"

#domain="my.domain.com"

##wget https://gist.githubusercontent.com/Inspirati/8f17b0799fdaf0ab7b201a5cfd1775a1/raw/install-doctis.sh
#wget --quiet https://raw.githubusercontent.com/Inspirati/doctis/refs/heads/dev/admin/tools/install-doctis.sh
#chmod +x install-doctis.sh
##./install-doctis.sh install project ${domain} ${mysql_pass} ${email_addr} ${email_hash} | tee logfile.txt
#./install-doctis.sh install all ${domain} ${mysql_pass} ${email_addr} ${email_hash} "doctis" | tee logfile.txt

wget --quiet https://raw.githubusercontent.com/Inspirati/doctis/refs/heads/dev/admin/tools/install-option.sh
chmod +x install-option.sh
./install-option.sh install all ${domain} ${mysql_pass} ${email_addr} ${email_hash} "doctis" | tee logfile.txt
