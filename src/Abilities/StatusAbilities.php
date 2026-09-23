<?php
/**
 * Abilities that report the plugin's state.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Abilities;

use GTM4WP\Admin\ConfigurationChecks;
use GTM4WP\Admin\SiteHealthInfo;
use GTM4WP\Admin\SiteHealthTests;
use GTM4WP\Capability;
use GTM4WP\Module\Registry;
use GTM4WP\Module\StatusInfoInterface;
use GTM4WP\Modules\Container\StatusReport;
use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The gtm4wp/get-status and gtm4wp/get-site-health abilities: what the plugin
 * loads here, what is wrong with it, and what Site Health says. Both are
 * read-only and answer from the option row afresh (a new Options service per
 * call), so a change made earlier in the session is reflected.
 *
 * Both assemble only: every module-specific fact comes from the module that
 * owns it (Container\StatusReport, each schema's StatusInfoInterface, the
 * two Site Health collectors), and this class keeps no per-module list
 * (PA-21).
 *
 * ⛔ An ability's output ends up in an AI assistant's transcript. Same rule
 * as the Site Health Info section: option states, statuses, counts,
 * timestamps, code names and identifiers already in the site's public HTML.
 * Never key material, a service-account address, a third party's raw error
 * body or visitor data.
 */
final class StatusAbilities implements ProviderInterface {

	public const GET_STATUS      = Registrar::NAMESPACE_PREFIX . 'get-status';
	public const GET_SITE_HEALTH = Registrar::NAMESPACE_PREFIX . 'get-site-health';

	/**
	 * Constructor.
	 *
	 * @param Registry $registry The module registry.
	 */
	public function __construct( private Registry $registry ) {
	}

