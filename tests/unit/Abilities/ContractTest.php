<?php
/**
 * The registration contract every plugin ability keeps.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Abilities;

use GTM4WP\Abilities\DataManagerAbilities;
use GTM4WP\Abilities\Registrar;
use GTM4WP\Abilities\SettingsAbilities;
use GTM4WP\Abilities\StatusAbilities;
use GTM4WP\Capability;

/**
 * The ability catalogue is a public contract: a client written against it
 * keys on the names, the annotations and the schemas. This file pins that
 * contract for every ability at once, so a new ability cannot ship without
 * the meta triple, a strict input schema or its capability gate, and a write
 * cannot be registered as read-only (the wp-abilities-verify idea: the
 * annotations are pinned to what the ability does, not to what it says).
 *
 * The two lists below ARE the catalogue. Adding an ability means adding it
 * to one of them; the tests fail on an ability registered but not listed,
 * and on one listed but not registered.
 */
final class ContractTest extends AbilitiesTestCase {

	/**
	 * Abilities that change nothing.
	 */
	private const READS = array(
		StatusAbilities::GET_STATUS,
		StatusAbilities::GET_SITE_HEALTH,
		SettingsAbilities::GET_SETTINGS,
		DataManagerAbilities::GET_LOG,
	);

	/**
	 * Abilities that change settings or contact Google. Empty in phase 1.
	 */
	private const WRITES = array();

	protected function setUp(): void {
		parent::setUp();

		( new Registrar( $this->registry() ) )->register_abilities();
	}

	public function test_the_catalogue_is_registered_exactly(): void {
		$expected = array_merge( self::READS, self::WRITES );
		sort( $expected );

		$actual = array_keys( $this->registered );
		sort( $actual );

		$this->assertSame( $expected, $actual, 'Every listed ability is registered and nothing else is.' );
	}

	public function test_every_ability_name_is_a_verb_noun_under_the_plugin_namespace(): void {
		foreach ( array_keys( $this->registered ) as $name ) {
			$this->assertMatchesRegularExpression(
				'#^gtm4wp/(get|update|test|replay|export|import)-[a-z0-9-]+$#',
				$name,
				'Names are gtm4wp/<verb>-<noun>; a feature-scoped noun carries the feature (get-google-data-manager-log).'
			);
		}
	}

	public function test_every_ability_belongs_to_the_plugin_category_and_is_labelled(): void {
		foreach ( $this->registered as $name => $args ) {
			$this->assertSame( Registrar::CATEGORY, $args['category'], "$name category" );
			$this->assertIsString( $args['label'] );
			$this->assertNotSame( '', $args['label'], "$name label" );
			$this->assertIsString( $args['description'] );
			$this->assertGreaterThan( 80, strlen( $args['description'] ), "$name description is the agent's runbook, not a title." );
		}
	}

	public function test_every_ability_is_gated_on_the_settings_capability(): void {
		foreach ( $this->registered as $name => $args ) {
			$this->assertSame(
				array( Capability::class, 'can_manage_settings' ),
				$args['permission_callback'],
				"$name is gated on the same capability as the settings screen."
			);
		}
	}

	public function test_every_input_schema_is_strict_and_survives_a_bare_call(): void {
		foreach ( $this->registered as $name => $args ) {
			$schema = $args['input_schema'];

			$this->assertSame( 'object', $schema['type'], "$name input is an object." );
			$this->assertFalse( $schema['additionalProperties'], "$name refuses unknown input keys." );
			$this->assertSame( array(), $schema['default'], "$name takes an empty default so a client sending no input at all validates (core applies the schema default to a null input)." );
			$this->assertArrayHasKey( 'properties', $schema );

			foreach ( $schema['properties'] as $property => $definition ) {
				$this->assertArrayHasKey( 'type', $definition, "$name input property $property is typed." );
			}
		}
	}

	public function test_every_ability_declares_an_output_schema(): void {
		foreach ( $this->registered as $name => $args ) {
			$this->assertSame( 'object', $args['output_schema']['type'], "$name output is an object." );
			$this->assertNotEmpty( $args['output_schema']['properties'], "$name output names its properties." );
		}
	}

	public function test_every_ability_carries_the_meta_triple(): void {
		foreach ( $this->registered as $name => $args ) {
			$meta = $args['meta'];

			$this->assertTrue( $meta['show_in_rest'], "$name: WordPress 6.9/7.0 REST exposure" );
			$this->assertTrue( $meta['public'], "$name: WordPress 7.1 REST exposure" );
			$this->assertTrue( $meta['mcp']['public'], "$name: MCP Adapter exposure" );
			$this->assertSame( 'tool', $meta['mcp']['type'] );
		}
	}

	public function test_the_annotations_match_the_catalogue(): void {
		foreach ( self::READS as $name ) {
			$annotations = $this->registered[ $name ]['meta']['annotations'];

			$this->assertTrue( $annotations['readonly'], "$name is a read." );
			$this->assertFalse( $annotations['destructive'], "$name destroys nothing." );
			$this->assertTrue( $annotations['idempotent'], "$name is idempotent." );
		}

		foreach ( self::WRITES as $name ) {
			$this->assertFalse( $this->registered[ $name ]['meta']['annotations']['readonly'], "$name is a write and must not claim to be read-only." );
		}
	}

	public function test_every_execute_callback_is_a_bound_public_method(): void {
		foreach ( $this->registered as $name => $args ) {
			$callback = $args['execute_callback'];

			$this->assertIsArray( $callback, "$name execute callback" );
			$this->assertIsObject( $callback[0] );
			$this->assertTrue( method_exists( $callback[0], $callback[1] ), "$name execute callback names a real method." );
		}
	}
}
