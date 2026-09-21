<?php
/**
 * Consent gate of the Google Data Manager send lanes.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

use GTM4WP\Google\ConsentRegions;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether an order's stored consent state allows sending an event
 * about it, given the site's policy and the billing country. Two tested
 * properties: **unknown is not denied** (an absent state withholds nothing
 * where the policy does not apply), and **where the policy applies only a
 * granted analytics_storage passes** (with it denied the client id can be
 * ephemeral and the event permanently unmatchable).
 */
final class ConsentPolicy {

	/**
	 * Consent signals are required only for buyers in the countries Google's
	 * EU user consent policy covers.
	 */
	public const POLICY_EEA_ONLY = 'eea-only';

	/**
	 * Consent signals are required for every order.
	 */
	public const POLICY_ALWAYS = 'always';

	/**
	 * No consent gate; the site owner asserts their own lawful basis.
	 */
	public const POLICY_NEVER = 'never';

	/**
	 * The consent-mode signal that gates the Google Analytics lane.
	 */
	public const SIGNAL_ANALYTICS = 'analytics_storage';

	/**
	 * The signal value that passes the gate.
	 */
	public const GRANTED = 'granted';

	/**
	 * Reason code: the gate does not apply to this order.
	 */
	public const REASON_NOT_REQUIRED = 'consent_not_required';

	/**
	 * Reason code: the required signal was granted.
	 */
	public const REASON_GRANTED = 'consent_granted';

	/**
	 * Reason code: the required signal was denied.
	 */
	public const REASON_DENIED = 'consent_denied';

	/**
	 * Reason code: no consent state was captured for this order.
	 */
	public const REASON_UNKNOWN = 'consent_unknown';

	/**
	 * All valid policy values, in the order the settings select shows them.
	 *
	 * @return string[]
	 */
	public static function policies(): array {
		return array(
			self::POLICY_EEA_ONLY,
			self::POLICY_ALWAYS,
			self::POLICY_NEVER,
		);
	}

	/**
	 * Whether the consent gate applies to an order at all.
	 *
	 * @param string $policy          One of the POLICY_* values; an unknown value is treated as the eea-only default.
	 * @param string $billing_country Two-letter billing country of the order, empty when not recorded.
	 * @return bool
	 */
	public static function applies( string $policy, string $billing_country ): bool {
		if ( self::POLICY_NEVER === $policy ) {
			return false;
		}

		if ( self::POLICY_ALWAYS === $policy ) {
			return true;
		}

		return ConsentRegions::covers( $billing_country );
	}

	/**
	 * Decides whether an event about this order may be sent.
	 *
	 * @param string                     $policy          One of the POLICY_* values.
	 * @param string                     $billing_country Two-letter billing country of the order, empty when not recorded.
	 * @param array<string, string>|null $consent_state   The stored consent-mode signal map, or null when none was captured.
	 * @return array{allowed: bool, reason: string}
	 */
	public static function decide( string $policy, string $billing_country, ?array $consent_state ): array {
		if ( ! self::applies( $policy, $billing_country ) ) {
			return array(
				'allowed' => true,
				'reason'  => self::REASON_NOT_REQUIRED,
			);
		}

		if ( null === $consent_state || ! array_key_exists( self::SIGNAL_ANALYTICS, $consent_state ) ) {
			return array(
				'allowed' => false,
				'reason'  => self::REASON_UNKNOWN,
			);
		}

		if ( self::GRANTED !== $consent_state[ self::SIGNAL_ANALYTICS ] ) {
			return array(
				'allowed' => false,
				'reason'  => self::REASON_DENIED,
			);
		}

		return array(
			'allowed' => true,
			'reason'  => self::REASON_GRANTED,
		);
	}
}
