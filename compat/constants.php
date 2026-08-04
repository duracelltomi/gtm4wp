<?php
/**
 * Backward compatible constant declarations.
 *
 * GTM4WP 2.x reads options through the GTM4WP\Options\Options service, however
 * the 1.x option and hook name constants below stay part of the public API:
 * third party plugins and themes use them to read plugin options and to hook
 * into the data layer. All values must stay unchanged forever.
 *
 * Constants of features removed in 2.0 (WP e-Commerce, weather and geo data,
 * scroll tracking) are also kept so that third party code referencing them
 * does not fatal.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

defined( 'ABSPATH' ) || exit;

define( 'GTM4WP_OPTIONS', 'gtm4wp-options' );
define( 'GTM4WP_ADMINSLUG', 'gtm4wp-settings' );

define( 'GTM4WP_OPTION_GTM_CODE', 'gtm-code' );

// New in 2.0: array of per-container rows (ID, environment, domain, path).
// The flat 1.x options below (gtm-code, gtm-env-*, gtm-domain-name,
// gtm-custom-path) are kept as read-only mirrors derived from these rows.
define( 'GTM4WP_OPTION_GTM_CONTAINERS', 'gtm-containers' );

define( 'GTM4WP_OPTION_GTM_PLACEMENT', 'gtm-code-placement' );
define( 'GTM4WP_OPTION_DATALAYER_NAME', 'gtm-datalayer-variable-name' );
define( 'GTM4WP_OPTION_ENV_GTM_AUTH', 'gtm-env-gtm-auth' );
define( 'GTM4WP_OPTION_ENV_GTM_PREVIEW', 'gtm-env-gtm-preview' );
define( 'GTM4WP_OPTION_LOADEARLY', 'gtm-load-gtm-early' );
define( 'GTM4WP_OPTION_GTMDOMAIN', 'gtm-domain-name' );
define( 'GTM4WP_OPTION_GTMCUSTOMPATH', 'gtm-custom-path' );
define( 'GTM4WP_OPTION_NOGTMFORLOGGEDIN', 'gtm-no-gtm-for-logged-in' );
define( 'GTM4WP_OPTION_NOCONSOLELOG', 'gtm-no-console-log' );
define( 'GTM4WP_OPTION_PRODUCTIONONLY', 'gtm-production-only' );

define( 'GTM4WP_OPTION_INCLUDE_LOGGEDIN', 'include-loggedin' );
define( 'GTM4WP_OPTION_INCLUDE_USERROLE', 'include-userrole' );
define( 'GTM4WP_OPTION_INCLUDE_USERID', 'include-userid' );
define( 'GTM4WP_OPTION_INCLUDE_USEREMAIL', 'include-useremail' );
define( 'GTM4WP_OPTION_INCLUDE_USERREGDATE', 'include-userregdate' );
define( 'GTM4WP_OPTION_INCLUDE_USERNAME', 'include-username' );
define( 'GTM4WP_OPTION_INCLUDE_VISITOR_IP', 'include-visitor-ip' );
define( 'GTM4WP_OPTION_INCLUDE_VISITOR_IP_HEADER', 'include-visitor-ip-header' );
define( 'GTM4WP_OPTION_INCLUDE_VISITOR_IP_PROXIES', 'include-visitor-ip-proxies' );
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
/**
 * Split out of GTM4WP_OPTION_INCLUDE_POSTTERMLIST in 2.0: that single option used to
 * emit BOTH the taxonomy terms and every non-underscore-prefixed post meta value, while
 * describing only the former. The meta half now has its own opt-in; Migration seeds it
 * from the legacy option so an upgrading site keeps the data it was already sending.
 */
define( 'GTM4WP_OPTION_INCLUDE_POSTMETA', 'include-postmeta' );
define( 'GTM4WP_OPTION_INCLUDE_SEARCHDATA', 'include-searchdata' );
define( 'GTM4WP_OPTION_INCLUDE_BROWSERDATA', 'include-browserdata' );
define( 'GTM4WP_OPTION_INCLUDE_OSDATA', 'include-osdata' );
define( 'GTM4WP_OPTION_INCLUDE_DEVICEDATA', 'include-devicedata' );
define( 'GTM4WP_OPTION_INCLUDE_SITEID', 'include-siteid' );
define( 'GTM4WP_OPTION_INCLUDE_SITENAME', 'include-sitename' );

