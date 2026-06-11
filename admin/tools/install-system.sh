#!/bin/bash

mysqladminname="admin"
sudoers_file="/etc/sudoers.d/34_installer"
database="mariadb"
db_cmd="mysql"
#database="mysql"

OFF="\033[0m"
RED="\033[31m"
GREEN="\033[32m"
YELLOW="\033[33m"
CYAN="\033[36m"
FAIL=$RED
INFO=$CYAN
DIAG=$GREEN
WARN=$YELLOW

#set -euo pipefail
IFS=$'\n\t'
trap 'echo -e "\033[31m[FAIL]\033[0m on line $LINENO" >&2' ERR

# === Utility functions ===

silence_sudo() {
    # try and prevent sudo from repeatedly prompting for password
    local user="$(whoami)"
    echo -e "${INFO}Configuring sudo for user:${OFF} $user"
    sudo tee "$sudoers_file" >/dev/null <<EOF
# this file was created by the an installer and should be deleted
$user ALL=(ALL:ALL) NOPASSWD:/usr/bin/apt-get, \
/lib/systemd/systemd-sysv-install, \
/usr/bin/mariadb, /bin/mariadb, \
/usr/bin/mysql, /bin/mysql, \
/usr/bin/systemctl, \
/usr/sbin/usermod, \
/usr/bin/chown, \
/usr/bin/chmod, \
/usr/bin/git, \
/bin/mkdir, \
/bin/cp, \
/bin/mv, \
/bin/rm
EOF
    # sudo won't read it without the correct permission set
    sudo chmod 440 "$sudoers_file"
    # Arrange for cleanup on exit (normal or error)
    trap cleanup_sudo EXIT
}

cleanup_sudo() {
    if [ -n "$sudoers_file" ] && [ -f "$sudoers_file" ]; then
        # echo "Cleaning up sudoers file: $sudoers_file"
        sudo rm -f "$sudoers_file"
    fi
}

