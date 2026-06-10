Doctis - Document Issue Tracking System
=======================================

[![Build Status](https://github.com/Inspirati/doctis/actions/workflows/mantisbt.yml/badge.svg?branch=dev)](https://github.com/Inspirati/doctis/actions/workflows/mantisbt.yml)
[![Gitter](https://img.shields.io/gitter/room/doctis/doctis.svg?logo=gitter)](https://gitter.im/Inspirati/doctis)

Quick Install — LAN (from local development server)
----------------------------------------------------

Run this on a fresh Debian/Ubuntu VM connected to the local network.
Pulls scripts and the repository directly from the development server at `10.0.0.10`.

```sh
cd ~/Documents && wget -qO install-lan.sh http://10.0.0.10/doctis/admin/tools/install-lan.sh && bash install-lan.sh
```

> Edit `install-lan.sh` before running to set your email credentials and MySQL password.
> For a public internet install see the **Installation** section below.

About
-----

The Doctis project aims to add support to MantisBT for tracking documents and the issues raised against them during a formal review process.

Documents can be any set of electronic files or physical objects that can have suitable configuration data to uniquely identify them.

The documents themselves do not need to be contained within the system, but rather their leading particulars will include a reference number, and/or a URL to their location.

The easiest way to try Doctis right now is to duplicate the developers test environment, hosted in a VirtualBox running Debian Linux.

A script (below) will automatically clone, install, and configure Doctis (under development and currently undergoing beta testing).

Design Goals (requirements)
---------------------------

* simple to use, requires little to no training
* track status of documents through the review cycle
* track status of issues identified during document reviews (tailor existing mantis functionality)
* maximise maintainability (minimise the diff with mantisbt codebase)
* bulk import of document data from spreadsheet, csv, tsv
* add group feature (users can be assigned to groups)

Installation
------------

1. Install [VirtualBox](https://www.virtualbox.org/) on any system it is supported on. (or VMWare if preferred)

2. Create a new virtual machine, configured with 4GB Memory (RAM), 10+ GB disk, and bridged network adaptor.

3. Install a Debian[^1] based Linux virtual machine. (the ISO image at [Debian-13.1.0-amd64-netinst.iso](https://cdimage.debian.org/debian-cd/current/amd64/iso-cd/debian-13.1.0-amd64-netinst.iso) is recommended) however Debian 12, and recent Ubuntu and KDE-Neon distributions have also been tried successfully.

    1. select the VirtualBox default 'unattended' install option, leave other options as default (this results in a GNOME[^2] desktop environment on Debian)

    2. upon initial login, open a terminal window (click top-left corner and then find the black terminal icon)

    3. if using Debian, enable sudo (where \<username\> is your login username) and shutdown[^3]

    ```sh
         $ su -

         # usermod -aG sudo <username>

         # shutdown now
    ```

    4. create a clone (backup) of your new virtual machine as a reference baseline (recommended)

    5. start a virtual machine and login to your account

4. Download and install the Doctis project.

    SIMPLE:

    For a default install, enter this single statement into a bash command shell:

    ```sh
    cd Documents && wget -O- https://tinyurl.com/get-doctis | bash
    ```

    or,

    ADVANCED:

    1. make a working directory, or just change to the existing '~/Documents' directory

    ```sh
    cd Documents
    ```

    2. copy the provided install script (below) into a file of your choosing, ie. 'install.sh'
       or fetch it online with:
    ```sh
    wget -O- https://tinyurl.com/get-doctis > install.sh
    ```

    3. customise the configuration options in the install.sh script as needed (optional):

    ```sh
    pico install.sh
    ```

    4. enable the executable property on the script and run it:

    ```sh
    chmod +x install.sh

    ./install.sh
    ```

5. Follow the getting-started tips which should eventually be displayed.

NOTE: in order to create new users in mantisbt/doctis, the ability to send email is required and perhaps the most-difficult way to achieve this is to create an App Password for a gmail account. However the system can still be used with predefined user accounts without being able to send email. These accounts can be modified when logged into Doctis with an administrator account. The default account is 'administrator' with password 'root'.

[^1]: the install script utilises the 'apt' package manager for installing system services and tools
[^2]: for alternative desktop environments, perform a manual Debian setup process. (this has undergone minimal testing)
[^3]: a system restart seems to be required to ensure sudo is enabled upon next login

Doctis Install Script
---------------------

WARNING: this script should only be used inside your Debian based Linux virtual machine.
(note this quoted script may be obsolete and you should obtain the lastest online version as per above)

```sh
#!/bin/bash

# Customise the email and database credentials for the project to use
email_addr="my.email@gmail.com"
email_hash="GmailAppPassword"
mysql_pass="password"

# Do we want a local machine (localhost) only server
# or one available to a Local Area Network (LAN) via ip address (recommended)
# or Fully Qualified Domain Name (FQDN), for public internet server (advanced)
#domain="locahost"
domain=$(ip r get 1 | grep -Eo 'src [^ ]+' | awk '{print $2}')
#domain="my.domain.com"

wget --quiet https://raw.githubusercontent.com/Inspirati/doctis/refs/heads/dev/admin/tools/install-doctis.sh
chmod +x install-doctis.sh
./install-doctis.sh install all ${domain} ${mysql_pass} ${email_addr} ${email_hash} "doctis" | tee logfile.txt```
```

Documentation
-------------

For complete documentation, please read the mantisbt administration guide included with this release in the `doc/<lang>` directory. The guide is available in text, PDF, and HTML formats.

Limitations
-----------

There is currently no built-in user interface support for bulk adding documents to the database. Bulk document data needs to be added to the database directly using other tools, such as phpMyAdmin or the CLI.

Style Guide / Naming Convention
-------------------------------

A primary goal of Doctis is keeping the fork standarised with is origins, MantisBT.

More detailed documentation can be found at https://www.mantisbt.org/docs/

* `config_defaults_inc.php`
  * this file contains the default values for all the site-wide variables.
* `config/config_inc.php`
  * You should use this file to change config variable values. Your
    values from this file will be used instead of the defaults. This file
    will not be overwritten when you upgrade, but config_defaults_inc.php will.
    Look at `config/config_inc.php.sample` for an example.

* `core/*_api.php` - these files contain all the API library functions.

* global variables are prefixed by `g_`
* parameters in functions are prefixed with `p_` -- parameters shouldn't be modified within the function.
* form variables are prefixed with `f_`
* variables that have been cleaned for db insertion are prefixed with `c_`
* temporary variables are prefixed with `t_`.
* count variables have the word `count` in the variable name

The Doctis fork has not renamed most local variables from the original MantisBT code. This is in the interests of keeping the 'diff' of the fork to a minimum in order to simplify future syncronisation with ongoing mantis development. This is a trade-off between readability and maintainability.

More detail can be seen in the coding guidelines at:
https://www.mantisbt.org/guidelines.php

* The files are split into three basic categories, viewable pages,
  include files and pure scripts. Examining the viewable pages (suffix `_page`)
  should make the basic file format fairly easy to see. The file names
  themselves should make their purpose apparent. The approach used is to break the
  work into many small files rather than have a small number of large files.

* For legacy and namespace reasons, there are a few naming anomolies.
  In particular:
	- 'bug' and 'issue' should (for the most part) be considered analogous
	- 'dwg' and 'document' should be considered analogous
		('doc' and 'document' are keywords which tend to be seriously overloaded)

* All files are to be edited with TAB SPACES set to 4. Please be professional and use tabs for indentation, spaces for alignment.

Contributing
------------

If you are interested in contributing to the development and/or testing of the project, raise a GitHub issue expressing your interest.

Feedback
--------

Should the installation fail please raise an issue and attach a copy of the generated logfile.txt

Origins and Credit
------------------

[MantisBT](https://github.com/mantisbt/mantisbt)

