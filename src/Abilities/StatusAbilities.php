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
use GTM4WP\Capability;
use GTM4WP\Google\KeyVault;
use GTM4WP\Module\Registry;
use GTM4WP\Modules\Container\ContainerRows;
use GTM4WP\Modules\Container\HardcodedContainers;
use GTM4WP\Modules\GoogleDataManager\CaptureStats;
use GTM4WP\Modules\GoogleDataManager\DestinationHealth;
use GTM4WP\Modules\GoogleDataManager\SiteHealth;
use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The gtm4wp/get-status and gtm4wp/get-site-health abilities: what the plugin loads on
 * this site, what is wrong with it, and what Site Health says about it.
 *
 * Both are read-only and both answer from the option row afresh (a new
 * Options service per call, never the request-scoped one), so a change made
 * earlier in the same session is reflected.
 *
 * ⛔ **What may not appear in the output.** Everything an ability returns
 * ends up in an AI assistant's transcript, on somebody else's servers. The
 * rule is the one the Site Health Info section already follows: option
 * states, statuses, counts, timestamps, bare code names and identifiers that
 * are already in the site's public HTML (container IDs, measurement IDs).
 * Never key material, never a service-account address, never a raw error
 * body from a third party, never visitor data.
 */
final class StatusAbilities implements ProviderInterface {

	public const GET_STATUS      = Registrar::NAMESPACE_PREFIX . 'get-status';
	public const GET_SITE_HEALTH = Registrar::NAMESPACE_PREFIX . 'get-site-health';

