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
 * Adds gtm.allowlist / gtm.blocklist to the main data layer to control which
 * tag, trigger and variable types may execute. The entity ID lists mirror
 * Google's restriction documentation
 * (https://developers.google.com/tag-platform/tag-manager/restrict), registry
 * row U53 - re-derive rather than trust this comment. Group classes live in
 * GROUP_CLASS_IDS (`sandboxedScripts` controls community templates). Only the
 * plain ID lists live here; the labels are in the admin-only AdminSchema.
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
		'mf',
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
	 * Valid group class IDs.
	 *
	 * Group classes restrict whole families of entities that have no
	 * individual entity ID. `sandboxedScripts` covers the sandboxed
	 * JavaScript of custom tag/variable templates - the only restriction
	 * target that cannot be expressed as one of the entity IDs above.
	 * See https://developers.google.com/tag-platform/tag-manager/restrict.
	 *
	 * @var string[]
	 */
	public const GROUP_CLASS_IDS = array(
		'sandboxedScripts',
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
	 * Option defaults, 1.x compatible. The 1.x blacklist-sandboxed option is
	 * not carried over: it was never emitted; the `sandboxedScripts` group class
	 * serves its purpose.
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
	 * Registers the frontend hooks. Priority 11 keeps the restriction key
	 * after the page variables in the compiled data layer, mirroring the
	 * 1.x key order.
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
	 * Returns every valid entity ID (individual tags, triggers and variables).
	 *
	 * @return string[]
	 */
	public static function valid_entity_ids(): array {
		return array_merge( self::TAG_IDS, self::TRIGGER_IDS, self::VARIABLE_IDS );
	}

	/**
	 * Returns every valid group class ID.
	 *
	 * @return string[]
	 */
	public static function valid_group_classes(): array {
		return self::GROUP_CLASS_IDS;
	}

	/**
	 * Returns every valid restriction target: individual entity IDs plus the
	 * supported group classes. This is the allow-list a stored blacklist
	 * entry must match before it is emitted into the data layer.
	 *
	 * @return string[]
	 */
	public static function valid_restrictions(): array {
		return array_merge( self::valid_entity_ids(), self::valid_group_classes() );
	}

	/**
	 * Adds the selected restriction list (gtm.allowlist or gtm.blocklist) to
	 * the data layer.
	 *
	 * @param array $data_layer Array of key-value pairs output into the data layer variable.
	 * @return array
	 */
	public function add_datalayer_data( $data_layer ) {
		$_gtmrestrictlistitems = array();

		// Re-validated against the allow-list at the sink.
		$valid_restrictions = self::valid_restrictions();
		$stored_entities    = $this->opt( GTM4WP_OPTION_BLACKLIST_STATUS );
		if ( ! is_array( $stored_entities ) ) {
			$stored_entities = explode( ',', (string) $stored_entities );
		}

		foreach ( $stored_entities as $listed_entity ) {
			if ( in_array( $listed_entity, $valid_restrictions, true ) ) {
				$_gtmrestrictlistitems[] = $listed_entity;
			}
		}

		// gtm.allowlist / gtm.blocklist are the documented key names (the legacy
		// gtm.whitelist / gtm.blacklist pair is documented nowhere). Emit ONE key
		// for the selected mode and NEVER an empty companion: an empty array is
		// truthy in JavaScript, so an empty allowlist "has been set" and the GTM
		// runtime blocks the WHOLE container (verified against the runtime's own
		// code, not the documentation).
		$mode_key = ( 1 === (int) $this->opt( GTM4WP_OPTION_BLACKLIST_ENABLE ) )
			? 'gtm.blocklist'
			: 'gtm.allowlist';

		$data_layer[ $mode_key ] = $_gtmrestrictlistitems;

		return $data_layer;
	}
}
