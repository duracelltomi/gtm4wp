/**
 * GTM4WP cache-safe data layer — client-side visitor data runtime (issue #398).
 *
 * On full-page-cached sites, values specific to the current visitor/session must
 * not be baked into the (shared) page HTML. This script pushes the values the
 * browser can compute itself — with zero network requests — as a
 * gtm4wp.visitorData data layer event, using the SAME variable names the server
 * used, so existing Google Tag Manager setups keep working.
 *
 * The PHP side (VisitorDataModule) bakes a small, cache-safe config into the page
 * telling this runtime which data layer keys to build and from which browser
 * source. Each source is a "producer" below. Phase 2 will add producers that read
 * once-per-session / cookie-gated values from a first-party session endpoint —
 * there is deliberately NO unconditional fetch() here.
 */
( function () {
	'use strict';

	const config = window.gtm4wp_visitordata_config || { fields: {} };
	const datalayerName = window.gtm4wp_datalayer_name || 'dataLayer';
	const eventName = config.event || 'gtm4wp.visitorData';
	const fields = config.fields || {};

	/**
	 * Producers compute one visitor-scoped value from a browser source, returning
	 * an empty string when it cannot be determined (the value is then omitted, as
	 * the server omitted an empty value). Keyed by the source token the PHP side
	 * sends in config.fields.
	 */
	const producers = {
		/**
		 * The site search term from the current URL query string (?s=...),
		 * mirroring get_search_query() / the siteSearchTerm server value.
		 *
		 * @return {string} The search term, or '' when absent.
		 */
		searchTerm() {
			try {
				return (
					new URLSearchParams( window.location.search ).get( 's' ) ||
					''
				);
			} catch ( e ) {
				return '';
			}
		},

		/**
		 * The referring page URL, normalized like the 1.x siteSearchFrom value:
		 * the part before the query string, and — when a query string is present —
		 * the query re-appended URL-encoded so it stays a single opaque token.
		 *
		 * @return {string} The normalized referrer, or '' when there is none.
		 */
		searchReferrer() {
			const ref = document.referrer || '';
			if ( '' === ref ) {
				return '';
			}

			const parts = ref.split( '?' );
			if ( parts.length < 2 ) {
				return ref;
			}

			return parts[ 0 ] + '?' + encodeURIComponent( parts[ 1 ] );
		},
	};

	const push = { event: eventName };
	let hasData = false;

	Object.keys( fields ).forEach( function ( key ) {
		const producer = producers[ fields[ key ] ];
		if ( 'function' !== typeof producer ) {
			return;
		}

		const value = producer();
		if ( '' !== value && undefined !== value && null !== value ) {
			push[ key ] = value;
			hasData = true;
		}
	} );

	if ( hasData ) {
		window[ datalayerName ] = window[ datalayerName ] || [];
		window[ datalayerName ].push( push );
	}
} )();
