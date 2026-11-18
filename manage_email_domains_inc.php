<?php
# Included only by the authenticated company-management page.
if( !defined( 'EMAIL_DOMAINS_MANAGEMENT' ) ) { exit; }
require_api( 'email_domain_api.php' );
require_api( 'signup_api.php' );
$t_domain_snapshot = email_domain_snapshot( true );
$t_allowed_domains = $t_domain_snapshot['lists']['limit_email_domains'];
$t_exempt_domains = $t_domain_snapshot['lists']['signup_captcha_exempt_domains'];
$t_domain_rows = array_values( array_unique( array_merge( $t_allowed_domains, $t_exempt_domains ) ) );
sort( $t_domain_rows );
$t_domain_rows[] = '';
$t_domain_error = session_get( 'email_domains_error', '' );
session_delete( 'email_domains_error' );
?>
<div class="space-10"></div>
<div class="widget-box widget-color-blue2" id="email-domains">
	<div class="widget-header widget-header-small"><h4 class="widget-title lighter"><?php echo lang_get( 'email_domains_title' ) ?></h4></div>
	<div class="widget-body"><div class="widget-main">
		<p><?php echo lang_get( 'email_domains_intro' ) ?></p>
		<?php if( $t_domain_snapshot['invalid'] ): ?><p class="alert alert-danger"><?php echo lang_get( 'email_domains_repair' ) ?></p><?php endif ?>
		<?php if( $t_domain_error !== '' ): ?><p class="alert alert-danger"><?php echo string_html_specialchars( $t_domain_error ) ?></p><?php endif ?>
		<?php if( gpc_get_bool( 'domains_saved' ) ): ?><p class="alert alert-success"><?php echo lang_get( 'email_domains_saved' ) ?></p><?php endif ?>
		<p><strong><?php echo lang_get( 'email_domains_mode' ) ?>:</strong> <?php echo lang_get( $t_allowed_domains ? 'email_domains_restricted' : 'email_domains_unrestricted' ) ?></p>
		<ul><?php foreach( EMAIL_DOMAIN_OPTIONS as $t_option ): ?>
			<li><?php echo lang_get( $t_option === 'limit_email_domains' ? 'email_domains_allowed' : 'email_domains_exempt' ) ?>:
				<?php echo lang_get( $t_domain_snapshot['sources'][$t_option] ) ?></li>
		<?php endforeach ?></ul>
		<p><?php echo lang_get( 'email_domains_source_hint' ) ?></p>
		<?php if( !signup_captcha_enabled() ): ?><p class="alert alert-info"><?php echo lang_get( 'email_domains_captcha_off' ) ?></p><?php endif ?>
		<?php if( config_get_global( 'validate_email' ) == OFF || config_get_global( 'use_ldap_email' ) == ON ): ?>
		<p class="alert alert-warning"><?php echo lang_get( 'email_domains_validation_off' ) ?></p><?php endif ?>
		<dl>
			<dt><?php echo lang_get( 'email_domains_domain' ) ?></dt><dd><?php echo lang_get( 'email_domains_domain_hint' ) ?></dd>
			<dt><?php echo lang_get( 'email_domains_restrict' ) ?></dt><dd><?php echo lang_get( 'email_domains_restrict_hint' ) ?></dd>
			<dt><?php echo lang_get( 'email_domains_allowed' ) ?></dt><dd><?php echo lang_get( 'email_domains_allowed_hint' ) ?></dd>
			<dt><?php echo lang_get( 'email_domains_exempt' ) ?></dt><dd><?php echo lang_get( 'email_domains_exempt_hint' ) ?></dd>
			<dt><?php echo lang_get( 'remove' ) ?></dt><dd><?php echo lang_get( 'email_domains_remove_hint' ) ?></dd>
		</dl>
		<p><?php echo lang_get( 'email_domains_mail_hint' ) ?></p>
		<form method="post" action="manage_email_domains_update.php" id="email-domains-form">
			<?php echo form_security_field( 'email_domains_preview' ) ?>
			<input type="hidden" name="revision" value="<?php echo string_attribute( $t_domain_snapshot['revision'] ) ?>" />
			<label><input type="checkbox" name="restricted" value="1" <?php echo $t_allowed_domains ? 'checked="checked"' : '' ?> /> <?php echo lang_get( 'email_domains_restrict' ) ?></label>
			<table class="table table-bordered" id="email-domain-rows"><thead><tr>
				<th><?php echo lang_get( 'email_domains_domain' ) ?></th><th><?php echo lang_get( 'email_domains_allowed' ) ?></th>
				<th><?php echo lang_get( 'email_domains_exempt' ) ?></th><th><?php echo lang_get( 'remove' ) ?></th>
			</tr></thead><tbody>
			<?php foreach( $t_domain_rows as $t_index => $t_domain ): ?>
			<tr>
				<td><input type="text" name="domains[<?php echo $t_index ?>][domain]" maxlength="253" value="<?php echo string_attribute( $t_domain ) ?>" aria-label="<?php echo string_attribute( lang_get( 'email_domains_domain' ) ) ?>" /></td>
				<td><input type="checkbox" name="domains[<?php echo $t_index ?>][allowed]" value="1" <?php echo in_array( $t_domain, $t_allowed_domains, true ) ? 'checked="checked"' : '' ?> aria-label="<?php echo string_attribute( lang_get( 'email_domains_allowed' ) ) ?>" /></td>
				<td><input type="checkbox" name="domains[<?php echo $t_index ?>][exempt]" value="1" <?php echo in_array( $t_domain, $t_exempt_domains, true ) ? 'checked="checked"' : '' ?> aria-label="<?php echo string_attribute( lang_get( 'email_domains_exempt' ) ) ?>" /></td>
				<td><input type="checkbox" name="domains[<?php echo $t_index ?>][remove]" value="1" aria-label="<?php echo string_attribute( lang_get( 'remove' ) ) ?>" /></td>
			</tr>
			<?php endforeach ?>
			</tbody></table>
			<button type="button" class="btn btn-sm btn-default hidden" id="email-domain-add"><?php echo lang_get( 'email_domains_add' ) ?></button>
			<p><?php echo lang_get( 'email_domains_save_hint' ) ?></p>
			<?php if( $t_domain_snapshot['scoped'] ): ?>
			<p class="alert alert-warning"><?php echo sprintf( lang_get( 'email_domains_scoped_hint' ), $t_domain_snapshot['scoped'] ) ?></p>
			<label><input type="checkbox" name="clear_scoped" value="1" /> <?php echo lang_get( 'email_domains_scoped_remove' ) ?></label>
			<?php endif ?>
			<p><button type="submit" class="btn btn-primary"><?php echo lang_get( 'email_domains_review' ) ?></button></p>
			<input type="hidden" name="complete" value="1" />
		</form>
	</div></div>
</div>
