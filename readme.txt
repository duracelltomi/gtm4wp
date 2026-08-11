=== GTM4WP - A Google Tag Manager (GTM) plugin for WordPress ===
Contributors: duracelltomi
Donate link: https://gtm4wp.com/
Tags: google tag manager, tag manager, gtm, google ads, google analytics
Requires at least: 6.3
Requires PHP: 8.0
Tested up to: 7.1
Stable tag: 1.22.5
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

= 2.0.0 =

Major rewrite of the plugin. Please read the announcement post on gtm4wp.com before upgrading!

**Architecture & requirements**

* Changed: complete object-oriented rewrite. Every feature is now a module that third-party plugins can extend through the `gtm4wp_register_modules` action. All public template functions (`gtm4wp_the_gtm_tag()` etc.), filter/action names, wp-config constants and the `gtm4wp-options` storage key are unchanged, so existing integrations keep working.
* Changed: minimum requirements raised to PHP 8.0 and WordPress 6.3. Supported up to WordPress 7.1.
* Updated: frontend scripts now load with the `defer` strategy where possible.
* Changed: the main plugin file now refuses to run when it is requested directly instead of loaded by WordPress, matching every other PHP file in the plugin.

**Settings screen**

* Added: modern React-based settings screen with left pane navigation, tabbed option groups, option search and inline per-field validation. The screen is usable on a phone: the title and the "Export settings" / "Import settings" / "Save changes" buttons stack instead of running off the edge of the screen; the list of settings sections becomes a search field and a dropdown pinned to the top of the screen, so switching section no longer means scrolling past a full screen of section names to reach the settings; a row of option group tabs that is wider than the screen scrolls sideways with each tab on one line, instead of being squeezed into columns of single words with the last tabs unreachable; and the container table turns into one card per container with every column labelled above its own field, instead of six columns crammed into the width of a phone. The selected option group tab is also kept in view now on any screen size, so opening a bookmark or an admin notice link no longer leaves the tab strip pointing at the wrong tab.
* Added: admin notices now link straight to the option they are about. Clicking through opens the module, switches to the tab holding that setting and highlights it, instead of dropping you on the settings screen to work out for yourself that, say, the trusted proxy addresses live under Page variables -> Visitor data.
* Added: the settings screen keeps its address bar up to date, so any module and tab can be bookmarked or sent to a colleague and reopens exactly where you left it. The back button still simply leaves the settings screen; it does not turn into a tab stepper.
* Added: **a help link on every option**. Each settings section now carries a documentation link in its header, and every one of the plugin's 110 options has a "?" icon next to it that opens the part of gtm4wp.com describing that particular setting - what it changes in the data layer, what it is set to before you touch it, and when it is worth changing. Previously the documentation existed but nothing on the settings screen pointed at it, so finding the page for, say, "Only track orders younger than" meant searching the site for it. The links open in a new tab and never change a setting when clicked. Three sections that had no documentation page at all - the Google Tag Manager container settings, AMP and tag restrictions - have one now. Plugins that add their own modules can point their options at their own documentation, either by giving a full URL in their settings schema or through the new `gtm4wp_admin_doc_url` filter.
* Added: **export & import of the plugin settings** (Google Tag Manager settings screen, next to "Save changes"). "Export settings" downloads all of your GTM4WP options as a JSON file; "Import settings" reads such a file back on another site (or the same one). The import is treated as untrusted: every value in the file is run back through the exact same per-field sanitizers as a normal save before anything is stored - unknown keys are dropped, every value is normalized to the field's expected type, oversized files are refused and the file is only ever parsed with `json_decode()` (never `unserialize`/`eval`), so a hand-edited or malicious file cannot inject unsafe data. Both endpoints require the settings capability and a nonce. The value set is schema driven, so every current and future option is covered automatically.

**Google Tag Manager container & tag restrictions**

