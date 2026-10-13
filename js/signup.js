/* ── Doctis — signup page checks ─────────────────────────────────────────── *
 * Loaded by signup_page.php. Lowercases the username while it is typed,      *
 * explains a username that breaks the naming policy before the form is sent, *
 * and hides the captcha for approved staff email domains. signup.php repeats *
 * every check; this only saves a round trip.                                 *
 * ─────────────────────────────────────────────────────────────────────────── */
document.addEventListener( 'DOMContentLoaded', function() {
	'use strict';

	var form = document.getElementById( 'signup-form' );
	if( !form ) return;

	var username = document.getElementById( 'username' );
	var email = document.getElementById( 'email-field' );
	var message = document.getElementById( 'signup-message' );
	var captcha = document.getElementById( 'signup-captcha' );
	var data = form.dataset;

	var pattern = null;
	try {
		pattern = new RegExp( data.namePattern, data.nameFlags );
	} catch( e ) {
		pattern = null;  // a PHP-only pattern: leave the check to the server
	}
	var reserved = ( data.reserved || '' ).split( ',' ).filter( Boolean );
	var exemptDomains = ( data.exemptDomains || '' ).toLowerCase().split( ',' ).filter( Boolean );

	function usernameProblem( p_name ) {
		if( p_name.indexOf( '@' ) !== -1 ) return data.msgEmail;
		if( pattern && !pattern.test( p_name ) ) return data.msgFormat;
		if( reserved.indexOf( p_name ) !== -1 ) return data.msgReserved.replace( '%s', p_name );
		return '';
	}

	function show( p_field, p_text ) {
		message.textContent = p_text;
		message.dataset.field = p_text === '' ? '' : p_field;
		message.classList.toggle( 'hidden', p_text === '' );
	}

	function clearFor( p_field ) {
		if( message.dataset.field === p_field ) show( p_field, '' );
	}

	function isExempt( p_email ) {
		var at = p_email.lastIndexOf( '@' );
		return at !== -1 && exemptDomains.indexOf( p_email.slice( at + 1 ).trim().toLowerCase() ) !== -1;
	}

	function updateCaptcha() {
		if( captcha ) captcha.classList.toggle( 'hidden', isExempt( email.value ) );
	}

	username.addEventListener( 'input', function() {
		var lower = username.value.toLowerCase();
		if( lower !== username.value ) {
			var pos = username.selectionStart;
			username.value = lower;
			username.setSelectionRange( pos, pos );
		}
		// Say straight away when an email address is going in the wrong field
		if( lower.indexOf( '@' ) !== -1 ) {
			show( 'username', data.msgEmail );
		} else if( usernameProblem( lower.trim() ) === '' ) {
			clearFor( 'username' );
		}
	} );

	username.addEventListener( 'blur', function() {
		var name = username.value.trim();
		if( name !== '' ) {
			var problem = usernameProblem( name );
			if( problem !== '' ) show( 'username', problem );
		}
	} );

	email.addEventListener( 'input', function() {
		clearFor( 'email' );
		updateCaptcha();
	} );

	form.addEventListener( 'submit', function( p_event ) {
		var problem = usernameProblem( username.value.trim() );
		if( problem !== '' ) {
			p_event.preventDefault();
			show( 'username', problem );
			username.focus();
		}
	} );

	updateCaptcha();
	if( message.dataset.field === 'email' ) {
		email.focus();
	}
} );
