<?php
/**
 * User events module (lean frontend class).
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\UserEvents;

use GTM4WP\Frontend\ScriptTag;
use GTM4WP\Module\AbstractModule;
use GTM4WP\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Fires data layer events on user login and registration and loads the
 * form move tracker script. Port of gtm4wp_wp_login(), gtm4wp_user_register(),
 * gtm4wp_wp_init() and the event parts of gtm4wp_wp_footer() from 1.x.
 */
final class UserEventsModule extends AbstractModule {

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'user-events';
	}

	/**
	 * Option defaults, 1.x compatible.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			GTM4WP_OPTION_EVENTS_FORMMOVE            => false,
			GTM4WP_OPTION_EVENTS_FORMMOVE_FILLEDONLY => false,
			GTM4WP_OPTION_EVENTS_NEWUSERREG          => false,
			GTM4WP_OPTION_EVENTS_USERLOGIN           => false,
		);
	}

	/**
	 * Registers the frontend hooks.
	 *
	 * @return void
	 */
	protected function register_frontend_hooks(): void {
		add_action( 'init', array( $this, 'clear_event_cookies' ) );
		add_action( 'wp_footer', array( $this, 'output_event_scripts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		if ( $this->opt( GTM4WP_OPTION_EVENTS_USERLOGIN ) ) {
			add_action( 'wp_login', array( $this, 'on_login' ) );
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_NEWUSERREG ) ) {
			add_action( 'user_register', array( $this, 'on_register' ) );
		}
	}

	/**
	 * Admin schema class name.
	 *
	 * @return string
	 */
	public function admin_schema(): string {
		return AdminSchema::class;
	}

	/**
	 * Loads the form move tracker script when enabled.
	 *
	 * @return void
	 */
	public function enqueue_scripts(): void {
		if ( $this->opt( GTM4WP_OPTION_EVENTS_FORMMOVE ) ) {
			$in_footer = (bool) apply_filters( 'gtm4wp_' . GTM4WP_OPTION_EVENTS_FORMMOVE, true );
			$this->enqueue_script( 'gtm4wp-form-move-tracker', 'gtm4wp-form-move-tracker.js', array(), $in_footer );

			// The sub-option is meaningless without its parent, and the parent is
			// what gates the enqueue above, so the config only ever ships with the
			// tracker it configures (the Field's depends_on is admin UX only).
			//
			// Declared with `var` so it really is a window property: the tracker
			// reads it as window.gtm4wp_form_move_config, and a top-level `const`
			// would bind lexically and never appear on window (RI-14).
			$config = array(
				'filledOnly' => (bool) $this->opt( GTM4WP_OPTION_EVENTS_FORMMOVE_FILLEDONLY ),
			);

			wp_add_inline_script(
				'gtm4wp-form-move-tracker',
				'var gtm4wp_form_move_config = ' . ScriptTag::json_literal( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ) . ';',
				'before'
			);
		}
	}

	/**
	 * Sets a cookie so that the next page load can fire a GTM event after a user has been logged in.
	 *
	 * @return void
	 */
	public function on_login(): void {
		setcookie(
			'gtm4wp_user_logged_in',
			'1',
			0,
			'/',
			'',
			( false !== strstr( get_option( 'home' ), 'https:' ) ) && is_ssl(),
			true
		);
	}

	/**
	 * Sets a cookie so that the next page load can fire a GTM event after a new user has been registered.
	 *
	 * @return void
	 */
	public function on_register(): void {
		setcookie(
			'gtm4wp_user_registered',
			'1',
			0,
			'/',
			'',
			( false !== strstr( get_option( 'home' ), 'https:' ) ) && is_ssl(),
			true
		);
	}

	/**
	 * Removes the event cookies during the init hook.
	 *
	 * @return void
	 */
	public function clear_event_cookies(): void {
		if ( array_key_exists( 'gtm4wp_user_logged_in', $_COOKIE ) ) {
			setcookie(
				'gtm4wp_user_logged_in',
				'',
				-10000,
				'/',
				'',
				( false !== strstr( get_option( 'home' ), 'https:' ) ) && is_ssl(),
				true
			);
		}

		if ( array_key_exists( 'gtm4wp_user_registered', $_COOKIE ) ) {
			setcookie(
				'gtm4wp_user_registered',
				'',
				-10000,
				'/',
				'',
				( false !== strstr( get_option( 'home' ), 'https:' ) ) && is_ssl(),
				true
			);
		}
	}

	/**
	 * Outputs the login and registration event scripts in the footer.
	 * Port of the event parts of gtm4wp_wp_footer() from 1.x.
	 *
	 * @return void
	 */
	public function output_event_scripts(): void {
		$frontend = Plugin::instance()->frontend();
		if ( null === $frontend ) {
			return;
		}

		$script_tag = $frontend->script_tag();

		if ( $this->opt( GTM4WP_OPTION_EVENTS_USERLOGIN ) ) {
			$user_logged_in = array_key_exists( 'gtm4wp_user_logged_in', $_COOKIE ) ?
				filter_var( wp_unslash( $_COOKIE['gtm4wp_user_logged_in'] ), FILTER_VALIDATE_INT )
				: 0;

			if ( $user_logged_in ) {
				$event_script = '
' . $script_tag->opening_tag() . '
	' . $frontend->datalayer()->push_snippet( array( 'event' => 'gtm4wp.userLoggedIn' ) ) . '
</script>';

				$script_tag->print_script_block( $event_script );

				unset( $_COOKIE['gtm4wp_user_logged_in'] );
			}
		}

		if ( $this->opt( GTM4WP_OPTION_EVENTS_NEWUSERREG ) ) {
			$user_registered = array_key_exists( 'gtm4wp_user_registered', $_COOKIE ) ?
				filter_var( wp_unslash( $_COOKIE['gtm4wp_user_registered'] ), FILTER_VALIDATE_INT )
				: 0;

			if ( $user_registered ) {
				$event_script = '
' . $script_tag->opening_tag() . '
	' . $frontend->datalayer()->push_snippet( array( 'event' => 'gtm4wp.userRegistered' ) ) . '
</script>';

				$script_tag->print_script_block( $event_script );

				unset( $_COOKIE['gtm4wp_user_registered'] );
			}
		}
	}
}
