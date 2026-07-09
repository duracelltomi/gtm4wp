# GTM4WP 2.0 — manual verification checklist

Automated checks (run on every push via GitHub Actions, `.github/workflows/ci.yml`):

- `vendor/bin/phpcs` — WordPress coding standards + PHP 8.0 compatibility sniffs
- `vendor/bin/phpunit` — PHP unit tests (Brain Monkey based, no WordPress install needed)
- `npm run build` — webpack build of the admin app and all frontend scripts
- `npm run test:unit` — Jest tests of the admin app helpers

The checks below need a real WordPress site (local dev site or wp-env)
with WooCommerce installed and must pass before 2.0 ships. Compare against
a parallel 1.22.4 install where noted.

## 1. Fresh install

- [ ] Activate the plugin on PHP >= 8.0 / WP >= 6.3 — no notices/errors.
- [ ] Activating on PHP < 8.0 or WP < 6.3 shows the requirements admin notice instead of a fatal error.
- [ ] "To start using Google Tag Manager..." notice appears until a GTM ID is entered; notice is dismissible and stays dismissed.
- [ ] Settings → Google Tag Manager opens the React app: left pane navigation, accordion groups, option search.
- [ ] Enter an invalid GTM ID → inline validation error, stored value unchanged. Enter a valid ID → saved, snackbar shown.

## 2. Upgrade from 1.22.4

- [ ] Install 1.22.4, configure: GTM ID, custom data layer name, scroll tracking, WooCommerce tracking, consent mode flags, a few blacklist entities including `ua`.
- [ ] Switch to 2.0 → every kept setting preserved; weather/geo + WP e-Commerce keys and the `ua` blacklist entry removed from the `gtm4wp-options` row (check via `wp option get gtm4wp-options`).
- [ ] `gtm4wp-plugin-version` option stamped.

## 3. dataLayer parity vs 1.22.4 (field-for-field diff of `dataLayer_content` and pushed events)

- [ ] Single post (categories, tags, author, post date, post terms options on)
- [ ] Category archive page, search results page (incl. `siteSearchTerm` escaping), 404 page, front page
- [ ] Logged-in user with visitor data options on (`visitorType`, email hash, ...)
- [ ] WooCommerce product page: `view_item` push (+ parent product variant option), `productRatingCounts` etc.
- [ ] Category/shop page: hidden `.gtm4wp_productdata` spans present; `view_item_list` / `select_item` fire (frontend JS)
- [ ] Cart: `view_cart` on load, `add_to_cart` / `remove_from_cart` on interactions, undo re-add fires `add_to_cart`
- [ ] Checkout: `begin_checkout`, `add_shipping_info`, `add_payment_info` (JS driven)
- [ ] Order received page: `purchase` event payload identical; `orderData` when the option is on
- [ ] Purchase dedupe: reload order received page → no second `purchase` (order meta `_ga_tracked`, localStorage `gtm4wp_orderid_tracked`); "Only track orders younger than" respected
- [ ] Consent mode block appears before the container loader with the configured granted/denied flags
- [ ] Container placement modes: footer / manual (`gtm4wp_the_gtm_tag()` in theme, no double output) / automatic / off (console warnings unless disabled)
- [ ] Container environment parameters (gtm_auth/gtm_preview) present in loader + noscript URLs; only first container used
- [ ] Custom container domain/path in loader URL
- [ ] User login / registration events fire once after the action
- [ ] Browser/OS/device: `gtm4wp.deviceData` event pushed (Chrome: full detail via Client Hints; Safari/Firefox: fallback detail) — expected behavior change vs 1.x
- [ ] AMP page (amp-wp plugin + AMP container ID): amp-analytics component + snippet present

## 4. Admin regression

- [ ] Every module's options round-trip through save (change → save → reload → verify)
- [ ] Blacklist multiselect stores comma separated string; frontend outputs `gtm.blacklist`/`gtm.whitelist` accordingly
- [ ] Unavailable module (WooCommerce deactivated) shows disabled card with explanation
- [ ] `gtm4wp_admin_page_capability` filter respected for the settings page and the REST endpoint
- [ ] Settings link on the Plugins page works

## 5. Performance (Query Monitor, same page 1.22.4 vs 2.0)

- [ ] Plugin PHP files loaded on a frontend request: fewer and smaller (no admin/, no WhichBrowser)
- [ ] Zero `src/Admin/*` files loaded on frontend requests
- [ ] No external HTTP requests from the plugin (ipstack/OpenWeatherMap gone)
- [ ] Frontend scripts (except the WooCommerce tracker, which needs jQuery events from WooCommerce core) load with `defer`

## Release-time tasks

- [ ] Regenerate `languages/*.pot` (`wp i18n make-pot . languages/duracelltomi-google-tag-manager.pot`) and the JS translation JSONs (`wp i18n make-json`)
- [ ] Announcement post: PHP 8.0/WP 6.3 floor, removed features, deviceData behavior change
- [ ] readme.txt stable tag bump
