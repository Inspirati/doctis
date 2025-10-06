#!/bin/bash
# -ex

PROJECT="doctis"

#DOCTIS_URL="https://github.com/Inspirati/doctis.git"
#SCRIPT_URL="https://github.com/Inspirati/doctis/admin/scripts"

GITHUB_URL="https://github.com/Inspirati"
REPOSITORY="${PROJECT}/doctis.git"
SCRIPT_LOC="${PROJECT}/admin/scripts"
DOCTIS_URL="${GITHUB_URL}/${REPOSITORY}"
SCRIPT_URL="${GITHUB_URL}/${SCRIPT_LOC}"
SCRIPT_URL="10.0.0.10"

INSTALL_SYSTEM_SCRIPT="install-system.sh"
INSTALL_DOCTIS_SCRIPT="install-${PROJECT}.sh"

#SYSTEM_SCRIPT_URL="${SCRIPT_URL}/${INSTALL_SYSTEM_SCRIPT}"
#DOCTIS_SCRIPT_URL="${SCRIPT_URL}/${INSTALL_DOCTIS_SCRIPT}"

#echo ${INSTALL_SYSTEM_SCRIPT}
#echo ${INSTALL_DOCTIS_SCRIPT}
 
pushd . > /dev/null
cd "$(dirname $0)"
SCRIPT_DIR="$(pwd)"
popd > /dev/null
CRYPTO_SALT="$(cat /dev/urandom | head -c 64 | base64 -w 1000)"

domain=$(ip -4 addr show dev "$(ip route show default | awk '{print $5}' | head -n1)" | awk '/inet / {print $2}' | cut -d/ -f1)

# wget -O- https://gist.githubusercontent.com/<user>/<gist-id>/raw | bash
# wget -O- https://raw.githubusercontent.com/<user>/<repo>/main/bootstrap.sh | bash
#wget https://raw.githubusercontent.com/Inspirati/doctis/admin/tools/install-doctis.sh

#wget 10.0.0.10/install-doctis.sh
#chmod +x install-doctis.sh
#./install-doctis.sh ${domain} ${mysql_pass} ${email_addr} ${email_hash} | tee logfile.txt

# to retreive and run this file:
#wget 10.0.0.10/install.sh | bash && rm install.sh

END="\033[0m"
OFF="\033[0m"
RED="\033[31m"
GREEN="\033[32m"
YELLOW="\033[33m"
CYAN="\033[36m"
FAIL=$RED
INFO=$CYAN
WARN=$YELLOW

function echo_fail() { echo -e "${FAIL}[FAIL] $@${OFF}"; }
function echo_info() { echo -e "${INFO}[INFO] $@${OFF}"; }
function echo_pass() { echo -e "${PASS}[PASS] $@${OFF}"; }

#    chmod 776 ${script_name}

fetch_and_run() {
    local script_name="$1"
    wget --quiet -O ${script_name} ${SCRIPT_URL}/${script_name}
    chmod +x ${script_name}
    # Include the newly fetched script
    . ${script_name}
    # Remove the first argument
    shift
    # Pass all remaining arguments to script entry function that is identical to the script name
    ${script_name%.*} "${@:2}"
    # Since it would get overwritten on the next run of this script, don't leave it around for editing
    rm ${script_name}
}
 
function print_usage() {
    echo "Usage:"
    echo ""
    echo " To install:"
    echo "   $SCRIPT_DIR install <mysql root password> <database name> <database user> <database password>"
    echo ""
    echo " To uninstall:"
    echo "   $SCRIPT_DIR uninstall --really"
    echo ""
    exit
}

# [[ ($# -eq 1 || ($# -eq 2 && $2 == <glob pattern>)) && $1 =~ <regex pattern> ]]
# For pure arithmetic expressions, using (( )) to some may still be better, but they are still possible in [[ ]] with its arithmetic operators like -eq, -ne, -lt, -le, -gt, or -ge by placing the expression as a single string argument:

if [ "$#" -eq 0 ]; then
    echo_fail "No parameters given: $#"
    print_usage
    exit 64 # command line usage error
fi

if [ "$1" = "remove" ]; then
    # remove all the application directories, local and webroot
    echo_info "Removing ${PROJECT}"

elif [ "$1" = "reload" ]; then
    # purge the entire database and create a fresh one
    echo_info "Reloading ${PROJECT}"

elif [ "$1" = "refresh" ]; then
    # update the application directories
    echo_info "Refreshing ${PROJECT}"
    # Pass remaining arguments starting from the second
#    fetch_and_run ${REFRESH_SYSTEM_SCRIPT} "${@:2}"

elif [ "$1" = "install" ]; then
    if [ "$#" -lt 2 ]; then
        echo_fail "Insufficient parameters: '$#'"
        print_usage
        exit 64 # command line usage error
    fi
    if [ "$2" = "system" ]; then
        fetch_and_run ${INSTALL_SYSTEM_SCRIPT} "${@:3}"
    elif [ "$2" = "project" ]; then
        fetch_and_run ${INSTALL_DOCTIS_SCRIPT} "${@:3}"
    elif [ "$2" = "all" ]; then
        # Install everything from the system services through to the application launcher
        # Pass remaining arguments starting from the third
        fetch_and_run ${INSTALL_SYSTEM_SCRIPT} "${@:3}"
        fetch_and_run ${INSTALL_DOCTIS_SCRIPT} "${@:3}"

    else
        echo_info "Invalid install target '$2'"
    fi
else
    echo "You must specify 'install' or 'remove'"
    exit
fi

# ./install-doctis.sh install all "10.0.0.10" "password" "root@localhost" "gmailapppassword"

