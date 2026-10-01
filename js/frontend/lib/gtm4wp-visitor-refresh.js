/**
 * The document event a store tracker dispatches after a same-page cart change,
 * asking the cache-safe data layer runtime (gtm4wp-visitor-data.js) to re-fetch
 * the customer/cart blocks. One definition for both bundles (UC-6).
 */
export const GTM4WP_VISITOR_REFRESH_EVENT = 'gtm4wp:visitordata-refresh';

/**
 * Dispatches the refresh event on document. Never throws: a tracker must not
 * fail because the runtime is absent or the browser lacks CustomEvent.
 *
 * @return {void}
 */
export function gtm4wp_request_visitor_refresh() {
	try {
		document.dispatchEvent(
			new window.CustomEvent( GTM4WP_VISITOR_REFRESH_EVENT )
		);
	} catch ( e ) {}
}