* Added: every Google Tag Manager container ID now has its own environment parameters (`gtm_auth`/`gtm_preview`), custom domain and custom path, managed in a data table (new `gtm-containers` option). Existing settings are migrated automatically; the flat 1.x option keys are kept in sync for third-party code and downgrades.
* Changed: with environment parameters configured, all containers are loaded now (1.x only loaded the first container in that case). Only the hard-coded wp-config environment constants still limit output to the first container.
* Added: per-container "Omit container ID" option in the container table. When a custom path is set (server side GTM), turning it on drops the container ID from the loader URL (`gtm.js?'+dl` instead of `gtm.js?id='+i+dl`) for setups where the container is selected by its path.
* Added: a kill switch to stop the Google Tag Manager container from loading on a cloned or staging copy of a site without deactivating the plugin. A new **"Only output the container on production environments"** option (Google Tag Manager container → Advanced) emits the container only when WordPress reports the environment type as "production" (set `WP_ENVIRONMENT_TYPE` on your non-production copies). Because `WP_ENVIRONMENT_TYPE` lives in `wp-config.php` or the server config and is invisible from the admin, the option's description reports the environment type WordPress actually returns on *this* site and whether the container would therefore be loaded or suppressed - including the common trap that an unset `WP_ENVIRONMENT_TYPE` falls back to "production", so the option silently keeps loading the container. For host-based control from an mu-plugin or `wp-config.php`, the new `gtm4wp_output_container` filter (default `true`) can veto the container from PHP. In both cases the data layer stays active and only the container `<script>` / `<noscript>` is suppressed - exactly like the "Off" placement. **Off by default** (experimental).
* Changed: a malformed `GTM4WP_HARDCODED_GTM_ENV_AUTH` or `GTM4WP_HARDCODED_GTM_ENV_PREVIEW` value in `wp-config.php` is now rejected instead of being written into every container's loader URL, and the settings screen shows a warning naming the constant that is wrong. Previously only the hard-coded container ID was checked, so a typo in the environment constants was applied as-is and there was nothing anywhere to point at the cause. The accepted formats are the same ones the container table enforces (`gtm_preview` looks like `env-3`). The settings screen also shows what a *valid* hard-coded constant does: the part of the container table it fixes is displayed read-only with the values that are actually running - a single environment column, or the whole table when the constants also decide which containers are loaded - and the field description names the constant you have to edit. Your own stored container setup is kept untouched behind it and is used again as soon as you remove the constants from `wp-config.php`; 1.x showed the hard-coded container ID in a read-only field but wrote that value into your saved options on every save. A rejected constant changes nothing at output time, so it leaves the table editable.
* Fixed: the **dataLayer variable name** option (Google Tag Manager container -> Advanced) accepted names that break every script the plugin writes. The field allowed a hyphen, but JavaScript reads a hyphen as the subtraction operator, so a name like `my-layer` was saved without complaint and then written to the page as `var my-layer = my-layer || [];` - a syntax error that took the whole GTM4WP script block with it. The container itself still loaded, so the setup looked connected on the surface while no data layer content was ever pushed: no page variables, no e-commerce events, no media events. The field now accepts exactly what JavaScript accepts as a variable name (a letter, `_` or `$`, followed by letters, digits, `_` or `$` - and `$` is newly allowed, it was rejected before). A name stored under an older version that does not qualify is ignored in favour of the default `dataLayer` so the container keeps working, and the settings screen names the rejected value so you can correct it.
* Changed: the tag restriction list is written to the data layer under the key names Google documents, `gtm.allowlist` and `gtm.blocklist`. The plugin used the older `gtm.whitelist` / `gtm.blacklist` names, which appear nowhere in Google's current documentation for this feature - there is no statement that they are supported, deprecated, or anything else. Google Tag Manager still reads both pairs (it checks the documented name first and falls back to the old one), so nothing was broken by this on its own; the point is that an undocumented key name would fail *silently* if it were ever dropped, with every tag running unrestricted while the settings screen still showed the restriction in place. **Check your GTM setup if you built a data layer variable, trigger or custom template on `gtm.whitelist` or `gtm.blacklist`** - those keys are no longer written; read `gtm.allowlist` / `gtm.blocklist` instead. The restriction settings themselves are unchanged and need no attention.
* Updated: the tag restriction settings now use Google's current vocabulary. The two restriction modes are called **"Blocklist selected entities"** and **"Allowlist selected entities"** (they were "Blacklist" and "Whitelist"), matching both Google's own documentation and the `gtm.blocklist` / `gtm.allowlist` keys the plugin writes. Your saved mode and your selected entities are untouched - only the labels changed. The restrictable entities are also no longer one long list: they are grouped into collapsible **Tags**, **Triggers**, **Variables** and **Entity groups** sections, each showing how many of its entries you have selected, and the tab holding them is now called "Entities" - it was called "Tags", although it always held the triggers and variables too.
* Fixed: turning on the tag restriction list in **blocklist mode** (called "blacklist" before this version) blocked every tag, trigger and variable in your container instead of only the ones you selected. The plugin wrote both restriction keys on every page and left the unselected one as an empty list - and an empty *allowlist* is not "no allowlist" to Google Tag Manager, it is an allowlist that permits nothing, so everything was blocked. The failure is silent and looks like the restriction simply working very aggressively, which is why it went unnoticed: nothing errors, and the setting warns that it can affect your tag deployment anyway. Allowlist mode was not affected. Only the key for the mode you selected is written now. **If you turned tag restrictions on in blocklist mode and your tags stopped firing, this was why** - the restriction list itself was always correct and needs no changes.
* Updated: tag restriction entity list refreshed from Google's restriction documentation (added the Google tag / GA4 tags and the Google Analytics Settings variable, removed Universal Analytics). Mouseflow stays restrictable - Google still documents it.
* Added: the tag restriction list can now restrict sandboxed scripts (custom tag/variable templates) through Google Tag Manager's `sandboxedScripts` group class. 1.x had a "Custom tag/variable templates" checkbox for this, but it was never emitted to the container, so it had no effect; it is now a proper, working entry in the restriction list.
* Fixed: the **"User roles to exclude"** option (Google Tag Manager container → Advanced) now leaves out the whole container code for an excluded user role, not only half of it. It removed the container `<script>` from the page head, but the `<noscript>` iframe placed after the opening body tag was still written out - and that iframe is precisely what loads the container when JavaScript does not run, so an excluded user was still counted on such a request (a prefetcher, a crawler or a browser with scripting disabled). The `<noscript>` part is now suppressed together with the `<script>` part, and the browser console warning that explains why the container is missing is shown in both places. The data layer stays active for excluded users exactly as before.
* Fixed: the browser console warnings that explain why the container code is missing are no longer broken JavaScript. When the container is suppressed - placement set to "Off", the production-only kill switch, or an excluded user role - GTM4WP writes a short `console.warn` note into the page saying so. In the `<noscript>` part of the container code that note was passed through WordPress' HTML sanitizer without undoing its ampersand encoding, so `&&` arrived in the browser as `&amp;&amp;` and the whole warning block was a syntax error: no explanation appeared, and a JavaScript error was reported instead. The warnings now run as intended, and the container's `<noscript>` iframe URL keeps its correct `&amp;`-encoded form.
* Fixed: the red "To start using Google Tag Manager for WordPress, please enter your GTM ID" notice is no longer shown when **Container code placement** (Google Tag Manager container → General) is set to "Off". That placement means data layer only - the container code is deliberately left out and the container is loaded by your own code - so there is no container ID to enter, and nothing you could change on the settings screen would clear the notice. Dismissing it was the only way out, and that silenced it for the one admin user who clicked, on that browser. For every other placement the notice is unchanged, because a missing container ID there really does mean nothing is being tracked.
* Fixed: the `type="text/javascript"` attribute is no longer stripped from the plugin's `<head>` script block on themes that do not declare HTML5 support. That block is sanitized against an attribute allow-list which did not list `type`, so on such a theme the attribute the plugin had just added was removed again on its way to the page. The container's own `<script>` tags were never affected.
* Changed: the CSS permission the container's hidden `<noscript>` iframe needs from WordPress' HTML sanitizer is now applied only while GTM4WP sanitizes its own container markup, instead of for the whole page load. The container code that reaches the page is byte-for-byte unchanged. Everywhere else, WordPress' own inline-style rules apply - so if a `display` or `visibility` declaration somewhere in your content used to survive on this site, set it from your theme's stylesheet instead.
* Fixed: a single value that PHP cannot convert to JSON no longer takes the whole script block with it. The data layer, the purchase event, the additional data layer pushes, the checkout totals, the duplicate purchase guard and the settings screen each wrote a value into a script by pasting the conversion result straight in, and when that conversion fails PHP substitutes an empty string - which leaves behind a line such as `var dataLayer_content = ;` that a browser refuses to parse. The result was that the entire block failed, taking the data layer, the container snippet inside it and everything after it down, rather than losing the one value. Conversion fails for a handful of values a plugin can put into the data layer through GTM4WP's filters, among them the numeric results of a division by zero and anything nested more deeply than PHP's limit. Now the affected value is left out and the rest of the block is emitted normally; a purchase whose event could not be written is no longer marked as tracked either, so the next page view tries again instead of losing the order.
* Fixed: numeric-looking text values are no longer converted into numbers in the data layer. The main data layer JSON was encoded with PHP's `JSON_NUMERIC_CHECK` flag, which coerced every numeric-looking string anywhere in the structure into a JSON number: a SKU like `000035180` lost its leading zeros in `cartContent` while the same product inside `ecommerce.items` (built on a path without the flag) kept the correct string, order numbers, postcodes and phone numbers changed type, and custom values added through the `gtm4wp_compile_datalayer` filter were altered too - as reported on the wordpress.org support forum. Identifier-like values now always reach Google Tag Manager as unmodified strings, while genuinely numeric values (prices, cart/order totals, quantities, counts, ids - including the Unix-timestamp page variables `pagePostDateUnix`/`pageModifiedDateUnix`, the post counts and the author ids) are typed as real numbers at their source, so GA4 and Meta still receive numeric `price`/`value`. **Check your GTM setup if it compares one of these against a number:** `orderData.attributes.order_number`, numeric SKUs in `item_id`/`sku`, numeric-looking postcodes/phone numbers and zero-padded date parts (`pagePostDateMonth` is now `"07"`, not `7`) arrive as strings after this fix. GTM's "greater/less than" trigger comparisons keep working (they compare numerically), but an "equals" comparison against the unpadded number (e.g. `7`) must be updated to the padded string (`07`).

**Cache-safe data layer**

