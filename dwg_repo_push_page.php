<?php
# dwg_repo_push_page.php
# Push a repository the user already has into a project's Doctis repository.
#
# The common case: a project has just been given its own repository, which
# is empty, and the work already exists in a git repository on someone's
# computer -- they ran `git init` there.  Getting it into Doctis takes a
# token, the right remote, and a push of every branch and tag, plus Git LFS
# for large binaries and a .gitignore for generated files.  None of that is
# obvious, and Doctis aims to explain itself on the page where the job is
# done rather than send people to documentation.  So this page:
#
#   - says what state the repository is in, and refreshes into the check
#     that the push arrived;
#   - mints the token (as the Clone page does), so the commands it shows are
#     ready to paste;
#   - walks through the steps, then shows a worked example using this
#     repository's real address, and what each error message means;
#   - points to the next step: registering what was pushed.
#
# Reached from Manage Project -> Repository, beside Clone Repository, and
# from the Clone page when the repository is empty.  The same steps are in
# doc/DOCTIS-GIT-USER-MANUAL.md section 6.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'file_dwg_api.php' );
require_api( 'form_api.php' );
require_api( 'git_lfs_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'print_dwg_api.php' );
require_api( 'project_api.php' );
require_api( 'repository_api.php' );
require_api( 'string_api.php' );

auth_ensure_user_authenticated();

$f_project_id = gpc_get_int( 'project_id' );
project_ensure_exists( $f_project_id );

if( OFF == config_get_global( 'git_http_enabled' ) ) {
	error_parameters( lang_get( 'repo_clone_disabled' ) );
	trigger_error( ERROR_ACCESS_DENIED, ERROR );
}

$t_repo_id = repository_id_for_project( $f_project_id );
if( $t_repo_id === 0 || !is_dir( repository_bare_path( $t_repo_id ) ) ) {
	error_parameters( $f_project_id );
	trigger_error( ERROR_FILE_NOT_FOUND, ERROR );
}

# Anyone who can clone may read the instructions; only a token for someone
# who can push is useful, so minting one needs the write threshold.
if( !repository_user_can_clone( $f_project_id ) ) {
	access_denied();
}

$t_user_id   = auth_get_current_user_id();
$t_username  = user_get_username( $t_user_id );
$t_basename  = repository_basename( $t_repo_id );
$t_policy    = repository_push_policy( $t_repo_id );
$t_can_push  = repository_user_can_push( $f_project_id );
$t_branches  = repository_branches( $t_repo_id );
$t_empty     = !$t_branches;
$t_lfs       = git_lfs_enabled();
$t_clone_url = repository_clone_url( $t_basename );
$t_existing  = repository_git_tokens( $t_basename, $t_user_id );

$t_token = '';
if( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	form_security_validate( 'dwg_repo_push' );
	if( !$t_can_push ) {
		access_denied();
	}
	$f_revoke = gpc_get_bool( 'revoke_existing' );
	$t_token  = repository_git_token_create( $t_basename, $t_user_id, $f_revoke );
	if( $f_revoke ) {
		$t_existing = array();
	}
	form_security_purge( 'dwg_repo_push' );
}

# The address in the commands: with the new token once there is one, else
# with a visible placeholder for the user's own.
$t_push_url = $t_token !== ''
	? repository_clone_url( $t_basename, $t_username, $t_token )
	: str_replace( '://', '://' . $t_username . ':<token>@', $t_clone_url );
$t_shown_url = str_replace( '://', '://' . $t_username . ':<token>@', $t_clone_url );

# The largest single push PHP accepts; LFS files travel separately.
$t_push_limit = (string)ini_get( 'post_max_size' );

# What to do with the files once they are in: a source project registers the
# repository as one record, any other project registers files one by one.
$t_source_release = file_dwg_source_release_enabled( $f_project_id );

# A folder name for the worked example, from the project's name.
$t_example_dir = preg_replace( '/[^A-Za-z0-9._-]+/', '-', project_get_name( $f_project_id ) );

/**
 * Print a copyable command block: a read-only text area and a Copy button.
 *
 * @param string $p_id   Element id.
 * @param string $p_text The commands, one per line.
 * @return void
 */
function dwg_repo_push_commands( string $p_id, string $p_text ): void {
	$t_rows = substr_count( $p_text, "\n" ) + 1;
	echo '<textarea id="', string_attribute( $p_id ), '" class="form-control" rows="', $t_rows,
		'" readonly="readonly" style="font-family: monospace;">', string_html_specialchars( $p_text ), '</textarea>';
	echo '<div class="space-4"></div>';
	echo '<button type="button" class="btn btn-primary btn-xs btn-white doctis-copy" data-target="',
		string_attribute( $p_id ), '">', lang_get( 'repo_clone_copy' ), '</button>';
}

/**
 * Print one numbered step's heading and explanation.
 *
 * @param int    $p_number
 * @param string $p_title_key Lang key of the heading.
 * @param string $p_help      Explanation (HTML from the lang file).
 * @return void
 */
function dwg_repo_push_step( int $p_number, string $p_title_key, string $p_help ): void {
	echo '<h5 class="bold">', $p_number, '. ', lang_get( $p_title_key ), '</h5>';
	echo '<p class="small">', $p_help, '</p>';
}

layout_page_header( lang_get( 'repo_push_title' ) );
layout_page_begin();
?>
<div class="col-md-10 col-md-offset-1 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter">
				<?php print_icon( 'fa-upload', 'ace-icon' ); ?>
				<?php echo lang_get( 'repo_push_title' ) ?>
				&mdash; <code><?php echo string_display_line( $t_basename ) ?></code>
			</h4>
		</div>
		<div class="widget-body">
		<div class="widget-main padding-16">

			<p><?php echo lang_get( 'repo_push_page_intro' ) ?></p>

			<table class="table table-bordered table-condensed">
				<tr>
					<th class="category width-25"><?php echo lang_get( 'project_repository' ) ?></th>
					<td><code><?php echo string_display_line( $t_basename ) ?></code>
						&nbsp;<span class="label label-<?php echo $t_policy === DWG_PUSH_POLICY_SOURCE
							? 'primary' : 'default' ?>"><?php echo lang_get( $t_policy === DWG_PUSH_POLICY_SOURCE
							? 'push_policy_source' : 'push_policy_document' ) ?></span>
						<br /><span class="small"><?php echo lang_get( 'repo_push_address' ) ?>
						<code><?php echo string_display_line( $t_clone_url ) ?></code></span></td>
				</tr>
				<tr>
					<th class="category"><?php echo lang_get( 'repo_push_state' ) ?></th>
					<td>
