<?php
/**
 * Unit tests for the AMP module admin schema.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\Amp\AdminSchema;
use GTM4WP\Options\Field;
use GTM4WP\Tests\unit\TestCase;

/**
 * The AMP container-id sanitizer is the only gate between the settings form
 * and the amp.json URL the module builds (T130): #346 moved it onto the shared
 * GTM id pattern, whose D modifier stops a bare $ from matching before a
 * trailing newline.
 */
final class AmpAdminSchemaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
	}

	/**
	 * The AMP container-id field.
	 *
	 * @return Field
	 */
	private function field(): Field {
		foreach ( ( new AdminSchema() )->fields() as $field ) {
			if ( GTM4WP_OPTION_INTEGRATE_AMPID === $field->key ) {
				return $field;
			}
		}

		$this->fail( 'The AMP container id field is missing from the schema.' );
	}

	#[\PHPUnit\Framework\Attributes\TestWith( array( 'GTM-ABC123', 'GTM-ABC123' ) )]
	#[\PHPUnit\Framework\Attributes\TestWith( array( 'GTM-ABC123,GTM-DEF456', 'GTM-ABC123,GTM-DEF456' ) )]
	#[\PHPUnit\Framework\Attributes\TestWith( array( '  GTM-ABC123  ', 'GTM-ABC123' ) )]
	#[\PHPUnit\Framework\Attributes\TestWith( array( '', '' ) )]
	public function test_valid_ids_are_stored( string $raw, string $expected ): void {
		$this->assertSame( $expected, $this->field()->sanitize( $raw ) );
	}

	/**
	 * A newline inside the list survives trim(); without the D modifier the
	 * first id would match (the #346 revert).
	 *
	 * @param string $raw The submitted value.
	 */
	#[\PHPUnit\Framework\Attributes\TestWith( array( "GTM-ABC123\n,GTM-DEF456" ) )]
	#[\PHPUnit\Framework\Attributes\TestWith( array( 'GTM-abc123' ) )]
	#[\PHPUnit\Framework\Attributes\TestWith( array( 'GTM-ABC123, GTM-DEF456' ) )]
	#[\PHPUnit\Framework\Attributes\TestWith( array( 'UA-12345-1' ) )]
	#[\PHPUnit\Framework\Attributes\TestWith( array( 'GTM-ABC123,' ) )]
	public function test_invalid_ids_are_refused( string $raw ): void {
		$result = $this->field()->sanitize( $raw );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gtm4wp_invalid_amp_id', $result->get_error_code() );
	}
}
