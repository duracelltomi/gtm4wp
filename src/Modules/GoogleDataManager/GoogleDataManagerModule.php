<?php
/**
 * Google Data Manager module (lean class).
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\GoogleDataManager;

use GTM4WP\Frontend\ScriptTag;
use GTM4WP\Module\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * Sends e-commerce signals from the server to Google through the Data Manager
 * API. This version carries the foundation: the destination list (which GA4
 * property and data stream to send to, with which stored GoogleAuth service
 * account), its validateOnly test probe and the per-destination health
 * records. The features that actually send - server-side refund events,
 * attribution capture - build on these in later development phases.
 *
 * The is_available() answer stays unconditional on purpose (the inherited true): "no
 * service account uploaded yet" is an onboarding state, not an environment
 * dependency, and a vault check here would both hide the settings panel
 * behind a disabled card and re-add the per-pageview option read the
 * autoload=false choice exists to avoid. Gating is per feature instead,
 * inside the paths that need a key.
 */
final class GoogleDataManagerModule extends AbstractModule {

	/**
	 * Module id; also the settings-app bookmark (`#google-data-manager`).
	 */
	public const ID = 'google-data-manager';

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Option defaults. Every option of this module starts off/empty: each one
	 * is part of the plugin's first data-leaves-the-site feature.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			GTM4WP_OPTION_GDM_DESTINATIONS        => array(),
			GTM4WP_OPTION_GDM_CAPTURE_ATTRIBUTION => false,
			GTM4WP_OPTION_GDM_CONSENT_POLICY      => ConsentPolicy::POLICY_EEA_ONLY,
		);
	}

	/**
	 * Registers the attribution capture, when it is on and can work.
	 *
	 * Three conditions, not one: the option, a commerce platform to have
	 * orders at all, and at least one destination to take a measurement ID
	 * from. The last one is also declared as the field's depends_on, but that
	 * only greys the checkbox in the admin - a site that saved the option
	 * while a destination existed and deleted the destination afterwards
	 * arrives here with capture on and nothing to ask for (the UserEvents
	 * module carries the same reminder).
	 *
	 * @return void
	 */
	protected function register_frontend_hooks(): void {
		if ( ! $this->opt( GTM4WP_OPTION_GDM_CAPTURE_ATTRIBUTION ) ) {
			return;
		}

		if ( ! function_exists( 'WC' ) && ! function_exists( 'EDD' ) ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_capture_script' ) );
	}

	/**
	 * Loads the capture bundle with the values it needs to speak both
	 * contracts: which measurement IDs to ask Google about, and the cookie
	 * format the server-side parser expects.
	 *
	 * The config is printed as a `var` so that it really becomes a window
	 * property - a top-level `const` binds lexically and would never reach the
	 * script (RI-14) - and JSON-encoded with the full hex flag set, since it
	 * lands inside a script block.
	 *
	 * @return void
	 */
	public function enqueue_capture_script(): void {
		if ( null === $this->options ) {
			return;
		}

		$measurement_ids = array();
		foreach ( DestinationRows::rows( $this->options ) as $row ) {
			if ( DestinationRows::TYPE_GA4 === $row[ DestinationRows::COLUMN_TYPE ] ) {
				$measurement_ids[] = $row[ DestinationRows::COLUMN_MEASUREMENT ];
			}
		}

		$measurement_ids = array_values( array_unique( $measurement_ids ) );

		if ( array() === $measurement_ids ) {
			return;
		}

		$this->enqueue_script( 'gtm4wp-attribution', 'gtm4wp-attribution.js' );

		$config = array_merge(
			AttributionCookies::script_config(),
			array( 'measurementIds' => $measurement_ids )
		);

		wp_add_inline_script(
			'gtm4wp-attribution',
			'var gtm4wp_gdm_attribution_config = ' . ScriptTag::json_literal( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS ) . ';',
			'before'
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
}
