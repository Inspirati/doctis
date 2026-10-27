/* Continue a budgeted Publish All run.
 *
 * Each request publishes for a few seconds and renders this page again with
 * the resume point, so the run finishes in steps that stay well inside the
 * proxy's response timeout.  Submitting here is what makes it automatic; the
 * button is the fallback when scripting is off. */
document.addEventListener( 'DOMContentLoaded', function() {
	var form = document.getElementById( 'publish-all-continue' );
	if( form ) {
		form.submit();
	}
} );
