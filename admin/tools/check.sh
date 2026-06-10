#!/bin/bash
# -ex

project="doctis-foobar"
password="password"
domain=$(ip -4 route get 1.1.1.1 | sed -n 's/.* src \([0-9.]*\).*/\1/p')

targetproject="${1:-$project}"
mysqlpassword="${2:-$password}"
domain_idname="${3:-$domain}"

database="mariadb"
mysqladminname="admin"
mysqladminpass=${mysqlpassword}
dbuserpostfix=""
dbdatapostfix=""

OFF="\033[0m"
INFO="\033[36m"

show_parameters() {
    echo -e "${INFO}Configuration parameters:${OFF}"
    echo -e "${INFO}  domain id name:${OFF}" "$domain_idname"
    echo -e "${INFO}  mysql password:${OFF}" "$mysqlpassword"
}

run_mantis_install() {
    local target="$1"
    local logfile="mantis_install_$(date +%Y%m%d_%H%M%S).html"
    
    if [ -d ../../../${target} ]; then
        local install_url="http://${domain_idname}/${target}/admin/install.php"
    else
        local install_url="http://${domain_idname}/admin/install.php"
    fi
    
    echo ${install_url}
}

main() {
    run_mantis_install "doctis"
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    echo -e "${DIAG}This script is being invoked directly.${OFF}"
    show_parameters
    sleep 0.01  # tiny delay to allow earlier stdout echos to flush
    echo -e "${WARN}This will destroy all data in the ${targetproject} database${OFF}" >&2
    read -rp "Type 'yes' to proceed: " answer
    if [ "$answer" = "yes" ]; then
        main "$@"
    fi
    echo -e "${DIAG}Done: <ctrl-c> to close${OFF}"
else
    echo -e "${DIAG}This script is being sourced from ${0}.${OFF}"
    main "$@"
fi

