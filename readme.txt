=== GTM4WP - A Google Tag Manager (GTM) plugin for WordPress ===
Contributors: duracelltomi
Donate link: https://gtm4wp.com/
Tags: google tag manager, tag manager, gtm, google ads, google analytics
Requires at least: 6.3
Requires PHP: 8.0
Tested up to: 7.1
Stable tag: 2.0.2
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
	* Does not support promotions, since WooCommerce does not have such a feature (yet)
	* Does not support refunds
* CheckoutWC: optional support for its multi step checkout template
* PublishPress Authors: co-author and guest author data in the page variables
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

= Exclude specific user roles from being tracked =

You can set which user roles need to be excluded from tracking when a user with that role visits the frontend. This will completely disable the container code for that user.

= Staging and development sites =

The container can be limited to production environments only, so a cloned or staging copy of your site does not send data into
your production Google Tag Manager container. This relies on the WP_ENVIRONMENT_TYPE setting of WordPress.

= For developers =

Version 2.0 is a complete object oriented rewrite. Every feature is a module, and third party plugins can register their own
modules through the gtm4wp_register_modules action. All public template functions, filter and action names, wp-config constants
and the option storage key of the 1.x versions are unchanged, so existing integrations keep working.

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

* Changed: the container loader script requests gtm.js over https:// explicitly instead of the protocol-relative // form the plugin has emitted since 1.x, matching the snippet Google currently publishes. On an https site the request was already identical; a site still served over plain http now loads the container over https as well. The noscript iframe already used https://.
* Fixed: the plugin no longer buffers the output of every WooCommerce template part on every page. That buffering existed only to inject tracking markup into the classic "Products" sidebar widget, but the tracker looked for those products under a CSS class the plugin itself stopped emitting in 2017 (and WooCommerce never used), so the whole widget path has silently produced no tracking for years while the buffering ran as pure overhead on every store page - it is removed now, together with the dead `.widget-product-item` selectors in the tracker script. Product lists rendered by the current widget and block equivalents were and are tracked through the regular product list markup. If your GTM setup filters on an `item_list_name` ending in "(widget)", that value could only have come from this dead path and the condition can be removed.
* Added: an optional **"Output values in the default language"** setting (Page variables → Content & engagement data) for multilingual sites. With this on, the language dependent page variables - the post title (`pageTitle`), category slugs (`pageCategory`), tags (`pageAttributes`), taxonomy terms (`pagePostTerms`) and the primary category (`pagePrimaryCategory`) - are output in the site's default (master) language instead of the current translation, so Google Analytics can combine reports across all languages instead of splitting the same content per translation. The master value is written in place (no extra data layer variables), so no GTM changes are needed. Works with **both WPML and Polylang** (detected the same way as the "Page language" option) and is filterable through the new `gtm4wp_master_language_post_id` / `gtm4wp_master_language_term_id` filters for other multilingual plugins. On a single-language site, an untranslated page, or with no active multilingual plugin the current values are unchanged. **Off by default** (experimental). Thanks to @loran750 for the request (#145).
* Added: an optional **"Report products in the default language"** setting (WooCommerce → Product data), the e-commerce counterpart of the page-variable option above. On a multilingual store, the whole GA4 item - `item_id`, `item_name`, `item_category*`, `item_brand` and `item_variant` - is reported in the store's default (master) language, so a product sold in several languages combines into a single item in Google Analytics instead of one row per translation. Because GA4 groups items by `item_id`, the id is resolved to the master product too, so review any product feed or dynamic-remarketing setup that keys on the translated id before enabling it. Price, stock and the internal list-attribution id stay per language. Works with **both WPML and Polylang**; on a single-language store or an untranslated product nothing changes. **Off by default** (experimental) (#145).
* Added: an optional **"Report downloads in the default language"** setting (Easy Digital Downloads → Product data), the Easy Digital Downloads counterpart of the WooCommerce option above. On a multilingual store, the whole GA4 item - `item_id`, `item_name`, `item_category*`, `item_brand` and `item_variant` (the variable price option name, read from the default-language download) - is reported in the store's default (master) language, so a download sold in several languages combines into a single item in Google Analytics instead of one row per translation. Because GA4 groups items by `item_id`, the id is resolved to the master download too, so review any product feed or dynamic-remarketing setup that keys on the translated id before enabling it. Price and the internal list-attribution id stay per language. Works with **both WPML and Polylang**; on a single-language store or an untranslated download nothing changes. **Off by default** (experimental) (#145).
* Added: an optional **"Report the form name in the default language"** setting (Contact Form 7), so `form_name` in the data layer carries the form's default (master) language title instead of the translated one, letting submissions of the same form in several languages combine in Google Analytics. Works with **both WPML and Polylang** for forms translated as separate form entries; on a single-language site or a form translated only through string translation the title is unchanged. **Off by default** (experimental) (#145).
* Fixed: an Easy Digital Downloads category name containing an ampersand reached the data layer as "Shirts &amp; Ties" rather than "Shirts & Ties", which is what GA4 then reported. The name is decoded once, at the point it is read, exactly as 2.0.2 does for WooCommerce categories and brands, and still reaches the page through the same JSON encoder as before.
* Changed: the page variables carrying term names - pagePostTerms and pagePrimaryCategoryName - now report them as they were typed as well ("Shirts & Ties", not "Shirts &amp; Ties"), the same string the e-commerce items have reported since 2.0.2. A GTM trigger that matched the encoded form on one of these two variables needs the plain text now; slugs were never encoded and are unchanged.