// New in 2.0: content and engagement page variables (all default off).
define( 'GTM4WP_OPTION_INCLUDE_CONTENTWORDCOUNT', 'include-content-word-count' );
define( 'GTM4WP_OPTION_INCLUDE_READINGTIME', 'include-reading-time' );
define( 'GTM4WP_OPTION_INCLUDE_MODIFIEDDATE', 'include-modified-date' );
define( 'GTM4WP_OPTION_INCLUDE_CONTENTAGE', 'include-content-age' );
define( 'GTM4WP_OPTION_INCLUDE_COMMENTCOUNT', 'include-comment-count' );
define( 'GTM4WP_OPTION_INCLUDE_PAGETEMPLATE', 'include-page-template' );
define( 'GTM4WP_OPTION_INCLUDE_FEATUREDIMAGE', 'include-featured-image' );
define( 'GTM4WP_OPTION_INCLUDE_PAGEHIERARCHY', 'include-page-hierarchy' );
define( 'GTM4WP_OPTION_INCLUDE_POSTSTICKY', 'include-post-sticky' );
define( 'GTM4WP_OPTION_INCLUDE_PRIMARYCATEGORY', 'include-primary-category' );
define( 'GTM4WP_OPTION_INCLUDE_PAGELANGUAGE', 'include-page-language' );
define( 'GTM4WP_OPTION_INCLUDE_PARENTCATEGORIES', 'include-parent-categories' );

// New in 2.0: cache-safe data layer master switch. When on, visitor/session
// specific values are not rendered into the (cacheable) page HTML; the browser
// pushes what it can itself (referrer, search) as a gtm4wp.visitorData event.
define( 'GTM4WP_OPTION_CACHE_SAFE_DATALAYER', 'cache-safe-datalayer' );

// Removed in 2.0: geo and weather data. Constants kept for third party compatibility.
define( 'GTM4WP_OPTION_INCLUDE_MISCGEO', 'include-miscgeo' );
define( 'GTM4WP_OPTION_INCLUDE_MISCGEOAPI', 'geo-apikey' );
define( 'GTM4WP_OPTION_INCLUDE_MISCGEOCF', 'include-miscgeo-cloudflare' );
define( 'GTM4WP_OPTION_INCLUDE_WEATHER', 'include-weather' );
define( 'GTM4WP_OPTION_INCLUDE_WEATHERUNITS', 'weather-weatherunits' );
define( 'GTM4WP_OPTION_INCLUDE_WEATHEROWMAPI', 'weather-openweathermap-apikey' );

define( 'GTM4WP_OPTION_EVENTS_FORMMOVE', 'event-form-move' );
define( 'GTM4WP_OPTION_EVENTS_NEWUSERREG', 'event-new-user-registration' );
define( 'GTM4WP_OPTION_EVENTS_USERLOGIN', 'event-user-logged-in' );

define( 'GTM4WP_OPTION_EVENTS_YOUTUBE', 'event-youtube' );
define( 'GTM4WP_OPTION_EVENTS_VIMEO', 'event-vimeo' );
define( 'GTM4WP_OPTION_EVENTS_SOUNDCLOUD', 'event-soundcloud' );
define( 'GTM4WP_OPTION_EVENTS_HTML5MEDIA', 'event-html5-media' );
define( 'GTM4WP_OPTION_EVENTS_DAILYMOTION', 'event-dailymotion' );
define( 'GTM4WP_OPTION_EVENTS_MIXCLOUD', 'event-mixcloud' );
define( 'GTM4WP_OPTION_EVENTS_CLOUDFLARESTREAM', 'event-cloudflare-stream' );
define( 'GTM4WP_OPTION_EVENTS_WISTIA', 'event-wistia' );
define( 'GTM4WP_OPTION_EVENTS_JWPLAYER', 'event-jwplayer' );
define( 'GTM4WP_OPTION_EVENTS_VIDEOPRESS', 'event-videopress' );
define( 'GTM4WP_OPTION_EVENTS_SPOTIFY', 'event-spotify' );
define( 'GTM4WP_OPTION_EVENTS_TWITCH', 'event-twitch' );
define( 'GTM4WP_OPTION_EVENTS_MEDIA_DYNAMIC', 'event-media-dynamic' );

