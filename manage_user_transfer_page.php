<?php
# Doctis - Document Issue Tracking System, a fork of MantisBT

# Doctis is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 2 of the License, or
# (at your option) any later version.
#
# Doctis is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with MantisBT.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Transfer what a user still holds to a successor.
 *
 * Reached automatically after disabling an account that still holds
 * something, and from the Manage User page at any time. See
 * doc/dwg/USER_LIFECYCLE.md.
 *
 * @package Doctis
 *
 * @uses core.php
 * @uses access_api.php
 * @uses authentication_api.php
 * @uses config_api.php
 * @uses form_api.php
 * @uses gpc_api.php
 * @uses html_api.php
 * @uses lang_api.php
 * @uses print_api.php
 * @uses user_api.php
 * @uses user_transfer_api.php
 */

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'user_api.php' );
require_api( 'user_transfer_api.php' );

auth_reauthenticate();
access_ensure_global_level( config_get( 'manage_user_threshold' ) );

$f_user_id = gpc_get_int( 'user_id' );
user_ensure_exists( $f_user_id );

$t_user = user_get_row( $f_user_id );
$t_summary = user_transfer_summary( $f_user_id );
$t_suggested = user_transfer_suggest_successor( $f_user_id );
$t_categories = user_transfer_categories();

layout_page_header( lang_get( 'user_transfer_title' ) );
layout_page_begin( 'manage_overview_page.php' );
print_manage_menu();
?>

<div class="col-md-12 col-xs-12">
<div class="space-10"></div>

<div class="form-container">
<form method="post" action="manage_user_transfer.php">
<?php echo form_security_field( 'manage_user_transfer' ) ?>
<input type="hidden" name="user_id" value="<?php echo (int)$f_user_id ?>" />

<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-exchange', 'ace-icon' ); ?>
			<?php echo lang_get( 'user_transfer_title' ) ?>
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main no-padding">

<?php
	# Result of the transfer just performed, if we came back from the handler.
	$t_moved = gpc_get_int( 'moved', -1 );
	$t_skipped = gpc_get_int( 'skipped', 0 );
	if( $t_moved >= 0 ) {
?>
		<div class="alert alert-success">
			<?php printf( lang_get( 'user_transfer_result' ), $t_moved ); ?>
			<?php if( $t_skipped > 0 ) { ?>
				<br /><?php printf( lang_get( 'user_transfer_skipped' ), $t_skipped ); ?>
			<?php } ?>
		</div>
<?php } ?>

		<div class="alert alert-info">
			<?php printf( lang_get( $t_summary['total'] > 0
					? 'user_transfer_intro'
					: 'user_transfer_nothing' ),
				string_display_line( user_get_name( $f_user_id ) ) ); ?>
			<?php if( !user_is_enabled( $f_user_id ) ) { ?>
				<br /><?php echo lang_get( 'user_transfer_is_disabled' ) ?>
			<?php } else { ?>
				<br /><?php echo lang_get( 'user_transfer_still_enabled' ) ?>
			<?php } ?>
		</div>

<?php if( $t_summary['total'] > 0 ) { ?>
		<table class="table table-bordered table-condensed table-striped">
			<thead>
				<tr>
					<th class="category"><?php echo lang_get( 'user_transfer_holding' ) ?></th>
					<th><?php echo lang_get( 'user_transfer_count' ) ?></th>
					<th><?php echo lang_get( 'user_transfer_include' ) ?></th>
				</tr>
			</thead>
			<tbody>
<?php
	foreach( $t_categories as $t_key => $t_category ) {
		if( $t_summary[$t_key] == 0 ) {
			continue;
		}
?>
				<tr>
					<td class="category"><?php echo lang_get( $t_category['label'] ) ?></td>
					<td><?php echo (int)$t_summary[$t_key] ?></td>
					<td>
						<label>
							<input type="checkbox" class="ace input-sm"
								name="categories[]" checked="checked"
								value="<?php echo string_attribute( $t_key ) ?>" />
							<span class="lbl"></span>
						</label>
					</td>
				</tr>
<?php } ?>
				<tr>
					<td class="category">
						<label for="to_user_id"><?php echo lang_get( 'user_transfer_to' ) ?></label>
					</td>
					<td colspan="2">
						<select id="to_user_id" name="to_user_id" class="input-sm">
							<option value="0">&nbsp;</option>
							<?php print_user_option_list( $t_suggested ) ?>
						</select>
						<?php if( $t_suggested > 0 ) { ?>
							<span class="small">
								<?php printf( lang_get( 'user_transfer_suggested' ),
									string_display_line( user_get_name( $t_suggested ) ) ); ?>
							</span>
						<?php } else { ?>
							<span class="small"><?php echo lang_get( 'user_transfer_no_suggestion' ) ?></span>
						<?php } ?>
					</td>
				</tr>
			</tbody>
		</table>
<?php } ?>

	</div>
	</div>

	<div class="widget-toolbox padding-8 clearfix">
<?php if( $t_summary['total'] > 0 ) { ?>
		<button class="btn btn-primary btn-white btn-round">
			<?php echo lang_get( 'user_transfer_button' ) ?>
		</button>
<?php } ?>
		<a class="btn btn-primary btn-white btn-round"
			href="manage_user_edit_page.php?user_id=<?php echo (int)$f_user_id ?>">
			<?php echo lang_get( 'user_transfer_back' ) ?>
		</a>
	</div>
</div>

</form>
</div>
</div>

<?php
layout_page_end();
