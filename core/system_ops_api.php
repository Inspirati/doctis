<?php
# Doctis - Document Issue Tracking System
#
# System Operations helpers.
#
# The admin-only "System Operations" pages (manage_*_action.php:
# git pull / DB rebuild / sample-data load / config write / DB backup) run
# privileged shell commands as a dedicated OS account via "sudo -u".  That
# account is configured by $g_updater_run_as_user (set by the installer to the
# account performing the install) and must own the application working tree,
# hold the git-remote / database credentials, and have matching NOPASSWD rules
# in /etc/sudoers.d/doctis-web.
#
# See doc/doctis-git-server-setup.txt STEP 9 and doc/UPDATER-PLAN.md.

require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'utility_api.php' );

/**
 * Return the configured OS account that System Operations run as via sudo.
 *
 * Aborts with a clear error when unset, rather than letting a command run as
 * the wrong user.
 *
 * @return string The configured account name.
 */
function system_ops_run_as_user(): string {
	$t_user = config_get_global( 'updater_run_as_user' );
	if( is_blank( $t_user ) ) {
		error_parameters(
			'System Operations are not configured: set $g_updater_run_as_user '
			. 'in config_inc.php to the OS account that owns the application '
			. 'and holds the relevant sudoers rules.'
		);
		trigger_error( ERROR_GENERIC, ERROR );
	}
	return $t_user;
}

/**
 * Return a shell-safe "sudo -u <user> " command prefix for System Operations.
 *
 * @return string  e.g. "sudo -u 'hcr' "
 */
function system_ops_sudo_prefix(): string {
	return 'sudo -u ' . escapeshellarg( system_ops_run_as_user() ) . ' ';
}