set_webroot() {
    if command -v apache2ctl >/dev/null 2>&1; then
        webroot="$(apache2ctl -t -D DUMP_RUN_CFG 2>/dev/null | awk '/DocumentRoot/ {print $3; exit}')"
        echo -e "${INFO}webroot according to apache2ctl:${OFF}" "$webroot"
    elif command -v httpd >/dev/null 2>&1; then
        webroot="$(httpd -t -D DUMP_RUN_CFG 2>/dev/null | awk '/DocumentRoot/ {print $3; exit}')"
        echo -e "${INFO}webroot according to httpd:${OFF}" "$webroot"
    else
        webroot="/var/www/html"   # safe default
        echo -e "${WARN}webroot default:${OFF}" "$webroot"
    fi
    webroot=${webroot//\"/}
}

set_headless() {
    # Check if a display server is available (X11 or Wayland)
    if [ -z "$DISPLAY" ] && [ -z "$WAYLAND_DISPLAY" ]; then
        echo -e "${INFO}Headless environment detected (no GUI display).${OFF}"
        HEADLESS=true
    else
        echo -e "${INFO}GUI environment detected.${OFF}"
        HEADLESS=false
    fi
    # Optionally, check for X11 libraries to confirm
    if ! command -v xrandr >/dev/null 2>&1 && ! command -v gnome-shell >/dev/null 2>&1; then
        echo -e "${INFO}No GUI libraries found — likely a headless server.${OFF}"
        HEADLESS=true
    fi
}

################################################################################
# Part one: Perform all the system install and configure steps that require sudo
#   install LAMP apps required to host the project - Linux(Apache,Mariadb,PHP)
#
install_lamp() {
    echo -e "${INFO}Installing Apache, MySQL, PHP...${OFF}"
    sudo apt-get update -y
    sudo apt-get install -y apache2 ${database}-server php libapache2-mod-php php-mysql
    sudo apt-get install -y php-mbstring php-curl
    set_webroot
    # So that we can later copy our websites to the webroot without sudo
    sudo chown $(whoami):www-data ${webroot}
    # Optional, add current user to the apache server default permissions group
    sudo usermod -a -G www-data $(whoami)
    echo -e "${INFO}LAMP installed.${OFF}" >&2
}

install_extra() {
    echo -e "${INFO}Installing extras...${OFF}"
    phpver="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)"
    # Get and install the libraries and tools needed to run php's composer for mantisbt:
    sudo apt-get install -y composer curl
    sudo apt-get install -y php${phpver}-gd php-xml php-zip
    # Optional, to test apache server and php setup by browsing http://localhost/phpinfo.php
    # Should always to be removed for a production server
    sudo tee "${webroot}/phpinfo.php" >/dev/null <<EOF
<?php
phpinfo();
xdebuginfo();
?>
EOF
    # Get and install the libraries and tools needed for mantis extended features:
    sudo apt-get install -y graphviz
    echo -e "${INFO}Extras installed.${OFF}" >&2
}

install_tools_cli() {
    echo -e "${INFO}Installing CLI developer tools...${OFF}"
    sudo apt-get install -y git
    sudo apt-get install -y php-xdebug
    sudo apt-get install -y silversearcher-ag
    # Check if alias already exists in ~/.bashrc and insert if required
    if ! grep -qxF "alias trace='sudo tail -f /var/log/apache2/error.log | grep -v Xdebug'" "${HOME}/.bashrc"; then
        {
            echo ""
            echo "alias trace='sudo tail -f /var/log/apache2/error.log | grep -v Xdebug'"
        } >> "${HOME}/.bashrc"
    fi
}

install_vscode() {
    # Optional: install VS Code if not present
    if ! command -v code >/dev/null 2>&1; then
        echo "Installing VS Code..."
        sudo apt-get install -ymf apt-transport-https wget gpg
        wget -qO- https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor > packages.microsoft.gpg
        sudo install -D -o root -g root -m 644 packages.microsoft.gpg /usr/share/keyrings/packages.microsoft.gpg
        sudo sh -c 'echo "deb [arch=amd64,arm64,armhf signed-by=/usr/share/keyrings/packages.microsoft.gpg] https://packages.microsoft.com/repos/code stable main" > /etc/apt/sources.list.d/vscode.list'
        sudo apt-get update -y
        sudo apt-get install -y code
#        code --install-extension vscodevim.vim
        code --install-extension xdebug.php-debug
        code --install-extension muhammedrashid.stain
        code --install-extension oleg-shilo.favorites
    fi
}

install_tools_gui() {
    echo -e "${INFO}Installing GUI developer tools...${OFF}"
    sudo apt-get install -y meld
    wget https://github.com/VSCodium/vscodium/releases/download/1.105.06922/codium_1.105.06922_amd64.deb
    sudo dpkg -i codium_1.105.06922_amd64.deb
    codium --install-extension xdebug.php-debug
    codium --install-extension muhammedrashid.stain
    codium --install-extension oleg-shilo.favorites
    
    # install_vscode
}

install_tools() {
    echo -e "${INFO}Installing developer tools...${OFF}"
    install_tools_cli
    set_headless
    if [ $HEADLESS = false ]; then
        install_tools_gui
    fi
    echo -e "${INFO}Developer tools installed.${OFF}" >&2
}

config_xdebug() {
    local phpver="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
    echo -e "${INFO}Configuring Xdebug for PHP $phpver...${OFF}"
    sudo tee "/etc/php/$phpver/apache2/conf.d/99-xdebug.ini" >/dev/null <<EOF
zend_extension=xdebug.so
xdebug.mode=develop,debug
xdebug.start_with_request=yes
xdebug.client_host=127.0.0.1
xdebug.client_port=9003
EOF
    sudo phpenmod xdebug
    echo -e "${INFO}Xdebug configured.${OFF}"
}


# CREATE USER IF NOT EXISTS 'admin'@'localhost' IDENTIFIED BY 'password';
# GRANT ALL PRIVILEGES ON *.* TO 'admin'@'localhost' WITH GRANT OPTION;

init_database() {
    echo -e "${INFO}Initialising ${database} database...${OFF}"
    # Ensure MySQL/MariaDB is running and enabled
    if ! systemctl is-enabled --quiet "${database}"; then
        echo "Enabling ${database} to start on boot..."
        sudo systemctl enable "${database}"
    fi
    if ! systemctl is-active --quiet "${database}"; then
        echo "Starting ${database} service..."
        sudo systemctl start "${database}"
    fi
#    sudo ${database} <<EOF
#    sudo ${db_cmd} <<EOF
    sudo mysql -u root <<EOF
CREATE USER IF NOT EXISTS '${mysqladminname}'@'localhost' IDENTIFIED BY '${mysqladminpass}';
GRANT ALL PRIVILEGES ON *.* TO '${mysqladminname}'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
EOF
# CREATE USER IF NOT EXISTS 'mantisbt'@'localhost' IDENTIFIED BY 'password';
# GRANT ALL PRIVILEGES ON *.* TO 'mantisbt'@'localhost' WITH GRANT OPTION;
    # So the database cli mysql client doesn't keep prompting for a password
    # Optional convenience: drop a client config file (dev only)
    cat > ~/.my.cnf << EOF
[client]
user=${mysqladminname}
password=${mysqladminpass}
EOF
    chmod 600 ~/.my.cnf
    echo -e "${INFO}Database initialised.${OFF}" >&2
}

# Deprecated - no longer used
check_vbox_addin() {
    local running=0
    local installed=0
    # Detect installation (binary present)
    if command -v VBoxService >/dev/null 2>&1 || [ -x /usr/sbin/VBoxService ]; then
        installed=1
    fi
    # Detect running state (kernel module or service active)
    if lsmod | grep -q vboxguest \
       || systemctl is-active --quiet vboxservice 2>/dev/null; then
        running=1
    fi
    if [ "$installed" -eq 1 ]; then
        echo "✔ VirtualBox Guest Additions are installed."
        if [ "$running" -eq 1 ]; then
            echo "  → Service/kernel integration is active."
        else
            echo "  ⚠ Guest Additions are installed but not running (reboot may be required)."
        fi
        return 0
    else
        echo "✘ VirtualBox Guest Additions are not installed."
        return 1
    fi
}

install_vbox() {
    local interactive="${1:-true}"
    echo "Checking for VirtualBox Guest Additions..." >&2
    # Detect installed binaries and modules
    local installed=0
    local running=0
    local headers_missing=0
    # Installed: VBoxService binary or module present
    if command -v VBoxService >/dev/null 2>&1 || [ -x /usr/sbin/VBoxService ]; then
        installed=1
    fi
    # Installed: check if vboxguest module exists in current kernel
    if modinfo vboxguest >/dev/null 2>&1; then
        installed=1
    else
        headers_missing=1
    fi
    # Running: check if vboxguest module is loaded or service active
    if lsmod | grep -q '^vboxguest'; then
        running=1
    elif systemctl is-active --quiet vboxservice 2>/dev/null; then
        running=1
    fi
    if [ "$installed" -eq 1 ]; then
        echo "✔ VirtualBox Guest Additions are installed." >&2
        if [ "$running" -eq 1 ]; then
            echo "  → Service/kernel integration is active." >&2
        else
            echo "  ⚠ Guest Additions are installed but not running (reboot may be required)." >&2
        fi
        if [ "$headers_missing" -eq 1 ]; then
            echo "ℹ Kernel headers missing: modules could not be rebuilt, but tools appear functional." >&2
        fi
        return 0
    else
        echo "✘ VirtualBox Guest Additions are not installed." >&2
        echo "Clipboard sharing and display auto-resize are unavailable." >&2
    fi
    # Installation loop
    while true; do
        if [ "$interactive" = "true" ]; then
            read -rp "Would you like to install Guest Additions now? (Insert Guest Additions CD Image first) [y/N]: " reply
            case "$reply" in
                [Yy]*) ;;
                *) echo "Skipped Guest Additions installation."; return 1 ;;
            esac
        fi
        sudo mkdir -p /media/cdrom
        if ! mountpoint -q /media/cdrom; then
            if ! sudo mount /dev/cdrom /media/cdrom; then
                echo "❌ Could not mount /dev/cdrom." >&2
                echo "👉 Please attach the ISO via: Devices → Insert Guest Additions CD Image" >&2
                [ "$interactive" = "true" ] && continue || return 2
            fi
        fi
        if [ -f /media/cdrom/VBoxLinuxAdditions.run ]; then
            echo "Installing VirtualBox Guest Additions..." >&2