* Added: a new experimental **"Cache-safe data layer"** setting (issue #398). On sites with full-page caching (LiteSpeed, WP Rocket, Varnish, Cloudflare APO) the HTML built for one visitor is served to everyone, so any visitor-specific value baked into the data layer would leak - the classic case being a logged-in editor's page cached with their email/username/role and then served to anonymous visitors. With this on, **no** visitor or session data is rendered into cacheable HTML at all; every such value is instead delivered client-side under the **same** data layer variable names, so your Google Tag Manager tags keep working. **Off by default** (experimental); when off, the data layer is exactly as before. The rest of this group describes how it works when you turn it on.
* The values the browser can compute itself - the search term and the referring page (`siteSearchTerm`, `siteSearchFrom`) - are pushed client-side directly, which also removes their reflected-XSS surface.
* The visitor IP, Cloudflare country and logged-in-user data (login state, role, email + hash, registration date, username, id) come from a new first-party session endpoint that returns **only the current request's own data** (it takes no user/session id, so one visitor can never request another's) with no-cache headers. The IP/country are fetched once per session and cached in the browser, the user data only when the login state changed, so an anonymous visitor on a cached page never fetches and never receives user data.
* The WooCommerce customer and cart blocks ride the cart-fragments response WooCommerce already refreshes on every cart change, so GTM4WP makes no request of its own for them. It does make sure that response actually happens: WooCommerce only loads its cart-refresh script for the classic "Cart" widget, and not even there on the cart and checkout pages (the block Mini-Cart never loads it), so on most stores the customer and cart variables would simply never arrive on a normal page view - GTM4WP therefore loads that script itself while this mode is on. **On a store that was not loading it before, this means one extra WooCommerce cart-refresh request per browser tab** (per tab, because that is how WooCommerce caches the response; an already-loaded tab makes none on later page views, and a visitor who has blocked browser storage makes one per page view). That request is only made for a visitor who already has a cart or is logged in. WooCommerce's cart-refresh script has no empty-cart shortcut of its own, so without that condition a visitor who had never touched the shop would pay the request to be told their cart is empty. Nothing arrives late because of it: the moment they do add something, WooCommerce's own add-to-cart response carries the data in. Nothing changes on a store that already shows a mini-cart anywhere, which is the majority.
* Because these values now arrive **after** the page view instead of inside it, point the tags that read them at a Custom Event trigger. There is one event per group of variables, so the event name alone tells you what arrived and no trigger condition is needed: **`gtm4wp.visitorData`** carries the visitor and logged-in-user variables (`siteSearchTerm`, `siteSearchFrom`, `visitorIP`, `geoCloudflareCountryCode`, `visitorLoginState`, `visitorType`, `visitorEmail`, `visitorEmailHash`, `visitorRegistrationDate`, `visitorUsername`, `visitorId`), **`gtm4wp.customerData`** the WooCommerce `customer*` variables and **`gtm4wp.cartData`** the `cartContent` variable. If you need to react to more than one, use a "Some Custom Events" trigger matching `gtm4wp\.(visitor|customer|cart)Data`.
* Each of the three fires only when **its own** data changed and only when the option that produces it is enabled, so changing a cart quantity fires `gtm4wp.cartData` alone and editing a billing field on the checkout fires `gtm4wp.customerData` alone. Two things to read correctly: an **empty cart is still sent** (`cartContent` with an empty item list - that is how you see the cart being emptied), so a missing `gtm4wp.cartData` means the cart content option is off or the cart has not been read yet, not that the cart is empty; and `gtm4wp.visitorData` is skipped entirely on a page where none of its variables apply, so a tag firing on the two WooCommerce events must not assume a `visitor*` variable is already set. When `gtm4wp.visitorData` does fire it always comes first, so reading its variables from a later event does work.
* The two WooCommerce one-shot events - the `add_to_cart` fired when a product is restored to the cart (the cart "Undo") and the reliable-purchase **fallback** that recovers a `purchase` when the order-received page was missed (a custom/redirect thank-you page) - must fire exactly once, so they are delivered differently: when one is queued GTM4WP sets a short-lived event cookie, and only then does the browser fetch it (resolving the order/re-add from *this* session, never from a URL parameter), fire the event once and clear the cookie. An anonymous visitor on a cached page, who never has the cookie, never fetches.
* The purchase fallback reuses the existing de-duplication - the same `gtm4wp_orderid_tracked` browser guard (keyed on the order number) the order-received page writes, plus the server `_ga_tracked` order flag, which the browser sets with a single authenticated `POST` beacon after a fallback delivery (the order id taken only from the buyer's own session, never the request body). A fallback fire and a real order-received purchase for the same order can therefore never both count, on the same device or across devices; the re-add is de-duped on a per-event token.
* The "Do not flag orders as being tracked" option is honoured end to end (no marker, no beacon, no flag), and all request-header values and order numbers round-trip hex-encoded so a hostile value can never break out.
* The confirmation beacon only accepts a request that came from a page on your own site: it verifies both a WordPress REST nonce and the request origin. The nonce on its own would not be enough, because it is the same value for every logged-out visitor and so cannot tell your checkout page apart from a third-party one; the origin is the part a foreign page cannot fake. It is read only from the request's own headers, never from a parameter the caller could add to the URL.
* None of the plugin's REST routes can be read from another website. WordPress by default tells any site that asks that it may read a REST response using the visitor's own cookies; GTM4WP withdraws that permission for its own routes only - other plugins are untouched, and your own pages are unaffected. This covers every GTM4WP REST route, including the settings routes, whether or not the cache-safe data layer is switched on, and it holds however the route address is capitalised, because WordPress answers a REST route regardless of its letter case.

**Page variables**

* Added: new Page variables options in a "Content & engagement data" group, useful for behavior tracking and GA4 content grouping - Content word count (`pageContentWordCount`) and estimated reading time (`pageReadingTime`, adjustable with the `gtm4wp_reading_time_wpm` filter), counted correctly in every language including Cyrillic, Greek, Hebrew, Arabic and the space-less scripts Chinese, Japanese and Korean; Last modified date (`pageModifiedDate` and the `pageModifiedDate*` family) and content age in days (`pageContentAgeDays`); Comment count and status (`pageCommentCount`, `pageCommentStatus`); Page template (`pageTemplate`), featured image presence (`pageHasFeaturedImage`), page hierarchy (`pageParentID`, `pageDepth`) and sticky flag (`pagePostSticky`); Primary category (`pagePrimaryCategory`, `pagePrimaryCategoryName`) detected from Yoast SEO / Rank Math with a first-category fallback, overridable with the `gtm4wp_primary_category_term_id` filter; Page language (`pageLanguage`) detected from WPML / Polylang with a site-locale fallback, overridable with the `gtm4wp_page_language` filter.
* Added: **PublishPress Authors support** for the Page variables author data. On sites that use PublishPress Authors, a post can have several authors (co-authors and guest authors) - important for E-E-A-T. When PublishPress Authors is active, `pagePostAuthor` / `pagePostAuthorID` are sourced from it - including for a post with a single **guest** author, which would otherwise report the WordPress user who created the post - and when such a post has more than one author, GTM4WP also outputs `pagePostAuthors` (the list of author names) and `pagePostAuthorIDs` (the list of author IDs; guest authors use a negative id). The single-value variables stay for back-compat and point at the primary (first) author. The two arrays are filterable via `gtm4wp_page_post_authors` and `gtm4wp_page_post_author_ids`. Uses the existing "Post author name" / "Post author ID" options as the on/off switches; when PublishPress Authors is not active, behavior is unchanged.
* Added: a separate **"Post custom fields (meta)"** option (Page variables → Post data). Until now the "Post Terms" option did two very different things: it added the post's taxonomy values *and* published every custom field whose name does not start with an underscore - together with its value - into the data layer of the public page, even though the option only ever mentioned taxonomies. Since custom fields are where plugins and themes keep their own data (Advanced Custom Fields stores its values this way), that could put internal notes, ids, prices or contact details on a page any visitor can read, without the site owner ever being told. The two are now separate opt-ins with the custom-field one spelling out exactly what it publishes. **Nothing changes for an existing site:** if you had "Post Terms" enabled, the new option is turned on for you during the upgrade, so `pagePostTerms` (including `pagePostTerms.meta`) keeps exactly the shape and content it had. Turn the new option off if you did not intend to publish your custom fields; individual keys can still be excluded with the `gtm4wp_post_meta_in_datalayer` filter.
* Added: an optional **"Include parent categories in the category list"** setting (Page variables → Post data). By default the `pageCategory` data layer variable lists only the categories directly assigned to the current post or archive. With this on, the parent (ancestor) categories of each category are also added - immediate parent first, up to the top-level category - and the list is de-duplicated. The option is greyed out in the settings screen while its parent option, "Category list of current post/archive", is turned off (any option can now declare such a dependency). **Off by default**, so the current output is unchanged until you enable it. Thanks to @twentyfortysix for the original patch (#220).
* Changed: browser, OS and device data is now collected in the browser using User-Agent Client Hints and pushed as a `gtm4wp.deviceData` event (replaces the bundled WhichBrowser library; Safari and Firefox expose less detail).
* Fixed: repeated PHP warnings (`Attempt to read property "post_author" on null`) on sites where a theme or plugin leaves the global post object unset on a singular page. Every post-derived page variable (post type, category/tag lists, author data, dates, term list, word count and reading time, content age, comment data, page template, featured image flag, page hierarchy, sticky flag, primary category, post id and post format) is now simply omitted on such a request instead of being emitted with a placeholder value, and the `postCountOnPage`/`postCountTotal` variables are likewise omitted when the main query global is unavailable. The same now applies on an author archive: `pagePostAuthor`/`pagePostAuthorID` are omitted when the author object is not set up, instead of being sent as an empty name and the author id `0`. As reported on the wordpress.org support forum against 1.22.3.
* Fixed: JavaScript variables added through the `gtm4wp_add_global_vars_array` filter now keep their type **and their text**. A `null` value was rendered as `false`, an empty array as `false` and the float `0.0` as `false`, so a tag reading such a variable saw the wrong value. Separately, a **string** value was escaped for an HTML attribute rather than for a script, so a `"`, `<` or `>` in it reached your tags as the text `&quot;`, `&lt;` or `&gt;` - while the very same string inside an array arrived correctly. Both now use identical encoding, so a value is delivered the same way however it is supplied. A value the encoder cannot represent at all (text that is not valid UTF-8) now falls back to `null` instead of producing a broken declaration that made the whole head script block a syntax error - taking the data layer initialization down with it. The variable *name* was already checked this way; the value now is too.
* Added: a "Visitor IP - Trusted proxy addresses" setting, next to the existing custom-header option. An HTTP header is sent by the visitor, so on its own it is a claim rather than a fact, and with no list of trusted upstreams the plugin has no way to tell your infrastructure's entry in that header apart from the visitor's. List the addresses or CIDR ranges of your reverse proxy, load balancer or CDN there and it can - an X-Forwarded-For list is then read from the right, skipping your own hops, and a single-value header such as CF-Connecting-IP is only used when the request really did arrive through one of those addresses. **Nothing changes for an existing site until you fill it in:** the header is read exactly as before, and an admin notice points out that the value cannot be verified yet. If your site is not behind a proxy or CDN, leave the field empty and do not set a custom header at all. A range that covers every address (0.0.0.0/0 or ::/0) is rejected rather than stored, because it would declare the whole internet a trusted proxy - which is the situation the setting exists to end, and it would also silence the notice that warns you about it. Both this field and the custom-header field are greyed out while the "Visitor IP" variable itself is off.
* Fixed: option descriptions on the settings screen (Page variables) that did not match what the option actually sends. Building a Google Tag Manager variable needs three things - the subject, the **name** of the data layer variable it lands in, and the **form** the values take - and several descriptions gave only the first. "Category list of current post/archive" said it sends the category *names*, while `pageCategory` has always contained the category **slugs** (`news-and-events`, not `News and Events`), so a trigger written against the description could never match; "Tags of current post" named neither its variable (`pageAttributes`, not `pageTags`) nor the slug form; "Post date" said it adds 4 data layer variables, while it has added 9 since 1.15. Now corrected in the same way: "Post Format" (`postFormat`, the format slug, `standard` when none), "Primary category" (**two** variables - `pagePrimaryCategory` holds the slug and `pagePrimaryCategoryName` the display name), "Post author ID" and "Post author name" (`pagePostAuthorID`/`pagePostAuthor`, plus the `pagePostAuthorIDs`/`pagePostAuthors` lists that appear on a multi-author post), "Logged in user role" (`visitorType`, role slugs, comma separated for several roles, and `visitor-logged-out` for a visitor who is not logged in) and "Logged in status" (`visitorLoginState`, either `logged-in` or `logged-out`). All descriptions now match what is sent; no data layer value changed.
* Fixed: the `postFormat` data layer variable now contains the actual post format (`aside`, `gallery`, `video`, ...) of the current post. Since its introduction it sent an empty string for every post that *had* a format and `standard` otherwise, so it could never tell formats apart; posts without a format still report `standard`.

**WooCommerce - Cart & Checkout blocks**

* Added: WooCommerce Cart & Checkout **block** support. The React-based Cart & Checkout blocks (now the WooCommerce default) never fire the classic jQuery events, so `add_to_cart`, `remove_from_cart`, `add_shipping_info` and `add_payment_info` were previously lost on block-based stores. GTM4WP now exposes its GA4 item data on the WooCommerce Store API (`extensions.gtm4wp.item`) and reads the `wc/store/cart`, `wc/store/checkout` and `wc/store/payment` data stores to fire those events. On block Cart/Checkout pages the block tracker loads and the classic tracker is skipped, so nothing is counted twice; `view_cart`, `begin_checkout` and `purchase` continue to fire server-side. Because the item price comes from the server as a real number, values stay correct for zero-decimal (e.g. JPY) currencies.
* Added: block tracking now also covers the **Mini-Cart**, **Product Collection** and **cart cross-sells** blocks. Removing an item (or changing its quantity) in the Mini-Cart drawer now fires `remove_from_cart` - on a block-based store the block tracker rides along on every page in a "mini-cart" mode that reports removals only, so the classic tracker keeps sole ownership of `add_to_cart` and nothing is counted twice. The Product Collection grid (the current WooCommerce default, which fires none of the classic product-loop hooks) now reports `view_item_list` and `select_item`, with a friendly list name for every collection WooCommerce ships (Product catalog, Sale, Best selling, Top rated, New, Featured, Related, Upsells, Cross-sells, Hand-picked, By category, By tag, By brand and Cart contents). The four that replace a legacy product grid block report the same list as that block did, so moving a page over to Product Collection does not start a new list in your reports. The Cart block's cross-sell products now report `view_item_list` and `select_item` too, and like every other tracking path their GA4 items carry none of the plugin's internal bookkeeping keys.

**WooCommerce - Purchase tracking**

* Added: new "Reliable purchase tracking" option (WooCommerce → Purchase tracking) for the most common cause of a missing `purchase` event. When the customer lands on a heavily customized thank-you page, or on the order-pay page instead of the order received page, the purchase event is now emitted on the next page they view in the same browser session. The placed order is remembered server-side (on `woocommerce_payment_complete`, the order-status change and `woocommerce_thankyou`) and de-duplicated with the existing order-tracked flag, browser cookie and order-age guards, so it is never counted twice. Off by default (experimental). It cannot capture orders where the buyer pays via an asynchronous gateway and never returns to the site - that case needs server-side tracking.
* Added: new "Order statuses that trigger the purchase event" setting. The purchase now fires at order *placement* for the configured statuses (default: Processing, On hold, Completed), so Cash on Delivery (Processing) and bank transfer (On hold) orders are tracked at checkout even though payment clears later. Filterable via the new `gtm4wp_purchase_trackable_statuses` filter.
* Added: new "Custom order received (thank-you) page" option that fires the purchase event on a bespoke confirmation page - resolving the order from the current session - for themes or plugins that do not use the standard WooCommerce order received endpoint.
* Added: GA4 / Google Ads Enhanced Conversions user data is now included on the `purchase` event - SHA-256 hashed email, phone and name plus the plaintext address components Google expects - built from the order so guest checkouts are covered too. The phone number is converted to E.164 against the order's billing country before hashing, as Google requires, and is left out entirely when it cannot be placed in E.164 with confidence rather than sent as a hash that could never match. Opt-in through the existing "Customer data in data layer" option.
* Added: the `purchase` event now also carries `customer_type`, the `new` / `returning` string that Google's GA4 e-commerce reference documents. The existing `new_customer` boolean is unchanged and still sent - Google names the same idea differently on two surfaces (Google Ads customer acquisition reads `new_customer`, GA4 reads `customer_type`), so both are needed and neither replaces the other. Nothing to change in your GTM setup unless you want to use the new variable.
* Added: new "Transaction ID prefix" option (WooCommerce → Purchase tracking). The text entered here is prepended to the `transaction_id` sent with the `purchase` event, for example to tell several stores apart in one GA4 property or to match the order id format of another system. Empty by default, so the plain WooCommerce order number is sent exactly as before. Only the `purchase` event is affected: the order number in the `orderData` variable and the duplicate tracking guards of the plugin keep using the raw order number.
* Changed: the purchase event is no longer sent for failed or still-pending orders by default (previously any order except a failed one that reached the thank-you page was tracked). Add the relevant status to the new "Order statuses that trigger the purchase event" setting if you need the old behavior.
* Fixed: the "Do not flag orders as being tracked" WooCommerce option now also skips the browser-side duplicate guard. Previously it only disabled the server `_ga_tracked` order meta, while the `gtm4wp_orderid_tracked` cookie / localStorage entry was still written - so the same order could not be re-tested in the same browser without clearing storage. With the option on, no order-tracked state is written anywhere.

**WooCommerce - Item data & extensibility filters**

* Added: every tracked product list item now carries an `item_list_id` next to `item_list_name`, so GA4 list reports can key on a stable id. Each list reports its own id - including WooCommerce's product grid blocks (Handpicked Products, Newest Products, On Sale and the rest), where every grid on a page is a separate list rather than all of them sharing one. For the lists the plugin names itself the id is a fixed identifier that does not change with the site language, so a multilingual store reports one list instead of one per translation; a list named after a widget title still gets its id from that title. Third-party code can supply its own id via the `GTM4WP_WPFILTER_EEC_PRODUCT_ARRAY` filter.
* Added: new opt-in WooCommerce option **"Persist product list attribution across the funnel"** (WooCommerce → Product data). When a visitor clicks a product in a list - a classic product loop, a product grid or Product Collection block, or the Cart block's cross-sells - the plugin remembers which list it was (`item_list_name` / `item_list_id`) in a first-party cookie and carries that attribution onto the later `view_item`, `add_to_cart`, `view_cart`, `begin_checkout`, `add_shipping_info`, `add_payment_info` and `purchase` events, so GA4 can attribute the whole funnel back to the originating list. **Off by default** - enable it only if you are NOT already doing the same with custom JavaScript in Google Tag Manager, otherwise the attribution would be set twice. The product page keeps working behind a full-page cache: the `view_item` event is still rendered by the server, but the list name is filled in by the browser, so the cached HTML stays the same for every visitor. This covers the simple-product and variable-parent `view_item`, a variation selected on the product page, and WooCommerce Quick View. The cookie holds up to the 20 most recently seen products and evicts the least recently seen one when it is full - and it stays within the size a browser accepts for a single cookie, so a store whose list names are long simply remembers fewer products instead of losing the attribution altogether.
* Added: GA4 e-commerce items now carry a per-item `discount` wherever it can be computed - cart, `view_cart`, `begin_checkout` and `purchase` items report the per-unit discount whenever a coupon or sale reduced the line (on the same tax basis as the item price). Undiscounted lines carry no `discount` key. Per-item coupon codes are still not emitted (WooCommerce does not map coupons to individual lines); attach them via the `gtm4wp_eec_product_array` / `gtm4wp_eec_order_item` filters if you need them.
* Added: the `view_item` event now sends an explicit `quantity` of 1 on its item, for GA4-spec completeness (both the simple-product server event and the variable-product client event).
* Added: a new `gtm4wp_eec_item_affiliation` filter to set the GA4 item-level `affiliation` (the storefront/marketplace an item was sold through). It is empty by default - WooCommerce has no native value - so the item payload stays free of empty `affiliation` strings unless you supply one.
* Added: a new `gtm4wp_eec_item_with_source` filter for enriching a GA4 e-commerce item with custom data taken from the raw source object it was built from - the WooCommerce cart item (on the cart, mini-cart, checkout, re-add and Cart/Checkout-block paths) or the `WC_Order_Item` (on the purchase path), both of which can carry custom meta that never lives on the `WC_Product`/variation. Alongside the usual item array and placement context, the filter receives a new third argument: that source object (or `null` where there is no per-line source, e.g. a product-detail page or a product list). Read the meta you need and copy only those fields onto the item - the source object itself is never merged into the item array, so your GA4 events are not bloated with data you did not ask for. Thanks to @migueldamota for the request (#324).
* Changed: the `gtm4wp_eec_product_array` filter is now deprecated in favor of the new `gtm4wp_eec_item_with_source` filter (which additionally receives the source cart/order item). It keeps working unchanged - it still receives the same two arguments and still runs *before* the new filter, so both filters can modify the item - and only raises a deprecation notice when `WP_DEBUG` is enabled, once per request rather than once per product. Existing integrations need no change.
* Changed: server-pushed e-commerce events now serialize the `event` key before the `ecommerce` object, matching what the client-side events already emit. Key order is irrelevant to Google Tag Manager / GA4; this is a consistency-only change with no effect on tracking.

**WooCommerce - Tracking behavior & compatibility**

* Added: new opt-in **CheckoutWC compatibility** option (WooCommerce → Advanced). CheckoutWC replaces the WooCommerce checkout with its own multi-step template, so the classic markers the tracker relies on are not reliably present and the `add_shipping_info` / `add_payment_info` events were missed. With this on, the tracker also binds those steps to CheckoutWC's own `cfw_step_changed` event (the step is deduplicated with the classic checkout, so nothing is counted twice). **Off by default** (experimental) - enable it only on stores that use CheckoutWC.
* Added: the WooCommerce add-to-cart tracking logic is now exposed as reusable JavaScript functions - `window.gtm4wp_track_single_add_to_cart( button, form )` (product-detail page: variable, grouped and simple products) and `window.gtm4wp_track_list_add_to_cart( button )` (product lists and the `[add_to_cart]` shortcode). A theme that handles Add to Cart with its own AJAX - and calls `e.preventDefault()` on the button, which suppresses the built-in click tracking - can now fire the event from its success handler in a couple of lines instead of copying the tracker.
* Added: the WooCommerce e-commerce tracker logs every event it pushes to the browser console, honoring the site-wide "Do not use console.log() messages on the frontend" option (nothing is logged when that option is turned on).
* Changed: clarified the "Set maximum timeout for select_item event" option (WooCommerce → Advanced) so it explains that a value of 0 opens product links immediately without waiting for GTM - the select_item event is still sent, but the click is no longer delayed. Useful when product-list links feel slow to open (e.g. a consent tool blocks GTM so the callback never returns).

**WooCommerce - Fixes**

* Fixed: the browser-side duplicate-purchase guard now works on stores whose order numbers are not the plain order id (sequential or prefixed order numbers). The guard cookie holds the order **number**, but the server read it as an integer and compared it to the order **id**, so on those stores that leg of the de-duplication never matched. The same order number is now used consistently everywhere the guard is written or read.
* Fixed: cart and checkout pages no longer risk a PHP memory exhaustion under some WooCommerce versions. `wc_get_price_to_display()` was called once per cart item (and again for each item's remove-link data), which became very expensive on certain WooCommerce releases. Cart, mini-cart and checkout item prices are now taken from the line totals WooCommerce has already calculated, and the display-price call is skipped whenever a price is already supplied (the purchase path passed one that was then discarded).
* Fixed: when "Exclude tax from revenue" is enabled, per-item prices on the `purchase` event are now also reported excluding tax. Previously the transaction `value` was tax-exclusive while the item prices followed the shop's display setting (often tax-inclusive), so GA4 item-level revenue (product performance) did not reconcile with the transaction total (sales performance).
* Fixed: variable **subscription** products now keep their variant data in ecommerce tracking. Variations were detected by an exact `variation` product-type match, but WooCommerce Subscriptions variations report `subscription_variation`, so their `item_variant`, `item_group_id` and parent-derived `item_category`/`item_brand` were dropped (most visibly on the `purchase` event). Variations are now detected structurally (any `WC_Product_Variation`), covering subscriptions and similar extensions.
* Fixed: the dynamic-remarketing "Product ID prefix" is now kept on variations. When a variation was selected on a variable product page, the browser swapped in the variation id and dropped the configured prefix from the `id` field used for Google/Meta catalog matching; the prefix is now re-applied to the variation's `id` (the unprefixed `item_id` is unchanged).
* Fixed: the `[add_to_cart]` shortcode button now fires an `add_to_cart` event. A standalone shortcode button is rendered outside a product loop, so it never received the hidden product-data markup that product-list items get; the GA4 item data is now attached to the button itself so a click can be tracked. Product lists are unaffected — they already carry the data.
* Fixed: the product-page `add_to_cart` event is no longer fired when the browser blocks the add-to-cart form submit because a required field is empty (e.g. a required Product Add-ons field). The click now respects the form's HTML5 validity, so a rejected add no longer produces a false `add_to_cart`.
* Fixed: add_to_cart and remove_from_cart now always report quantity as a number, and report it the same way on every surface. Three symptoms of one cause: a product form with no quantity field at all - some themes and product add-on plugins render none - emitted quantity: null and value: 0; the cart page reported a string where the mini-cart reported a number for the very same product; and a cart line set to zero fired a removal event on the cart page while the mini-cart correctly suppressed it. Every quantity now goes through one parser, so the type and the zero handling are identical everywhere. **Check your GTM setup if a trigger or variable compares quantity against a string.**
* Fixed: the WooCommerce Quick View popup now pushes its product data into the data layer variable you configured. It was the one place in the plugin still using the default dataLayer name directly, so on a site that renamed the data layer the Quick View event was silently dropped - or pushed into whatever other tool owns that name on the page.
* Fixed: the **"Set maximum timeout for select_item event"** option (WooCommerce → Advanced) now actually takes effect. The value reached the page correctly, but the tracker looked it up under the wrong name and never found it, so every product-list click used the built-in 2000 ms wait for Google Tag Manager to call back, whatever you had configured. Most visibly, setting it to **0** to stop product links feeling slow to open - the documented remedy when a consent tool blocks GTM so the callback never returns - changed nothing at all. Stores that left the setting at its default of 2000 behave exactly as before.
* Fixed: the WooCommerce and form-interaction trackers no longer fire their events twice when their script is loaded a second time on the same page - by an AJAX navigation, or by a page builder that duplicates the script handle. Both re-registered their event listeners, and the WooCommerce tracker additionally reset its own "already reported" memory, which let add_payment_info and add_shipping_info fire again on checkout even once the listeners were under control. The media and Contact Form 7 trackers already had this protection; every tracker now has it.
* Fixed: Gmail addresses carrying a + tag now hash to the address Google actually matches. Google folds jane.doe+shopping@gmail.com down to janedoe@gmail.com before hashing, but the plugin removed only the dots, so every customer who used a tagged gmail.com or googlemail.com address produced a hash that could never match - in orderData.customer.billing.email_hash, in customerBillingEmailHash and in the enhanced-conversions user data. The + and everything after it is now dropped, for those two domains only: anywhere else a tagged address is a real, separate mailbox and is left exactly as the customer typed it. The domain test is stricter too, so an address at a domain that merely begins with gmail.com is no longer folded. **Check your GTM setup if it reads any of the email hash fields:** the value changes for tagged Google addresses.
* Fixed: the hashed phone number in orderData.customer.billing.phone_hash is now converted to E.164 (+36201234567) before hashing, which is the format Google matches on. Previously the number was only lowercased and stripped of spaces, so anything a customer typed the way they dial it - 06 20 123 4567, (201) 555-0123 - produced a hash Google could never match, and the enhanced conversion was simply left unattributed with no error shown anywhere. The dialling rules of the order's billing country come from a table generated from Google's own numbering plan data covering all 245 territories, so a leading zero is removed only where that country really dials one: Italian landlines keep theirs, as do six other territories where the zero is part of the number rather than a dialling prefix. Numbers written with the trunk digit in brackets (+49 (0) 30 12345678, the usual form in the German speaking countries and the Netherlands) and numbers carrying an extension (0121 234 5678 x22) are handled as well, and numbers from Jersey, Guernsey and the Isle of Man are no longer discarded outright. When a number still cannot be placed in E.164 with confidence - no country on the order, or too few digits to be a real number - the key is sent empty rather than carrying an unmatchable hash. **Check your GTM setup if it reads phone_hash:** the value changes for every customer who did not type their number in international format.

**Media events**

* Added: media player tracking for eight more players, each as its own opt-in option under Media events → Media players (experimental): Dailymotion, Mixcloud, Cloudflare Stream, Wistia, JW Player, VideoPress, Spotify and Twitch. Each fires the same `gtm4wp.media*` events (ready, state change, playback percentage, player event) as the existing players and also populates Google Tag Manager's built-in Video variables. Notes: Dailymotion tracking rebuilds each embed with Dailymotion's current player, because Dailymotion no longer lets a script listen to a video it did not create itself. The video keeps the size WordPress gave it, and its events report the real video title and channel name; if the player cannot be built for any reason, whether the library fails to load or the player itself refuses to start, the original embed is put back exactly as it was and simply goes untracked, so a tracking problem never costs you the video. An optional **"Dailymotion player ID"** setting picks which player configuration is loaded, taken from the Players tab of a Dailymotion Studio account, and removes Dailymotion's "initialized without a player id" console warning. Leave it empty to use Dailymotion's default player, which needs no account. A periodic playback update is the only signal the Spotify embed exposes, so play/pause/finished states are derived from it, and because the player reports no title either, the track title is read from the embed on the page - when the embed carries none, it is looked up once from Spotify's public oEmbed endpoint. The Cloudflare Stream player reports no title either, so its events take the title from the embed when it carries one, and otherwise report the video's Cloudflare UID. VideoPress reports the playback position and the video duration on separate browser messages, and sends neither one alongside a play, pause or finished signal, so the tracker keeps the last value it was given for each video and fills in every event from those; its player-ready event consequently fires when the duration first arrives, once per video, and a fullscreen change is reported as a player event. Twitch reports current time and duration for videos (VODs) only, not live streams.
* Added: tracking for native HTML5 `<video>` and `<audio>` players, as its own opt-in option under Media events → Media players (experimental). Fires the same `gtm4wp.media*` events as the other players - ready (carrying the real media duration), state changes, and playback percentage - and also tracks buffering plus Picture-in-Picture and fullscreen changes on video. Each event also populates Google Tag Manager's built-in Video variables. A local media file carries no title of its own, so the events identify it by its file name, taken without any query string the URL carries: a `?ver=` cache buster or a signed CDN token would otherwise make the same file a different video in your reports every time it changes. The full URL, parameters included, is reported separately.
* Added: an optional **"Track dynamically inserted players"** setting (Media events → Advanced). The media trackers wire up players that are on the page when it loads; with this on they also track players inserted *after* load - opened in a popup/lightbox or loaded via AJAX - which previously went untracked. It works by watching the page for new embeds with a single shared `MutationObserver` (one for all providers, scanning only the nodes each change adds, not the whole document), then running the exact same wiring a player gets at page load; a player whose SDK replaces the embed element with its own iframe (e.g. Spotify) is recognised as already wired, so each player is wired exactly once. **Off by default** (experimental): the observer has a small per-DOM-change cost, so enable it only if your site injects media players at runtime. The Wistia tracker already handled runtime insertion natively and is unchanged. Thanks for the request (#3).
* Added: every media event now fills all eight of Google Tag Manager's built-in **Video variables**, the **Video Visible** one included. This covers all four `gtm4wp.media*` events - not only the state change and playback percentage, which are the two with a Google Tag Manager counterpart, but the player-ready and player-event pushes as well. Those two report an empty **Video Status**, because Google Tag Manager has no status for them; every other Video variable is filled as usual, so a Custom Event trigger on any media event can read the video's title, URL, duration, position and percentage. (The one exception is `gtm4wp.mediaApiReady`, which fires when the player's script finishes loading, before any player exists to describe.) **Video Visible** reports whether the player was actually on screen at the moment of the event: a player scrolled out of view reports `false`, and so does one playing in a background tab or a minimised window, while a player even partly on screen reports `true`. Google publishes no minimum visible percentage for this variable, so no threshold is applied. Two cases cannot be detected by any browser API and follow the page instead: a window fully covered by another window, and a video popped out into Picture-in-Picture. It is measured for every tracked player, including the ones whose embed the player's own script replaces (Spotify) and the one heard from only through browser messages (VideoPress); when a player cannot be located on the page the variable is left unset rather than guessed, so a Custom Event trigger comparing it against `true` behaves the same as it would with GTM's own YouTube trigger.
* Changed: Vimeo media tracking is promoted from experimental to stable
* Updated: Vimeo tracker modernized against the Player SDK - tracks playback-rate, quality, fullscreen and Picture-in-Picture changes, maps buffering to Google Tag Manager's built-in video status, fires the start event on real playback (the `playing` event), and initializes reliably when loaded after the page is parsed (defer/async or late injection)
* Changed: SoundCloud media tracking is promoted from experimental to stable
* Updated: SoundCloud tracker hardened - bails out gracefully when the SoundCloud Widget API is blocked (consent manager, ad blocker, network error), still initializes when the script loads after the page is parsed (defer/async or late injection), and now reports the correct track metadata for playlist / multi-track widgets
* Deprecated: the "YouTube video events" option - Google Tag Manager now ships a native YouTube Video trigger, so YouTube tracking should be migrated to it; the plugin continues to populate GTM's built-in Video variables for the other players.
* Changed: a player's third-party script is now requested only on pages that actually contain one of its embeds. Enabling a player used to load its script on every page of the site, whether or not any page used that player - with every player enabled that was 288 KB fetched from seven different providers on every single page view, and it also handed those providers each visitor's IP address and browser details on pages with no player at all. Each tracker now fetches its own script, and only after it finds a matching embed on the finished page. That check also covers players the page's stored content cannot be searched for: ones placed in a widget, a block template, a page builder or a shortcode's output, and ones opened later in a popup. Tracking behaviour is otherwise unchanged. If you use a consent plugin, this arrangement works in your favour and needs no setup at all. A tracker asks its provider for the player library only after it finds that provider's embed on the page, and it recognises the embed by the provider's own domain. A consent plugin that holds the embed back until the visitor agrees therefore leaves the tracker nothing to find, so the provider is never contacted; when the visitor agrees and the embed appears, tracking picks it up. No rule naming GTM4WP is needed for any of that. What did change is that the page no longer contains a script tag naming the provider's domain, so a rule written against that domain alone has nothing to match. Three levers cover the cases where you would rather decide it yourself. Blocking or dequeuing one player's own script handle (`gtm4wp-vimeo`, `gtm4wp-twitch` and so on) stops that provider's request, because it stops the tracker that would make it. GTM4WP also ships a script whose only job is to be blocked: `gtm4wp-media-gate.js`, a real enqueued script that gives a consent plugin a single ordinary script tag to recognise. Block that tag and no media provider is contacted at all, while the trackers still run and still report players already on the page. That one has to be blocked rather than dequeued, because the trackers depend on it and WordPress prints a dependency whether or not it was dequeued. It is only added for the players that actually fetch something: HTML5 media, Wistia, JW Player and VideoPress each use a player already present on the page, so a site running only those never receives the extra script. The same decision is available server-side through the new `gtm4wp_media_sdk_blocked` filter.
* Fixed: the YouTube tracker now loads for every YouTube embed on the page. It previously loaded only when the post's own stored content held the legacy embed block or a hand-written `<iframe>`, so a video placed in a widget, a block template, a page builder, a reusable block or a shortcode's output was never tracked, and on an archive page only the first post was examined. The tracker now decides from the finished page instead.
* Fixed: the YouTube embed URL could receive a malformed query string (`?&enablejsapi=1`) when the embed carried no existing query parameters. It could also receive a broken `origin=` value - together with a PHP warning on every YouTube embed on the page - on a site whose WordPress Address setting is not a full URL the plugin can read a scheme and a host out of (a stray `example.com` instead of `https://example.com`, which a botched migration can leave behind). Whenever a usable origin cannot be built from the site address, for that reason or any other, the embed is now left exactly as WordPress produced it, so the video still plays; fix the WordPress Address setting to get the playback events back.
* Fixed: a media embed whose URL carries a `#` fragment (a `#t=30` start-time anchor, for example) is now tracked under the right video. The fragment stayed attached to the video ID the tracker reads out of the embed URL, so every YouTube and Vimeo event reported an ID and a URL that matched no video. On YouTube it could also stop the tracking completely, because the player API parameters were then appended after the fragment, where YouTube does not read them.
* Fixed: media players that report a zero or unknown duration - a live stream, or an HTML5 element that has not loaded its metadata yet - no longer emit every playback-percentage milestone at once.

**Consent mode & consent tools**

* Added: native Axeptio consent management platform (CMP) integration, as an "Axeptio" tab in the "Consent mode & consent tools" settings section (alongside Cookiebot and WebToffee, which now each have their own tab). GTM4WP loads the Axeptio SDK directly (no separate Axeptio plugin required); the cookies version is picked from a list fetched live from your Axeptio project, falling back to manual entry when that list cannot be loaded. When its "Google Consent Mode v2" option is enabled, Axeptio fires both the consent `default` and `update` commands and GTM4WP suppresses its own consent default so it is never sent twice, and every consent change is pushed to the data layer as a `gtm4wp.axeptioConsentUpdate` event. New `gtm4wp_axeptio_consent_mode_default` filter to adjust the default consent state.
* Added: new opt-in **CookieYes consent bridge** (Consent mode & consent tools → CookieYes). When enabled, GTM4WP listens for CookieYes' documented consent banner action API events (`cookieyes_consent_update` and `cookieyes_banner_load`) and pushes a `cookie_consent_update` data layer event carrying the accepted/rejected categories, giving your container a defined consent signal to sequence tags on. It does not defer or buffer any events - the correct fix for the ordering of e-commerce events versus consent is Google Consent Mode v2, which gates tags regardless of data layer push order. **Off by default** (experimental).
* Deprecated: the "WebToffee GDPR Cookie Consent (v2.x)" integration - it only targets the long-outdated v2.x product line. WebToffee v3.x and above integrate with Google Tag Manager natively, so the option is unnecessary there; upgrade the WebToffee plugin instead. The option is now flagged as deprecated in the settings screen.

**Contact Form 7**

* Updated: Contact Form 7 integration modernized against the current CF7 DOM events. Three new events are tracked - `gtm4wp.contactForm7BeforeSubmit` (before validation), `gtm4wp.contactForm7Unaccepted` (acceptance/terms checkbox not ticked) and `gtm4wp.contactForm7Aborted` (submission aborted) - and every CF7 data layer push now also carries the form's `unittag`, `containerpostid`, `locale` and `status` (the existing `formid` and `inputs` fields are unchanged). File inputs are reported by their file name instead of a raw File object. The tracker also guards against registering its data layer events twice when the script runs more than once (e.g. re-injected by a page builder or after an AJAX navigation).
* Updated: every Contact Form 7 data layer push now also carries the human-readable form name (`formname`), sourced from a `data-gtm4wp-form-name` attribute added to the rendered form (the CF7 DOM events only expose the numeric form ID).
* Added: "Submitted field values in the data layer" option for the Contact Form 7 integration (`integrate-wpcf7-inputs`). Defaults to "Full" (the existing behavior); can be set to "Field names only" or "None" to keep submitted personal data out of the data layer.
* Added: "Also push GA4 recommended events" option for the Contact Form 7 integration (`integrate-wpcf7-ga4events`, off by default). When enabled, the tracker also pushes the Google Analytics 4 recommended form events - `form_start` (on first field interaction), `form_submit` (on submit) and `generate_lead` (on a successful send) - each with `form_id`, `form_name` and `form_destination`, alongside the existing `gtm4wp.contactForm7*` events. Following the GA4 Enhanced Measurement definition, `form_submit` fires on every submission attempt, including one Contact Form 7 rejects, so `form_submit` and `generate_lead` also carry a `form_status` parameter holding the CF7 status (`mail_sent`, `validation_failed`, `spam` and so on) - use it in your container to separate accepted submissions from rejected ones. Both are pushed from the CF7 `wpcf7submit` event, so `form_submit` always precedes the `generate_lead` it produced.

**User events**

* Added: "Only report fields the visitor filled in" option for the form fill events (`event-form-move-filled-only`, off by default, beta). With it enabled, `gtm4wp.formElementLeave` is pushed only for a field that was empty when the visitor entered it and holds a value when they leave it, so tabbing through a form without typing no longer produces a leave event for every field along the way. `gtm4wp.formElementEnter` is never filtered and keeps firing for every field, so "fields touched" stays measurable next to "fields filled in". Text inputs, textareas and dropdowns are checked this way, and a whitespace-only value counts as empty; checkboxes, radio buttons, buttons, `meter` and `progress` are always reported, because they carry no empty state. Two consequences worth knowing before you switch it on: a field that was already filled in when the visitor entered it (one your theme pre-fills, or a dropdown whose options all carry a value) reports no leave event when they leave it unchanged, and neither does a field the visitor clears - the option reports the moment a field gets filled in, not the value it happens to hold.

* Added: both form fill events now also fill Google Tag Manager's built-in Form variables, so a Custom Event trigger on `gtm4wp.formElementEnter` or `gtm4wp.formElementLeave` can read the form with **Form ID**, **Form Classes**, **Form URL** and **Form Target** instead of only through Data Layer Variables. The existing `formID`, `formName` and `formClass` keys are unchanged and keep working. Two things worth knowing: Google publishes no built-in **Form Name** variable, so the form's name attribute stays available on the `formName` key and is read with a Data Layer Variable as before; and the keys behind these built-ins are the same ones the **Clicks** category reads, so on these two events **Click ID** and **Click Classes** resolve to the form as well. The built-in values follow what Google's own form submission event carries rather than the plugin's readable placeholders: an absent attribute arrives as an empty string, **Form URL** is the form's action resolved to an absolute URL (the current page when the form has no action), and a field that belongs to no form at all sends none of these keys rather than empty ones.

**AMP**

* Fixed: AMP integration now works in the AMP plugin's Standard, Transitional and Reader (theme) modes - previously the GTM amp-analytics tag was only emitted in the AMP plugin's deprecated Legacy Reader mode, so enabling the AMP Container ID produced no tracking on modern AMP setups. Migrated to the AMP plugin's `amp_analytics_entries` API (which also auto-loads the correct `amp-analytics` component script) and to the current `amp_is_request()` function (replacing the deprecated `is_amp_endpoint()`).
* Fixed: AMP data layer injection - 1.x checked a never-populated global and never injected the data layer into AMP pages.
* Fixed: the standard GTM container `<script>` is no longer emitted on AMP pages (it was invalid AMP and stripped by the AMP sanitizer anyway); the data layer is still compiled so the amp-analytics integration keeps its values.
* Changed: the AMP container ID setting is promoted from experimental to stable.

**Removed**

* Removed: weather and geo data features (ipstack.com / OpenWeatherMap integrations).
* Removed: scroll tracking feature - use Google Tag Manager's built-in Scroll Depth trigger instead. The `GTM4WP_OPTION_SCROLLER_*` constants remain in place for backward compatibility.

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

= 1.22 =

* Added: new WooCommerce option to change the event timeout of the select_item event. Could help some UX issues when a user has an ad blocker installed.
* Added: new WordPress filter GTM4WP_WPFILTER_OVERWRITE_COMO_FLAG / gtm4wp_overwrite_consent_mode_flag to overwrite Consent Mode flags in the default command. Can be used by consent banner plugins to mitigate support issues.
* Fixed: Unwanted content on frontend when container placement set to off and gtm4wp_the_gtm_tag() called manually. Thanks [Michael Bourne](https://wordpress.org/support/users/michaelbourne/)
* Fixed: Add to cart tracking broken with Bricks template and WooCommerce
* Updated: bundled WhichBrowser library updated to the latest version. This is a short term solution to solve PHP errors, on the long term, the plan is to switch to [Matomo Device Detector](https://github.com/matomo-org/device-detector)
* Updated: allow the / character in GTM container path to support same tag gateway
* Updated / Fixed: I reverted back the purchase tracking with WooCommerce to the logic present in v1.20- as the new way caused more headache than it resolved. Sorry for everyone who was affected by this.

= 1.21.1 =

* Fix: undefined product_qty variable prevents add_to_cart in some cases. Thanks [diegoarda](https://github.com/diegoarda)
* Fix: clarified that WebToffee integration is only needed with the legacy 2.x product line
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

* Fixed: run additional checks when determining product category to prevent PHP errors in certain cases
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
