<?php
# Doctis — "Repository" block of the Manage Project page
# (doc/git/SOURCE_REPOSITORIES.md, doc/git/GIT_ARCHITECTURE.md).
# Included from manage_proj_edit_page.php.
#
# One place for everything that decides what kind of project this is:
#   - which git repository its documents live in, its own or inherited;
#   - that repository's push policy, document control or source;
#   - whether source release records may be registered in this project.
#
# The first two are per *repository* and the third is per project, which is
# why they are shown together: a repository cannot be append-only for
# document control and rewritable for source development at the same time, so
# a source project needs its own repository.  §1.2 of SOURCE_REPOSITORIES.md.
#
# Expects: $f_project_id.

require_api( 'repository_api.php' );
require_api( 'file_dwg_api.php' );

$t_repo_id        = repository_id_for_project( $f_project_id );
$t_repo_own       = repository_id_linked_to_project( $f_project_id ) === $t_repo_id && $t_repo_id > 0;
$t_repo_row       = $t_repo_id > 0 ? repository_get_row( $t_repo_id ) : false;
$t_repo_owner     = $t_repo_row ? (int)$t_repo_row['owner_project_id'] : 0;
$t_repo_bare      = $t_repo_id > 0 ? repository_bare_path( $t_repo_id ) : '';
$t_repo_on_disk   = $t_repo_bare !== '' && is_dir( $t_repo_bare );
$t_repo_policy    = $t_repo_on_disk ? repository_push_policy( $t_repo_id ) : '';
$t_repo_sharers   = $t_repo_id > 0 ? count( repository_project_ids( $t_repo_id ) ) : 0;
$t_repo_releases  = file_dwg_source_release_enabled( $f_project_id );

# Relaxing history protection is an administrator's call, as it is on Manage
# → Git Store; enabling release records in a project is the project manager's.
$t_repo_can_policy = $t_repo_own && $t_repo_on_disk && access_has_global_level( ADMINISTRATOR );

# A project may be given its own repository only while nothing of its own is
# stored in the one it currently uses: moving existing documents between
# repositories is a separate operation (file_dwg_primary_move_prepare()).
db_param_push();
$t_repo_doc_count = (int)db_result( db_query(
	'SELECT COUNT(*) FROM {dwg_primary_file} f INNER JOIN {dwg} d ON d.id = f.dwg_id'
	. ' WHERE d.project_id=' . db_param(), array( (int)$f_project_id ) ) );
$t_repo_can_split = !$t_repo_own && $t_repo_doc_count === 0;
?>
<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div id="repository" class="form-container">
	<form method="post" action="manage_proj_repo_update.php">
		<?php echo form_security_field( 'manage_proj_repo_update' ) ?>
		<input type="hidden" name="project_id" value="<?php echo (int)$f_project_id ?>" />
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
						<a href="manage_git_store_page.php?repo=<?php echo urlencode( repository_basename( $t_repo_id ) ) ?>"><code><?php
							echo string_display_line( repository_basename( $t_repo_id ) ) ?></code></a>
<?php	if( $t_repo_own ): ?>
						&nbsp;<span class="label label-success"><?php echo lang_get( 'project_repository_own' ) ?></span>
<?php	else: ?>
						&nbsp;<span class="label label-default"><?php echo lang_get( 'project_repository_inherited' ) ?></span>
						<span class="small">&nbsp;<?php echo sprintf( lang_get( 'project_repository_from' ),
							'<a href="manage_proj_edit_page.php?project_id=' . $t_repo_owner . '">'
							. string_display_line( project_get_name( $t_repo_owner ) ) . '</a>' ) ?></span>
<?php	endif; ?>
<?php	if( $t_repo_sharers > 1 ): ?>
						<br /><span class="small"><?php echo sprintf(
							lang_get( 'project_repository_shared_by' ), $t_repo_sharers ) ?></span>
<?php	endif; ?>
<?php endif; ?>
					</td>
				</tr>

				<tr>
					<th class="category"><?php echo lang_get( 'push_policy_label' ) ?></th>
					<td>
<?php if( !$t_repo_on_disk ): ?>
						<span class="grey small"><?php echo lang_get( 'project_repository_policy_later' ) ?></span>
<?php elseif( $t_repo_can_policy ): ?>
						<select name="push_policy" class="input-sm">
							<option value="<?php echo DWG_PUSH_POLICY_DOCUMENT ?>"<?php
								echo $t_repo_policy === DWG_PUSH_POLICY_DOCUMENT ? ' selected="selected"' : '' ?>><?php
								echo lang_get( 'push_policy_document' ) ?></option>
							<option value="<?php echo DWG_PUSH_POLICY_SOURCE ?>"<?php
								echo $t_repo_policy === DWG_PUSH_POLICY_SOURCE ? ' selected="selected"' : '' ?>><?php
								echo lang_get( 'push_policy_source' ) ?></option>
						</select>
						<br /><span class="small"><?php echo lang_get( $t_repo_policy === DWG_PUSH_POLICY_SOURCE
							? 'push_policy_source_help' : 'push_policy_document_help' ) ?></span>
<?php	if( $t_repo_sharers > 1 ): ?>
						<br /><span class="small"><strong><?php echo sprintf(
							lang_get( 'project_repository_policy_shared_warning' ), $t_repo_sharers ) ?></strong></span>
<?php	endif; ?>
<?php else: ?>
						<?php echo lang_get( $t_repo_policy === DWG_PUSH_POLICY_SOURCE
							? 'push_policy_source' : 'push_policy_document' ) ?>
						<br /><span class="small"><?php echo $t_repo_own
							? lang_get( 'project_repository_policy_admin_only' )
							: sprintf( lang_get( 'project_repository_policy_on_owner' ),
								'<a href="manage_proj_edit_page.php?project_id=' . $t_repo_owner . '#repository">'
								. string_display_line( project_get_name( $t_repo_owner ) ) . '</a>' ) ?></span>
<?php endif; ?>
					</td>
				</tr>

				<tr>
					<th class="category"><?php echo lang_get( 'project_repository_releases_label' ) ?></th>
					<td>
						<label class="nopadding">
							<input type="checkbox" class="ace" name="source_release_enabled"
								<?php echo $t_repo_releases ? 'checked="checked"' : '' ?> />
							<span class="lbl">&nbsp;<?php echo lang_get( 'project_repository_releases_enable' ) ?></span>
						</label>
						<br /><span class="small"><?php echo lang_get( 'project_repository_releases_help' ) ?></span>
					</td>
				</tr>

				<tr>
					<th class="category"><?php echo lang_get( 'project_repository_separate' ) ?></th>
					<td>
<?php if( $t_repo_own ): ?>
						<span class="small"><?php echo lang_get( 'project_repository_already_own' ) ?></span>
<?php elseif( !$t_repo_can_split ): ?>
						<span class="small"><?php echo sprintf(
							lang_get( 'project_repository_has_documents' ), $t_repo_doc_count ) ?></span>
<?php else: ?>
						<button type="submit" name="action" value="create"
							class="btn btn-sm btn-white btn-round">
							<?php echo lang_get( 'project_repository_create_button' ) ?>
						</button>
						<br /><span class="small"><?php echo lang_get( 'project_repository_create_help' ) ?></span>
<?php endif; ?>
					</td>
				</tr>
			</table>
			</div>
			</div>
			<div class="widget-toolbox padding-8 clearfix">
				<button type="submit" name="action" value="update" class="btn btn-primary btn-white btn-round">
					<?php echo lang_get( 'project_repository_update_button' ) ?>
				</button>
			</div>
			</div>
		</div>
	</form>
	</div>
</div>
