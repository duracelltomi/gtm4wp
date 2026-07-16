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
 * Phase 1 only implements Tier 1. The value object is intentionally minimal so
 * Phase 2 can add the Tier 2/3 delivery inputs (a server resolver callable and a
 * cookie gate) as further constructor arguments with defaults, without changing
 * any Phase 1 caller.
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
	 */
	public function __construct(
		public string $key,
		public int $tier,
		public string $client_source = ''
	) {
	}
}
