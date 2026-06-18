<?php
/**
 * Axeptio CMP integration for gtm4wp: loads the SDK, optionally drives Google Consent Mode v2,
 * and bridges consent choices to the data layer.
 *
 * @package GTM4WP
 */

/**
 * Builds the Consent Mode v2 default state (all denied) handed over to the Axeptio SDK.
 *
 * @return array
 */
function gtm4wp_axeptio_get_consent_mode_default() {
	$consent_default = array(
		'analytics_storage'  => 'denied',
		'ad_storage'         => 'denied',
		'ad_user_data'       => 'denied',
		'ad_personalization' => 'denied',
		'wait_for_update'    => 500,
	);

	/**
	 * Filters the Consent Mode v2 default, e.g. to grant signals for non-GDPR audiences.
	 *
	 * @param array $consent_default
	 */
	return apply_filters( GTM4WP_WPFILTER_AXEPTIO_CONSENT_MODE_DEFAULT, $consent_default );
}

/**
 * Builds the window.axeptioSettings object injected before the Axeptio SDK.
 *
 * @return array
 */
function gtm4wp_axeptio_get_settings() {
	global $gtm4wp_options;

	$settings = array(
		'clientId' => $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID ],
	);

	$cookies_version = $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_AXEPTIO_COOKIES_VERSION ];
	if ( '' !== $cookies_version ) {
		$settings['cookiesVersion'] = $cookies_version;
	}

	if ( $gtm4wp_options[ GTM4WP_OPTION_INTEGRATE_AXEPTIO_CONSENTMODE ] ) {
		$settings['googleConsentMode'] = array(
			'default' => gtm4wp_axeptio_get_consent_mode_default(),
		);
	}

	return $settings;
}

/**
 * Outputs the Axeptio settings, SDK loader and data layer bridge.
 *
 * Hooked early (priority 1) so the consent default is set before the GTM container and Google tags load.
 *
 * @return void
 */
function gtm4wp_axeptio_wp_head() {
	global $gtm4wp_datalayer_name;

	$axeptio_settings = wp_json_encode(
		gtm4wp_axeptio_get_settings(),
		JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS
	);

	$data_layer_name = esc_js( $gtm4wp_datalayer_name );

	$script_tag = '
' . gtm4wp_generate_script_opening_tag() . '
	window.axeptioSettings = ' . $axeptio_settings . ';

	window._axcb = window._axcb || [];
	window._axcb.push(function(axeptio) {
		axeptio.on("cookies:complete", function(choices) {
			window.' . $data_layer_name . ' = window.' . $data_layer_name . ' || [];
			window.' . $data_layer_name . '.push({
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
	})(document, "script");
</script>';

	echo htmlspecialchars_decode( // phpcs:ignore
		wp_kses(
			$script_tag,
			gtm4wp_get_sanitize_script_block_rules()
		)
	);
}
add_action( 'wp_head', 'gtm4wp_axeptio_wp_head', 1, 0 );
