# Easy Digital Downloads integration — development plan

Status: **plan / not implemented**. Target: a new built-in module mirroring the
WooCommerce integration's feature set for [Easy Digital Downloads](https://github.com/awesomemotive/easy-digital-downloads)
(EDD).

All EDD facts below were verified against the official repository
`awesomemotive/easy-digital-downloads`, branch `main`, **version 3.6.9**
(readme: requires WP 6.2+, PHP 7.4+, GPLv2). EDD 3.x rewrote its data layer
(custom order tables, `EDD\Orders\Order`), so the plan targets **EDD >= 3.0
only** — the pre-3.0 `EDD_Payment` API is out of scope. Items that still need
re-confirmation at implementation time are collected in
[Open verification items](#open-verification-items).

EDD core ships **no** Google Analytics / GTM integration of its own
(`src/Integrations/` holds only Elementor/WPCode glue), so there is no overlap
to defend against.

---

## 1. Module shape and wiring

Mirror the WooCommerce module one-for-one in the module framework:

| Aspect | Decision |
|---|---|
| Directory | `src/Modules/EasyDigitalDownloads/` |
| Module class | `EasyDigitalDownloadsModule extends AbstractModule` |
| Module id | `edd` |
| Option key prefix | `integrate-edd-` (constants `GTM4WP_OPTION_INTEGRATE_EDD*` in `compat/constants.php`) |
| Admin schema | `AdminSchema implements AdminSchemaInterface` (all translated strings live here) |
| Registration | Append to `Registry::BUILTIN_MODULES` (after `WooCommerceModule`) + add the id to the hardcoded list in `ModuleConsistencyTest` |
| Availability | `is_available()`: `function_exists( 'EDD' ) && defined( 'EDD_VERSION' ) && version_compare( EDD_VERSION, self::MIN_EDD_VERSION, '>=' )` with `MIN_EDD_VERSION = '3.0'` |
| Master gate | `register_frontend_hooks()` returns immediately unless `integrate-edd-track-ecommerce` is on (same single-gate pattern as WooCommerce) |

Planned class split (same responsibilities as the WooCommerce files):

| File | Mirrors | Est. size |
|---|---|---|
| `EasyDigitalDownloadsModule.php` | `WooCommerceModule.php` — defaults, hook wiring, script enqueue, global JS vars | ~350 lines |
| `AdminSchema.php` | `AdminSchema.php` — groups `general`, `products`, `datalayer`, `purchase`, `advanced` | ~330 lines |
| `DownloadData.php` | `ProductData.php` — item array builder, order items, purchase dataLayer | ~450 lines |
| `PageDataLayer.php` | `PageDataLayer.php` — server-side events on `GTM4WP_WPFILTER_COMPILE_DATALAYER` | ~550 lines |
| `ListTracking.php` | `ListTracking.php` — hidden product-data markup for lists and buy buttons | ~300 lines |
| `PurchaseTracking.php` | `PurchaseTracking.php` — dedupe flag + fallback hooks | ~120 lines |
| `js/frontend/gtm4wp-edd.js` | `gtm4wp-woocommerce.js` — client events (own webpack bundle automatically) | ~500 lines |

### Shared-code extraction (prerequisite refactor)

`WooCommerce\Helpers` currently owns code the EDD module needs verbatim:
`normalize_and_hash*()` (Enhanced Conversions), `BUSINESS_VERTICALS`(+`_IDS`),
`get_gads_product_id_variable_name()`, the list-attribution cookie readers, and
the purchase dedupe-guard JS fragment lives in `ProductData`. Before building
the EDD module, extract these into a shared namespace (proposal:
`src/Ecommerce/Helpers.php`, `GTM4WP\Ecommerce`), keep `WooCommerce\Helpers`
delegating to it (internal API only — no public names change). Pure refactor,
`[skip changelog]`.

`js/frontend/gtm4wp-ecommerce-generic.js` is already store-agnostic
(`gtm4wp_push_ecommerce()`, JSON readers for `.gtm4wp_productdata` spans and
`gtm4wp_product_data` hidden inputs) and is **reused unchanged** as a dependency
of the EDD bundle.

---

## 2. Options — WooCommerce ↔ EDD mapping

Every WooCommerce option, with the mirroring decision. "Mirror" = same
semantics under an `integrate-edd-*` key; phases follow the
`Field::PHASE_*` policy (new integration ⇒ nothing starts `stable`).

### Mirrored

| WooCommerce option | EDD key | Default | Phase | Notes |
|---|---|---|---|---|
| track-enhanced-ecommerce | `integrate-edd-track-ecommerce` | `false` | beta | Master gate. |
| product-per-impression | `integrate-edd-product-per-impression` | `10` | beta | `view_item_list` chunk size (client-side, shared JS). |
| remarketing-usesku | `integrate-edd-use-sku` | `false` | beta | `edd_get_download_sku()`; falls back to ID when unset/`-`. EDD gates SKUs behind an admin toggle — describe that in the field description. |
| brand-taxonomy | `integrate-edd-brand-taxonomy` | `''` | beta | Choices: public, `show_ui`, non-builtin taxonomies registered on the `download` post type (EDD core has none — this serves brand plugins/custom taxonomies). |
| use-full-category-path | `integrate-edd-use-full-category-path` | `false` | beta | `download_category` hierarchy via the same `get_term_parents_list()` logic. |
| business-vertical | `integrate-edd-business-vertical` | `'retail'` | beta | Same choices/`id`-vs-`destination` logic from shared helpers. |
| remarketing-productidprefix | `integrate-edd-product-id-prefix` | `''` | beta | |
| cart-content-in-datalayer | `integrate-edd-cart-content-in-datalayer` | `false` | beta | From `edd_get_cart_content_details()`. |
| customer-data | `integrate-edd-customer-data` | `false` | beta | Customer block + Enhanced Conversions `user_data` on purchase. |
| order-data | `integrate-edd-order-data` | `false` | beta | Raw `orderData` on the receipt page. |
| order-max-age | `integrate-edd-order-max-age` | `30` | experimental | Same age gate, from `$order->date_created`. |
| exclude-tax | `integrate-edd-exclude-tax` | `false` | beta | EDD has taxes (`$order->tax`, per-item `tax`). |
| transaction-id-prefix | `integrate-edd-transaction-id-prefix` | `''` | beta | Applied to `$order->get_number()` (respects EDD sequential order numbers automatically). |
| do-not-use-order-tracked-flag | `integrate-edd-do-not-use-order-tracked-flag` | `false` | beta | |
| purchase-track-statuses | `integrate-edd-purchase-track-statuses` | `['complete']` | beta | Choices from `edd_get_payment_statuses()`. Default only `complete`: EDD's `pending`/`processing` are explicitly *incomplete* states, and offsite gateways can land buyers on the success page while still pending (EDD even ships a `payment-processing.php` holding template). Users with early-landing gateways can opt statuses in. |
| clear-ecommerce-datalayer | `integrate-edd-clear-ecommerce-datalayer` | `false` | beta | Feeds the shared `gtm4wp_clear_ecommerce` JS global. |
| datalayer-max-timeout | `integrate-edd-datalayer-max-timeout` | `2000` | beta | `select_item` eventCallback timeout, shared JS. |

`ModuleConsistencyTest` requires every option key to be owned by exactly one
module, so the two "advanced" options are duplicated per module rather than
shared. If WooCommerce **and** EDD are both active with different values, the
module registered later (EDD) wins the shared JS globals — an edge case worth
one sentence in both field descriptions, not extra machinery.

### Deliberately NOT mirrored (and why)

| WooCommerce option/feature | Reason |
|---|---|
| view-item-on-parent-product | No product-variation post type in EDD; variable *prices* are options on one download (see §4). |
| persist-list-attribution (experimental, #405) | Deferred to backlog; ship the core funnel first. |
| purchase-track-on-any-page (experimental) | Its WC implementation rides on WC session + status hooks; EDD equivalent needs its own hook research (`edd_transition_order_status`?). Backlog. |
| custom-order-received-page | Unnecessary: EDD's success page is *already* a configurable page option that `edd_is_success_page()` resolves. |
| checkoutwc compatibility | CheckoutWC is WooCommerce-only. |
| exclude-shipping | EDD core has no shipping. |

---

## 3. GA4 events — trigger map

Verified EDD hook/DOM listen points per event. "Server" = pushed into the page
dataLayer during `GTM4WP_WPFILTER_COMPILE_DATALAYER`; "Client" = fired by
`gtm4wp-edd.js` through `gtm4wp_push_ecommerce()`.

| Event | Trigger | Side | Implementation |
|---|---|---|---|
| `view_item` | `is_singular( 'download' )` | Server | `PageDataLayer`. Variable-price downloads: emit with the lowest price option (`edd_get_lowest_price_option()`), `item_variant` empty; no re-fire on option change in v1 (no EDD equivalent of `found_variation`). |
| `view_item_list` | `.gtm4wp_productdata` spans present in DOM | Client (shared logic) | `ListTracking` hooks `edd_download_after` (fires per item inside `templates/shortcode-download.php` for the `[downloads]` grid) to emit the same span markup the generic JS already reads; chunked by product-per-impression. |
| `select_item` | Delegated click on links inside download-list items | Client | Same eventCallback + max-timeout navigation delay as WC, reusing the shared helper. |
| `add_to_cart` | Click on `.edd-add-to-cart` (form `.edd_download_purchase_form`) | Client | Item data from a hidden input injected via `edd_purchase_link_end` (`$download_id, $args` — fires just before `</form>`); price/variant read from the checked `edd_options[price_id][]` radio/checkbox (`data-price` attr). Multi-price mode (checkboxes) ⇒ one event with multiple items. Covers AJAX and non-AJAX carts alike since it's click-driven; `edd_cart_item_added` on `document.body` is the AJAX-success signal but carries only totals, so click capture is primary. Buy Now (`edd_action=straight_to_gateway`) buttons fire `add_to_cart` too. |
| `remove_from_cart` | Click on cart-row remove links | Client | Item data injected per row: `edd_checkout_cart_item_title_after( $item, $key )` on the checkout cart; a printed `cart_key → item` JSON map covers the `[download_cart]` widget rows (exact remove-link selectors: see open items). `edd_cart_item_removed` on `document.body` as the AJAX signal. |
| `view_cart` | Singular page whose content `has_shortcode( 'download_cart' )` | Server | `PageDataLayer`, items from `edd_get_cart_content_details()`. EDD has no `edd_is_cart()`; checkout's embedded cart is covered by `begin_checkout` instead. |
| `begin_checkout` | `edd_is_checkout()` | Server | `PageDataLayer`; also exposes `window.gtm4wp_checkout_products` for the client events. |
| `add_shipping_info` | — | — | **Dropped** — no shipping in EDD core. |
| `add_payment_info` | jQuery `edd_gateway_loaded` on `body` (gateway slug arg) + fallback on purchase-button submit | Client | Gateway radios are `input[name="payment-mode"]`. Fire once per selected gateway (dedupe like WC); ignore the initial page-load `edd_gateway_loaded` unless it's the only signal before submit. |
| `purchase` | `edd_is_success_page()` | Server | See §5. |

WooCommerce-only surfaces with no EDD counterpart (no work planned): variation
`view_item`, Quick View, cart-quantity add/remove diffing (EDD checkout has
quantity fields only when a setting enables them — backlog), WC Blocks/Store
API mirroring (EDD's checkout block needs its own research — open item),
re-added-to-cart "Undo" session event.

---

## 4. Item (product) data structure

Built by `DownloadData::process_download( $download, array $additional, string $context, $source_item = null )`
mirroring `ProductData::process_product()`:

| Field | Source (EDD) |
|---|---|
| `internal_id` | `$download->get_ID()` — stripped before output, as in WC |
| `item_id` / `sku` | `edd_get_download_sku()` when use-sku on and set, else ID |
| `item_name` | `$download->get_name()`; append price-option name for cart/order lines? No — that goes in `item_variant` |
| `price` | Simple: `$download->get_price()`. Variable: `edd_get_price_option_amount( $id, $price_id )` when a `price_id` is in play, else lowest option. Cart/order lines pass the already-computed line price via `$additional['price']` (same perf pattern as WC #436) |
| `item_variant` | Price-option name from `$download->get_prices()[ $price_id ]['name']` when `price_id` present (cart items carry it in `options['price_id']`, order items in `Order_Item::$price_id`) |
| `item_category`…`item_category5` | `download_category` terms, primary-term + full-path logic from shared helpers |
| `item_brand` | Only when brand taxonomy configured |
| `item_list_name` / `item_list_id` | Set by list callers; id auto-derived via `sanitize_title()` |
| `google_business_vertical`, Ads `id`/`destination` (+prefix) | Shared helpers |
| `affiliation` | Empty unless the affiliation filter returns non-empty |
| *(dropped)* | `stocklevel` / `stockstatus` (no inventory in EDD), `item_group_id` (no variation products; price options stay one item) |

**Cart items** come from `edd_get_cart_content_details()` — each entry already
carries `name`, `id`, `item_number.options.price_id`, `item_price`, `quantity`,
`discount`, `subtotal`, `tax`, `price` — so per-line price/discount need no
recomputation.

**Order items** come from `$order->get_items()` (`EDD\Orders\Order_Item`:
`product_id`, `product_name`, `price_id`, `quantity`, `amount`, `subtotal`,
`discount`, `tax`, `total`). Per-unit `discount` = `discount / quantity` when
positive; price on the same tax basis as the transaction when exclude-tax is on.

---

## 5. Purchase tracking design

Flow on `edd_is_success_page()` (mirrors `add_order_received_data` /
`add_purchase_for_order`):

1. **Order resolution** — EDD's own receipt fallback chain, reimplemented
   read-only: `$_GET['payment_key']` → (`$_GET['order']` + `$_GET['id']` →
   `edd_get_payment_key()`) → purchase session (`edd_get_purchase_session()['purchase_key']`);
   then `edd_get_order_by( 'payment_key', $payment_key )`.
   **Security:** never resolve an order from a bare ID — possession of the
   unguessable `payment_key` (or the buyer's own session) is the authorization,
   exactly like WC's `order_key` check. Customer/order data stays behind its
   options regardless (A0 must not be able to enumerate orders).
2. **Eligibility gauntlet** (same order as WC): age gate
   (`integrate-edd-order-max-age` vs `$order->date_created`) → already-tracked
   check → status check (`$order->status` against
   `integrate-edd-purchase-track-statuses`, filterable).
3. **Duplicate prevention, three layers:**
   - `_ga_tracked` order meta via `edd_get_order_meta()` / `edd_add_order_meta()`
     (same meta key as WC for operator familiarity);
   - browser guard: the shared `gtm4wp_orderid_tracked` cookie/localStorage JS
     fragment keyed by order number;
   - the age gate.
   All three no-op under do-not-use-order-tracked-flag (browser guard included,
   matching WC).
4. **Purchase dataLayer:** `transaction_id` = prefix + `$order->get_number()`,
   `currency` = `$order->currency`, `value` = `total` (minus `tax` when
   exclude-tax; no shipping term), `tax`, `coupon` = discount-type adjustment
   codes from `$order->get_discounts()` joined with `,`, `items` from §4.
   Optional `user_data` (Enhanced Conversions) from `$order->email` +
   `$order->address` via the shared hashing helpers when customer-data is on.
5. **Raw `orderData`** (when order-data on): attributes (dates, `order_number`,
   `payment_key` **excluded** — it is a credential, unlike WC's order key which
   WC also exposes; decide in review), `gateway`, `mode` (live/test), `status`,
   discounts; totals (`subtotal`, `discount`, `tax`, `total`, `currency`);
   customer block incl. SHA256 hashes; items.
6. **new_customer flag:** from EDD customer purchase count
   (`edd_get_customer_by( 'email', … )->purchase_count === 1`) — needs
   verification (open item), degrade to omitting the flag.

**Offsite/pending gateways:** EDD sends pending arrivals to a
`payment-processing.php` holding page that redirects to the success page ~8s
later; the status gate in step 2 handles both orders (still-pending ⇒ not
tracked; the buyer who reloads/returns after completion ⇒ tracked once). The
WC-style status-change session seeding ("reliable purchase tracking") is
backlog, pending EDD status-transition hook research.

**Free purchases** flow through checkout normally (EDD forces the `manual`
gateway at `0.00` total) and produce real orders — purchase tracking works
unchanged.

**Cache-safe mode:** the success page URL always carries per-order query args,
which page caches don't serve from cache, so the WC-style one-shot
VisitorData/REST delivery is *not* required for correctness in v1. Documented
as backlog for parity.

---

## 6. Frontend JS

- New entry `js/frontend/gtm4wp-edd.js` → own `build/` bundle (webpack config
  needs no change), enqueued via `AbstractModule::enqueue_script()` with
  `jquery` dependency (EDD's `edd_cart_item_added` / `edd_cart_item_removed` /
  `edd_gateway_loaded` are jQuery events on `body`), depending on
  `gtm4wp-ecommerce-generic`.
- Enqueued on all frontend pages while the master gate is on (purchase buttons
  and `[downloads]` grids can appear anywhere), matching the
  conditional-loading design decision — no combining, `defer` where the jQuery
  dependency allows.
- Global JS vars: reuse the existing shared names
  (`gtm4wp_currency` via `edd_get_currency()`, `gtm4wp_use_sku_instead`,
  `gtm4wp_product_per_impression`, `gtm4wp_clear_ecommerce`,
  `gtm4wp_datalayer_max_timeout`, `gtm4wp_console_log`,
  `gtm4wp_remarketing_prod_id_prefix`) through
  `GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY`, so the generic helper needs zero
  changes.
- Every PHP-printed payload (spans, hidden inputs, cart map) goes through
  `wp_json_encode()` with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS`
  — download titles, category names and **price-option names** are the hostile
  inputs here.

---

## 7. Extensibility filters

New constants in `compat/constants.php` (string values are public API from day
one):

| Constant | Filter | Args | Mirrors |
|---|---|---|---|
| `GTM4WP_WPFILTER_EEC_EDD_CART_ITEM` | `gtm4wp_eec_edd_cart_item` | `( true, array $cart_item_details )` — false excludes | `gtm4wp_eec_cart_item` |
| `GTM4WP_WPFILTER_EEC_EDD_ORDER_ITEM` | `gtm4wp_eec_edd_order_item` | `( true, EDD\Orders\Order_Item )` — false excludes | `gtm4wp_eec_order_item` |
| `GTM4WP_WPFILTER_EEC_EDD_ORDER_DATA` | `gtm4wp_eec_edd_order_data` | `( array, EDD\Orders\Order )` | `gtm4wp_eec_order_data` |
| `GTM4WP_WPFILTER_EDD_PURCHASE_DATALAYER` | `gtm4wp_edd_purchase_datalayer` | `( array, EDD\Orders\Order )` | `gtm4wp_purchase_datalayer` |
| `GTM4WP_WPFILTER_EDD_DATALAYER_PAGELOAD` | `gtm4wp_edd_datalayer_on_pageload` | `( array )` | `gtm4wp_woocommerce_datalayer_on_pageload` |

Reused as-is (already store-agnostic, receive a `$context`/`$source_item`):
`gtm4wp_eec_item_with_source` (source = `EDD_Download`, cart-details array, or
`Order_Item`), `gtm4wp_eec_item_affiliation`, plus the unprefixed
`gtm4wp_edd_purchase_trackable_statuses` companion filter. The deprecated
`gtm4wp_eec_product_array` is **not** wired into the EDD path.

---

## 8. Testing plan

Mirror the WooCommerce test layout under `tests/unit/Modules/EasyDigitalDownloads/`
(TestCase + Brain Monkey + Mockery patterns, protected hooks invoked via
`ReflectionMethod`):

- `edd-stubs.php` alongside `wc-stubs.php`: `EDD()`, `edd_get_option()`,
  `edd_get_currency()`, cart functions, `edd_get_order()`/`edd_get_order_by()`,
  order-meta functions, stub `EDD\Orders\Order` / `Order_Item`, `EDD_Download`.
- Test classes: `EasyDigitalDownloadsModuleTest` (gating, enqueue, globals),
  `AdminSchemaTest`, `DownloadDataTest`, `PageDataLayerTest`,
  `ListTrackingTest`, `PurchaseTrackingTest`; update `ModuleConsistencyTest`'s
  id list (it then enforces defaults ↔ schema key parity automatically).
- **Security regression tests** (per `.testing/` rules, same change that adds
  each sink): hostile download title / category / price-option name / coupon
  code through every `<script>`-context sink — the span markup, the hidden
  input, the cart map, the purchase dataLayer. Assert on encoded output, not
  just absence of errors.
- JS: `js/frontend/test/edd-tracker.test.js` mirroring
  `woocommerce-tracker.test.js` (click add-to-cart with variable prices,
  gateway-switch dedupe, remove-link handling, select_item timeout).

---

## 9. Delivery phases

Each phase lands green (`composer test`, `vendor/bin/phpcs`, `npm run build`)
with its CHANGELOG bullet(s) folded into a single `* Added:` under the EDD
theme heading (2.0 is unreleased — one bullet, edited as phases land).

1. **Phase 0 — shared extraction:** `GTM4WP\Ecommerce` helpers refactor out of
   the WooCommerce module (no behavior change, `[skip changelog]`).
2. **Phase 1 — skeleton:** module + AdminSchema + constants + registry entry +
   consistency/schema tests. Settings page shows the EDD section (fields
   functional but inert).
3. **Phase 2 — server dataLayer:** `DownloadData` + `PageDataLayer`:
   `view_item`, `view_cart`, `begin_checkout`, cart-content and customer-data
   blocks. First security regression tests.
4. **Phase 3 — lists & client events:** `ListTracking` markup injection +
   `gtm4wp-edd.js`: `view_item_list`, `select_item`, `add_to_cart`,
   `remove_from_cart`, `add_payment_info`. JS tests.
5. **Phase 4 — purchase:** `PurchaseTracking` + purchase/orderData/Enhanced
   Conversions + three-layer dedupe.
6. **Phase 5 — hardening:** `/code-review` + `/test-review` passes, readme.txt
   mirror bullet, field descriptions final, maturity phases confirmed.

**Backlog (explicitly out of v1):** EDD checkout **block** support
(`src/Blocks/Checkout` exists in EDD core — needs markup research), cache-safe
one-shot purchase parity, list-attribution persistence, status-change purchase
seeding ("reliable purchase tracking"), checkout quantity-field cart diffing,
archive/taxonomy list tracking beyond the `[downloads]` grid, and EDD
extension ecosystems (Recurring Payments, Software Licensing, Free Downloads).

## Open verification items

Re-confirm against EDD source while implementing (research was done through
per-file fetches; line-level details need a local checkout):

1. The admin setting key that enables SKUs (field description wording).
2. Definition site of `edd_get_purchase_session()` / `edd_set_purchase_session()`
   (behavior + session key `edd_purchase` verified via call sites only).
3. Exact remove-link selectors/classes in `templates/checkout_cart.php` and
   `templates/widget-cart-item.php`.
4. Whether EDD appends purchase buttons to archive pages via a `the_content`
   filter (would extend list tracking to archives cheaply).
5. `edd_get_customer_by()` / `purchase_count` for the new_customer flag.
6. EDD checkout block internals (does it render the classic form server-side?).
7. Order status at success-page arrival per major gateway (Stripe vs PayPal) —
   informs whether the `['complete']` default needs gateway-specific guidance.
8. Behavior with EDD's AJAX cart disabled (`edd_is_ajax_enabled()`), and the
   quantity-field setting's effect on the checkout cart markup.
