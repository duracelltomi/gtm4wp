/**
 * GTM4WP shared data layer push helper.
 *
 * The one canonical JS-bundle entry point for pushing a GTM4WP event to the
 * data layer. When the consent-aware event queue runtime is on the page
 * (lib/gtm4wp-consent-queue-runtime.js, inlined into the head block by
 * ConsentQueue::add_head_js()), the push routes through it so the event can
 * be held until the visitor's consent choice is available; otherwise it is a
 * plain direct push — byte-for-byte the behavior the trackers had before the
 * queue existed.
 *
 * A guarded twin of this helper lives in PHP-emitted JavaScript —
 * DataLayer::push_snippet() — which cannot import this module; keep the two
 * in sync.
 */

/**
 * Pushes one event object to the GTM4WP data layer, consent-aware when the
 * queue runtime is installed.
 *
 * @param {Object} dlObj The data layer event object.
 * @return {void}
 */
export function gtm4wp_push_dl_event( dlObj ) {
	if ( 'function' === typeof window.gtm4wp_datalayer_push ) {
		window.gtm4wp_datalayer_push( dlObj );
		return;
	}

	const dlName = window.gtm4wp_datalayer_name || 'dataLayer';
	window[ dlName ] = window[ dlName ] || [];
	window[ dlName ].push( dlObj );
}
