/* Edit Document: open the Owner picker in place.
 *
 * Changing who is responsible for a document is a significant act, so the
 * owner is shown as "name [Edit]" and the picker stays hidden and disabled --
 * a disabled field is not posted, so the form cannot change the owner by
 * accident.  [Edit] reveals and enables it here, without reloading, so
 * anything already typed elsewhere on the form is kept.  Without scripting
 * the link still works: it reloads the page with the picker open. */
document.addEventListener( 'DOMContentLoaded', function() {
	var link = document.getElementById( 'dwg-owner-edit' );
	if( !link ) {
		return;
	}
	link.addEventListener( 'click', function( event ) {
		event.preventDefault();
		var display = document.getElementById( 'dwg-owner-display' );
		var picker  = document.getElementById( 'dwg-owner-picker' );
		var select  = document.getElementById( 'owner_id' );
		if( !picker || !select ) {
			return;
		}
		if( display ) {
			display.style.display = 'none';
		}
		picker.style.display = '';
		select.disabled = false;
		select.focus();
	} );
} );
