<?php
/**
 * Unit tests for the Google Data Manager consent gate.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use GTM4WP\Modules\GoogleDataManager\ConsentPolicy;
use GTM4WP\Tests\unit\TestCase;

/**
 * The full policy matrix: (three policies) x (covered / not covered billing
 * country) x (granted / denied / unknown analytics_storage). Every cell is
 * asserted on both halves of the answer - whether the send happens AND the
 * reason code that goes into the diagnostics log - because a send that stops
 * with the wrong reason is a support thread nobody can close.
 */
final class GoogleDataManagerConsentPolicyTest extends TestCase {

	private const GRANTED = array( 'analytics_storage' => 'granted' );
	private const DENIED  = array( 'analytics_storage' => 'denied' );

	/**
	 * Every cell of the matrix.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, string>|null, 3: bool, 4: string}>
	 */
	public static function matrix(): array {
		return array(
			// eea-only, buyer inside the policy region: the gate applies.
			'eea-only / DE / granted' => array( ConsentPolicy::POLICY_EEA_ONLY, 'DE', self::GRANTED, true, ConsentPolicy::REASON_GRANTED ),
			'eea-only / DE / denied'  => array( ConsentPolicy::POLICY_EEA_ONLY, 'DE', self::DENIED, false, ConsentPolicy::REASON_DENIED ),
			'eea-only / DE / unknown' => array( ConsentPolicy::POLICY_EEA_ONLY, 'DE', null, false, ConsentPolicy::REASON_UNKNOWN ),
			'eea-only / GB / denied'  => array( ConsentPolicy::POLICY_EEA_ONLY, 'GB', self::DENIED, false, ConsentPolicy::REASON_DENIED ),
			'eea-only / CH / unknown' => array( ConsentPolicy::POLICY_EEA_ONLY, 'CH', null, false, ConsentPolicy::REASON_UNKNOWN ),

			// eea-only, buyer outside it: the gate does not apply at all, so
			// even a denial does not stop the send - the site owner's own
			// lawful basis governs there.
			'eea-only / US / granted' => array( ConsentPolicy::POLICY_EEA_ONLY, 'US', self::GRANTED, true, ConsentPolicy::REASON_NOT_REQUIRED ),
			'eea-only / US / denied'  => array( ConsentPolicy::POLICY_EEA_ONLY, 'US', self::DENIED, true, ConsentPolicy::REASON_NOT_REQUIRED ),
			'eea-only / US / unknown' => array( ConsentPolicy::POLICY_EEA_ONLY, 'US', null, true, ConsentPolicy::REASON_NOT_REQUIRED ),
			'eea-only / "" / unknown' => array( ConsentPolicy::POLICY_EEA_ONLY, '', null, true, ConsentPolicy::REASON_NOT_REQUIRED ),

			// always: the country stops mattering.
			'always / DE / granted'   => array( ConsentPolicy::POLICY_ALWAYS, 'DE', self::GRANTED, true, ConsentPolicy::REASON_GRANTED ),
			'always / US / granted'   => array( ConsentPolicy::POLICY_ALWAYS, 'US', self::GRANTED, true, ConsentPolicy::REASON_GRANTED ),
			'always / US / denied'    => array( ConsentPolicy::POLICY_ALWAYS, 'US', self::DENIED, false, ConsentPolicy::REASON_DENIED ),
			'always / US / unknown'   => array( ConsentPolicy::POLICY_ALWAYS, 'US', null, false, ConsentPolicy::REASON_UNKNOWN ),
			'always / "" / unknown'   => array( ConsentPolicy::POLICY_ALWAYS, '', null, false, ConsentPolicy::REASON_UNKNOWN ),

			// never: no gate anywhere, whatever was captured.
			'never / DE / denied'     => array( ConsentPolicy::POLICY_NEVER, 'DE', self::DENIED, true, ConsentPolicy::REASON_NOT_REQUIRED ),
			'never / DE / unknown'    => array( ConsentPolicy::POLICY_NEVER, 'DE', null, true, ConsentPolicy::REASON_NOT_REQUIRED ),
			'never / US / granted'    => array( ConsentPolicy::POLICY_NEVER, 'US', self::GRANTED, true, ConsentPolicy::REASON_NOT_REQUIRED ),
		);
	}

