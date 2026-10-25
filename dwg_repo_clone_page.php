<?php
# dwg_repo_clone_page.php
# Help a user clone a project's git repository onto their own machine.
#
# A web page cannot run git on the visitor's computer, and a browser has no
# way to pick a directory and clone into it — nothing in the platform allows
# that without an installed helper.  So this does the three things a browser
# genuinely can:
#
#   1. mint an API token for the purpose, because a token's value is stored
#      only as a hash and can never be shown again after it is created;
#   2. hand over a ready-made `git clone` command with that token in it;
#   3. hand over a small clone script, which the browser's own save dialog
#      puts wherever the user wants it, and which clones into the directory
#      they choose when they run it.
#
# Reached from Manage Project -> Repository and from the Primary Document
# panel of a source record.  See doc/git/SOURCE_REPOSITORIES.md.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'api_token_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'database_api.php' );
require_api( 'dwg_api.php' );
require_api( 'file_dwg_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'print_dwg_api.php' );
require_api( 'project_api.php' );
require_api( 'repository_api.php' );
require_api( 'string_api.php' );

auth_ensure_user_authenticated();

# Reached either from a project page or from a document's panel.
$f_project_id = gpc_get_int( 'project_id', 0 );
$f_dwg_id     = gpc_get_int( 'dwg_id', 0 );
if( $f_project_id === 0 && $f_dwg_id > 0 ) {
	$f_project_id = (int)dwg_get_field( $f_dwg_id, 'project_id' );
}
project_ensure_exists( $f_project_id );

if( OFF == config_get_global( 'git_http_enabled' ) ) {
	# Nothing to clone from: the gateway refuses every request when this is off.
	error_parameters( lang_get( 'repo_clone_disabled' ) );
	trigger_error( ERROR_ACCESS_DENIED, ERROR );
}

$t_repo_id = repository_id_for_project( $f_project_id );
if( $t_repo_id === 0 || !is_dir( repository_bare_path( $t_repo_id ) ) ) {
	error_parameters( $f_project_id );
	trigger_error( ERROR_FILE_NOT_FOUND, ERROR );
}

# The same test the Clone buttons are shown by, so a button never leads here
# to be refused: read access on the repository's owner project.
if( !repository_user_can_clone( $f_project_id ) ) {
	access_denied();
}

$t_repo_row   = repository_get_row( $t_repo_id );
$t_owner_proj = (int)$t_repo_row['owner_project_id'];

$t_basename  = repository_basename( $t_repo_id );
$t_policy    = repository_push_policy( $t_repo_id );
$t_can_push  = access_has_project_level(
	config_get( 'git_http_write_threshold', null, auth_get_current_user_id(), $t_owner_proj ),
	$t_owner_proj );
$t_user_id   = auth_get_current_user_id();
$t_username  = user_get_username( $t_user_id );

# The clone URL has no credentials in it; the token is added only where the
# page explicitly hands one over.
$t_clone_url = repository_clone_url( $t_basename );

# Tokens minted here are named after the repository, so the user can tell on
# My Account -> API Tokens what each is for and revoke it.
$t_token_base = 'git: ' . $t_basename;
$t_existing   = api_token_get_by_prefix( $t_token_base, $t_user_id );

$t_token = '';
if( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	form_security_validate( 'dwg_repo_clone' );

	# A new token never revokes an older one by default.  A token's value is
	# stored only as a hash and cannot be shown again, so an older one is still
	# in use by whatever already holds it — a clone's .git/config, most likely
	# — and revoking it silently breaks that clone's pushes.  Clearing up is a
	# deliberate, separate tick.
	if( gpc_get_bool( 'revoke_existing' ) ) {
		foreach( $t_existing as $t_old ) {
			api_token_revoke( (int)$t_old['id'], $t_user_id );
		}
		$t_existing = array();
	}

	$t_token = api_token_create(
		api_token_name_available( $t_token_base, $t_user_id ), $t_user_id );

	form_security_purge( 'dwg_repo_clone' );
}

$t_auth_url = $t_token === '' ? '' : repository_clone_url( $t_basename, $t_username, $t_token );

layout_page_header( lang_get( 'repo_clone_title' ) );
layout_page_begin();
?>
<div class="col-md-10 col-md-offset-1 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter">
				<?php print_icon( 'fa-download', 'ace-icon' ); ?>
				<?php echo lang_get( 'repo_clone_title' ) ?>
				&mdash; <code><?php echo string_display_line( $t_basename ) ?></code>
			</h4>
		</div>
		<div class="widget-body">
		<div class="widget-main padding-16">

			<table class="table table-bordered table-condensed">
				<tr>
					<th class="category width-25"><?php echo lang_get( 'project_repository' ) ?></th>
					<td><code><?php echo string_display_line( $t_basename ) ?></code>
						&nbsp;<span class="label label-<?php echo $t_policy === DWG_PUSH_POLICY_SOURCE
							? 'primary' : 'default' ?>"><?php echo lang_get( $t_policy === DWG_PUSH_POLICY_SOURCE
							? 'push_policy_source' : 'push_policy_document' ) ?></span></td>
				</tr>
				<tr>
					<th class="category"><?php echo lang_get( 'repo_clone_your_access' ) ?></th>
					<td><?php echo lang_get( $t_can_push ? 'repo_clone_read_write' : 'repo_clone_read_only' ) ?>
						<br /><span class="small"><?php echo lang_get( $t_policy === DWG_PUSH_POLICY_SOURCE
							? 'push_policy_source_help' : 'push_policy_document_help' ) ?></span></td>
				</tr>
			</table>

