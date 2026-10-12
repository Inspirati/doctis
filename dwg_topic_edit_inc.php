<?php
# Doctis — Inline change/remove controls for one Topic tag of a document.
#
# Expects: $t_tag (tag row with id, extent, locator), $t_return (hidden
# fields telling the action where to go back to, see dwg_topic_redirect()).
?>
<form method="post" action="dwg_topic_update.php" class="form-inline inline">
	<?php echo form_security_field( 'dwg_topic_update' ) ?>
	<input type="hidden" name="tag_id" value="<?php echo (int)$t_tag['id'] ?>" />
	<?php print_hidden_inputs( $t_return ); ?>
	<?php print_topic_extent_select( 'extent', (int)$t_tag['extent'] ); ?>
	<input type="text" name="locator" class="input-sm" size="16" maxlength="255"
		value="<?php echo string_attribute( $t_tag['locator'] ) ?>"
		placeholder="<?php echo lang_get( 'topic_sections' ) ?>" aria-label="<?php echo lang_get( 'topic_sections' ) ?>" />
	<button type="submit" class="btn btn-primary btn-xs btn-white btn-round"><?php echo lang_get( 'update' ) ?></button>
</form>
<?php
print_form_button( 'dwg_topic_delete.php', lang_get( 'topic_remove' ),
	array_merge( array( 'tag_id' => (int)$t_tag['id'] ), $t_return ), null, 'btn btn-danger btn-xs btn-white btn-round' );
