<?php
/**
 * Admin notices.
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
 * Dismissible admin notices about missing configuration and conflicting
 * plugins. Port of gtm4wp_show_warning() and gtm4wp_dismiss_notice()
 * from 1.x; dismiss state is stored per user in the same user meta key.
 */
final class Notices {

	public const USER_NOTICES_KEY = 'gtm4wp_user_notices_dismisses_json';

	/**
	 * All known dismissible notice ids with their default (not dismissed) state.
	 *
	 * @var array<string, bool>
	 */
	private const DEFAULT_DISMISSES = array(
		'enter-gtm-code'            => false,
		'wc-ga-plugin-warning'      => false,
		'wc-gayoast-plugin-warning' => false,
	);

	/**
	 * Constructor.
	 *
	 * @param Options $options The plugin options service.
	 */
	public function __construct( private Options $options ) {
	}

	/**
	 * Registers the admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_notices', array( $this, 'show_notices' ) );
		add_action( 'wp_ajax_gtm4wp_dismiss_notice', array( $this, 'dismiss_notice' ) );
		add_action( 'admin_footer', array( $this, 'print_dismiss_script' ) );
	}

	/**
	 * Reads the per-user dismiss states merged with the defaults.
	 *
	 * @return array<string, bool>
	 */
	private function user_dismisses(): array {
		$stored = get_user_meta( get_current_user_id(), self::USER_NOTICES_KEY, true );

		if ( '' === $stored ) {
			return self::DEFAULT_DISMISSES;
		}

		$stored = json_decode( (string) $stored, true );
		if ( null === $stored || ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::DEFAULT_DISMISSES, $stored );
	}

