<?php
/**
 * The plugin's tests on the Site Health Status tab.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Admin;

use GTM4WP\Module\Registry;
use GTM4WP\Module\SiteHealthTestsInterface;
use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Registers ONE site_status_tests filter for the whole plugin: the
 * configuration test below plus every test a module contributes through
 * SiteHealthTestsInterface, in registry order. The test id and the plugin
 * badge are stamped here so a module never repeats them; the rest of a
 * result is the module's (PA-21). run_all() serves the
 * gtm4wp/get-site-health ability, which cannot go through the filter: core
 * registers no tests on a REST request.
 */
final class SiteHealthTests {

	/**
	 * Id of the plugin-wide configuration test.
	 */
	public const TEST_CONFIGURATION = 'gtm4wp_configuration';

	/**
	 * Prefix of every test id.
	 */
	private const PREFIX = 'gtm4wp_';

	/**
	 * Constructor.
	 *
	 * @param Registry $registry The module registry.
	 * @param Options  $options  The plugin options service.
	 */
	public function __construct( private Registry $registry, private Options $options ) {
	}

	/**
	 * Registers the filter.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
	}

	/**
	 * Adds every test as a direct one.
	 *
	 * @param array<string, mixed> $tests The registered tests.
	 * @return array<string, mixed>
	 */
	public function add_tests( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}

		// Core restores an ABSENT 'direct' after the filter, not a scalar an
		// earlier callback left there, and the nested write below would fatal on one.
		if ( ! isset( $tests['direct'] ) || ! is_array( $tests['direct'] ) ) {
			$tests['direct'] = array();
		}

		foreach ( $this->tests() as $id => $run ) {
			$tests['direct'][ $id ] = array( 'test' => $run );
		}

		return $tests;
	}

	/**
	 * Every test keyed by its id, each wrapped to carry the id and the badge.
	 *
	 * @return array<string, callable>
	 */
	public function tests(): array {
		$tests = array(
			self::TEST_CONFIGURATION => self::wrap( self::TEST_CONFIGURATION, array( $this, 'configuration_test' ) ),
		);

		foreach ( $this->registry->all() as $module ) {
			$schema_class = $module->admin_schema();

			if ( ! class_exists( $schema_class ) ) {
				continue;
			}

			$schema = new $schema_class();

			// instanceof, not method_exists(): an older third-party schema
			// contributes nothing and does not fatal.
			if ( ! $schema instanceof SiteHealthTestsInterface ) {
				continue;
			}

			$prefix = self::PREFIX . self::slug( $module->id() ) . '_';

			foreach ( $schema->site_health_tests( $this->options ) as $key => $run ) {
				if ( ! is_callable( $run ) ) {
					continue;
				}

				$id           = self::unique( $prefix . self::slug( (string) $key ), $tests );
				$tests[ $id ] = self::wrap( $id, $run );
			}
		}

		return $tests;
	}

	/**
	 * Runs every test now.
	 *
	 * @return array<string, array<string, mixed>> Results keyed by test id.
	 */
	public function run_all(): array {
		$results = array();

		foreach ( $this->tests() as $id => $run ) {
			$results[ $id ] = $run();
		}

		return $results;
	}

	/**
	 * The problems the admin notices report, as one result: an error is
	 * critical (something does not work), a warning alone is recommended,
	 * nothing is good. One source for both surfaces (PA-2).
	 *
	 * @return array<string, mixed>
	 */
	public function configuration_test(): array {
		$problems = ( new ConfigurationChecks( $this->options ) )->problems();

		if ( array() === $problems ) {
			// Placement off is the deliberate data-layer-only setup.
			$off = ( GTM4WP_PLACEMENT_OFF === $this->options->get( GTM4WP_OPTION_GTM_PLACEMENT ) );

			return array(
				'status'      => 'good',
				'label'       => __( 'Google Tag Manager is configured', 'duracelltomi-google-tag-manager' ),
				'description' => '<p>' . esc_html(
					$off
						? __( 'The container code is switched off: the data layer is written and no container loads. That is a deliberate setup, not a problem.', 'duracelltomi-google-tag-manager' )
						: __( 'The plugin reports no problem with its configuration.', 'duracelltomi-google-tag-manager' )
				) . '</p>',
				'actions'     => '',
			);
		}

		$critical    = false;
		$description = '';
		$option_key  = '';

		foreach ( $problems as $problem ) {
			$critical     = $critical || ( ConfigurationChecks::SEVERITY_ERROR === $problem['severity'] );
			$description .= '<p>' . esc_html( $problem['message'] ) . '</p>';

			if ( ( '' === $option_key ) && ( '' !== $problem['option_key'] ) ) {
				$option_key = $problem['option_key'];
			}
		}

		return array(
			'status'      => $critical ? 'critical' : 'recommended',
			'label'       => $critical
				? __( 'Google Tag Manager is not configured correctly', 'duracelltomi-google-tag-manager' )
				: __( 'Google Tag Manager reports a configuration warning', 'duracelltomi-google-tag-manager' ),
			'description' => $description,
			'actions'     => ( '' === $option_key ) ? '' : sprintf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( SettingsPage::url( $option_key ) ),
				esc_html__( 'Open the setting', 'duracelltomi-google-tag-manager' )
			),
		);
	}

	/**
	 * A module or test id as a test-id segment. Core interpolates the id into
	 * an HTML id and reads it back through a jQuery `#` selector, and the
	 * registry validates nothing, so every character outside [a-z0-9_] becomes
	 * an underscore (a space or a dot leaves the panel unopenable).
	 *
	 * @param string $id The id.
	 * @return string
	 */
	private static function slug( string $id ): string {
		$slug = preg_replace( '/[^a-z0-9_]/', '_', strtolower( $id ) );

		return is_string( $slug ) ? $slug : '_';
	}

	/**
	 * The id, suffixed when the slug of another module's test already took it
	 * (`a-b` and `a_b` collide): a collision must never drop a test silently.
	 *
	 * @param string               $id    The derived id.
	 * @param array<string, mixed> $taken The tests collected so far.
	 * @return string
	 */
	private static function unique( string $id, array $taken ): string {
		$candidate = $id;

		for ( $n = 2; isset( $taken[ $candidate ] ); $n++ ) {
			$candidate = $id . '_' . $n;
		}

		return $candidate;
	}

	/**
	 * Wraps a module's callable so its result carries the id, the badge and
	 * every key core reads; what the module set is never overwritten.
	 *
	 * @param string   $id  The test id.
	 * @param callable $run The module's callable.
	 * @return \Closure
	 */
	private static function wrap( string $id, callable $run ): \Closure {
		return static function () use ( $id, $run ): array {
			$result = $run();
			$result = is_array( $result ) ? $result : array();

			$result += array(
				'status'      => 'good',
				'label'       => '',
				'description' => '',
				'actions'     => '',
				'badge'       => array(
					'label' => __( 'Google Tag Manager', 'duracelltomi-google-tag-manager' ),
					'color' => 'blue',
				),
			);

			$result['test'] = $id;

			return $result;
		};
	}
}
