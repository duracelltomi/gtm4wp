<?php
/**
 * Unit tests for the ability meta builder.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Abilities;

use GTM4WP\Abilities\Meta;
use GTM4WP\Tests\unit\TestCase;

/**
 * Three consumers read three different exposure keys (U156), so every meta
 * array must carry all three; and the annotations are a promise MCP clients
 * act on (U157), so a read must say readonly and a write must not.
 */
final class MetaTest extends TestCase {

	public function test_a_read_is_exposed_to_rest_on_every_core_version_and_to_mcp(): void {
		$meta = Meta::read();

		$this->assertTrue( $meta['show_in_rest'], 'WordPress 6.9/7.0 read show_in_rest.' );
		$this->assertTrue( $meta['public'], 'WordPress 7.1 reads public.' );
		$this->assertTrue( $meta['mcp']['public'], 'The MCP Adapter reads mcp.public.' );
		$this->assertSame( 'tool', $meta['mcp']['type'] );
	}

	public function test_a_read_is_annotated_readonly_idempotent_and_not_destructive(): void {
		$this->assertSame(
			array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			Meta::read()['annotations']
		);
	}

	public function test_a_write_carries_the_same_exposure_and_its_own_annotations(): void {
		$meta = Meta::write( true, true );

		$this->assertTrue( $meta['show_in_rest'] );
		$this->assertTrue( $meta['public'] );
		$this->assertTrue( $meta['mcp']['public'] );
		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
			$meta['annotations']
		);
	}

	public function test_a_write_that_contacts_google_says_so_in_the_mcp_spelling(): void {
		$annotations = Meta::write( false, false, true )['annotations'];

		$this->assertFalse( $annotations['readonly'] );
		$this->assertFalse( $annotations['destructive'] );
		$this->assertFalse( $annotations['idempotent'] );
		$this->assertTrue( $annotations['openWorldHint'], 'openWorldHint has no WordPress spelling; the adapter passes it through as is.' );
	}

	public function test_a_local_write_carries_no_open_world_hint_at_all(): void {
		$this->assertArrayNotHasKey( 'openWorldHint', Meta::write( true, true )['annotations'], 'Absent, not false: the adapter maps only the keys present.' );
		$this->assertArrayNotHasKey( 'openWorldHint', Meta::read()['annotations'] );
	}
}
