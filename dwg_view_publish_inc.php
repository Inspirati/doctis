<?php
# Doctis — "Published" row of the registered document table on the document
# view page (doc/publish/PUBLISHING.md).  Included from dwg_view_inc.php.
#
# Shown when the on-record file has a page on the project's website: where,
# when and by whom it was published, the commit and status it was published
# from, and an Unpublish button.
#
# Expects: $f_dwg_id.

require_api( 'dwg_publish_api.php' );

$t_published = dwg_publish_enabled() ? dwg_publish_get( $f_dwg_id ) : null;
if( $t_published !== null ) {
	$t_published_sha = (string)( $t_published['sha'] ?? '' );
?>
				<tr>
					<th class="category">
						<span class="label label-info"><?php echo lang_get( 'dwg_published' ) ?></span>
					</th>
					<td>
<?php	if( $t_published['url'] !== '' ): ?>
						<a href="<?php echo string_attribute( $t_published['url'] ) ?>" target="_blank" rel="noopener"><?php
							echo string_display_line( $t_published['page'] )
						?></a>
<?php	else: ?>
						<?php echo string_display_line( $t_published['page'] ) ?>
<?php	endif; ?>
						&nbsp;<span class="small">(<?php echo string_display_line( (string)( $t_published['status_label'] ?? '' ) ) ?>)</span>
					</td>
					<td><?php echo date( config_get( 'normal_date_format' ), (int)( $t_published['published'] ?? 0 ) ) ?></td>
					<td>
						<code title="<?php echo string_attribute( $t_published_sha ) ?>"><?php echo string_display_line( substr( $t_published_sha, 0, 8 ) ) ?></code>
<?php	if( dwg_publish_is_stale( $t_published, $f_dwg_id ) ): ?>
						&nbsp;<span class="label label-warning" title="<?php echo string_attribute( lang_get( 'dwg_publish_out_of_date_help' ) ) ?>"><?php echo lang_get( 'dwg_publish_out_of_date' ) ?></span>
<?php	endif; ?>
					</td>
					<td><?php echo string_display_line( user_get_name( (int)( $t_published['user_id'] ?? 0 ) ) ) ?></td>
					<td>
<?php	if( dwg_publish_can_withdraw( $f_dwg_id ) ): ?>
						<form method="post" action="dwg_publish.php" style="display:inline">
							<?php echo form_security_field( 'dwg_publish' ) ?>
							<input type="hidden" name="dwg_id" value="<?php echo (int)$f_dwg_id ?>" />
							<input type="hidden" name="action" value="unpublish" />
							<input type="submit"
								class="btn btn-danger btn-xs btn-white btn-round"
								value="<?php echo lang_get( 'dwg_unpublish_button' ) ?>" />
						</form>
<?php	endif; ?>
					</td>
				</tr>
<?php
}
