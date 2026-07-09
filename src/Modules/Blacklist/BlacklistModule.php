<?php
/**
 * Tag blacklist/whitelist module (lean frontend class).
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\Blacklist;

use GTM4WP\Module\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * Adds gtm.whitelist / gtm.blacklist to the main data layer to control
 * which tag, trigger and variable types are allowed to execute on the site.
 * Port of the blacklist part of gtm4wp_add_basic_datalayer_data() from 1.x.
 *
 * The entity ID list below is refreshed from Google's restriction
 * documentation (https://developers.google.com/tag-platform/tag-manager/restrict)
 * and contains individual entity IDs only, no group classes.
 * Compared to 1.x: added gaawc, gaawe (tags) and gas (variable);
 * removed ua (Universal Analytics) and mf (Mouseflow).
 *
 * Only the plain ID list lives here (needed for frontend validation);
 * the human readable labels live in the admin-only AdminSchema class.
 */
final class BlacklistModule extends AbstractModule {

	/**
	 * Valid tag entity IDs.
	 *
	 * @var string[]
	 */
	public const TAG_IDS = array(
		'abtGeneric',
		'ta',
		'adm',
		'asp',
		'awct',
		'sp',
		'awc',
		'awj',
		'baut',
		'bb',
		'bsa',
		'cts',
		'csm',
		'mpm',
		'mpr',
		'gclidw',
		'cegg',
		'crto',
		'html',
		'img',
		'dstag',
		'flc',
		'fls',
		'm6d',
		'ela',
		'gaawc',
		'gaawe',
		'ga',
		'gcs',
		'ts',
		'hjtc',
		'infinity',
		'sca',
		'k50Init',
		'll',
		'bzi',
		'ljs',
		'ms',
		'messagemate',
		'ndcr',
		'nudge',
		'okt',
		'omc',
		'pa',
		'pc',
		'pntr',
		'placedPixel',
		'pijs',
		'qcm',
		'qpx',
		'fxm',
		'scjs',
		'scp',
		'sfc',
		'sfl',
		'sfr',
		'shareaholic',
		'svw',
		'tdlc',
		'tdsc',
		'tc',
		'tdc',
		'twitter_website_tag',
		'uslt',
		'uspt',
		'vei',
		'veip',
		'vdc',
		'xpsh',
		'yieldify',
		'zone',
	);

	/**
	 * Valid trigger entity IDs.
	 *
	 * @var string[]
	 */
	public const TRIGGER_IDS = array(
		'evl',
		'cl',
		'fsl',
		'hl',
		'jel',
		'lcl',
		'sdl',
		'tl',
		'ytl',
	);

	/**
	 * Valid variable entity IDs.
	 *
	 * @var string[]
	 */
	public const VARIABLE_IDS = array(
		'k',
		'v',
		'c',
		'ctv',
		'e',
		'jsm',
		'dbg',
		'd',
		'vis',
		'gas',
		'f',
		'j',
		'smm',
		'r',
		'remm',
		'u',
	);

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'blacklist';
	}

	/**
	 * Option defaults, 1.x compatible.
	 *
	 * The blacklist-sandboxed option of 1.x is intentionally not carried
	 * over: it was stored but never used on the frontend, and its only
	 * conceivable purpose (the sandboxedScripts group class) is out of
	 * scope since 2.0 manages individual entity IDs only.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			GTM4WP_OPTION_BLACKLIST_ENABLE => 0,
			GTM4WP_OPTION_BLACKLIST_STATUS => '',
		);
	}

	/**
	 * Registers the frontend hooks. Priority 11 keeps the gtm.whitelist /
	 * gtm.blacklist keys after the page variables in the compiled data
	 * layer, mirroring the 1.x key order.
	 *
	 * @return void
	 */
	protected function register_frontend_hooks(): void {
		if ( ! ( (int) $this->opt( GTM4WP_OPTION_BLACKLIST_ENABLE ) > 0 ) ) {
			return;
		}

		add_filter( GTM4WP_WPFILTER_COMPILE_DATALAYER, array( $this, 'add_datalayer_data' ), 11 );
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
	 * Returns every valid entity ID.
	 *
	 * @return string[]
	 */
	public static function valid_entity_ids(): array {
		return array_merge( self::TAG_IDS, self::TRIGGER_IDS, self::VARIABLE_IDS );
	}

	/**
	 * Adds gtm.whitelist / gtm.blacklist to the data layer.
	 *
	 * @param array $data_layer Array of key-value pairs output into the data layer variable.
	 * @return array
	 */
	public function add_datalayer_data( $data_layer ) {
		$_gtmrestrictlistitems = array();

		// Because of security reasons, we loop through each stored entity in the options and validate them
		// to make sure nobody has entered some 'funny' item manually.
		$valid_entity_ids = self::valid_entity_ids();
		$stored_entities  = $this->opt( GTM4WP_OPTION_BLACKLIST_STATUS );
		if ( ! is_array( $stored_entities ) ) {
			$stored_entities = explode( ',', (string) $stored_entities );
		}

		foreach ( $stored_entities as $listed_entity ) {
			if ( in_array( $listed_entity, $valid_entity_ids, true ) ) {
				$_gtmrestrictlistitems[] = $listed_entity;
			}
		}

		$_gtmwhitelist = array();
		$_gtmblacklist = array();
		if ( 1 === (int) $this->opt( GTM4WP_OPTION_BLACKLIST_ENABLE ) ) {
			$_gtmblacklist = array_merge( $_gtmblacklist, $_gtmrestrictlistitems );
		} else {
			$_gtmwhitelist = array_merge( $_gtmwhitelist, $_gtmrestrictlistitems );
		}

		$data_layer['gtm.whitelist'] = $_gtmwhitelist;
		$data_layer['gtm.blacklist'] = $_gtmblacklist;

		return $data_layer;
	}
}
