<?php
/**
 * Unit tests for the container module admin schema.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Modules\Container\AdminSchema;
use GTM4WP\Options\Field;
use GTM4WP\Tests\unit\TestCase;

/**
 * Covers the "User roles to exclude" field, which renders every available
 * WordPress role as a checkbox (multiselect) as it did in 1.x.
 */
final class ContainerAdminSchemaTest extends TestCase {

	/**
	 * Value of the WP_ENVIRONMENT_TYPE environment variable before the test,
	 * restored in tearDown so the environment-readout tests cannot leak into
	 * each other (or into the rest of the suite).
	 *
	 * @var string|false
	 */
	private $environment_type_backup = false;

	protected function setUp(): void {
		parent::setUp();

		$this->environment_type_backup = getenv( 'WP_ENVIRONMENT_TYPE' );
		putenv( 'WP_ENVIRONMENT_TYPE' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- test isolation: the readout under test reads this variable.

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		Functions\when( 'wp_kses' )->alias(
			static function ( $content ) {
				return $content;
			}
		);
		Functions\when( 'translate_user_role' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			static function ( $value ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
			}
		);

		// A settings page always has the role API available.
		$roles = new class() {
			public function get_names(): array {
				return array(
					'administrator' => 'Administrator',
					'editor'        => 'Editor',
					'subscriber'    => 'Subscriber',
				);
			}
		};
		Functions\when( 'wp_roles' )->justReturn( $roles );

		// The production-only field description reads the environment type.
		// Brain Monkey keeps a stubbed function defined for the rest of the
		// process, so every test in this class needs it available - the
		// environment-readout tests below override this default.
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
	}

	protected function tearDown(): void {
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- restoring the value snapshotted in setUp().
		if ( false === $this->environment_type_backup ) {
			putenv( 'WP_ENVIRONMENT_TYPE' );
		} else {
			putenv( 'WP_ENVIRONMENT_TYPE=' . $this->environment_type_backup );
		}
		// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv

		parent::tearDown();
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

		$this->fail( "Field '{$key}' not found in the container admin schema." );
	}

	public function test_user_roles_to_exclude_is_a_checkbox_list_of_all_roles(): void {
		$field = $this->field( GTM4WP_OPTION_NOGTMFORLOGGEDIN );

		$this->assertSame( Field::TYPE_MULTISELECT, $field->type );
		$this->assertSame(
			array(
				'administrator' => 'Administrator',
				'editor'        => 'Editor',
				'subscriber'    => 'Subscriber',
			),
			$field->choices
		);
	}

	public function test_container_table_has_an_omit_id_checkbox_column(): void {
		$field   = $this->field( GTM4WP_OPTION_GTM_CONTAINERS );
		$columns = array_column( $field->columns, null, 'key' );

		$this->assertArrayHasKey( 'no_id', $columns, 'The container table must expose the "omit container ID" column.' );
		$this->assertSame( 'checkbox', $columns['no_id']['type'] );
		$this->assertSame( 'path', $columns['no_id']['depends_on'], 'The checkbox depends on the custom path column.' );
	}

	public function test_container_table_stores_omit_id_flag_only_with_custom_path(): void {
		$field = $this->field( GTM4WP_OPTION_GTM_CONTAINERS );

		// A custom path plus the flag on is stored as the canonical '1'.
		$rows = $field->sanitize(
			array(
				array(
					'id'    => 'GTM-AAA111',
					'path'  => 'custom/loader.js',
					'no_id' => true,
				),
			)
		);
		$this->assertSame( '1', $rows[0]['no_id'] );

		// The flag has no effect without a custom path, so it is dropped.
		$rows = $field->sanitize(
			array(
				array(
					'id'    => 'GTM-AAA111',
					'no_id' => '1',
				),
			)
		);
		$this->assertSame( '', $rows[0]['no_id'] );

		// The flag off with a custom path stays off.
		$rows = $field->sanitize(
			array(
				array(
					'id'    => 'GTM-AAA111',
					'path'  => 'custom/loader.js',
					'no_id' => '',
				),
			)
		);
		$this->assertSame( '', $rows[0]['no_id'] );
	}

	/**
	 * The option gates on wp_get_environment_type(), whose value lives in
	 * wp-config.php / the server config and is invisible from the admin. The
	 * description therefore has to state what THIS instance reports and what
	 * turning the option on would do here.
	 */
	public function test_production_only_description_reports_a_non_production_environment(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'staging' );

		$description = $this->field( GTM4WP_OPTION_PRODUCTIONONLY )->description;

		$this->assertStringContainsString( 'staging', $description, 'The admin must see the environment type this site actually reports.' );
		$this->assertStringContainsString( 'is NOT loaded here', $description, 'A non-production environment must state that the container is suppressed.' );
		$this->assertStringNotContainsString( 'is still loaded here', $description );
	}

	public function test_production_only_description_reports_an_explicit_production_environment(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );

		// An explicitly configured environment must not produce the
		// "WP_ENVIRONMENT_TYPE is not set" warning. Set through the environment
		// variable rather than the constant so the test stays reversible (a
		// constant cannot be undefined) and no test order can leak into another.
		putenv( 'WP_ENVIRONMENT_TYPE=production' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- drives the "explicitly configured" branch; restored in tearDown().

		$description = $this->field( GTM4WP_OPTION_PRODUCTIONONLY )->description;

		$this->assertStringContainsString( 'production', $description );
		$this->assertStringContainsString( 'is still loaded here', $description, 'A production environment must state that the container keeps loading.' );
		$this->assertStringNotContainsString( 'is not set', $description, 'The unset warning must not appear once WP_ENVIRONMENT_TYPE is configured.' );
	}

	/**
	 * The trap this readout exists for: a staging copy with no
	 * WP_ENVIRONMENT_TYPE resolves to "production", so the option silently does
	 * nothing and the container keeps loading.
	 */
	public function test_production_only_description_warns_when_the_environment_is_not_configured(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );

		$description = $this->field( GTM4WP_OPTION_PRODUCTIONONLY )->description;

		$this->assertStringContainsString( 'WP_ENVIRONMENT_TYPE is not set', $description, 'An unset WP_ENVIRONMENT_TYPE must be called out, since it silently defaults to production.' );
		$this->assertStringContainsString( 'is still loaded here', $description );
	}

	public function test_checked_roles_are_stored_as_the_1x_comma_separated_string(): void {
		$field = $this->field( GTM4WP_OPTION_NOGTMFORLOGGEDIN );

		// The multiselect submits an array of role ids.
		$this->assertSame( 'administrator,editor', $field->sanitize( array( 'administrator', 'editor' ) ) );

		// A legacy comma separated string keeps working (imports, downgrades).
		$this->assertSame( 'administrator,editor', $field->sanitize( 'administrator,editor' ) );

		// Nothing checked stores an empty string, matching the default.
		$this->assertSame( '', $field->sanitize( array() ) );
	}
}
