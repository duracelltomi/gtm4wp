# Full changelog for GTM4WP

## 2.0

Major rewrite of the plugin - please read the announcement post on gtm4wp.com before upgrading.

### Architecture & requirements

* Changed: complete object-oriented rewrite. Every feature is now a module that third-party plugins can extend through the `gtm4wp_register_modules` action. All public template functions (`gtm4wp_the_gtm_tag()` etc.), filter/action names, wp-config constants and the `gtm4wp-options` storage key are unchanged, so existing integrations keep working.
* Changed: minimum requirements raised to PHP 8.0 and WordPress 6.3. Supported up to WordPress 7.1.
* Updated: frontend scripts now load with the `defer` strategy where possible.
* Changed: the main plugin file now refuses to run when it is requested directly instead of loaded by WordPress, matching every other PHP file in the plugin.

### Settings screen

* Added: modern React-based settings screen with left pane navigation, tabbed option groups, option search and inline per-field validation.
* Added: admin notices now link straight to the option they are about. Clicking through opens the module, switches to the tab holding that setting and highlights it, instead of dropping you on the settings screen to work out for yourself that, say, the trusted proxy addresses live under Page variables → Visitor data.
* Added: the settings screen keeps its address bar up to date, so any module and tab can be bookmarked or sent to a colleague and reopens exactly where you left it. The back button still simply leaves the settings screen; it does not turn into a tab stepper.
* Added: **export & import of the plugin settings** (Google Tag Manager settings screen, next to "Save changes"). "Export settings" downloads all of your GTM4WP options as a JSON file; "Import settings" reads such a file back on another site (or the same one). The import is treated as untrusted: every value in the file is run back through the exact same per-field sanitizers as a normal save before anything is stored - unknown keys are dropped, every value is normalized to the field's expected type, oversized files are refused and the file is only ever parsed with `json_decode()` (never `unserialize`/`eval`), so a hand-edited or malicious file cannot inject unsafe data. Both endpoints require the settings capability and a nonce. The value set is schema driven, so every current and future option is covered automatically.

### Google Tag Manager container & tag restrictions

* Added: every Google Tag Manager container ID now has its own environment parameters (`gtm_auth`/`gtm_preview`), custom domain and custom path, managed in a data table (new `gtm-containers` option). Existing settings are migrated automatically; the flat 1.x option keys are kept in sync for third-party code and downgrades.
* Changed: with environment parameters configured, all containers are loaded now (1.x only loaded the first container in that case). Only the hard-coded wp-config environment constants still limit output to the first container.
* Added: per-container "Omit container ID" option in the container table. When a custom path is set (server side GTM), turning it on drops the container ID from the loader URL (`gtm.js?'+dl` instead of `gtm.js?id='+i+dl`) for setups where the container is selected by its path.
* Added: a kill switch to stop the Google Tag Manager container from loading on a cloned or staging copy of a site without deactivating the plugin. A new **"Only output the container on production environments"** option (Google Tag Manager container → Advanced) emits the container only when WordPress reports the environment type as "production" (set `WP_ENVIRONMENT_TYPE` on your non-production copies). Because `WP_ENVIRONMENT_TYPE` lives in `wp-config.php` or the server config and is invisible from the admin, the option's description reports the environment type WordPress actually returns on *this* site and whether the container would therefore be loaded or suppressed - including the common trap that an unset `WP_ENVIRONMENT_TYPE` falls back to "production", so the option silently keeps loading the container. For host-based control from an mu-plugin or `wp-config.php`, the new `gtm4wp_output_container` filter (default `true`) can veto the container from PHP. In both cases the data layer stays active and only the container `<script>` / `<noscript>` is suppressed - exactly like the "Off" placement. **Off by default** (experimental).
* Changed: a malformed `GTM4WP_HARDCODED_GTM_ENV_AUTH` or `GTM4WP_HARDCODED_GTM_ENV_PREVIEW` value in `wp-config.php` is now rejected instead of being written into every container's loader URL, and the settings screen shows a warning naming the constant that is wrong. Previously only the hard-coded container ID was checked, so a typo in the environment constants was applied as-is and there was nothing anywhere to point at the cause. The accepted formats are the same ones the container table enforces (`gtm_preview` looks like `env-3`). The settings screen also shows what a *valid* hard-coded constant does: the part of the container table it fixes is displayed read-only with the values that are actually running - a single environment column, or the whole table when the constants also decide which containers are loaded - and the field description names the constant you have to edit. Your own stored container setup is kept untouched behind it and is used again as soon as you remove the constants from `wp-config.php`; 1.x showed the hard-coded container ID in a read-only field but wrote that value into your saved options on every save. A rejected constant changes nothing at output time, so it leaves the table editable.
* Fixed: the **dataLayer variable name** option (Google Tag Manager container → Advanced) accepted names that break every script the plugin writes. The field allowed a hyphen, but JavaScript reads a hyphen as the subtraction operator, so a name like `my-layer` was saved without complaint and then written to the page as `var my-layer = my-layer || [];` - a syntax error that took the whole GTM4WP script block with it. The container itself still loaded, so the setup looked connected on the surface while no data layer content was ever pushed: no page variables, no e-commerce events, no media events. The field now accepts exactly what JavaScript accepts as a variable name (a letter, `_` or `$`, followed by letters, digits, `_` or `$` - and `$` is newly allowed, it was rejected before). A name stored under an older version that does not qualify is ignored in favour of the default `dataLayer` so the container keeps working, and the settings screen names the rejected value so you can correct it.
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
* Fixed: numeric-looking text values are no longer converted into numbers in the data layer. The main data layer JSON was encoded with PHP's `JSON_NUMERIC_CHECK` flag, which coerced every numeric-looking string anywhere in the structure into a JSON number: a SKU like `000035180` lost its leading zeros in `cartContent` while the same product inside `ecommerce.items` (built on a path without the flag) kept the correct string, order numbers, postcodes and phone numbers changed type, and custom values added through the `gtm4wp_compile_datalayer` filter were altered too - as reported on the wordpress.org support forum. Identifier-like values now always reach Google Tag Manager as unmodified strings, while genuinely numeric values (prices, cart/order totals, quantities, counts, ids - including the Unix-timestamp page variables `pagePostDateUnix`/`pageModifiedDateUnix`, the post counts and the author ids) are typed as real numbers at their source, so GA4 and Meta still receive numeric `price`/`value`. **Check your GTM setup if it compares one of these against a number:** `orderData.attributes.order_number`, numeric SKUs in `item_id`/`sku`, numeric-looking postcodes/phone numbers and zero-padded date parts (`pagePostDateMonth` is now `"07"`, not `7`) arrive as strings after this fix. GTM's "greater/less than" trigger comparisons keep working (they compare numerically), but an "equals" comparison against the unpadded number (e.g. `7`) must be updated to the padded string (`07`).

### Cache-safe data layer

