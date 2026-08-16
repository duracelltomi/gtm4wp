<?php
/**
 * Consent mode and consent tool integrations module (lean frontend class).
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\ConsentMode;

use GTM4WP\Frontend\ContainerCode;
use GTM4WP\Module\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the Google consent mode default flags and the consent tool
 * integrations (Cookiebot, WebToffee GDPR Cookie Consent).
 *
 * The consent mode default block itself is output by the core
 * GTM4WP\Frontend\ConsentDefaults service before the container code; the
 * Cookiebot flag is consumed by the core ScriptTag service. This module
 * adds the WebToffee consent update callback to the head block.
 * Port of the WebToffee part of gtm4wp_wp_header_top() from 1.x.
 */
final class ConsentModeModule extends AbstractModule {

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'consent';
	}

	/**
	 * Option defaults, 1.x compatible.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			GTM4WP_OPTION_INTEGRATE_CONSENTMODE           => false,
			GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ADS       => false,
			GTM4WP_OPTION_INTEGRATE_CONSENTMODE_AD_USER_DATA => false,
			GTM4WP_OPTION_INTEGRATE_CONSENTMODE_AD_PERSO  => false,
			GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ANALYTICS => false,
			GTM4WP_OPTION_INTEGRATE_CONSENTMODE_PERSO     => false,
			GTM4WP_OPTION_INTEGRATE_CONSENTMODE_FUNC      => false,
			GTM4WP_OPTION_INTEGRATE_CONSENTMODE_SECURUTY  => false,
			GTM4WP_OPTION_INTEGRATE_CONSENTMODE_REGIONS   => '',
			GTM4WP_OPTION_INTEGRATE_CONSENTMODE_REGIONS_CUSTOM => '',
			GTM4WP_OPTION_INTEGRATE_CONSENTMODE_WAITFORUPDATE => 0,
			GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ADSREDACTION => false,
			GTM4WP_OPTION_INTEGRATE_CONSENTMODE_URLPASSTHROUGH => false,
			GTM4WP_OPTION_INTEGRATE_COOKIEBOT             => false,
			GTM4WP_OPTION_INTEGRATE_WEBTOFFEE_GDPR        => false,
			GTM4WP_OPTION_INTEGRATE_COOKIEYES             => false,
			GTM4WP_OPTION_INTEGRATE_AXEPTIO               => false,
			GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID     => '',
			GTM4WP_OPTION_INTEGRATE_AXEPTIO_COOKIES_VERSION => '',
			GTM4WP_OPTION_INTEGRATE_AXEPTIO_CONSENTMODE   => false,
		);
	}

	/**
	 * Registers the frontend hooks.
	 *
	 * @return void
	 */
	protected function register_frontend_hooks(): void {
		if ( $this->opt( GTM4WP_OPTION_INTEGRATE_WEBTOFFEE_GDPR ) ) {
			add_filter( ContainerCode::FILTER_HEADER_TOP_JS, array( $this, 'add_webtoffee_header_js' ), 20, 2 );
		}

		if ( $this->opt( GTM4WP_OPTION_INTEGRATE_COOKIEYES ) ) {
			add_filter( ContainerCode::FILTER_HEADER_TOP_JS, array( $this, 'add_cookieyes_header_js' ), 20, 2 );
		}

		// Axeptio is a consent management tool owned by this module; its own
		// enabling option and project ID gate the registration.
		( new Axeptio( $this->options ) )->register_hooks();
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
	 * Adds the WebToffee GDPR Cookie Consent callback to the data layer
	 * initialization block. Byte-identical to the 1.x block inside
	 * gtm4wp_wp_header_top().
	 *
	 * @param string $inline_js      Inline JS collected so far.
	 * @param string $datalayer_name Name of the data layer JS variable.
	 * @return string
	 */
	public function add_webtoffee_header_js( $inline_js, $datalayer_name ) {
		return $inline_js . '
	var CookieLawInfo_Accept_Callback = (function() {
		var gtm4wp_original_cli_callback = CookieLawInfo_Accept_Callback;

		return function() {
			if ( !window.CLI.consent ) {
				return false;
			}

			window.' . esc_js( $datalayer_name ) . ' = window.' . esc_js( $datalayer_name ) . ' || [];
			window.' . esc_js( $datalayer_name ) . '.push({
				"event": "cookie_consent_update",
				"consent_data": window.CLI.consent
			});

			for(var i in window.CLI.consent) {
				window.' . esc_js( $datalayer_name ) . '.push({
					"event": "cookie_consent_" + i
				});
			}

			if ( "function" == typeof gtm4wp_original_cli_callback ) {
				gtm4wp_original_cli_callback();
			}
		}
	})();';
	}

	/**
	 * Bridges CookieYes' documented consent events to a data layer
	 * cookie_consent_update push, so the GTM container gets a defined consent
	 * signal to sequence tags on (the same event name the WebToffee and
	 * Cookiebot paths emit).
	 *
	 * Based on CookieYes' public Consent Banner Action API, not the WebToffee
	 * global-callback override: CookieYes dispatches DOM events on document —
	 * cookieyes_consent_update (detail: { accepted:[…], rejected:[…] }) when the
	 * visitor sets or changes consent, and cookieyes_banner_load (detail:
	 * { categories, isUserActionCompleted, … }) on every load. This mirrors the
	 * Axeptio cookies:complete bridge rather than add_webtoffee_header_js().
	 *
	 * Only the data layer variable name is interpolated from PHP (escaped with
	 * esc_js exactly like the sibling consent bridges); the consent payload is
	 * read from the browser event, never from PHP, so there is no server-side
	 * script sink here.
	 *
	 * @param string $inline_js      Inline JS collected so far.
	 * @param string $datalayer_name Name of the data layer JS variable.
	 * @return string
	 */
	public function add_cookieyes_header_js( $inline_js, $datalayer_name ) {
		$datalayer_name = esc_js( $datalayer_name );

		return $inline_js . '
	document.addEventListener("cookieyes_consent_update", function(e) {
		window.' . $datalayer_name . ' = window.' . $datalayer_name . ' || [];
		window.' . $datalayer_name . '.push({
			"event": "cookie_consent_update",
			"consent_data": (e && e.detail) || {}
		});
	});

	document.addEventListener("cookieyes_banner_load", function(e) {
		if ( !e || !e.detail || !e.detail.isUserActionCompleted ) {
			return;
		}

		window.' . $datalayer_name . ' = window.' . $datalayer_name . ' || [];
		window.' . $datalayer_name . '.push({
			"event": "cookie_consent_update",
			"consent_data": e.detail
		});
	});';
	}
}
