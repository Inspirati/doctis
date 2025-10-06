Doctis - Document Issue Tracking System
=======================================

[![Build Status](https://github.com/Inspirati/doctis/actions/workflows/mantisbt.yml/badge.svg?branch=dev)](https://github.com/Inspirati/doctis/actions/workflows/mantisbt.yml)
[![Gitter](https://img.shields.io/gitter/room/doctis/doctis.svg?logo=gitter)](https://gitter.im/Inspirati/doctis)

About
-----

The Doctis project aims to add support to MantisBT for tracking documents and the issues raised against them during a formal review process.

Documents can be any set of electronic files or physical objects that can have suitable configuration data to uniquely identify them.

The documents themselves do not need to be contained within the system, but rather their leading particulars will include a reference number, and/or a URL to their location.

The easiest way to try Doctis is to duplicate the developers test environment, hosted in a VirtualBox running Debian Linux.

A script to automatically clone, install, and configure Doctis is under development and is currently undergoing beta testing.

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

1. Install [VirtualBox](https://www.virtualbox.org/) on any system it is supported on.

2. Create a Debian based Linux virtual machine using the ISO image at [Debian-13.1.0-amd64-netinst.iso](https://cdimage.debian.org/debian-cd/current/amd64/iso-cd/debian-13.1.0-amd64-netinst.iso). Debian 12 or recent Ubuntu distribution should also work. (limited testing has been performed)

    1. select the VirtualBox default automated install option (results in a GNOME[^1] desktop environment)

    2. upon initial login, open a terminal window (click top-left corner and then find the black terminal icon)

    3. enable sudo (where \<username\> is your login username) and shutdown[^2]

    ```sh
         $ su -

         # usermod -aG sudo <username>

         # shutdown now
    ```

    4. create a clone (backup) of this virtual machine as a reference baseline (recommended)

    5. start the virtual machine and login to your user account

3. Download and install the Doctis project.

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

    Or, for a default install, simply copy and paste this single statement:

    ```sh
    cd Documents && wget -O- https://tinyurl.com/get-doctis | bash
    ```

4. Follow the getting-started tips which should eventually be displayed.

NOTE: in order to create new users in mantisbt/doctis, the ability to send smtp emails is required and perhaps the most-difficult way to achieve this is to create an App Password for a gmail account. However the system can still be used in single administrator mode without being able to send email. The default account is 'administrator' with password 'root'.

[^1]: for alternative desktop environments, perform a manual Debian setup process. (this has undergone minimal testing)
[^2]: a system restart seems to be required to ensure sudo is enabled upon next login

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

# Do we want a local machine only server (localhost)
# or one available to a Local Area Network (LAN) via ip address
# or Fully Qualified Domain Name (FQDN), for public internet server - advanced user
#domain="locahost"
domain=$(ip r get 1 | grep -Eo 'src [^ ]+' | awk '{print $2}')
#domain="my.domain.com"

wget https://gist.githubusercontent.com/Inspirati/8f17b0799fdaf0ab7b201a5cfd1775a1/raw/install-doctis.sh
chmod +x install-doctis.sh
./install-doctis.sh ${domain} ${mysql_pass} ${email_addr} ${email_hash} | tee logfile.txt
```

Documentation
-------------

For complete documentation, please read the mantisbt administration guide included with this release in the `doc/<lang>` directory. The guide is available in text, PDF, and HTML formats.

Limitations
-----------

There is currently no built-in user interface support for bulk adding documents to the database. Bulk document data needs to be added to the database directly using other tools, such as phpMyAdmin or the CLI.

Style Guide / Naming Convention
-------------------------------

Guidance on keeping this fork standarised with is origins, MantisBT.

More detailed documentation can be found at https://www.mantisbt.org/docs/

* `config_defaults_inc.php`
  * this file contains the default values for all the site-wide variables.
* `config/config_inc.php`
  * You should use this file to change config variable values. Your
    values from this file will be used instead of the defaults. This file
    will not be overwritten when you upgrade, but config_defaults_inc.php will.
    Look at `config/config_inc.php.sample` for an example.

* `core/*_api.php` - these files contains all the API library functions.

* global variables are prefixed by `g_`
* parameters in functions are prefixed with `p_` -- parameters shouldn't be modified within the function.
* form variables are prefixed with `f_`
* variables that have been cleaned for db insertiong are prefixed with `c_`
* temporary variables are prefixed with `t_`.
* count variables have the word `count` in the variable name

More detail can be seen in the coding guidelines at:
https://www.mantisbt.org/guidelines.php

* The files are split into three basic categories, viewable pages,
  include files and pure scripts. Examining the viewable pages (suffix `_page`)
  should make the basic file format fairly easy to see. The file names
  themselves should make their purpose apparent. The approach used is to break the
  work into many small files rather than have a small number of really
  large files.

* For legacy and namespace reasons, there are a few naming anomolies.
  In particular:
	- 'bug' and 'issue' should (for the most part) be considered analogous
	- 'dwg' and 'document' should be considered analogous
		('doc' and 'document' are keywords which tend to be seriously overloaded)

* All files are to be edited with TAB SPACES set to 4.

Contributing
------------

If you are interested in contributing to the development and/or testing of the project, raise a GitHub issue expressing your interest.

Feedback
--------

Should the installation fail please raise an issue and attach a copy of the generated logfile.txt

Origins and Credit
------------------

[MantisBT](https://github.com/mantisbt/mantisbt)

