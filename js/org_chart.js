/* Keep both chart scrollbars inside the available browser viewport. */
document.addEventListener( 'DOMContentLoaded', function() {
	'use strict';
	var chart = document.querySelector( '.org-chart' );
	if( !chart ) return;
	var pending = false;
	function scheduleFit() {
		if( pending ) return;
		pending = true;
		window.requestAnimationFrame( function() {
			pending = false;
			var viewportHeight = window.visualViewport ? window.visualViewport.height : document.documentElement.clientHeight;
			var top = Math.max( 0, chart.getBoundingClientRect().top );
			chart.style.maxHeight = Math.max( 32, viewportHeight - top - 24 ) + 'px';
		} );
	}
	window.addEventListener( 'resize', scheduleFit );
	window.addEventListener( 'load', scheduleFit );
	window.addEventListener( 'scroll', scheduleFit, { passive: true } );
	if( window.visualViewport ) window.visualViewport.addEventListener( 'resize', scheduleFit );
	if( window.ResizeObserver ) {
		var observer = new ResizeObserver( scheduleFit );
		observer.observe( chart.parentElement );
	}
	scheduleFit();
} );
