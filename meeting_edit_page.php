<?php
# Doctis — change a meeting (reschedule, location, minute taker, invitees).
# Chair or organiser only, while only the agenda has been issued.
# Submits to meeting_edit.php.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'layout_api.php' );
require_api( 'meeting_api.php' );
require_api( 'string_api.php' );

auth_ensure_user_authenticated();

$f_meeting_id = gpc_get_int( 'id' );
$t_meeting = meeting_get( $f_meeting_id );
if( $t_meeting === null || !meeting_user_can_manage( $t_meeting, auth_get_current_user_id() ) ) {
	access_denied();
}

$t_invitees = meeting_invitees_get( $f_meeting_id );
$t_invited_ids = array_map( 'intval', array_column( $t_invitees, 'user_id' ) );

# Users who accept invitations and are not yet invited.
$t_candidates = array();
$t_result = db_query( 'SELECT id FROM {user} WHERE meeting_invite != 0 AND enabled = 1 ORDER BY realname, username' );
while( ( $t_row = db_fetch_array( $t_result ) ) !== false ) {
	if( !in_array( (int)$t_row['id'], $t_invited_ids, true ) ) {
		$t_candidates[] = (int)$t_row['id'];
	}
}

layout_page_header( lang_get( 'meeting_edit_button' ) . ': ' . $t_meeting['doc_ref'] );
layout_page_begin( 'my_view_page.php', true );
print_my_view_menu( 'my_view_meeting_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<form method="post" action="meeting_edit.php">
	<?php echo form_security_field( 'meeting_edit' ) ?>
	<input type="hidden" name="meeting_id" value="<?php echo (int)$f_meeting_id ?>" />
	<div class="widget-box widget-color-blue2">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter">
				<?php print_icon( 'fa-edit', 'ace-icon' ); ?>
				<?php echo lang_get( 'meeting_edit_button' ) ?>:
				<?php echo string_display_line( $t_meeting['doc_ref'] ) ?> &mdash; <?php echo string_display_line( $t_meeting['title'] ) ?>
			</h4>
		</div>
		<div class="widget-body">
			<div class="widget-main no-padding">
				<p class="text-muted" style="padding:8px 8px 0"><?php echo lang_get( 'meeting_edit_hint' ) ?></p>
				<table class="table table-bordered table-condensed">
					<tr>
						<th class="category width-25"><label for="meeting_date"><?php echo lang_get( 'meeting_date' ) ?></label></th>
						<td>
							<input type="date" id="meeting_date" name="date" class="input-sm" required
								value="<?php echo date( 'Y-m-d', (int)$t_meeting['date_start'] ) ?>" />
							<label for="meeting_time" class="sr-only"><?php echo lang_get( 'meeting_time' ) ?></label>
							<input type="time" id="meeting_time" name="time" class="input-sm" required
								value="<?php echo date( 'H:i', (int)$t_meeting['date_start'] ) ?>" />
							<span class="text-muted small"><?php echo string_display_line( date_default_timezone_get() ) ?></span>
						</td>
					</tr>
					<tr>
						<th class="category"><label for="meeting_duration"><?php echo lang_get( 'meeting_duration' ) ?></label></th>
						<td><input type="number" id="meeting_duration" name="duration" class="input-sm" min="5" max="600" required
							value="<?php echo (int)$t_meeting['duration'] ?>" /></td>
					</tr>
					<tr>
						<th class="category"><label for="meeting_location"><?php echo lang_get( 'meeting_location' ) ?></label></th>
						<td><input type="text" id="meeting_location" name="location" class="input-sm" size="50" maxlength="128"
							value="<?php echo string_attribute( $t_meeting['location'] ) ?>" /></td>
					</tr>
					<tr>
						<th class="category"><label for="meeting_minute_taker"><?php echo lang_get( 'meeting_role_minute_taker' ) ?></label></th>
						<td>
							<select id="meeting_minute_taker" name="minute_taker_id" class="input-sm">
								<option value="0"><?php echo lang_get( 'meeting_none_option' ) ?></option>
<?php foreach( $t_invitees as $t_invitee ):
		if( (int)$t_invitee['user_id'] <= 0 ) continue; ?>
								<option value="<?php echo (int)$t_invitee['user_id'] ?>"<?php
									echo (int)$t_invitee['user_id'] === (int)$t_meeting['minute_taker_id'] ? ' selected="selected"' : '' ?>><?php
									echo string_display_line( $t_invitee['name'] ) ?></option>
<?php endforeach; ?>
							</select>
							<span class="text-muted small"><?php echo lang_get( 'meeting_minute_taker_hint' ) ?></span>
						</td>
					</tr>
					<tr>
						<th class="category"><?php echo lang_get( 'meeting_keep_invitees' ) ?></th>
						<td>
<?php foreach( $t_invitees as $t_invitee ): ?>
							<label class="block">
								<input type="checkbox" class="ace" name="keep[]" value="<?php echo (int)$t_invitee['id'] ?>" checked="checked" />
								<span class="lbl"> <?php echo string_display_line( $t_invitee['name'] ) ?>
								<?php if( (int)$t_invitee['user_id'] === 0 ) echo '<span class="text-muted">(' . lang_get( 'meeting_guest' ) . ')</span>' ?></span>
							</label>
<?php endforeach; ?>
<?php if( empty( $t_invitees ) ): ?>
							<span class="text-muted"><?php echo lang_get( 'meeting_none_option' ) ?></span>
<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th class="category"><label for="meeting_add_users"><?php echo lang_get( 'meeting_add_invitees' ) ?></label></th>
						<td>
<?php if( empty( $t_candidates ) ): ?>
							<span class="text-muted"><?php echo lang_get( 'meeting_no_candidates' ) ?></span>
<?php else: ?>
							<select id="meeting_add_users" name="add_user_ids[]" multiple="multiple" size="<?php echo min( 8, count( $t_candidates ) ) ?>" class="input-sm">
<?php	foreach( $t_candidates as $t_uid ):
			$t_title = (string)user_get_field( $t_uid, 'position_title' ); ?>
								<option value="<?php echo $t_uid ?>"><?php echo string_display_line( meeting_user_display_name( $t_uid )
									. ( is_blank( $t_title ) ? '' : ' — ' . $t_title ) ) ?></option>
<?php	endforeach; ?>
							</select>
<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th class="category"><label for="meeting_guests"><?php echo lang_get( 'meeting_add_guests' ) ?></label></th>
						<td><textarea id="meeting_guests" name="guests" rows="2" cols="50" class="input-sm"></textarea></td>
					</tr>
				</table>
			</div>
			<div class="widget-toolbox padding-8 clearfix">
				<input type="submit" class="btn btn-primary btn-sm btn-white btn-round" value="<?php echo lang_get( 'meeting_update_button' ) ?>" />
				<a class="btn btn-sm btn-default btn-white btn-round" href="meeting_view_page.php?id=<?php echo (int)$f_meeting_id ?>"><?php echo lang_get( 'meeting_back' ) ?></a>
			</div>
		</div>
	</div>
	</form>
</div>
<?php layout_page_end(); ?>
