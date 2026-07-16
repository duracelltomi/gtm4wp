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
 * Describes one data layer value that is specific to the current visitor or
 * session rather than to the URL, so it must not be baked into full-page-cached
 * HTML (issue #398). A module declares such a field through the
 * GTM4WP_WPFILTER_VISITOR_SCOPED_FIELDS filter together with its delivery tier;
 * the VisitorData module + client runtime decide how it is delivered.
 *
 * The delivery tiers form the framework the cache-safe data layer is built on:
 *
 * - TIER_CLIENT (1): the browser already knows the value, so it is pushed
 *   client-side with no network request and can never leak between visitors.
 *   Delivered in Phase 1 via the gtm4wp.visitorData push; $client_source names
 *   the producer the js/frontend/gtm4wp-visitor-data.js runtime uses to compute
 *   it (e.g. 'referrer', 'searchTerm').
 * - TIER_SESSION (2): server-only but constant for the whole session (e.g. the
 *   visitor IP, the Cloudflare country). Phase 2 fetches it once per session and
 *   caches it in sessionStorage — never an unconditional per-page fetch.
 * - TIER_ACTION (3): server-only and changes on an action (logged-in user data,
 *   the cart, one-shot events). Phase 2 fetches it gated by an existing cookie
 *   (the WP logged-in cookie, a cart-version cookie, an event cookie).
 *
 * Phase 1 implemented Tier 1. Phase 2 adds the Tier 2/3 delivery inputs — a
 * server resolver callable and a cookie gate — as further constructor arguments
 * with defaults, so every Phase 1 caller is unaffected:
 *
 * - $resolver runs on the first-party session endpoint (VisitorDataEndpoint) for
 *   the CURRENT request and returns the field value, or null to omit it. It is
 *   the field's own identity/capability gate: a logged-in-user field's resolver
 *   returns null for an anonymous request, so a logged-out caller never receives
 *   user data.
 * - $cookie_gate names the JS-readable cookie whose change tells the client
 *   runtime to re-fetch this field (Tier 3). An empty gate means Tier 2 delivery:
 *   fetched once per session and cached in sessionStorage. The gate is never the
 *   HttpOnly WordPress auth cookie (JS cannot read it) but a JS-visible companion
 *   cookie the VisitorData module maintains alongside it.
 *
 * Phase 3 adds one further constructor argument for the WooCommerce one-shot
 * events (an add_to_cart after the cart "Undo"; the reliable-purchase fallback):
 *
 * - $one_shot marks a Tier 3 field as a one-shot EVENT rather than a persistent
 *   session value. Unlike a regular gate (which is cached and replayed on every
 *   later page view), a one-shot must fire EXACTLY once: the client fetches it
 *   only while its event cookie is present, pushes it as its own data layer event
 *   with a per-event de-dupe guard, then clears the event cookie — it is never
 *   cached or replayed. The VisitorData module routes one-shot fields into the
 *   config `actions` list instead of `gates`.
 *
 * (WooCommerce customer & cart data are session-scoped and delivered on the
 * existing cart-fragments AJAX instead — see WooCommerce\PageDataLayer — so they
 * are not declared as VisitorField resolvers here.)
 */
final class VisitorField {

	/**
	 * The browser knows the value; pushed client-side, no endpoint, no leak.
	 */
	public const TIER_CLIENT = 1;

	/**
	 * Server-only but constant per session; Phase 2 once-per-session endpoint.
	 */
	public const TIER_SESSION = 2;

	/**
	 * Server-only, changes on an action; Phase 2 cookie-gated endpoint.
	 */
	public const TIER_ACTION = 3;

	/**
	 * Constructor.
	 *
	 * @param string $key           Data layer variable name (kept identical to the
	 *                              server-rendered name so existing GTM setups keep working).
	 * @param int    $tier          One of the TIER_* constants.
	 * @param string $client_source Tier 1 only: the producer token the client runtime
	 *                              uses to compute the value (empty for Tier 2/3).
	 * @param mixed  $resolver      Tier 2/3 only: a callable resolving the field value for
	 *                              the current request on the session endpoint, or null to
	 *                              omit it. Untyped so both closures and [$obj,'method']
	 *                              array callables are accepted (null for Tier 1).
	 * @param string $cookie_gate   Tier 3 only: the JS-readable cookie whose change makes
	 *                              the client re-fetch this field (empty = Tier 2, once per
	 *                              session).
	 * @param bool   $one_shot      Tier 3 only: whether this field is a one-shot EVENT
	 *                              (Phase 3) — fetched only while its event cookie is
	 *                              present, pushed once with a de-dupe guard, then the
	 *                              cookie is cleared; never cached/replayed.
	 */
	public function __construct(
		public string $key,
		public int $tier,
		public string $client_source = '',
		public mixed $resolver = null,
		public string $cookie_gate = '',
		public bool $one_shot = false
	) {
	}
}
