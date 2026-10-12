<?php
# Doctis — Manage > Topics: add/edit form for one Topic of a Draft catalogue.
#
# Expects: $t_form_topic (row of {topic}, id 0 for a new Topic),
# $t_form_catalogue_id, $t_form_parents (topic_get_all() of the catalogue).

$t_form_is_new = (int)$t_form_topic['id'] == 0;
?>
<form method="post" action="manage_topic_action.php">
	<?php echo form_security_field( 'manage_topic_action' ) ?>
	<input type="hidden" name="action" value="<?php echo $t_form_is_new ? 'create' : 'update' ?>" />
	<input type="hidden" name="id" value="<?php echo (int)$t_form_topic['id'] ?>" />
	<input type="hidden" name="catalogue_id" value="<?php echo (int)$t_form_catalogue_id ?>" />
	<table class="table table-bordered table-condensed">
		<tr>
			<th class="category width-20"><label for="topic-code"><?php echo lang_get( 'topic_code' ) ?></label></th>
			<td><input type="text" id="topic-code" name="code" class="input-sm" size="8" maxlength="8" required
				value="<?php echo string_attribute( $t_form_topic['code'] ) ?>" />
				<span class="grey"><?php echo lang_get( 'topic_code_hint' ) ?></span></td>
		</tr>
		<tr>
			<th class="category"><label for="topic-title"><?php echo lang_get( 'topic_title' ) ?></label></th>
			<td><input type="text" id="topic-title" name="title" class="input-sm" size="64" maxlength="128" required
				value="<?php echo string_attribute( $t_form_topic['title'] ) ?>" /></td>
		</tr>
		<tr>
			<th class="category"><label for="topic-parent"><?php echo lang_get( 'topic_parent' ) ?></label></th>
			<td><select id="topic-parent" name="parent_id" class="input-sm">
				<option value="0"><?php echo lang_get( 'topic_principal' ) ?></option>
				<?php foreach( $t_form_parents as $t_parent_id => $t_parent ) {
					if( (int)$t_parent['parent_id'] != 0 || $t_parent_id == (int)$t_form_topic['id'] ) {
						continue;
					} ?>
				<option value="<?php echo (int)$t_parent_id ?>"<?php
					echo $t_parent_id == (int)$t_form_topic['parent_id'] ? ' selected="selected"' : '' ?>><?php
					echo string_display_line( topic_label( $t_parent ) ) ?></option>
				<?php } ?>
			</select></td>
		</tr>
		<tr>
			<th class="category"><label for="topic-description"><?php echo lang_get( 'topic_expected_information' ) ?></label></th>
			<td><textarea id="topic-description" name="description" class="form-control" rows="4"><?php
				echo string_textarea( $t_form_topic['description'] ) ?></textarea></td>
		</tr>
		<tr>
			<th class="category"><label for="topic-order"><?php echo lang_get( 'topic_sort_order' ) ?></label></th>
			<td><input type="number" id="topic-order" name="sort_order" class="input-sm" style="width:8em"
				value="<?php echo (int)$t_form_topic['sort_order'] ?>" /></td>
		</tr>
	</table>
	<button type="submit" class="btn btn-primary btn-sm btn-white btn-round"><?php
		echo lang_get( $t_form_is_new ? 'topic_add_topic' : 'update' ) ?></button>
</form>
