<?php
# Preview and commit both global domain lists together.
require_once( 'core.php' );
require_api( 'authentication_api.php' );
require_api( 'email_domain_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'session_api.php' );

auth_reauthenticate();
email_domain_ensure_manage_access();
$f_confirm = gpc_get_bool( 'confirm' );
form_security_validate( $f_confirm ? 'email_domains_confirm' : 'email_domains_preview' );

try {
	if( $f_confirm ) {
		$t_pending = session_get( 'email_domains_pending', null );
		if( !is_array( $t_pending ) || $t_pending['expires'] < time()
			|| $t_pending['owner'] !== auth_get_current_user_id()
			|| !hash_equals( $t_pending['nonce'], gpc_get_string( 'nonce' ) ) ) {
			email_domain_fail( 'email_domains_stale' );
		}
		email_domain_save( $t_pending['lists'], $t_pending['revision'], $t_pending['clear_scoped'] );
		session_delete( 'email_domains_pending' );
		form_security_purge( 'email_domains_confirm' );
		print_header_redirect( 'manage_company_page.php?domains_saved=1#email-domains' );
		exit;
	}
	if( gpc_get_string( 'complete', '' ) !== '1' ) { email_domain_fail( 'email_domains_invalid' ); }
	$t_lists = email_domain_from_rows( gpc_get( 'domains', array() ), gpc_get_bool( 'restricted' ) );
	$t_snapshot = email_domain_snapshot( true );
	$t_revision = gpc_get_string( 'revision' );
	if( !hash_equals( $t_snapshot['revision'], $t_revision ) ) { email_domain_fail( 'email_domains_stale' ); }
	$t_clear_scoped = gpc_get_bool( 'clear_scoped' );
	if( $t_snapshot['scoped'] && !$t_clear_scoped ) { email_domain_fail( 'email_domains_scoped' ); }
	$t_affected = email_domain_affected_users( $t_lists );
	$t_pending = array( 'lists' => $t_lists, 'revision' => $t_revision, 'clear_scoped' => $t_clear_scoped,
		'owner' => auth_get_current_user_id(), 'expires' => time() + 900, 'nonce' => bin2hex( random_bytes( 16 ) ) );
	session_set( 'email_domains_pending', $t_pending );
	form_security_purge( 'email_domains_preview' );
} catch( Mantis\Exceptions\ClientException $t_error ) {
	session_set( 'email_domains_error', $t_error->getMessage() );
	print_header_redirect( 'manage_company_page.php#email-domains' );
	exit;
}

layout_page_header( lang_get( 'email_domains_review' ) );
layout_page_begin( 'manage_overview_page.php' );
print_manage_menu( 'manage_company_page.php' );
?>
<div class="col-md-12 col-xs-12"><div class="widget-box widget-color-blue2">
	<div class="widget-header"><h4 class="widget-title"><?php echo lang_get( 'email_domains_review' ) ?></h4></div>
	<div class="widget-body"><div class="widget-main">
		<p><?php echo lang_get( 'email_domains_review_hint' ) ?></p>
		<table class="table table-bordered">
			<tr><th><?php echo lang_get( 'email_domains_mode' ) ?></th><td><?php echo lang_get( $t_lists['limit_email_domains'] ? 'email_domains_restricted' : 'email_domains_unrestricted' ) ?></td></tr>
			<?php foreach( EMAIL_DOMAIN_OPTIONS as $t_option ): ?>
			<tr><th><?php echo lang_get( $t_option === 'limit_email_domains' ? 'email_domains_allowed' : 'email_domains_exempt' ) ?></th>
				<td><?php echo string_html_specialchars( implode( ', ', $t_lists[$t_option] ) ?: lang_get( 'none' ) ) ?></td></tr>
			<?php endforeach ?>
		</table>
		<p><?php echo sprintf( lang_get( 'email_domains_affected' ), count( $t_affected ) ) ?></p>
		<?php if( $t_affected ): ?>
		<ul><?php foreach( $t_affected as $t_user ): ?>
			<li><?php echo string_html_specialchars( $t_user['username'] . ' — ' . $t_user['email'] ) ?>
			<?php if( (int)$t_user['id'] === auth_get_current_user_id() ): ?><strong><?php echo lang_get( 'email_domains_your_account' ) ?></strong><?php endif ?></li>
		<?php endforeach ?></ul>
		<?php endif ?>
		<?php if( $t_clear_scoped ): ?><p class="alert alert-warning"><?php echo lang_get( 'email_domains_scoped_remove' ) ?></p><?php endif ?>
		<form method="post" action="manage_email_domains_update.php">
			<?php echo form_security_field( 'email_domains_confirm' ) ?>
			<input type="hidden" name="confirm" value="1" />
			<input type="hidden" name="nonce" value="<?php echo string_attribute( $t_pending['nonce'] ) ?>" />
			<button class="btn btn-primary" type="submit"><?php echo lang_get( 'email_domains_save' ) ?></button>
			<a class="btn btn-default" href="manage_company_page.php#email-domains"><?php echo lang_get( 'cancel' ) ?></a>
		</form>
	</div></div>
</div></div>
<?php layout_page_end();
