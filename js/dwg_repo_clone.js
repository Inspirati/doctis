/*
 * Doctis - copy the ready-made git clone or push commands to the clipboard.
 *
 * External file because the Content-Security-Policy is script-src 'self';
 * an inline handler would be blocked.
 */
document.addEventListener( 'DOMContentLoaded', function() {
	/* The Clone page's buttons by id; the Push page's by class, one per
	   command block. */
	var buttons = Array.prototype.slice.call(
		document.querySelectorAll( '#clone-copy, #repoint-copy, .doctis-copy' ) );
	buttons.forEach( function( button ) {
	var original = button.textContent;

	button.addEventListener( 'click', function() {
		var field = document.getElementById( button.getAttribute( 'data-target' ) );
		if( !field ) {
			return;
		}

		function done( ok ) {
			button.textContent = ok ? button.getAttribute( 'data-copied' ) || 'Copied'
				: button.getAttribute( 'data-failed' ) || 'Press Ctrl+C';
			window.setTimeout( function() { button.textContent = original; }, 2000 );
		}

		/* Selecting first means Ctrl+C still works if the clipboard is denied,
		   which it is on a plain-HTTP origin in some browsers. */
		field.focus();
		field.select();
		field.setSelectionRange( 0, field.value.length );

		if( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( field.value ).then(
				function() { done( true ); },
				function() { done( false ); }
			);
		} else {
			var ok = false;
			try { ok = document.execCommand( 'copy' ); } catch ( e ) { ok = false; }
			done( ok );
		}
	} );
	} );
} );
