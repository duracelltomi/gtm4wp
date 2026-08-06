<?php
/**
 * Blacklist module admin schema.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\Blacklist;

use GTM4WP\Module\AdminSchemaInterface;
use GTM4WP\Options\Field;

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions of the blacklist module.
 *
 * The entity labels are refreshed from Google's restriction documentation
 * (https://developers.google.com/tag-platform/tag-manager/restrict),
 * individual entity IDs plus the supported `sandboxedScripts` group class.
 * Vendor names are product names and are not translated, matching 1.x
 * behavior.
 */
final class AdminSchema implements AdminSchemaInterface {

	/**
	 * Human readable labels of the tag entity IDs.
	 *
	 * @return array<string, string>
	 */
	public static function tag_labels(): array {
		return array(
			'abtGeneric'          => 'AB TASTY Generic Tag',
			'ta'                  => 'AdAdvisor Tag',
			'adm'                 => 'Adometry Tag',
			'asp'                 => 'AdRoll Smart Pixel Tag',
			'awct'                => 'Google Ads Conversion Tracking Tag',
			'sp'                  => 'Google Ads Remarketing Tag',
			'awc'                 => 'Affiliate Window Conversion Tag',
			'awj'                 => 'Affiliate Window Journey Tag',
			'baut'                => 'Bing Ads Universal Event Tracking',
			'bb'                  => 'Bizrate Insights Buyer Survey Solution',
			'bsa'                 => 'Bizrate Insights Site Abandonment Survey Solution',
			'cts'                 => 'ClickTale Standard Tracking Tag (obsolete)',
			'csm'                 => 'comScore Unified Digital Measurement Tag',
			'mpm'                 => 'Conversant Mediaplex - IFRAME MCT Tag',
			'mpr'                 => 'Conversant Mediaplex - Standard IMG ROI Tag',
			'gclidw'              => 'Conversion Linker',
			'cegg'                => 'Crazy Egg Tag',
			'crto'                => 'Criteo OneTag',
			'html'                => 'Custom HTML Tag',
			'img'                 => 'Custom Image Tag',
			'dstag'               => 'DistroScale Tag',
			'flc'                 => 'Floodlight Counter Tag',
			'fls'                 => 'Floodlight Sales Tag',
			'm6d'                 => 'Dstillery Universal Pixel Tag',
			'ela'                 => 'Eulerian Analytics Tag',
			'gaawc'               => 'Google tag (Google Analytics 4 Configuration)',
			'gaawe'               => 'Google Analytics 4 Event',
			'ga'                  => 'Google Analytics Tag (legacy)',
			'gcs'                 => 'Google Consumer Surveys Website Satisfaction',
			'ts'                  => 'Google Trusted Stores Tag',
			'hjtc'                => 'Hotjar Tracking Code',
			'infinity'            => 'Infinity Call Tracking Tag',
			'sca'                 => 'Intent Media - Search Compare Ads',
			'k50Init'             => 'K50 tracking tag',
			'll'                  => 'LeadLab',
			'bzi'                 => 'LinkedIn Tag',
			'ljs'                 => 'Lytics JS Tag',
			'ms'                  => 'Marin Software Tag',
			'messagemate'         => 'OwnerListens Message Mate',
			'mf'                  => 'Mouseflow Tag',
			'ndcr'                => 'Nielsen DCR Static Lite Tag',
			'nudge'               => 'Nudge Content Analytics Tag',
			'okt'                 => 'Oktopost Tracking Code',
			'omc'                 => 'Optimise Conversion Tag',
			'pa'                  => 'Perfect Audience Pixel',
			'pc'                  => 'Personali Canvas',
			'pntr'                => 'Pinterest',
			'placedPixel'         => 'Placed',
			'pijs'                => 'Pulse Insights Voice of Customer Platform',
			'qcm'                 => 'Quantcast Audience Measurement',
			'qpx'                 => 'Quora Pixel',
			'fxm'                 => 'Rawsoft FoxMetrics',
			'scjs'                => 'SaleCycle JavaScript Tag',
			'scp'                 => 'SaleCycle Pixel Tag',
			'sfc'                 => 'SearchForce JavaScript Tracking for Conversion Page',
			'sfl'                 => 'SearchForce JavaScript Tracking for Landing Page',
			'sfr'                 => 'SearchForce Redirection Tracking Tag',
			'shareaholic'         => 'Shareaholic',
			'svw'                 => 'Survicate Widget',
			'tdlc'                => 'Tradedoubler Lead Conversion Tag',
			'tdsc'                => 'Tradedoubler Sale Conversion Tag',
			'tc'                  => 'Turn Conversion Tracking Tag',
			'tdc'                 => 'Turn Data Collection Tag',
			'twitter_website_tag' => 'Twitter Universal Website Tag',
			'uslt'                => 'Upsellit Global Footer Tag',
			'uspt'                => 'Upsellit Confirmation Tag',
			'vei'                 => 'Ve Interactive JavaScript Tag',
			'veip'                => 'Ve Interactive Pixel',
			'vdc'                 => 'VisualDNA Conversion Tag',
			'xpsh'                => 'Xtremepush',
			'yieldify'            => 'Yieldify',
			'zone'                => 'Zones',
		);
	}