	/**
	 * Every combination of policy, billing country and stored consent state.
	 *
	 * @param string                     $policy          Policy setting.
	 * @param string                     $country         Billing country.
	 * @param array<string, string>|null $state           Stored consent state.
	 * @param bool                       $expected_allow  Whether the send may happen.
	 * @param string                     $expected_reason Reason code.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'matrix' )]
	public function test_the_matrix( string $policy, string $country, ?array $state, bool $expected_allow, string $expected_reason ): void {
		$decision = ConsentPolicy::decide( $policy, $country, $state );

		$this->assertSame(
			array(
				'allowed' => $expected_allow,
				'reason'  => $expected_reason,
			),
			$decision
		);
	}

	/**
	 * An empty map is not the same as no map, but both mean the same here:
	 * the signal that gates the lane was never observed. Asserted separately
	 * because a `null`-only check would pass while an empty array slipped
	 * through as "granted" from a missing-key read.
	 */
	public function test_a_state_without_the_analytics_signal_is_unknown_not_granted(): void {
		$decision = ConsentPolicy::decide( ConsentPolicy::POLICY_ALWAYS, 'DE', array( 'ad_storage' => 'granted' ) );

		$this->assertFalse( $decision['allowed'] );
		$this->assertSame( ConsentPolicy::REASON_UNKNOWN, $decision['reason'] );

		$empty = ConsentPolicy::decide( ConsentPolicy::POLICY_ALWAYS, 'DE', array() );

		$this->assertFalse( $empty['allowed'] );
		$this->assertSame( ConsentPolicy::REASON_UNKNOWN, $empty['reason'] );
	}

	/**
	 * Only the exact string "granted" passes. Anything else - a truthy value,
	 * a differently cased spelling, the consent-mode wire value of a signal we
	 * did not anticipate - has to fail closed, because the failure mode on the
	 * other side is an unmatchable event sent without a lawful basis.
	 */
	public function test_only_the_exact_granted_value_passes(): void {
		foreach ( array( 'Granted', 'GRANTED', 'true', '1', 'yes', 'granted ', '' ) as $value ) {
			$decision = ConsentPolicy::decide( ConsentPolicy::POLICY_ALWAYS, 'DE', array( 'analytics_storage' => $value ) );

			$this->assertFalse( $decision['allowed'], sprintf( 'The value "%s" must not pass the consent gate.', $value ) );
			$this->assertSame( ConsentPolicy::REASON_DENIED, $decision['reason'] );
		}
	}

	/**
	 * An unrecognised stored policy - a hand-edited option row, or a value
	 * from a future version rolled back - must not open the gate. It reads as
	 * the shipped default instead.
	 */
	public function test_an_unknown_policy_value_behaves_like_the_default(): void {
		$this->assertTrue( ConsentPolicy::applies( 'something-else', 'DE' ) );
		$this->assertFalse( ConsentPolicy::applies( 'something-else', 'US' ) );

		$decision = ConsentPolicy::decide( 'something-else', 'DE', self::DENIED );

		$this->assertFalse( $decision['allowed'] );
		$this->assertSame( ConsentPolicy::REASON_DENIED, $decision['reason'] );
	}

	public function test_applies_answers_the_region_question_per_policy(): void {
		$this->assertTrue( ConsentPolicy::applies( ConsentPolicy::POLICY_EEA_ONLY, 'FR' ) );
		$this->assertFalse( ConsentPolicy::applies( ConsentPolicy::POLICY_EEA_ONLY, 'JP' ) );
		$this->assertTrue( ConsentPolicy::applies( ConsentPolicy::POLICY_ALWAYS, 'JP' ) );
		$this->assertFalse( ConsentPolicy::applies( ConsentPolicy::POLICY_NEVER, 'FR' ) );
	}

	public function test_the_policy_list_matches_the_three_documented_choices(): void {
		$this->assertSame(
			array( 'eea-only', 'always', 'never' ),
			ConsentPolicy::policies(),
			'The stored option values are part of the settings contract; renaming one silently changes what a saved site does.'
		);
	}
}