	/**
	 * Shows warning messages on the WordPress admin about missing GTM ID,
	 * incomplete environment configuration and conflicting plugins.
	 *
	 * @return void
	 */
	public function show_notices(): void {
		$dismisses = $this->user_dismisses();

		// Every anchor below deep links to the option it is about
		// (SettingsPage::url()), not to the settings page as a whole: a notice
		// that names a setting and then drops the admin on a screen where they
		// have to guess which module and which tab it sits behind is only half a
		// pointer. The link addresses the option key, so it survives regrouping.

		// Placement OFF is the deliberate "data layer only" setup: the container
		// code is never emitted, so the site needs no container ID at all and the
		// prompt below is advice no setting can act on - the admin can only
		// dismiss it, per user, forever. Compared strictly against the int
		// constant, exactly the way ContainerCode decides the same thing, so the
		// notice cannot disagree with what the frontend actually emits.
		$container_code_off = ( GTM4WP_PLACEMENT_OFF === $this->options->get( GTM4WP_OPTION_GTM_PLACEMENT ) );

		if ( ! $container_code_off && ( '' === trim( (string) $this->options->get( GTM4WP_OPTION_GTM_CODE ) ) ) && ( false === $dismisses['enter-gtm-code'] ) ) {
			echo '<div class="gtm4wp-notice notice notice-error is-dismissible" data-href="?enter-gtm-code"><p><strong>';
			printf(
				/* translators: 1: opening anchor element pointing to the GTM4WP options page. 2: closing anchor element. */
				esc_html__(
					'To start using Google Tag Manager for WordPress, please %1$senter your GTM ID%2$s',
					'duracelltomi-google-tag-manager'
				),
				// The container list, not GTM4WP_OPTION_GTM_CODE: since 2.0 the flat
				// key is a derived read-only mirror with no control of its own.
				'<a href="' . esc_url( SettingsPage::url( GTM4WP_OPTION_GTM_CONTAINERS ) ) . '">',
				'</a>'
			);
			echo '</strong></p></div>';
		}

		foreach ( (array) $this->options->get( GTM4WP_OPTION_GTM_CONTAINERS, array() ) as $one_container ) {
			$gtm_auth    = (string) ( $one_container[ ContainerRows::COLUMN_AUTH ] ?? '' );
			$gtm_preview = (string) ( $one_container[ ContainerRows::COLUMN_PREVIEW ] ?? '' );

			if ( ( '' !== $gtm_auth ) !== ( '' !== $gtm_preview ) ) {
				echo '<div class="gtm4wp-notice notice notice-error" data-href="?incomplete-gtm-env-config"><p><strong>';
				printf(
					/* translators: %s: the Google Tag Manager container ID with the incomplete environment configuration. */
					esc_html__(
						'Incomplete Google Tag Manager environment configuration of container %s: either the gtm_preview or the gtm_auth parameter value is missing!',
						'duracelltomi-google-tag-manager'
					),
					esc_html( (string) ( $one_container[ ContainerRows::COLUMN_ID ] ?? '' ) )
				);
				echo '</strong></p></div>';
			}
		}

		// A malformed GTM4WP_HARDCODED_* constant in wp-config.php is ignored while
		// the options are built, which is invisible from the outside: the operator
		// sees a container that quietly disregards their wp-config and has nothing
		// to search for. Name the offending constant instead. Not dismissible - it
		// stays until wp-config is fixed, exactly like the incomplete-environment
		// notice above.
		$hardcoded_errors = $this->options->hardcoded_errors();
		if ( array() !== $hardcoded_errors ) {
			echo '<div class="gtm4wp-notice notice notice-error" data-href="?invalid-hardcoded-constant"><p><strong>';
			printf(
				esc_html(
					/* translators: %s: comma separated list of wp-config.php constant names that hold an invalid value. */
					_n(
						'The value of %s in your wp-config.php file is invalid and has been ignored. Please correct it, otherwise your Google Tag Manager container will not use the setting you configured there.',
						'The values of %s in your wp-config.php file are invalid and have been ignored. Please correct them, otherwise your Google Tag Manager container will not use the settings you configured there.',
						count( $hardcoded_errors ),
						'duracelltomi-google-tag-manager'
					)
				),
				esc_html( implode( ', ', $hardcoded_errors ) )
			);
			echo '</strong></p></div>';
		}

		// A custom visitor-IP header with no trusted proxies declared is read exactly as
		// it always was, and that reading cannot be authenticated: an HTTP header is
		// sent by the client. The admin has no way to see this from the settings screen
		// - both states look identical there - so name it, the same way a discarded
		// wp-config constant is named above. Not dismissible: it describes a live
		// configuration gap and goes away by itself once the list is filled in.
		if (
			$this->options->get( GTM4WP_OPTION_INCLUDE_VISITOR_IP )
			&& ( '' !== trim( (string) $this->options->get( GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER ) ) )
			&& ( '' === trim( (string) $this->options->get( GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES ) ) )
		) {
			echo '<div class="gtm4wp-notice notice notice-warning" data-href="?visitor-ip-untrusted-header"><p><strong>';
			printf(
				/* translators: 1: opening anchor element pointing to the GTM4WP options page. 2: closing anchor element. */
				esc_html__(
					'Google Tag Manager for WordPress is reading the visitor IP address from a custom HTTP header, but no trusted proxy addresses are configured. HTTP headers are sent by the visitor, so the reported IP address can be chosen by them. Please %1$sadd the addresses of your reverse proxy, load balancer or CDN%2$s, or turn the custom header off.',
					'duracelltomi-google-tag-manager'
				),
				'<a href="' . esc_url( SettingsPage::url( GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES ) ) . '">',
				'</a>'
			);
			echo '</strong></p></div>';
		}

		// A stored data layer variable name that is not a usable JavaScript
		// identifier is ignored by the frontend, which falls back to dataLayer.
		// 1.x accepted names containing a hyphen, and those are stored verbatim by
		// the migration, so an upgrading site can reach this state without ever
		// having done anything wrong. Silently substituting a different global
		// would be the same undiagnosable failure the name itself caused, so name
		// it (PA-2: validation without a signal is half a fix). Not dismissible -
		// it describes a live configuration gap and clears itself on the next save.
		$stored_datalayer_name = trim( (string) $this->options->get( GTM4WP_OPTION_DATALAYER_NAME ) );
		if ( ( '' !== $stored_datalayer_name ) && ! ContainerRows::is_valid_js_identifier( $stored_datalayer_name ) ) {
			echo '<div class="gtm4wp-notice notice notice-error" data-href="?invalid-datalayer-name"><p><strong>';
			printf(
				/* translators: 1: the configured dataLayer variable name that was rejected. 2: opening anchor element pointing to the GTM4WP options page. 3: closing anchor element. */
				esc_html__(
					'The configured dataLayer variable name "%1$s" is not a valid JavaScript variable name, so Google Tag Manager for WordPress is using the default name "dataLayer" instead. Please %2$scorrect it on the settings page%3$s.',
					'duracelltomi-google-tag-manager'
				),
				esc_html( $stored_datalayer_name ),
				'<a href="' . esc_url( SettingsPage::url( GTM4WP_OPTION_DATALAYER_NAME ) ) . '">',
				'</a>'
			);
			echo '</strong></p></div>';
		}

		if ( function_exists( 'is_plugin_active' ) && $this->options->get( GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE ) ) {
			if ( ( false === $dismisses['wc-ga-plugin-warning'] ) && is_plugin_active( 'woocommerce-google-analytics-integration/woocommerce-google-analytics-integration.php' ) ) {
				echo '<div class="gtm4wp-notice notice notice-warning is-dismissible" data-href="?wc-ga-plugin-warning"><p><strong>' . esc_html__( 'Notice: you should deactivate the plugin "WooCommerce Google Analytics Integration" if you are using Google Analytics tags inside Google Tag Manager!', 'duracelltomi-google-tag-manager' ) . '</strong></p></div>';
			}

			if ( ( false === $dismisses['wc-gayoast-plugin-warning'] ) && is_plugin_active( 'google-analytics-for-wordpress/googleanalytics.php' ) ) {
				echo '<div class="gtm4wp-notice notice notice-warning is-dismissible" data-href="?wc-gayoast-plugin-warning"><p><strong>' . esc_html__( 'Notice: you should deactivate the plugin "Google Analytics for WordPress by MonsterInsights" if you are using Google Analytics tags inside Google Tag Manager!', 'duracelltomi-google-tag-manager' ) . '</strong></p></div>';
			}
		}
	}

