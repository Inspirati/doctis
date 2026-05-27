# Installation

The steps below will automatically clone, install, and configure an initial instance of Doctis.

---

## System Requirements

Recommended minimum virtual machine configuration:

- 4 GB RAM
- 10+ GB storage
- bridged network adapter
- Debian-based Linux distribution

Supported and tested environments include:

- Debian 13
- Debian 12
- Ubuntu (recent releases)
- KDE Neon

> **Note:**  
> The automated install script currently relies on the `apt` package manager.

---

## Virtual Machine Setup

### 1. Install a Hypervisor

Install one of the following:

- [VirtualBox](https://www.virtualbox.org/)
- VMware

---

### 2. Create a Virtual Machine

Create a new virtual machine with:

- 4 GB RAM
- 10+ GB virtual disk
- bridged networking enabled

---

### 3. Install Debian Linux

The recommended installer image is:

- [Debian 13.1.0 amd64 netinst ISO](https://cdimage.debian.org/debian-cd/current/amd64/iso-cd/debian-13.1.0-amd64-netinst.iso)

For VirtualBox users:

1. Select the default **Unattended Installation** option.
2. Leave remaining options at their defaults.
3. This will typically install the GNOME desktop environment.

---

### 4. Enable `sudo` Access (Debian)

After first login:

1. Open a terminal window.
2. Run the following commands:

```sh
su -

usermod -aG sudo <username>

shutdown now
```

Replace `<username>` with your login username.

> **Note:**  
> A restart is typically required before `sudo` becomes active.

---

### 5. Create a Baseline Snapshot (Recommended)

Before installing Doctis, create a clone or snapshot of the virtual machine.

This provides a clean recovery point if experimentation or configuration changes need to be rolled back later.

---

# Installing Doctis

## Simple Installation

For a default installation, open a terminal and run:

```sh
cd ~/Documents && wget -O- https://tinyurl.com/get-doctis | bash
```

The script will:

- download the latest installer
- install required dependencies
- configure the web server and database
- deploy an operational Doctis instance

---

## Advanced Installation

### 1. Create a Working Directory

```sh
cd ~/Documents
```

---

### 2. Download the Installer Script

```sh
wget -O install.sh https://tinyurl.com/get-doctis
```

---

### 3. Review or Customise Configuration (Optional)

```sh
nano install.sh
```

Configuration options include:

- email account settings
- database password
- domain name / hostname
- local vs network-accessible deployment

---

### 4. Make the Script Executable

```sh
chmod +x install.sh
```

---

### 5. Run the Installer

```sh
./install.sh
```

---

## Post Installation

After installation completes:

1. Open a web browser.
2. Navigate to the displayed Doctis server URL.
3. Follow the getting-started guidance presented by the system.

---

## Default Credentials

Default administrator credentials are:

| Username | Password |
|---|---|
| `administrator` | `root` |

> **Important:**  
> Change the administrator password immediately after installation.

---

## Email Configuration Notes

User account creation and notifications require outbound email support.

One simple approach is to configure a Gmail account using an App Password.

However:

- email support is optional
- the system can still operate without SMTP configured
- administrator-created user accounts will still function normally

---

# Example Install Script

> **WARNING:**  
> This script is intended only for Debian-based Linux environments.

> **NOTE:**  
> The online installer should always be preferred, as the example below may become outdated.

```sh
#!/bin/bash

# Customise the email and database credentials for the project
email_addr="my.email@gmail.com"
email_hash="GmailAppPassword"
mysql_pass="password"

# Deployment target:
# localhost
# LAN IP address
# Fully Qualified Domain Name (FQDN)

#domain="localhost"
domain=$(ip r get 1 | grep -Eo 'src [^ ]+' | awk '{print $2}')
#domain="my.domain.com"

wget --quiet https://raw.githubusercontent.com/Inspirati/doctis/refs/heads/dev/admin/tools/install-doctis.sh

chmod +x install-doctis.sh

./install-doctis.sh install all \
    ${domain} \
    ${mysql_pass} \
    ${email_addr} \
    ${email_hash} \
    "doctis" | tee logfile.txt
```

---

# Documentation

Doctis builds upon the proven foundations of MantisBT.

Additional administration and configuration documentation can be found in:

- the `doc/<lang>` directory included with the release
- text, PDF, and HTML documentation formats
- the official MantisBT documentation:
  - <https://www.mantisbt.org/docs/>

---

# Limitations

Current known limitations include:

- no built-in graphical interface for bulk document import
- bulk document insertion currently requires:
  - phpMyAdmin
  - SQL import tools
  - command line database access
  - external scripting

---

# Development Notes and Style Guide

A primary goal of the Doctis project is maintaining compatibility and synchronisation with upstream MantisBT wherever practical.

This minimises long-term maintenance overhead and simplifies integration of future upstream improvements.

---

## Configuration Files

### `config_defaults_inc.php`

Contains default values for all site-wide configuration variables.

---

### `config/config_inc.php`

Use this file for site-specific configuration overrides.

Values defined here will override defaults without being overwritten during upgrades.

See:

```text
config/config_inc.php.sample
```

for examples.

---

## API Structure

### `core/*_api.php`

Contains core API library functions.

---

## Naming Conventions

| Prefix | Meaning |
|---|---|
| `g_` | global variables |
| `p_` | function parameters |
| `f_` | form variables |
| `c_` | database-cleaned variables |
| `t_` | temporary variables |

Additional conventions:

- count variables should contain the word `count`
- tabs should be used for indentation
- tab width should be set to 4 spaces

---

## Legacy Terminology

For compatibility and namespace reasons, some historical naming conventions remain:

| Legacy Term | Equivalent Meaning |
|---|---|
| `bug` | issue |
| `dwg` | document |

> `doc` and `document` are heavily overloaded terms internally and externally.

---

## Source Layout

The codebase is broadly divided into:

- viewable pages
- include files
- standalone scripts

Files with the suffix `_page` generally represent user-facing pages.

The architecture intentionally favours:

- many small focused files
- separation of concerns
- maintainability

---

## Upstream Development References

Further MantisBT development guidelines can be found at:

- <https://www.mantisbt.org/guidelines.php>

---

# Contributing

Contributions, testing assistance, feedback, and feature suggestions are welcome.

Please raise a GitHub issue to:

- report bugs
- request features
- discuss development
- express interest in contributing

---

# Feedback

If installation fails:

1. collect the generated `logfile.txt`
2. raise a GitHub issue
3. attach the logfile for diagnosis

---

# Origins and Credit

Doctis is built upon the excellent work of the MantisBT project:

- [MantisBT](https://github.com/mantisbt/mantisbt)