// Scroll tracking was removed in 2.0 (GTM has built-in scroll depth
// tracking); the option keys are kept here for third party compatibility and
// their stored values are cleaned up by GTM4WP\Migration.
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
define( 'GTM4WP_OPTION_INTEGRATE_WPCF7_INPUTS', 'integrate-wpcf7-inputs' );
define( 'GTM4WP_OPTION_INTEGRATE_WPCF7_GA4EVENTS', 'integrate-wpcf7-ga4events' );

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
define( 'GTM4WP_OPTION_INTEGRATE_WCTRANSACTIONIDPREFIX', 'integrate-woocommerce-transaction-id-prefix' );
define( 'GTM4WP_OPTION_INTEGRATE_WCPURCHASESTATUSES', 'integrate-woocommerce-purchase-track-statuses' );
define( 'GTM4WP_OPTION_INTEGRATE_WCPURCHASEONANYPAGE', 'integrate-woocommerce-purchase-track-on-any-page' );
define( 'GTM4WP_OPTION_INTEGRATE_WCCUSTOMORDERRECEIVEDPAGE', 'integrate-woocommerce-custom-order-received-page' );
define( 'GTM4WP_OPTION_INTEGRATE_WCLISTATTRIBUTION', 'integrate-woocommerce-persist-list-attribution' );
define( 'GTM4WP_OPTION_INTEGRATE_WC_CHECKOUTWC', 'integrate-woocommerce-checkoutwc' );

// Removed in 2.0: WP e-Commerce integration. Constant kept for third party compatibility.
define( 'GTM4WP_OPTION_INTEGRATE_WPECOMMERCE', 'integrate-wp-e-commerce' );

define( 'GTM4WP_OPTION_INTEGRATE_AMPID', 'integrate-amp-id' );

define( 'GTM4WP_OPTION_INTEGRATE_COOKIEBOT', 'integrate-cookiebot' );

define( 'GTM4WP_OPTION_INTEGRATE_WEBTOFFEE_GDPR', 'integrate-webtoffee-gdpr' );

define( 'GTM4WP_OPTION_INTEGRATE_COOKIEYES', 'integrate-cookieyes' );

define( 'GTM4WP_OPTION_INTEGRATE_AXEPTIO', 'integrate-axeptio' );
define( 'GTM4WP_OPTION_INTEGRATE_AXEPTIO_PROJECTID', 'integrate-axeptio-projectid' );
define( 'GTM4WP_OPTION_INTEGRATE_AXEPTIO_COOKIES_VERSION', 'integrate-axeptio-cookies-version' );
define( 'GTM4WP_OPTION_INTEGRATE_AXEPTIO_CONSENTMODE', 'integrate-axeptio-consent-mode' );

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

/**
 * Constant used by GTM4WP as a WordPress filter to allow itself and 3rd party plugins
 * to add their own data into the data layer.
 */
define( 'GTM4WP_WPFILTER_COMPILE_DATALAYER', 'gtm4wp_compile_datalayer' );

/**
 * Constant used by GTM4WP as a WordPress action to allow itself and 3rd party plugins
 * to add their own code after the first data layer push command.
 */
define( 'GTM4WP_WPACTION_AFTER_DATALAYER', 'gtm4wp_output_after_datalayer' );

/**
 * Constant used by GTM4WP as a WordPress action to allow itself and 3rd party plugins
 * to fire additional data layer events after the main GTM container code.
 */
define( 'GTM4WP_WPACTION_AFTER_CONTAINER_CODE', 'gtm4wp_after_container_code' );

