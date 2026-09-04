<?php
/**
 * The contract of the attribution capture cookies.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

defined( 'ABSPATH' ) || exit;

/**
 * Names, format version, lifetime and size caps of the two first-party cookies
 * the capture script writes and the order-creation hooks read back.
 *
 * Both ends of this contract are ours, so it gets exactly one definition: the
 * capture bundle receives these values in its printed config rather than
 * repeating them as literals, and the server-side parser validates against the
 * same constants. A value written by the browser and read by PHP is the RI-14
 * class of bug - the two ends silently disagreeing - and a shared definition is
 * what keeps that from happening.
 *
 * The IDs and the consent map are deliberately two separate cookies: the
 * consent map is written even when nothing else may be (it is the record of
 * the visitor's choice, on the same strictly-necessary basis a consent tool
 * stores its own answer), while the IDs are written only when that choice
 * allows it. One cookie could not express that.
 *
 * Loaded on frontend requests, so no translated strings here.
 */
final class AttributionCookies {

	/**
	 * Cookie carrying the resolved Google IDs and the click IDs of the visit.
	 */
	public const IDS_COOKIE = 'gtm4wp_gdm_ids';

	/**
	 * Cookie carrying the observed consent-mode signal map.
	 */
	public const CONSENT_COOKIE = 'gtm4wp_gdm_consent';

	/**
	 * Format version of both cookie payloads, stored as the `v` member.
	 *
	 * A payload without this exact value is discarded unread: it is either a
	 * leftover from an older format or something a visitor wrote by hand, and
	 * neither is worth a compatibility branch.
	 */
	public const FORMAT_VERSION = 1;

	/**
	 * Lifetime of both cookies, in days.
	 *
	 * Long enough to cover the gap between an ad click and the purchase it
	 * leads to, short enough that stale attribution expires on its own. The
	 * values are refreshed on every page that resolves them, so an active
	 * visitor never runs into the limit.
	 */
	public const LIFETIME_DAYS = 90;

	/**
	 * Longest cookie payload the parser will look at, in bytes.
	 *
	 * Checked before any decode, so a multi-kilobyte crafted value costs a
	 * length comparison rather than a JSON parse (the read_item_list_cookie
	 * precedent).
	 */
	public const MAX_BYTES = 2048;

	/**
	 * Nesting depth allowed when decoding, counted the way json_decode counts:
	 * the ids payload is {v, client_id, sessions: {...}, gclid, ...}, so its
	 * deepest legitimate structure is three levels.
	 */
	public const MAX_DEPTH = 4;

	/**
	 * Payload member carrying the Google Analytics client id.
	 */
	public const KEY_CLIENT_ID = 'client_id';

	/**
	 * Payload member carrying the per-measurement-id session ids.
	 */
	public const KEY_SESSIONS = 'sessions';

	/**
	 * Payload member carrying the capture timestamp of the consent map.
	 */
	public const KEY_CAPTURED_AT = 'captured_at';

	/**
	 * Payload member carrying the consent signal map.
	 */
	public const KEY_SIGNALS = 'signals';

	/**
	 * The click id parameters captured from the landing URL, in the order they
	 * are looked for. These are Google's parameter names, registered upstream:
	 * `gclid` for Google Ads, `gbraid` and `wbraid` for the iOS app-to-web and
	 * web-to-app journeys. Google's own `_gcl_*` cookies are deliberately not
	 * read - their format carries no stability promise.
	 *
	 * @var string[]
	 */
	public const CLICK_ID_PARAMS = array( 'gclid', 'gbraid', 'wbraid' );

	/**
	 * The configuration the capture bundle needs to speak this format.
	 *
	 * @return array<string, mixed>
	 */
	public static function script_config(): array {
		return array(
			'idsCookie'     => self::IDS_COOKIE,
			'consentCookie' => self::CONSENT_COOKIE,
			'version'       => self::FORMAT_VERSION,
			'lifetimeDays'  => self::LIFETIME_DAYS,
			'maxBytes'      => self::MAX_BYTES,
			'clickIds'      => self::CLICK_ID_PARAMS,
		);
	}
}
