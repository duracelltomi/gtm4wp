<?php
/**
 * Consistency tests across all built-in modules.
 *
 * @package GTM4WP
 */

namespace GTM4WP\Tests\unit\Modules;

use Brain\Monkey\Functions;
use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Module\DocumentedSchemaInterface;
use GTM4WP\Module\ModuleInterface;
use GTM4WP\Module\Registry;
use GTM4WP\Tests\unit\TestCase;

/**
 * Asserts the lean module / admin schema pair of every built-in module
 * cannot drift apart: defaults() keys must exactly match the AdminSchema
 * field keys, and every field must reference a declared accordion group.
 */
final class ModuleConsistencyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		Functions\when( 'wp_kses' )->alias(
			static function ( $content, $allowed_html ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- mock matches the real wp_kses() signature
				return $content;
			}
		);
		Functions\when( 'get_object_taxonomies' )->justReturn( array() );
		Functions\when( 'wc_get_order_statuses' )->justReturn( array() );
		Functions\when( 'get_pages' )->justReturn( array() );
		Functions\when( 'wp_roles' )->justReturn(
			new class() {
				public function get_names(): array {
					return array();
				}
			}
		);
		Functions\when( 'translate_user_role' )->returnArg();

		// The container module's production-only field description reads the
		// environment type while building its schema.
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
	}

	/**
	 * Returns all built-in modules.
	 *
	 * @return array<string, ModuleInterface>
	 */
	private function builtin_modules(): array {
		return Registry::with_default_modules()->all();
	}

	public function test_every_module_defaults_match_admin_schema_fields(): void {
		foreach ( $this->builtin_modules() as $module_id => $module ) {
			$schema_class = $module->admin_schema();
			$this->assertTrue( class_exists( $schema_class ), "Admin schema class of module '{$module_id}' must exist." );

			$schema = new $schema_class();
			$this->assertInstanceOf( AdminSchemaInterface::class, $schema );

			$default_keys = array_keys( $module->defaults() );
			$field_keys   = array_map( static fn ( $field ) => $field->key, $schema->fields() );

			sort( $default_keys );
			sort( $field_keys );

			$this->assertSame(
				$default_keys,
				$field_keys,
				"Module '{$module_id}': defaults() keys must exactly match AdminSchema field keys."
			);
		}
	}

	/**
	 * Without WPML, Polylang or a resolution filter callback, exactly the four
	 * master-language options are marked unavailable. Own process: a leaked
	 * pll_* stub would make them available (TS-16).
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_master_language_options_are_unavailable_without_a_multilingual_plugin(): void {
		$unavailable = array();
		foreach ( $this->builtin_modules() as $module ) {
			$schema_class = $module->admin_schema();
			foreach ( ( new $schema_class() )->fields() as $field ) {
				if ( '' !== $field->unavailable ) {
					$unavailable[] = $field->key;
				}
			}
		}
		sort( $unavailable );

		$expected = array(
			GTM4WP_OPTION_INCLUDE_MASTERLANGUAGE,
			GTM4WP_OPTION_INTEGRATE_EDDMASTERLANGUAGE,
			GTM4WP_OPTION_INTEGRATE_WCMASTERLANGUAGE,
			GTM4WP_OPTION_INTEGRATE_WPCF7_MASTERLANGUAGE,
		);
		sort( $expected );

		$this->assertSame( $expected, $unavailable );
	}

	/**
	 * Each master-language note names only the resolution filters its option
	 * reaches: page variables resolve terms, the other three only posts (#366).
	 * The note shows exactly when a filter is the way in; the short description
	 * names none, and must never name one the option cannot reach.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_master_language_notes_name_only_the_filters_each_option_reaches(): void {
		$fields = $this->master_language_fields();

		foreach ( $fields as $key => $field ) {
			$this->assertStringContainsString( 'gtm4wp_master_language_post_id', $field->unavailable, $key );
		}

		$this->assertStringContainsString( 'gtm4wp_master_language_term_id', $fields[ GTM4WP_OPTION_INCLUDE_MASTERLANGUAGE ]->unavailable );

		foreach ( array( GTM4WP_OPTION_INTEGRATE_WCMASTERLANGUAGE, GTM4WP_OPTION_INTEGRATE_EDDMASTERLANGUAGE, GTM4WP_OPTION_INTEGRATE_WPCF7_MASTERLANGUAGE ) as $key ) {
			$this->assertStringNotContainsString( 'gtm4wp_master_language_term_id', $fields[ $key ]->unavailable, $key );
			$this->assertStringNotContainsString( 'gtm4wp_master_language_term_id', $fields[ $key ]->description, $key );
		}
	}

	/**
	 * A term-filter callback can only affect page variables, so it clears that
	 * note alone (#366).
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_a_term_filter_callback_clears_only_the_option_that_resolves_terms(): void {
		add_filter( 'gtm4wp_master_language_term_id', static fn ( $resolved ) => $resolved );

		$fields = $this->master_language_fields();

		$this->assertSame( '', $fields[ GTM4WP_OPTION_INCLUDE_MASTERLANGUAGE ]->unavailable );
		foreach ( array( GTM4WP_OPTION_INTEGRATE_WCMASTERLANGUAGE, GTM4WP_OPTION_INTEGRATE_EDDMASTERLANGUAGE, GTM4WP_OPTION_INTEGRATE_WPCF7_MASTERLANGUAGE ) as $key ) {
			$this->assertNotSame( '', $fields[ $key ]->unavailable, $key );
		}
	}

	/**
	 * The four master-language fields, keyed by option key.
	 *
	 * @return array<string, \GTM4WP\Options\Field>
	 */
	private function master_language_fields(): array {
		$keys   = array( GTM4WP_OPTION_INCLUDE_MASTERLANGUAGE, GTM4WP_OPTION_INTEGRATE_WCMASTERLANGUAGE, GTM4WP_OPTION_INTEGRATE_EDDMASTERLANGUAGE, GTM4WP_OPTION_INTEGRATE_WPCF7_MASTERLANGUAGE );
		$fields = array();
		foreach ( $this->builtin_modules() as $module ) {
			$schema_class = $module->admin_schema();
			foreach ( ( new $schema_class() )->fields() as $field ) {
				if ( in_array( $field->key, $keys, true ) ) {
					$fields[ $field->key ] = $field;
				}
			}
		}
		$this->assertCount( 4, $fields );

		return $fields;
	}

	public function test_master_language_options_are_available_with_wpml(): void {
		add_filter( 'wpml_current_language', static fn () => 'de' );

		foreach ( $this->builtin_modules() as $module ) {
			$schema_class = $module->admin_schema();
			foreach ( ( new $schema_class() )->fields() as $field ) {
				$this->assertSame( '', $field->unavailable, "Field '{$field->key}' must be available with WPML active." );
			}
		}
	}

	public function test_every_field_references_a_declared_group(): void {
		foreach ( $this->builtin_modules() as $module_id => $module ) {
			$schema_class = $module->admin_schema();
			$schema       = new $schema_class();
			$group_ids    = array_keys( $schema->groups() );

			foreach ( $schema->fields() as $field ) {
				$this->assertContains(
					$field->group,
					$group_ids,
					"Module '{$module_id}': field '{$field->key}' references undeclared group '{$field->group}'."
				);
			}
		}
	}

	public function test_every_field_dependency_references_a_known_option(): void {
		$found_dependency = false;

		foreach ( $this->builtin_modules() as $module_id => $module ) {
			$schema_class = $module->admin_schema();
			$schema       = new $schema_class();
			$field_keys   = array_map( static fn ( $field ) => $field->key, $schema->fields() );

			foreach ( $schema->fields() as $field ) {
				if ( '' === $field->depends_on ) {
					continue;
				}

				$found_dependency = true;

				// A field can only depend on a real option so the React app can
				// resolve the current value it must gate the control on; a
				// dependency on itself would never resolve. Several keys may be
				// listed comma separated (#272); each must resolve.
				foreach ( array_map( 'trim', explode( ',', $field->depends_on ) ) as $dependency ) {
					$this->assertContains(
						$dependency,
						$field_keys,
						"Module '{$module_id}': field '{$field->key}' depends on unknown option '{$dependency}'."
					);
					$this->assertNotSame(
						$field->key,
						$dependency,
						"Module '{$module_id}': field '{$field->key}' must not depend on itself."
					);
				}
			}
		}

		$this->assertTrue(
			$found_dependency,
			'At least one built-in field is expected to declare a depends_on (e.g. parent categories on the category list).'
		);
	}

	/**
	 * The control behind the in-app help links, and the reason they cannot rot
	 * quietly.
	 *
	 * A missing documentation link is invisible from inside the plugin: the icon
	 * is simply not rendered, nothing errors, and no other test goes red. So the
	 * absence is made a failure here instead. A new option added without a `doc`
	 * fails this test in the same change that introduces it, which is the only
	 * moment anybody knows where its documentation belongs.
	 *
	 * Deliberately NOT asserted here: that the page exists on gtm4wp.com. That
	 * claim needs the network and lives in tests/network/DocLinksTest.php, run
	 * before a release. This test only pins that every option names a target.
	 */
	public function test_every_field_declares_a_documentation_page(): void {
		foreach ( $this->builtin_modules() as $module_id => $module ) {
			$schema_class = $module->admin_schema();
			$schema       = new $schema_class();

			$this->assertInstanceOf(
				DocumentedSchemaInterface::class,
				$schema,
				"Module '{$module_id}': the admin schema must declare the module's own documentation page."
			);
			$this->assertNotSame(
				'',
				$schema->doc_url(),
				"Module '{$module_id}': doc_url() must name a page."
			);

			foreach ( $schema->fields() as $field ) {
				$this->assertNotSame(
					'',
					$field->doc,
					"Module '{$module_id}': field '{$field->key}' has no documentation page. Add one to the schema, or a page to gtm4wp.com first."
				);

				// A path, never a URL: the domain has exactly one definition,
				// in \GTM4WP\Admin\Docs. A full URL here would still render a
				// working link today and silently escape that single definition.
				$this->assertStringStartsNotWith(
					'http',
					$field->doc,
					"Module '{$module_id}': field '{$field->key}' must carry a path relative to the documentation base, not a full URL."
				);

				// The fragment is appended from the option key, so a path that
				// brings its own would produce two of them.
				$this->assertStringNotContainsString(
					'#',
					$field->doc,
					"Module '{$module_id}': field '{$field->key}' must not carry a fragment; the anchor is its option key."
				);
			}
		}
	}

	public function test_module_ids_are_unique_and_stable(): void {
		$modules = $this->builtin_modules();

		$this->assertSame(
			array(
				'container',
				'page-variables',
				'client-device-data',
				'visitor-data',
				'user-events',
				'media-events',
				'consent',
				'contact-form-7',
				'woocommerce',
				'edd',
				'amp',
				'blacklist',
				'google-auth',
				'google-data-manager',
				'services',
			),
			array_keys( $modules )
		);
	}

	/**
	 * The settings-import route hands every field its RAW decoded value, so a
	 * crafted file can supply an array (or deeper nesting) where a scalar is
	 * expected. The type-based default sanitizers are type-defensive via
	 * Field::to_string(); a CUSTOM sanitizer REPLACES them entirely
	 * (Field::sanitize() returns its result before the type branches run), so
	 * it must be type-defensive itself. This sweep feeds every field of every
	 * built-in module a nested array and promotes any PHP warning ("Array to
	 * string conversion" and friends) to a failure, pinning the class shut for
	 * future fields too.
	 */
	public function test_every_field_sanitizer_handles_non_scalar_input_without_warning(): void {
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ) => trim( (string) $value ) );
		Functions\when( 'sanitize_textarea_field' )->alias( static fn ( $value ) => trim( (string) $value ) );
		Functions\when( 'sanitize_key' )->alias(
			static fn ( $value ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) )
		);

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- test-only: promotes the PHP warning to a test failure; restored in finally.
		set_error_handler(
			static function ( int $errno, string $errstr ): bool {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test-only exception; the message is reported by PHPUnit, never rendered as HTML.
				throw new \ErrorException( $errstr, 0, $errno );
			},
			E_WARNING | E_NOTICE
		);

		try {
			foreach ( $this->builtin_modules() as $module_id => $module ) {
				$schema_class = $module->admin_schema();
				$schema       = new $schema_class();

				foreach ( $schema->fields() as $field ) {
					$sanitized = $field->sanitize( array( array( 'hostile' ) ) );

					$this->assertTrue(
						is_scalar( $sanitized ) || is_array( $sanitized ) || null === $sanitized || $sanitized instanceof \WP_Error,
						"Module '{$module_id}': field '{$field->key}' must reduce a non-scalar submission to a scalar/array/WP_Error."
					);

					// The warning promotion above is the primary detector, but it
					// only fires for a cast PHP complains about. A sanitizer that
					// stringifies the submission deliberately would satisfy it and
					// still store the useless literal "Array" - and a string IS
					// scalar, so the type assertion above passes too. Assert the
					// value contract as well, the way WooCommerceAdminSchemaTest
					// does per-field, so neither route is left open.
					foreach ( self::flatten_strings( $sanitized ) as $leaf ) {
						$this->assertStringNotContainsString(
							'Array',
							$leaf,
							"Module '{$module_id}': field '{$field->key}' must not stringify a non-scalar submission into the literal \"Array\"."
						);
					}
				}
			}
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Collects every string leaf of a sanitized value so the "Array" contract can
	 * be asserted on table/multiselect results too, not just scalar fields.
	 *
	 * @param mixed $value Sanitized field value of any shape.
	 * @return string[]
	 */
	private static function flatten_strings( $value ): array {
		if ( is_string( $value ) ) {
			return array( $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$strings = array();
		foreach ( $value as $one ) {
			$strings = array_merge( $strings, self::flatten_strings( $one ) );
		}

		return $strings;
	}

	public function test_option_keys_are_owned_by_exactly_one_module(): void {
		$seen = array();

		foreach ( $this->builtin_modules() as $module_id => $module ) {
			foreach ( array_keys( $module->defaults() ) as $option_key ) {
				$owner = $seen[ $option_key ] ?? '';
				$this->assertArrayNotHasKey(
					$option_key,
					$seen,
					"Option '{$option_key}' is owned by both '{$owner}' and '{$module_id}'."
				);
				$seen[ $option_key ] = $module_id;
			}
		}
	}

	/**
	 * Longest field description, in words: what the option does and what to
	 * know before turning it on. Everything else lives behind the "?" link.
	 */
	private const DESCRIPTION_MAX_WORDS = 45;

	/**
	 * Descriptions that shipped in 2.0 and are still longer: shortening them
	 * breaks their translations, so it is batched for 2.2 (decided 2026-10-05).
	 * A field leaves this list the moment it fits.
	 */
	private const LONG_DESCRIPTIONS_UNTIL_2_2 = array(
		'gtm-containers',
		'gtm-production-only',
		'integrate-consent-mode',
		'integrate-cookieyes',
		'include-postmeta',
		'include-postmeta-keys',
		'integrate-woocommerce-purchase-track-on-any-page',
		'integrate-woocommerce-datalayer-max-timeout',
		'event-form-move',
		'integrate-woocommerce-persist-list-attribution',
		'include-visitor-ip-header',
		'include-visitor-ip-proxies',
		'event-dailymotion',
		'event-form-move-filled-only',
		'integrate-wpcf7-ga4events',
		'gtm-code-placement',
		'event-spotify',
		'event-media-dynamic',
		'event-html5-media',
		'integrate-woocommerce-transaction-id-prefix',
		'integrate-woocommerce-product-per-impression',
		'event-dailymotion-playerid',
		'integrate-woocommerce-purchase-track-statuses',
		'integrate-woocommerce-checkoutwc',
		'integrate-woocommerce-clear-ecommerce-datalayer',
		'include-visitor-ip',
		'integrate-woocommerce-order-max-age',
		'integrate-wpcf7-inputs',
		'include-primary-category',
		'event-twitch',
	);

	public function test_field_descriptions_stay_short(): void {
		$long = array();

		foreach ( $this->builtin_modules() as $module ) {
			$schema_class = $module->admin_schema();

			foreach ( ( new $schema_class() )->fields() as $field ) {
				$words = count( preg_split( '/\s+/', trim( strip_tags( (string) $field->description ) ), -1, PREG_SPLIT_NO_EMPTY ) );

				if ( $words > self::DESCRIPTION_MAX_WORDS ) {
					$long[ $field->key ] = $words;
				}
			}
		}

		$unexpected = array_diff_key( $long, array_flip( self::LONG_DESCRIPTIONS_UNTIL_2_2 ) );
		$this->assertSame( array(), $unexpected, 'Over ' . self::DESCRIPTION_MAX_WORDS . ' words: keep what to know before turning it on, move the rest to the docs page behind the "?".' );

		$shortened = array_diff( self::LONG_DESCRIPTIONS_UNTIL_2_2, array_keys( $long ) );
		$this->assertSame( array(), array_values( $shortened ), 'Now short enough (or gone): remove it from LONG_DESCRIPTIONS_UNTIL_2_2.' );
	}
}
