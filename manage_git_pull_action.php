<?php
# Doctis — Execute git pull on the doctis working tree and display output.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );
require_api( 'form_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'system_ops_api.php' );

form_security_validate( 'manage_git_pull' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

form_security_purge( 'manage_git_pull' );

$t_repo = '/var/www/html/doctis';
$t_before = trim( (string)@shell_exec(
	'/usr/bin/git -C ' . escapeshellarg( $t_repo ) . ' rev-parse --verify HEAD 2>/dev/null'
) );

exec( system_ops_sudo_prefix() . '/usr/bin/git -C /var/www/html/doctis pull 2>&1', $t_lines, $t_rc );
$t_output = implode( "\n", $t_lines );
$t_after = trim( (string)@shell_exec(
	'/usr/bin/git -C ' . escapeshellarg( $t_repo ) . ' rev-parse --verify HEAD 2>/dev/null'
) );
$t_updated = $t_rc === 0 && $t_before !== '' && $t_after !== '' && $t_before !== $t_after;
$t_unchanged = $t_rc === 0 && $t_before !== '' && hash_equals( $t_before, $t_after );

error_log(
	'ADMIN: ' . current_user_get_field( 'username' ) . ' ran git pull at ' . date( 'c' )
	. ' (exit ' . $t_rc . ', before ' . ( $t_before ?: 'unknown' )
	. ', after ' . ( $t_after ?: 'unknown' ) . ')'
);

layout_page_header( 'Git Pull — System Operations' );
layout_page_begin( __FILE__ );
print_manage_menu( 'manage_overview_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box <?php echo $t_rc === 0 ? 'widget-color-blue2' : 'widget-color-red'; ?>">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-download', 'ace-icon' ); ?>
			Git Pull Result
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<?php if( $t_rc !== 0 ) { ?>
		<div class="alert alert-danger">
			<strong>git pull failed</strong> (exit code <?php echo (int)$t_rc; ?>).
		</div>
		<?php } elseif( $t_updated ) { ?>
		<div class="alert alert-success">
			<strong>Application updated successfully.</strong>
		</div>
		<?php } elseif( $t_unchanged ) { ?>
		<div class="alert alert-info">
			<strong>Application was already up to date.</strong>
		</div>
		<?php } else { ?>
		<div class="alert alert-warning">
			<strong>git pull exited successfully, but the deployed commit could not be verified.</strong>
		</div>
		<?php } ?>
		<table class="table table-bordered table-condensed" style="max-width:760px;">
			<tr>
				<th class="category">Commit before pull</th>
				<td><code><?php echo htmlspecialchars( $t_before ?: 'unknown' ); ?></code></td>
			</tr>
			<tr>
				<th class="category">Commit after pull</th>
				<td><code><?php echo htmlspecialchars( $t_after ?: 'unknown' ); ?></code></td>
			</tr>
		</table>
		<pre style="background:#f5f5f5;padding:12px;border:1px solid #ddd;overflow:auto;max-height:400px;"><?php echo htmlspecialchars( $t_output ); ?></pre>
		<div class="space-10"></div>
		<a href="manage_overview_page.php" class="btn btn-sm btn-default">
			<?php print_icon( 'fa-arrow-left', 'ace-icon' ); ?> Back to System Operations
		</a>
	</div>
	</div>
	</div>
</div>

<?php
layout_page_end();
