<?php
# Doctis — Git branches of the application checkout: list, fetch, review and switch.
#
# Switching changes the running application code immediately.  A branch can
# be switched to freely only if it carries this page (so Doctis can switch
# back); a branch that can only git pull is a one-way switch that needs an
# explicit acknowledgement; anything else is refused, because a server
# without console access could not recover from it.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'form_api.php' );
require_api( 'git_checkout_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'session_api.php' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );

$t_branches = git_checkout_branches();
$t_current = null;
foreach( $t_branches as $t_entry ) {
	if( $t_entry['current'] ) {
		$t_current = $t_entry;
	}
}
$t_head = git_checkout_commit( 'HEAD' );
$t_status = git_checkout_status();
$t_db_version = (int)config_get( 'database_version', 0, ALL_USERS, ALL_PROJECTS );
$t_code_version = git_checkout_schema_version( 'HEAD' );
$t_access = git_checkout_operator_access();
$t_last_fetch = git_checkout_last_fetch();

$f_branch = gpc_get_string( 'branch', '' );
$t_review = ( isset( $t_branches[$f_branch] ) && !$t_branches[$f_branch]['current'] ) ? $t_branches[$f_branch] : null;

# Result of a switch, handed over by manage_git_branch_action.php through a redirect.
$t_result = session_get( 'git_branch_result', null );
if( $t_result !== null ) {
	session_delete( 'git_branch_result' );
}

/**
 * Print a commit compactly.
 *
 * @param array|null $p_commit
 * @return void
 */
function print_git_branch_commit( $p_commit ) {
	if( !$p_commit ) {
		echo '<span class="grey">—</span>';
		return;
	}
	echo '<code>' . htmlspecialchars( substr( $p_commit['sha'], 0, 9 ) ) . '</code> '
		. htmlspecialchars( date( config_get( 'normal_date_format' ), $p_commit['time'] ) ) . '<br />'
		. '<small>' . htmlspecialchars( $p_commit['author'] . ': ' . mb_strimwidth( $p_commit['subject'], 0, 70, '…' ) ) . '</small>';
}

/**
 * Print the schema label for a branch relative to the database.
 *
 * @param integer|null $p_schema
 * @param integer      $p_db_version
 * @return void
 */
function print_git_branch_schema( $p_schema, $p_db_version ) {
	if( $p_schema === null ) {
		echo '<span class="label label-danger">no Doctis schema</span>';
	} else if( $p_schema === $p_db_version ) {
		echo '<span class="label label-success">' . (int)$p_schema . '</span>';
	} else if( $p_schema > $p_db_version ) {
		echo '<span class="label label-warning" title="needs a database upgrade">' . (int)$p_schema . ' (upgrade)</span>';
	} else {
		echo '<span class="label label-danger" title="older than the database">' . (int)$p_schema . ' (older)</span>';
	}
}

$t_switch_labels = array(
	'free' => array( 'success', 'can switch back' ),
	'one-way' => array( 'warning', 'one-way' ),
	'blocked' => array( 'danger', 'blocked' ),
);

layout_page_header( 'Git Branches — System Operations' );
layout_page_begin( 'manage_overview_page.php' );
print_manage_menu( 'manage_overview_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>

<?php if( $t_result !== null ) { ?>
	<div class="alert <?php echo $t_result['rc'] === 0 ? 'alert-success' : 'alert-danger'; ?>">
		<strong><?php echo $t_result['rc'] === 0
			? 'Switched to ' . htmlspecialchars( $t_result['branch'] ) . '.'
			: 'Switching to ' . htmlspecialchars( $t_result['branch'] ) . ' failed (exit ' . (int)$t_result['rc'] . ').'; ?></strong>
		Before <code><?php echo htmlspecialchars( substr( $t_result['before'], 0, 10 ) ); ?></code>,
		after <code><?php echo htmlspecialchars( substr( $t_result['after'], 0, 10 ) ); ?></code>.
		<pre style="margin-top:8px;max-height:200px;overflow:auto;"><?php echo htmlspecialchars( $t_result['output'] ); ?></pre>
	</div>
<?php } ?>

	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-code-fork', 'ace-icon' ); ?>
			Git Branches
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">

		<table class="table table-bordered table-condensed">
			<tr>
				<th class="category" width="18%">Current branch</th>
				<td width="32%"><?php
					if( $t_current ) {
						echo '<strong>' . htmlspecialchars( $t_current['name'] ) . '</strong>';
						if( $t_current['upstream'] !== '' ) {
							echo ' → ' . htmlspecialchars( $t_current['upstream'] ) . ' ' . htmlspecialchars( $t_current['track'] );
						}
					} else {
						echo '<span class="label label-warning">detached HEAD</span>';
					}
				?><br /><?php print_git_branch_commit( $t_head ); ?></td>
				<th class="category" width="18%">Database schema</th>
				<td><?php echo $t_db_version; ?>
					<?php if( $t_code_version !== null && $t_code_version !== $t_db_version ) { ?>
					<span class="label label-warning">this code expects <?php echo (int)$t_code_version; ?></span>
					<?php } ?></td>
			</tr>
			<tr>
				<th class="category">Local changes</th>
				<td><?php if( empty( $t_status['blocking'] ) ) { ?>
					<span class="label label-success">none</span>
					<?php } else { ?>
					<a href="manage_git_checkout_page.php"><?php echo count( $t_status['blocking'] ); ?> modified tracked files</a>
					(a switch that touches them is refused)
					<?php } ?></td>
				<th class="category">Operator account</th>
				<td><?php echo htmlspecialchars( system_ops_run_as_user() ); ?>:
					<?php echo array(
						'self' => '<span class="label label-success">same account as the web server</span>',
						'any' => '<span class="label label-success">may run git</span>',
						'pull' => '<span class="label label-warning">git pull only</span> (fetch and switch run as the web server account)',
						'none' => '<span class="label label-danger">no sudo rule</span> (fetch and switch run as the web server account)',
					)[$t_access]; ?></td>
			</tr>
		</table>

		<form method="post" action="manage_git_branch_action.php" class="pull-right">
			<?php echo form_security_field( 'manage_git_branch' ); ?>
			<input type="hidden" name="action" value="fetch" />
			<button type="submit" class="btn btn-sm btn-primary btn-white btn-round">
				<?php print_icon( 'fa-refresh', 'ace-icon' ); ?> Fetch from origin
			</button>
		</form>
		<p>Branches on <code>origin</code> as last fetched
			<?php echo $t_last_fetch ? htmlspecialchars( date( config_get( 'normal_date_format' ), $t_last_fetch ) ) : '(never)'; ?>,
			and local branches. Ahead and behind are relative to the current code.</p>

		<div class="table-responsive">
		<table class="table table-condensed table-striped table-hover">
			<thead><tr>
				<th>Branch</th><th>Local</th><th>origin</th><th class="nowrap">vs current</th><th>Schema</th><th>Switch</th><th></th>
			</tr></thead>
			<tbody>
			<?php foreach( $t_branches as $t_branch ) {
				list( $t_class, $t_label ) = $t_switch_labels[$t_branch['switch']];
			?>
				<tr<?php echo $t_branch['current'] ? ' class="info"' : ''; ?>>
					<td><strong><?php echo htmlspecialchars( $t_branch['name'] ); ?></strong>
						<?php if( $t_branch['current'] ) { ?><span class="label label-info">current</span><?php } ?>
						<?php if( $t_branch['track'] !== '' ) { ?><br /><small><?php echo htmlspecialchars( $t_branch['track'] ); ?> vs origin</small><?php } ?></td>
					<td><?php print_git_branch_commit( $t_branch['local'] ); ?></td>
					<td><?php print_git_branch_commit( $t_branch['remote'] ); ?></td>
					<td class="nowrap"><?php echo $t_branch['current'] ? '—'
						: '+' . (int)$t_branch['ahead'] . ' / −' . (int)$t_branch['behind']; ?></td>
					<td><?php print_git_branch_schema( $t_branch['schema'], $t_db_version ); ?></td>
					<td><span class="label label-<?php echo $t_class; ?>"><?php echo $t_label; ?></span></td>
					<td><?php if( !$t_branch['current'] ) { ?>
						<a class="btn btn-xs btn-white btn-round" href="manage_git_branch_page.php?branch=<?php echo urlencode( $t_branch['name'] ); ?>#review">Review</a>
						<?php } ?></td>
				</tr>
			<?php } ?>
			</tbody>
		</table>
		</div>
		<p class="small grey">
			<strong>can switch back</strong>: the branch has this page.
			<strong>one-way</strong>: it can only git pull, so returning needs a commit with this page pushed to that branch.
			<strong>blocked</strong>: it cannot update itself, so switching is refused.
		</p>

	</div>
	</div>
	</div>

<?php if( $t_review ) {
	$t_ref = $t_review['ref'];
	$t_gained = git_checkout_lines( git_checkout_git( 'log -n 20 ' . escapeshellarg( '--format=%h %s' ) . ' ' . escapeshellarg( 'HEAD..' . $t_ref ) ) );
	$t_lost = git_checkout_lines( git_checkout_git( 'log -n 20 ' . escapeshellarg( '--format=%h %s' ) . ' ' . escapeshellarg( $t_ref . '..HEAD' ) ) );
	$t_stat = git_checkout_git( 'diff --shortstat HEAD ' . escapeshellarg( $t_ref ) );
?>
	<div class="space-10"></div>
	<div id="review" class="widget-box <?php echo $t_review['switch'] === 'blocked' ? 'widget-color-red' : 'widget-color-orange'; ?>">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-random', 'ace-icon' ); ?>
			Switch to <?php echo htmlspecialchars( $t_review['name'] ); ?>
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<table class="table table-bordered table-condensed">
			<tr><th class="category" width="18%">Target</th><td><?php
				echo $t_review['local']
					? 'local branch <code>' . htmlspecialchars( $t_review['name'] ) . '</code>'
					: 'new local branch tracking <code>origin/' . htmlspecialchars( $t_review['name'] ) . '</code>';
				echo '<br />';
				print_git_branch_commit( $t_review['local'] ?: $t_review['remote'] );
			?></td></tr>
			<tr><th class="category">Code change</th><td><?php echo htmlspecialchars( $t_stat !== '' ? $t_stat : 'no file differences' ); ?></td></tr>
			<tr><th class="category">Database</th><td><?php
				print_git_branch_schema( $t_review['schema'], $t_db_version );
				if( $t_review['schema'] === null ) {
					echo ' This branch is not a Doctis application with a flat schema.';
				} else if( $t_review['schema'] > $t_db_version ) {
					$t_first = $t_db_version + 1;
					echo ' After switching, run <strong>Install/Upgrade Database</strong> in <code>admin/install.php</code> to add '
						. ( $t_first === $t_review['schema'] ? 'step ' . $t_first : 'steps ' . $t_first . '–' . (int)$t_review['schema'] ) . '.';
				} else if( $t_review['schema'] < $t_db_version ) {
					echo ' The database has steps this branch does not know about. Usually harmless (extra tables), but its code'
						. ' may not match the database.';
				} else {
					echo ' Same schema as the database.';
				}
			?></td></tr>
			<tr><th class="category">Returning</th><td><?php
				list( $t_class, $t_label ) = $t_switch_labels[$t_review['switch']];
				echo '<span class="label label-' . $t_class . '">' . $t_label . '</span> ';
				echo array(
					'free' => 'This branch has the branch switcher, so you can switch back from Doctis.',
					'one-way' => 'This branch has Git Pull but not the branch switcher. Doctis will keep pulling <code>'
						. htmlspecialchars( $t_review['name'] ) . '</code>; to come back, a commit that adds this page must be pushed to it.',
					'blocked' => 'This branch cannot update itself. Without console access the server could not be recovered,'
						. ' so switching is refused.',
				)[$t_review['switch']];
			?></td></tr>
		</table>

		<div class="row">
			<div class="col-md-6">
				<h5>Commits gained (<?php echo (int)$t_review['ahead']; ?>)</h5>
				<pre style="max-height:220px;overflow:auto;"><?php echo htmlspecialchars( $t_gained ? implode( "\n", $t_gained ) : '(none)' );
					echo $t_review['ahead'] > 20 ? "\n…" : ''; ?></pre>
			</div>
			<div class="col-md-6">
				<h5>Commits no longer in the running code (<?php echo (int)$t_review['behind']; ?>)</h5>
				<pre style="max-height:220px;overflow:auto;"><?php echo htmlspecialchars( $t_lost ? implode( "\n", $t_lost ) : '(none)' );
					echo $t_review['behind'] > 20 ? "\n…" : ''; ?></pre>
			</div>
		</div>

		<?php if( $t_review['switch'] !== 'blocked' ) { ?>
		<form method="post" action="manage_git_branch_action.php">
			<?php echo form_security_field( 'manage_git_branch' ); ?>
			<input type="hidden" name="action" value="switch" />
			<input type="hidden" name="branch" value="<?php echo htmlspecialchars( $t_review['name'] ); ?>" />
			<p>The application switches immediately, for every user. Type the branch name to confirm:</p>
			<div class="form-group" style="max-width:300px;">
				<input type="text" name="confirm" class="form-control" autocomplete="off" required
					placeholder="<?php echo htmlspecialchars( $t_review['name'] ); ?>" />
			</div>
			<?php if( $t_review['switch'] === 'one-way' ) { ?>
			<div class="checkbox"><label>
				<input type="checkbox" name="one_way" value="1" required />
				I understand Doctis cannot switch back from this branch.
			</label></div>
			<?php } ?>
			<button type="submit" class="btn btn-sm btn-warning">
				<?php print_icon( 'fa-random', 'ace-icon' ); ?> Switch to <?php echo htmlspecialchars( $t_review['name'] ); ?>
			</button>
			<a href="manage_git_branch_page.php" class="btn btn-sm btn-default">Cancel</a>
		</form>
		<?php } ?>
	</div>
	</div>
	</div>
<?php } ?>
</div>

<?php
layout_page_end();
