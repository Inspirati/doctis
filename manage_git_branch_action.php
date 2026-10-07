<?php
# Doctis — Fetch from origin, or switch the application checkout to a branch.
#
# A switch replaces the running code, so the result is not rendered here:
# this request has already loaded part of the old code and would load the
# rest from the new branch.  It is handed to manage_git_branch_page.php
# through the session, and the redirect serves that page from the new code.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );
require_api( 'form_api.php' );
require_api( 'git_checkout_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'session_api.php' );

form_security_validate( 'manage_git_branch' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

$f_action = gpc_get_string( 'action', '' );

if( $f_action === 'switch' ) {
	$f_branch = gpc_get_string( 'branch', '' );
	$t_branches = git_checkout_branches();
	$t_branch = $t_branches[$f_branch] ?? null;
	$t_refusal = null;
	if( $t_branch === null ) {
		$t_refusal = 'Unknown branch.';
	} else if( $t_branch['current'] ) {
		$t_refusal = 'That branch is already checked out.';
	} else if( $t_branch['switch'] === 'blocked' ) {
		$t_refusal = 'That branch cannot update itself, so switching to it is refused.';
	} else if( gpc_get_string( 'confirm', '' ) !== $f_branch ) {
		$t_refusal = 'Confirmation text did not match. Type the branch name to proceed.';
	} else if( $t_branch['switch'] === 'one-way' && !gpc_get_bool( 'one_way' ) ) {
		$t_refusal = 'Acknowledge that Doctis cannot switch back from that branch.';
	}
	form_security_purge( 'manage_git_branch' );
	if( $t_refusal !== null ) {
		error_parameters( $t_refusal );
		trigger_error( ERROR_GENERIC, ERROR );
	}

	$t_before = git_checkout_git( 'rev-parse --verify HEAD' );
	$t_output = git_checkout_switch( $t_branch, $t_rc );
	$t_after = git_checkout_git( 'rev-parse --verify HEAD' );

	error_log( 'ADMIN: ' . current_user_get_field( 'username' ) . ' switched the application to branch "' . $f_branch
		. '" at ' . date( 'c' ) . ' (exit ' . $t_rc . ', before ' . $t_before . ', after ' . $t_after . ')' );

	session_set( 'git_branch_result', array( 'branch' => $f_branch, 'rc' => $t_rc, 'output' => $t_output,
		'before' => $t_before, 'after' => $t_after ) );
	print_header_redirect( 'manage_git_branch_page.php' );
}

if( $f_action !== 'fetch' ) {
	error_parameters( 'Unknown action.' );
	trigger_error( ERROR_GENERIC, ERROR );
}

form_security_purge( 'manage_git_branch' );
$t_output = git_checkout_fetch( $t_rc );

error_log( 'ADMIN: ' . current_user_get_field( 'username' ) . ' fetched origin at ' . date( 'c' ) . ' (exit ' . $t_rc . ')' );

layout_page_header( 'Git Branches — System Operations' );
layout_page_begin( 'manage_overview_page.php' );
print_manage_menu( 'manage_overview_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box <?php echo $t_rc === 0 ? 'widget-color-blue2' : 'widget-color-red'; ?>">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-refresh', 'ace-icon' ); ?>
			Fetch from origin
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<div class="alert <?php echo $t_rc === 0 ? 'alert-success' : 'alert-danger'; ?>">
			<strong><?php echo $t_rc === 0 ? 'Fetched successfully.' : 'Fetch failed (exit ' . (int)$t_rc . ').'; ?></strong>
		</div>
		<pre style="background:#f5f5f5;padding:12px;border:1px solid #ddd;overflow:auto;max-height:400px;"><?php echo htmlspecialchars( $t_output ); ?></pre>
		<a href="manage_git_branch_page.php" class="btn btn-sm btn-default">
			<?php print_icon( 'fa-arrow-left', 'ace-icon' ); ?> Back to Git Branches
		</a>
	</div>
	</div>
	</div>
</div>

<?php
layout_page_end();
