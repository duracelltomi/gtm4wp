<?php
/**
 * Shared fixture of the per-module Site Health row tests.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Module\ModuleInterface;
use GTM4WP\Module\SiteHealthInfoInterface;
use GTM4WP\Options\Options;
use GTM4WP\Tests\unit\TestCase;

/**
 * A module's rows over its own defaults merged with a stored row - the way
 * the collector calls them, with the module's schema built by class name.
 * Every row test asserts two things: what the rows say, and what they must
 * never say (the section is pasted into public threads).
 */
abstract class ModuleSiteHealthTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		// Marks every translation, so a `debug` twin that went through __()
		// cannot pass as English (TS-22: an identity translator would hide it).
		Functions\when( '__' )->alias( static fn ( string $text ): string => '[' . $text . ']' );
		Functions\stubEscapeFunctions();
	}

	/**
	 * The module's Site Health rows.
	 *
	 * @param ModuleInterface      $module The module.
	 * @param array<string, mixed> $stored The stored option row.
	 * @return array<string, array<string, mixed>>
	 */
	protected function rows( ModuleInterface $module, array $stored = array() ): array {
		Functions\when( 'get_option' )->alias( static fn ( $key, $default_value = false ) => ( GTM4WP_OPTIONS === $key ) ? $stored : $default_value );

		$schema_class = $module->admin_schema();
		$schema       = new $schema_class();

		$this->assertInstanceOf( SiteHealthInfoInterface::class, $schema, 'The module reports into Site Health through the opt-in interface.' );

		$rows = $schema->site_health_info( new Options( $module->defaults() ) );

		foreach ( $rows as $key => $row ) {
			$this->assertArrayHasKey( 'label', $row, $key );
			$this->assertArrayHasKey( 'value', $row, $key );
			$this->assertArrayHasKey( 'debug', $row, "$key: every row carries the English twin the copied text prints." );
			$this->assertNotSame( '', $row['value'], "$key: an empty value prints as undefined." );

			foreach ( (array) $row['debug'] as $line ) {
				$this->assertStringNotContainsString( '[', (string) $line, "$key: the English twin went through the translator (U161)." );
			}
		}

		return $rows;
	}

	/**
	 * The rows as one flat string, for the "nowhere in here" assertions.
	 *
	 * @param array<string, array<string, mixed>> $rows The rows.
	 * @return string
	 */
	protected function text( array $rows ): string {
		return (string) json_encode( $rows ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- flattening for a substring assertion.
	}
}
