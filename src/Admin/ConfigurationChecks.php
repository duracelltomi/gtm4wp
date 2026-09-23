<?php
/**
 * Configuration problems the plugin can detect by itself.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Admin;

use GTM4WP\Modules\Container\ContainerRows;
use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The configuration states the plugin knows are wrong or risky, as data, for
 * the admin notices and the gtm4wp/get-status ability alike - one definition,
 * so an assistant cannot disagree with the screen about what is wrong (PA-2).
 *
 * A problem is an array with:
 *
 * - `code`        a stable id; for a dismissible problem it is also the key
 *                 the per-user dismissal is stored under;
 * - `severity`    `error` (something is not working) or `warning` (something
 *                 is working in a way the site owner should know about);
 * - `option_key`  the option the problem is about, for a deep link into the
 *                 settings screen, or '' when no setting can act on it;
 * - `message`     translated plain text, escaped by whoever prints it;
 * - `dismissible` whether an administrator may hide it for themselves. A
 *                 problem that clears itself once the configuration is fixed
 *                 is not dismissible - a warning that can be waved away and
 *                 then keeps being true is no warning.
 */
final class ConfigurationChecks {

	public const CODE_MISSING_CONTAINER_ID     = 'enter-gtm-code';
	public const CODE_INCOMPLETE_ENVIRONMENT   = 'incomplete-gtm-env-config';
	public const CODE_INVALID_HARDCODED        = 'invalid-hardcoded-constant';
	public const CODE_UNTRUSTED_VISITOR_IP     = 'visitor-ip-untrusted-header';
	public const CODE_UNTRUSTED_COUNTRY        = 'cloudflare-country-untrusted-header';
	public const CODE_INVALID_DATALAYER_NAME   = 'invalid-datalayer-name';
	public const CODE_CONFLICT_WC_GA           = 'wc-ga-plugin-warning';
	public const CODE_CONFLICT_MONSTERINSIGHTS = 'wc-gayoast-plugin-warning';

	public const SEVERITY_ERROR   = 'error';
	public const SEVERITY_WARNING = 'warning';

	/**
	 * Constructor.
	 *
	 * @param Options $options The plugin options service.
	 */
	public function __construct( private Options $options ) {
	}

	/**
	 * Every problem the current configuration has, in the order the notices
	 * show them.
	 *
	 * @return array<int, array{code: string, severity: string, option_key: string, message: string, dismissible: bool}>
	 */
	public function problems(): array {
		return array_merge(
			$this->missing_container_id(),
			$this->incomplete_environments(),
			$this->invalid_hardcoded_constants(),
			$this->untrusted_visitor_ip_header(),
			$this->untrusted_country_header(),
			$this->invalid_datalayer_name(),
			$this->conflicting_plugins()
		);
	}

