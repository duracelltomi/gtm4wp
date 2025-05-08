<?php
/**
 * Plugin constant declarations and default option values.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

define( 'GTM4WP_OPTIONS', 'gtm4wp-options' );
define( 'GTM4WP_OPTION_GTM_CODE', 'gtm-code' );
define( 'GTM4WP_OPTION_GTM_PLACEMENT', 'gtm-code-placement' );
define( 'GTM4WP_OPTION_DATALAYER_NAME', 'gtm-datalayer-variable-name' );
define( 'GTM4WP_OPTION_ENV_GTM_AUTH', 'gtm-env-gtm-auth' );
define( 'GTM4WP_OPTION_ENV_GTM_PREVIEW', 'gtm-env-gtm-preview' );
define( 'GTM4WP_OPTION_LOADEARLY', 'gtm-load-gtm-early' );
define( 'GTM4WP_OPTION_GTMDOMAIN', 'gtm-domain-name' );
define( 'GTM4WP_OPTION_GTMCUSTOMPATH', 'gtm-custom-path' );
define( 'GTM4WP_OPTION_NOGTMFORLOGGEDIN', 'gtm-no-gtm-for-logged-in' );
define( 'GTM4WP_OPTION_NOCONSOLELOG', 'gtm-no-console-log' );

define( 'GTM4WP_OPTION_INCLUDE_LOGGEDIN', 'include-loggedin' );
define( 'GTM4WP_OPTION_INCLUDE_USERROLE', 'include-userrole' );
define( 'GTM4WP_OPTION_INCLUDE_USERID', 'include-userid' );
define( 'GTM4WP_OPTION_INCLUDE_USEREMAIL', 'include-useremail' );
define( 'GTM4WP_OPTION_INCLUDE_USERREGDATE', 'include-userregdate' );
define( 'GTM4WP_OPTION_INCLUDE_USERNAME', 'include-username' );
define( 'GTM4WP_OPTION_INCLUDE_VISITOR_IP', 'include-visitor-ip' );
define( 'GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER', 'include-visitor-ip-header' );
define( 'GTM4WP_OPTION_INCLUDE_POSTTYPE', 'include-posttype' );
define( 'GTM4WP_OPTION_INCLUDE_CATEGORIES', 'include-categories' );
define( 'GTM4WP_OPTION_INCLUDE_TAGS', 'include-tags' );
define( 'GTM4WP_OPTION_INCLUDE_AUTHORID', 'include-authorid' );
define( 'GTM4WP_OPTION_INCLUDE_AUTHOR', 'include-author' );
define( 'GTM4WP_OPTION_INCLUDE_POSTDATE', 'include-postdate' );
define( 'GTM4WP_OPTION_INCLUDE_POSTTITLE', 'include-posttitle' );
define( 'GTM4WP_OPTION_INCLUDE_POSTCOUNT', 'include-postcount' );
define( 'GTM4WP_OPTION_INCLUDE_POSTID', 'include-postid' );
define( 'GTM4WP_OPTION_INCLUDE_POSTFORMAT', 'include-postformat' );
define( 'GTM4WP_OPTION_INCLUDE_POSTTERMLIST', 'include-postterms' );
define( 'GTM4WP_OPTION_INCLUDE_SEARCHDATA', 'include-searchdata' );
define( 'GTM4WP_OPTION_INCLUDE_BROWSERDATA', 'include-browserdata' );
define( 'GTM4WP_OPTION_INCLUDE_OSDATA', 'include-osdata' );
define( 'GTM4WP_OPTION_INCLUDE_DEVICEDATA', 'include-devicedata' );
define( 'GTM4WP_OPTION_INCLUDE_MISCGEO', 'include-miscgeo' );
define( 'GTM4WP_OPTION_INCLUDE_MISCGEOAPI', 'geo-apikey' );
define( 'GTM4WP_OPTION_INCLUDE_MISCGEOCF', 'include-miscgeo-cloudflare' );
define( 'GTM4WP_OPTION_INCLUDE_WEATHER', 'include-weather' );
define( 'GTM4WP_OPTION_INCLUDE_WEATHERUNITS', 'weather-weatherunits' );
define( 'GTM4WP_OPTION_INCLUDE_WEATHEROWMAPI', 'weather-openweathermap-apikey' );
define( 'GTM4WP_OPTION_INCLUDE_SITEID', 'include-siteid' );
define( 'GTM4WP_OPTION_INCLUDE_SITENAME', 'include-sitename' );

define( 'GTM4WP_OPTION_EVENTS_FORMMOVE', 'event-form-move' );
define( 'GTM4WP_OPTION_EVENTS_NEWUSERREG', 'event-new-user-registration' );
define( 'GTM4WP_OPTION_EVENTS_USERLOGIN', 'event-user-logged-in' );

define( 'GTM4WP_OPTION_EVENTS_YOUTUBE', 'event-youtube' );
define( 'GTM4WP_OPTION_EVENTS_VIMEO', 'event-vimeo' );
define( 'GTM4WP_OPTION_EVENTS_SOUNDCLOUD', 'event-soundcloud' );

define( 'GTM4WP_OPTION_SCROLLER_ENABLED', 'scroller-enabled' );
define( 'GTM4WP_OPTION_SCROLLER_DEBUGMODE', 'scroller-debug-mode' );
define( 'GTM4WP_OPTION_SCROLLER_CALLBACKTIME', 'scroller-callback-time' );
define( 'GTM4WP_OPTION_SCROLLER_DISTANCE', 'scroller-distance' );
define( 'GTM4WP_OPTION_SCROLLER_CONTENTID', 'scroller-contentid' );
define( 'GTM4WP_OPTION_SCROLLER_READERTIME', 'scroller-readertime' );

define( 'GTM4WP_OPTION_BLACKLIST_ENABLE', 'blacklist-enable' );
define( 'GTM4WP_OPTION_BLACKLIST_SANDBOXED', 'blacklist-sandboxed' );
define( 'GTM4WP_OPTION_BLACKLIST_STATUS', 'blacklist-status' );

define( 'GTM4WP_OPTION_INTEGRATE_WPCF7', 'integrate-wpcf7' );

define( 'GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE', 'integrate-woocommerce-track-enhanced-ecommerce' );
define( 'GTM4WP_OPTION_INTEGRATE_WCPRODPERIMPRESSION', 'integrate-woocommerce-product-per-impression' );
define( 'GTM4WP_OPTION_INTEGRATE_WCEINCLUDECARTINDL', 'integrate-woocommerce-cart-content-in-datalayer' );
define( 'GTM4WP_OPTION_INTEGRATE_WCEECBRANDTAXONOMY', 'integrate-woocommerce-brand-taxonomy' );
define( 'GTM4WP_OPTION_INTEGRATE_WCBUSINESSVERTICAL', 'integrate-woocommerce-business-vertical' );
define( 'GTM4WP_OPTION_INTEGRATE_WCUSESKU', 'integrate-woocommerce-remarketing-usesku' );
define( 'GTM4WP_OPTION_INTEGRATE_WCVIEWITEMONPARENT', 'integrate-woocommerce-view-item-on-parent-product' );
define( 'GTM4WP_OPTION_INTEGRATE_WCUSEFULLCATEGORYPATH', 'integrate-woocommerce-use-full-category-path' );
define( 'GTM4WP_OPTION_INTEGRATE_WCREMPRODIDPREFIX', 'integrate-woocommerce-remarketing-productidprefix' );
define( 'GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA', 'integrate-woocommerce-customer-data' );
define( 'GTM4WP_OPTION_INTEGRATE_WCORDERDATA', 'integrate-woocommerce-order-data' );
define( 'GTM4WP_OPTION_INTEGRATE_WCORDERMAXAGE', 'integrate-woocommerce-order-max-age' );
define( 'GTM4WP_OPTION_INTEGRATE_WCEXCLUDETAX', 'integrate-woocommerce-exclude-tax' );
define( 'GTM4WP_OPTION_INTEGRATE_WCEXCLUDESHIPPING', 'integrate-woocommerce-exclude-shipping' );
define( 'GTM4WP_OPTION_INTEGRATE_WCNOORDERTRACKEDFLAG', 'integrate-woocommerce-do-not-use-order-tracked-flag' );
define( 'GTM4WP_OPTION_INTEGRATE_WCCLEARECOMMERCEDL', 'integrate-woocommerce-clear-ecommerce-datalayer' );
define( 'GTM4WP_OPTION_INTEGRATE_WCDLMAXTIMEOUT', 'integrate-woocommerce-datalayer-max-timeout' );

define( 'GTM4WP_OPTION_INTEGRATE_WPECOMMERCE', 'integrate-wp-e-commerce' );

define( 'GTM4WP_OPTION_INTEGRATE_AMPID', 'integrate-amp-id' );

define( 'GTM4WP_OPTION_INTEGRATE_COOKIEBOT', 'integrate-cookiebot' );

define( 'GTM4WP_OPTION_INTEGRATE_WEBTOFFEE_GDPR', 'integrate-webtoffee-gdpr' );

define( 'GTM4WP_OPTION_INTEGRATE_CONSENTMODE', 'integrate-consent-mode' );
define( 'GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ADS', 'integrate-consent-mode-ads' );
define( 'GTM4WP_OPTION_INTEGRATE_CONSENTMODE_AD_USER_DATA', 'integrate-consent-mode-ad-user-data' );
define( 'GTM4WP_OPTION_INTEGRATE_CONSENTMODE_AD_PERSO', 'integrate-consent-mode-ad-perso' );
define( 'GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ANALYTICS', 'integrate-consent-mode-analytics' );
define( 'GTM4WP_OPTION_INTEGRATE_CONSENTMODE_PERSO', 'integrate-consent-mode-perso' );
define( 'GTM4WP_OPTION_INTEGRATE_CONSENTMODE_FUNC', 'integrate-consent-mode-func' );
define( 'GTM4WP_OPTION_INTEGRATE_CONSENTMODE_SECURUTY', 'integrate-consent-mode-security' );

define( 'GTM4WP_PLACEMENT_FOOTER', 0 );
define( 'GTM4WP_PLACEMENT_BODYOPEN', 1 );
define( 'GTM4WP_PLACEMENT_BODYOPEN_AUTO', 2 );
define( 'GTM4WP_PLACEMENT_OFF', 3 );

global $gtm4wp_options, $gtm4wp_defaultoptions, $gtm4wp_entity_ids, $gtm4wp_business_verticals, $gtm4wp_business_verticals_ids;

$gtm4wp_options = array();

$gtm4wp_defaultoptions = array(
	GTM4WP_OPTION_GTM_CODE                           => '',
	GTM4WP_OPTION_DATALAYER_NAME                     => '',
	GTM4WP_OPTION_GTM_PLACEMENT                      => GTM4WP_PLACEMENT_FOOTER,
	GTM4WP_OPTION_ENV_GTM_AUTH                       => '',
	GTM4WP_OPTION_ENV_GTM_PREVIEW                    => '',
	GTM4WP_OPTION_LOADEARLY                          => false,
	GTM4WP_OPTION_GTMDOMAIN                          => '',
	GTM4WP_OPTION_GTMCUSTOMPATH                      => '',
	GTM4WP_OPTION_NOGTMFORLOGGEDIN                   => '',
	GTM4WP_OPTION_NOCONSOLELOG                       => false,

	GTM4WP_OPTION_INCLUDE_LOGGEDIN                   => false,
	GTM4WP_OPTION_INCLUDE_USERROLE                   => false,
	GTM4WP_OPTION_INCLUDE_USERID                     => false,
	GTM4WP_OPTION_INCLUDE_USEREMAIL                  => false,
	GTM4WP_OPTION_INCLUDE_USERREGDATE                => false,
	GTM4WP_OPTION_INCLUDE_USERNAME                   => false,
	GTM4WP_OPTION_INCLUDE_VISITOR_IP                 => false,
	GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER          => '',
	GTM4WP_OPTION_INCLUDE_POSTTYPE                   => true,
	GTM4WP_OPTION_INCLUDE_CATEGORIES                 => true,
	GTM4WP_OPTION_INCLUDE_TAGS                       => true,
	GTM4WP_OPTION_INCLUDE_AUTHOR                     => true,
	GTM4WP_OPTION_INCLUDE_AUTHORID                   => false,
	GTM4WP_OPTION_INCLUDE_POSTDATE                   => false,
	GTM4WP_OPTION_INCLUDE_POSTTITLE                  => false,
	GTM4WP_OPTION_INCLUDE_POSTCOUNT                  => false,
	GTM4WP_OPTION_INCLUDE_POSTID                     => false,
	GTM4WP_OPTION_INCLUDE_POSTFORMAT                 => false,
	GTM4WP_OPTION_INCLUDE_POSTTERMLIST               => false,
	GTM4WP_OPTION_INCLUDE_SEARCHDATA                 => false,
	GTM4WP_OPTION_INCLUDE_BROWSERDATA                => false,
	GTM4WP_OPTION_INCLUDE_OSDATA                     => false,
	GTM4WP_OPTION_INCLUDE_DEVICEDATA                 => false,
	GTM4WP_OPTION_INCLUDE_MISCGEO                    => false,
	GTM4WP_OPTION_INCLUDE_MISCGEOAPI                 => '',
	GTM4WP_OPTION_INCLUDE_MISCGEOCF                  => false,
	GTM4WP_OPTION_INCLUDE_WEATHER                    => false,
	GTM4WP_OPTION_INCLUDE_WEATHERUNITS               => 0,
	GTM4WP_OPTION_INCLUDE_WEATHEROWMAPI              => '',
	GTM4WP_OPTION_INCLUDE_SITEID                     => false,
	GTM4WP_OPTION_INCLUDE_SITENAME                   => false,

	GTM4WP_OPTION_EVENTS_FORMMOVE                    => false,
	GTM4WP_OPTION_EVENTS_NEWUSERREG                  => false,
	GTM4WP_OPTION_EVENTS_USERLOGIN                   => false,

	GTM4WP_OPTION_EVENTS_YOUTUBE                     => false,
	GTM4WP_OPTION_EVENTS_VIMEO                       => false,
	GTM4WP_OPTION_EVENTS_SOUNDCLOUD                  => false,

	GTM4WP_OPTION_SCROLLER_ENABLED                   => false,
	GTM4WP_OPTION_SCROLLER_DEBUGMODE                 => false,
	GTM4WP_OPTION_SCROLLER_CALLBACKTIME              => 100,
	GTM4WP_OPTION_SCROLLER_DISTANCE                  => 150,
	GTM4WP_OPTION_SCROLLER_CONTENTID                 => 'content',
	GTM4WP_OPTION_SCROLLER_READERTIME                => 60,

	GTM4WP_OPTION_BLACKLIST_ENABLE                   => 0,
	GTM4WP_OPTION_BLACKLIST_SANDBOXED                => false,
	GTM4WP_OPTION_BLACKLIST_STATUS                   => '',

	GTM4WP_OPTION_INTEGRATE_WPCF7                    => false,

	GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE         => false,
	GTM4WP_OPTION_INTEGRATE_WCPRODPERIMPRESSION      => 10,
	GTM4WP_OPTION_INTEGRATE_WCEINCLUDECARTINDL       => false,
	GTM4WP_OPTION_INTEGRATE_WCEECBRANDTAXONOMY       => '',
	GTM4WP_OPTION_INTEGRATE_WCBUSINESSVERTICAL       => 'retail',
	GTM4WP_OPTION_INTEGRATE_WCUSESKU                 => false,
	GTM4WP_OPTION_INTEGRATE_WCVIEWITEMONPARENT       => false,
	GTM4WP_OPTION_INTEGRATE_WCUSEFULLCATEGORYPATH    => false,
	GTM4WP_OPTION_INTEGRATE_WCREMPRODIDPREFIX        => '',
	GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA           => false,
	GTM4WP_OPTION_INTEGRATE_WCORDERDATA              => false,
	GTM4WP_OPTION_INTEGRATE_WCORDERMAXAGE            => 30,
	GTM4WP_OPTION_INTEGRATE_WCEXCLUDETAX             => false,
	GTM4WP_OPTION_INTEGRATE_WCEXCLUDESHIPPING        => false,
	GTM4WP_OPTION_INTEGRATE_WCNOORDERTRACKEDFLAG     => false,
	GTM4WP_OPTION_INTEGRATE_WCCLEARECOMMERCEDL       => false,
	GTM4WP_OPTION_INTEGRATE_WCDLMAXTIMEOUT           => 2000,

	GTM4WP_OPTION_INTEGRATE_WPECOMMERCE              => false,

	GTM4WP_OPTION_INTEGRATE_AMPID                    => '',

	GTM4WP_OPTION_INTEGRATE_COOKIEBOT                => false,

	GTM4WP_OPTION_INTEGRATE_WEBTOFFEE_GDPR           => false,

	GTM4WP_OPTION_INTEGRATE_CONSENTMODE              => false,
	GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ADS          => false,
	GTM4WP_OPTION_INTEGRATE_CONSENTMODE_AD_USER_DATA => false,
	GTM4WP_OPTION_INTEGRATE_CONSENTMODE_AD_PERSO     => false,
	GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ANALYTICS    => false,
	GTM4WP_OPTION_INTEGRATE_CONSENTMODE_PERSO        => false,
	GTM4WP_OPTION_INTEGRATE_CONSENTMODE_FUNC         => false,
	GTM4WP_OPTION_INTEGRATE_CONSENTMODE_SECURUTY     => false,
);

$gtm4wp_business_verticals = array(
	'retail'       => 'Retail',
	'education'    => 'Education',
	'flights'      => 'Flights',
	'hotel_rental' => 'Hotel rental',
	'jobs'         => 'Jobs',
	'local'        => 'Local deals',
	'real_estate'  => 'Real estate',
	'travel'       => 'Travel',
	'custom'       => 'Custom',
);

$gtm4wp_business_verticals_ids = array(
	'flights' => 'destination',
	'travel'  => 'destination',
);

$gtm4wp_entity_ids = array(
	'tags'      => array(
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
		'cts'                 => 'ClickTale Standard Tracking Tag',
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
		'ga'                  => 'Google Analytics Tag (classic, legacy)',
		'ua'                  => 'Google Analytics Tag (universal, latest)',
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
		'mpm'                 => 'Mediaplex - IFRAME MCT Tag',
		'mpr'                 => 'Mediaplex - Standard IMG ROI Tag',
		'messagemate'         => 'Message Mate',
		'mf'                  => 'Mouseflow Tag',
		'ta'                  => 'Neustar Pixel',
		'ndcr'                => 'Nielsen DCR Static Lite Tag',
		'nudge'               => 'Nudge Content Analytics Tag',
		'okt'                 => 'Oktopost Tracking Code',
		'omc'                 => 'Optimise Conversion Tag',
		'messagemate'         => 'OwnerListens Message Mate',
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
	),
	'triggers'  => array(
		'evl' => 'Element Visibility Listener/Trigger',
		'cl'  => 'Click Listener/Trigger',
		'fsl' => 'Form Submit Listener/Trigger',
		'hl'  => 'History Listener/Trigger',
		'jel' => 'JavaScript Error Listener/Trigger',
		'lcl' => 'Link Click Listener/Trigger',
		'sdl' => 'Scroll Depth Listener/Trigger',
		'tl'  => 'Timer Listener/Trigger',
		'ytl' => 'YouTube Video Listener/Trigger',
	),
	'variables' => array(
		'k'    => '1st Party Cookie',
		'c'    => 'Constant',
		'ctv'  => 'Container Version Number',
		'e'    => 'Custom Event',
		'jsm'  => 'Custom JavaScript Variable',
		'v'    => 'Data Layer Variable',
		'dbg'  => 'Debug Mode',
		'd'    => 'DOM Element',
		'vis'  => 'Element Visibility',
		'f'    => 'HTTP Referrer',
		'j'    => 'JavaScript Variable',
		'smm'  => 'Lookup Table',
		'r'    => 'Random Number',
		'remm' => 'RegEx Table',
		'u'    => 'URL',
	),
);

/**
 * Reads plugin options from database backend and merges this with default values.
 * Migrates blacklist/whitelist options from v1.12- to v1.12+
 * Overwrites some options that can be hard coded in wp-config.
 *
 * @return array
 */
