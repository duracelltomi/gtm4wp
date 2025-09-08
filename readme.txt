=== GTM4WP - A Google Tag Manager (GTM) plugin for WordPress  ===
Contributors: duracelltomi
Donate link: https://gtm4wp.com/
Tags: google tag manager, tag manager, gtm, google ads, google analytics
Requires at least: 3.4.0
Requires PHP: 7.4
Tested up to: 6.8
Stable tag: 1.22.1
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl.html

Advanced tag management for WordPress with Google Tag Manager

== Description ==

Google Tag Manager (GTM) is Google's free tool for everyone to manage and deploy analytics and marketing tags as well as other code snippets
using an intuitive web UI. To learn more about this tool, visit the [official website](https://marketingplatform.google.com/about/tag-manager/).

This plugin places the GTM container code snippets onto your WordPress website so that you do not need to add it manually.
Multiple containers are also supported!

The plugin complements your GTM setup by pushing page meta data and user information into the so called data layer.
Google's official help pages includes [more details about the data layer](https://developers.google.com/tag-platform/tag-manager/datalayer#datalayer).

**PHP 7.4 is required to use this plugin.**

= GTM container code placement =

The original GTM container code is divided into two parts:

The first part is a javascript code snippet that is added to the `<head>` section of every page of the website.
This part is critical to enable all features of GTM, and this plugin helps to place this part
correctly on your site.

The second part is an iframe snippet that acts as a failsafe/fallback should users' JavaScript be disabled.
Google recommends – for best performance – to place this code snippet directly after the opening `<body>` tag on each page.

Albeit not ideal, it will work when placed lower in the code. This plugin provides a code placement option for the second code snippet.

If your WordPress theme is compatible with the additions of WordPress 5.2 then this plugin will place this second code to the right place.
Users of the Genisis theme, GeneratePress theme, Elementor, Oxygen Builder and Beaver Builder Theme will also have this placed correctly.
To utilize this, set the compatibility mode in plugin options to off.

All other users can place this second code snippet using a custom PHP code ("Manually coded" option) or select the so called "Footer" option to
add the code lower in the code (it is not the recommended way but will work)

= Basic data included =

* post/page titles
* post/page dates
* post/page category names
* post/page tag names
* post/page author ID and name
* post/page ID
* post types
* post format
* post count on the current page + in the current category/tag/taxonomy
* custom terms associated with any post type
* logged in status
* logged in user role
* logged in user ID (to track cross device behaviour in Google Analytics)
* logged in user email address (both unhashed and SHA256 hased values to be used with tracking)
* logger in user creation date
* site search data
* site name and id (for WordPress multisite instances)
* IP address of the visitor (please use the explicit consent of the visitor to utilize this)

= Browser / OS / Device data =

* browser data (name, version, engine)
* OS data (name, version)
* device data (type, manufacturer, model)

Data is provided using the WhichBrowser library: http://whichbrowser.net/

= Weather data =

(beta)

Push data about users' current weather conditions into the dataLayer. This can be used to generate weather-related
audience/remarketing lists on ad platforms and allows for user segmentation in your web analytics solutions:

* weather category (clouds, rain, snow, etc.)
* weather description: more detailed data
* temperature in Celsius or Fahrenheit
* air pressure
* wind speed and degrees

Weather data is queried from Open Weather Map. Depending on your websites traffic, additional fees may apply:
http://openweathermap.org/price

An (free) API key from OpenWeatherMap is required for this feature to work.

ipstack.com is used to determine the site visitor's location. A (free) API key from IPStack.com is required for this feature to work:
https://ipstack.com/product

= Media player events =

(experimental)

Track users' interaction with any embedded media:

* YouTube
* Vimeo
* Soundcloud

DataLayer events can be chosen to fire upon media player load, media is being played, paused/stopped and optionally when
the user reaches 10, 20, 30, ..., 90, 100% of the media duration.

Tracking is supported for embedded media using the built-in oEmbed feature of WordPress as well as most other media plugins
and copy/pasted codes. Players injected into the website after page load are not currently supported.

= Scroll tracking =

Fire tags based on how the visitor scrolls from the top to the bottom of a page.
An example would be to separate "readers" (who spend a specified amount of time on a page) from "scrollers"
(who only scroll through within seconds). You can use these events to fire Analytics tags and/or remarketing/conversion tags
(for micro conversions).

Scroll tracking is based on the solution originally created by

* Nick Mihailovski
* Thomas Baekdal
* Avinash Kaushik
* Joost de Valk
* Eivind Savio
* Justin Cutroni

Original script:
http://cutroni.com/blog/2012/02/21/advanced-content-tracking-with-google-analytics-part-1/

= Blacklist & Whitelist Tag Manager tags, triggers and variables =

To increase website security, you have the option to white- and blacklist tags/triggers/variables.
You can prevent specific tags from firing or the use of certain variable types regardless of your GTM setup.

If the Google account associated with your GTM account is being hacked, an attacker could easily
execute malware on your website without accessing its code on your hosting server. By blacklisting custom HTML tags
and/or custom JavaScript variables you can secure the Tag Manager container.

= Integration =

Google Tag Manager for WordPress integrates with several popular plugins. More integration to come!

* Contact Form 7: fire an event when a Contact Form 7 form was submitted with any result (mail sent, mail failed, spam detected, invalid input)
* WooCommerce:
	*	Implementation of [GA4 E-commerce](https://developers.google.com/tag-manager/ecommerce-ga4)
	* Does not support promotions since WooCommerce does not have such a feature (yet)
	* Does not support refunds
  * Compatibility with High Performance Order Storage (HPOS)
* AMP: load your AMP container on the AMP version of your pages
* Cookiebot: use automatic cookie blocking mode if needed
* Google Consent Mode v2: fire the "default" command with specific consent flags to integrat with non-certified Consent Management Platforms (CMPs) and plugins.

= Server side containers =

If you are using a [server side container](https://developers.google.com/tag-manager/serverside/send-data#update_the_gtmjs_source_domain)
you can enter your custom domain name and custom path to load gtm.js from your there.

= Exclude specific user roles from being tracked =

You can set which user roles needs to be excluded from tracking when a user with that role visits the frontend. This will completely disable the container code for that user.

== Installation ==

1. Upload `duracelltomi-google-tag-manager-for-wordpress` to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Go to Settings / Google Tag Manager and enter your Google Tag Manager container ID and set additional options

== Frequently Asked Questions ==

= How can I ... =

Tutorials for various Google Tag Manager settings and implementation are available on the plugin's website:
https://gtm4wp.com/setup-gtm4wp-features

= PayPal / 3rd party payment gateway transactions in WooCommerce are not being tracked in Google Analytics =

PayPal and some other 3rd party payment gateways do not redirect users back to your website upon successful transaction by default.
It offers the route back for your customer but it can happen that users close the browser before arriving at your thankyou page
(aka. order received page). This means that neither Google Analytics tags or any other tags have the chance to fire.

Enable auto-return in your payment gateway settings. This will instruct them to show a quick info page after payment
and redirect the user back to your site. This will improve the accuracy and frequency of tracked transactions.

= Purchase event is not tracked with WooCommerce =

If you are using a 3rd party plugin that alters the default order received page in a way that does not utilize the integration hooks
of WooCommerce then this can happen. Either stop using this 3rd party plugin or ask them to better mimic the behavior of the
default order received page by supporting the woocommerce is_order_received_page and the woocommerce_thankyou actions and filters.

= Why isn't there an option to blacklist tag/variable classes =

Although Google recommends to blacklist tags and variables using classes, people struggle to know
which tags/variables gets affected. Therefore I opted for individual tags and variables rather than classes
on the blacklist tabs.

Regarding variables; ensure they are not part of any critical tags as blacklisting such variables will render said tags useless.

= How can I track scroll events in Google Tag Manager? =

Google Tag Manager supports basic scroll depth tracking based on percentage or pixels natively. This plugin adds
additional scroll tracking events, more focused on capturing the users' intent and/or engagement.

There are five dataLayer events you can use in your rule definitions:

* gtm4wp.reading.articleLoaded: the content has been loaded
* gtm4wp.reading.startReading: the visitor started to scroll. The `timeToScroll` dataLayer variable stores duration since the article loaded (in seconds)
* gtm4wp.reading.contentBottom: the visitor reached the end of the content (not the page!). `timeToScroll` dataLayer variable updated
* gtm4wp.reading.pagebottom: the visitor reached the end of the page. `timeToScroll` dataLayer variable updated
* gtm4wp.reading.readerType: based on time spent since article loaded we determine whether the user is a 'scanner' or 'reader' and store this in the `readerType` dataLayer variable

Example use cases: using these events as triggers, you can fire Google Universal Analytics and/or Google Ads remarketing/conversion tags
to report micro conversions and/or to serve ads only to visitors who spend more time reading your content.

== Screenshots ==

1. Admin panel
2. Basic settings
3. Events
4. Integration panel
5. Advanced settings
6. Scroll tracking

== Changelog ==

= 1.22.1 =

* Fixed: yet another fix to the purchase tracking. Thanks [Khnaz35](https://github.com/Khnaz35)

= 1.22 =

* Added: new WooCommerce option to change the event timeout of the select_item event. Could help some UX issues when a user has an ad blocker installed.
* Added: new WordPress filter GTM4WP_WPFILTER_OVERWRITE_COMO_FLAG / gtm4wp_overwrite_consent_mode_flag to overwrite Consent Mode flags in the default command. Can be used by consent banner plugins to mitigate support issues.
* Fixed: Unwanted content on frontend when container placement set to off and gtm4wp_the_gtm_tag() called manually. Thanks [Michael Bourne](https://wordpress.org/support/users/michaelbourne/)
* Fixed: Add to cart tracking broken with Bricks template and WooCommerce
* Updated: bundled WhichBrowser library updated to the latest version. This is a short term solution to solve PHP erros, on the long term, the plan is to switch to [Matomo Device Detector](https://github.com/matomo-org/device-detector)
* Updated: allow the / character in GTM container path to support same tag gateway
* Updated / Fixed: I reverted back the purchase tracking with WooCommerce to the logic present in v1.20- as the new way caused more headache than it resolved. Sorry for everyone who was affected by this.

= 1.21.1 =

* Fix: undefined product_qty variable prevents add_to_cart in some cases. Thanks [diegoarda](https://github.com/diegoarda)
* Fix: clarified that WebToffee integartion is only needed with the legacy 2.x product line
* Fix: Reflected Cross-Site Scripting (XSS) with site search tracking. Thanks [godzeo](https://github.com/godzeo)!

= 1.21 =

!!! WARNING !!!
Significant change in WooCommerce integration!
Check your template whether it supports must have hook: woocommerce_thankyou

* Added: new WooCommerce option that allows you to fire the view_item event during page load of a variable product using parent product data.
* Added: item_group_id parameter includes the parent ID of a variable product in WooCommerce
* Added: GTM4WP_WPFILTER_GET_CSP_NONCE (PHP constant) / gtm4wp_get_csp_nonce WordPress filter to add nonce to script tags supporting Content Security Policy (CSP) setups
* Updated: purchase event is now fired on the woocommerce_thankyou event. This will move execution time behind consent state checks in most cases making the purchase event working in more cases
* Updated: GTM4WP_WPFILTER_EEC_ORDER_DATA / gtm4wp_eec_order_data WordPress filter now has a second parameter passing the whole WC_Order object to the filter after the $order_data parameter
* Updated: no view_cart or begin_checkout events will be triggered from the backend if there are no products in the cart. This prevents wrong data collection with themes not properly supporting WooCommerce hooks
* Fix: load_plugin_textdomain() related PHP notice on admin page

= 1.20.3 =

* Fix: notice "_load_textdomain_just_in_time was called incorrectly" - WordPress 6.7 compatibility

= 1.20.2 =

* Fix: Wrong ID parameter used tracking product variations (id instead of item_id). Thanks [micmaf](https://github.com/micmaf)
* Fix: do not track add_to_cart in product lists when 'Select options' or 'View products' buttons are clicked
* Fix: JavaScript reference error while updating cart count in WooCommerce. Thanks [Sakuk3](https://github.com/Sakuk3)
* Fix: hash customerBillingEmailHash just like orderData.customer.billing.email_hash (for enhanced conversions, use the value in orderData)
* Added: minimum required WooCommerce version (currently 5.0+) is displayed now on GTM4WP admin

= 1.20.1 =

* Fix: do not load GTM container when OFF and console.log OFF. Thanks [morvy](https://github.com/morvy)
* Fix: reverted a change where item_id in ecommerce data layer was converted to numeric type when the value was a numeric value. It will be kept as a string to preserve compatibility with other integrations.
* Fix: JavaScript error when element ID not set correctly in scroll tracking
* Changed: better normalize and hash user data with WooCommerce + orderData variable for enhanced conventions
* Changed: PHP code optimization by [hans2103](https://github.com/hans2103)
* Added: New filter to be able to modify data in the orderData variable on a WooCommerce order received page. Filter can be accessed either using the GTM4WP_WPFILTER_EEC_ORDER_DATA constant or the gtm4wp_eec_order_data string.

= 1.20 =

THE BIG CLEANUP RELEASE!

Lots of deprecated features removed.
The code of WooCommerce integration was cleaned and restructured, if you have any custom code that relies on GTM4WP internal data structure,
please update the plugin on a test version of your website before updating your live site!

* Removed: deprecated Universal Analytics events for WooCommerce
* Removed: deprecated filter GTM4WP_WPFILTER_AFTER_DATALAYER/gtm4wp_after_datalayer
* Removed: deprecated filter GTM4WP_WPFILTER_ADDGLOBALVARS/gtm4wp_add_global_vars
* Removed: deprecated feature that puts the 'Do not track' option of the browser into the data layer. It is a deprecated browser feature therefore removed from this plugin.
* Removed: deprecated Google Optimize integration
* Removed: deprecated constants GTM4WP_OPTION_BLACKLIST_MACRO_* and GTM4WP_OPTION_BLACKLIST_<adsystemname>
* Removed: deprecated WordPress filter GTM4WP_WPFILTER_GETTHEGTMTAG (deprecated in v1.16)
* Changed: lots of internal function names and variable names changed, although is not recommended to depend on them, if you did, check your code
* Changed: renamed WP filter gtm4wp_datalayer_on_pageload to gtm4wp_woocommerce_datalayer_on_pageload to better reflect when it is called.
* Changed: orderData data layer variable in WooCommerce integration now includes keys to better support enhanced conversions setup:
  * orderData.customer.billing.first_name_hash
  * orderData.customer.billing.last_name_hash
  * orderData.customer.billing.phone_hash
* Deprecated: orderData.customer.billing.emailhash, please update to use orderData.customer.billing.email_hash (with an underscore)
* Added: stockstatus key into the product array of every ecommerce action. Returns the value of WP_Product->get_stock_status(). Thanks [hans2103](https://github.com/hans2103).
* Added: integration with WebToffee GDPR Cookie Consent plugin. GTM4WP can not fire a GTM event when user consent changes or when a previously stored consent has been loaded.
* Fixed: add_payment_info and add_shipping_info events were not fired during checkout submit when not fired before on the page.
* Fixed: GTM4WP will only look for the user's IP address in the REMOTE_ADDR server variable. You may enter a custom HTTP header instead in plugin settings.

= 1.19.1 =

* Fixed: run additional checks when determing product category to prevent PHP errors in certain cases
* Fixed: corrected Consent Mode flag names in admin page description texts

= 1.19 =

WARNING!
Universal Analytics / GA3 ecommerce events **deprecated**!
Please update your GTM setup so that it does not rely on any of the following GTM4WP events and the old ecommerce data layer:
* gtm4wp.productClickEEC
* gtm4wp.addProductToCartEEC
* gtm4wp.removeFromCartEEC
* gtm4wp.checkoutOptionEEC
* gtm4wp.checkoutStepEEC
* gtm4wp.changeDetailViewEEC
* gtm4wp.orderCompletedEEC

Instead of the above events, you can use the new GA4 ecommerce events (add_to_cart, purchase, etc.)
In the Universal Analytics GTM tag now you have an option to use the GA4 ecommerce data in the data layer.
If you are on GA360 and still collecting ecommerce data, you need to update your GTM setup to use that option firing on the GA4 ecommerce events.

* Updated: WooCommerce integration now accepts the primary category selection of Yoast SEO and Rank Math SEO
* Updated: use the gtm4wp_admin_page_capability filter to modify the capability needed to use the admin page (thanks [emreerkan](https://github.com/emreerkan))
* Fixed: price did not include the .00 postfix for integers in the add_to_cart event
* Added: if needed, you can turn on clearing the ecommerce object before a new GA4 event is being pushed.
* Added: support for Google Consent Mode default command for use cases where the consent management tool does not support it already

= 1.18.1 =

* Fixed: PHP notice about Undefined variable: blocking_cookie

= 1.18 =

* Fixed: error while checking the new customer status in WooCommerce on the order received page (thanks [morvy](https://github.com/morvy))
* Fixed: Call to a member function get_meta() on null error on WooCommerce order received page (thanks [Dekadinious](https://github.com/Dekadinious))
* Updated: WordPress and WooCommerce compatibility
* Updated: Deprecated Google Optimize integration as the tool is sunsetting in September 2023
* Added: Create a cookie named block_gtm4wp_geoip after a specific user selected cookie preferences. Set the value to either "yes", "true", "on" or "1" and the GeoIP (and weather API) feature will be disabled for that particular user

== Upgrade Notice ==

= 1.22.1 =

Bugfix release

= 1.22 =

Updated WhichBrowser library, new WooCommerce option, new filter for Consent Mode flags, fixes

= 1.21.1 =

Bugfix release with an XSS fix

= 1.21 =

Important update for WooCommerce users, please check the full changelog!

= 1.20.3 =

Bugfix release

= 1.20.2 =

Bugfix release

= 1.20.1 =

Bugfix release

= 1.20 =

* Lots of deprecated features removed, please read announcement post before upgrading!

= 1.19.1 =

Bugfix release

= 1.19 =

Added features

= 1.18.1 =

Bugfix release

= 1.18 =

Bugfixes and new features