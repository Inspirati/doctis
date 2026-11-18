document.addEventListener( 'DOMContentLoaded', function() {
	'use strict';
	var table = document.querySelector( '#email-domain-rows tbody' );
	var add = document.getElementById( 'email-domain-add' );
	if( !table || !add ) return;
	add.classList.remove( 'hidden' );
	add.addEventListener( 'click', function() {
		var index = table.rows.length;
		if( index >= 200 ) return;
		var row = table.rows[table.rows.length - 1].cloneNode( true );
		row.querySelectorAll( 'input' ).forEach( function( input ) {
			input.name = input.name.replace( /domains\[\d+\]/, 'domains[' + index + ']' );
			if( input.type === 'checkbox' ) input.checked = false;
			else input.value = '';
		} );
		table.appendChild( row );
		row.querySelector( 'input' ).focus();
	} );
} );
