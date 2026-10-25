<?php
# dwg_cut_release_page.php
# Form page — cut a release from a source *line*.
#
# A line document follows its branch and never freezes; a release is a
# document of its own, frozen at one commit, with its own revision, status,
# owner, approval cycle and issues.  This creates the release document and
# registers it, leaving the line to carry on.  The documents list then shows
# every past release as a row.  See doc/git/SOURCE_REPOSITORIES.md §8.3.
#
# GET renders the form; POST creates the release and redirects to it.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'dwg_api.php' );
require_api( 'file_dwg_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'history_dwg_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'project_api.php' );
require_api( 'repository_api.php' );
require_api( 'string_api.php' );

use Mantis\Exceptions\ClientException;

auth_ensure_user_authenticated();

$f_dwg_id = gpc_get_int( 'id' );

# Cutting a release settles what is on record, as promoting a Draft does.
access_ensure_dwg_level( MANAGER, $f_dwg_id );

$t_line = file_dwg_primary_get( $f_dwg_id );
if( $t_line === null || !dwg_is_source( $f_dwg_id ) || dwg_is_frozen( $f_dwg_id ) ) {
	# Only a tracking line has releases to cut.
	access_denied();
}

$t_project_id = (int)dwg_get_field( $f_dwg_id, 'project_id' );
$t_bare       = dwg_project_bare_repo_path( $t_project_id );
$t_branch     = (string)$t_line['git_branch'];

# Default to whatever the line is currently showing: its Draft if it has one,
# else the version it is on record at.
$t_info     = file_dwg_git_head_info( $f_dwg_id );
$t_tip      = ( $t_info !== null && $t_info['has_draft'] ) ? $t_info['sha'] : $t_line['git_sha'];
$t_line_doc = dwg_get( $f_dwg_id );

$t_error    = '';
$f_commit   = '';
$f_revision = '';
$f_title    = '';
$f_note     = '';

if( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	form_security_validate( 'dwg_cut_release' );

	$f_commit   = strtolower( trim( gpc_get_string( 'commit', '' ) ) );
	$f_revision = trim( gpc_get_string( 'revision', '' ) );
	$f_title    = trim( gpc_get_string( 'title', '' ) );
	$f_note     = trim( gpc_get_string( 'note', '' ) );

	$t_sha = $f_commit !== '' ? $f_commit : $t_tip;
	$t_sha = trim( (string)shell_exec(
		'git --git-dir=' . escapeshellarg( $t_bare ) . ' rev-parse --verify -q '
		. escapeshellarg( $t_sha . '^{commit}' ) . ' 2>/dev/null' ) );

	if( $f_revision === '' ) {
		$t_error = lang_get( 'dwg_cut_release_revision_required' );
	} elseif( !preg_match( '/^[0-9a-f]{40}$/', $t_sha ) ) {
		$t_error = lang_get( 'dwg_cut_release_bad_commit' );
	} elseif( $t_branch !== '' && !file_dwg_git_branch_exists( $t_bare, $t_branch ) ) {
		$t_error = lang_get( 'dwg_cut_release_branch_gone' );
	} else {
		# The release inherits the line's identity and ownership; only its
		# revision, title and recorded commit differ.
		$t_release = dwg_get( $f_dwg_id, true );
		$t_release->creator_id     = auth_get_current_user_id();
		$t_release->date_submitted = db_now();
		$t_release->last_updated   = db_now();
		$t_release->revision       = $f_revision;
		$t_release->summary        = $f_title !== '' ? $f_title
			: $t_line_doc->summary . ' ' . $f_revision;
		$t_release->title          = $t_release->summary;
		$t_release->sticky         = 0;

		$t_release_id = $t_release->create();
		dwg_set_field( $t_release_id, 'owner_id', (int)$t_line_doc->owner_id );
		dwg_set_field( $t_release_id, 'handler_id', (int)$t_line_doc->handler_id );
		dwg_set_field( $t_release_id, 'status', (int)$t_line_doc->status );

		try {
			file_dwg_primary_register(
				$t_release_id, auth_get_current_user_id(), (string)$t_line['git_path'], $t_sha,
				$f_note !== '' ? $f_note : $f_revision, true, '',
				array( 'allow_tree' => true, 'branch' => $t_branch, 'frozen' => true )
			);
		} catch( ClientException $e ) {
			# Leave no half-made release behind.
			dwg_delete( $t_release_id );
			throw $e;
		}

		# Both records say what happened, so the line's history shows the
		# releases cut from it and the release says where it came from.
		history_dwg_log_event_direct( $f_dwg_id, 'release_cut', '',
			$f_revision . ' (dwg ' . $t_release_id . ') @ ' . substr( $t_sha, 0, 8 ),
			auth_get_current_user_id() );
		history_dwg_log_event_direct( $t_release_id, 'release_cut_from', '',
			$t_line_doc->summary . ' (dwg ' . $f_dwg_id . ')',
			auth_get_current_user_id() );

		form_security_purge( 'dwg_cut_release' );
		print_header_redirect( 'dwg_view.php?id=' . $t_release_id );
	}
}