<?php if( $t_empty ): ?>
						<span class="label label-warning"><?php echo lang_get( 'project_repository_empty' ) ?></span>
						&nbsp;<?php echo lang_get( 'repo_push_state_empty' ) ?>
<?php else: ?>
						<?php echo sprintf( lang_get( 'repo_push_state_branches' ), count( $t_branches ) ) ?>
						<table class="table table-condensed no-margin">
<?php	foreach( array_slice( $t_branches, 0, 10 ) as $t_branch ): ?>
							<tr>
								<td class="nowrap"><code><?php echo string_display_line( $t_branch['name'] ) ?></code></td>
								<td class="nowrap"><code><?php echo htmlspecialchars( substr( $t_branch['sha'], 0, 8 ) ) ?></code></td>
								<td><?php echo string_display_line( $t_branch['subject'] ) ?></td>
								<td class="nowrap small"><?php echo string_display_line(
									date( config_get( 'normal_date_format' ), $t_branch['time'] ) ) ?></td>
							</tr>
<?php	endforeach; ?>
						</table>
<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th class="category"><?php echo lang_get( 'repo_clone_your_access' ) ?></th>
					<td><?php echo lang_get( $t_can_push ? 'repo_clone_read_write' : 'repo_clone_read_only' ) ?></td>
				</tr>
			</table>

<?php if( !$t_empty ): ?>
			<div class="alert alert-warning">
				<?php echo lang_get( 'repo_push_not_empty' ) ?>
				<div class="space-6"></div>
				<a href="dwg_repo_clone_page.php?project_id=<?php echo (int)$f_project_id ?>"
					class="btn btn-sm btn-primary btn-white btn-round">
					<?php print_icon( 'fa-download', 'ace-icon' ); ?>
					<?php echo lang_get( 'repo_clone_button' ) ?>
				</a>
			</div>
<?php endif; ?>
<?php if( !$t_can_push ): ?>
			<div class="alert alert-danger"><?php echo lang_get( 'repo_push_cannot_push' ) ?></div>
<?php endif; ?>

			<h5 class="bold"><?php echo lang_get( 'repo_push_before' ) ?></h5>
			<p class="small"><?php echo lang_get( 'repo_push_before_help' ) ?></p>
			<?php dwg_repo_push_commands( 'cmd-check', 'git --version' . ( $t_lfs ? "\ngit lfs version" : '' ) ); ?>

			<div class="space-10"></div>
			<?php dwg_repo_push_step( 1, 'repo_push_s1', lang_get( 'repo_push_s1_help' ) ); ?>