	/**
	 * Prints the small vanilla JS handler that reports notice dismissals
	 * back through admin-ajax (replaces the jQuery handler of 1.x).
	 *
	 * @return void
	 */
	public function print_dismiss_script(): void {
		// wp_json_encode() with the hex flags, not esc_js(): this is a string VALUE
		// in a raw <script> body (no wp_kses sink, no entity decode anywhere on this
		// path), and esc_js() emits &quot;/&amp;/&lt; entities the browser never
		// decodes inside <script> - the same swap made in global_var_literal() and
		// disabled_role_warning() (PA-4/RI-4).
		//
		// The literal supplies its own quotes, so it is NOT wrapped below and the
		// emitted line stays byte-identical for an ordinary nonce.
		//
		// No false-return fallback here, unlike ContainerCode::json_literal(): that
		// guards values supplied by a public filter, whereas wp_create_nonce()
		// always returns 10 ASCII characters, which wp_json_encode() cannot fail on.
		//
		// Encoded INSIDE the echo rather than into a variable first. That is RI-17's
		// own rule - an escape is only valid at the instant of output - and WPCS
		// enforces it: it credits an escaping function only where the call is part of
		// the echoed expression, so assigning the result first is reported as
		// unescaped output. Taking the phpcs:ignore instead would have suppressed a
		// warning that was pointing at the right thing.
		echo '<script>
	document.addEventListener( "click", function ( event ) {
		if ( ! event.target.matches( ".gtm4wp-notice .notice-dismiss" ) ) {
			return;
		}
		var notice = event.target.closest( ".gtm4wp-notice" );
		if ( ! notice || ! notice.dataset.href ) {
			return;
		}
		var body = new FormData();
		body.append( "action", "gtm4wp_dismiss_notice" );
		body.append( "noticeid", notice.dataset.href.substring( 1 ) );
		body.append( "nonce", ' . wp_json_encode(
			wp_create_nonce( 'gtm4wp-notice-dismiss-nonce' ),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS
		) . ' );
		window.fetch( window.ajaxurl, { method: "POST", credentials: "same-origin", body: body } );
	} );
</script>';
	}

	/**
	 * Saves the dismissed notice ID as user meta to hide the notice on the next pageview.
	 *
	 * @return void
	 */
	public function dismiss_notice(): void {
		check_ajax_referer( 'gtm4wp-notice-dismiss-nonce', 'nonce' );

		// The hook is only registered for users with the settings capability
		// (see Plugin::boot()), but the handler re-checks it so it stays safe
		// on its own regardless of how it is wired up.
		/** This filter is documented in src/Plugin.php */
		if ( ! current_user_can( apply_filters( 'gtm4wp_admin_page_capability', 'manage_options' ) ) ) {
			wp_die( -1, 403 );
		}

		$dismisses = $this->user_dismisses();

		$noticeid = isset( $_POST['noticeid'] ) ? esc_url_raw( wp_unslash( $_POST['noticeid'] ) ) : '';
		$noticeid = trim( basename( $noticeid ) );

		if ( array_key_exists( $noticeid, $dismisses ) ) {
			$dismisses[ $noticeid ] = true;
			update_user_meta( get_current_user_id(), self::USER_NOTICES_KEY, wp_json_encode( $dismisses ) );
		}
	}
}