layout_page_header( lang_get( 'dwg_cut_release_title' ) );
layout_page_begin();
?>
<div class="col-md-8 col-md-offset-2 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title">
				<?php echo lang_get( 'dwg_cut_release_title' ) ?>
				&mdash; <?php echo string_display_line( $t_line_doc->summary ) ?>
			</h4>
		</div>
		<div class="widget-body">
			<div class="widget-main padding-16">

<?php if( $t_error !== '' ): ?>
				<div class="alert alert-danger"><?php echo string_display_line( $t_error ) ?></div>
<?php endif; ?>

				<p><?php echo lang_get( 'dwg_cut_release_intro' ) ?></p>

				<table class="table table-bordered table-condensed">
					<tr>
						<th class="category width-30"><?php echo lang_get( 'source_release_branch' ) ?></th>
						<td><code><?php echo string_display_line( $t_branch ) ?></code>
							&nbsp;<span class="small"><?php echo $t_line['git_path'] === ''
								? lang_get( 'source_release_whole_repository' )
								: string_display_line( $t_line['git_path'] ) ?></span></td>
					</tr>
					<tr>
						<th class="category"><?php echo lang_get( 'dwg_cut_release_at' ) ?></th>
						<td><code><?php echo htmlspecialchars( substr( $t_tip, 0, 8 ) ) ?></code>
							&nbsp;<span class="small"><?php echo ( $t_info !== null && $t_info['has_draft'] )
								? lang_get( 'dwg_cut_release_from_draft' )
								: lang_get( 'dwg_cut_release_from_record' ) ?></span></td>
					</tr>
				</table>

				<form method="post" action="dwg_cut_release_page.php">
					<?php echo form_security_field( 'dwg_cut_release' ) ?>
					<input type="hidden" name="id" value="<?php echo (int)$f_dwg_id ?>" />

					<div class="form-group">
						<label for="revision"><?php echo lang_get( 'dwg_cut_release_revision' ) ?></label>
						<input id="revision" type="text" name="revision" class="input-sm width-30"
							maxlength="64" placeholder="<?php echo lang_get( 'dwg_cut_release_revision_hint' ) ?>"
							value="<?php echo string_attribute( $f_revision ) ?>" autofocus />
					</div>
					<div class="form-group">
						<label for="title"><?php echo lang_get( 'dwg_cut_release_doc_title' ) ?></label>
						<input id="title" type="text" name="title" class="input-sm width-60"
							maxlength="255" placeholder="<?php echo string_attribute(
								$t_line_doc->summary . ' <revision>' ) ?>"
							value="<?php echo string_attribute( $f_title ) ?>" />
					</div>
					<div class="form-group">
						<label for="commit"><?php echo lang_get( 'source_release_commit' ) ?></label>
						<input id="commit" type="text" name="commit" class="input-sm width-40"
							maxlength="40" placeholder="<?php echo string_attribute( substr( $t_tip, 0, 8 ) ) ?>"
							value="<?php echo string_attribute( $f_commit ) ?>" />
					</div>
					<div class="form-group">
						<label for="note"><?php echo lang_get( 'source_release_note' ) ?></label>
						<input id="note" type="text" name="note" class="input-sm width-60"
							maxlength="255" value="<?php echo string_attribute( $f_note ) ?>" />
					</div>

					<div class="space-10"></div>
					<input type="submit" class="btn btn-primary btn-sm btn-white btn-round"
						value="<?php echo lang_get( 'dwg_cut_release_button' ) ?>" />
					&nbsp;
					<a href="dwg_view.php?id=<?php echo (int)$f_dwg_id ?>"
						class="btn btn-default btn-sm btn-white btn-round">
						<?php echo lang_get( 'primary_document_tag_cancel' ) ?>
					</a>
				</form>

			</div>
		</div>
	</div>
</div>
<?php
layout_page_end();