#            sudo sh /media/cdrom/VBoxLinuxAdditions.run --nox11 || echo "⚠ Installer finished with warnings/errors." >&2
            sudo sh /media/cdrom/VBoxLinuxAdditions.run || echo "⚠ Installer finished with warnings/errors." >&2
            echo "✔ Installation complete (modules may require reboot to activate)." >&2
            echo "ℹ Please reboot or log out/in to enable clipboard sharing and auto-resize." >&2
            sudo umount /media/cdrom || true
            return 0
        else
            echo "❌ Guest Additions installer not found on /media/cdrom." >&2
            echo "👉 Make sure you selected: Devices → Insert Guest Additions CD Image" >&2
            [ "$interactive" = "true" ] && continue || return 3
        fi
    done
}
# Example usage:
# install_vbox true   # interactive mode (default)
# install_vbox false  # non-interactive mode

# ------------------------------
# Installation handlers
# ------------------------------

install_vbox() {
    echo "[INFO] VirtualBox environment detected."
    echo "[INFO] Running VirtualBox-specific installation..."
    # TODO: add your VirtualBox installation steps here
}

install_qemu() {
    echo "[INFO] QEMU/KVM environment detected."
    echo "[INFO] Running QEMU/KVM-specific installation..."
    # TODO: add your QEMU/KVM installation steps here
}