<?php if( $t_token === '' ): ?>
			<p><?php echo lang_get( 'repo_clone_intro' ) ?></p>
<?php	if( $t_existing ): ?>
			<div class="alert alert-info">
				<?php echo lang_get( 'repo_clone_existing' ) ?>
				<ul class="no-margin">
<?php		foreach( $t_existing as $t_old ): ?>
					<li><code><?php echo string_display_line( $t_old['name'] ) ?></code>
						&mdash; <?php echo sprintf( lang_get( 'repo_clone_existing_made' ),
							date( config_get( 'normal_date_format' ), (int)$t_old['date_created'] ) ) ?></li>
<?php		endforeach; ?>
				</ul>
			</div>
<?php	endif; ?>
			<form method="post" action="dwg_repo_clone_page.php">
				<?php echo form_security_field( 'dwg_repo_clone' ) ?>
				<input type="hidden" name="project_id" value="<?php echo (int)$f_project_id ?>" />
				<button class="btn btn-primary btn-white btn-round">
					<?php echo lang_get( 'repo_clone_create_token' ) ?>
				</button>
<?php	if( $t_existing ): ?>
				<div class="space-10"></div>
				<label class="nopadding">
					<input type="checkbox" class="ace" name="revoke_existing" value="1" />
					<span class="lbl">&nbsp;<?php echo sprintf(
						lang_get( 'repo_clone_revoke_existing' ), count( $t_existing ) ) ?></span>
				</label>
<?php	endif; ?>
			</form>
<?php	if( $t_existing ): ?>
			<div class="space-10"></div>
			<?php print_dwg_git_token_notice( $t_basename, 'alert-info' ); ?>
<?php	endif; ?>

			<div class="space-10"></div>
			<p class="small"><?php echo sprintf( lang_get( 'repo_clone_no_token_url' ),
				'<code>' . string_display_line( $t_clone_url ) . '</code>' ) ?></p>
<?php else: ?>
			<div class="alert alert-danger">
				<strong><?php echo lang_get( 'repo_clone_token_once' ) ?></strong>
				<?php echo lang_get( 'repo_clone_token_scope' ) ?>
			</div>

			<h5><?php echo lang_get( 'repo_clone_step_command' ) ?></h5>
			<p class="small"><?php echo lang_get( 'repo_clone_step_command_help' ) ?></p>
			<div class="input-group">
				<input id="clone-command" type="text" class="form-control" readonly="readonly"
					value="<?php echo string_attribute( 'git clone ' . $t_auth_url ) ?>" />
				<span class="input-group-btn">
					<button type="button" id="clone-copy" class="btn btn-primary btn-white"
						data-target="clone-command"><?php echo lang_get( 'repo_clone_copy' ) ?></button>
				</span>
			</div>

			<div class="space-10"></div>
			<h5><?php echo lang_get( 'repo_clone_step_script' ) ?></h5>
			<p class="small"><?php echo lang_get( 'repo_clone_step_script_help' ) ?></p>
			<form method="post" action="dwg_repo_clone_script.php" style="display:inline">
				<?php echo form_security_field( 'dwg_repo_clone_script' ) ?>
				<input type="hidden" name="project_id" value="<?php echo (int)$f_project_id ?>" />
				<input type="hidden" name="token" value="<?php echo string_attribute( $t_token ) ?>" />
				<input type="hidden" name="os" value="sh" />
				<button class="btn btn-success btn-sm btn-white btn-round">
					<?php echo lang_get( 'repo_clone_download_sh' ) ?>
				</button>
			</form>
			<form method="post" action="dwg_repo_clone_script.php" style="display:inline">
				<?php echo form_security_field( 'dwg_repo_clone_script' ) ?>
				<input type="hidden" name="project_id" value="<?php echo (int)$f_project_id ?>" />
				<input type="hidden" name="token" value="<?php echo string_attribute( $t_token ) ?>" />
				<input type="hidden" name="os" value="cmd" />
				<button class="btn btn-success btn-sm btn-white btn-round">
					<?php echo lang_get( 'repo_clone_download_cmd' ) ?>
				</button>
			</form>

			<div class="space-10"></div>
			<h5><?php echo lang_get( 'repo_clone_step_existing' ) ?></h5>
			<p class="small"><?php echo lang_get( 'repo_clone_step_existing_help' ) ?></p>
			<div class="input-group">
				<input id="repoint-command" type="text" class="form-control" readonly="readonly"
					value="<?php echo string_attribute( 'git remote set-url origin ' . $t_auth_url ) ?>" />
				<span class="input-group-btn">
					<button type="button" id="repoint-copy" class="btn btn-primary btn-white"
						data-target="repoint-command"><?php echo lang_get( 'repo_clone_copy' ) ?></button>
				</span>
			</div>

			<div class="space-10"></div>
			<p class="small"><?php echo sprintf( lang_get( 'repo_clone_in_config' ),
				'<code>git -C &lt;dir&gt; remote set-url origin '
					. string_display_line( $t_clone_url ) . '</code>' ) ?></p>
<?php endif; ?>

			<div class="space-10"></div>
			<a href="<?php echo $f_dwg_id > 0 ? 'dwg_view.php?id=' . (int)$f_dwg_id
				: 'manage_proj_edit_page.php?project_id=' . (int)$f_project_id . '#repository' ?>"
				class="btn btn-default btn-sm btn-white btn-round">
				<?php echo lang_get( 'primary_document_tag_cancel' ) ?>
			</a>

		</div>
		</div>
	</div>
</div>
<?php
html_javascript_link( 'dwg_repo_clone.js' );
layout_page_end();
