#!/bin/bash
#
# Doctis — Load sample data into an existing database
#
# Loads the example project/licence data and the named test user accounts
# (role-named accounts + LOTR characters) into an already-installed Doctis
# database.
#
# Run after doctis-drop-and-create-new-database.sh when a populated test
# environment is needed.  Safe to run independently at any time, but the
# INSERT statements are not idempotent — running twice against a populated
# database will fail on duplicate-key violations.
#
# Usage:
#   bash doctis-load-sample-data.sh [project [mysql-password]]
#
# Requires ~/.my.cnf with database admin credentials:
#   [client]
#   user=mysqladminname
#   password=mysqladminpass
#

project="doctis"
password="password"

targetproject="${1:-$project}"
mysqlpassword="${2:-$password}"

db_cmd="mysql"
dbuserpostfix=""
dbdatapostfix=""

OFF="\033[0m"
INFO="\033[36m"

# ── Example project and licence data ─────────────────────────────────────────

load_example_data() {
    local target="$1"
    local mysqldatabase="${target}${dbdatapostfix}"
    echo -e "${INFO}Loading example project/licence data into ${mysqldatabase}...${OFF}" >&2
    ${db_cmd} <<EOF
USE ${mysqldatabase};
$(cat <<'SQL'
INSERT INTO `project` (`id`, `name`, `status`, `enabled`, `view_state`, `access_min`, `file_path`, `description`, `category_id`, `inherit_global`, `classification`)
VALUES (1, 'example', 10, 1, 10, 10, '', '', 1, 1, '');
SQL
)
EOF
    ${db_cmd} <<EOF
USE ${mysqldatabase};
$(cat <<'SQL'
INSERT INTO `license` (`project_id`, `enabled`, `name`, `match_str`, `type`, `status`, `view_state`, `access_min`, `description`) VALUES
(0, 1, 'DDG - 0283-11/2063-08', 'DDG028311/206308', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - 0296-11/1129-09', 'DDG029611112909', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - 3053-11/1861-09', 'DDG305311186109', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - 4356-11/3992-09', 'DDG435611399209', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - 7777-10/2016-07', 'DDG777710201607', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - 9912-10', 'DDG991210', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - AT-P-GSB', 'DDGATPGSB', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - AT-P-GSC', 'DDGATPGSC', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - AT-P-GSU', 'DDGATPGSU', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - AT-P-LCQ', 'DDGATPLCQ', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - AT-P-LFZ', 'DDGATPLFZ', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - AWD-CS-3664/2009', 'DDGAWDCS36642009', 'IP License', 10, 10, 10, ''),
(0, 1, 'DDG - ECCN 8A609-x 8E609', 'DDGECCN8A609X8E609', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - ECCN 8E992', 'DDGECCN8E992', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - NAUS-2023', 'DDGNAUS2023', 'IP License', 10, 10, 10, ''),
(0, 1, 'DDG - RAPL FMS TPTA', 'DDGRAPLFMSTPTA', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - RSAT 16-5184', 'DDGRSAT165184', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - RSAT 19-6672', 'DDGRSAT196672', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - SEA 4000-1180', 'DDGSEA40001180', 'IP License', 10, 10, 10, ''),
(0, 1, 'DDG - 2212361', 'DDG2212361', 'ITAR License', 10, 10, 10, ''),
(0, 1, 'DDG - 9250-10/3211-08', 'DDG925010321108', 'Harpoon License', 10, 10, 10, '');
SQL
)
EOF
    echo -e "${INFO}Example project/licence data loaded.${OFF}" >&2
}

# ── Test user accounts ────────────────────────────────────────────────────────

load_testing_user() {
    local target="$1"
    local mysqldatabase="${target}${dbdatapostfix}"
    echo -e "${INFO}Loading test user accounts into ${mysqldatabase}...${OFF}" >&2
    ${db_cmd} <<EOF
USE ${mysqldatabase};
$(cat <<'SQL'
-- Column order matches the current user table schema including all profile fields.
-- password hash 'd41d8cd98f00b204e9800998ecf8427e' = blank password ''
-- password hash '5f4dcc3b5aa765d61d8327deb882cf99' = 'password'
-- access_level: 10=viewer 25=reporter 40=updater 55=developer 70=manager 90=administrator
-- meeting_invite: 0=never 1=department only 2=all meetings
INSERT INTO `user` (
    `username`, `realname`, `email`, `password`,
    `enabled`, `protected`, `access_level`,
    `login_count`, `lost_password_request_count`, `failed_login_count`,
    `cookie_string`, `last_visit`, `date_created`,
    `position_title`, `company`, `phone`, `department`, `meeting_invite`, `email_secondary`
) VALUES
-- ── Role-named test accounts ──────────────────────────────────────────────────
('user',      '', 'doctis.user@gmail.com',      'd41d8cd98f00b204e9800998ecf8427e', 1, 0, 25, 3, 0, 0, '2f0adeec1f967ae6c23abf54f8e7487d6ae8ca98185bd228469f5ce4478346f9', 1757927188, 1757927188, '', '', '', '', 0, ''),
('viewer',    '', 'doctis.viewer@gmail.com',    'd41d8cd98f00b204e9800998ecf8427e', 1, 0, 10, 3, 0, 0, '96cf4e972760ca2b25da0883b157808e6ae8ca98185bd228469f5ce4478346f9', 1757927188, 1757927188, '', '', '', '', 0, ''),
('reporter',  '', 'doctis.reporter@gmail.com',  'd41d8cd98f00b204e9800998ecf8427e', 1, 0, 25, 3, 0, 0, '7be89c3bacb19567c52d56ba4d7b12726ae8ca98185bd228469f5ce4478346f9', 1757927188, 1757927188, '', '', '', '', 0, ''),
('updater',   '', 'doctis.updater@gmail.com',   'd41d8cd98f00b204e9800998ecf8427e', 1, 0, 40, 3, 0, 0, '63f66ba20df9c98303fc2ed9b7708fc06ae8ca98185bd228469f5ce4478346f9', 1757927188, 1757927188, '', '', '', '', 0, ''),
('developer', '', 'doctis.developer@gmail.com', 'd41d8cd98f00b204e9800998ecf8427e', 1, 0, 55, 3, 0, 0, '716bd2ac4467b24752348d1772b4baee6ae8ca98185bd228469f5ce4478346f9', 1757927188, 1757927188, '', '', '', '', 0, ''),
('manager',   '', 'doctis.manager@gmail.com',   'd41d8cd98f00b204e9800998ecf8427e', 1, 0, 70, 3, 0, 0, '9f7dc77b274b9a7466466da2007ef1a26ae8ca98185bd228469f5ce4478346f9', 1757927188, 1757927188, '', '', '', '', 0, ''),
('admin',     '', 'doctis.owner@gmail.com',     '5f4dcc3b5aa765d61d8327deb882cf99', 1, 0, 90, 3, 0, 0, 'f79e4810068402b52f4856cd8953f8976ae8ca98185bd228469f5ce4478346f9', 1757927188, 1757927188, '', '', '', '', 0, ''),
-- ── Named example users (Lord of the Rings characters) with full profile data ─
('frodo',   'Frodo Baggins',       'frodo@shire.example',    'd41d8cd98f00b204e9800998ecf8427e', 1, 0, 55, 0, 0, 0, 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2', 1757927188, 1757927188, 'Ring-bearer',             'The Fellowship',  '+64 9 000 0001', 'Shire',        2, ''),
('sam',     'Samwise Gamgee',      'sam@shire.example',      'd41d8cd98f00b204e9800998ecf8427e', 1, 0, 40, 0, 0, 0, 'b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2c3', 1757927188, 1757927188, 'Gardener',                'Bag End',         '+64 9 000 0002', 'Shire',        1, 'samwise@bagend.example'),
('pip',     'Peregrin Took',       'pip@shire.example',      'd41d8cd98f00b204e9800998ecf8427e', 1, 0, 40, 0, 0, 0, 'c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2c3d4', 1757927188, 1757927188, 'Guard of the Citadel',   'Gondor',          '+64 9 000 0003', 'Minas Tirith', 1, ''),
('merry',   'Meriadoc Brandybuck', 'merry@shire.example',    'd41d8cd98f00b204e9800998ecf8427e', 1, 0, 40, 0, 0, 0, 'd5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6', 1757927188, 1757927188, 'Rider of Rohan',          'The Fellowship',  '+64 9 000 0007', 'Rohan',        1, ''),
('gimli',   'Gimli son of Gloin',  'gimli@erebor.example',   'd41d8cd98f00b204e9800998ecf8427e', 1, 0, 55, 0, 0, 0, 'd4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5', 1757927188, 1757927188, 'Lord of Glittering Caves','The Fellowship',  '+64 9 000 0004', 'Erebor',       1, ''),
('legolas', 'Legolas Greenleaf',   'legolas@mirkwood.example','d41d8cd98f00b204e9800998ecf8427e', 1, 0, 55, 0, 0, 0, 'e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f6', 1757927188, 1757927188, 'Prince of Mirkwood',     'The Fellowship',  '+64 9 000 0005', 'Mirkwood',     2, ''),
('gandalf', 'Gandalf the Grey',    'gandalf@istari.example', 'd41d8cd98f00b204e9800998ecf8427e', 1, 0, 70, 0, 0, 0, 'f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f6a7', 1757927188, 1757927188, 'Wizard',                 'Order of Istari', '+64 9 000 0006', 'Middle-earth', 2, '');
SQL
)
EOF
    echo -e "${INFO}Test user accounts loaded.${OFF}" >&2
}

# ── Main ──────────────────────────────────────────────────────────────────────

main() {
    load_example_data "${targetproject}"
    load_testing_user "${targetproject}"
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    echo -e "${INFO}This will load sample data into the ${targetproject} database.${OFF}"
    echo -e "${INFO}INSERT statements are not idempotent — do not run against a populated database.${OFF}"
    read -rp "Type 'yes' to proceed: " answer
    if [ "$answer" = "yes" ]; then
        main
    fi
else
    # Being sourced — caller decides whether to invoke main
    :
fi
