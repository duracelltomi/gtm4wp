<?php
/**
 * GTM4WP options on the Integrate tab.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

$GLOBALS['gtm4wp_integratefieldtexts'] = array(
	GTM4WP_OPTION_INTEGRATE_WPCF7                    => array(
		'label'         => esc_html__( 'Contact Form 7', 'duracelltomi-google-tag-manager' ),
		'description'   => esc_html__( 'Check this to fire dataLayer events after Contact Form 7 submissions (supported events: invalid input, spam detected, form submitted, form submitted and mail sent, form submitted and mail send failed).', 'duracelltomi-google-tag-manager' ),
		'phase'         => GTM4WP_PHASE_STABLE,
		'plugintocheck' => 'contact-form-7/wp-contact-form-7.php',
	),
	GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE         => array(
		'label'         => esc_html__( 'Track e-commerce', 'duracelltomi-google-tag-manager' ),
		'description'   => sprintf(
			gtm4wp_safe_admin_html(
				// translators: 1: anchor element linking to GA4 Ecommerce docs. 2: closing anchor element. 3: anchor element linking to GTM4WP setup guide for Google Analytics 4. 4: closing anchor element.
				__(
					'Choose this option if you would like to track e-commerce data using 
					 %1$secommerce tracking%2$s.<br>
					 Use the plugin\'s %3$sofficial guides%4$s to setup your Google Tag Manager container:<br/>
					 <strong>WooCommerce 5.0+ is required to use this integration</strong>',
					'duracelltomi-google-tag-manager'
				)
			),
			'<a href="https://developers.google.com/analytics/devguides/collection/ga4/ecommerce?client_type=gtm" target="_blank" rel="noopener">',
			'</a>',
			'<a href="https://gtm4wp.com/how-to-articles/how-to-setup-enhanced-ecommerce-tracking-google-analytics-4-ga4-version" target="_blank" rel="noopener">',
			'</a>'
		),
		'phase'         => GTM4WP_PHASE_STABLE,
		'plugintocheck' => 'woocommerce/woocommerce.php',
	),
	GTM4WP_OPTION_INTEGRATE_WCPRODPERIMPRESSION      => array(
		'label'       => esc_html__( 'Products per impression', 'duracelltomi-google-tag-manager' ),
		'description' => gtm4wp_safe_admin_html(
			__(
				'If you have many products shown on product category pages and/or on your site home, you could miss pageviews in Google Analytics due to the
				amount of data that is needed to be sent. To prevent this, you can split product impression data into multiple Google Analytics events by
				entering a number here (minimum 10-15 recommended)<br /><br />Leave this value 0 to include product impression data in your pageview hit.',
				'duracelltomi-google-tag-manager'
			)
		),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INTEGRATE_WCEINCLUDECARTINDL       => array(
		'label'       => esc_html__( 'Cart content in data layer', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Enable this option to include the content of the cart in the data layer on each page. Needs WooCommerce v3.2 or newer. Especially useful for site personalization tools.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INTEGRATE_WCUSEFULLCATEGORYPATH    => array(
		'label'       => esc_html__( 'Include full category path.', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this to inclulde the full category path of each product in enhanced ecommerce tracking. WARNING! This can lead to performance issues on large sites with lots of traffic!', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INTEGRATE_WCEECBRANDTAXONOMY       => array(
		'label'       => esc_html__( 'Taxonomy to be used for product brands', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Select which custom taxonomy is being used to add the brand of products', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA           => array(
		'label'       => esc_html__( 'Customer data in data layer', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Enable this to add all customer data (billing and shipping data, total number of orders and order value) into the data layer (WooCommerce 3.x required)', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INTEGRATE_WCORDERDATA              => array(
		'label'       => esc_html__( 'Order data in data layer', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Enable this to add all order attribute into the data layer on the order received page regardless and independently from classic and enhanced ecommerce tracking (WooCommerce 3.x required)', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INTEGRATE_WCEXCLUDETAX             => array(
		'label'       => esc_html__( 'Exclude tax from revenue', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Enable this to exclude tax from the revenue variable while generating the purchase data', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INTEGRATE_WCORDERMAXAGE            => array(
		'label'       => esc_html__( 'Only track orders younger than', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'To prevent duplicate transaction tracking at the order received page, enter the maximum age (in minutes) of the order or its payment for the transaction to be measured. Viewing the order received page of older orders will be ignored from transaction tracking, as it is considered to be a measured in an earlier session.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_EXPERIMENTAL,
	),
	GTM4WP_OPTION_INTEGRATE_WCEXCLUDESHIPPING        => array(
		'label'       => esc_html__( 'Exclude shipping from revenue', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Enable this to exclude shipping costs from the revenue variable while generating the purchase data', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INTEGRATE_WCBUSINESSVERTICAL       => array(
		'label'       => esc_html__( 'Google Ads Business Vertical', 'duracelltomi-google-tag-manager' ),
		'description' => sprintf(
			gtm4wp_safe_admin_html(
				// translators: 1: anchor element linking to GTM4WP setup guide for Google Ads dynamic remarketing. 2: closing anchor element.
				__(
					'Select which vertical category to add next to each product to utilize dynamic remarketing for Google Ads.
					 <br />Use the plugin\'s %1$sofficial setup guide for dynamic remarketing%2$s
					 to setup your Google Tag Manager container.',
					'duracelltomi-google-tag-manager'
				)
			),
			'<a href="https://gtm4wp.com/how-to-articles/how-to-setup-dynamic-remarketing-in-google-ads-adwords" target="_blank" rel="noopener">',
			'</a>'
		),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INTEGRATE_WCREMPRODIDPREFIX        => array(
		'label'       => esc_html__( 'Product ID prefix', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( "Some product feed generator plugins prefix product IDs with a fixed text like 'woocommerce_gpf'. You can enter this prefix here so that tags in your website include this prefix as well.", 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INTEGRATE_WCUSESKU                 => array(
		'label'       => esc_html__( 'Use SKU instead of ID', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'Check this to use product SKU instead of the ID of the products for remarketing and ecommerce tracking. Will fallback to ID if no SKU is set.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INTEGRATE_WCVIEWITEMONPARENT       => array(
		'label'       => esc_html__( 'Fire view_item on parent product', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__( 'If this option is turned on, the view_item event will be also fired during page load of a variable product using parent product data.', 'duracelltomi-google-tag-manager' ),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INTEGRATE_WCNOORDERTRACKEDFLAG     => array(
		'label'       => esc_html__( 'Do not flag orders as being tracked', 'duracelltomi-google-tag-manager' ),
		'description' => gtm4wp_safe_admin_html(
			__(
				'Turn this on to prevent the plugin to flag orders as being already tracked.<br /><br />
			 	Leaving this unchecked ensures that no order data will be tracked multiple times
				in any ad or measurement system.<br />
				Please only turn this feature on if you really need it!',
				'duracelltomi-google-tag-manager'
			)
		),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INTEGRATE_WCCLEARECOMMERCEDL       => array(
		'label'       => esc_html__( 'Clear ecommerce object before new event', 'duracelltomi-google-tag-manager' ),
		'description' => sprintf(
			gtm4wp_safe_admin_html(
				// translators: 1: anchor element linking to the official GA4 doc about clearing the ecommerce object. 2: closing anchor element.
				__(
					'Clear the ecommerce object before any new event being pushed into the data layer.<br /><br />
					Althought it is %1$srecommended by Google%2$s, it is not mandatory to activate this feature as
					the GA4 event tag reads only the last pushed ecommerce data on any new event.<br />
					Use it if you encounter issues with your GTM implementation.',
					'duracelltomi-google-tag-manager'
				)
			),
			'<a href="https://developers.google.com/analytics/devguides/collection/ga4/ecommerce?client_type=gtm#clear_the_ecommerce_object" target="_blank" rel="noopener">',
			'</a>'
		),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INTEGRATE_WCDLMAXTIMEOUT           => array(
		'label'       => esc_html__( 'Set maximum timeout for select_item event', 'duracelltomi-google-tag-manager' ),
		'description' => sprintf(
			gtm4wp_safe_admin_html(
				// translators: 1: anchor element linking to the official GA4 doc about clearing the ecommerce object. 2: closing anchor element.
				__(
					'When a user clicks on a product in a list, the <code>select_item</code> event uses a callback function with Google Tag Manager (GTM).
					This ensures that GTM can fire all related tags before the browser navigates to the product detail page.
					You can customize a timeout period (in milliseconds). This timeout only comes into play if a tag fails to fire within the specified time
					for some reason. If all tags fire correctly, this timeout won\'t affect anything.',
					'duracelltomi-google-tag-manager'
				)
			),
			'<a href="https://developers.google.com/analytics/devguides/collection/ga4/ecommerce?client_type=gtm#clear_the_ecommerce_object" target="_blank" rel="noopener">',
			'</a>'
		),
		'phase'       => GTM4WP_PHASE_STABLE,
	),

	GTM4WP_OPTION_INTEGRATE_AMPID                    => array(
		'label'         => esc_html__( "Google Tag Manager 'AMP' Container ID", 'duracelltomi-google-tag-manager' ),
		'description'   => sprintf(
			// translators: 1: opening anchor tag for a link pointing to the official GTM help center article about the AMP container snippet 2: Closing anchor tag.
			esc_html__(
				'Enter a comma separated list of Google Tag Manager container IDs that you would like to use on your site.
				This plugin will add the %1$sAMP GTM snippet%2$s to your AMP pages.',
				'duracelltomi-google-tag-manager'
			),
			'<a href="https://support.google.com/tagmanager/answer/6103696?hl=en" target="_blank" rel="noopener">',
			'</a>'
		) .
			'<br /><span class="ampid_validation_error">' .
			esc_html__(
				'This does not seems to be a valid Google Tag Manager Container ID! Valid format: GTM-XXXXXX
				where X can be numbers and capital letters. Use comma without any space (,) to enter multpile IDs.',
				'duracelltomi-google-tag-manager'
			) .
			'</span>',
		'phase'         => GTM4WP_PHASE_EXPERIMENTAL,
		'plugintocheck' => 'amp/amp.php',
	),

	GTM4WP_OPTION_INTEGRATE_COOKIEBOT                => array(
		'label'       => esc_html__( 'Cookiebot auto blocking', 'duracelltomi-google-tag-manager' ),
		'description' => sprintf(
			// translators: 1: opening anchor tag linking to Cookiebot's documentation about the automatic cookie blocking feature. 2: Closing anchor tag.
			esc_html__(
				'Enable this checkbox if you wish to use the %1$sautomatic cookie blocking mode of Cookiebot with Google Tag Manager%2$s.',
				'duracelltomi-google-tag-manager'
			),
			'<a href="https://support.cookiebot.com/hc/en-us/articles/360009192739-Google-Tag-Manager-and-Automatic-cookie-blocking" target="_blank" rel="noopener">',
			'</a>'
		),
		'phase'       => GTM4WP_PHASE_STABLE,
	),

	GTM4WP_OPTION_INTEGRATE_CONSENTMODE              => array(
		'label'       => esc_html__( 'Google Consent Mode', 'duracelltomi-google-tag-manager' ),
		'description' => sprintf(
			gtm4wp_safe_admin_html(
				// translators: 1: opening anchor tag linking to Google's documentation about the consent mode command. 2: Closing anchor tag.
				__(
					'Enable this checkbox if you wish to execute the "default" command of %1$sGoogle Consent Mode%2$s before the container loads.
					The "update" command needs to be executed from your consent management tool.<br /><br />
					DO NOT enable this feature if your consent manager tool supports firing both the "default" and the "update" command.',
					'duracelltomi-google-tag-manager'
				)
			),
			'<a href="https://developers.google.com/tag-platform/gtagjs/reference#consent" target="_blank" rel="noopener">',
			'</a>'
		),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ANALYTICS    => array(
		'label'       => esc_html__( 'Analytics Storage', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__(
			'Select this checkbox to make the analytics_storage flag "granted" by default.',
			'duracelltomi-google-tag-manager'
		),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INTEGRATE_CONSENTMODE_ADS          => array(
		'label'       => esc_html__( 'Ad Storage', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__(
			'Select this checkbox to make the ad_storage flag "granted" by default.',
			'duracelltomi-google-tag-manager'
		),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INTEGRATE_CONSENTMODE_AD_USER_DATA => array(
		'label'       => esc_html__( 'Ad User Data', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__(
			'Select this checkbox to make the ad_user_data flag "granted" by default.',
			'duracelltomi-google-tag-manager'
		),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INTEGRATE_CONSENTMODE_AD_PERSO     => array(
		'label'       => esc_html__( 'Ad Personalization', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__(
			'Select this checkbox to make the ad_personalization flag "granted" by default.',
			'duracelltomi-google-tag-manager'
		),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INTEGRATE_CONSENTMODE_FUNC         => array(
		'label'       => esc_html__( 'Functionality Storage', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__(
			'Select this checkbox to make the functionality_storage flag "granted" by default.',
			'duracelltomi-google-tag-manager'
		),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INTEGRATE_CONSENTMODE_SECURUTY     => array(
		'label'       => esc_html__( 'Security Storage', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__(
			'Select this checkbox to make the security_storage flag "granted" by default.',
			'duracelltomi-google-tag-manager'
		),
		'phase'       => GTM4WP_PHASE_STABLE,
	),
	GTM4WP_OPTION_INTEGRATE_CONSENTMODE_PERSO        => array(
		'label'       => esc_html__( 'Personalization Storage', 'duracelltomi-google-tag-manager' ),
		'description' => esc_html__(
			'Select this checkbox to make the personalization_storage flag "granted" by default.',
			'duracelltomi-google-tag-manager'
		),
		'phase'       => GTM4WP_PHASE_STABLE,
	),

	GTM4WP_OPTION_INTEGRATE_WEBTOFFEE_GDPR           => array(
		'label'         => esc_html__( 'WebToffee GDPR Cookie Consent (v2.x)', 'duracelltomi-google-tag-manager' ),
		'description'   =>
			esc_html__(
				'Enabling this feature will fire a GTM event (cookie_consent_update) when the consent banner has been closed
				with consents being set or during pageload when previously set consents have been found.
				You do not need to use this integration with v3.x or above since it includes all the necessary codes to integrate
				the consent banner with Google Tag Manager. Just use their wcc_gcm_consent_update event to fire
				tags that do not support advanced consent mode (usually non-Google tags) or if you would like to implement
				basic consent mode.',
				'duracelltomi-google-tag-manager'
			),
		'phase'         => GTM4WP_PHASE_STABLE,
		'plugintocheck' => 'webtoffee-gdpr-cookie-consent/cookie-law-info.php',
	),

);
