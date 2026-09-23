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

use GTM4WP\Capability;
use GTM4WP\Options\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Admin notices about missing configuration and conflicting plugins. Port of
 * gtm4wp_show_warning() and gtm4wp_dismiss_notice() from 1.x; dismiss state
 * is stored per user in the same user meta key.
 *
 * What is wrong is decided by ConfigurationChecks, which the
 * gtm4wp/get-status ability reads as well; this class only renders the list
 * and keeps the per-user dismissals.
 */
final class Notices {

	public const USER_NOTICES_KEY = 'gtm4wp_user_notices_dismisses_json';

	/**
	 * All known dismissible notice ids with their default (not dismissed) state.
	 *
	 * @var array<string, bool>
	 */
	private const DEFAULT_DISMISSES = array(
		ConfigurationChecks::CODE_MISSING_CONTAINER_ID     => false,
		ConfigurationChecks::CODE_CONFLICT_WC_GA           => false,
		ConfigurationChecks::CODE_CONFLICT_MONSTERINSIGHTS => false,
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
		// The dismiss script is hooked from print_notice() only when a dismissible
		// notice was printed, not on every admin screen (#302).
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
	 * Shows one notice per configuration problem, skipping the dismissible
	 * ones this user has dismissed.
	 *
	 * @return void
	 */
	public function show_notices(): void {
		$dismisses = $this->user_dismisses();

		foreach ( ( new ConfigurationChecks( $this->options ) )->problems() as $problem ) {
			if ( $problem['dismissible'] && ! empty( $dismisses[ $problem['code'] ] ) ) {
				continue;
			}

			$this->print_notice( $problem );
		}
	}

	/**
	 * Prints one problem as a notice. A problem about a setting deep links to
	 * that setting (SettingsPage::url()): a notice that names a setting and
	 * then drops the admin on the settings screen to guess which module and
	 * tab it sits behind is only half a pointer.
	 *
	 * @param array{code: string, severity: string, option_key: string, message: string, dismissible: bool} $problem One problem.
	 * @return void
	 */
	private function print_notice( array $problem ): void {
		$classes = 'gtm4wp-notice notice notice-'
			. ( ConfigurationChecks::SEVERITY_WARNING === $problem['severity'] ? 'warning' : 'error' )
			. ( $problem['dismissible'] ? ' is-dismissible' : '' );

		if ( $problem['dismissible'] ) {
			// admin_notices runs before admin_footer; re-adding the same callable is idempotent.
			add_action( 'admin_footer', array( $this, 'print_dismiss_script' ) );
		}

		echo '<div class="' . esc_attr( $classes ) . '" data-href="?' . esc_attr( $problem['code'] ) . '"><p><strong>' . esc_html( $problem['message'] ) . '</strong>';

		if ( '' !== $problem['option_key'] ) {
			echo ' <a href="' . esc_url( SettingsPage::url( $problem['option_key'] ) ) . '">' . esc_html__( 'Open the setting', 'duracelltomi-google-tag-manager' ) . '</a>';
		}

		echo '</p></div>';
	}

	/**
	 * Prints the small vanilla JS handler that reports notice dismissals
	 * back through admin-ajax (replaces the jQuery handler of 1.x).
	 *
	 * @return void
	 */
	public function print_dismiss_script(): void {
		// wp_json_encode() + hex flags, not esc_js(), for a string VALUE in a raw
		// <script> body (PA-4/RI-4); the literal supplies its own quotes. No
		// false fallback: a nonce is 10 ASCII characters. Encoded INSIDE the echo
		// (RI-17), which is also what WPCS credits as escaped output.
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

		// Re-checked here so the handler is safe on its own, however it is wired.
		if ( ! Capability::can_manage_settings() ) {
			wp_die( -1, 403 );
		}

		$dismisses = $this->user_dismisses();

		// sanitize_key(): a notice id is an opaque key, never a URL (RI-18); the
		// allow-list below is what makes this safe either way.
		$noticeid = isset( $_POST['noticeid'] ) ? sanitize_key( wp_unslash( $_POST['noticeid'] ) ) : '';

		if ( array_key_exists( $noticeid, $dismisses ) ) {
			$dismisses[ $noticeid ] = true;
			update_user_meta( get_current_user_id(), self::USER_NOTICES_KEY, wp_json_encode( $dismisses ) );
		}
	}
}