**Easy Digital Downloads**

* Added: **Easy Digital Downloads integration** (EDD 3.0+, beta) as its own settings section, mirroring the WooCommerce integration for EDD's digital-goods flow. GA4 events: `view_item` on download pages (variable-priced downloads report their lowest price option first, then `view_item` fires again with the picked option's price and name once the buyer selects one), `view_item_list`/`select_item` on the `[downloads]` grid, `add_to_cart` on the buy button (including Buy Now, with one item per checked price option — the option name becomes the `item_variant`, the id stays the download id/SKU), `remove_from_cart` on the checkout cart (quantity edits report their delta as `add_to_cart`/`remove_from_cart` when EDD's Item Quantities setting is on), `view_cart` on the `[download_cart]` page, `begin_checkout` on the checkout page, `add_payment_info` on gateway selection and `purchase` on the confirmation page. Purchases resolve the order only through EDD's own payment-key chain (never a bare order id) and are deduplicated three ways: the `_ga_tracked` order meta, the `gtm4wp_orderid_tracked` browser guard and an order age gate. Offsite gateways can land buyers on the confirmation page while the order is still pending, so the "Order statuses that trigger the purchase event" default includes Pending and Processing — the dedupe guards keep the order from counting again once it completes. The EDD blocks are covered alongside the classic shortcodes: the downloads and buy-button blocks, the checkout block and the full cart block (`view_cart` fires for a cart block with the mini display switched off).
* Added: Easy Digital Downloads settings mirroring the WooCommerce ones where they apply to digital goods: products per impression, use SKU instead of ID, brand taxonomy, full category path, persistent list attribution (experimental: remember which list a download was clicked in and carry `item_list_name`/`item_list_id` onto the later funnel events, merged in the browser on cacheable pages), Google Ads business vertical + product ID prefix, cart content / customer data (with hashed email, the Enhanced Conversions `user_data` block — including a hashed E.164 phone number when EDD's own optional checkout phone field collects one, when a checkout-field extension stores one under the community-standard `phone` meta, or when site code supplies it via the new `gtm4wp_edd_order_phone` filter — and the `new_customer` / `customer_type` signals on `purchase`) / order data in the data layer (the raw order data never includes the order's payment key — it authorizes viewing the receipt; the confirmation page resolves the order only through EDD's own verified receipt link — the payment key, or the order id together with the matching receipt hash EDD's links carry, or the buyer's own purchase session — never a bare order id, and the customer identity — names, address, email, the Enhanced Conversions block — is emitted only to a visitor Easy Digital Downloads itself would show the receipt to, while the purchase event still fires; a leaked or shared confirmation URL therefore measures the purchase without exposing the buyer's details), maximum order age, exclude tax from revenue, transaction ID prefix, do-not-flag-orders, reliable purchase tracking (experimental: a purchase whose confirmation page was never reached — an abandoned offsite payment redirect — is measured on the buyer's next visit instead, resolved from their own purchase session) and the clear-ecommerce/`select_item`-timeout advanced options. New extension filters: `gtm4wp_eec_edd_cart_item`, `gtm4wp_eec_edd_order_item`, `gtm4wp_eec_edd_order_data`, `gtm4wp_edd_purchase_datalayer`, `gtm4wp_edd_datalayer_on_pageload` and `gtm4wp_edd_purchase_trackable_statuses`; the store-agnostic `gtm4wp_eec_item_with_source` and `gtm4wp_eec_item_affiliation` filters run on EDD items too. A filter value PHP cannot convert to JSON costs only that value, never the surrounding script block.

**Google service accounts**

* Added: a **Google service accounts** section on the settings screen (experimental), the foundation for features that need to call Google APIs from the server rather than from the visitor's browser - the first of them being the new Google Data Manager section below, where each destination picks the stored account it authenticates with. Upload the JSON key file of a Google Cloud service account, give it an optional label (renameable at any time - the label is display-only, so renaming never touches the key or anything referencing the account), test that it can obtain an access token from Google, and delete it again; several accounts can be stored side by side. The private key inside the file is kept encrypted (AES-256-GCM, keyed from the site's AUTH_KEY/SECURE_AUTH_KEY security keys) in its own non-autoloaded option, is never shown again after the upload, is left out of a settings export, and is removed on uninstall; only the account email, key id, upload date and test result are ever displayed. Only the four fields the token exchange needs are stored, so the rest of the key file never reaches the database. A file that is not a service-account key file, whose key cannot be read, or that names a token endpoint other than Google's is refused at upload with a message that never quotes the file back. Every server-side request this feature makes goes over https to Google's own API hosts and nowhere else - any other host, a plain-http URL or an unexpected explicit port is refused before a connection is opened. Changing the security keys in wp-config.php makes every stored key unreadable: the affected accounts are then named in an admin notice linking to the section, and their key files have to be uploaded again. The section has no options in the regular settings row and does nothing on a frontend pageview; it is managed through REST routes that require the same capability as the settings screen. Access tokens are cached for their lifetime in transients, are dropped for every scope the account was used with when the account is deleted, and are never written to a log or a response.

**Google Data Manager**

* Added: a **Google Data Manager** section on the settings screen (experimental), the foundation for sending e-commerce signals to Google from the server through the Google Data Manager API - above all signals the visitor's browser never sees, such as refunds issued in the store admin. At this stage the section defines and verifies **destinations**: each row of the destinations table names a Google Analytics 4 property (its numeric property ID) and one of its web data streams (the G-XXXXXXX measurement ID), and picks the stored Google service account that authenticates the plugin to it. A per-row Test button sends a single validation-only request through the Data Manager API: Google checks the whole chain - the key, the API being enabled in the Google Cloud project, the account's access to the property - but records nothing, so a missing permission or a mistyped ID is reported immediately next to the row (as is a failure of the test request itself), and rows can be tested before the table is even saved. Google's refusals are explained in plain words where they have a known meaning, with Google's original wording kept in parentheses: a not-found answer says to double-check both IDs and the account's access to the property (Google reports a property the account is not allowed to see as not found too), a permission answer points at the Editor role and the Data Manager API being enabled, an authentication answer points back at testing the stored service account; a destination refused before the request is even sent has each failing field named, never a generic "not valid". The property ID and measurement ID cells also check their format while you type: a non-numeric property ID or a measurement ID that is not of the G-XXXXXXX form is marked in the table right away, with a message naming what the cell expects. Saving validates every row (numeric property ID, G-XXXXXXX measurement ID, each data stream only once) and names the row and the reason when something is refused, while an added row left untouched is simply dropped; a service account that a destination still references cannot be deleted. The section also keeps a per-destination health record, so that repeated failures of a destination raise a non-dismissible admin notice naming it. Third-party code can extend the runtime destination list through the new `gtm4wp_gdm_destinations` filter.
* Added: **attribution capture** (experimental, off by default), on a new **Attribution capture** tab of the Google Data Manager section: the setting that lets the plugin store with every new order what is needed to match a later server-side event - a refund, above all - to the original purchase in Google Analytics: the Analytics client and session IDs, the Google Ads click IDs (gclid, gbraid, wbraid) of the visit, and the consent state at the time of the order. Because the IDs are read through the official Google tag API (gtag('get')) rather than by picking Google's own cookies apart, the setting needs at least one destination above whose measurement ID belongs to a Google Analytics 4 tag that really fires in your container - the checkbox stays disabled until a destination exists, and the option description says so. The lookups are queued once per page view and answered whenever the Google tag loads, so a container that brings Google Analytics in a few seconds late is served just as well as one that loads it immediately; if no such tag ever fires, nothing is stored and nothing is invented. Everything captured is held in memory until the visitor's consent state allows storing it: the Analytics IDs need analytics storage and the click IDs need ad storage to be granted, a site running no consent mode at all stores normally, and a banner answered on the landing page itself still saves the click ID that page arrived with. The consent state itself is written in every case, denial included - it is the record of the choice. The visitor's answer is read from the Google tag's own consent state, so a consent tool that lives inside your GTM container - a consent-mode tag template rather than a script on the page - is understood exactly like one that announces itself on the page; those are invisible to the page itself, and reading only the page would mean both storing data for visitors who refused and discarding it for visitors who agreed. Consent is honoured in both directions: if the visitor later withdraws it, or if a consent tool that loads a moment after the page finishes answers no, whatever had already been stored is removed again rather than merely left alone. The values live in two first-party cookies of the site's own; no third-party cookie is involved and no Google cookie is ever read or written.On the server they are read back the moment an order is created - on the WooCommerce classic checkout, on the block checkout and on Easy Digital Downloads alike - and stored in the order's own meta under namespaced _gtm4wp_* keys. Every value is checked against a strict format and dropped if it does not fit, and anything that was never captured is left out of the order rather than stored as an empty value. Site code can supply or override the consent state per order through the new gtm4wp_gdm_order_consent filter, which is the way out for consent tools that keep the visitor's choice inside the GTM container where the page never reflects it; the filter has the last word on every path that stores a consent state, and the server drops the identifiers that the resulting answer does not allow rather than trusting the browser to have done it.A buyer whose only page view was the checkout can place the order before Google has answered, so the confirmation page tries once more and sends what it resolved to the order it belongs to: the page carries that request only when the order really is still missing its attribution, the request has to come from a page on this site and carry the order's own proof of purchase - the WooCommerce order key or the Easy Digital Downloads payment key, the same secret that authorizes showing the receipt, or on EDD's own receipt links the verification hash those links carry instead of the key, so the page never prints a secret its address bar does not already hold - and it can only fill in what is empty, so nothing can overwrite attribution that is already there; a consent denial already recorded with the order keeps the identifiers it forbids out as well, whatever the confirmation page reports later. The plugin also keeps a small counter of how many recent orders carried attribution, so that a mistyped measurement ID or an Analytics tag removed from the container becomes something the plugin can report rather than a mystery discovered weeks later. All of this is wired into WordPress' own privacy tools on both platforms: an export request lists what is stored against that person's orders in plain words, and an erasure request removes it, in a single write per order. That matters more than it may sound, because uninstalling the plugin deliberately leaves order meta alone - sweeping every order of a store is expensive, and the existing purchase-tracking flag works the same way - which makes the erasure request the way to remove one person's data.
* Added: **server-side refund events** (experimental, off by default), on a new **Sending events** tab of the Google Data Manager section - the signal browser-side tracking can never report. A refund is issued in the store admin, where no page is loaded and no tag fires, so Google Analytics keeps counting revenue that was given back. With this on, every refund issued in WooCommerce or in Easy Digital Downloads is sent to each configured destination as a GA4 refund event, matched to its original purchase by the same transaction ID the purchase event reported and by the client ID the attribution capture stored with the order. Every refund reports its own amount, the items it covers and the shipping and tax it returned, whether it returns one line or the whole order. Known limitation, being taken up with Google: when an order is refunded in several steps, Google Analytics has been observed to count only the first step although it accepts every one; each step is still sent. Refunded line items are built by the same builder as the purchase items, so their item_id, name, brand, variant, categories, discount and affiliation match whatever the purchase sent - variations included. Sending happens in the background about a minute after the refund, through Action Scheduler where the store provides it - WooCommerce always, Easy Digital Downloads since its version 3.6.5 - and through WP-Cron anywhere else, and queued jobs are removed again on uninstall on either backend; rate limits, network failures and Google-side outages are retried on a widening schedule up to twelve hours, and a request Google refused as wrong is not repeated. Where several destinations are configured, only the ones that failed are retried, and every refund is sent exactly once. Nothing is ever guessed: an order with no stored client ID, an order whose consent rule does not allow the transfer, or a store with no destination configured is skipped with the reason recorded - and where the missing client ID is the consequence of the buyer refusing analytics storage, the list says that rather than reporting the missing ID on its own. A store with no destination configured sees that reported first, ahead of anything particular to the order. Site code can change or cancel the event through the new gtm4wp_gdm_refund_event filter.
* Changed: the settings screen says **Unsaved changes** next to the Save button while anything is waiting, and the browser asks before you close or reload the tab with unsaved edits.
* Changed: removing a row from a settings table (a container, a Data Manager destination) now asks first instead of deleting it under the pointer. An untouched row is still removed straight away.
* Added: a **Recent sends** list under the destinations table, and two new rows in Tools -> Site Health. The list appears once a server-side send is turned on, and stays as long as it has something to show. It shows what has been sent to Google from the server and what became of it, including every case where nothing was sent and why, colour coded so failures and skipped sends are found at a glance while the words still say the same thing, with a switch that hides the successful sends entirely. The list keeps the fifty most recent sends and drops routine ones before problems when it has to make room. Once a failure's cause is corrected, one button queues every failed refund again - aimed only at the destinations that never took them - and a passing Test on the corrected destination ends its failure streak straight away. Because Google processes an accepted request between half an hour and a day later, the plugin asks back on Google's own published schedule and records the answer. Site Health reports a service account key that can no longer be read and a destination whose sends keep failing as critical issues, and orders arriving with no attribution captured at all - the state a mistyped measurement ID produces - as a recommended improvement. Its Info tab gains a section with statuses, counts and short reason codes only: no account address, no key material and no property IDs, so it stays safe to paste into a support thread. The section belongs to the plugin as a whole; any module can report into it, and site code can add rows through the new gtm4wp_site_health_info filter.
* Added: a **"Require consent before sending"** setting (experimental) that decides for which orders the stored consent state has to allow analytics storage before anything about them is sent to Google: for buyers in the EEA, the UK and Switzerland (the default, resolved from the billing country of the order, which is more reliable than guessing from the visitor's IP address), for every order, or never - the last choice meaning you assert your own lawful basis for the transfer. Where the gate applies and consent was denied, or where no consent state was captured at all, nothing about that order is sent and the reason is recorded rather than guessed. Refusing in the unknown case is deliberate on two counts: an absent record is not evidence of a grant, and a Google Analytics event sent with the temporary ID a cookieless page hands out could never be matched to its purchase anyway.

= 2.0.3 =

* Fixed: on a store that displays prices including tax, every cart line in view_cart, begin_checkout and the cart content carried a discount equal to the line's tax, with no coupon or sale involved, so GA4 reported a share of the revenue as a discount on every order. The total side of the discount calculation read a key WooCommerce never writes on a cart item, so only the subtotal side gained the tax. An undiscounted line carries no discount again. Stores displaying prices excluding tax were never affected, and neither was the purchase event.
* Fixed: on a store whose product page runs the newer WooCommerce blocks (built on the WordPress Interactivity API), an add to cart the store refused could still be reported when a related-products or grid add was clicked within the next ten seconds, so add_to_cart fired twice for a single item. A list add now supersedes whatever the product form still had waiting.
* Fixed: on the same stores, a cart read over the Store API that came back with something other than a cart could report every item as removed; it is now treated as no reading at all.

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

= 1.22.5 =

A maintenance release for the 1.x line. 1.22.4 was intended to be the last one before GTM4WP 2.0; this release exists because the fixes below are worth shipping to 1.x users rather than holding for 2.0.

* Fixed (security): hardened how the hidden product-data attribute is built for WooCommerce product lists and cart remove links, so that no product field value can affect the surrounding HTML. Certain values were not guaranteed to stay inside the attribute.
* Fixed: when a custom X-Forwarded-For header is configured as the visitor IP source, all entries of the header are now evaluated. Only the first entry was ever considered, because the remaining ones were not trimmed of the space that follows each comma and therefore failed IP validation.
* Fixed: the custom visitor IP header name is now validated in full. The check accepted any value that contained at least one valid character, so an invalid header name passed validation and was then simply never found. Setups with a working header name are unaffected.

= 1.22.4 =

* Fixed: hardened how values are encoded into the data layer and into inline script blocks. Script blocks are no longer HTML entity decoded after sanitization, and every value written into a script context is now JSON encoded with the full set of hex escaping flags. Thanks [cyn](https://github.com/cyn8)
* Fixed: nonce attribute should not be sanitized. Thanks [oxyc](https://github.com/oxyc)
* Fixed: avoid warnings when saving settings. Thanks [mircobabini](https://github.com/mircobabini)
* Fixed: only float number is returned in JavaScript processing. Thanks [gkipouros](https://github.com/gkipouros)
* Updated: only load plugin files on frontend or on admin when the admin user has sufficient permissions.
* Updated: WooCommerce 10.4+ compatibility - the checkout inline script no longer uses the deprecated wc_enqueue_js() function. The old function is still used as a fallback on setups where the tracker script is loaded in the page head.
* Updated: WordPress and WooCommerce compatibility.
* Deprecated: geo data (ipstack.com) and weather data (OpenWeatherMap) integrations. They still work in this version but will be removed in GTM4WP 2.0. There is no direct replacement: collect this data in Google Tag Manager or with a dedicated service instead.
* Deprecated: scroll tracking. It still works in this version but will be removed in GTM4WP 2.0. Use the Scroll Depth trigger built into Google Tag Manager instead.
* Deprecated: the "Custom tag/variable templates" blacklist option, plus the Universal Analytics and Mouseflow entries of the tag blacklist. These will be removed in GTM4WP 2.0.
* Added: a dismissable admin notice announcing the upcoming GTM4WP 2.0 release. If your site uses one of the deprecated features above, the notice names it.

= 1.22.3 =

* Fixed: when timeout for the select_item event with WooCommerce is set to 0, plugin does not halt the browser from loading the product page
* Fixed: properly reading timeout for select_item eventCallback

= 1.22.2 =

* Fixed: purchase event was not fired when is_order_received_page() WooCommerce tag was not supported by the template and the fallback method had to activate.

= 1.22.1 =

* Fixed: yet another fix to the purchase tracking. Thanks [Khnaz35](https://github.com/Khnaz35)

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
