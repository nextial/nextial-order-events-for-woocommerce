/* Nextial Order Events admin page: select-on-click fields and copy buttons. */
( function () {
	document.addEventListener( 'click', function ( e ) {
		var el = e.target;
		if ( el.matches && el.matches( 'input.nextial-oe-select' ) ) {
			el.select();
			return;
		}
		var btn = el.closest ? el.closest( 'button[data-nextial-oe-copy]' ) : null;
		if ( ! btn ) {
			return;
		}
		var field = document.getElementById( btn.getAttribute( 'data-nextial-oe-copy' ) );
		if ( field && navigator.clipboard ) {
			navigator.clipboard.writeText( field.value );
			btn.textContent = btn.getAttribute( 'data-nextial-oe-copied' ) || btn.textContent;
		}
	} );
}() );