install_droplet() {
    echo "[INFO] DigitalOcean droplet detected."
    echo "[INFO] Running DigitalOcean-specific installation..."
    # TODO: add your DigitalOcean installation steps here
}

install_baremetal() {
    echo "[INFO] No virtualization detected: assuming bare metal."
    echo "[INFO] Running bare-metal installation..."
    # TODO: add your bare metal installation steps here
}

# ------------------------------
# Virtualization detection
# returns one of:
#   virtualbox | qemu-kvm | digitalocean | baremetal
# ------------------------------

detect_virtualization() {

    # Prefer systemd-detect-virt if available
    if command -v systemd-detect-virt >/dev/null 2>&1; then
        virt=$(systemd-detect-virt)

        case "$virt" in
            oracle)
                echo "virtualbox"
                return ;;
            kvm|qemu)
                # Could be DigitalOcean (DO uses KVM)
                if grep -qi "DigitalOcean" /sys/class/dmi/id/sys_vendor 2>/dev/null; then
                    echo "digitalocean"
                elif curl -fs --connect-timeout 3 http://169.254.169.254/metadata/v1/id >/dev/null 2>&1; then
                    echo "digitalocean"
                else
                    echo "qemu-kvm"
                fi
                return ;;
        esac
    fi

    # --- DMI fallback (VirtualBox / QEMU / DO) ---

    # VirtualBox fingerprints
    if grep -qi "VirtualBox" /sys/class/dmi/id/product_name 2>/dev/null \
    || grep -qi "innotek" /sys/class/dmi/id/sys_vendor 2>/dev/null; then
        echo "virtualbox"
        return
    fi

    # DigitalOcean DMI fingerprint
    if grep -qi "DigitalOcean" /sys/class/dmi/id/sys_vendor 2>/dev/null; then
        echo "digitalocean"
        return
    fi

    # QEMU/KVM DMI pattern
    if grep -qiE "QEMU|KVM" /sys/class/dmi/id/sys_vendor 2>/dev/null; then
        echo "qemu-kvm"
        return
    fi

    # --- DigitalOcean metadata fallback ---
    if curl -fs --connect-timeout 3 http://169.254.169.254/metadata/v1/id >/dev/null 2>&1; then
        echo "digitalocean"
        return
    fi

    # Default fallback
    echo "baremetal"
}

# ------------------------------
# Dispatcher
# ------------------------------

chkinst_virt() {
    virt=$(detect_virtualization)

    case "$virt" in
        virtualbox)
            install_vbox
            ;;
        qemu-kvm)
            install_qemu
            ;;
        digitalocean)
            install_droplet
            ;;
        baremetal|*)
            install_baremetal
            ;;
    esac
}

install_system() {
    # Run the system installation functions in a specific sequence
    mysqladminpass="${2:-password}"
    echo -e "${GREEN}Started installing services..${OFF}"
    silence_sudo
#    install_vbox
#    chkinst_virt
    install_lamp
    install_tools
    install_extra
    config_xdebug
    init_database
    # Restart apache to ensure all our new modules are loaded
    sudo systemctl restart apache2
    echo -e "${GREEN}Finished installing services.${OFF}"
    return 0
}

################################################################################
# Function entry point of the same name as the script, useful when source'd

install-system() {
    install_system "$@"
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    echo -e "${DIAG}This script is being invoked directly.${OFF}"
    install_system "$@"
else
    echo -e "${DIAG}This script is being sourced from ${0}.${OFF}"
fi

