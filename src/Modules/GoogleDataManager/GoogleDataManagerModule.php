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
 * API: the destination list, the attribution capture that lets a server-side
 * event be matched to its purchase, and the refund events a browser never
 * sees. The send lane is registered in Plugin::boot(), not here, because it
 * must exist on cron and admin requests too. is_available() stays the
 * inherited true on purpose: "no service account yet" is an onboarding state,
 * and a vault check here would re-add the per-pageview read autoload=false avoids.
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
			GTM4WP_OPTION_GDM_SEND_REFUNDS        => false,
		);
	}

	/**
	 * Registers the attribution capture when it is on AND can work: a commerce
	 * platform and at least one destination. depends_on only greys the admin
	 * checkbox; a destination deleted after saving leaves capture on with
	 * nothing to ask for.
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

		( new CaptureHooks( new CaptureStats() ) )->register_hooks();
	}

	/**
	 * Loads the capture bundle with the measurement IDs to ask Google about
	 * and the cookie contract the server-side parser expects. Printed as a
	 * `var` so it really is a window property (RI-14), hex-flag encoded.
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

		$backfill = ReceiptPage::backfill_config();

		if ( null !== $backfill ) {
			$config['backfill'] = $backfill;
		}

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
