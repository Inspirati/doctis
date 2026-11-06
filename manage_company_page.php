<?php
# Doctis — administrator-maintained company list.
require_once( 'core.php' );
require_api( 'authentication_api.php' );
require_api( 'access_api.php' );
require_api( 'company_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'layout_api.php' );

auth_reauthenticate();
access_ensure_global_level( config_get( 'admin_site_threshold' ) );
layout_page_header( lang_get( 'manage_companies_link' ) );
layout_page_begin( 'manage_overview_page.php' );
print_manage_menu( 'manage_company_page.php' );
?>
<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter"><?php echo lang_get( 'manage_companies_link' ) ?></h4>
		</div>
		<div class="widget-body"><div class="widget-main">
			<p><?php echo lang_get( 'company_manage_hint' ) ?></p>
			<?php foreach( company_get_rows( COMPANY_NOT_SPECIFIED, true ) as $t_id => $t_company ): ?>
			<?php if( $t_id === COMPANY_NOT_SPECIFIED ): ?>
			<p><strong><?php echo string_html_specialchars( $t_company['name'] ) ?></strong> — <?php echo lang_get( 'company_default_hint' ) ?></p>
			<?php else: ?>
			<form method="post" action="manage_company_update.php" class="form-inline">
				<?php echo form_security_field( 'manage_company_update' ) ?>
				<input type="hidden" name="action" value="update" />
				<input type="hidden" name="company_id" value="<?php echo $t_id ?>" />
				<label for="company-<?php echo $t_id ?>"><?php echo lang_get( 'company' ) ?></label>
				<input id="company-<?php echo $t_id ?>" name="name" class="input-sm" maxlength="<?php echo DB_FIELD_SIZE_COMPANY ?>" required value="<?php echo string_attribute( $t_company['name'] ) ?>" />
				<label><input type="checkbox" name="enabled" value="1" <?php echo $t_company['enabled'] ? 'checked="checked"' : '' ?> /> <?php echo lang_get( 'enabled' ) ?></label>
				<button type="submit" class="btn btn-sm btn-primary"><?php echo lang_get( 'update' ) ?></button>
				<div class="space-10"></div>
			</form>
			<?php endif; endforeach; ?>
			<hr />
			<form method="post" action="manage_company_update.php" class="form-inline">
				<?php echo form_security_field( 'manage_company_update' ) ?>
				<input type="hidden" name="action" value="add" />
				<label for="new-company"><?php echo lang_get( 'company' ) ?></label>
				<input id="new-company" name="name" class="input-sm" maxlength="<?php echo DB_FIELD_SIZE_COMPANY ?>" required />
				<button type="submit" class="btn btn-sm btn-primary"><?php echo lang_get( 'company_add' ) ?></button>
			</form>
		</div></div>
	</div>
</div>
<?php layout_page_end(); ?>