<?php if( $t_token !== '' ): ?>
			<div class="alert alert-danger">
				<strong><?php echo lang_get( 'repo_clone_token_once' ) ?></strong>
				<?php echo lang_get( 'repo_clone_token_scope' ) ?>
				<?php echo lang_get( 'repo_push_token_in_commands' ) ?>
			</div>
<?php elseif( $t_can_push ): ?>
<?php	if( $t_existing ): ?>
			<div class="alert alert-info"><?php echo lang_get( 'repo_clone_existing' ) ?></div>
<?php	endif; ?>
			<form method="post" action="dwg_repo_push_page.php">
				<?php echo form_security_field( 'dwg_repo_push' ) ?>
				<input type="hidden" name="project_id" value="<?php echo (int)$f_project_id ?>" />
				<button class="btn btn-primary btn-sm btn-white btn-round">
					<?php echo lang_get( 'repo_push_create_token' ) ?>
				</button>
<?php	if( $t_existing ): ?>
				<div class="space-6"></div>
				<label class="nopadding">
					<input type="checkbox" class="ace" name="revoke_existing" value="1" />
					<span class="lbl">&nbsp;<?php echo sprintf(
						lang_get( 'repo_clone_revoke_existing' ), count( $t_existing ) ) ?></span>
				</label>
<?php	endif; ?>
			</form>
<?php endif; ?>

			<div class="space-10"></div>
			<?php dwg_repo_push_step( 2, 'repo_push_s2', lang_get( 'repo_push_s2_help' ) ); ?>
<pre class="small">Git Bash (Windows):        cd /c/Projects/<?php echo string_html_specialchars( $t_example_dir ) ?>

Command Prompt (Windows):  cd /d C:\Projects\<?php echo string_html_specialchars( $t_example_dir ) ?>

macOS or Linux:            cd ~/Projects/<?php echo string_html_specialchars( $t_example_dir ) ?></pre>

			<div class="space-10"></div>
			<?php dwg_repo_push_step( 3, 'repo_push_s3', lang_get( 'repo_push_s3_help' ) ); ?>
			<?php dwg_repo_push_commands( 'cmd-init', "git status\ngit init" ); ?>

			<div class="space-10"></div>
			<?php dwg_repo_push_step( 4, 'repo_push_s4', lang_get( 'repo_push_s4_help' ) ); ?>
			<pre class="small"># .gitignore
History/
__Previews/
Project Logs for */
Project Outputs for */
*.bak</pre>

<?php
	$t_step = 5;
	if( $t_lfs ): ?>
			<?php dwg_repo_push_step( $t_step++, 'repo_push_s5', lang_get( 'repo_push_s5_help' ) ); ?>
			<?php dwg_repo_push_commands( 'cmd-lfs',
				"git lfs install\ngit lfs track \"*.PcbDoc\" \"*.SchDoc\" \"*.PcbLib\" \"*.SchLib\" \"*.step\"\ngit add .gitattributes" ); ?>
			<div class="space-10"></div>
<?php endif; ?>
			<?php dwg_repo_push_step( $t_step++, 'repo_push_s6', lang_get( 'repo_push_s6_help' ) ); ?>
			<?php dwg_repo_push_commands( 'cmd-commit', "git add -A\ngit commit -m \"Initial version\"\ngit log --oneline" ); ?>
<?php if( $t_lfs ): ?>
			<p class="small"><?php echo sprintf( lang_get( 'repo_push_lfs_migrate' ),
				'<code>git lfs migrate import --include="*.PcbDoc,*.SchDoc" --everything</code>' ) ?></p>
<?php endif; ?>

			<div class="space-10"></div>
			<?php dwg_repo_push_step( $t_step++, 'repo_push_s7', lang_get( 'repo_push_s7_help' ) ); ?>
			<?php dwg_repo_push_commands( 'cmd-push',
				"git remote add origin " . $t_push_url . "\ngit push -u origin --all\ngit push origin --tags" ); ?>
			<p class="small"><?php echo sprintf( lang_get( 'repo_push_has_origin' ),
				'<code>git remote set-url origin &hellip;</code>',
				'<code>git remote add doctis &hellip;</code>' ) ?></p>
			<p class="small"><?php echo sprintf( lang_get( 'repo_clone_in_config' ),
				'<code>git remote set-url origin ' . string_display_line( $t_clone_url ) . '</code>' ) ?></p>

			<div class="space-10"></div>
			<?php dwg_repo_push_step( $t_step++, 'repo_push_s8', lang_get( 'repo_push_s8_help' ) ); ?>
			<a href="dwg_repo_push_page.php?project_id=<?php echo (int)$f_project_id ?>"
				class="btn btn-default btn-sm btn-white btn-round">
				<?php print_icon( 'fa-refresh', 'ace-icon' ); ?>
				<?php echo lang_get( 'repo_push_reload' ) ?>
			</a>

			<div class="space-10"></div>
			<h5 class="bold"><?php echo lang_get( 'repo_push_next' ) ?></h5>
