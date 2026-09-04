<?php
/**
 * Reads the attribution cookies and turns them into order meta.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

defined( 'ABSPATH' ) || exit;

/**
 * The platform-neutral half of the capture: parse what the browser stored,
 * validate it strictly, and hand the resulting meta pairs to a per-platform
 * writer. Only the hook wiring differs between WooCommerce and Easy Digital
 * Downloads; everything decided here is decided once for both.
 *
 * The parsers reject rather than repair. These cookies are ours by format, but
 * a cookie is attacker-writable whoever defined it, so every field is matched
 * against a grammar and dropped when it does not fit - a "cleaned up" value
 * would be an invented one, and an invented ID produces an event that can
 * never be matched to anything.
 *
 * Absent values are omitted from the meta entirely. There is no empty-string
 * placeholder: a later send has to be able to tell "no client ID was ever
 * resolved" from "the client ID is the empty string", and only an absent key
 * says the first one.
 */
final class AttributionCapture {

	/**
	 * Order meta carrying the Google Analytics client id.
	 */
	public const META_CLIENT_ID = '_gtm4wp_ga_client_id';

	/**
	 * Order meta carrying the per-measurement-id session ids.
	 */
	public const META_SESSION_IDS = '_gtm4wp_ga_session_ids';

	/**
	 * Order meta carrying the consent-mode signal map and its timestamp.
	 */
	public const META_CONSENT_STATE = '_gtm4wp_consent_state';

	/**
	 * Meta key prefix of the captured click ids (`_gtm4wp_gclid` and friends).
	 */
	public const META_CLICK_ID_PREFIX = '_gtm4wp_';

	/**
	 * Grammar of a Google Analytics client or session id.
	 *
	 * Deliberately generous - a client id looks like `1234567890.1234567890`
	 * and a session id like `1788522496` today, but pinning those exact shapes
	 * would turn a future Google format into what looks like a plugin bug
	 * (UC-5). What this rules out is everything that could matter downstream:
	 * quotes, angle brackets, whitespace, control characters.
	 */
	public const ID_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/D';

	/**
	 * Grammar of a click id. Same reasoning, with room for the long opaque
	 * values Google Ads produces.
	 */
	public const CLICK_ID_PATTERN = '/^[A-Za-z0-9._-]{1,512}$/D';

	/**
	 * Grammar of a consent signal name.
	 */
	public const SIGNAL_NAME_PATTERN = '/^[a-z_]{1,40}$/D';

	/**
	 * The only two values a consent signal may carry.
	 *
	 * @var string[]
	 */
	public const SIGNAL_VALUES = array( 'granted', 'denied' );

	/**
	 * Most consent signals kept for one order. Consent mode v2 defines seven;
	 * the cap is what keeps a crafted cookie from turning into unbounded meta.
	 */
	public const MAX_SIGNALS = 20;

	/**
	 * Most session ids kept for one order - one per configured data stream.
	 */
	public const MAX_SESSIONS = 20;

	/**
	 * All meta keys this feature writes, which is also the list the privacy
	 * exporter and eraser work from. One definition: a key added here without
	 * the privacy wiring following would leave personal data behind a request
	 * that claims to have removed everything.
	 *
	 * @return string[]
	 */
	public static function meta_keys(): array {
		$keys = array(
			self::META_CLIENT_ID,
			self::META_SESSION_IDS,
			self::META_CONSENT_STATE,
		);

		foreach ( AttributionCookies::CLICK_ID_PARAMS as $param ) {
			$keys[] = self::META_CLICK_ID_PREFIX . $param;
		}

		return $keys;
	}

	/**
	 * Decodes one of the capture cookies.
	 *
	 * The size is checked before the decode, so an oversized crafted value
	 * costs a length comparison instead of a JSON parse, and the format version
	 * is checked before anything is read out of it.
	 *
	 * @param string $cookie_name Cookie to read.
	 * @return array<string, mixed>|null The decoded payload, or null when absent or unusable.
	 */
	private static function decode( string $cookie_name ): ?array {
		if ( ! isset( $_COOKIE[ $cookie_name ] ) ) {
			return null;
		}

		// The raw value is a JSON container, not a value used at any output
		// sink: it is decoded below and every field extracted from it is
		// validated individually against a grammar. A blanket sanitizer here
		// would corrupt valid JSON without adding any protection (the
		// read_item_list_cookie precedent).
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = wp_unslash( $_COOKIE[ $cookie_name ] );

		if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > AttributionCookies::MAX_BYTES ) {
			return null;
		}

		$decoded = json_decode( rawurldecode( $raw ), true, AttributionCookies::MAX_DEPTH );

		if ( ! is_array( $decoded ) ) {
			return null;
		}

		if ( ! isset( $decoded['v'] ) || AttributionCookies::FORMAT_VERSION !== $decoded['v'] ) {
			return null;
		}

