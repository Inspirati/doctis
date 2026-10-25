<?php
# dwg_source_release_page.php
# Form page — register the project repository, or one subtree of it, as this
# document's primary content, so that the document records a *release* of a
# source repository rather than a single file.  GET renders the form; POST
# registers and redirects back to the document view page.
#
# See doc/git/SOURCE_REPOSITORIES.md.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'dwg_api.php' );
require_api( 'file_dwg_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'project_api.php' );
require_api( 'repository_api.php' );
require_api( 'string_api.php' );

use Mantis\Exceptions\ClientException;

auth_ensure_user_authenticated();

$f_dwg_id = gpc_get_int( 'id' );

access_ensure_dwg_level( config_get( 'update_dwg_threshold' ), $f_dwg_id );

$t_project_id = (int)dwg_get_field( $f_dwg_id, 'project_id' );

if( !file_dwg_source_release_enabled( $t_project_id ) ) {
	access_denied();
}

$t_bare = dwg_project_bare_repo_path( $t_project_id );
if( $t_bare === '' || !is_dir( $t_bare ) ) {
	error_parameters( $t_project_id );
	trigger_error( ERROR_FILE_NOT_FOUND, ERROR );
}

# Replacing an existing registration would retire whatever is on record, so
# the page registers only onto a document that has nothing yet.
$t_existing = file_dwg_primary_get( $f_dwg_id );

# ── Branches available to release from ─────────────────────────────────────

$t_branches = array_filter( array_map( 'trim', explode( "\n", (string)shell_exec(
	'git --git-dir=' . escapeshellarg( $t_bare )
	. ' for-each-ref ' . escapeshellarg( '--format=%(refname:short)' ) . ' refs/heads 2>/dev/null'
) ) ) );

$t_default_branch = trim( (string)shell_exec(
	'git --git-dir=' . escapeshellarg( $t_bare ) . ' symbolic-ref --short HEAD 2>/dev/null'
) );

# ── Handle POST (register the release) ─────────────────────────────────────

$t_error    = '';
$f_path     = '';
$f_branch   = $t_default_branch;
$f_commit   = '';
$f_revision = '';

if( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	form_security_validate( 'dwg_source_release' );

	$f_path     = trim( gpc_get_string( 'git_path', '' ) );
	$f_branch   = trim( gpc_get_string( 'branch', $t_default_branch ) );
	$f_commit   = strtolower( trim( gpc_get_string( 'commit', '' ) ) );
	$f_revision = trim( gpc_get_string( 'description', '' ) );

	if( $t_existing !== null ) {
		$t_error = 'This document already has a primary document registered.';
	} elseif( $f_branch !== '' && !in_array( $f_branch, $t_branches, true ) ) {
		$t_error = 'Unknown branch: ' . $f_branch;
	} else {
		# An explicit commit wins; otherwise release the tip of the branch.
		$t_rev = $f_commit !== '' ? $f_commit : $f_branch;
		$t_sha = trim( (string)shell_exec(
			'git --git-dir=' . escapeshellarg( $t_bare )
			. ' rev-parse --verify ' . escapeshellarg( $t_rev . '^{commit}' ) . ' 2>/dev/null'
		) );

		if( !preg_match( '/^[0-9a-f]{40}$/', $t_sha ) ) {
			$t_error = 'Cannot resolve "' . $t_rev . '" to a commit in this repository.';
		} else {
			try {
				file_dwg_primary_register(
					$f_dwg_id, auth_get_current_user_id(), $f_path, $t_sha,
					$f_revision, true, '',
					array( 'allow_tree' => true, 'branch' => $f_branch )
				);
				form_security_purge( 'dwg_source_release' );
				print_header_redirect( 'dwg_view.php?id=' . $f_dwg_id );
			} catch( ClientException $e ) {
				$t_error = $e->getMessage();
			}
		}
	}
}

# ── Display values ─────────────────────────────────────────────────────────

$t_dwg_title = dwg_get_field( $f_dwg_id, 'summary' );
$t_repo_id   = repository_id_for_project( $t_project_id );
$t_repo      = $t_repo_id > 0 ? repository_get_row( $t_repo_id ) : false;
$t_policy    = repository_get_push_policy( $t_bare );

$t_clone_url = '';
if( $t_repo && ON == config_get_global( 'git_http_enabled', OFF ) ) {
	$t_url = parse_url( config_get_global( 'path' ) );
	if( isset( $t_url['scheme'], $t_url['host'] ) ) {
		$t_clone_url = $t_url['scheme'] . '://' . $t_url['host']
			. ( isset( $t_url['port'] ) ? ':' . $t_url['port'] : '' )
			. '/git/' . repository_basename( $t_repo_id ) . '.git';
	}
}

layout_page_header( lang_get( 'source_release_page_title' ) );
layout_page_begin();
?>
<div class="col-md-8 col-md-offset-2 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title">
				<?php echo lang_get( 'source_release_page_title' ) ?>
				&mdash; Document <?php echo (int)$f_dwg_id ?>:
				<?php echo string_display_line( $t_dwg_title ) ?>
			</h4>
		</div>
		<div class="widget-body">
			<div class="widget-main padding-16">

<?php if( $t_error !== '' ): ?>
				<div class="alert alert-danger"><?php echo htmlspecialchars( $t_error ) ?></div>
<?php endif; ?>

				<p><?php echo lang_get( 'source_release_intro' ) ?></p>

				<table class="table table-bordered table-condensed">
					<tr>
						<th class="category width-30"><?php echo lang_get( 'source_release_repository' ) ?></th>
						<td>
							<code><?php echo htmlspecialchars( $t_repo ? $t_repo['name'] : basename( $t_bare ) ) ?></code>
<?php if( $t_clone_url !== '' ): ?>
							<br /><span class="small"><?php echo lang_get( 'source_release_push_url' ) ?>:
								<code><?php echo htmlspecialchars( $t_clone_url ) ?></code></span>
<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th class="category"><?php echo lang_get( 'source_release_push_policy' ) ?></th>
						<td>
<?php if( $t_policy === DWG_PUSH_POLICY_SOURCE ): ?>
							<span class="label label-success"><?php echo lang_get( 'push_policy_source' ) ?></span>
<?php else: ?>
							<span class="label label-warning"><?php echo lang_get( 'push_policy_document' ) ?></span>
							<span class="small">&nbsp;<?php echo lang_get( 'source_release_policy_hint' ) ?></span>
<?php endif; ?>
						</td>
					</tr>
				</table>

<?php if( $t_existing !== null ): ?>
				<div class="alert alert-warning">
					<?php echo lang_get( 'source_release_already_registered' ) ?>
				</div>
				<a href="dwg_view.php?id=<?php echo (int)$f_dwg_id ?>"
					class="btn btn-default btn-sm btn-white btn-round">
					<?php echo lang_get( 'primary_document_tag_cancel' ) ?>
				</a>
<?php else: ?>
				<form method="post" action="dwg_source_release_page.php">
					<?php echo form_security_field( 'dwg_source_release' ) ?>
					<input type="hidden" name="id" value="<?php echo (int)$f_dwg_id ?>" />

					<div class="form-group">
						<label for="branch"><?php echo lang_get( 'source_release_branch' ) ?></label>
						<select id="branch" name="branch" class="input-sm">
<?php foreach( $t_branches as $t_branch ): ?>
							<option value="<?php echo string_attribute( $t_branch ) ?>"
								<?php echo $t_branch === $f_branch ? 'selected="selected"' : '' ?>><?php
								echo string_display_line( $t_branch ) ?></option>
<?php endforeach; ?>
						</select>
					</div>

					<div class="form-group">
						<label for="commit"><?php echo lang_get( 'source_release_commit' ) ?></label>
						<input id="commit" type="text" name="commit" class="input-sm width-40"
							maxlength="40" placeholder="<?php echo lang_get( 'source_release_commit_hint' ) ?>"
							value="<?php echo string_attribute( $f_commit ) ?>" />
					</div>

					<div class="form-group">
						<label for="git_path"><?php echo lang_get( 'source_release_subtree' ) ?></label>
						<input id="git_path" type="text" name="git_path" class="input-sm width-40"
							maxlength="1024" placeholder="<?php echo lang_get( 'source_release_subtree_hint' ) ?>"
							value="<?php echo string_attribute( $f_path ) ?>" />
					</div>

					<div class="form-group">
						<label for="description"><?php echo lang_get( 'source_release_note' ) ?></label>
						<input id="description" type="text" name="description" class="input-sm width-60"
							maxlength="255" value="<?php echo string_attribute( $f_revision ) ?>" />
					</div>

					<div class="space-10"></div>
					<input type="submit" class="btn btn-primary btn-sm btn-white btn-round"
						value="<?php echo lang_get( 'source_release_register' ) ?>" />
					&nbsp;
					<a href="dwg_view.php?id=<?php echo (int)$f_dwg_id ?>"
						class="btn btn-default btn-sm btn-white btn-round">
						<?php echo lang_get( 'primary_document_tag_cancel' ) ?>
					</a>
				</form>
<?php endif; ?>

			</div>
		</div>
	</div>
</div>
<?php
layout_page_end();
