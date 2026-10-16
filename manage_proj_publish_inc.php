<?php
# Doctis — "Publishing" block of the Manage Project page
# (doc/publish/PUBLISHING.md).  Included from manage_proj_edit_page.php.
#
# Sets the project's publication target directory, website URL, template
# and banner logo.  Shown only when the server allows publishing
# ($g_dwg_publish_roots).  A project without its own target inherits its
# nearest ancestor's site.
#
# Expects: $f_project_id.

require_api( 'dwg_publish_api.php' );

if( dwg_publish_enabled() ) {
	$t_publish_site  = dwg_publish_site( $f_project_id );
	$t_publish_own   = (int)$t_publish_site['project_id'] === (int)$f_project_id;
	$t_publish_value = function( $p_option ) use ( $f_project_id, $t_publish_own ) {
		return $t_publish_own ? (string)config_get( $p_option, '', ALL_USERS, $f_project_id ) : '';
	};
	$t_publish_pages = $t_publish_site['target'] !== '' ? count( dwg_publish_site_pages( $t_publish_site['target'] ) ) : 0;
?>
<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div id="publishing" class="form-container">
	<form id="manage-proj-publish-form" method="post" action="manage_proj_publish_update.php">
		<?php echo form_security_field( 'manage_proj_publish_update' ) ?>
		<input type="hidden" name="project_id" value="<?php echo (int)$f_project_id ?>" />
		<div class="widget-box widget-color-blue2">
			<div class="widget-header widget-header-small">
				<h4 class="widget-title lighter">
					<?php print_icon( 'fa-globe', 'ace-icon' ); ?>
					<?php echo lang_get( 'dwg_publish_settings' ) ?>
				</h4>
			</div>
			<div class="widget-body">
			<div class="widget-main no-padding">
			<div class="table-responsive">
			<table class="table table-bordered table-condensed table-striped">
<?php	if( !$t_publish_own && $t_publish_site['configured_target'] !== '' ): ?>
				<tr>
					<td colspan="2">
						<?php printf( lang_get( 'dwg_publish_inherited' ),
							string_display_line( $t_publish_site['project_id'] > 0 ? project_get_name( (int)$t_publish_site['project_id'] ) : lang_get( 'all_projects' ) ),
							string_display_line( $t_publish_site['configured_target'] ) ) ?>
					</td>
				</tr>
<?php	endif; ?>
<?php	if( $t_publish_site['problem'] !== '' ): ?>
				<tr>
					<td colspan="2">
						<span class="label label-danger"><?php echo lang_get( 'dwg_publish_target_unusable' ) ?></span>
						<?php echo string_display_line( $t_publish_site['problem'] ) ?>
					</td>
				</tr>
<?php	elseif( $t_publish_site['target'] !== '' ): ?>
				<tr>
					<td colspan="2">
						<?php printf( lang_get( 'dwg_publish_page_count' ), $t_publish_pages ) ?>
<?php		if( $t_publish_pages > 0 && $t_publish_site['url'] !== '' ): ?>
						&nbsp;<a href="<?php echo string_attribute( dwg_publish_page_url( $t_publish_site, DWG_PUBLISH_INDEX ) ) ?>" target="_blank" rel="noopener"><?php echo lang_get( 'dwg_publish_view_site' ) ?></a>
<?php		endif; ?>
					</td>
				</tr>
<?php	endif; ?>
				<tr>
					<td class="category width-20">
						<label for="dwg-publish-target"><?php echo lang_get( 'dwg_publish_target' ) ?></label>
					</td>
					<td>
						<input type="text" id="dwg-publish-target" name="dwg_publish_target" class="input-sm" size="60" maxlength="250"
							value="<?php echo string_attribute( $t_publish_value( 'dwg_publish_target' ) ) ?>" />
						<br /><span class="small"><?php printf( lang_get( 'dwg_publish_target_help' ), string_display_line( implode( ', ', dwg_publish_roots() ) ) ) ?></span>
					</td>
				</tr>
				<tr>
					<td class="category">
						<label for="dwg-publish-url"><?php echo lang_get( 'dwg_publish_url' ) ?></label>
					</td>
					<td>
						<input type="text" id="dwg-publish-url" name="dwg_publish_url" class="input-sm" size="60" maxlength="250"
							value="<?php echo string_attribute( $t_publish_value( 'dwg_publish_url' ) ) ?>" />
						<br /><span class="small"><?php echo lang_get( 'dwg_publish_url_help' ) ?></span>
					</td>
				</tr>
				<tr>
					<td class="category">
						<label for="dwg-publish-template"><?php echo lang_get( 'dwg_publish_template' ) ?></label>
					</td>
					<td>
						<input type="text" id="dwg-publish-template" name="dwg_publish_template" class="input-sm" size="60" maxlength="250"
							value="<?php echo string_attribute( $t_publish_value( 'dwg_publish_template' ) ) ?>" />
						<br /><span class="small"><?php echo lang_get( 'dwg_publish_template_help' ) ?></span>
					</td>
				</tr>
				<tr>
					<td class="category">
						<label for="dwg-publish-logo"><?php echo lang_get( 'dwg_publish_logo' ) ?></label>
					</td>
					<td>
						<input type="text" id="dwg-publish-logo" name="dwg_publish_logo" class="input-sm" size="60" maxlength="250"
							value="<?php echo string_attribute( $t_publish_value( 'dwg_publish_logo' ) ) ?>" />
						<br /><span class="small"><?php echo lang_get( 'dwg_publish_logo_help' ) ?></span>
					</td>
				</tr>
				<tr>
					<td class="category">
						<label for="dwg-publish-pdf"><?php echo lang_get( 'dwg_publish_pdf' ) ?></label>
					</td>
					<td>
						<label>
							<input type="checkbox" id="dwg-publish-pdf" name="dwg_publish_pdf" class="ace"
								<?php check_checked( $t_publish_own && (int)config_get( 'dwg_publish_pdf', OFF, ALL_USERS, $f_project_id ) === ON ) ?> />
							<span class="lbl"> <?php echo lang_get( 'dwg_publish_pdf_label' ) ?></span>
						</label>
<?php	if( dwg_publish_pdf_problem() !== '' ): ?>
						<br /><span class="label label-warning"><?php echo string_display_line( dwg_publish_pdf_problem() ) ?></span>
<?php	endif; ?>
					</td>
				</tr>
			</table>
			</div>
			</div>
			<div class="widget-toolbox padding-8 clearfix">
				<button class="btn btn-primary btn-white btn-round">
					<?php echo lang_get( 'dwg_publish_update_button' ) ?>
				</button>
			</div>
			</div>
		</div>
	</form>
	</div>
</div>
<?php
}