		return $decoded;
	}

	/**
	 * Whether a value is a string matching a grammar.
	 *
	 * @param mixed  $value   Value to check.
	 * @param string $pattern Anchored pattern.
	 * @return bool
	 */
	private static function matches( $value, string $pattern ): bool {
		return is_string( $value ) && 1 === preg_match( $pattern, $value );
	}

	/**
	 * Parses the IDs cookie into validated values.
	 *
	 * @return array<string, mixed> Only the members that passed validation.
	 */
	public static function parse_ids(): array {
		$decoded = self::decode( AttributionCookies::IDS_COOKIE );

		if ( null === $decoded ) {
			return array();
		}

		$parsed = array();

		if ( isset( $decoded[ AttributionCookies::KEY_CLIENT_ID ] )
			&& self::matches( $decoded[ AttributionCookies::KEY_CLIENT_ID ], self::ID_PATTERN ) ) {
			$parsed[ AttributionCookies::KEY_CLIENT_ID ] = $decoded[ AttributionCookies::KEY_CLIENT_ID ];
		}

		if ( isset( $decoded[ AttributionCookies::KEY_SESSIONS ] ) && is_array( $decoded[ AttributionCookies::KEY_SESSIONS ] ) ) {
			$sessions = array();

			foreach ( $decoded[ AttributionCookies::KEY_SESSIONS ] as $measurement_id => $session_id ) {
				if ( count( $sessions ) >= self::MAX_SESSIONS ) {
					break;
				}

				// The key is a measurement id, held to the same grammar the
				// settings table validates, so a crafted map cannot introduce
				// keys of any other shape.
				if ( ! is_string( $measurement_id )
					|| 1 !== preg_match( DestinationRows::MEASUREMENT_PATTERN, $measurement_id ) ) {
					continue;
				}

				if ( ! self::matches( $session_id, self::ID_PATTERN ) ) {
					continue;
				}

				$sessions[ $measurement_id ] = $session_id;
			}

			if ( array() !== $sessions ) {
				$parsed[ AttributionCookies::KEY_SESSIONS ] = $sessions;
			}
		}

		foreach ( AttributionCookies::CLICK_ID_PARAMS as $param ) {
			if ( isset( $decoded[ $param ] ) && self::matches( $decoded[ $param ], self::CLICK_ID_PATTERN ) ) {
				$parsed[ $param ] = $decoded[ $param ];
			}
		}

		return $parsed;
	}

	/**
	 * Parses the consent cookie into a validated signal map.
	 *
	 * @return array<string, mixed>|null The map with its timestamp, or null when nothing usable was stored.
	 */
	public static function parse_consent(): ?array {
		$decoded = self::decode( AttributionCookies::CONSENT_COOKIE );

		if ( null === $decoded || ! isset( $decoded[ AttributionCookies::KEY_SIGNALS ] ) ) {
			return null;
		}

		if ( ! is_array( $decoded[ AttributionCookies::KEY_SIGNALS ] ) ) {
			return null;
		}

		$signals = array();

		foreach ( $decoded[ AttributionCookies::KEY_SIGNALS ] as $name => $value ) {
			if ( count( $signals ) >= self::MAX_SIGNALS ) {
				break;
			}

			if ( ! is_string( $name ) || 1 !== preg_match( self::SIGNAL_NAME_PATTERN, $name ) ) {
				continue;
			}

			if ( ! is_string( $value ) || ! in_array( $value, self::SIGNAL_VALUES, true ) ) {
				continue;
			}

			$signals[ $name ] = $value;
		}

		$captured_at = 0;
		if ( isset( $decoded[ AttributionCookies::KEY_CAPTURED_AT ] ) && is_int( $decoded[ AttributionCookies::KEY_CAPTURED_AT ] ) ) {
			$captured_at = max( 0, $decoded[ AttributionCookies::KEY_CAPTURED_AT ] );
		}

		return array(
			AttributionCookies::KEY_SIGNALS     => $signals,
			AttributionCookies::KEY_CAPTURED_AT => $captured_at,
		);
	}

	/**
	 * Builds the meta pairs to store for one order.
	 *
	 * The consent map runs through a filter before it is stored, which is the
	 * escape hatch for a consent tool that keeps the visitor's choice entirely
	 * inside the GTM container, where the page carries no reflection of it and
	 * the capture script has nothing to observe.
	 *
	 * @param mixed $order_reference The platform's order object or id, passed to the filter.
	 * @return array<string, mixed> Meta key to value; absent captures are absent keys.
	 */
	public static function meta_for_order( $order_reference ): array {
		$ids  = self::parse_ids();
		$meta = array();

		if ( isset( $ids[ AttributionCookies::KEY_CLIENT_ID ] ) ) {
			$meta[ self::META_CLIENT_ID ] = $ids[ AttributionCookies::KEY_CLIENT_ID ];
		}

		if ( isset( $ids[ AttributionCookies::KEY_SESSIONS ] ) ) {
			$meta[ self::META_SESSION_IDS ] = $ids[ AttributionCookies::KEY_SESSIONS ];
		}

		foreach ( AttributionCookies::CLICK_ID_PARAMS as $param ) {
			if ( isset( $ids[ $param ] ) ) {
				$meta[ self::META_CLICK_ID_PREFIX . $param ] = $ids[ $param ];
			}
		}

		/**
		 * Filters the consent state stored with an order.
		 *
		 * Receives the map parsed from the visitor's consent cookie, or null
		 * when none was captured, together with the order it belongs to.
		 * Returning null stores nothing, which stays distinguishable from an
		 * empty map: absent means unknown, never denied.
		 *
		 * @since 2.1.0
		 *
		 * @param array|null $consent         The parsed consent state, or null.
		 * @param mixed      $order_reference The order the state belongs to.
		 */
		$consent = apply_filters( GTM4WP_WPFILTER_GDM_ORDER_CONSENT, self::parse_consent(), $order_reference );

		if ( is_array( $consent ) ) {
			$meta[ self::META_CONSENT_STATE ] = $consent;
		}

		return $meta;
	}

	/**
	 * Whether a set of captured meta carries what a send would need.
	 *
	 * Used for the capture-rate counters: without a client id nothing can be
	 * matched in Google Analytics, so an order that stored only a click id
	 * counts as seen but not as captured.
	 *
	 * @param array<string, mixed> $meta The meta built for an order.
	 * @return bool
	 */
	public static function is_usable( array $meta ): bool {
		return isset( $meta[ self::META_CLIENT_ID ] );
	}
}
