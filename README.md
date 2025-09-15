Doctis - Document Issue Tracking System
=======================================

[![Build Status](https://github.com/Inspirati/doctis/actions/workflows/doctis.yml/badge.svg?branch=master)](https://github.com/Inspirati/doctis/actions/workflows/doctis.yml)
[![Gitter](https://img.shields.io/gitter/room/doctis/doctis.svg?logo=gitter)](https://gitter.im/Inspirati/doctis)

About
-----

Doctis aims to add support for tracking documents and the issues raised against them during a review process.

Documents can be any set of electronic files or physical objects that can have configuration data to identify them.

The documents themselves will not be contained within the system, but rather their leading particulars will include a reference number, and/or a URL to their location.

The easiest way to try Doctis is to duplicate the developers test environment, hosted in a VirtualBox running Debian Linux.

A script to automatically clone, install, and configure Doctis is under development and currently in beta tesing.

Installing
----------

1. Install [VirtualBox](https://www.virtualbox.org/) on any system it is supported on.

2. Create a Debian Linux virtual machine using the ISO image at [Debian-13.1.0-amd64-netinst.iso](https://cdimage.debian.org/debian-cd/current/amd64/iso-cd/debian-13.1.0-amd64-netinst.iso)
   
   a. select the VirtualBox default automated install option (results in a GNOME* desktop environment)
   
   b. upon initial login, open a terminal window (click top-left corner and then the black terminal icon)
   
   c. enable sudo: (where <username> is your login username)
         $ su -
         $ usermod -aG sudo <username>
         $ shutdown now
      (a system restart seems to be required to ensure sudo is enabled upon next login)
   
   d. create a clone (backup) of this virtual machine as a reference baseline (recommended)
   
   e. restart the virtual machine

3. Download and install the DocTIS project.
   
   a. make a working directory, or just use the existing '~/Documents' directory
         $ cd Documents
   
   b. copy the provided install script (below) into a file of your choosing, ie. 'install.sh'
       - or fetch it online with:
         $ wget -O- https://tinyurl.com/get-doctis > install.sh
   
   c. customise the install.sh script as needed (optional):
         $ pico install.sh
   
   d. enable the executable property on the script and run it:
         $ chmod +x install.sh
         $ ./install.sh

4. Follow the getting-started tips which should eventually be displayed.
    
NOTE: in order to create new users in mantisbt/doctis, the ability to send smtp emails is required and perhap the most-difficult way to achieve this is to create an App Password for a gmail account. However the system can still be used in single administrator mode without being able to send email. The default account is 'administrator' with password 'root'.

*for alternative desktop environments, perform a manual Debian setup process. (this has not been tested)

Doctis Install Script
---------------------

WARNING: this script should only be used inside your Debian Linux virtual machine.

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
domain=$(ip -4 addr show dev "$(ip route show default | awk '{print $5}' | head -n1)" | awk '/inet / {print $2}' | cut -d/ -f1)
#domain="my.domain.com"

wget https://gist.githubusercontent.com/Inspirati/8f17b0799fdaf0ab7b201a5cfd1775a1/raw/install-doctis.sh
chmod +x install-doctis.sh
./install-doctis.sh ${domain} ${mysql_pass} ${email_addr} ${email_hash} | tee logfile.txt
```

Documentation
-------------

For complete documentation, please read the administration guide included with this release in the `doc/<lang>` directory. The guide is available in text, PDF, and HTML formats.

Limitations
-----------

There is currently no built-in user interface support for adding documents to the database. Document data needs to be added to the database directly using other tools, such as phpMyAdmin or the CLI.

Feedback
--------

Should the installation fail please raise an issue and attach a copy of the generated logfile.txt

Origins and Credit
------------------

[MantisBT](https://github.com/mantisbt/mantisbt)

