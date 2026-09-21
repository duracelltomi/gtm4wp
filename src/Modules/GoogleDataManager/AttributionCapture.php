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
 * validate it strictly, hand the meta pairs to a per-platform writer. The
 * parsers reject rather than repair (a cookie is attacker-writable whoever
 * defined it, and a "cleaned up" ID is an invented one), and absent values
 * are omitted, never stored as '', so a later send can tell "never resolved"
 * from "empty".
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
	 * Grammar of a Google Analytics client or session id. Deliberately generous
	 * (UC-5): it rules out quotes, brackets, whitespace and control characters,
	 * not a future Google format.
	 */
	public const ID_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/D';

	/**
	 * Grammar of a click id, with room for Google Ads' long opaque values.
	 */
	public const CLICK_ID_PATTERN = '/^[A-Za-z0-9._-]{1,512}$/D';

	/**
	 * Grammar of a consent signal name.
	 */
	public const SIGNAL_NAME_PATTERN = '/^[a-z_]{1,40}$/D';

	/**
	 * The consent-mode signal gating the Google Analytics identifiers.
	 */
	public const SIGNAL_ANALYTICS = 'analytics_storage';

	/**
	 * The consent-mode signal gating the Google Ads click identifiers.
	 */
	public const SIGNAL_ADS = 'ad_storage';

	/**
	 * The signal value that permits storing.
	 */
	public const CONSENT_GRANTED = 'granted';

	/**
	 * The only two values a consent signal may carry.
	 *
	 * @var string[]
	 */
	public const SIGNAL_VALUES = array( 'granted', 'denied' );

	/**
	 * Most consent signals kept per order (consent mode v2 defines seven); the
	 * cap keeps a crafted cookie from becoming unbounded meta.
	 */
	public const MAX_SIGNALS = 20;

	/**
	 * Most session ids kept per order, one per configured data stream.
	 */
	public const MAX_SESSIONS = 20;

	/**
	 * All meta keys this feature writes, which is also what the privacy
	 * exporter and eraser work from - one definition, or an erasure request
	 * would leave personal data behind.
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
	 * Decodes one of the capture cookies: size checked before the parse,
	 * format version before anything is read.
	 *
	 * @param string $cookie_name Cookie to read.
	 * @return array<string, mixed>|null The decoded payload, or null when absent or unusable.
	 */
	private static function decode( string $cookie_name ): ?array {
		if ( ! isset( $_COOKIE[ $cookie_name ] ) ) {
			return null;
		}

		// A JSON container, not an output value: every extracted field is
		// validated against a grammar; a blanket sanitizer would corrupt it.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = wp_unslash( $_COOKIE[ $cookie_name ] );

		if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > AttributionCookies::MAX_BYTES ) {
			return null;
		}

		// No URL decoding: PHP already decoded the percent-encoded payload once,
		// and a second decode rewrites any value containing a percent sign.
		$decoded = json_decode( $raw, true, AttributionCookies::MAX_DEPTH );

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

		return self::validate_ids( $decoded );
	}

	/**
	 * Validates an ID payload however it arrived; shared by the cookie parser
	 * and the backfill route so one grammar cannot drift against itself (UC-6).
	 *
	 * @param array<string, mixed> $decoded The raw payload.
	 * @return array<string, mixed> Only the members that passed validation.
	 */
	private static function validate_ids( array $decoded ): array {
		$parsed = array();

		if ( isset( $decoded[ AttributionCookies::KEY_CLIENT_ID ] )
			&& self::matches( $decoded[ AttributionCookies::KEY_CLIENT_ID ], self::ID_PATTERN ) ) {
			$parsed[ AttributionCookies::KEY_CLIENT_ID ] = $decoded[ AttributionCookies::KEY_CLIENT_ID ];
		}

		if ( isset( $decoded[ AttributionCookies::KEY_SESSIONS ] ) && is_array( $decoded[ AttributionCookies::KEY_SESSIONS ] ) ) {
			$sessions = array();

			// Sliced, not counted while looping: the cap must bound the work, not
			// only the result (the backfill route parses an unbounded JSON body).
			foreach ( array_slice( $decoded[ AttributionCookies::KEY_SESSIONS ], 0, self::MAX_SESSIONS, true ) as $measurement_id => $session_id ) {
				// The key is a measurement id, held to the settings table's grammar.
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

		if ( null === $decoded ) {
			return null;
		}

		return self::validate_consent( $decoded );
	}

	/**
	 * Validates a consent payload however it arrived.
	 *
	 * @param array<string, mixed> $decoded The raw payload.
	 * @return array<string, mixed>|null The map with its timestamp, or null when unusable.
	 */
	private static function validate_consent( array $decoded ): ?array {
		if ( ! isset( $decoded[ AttributionCookies::KEY_SIGNALS ] ) || ! is_array( $decoded[ AttributionCookies::KEY_SIGNALS ] ) ) {
			return null;
		}

		$signals = array();

		// Sliced for the same reason as the session map above.
		foreach ( array_slice( $decoded[ AttributionCookies::KEY_SIGNALS ], 0, self::MAX_SIGNALS, true ) as $name => $value ) {
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
	 * Validates values posted to the backfill route, keyed by meta key: the
	 * cookie's grammar mapped onto the fixed meta keys, so a caller can never
	 * introduce a key of their own into order meta.
	 *
	 * @param array<string, mixed> $payload The posted values.
	 * @return array<string, mixed> Meta key to value.
	 */
	public static function parse_payload( array $payload ): array {
		$ids  = self::validate_ids( $payload );
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

		return $meta;
	}

	/**
	 * Validates a consent map posted to the backfill route.
	 *
	 * @param array<string, mixed> $payload The posted consent state.
	 * @return array<string, mixed>|null The validated state, or null when unusable.
	 */
	public static function parse_consent_payload( array $payload ): ?array {
		return self::validate_consent( $payload );
	}

	/**
	 * Builds the meta pairs to store for one order. The consent map runs
	 * through a filter first: the escape hatch for a consent tool that keeps
	 * the choice entirely inside the GTM container.
	 *
	 * @param \WC_Order|int $order_reference The order, passed to the filter: the WC_Order object on WooCommerce, the order id (int) on Easy Digital Downloads.
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

		$consent = self::filter_consent( self::parse_consent(), $order_reference );
		$meta    = self::apply_consent_gate( $meta, $consent );

		if ( null !== $consent ) {
			$meta[ self::META_CONSENT_STATE ] = $consent;
		}

		return $meta;
	}

	/**
	 * Runs a consent state through the site's override filter. Every storing
	 * path goes through here, the backfill path included: a route taking the
	 * posted map verbatim would let a buyer hand us the answer the send gate
	 * reads. The order reference has one type per platform on every path
	 * (WC_Order on WooCommerce, the order id on EDD), so a callback sees the
	 * same thing at creation and on backfill (RI-31).
	 *
	 * @param array<string, mixed>|null $consent         The parsed consent state, or null.
	 * @param \WC_Order|int             $order_reference The order it belongs to: the WC_Order object, or the EDD order id.
	 * @return array<string, mixed>|null
	 */
	public static function filter_consent( ?array $consent, $order_reference ): ?array {
		/**
		 * Filters the consent state stored with an order. Returning null stores
		 * nothing, which stays distinguishable from an empty map: absent means
		 * unknown, never denied.
		 *
		 * @since 2.1.0
		 *
		 * @param array|null    $consent         The parsed consent state, or null.
		 * @param \WC_Order|int $order_reference The order the state belongs to: the WC_Order object on WooCommerce, the order id on Easy Digital Downloads - the same type at order creation and on the receipt-page backfill.
		 */
		$filtered = apply_filters( GTM4WP_WPFILTER_GDM_ORDER_CONSENT, $consent, $order_reference );

		return is_array( $filtered ) ? $filtered : null;
	}

	/**
	 * Drops the values a consent state does not allow storing. The browser
	 * applies the same rule before writing, but a consent tool that blocks our
	 * bundle leaves an earlier visit's cookie to be read here unchecked, so the
	 * rule is repeated on the side that cannot be bypassed. Only an observed
	 * `denied` drops anything: absent means unknown.
	 *
	 * @param array<string, mixed>      $meta    The meta built for an order.
	 * @param array<string, mixed>|null $consent The consent state stored with it.
	 * @return array<string, mixed>
	 */
	public static function apply_consent_gate( array $meta, ?array $consent ): array {
		if ( null === $consent || ! isset( $consent[ AttributionCookies::KEY_SIGNALS ] ) || ! is_array( $consent[ AttributionCookies::KEY_SIGNALS ] ) ) {
			return $meta;
		}

		$signals = $consent[ AttributionCookies::KEY_SIGNALS ];

		if ( isset( $signals[ self::SIGNAL_ANALYTICS ] ) && self::CONSENT_GRANTED !== $signals[ self::SIGNAL_ANALYTICS ] ) {
			unset( $meta[ self::META_CLIENT_ID ], $meta[ self::META_SESSION_IDS ] );
		}

		if ( isset( $signals[ self::SIGNAL_ADS ] ) && self::CONSENT_GRANTED !== $signals[ self::SIGNAL_ADS ] ) {
			foreach ( AttributionCookies::CLICK_ID_PARAMS as $param ) {
				unset( $meta[ self::META_CLICK_ID_PREFIX . $param ] );
			}
		}

		return $meta;
	}

	/**
	 * Whether captured meta carries what a send would need (the capture-rate
	 * counters): without a client id nothing can be matched in Analytics.
	 *
	 * @param array<string, mixed> $meta The meta built for an order.
	 * @return bool
	 */
	public static function is_usable( array $meta ): bool {
		return isset( $meta[ self::META_CLIENT_ID ] );
	}
}
