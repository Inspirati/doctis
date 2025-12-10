#!/usr/bin/env bash

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
                elif curl -fs http://169.254.169.254/metadata/v1/id >/dev/null 2>&1; then
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
    if curl -fs http://169.254.169.254/metadata/v1/id >/dev/null 2>&1; then
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

# ------------------------------
# Run dispatcher
# ------------------------------

chkinst_virt