	/**
	 * Registers the two abilities.
	 *
	 * @return void
	 */
	public function register(): void {
		wp_register_ability(
			self::GET_STATUS,
			array(
				'label'               => __( 'Get the Google Tag Manager status', 'duracelltomi-google-tag-manager' ),
				'description'         => __( 'Call this first. Reports what Google Tag Manager for WordPress loads on this site and what is wrong with it: the plugin version, every container that loads (with the wp-config.php overrides applied), where the container code is placed, the data layer variable name, which modules are available and switched on (and, for a module that integrates another plugin, whether that plugin is installed and in which version), and the configuration problems the plugin reports as admin notices. Read-only. Use gtm4wp/get-settings for option values and gtm4wp/get-site-health for the Site Health state.', 'duracelltomi-google-tag-manager' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => Registrar::no_input_schema(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'plugin_version'        => array( 'type' => 'string' ),
						'containers'            => array(
							'type'        => 'array',
							'description' => __( 'The containers that load, in load order, wp-config.php overrides applied.', 'duracelltomi-google-tag-manager' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'id'          => array( 'type' => 'string' ),
									'environment' => array(
										'type'        => 'boolean',
										'description' => __( 'Whether the container loads a GTM environment (gtm_auth and gtm_preview both set).', 'duracelltomi-google-tag-manager' ),
									),
									'domain'      => array( 'type' => 'string' ),
									'path'        => array( 'type' => 'string' ),
									'omit_id'     => array( 'type' => 'boolean' ),
								),
							),
						),
						'placement'             => array(
							'type' => 'string',
							'enum' => StatusReport::PLACEMENTS,
						),
						'container_code_output' => array(
							'type'        => 'boolean',
							'description' => __( 'False when the placement is off: the data layer is written but no container code is emitted.', 'duracelltomi-google-tag-manager' ),
						),
						'datalayer_name'        => array(
							'type'       => 'object',
							'properties' => array(
								'configured' => array( 'type' => 'string' ),
								'effective'  => array( 'type' => 'string' ),
								'valid'      => array( 'type' => 'boolean' ),
							),
						),
						'hardcoded'             => array(
							'type'        => 'object',
							'description' => __( 'The GTM4WP_HARDCODED_* wp-config.php constants in effect.', 'duracelltomi-google-tag-manager' ),
							'properties'  => array(
								'active'         => array( 'type' => 'boolean' ),
								'locked_columns' => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'locked_rows'    => array( 'type' => 'boolean' ),
								'errors'         => array(
									'type'        => 'array',
									'description' => __( 'Constants defined with a malformed value, ignored by the plugin.', 'duracelltomi-google-tag-manager' ),
									'items'       => array( 'type' => 'string' ),
								),
							),
						),
						'modules'               => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'          => array( 'type' => 'string' ),
									'title'       => array( 'type' => 'string' ),
									'available'   => array( 'type' => 'boolean' ),
									'enabled'     => array(
										'type'        => array( 'boolean', 'null' ),
										'description' => __( 'The module\'s master switch, or null for a module that has none.', 'duracelltomi-google-tag-manager' ),
									),
									'integration' => self::integration_schema(),
								),
							),
						),
						'problems'              => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'code'        => array( 'type' => 'string' ),
									'severity'    => array(
										'type' => 'string',
										'enum' => array( ConfigurationChecks::SEVERITY_ERROR, ConfigurationChecks::SEVERITY_WARNING ),
									),
									'option_key'  => array( 'type' => 'string' ),
									'message'     => array( 'type' => 'string' ),
									'dismissible' => array( 'type' => 'boolean' ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'get_status' ),
				'permission_callback' => array( Capability::class, 'can_manage_settings' ),
				'meta'                => Meta::read(),
			)
		);

		wp_register_ability(
			self::GET_SITE_HEALTH,
			array(
				'label'               => __( 'Get the Site Health state of Google Tag Manager', 'duracelltomi-google-tag-manager' ),
				'description'         => __( 'Returns the plugin\'s own Site Health status tests (the configuration, the Google service account keys, Google Data Manager sending) and every row of its section of the Site Health Info tab - the state of every module - as an administrator sees them under Tools -> Site Health. Read-only and safe to quote: statuses, counts, timestamps and short reason codes only - no keys, no account addresses, no visitor data. Call gtm4wp/get-status first for the configuration itself.', 'duracelltomi-google-tag-manager' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => Registrar::no_input_schema(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'tests' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'          => array( 'type' => 'string' ),
									'status'      => array(
										'type' => 'string',
										'enum' => array( 'good', 'recommended', 'critical' ),
									),
									'label'       => array( 'type' => 'string' ),
									'description' => array( 'type' => 'string' ),
								),
							),
						),
						'info'  => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'key'   => array( 'type' => 'string' ),
									'label' => array( 'type' => 'string' ),
									'value' => array(
										'type'  => array( 'string', 'array' ),
										'items' => array( 'type' => 'string' ),
									),
								),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'get_site_health' ),
				'permission_callback' => array( Capability::class, 'can_manage_settings' ),
				'meta'                => Meta::read(),
			)
		);
	}

	/**
	 * The gtm4wp/get-status ability.
	 *
	 * @return array<string, mixed>
	 */
	public function get_status(): array {
		$options = $this->options();

		return array_merge(
			array( 'plugin_version' => defined( 'GTM4WP_VERSION' ) ? (string) GTM4WP_VERSION : '' ),
			( new StatusReport( $options ) )->report(),
			array(
				'modules'  => $this->modules( $options ),
				'problems' => ( new ConfigurationChecks( $options ) )->problems(),
			)
		);
	}

	/**
	 * The gtm4wp/get-site-health ability. The tests are run through the
	 * collector's run_all(), not the site_status_tests filter: on a REST
	 * request core registers no tests and the admin path is not booted.
	 *
	 * @return array<string, mixed>
	 */
	public function get_site_health(): array {
		$options = $this->options();
		$tests   = array();

		foreach ( ( new SiteHealthTests( $this->registry, $options ) )->run_all() as $id => $result ) {
			$tests[] = array(
				'id'          => (string) $id,
				'status'      => (string) ( $result['status'] ?? 'good' ),
				'label'       => (string) ( $result['label'] ?? '' ),
				'description' => self::plain_text( (string) ( $result['description'] ?? '' ) ),
			);
		}

		$info = array();

		foreach ( ( new SiteHealthInfo( $this->registry, $options ) )->fields() as $key => $row ) {
			// A row the Info tab keeps out of its copy-to-clipboard text is
			// kept out of the transcript for the same reason.
			if ( ! empty( $row['private'] ) ) {
				continue;
			}

			$info[] = array(
				'key'   => (string) $key,
				'label' => (string) ( $row['label'] ?? '' ),
				'value' => self::copy_value( $row ),
			);
		}

		return array(
			'tests' => $tests,
			'info'  => $info,
		);
	}

	/**
	 * A test description as text: one line per block, because a plain tag strip
	 * fuses adjacent paragraphs ("…GTM ID.Google Tag Manager…"). A constant
	 * replacement, so the data never reaches the replacement argument (PA-7).
	 *
	 * @param string $html The description as the Status tab renders it.
	 * @return string
	 */
	private static function plain_text( string $html ): string {
		$lined = preg_replace( '#(</(?:p|li|div)>|<br\s*/?>)\s*#i', "\n", $html );

		return trim( wp_strip_all_tags( is_string( $lined ) ? $lined : $html ) );
	}

	/**
	 * A row's value as the Info tab's copy-to-clipboard text carries it: the
	 * English `debug` twin when the row has one, and named sub-lines as
	 * "key: value" strings.
	 *
	 * @param array<string, mixed> $row The row.
	 * @return string|string[]
	 */
	private static function copy_value( array $row ) {
		$value = $row['debug'] ?? ( $row['value'] ?? '' );

		if ( ! is_array( $value ) ) {
			return (string) $value;
		}

		$lines = array();

		foreach ( $value as $key => $item ) {
			$lines[] = is_int( $key ) ? (string) $item : $key . ': ' . (string) $item;
		}

		return $lines;
	}

	/**
	 * A fresh Options service over the stored row.
	 *
	 * @return Options
	 */
	private function options(): Options {
		return new Options( $this->registry->defaults() );
	}

	/**
	 * One row per registered module: whether it can run here, and what the
	 * module says about its master switch and its host plugin through
	 * StatusInfoInterface. A module that says nothing reports null for both.
	 *
	 * @param Options $options The plugin options service.
	 * @return array<int, array<string, mixed>>
	 */
	private function modules( Options $options ): array {
		$rows = array();

		foreach ( $this->registry->all() as $module ) {
			$schema_class = $module->admin_schema();
			$schema       = class_exists( $schema_class ) ? new $schema_class() : null;

			// instanceof, not method_exists(): a schema predating the interface
			// is a module with nothing to add, not an error.
			$info = $schema instanceof StatusInfoInterface ? $schema->status_info( $options ) : array();

			$rows[] = array(
				'id'          => $module->id(),
				'title'       => null !== $schema ? (string) $schema->title() : $module->id(),
				'available'   => $module->is_available(),
				'enabled'     => isset( $info['enabled'] ) && is_bool( $info['enabled'] ) ? $info['enabled'] : null,
				'integration' => self::integration( $info['integration'] ?? null ),
			);
		}

		return $rows;
	}

	/**
	 * A module's host-plugin entry in the shape the schema promises, or null
	 * for a module that integrates nothing.
	 *
	 * @param mixed $integration What the schema reported.
	 * @return array{active: bool, version: string|null}|null
	 */
	private static function integration( $integration ): ?array {
		if ( ! is_array( $integration ) ) {
			return null;
		}

		return array(
			'active'  => ! empty( $integration['active'] ),
			'version' => isset( $integration['version'] ) ? (string) $integration['version'] : null,
		);
	}

	/**
	 * Schema of a module's integration entry.
	 *
	 * @return array<string, mixed>
	 */
	private static function integration_schema(): array {
		return array(
			'type'        => array( 'object', 'null' ),
			'description' => __( 'The plugin this module integrates: whether it is installed, and its version. Null for a module that integrates nothing.', 'duracelltomi-google-tag-manager' ),
			'properties'  => array(
				'active'  => array( 'type' => 'boolean' ),
				'version' => array( 'type' => array( 'string', 'null' ) ),
			),
		);
	}
}