/**
 * Constant used by GTM4WP as a WordPress filter to decide whether the GTM
 * container code (the <head> loader and the <noscript> iframe) is output on
 * the current request. Defaults to true. Returning false from an mu-plugin or
 * wp-config snippet suppresses the container - based on the host name,
 * WP_ENVIRONMENT_TYPE or any custom condition - while the data layer stays
 * active, so a cloned/staging copy of a site does not send hits to the
 * production container without deactivating the plugin.
 */
define( 'GTM4WP_WPFILTER_OUTPUT_CONTAINER', 'gtm4wp_output_container' );

/**
 * Constant that GTM4WP itself and 3rd party plugins can use to add JavaScript
 * variable declarations above the first data layer push command.
 */
define( 'GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY', 'gtm4wp_add_global_vars_array' );

/**
 * Constant used by the cache-safe data layer (issue #398) to collect the
 * "visitor-scoped" fields a module wants delivered outside the cacheable page
 * HTML. Filter callbacks receive an array and append GTM4WP\Modules\VisitorData\VisitorField
 * objects (each carrying a delivery tier). Phase 1 consumes the Tier 1 (client
 * knows it) fields to build the gtm4wp.visitorData client push; Phase 2 will
 * consume Tier 2/3 fields for the session endpoint.
 */
define( 'GTM4WP_WPFILTER_VISITOR_SCOPED_FIELDS', 'gtm4wp_visitor_scoped_fields' );

/**
 * Constant that can be used to add nonce value to script tags for
 * Content Security Policy (CSP) setups.
 */
define( 'GTM4WP_WPFILTER_GET_CSP_NONCE', 'gtm4wp_get_csp_nonce' );

/**
 * Constant that can be used to overwrite the stored default value of a consent mode flag.
 */
define( 'GTM4WP_WPFILTER_OVERWRITE_COMO_FLAG', 'gtm4wp_overwrite_consent_mode_flag' );

/**
 * Constant used by the Axeptio integration to filter the Google Consent Mode v2
 * default state (all denied) handed to the Axeptio SDK, e.g. to grant signals
 * for non-GDPR audiences.
 */
define( 'GTM4WP_WPFILTER_AXEPTIO_CONSENT_MODE_DEFAULT', 'gtm4wp_axeptio_consent_mode_default' );

/**
 * Constants used by the WooCommerce integration as WordPress filters to allow
 * 3rd party plugins to alter e-commerce related data layer content.
 */
define( 'GTM4WP_WPFILTER_EEC_PRODUCT_ARRAY', 'gtm4wp_eec_product_array' );

/**
 * Source-aware successor of GTM4WP_WPFILTER_EEC_PRODUCT_ARRAY (since 2.0).
 *
 * Behaves like the deprecated gtm4wp_eec_product_array filter but receives a
 * third argument: the raw source object the GA4 item was built from - the
 * WooCommerce cart item array on the cart/checkout paths, or the WC_Order_Item
 * on the purchase path (null when there is no per-line source, e.g. a product
 * detail page or product list). This lets extensions read custom cart/order
 * item meta - which never lives on the WC_Product/variation - and attach only
 * the fields they need, instead of bloating every GA4 event with the whole
 * source array. The source object itself is never merged into the item array.
 */
define( 'GTM4WP_WPFILTER_EEC_ITEM_WITH_SOURCE', 'gtm4wp_eec_item_with_source' );
define( 'GTM4WP_WPFILTER_EEC_CART_ITEM', 'gtm4wp_eec_cart_item' );
define( 'GTM4WP_WPFILTER_EEC_ORDER_ITEM', 'gtm4wp_eec_order_item' );
define( 'GTM4WP_WPFILTER_EEC_ORDER_DATA', 'gtm4wp_eec_order_data' );
define( 'GTM4WP_WPFILTER_EEC_ITEM_AFFILIATION', 'gtm4wp_eec_item_affiliation' );
define( 'GTM4WP_WPFILTER_ECC_PURCHASE_DATALAYER', 'gtm4wp_purchase_datalayer' );
define( 'GTM4WP_WPFILTER_EEC_DATALAYER_PAGELOAD', 'gtm4wp_woocommerce_datalayer_on_pageload' );
