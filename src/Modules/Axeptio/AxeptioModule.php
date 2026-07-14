<?php
/**
 * Axeptio CMP integration module (lean frontend class).
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\Axeptio;

use GTM4WP\Frontend\ConsentDefaults;
use GTM4WP\Frontend\ContainerCode;
use GTM4WP\Module\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the Axeptio CMP SDK directly (no separate Axeptio plugin needed),
 * optionally drives Google Consent Mode v2 and bridges consent choices to
 * the data layer.
 *
 * The SDK loader, the consent default and the data layer bridge are appended
 * to the GTM4WP head block via ContainerCode::FILTER_HEADER_TOP_JS — the same
 * extension point the WebToffee consent integration uses — so the settings are
 * emitted before the GTM container loader (wp_head priority 1 vs. >= 2) and the
 * script goes through the core ScriptTag sanitizer instead of a raw echo. Port
 * of integration/axeptio.php from the 1.x pull request.
 */
final class AxeptioModule extends AbstractModule {

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'axeptio';
	}

	/**
	 * Option defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			GTM4WP_OPTION_INTEGRATE_AXEPTIO             => false,
			GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID   => '',
			GTM4WP_OPTION_INTEGRATE_AXEPTIO_COOKIES_VERSION => '',
			GTM4WP_OPTION_INTEGRATE_AXEPTIO_CONSENTMODE => false,
		);
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
	 * Registers the frontend hooks. Nothing is registered unless the
	 * integration is enabled and a project ID is configured.
	 *
	 * @return void
	 */
	protected function register_frontend_hooks(): void {
		if ( ! $this->opt( GTM4WP_OPTION_INTEGRATE_AXEPTIO ) || '' === (string) $this->opt( GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID ) ) {
			return;
		}

		add_filter( ContainerCode::FILTER_HEADER_TOP_JS, array( $this, 'add_axeptio_head_js' ), 5, 2 );

		// When Axeptio drives Consent Mode v2 it fires the consent default
		// itself, so GTM4WP must not output its own (would be sent twice).
		if ( $this->opt( GTM4WP_OPTION_INTEGRATE_AXEPTIO_CONSENTMODE ) ) {
			add_filter( ConsentDefaults::FILTER_DEFAULT_ENABLED, array( $this, 'suppress_consent_default' ) );
		}
	}

	/**
	 * Appends the Axeptio settings object, SDK loader and the data layer
	 * consent bridge to the GTM4WP head block.
	 *
	 * The settings object is JSON encoded with the full hex flag set so no
	 * value can break out of the inline script; the ampersand hex flag also
	 * keeps the block safe when the head block is printed without the
	 * ampersand-restore that ScriptTag::print_script_block() applies.
	 *
	 * @param string $inline_js      Inline JS collected so far.
	 * @param string $datalayer_name Name of the data layer JS variable.
	 * @return string
	 */
	public function add_axeptio_head_js( $inline_js, $datalayer_name ) {
		$axeptio_settings = wp_json_encode(
			$this->settings(),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS
		);

		$datalayer_name = esc_js( $datalayer_name );

		return $inline_js . '
	window.axeptioSettings = ' . $axeptio_settings . ';

	window._axcb = window._axcb || [];
	window._axcb.push(function(axeptio) {
		axeptio.on("cookies:complete", function(choices) {
			window.' . $datalayer_name . ' = window.' . $datalayer_name . ' || [];
			window.' . $datalayer_name . '.push({
				"event": "gtm4wp.axeptioConsentUpdate",
				"axeptioChoices": choices
			});
		});
	});

	(function(d, s) {
		var t = d.getElementsByTagName(s)[0], e = d.createElement(s);
		e.async = true;
		e.src = "https://static.axept.io/sdk.js";
		t.parentNode.insertBefore(e, t);
	})(document, "script");';
	}

	/**
	 * Filter callback that suppresses the GTM4WP consent mode default block
	 * when Axeptio is handling Consent Mode v2.
	 *
	 * @return bool Always false.
	 */
	public function suppress_consent_default(): bool {
		return false;
	}

	/**
	 * Builds the window.axeptioSettings object handed to the SDK.
	 *
	 * @return array<string, mixed>
	 */
	private function settings(): array {
		$settings = array(
			'clientId' => (string) $this->opt( GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID ),
		);

		$cookies_version = (string) $this->opt( GTM4WP_OPTION_INTEGRATE_AXEPTIO_COOKIES_VERSION );
		if ( '' !== $cookies_version ) {
			$settings['cookiesVersion'] = $cookies_version;
		}

		if ( $this->opt( GTM4WP_OPTION_INTEGRATE_AXEPTIO_CONSENTMODE ) ) {
			$settings['googleConsentMode'] = array(
				'default' => $this->consent_mode_default(),
			);
		}

		return $settings;
	}

	/**
	 * Builds the Consent Mode v2 default state (all denied) passed to the SDK.
	 *
	 * @return array<string, mixed>
	 */
	private function consent_mode_default(): array {
		$consent_default = array(
			'analytics_storage'  => 'denied',
			'ad_storage'         => 'denied',
			'ad_user_data'       => 'denied',
			'ad_personalization' => 'denied',
			'wait_for_update'    => 500,
		);

		/**
		 * Filters the Axeptio Consent Mode v2 default, e.g. to grant signals
		 * for non-GDPR audiences.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string, mixed> $consent_default The default consent state.
		 */
		return (array) apply_filters( GTM4WP_WPFILTER_AXEPTIO_CONSENT_MODE_DEFAULT, $consent_default );
	}
}
