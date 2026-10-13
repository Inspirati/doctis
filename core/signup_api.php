<?php
# Doctis — self sign-up checks
#
# signup.php runs these checks before it creates the account. A refused
# sign-up goes back to signup_page.php with the reason and the values entered,
# instead of to an error page. user_create() repeats the username and email
# checks, so other ways of creating accounts are covered too.
#
# Staff using an email domain in $g_signup_captcha_exempt_domains sign up
# without the captcha; everyone else still needs it.
#
# @package    Doctis
# @copyright  Copyright 2026 Inspirati
# @license    GPL-2.0-or-later

require_api( 'config_api.php' );
require_api( 'email_api.php' );
require_api( 'form_api.php' );
require_api( 'helper_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'session_api.php' );
require_api( 'user_api.php' );
require_api( 'utility_api.php' );

/**
 * Check whether an email address belongs to one of the given domains.
 *
 * @param string $p_email   Email address.
 * @param array  $p_domains Domain names, compared without regard to case.
 * @return bool
 */
function signup_email_in_domains( $p_email, array $p_domains ) {
	$t_at = strrpos( $p_email, '@' );
	if( $t_at === false ) {
		return false;
	}
	$t_domain = substr( $p_email, $t_at + 1 );
	foreach( $p_domains as $t_allowed ) {
		if( 0 == strcasecmp( $t_allowed, $t_domain ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Whether the captcha is in use on the signup page at all.
 *
 * @return bool
 */
function signup_captcha_enabled() {
	# captcha image requires GD library and related option to ON
	return ON == config_get( 'signup_use_captcha' ) && get_gd_version() > 0 &&
		helper_call_custom_function( 'auth_can_change_password', array() );
}

/**
 * Whether a sign-up with this email address must pass the captcha.
 *
 * @param string $p_email Email address entered on the signup form.
 * @return bool
 */
function signup_captcha_required( $p_email ) {
	return signup_captcha_enabled() &&
		!signup_email_in_domains( $p_email, config_get( 'signup_captcha_exempt_domains' ) );
}

/**
 * Find a problem with the form values themselves.
 *
 * Reveals nothing about existing accounts, so signup.php runs it before the
 * captcha: nobody has to solve the captcha to learn their entry is malformed.
 *
 * @param string $p_username Username, already trimmed and lowercased.
 * @param string $p_email    Email address, already trimmed.
 * @return string '' if none, otherwise a key of signup_problem_message()
 */
function signup_form_problem( $p_username, $p_email ) {
	if( strpos( $p_username, '@' ) !== false ) {
		return 'username_email';
	}
	if( !user_is_name_valid( $p_username ) ) {
		return 'username_format';
	}
	$t_policy = user_name_policy_problem( $p_username );
	if( $t_policy !== '' ) {
		return 'username_' . $t_policy;
	}

	$t_domains = config_get( 'limit_email_domains' );
	if( !empty( $t_domains ) && strpos( $p_email, '@' ) !== false &&
		!signup_email_in_domains( $p_email, $t_domains ) ) {
		return 'email_domain';
	}
	if( !email_is_valid( $p_email ) || email_is_disposable( $p_email ) ) {
		return 'email_invalid';
	}

	return '';
}

/**
 * Find a clash with an existing account.
 *
 * signup.php runs it after the captcha, so that bots cannot probe for
 * existing usernames and email addresses.
 *
 * @param string $p_username Username, already trimmed and lowercased.
 * @param string $p_email    Email address, already trimmed.
 * @return string '' if none, otherwise a key of signup_problem_message()
 */
function signup_account_problem( $p_username, $p_email ) {
	if( !user_is_name_unique( $p_username ) ) {
		return 'username_taken';
	}
	if( config_get_global( 'email_ensure_unique' ) && !user_is_email_unique( $p_email ) ) {
		return 'email_taken';
	}

	return '';
}

/**
 * The form field a problem belongs to.
 *
 * @param string $p_problem Key returned by signup_form_problem() or
 *                           signup_account_problem(), or 'captcha'.
 * @return string 'username', 'email' or 'captcha'
 */
function signup_problem_field( $p_problem ) {
	return explode( '_', $p_problem, 2 )[0];
}

/**
 * Explain a refused sign-up, as plain text.
 *
 * @param string $p_problem  Key returned by signup_form_problem() or
 *                           signup_account_problem(), or 'captcha'.
 * @param string $p_username The username entered.
 * @return string
 */
function signup_problem_message( $p_problem, $p_username ) {
	switch( $p_problem ) {
		case 'username_reserved':
		case 'username_taken':
			return sprintf( lang_get( 'signup_error_' . $p_problem ), $p_username );
		case 'email_domain':
			$t_message = lang_get( 'signup_error_email_domain' );
			$t_staff_domains = config_get( 'signup_captcha_exempt_domains' );
			if( !empty( $t_staff_domains ) ) {
				$t_message .= ' ' . sprintf( lang_get( 'signup_error_email_staff' ),
					implode( ', ', $t_staff_domains ) );
			}
			return $t_message;
		default:
			return lang_get( 'signup_error_' . $p_problem );
	}
}

/**
 * Send the user back to the signup form to correct a refused sign-up.
 *
 * The values travel in the session, not the URL, so the email address stays
 * out of the web server logs.
 *
 * @param string $p_problem  Key returned by signup_form_problem() or
 *                           signup_account_problem(), or 'captcha'.
 * @param string $p_username The username entered.
 * @param string $p_email    The email address entered.
 * @return void Does not return.
 */
function signup_return_to_form( $p_problem, $p_username, $p_email ) {
	session_set( 'signup_form', array(
		'problem' => $p_problem,
		'username' => $p_username,
		'email' => $p_email,
	) );
	form_security_purge( 'signup' );
	print_header_redirect( 'signup_page.php' );
}