	/**
	 * Human readable labels of the trigger entity IDs.
	 *
	 * @return array<string, string>
	 */
	public static function trigger_labels(): array {
		return array(
			'evl' => 'Element Visibility Listener/Trigger',
			'cl'  => 'Click Listener/Trigger',
			'fsl' => 'Form Submit Listener/Trigger',
			'hl'  => 'History Listener/Trigger',
			'jel' => 'JavaScript Error Listener/Trigger',
			'lcl' => 'Link Click Listener/Trigger',
			'sdl' => 'Scroll Depth Listener/Trigger',
			'tl'  => 'Timer Listener/Trigger',
			'ytl' => 'YouTube Video Listener/Trigger',
		);
	}

	/**
	 * Human readable labels of the variable entity IDs.
	 *
	 * @return array<string, string>
	 */
	public static function variable_labels(): array {
		return array(
			'k'    => '1st Party Cookie',
			'v'    => 'Auto-Event Variable / Data Layer Variable',
			'c'    => 'Constant',
			'ctv'  => 'Container Version Number',
			'e'    => 'Custom Event',
			'jsm'  => 'Custom JavaScript Variable',
			'dbg'  => 'Debug Mode',
			'd'    => 'DOM Element',
			'vis'  => 'Element Visibility',
			'gas'  => 'Google Analytics Settings (legacy)',
			'f'    => 'HTTP Referrer',
			'j'    => 'JavaScript Variable',
			'smm'  => 'Lookup Table',
			'r'    => 'Random Number',
			'remm' => 'RegEx Table',
			'u'    => 'URL',
		);
	}

	/**
	 * Human readable labels of the group class IDs.
	 *
	 * Group classes restrict families of entities that have no individual
	 * entity ID; `sandboxedScripts` covers the sandboxed scripts of custom
	 * tag/variable templates.
	 *
	 * @return array<string, string>
	 */
	public static function group_class_labels(): array {
		return array(
			'sandboxedScripts' => __( 'Sandboxed custom template scripts', 'duracelltomi-google-tag-manager' ),
		);
	}

	/**
	 * Module title.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Tag restrictions', 'duracelltomi-google-tag-manager' );
	}

	/**
	 * Module panel introduction.
	 *
	 * @return string
	 */
	public function intro(): string {
		return esc_html__( 'Here you can control which types of tags, triggers and variables can be executed on your site regardless of what tags are included in your container on the Google Tag Manager site. Use this to increase security!', 'duracelltomi-google-tag-manager' ) . '<br />' .
			esc_html__( 'Do not modify if you do not know what to do, since it can cause issues with your tag deployment!', 'duracelltomi-google-tag-manager' ) . '<br />' .
			esc_html__( 'For example blocklisting everything and only allowlisting the Google Analytics tag without allowlisting the URL variable type will cause your Google Analytics tags to be blocked anyway since the attached triggers (Page View) can not fire!', 'duracelltomi-google-tag-manager' );
	}

