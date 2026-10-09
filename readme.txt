=== GTM4WP - A Google Tag Manager (GTM) plugin for WordPress ===
Contributors: duracelltomi
Donate link: https://gtm4wp.com/
Tags: google tag manager, tag manager, gtm, google ads, google analytics
Requires at least: 6.3
Requires PHP: 8.0
Tested up to: 7.1
Stable tag: 2.0.5
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl.html

Advanced tag management for WordPress with Google Tag Manager

== Description ==

Google Tag Manager (GTM) is Google's free tool for everyone to manage and deploy analytics and marketing tags as well as other code snippets
using an intuitive web UI. To learn more about this tool, visit the [official website](https://marketingplatform.google.com/about/tag-manager/).

This plugin places the GTM container code snippets onto your WordPress website so that you do not need to add them manually.
Multiple containers are also supported, and each container can have its own environment parameters, custom domain and custom path.

The plugin complements your GTM setup by pushing page meta data and user information into the so-called data layer.
Google's official help pages include [more details about the data layer](https://developers.google.com/tag-platform/tag-manager/datalayer#datalayer).

**PHP 8.0 and WordPress 6.3 are required to use this plugin.**

= GTM container code placement =

The original GTM container code is divided into two parts:

The first part is a javascript code snippet that is added to the `<head>` section of every page of the website.
This part is critical to enable all features of GTM, and this plugin helps to place this part
correctly on your site.

The second part is an iframe snippet that acts as a failsafe/fallback should users' JavaScript be disabled.
Google recommends – for best performance – to place this code snippet directly after the opening `<body>` tag on each page.

Albeit not ideal, it will work when placed lower in the code. This plugin provides a code placement option for the second code snippet.

If your WordPress theme is compatible with the additions of WordPress 5.2, then this plugin will place this second snippet in the right place.
Users of the Genesis theme, GeneratePress theme, Elementor, Oxygen Builder and Beaver Builder Theme will also have it placed correctly.
To utilize this, set the compatibility mode in the plugin options to off.

All other users can place this second code snippet using custom PHP code (the "Manually coded" option), or select the so-called "Footer" option
to add the code lower on the page (this is not the recommended way, but it will work).

= Basic data included =

* post/page titles
* post/page dates
* post/page category slugs
* post/page tag slugs
* post/page author ID and name
* post/page ID
* post types
* post format
* post count on the current page + in the current category/tag/taxonomy
* custom terms associated with any post type
* logged in status
* logged in user role
* logged in user ID (to track cross device behavior in Google Analytics)
* logged in user email address (both unhashed and SHA256 hashed values to be used with tracking)
* logged in user creation date
* site search data
* site name and id (for WordPress multisite instances)
* IP address of the visitor (please obtain the visitor's explicit consent before using this)
* post author data from PublishPress Authors, including co-authors and guest authors

= Content & engagement data =

Optional page variables that help with behavior tracking and Google Analytics 4 content grouping:

* content word count and estimated reading time
* last modified date and content age in days
* comment count and comment status
* page template, featured image presence, page hierarchy and sticky flag
* primary category, detected from Yoast SEO or Rank Math
* page language, detected from WPML or Polylang
* optionally, titles, categories and terms in the default language of a WPML or Polylang site, so reports combine across translations

= Browser / OS / Device data =

* browser data (name, version, engine)
* OS data (name, version)
* device data (type, manufacturer, model)

Data is collected in the browser using User-Agent Client Hints and pushed as a gtm4wp.deviceData event.
Note that Safari and Firefox expose less detail than Chromium based browsers.

= Media player events =

Track users' interactions with any embedded media:

* YouTube
* Vimeo
* Soundcloud
* HTML5 audio and video
* Dailymotion
* Mixcloud
* Cloudflare Stream
* Wistia
* JW Player
* VideoPress
* Spotify
* Twitch

You can choose to fire data layer events when the media player loads, when the media is played, when it is paused or stopped,
and optionally when the user reaches 10, 20, 30, ..., 90, 100% of the media duration. Each event also populates Google Tag
Manager's built-in Video variables (Video Status, Video URL, Video Title, Video Provider, Video Duration, Video Current Time,
Video Percent, Video Visible).

Tracking is supported for embedded media using the built-in oEmbed feature of WordPress, as well as most other media plugins
and copy/pasted embed codes. Players inserted into the page after it has loaded (for example in a popup, a lightbox or via
AJAX) can also be tracked by turning on the optional "Track dynamically inserted players" setting.

= Tag restrictions: allowlist & blocklist Tag Manager tags, triggers and variables =

To increase website security, you have the option to allowlist or blocklist tags, triggers and variables.
You can prevent specific tags from firing, or prevent the use of certain variable types, regardless of your GTM setup.

If the Google account associated with your GTM account is compromised, an attacker could easily
execute malware on your website without accessing its code on your hosting server. By blocklisting custom HTML tags,
custom JavaScript variables and sandboxed scripts (custom tag and variable templates), you can secure the Tag Manager container.

= Integration =

Google Tag Manager for WordPress integrates with several popular plugins. More integrations to come!

* Contact Form 7: fire an event when a form is submitted with any result (mail sent, mail failed, spam detected, invalid input, submission aborted or terms not accepted). Optionally push the Google Analytics 4 recommended form events (form_start, form_submit, generate_lead) as well
* WooCommerce:
	* Implementation of [GA4 E-commerce](https://developers.google.com/tag-manager/ecommerce-ga4)
	* Support for the Cart, Checkout, Mini-Cart, Product Collection and cross-sell blocks, not only the classic shortcode based pages
	* Enhanced Conversions user data on the purchase event for Google Ads
	* Compatibility with High Performance Order Storage (HPOS)
	* Refunds reported from the server through the Google Data Manager API (see below)
	* Does not support promotions, since WooCommerce does not have such a feature (yet)
* Easy Digital Downloads (EDD 3.0+, beta): the same GA4 e-commerce events from view_item to purchase for the classic shortcodes and the EDD blocks, with Enhanced Conversions user data and reliable purchase tracking
* CheckoutWC: optional support for its multi step checkout template
* PublishPress Authors: co-author and guest author data in the page variables
* WPML and Polylang: page language detection, plus optional default-language values for page variables, WooCommerce and EDD items and Contact Form 7 form names
* AMP: load your AMP container on the AMP version of your pages
* Google Consent Mode v2: fire the "default" command with specific consent flags to integrate with non-certified Consent Management Platforms (CMPs) and plugins
* Cookiebot: use automatic cookie blocking mode if needed
* Axeptio: load the Axeptio SDK and push every consent change into the data layer
* CookieYes: push a data layer event whenever the visitor's consent changes

= Server side containers =

If you are using a [server side container](https://developers.google.com/tag-manager/serverside/send-data#update_the_gtmjs_source_domain),
you can enter your custom domain name and custom path to load gtm.js from there. Both can be set per container, so you can mix
server side and standard containers on the same site.

= Cache-safe data layer =

(experimental, off by default)

On sites using a full page cache (LiteSpeed, WP Rocket, Varnish, Cloudflare APO), the HTML generated for one visitor is served
to everyone else as well. Any visitor specific value written into the data layer would therefore leak to other visitors - the
classic example being a page cached while an editor was logged in, then served to anonymous visitors with that editor's email
address and role still in the data layer.

When this option is enabled, no visitor or session data is written into cacheable HTML at all. Those values are delivered in the
browser instead, under the same data layer variable names, so your existing Google Tag Manager setup keeps working.

= Export & import your settings =

You can export all plugin settings into a JSON file and import them on another site, which makes it easy to roll out the same
configuration across several websites. Imported files are treated as untrusted and every value is validated before it is stored.

= Server-side events with the Google Data Manager API =

(experimental, off by default)

Some signals never reach a browser tag. A refund is issued in the store admin, where no page is loaded and no tag fires, so
Google Analytics keeps counting revenue you have given back. The plugin can send such events from the server through the
Google Data Manager API:

* Google service accounts: upload the JSON key of a Google Cloud service account, several side by side. Keys are stored encrypted and never shown again.
* Data Manager destinations: one row per GA4 property, each with a Test button that validates the setup before anything is sent.
* Attribution capture: each new WooCommerce or EDD order stores the Analytics client and session IDs, the Google Ads click IDs and the consent state it was placed with, so a later server-side event is matched to the right session.
* Refund events: a refund issued in WooCommerce or EDD is sent to every destination as a GA4 refund event with amount, items, shipping and tax. Google Analytics itself currently processes only the first of several refunds on one order; this is reported to Google.
* Consent requirement: choose whether orders from the EEA, the UK and Switzerland, every order, or no order need analytics consent before anything is sent.
* Recent sends: what was sent, what Google did with it, and the reason for every deliberate skip. Failed refunds can be queued again.

= Site Health =

Tools → Site Health reports the whole plugin: every module's option states, the containers and their placement, the data
layer name and any wp-config.php overrides in the Info section, plus status tests for the configuration, the Google service
account keys and Data Manager sending. The copied text carries no keys, addresses or visitor data, so it can be pasted into a
support thread as it is.

= AI assistants (WordPress Abilities API) =

(experimental)

On WordPress 6.9 and newer the plugin registers eleven abilities with the WordPress Abilities API, so an AI assistant
connected through the WordPress MCP Adapter or another client can read the configuration, help work out why an event is
not firing, and change a setting once you have confirmed it. Six abilities are read-only, every one requires the settings
capability, and none returns keys or visitor data. Two filters switch the surface off or keep it read-only.

= Exclude specific user roles from being tracked =

You can set which user roles need to be excluded from tracking when a user with that role visits the frontend. This will completely disable the container code for that user.

= Staging and development sites =

The container can be limited to production environments only, so a cloned or staging copy of your site does not send data into
your production Google Tag Manager container. This relies on the WP_ENVIRONMENT_TYPE setting of WordPress.

= For developers =

Version 2.0 is a complete object oriented rewrite. Every feature is a module, and third party plugins can register their own
modules through the gtm4wp_register_modules action. All public template functions, filter and action names, wp-config constants
and the option storage key of the 1.x versions are unchanged, so existing integrations keep working. A third party module can
report into Site Health and register its own abilities through the same interfaces the built-in modules use.

== Installation ==

1. Upload `duracelltomi-google-tag-manager-for-wordpress` to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Go to Settings / Google Tag Manager and enter your Google Tag Manager container ID and set additional options

== Frequently Asked Questions ==

= How can I ... =

Tutorials for various Google Tag Manager settings and implementation are available on the plugin's website:
https://gtm4wp.com/setup-gtm4wp-features

= PayPal / 3rd party payment gateway transactions in WooCommerce are not being tracked in Google Analytics =

PayPal and some other 3rd party payment gateways do not redirect users back to your website after a successful transaction by default.
They offer a route back for your customer, but it can happen that users close the browser before arriving at your thank you page
(also known as the order received page). This means that neither Google Analytics tags nor any other tags have a chance to fire.

Enable auto-return in your payment gateway settings. This will instruct the gateway to show a quick info page after payment
and redirect the user back to your site. This will improve the accuracy and frequency of tracked transactions.

= Purchase event is not tracked with WooCommerce =

This can happen if you are using a 3rd party plugin that alters the default order received page in a way that does not use
WooCommerce's integration hooks. Either stop using that plugin, or ask its authors to mimic the behavior of the default order
received page more closely by supporting the woocommerce is_order_received_page function and the woocommerce_thankyou action.

Since version 2.0 the plugin also offers two settings that work around this without changing the 3rd party plugin: "Custom order
received (thank-you) page" fires the purchase event on a bespoke confirmation page, and "Reliable purchase tracking" emits a
missed purchase event on the next page the customer views in the same browser session. Both are de-duplicated, so an order is
never counted twice.

= Can the plugin report refunds to Google Analytics? =

Yes, since 2.1, from the server: a refund is issued in the store admin where no tag can fire, so the plugin sends a GA4 refund
event through the Google Data Manager API instead. It needs a Google Cloud service account with access to your GA4 property and
refund sending switched on before the order was placed. Note that Google Analytics itself currently processes only the
first of several refunds on one order; this has been reported to Google and needs no plugin change once fixed.

= Does the plugin support Easy Digital Downloads? =

Yes, since 2.1: Easy Digital Downloads 3.0 and newer gets the same GA4 e-commerce events as WooCommerce, for the classic
shortcodes and the EDD blocks alike, with its own settings section.

= Why isn't there an option to blocklist tag/variable classes =

Although Google recommends blocklisting tags and variables using classes, people struggle to know
which tags and variables get affected. Therefore I opted for individual tags and variables rather than classes
on the tag restriction tabs.

Regarding variables: ensure they are not part of any critical tags, as blocklisting such variables will render those tags useless.

= How can I track scroll events in Google Tag Manager? =

Google Tag Manager supports basic scroll depth tracking based on percentage or pixels natively. Add a Scroll Depth
trigger in your container and use it to fire your Google Analytics 4 and/or Google Ads remarketing/conversion tags.
(The plugin's own scroll tracking feature was removed in 2.0 in favor of this built-in GTM functionality.)

= I use a page cache. Can visitor data leak between visitors? =

Yes, this is a real risk with any full page cache, and it is not specific to this plugin: the HTML generated for one visitor is
stored and served to everyone else. If visitor specific values (email address, user role, IP address) are written into the data
layer, the cached copy will contain the values of whoever happened to trigger the caching.

Turn on the "Cache-safe data layer" option to avoid this. With it enabled, no visitor or session data is written into cacheable
HTML; those values are delivered in the browser instead, under the same data layer variable names.

= Why does the plugin load several separate JavaScript files instead of one? =

Each tracking feature (WooCommerce, each media player, Contact Form 7, device data, etc.) is its own small
JavaScript file, and the plugin loads only the files a page actually needs - the YouTube tracker, for instance,
loads only on pages that embed a YouTube video, and with the "defer" strategy so it never blocks rendering.
Those files are already minified by the plugin's build.

Merging several files into one is intentionally left to a caching / performance plugin (WP Rocket, Autoptimize,
LiteSpeed Cache, etc.), which can combine scripts across your whole site and in a way that suits your hosting and
HTTP setup. On modern HTTP/2 hosting, many small conditionally-loaded files usually perform as well as one combined
file. (1.x combined its own scripts; 2.0 delegates this.)

== Screenshots ==

1. Admin panel
2. Basic settings
3. Events
4. Integration panel
5. Advanced settings

== Changelog ==

= 2.1 =

* Changed: for developers, `ContainerCode::header_top()` always prints its block; the never-used argument that made it return the block instead is gone.
* Changed: the container loader requests `gtm.js` over `https://` instead of the protocol-relative `//` form the plugin has emitted since 1.x, matching the snippet Google publishes today. Only a site still served over plain http sees a difference; the `<noscript>` iframe already used `https://`.
* Fixed: the plugin no longer buffers every WooCommerce template part on every page. That buffering only fed the classic "Products" widget, whose tracking had been dead since 2017. If a GTM trigger filters on an `item_list_name` ending in "(widget)", remove that condition.
* Fixed: the description of the WooCommerce **"Order data in data layer"** setting no longer says it works independently of ecommerce tracking. It needs "Track e-commerce", and still writes `orderData` when the purchase event is not sent again.
* Fixed: a settings import file with a checkbox written as the text "false" switched that option on; it now means off, as it does when saving the settings screen.
* Changed: with the cache-safe data layer on, the WooCommerce customer and cart data are re-read only when the cart fragment changed, not on every change to the page.
* Added: an optional **"Output values in the default language"** setting (Page variables → Content & engagement data): `pageTitle`, `pageCategory`, `pageAttributes`, `pagePostTerms` and `pagePrimaryCategory` report the master language, so Google Analytics combines reports across translations. Needs WPML, Polylang or the `gtm4wp_master_language_post_id` / `gtm4wp_master_language_term_id` filters (the three settings below: post filter only). Off by default (experimental). Thanks to @loran750 (#145).
* Added: an optional **"Report products in the default language"** setting (WooCommerce → Product data): the whole GA4 item, `item_id` included, reports the master language, so one product combines across its translations. Review any product feed or dynamic-remarketing setup keyed on the translated id before switching it on. Off by default (experimental) (#145).
* Added: an optional **"Report downloads in the default language"** setting (Easy Digital Downloads → Product data), the EDD counterpart of the WooCommerce option above and with the same caveat about setups keyed on the translated `item_id`. Off by default (experimental) (#145).
* Added: an optional **"Report the form name in the default language"** setting (Contact Form 7), so `form_name` carries the master-language title and submissions of one form combine across languages. Works for forms translated as separate entries. Off by default (experimental) (#145).
* Changed: `pagePostTerms` and `pagePrimaryCategoryName` report term names as they were typed, the same string the e-commerce items have carried since 2.0.2. A GTM trigger that matched the encoded form on either variable needs the plain text now; the slug variables are unchanged.
* Changed: `pagePostTerms` is omitted when the post has no terms and no reported meta, instead of being an empty list.
* Changed: on a single site `siteID` and `siteName` carry the site's own id and name instead of 0 and an empty string, and only the variable whose option is on is reported.
* Changed: `visitorEmail`, `visitorEmailHash`, `visitorUsername` and `visitorRegistrationDate` are omitted for a logged-out visitor or an empty value, and `visitorIP` when no address can be determined. `visitorEmailHash` is now normalised the way Google matches user-provided data, so it changes for mixed-case and Gmail addresses.
* Changed: with trusted proxy addresses configured, the Cloudflare country code is read only for requests that arrived through one of them, so add Cloudflare's IP ranges to the list; one admin notice asks for it while either proxy header is read without one. `geoCloudflareCountryCode` only ever carries Cloudflare's two-letter form (plus `XX` and `T1`).
* Changed: a `gtm4wp_admin_page_capability` callback returning something other than a capability name is reported through `_doing_it_wrong()` and the default applies instead of locking every administrator out.
* Changed: the Axeptio project ID field looks up the cookie versions 400 ms after typing stops instead of on every keystroke.
* Added: a **Services for agencies and freelancers** section on the settings screen, with no options, introducing the services the developers of GTM4WP offer to agencies and freelancers and linking to them on gtm4wp.com; the link is left out when a site removes the documentation links with `gtm4wp_admin_doc_url`.
* Removed: the `$gtp4wp_plugin_url`, `$gtp4wp_plugin_basename` and `$gtp4wp_script_path` globals, deprecated in 2.0 as announced. Third-party code still reading them uses `plugin_dir_url( GTM4WP_PLUGIN_FILE )`, `plugin_basename( GTM4WP_PLUGIN_FILE )` and `plugin_dir_url( GTM4WP_PLUGIN_FILE ) . 'build/'` instead.
* Removed: the WebToffee GDPR Cookie Consent (v2.x) integration, deprecated in 2.0. WebToffee v3.x and later connect to Google Tag Manager on their own, so upgrade WebToffee if a site still runs v2.x. The stored setting is deleted on upgrade, and the `cookie_consent_update` and `cookie_consent_<category>` events it pushed stop; review GTM triggers built on them.
* Removed: the "YouTube video events" option, deprecated in 2.0 as announced. Use Google Tag Manager's built-in YouTube Video trigger with its "Add JavaScript API support to all YouTube videos" setting on, since the plugin no longer adds `enablejsapi` to YouTube embeds. Triggers on the plugin's YouTube `gtm4wp.media*` events stop firing; the `gtm4wp_youtube` filter is gone too.
* Removed: `orderData.customer.billing.emailhash`, deprecated since 1.20. GTM variables still reading it switch to `orderData.customer.billing.email_hash`, which carries the same value.

**Easy Digital Downloads**

* Added: **Easy Digital Downloads integration** (EDD 3.0+, beta) as its own settings section, covering the classic shortcodes and the EDD blocks with the full GA4 event set from `view_item` to `purchase`. Purchases resolve through EDD's own payment-key chain and are deduplicated three ways. The purchase-status default includes Pending and Processing, because offsite gateways return buyers before the order completes.
* Added: Easy Digital Downloads settings mirroring the WooCommerce ones: `orderData` in the same key names, persistent list attribution, Enhanced Conversions `user_data` (phone via EDD's field or the new `gtm4wp_edd_order_phone` filter) and reliable purchase tracking; the cache-safe data layer also delivers the customer, the cart and missed purchases. Customer identity reaches only a visitor EDD would show the receipt to.
* Added: for developers, the Easy Digital Downloads filters `gtm4wp_eec_edd_cart_item`, `gtm4wp_eec_edd_order_item`, `gtm4wp_eec_edd_order_data`, `gtm4wp_edd_purchase_datalayer`, `gtm4wp_edd_datalayer_on_pageload` and `gtm4wp_edd_purchase_trackable_statuses`.
* Added: an admin notice when WooCommerce and Easy Digital Downloads both track e-commerce on one site. Running both stores together is not supported: they share settings the browser trackers read, such as the currency and clearing the ecommerce object, so some events may not be tracked as intended.

**Google service accounts**

* Added: a **Google service accounts** section on the settings screen (experimental): upload, label, test and delete the JSON key of a Google Cloud service account, several side by side. The Data Manager destinations below authenticate with one. Keys are stored encrypted, never shown again and removed on uninstall; changing the wp-config.php security keys makes them unreadable, and a notice then names the accounts to upload again.

**Google Data Manager**

* Added: a **Google Data Manager** section on the settings screen (experimental), where each destination row names a GA4 property ID, a measurement ID and the service account authenticating to it. A per-row Test button sends a validation-only request, so a missing permission or mistyped ID is reported before anything is saved. Repeated failures raise an admin notice.
* Added: **attribution capture** (experimental): while refund sending is on, each new order stores the Analytics client and session IDs, the Google Ads click IDs and the consent state, so a server-side event can be matched to it. A GA4 tag has to fire in your container. Storing is consent-gated, and `gtm4wp_gdm_order_consent` overrides the recorded state.
* Added: **server-side refund events** (experimental): every store refund is sent separately to each Data Manager destination as a GA4 `refund` event with amount, items, shipping and tax, matched by transaction ID and client ID. Google Analytics itself processes only the first of several refunds on one order; reported to Google, no update needed once fixed.
* Changed: the settings screen shows **Unsaved changes** next to the Save button while anything is waiting, and the browser asks before a tab with unsaved edits is closed or reloaded.
* Changed: removing a row from a settings table now asks first: the trash icon becomes a Remove/Cancel pair for that row. A row nobody has typed into is still removed straight away.
* Added: a **Recent sends** list under the destinations table: what was sent to Google from the server and what became of it, skips and their reason included, with a switch that hides everything Google applied. Failed or fixable refunds can be sent again, one row or all, and show as queued until the background job runs.
* Added: a **"Require consent before sending"** setting (experimental) deciding which orders need analytics storage granted before anything about them is sent: buyers in the EEA, the UK and Switzerland by billing country (the default), every order, or never. Where the gate applies and consent was denied or was never captured, nothing is sent and the reason is recorded.

**Site Health**

* Added: **Tools → Site Health** reports the whole plugin: option states, containers, placement, data layer name and wp-config overrides (no keys, addresses or visitor data), plus status tests for the configuration, Google service account keys, Data Manager sending and an allowlist blocking every tag; on multisite, who can change the container. Modules report through `SiteHealthInfoInterface` / `SiteHealthTestsInterface`.

**AI assistants (WordPress Abilities API)**

* Added: the plugin registers **abilities** with the WordPress Abilities API (WordPress 6.9+; nothing changes below that), so an AI assistant connected through the WordPress MCP Adapter or another client can read the configuration, help work out why tracking is not firing, and change a setting once you have confirmed it. Eleven abilities, six of them read-only, all requiring the settings capability and returning no keys and no visitor data. `gtm4wp_abilities_enabled` and `gtm4wp_abilities_allow_write` switch the surface off or keep it read-only. **Experimental.**
* Changed: the admin notices about a missing container ID, an incomplete environment configuration, a malformed `GTM4WP_HARDCODED_*` constant, a visitor IP header with no trusted proxies and an unusable data layer variable name now carry a separate "Open the setting" link after the message. The same checks feed `gtm4wp/get-status`, so an assistant and the screen report the same problems.

= 2.0.6 =

* Fixed: visitors browsing in the in-app browser of an Android app such as Facebook or Instagram got no WooCommerce data layer, so their `purchase` and other e-commerce events were missing. Thanks to @sw33tr for the report (#477).
* Fixed: on the classic cart page, re-adding a removed product with "Undo" now sends `add_to_cart`. WooCommerce restores it in the background and discarded the page part that carried the event.
* Fixed: with "Clear ecommerce object before new event" on, the e-commerce events GTM4WP adds as the page loads (`view_item`, `view_cart`, `begin_checkout`, `purchase`, the cart "Undo" `add_to_cart`) and the Quick View `view_item` are now cleared before too, not only the events fired in the browser.
* Fixed: the plugin conflict notice now calls Google Analytics for WooCommerce by its current name.
* Fixed: WooCommerce Mini-Cart tracking (`remove_from_cart` from the Mini-Cart drawer) now loads only on pages that show the Mini-Cart block. Stores using the Cart or Checkout block no longer load the WordPress data scripts on every page, and stores with a classic cart now track removals from a Mini-Cart block too.
* Fixed: a WooCommerce Product Bundles bundle reported `stockstatus` as `instock` in the e-commerce items even when one of its bundled products was out of stock and the bundle could not be bought; it now reports `outofstock`. Thanks to @KingWehsops for the report (#474).
* Fixed: a `gtm4wp_overwrite_consent_mode_flag` filter callback returning the string `denied` set that consent signal to `granted`; the filter now also accepts `granted` and `denied`.
* Fixed: a WooCommerce checkout total that is not a finite number no longer breaks the inline checkout script; `gtm4wp_checkout_value` is `null` then.
* Fixed: hardened how the plugin's REST endpoints answer requests made from other pages.
* Changed: `siteSearchTerm` carries the search term as typed; the classic data layer used to HTML-encode it while the cache-safe one did not. A GTM trigger matching the encoded form needs the plain text now.
* Changed: `pageTitle` carries the title as the visitor reads it: WordPress's encoding of ampersands, quotes and dashes is decoded. A GTM trigger matching the encoded form needs the plain text now.
* Changed: the Contact Form 7 tracker script is no longer loaded while Contact Form 7 is not installed.
* Changed: for developers, `gtm4wp_datalayer_push()` returns `false` for a non-string `$js_before`/`$js_after` argument instead of printing `Array` into the page.
* Fixed: thank-you pages that show the purchase are now kept out of page caches. The page set as "Custom order received page" is treated the same way WooCommerce treats its own order received page; a thank-you page built by a theme or page builder is excluded from page-cache plugins.
* Fixed: on a password-protected post, the post meta, word count and reading time variables are left out of the data layer for visitors who have not entered the password.
* Fixed: when the Contact Form 7 tracker's settings do not reach the page (for example an optimisation plugin drops inline scripts), it no longer falls back to sending the submitted form values.
* Fixed: a PHP error when `woocommerce_thankyou` fired with an id that is not an order, or on a product page whose product could not be loaded.
* Updated: tested with WooCommerce 11.2.0.

= 2.0.5 =

* Fixed: the data layer initialisation block no longer contains the word `gtag`. Since 2.0.3, a JavaScript delay plugin with `gtag` on its keyword list, such as Flying Scripts, delayed the whole block and the browser console showed `dataLayer is not defined`. The Google tag developer ID is still set.
* Fixed: a page with no data layer variables to report no longer pushes an empty array into the data layer; `dataLayer_content` is then an empty object and is not pushed. Content that is pushed is always an object, never an array, which GTM would read as a command.

= 2.0.4 =

* Fixed: on the classic WooCommerce checkout, an error in the plugin's checkout step tracking could stop the order from being submitted the normal way, so a gateway that adds card details in the browser, such as Stripe, rejected it. Tracking errors can no longer interrupt the checkout or a variation selection, and still show in the browser console. (#472)

= 2.0.3 =

* Fixed: on a store that displays prices including tax, every cart line in view_cart, begin_checkout and the cart content carried a discount equal to the line's tax, with no coupon or sale involved, so GA4 reported a share of the revenue as a discount on every order. The total side of the discount calculation read a key WooCommerce never writes on a cart item, so only the subtotal side gained the tax. An undiscounted line carries no discount again. Stores displaying prices excluding tax were never affected, and neither was the purchase event.
* Fixed: on a store whose product page runs the newer WooCommerce blocks (built on the WordPress Interactivity API), an add to cart the store refused could still be reported when a related-products or grid add was clicked within the next ten seconds, so add_to_cart fired twice for a single item. A list add now supersedes whatever the product form still had waiting.
* Fixed: on the same stores, a cart read over the Store API that came back with something other than a cart could report every item as removed; it is now treated as no reading at all.
* Fixed: a purchase was never reported when the customer reached the order received page while the order was still Pending payment, and "Reliable purchase tracking" did not recover it. This happens with a payment provider that confirms the payment through a webhook, such as the WooCommerce Stripe Gateway with webhooks enabled: the customer is redirected back a moment before the confirmation arrives, so the plugin sees a status that is not in "Order statuses that trigger the purchase event" and withholds the event, and the later change to Processing happens in a request the plugin cannot connect to the customer's browser. With "Reliable purchase tracking" on, such an order is now remembered in the customer's session and checked again on the pages they view next; the purchase is reported once, as soon as the status has become one of the tracked statuses, and the order is flagged as tracked at that moment. The wait ends by itself when the order is cancelled, refunded or fails, or once the order is older than the "Maximum order age" (30 minutes by default). Pending payment itself is still not counted as a sale, and every existing duplicate guard applies unchanged.
* Fixed: with a custom data layer variable name, the Google Consent Mode default block pushed its defaults to dataLayer instead of the configured variable, so the container never received them and the consent defaults did not apply. The block now uses the configured name and stays out of WP Rocket's combined JavaScript.
* Fixed: on a subdomain multisite, or any site defining COOKIE_DOMAIN, the cache-safe data layer could not clear its event cookie after a one-shot event was delivered, so every later page view made a needless request. The cookie is now host-only.
* Fixed: a login or registration event was lost when the visitor's next request was a REST call (the WooCommerce Store API, a headless front end) rather than a page view.
* Fixed: a user account created by a logged-in administrator through the REST API or an admin app fired gtm4wp.userRegistered in the administrator's own browser instead of nowhere.
* Fixed: the form interaction and Contact Form 7 events reported a hidden action field instead of the form's URL when the form contained a field named action, id or target.
* Fixed: the browser, OS and device data script reported all three signals when a page optimiser removed its inline configuration, ignoring which of them were enabled. It now reports nothing in that case.
* Fixed: with the cache-safe data layer, a logged-in visitor served a cached page kept requesting their visitor data on every page view after WordPress refused the page's nonce. When WordPress itself reports the rejected nonce, the data is requested once more anonymously and that page stops asking.
* Updated: tested with WooCommerce 11.1.2.
* Added: the data layer initialisation block carries the plugin's Google tag developer ID, gtag('set', 'developer_id.dNGJiYT', true), so Google can tell which platform installed the tag. It identifies GTM4WP only and adds nothing about the site or its visitors.

= 2.0.2 =

* Updated: tested with WooCommerce 11.1.
* Fixed: a product category or brand name containing an ampersand reached the data layer as "Shirts &amp; Ties" rather than "Shirts & Ties", which is what GA4 then reported. WordPress encodes term names when they are saved, and the plugin now decodes that once when it reads the name.
* Fixed: item_id, sku and the dynamic remarketing id are now always strings. A product with no SKU falls back to its numeric id, and that fallback was written into the data layer as a number while the same fields are strings on every product that has a SKU. Check your GTM setup if a trigger or variable compares one of those fields against a number.

* Fixed: the begin_checkout event was missing on stores where WooCommerce reports the checkout page as the cart page as well, which happens when a plugin or a theme forces that decision or when a cart shortcode is left in the checkout page content. Such a checkout page pushed view_cart and begin_checkout never fired anywhere. The checkout is now decided first, so a page reported as both is treated as the checkout page. The order-received page is recognized ahead of both on both halves as well: on such a store the thank-you page used to be taken for the cart page by the block tracker, which loaded there in its cart context in place of the regular tracker.
* Fixed: add_to_cart and remove_from_cart never fired on a store whose product and cart blocks are built on the WordPress Interactivity API, the newer form of the WooCommerce blocks. Product pages now report the add once WooCommerce confirms that the item reached the cart, and cart changes made anywhere else are read back from the WooCommerce Store API and reported the same way as on a store using the older blocks. Variable products are covered as well: the interactive form publishes no variation data the plugin could read, so the event is completed from the cart line the add creates, which carries the data of the variation itself.
* Fixed: a GTM4WP_HARDCODED_* value in wp-config.php that ends in a newline character - typically an untrimmed file read feeding the define() - is now rejected and named in the existing admin notice, like any other malformed value. Previously a gtm_auth/gtm_preview value with a trailing newline passed validation, the newline reached the container loader script and broke the whole block, so the container silently did not load with nothing pointing at wp-config. A container ID with a trailing newline used to be silently repaired and kept working; it is now reported the same way instead - remove the stray newline from the define() and the override applies again.
* Fixed: a page whose author cannot be resolved no longer takes the whole page down with a critical error when PublishPress Authors is active. PublishPress reports such an author as no author at all, which happens when the author's user account has been deleted, and the plugin passed that straight into the code reading the author name and ID, where it ended in a fatal error. The WooCommerce My Account page is the one most likely to hit it, since it is often the page nobody keeps an author on. An author that cannot be resolved is now skipped, so the page renders and the author variables are simply left out of the data layer, and a post that has one real author next to an unresolvable one is treated as having a single author.

= 2.0.1 =

* Fixed: on a block-based store, opening the Cart page pushed add_shipping_info and add_payment_info into the data layer with no interaction, and both events then fired again on the Checkout page. The block tracker told the two pages apart by the presence of the WooCommerce payment data store, which WooCommerce registers on the Cart page as well; the Cart and Checkout pages now each receive their own context and the checkout-step events fire only on the Checkout page.
* Fixed: a blank settings screen no longer stays silent about why it is blank. Some ad and privacy blocker filter lists block everything under the plugin folder, including the file that builds the settings screen in the browser, which left the settings page empty with no explanation. A static notice now appears after a few seconds whenever the settings app could not start, explaining the most likely cause and the workaround (pause the blocker for the admin area of the site, or add an exception for it).

= 2.0.0 =

Major rewrite of the plugin. Please read the announcement post on gtm4wp.com before upgrading. This section is a summary; wordpress.org truncates a changelog after 5,000 words, so the complete list of 2.0.0 changes with every detail lives in the changelog on GitHub: https://github.com/duracelltomi/gtm4wp/blob/master/CHANGELOG.md

* Changed: complete object-oriented rewrite. Every feature is a module that third-party plugins can extend. All public template functions, filter and action names, wp-config constants and the options storage key are unchanged, so existing integrations keep working.
* Changed: minimum requirements raised to PHP 8.0 and WordPress 6.3.
* Added: modern React-based settings screen with left pane navigation, tabbed option groups, option search, inline validation, a help link on every option, bookmarkable addresses and settings export and import.
* Added: every Google Tag Manager container ID has its own environment parameters, custom domain and custom path; a production-only kill switch for staging copies; validation of the hard-coded wp-config constants with a warning that names the wrong one.
* Changed: the tag restriction list is written under the documented `gtm.allowlist` / `gtm.blocklist` keys, the entity list is refreshed from Google's documentation, and blocklist mode no longer blocks every tag.
* Added: cache-safe data layer mode for sites behind a full page cache, with the visitor specific values fetched separately.
* Changed: GA4 e-commerce tracking reworked for WooCommerce: reliable purchase tracking, custom order received page, order statuses that trigger the purchase event, list attribution across the funnel, block cart and checkout support, Store API data for the block trackers, and numeric-looking text values no longer turned into numbers.
* Added: an Axeptio consent management platform integration and a CookieYes consent bridge; the WebToffee v2.x integration is deprecated. Media events cover more embedded players, and a player's script is requested only on pages that contain one of its embeds.
* Removed: the weather and geo data features and the scroll tracking feature (use the built-in Scroll Depth trigger of Google Tag Manager instead). Browser, OS and device data is now collected in the browser with User-Agent Client Hints, which replaces the bundled WhichBrowser library.
* Deprecated: the "YouTube video events" option (Google Tag Manager ships a native YouTube Video trigger) and the `$gtp4wp_plugin_url`, `$gtp4wp_plugin_basename` and `$gtp4wp_script_path` global variables, which will be removed in 2.1.

Older releases are listed in the full changelog on GitHub: https://github.com/duracelltomi/gtm4wp/blob/master/CHANGELOG.md

== Upgrade Notice ==

= 2.0.0 =
Major rewrite: requires PHP 8.0 and WordPress 6.3. Weather/geo data, scroll tracking and the bundled WhichBrowser library were removed. Public API (template functions, hooks, options) is unchanged.


= 1.22.5 =

Security release. Hardens how product data is written into WooCommerce product list and cart markup. Recommended for every store, and especially where users other than the site administrator can edit products.

= 1.22.4 =

Security and deprecation release. Hardens data layer and inline script encoding, and marks the features that will be removed in the upcoming GTM4WP 2.0 rewrite (geo data, weather data and scroll tracking). Those features still work in this version.

= 1.22.3 =

Bugfix release

= 1.22.2 =

Bugfix release

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