	/**
	 * Constructor. The Site Health collaborators are injectable for tests;
	 * production builds them the way Admin::boot() does.
	 *
	 * @param Registry               $registry The module registry.
	 * @param KeyVault|null          $vault    The service-account store.
	 * @param DestinationHealth|null $health   Per-destination health records.
	 * @param CaptureStats|null      $stats    Attribution capture-rate counters.
	 */
	public function __construct(
		private Registry $registry,
		private ?KeyVault $vault = null,
		private ?DestinationHealth $health = null,
		private ?CaptureStats $stats = null
	) {
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
				'description'         => __( 'Call this first. Reports what Google Tag Manager for WordPress loads on this site and what is wrong with it: the plugin version, every container that loads (with the wp-config.php overrides applied), where the container code is placed, the data layer variable name, which modules are available and switched on, which supported plugins are installed, and the configuration problems the plugin reports as admin notices. Read-only. Use gtm4wp/get-settings for option values and gtm4wp/get-site-health for the Site Health state.', 'duracelltomi-google-tag-manager' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => self::no_input_schema(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'plugin_version'        => array( 'type' => 'string' ),
						'containers'            => array(
							'type'        => 'array',
							'description' => 'The containers that load, in load order, wp-config.php overrides applied.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'id'          => array( 'type' => 'string' ),
									'environment' => array(
										'type'        => 'boolean',
										'description' => 'Whether the container loads a GTM environment (gtm_auth and gtm_preview both set).',
									),
									'domain'      => array( 'type' => 'string' ),
									'path'        => array( 'type' => 'string' ),
									'omit_id'     => array( 'type' => 'boolean' ),
								),
							),
						),
						'placement'             => array(
							'type' => 'string',
							'enum' => array( 'footer', 'body_open_manual', 'body_open_auto', 'off' ),
						),
						'container_code_output' => array(
							'type'        => 'boolean',
							'description' => 'False when the placement is off: the data layer is written but no container code is emitted.',
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
							'description' => 'The GTM4WP_HARDCODED_* wp-config.php constants in effect.',
							'properties'  => array(
								'active'         => array( 'type' => 'boolean' ),
								'locked_columns' => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'locked_rows'    => array( 'type' => 'boolean' ),
								'errors'         => array(
									'type'        => 'array',
									'description' => 'Constants defined with a malformed value, ignored by the plugin.',
									'items'       => array( 'type' => 'string' ),
								),
							),
						),
						'modules'               => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'        => array( 'type' => 'string' ),
									'title'     => array( 'type' => 'string' ),
									'available' => array( 'type' => 'boolean' ),
									'enabled'   => array(
										'type'        => array( 'boolean', 'null' ),
										'description' => 'The module\'s master switch, or null for a module that has none.',
									),
								),
							),
						),
						'integrations'          => array(
							'type'       => 'object',
							'properties' => array(
								'woocommerce'            => self::integration_schema(),
								'easy_digital_downloads' => self::integration_schema(),
								'contact_form_7'         => self::integration_schema(),
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
				'description'         => __( 'Returns the plugin\'s own Site Health status tests and its section of the Site Health Info tab, as an administrator sees them under Tools -> Site Health. Read-only and safe to quote: statuses, counts, timestamps and short reason codes only - no keys, no account addresses, no visitor data. Call gtm4wp/get-status first for the configuration itself.', 'duracelltomi-google-tag-manager' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => self::no_input_schema(),
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

		$rows       = ContainerRows::normalize( $options->get( GTM4WP_OPTION_GTM_CONTAINERS ) );
		$containers = array();

		foreach ( $rows as $row ) {
			$containers[] = array(
				'id'          => $row[ ContainerRows::COLUMN_ID ],
				'environment' => ( '' !== $row[ ContainerRows::COLUMN_AUTH ] ) && ( '' !== $row[ ContainerRows::COLUMN_PREVIEW ] ),
				'domain'      => $row[ ContainerRows::COLUMN_DOMAIN ],
				'path'        => $row[ ContainerRows::COLUMN_PATH ],
				'omit_id'     => ( '' !== $row[ ContainerRows::COLUMN_NO_ID ] ) && ( '0' !== $row[ ContainerRows::COLUMN_NO_ID ] ),
			);
		}

		$placement  = self::placement_name( $options->get( GTM4WP_OPTION_GTM_PLACEMENT ) );
		$configured = trim( (string) $options->get( GTM4WP_OPTION_DATALAYER_NAME ) );
		$effective  = ContainerRows::datalayer_name( $configured );
		$locks      = HardcodedContainers::locks();

		return array(
			'plugin_version'        => defined( 'GTM4WP_VERSION' ) ? (string) GTM4WP_VERSION : '',
			'containers'            => $containers,
			'placement'             => $placement,
			'container_code_output' => 'off' !== $placement,
			'datalayer_name'        => array(
				'configured' => $configured,
				'effective'  => $effective,
				'valid'      => ( '' === $configured ) || ( $configured === $effective ),
			),
			'hardcoded'             => array(
				'active'         => array() !== $locks['columns'],
				'locked_columns' => array_keys( $locks['columns'] ),
				'locked_rows'    => array() !== $locks['rows'],
				'errors'         => $options->hardcoded_errors(),
			),
			'modules'               => $this->modules( $options ),
			'integrations'          => array(
				'woocommerce'            => self::integration( 'WC_VERSION' ),
				'easy_digital_downloads' => self::integration( 'EDD_VERSION' ),
				'contact_form_7'         => self::integration( 'WPCF7_VERSION' ),
			),
			'problems'              => ( new ConfigurationChecks( $options ) )->problems(),
		);
	}

	/**
	 * The gtm4wp/get-site-health ability.
	 *
	 * The status test is built the way Admin::boot() builds it rather than
	 * collected through the site_status_tests filter: on a REST request core
	 * registers no Site Health tests and the plugin's admin path is not
	 * booted, so the filter would answer with nothing.
	 *
	 * @return array<string, mixed>
	 */
	public function get_site_health(): array {
		$options = $this->options();

		$site_health = new SiteHealth(
			$options,
			$this->health ?? new DestinationHealth(),
			$this->stats ?? new CaptureStats(),
			$this->vault ?? new KeyVault()
		);

		$test = $site_health->run_test();

		$info = array();

		foreach ( ( new SiteHealthInfo( $this->registry, $options ) )->fields() as $key => $row ) {
			// A row the Info tab keeps out of its copy-to-clipboard text is
			// kept out of the transcript for the same reason.
			if ( ! empty( $row['private'] ) ) {
				continue;
			}

			$value = $row['value'] ?? '';

			$info[] = array(
				'key'   => (string) $key,
				'label' => (string) ( $row['label'] ?? '' ),
				'value' => is_array( $value ) ? array_values( array_map( 'strval', $value ) ) : (string) $value,
			);
		}

		return array(
			'tests' => array(
				array(
					'id'          => SiteHealth::TEST_ID,
					'status'      => (string) ( $test['status'] ?? 'good' ),
					'label'       => (string) ( $test['label'] ?? '' ),
					'description' => trim( wp_strip_all_tags( (string) ( $test['description'] ?? '' ) ) ),
				),
			),
			'info'  => $info,
		);
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
	 * One row per registered module: whether it can run here and whether its
	 * master switch is on, for the modules that have one.
	 *
	 * @param Options $options The plugin options service.
	 * @return array<int, array<string, mixed>>
	 */
	private function modules( Options $options ): array {
		$master_switches = array(
			'woocommerce'    => GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE,
			'edd'            => GTM4WP_OPTION_INTEGRATE_EDDTRACKECOMMERCE,
			'contact-form-7' => GTM4WP_OPTION_INTEGRATE_WPCF7,
		);

		$rows = array();

		foreach ( $this->registry->all() as $module ) {
			$schema_class = $module->admin_schema();
			$title        = class_exists( $schema_class ) ? (string) ( new $schema_class() )->title() : $module->id();

			$rows[] = array(
				'id'        => $module->id(),
				'title'     => $title,
				'available' => $module->is_available(),
				'enabled'   => isset( $master_switches[ $module->id() ] )
					? (bool) $options->get( $master_switches[ $module->id() ] )
					: null,
			);
		}

		return $rows;
	}

	/**
	 * Whether a supported plugin is present, and its version.
	 *
	 * @param string $version_constant The plugin's version constant.
	 * @return array{active: bool, version: string|null}
	 */
	private static function integration( string $version_constant ): array {
		$active = defined( $version_constant );

		return array(
			'active'  => $active,
			'version' => $active ? (string) constant( $version_constant ) : null,
		);
	}

	/**
	 * The placement option as a word.
	 *
	 * @param mixed $stored The stored placement value.
	 * @return string
	 */
	private static function placement_name( $stored ): string {
		switch ( (int) $stored ) {
			case GTM4WP_PLACEMENT_OFF:
				return 'off';
			case GTM4WP_PLACEMENT_BODYOPEN:
				return 'body_open_manual';
			case GTM4WP_PLACEMENT_BODYOPEN_AUTO:
				return 'body_open_auto';
			default:
				return 'footer';
		}
	}

	/**
	 * Schema of an integration entry.
	 *
	 * @return array<string, mixed>
	 */
	private static function integration_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'active'  => array( 'type' => 'boolean' ),
				'version' => array( 'type' => array( 'string', 'null' ) ),
			),
		);
	}

	/**
	 * The input schema of an ability that takes no input. The empty-object
	 * default is what core applies when a client sends nothing at all, so a
	 * bare call validates instead of failing on a null input (U155).
	 *
	 * @return array<string, mixed>
	 */
	public static function no_input_schema(): array {
		return array(
			'type'                 => 'object',
			'default'              => array(),
			'properties'           => array(),
			'additionalProperties' => false,
		);
	}
}