	/**
	 * Accordion groups.
	 *
	 * @return array<string, string>
	 */
	public function groups(): array {
		return array(
			'mode'     => __( 'Restriction mode', 'duracelltomi-google-tag-manager' ),
			'entities' => __( 'Entities', 'duracelltomi-google-tag-manager' ),
		);
	}

	/**
	 * Field definitions.
	 *
	 * Every selected entity - tag, trigger, variable and group class alike -
	 * is stored in the single 1.x compatible blacklist-status option. The
	 * four entity types are a presentation concern only: the multiselect
	 * declares them as choice sections, so the admin UI renders four labelled
	 * lists while the stored value stays one flat list.
	 *
	 * The section id lists are derived from the same four label tables that
	 * build the choices, so an entity can never be labelled in one place and
	 * sorted in another. BlacklistAdminSchemaTest pins that the sections cover
	 * every choice exactly once.
	 *
	 * @return Field[]
	 */
	public function fields(): array {
		$all_labels = self::tag_labels() + self::trigger_labels() + self::variable_labels() + self::group_class_labels();

		return array(
			new Field(
				key: GTM4WP_OPTION_BLACKLIST_ENABLE,
				type: Field::TYPE_SELECT,
				default_value: '0',
				label: __( 'Restriction mode', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Select whether the checked entities below should be blocklisted (blocked) or allowlisted (everything else blocked).', 'duracelltomi-google-tag-manager' ),
				group: 'mode',
				// The stored VALUES are part of the contract: 1 is blocklist mode
				// in BlacklistModule::add_datalayer_data(). Only the labels moved
				// to Google's current wording - renumbering these would silently
				// invert the restriction mode of every site that already saved one.
				choices: array(
					'0' => __( 'Disabled', 'duracelltomi-google-tag-manager' ),
					'1' => __( 'Blocklist selected entities', 'duracelltomi-google-tag-manager' ),
					'2' => __( 'Allowlist selected entities', 'duracelltomi-google-tag-manager' ),
				),
				sanitizer: static function ( $value ) {
					$value = (int) $value;

					if ( ( $value < 0 ) || ( $value > 2 ) ) {
						return 0;
					}

					return $value;
				}
			),
			new Field(
				key: GTM4WP_OPTION_BLACKLIST_STATUS,
				type: Field::TYPE_MULTISELECT,
				default_value: '',
				label: __( 'Restricted entities', 'duracelltomi-google-tag-manager' ),
				description: esc_html__( 'Select the tag, trigger, variable and entity group types affected by the restriction mode above.', 'duracelltomi-google-tag-manager' ),
				group: 'entities',
				choices: $all_labels,
				sanitizer: static function ( $value ) {
					$values = is_array( $value ) ? $value : explode( ',', Field::to_string( $value ) );
					$valid  = BlacklistModule::valid_restrictions();

					$values = array_values(
						array_filter(
							array_map( 'sanitize_text_field', array_map( static fn ( $one ) => Field::to_string( $one ), $values ) ),
							static fn ( $one_value ) => in_array( $one_value, $valid, true )
						)
					);

					return implode( ',', $values );
				},
				choice_sections: array(
					array(
						'label'   => __( 'Tags', 'duracelltomi-google-tag-manager' ),
						'choices' => array_keys( self::tag_labels() ),
					),
					array(
						'label'   => __( 'Triggers', 'duracelltomi-google-tag-manager' ),
						'choices' => array_keys( self::trigger_labels() ),
					),
					array(
						'label'   => __( 'Variables', 'duracelltomi-google-tag-manager' ),
						'choices' => array_keys( self::variable_labels() ),
					),
					array(
						'label'   => __( 'Entity groups', 'duracelltomi-google-tag-manager' ),
						'choices' => array_keys( self::group_class_labels() ),
					),
				)
			),
		);
	}

	/**
	 * The blacklist module is always available.
	 *
	 * @return string
	 */
	public function unavailable_message(): string {
		return '';
	}
}
