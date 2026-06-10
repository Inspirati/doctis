<?php
# MantisBT - A PHP based bugtracking system

# MantisBT is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 2 of the License, or
# (at your option) any later version.
#
# MantisBT is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with MantisBT.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Edit Project Page
 *
 * @package MantisBT
 * @copyright Copyright 2000 - 2002  Kenzaburo Ito - kenito@300baud.org
 * @copyright Copyright 2002  MantisBT Team - mantisbt-dev@lists.sourceforge.net
 * @link http://www.mantisbt.org
 *
 * @uses core.php
 * @uses access_api.php
 * @uses authentication_api.php
 * @uses category_api.php
 * @uses config_api.php
 * @uses constant_inc.php
 * @uses current_user_api.php
 * @uses custom_field_api.php
 * @uses date_api.php
 * @uses event_api.php
 * @uses file_api.php
 * @uses form_api.php
 * @uses gpc_api.php
 * @uses helper_api.php
 * @uses html_api.php
 * @uses lang_api.php
 * @uses print_api.php
 * @uses project_api.php
 * @uses project_hierarchy_api.php
 * @uses string_api.php
 * @uses user_api.php
 * @uses utility_api.php
 * @uses version_api.php
 */

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'category_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'current_user_api.php' );
require_api( 'custom_field_api.php' );
require_api( 'date_api.php' );
require_api( 'event_api.php' );
require_api( 'file_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'project_api.php' );
require_api( 'license_api.php' );
// require_api( 'project_hierarchy_api.php' );
require_api( 'string_api.php' );
require_api( 'user_api.php' );
require_api( 'utility_api.php' );
require_api( 'version_api.php' );

auth_reauthenticate();

$f_project_id = gpc_get_int( 'project_id', ALL_PROJECTS );
$f_license_id = gpc_get_int( 'license_id', 0 );
$f_document_id = gpc_get_int( 'document_id', 0 );
$f_show_global_users = gpc_get_bool( 'show_global_users' );

// function mci_get_project_id( $p_project, $p_default = ALL_PROJECTS ) {
if( ALL_PROJECTS == $f_project_id ) {
	$f_project_id = helper_get_current_project();
	project_ensure_exists( $f_project_id );
}
// if( ALL_PROJECTS != $f_project_id) {
// 	project_ensure_exists( $f_project_id );
// }
license_ensure_exists( $f_license_id );
// $g_project_override = $f_project_id;
access_ensure_project_level( config_get( 'manage_project_threshold' ), $f_project_id );
access_ensure_license_level( config_get( 'manage_license_threshold' ), $f_license_id );

$t_row = license_get_row( $f_license_id );

// $t_can_manage_users = access_has_project_level( config_get( 'project_user_threshold' ), $f_project_id );
$t_can_manage_users = access_has_license_level( config_get( 'license_user_threshold' ), $f_license_id );

// require_js( 'manage_license_edit_page.js' );
require_js( 'manage_proj_edit_page.js' ); // the javascript for project page is identical to what it would need to be for licenses

layout_page_header( project_get_field( $f_project_id, 'name' ) );
layout_page_begin( 'manage_overview_page.php' );

print_manage_menu( 'manage_license_page.php' );
?>

<!-- LICENSE PROPERTIES -->
<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div id="manage-proj-update-div" class="form-container">
	<form id="manage-proj-update-form" method="post" action="manage_license_update.php">
<div class="widget-box widget-color-blue2">
<div class="widget-header widget-header-small">
<h4 class="widget-title lighter">
	<?php print_icon( 'fa-puzzle-piece', 'ace-icon' ); ?>
	<?php echo lang_get('edit_license_title') ?>
</h4>
</div>

<div class="widget-body">
<div class="widget-main no-padding">
	<div class="table-responsive">
		<fieldset>
			<?php echo form_security_field( 'manage_license_update' ) ?>
			<input type="hidden" name="license_id" value="<?php echo $f_license_id ?>" />
			<table class="table table-bordered table-condensed table-striped">
			<tr>
				<td class="category">
					<label for="project-name">
						<span class="required">*</span>
						<?php echo lang_get( 'license_name' ) ?>
					</label>
				</td>
				<td>
					<input type="text" id="project-name" name="name" required
						   class="input-sm" size="60" maxlength="128"
						   value="<?php echo string_attribute( $t_row['name'] ) ?>"
					/>
				</td>
			</tr>
			<tr>
				<td class="category">
					<label for="project-status">
						<?php echo lang_get( 'status' ) ?>
					</label>
				</td>
				<td>
					<select id="project-status" name="status" class="input-sm">
						<?php print_enum_string_option_list( 'license_status', (int)$t_row['status'] ) ?>
					</select>
				</td>
			</tr>
			<tr>
				<td class="category">
					<label for="project-enabled">
						<?php echo lang_get( 'enabled' ) ?>
					</label>
				</td>
				<td>
					<input type="checkbox" id="project-enabled" name="enabled" class="ace"
						<?php check_checked( (int)$t_row['enabled'], ON ); ?>
					/>
					<span class="lbl"></span>
				</td>
			</tr>
<?php /*
			<tr>
				<td class="category">
					<label for="project-inherit-global">
						<?php echo lang_get( 'inherit_global' ) ?>
					</label>
				</td>
				<td>
					<input type="checkbox" id="project-inherit-global" name="inherit_global" class="ace"
						<?php check_checked( (int)$t_row['inherit_global'], ON ); ?>
					/>
					<span class="lbl"></span>
				</td>
			</tr>
 */ ?>
			<tr>
				<td class="category">
					<label for="project-view-state">
						<?php echo lang_get( 'view_status' ) ?>
					</label>
				</td>
				<td>
					<select id="project-view-state" name="view_state" class="input-sm">
						<?php print_enum_string_option_list( 'license_view_state', (int)$t_row['view_state']) ?>
					</select>
				</td>
			</tr>
			<?php /*
			if( file_is_uploading_enabled() && DATABASE !== config_get( 'file_upload_method' ) ) {
				$t_file_path = $t_row['file_path'];
				# Don't reveal the absolute path to non-administrators for security reasons
				if( is_blank( $t_file_path ) && current_user_is_administrator() ) {
					$t_file_path = config_get_global( 'absolute_path_default_upload_folder' );
				}
				?>
				<tr>
					<td class="category">
						<label for="project-file-path">
							<?php echo lang_get( 'upload_file_path' ) ?>
						</label>
					</td>
					<td>
						<input type="text" id="project-file-path" name="file_path"
							   class="input-sm" size="60" maxlength="<?php echo DB_FIELD_SIZE_FILENAME ?>"
							   value="<?php echo string_attribute( $t_file_path ) ?>"
						/>
					</td>
				</tr><?php
			} */ ?>
			<tr>
				<td class="category">
					<label for="project-description">
						<?php echo lang_get( 'description' ) ?>
					</label>
				</td>
				<td>
					<?php # Newline after opening textarea tag is intentional, see #25839 ?>
					<textarea class="form-control" id="project-description" name="description" cols="70" rows="5">
<?php echo string_textarea( $t_row['description'] ) ?>
</textarea>
				</td>
			</tr>
			<?php event_signal( 'EVENT_MANAGE_LICENSE_UPDATE_FORM', array( $f_license_id ) ); ?>
			</table>
		</fieldset>
		</div>
		</div>
		</div>
		<div class="widget-toolbox padding-8 clearfix">
			<span class="required pull-right"> * <?php echo lang_get( 'required' ) ?></span>
			<button class="btn btn-primary btn-white btn-round">
				<?php echo lang_get( 'update_license_button' ) ?>
			</button>
<?php
	# You must have global permissions to delete projects
	if( access_has_global_level ( config_get( 'delete_license_threshold' ) ) ) {
?>
			<button class="btn btn-primary btn-white btn-round"
					formaction="manage_license_delete.php">
				<?php echo lang_get( 'delete_license_button' ) ?>
			</button>
<?php
	}
?>
		</div>
	</div>
	</form>
</div>
</div>

<?php
event_signal( 'EVENT_MANAGE_LICENSE_PAGE', array( $f_license_id ) );
?>

<!-- MANAGE LICENSES -->
<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
<?php /*
	<div id="license-users" class="alert alert-info">
		<div class="center bigger-110">
	<?php
	print_icon( 'fa-info-circle' );
	echo ' ';
	if( VS_PUBLIC == project_get_field( $f_project_id, 'view_state' ) ) {
		echo lang_get( 'public_project_msg' );
	} else {
		echo lang_get( 'private_project_msg' );
	} ?>
		</div>
	</div>
 */ ?>

	<div id="manage-license-users-div" class="form-container">
		<div class="widget-box widget-color-blue2">
			<div class="widget-header widget-header-small">
				<h4 class="widget-title lighter">
					<?php print_icon( 'fa-users', 'ace-icon' ); ?>
					<?php echo lang_get( 'manage_licenses_title' ); ?>
				</h4>
			</div>
			<div class="widget-body" id="manage-project-users-list">

				<div class="widget-toolbox padding-8 clearfix">
					<form id="manage-project-users-copy-form" method="post" action="manage_license_user_copy.php" class="form-inline">
						<fieldset>
							<?php echo form_security_field( 'manage_license_user_copy' ) ?>
							<input type="hidden" name="project_id" value="<?php echo $f_project_id ?>" />
							<!--suppress HtmlFormInputWithoutLabel -->
							<select name="other_project_id" class="input-sm" required>
								<option selected disabled value="">
									<?php echo '[', lang_get( 'select_project_button' ), ']' ?>
								</option>
								<?php print_project_option_list( null, false, $f_project_id ); ?>
							</select>
							<span class="form-inline">
								<button name="copy_from" class="btn btn-sm btn-primary btn-white btn-round" value="1">
									<?php echo lang_get( 'copy_licenses_from' ) ?>
								</button>
								<button name="copy_to" class="btn btn-sm btn-primary btn-white btn-round" value="1">
									<?php echo lang_get( 'copy_licenses_to' ) ?>
								</button>
							</span>
						</fieldset>
					</form>
				</div>
	<?php
	$t_users = project_get_all_user_rows( $f_project_id, ANYBODY, $f_show_global_users );
	$t_users_count = count( $t_users );

	if( $t_users_count > 0 ) {

		$t_user_ids = array();
		$t_sort = array();
		foreach ( $t_users as $t_ix => $t_user ) {
			$t_user_display_name = user_get_name_from_row( $t_user );
			$t_users[$t_ix]['display_name'] = $t_user_display_name;
			$t_user_ids[] = $t_user['id'];
			$t_sort[] = $t_user_display_name;
		}

		user_cache_array_rows( $t_user_ids );
		array_multisort( $t_sort, SORT_ASC, SORT_NATURAL | SORT_FLAG_CASE, $t_users );

		?>
				<div id="manage-project-users-form-toolbox" class="hidden widget-toolbox padding-8 clearfix">
					<div class="btn-toolbar">
						<div class="widget-toolbar no-border pull-left">
							<label>
								<input type="text" class="search input-sm"
									   placeholder="<?php echo lang_get( 'filter_button' ) ?>" />
							</label>
						</div>
						<div class="widget-toolbar pull-left">
							<label>
								<?php echo lang_get( 'show' ) ?>
								<input id="input-per-page" type="text" min="5" size="2" class="input-sm"
									   value="<?php echo config_get( 'default_limit_view' ) ?>"
								/>
							</label>
							<?php echo '(', lang_get( 'total' ), ': ',
								$t_users_count, ' ', lang_get( 'users_link' ), ')'
							?>
						</div>
						<div class="widget-toolbar pull-left">
<?php
		# Show users with global access button
		print_form_button(
			'manage_license_edit_page.php#license-users',
			lang_get( $f_show_global_users ? 'hide_global_users' : 'show_global_users' ),
			array(
				'project_id' => $f_project_id,
				'show_global_users' => !$f_show_global_users
			),
			OFF,
			'btn btn-sm btn-primary btn-white btn-round'
		);
?>
						</div>
						<div class="btn-group pull-right">
							<ul class="pagination small no-margin"></ul>
						</div>
					</div>
				</div>

				<div class="widget-main no-padding" >
					<form id="manage-project-users-form" method="post" action="manage_proj_user_update.php">
						<input type="hidden" name="project_id" value="<?php echo $f_project_id ?>" />
						<?php echo form_security_field( 'manage_proj_user_update' ) ?>
						<div class="table-responsive listjs-table">
							<table class="table table-striped table-bordered table-condensed">
								<thead>
									<tr>
										<th>
											<div class="sort" role="button" data-sort="key-name">
												<?php echo lang_get( 'username' ) ?>
											</div>
										</th>
										<th>
											<div class="sort" role="button" data-sort="key-email">
												<?php echo lang_get( 'email' ) ?>
											</div>
										</th>
										<th class="col-md-4">
											<div class="sort" role="button" data-sort="key-access">
												<?php echo lang_get( 'access_level' ) ?>
											</div>
										</th>
										<th>
											<?php echo lang_get( 'remove_link' ) ?>
										</th>
									</tr>
								</thead>
								<tbody class="list">
<?php
		# If including global users, fetch here all local user to later distinguish them
		$t_local_users = array();
		if( $f_show_global_users ) {
			$t_local_users = project_get_all_user_rows( $f_project_id, ANYBODY, false );
		}

		foreach( $t_users as $t_user ) {
			$t_username =  $t_user['display_name'];
			$t_email = user_get_email( $t_user['id'] );
			$t_can_manage_this_user = $t_can_manage_users
					&& access_has_project_level( $t_user['access_level'], $f_project_id )
					&& ( !$f_show_global_users || isset( $t_local_users[$t_user['id']]) );
?>
		<tr>
			<td class="key-name" data-sortvalue="<?php echo string_attribute( $t_username ) ?>">
				<a href="manage_user_edit_page.php?user_id=<?php echo $t_user['id'] ?>">
				<?php echo prepare_user_name( $t_user['id'], false ); ?>
				</a>
			</td>
			<td class="key-email" data-sortvalue="<?php echo string_attribute( $t_email ) ?>">
				<?php print_email_link( $t_email, $t_email ); ?>
			</td>
			<?php
			$t_current_level_string = get_enum_element( 'access_levels', $t_user['access_level'] );
			?>
			<td class="key-access" data-sortvalue="<?php echo $t_current_level_string ?>">
				<?php
				if( $t_can_manage_this_user ) {
					echo '<div class="editable_access_level">';
					echo "<span>$t_current_level_string</span>";
					echo '<span class="hidden unchanged">';
					echo '&nbsp;<a href="#" class="edit_link">[' . lang_get( 'edit' ) . ']</a>';
					echo '</span>';
					$t_arrow = layout_is_rtl() ? 'fa-long-arrow-left' : 'fa-long-arrow-right';
					echo ' <span class="changed_to">';
					print_icon( $t_arrow, 'fa-lg' );
					echo '</span>';
					echo '<select name="user_access_level[' . $t_user['id'] . ']" class="input-xs user_access_level"'
							. ' data-original_val="' . $t_user['access_level'] . '" data-user_id="' . $t_user['id'] . '">';
					# only access levels that are less than or equal current user access level for current project
					print_project_access_levels_option_list( (int)$t_user['access_level'], $f_project_id );
					echo '</select>';
					echo '</div>';
				} else {
					echo $t_current_level_string;
				}
				?>
			</td>
			<td class="center">
				<?php
				# You need global or project-specific permissions to remove users
				#  from this project
				if( $t_can_manage_this_user ) {
					?>
					<div class="checkbox no-padding no-margin editable_user_delete">
						<label>
							<input type="checkbox" name="user_access_delete[]"
								   class="ace user_access_delete"
								   value="<?php echo $t_user['id'] ?>"
							/>
							<span class="lbl"></span>
						</label>
					</div>
					<?php
				}
				?>
			</td>
		</tr>
		<?php
		}  # end for
		?>
								</tbody>
							</table>
						</div>

						<div class="widget-toolbox padding-8 clearfix">
							<div class="form-inline pull-left">
								<button name="submit-apply" class="btn btn-primary btn-white btn-round">
									<?php echo lang_get( 'apply_changes' ) ?>
								</button>
							</div>
							<div class="form-inline pull-right">
								<?php echo form_security_field( 'manage_proj_user_remove' ) ?>
								<button name="btn-remove-all"
									    class="btn btn-primary btn-white btn-round"
									    formaction="manage_proj_user_remove.php">
									<?php echo lang_get( 'remove_all_link' ) ?>
								</button>
								<button name="btn-undo-remove-all" class="hidden btn btn-primary btn-white btn-round">
									<?php echo lang_get( 'undo' ). ': ', lang_get( 'remove_all_link' ) ?>
								</button>
							</div>
						</div>
					</form>
				</div>
<?php
	} // end if user count > 0
?>
			</div>
		</div>
	</div>
</div>

<!-- ADD USER TO LICENSE -->
<?php
# We want to allow people with global permissions and people with high enough
#  permissions on the project we are editing
if( $t_can_manage_users ) {

$t_user_id = auth_get_current_user_id();

$t_users = user_get_unassigned_by_license_id( $f_license_id, $t_user_id, $f_project_id );
if( count( $t_users ) > 0 ) { ?>
<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="form-container">
	<form id="manage-project-add-user-form" method="post" action="manage_license_user_add.php">
	<div class="widget-box widget-color-blue2">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter">
				<?php print_icon( 'fa-user', 'ace-icon' ); ?>
				<?php echo lang_get( 'add_user_license_title' ); ?>
			</h4>
		</div>
		<div class="widget-body">
		<div class="widget-main no-padding">
		<div class="table-responsive">
        <fieldset>
		<table class="table table-bordered table-condensed table-striped">
			<?php echo form_security_field( 'manage_license_user_add' ) ?>
			<input type="hidden" name="project_id" value="<?php echo $f_project_id ?>" />
			<input type="hidden" name="license_id" value="<?php echo $f_license_id ?>" />
			<tr>
				<td class="category">
					<label for="project-add-users-username">
						<span class="required">*</span>
						<?php echo lang_get( 'username' ) ?>
					</label>
				</td>
				<td>
					<select id="project-add-users-username" name="user_id[]"
							class="input-sm" multiple="multiple" size="10" required>
						<?php
						foreach( $t_users AS $t_user_id=>$t_display_name ) {
							echo '<option value="', $t_user_id, '">', string_attribute( $t_display_name ), '</option>';
						}
						?>
					</select>
				</td>
			</tr>
<?php /*
			<tr>
				<td class="category">
					<label for="project-add-users-access-level">
						<?php echo lang_get( 'access_level' ) ?>
					</label>
				</td>
				<td>
					<select id="project-add-users-access-level" name="access_level" class="input-sm">
						<?php
						# only access levels that are less than or equal current user access level for current project
						print_project_access_levels_option_list(
							config_get( 'default_new_account_access_level' ),
							$f_project_id
						);
						?>
					</select>
				</td>
			</tr>
 */ ?>
		</table>
        </fieldset>
		</div>
		</div>
		</div>
			<div class="widget-toolbox padding-8 clearfix">
				<span class="required pull-right"> * <?php echo lang_get( 'required' ) ?></span>
				<button class="btn btn-primary btn-white btn-round">
					<?php echo lang_get( 'add_user_button' ) ?>
				</button>
			</div>
		</div>
	</form>
	</div>
</div>
<?php
	}
}
?>

<!-- ADD DWG TO LICENSE -->
<?php
# We want to allow people with global permissions and people with high enough
#  permissions on the project we are editing
if( $t_can_manage_users ) {
$t_documents = document_get_unassigned_by_license_id( $f_license_id, $f_project_id );
// $t_documents = document_get_unassigned_by_license_id( $f_license_id, $f_document_id, $f_project_id );
if( true /*count( $t_documents ) > 0*/ ) { ?>
<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="form-container">
	<form id="manage-project-add-user-form" method="post" action="manage_license_document_add.php">
	<div class="widget-box widget-color-blue2">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter">
				<?php print_icon( 'fa-user', 'ace-icon' ); ?>
				<?php echo lang_get( 'add_document_license_title' ); ?>
			</h4>
		</div>
		<div class="widget-body">
		<div class="widget-main no-padding">
		<div class="table-responsive">
        <fieldset>
		<table class="table table-bordered table-condensed table-striped">
			<?php echo form_security_field( 'manage_license_document_add' ) ?>
			<input type="hidden" name="project_id" value="<?php echo $f_project_id ?>" />
			<input type="hidden" name="license_id" value="<?php echo $f_license_id ?>" />
			<tr>
				<td class="category">
					<label for="project-add-users-username">
						<span class="required">*</span>
						<?php echo lang_get( 'document' ) ?>
					</label>
				</td>
				<td>
					<select id="project-add-users-username" name="document_id[]"
							class="input-sm" multiple="multiple" size="10" required>
						<?php
						foreach( $t_documents AS $t_document_id=>$t_display_name ) {
						// foreach( $t_documents AS $t_document_id ) {
							// $t_display_name = document_full_title( $t_document_id, false );
// function document_full_title( $p_document_id, $p_show_project = true, $p_current_project = null );

							echo '<option value="', $t_document_id, '">', string_attribute( $t_display_name ), '</option>';
						}
						?>
					</select>
				</td>
			</tr>
		</table>
        </fieldset>
		</div>
		</div>
		</div>
			<div class="widget-toolbox padding-8 clearfix">
				<span class="required pull-right"> * <?php echo lang_get( 'required' ) ?></span>
				<button class="btn btn-primary btn-white btn-round">
					<?php echo lang_get( 'add_document_button' ) ?>
				</button>
			</div>
		</div>
	</form>
	</div>
</div>
<?php
	}
}
?>

<?php
layout_page_end();
