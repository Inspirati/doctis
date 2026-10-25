<?php
# Doctis — "Repository" block of the Manage Project page
# (doc/git/SOURCE_REPOSITORIES.md, doc/git/GIT_ARCHITECTURE.md).
# Included from manage_proj_edit_page.php.
#
# Shows which git repository this project's documents live in, and whether it
# is the project's own or inherited from an ancestor.  A project tree shares
# one repository by default; this is where a sub-project is given its own.
#
# That matters because the push policy and the source-release setting are
# per *repository*: a repository cannot be append-only for documents and
# rewritable for source development at the same time.  Separating a source
# sub-project into its own repository is how a project tree gets both.
#
# Expects: $f_project_id.

require_api( 'repository_api.php' );
require_api( 'file_dwg_api.php' );

$t_repo_id       = repository_id_for_project( $f_project_id );
$t_repo_own_id   = repository_id_linked_to_project( $f_project_id );
$t_repo_row      = $t_repo_id > 0 ? repository_get_row( $t_repo_id ) : false;
$t_repo_inherited = $t_repo_id > 0 && $t_repo_own_id !== $t_repo_id;
$t_repo_owner    = $t_repo_row ? (int)$t_repo_row['owner_project_id'] : 0;
$t_repo_bare     = $t_repo_id > 0 ? repository_bare_path( $t_repo_id ) : '';
$t_repo_policy   = ( $t_repo_bare !== '' && is_dir( $t_repo_bare ) )
	? repository_get_push_policy( $t_repo_bare ) : '';

# A project may be given its own repository only while nothing of its own is
# stored in the one it currently uses: moving existing documents between
# repositories is a separate operation (file_dwg_primary_move_prepare()).
db_param_push();
$t_repo_doc_count = (int)db_result( db_query(
	'SELECT COUNT(*) FROM {dwg_primary_file} f INNER JOIN {dwg} d ON d.id = f.dwg_id'
	. ' WHERE d.project_id=' . db_param(), array( (int)$f_project_id ) ) );
$t_repo_can_split = $t_repo_own_id === 0 && $t_repo_doc_count === 0;
?>
<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div id="repository" class="form-container">
		<div class="widget-box widget-color-blue2">
			<div class="widget-header widget-header-small">
				<h4 class="widget-title lighter">
					<?php print_icon( 'fa-archive', 'ace-icon' ); ?>
					<?php echo lang_get( 'project_repository_section' ) ?>
				</h4>
			</div>
			<div class="widget-body">
			<div class="widget-main no-padding">
			<div class="table-responsive">
			<table class="table table-bordered table-condensed table-striped">
				<tr>
					<th class="category width-30"><?php echo lang_get( 'project_repository' ) ?></th>
					<td>
<?php if( $t_repo_id === 0 ): ?>
						<span class="grey"><?php echo lang_get( 'project_repository_none' ) ?></span>
<?php else: ?>
						<code><?php echo string_display_line( repository_basename( $t_repo_id ) ) ?></code>
<?php	if( $t_repo_inherited ): ?>
						&nbsp;<span class="label label-default"><?php echo lang_get( 'project_repository_inherited' ) ?></span>
						<span class="small">&nbsp;<?php echo sprintf( lang_get( 'project_repository_from' ),
							'<a href="manage_proj_edit_page.php?project_id=' . $t_repo_owner . '">'
							. string_display_line( project_get_name( $t_repo_owner ) ) . '</a>' ) ?></span>
<?php	else: ?>
						&nbsp;<span class="label label-success"><?php echo lang_get( 'project_repository_own' ) ?></span>
<?php	endif; ?>
<?php	if( $t_repo_policy !== '' ): ?>
						<br /><span class="small"><?php echo lang_get( 'push_policy_label' ) ?>:
							<?php echo lang_get( $t_repo_policy === DWG_PUSH_POLICY_SOURCE
								? 'push_policy_source' : 'push_policy_document' ) ?>
							&mdash; <a href="manage_git_store_page.php?repo=<?php
								echo urlencode( repository_basename( $t_repo_id ) ) ?>"><?php
								echo lang_get( 'project_repository_manage' ) ?></a></span>
<?php	endif; ?>
<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th class="category"><?php echo lang_get( 'project_repository_separate' ) ?></th>
					<td>
<?php if( $t_repo_own_id > 0 ): ?>
						<span class="small"><?php echo lang_get( 'project_repository_already_own' ) ?></span>
<?php elseif( !$t_repo_can_split ): ?>
						<span class="small"><?php echo sprintf(
							lang_get( 'project_repository_has_documents' ), $t_repo_doc_count ) ?></span>
<?php else: ?>
						<form method="post" action="manage_proj_repo_create.php" style="display:inline">
							<?php echo form_security_field( 'manage_proj_repo_create' ) ?>
							<input type="hidden" name="project_id" value="<?php echo (int)$f_project_id ?>" />
							<button class="btn btn-primary btn-sm btn-white btn-round">
								<?php echo lang_get( 'project_repository_create_button' ) ?>
							</button>
							&nbsp;<span class="small"><?php echo lang_get( 'project_repository_create_help' ) ?></span>
						</form>
<?php endif; ?>
					</td>
				</tr>
			</table>
			</div>
			</div>
			</div>
		</div>
	</div>
</div>
