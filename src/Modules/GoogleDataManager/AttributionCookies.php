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
 * the capture script writes and the order-creation hooks read back. One
 * definition for both ends (RI-14): the bundle gets these values in its
 * printed config. The IDs and the consent map are separate cookies on
 * purpose: the map is written even when nothing else may be (the record of
 * the choice, on a strictly-necessary basis), the IDs only when it allows.
 * Loaded on frontend requests: no translated strings here.
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
	 * Format version of both payloads (`v`); anything else is discarded unread.
	 */
	public const FORMAT_VERSION = 1;

	/**
	 * Lifetime of both cookies, in days: covers the click-to-purchase gap,
	 * refreshed on every page that resolves them.
	 */
	public const LIFETIME_DAYS = 90;

	/**
	 * Longest cookie payload the parser looks at, in bytes; checked before any decode.
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
	 * The click id parameters captured from the landing URL (Google's names,
	 * registered upstream). Google's own `_gcl_*` cookies are deliberately not
	 * read: their format carries no stability promise.
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
