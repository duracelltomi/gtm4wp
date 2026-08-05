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
 * Adds gtm.allowlist / gtm.blocklist to the main data layer to control
 * which tag, trigger and variable types are allowed to execute on the site.
 * Port of the blacklist part of gtm4wp_add_basic_datalayer_data() from 1.x,
 * which wrote the undocumented legacy gtm.whitelist / gtm.blacklist names.
 *
 * The entity ID list below is refreshed from Google's restriction
 * documentation (https://developers.google.com/tag-platform/tag-manager/restrict)
 * and contains individual entity IDs only. Compared to 1.x: added gaawc,
 * gaawe (tags) and gas (variable); removed ua (Universal Analytics), which
 * Google no longer documents. Group classes (which have no individual entity
 * ID) live in the separate GROUP_CLASS_IDS list; `sandboxedScripts` is
 * supported there to control GTM community templates.
 *
 * Last verified against the source page: 2026-08-05 — 72 tag, 9 trigger and
 * 16 variable IDs, matching this table exactly. The list is tracked as entry
 * U53 in .upstream/upstream-review-checklist.md; this comment is a record,
 * not a control, so re-derive rather than trust it.
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
	 * Option defaults, 1.x compatible.
	 *
	 * The standalone blacklist-sandboxed option of 1.x is intentionally not
	 * carried over: it was stored but never emitted on the frontend (a
	 * non-functional checkbox). Its purpose - restricting the sandboxed
	 * scripts of custom tag/variable templates - is now served properly by
	 * the `sandboxedScripts` group class, selectable in the same
	 * blacklist-status list as the individual entity IDs.
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

		// Because of security reasons, we loop through each stored entity in the options and validate them
		// to make sure nobody has entered some 'funny' item manually.
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

		/*
		 * gtm.allowlist / gtm.blocklist are the key names Google documents. The
		 * gtm.whitelist / gtm.blacklist pair this replaces appears nowhere in the
		 * current documentation, nor in the older tag-manager/web/restrict page -
		 * neither states that the legacy names are supported, deprecated, or
		 * anything at all. The runtime has historically read both pairs, but
		 * "undocumented and observed to work once" is a weaker guarantee than
		 * "documented", and the failure mode if a legacy name is ever dropped is
		 * silent: the plugin writes the key, nothing reads it, and every tag runs
		 * unrestricted while the settings screen still shows the restriction.
		 *
		 * Emit ONE key, for the selected mode only, and never an empty companion.
		 * That is not a style choice - it is the bug this method used to have.
		 * 1.x and pre-fix 2.0 wrote both keys and left the unselected one as an
		 * empty array. Google Tag Manager's runtime reads the allowlist as:
		 *
		 *     var a = SA("gtm.allowlist") || SA("gtm.whitelist");
		 *     ...
		 *     a && (k = k && $A(g, h, b));
		 *
		 * An empty array is TRUTHY in JavaScript, so an empty allowlist is an
		 * allowlist that "has been set" - and $A() then returns false for every
		 * entity, because nothing is in an empty list. In blocklist mode the
		 * plugin was therefore blocking the WHOLE container rather than the
		 * selected entities. It fails closed, so it reads as an over-eager
		 * restriction rather than as a defect, which is why it survived so long.
		 * (Allowlist mode was unaffected: an empty blocklist blocks nothing.)
		 *
		 * Verified by replaying the runtime's own YA()/$A()/Tb()/Lb() against
		 * this method's output, not inferred from the documentation - which
		 * describes neither empty lists nor these key names.
		 */
		$mode_key = ( 1 === (int) $this->opt( GTM4WP_OPTION_BLACKLIST_ENABLE ) )
			? 'gtm.blocklist'
			: 'gtm.allowlist';

		$data_layer[ $mode_key ] = $_gtmrestrictlistitems;

		return $data_layer;
	}
}
