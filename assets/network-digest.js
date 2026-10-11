( function () {
	document.addEventListener( 'DOMContentLoaded', function () {
		var wrap = document.getElementById( 'network-digest' );
		if ( ! wrap ) {
			return;
		}

		var button    = wrap.querySelector( '.network-digest-load-more' );
		var loading   = wrap.querySelector( '.network-digest-loading' );
		var itemsList = wrap.querySelector( '.network-digest-items' );

		if ( ! button ) {
			return; // No "Load more" button on the page (e.g. all posts fit in the first batch) — nothing to wire up.
		}

		var ajaxUrl  = wrap.getAttribute( 'data-ajax-url' );
		var nonce    = wrap.getAttribute( 'data-nonce' );
		var perPage  = parseInt( wrap.getAttribute( 'data-per-page' ), 10 ) || 10;
		var total    = parseInt( wrap.getAttribute( 'data-total' ), 10 ) || 0;

		if ( ! ajaxUrl || ! nonce ) {
			console.error( 'Network Digest: missing data-ajax-url or data-nonce attribute on #network-digest. This suggests the wrapper markup itself was altered by another plugin/theme, or the shortcode output is being cached from before this attribute existed — try clearing your page cache.' );
			return;
		}

		button.addEventListener( 'click', function () {
			var offset = parseInt( button.getAttribute( 'data-offset' ), 10 ) || 0;

			button.disabled = true;
			loading.style.display = 'inline';

			var body = new URLSearchParams( {
				action:   'network_digest_load_more',
				nonce:    nonce,
				offset:   offset,
				per_page: perPage,
				limit:    total,
			} );

			fetch( ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
				cache: 'no-store',
			} )
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( 'HTTP ' + response.status + ' from admin-ajax.php' );
					}
					return response.text();
				} )
				.then( function ( raw ) {
					var response;
					try {
						response = JSON.parse( raw );
					} catch ( e ) {
						console.error( 'Network Digest: response was not valid JSON. Raw response below — this usually means a PHP warning/notice, a caching plugin, or a security plugin is injecting extra output before the JSON.' );
						console.error( raw );
						return;
					}

					if ( ! response.success ) {
						console.error( 'Network Digest: server returned an error.', response );
						return;
					}

					itemsList.insertAdjacentHTML( 'beforeend', response.data.html );

					var newOffset = offset + perPage;
					button.setAttribute( 'data-offset', newOffset );

					if ( ! response.data.has_more ) {
						button.style.display = 'none';
					}
				} )
				.catch( function ( err ) {
					console.error( 'Network Digest: load more request failed.', err );
				} )
				.finally( function () {
					button.disabled = false;
					loading.style.display = 'none';
				} );
		} );
	} );
} )();
