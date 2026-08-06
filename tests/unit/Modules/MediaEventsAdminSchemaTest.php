<?php
/**
 * Unit tests for the media events module admin schema.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\MediaEvents\AdminSchema;
use GTM4WP\Options\Field;
use GTM4WP\Tests\unit\TestCase;

/**
 * Pins the maturity phase of every media player option.
 *
 * `Field::$phase` has no runtime behavior - it only drives the admin badge - so
 * nothing else in the suite notices when a player silently changes phase. The
 * doc block on the `Field::PHASE_*` constants requires promotion to be a
 * deliberate act, which is only enforceable if the map is pinned somewhere: a
 * player added without a `phase:` argument defaults to `PHASE_STABLE` and would
 * otherwise ship badge-less and unnoticed.
 */
final class MediaEventsAdminSchemaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// AdminSchema::fields() only calls the translation/escaping wrappers.
		// Stubbed here rather than relied upon from another test file, because
		// Brain Monkey keeps a defined function for the whole process and test
		// order must not decide whether this file passes.
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
	}

	/**
	 * Returns the schema's `option key => phase` map, in declaration order.
	 *
	 * @return array<string, string>
	 */
	private function phase_map(): array {
		$phases = array();

		foreach ( ( new AdminSchema() )->fields() as $field ) {
			$phases[ $field->key ] = $field->phase;
		}

		return $phases;
	}

	/**
	 * Returns a single field by option key.
	 *
	 * @param string $key Option key.
	 * @return Field
	 */
	private function field( string $key ): Field {
		foreach ( ( new AdminSchema() )->fields() as $field ) {
			if ( $key === $field->key ) {
				return $field;
			}
		}

		$this->fail( "Field '{$key}' not found in the media events admin schema." );
	}

	public function test_media_field_phases_are_pinned(): void {
		$expected = array(
			// Google ships a native YouTube Video trigger; migrate to that.
			GTM4WP_OPTION_EVENTS_YOUTUBE          => Field::PHASE_DEPRECATED,

			// The two players carried over from 1.x, proven in the field.
			GTM4WP_OPTION_EVENTS_VIMEO            => Field::PHASE_STABLE,
			GTM4WP_OPTION_EVENTS_SOUNDCLOUD       => Field::PHASE_STABLE,

			// New in 2.0, no real-world usage yet. HTML5 belongs here too: 1.x
			// shipped no native media tracker at all (only the YouTube, Vimeo
			// and SoundCloud ones), so this is a new player, not a promotion.
			GTM4WP_OPTION_EVENTS_HTML5MEDIA       => Field::PHASE_EXPERIMENTAL,
			GTM4WP_OPTION_EVENTS_DAILYMOTION      => Field::PHASE_EXPERIMENTAL,
			GTM4WP_OPTION_EVENTS_MIXCLOUD         => Field::PHASE_EXPERIMENTAL,
			GTM4WP_OPTION_EVENTS_CLOUDFLARESTREAM => Field::PHASE_EXPERIMENTAL,
			GTM4WP_OPTION_EVENTS_WISTIA           => Field::PHASE_EXPERIMENTAL,
			GTM4WP_OPTION_EVENTS_JWPLAYER         => Field::PHASE_EXPERIMENTAL,
			GTM4WP_OPTION_EVENTS_VIDEOPRESS       => Field::PHASE_EXPERIMENTAL,
			GTM4WP_OPTION_EVENTS_SPOTIFY          => Field::PHASE_EXPERIMENTAL,
			GTM4WP_OPTION_EVENTS_TWITCH           => Field::PHASE_EXPERIMENTAL,
			GTM4WP_OPTION_EVENTS_MEDIA_DYNAMIC    => Field::PHASE_EXPERIMENTAL,
		);

		$this->assertSame(
			$expected,
			$this->phase_map(),
			'Media option phases drifted. Promotion is deliberate: update this map together with the AdminSchema and a CHANGELOG bullet.'
		);
	}

	public function test_html5_media_is_an_off_by_default_experimental_checkbox(): void {
		$field = $this->field( GTM4WP_OPTION_EVENTS_HTML5MEDIA );

		$this->assertSame( Field::TYPE_CHECKBOX, $field->type );
		$this->assertFalse(
			$field->default_value,
			'An experimental option must stay off by default - it is the half of the contract the phase map does not cover.'
		);
		$this->assertSame( Field::PHASE_EXPERIMENTAL, $field->phase );
	}
}