<?php if( $t_source_release ): ?>
			<p class="small"><?php echo lang_get( 'repo_push_next_source' ) ?></p>
			<a href="dwg_create_page.php?project_id=<?php echo (int)$f_project_id ?>"
				class="btn btn-default btn-sm btn-white btn-round"><?php echo lang_get( 'repo_push_next_source_button' ) ?></a>
<?php else: ?>
			<p class="small"><?php echo lang_get( 'repo_push_next_files' ) ?></p>
			<a href="dwg_repo_files_page.php?project_id=<?php echo (int)$f_project_id ?>"
				class="btn btn-default btn-sm btn-white btn-round"><?php echo lang_get( 'dwg_repo_files_title' ) ?></a>
<?php endif; ?>

			<hr />
			<h5 class="bold"><?php echo lang_get( 'repo_push_example' ) ?></h5>
			<p class="small"><?php echo lang_get( 'repo_push_example_help' ) ?></p>
<pre class="small">$ cd /c/Projects/<?php echo string_html_specialchars( $t_example_dir ) ?>

$ git init
Initialized empty Git repository in C:/Projects/<?php echo string_html_specialchars( $t_example_dir ) ?>/.git/

$ printf 'History/\n__Previews/\nProject Logs for */\nProject Outputs for */\n' > .gitignore
<?php if( $t_lfs ): ?>

$ git lfs install
Git LFS initialized.
$ git lfs track "*.PcbDoc" "*.SchDoc" "*.PcbLib" "*.SchLib"
Tracking "*.PcbDoc"
Tracking "*.SchDoc"
Tracking "*.PcbLib"
Tracking "*.SchLib"
<?php endif; ?>

$ git add -A
$ git commit -m "Initial version"
[master (root-commit) 1a2b3c4] Initial version
 7 files changed, 35 insertions(+)

$ git remote add origin <?php echo string_html_specialchars( $t_shown_url ) ?>

$ git push -u origin --all
<?php if( $t_lfs ): ?>
Uploading LFS objects: 100% (4/4), 18 MB | 2.1 MB/s, done.
<?php endif; ?>
remote: doctis: repository HEAD set to master
To <?php echo string_html_specialchars( $t_clone_url ) ?>

 * [new branch]      master -> master
branch 'master' set up to track 'origin/master'.

$ git push origin --tags
Everything up-to-date</pre>

			<h5 class="bold"><?php echo lang_get( 'repo_push_trouble' ) ?></h5>
			<table class="table table-bordered table-condensed small">
				<tr><th class="width-40"><?php echo lang_get( 'repo_push_trouble_message' ) ?></th>
					<th><?php echo lang_get( 'repo_push_trouble_fix' ) ?></th></tr>
<?php
	$t_trouble = array(
		'fatal: not a git repository'                            => 'repo_push_t_not_repo',
		'error: remote origin already exists.'                   => 'repo_push_t_origin',
		'error: src refspec … does not match any / No refs in common' => 'repo_push_t_no_commit',
		'Authentication failed / HTTP 401'                       => 'repo_push_t_auth',
		'HTTP 403'                                               => 'repo_push_t_forbidden',
		'! [rejected] … (fetch first)'                           => 'repo_push_t_rejected',
		'HTTP 413 / RPC failed / the remote end hung up'         => 'repo_push_t_too_big',
		'warning: current Git remote contains credentials'       => 'repo_push_t_credentials',
	);
	foreach( $t_trouble as $t_message => $t_key ): ?>
				<tr><td><code><?php echo string_html_specialchars( $t_message ) ?></code></td>
					<td><?php echo $t_key === 'repo_push_t_too_big'
						? sprintf( lang_get( $t_key ), string_html_specialchars( $t_push_limit ) )
						: lang_get( $t_key ) ?></td></tr>
<?php endforeach; ?>
			</table>

			<a href="manage_proj_edit_page.php?project_id=<?php echo (int)$f_project_id ?>#repository"
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
