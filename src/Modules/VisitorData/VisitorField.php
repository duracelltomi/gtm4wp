<?php
/**
 * Visitor-scoped data layer field descriptor.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\VisitorData;

defined( 'ABSPATH' ) || exit;

/**
 * Describes one data layer value specific to the visitor or session rather
 * than the URL, so it must not be baked into full-page-cached HTML (issue
 * #398). Declared through GTM4WP_WPFILTER_VISITOR_SCOPED_FIELDS with a tier:
 *
 * - TIER_CLIENT: the browser knows the value; pushed client-side with no
 *   request ($client_source names the producer in gtm4wp-visitor-data.js).
 * - TIER_SESSION: server-only but constant per session (IP, country); fetched
 *   once per session from the endpoint, cached in sessionStorage.
 * - TIER_ACTION: server-only, changes on an action (user data, one-shots);
 *   fetched when $cookie_gate (a JS-readable companion cookie, never the
 *   HttpOnly auth cookie) changes. A $one_shot fires EXACTLY once: fetched only
 *   while its event cookie exists, pushed as its own event with a de-dupe
 *   guard, never cached or replayed (config `actions`, not `gates`), with an
 *   optional $confirm_url POST beacon so the GET stays side-effect-free.
 *
 * $resolver is the field's own identity gate: it returns null to omit the
 * field (a user field on an anonymous request).
 *
 * WooCommerce customer/cart data ride the cart-fragments AJAX instead (see
 * WooCommerce\PageDataLayer) and must STAY off this list: every non-one-shot
 * field here merges into the single EVENT_VISITOR_DATA push, which would move
 * them off their own event.
 */
final class VisitorField {

	/**
	 * The browser knows the value; pushed client-side, no endpoint, no leak.
	 */
	public const TIER_CLIENT = 1;

	/**
	 * Server-only but constant per session; once-per-session endpoint fetch.
	 */
	public const TIER_SESSION = 2;

	/**
	 * Server-only, changes on an action; cookie-gated endpoint fetch.
	 */
	public const TIER_ACTION = 3;

	/**
	 * Constructor.
	 *
	 * @param string $key           Data layer variable name (identical to the server-rendered name).
	 * @param int    $tier          One of the TIER_* constants.
	 * @param string $client_source Tier 1 only: the producer token the client runtime uses.
	 * @param mixed  $resolver      Tier 2/3 only: callable resolving the value on the endpoint,
	 *                              null to omit. Untyped so array callables are accepted.
	 * @param string $cookie_gate   Tier 3 only: the JS-readable cookie whose change triggers a
	 *                              re-fetch (empty = Tier 2).
	 * @param bool   $one_shot      Tier 3 only: a one-shot EVENT (see the class docblock).
	 * @param string $confirm_url   One-shot only: URL of the POST beacon fired after delivery
	 *                              (empty = no beacon).
	 */
	public function __construct(
		public string $key,
		public int $tier,
		public string $client_source = '',
		public mixed $resolver = null,
		public string $cookie_gate = '',
		public bool $one_shot = false,
		public string $confirm_url = ''
	) {
	}
}