* Added: a new experimental **"Cache-safe data layer"** setting (issue #398). On sites with full-page caching (LiteSpeed, WP Rocket, Varnish, Cloudflare APO) the HTML built for one visitor is served to everyone, so any visitor-specific value baked into the data layer would leak - the classic case being a logged-in editor's page cached with their email/username/role and then served to anonymous visitors. With this on, **no** visitor or session data is rendered into cacheable HTML at all; every such value is instead delivered client-side under the **same** data layer variable names, so your Google Tag Manager tags keep working.
	* The values the browser can compute itself - the search term and the referring page (`siteSearchTerm`, `siteSearchFrom`) - are pushed client-side directly, which also removes their reflected-XSS surface.
	* The visitor IP, Cloudflare country and logged-in-user data (login state, role, email + hash, registration date, username, id) come from a new first-party session endpoint that returns **only the current request's own data** (it takes no user/session id, so one visitor can never request another's) with no-cache headers. The IP/country are fetched once per session and cached in the browser, the user data only when the login state changed, so an anonymous visitor on a cached page never fetches and never receives user data.
	* The WooCommerce customer and cart blocks ride the cart-fragments response WooCommerce already refreshes on every cart change, so GTM4WP makes no request of its own for them. It does make sure that response actually happens: WooCommerce only loads its cart-refresh script for the classic "Cart" widget, and not even there on the cart and checkout pages (the block Mini-Cart never loads it), so on most stores the customer and cart variables would simply never arrive on a normal page view - GTM4WP therefore loads that script itself while this mode is on. **On a store that was not loading it before, this means one extra WooCommerce cart-refresh request per browser tab** (per tab, because that is how WooCommerce caches the response; an already-loaded tab makes none on later page views, and a visitor who has blocked browser storage makes one per page view). Nothing changes on a store that already shows a mini-cart anywhere, which is the majority.
	* Because these values now arrive **after** the page view instead of inside it, point the tags that read them at a Custom Event trigger. There is one event per group of variables, so the event name alone tells you what arrived and no trigger condition is needed: **`gtm4wp.visitorData`** carries the visitor and logged-in-user variables (`siteSearchTerm`, `siteSearchFrom`, `visitorIP`, `geoCloudflareCountryCode`, `visitorLoginState`, `visitorType`, `visitorEmail`, `visitorEmailHash`, `visitorRegistrationDate`, `visitorUsername`, `visitorId`), **`gtm4wp.customerData`** the WooCommerce `customer*` variables and **`gtm4wp.cartData`** the `cartContent` variable. If you need to react to more than one, use a "Some Custom Events" trigger matching `gtm4wp\.(visitor|customer|cart)Data`.
	* Each of the three fires only when **its own** data changed and only when the option that produces it is enabled, so changing a cart quantity fires `gtm4wp.cartData` alone and editing a billing field on the checkout fires `gtm4wp.customerData` alone. Two things to read correctly: an **empty cart is still sent** (`cartContent` with an empty item list - that is how you see the cart being emptied), so a missing `gtm4wp.cartData` means the cart content option is off or the cart has not been read yet, not that the cart is empty; and `gtm4wp.visitorData` is skipped entirely on a page where none of its variables apply, so a tag firing on the two WooCommerce events must not assume a `visitor*` variable is already set. When `gtm4wp.visitorData` does fire it always comes first, so reading its variables from a later event does work.
	* The two WooCommerce one-shot events - the `add_to_cart` fired when a product is restored to the cart (the cart "Undo") and the reliable-purchase **fallback** that recovers a `purchase` when the order-received page was missed (a custom/redirect thank-you page) - must fire exactly once, so they are delivered differently: when one is queued GTM4WP sets a short-lived event cookie, and only then does the browser fetch it (resolving the order/re-add from *this* session, never from a URL parameter), fire the event once and clear the cookie. An anonymous visitor on a cached page, who never has the cookie, never fetches.
	* The purchase fallback reuses the existing de-duplication - the same `gtm4wp_orderid_tracked` browser guard (keyed on the order number) the order-received page writes, plus the server `_ga_tracked` order flag, which the browser sets with a single authenticated `POST` beacon after a fallback delivery (the order id taken only from the buyer's own session, never the request body). A fallback fire and a real order-received purchase for the same order can therefore never both count, on the same device or across devices; the re-add is de-duped on a per-event token.
	* The "Do not flag orders as being tracked" option is honoured end to end (no marker, no beacon, no flag), and all request-header values and order numbers round-trip hex-encoded so a hostile value can never break out.
	* The confirmation beacon only accepts a request that came from a page on your own site: it verifies both a WordPress REST nonce and the request origin. The nonce on its own would not be enough, because it is the same value for every logged-out visitor and so cannot tell your checkout page apart from a third-party one; the origin is the part a foreign page cannot fake. It is read only from the request's own headers, never from a parameter the caller could add to the URL.
	* None of the plugin's REST routes can be read from another website. WordPress by default tells any site that asks that it may read a REST response using the visitor's own cookies; GTM4WP withdraws that permission for its own routes only - other plugins are untouched, and your own pages are unaffected. This covers every GTM4WP REST route, including the settings routes, whether or not the cache-safe data layer is switched on.
	* **Off by default** (experimental); when off, the data layer is exactly as before.

### Page variables

* Added: new Page variables options in a "Content & engagement data" group, useful for behavior tracking and GA4 content grouping:
	* Content word count (`pageContentWordCount`) and estimated reading time (`pageReadingTime`, adjustable with the `gtm4wp_reading_time_wpm` filter). Both count words correctly in every language, including scripts PHP's own word counter does not recognise (Cyrillic, Greek, Hebrew, Arabic) and languages that do not separate words with spaces (Chinese, Japanese, Korean), where each character counts as one word.
	* Last modified date (`pageModifiedDate` and the `pageModifiedDate*` family) and content age in days (`pageContentAgeDays`)
	* Comment count and status (`pageCommentCount`, `pageCommentStatus`)
	* Page template (`pageTemplate`), featured image presence (`pageHasFeaturedImage`), page hierarchy (`pageParentID`, `pageDepth`) and sticky flag (`pagePostSticky`)
	* Primary category (`pagePrimaryCategory`, `pagePrimaryCategoryName`) detected from Yoast SEO / Rank Math with a first-category fallback, overridable with the `gtm4wp_primary_category_term_id` filter
	* Page language (`pageLanguage`) detected from WPML / Polylang with a site-locale fallback, overridable with the `gtm4wp_page_language` filter
* Added: **PublishPress Authors support** for the Page variables author data. On sites that use PublishPress Authors, a post can have several authors (co-authors and guest authors) - important for E-E-A-T. When PublishPress Authors is active, `pagePostAuthor` / `pagePostAuthorID` are sourced from it - including for a post with a single **guest** author, which would otherwise report the WordPress user who created the post - and when such a post has more than one author, GTM4WP also outputs `pagePostAuthors` (the list of author names) and `pagePostAuthorIDs` (the list of author IDs; guest authors use a negative id). The single-value variables stay for back-compat and point at the primary (first) author. The two arrays are filterable via `gtm4wp_page_post_authors` and `gtm4wp_page_post_author_ids`. Uses the existing "Post author name" / "Post author ID" options as the on/off switches; when PublishPress Authors is not active, behavior is unchanged.
* Added: a separate **"Post custom fields (meta)"** option (Page variables → Post data). Until now the "Post Terms" option did two very different things: it added the post's taxonomy values *and* published every custom field whose name does not start with an underscore - together with its value - into the data layer of the public page, even though the option only ever mentioned taxonomies. Since custom fields are where plugins and themes keep their own data (Advanced Custom Fields stores its values this way), that could put internal notes, ids, prices or contact details on a page any visitor can read, without the site owner ever being told. The two are now separate opt-ins with the custom-field one spelling out exactly what it publishes. **Nothing changes for an existing site:** if you had "Post Terms" enabled, the new option is turned on for you during the upgrade, so `pagePostTerms` (including `pagePostTerms.meta`) keeps exactly the shape and content it had. Turn the new option off if you did not intend to publish your custom fields; individual keys can still be excluded with the `gtm4wp_post_meta_in_datalayer` filter.
* Added: an optional **"Include parent categories in the category list"** setting (Page variables → Post data). By default the `pageCategory` data layer variable lists only the categories directly assigned to the current post or archive. With this on, the parent (ancestor) categories of each category are also added - immediate parent first, up to the top-level category - and the list is de-duplicated. The option is greyed out in the settings screen while its parent option, "Category list of current post/archive", is turned off (any option can now declare such a dependency). **Off by default**, so the current output is unchanged until you enable it. Thanks to @twentyfortysix for the original patch (#220).
* Changed: browser, OS and device data is now collected in the browser using User-Agent Client Hints and pushed as a `gtm4wp.deviceData` event (replaces the bundled WhichBrowser library; Safari and Firefox expose less detail).
* Fixed: the `postFormat` data layer variable now contains the actual post format (`aside`, `gallery`, `video`, ...) of the current post. Since its introduction it sent an empty string for every post that *had* a format and `standard` otherwise, so it could never tell formats apart; posts without a format still report `standard`.
* Fixed: repeated PHP warnings (`Attempt to read property "post_author" on null`) on sites where a theme or plugin leaves the global post object unset on a singular page. Every post-derived page variable (post type, category/tag lists, author data, dates, term list, word count and reading time, content age, comment data, page template, featured image flag, page hierarchy, sticky flag, primary category, post id and post format) is now simply omitted on such a request instead of being emitted with a placeholder value, and the `postCountOnPage`/`postCountTotal` variables are likewise omitted when the main query global is unavailable. The same now applies on an **author archive**: `pagePostAuthor`/`pagePostAuthorID` are omitted when the author object is not set up, instead of being sent as an empty name and the author id `0`. As reported on the wordpress.org support forum against 1.22.3.
* Fixed: JavaScript variables added through the `gtm4wp_add_global_vars_array` filter now keep their type **and their text**. A `null` value was rendered as `false`, an empty array as `false` and the float `0.0` as `false`, so a tag reading such a variable saw the wrong value. Separately, a **string** value was escaped for an HTML attribute rather than for a script, so a `"`, `<` or `>` in it reached your tags as the text `&quot;`, `&lt;` or `&gt;` - while the very same string inside an array arrived correctly. Both now use identical encoding, so a value is delivered the same way however it is supplied. A value the encoder cannot represent at all (text that is not valid UTF-8) now falls back to `null` instead of producing a broken declaration that made the whole head script block a syntax error - taking the data layer initialization down with it. The variable *name* was already checked this way; the value now is too.
* Added: a "Visitor IP - Trusted proxy addresses" setting, next to the existing custom-header option. An HTTP header is sent by the visitor, so on its own it is a claim rather than a fact, and with no list of trusted upstreams the plugin has no way to tell your infrastructure's entry in that header apart from the visitor's. List the addresses or CIDR ranges of your reverse proxy, load balancer or CDN there and it can - an `X-Forwarded-For` list is then read from the right, skipping your own hops, and a single-value header such as `CF-Connecting-IP` is only used when the request really did arrive through one of those addresses. **Nothing changes for an existing site until you fill it in:** the header is read exactly as before, and an admin notice points out that the value cannot be verified yet. If your site is not behind a proxy or CDN, leave the field empty and do not set a custom header at all. A range that covers every address (`0.0.0.0/0` or `::/0`) is rejected rather than stored, because it would declare the whole internet a trusted proxy - which is the situation the setting exists to end, and it would also silence the notice that warns you about it. Both this field and the custom-header field are greyed out while the "Visitor IP" variable itself is off.
* Fixed: option descriptions on the settings screen (Page variables) that did not match what the option actually sends. Building a Google Tag Manager variable needs three things - the subject, the **name** of the data layer variable it lands in, and the **form** the values take - and several descriptions gave only the first. "Category list of current post/archive" said it sends the category *names*, while `pageCategory` has always contained the category **slugs** (`news-and-events`, not `News and Events`), so a trigger written against the description could never match; "Tags of current post" named neither its variable (`pageAttributes`, not `pageTags`) nor the slug form; "Post date" said it adds 4 data layer variables, while it has added 9 since 1.15. Now corrected in the same way: "Post Format" (`postFormat`, the format slug, `standard` when none), "Primary category" (**two** variables - `pagePrimaryCategory` holds the slug and `pagePrimaryCategoryName` the display name), "Post author ID" and "Post author name" (`pagePostAuthorID`/`pagePostAuthor`, plus the `pagePostAuthorIDs`/`pagePostAuthors` lists that appear on a multi-author post), "Logged in user role" (`visitorType`, role slugs, comma separated for several roles, and `visitor-logged-out` for a visitor who is not logged in) and "Logged in status" (`visitorLoginState`, either `logged-in` or `logged-out`). All descriptions now match what is sent; no data layer value changed.

### WooCommerce

#### Cart & Checkout blocks

* Added: WooCommerce Cart & Checkout **block** support. The React-based Cart & Checkout blocks (now the WooCommerce default) never fire the classic jQuery events, so `add_to_cart`, `remove_from_cart`, `add_shipping_info` and `add_payment_info` were previously lost on block-based stores. GTM4WP now exposes its GA4 item data on the WooCommerce Store API (`extensions.gtm4wp.item`) and reads the `wc/store/cart`, `wc/store/checkout` and `wc/store/payment` data stores to fire those events. On block Cart/Checkout pages the block tracker loads and the classic tracker is skipped, so nothing is counted twice; `view_cart`, `begin_checkout` and `purchase` continue to fire server-side. Because the item price comes from the server as a real number, values stay correct for zero-decimal (e.g. JPY) currencies.
* Added: block tracking now also covers the **Mini-Cart**, **Product Collection** and **cart cross-sells** blocks. Removing an item (or changing its quantity) in the Mini-Cart drawer now fires `remove_from_cart` - on a block-based store the block tracker rides along on every page in a "mini-cart" mode that reports removals only, so the classic tracker keeps sole ownership of `add_to_cart` and nothing is counted twice. The Product Collection grid (the current WooCommerce default, which fires none of the classic product-loop hooks) now reports `view_item_list` and `select_item`, with a friendly list name for every collection WooCommerce ships (Product catalog, Sale, Best selling, Top rated, New, Featured, Related, Upsells, Cross-sells, Hand-picked, By category, By tag, By brand and Cart contents). The four that replace a legacy product grid block report the same list as that block did, so moving a page over to Product Collection does not start a new list in your reports. The Cart block's cross-sell products now report `view_item_list` and `select_item` too, and like every other tracking path their GA4 items carry none of the plugin's internal bookkeeping keys.

#### Purchase tracking

* Added: new "Reliable purchase tracking" option (WooCommerce → Purchase tracking) for the most common cause of a missing `purchase` event. When the customer lands on a heavily customized thank-you page, or on the order-pay page instead of the order received page, the purchase event is now emitted on the next page they view in the same browser session. The placed order is remembered server-side (on `woocommerce_payment_complete`, the order-status change and `woocommerce_thankyou`) and de-duplicated with the existing order-tracked flag, browser cookie and order-age guards, so it is never counted twice. Off by default (experimental). It cannot capture orders where the buyer pays via an asynchronous gateway and never returns to the site - that case needs server-side tracking.
* Added: new "Order statuses that trigger the purchase event" setting. The purchase now fires at order *placement* for the configured statuses (default: Processing, On hold, Completed), so Cash on Delivery (Processing) and bank transfer (On hold) orders are tracked at checkout even though payment clears later. Filterable via the new `gtm4wp_purchase_trackable_statuses` filter.
* Added: new "Custom order received (thank-you) page" option that fires the purchase event on a bespoke confirmation page - resolving the order from the current session - for themes or plugins that do not use the standard WooCommerce order received endpoint.
* Added: GA4 / Google Ads Enhanced Conversions user data is now included on the `purchase` event - SHA-256 hashed email, phone and name plus the plaintext address components Google expects - built from the order so guest checkouts are covered too. Opt-in through the existing "Customer data in data layer" option.
* Added: the `purchase` event now also carries `customer_type`, the `new` / `returning` string that Google's GA4 e-commerce reference documents. The existing `new_customer` boolean is unchanged and still sent - Google names the same idea differently on two surfaces (Google Ads customer acquisition reads `new_customer`, GA4 reads `customer_type`), so both are needed and neither replaces the other. Nothing to change in your GTM setup unless you want to use the new variable.
* Added: new "Transaction ID prefix" option (WooCommerce → Purchase tracking). The text entered here is prepended to the `transaction_id` sent with the `purchase` event, for example to tell several stores apart in one GA4 property or to match the order id format of another system. Empty by default, so the plain WooCommerce order number is sent exactly as before. Only the `purchase` event is affected: the order number in the `orderData` variable and the duplicate tracking guards of the plugin keep using the raw order number.
* Changed: the purchase event is no longer sent for failed or still-pending orders by default (previously any order except a failed one that reached the thank-you page was tracked). Add the relevant status to the new "Order statuses that trigger the purchase event" setting if you need the old behavior.
* Fixed: the "Do not flag orders as being tracked" WooCommerce option now also skips the browser-side duplicate guard. Previously it only disabled the server `_ga_tracked` order meta, while the `gtm4wp_orderid_tracked` cookie / localStorage entry was still written - so the same order could not be re-tested in the same browser without clearing storage. With the option on, no order-tracked state is written anywhere.

#### Item data & extensibility filters

* Added: every tracked product list item now carries an `item_list_id` next to `item_list_name`, so GA4 list reports can key on a stable id. Each list reports its own id - including WooCommerce's product grid blocks (Handpicked Products, Newest Products, On Sale and the rest), where every grid on a page is a separate list rather than all of them sharing one. For the lists the plugin names itself the id is a fixed identifier that does not change with the site language, so a multilingual store reports one list instead of one per translation; a list named after a widget title still gets its id from that title. Third-party code can supply its own id via the `GTM4WP_WPFILTER_EEC_PRODUCT_ARRAY` filter.
* Added: new opt-in WooCommerce option **"Persist product list attribution across the funnel"** (WooCommerce → Product data). When a visitor clicks a product in a list - a classic product loop, a product grid or Product Collection block, or the Cart block's cross-sells - the plugin remembers which list it was (`item_list_name` / `item_list_id`) in a first-party cookie and carries that attribution onto the later `view_item`, `add_to_cart`, `view_cart`, `begin_checkout`, `add_shipping_info`, `add_payment_info` and `purchase` events, so GA4 can attribute the whole funnel back to the originating list. **Off by default** - enable it only if you are NOT already doing the same with custom JavaScript in Google Tag Manager, otherwise the attribution would be set twice. The product page keeps working behind a full-page cache: the `view_item` event is still rendered by the server, but the list name is filled in by the browser, so the cached HTML stays the same for every visitor. This covers the simple-product and variable-parent `view_item`, a variation selected on the product page, and WooCommerce Quick View. The cookie holds up to the 20 most recently seen products and evicts the least recently seen one when it is full - and it stays within the size a browser accepts for a single cookie, so a store whose list names are long simply remembers fewer products instead of losing the attribution altogether.
* Added: GA4 e-commerce items now carry a per-item `discount` wherever it can be computed - cart, `view_cart`, `begin_checkout` and `purchase` items report the per-unit discount whenever a coupon or sale reduced the line (on the same tax basis as the item price). Undiscounted lines carry no `discount` key. Per-item coupon codes are still not emitted (WooCommerce does not map coupons to individual lines); attach them via the `gtm4wp_eec_product_array` / `gtm4wp_eec_order_item` filters if you need them.
* Added: the `view_item` event now sends an explicit `quantity` of 1 on its item, for GA4-spec completeness (both the simple-product server event and the variable-product client event).
* Added: a new `gtm4wp_eec_item_affiliation` filter to set the GA4 item-level `affiliation` (the storefront/marketplace an item was sold through). It is empty by default - WooCommerce has no native value - so the item payload stays free of empty `affiliation` strings unless you supply one.
* Added: a new `gtm4wp_eec_item_with_source` filter for enriching a GA4 e-commerce item with custom data taken from the raw source object it was built from - the WooCommerce cart item (on the cart, mini-cart, checkout, re-add and Cart/Checkout-block paths) or the `WC_Order_Item` (on the purchase path), both of which can carry custom meta that never lives on the `WC_Product`/variation. Alongside the usual item array and placement context, the filter receives a new third argument: that source object (or `null` where there is no per-line source, e.g. a product-detail page or a product list). Read the meta you need and copy only those fields onto the item - the source object itself is never merged into the item array, so your GA4 events are not bloated with data you did not ask for. Thanks to @migueldamota for the request (#324).
* Changed: the `gtm4wp_eec_product_array` filter is now deprecated in favor of the new `gtm4wp_eec_item_with_source` filter (which additionally receives the source cart/order item). It keeps working unchanged - it still receives the same two arguments and still runs *before* the new filter, so both filters can modify the item - and only raises a deprecation notice when `WP_DEBUG` is enabled, once per request rather than once per product. Existing integrations need no change.
* Changed: server-pushed e-commerce events now serialize the `event` key before the `ecommerce` object, matching what the client-side events already emit. Key order is irrelevant to Google Tag Manager / GA4; this is a consistency-only change with no effect on tracking.

#### Tracking behavior & compatibility

* Added: new opt-in **CheckoutWC compatibility** option (WooCommerce → Advanced). CheckoutWC replaces the WooCommerce checkout with its own multi-step template, so the classic markers the tracker relies on are not reliably present and the `add_shipping_info` / `add_payment_info` events were missed. With this on, the tracker also binds those steps to CheckoutWC's own `cfw_step_changed` event (the step is deduplicated with the classic checkout, so nothing is counted twice). **Off by default** (experimental) - enable it only on stores that use CheckoutWC.
* Added: the WooCommerce add-to-cart tracking logic is now exposed as reusable JavaScript functions - `window.gtm4wp_track_single_add_to_cart( button, form )` (product-detail page: variable, grouped and simple products) and `window.gtm4wp_track_list_add_to_cart( button )` (product lists and the `[add_to_cart]` shortcode). A theme that handles Add to Cart with its own AJAX - and calls `e.preventDefault()` on the button, which suppresses the built-in click tracking - can now fire the event from its success handler in a couple of lines instead of copying the tracker.
* Added: the WooCommerce e-commerce tracker logs every event it pushes to the browser console, honoring the site-wide "Do not use console.log() messages on the frontend" option (nothing is logged when that option is turned on).
* Changed: clarified the "Set maximum timeout for select_item event" option (WooCommerce → Advanced) so it explains that a value of 0 opens product links immediately without waiting for GTM - the select_item event is still sent, but the click is no longer delayed. Useful when product-list links feel slow to open (e.g. a consent tool blocks GTM so the callback never returns).

#### Fixes

* Fixed: the browser-side duplicate-purchase guard now works on stores whose order numbers are not the plain order id (sequential or prefixed order numbers). The guard cookie holds the order **number**, but the server read it as an integer and compared it to the order **id**, so on those stores that leg of the de-duplication never matched. The same order number is now used consistently everywhere the guard is written or read.
* Fixed: cart and checkout pages no longer risk a PHP memory exhaustion under some WooCommerce versions. `wc_get_price_to_display()` was called once per cart item (and again for each item's remove-link data), which became very expensive on certain WooCommerce releases. Cart, mini-cart and checkout item prices are now taken from the line totals WooCommerce has already calculated, and the display-price call is skipped whenever a price is already supplied (the purchase path passed one that was then discarded).
* Fixed: when "Exclude tax from revenue" is enabled, per-item prices on the `purchase` event are now also reported excluding tax. Previously the transaction `value` was tax-exclusive while the item prices followed the shop's display setting (often tax-inclusive), so GA4 item-level revenue (product performance) did not reconcile with the transaction total (sales performance).
* Fixed: variable **subscription** products now keep their variant data in ecommerce tracking. Variations were detected by an exact `variation` product-type match, but WooCommerce Subscriptions variations report `subscription_variation`, so their `item_variant`, `item_group_id` and parent-derived `item_category`/`item_brand` were dropped (most visibly on the `purchase` event). Variations are now detected structurally (any `WC_Product_Variation`), covering subscriptions and similar extensions.
* Fixed: the dynamic-remarketing "Product ID prefix" is now kept on variations. When a variation was selected on a variable product page, the browser swapped in the variation id and dropped the configured prefix from the `id` field used for Google/Meta catalog matching; the prefix is now re-applied to the variation's `id` (the unprefixed `item_id` is unchanged).
* Fixed: the `[add_to_cart]` shortcode button now fires an `add_to_cart` event. A standalone shortcode button is rendered outside a product loop, so it never received the hidden product-data markup that product-list items get; the GA4 item data is now attached to the button itself so a click can be tracked. Product lists are unaffected — they already carry the data.
* Fixed: the product-page `add_to_cart` event is no longer fired when the browser blocks the add-to-cart form submit because a required field is empty (e.g. a required Product Add-ons field). The click now respects the form's HTML5 validity, so a rejected add no longer produces a false `add_to_cart`.
* Fixed: `add_to_cart` and `remove_from_cart` now always report `quantity` as a number, and report it the same way on every surface. Three symptoms of one cause: a product form with no quantity field at all - some themes and product add-on plugins render none - emitted `quantity: null` and `value: 0`; the cart page reported a string where the mini-cart reported a number for the very same product; and a cart line set to zero fired a removal event on the cart page while the mini-cart correctly suppressed it. Every quantity now goes through one parser, so the type and the zero handling are identical everywhere. **Check your GTM setup if a trigger or variable compares `quantity` against a string.**
* Fixed: the WooCommerce Quick View popup now pushes its product data into the data layer variable you configured. It was the one place in the plugin still using the default `dataLayer` name directly, so on a site that renamed the data layer the Quick View event was silently dropped - or pushed into whatever other tool owns that name on the page.
* Fixed: the **"Set maximum timeout for select_item event"** option (WooCommerce → Advanced) now actually takes effect. The value reached the page correctly, but the tracker looked it up under the wrong name and never found it, so every product-list click used the built-in 2000 ms wait for Google Tag Manager to call back, whatever you had configured. Most visibly, setting it to **0** to stop product links feeling slow to open - the documented remedy when a consent tool blocks GTM so the callback never returns - changed nothing at all. Stores that left the setting at its default of 2000 behave exactly as before.
* Fixed: the WooCommerce and form-interaction trackers no longer fire their events twice when their script is loaded a second time on the same page - by an AJAX navigation, or by a page builder that duplicates the script handle. Both re-registered their event listeners, and the WooCommerce tracker additionally reset its own "already reported" memory, which let `add_payment_info` and `add_shipping_info` fire again on checkout even once the listeners were under control. The media and Contact Form 7 trackers already had this protection; every tracker now has it.

### Media events

* Added: media player tracking for eight more players, each as its own opt-in option under Media events → Media players (experimental): Dailymotion, Mixcloud, Cloudflare Stream, Wistia, JW Player, VideoPress, Spotify and Twitch. Each fires the same `gtm4wp.media*` events (ready, state change, playback percentage, player event) as the existing players and also populates Google Tag Manager's built-in Video variables. Notes: Spotify derives play/pause/finished states from its periodic playback updates (the only signal the Spotify embed exposes); Twitch reports current time and duration for videos (VODs) only, not live streams.
* Added: tracking for native HTML5 `<video>` and `<audio>` players, as its own opt-in option under Media events → Media players (experimental). Fires the same `gtm4wp.media*` events as the other players - ready (carrying the real media duration), state changes, and playback percentage - and also tracks buffering plus Picture-in-Picture and fullscreen changes on video. Each event also populates Google Tag Manager's built-in Video variables.
* Added: an optional **"Track dynamically inserted players"** setting (Media events). The media trackers wire up players that are on the page when it loads; with this on they also track players inserted *after* load - opened in a popup/lightbox or loaded via AJAX - which previously went untracked. It works by watching the page for new embeds with a single shared `MutationObserver` (one for all providers, scanning only the nodes each change adds, not the whole document), then running the exact same wiring a player gets at page load; a player whose SDK replaces the embed element with its own iframe (e.g. Spotify) is recognised as already wired, so each player is wired exactly once. **Off by default** (experimental): the observer has a small per-DOM-change cost, so enable it only if your site injects media players at runtime. The Wistia tracker already handled runtime insertion natively and is unchanged. Thanks for the request (#3).
* Added: every media event now fills all eight of Google Tag Manager's built-in **Video variables**, the **Video Visible** one included. It reports whether the player was actually on screen at the moment of the event: a player scrolled out of view reports `false`, and so does one playing in a background tab or a minimised window, while a player even partly on screen reports `true`. Google publishes no minimum visible percentage for this variable, so no threshold is applied. Two cases cannot be detected by any browser API and follow the page instead: a window fully covered by another window, and a video popped out into Picture-in-Picture. It is measured for every tracked player, including the ones whose embed the player's own script replaces (Spotify) and the one heard from only through browser messages (VideoPress); when a player cannot be located on the page the variable is left unset rather than guessed, so a Custom Event trigger comparing it against `true` behaves the same as it would with GTM's own YouTube trigger.
* Changed: Vimeo media tracking is promoted from experimental to stable
* Updated: Vimeo tracker modernized against the Player SDK - tracks playback-rate, quality, fullscreen and Picture-in-Picture changes, maps buffering to Google Tag Manager's built-in video status, fires the start event on real playback (the `playing` event), and initializes reliably when loaded after the page is parsed (defer/async or late injection)
* Changed: SoundCloud media tracking is promoted from experimental to stable
* Updated: SoundCloud tracker hardened - bails out gracefully when the SoundCloud Widget API is blocked (consent manager, ad blocker, network error), still initializes when the script loads after the page is parsed (defer/async or late injection), and now reports the correct track metadata for playlist / multi-track widgets
* Deprecated: the "YouTube video events" option - Google Tag Manager now ships a native YouTube Video trigger, so YouTube tracking should be migrated to it; the plugin continues to populate GTM's built-in Video variables for the other players.
* Fixed: the YouTube tracker now loads for modern block-editor embeds and classic URL auto-embeds, not only the legacy embed block or a hand-written `<iframe>`.
* Fixed: the YouTube embed URL could receive a malformed query string (`?&enablejsapi=1`) when the embed carried no existing query parameters. It could also receive a broken `origin=` value - together with a PHP warning on every YouTube embed on the page - on a site whose WordPress Address setting is not a full URL the plugin can read a scheme and a host out of (a stray `example.com` instead of `https://example.com`, which a botched migration can leave behind). Whenever a usable origin cannot be built from the site address, for that reason or any other, the embed is now left exactly as WordPress produced it, so the video still plays; fix the WordPress Address setting to get the playback events back.
* Fixed: media players that report a zero or unknown duration - a live stream, or an HTML5 element that has not loaded its metadata yet - no longer emit every playback-percentage milestone at once.

### Consent mode & consent tools

* Added: native Axeptio consent management platform (CMP) integration, as an "Axeptio" tab in the "Consent mode & consent tools" settings section (alongside Cookiebot and WebToffee, which now each have their own tab). GTM4WP loads the Axeptio SDK directly (no separate Axeptio plugin required); the cookies version is picked from a list fetched live from your Axeptio project, falling back to manual entry when that list cannot be loaded. When its "Google Consent Mode v2" option is enabled, Axeptio fires both the consent `default` and `update` commands and GTM4WP suppresses its own consent default so it is never sent twice, and every consent change is pushed to the data layer as a `gtm4wp.axeptioConsentUpdate` event. New `gtm4wp_axeptio_consent_mode_default` filter to adjust the default consent state.
* Added: new opt-in **CookieYes consent bridge** (Consent mode & consent tools → CookieYes). When enabled, GTM4WP listens for CookieYes' documented consent banner action API events (`cookieyes_consent_update` and `cookieyes_banner_load`) and pushes a `cookie_consent_update` data layer event carrying the accepted/rejected categories, giving your container a defined consent signal to sequence tags on. It does not defer or buffer any events - the correct fix for the ordering of e-commerce events versus consent is Google Consent Mode v2, which gates tags regardless of data layer push order. **Off by default** (experimental).
* Deprecated: the "WebToffee GDPR Cookie Consent (v2.x)" integration - it only targets the long-outdated v2.x product line. WebToffee v3.x and above integrate with Google Tag Manager natively, so the option is unnecessary there; upgrade the WebToffee plugin instead. The option is now flagged as deprecated in the settings screen.

### Contact Form 7

* Updated: Contact Form 7 integration modernized against the current CF7 DOM events. Three new events are tracked - `gtm4wp.contactForm7BeforeSubmit` (before validation), `gtm4wp.contactForm7Unaccepted` (acceptance/terms checkbox not ticked) and `gtm4wp.contactForm7Aborted` (submission aborted) - and every CF7 data layer push now also carries the form's `unittag`, `containerpostid`, `locale` and `status` (the existing `formid` and `inputs` fields are unchanged). File inputs are reported by their file name instead of a raw File object. The tracker also guards against registering its data layer events twice when the script runs more than once (e.g. re-injected by a page builder or after an AJAX navigation).
* Updated: every Contact Form 7 data layer push now also carries the human-readable form name (`formname`), sourced from a `data-gtm4wp-form-name` attribute added to the rendered form (the CF7 DOM events only expose the numeric form ID).
* Added: "Submitted field values in the data layer" option for the Contact Form 7 integration (`integrate-wpcf7-inputs`). Defaults to "Full" (the existing behavior); can be set to "Field names only" or "None" to keep submitted personal data out of the data layer.
* Added: "Also push GA4 recommended events" option for the Contact Form 7 integration (`integrate-wpcf7-ga4events`, off by default). When enabled, the tracker also pushes the Google Analytics 4 recommended form events - `form_start` (on first field interaction), `form_submit` (on submit) and `generate_lead` (on a successful send) - each with `form_id`, `form_name` and `form_destination`, alongside the existing `gtm4wp.contactForm7*` events.

### AMP

* Fixed: AMP integration now works in the AMP plugin's Standard, Transitional and Reader (theme) modes - previously the GTM amp-analytics tag was only emitted in the AMP plugin's deprecated Legacy Reader mode, so enabling the AMP Container ID produced no tracking on modern AMP setups. Migrated to the AMP plugin's `amp_analytics_entries` API (which also auto-loads the correct `amp-analytics` component script) and to the current `amp_is_request()` function (replacing the deprecated `is_amp_endpoint()`).
* Fixed: AMP data layer injection - 1.x checked a never-populated global and never injected the data layer into AMP pages.
* Fixed: the standard GTM container `<script>` is no longer emitted on AMP pages (it was invalid AMP and stripped by the AMP sanitizer anyway); the data layer is still compiled so the amp-analytics integration keeps its values.
* Changed: the AMP container ID setting is promoted from experimental to stable.

### Removed

* Removed: weather and geo data features (ipstack.com / OpenWeatherMap integrations).
* Removed: scroll tracking feature - use Google Tag Manager's built-in Scroll Depth trigger instead. The `GTM4WP_OPTION_SCROLLER_*` constants remain in place for backward compatibility.

## 1.22.5

A maintenance release for the 1.x line. 1.22.4 was intended to be the last one before GTM4WP 2.0; this release exists because the fixes below are worth shipping to 1.x users rather than holding for 2.0.

* Fixed (security): hardened how the hidden product-data attribute is built for WooCommerce product lists and cart remove links, so that no product field value can affect the surrounding HTML. Certain values were not guaranteed to stay inside the attribute.
* Fixed: when a custom X-Forwarded-For header is configured as the visitor IP source, all entries of the header are now evaluated. Only the first entry was ever considered, because the remaining ones were not trimmed of the space that follows each comma and therefore failed IP validation.
* Fixed: the custom visitor IP header name is now validated in full. The check accepted any value that contained at least one valid character, so an invalid header name passed validation and was then simply never found. Setups with a working header name are unaffected.

## 1.22.4

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

## 1.22.3

* Fixed: when timeout for the select_item event with WooCommerce is set to 0, plugin does not halt the browser from loading the product page
* Fixed: properly reading timeout for select_item eventCallback

## 1.22.2

* Fixed: purchase event was not fired when is_order_received_page() WooCommerce tag was not supported by the template and the fallback method had to activate.

## 1.22.1

* Fixed: yet another fix to the purchase tracking. Thanks [Khnaz35](https://github.com/Khnaz35)

## 1.22

* Added: new WooCommerce option to change the event timeout of the select_item event. Could help some UX issues when a user has an ad blocker installed.
* Added: new WordPress filter GTM4WP_WPFILTER_OVERWRITE_COMO_FLAG / gtm4wp_overwrite_consent_mode_flag to overwrite Consent Mode flags in the default command. Can be used by consent banner plugins to mitigate support issues.
* Fixed: Unwanted content on frontend when container placement set to off and gtm4wp_the_gtm_tag() called manually. Thanks [Michael Bourne](https://wordpress.org/support/users/michaelbourne/)
* Fixed: Add to cart tracking broken with Bricks template and WooCommerce
* Updated: bundled WhichBrowser library updated to the latest version. This is a short term solution to solve PHP errors, on the long term, the plan is to switch to [Matomo Device Detector](https://github.com/matomo-org/device-detector)
* Updated: allow the / character in GTM container path to support same tag gateway
* Updated / Fixed: I reverted back the purchase tracking with WooCommerce to the logic present in v1.20- as the new way caused more headache than it resolved. Sorry for everyone who was affected by this.

## 1.21.1

* Fix: undefined product_qty variable prevents add_to_cart in some cases. Thanks [diegoarda](https://github.com/diegoarda)
* Fix: clarified that WebToffee integration is only needed with the legacy 2.x product line
* Fix: Reflected Cross-Site Scripting (XSS) with site search tracking. Thanks [godzeo](https://github.com/godzeo)!

## 1.21

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

## 1.20.3

* Fix: notice "_load_textdomain_just_in_time was called incorrectly" - WordPress 6.7 compatibility

## 1.20.2

* Fix: Wrong ID parameter used tracking product variations (id instead of item_id). Thanks [micmaf](https://github.com/micmaf)
* Fix: do not track add_to_cart in product lists when 'Select options' or 'View products' buttons are clicked
* Fix: JavaScript reference error while updating cart count in WooCommerce. Thanks [Sakuk3](https://github.com/Sakuk3)
* Fix: hash customerBillingEmailHash just like orderData.customer.billing.email_hash (for enhanced conversions, use the value in orderData)
* Added: minimum required WooCommerce version (currently 5.0+) is displayed now on GTM4WP admin

## 1.20.1

* Fix: do not load GTM container when OFF and console.log OFF. Thanks [morvy](https://github.com/morvy)
* Fix: reverted a change where item_id in ecommerce data layer was converted to numeric type when the value was a numeric value. It will be kept as a string to preserve compatibility with other integrations.
* Fix: JavaScript error when element ID not set correctly in scroll tracking
* Changed: better normalize and hash user data with WooCommerce + orderData variable for enhanced conventions
* Changed: PHP code optimization by [hans2103](https://github.com/hans2103)
* Added: New filter to be able to modify data in the orderData variable on a WooCommerce order received page. Filter can be accessed either using the GTM4WP_WPFILTER_EEC_ORDER_DATA constant or the gtm4wp_eec_order_data string.

## 1.20

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

## 1.19.1

* Fixed: run additional checks when determining product category to prevent PHP errors in certain cases
* Fixed: corrected Consent Mode flag names in admin page description texts

## 1.19

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

## 1.18.1

* Fixed: PHP notice about Undefined variable: blocking_cookie

## 1.18

* Fixed: error while checking the new customer status in WooCommerce on the order received page (thanks [morvy](https://github.com/morvy))
* Fixed: Call to a member function get_meta() on null error on WooCommerce order received page (thanks [Dekadinious](https://github.com/Dekadinious))
* Updated: WordPress and WooCommerce compatibility
* Updated: Deprecated Google Optimize integration as the tool is sunsetting in September 2023
* Added: Create a cookie named block_gtm4wp_geoip after a specific user selected cookie preferences. Set the value to either "yes", "true", "on" or "1" and the GeoIP (and weather API) feature will be disabled for that particular user

## 1.17

* Added: pagePostTerms data layer variable will now also include a meta key with post meta values that does not start with the _ character. This should allow to utilize custom fields added by plugins like Advanced Custom Fields.
* Added: new filter: gtm4wp_post_meta_in_datalayer to limit which post meta should be present with with the previously added feature.
* Added: ability to use a custom path for your server side GTM container. This makes GTM4WP compatible with services like stape.io
* Added: new advanced option to disable browser console.log() messages on frontend
* Added: new filter: gtm4wp_purchase_datalayer to be able to alter data layer content for the purchase data
* Added: new filter: gtm4wp_datalayer_on_pageload to be able to alter data layer content generated during page load
* Added: customerBillingState and customerShippingState on WooCommerce order received page, thanks [massimo-maimeri](https://github.com/massimo-maimeri)
* Added: High Performance Order Storage (HPOS) compatibility for WooCommerce
* Updated: WooCommerce integration will also check target attitbute of product links while firing select_item event, thanks [robklo])(https://github.com/robklo)
* Updated: YouTube tracking will not load on pages not containing a YouTube block or a YouTube iframe
* Fixed: add_to_cart event on grouped products not working when SKU is selected as product ID in plugin options.
* Fixed: more reliable new_customer flag working with guest orders of existing customers, thanks [morvy](https://github.com/morvy)

## 1.16.2

* Fixed: server side GTM hostname did not work if domain name included a hyphen character
* Fixed: user login and user registration data layer events were swapped, thanks [danvy](https://github.com/danvy) for the fix
* Fixed: JavaScript error in Chrome around event.target.closest calls, thanks [pinkasey](https://github.com/pinkasey) for the fix
* Fixed: cast _ga_tracked variable as integer to make the identical operator work correctly, thanks [Irfan](https://github.com/in-codes) for the suggestion
* Fixed: removed extra code added in 1.14 that reloads pages in Safari in WooCommerce integration. It broke some sites.
* Updated: required PHP version raised to 7.4. Currently, this is not a hard requirement but from now I could include updates that will require this PHP version.

## 1.16.1 = 

* Fixed: GTM ID not properly set in noscript tag (probably fixes some 403 errors with firewalls too)

## 1.16

This plugin version does not add or update any functionality.
After recent events, the code of the plugin has been checked line by line to see where additional security checks can be added.
The code has been formatted to better support readability for other programmers.

Deprecated:
* gtm4wp_get_the_gtm_tag hook and the corresponding GTM4WP_WPFILTER_GETTHEGTMTAG PHP constant.
* gtm4wp_add_global_vars hook and the corresponding GTM4WP_WPFILTER_ADDGLOBALVARS PHP constant. Use gtm4wp_add_global_vars_array / GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY instead.
* gtm4wp_after_datalayer hook and the corresponding GTM4WP_WPACTION_AFTER_DATALAYER PHP constant. Use gtm4wp_output_after_datalayer / GTM4WP_WPACTION_AFTER_DATALAYER instead witch can be used in the same way but it is an action instead of a filter.

Upcoming version will come with important changes:
* Minimum PHP version will be raised to 7.4: this will allow me to add even more safety measures
* Minimum supported WooCommerce version will be raised to WooCommerce 5.0: with this I can remove some very old compatibility code
* Deprecated features will be removed (aims to simplify code for better maintenance):
  * Do not track flag of the browser added into data layer
  * Legacy version of WooCommerce dynamic remarketing (using ecomm_ parameters)

The goal of all these changes aim to keep the plugin code clean and free from legacy solutions.

## 1.15.2

* Fixed: Stored XSS when using the scroll tracking feature and an admin changes the content element ID into a JavaScript code.
* Deprecated option: 'do not track' flag of the browser. This browser feature itself [is now deprecated](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/DNT)

Full scan of the plugin is also in works to fix any other possible XSS issue.

## 1.15.1

* Fixed: JavaScript error with the newly added console logging to debug code placement issues
* Fixed: possible XSS Vulnerability if Cloudflare country code option enabled. Thanks [Guillaume Fortier](https://www.linkedin.com/in/guillaume-f-a728711b0/)
* Fixed: proven XSS Vulnerability if adding site search into the data layer was enabled. Original report by [not_stoppable](https://hackerone.com/not_stoppable?type=user). Root cause analysis by [Cory Buecker](https://www.linkedin.com/in/corybuecker/).
* Removed: deprecated feature Google Ads remarketing. This is the outdated, classic way using the google_tag_params variable.
* Dev: removed PHP constant GTM4WP_WPFILTER_COMPILE_REMARKTING (related to removed Google Ads remarketing feature)
* Dev: removed gtm4wp_compile_remarkering WordPress filter (related to removed Google Ads remarketing feature)

Note to plugin users: I sincerely apologize for the vulnerabilities. To make sure, such cases do not happen again,
the next version will be fully dedicacted to go through every peace of code and make sure proper data processing is happening in GTM4WP.

## 1.15

* Added: pagePostType data layer variable will now return 404-error on 404 pages and search-results on search result pages
* Added: Google Tag Manager container code can be disabled for specific WordPress user roles under Advanced plugin options. A browser console warning will be shown in such cases to prevent confusion
* Added: support for all Contact Form 7 events for more granual tracking: gtm4wp.contactForm7MailSent, gtm4wp.contactForm7MailFailed, gtm4wp.contactForm7SpamDetected, gtm4wp.contactForm7InvalidInput
* Added: additional data layer variables for date attributes: pagePostDateDayName, pagePostDateHour, pagePostDateMinute, pagePostDateIso, pagePostDateUnix - by [ajtatum](https://github.com/ajtatum)
* Fixed: unclickable products in WooCommerce product lists in Firefox when visiting site in Strict privacy mode or using private browsing
* Fixed: tracking step 2 on WooCommerce checkout page was broken
* Updated: removed CDATA blocks as they are not required in simple HTML and they break some cases where code optimizer is being used
* Updated: products per impression in WooCommerce integration now defaults to 10 instead of 0. This allows view_item_list event to fire on new sites as well
* Updated: code placement options. Separated container on/off option and replaced code placement with the new terminology: compatibility mode
* Updated: removed optional chaining operator usage (?.) in JavaScript codes for better compatibility with outdated browsers
* Updated: changed 'Do not flag orders as being tracked' description to be more precise about what happens if turned on or left off
* Updated: if you enter your custom domain name for server side tagging with the https:// prefix, it will be removed before domain name validation
* Updated: all script blocks to be ignored by Cookiebot if this integration is enabled
* Updated: do not track WooCommerce order where payment failed

## 1.14.2

* Fixed: undefined google_business_vertical
* Fixed: missing product price in product impression data
* Fixed: better compatibility with cache plugins and lazy load functionalities
* Fixed: Added optional chaining operator to form move tracker code

## 1.14.1

* Bugfixes

## 1.14

* Added: support for tracking WooCommerce Block based product lists, except the "All Products" block
* Added: support for [new_customer parameter](https://support.google.com/google-ads/answer/9917012?hl=en-AU#zippy=%2Cinstall-with-google-tag-manager) for Google Smart Shopping campaigns
* Added: SHA256 hashed versions of data layer variables containing email addresses: customerBillingEmailHash on WooCommerce order received pages and visitorEmailHash on generic uses cases
* Added: WooCommerce - if for some reason is_order_received_page() reports false on the order received page, woocommerce_thankyou hook will be used as backup
* Updated: removed jQuery dependency from plugin modules: contact form 7 integration, form move tracker, Vimeo, YouTube, Soundcloud, partly WooCommerce
* Updated: moved the hidden helper span element in products lists to the end of the product box to make more compatible with themes
* Updated: more consistent retrieval of product categories  - by [Dekadinious](https://github.com/Dekadinious)
* Updated: gtm4wp_product_readded_to_cart cookie replaced with a WooCommerce session variable to use fewer cookies in this plugin
* Updated: gtm4wp_user_logged_in, gtm4wp_user_registered and gtm4wp_last_weatherstatus cookies are now HTTP only cookies
* Updated: replaced deprecated jQuery method and event usage in WP admin
* Updated: added rel="noopener" to links pointing to external sites on WP admin page
* Updated: Hiding the iframe tag from assistive technologies as it provides no functionality for the end user. This will also alleviate a11y audit warnings.
* Fixed: proper values for visitorType data layer variable
* Fixed: replaced unsafe usage of eval() in WooCommerce QuickView plugin integration
* Fixed: type check of the order ID obtained from a cookie before using the value
* Fixed: navigation issues in Safari if browser loads previous page from cache. GTM4WP will now force Safari to always reload pages.
* Fixed: Do not trigger browser change event in WooCommerce checkout page submit event handler. It caused issues with other 3rd party plugins.
* Fixed: HTML5 detection. - by [Sjoerd](https://github.com/sjoerdkoelewijn)
* Fixed: Username not included in datalayer if no other user attribute is included - by [StaymanHou](https://github.com/StaymanHou)

## 1.13.1

* Fix: better PHP8 compatibility
* Fix: PHP notice on admin page

## 1.13

WARNING!
If you are using the geo or weather options of this plugin, make sure your hosting is using PHP 7.0 or newer!

If you are using the WooCommerce integration with enhanced ecommerce, once again you will need to update your GTM container.
Please check the [setup article](https://gtm4wp.com/how-to-articles/how-to-setup-enhanced-ecommerce-tracking) to see what needs to be changed.
The goal is to keep this setup in the upcoming versions.

* Added: Google Business Vertical option to populate Google Ads dynamic remarketing
* Added: Make automatic cookie blocking mode of Cookiebot to work with your Google Tag Manager container (new option in the Integration tab)
* Added: support for [server side containers with custom domains](https://developers.google.com/tag-manager/serverside/send-data#update_the_gtmjs_source_domain)
* Added: improved duplicate WooCommerce order tracking prevention by also checking the age of the order. You can adjust the value in minutes on the plugin options page - by [Code-Craze](https://github.com/Code-Craze)
* Fixed: prices rounded to 2 decimals in the data layer (WooCommerce integration)
* Fixed: select_item event fired multiple times (at least twice)
* Fixed: frontend protection to preveent double tracking transactions had a bug
* Fixed: missing product detail and first checkout step tracking in WooCommerce shops with Enhanced Ecommerce
* Fixed: JavaScript type error if product category is a number
* Updated: WhichBrowser v2.1.1 (requires PHP 7.0 or newer)
* Updated: descriptions of WooCommerce tracking methods to emphasize the importance to migrate from standard to enhanced ecommerce
* Updated: added links to enhanced ecommerce setup guides (GA3 and GA4)

## 1.12.3

No new or updated functionality, but updated WooCommerce compatibility.

IMPORTANT!
If you are using the WooCommerce integration and enhanced ecommerce, please update your ecommerce event trigger to include gtm4wp.orderCompletedEEC as well.
https://gtm4wp.com/how-to-articles/how-to-setup-enhanced-ecommerce-tracking

## 1.12.2

If you are upgrading from 1.11.x, please read the previous changelog entry for v1.12

* Fixed: error message in WooCommerce integration on PHP 8 hosts
* Fixed: PHP notice about non existing blacklist-enable array key

## 1.12.1

If you are upgrading from 1.11.x, please read the previous changelog entry for v1.12

* Fixed: PHP notice while saving admin options

## 1.12

WARNING!
If you are using the blacklist/whitelist feature of the plugin, review these options after upgrade as they could break because of a fundamental rework of this feature.

* Added: support for Google Analytics 4 version of ecommerce data layer
* Fixed: safer IP address validation in geo and weather data features
* Updated: removed deprecated events: download links, email links, social links -> such can be now tracked with native Google Tag Manager triggers
* Updated: removed support for WooCommerce versions before v3.2
* Updated: 'Blacklist tags' tab renamed to 'Security'
* Updated: complete rework of blacklist/whitelist feature to use the latest tag/trigger/variable list of Google
* Deprecated: classic ecommerce tracking will be removed later this year, please upgrade to enhanced ecommerce tracking
* Deprecated: standard Google Ads remarketing variable will be removed soon as the Google Ads remarketing tag template can easily use any of your Google Tag Manager variables
* Deprecated: the old fashioned way of using Google Ads remarketing with the ecomm_ prefixed data layer variables will be removed soon. Instructions for upgrade will be published on gtm4wp.com once this feature gets updated in an upcoming plugin version

## 1.11.6

* Fixed: do not track hidden products in the cart in WooCommerce shops
* Fixed: do not fire add to cart event if button is in a disabled state
* Fixed: fixed translation of an admin text
* Fixed: needs_shipping_address() calls were sometimes broken in WooCommerce shops, added additional checks to prevent
* Updated: removed the body_class method of adding the iframe/noscript container code (page builders and the standard wp_body_open hook remains supported)

## 1.11.5

* Fixed: new Google Optimize container ID format accepted now

## 1.11.4

* Fixed: fire gtm4wp.checkoutStepEEC and gtm4wp.checkoutOptionEEC events if there is only one shipping method available and it is hidden from the user
* Fixed: decrease checkout step numbers 3 and 4 if shipping destination is set to 'Force shipping to the customer billing address' in WooCommerce. This way there will be no gap in Checkout behaviour report in Google Analytics.
* Updated: tested version number for WooCommerce

## 1.11.3

* Fixed: use var_export instead of var_dump in some debug code,
* Fixed: apply WooCommerce option for tax inclusion on the order received page as well
* Fixed: JavaScript errors in Vimeo player
* Updated: do not add type attribute to script elements if theme supports HTML5
* Updated: tested version numbers for WordPress and WooCommerce

## 1.11.2

WARNING!
If you are upgrading directly from v1.10.x, please read the changelog of v1.11 since it includes many important notices!

No change in plugin code, WP.org deployment of v1.11.1 included wrong directories

## 1.11.1

WARNING!
If you are upgrading directly from v1.10.x, please read the changelog of v1.11 since it includes many important notices!

* Fixed: PHP notice about undefined order_items variable if the new 'Order data in data layer' is turned off
* Fixed: PHP notice about missing brand array key if no brand taxonomy is selected in GTM4WP options

## 1.11

WARNING!
Please read the changelog very carefully as there are many important changes and removed features which could need your attention before updating!

* Added Oxygen Builder and Beaver Builder Theme support - you can now use the codeless placement option without issues
* Added ability to fix the Google Tag Manager ID and GTM Environment parameters in wp-config.php. To use it, create PHP constants with the names
  * GTM4WP_HARDCODED_GTM_ID
  * GTM4WP_HARDCODED_GTM_ENV_AUTH
  * GTM4WP_HARDCODED_GTM_ENV_PREVIEW
* Added support for WooCommerce Grouped Products
* Added new WooCommerce option to add all order data into the data layer on the order received page
  * This includes personal data of the customer -> you need to ensure this is used in a privacy friendly and compliant way!
  * This order data will be always present on the order received page, even if the page is reloaded or later revisited!
* Removed several unofficial data layer variables on the WooCommerce order received page as they can be read using the new order data option
  * transactionDate
  * transactionType
  * transactionPaymentType
  * transactionShippingMethod
  * transactionPromoCode
* Improved: price reporting with the WooCommerce enhanced ecommerce integration now follows the option set with the 'Display prices in the shop' option of WooCommerce
* Improved: from WooCommerce 3.7 WC_Abstract_Order::get_used_coupons() was replaced with WC_Abstract_Order::get_coupon_codes() which is now used if WC 3.7+ is detected
* Improved: use localStorage for WooCommerce duplicate transaction tracking prevention if available. Should be work with Safari at least for now.
* Fixed: WooCommerce duplicate transaction tracking prevention's cookie was set to expire on session end, now adds 1 year.
* Deprecated data layer variable productIsVariable. Use the new productType data layer variable which will equal to simple, variable, grouped or external depending on the type of the product shown
* Fixed: Wrong lookup for product brand name if Use SKU instead of product ID option was turned on
* Fixed: Wrong lookup for product brand name for variable products
* Fixed: check if $woo->customer is initialized
* Fixed: no checkout step reported on WooCommerce checkout page if the user has accepted the default selection of the payment and shipping methods

! Planned deprecation of support for WooCommerce 2.x-3.1.x with next plugin version !
! Planned deprecation of support for WordPress 4.x with next plugin version !

## 1.10.1

* Fixed: wrong cookie name was used with the newly introduced double transaction tracking protection while setting the cookie
* Fixed: double transaction tracking JavaScript code is now only included on the order received page
* Fixed: product impressions not properly reported if Products per impressions were set to 0
* Fixed: replaced all references to AdWords to Google Ads

## 1.10

* Added: Automatically add the noscript part of the container code after the opening body tag for WordPress 5.2+ sites where themes support the new wp_body_open action
* Added: add associated taxonomy values for post type
* Added: select brand taxonomy for WooCommerce products to populate "Product brand" dimension in enhanced ecommerce
* Added: add cart content into data layer so that you can personalize your site experience using Google Optimize
* Added: option to remove shipping costs from revenue data on order received page of WooCommerce
* Added: if you enable either enhanced ecommerce or just Google Ads remarketing variables, 3 new data layer variables will be also available about the product on a detail page
  * Product rating details (productRatingCounts)
  * Average product rating (productAverageRating)
  * Review count (productReviewCount)
* Added: if you are using Cloudflare, you can now add the country code HTTP header value into the data layer and read from it with the geoCloudflareCountryCode variable name
* Fixed: better compatibility with Google's mod_pagespeed
* Fixed: missing product quantity while adding a variable product into the cart
* Fixed: prevent multiple tracking of WooCommerce orders on mobile devices where the mobile browser reloads the order received page from local cache executing GTM tracking again

## 1.9.2

* Fixed: possible PHP warning if geo data or weather data feature is turned on

## 1.9.1

* Fixed: handle out of quota cases with ipstack queries properly
* Fixed: proper YouTube tracking for WordPress sites and WordPress multisites installed in a subdirectory
* Fixed: properly detect client IP address and also properly escape this data while using it
* Fixed: WooCommerce checkout steps after page load did not include products in the cart
* Fixed: checkout step events for payment mode and shipping type not always fired
* Fixed: the CMD on Mac will be treated just like the Ctrl key on Windows while processing the product click event in the WooCommerce integration (thy for luzinis)
* Fixed: add currencyCode to every ecommerce action in WooCommerce integration
* Fixed: better WooCommerce Quick View integration
* Fixed: possible cross site scripting vulnerability if site search tracking was enabled due to not properly escaped referrer url tracking
* Changed: code cleanup in WooCommerce integration

## 1.9

* Added: initial support for AMP plugin from Automattic (thx koconder for the contribution!)
* Added: option to remove tax from revenue data on order received page of WooCommerce
* Added: WooCommerce enhanced ecommerce datasets now include stock levels
* Added: new productIsVariable data layer variable is set to 1 on variable WooCommerce product pages
* Added: product impressions can now be split into multiple chunks to prevent data loss on large product category and site home pages  (thx Tim Zook for the contribution!)
  * IMPORTANT! You will need to update your GTM setup, please read the new Step 9 section of the [setup tutorial page](https://gtm4wp.com/how-to-articles/how-to-setup-enhanced-ecommerce-tracking).
* Added: you can now disable flagging of WooCommerce orders as being already tracked once. In same cases (with iDeal for example) you may need this to make purchase tracking to work.
* Added: uninstalling the plugin will now remove configured plugin options from database
* Added: new advanced plugin option: data layer variable visitorDoNotTrack will include 1 if the user has set the [do not track flag](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/DNT) in his/her browser
* Added: new data layer event when a user has logged in on the frontend: gtm4wp.userLoggedIn
* Added: new data layer event when a new user has registered on the frontend: gtm4wp.userRegistered
* Added: new advanced plugin option: move data layer declaration and Google Tag Manager container as close as possible to the beginning of the HTML document
* Added: better WP Rocket support
* Updated: Full Google Optimize support. Now the plugin can load your Google Optimize container with the [recommended code placement](https://support.google.com/optimize/answer/7359264?hl=en)
* Updated: moved most of the inline JavaScript codes into separate .js files which should help cache plugins to do their job much better when my plugin is active
* Fixed: wrong ecomm_pagetype on product search result pages
* Fixed: PHP notice in some cases when geo data was not loaded properly
* Fixed / Added: freegeoip.net was rebranded to ipstack.com and an API key is needed now even for free usage. You can now add your API key so that weather data and geo data can be added into the data layer
* Warning: some plugin features will be remove from v1.10, most of them can be tracked now using pure Google Tag Manager triggers:
  * Social actions
  * Outbound link click events
  * Download click events
  * Email click events
* Warning: PHP 5.6 is now the minimum recommended version to use this plugin. I advise to move to PHP 7.x

## 1.8.1

* Added: new visitorIP data layer variable to support post-GDPR implementations where for example internal traffic exclusion has to be made inside the browser
* Fixed: JavaScript error around the variable gtm4wp_use_sku_instead
* Fixed: added _ as a valid character for gtm_auth GTM environment variable
* Fixed: corrected typo - gtm4wp.checkoutStepE**E**C
* Fixed: two strings were not recognized by WordPress Translate on the admin page
* Fixed: some other plugins call found_variation event of WooCommerce without product variation data being included
* Fixed: product name included variation name on order received page which broke GA product reports
* Fixed: in some cases, no contact form 7 data was being passed to the gtm4wp.contactForm7Submitted event
* Updated: added CDATA markup around container code for better DOM compatibility
* Updated: removed 'SKU:' prefix text from classic ecommerce dimension as it broke some enhanced ecommerce reports

## 1.8

* Fixed: weather data tracking codes could result in fatal PHP error
* Fixed: cart events did to fire while user pressed the Enter key in quantity fields
* Fixed: contact form 7 changed some code which prevented successful form submission tracking
* Changed: links to plugin website updated
* Changed: gtm4wp.cf7formid data layer variable now includes the ID of the form in WordPress
* Added: gtm4wp.cf7inputs includes data that has been filled in the form
* Added: [WooCommerce compatibility headers](https://docs.woocommerce.com/document/create-a-plugin/#section-10)
* Added: admin warning for WooCommerce 2.x users. This plugin will drop support for WooCommerce 2.x soon
* Added: postFormat data layer variable on singular pages
* Added: customer* data layer variables with stored billing and shipping data, total number of orders and total value of those orders (needs WooCommerce 3.x)
* Added: geo* data layer variables to get country, city, lat-lon coordinates of the visitor
* Added: visitorUsername data layer variable with the username of the logged in user
* Added: more detailed checkout reporting for WooCommerce sites
  * Add gtm4wp.checkoutStepEEC to your Ecommerce Helper trigger
  * Change a typo: gtm4wp.checkoutOptionE**C**C => gtm4wp.checkoutOptionE**E**C
* Added: option to include full product category path in enhanced ecommerce reporting (can cause performance issues on large sites!)
* Added: initial support for [Google Tag Manager Environments](https://support.google.com/tagmanager/answer/6311518?hl=en)
* Added: support for [WooCommerce Quick View plugin](https://woocommerce.com/products/woocommerce-quick-view/)
* Updated: description of code placement options to clarify what this option does
* Updated: cleanup of readme.txt, spelling and grammar improvements
* Updated: bundled WhichBrowser lib v2.0.32


## 1.7.2

* Fixed: in some cases, the remove item from cart link in a WooCommerce cart was not altered properly with additional tracking codes
* Fixed: product categories were empty in the cart, on the checkout pages and on the order received page for product variations
* Fixed: checkout option data included checkout step #1 if cart page was setup to be the first
* Fixed: even more WooCommerce 3.x compatibility
* Added: registration date of the logged in user can be added to the data layer
* Updated: geoplugin.net has been replaced by freegeoip.net for weather tracking which has far better quota for free usage
* Updated: Google Ads dynamic remarketing data layer items on a WooCommerce product page will be shown for the root product as well on variable product pages
* Updated: Selecting a product variation will include the price of the product in Google Ads dynamic remarketing data layer items
* Updated: minor code cleanup

## 1.7.1

* Fixed: PHP 5.3 compatible syntax in frontend.php
* Fixed: PHP error using classic ecommerce with WooCommerce 2.6.x
* Updated: Added data-cfasync='false' to all <script> elements to prevent CloudFlare to load scripts async
* Added: Warning for users of PHP 5.4 or older to consider upgrade (FYI: PHP 5.5 and older versions do not get even security fixes)

## 1.7

* Updated: even better WooCommerce 3.0 compatibility (WooCommerce 2.6 still supported but this support ends with the next plugin version)
* Fixed: properly escaping product category name on variable product detail pages
* Fixed: proper data layer structure in the gtm4wp.changeDetailViewEEC event
* Added: Google Optimize page hiding snippet under Integrations tab
* Added: add to cart data for WooCommerce enhanced ecommerce tracking if user undos a cart item removal (no need to update GTM tags)
* Added: you can now enter a product ID prefix so that IDs can match with IDs in some product feeds generated by other plugins
* Added: option to track cart page as step 1 in enhanced ecommerce checkout funnel

## 1.6.1

* Fixed: PHP warning message on WooCommerce cart page
* Fixed: Better compatibility with WooCommerce 2.6.x :-)

## 1.6

* Fixed: do not block product list item clicks if ad blocker is enabled
* Fixed: only track product clicks in product lists if link points to the product detail page URL
* Fixed: PHP warning in backlogs 'Undefined variable: gtm4wp_options'
* Added: product variation support in WooCommerce integration (enhanced ecommerce implementations should add the GTM event gtm4wp.changeDetailViewEEC to the ecommerce event trigger)
* Updated: better WooCommerce 3.0 compatibility

## 1.5.1

* Fixed: clicks on products in product list pages redirected to undefined URLs with some themes.

## 1.5

Lots of WooCommerce ecommerce codes has been changed and extended, please double check your measurement after upgrading to this version!

* Added: warning message if you are using PHP 5.3 or older. Browser/OS/Device tracking needs 5.4 or newer
* Added: Email address of the logged in user into the visitorEmail dataLayer variable. Remember: to comply with GTM TOS you are not allowed to pass this data towards any Google tag but you can use this in any other 3rd party tag.
* Added: gtm4wp_eec_product_array WordPress filter so that plugin and theme authors can add their own data for enhanced ecommere product arrays
* Fixed: JavaScript error in WooCommerce stores when enhanced ecommerce enabled and a product being clicked in a widget area
* Fixed: Order data not present in some cases on the order received page
* Changed: Extended "User SKUs instead of IDs for remarketing" option to be also applied to ecommerce product data arrays
* Changed: Use wc_clean instead of the deprecated function woocommerce_clean
* Changed: New, divided GTM container implemented - a fixed part in the <head> and an iframe part placed using the container placement option you've set earlier

## 1.4

* Fixed: WP CLI error message
* Fixed: wrong dynamic remarketing tagging on cart and checkout pages
* Updated: WhichBrowser library to 2.0.22
* Updated: slightly changed container code snippet to prevent W3 Total Cache to alter the code which breaks proper code execution
* Updated: replaced file_get_contents() usage in weather tracking to wp_remote_get() so that it is more compatible with several WP instances
* Updated: YouTube/Video/Soundcloud tracking now tracks videos not embedded using oEmbed (like videos in a widget area)
* Updated: new Vimeo Player API implemented which should solve several issues
* Changed: adapted W3C HTML5 media player event names which changes some events (needs updating your existing GTM setup):
  * Soundcloud: finish => ended, seek => seeked
  * YouTube: playing => play, paused => pause, playback-rate-change => ratechange
  * Vimeo: seek => seeked
* Added: new placement option - 'off'. This will only generate the data layer but you will need to add the proper GTM container code snippet by hand
* Added: new data layer variable: authorID
* Added: new data layer variable: siteID to be able to track based on blog ID in a multisite environment
* Added: new data layer variable: siteName to be able to track in a multisite environment

## 1.3.2

* Fixed: remove cart event not fired in WooCommerce 2.6
* Fixed: ecomm_prodid.push error message on product detail pages
* Fixed: proper tracking of cart actions on the cart page for WooCommerce 2.6
* Fixed: 'Illegal string offset' errors in some cases in the cart
* Fixed: OpenWeatherMap requires a (free) API key now, you can now enter this to use weather data in data layer

## 1.3.1

* Fixed: "json_encode() expects parameter 2 to be long, string given" on PHP 5.3 instances
* Fixed: Fatal PHP error in cart if you enabled taxes to be included in your cart

## 1.3

Major changes to the Enhanced Ecommerce implementation of the WooCommerce integration!

* Fixed: proper tracking of list positions
* Fixed: opening product detail page in a new window/tab when user pressed the CTRL key
* Fixed: ecomm_totalvalue included the total price of the cart without taxes
* Fixed: ecomm_totalvalue does not take into account the quantity of ordered products on the order received page
* Fixed: php error message on product lists when Google Ads dynamic remarketing was enabled on WooCommerce 2.6
* Fixed: added data-cfasync="false" to the GTM container code for better compatibility with CloudFlare
* Added: introducing tracking of list names (general product list, recent products list, featured products list, etc.)
  * Some list names (like cross-sells) will be shown as 'General Product List'. A proposed change in WooCommerce 2.6 will solve that issue
* Added: tracking product lists in widgets
* Added: tracking checkout options (payment and shipment)
* Updated: better add-to-cart / remove-from-cart management in mini cart and while updating cart content
* Updated: added currency code to each enhanced ecommerce call so that currency reporting is OK for multi currency sites
* Updated: replaced usage of get_currentuser() to keep compatibility with WordPress 4.5

## 1.2

* Fixed: subtabs on admin page now showing in certain cases
* Fixed: error message when running the site using WP CLI (thanks Patrick Holberg Hesselberg)
* Fixed: some typos on admin page
* Fixed: dismissable notices did not disappear in some cases
* Fixed: tracking of Twitter event cased sometimes JS errors
* Fixed: site search tracking caused sometimes PHP errors when HTTP_REFERER was not set
* Updated: preparation for translate.wordpress.org
* Added: support for multiple container IDs
* Added: added form ID when sending a Contact Form 7 form. Variable name: gtm4wp.cf7formid

## 1.1.1

* Fixed: PHP errors in frontend.php and admin.php

## 1.1

* Added: track embedded YouTube/Vimeo/Soundcloud videos (experimental)
* Added: new checkbox - use product SKU for Google Ads Dynamic Remarketing variables instead of product ID (experimental)
* Added: place your container code after the opening body tag without modifying your theme files (thx Yaniv Friedensohn)
* Added: automatic codeless container code injection for Genesis framework users
* Fixed: Possible PHP error with custom payment gateway (QuickPay) on the checkout page (thx Damiel for finding this)

## 1.0

The plugin itself is now declared as stable. This means that it should work with most WordPress instances.
From now on each version will include features labeled as:

* Beta: the feature has been proven to work for several users but it can still have some bugs
* Experimental: new feature that needs proper testing with more users
* Deprecated: this feature will be removed in a future version

If you see any issue with beta or experimental functions just disable the checkbox. Using this error messages should disappear.
Please report all bugs found in my plugin using the [contact form on my website](https://gtm4wp.com/contact).

* Fixed: wrong GTM container code when renaming default dataLayer variable name (thx Vassilis Papavassiliou)
* Fixed: Enhanced Ecommerce product click data was "undefined" in some cases (thx Sergio Alen)
* Fixed: wrong user role detection while adding visitorType to the dataLayer (thx Philippe Vachon-Rivard)
* Changed: only add visitorId to the dataLayer if there is a logged in user
* Added: feature labels so that you can see beta, experimental and deprecated features
* Deprecated: outbound click, email click and download click events. You should use GTM trigger events instead

## 0.9.1

* Fixed: PHP error message: missing get_shipping function using WooCommerce 2.3.x

## 0.9

* Added: visitorId dataLayer variable with the ID of the currently logged in user to track userID in Google Analytics
* Added: WordPress filter hook so that other templates and plugins can get access to the GTM container code before outputting it
* Fixed: 'variation incorrect' issue by Sharken03
* Fixed: error messages in WooCommerce integration when product has no categories
* Fixed: add_inline_js errors in newer versions of WooCommerce
* Fixed: error message when some device/browser/OS data could not be set
* Fixed: tracking Twitter events was broken

## 0.8.2

* Fixed: broken links when listing subcategories instead of products (thanks Jon)
* Fixed: wheather/weather typo (thanks John Hockaday)
* Fixed: wrong usage of get_the_permalink() instead of get_permalink() (thanks Szepe Viktor)

## 0.8.1

* Fixed: PHP error in enhanced ecommerce implementation when using layered nav widget

## 0.8

* Updated: Added subtabs to the admin UI to make room for new features :-)
* Updated: WhichBrowser library to the latest version
* Added: You can now dismiss plugin notices permanently for each user
* Added: weather data. See updated plugin description for details
* Added: Enhanced E-commerce for WooCommerce (experimental!)
* Fixed: PHP notice in frontend.php script. Credit to Daniel Sousa

## 0.7.1

* Fixed: WooCommerce 2.1.x compatibility

## 0.7

* Updated/Fixed: dataLayer variables are now populated at the end of the head section. Using this the container code can appear just after the opening body tag, thus Webmaster Tools verification using Tag Manager option will work
* Added: blacklist or whitelist tags and macros to increase security of your Tag Manager setup


## 0.6

* Updated: better add-to-cart events for WooCommerce, it includes now product name, SKU and ID
* Added: browser, OS and device data to dataLayer variables
* Added: postCountOnPage and postCountTotal dataLayer variables to track empty categories/tags/taxonomies

## 0.5.1

* Fixed: WooCommerce integration did not work on some environments

## 0.5

* Added: scroll tracking
* Fixed: social tracking option on the admin panel was being shown as an edit box instead of a checkbox
* Fixed: WooCommerce transaction data was not included in the dataLayer if you selected "Custom" code placement
* Fixed: do not do anything if you enabled WooCommerce integration but did not activate WooCommerce plugin itself
* Updated: do not re-declare dataLayer variable if it already exists (because another script already created it before my plugin was run)

## 0.4

* Added: you can now select container code placement. This way you can insert the code snippet after the opening body tag. Please read FAQ for details
* Added: initial support for social event tracking for Facebook and Twitter buttons. Please read FAQ for details
* Updated: event name on successful WooCommerce transaction: OrderCompleted -> gtm4wp.orderCompleted
* Fixed: frontend JS codes did not load on some WordPress installs

## 0.3

* Updated: admin page does not show an alert box if Tag Manager ID or dataLayer variable name is incorrect. Instead it shows a warning line below the input field.
* Updated: rewritten the code for WooCommerce dynamic remarketing. Added tag for homepage and order completed page.

## 0.2

* ! BACKWARD INCOMPATIBLE CHANGE ! - Names of Tag Manager click events has been changed to comply with naming conventions:
	* ContactFormSubmitted -> gtm4wp.contactForm7Submitted
	* DownloadClick -> gtm4wp.downloadClick
	* EmailClick -> gtm4wp.emailClick
	* OutboundClick -> gtm4wp.outboundClick
	* AddProductToCart -> gtm4wp.addProductToCart
* Updated: click events are now disabled by default to reflect recently released Tag Manager auto events. I do not plan to remove this functionality. You can decide which solution you would like to use :-)
* Updated: language template (pot) file and Hungarian translation
* Added: new form move events to track how visitors interact with your (comment, contact, etc.) forms
* Added: event names to admin options page so that you know what events to use in Google Tag Manager
* Added: Google Tag Manager icon to admin settings page
* Added: Settings link to admin plugins page
* Fixed: null value in visitorType dataLayer variable if no logged in user exists (now 'visitor-logged-out')

## 0.1

* First beta release
