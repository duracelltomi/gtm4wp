<?php
/**
 * Unit tests for the tag restriction module admin schema.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\Blacklist\AdminSchema;
use GTM4WP\Modules\Blacklist\BlacklistModule;
use GTM4WP\Options\Field;
use GTM4WP\Tests\unit\TestCase;

/**
 * Covers what the admin screen promises about the restriction list, which the
 * frontend module cannot express: that every restrictable entity is actually
 * reachable in the UI, and that the mode values did not move when the labels
 * were reworded to Google's allowlist/blocklist vocabulary.
 */
final class BlacklistAdminSchemaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
	}

	/**
	 * Returns the field owning the given option key.
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

		$this->fail( "Field '{$key}' not found in the tag restriction admin schema." );
	}

	/**
	 * The settings screen and the frontend validator are two copies of one list:
	 * the schema decides what an admin can check, BlacklistModule decides what
	 * survives to the data layer. A disagreement is silent in both directions -
	 * a choice the validator rejects is a checkbox that does nothing, and an
	 * entity the schema omits cannot be restricted at all - so pin them equal
	 * rather than trusting that they were edited together.
	 *
	 * @return void
	 */
	/**
	 * Core-faithful sanitize_text_field: strips tags and trims, which is the
	 * behavior the status sanitizer leans on for a hostile entity id. A
	 * returnArg identity stub would make the hostile-input cases below vacuous
	 * (#131's lesson).
	 *
	 * @return void
	 */
	private function stub_sanitize_text_field(): void {
		Functions\when( 'sanitize_text_field' )->alias(
			static fn ( $value ) => trim( wp_strip_all_tags( (string) $value ) )
		);
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn ( $value ) => trim( strip_tags( (string) $value ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- test stub models core.
		);
	}

	/**
	 * T45: the mode sanitizer was only ever executed by the whole-schema
	 * non-scalar sweep - nothing asserted its clamp, so it could be gutted with
	 * the suite green. The stored value is defense-in-depth: the frontend
	 * re-derives the mode, but the stored copy also reaches
	 * $GLOBALS['gtm4wp_options'] and the admin bootstrap.
	 *
	 * @param mixed $raw      Submitted value.
	 * @param int   $expected Stored result.
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'provide_mode_inputs' )]
	public function test_mode_sanitizer_clamps_to_the_three_valid_modes( $raw, int $expected ): void {
		$this->assertSame( $expected, $this->field( GTM4WP_OPTION_BLACKLIST_ENABLE )->sanitize( $raw ) );
	}

	/**
	 * Out-of-range and junk inputs land on 0 (disabled) - failing closed for a
	 * restriction feature would mean blocking tags nobody selected.
	 *
	 * @return array<string, array{0: mixed, 1: int}>
	 */
	public static function provide_mode_inputs(): array {
		return array(
			'valid blocklist mode'     => array( '1', 1 ),
			'valid allowlist mode'     => array( 2, 2 ),
			'above the range'          => array( 5, 0 ),
			'below the range'          => array( -1, 0 ),
			'junk with a valid prefix' => array( '2abc', 2 ),
			'pure junk'                => array( 'abc', 0 ),
		);
	}

	/**
	 * T45: the status sanitizer's own allow-list filter, exercised with a
	 * hostile entity id in BOTH accepted input shapes (the REST layer sends a
	 * comma string; the settings-import path can hand the closure an array).
	 * Only known entity ids survive, imploded back to the stored comma form.
	 *
	 * @return void
	 */
	public function test_status_sanitizer_filters_hostile_entities_from_a_comma_string(): void {
		$this->stub_sanitize_text_field();

		$sanitized = $this->field( GTM4WP_OPTION_BLACKLIST_STATUS )
			->sanitize( 'html,</script><script>alert(1)</script>,evil,sandboxedScripts' );

		$this->assertSame( 'html,sandboxedScripts', $sanitized, 'Only known entity ids survive; the hostile and unknown ones are dropped.' );
	}

	public function test_status_sanitizer_filters_the_array_input_shape(): void {
		$this->stub_sanitize_text_field();

		$sanitized = $this->field( GTM4WP_OPTION_BLACKLIST_STATUS )
			->sanitize( array( 'html', 'evil', array( 'nested' ), 'gaawe' ) );

		$this->assertSame( 'html,gaawe', $sanitized, 'The array shape filters identically and stores the same comma form.' );
	}

	public function test_choices_cover_exactly_the_valid_restrictions(): void {
		$choices = array_keys( $this->field( GTM4WP_OPTION_BLACKLIST_STATUS )->choices );
		$valid   = BlacklistModule::valid_restrictions();

		sort( $choices );
		sort( $valid );

		$this->assertSame(
			$valid,
			$choices,
			'Every restrictable entity must be selectable in the admin, and nothing else.'
		);
	}

	/**
	 * The four sections are a rendering of one flat option value, so they must
	 * partition the choices: a choice claimed by no section would still render
	 * (utils.js appends the leftovers), but only because that fallback exists -
	 * and a choice claimed twice would render twice, with two checkboxes writing
	 * the same stored entry. Neither shows up as an error anywhere.
	 *
	 * @return void
	 */
	public function test_sections_cover_every_choice_exactly_once(): void {
		$field = $this->field( GTM4WP_OPTION_BLACKLIST_STATUS );

		$sectioned = array();
		foreach ( $field->choice_sections as $section ) {
			$this->assertNotSame( '', $section['label'], 'Every choice section needs a label to render as a group.' );

			$sectioned = array_merge( $sectioned, $section['choices'] );
		}

		$this->assertSame(
			count( $sectioned ),
			count( array_unique( $sectioned ) ),
			'No entity may appear in two sections.'
		);

		$choices = array_keys( $field->choices );

		sort( $sectioned );
		sort( $choices );

		$this->assertSame( $choices, $sectioned, 'The sections must cover every choice of the field.' );
	}

	/**
	 * The section split follows the entity type, which is also how the frontend
	 * tables are organised. Asserted per type so a table that grows (a new tag
	 * id, a new group class) cannot land in the wrong section unnoticed.
	 *
	 * @return void
	 */
	public function test_each_section_holds_its_own_entity_type(): void {
		$sections = $this->field( GTM4WP_OPTION_BLACKLIST_STATUS )->choice_sections;

		$this->assertCount( 4, $sections, 'Tags, triggers, variables and group classes each get their own section.' );

		$expected = array(
			array_keys( AdminSchema::tag_labels() ),
			array_keys( AdminSchema::trigger_labels() ),
			array_keys( AdminSchema::variable_labels() ),
			array_keys( AdminSchema::group_class_labels() ),
		);

		foreach ( $expected as $index => $entity_ids ) {
			$this->assertSame( $entity_ids, $sections[ $index ]['choices'] );
		}

		// The group-class section is the one an entity id could plausibly leak
		// into: sandboxedScripts is selectable in the same list but is NOT an
		// entity id, and the two lists are validated separately on the frontend.
		$this->assertSame(
			BlacklistModule::valid_group_classes(),
			$sections[3]['choices'],
			'The last section must hold the group classes, not entity ids.'
		);
	}

	/**
	 * Renaming the labels to blocklist/allowlist must not renumber the stored
	 * values: BlacklistModule::add_datalayer_data() treats 1 as blocklist mode
	 * and anything else enabled as allowlist, so swapping 1 and 2 would invert
	 * the restriction of every site that already saved one - and the screen
	 * would keep showing the mode the admin picked.
	 *
	 * @return void
	 */
	public function test_restriction_mode_values_are_unchanged(): void {
		$mode = $this->field( GTM4WP_OPTION_BLACKLIST_ENABLE );

		// Declared as '0'/'1'/'2'; PHP normalizes numeric-string keys to ints,
		// which is also why Field::sanitize()'s array_key_exists() lookup on the
		// stringified submitted value still matches.
		$this->assertSame( array( 0, 1, 2 ), array_keys( $mode->choices ) );
		$this->assertStringContainsString( 'Blocklist', $mode->choices['1'] );
		$this->assertStringContainsString( 'Allowlist', $mode->choices['2'] );
	}

	/**
	 * The old wording is gone from every string the settings screen renders.
	 * Kept as a sweep over the whole schema rather than three literal
	 * comparisons, so a re-worded description cannot quietly bring it back.
	 *
	 * @return void
	 */
	public function test_no_user_facing_string_uses_the_retired_wording(): void {
		$schema = new AdminSchema();

		$strings = array( $schema->title(), $schema->intro(), $schema->unavailable_message() );
		$strings = array_merge( $strings, array_values( $schema->groups() ) );

		foreach ( $schema->fields() as $field ) {
			$strings = array_merge( $strings, array( $field->label, $field->description ), array_values( $field->choices ) );

			foreach ( $field->choice_sections as $section ) {
				$strings[] = $section['label'];
			}
		}

		foreach ( $strings as $string ) {
			$this->assertDoesNotMatchRegularExpression(
				'/blacklist|whitelist/i',
				$string,
				"Admin string still uses the retired wording: '{$string}'"
			);
		}
	}
}