function gtm4wp_reload_options() {
	global $gtm4wp_defaultoptions, $gtm4wp_business_verticals;

	$storedoptions = get_option( GTM4WP_OPTIONS, array() );
	if ( ! is_array( $gtm4wp_defaultoptions ) ) {
		$gtm4wp_defaultoptions = array();
	}

	$return_options = array_merge( $gtm4wp_defaultoptions, $storedoptions );

	$return_options[ GTM4WP_OPTION_BLACKLIST_STATUS ] = explode( ',', $return_options[ GTM4WP_OPTION_BLACKLIST_STATUS ] );

	if ( defined( 'GTM4WP_HARDCODED_GTM_ENV_AUTH' ) ) {
		$return_options[ GTM4WP_OPTION_ENV_GTM_AUTH ] = constant( 'GTM4WP_HARDCODED_GTM_ENV_AUTH' );
	}

	if ( defined( 'GTM4WP_HARDCODED_GTM_ENV_PREVIEW' ) ) {
		$return_options[ GTM4WP_OPTION_ENV_GTM_PREVIEW ] = constant( 'GTM4WP_HARDCODED_GTM_ENV_PREVIEW' );
	}

	if ( defined( 'GTM4WP_HARDCODED_GTM_ID' ) ) {
		$hardcoded_gtm_id = constant( 'GTM4WP_HARDCODED_GTM_ID' );

		// validate hard coded GTM ID before overriding stored value.
		$_gtmid_list     = explode( ',', $hardcoded_gtm_id );
		$_gtmid_haserror = false;

		foreach ( $_gtmid_list as $one_gtm_id ) {
			$_gtmid_haserror = $_gtmid_haserror || ! preg_match( '/^GTM-[A-Z0-9]+$/', $one_gtm_id );
		}

		if ( ! $_gtmid_haserror ) {
			$return_options[ GTM4WP_OPTION_GTM_CODE ] = $hardcoded_gtm_id;
		}
	}

	// only load the first container if environment parameters are set.
	if (
		( '' !== $return_options[ GTM4WP_OPTION_ENV_GTM_AUTH ] ) &&
		( '' !== $return_options[ GTM4WP_OPTION_ENV_GTM_PREVIEW ] )
	) {
		$_gtmid_list = explode( ',', $return_options[ GTM4WP_OPTION_GTM_CODE ] );
		if ( count( $_gtmid_list ) > 0 ) {
			$return_options[ GTM4WP_OPTION_GTM_CODE ] = $_gtmid_list[0];
		}
	}

	if ( ! array_key_exists( $return_options[ GTM4WP_OPTION_INTEGRATE_WCBUSINESSVERTICAL ], $gtm4wp_business_verticals ) ) {
		$return_options[ GTM4WP_OPTION_INTEGRATE_WCBUSINESSVERTICAL ] = $gtm4wp_defaultoptions[ GTM4WP_OPTION_INTEGRATE_WCBUSINESSVERTICAL ];
	}

	return $return_options;
}

/**
 * Helper function for debug purposes. Not used in stable versions.
 *
 * @param string $debug_data The data that needs to be saved for debug logging.
 * @return void
 */
// phpcs:disable
function gtp4wp_debug_file( $debug_data ) {
	$fp = fopen( dirname( __FILE__ ) . '/' . gmdate( 'Y-m-d-H-i-s-u' ) . '.txt', 'w' );
	if ( $fp ) {
		fwrite( $fp, $debug_data );
		fclose( $fp );
	}
}
// phpcs:enable

$gtm4wp_options = gtm4wp_reload_options();
