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

		if ( ( '' === trim( (string) $this->options->get( GTM4WP_OPTION_GTM_CODE ) ) ) && ( false === $dismisses['enter-gtm-code'] ) ) {
			echo '<div class="gtm4wp-notice notice notice-error is-dismissible" data-href="?enter-gtm-code"><p><strong>';
			printf(
				/* translators: 1: opening anchor element pointing to the GTM4WP options page. 2: closing anchor element. */
				esc_html__(
					'To start using Google Tag Manager for WordPress, please %1$senter your GTM ID%2$s',
					'duracelltomi-google-tag-manager'
				),
				'<a href="' . esc_url( menu_page_url( GTM4WP_ADMINSLUG, false ) ) . '">',
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
		$nonce = wp_create_nonce( 'gtm4wp-notice-dismiss-nonce' );

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
		body.append( "nonce", "' . esc_js( $nonce ) . '" );
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
