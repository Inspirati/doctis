<?php
# Doctis — Document list group action "Add Topic": tag the selected
# documents with a Documentation Topic (doc/topics/TOPICS_PLAN.md §5).
#
# The Topic is chosen by code, so a selection can span projects; each
# document is tagged with that code's Topic in its own project's catalogue.
# A document that already has the Topic, or whose project does not use
# Topics or lacks the code, is reported as failed and left unchanged.

if( !defined( 'DWG_ACTIONGROUP_INC_ALLOW' ) ) {
	return;
}

require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'lang_api.php' );
require_api( 'string_api.php' );
require_api( 'topic_api.php' );

/**
 * Prints the title for the custom action page.
 * @return void
 */
function dwg_action_add_topic_print_title() {
	echo lang_get( 'topic_actiongroup_add' );
}

/**
 * Prints the field within the custom action form.
 * @return void
 */
function dwg_action_add_topic_print_fields() {
?>
	<tr>
		<th class="category"><label for="actiongroup-topic"><?php echo lang_get( 'topic_topic' ) ?></label></th>
		<td>
			<select id="actiongroup-topic" name="topic_code" class="input-sm">
			<?php foreach( topic_code_options( helper_get_current_project() ) as $t_code => $t_option ) { ?>
				<option value="<?php echo string_attribute( $t_code ) ?>"><?php echo string_display_line( $t_code . ' ' . $t_option['title'] ) ?></option>
			<?php } ?>
			</select>
		</td>
	</tr>
	<tr>
		<th class="category"><label for="actiongroup-extent"><?php echo lang_get( 'topic_extent' ) ?></label></th>
		<td><?php print_topic_extent_select( 'extent', TOPIC_EXTENT_PART, 'actiongroup-extent' ); ?></td>
	</tr>
	<tr>
		<th class="category"><label for="actiongroup-locator"><?php echo lang_get( 'topic_sections' ) ?></label></th>
		<td><input type="text" id="actiongroup-locator" name="locator" class="input-sm" size="40" maxlength="255" />
			<span class="grey small"><?php echo lang_get( 'topic_actiongroup_sections_hint' ) ?></span></td>
	</tr>
<?php
}

/**
 * Validates the action on the specified document.
 * @param integer $p_bug_id A document identifier.
 * @return string|null On failure: the reason why the action could not be validated.
 */
function dwg_action_add_topic_validate( $p_bug_id ) {
	$t_code = gpc_get_string( 'topic_code' );
	$t_project_id = (int)dwg_get_field( $p_bug_id, 'project_id' );
	if( !topic_project_is_enabled( $t_project_id ) ) {
		return lang_get( 'topic_not_enabled' );
	}
	if( !topic_can_tag_dwg( (int)$p_bug_id ) ) {
		return lang_get( 'access_denied' );
	}
	$t_topic = topic_get_by_code( $t_project_id, $t_code );
	if( $t_topic === null ) {
		return sprintf( lang_get( 'topic_actiongroup_no_such_topic' ), $t_code );
	}
	foreach( topic_dwg_get_for_dwg( (int)$p_bug_id ) as $t_tag ) {
		if( (int)$t_tag['topic_id'] == (int)$t_topic['id'] ) {
			return sprintf( lang_get( 'topic_actiongroup_already_tagged' ), $t_code );
		}
	}
	return null;
}

/**
 * Executes the action on the specified document.
 * @param integer $p_bug_id A document identifier.
 * @return string|null On failure: the reason why the action failed.
 */
function dwg_action_add_topic_process( $p_bug_id ) {
	$t_project_id = (int)dwg_get_field( $p_bug_id, 'project_id' );
	$t_topic = topic_get_by_code( $t_project_id, gpc_get_string( 'topic_code' ) );
	topic_dwg_add( (int)$p_bug_id, (int)$t_topic['id'], gpc_get_int( 'extent', TOPIC_EXTENT_PART ),
		gpc_get_string( 'locator', '' ) );
	return null;
}