	/**
	 * No container ID while the container code is emitted. Placement OFF is the
	 * deliberate "data layer only" setup, where no container ID is needed;
	 * compared strictly against the int constant, as ContainerCode does.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function missing_container_id(): array {
		$container_code_off = ( GTM4WP_PLACEMENT_OFF === $this->options->get( GTM4WP_OPTION_GTM_PLACEMENT ) );

		if ( $container_code_off || ( '' !== trim( (string) $this->options->get( GTM4WP_OPTION_GTM_CODE ) ) ) ) {
			return array();
		}

		return array(
			array(
				'code'        => self::CODE_MISSING_CONTAINER_ID,
				'severity'    => self::SEVERITY_ERROR,
				// The container list, not GTM4WP_OPTION_GTM_CODE: since 2.0 the flat
				// key is a derived read-only mirror with no control of its own.
				'option_key'  => GTM4WP_OPTION_GTM_CONTAINERS,
				'message'     => __( 'To start using Google Tag Manager for WordPress, please enter your GTM ID.', 'duracelltomi-google-tag-manager' ),
				'dismissible' => true,
			),
		);
	}

	/**
	 * A container with one of the two environment parameters set and the
	 * other empty: the environment then never resolves.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function incomplete_environments(): array {
		$problems = array();

		foreach ( (array) $this->options->get( GTM4WP_OPTION_GTM_CONTAINERS, array() ) as $one_container ) {
			if ( ! is_array( $one_container ) ) {
				continue;
			}

			$gtm_auth    = (string) ( $one_container[ ContainerRows::COLUMN_AUTH ] ?? '' );
			$gtm_preview = (string) ( $one_container[ ContainerRows::COLUMN_PREVIEW ] ?? '' );

			if ( ( '' !== $gtm_auth ) === ( '' !== $gtm_preview ) ) {
				continue;
			}

			$problems[] = array(
				'code'        => self::CODE_INCOMPLETE_ENVIRONMENT,
				'severity'    => self::SEVERITY_ERROR,
				'option_key'  => GTM4WP_OPTION_GTM_CONTAINERS,
				'message'     => sprintf(
					/* translators: %s: the Google Tag Manager container ID with the incomplete environment configuration. */
					__( 'Incomplete Google Tag Manager environment configuration of container %s: either the gtm_preview or the gtm_auth parameter value is missing!', 'duracelltomi-google-tag-manager' ),
					(string) ( $one_container[ ContainerRows::COLUMN_ID ] ?? '' )
				),
				'dismissible' => false,
			);
		}

		return $problems;
	}

	/**
	 * A malformed GTM4WP_HARDCODED_* constant is ignored while the options are
	 * built, which is invisible from the outside, so it is named instead. Stays
	 * until wp-config is fixed.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function invalid_hardcoded_constants(): array {
		$hardcoded_errors = $this->options->hardcoded_errors();

		if ( array() === $hardcoded_errors ) {
			return array();
		}

		return array(
			array(
				'code'        => self::CODE_INVALID_HARDCODED,
				'severity'    => self::SEVERITY_ERROR,
				'option_key'  => GTM4WP_OPTION_GTM_CONTAINERS,
				'message'     => sprintf(
					/* translators: %s: comma separated list of wp-config.php constant names that hold an invalid value. */
					_n(
						'The value of %s in your wp-config.php file is invalid and has been ignored. Please correct it, otherwise your Google Tag Manager container will not use the setting you configured there.',
						'The values of %s in your wp-config.php file are invalid and have been ignored. Please correct them, otherwise your Google Tag Manager container will not use the settings you configured there.',
						count( $hardcoded_errors ),
						'duracelltomi-google-tag-manager'
					),
					implode( ', ', $hardcoded_errors )
				),
				'dismissible' => false,
			),
		);
	}

	/**
	 * The Cloudflare country header with no trusted proxies declared: the
	 * same unauthenticated read as the visitor-IP case below (#272).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function untrusted_country_header(): array {
		if (
			! $this->options->get( GTM4WP_OPTION_INCLUDE_MISCGEOCF )
			|| ( '' !== trim( (string) $this->options->get( GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES ) ) )
		) {
			return array();
		}

		return array(
			array(
				'code'        => self::CODE_UNTRUSTED_COUNTRY,
				'severity'    => self::SEVERITY_WARNING,
				'option_key'  => GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES,
				'message'     => __( 'Google Tag Manager for WordPress is reading the visitor country from the Cloudflare header, but no trusted proxy addresses are configured. HTTP headers are sent by the visitor, so the reported country can be chosen by them. Please add the Cloudflare IP ranges as trusted proxy addresses, or turn the Cloudflare country code off.', 'duracelltomi-google-tag-manager' ),
				'dismissible' => false,
			),
		);
	}

	/**
	 * A custom visitor-IP header with no trusted proxies declared is read
	 * exactly as it always was, and that reading cannot be authenticated: an
	 * HTTP header is sent by the client. Both states look identical on the
	 * settings screen. Clears itself once the list is filled in.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function untrusted_visitor_ip_header(): array {
		if (
			! $this->options->get( GTM4WP_OPTION_INCLUDE_VISITOR_IP )
			|| ( '' === trim( (string) $this->options->get( GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER ) ) )
			|| ( '' !== trim( (string) $this->options->get( GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES ) ) )
		) {
			return array();
		}

		return array(
			array(
				'code'        => self::CODE_UNTRUSTED_VISITOR_IP,
				'severity'    => self::SEVERITY_WARNING,
				'option_key'  => GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES,
				'message'     => __( 'Google Tag Manager for WordPress is reading the visitor IP address from a custom HTTP header, but no trusted proxy addresses are configured. HTTP headers are sent by the visitor, so the reported IP address can be chosen by them. Please add the addresses of your reverse proxy, load balancer or CDN, or turn the custom header off.', 'duracelltomi-google-tag-manager' ),
				'dismissible' => false,
			),
		);
	}

	/**
	 * A stored data layer name that is not a usable JavaScript identifier is
	 * ignored by the frontend, which falls back to dataLayer. 1.x accepted
	 * hyphens and the migration stores them verbatim, so an upgrading site lands
	 * here without doing anything wrong; named rather than silently substituted
	 * (PA-2). Clears itself on the next save.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function invalid_datalayer_name(): array {
		$stored = trim( (string) $this->options->get( GTM4WP_OPTION_DATALAYER_NAME ) );

		if ( ( '' === $stored ) || ContainerRows::is_valid_js_identifier( $stored ) ) {
			return array();
		}

		return array(
			array(
				'code'        => self::CODE_INVALID_DATALAYER_NAME,
				'severity'    => self::SEVERITY_ERROR,
				'option_key'  => GTM4WP_OPTION_DATALAYER_NAME,
				'message'     => sprintf(
					/* translators: %s: the configured dataLayer variable name that was rejected. */
					__( 'The configured dataLayer variable name "%s" is not a valid JavaScript variable name, so Google Tag Manager for WordPress is using the default name "dataLayer" instead. Please correct it on the settings page.', 'duracelltomi-google-tag-manager' ),
					$stored
				),
				'dismissible' => false,
			),
		);
	}

	/**
	 * Plugins sending their own Google Analytics hits next to a container that
	 * tracks e-commerce: every purchase is counted twice. is_plugin_active()
	 * lives in an admin-only file, so a REST request (an ability asking for the
	 * status) loads it here the way core's own plugin controller does.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function conflicting_plugins(): array {
		if ( ! $this->options->get( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE ) ) {
			return array();
		}

		if ( ! function_exists( 'is_plugin_active' ) && is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			return array();
		}

		$problems = array();

		if ( is_plugin_active( 'woocommerce-google-analytics-integration/woocommerce-google-analytics-integration.php' ) ) {
			$problems[] = array(
				'code'        => self::CODE_CONFLICT_WC_GA,
				'severity'    => self::SEVERITY_WARNING,
				'option_key'  => '',
				'message'     => __( 'Notice: you should deactivate the plugin "WooCommerce Google Analytics Integration" if you are using Google Analytics tags inside Google Tag Manager!', 'duracelltomi-google-tag-manager' ),
				'dismissible' => true,
			);
		}

		if ( is_plugin_active( 'google-analytics-for-wordpress/googleanalytics.php' ) ) {
			$problems[] = array(
				'code'        => self::CODE_CONFLICT_MONSTERINSIGHTS,
				'severity'    => self::SEVERITY_WARNING,
				'option_key'  => '',
				'message'     => __( 'Notice: you should deactivate the plugin "Google Analytics for WordPress by MonsterInsights" if you are using Google Analytics tags inside Google Tag Manager!', 'duracelltomi-google-tag-manager' ),
				'dismissible' => true,
			);
		}

		return $problems;
	}
}
