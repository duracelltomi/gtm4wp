<?php
/**
 * Pins CoreInputDouble to core's own recorded verdicts (T158).
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Abilities;

use PHPUnit\Framework\TestCase;

/**
 * The double is only as good as its agreement with core, so it is replayed
 * against core-input-cases.json, which tools/generate-core-input-cases.php
 * writes from a real core tree (U155). A disagreement means the double or the
 * fixture is stale; regenerate the fixture first.
 */
final class CoreInputDoubleTest extends TestCase {

	/**
	 * The recorded fixture.
	 *
	 * @return array<string, mixed>
	 */
	private static function fixture(): array {
		$json = file_get_contents( __DIR__ . '/core-input-cases.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local fixture.

		return json_decode( (string) $json, true, 512, JSON_THROW_ON_ERROR );
	}

	public function test_the_fixture_is_core_output_of_a_plausible_size(): void {
		$fixture = self::fixture();
		$valid   = count( array_filter( $fixture['cases'], static fn ( array $one ): bool => $one['valid'] ) );

		$this->assertMatchesRegularExpression( '/^\d+\.\d+/', $fixture['wp_version'] );
		$this->assertGreaterThanOrEqual( 500, count( $fixture['cases'] ), 'Every schema x value case is present.' );
		$this->assertGreaterThan( 100, $valid, 'Core accepted a share of the corpus.' );
		$this->assertGreaterThan( 300, count( $fixture['cases'] ) - $valid, 'Core refused a share of the corpus.' );
		$this->assertArrayHasKey( 'object, strict', $fixture['schemas'], 'The last schema written is present (UD-14 sentinel).' );
	}

	public function test_every_modelled_keyword_is_exercised_by_the_fixture(): void {
		$used = array();
		$walk = static function ( array $schema ) use ( &$walk, &$used ): void {
			foreach ( $schema as $keyword => $member ) {
				$used[ $keyword ] = true;
				if ( 'properties' === $keyword ) {
					array_map( $walk, (array) $member );
				} elseif ( 'items' === $keyword ) {
					$walk( (array) $member );
				}
			}
		};

		array_map( $walk, self::fixture()['schemas'] );

		$this->assertEqualsCanonicalizing( CoreInputDouble::KEYWORDS, array_keys( $used ) );
	}

	public function test_the_double_reproduces_every_verdict_and_cast_core_recorded(): void {
		$fixture = self::fixture();

		foreach ( $fixture['cases'] as $case ) {
			$schema = $fixture['schemas'][ $case['schema'] ];
			$label  = $case['schema'] . ' <- ' . json_encode( $case['value'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- a failure label.

			$verdict = CoreInputDouble::validate( $case['value'], $schema );

			$this->assertSame( $case['valid'], true === $verdict, "Verdict: $label" );

			if ( $case['valid'] ) {
				$this->assertSame( $case['cast'], CoreInputDouble::cast( $case['value'], $schema ), "Cast: $label" );
			}
		}
	}
}
