<?php
require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'form_api.php' );
require_api( 'html_api.php' );
require_api( 'git_restore_api.php' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );
$t_recovery = trim( (string)@file_get_contents( git_restore_gate_path() . '.restore' ) );
$t_uploads = git_restore_uploads( auth_get_current_user_id() );

layout_page_header( 'Restore Git Store — System Operations' );
layout_page_begin( 'manage_overview_page.php' );
print_manage_menu( 'manage_overview_page.php' );
?>
<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-orange">
		<div class="widget-header widget-header-small"><h4 class="widget-title lighter">Restore Git Store</h4></div>
		<div class="widget-body"><div class="widget-main">
			<p>Restore destination: <strong><?php echo string_display_line( config_get_global( 'path' ) ); ?></strong></p>
			<p>Restore the matching database first. Select an archive from <a href="manage_git_backup_page.php">Backup Git Store</a>.
				The backup is uploaded in small chunks, then its Git history, LFS files and database references are checked before you confirm replacement.</p>
			<p>Replacement removes this server's existing repositories, LFS objects and cached worktrees. Keep a backup first.
				Other application requests are paused during replacement. If interrupted, return here to recover it.</p>
			<noscript><p>JavaScript is required for chunked uploads and restore progress.</p></noscript>
			<form id="git-restore-form" method="post" action="manage_git_restore_action.php" data-recovery="<?php echo string_attribute( $t_recovery ); ?>">
				<?php echo form_security_field( 'manage_git_restore' ); ?>
				<?php if( $t_uploads ): ?>
				<div class="form-group"><label for="restore-saved">Saved uploads</label>
					<select id="restore-saved" class="form-control" style="max-width:650px">
						<option value="">New upload</option>
						<?php foreach( $t_uploads as $t_upload ): ?>
						<option value="<?php echo string_attribute( $t_upload['ticket'] ); ?>"><?php echo string_display_line( $t_upload['name'] . ' — ' . $t_upload['phase'] ); ?></option>
						<?php endforeach; ?>
					</select></div>
				<?php endif; ?>
				<div class="form-group"><label for="restore-file">Git/LFS backup (.tar.gz)</label>
					<input id="restore-file" type="file" accept=".tar.gz,.tgz" required></div>
				<div class="form-group"><label for="restore-sha256">Expected SHA-256 (optional)</label>
					<input id="restore-sha256" class="form-control" type="text" maxlength="64" pattern="[a-fA-F0-9]{64}" style="max-width:650px"></div>
				<button id="restore-upload" type="submit" class="btn btn-sm btn-primary">Upload and validate</button>
				<button id="restore-cancel" type="button" class="btn btn-sm btn-default" hidden>Discard upload</button>
			</form>
			<div class="space-10"></div>
			<progress id="restore-progress" value="0" max="100" style="width:100%" hidden></progress>
			<p id="restore-message" role="status" aria-live="polite"></p>
			<pre id="restore-report" hidden></pre>
			<div id="restore-confirmation" hidden>
				<label for="restore-confirm">Type RESTORE to replace this server's Git/LFS store</label>
				<input id="restore-confirm" type="text" class="form-control" style="max-width:300px" autocomplete="off">
				<div class="space-10"></div><button id="restore-apply" class="btn btn-sm btn-danger" type="button">Restore Git Store</button>
			</div>
			<div id="restore-recovery" hidden>
				<p>An installation was interrupted. Recovery rolls back an unfinished replacement, or finishes cleanup if it completed.</p>
				<label for="restore-recover-confirm">Type RECOVER</label>
				<input id="restore-recover-confirm" type="text" class="form-control" style="max-width:300px" autocomplete="off">
				<button id="restore-recover" class="btn btn-sm btn-warning" type="button">Recover interrupted restore</button>
			</div>
		</div></div>
	</div>
</div>
<?php
html_javascript_link( 'git_restore.js' );
layout_page_end();
