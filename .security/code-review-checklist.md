# Code Review Checklist

> ## ⛔ Disclosure rule — HARD REQUIREMENT
>
> **This is a public repository. Committed == published.** Every committed `.md` file — this checklist, `code-review-patterns.md`, and any doc under `.security/`, `.claude/`, or elsewhere — MUST NOT contain:
> - working exploit payloads or proof-of-concept strings,
> - step-by-step reproduction instructions, or
> - the full technical detail of any `open` (unfixed) finding.
>
> Committed files may contain ONLY: a one-line summary, severity, status, and file path — plus, for `fixed` issues, the general vulnerability class. **All exploit detail lives solely in the git-ignored reports.** When in doubt, write less in the committed file and keep the detail in the local report.

Persistent coverage tracker for systematic reviews of the GTM4WP WordPress plugin. Updated after each review run.

**How to use:** Before running a review, read this file. Prioritize `[ ]` (unreviewed) cells. After the review, mark reviewed cells `[x]` with the date and append new findings to the Known Findings Log.

**Status markers:**
- `[ ]` — not yet reviewed
- `[x] YYYY-MM-DD` — reviewed on date
- `[~] YYYY-MM-DD` — reviewed but stale (files changed since)
- `[-]` — not applicable to this component

**Staleness rule:** A cell becomes `[~]` if any file in the component group was modified after the review date. Check with `git log --since="YYYY-MM-DD" -- <path>`.

**Dimensions:** *Cap/Nonce* = capability + nonce/CSRF on state changes · *Input San.* = `wp_unslash` + sanitize on request input · *Output XSS* = escaping into HTML/`<script>` (the primary dimension for this plugin) · *SQL* = `$wpdb->prepare` · *Cplx* = complexity/dead code · *Perf* = performance · *Types* = type hints/return types.

---

## Coverage Matrix

| Component Group | Cap/Nonce | Input San. | Output XSS | SQL | Cplx | Perf | Types |
|---|---|---|---|---|---|---|---|
| **Plugin Bootstrap** (`duracelltomi-google-tag-manager-for-wordpress.php`, `uninstall.php`, `src/Plugin.php`, `src/Autoloader.php`) | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 |
| **Options** (`src/Options/`) | [x] 2026-07-10 | [x] 2026-07-14 | [x] 2026-07-14 | [-] | [x] 2026-07-14 | [x] 2026-07-10 | [x] 2026-07-14 |
| **Compat Layer** (`compat/constants.php`, `compat/functions.php`, `src/Compat/Globals.php`) | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [-] | [x] 2026-07-10 | [-] | [x] 2026-07-10 |
| **Migration** (`src/Migration.php`) | [x] 2026-07-10 | [x] 2026-07-14 | [-] | [-] | [x] 2026-07-14 | [x] 2026-07-10 | [x] 2026-07-10 |
| **Frontend Core** (`src/Frontend/` — DataLayer, ScriptTag, ContainerCode, ConsentDefaults, VisitorIp, Frontend) | [-] | [x] 2026-07-15 | [x] 2026-07-15 | [-] | [x] 2026-07-15 | [x] 2026-07-10 | [x] 2026-07-10 |
| **Module Framework** (`src/Module/`) | [-] | [-] | [-] | [-] | [x] 2026-07-10 | [-] | [x] 2026-07-10 |
| **PageVariables Module** (`src/Modules/PageVariables/`) | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 |
| **Container Module** (`src/Modules/Container/`) | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 |
| **WooCommerce Module** (`src/Modules/WooCommerce/` — PurchaseTracking, ProductData, PageDataLayer, ListTracking, Helpers, StoreApiData) | [x] 2026-07-15 | [x] 2026-07-15 | [x] 2026-07-15 | [x] 2026-07-15 | [x] 2026-07-15 | [x] 2026-07-15 | [x] 2026-07-15 |
| **ConsentMode Module** (`src/Modules/ConsentMode/` — incl. Axeptio handler, CookieYes bridge) | [x] 2026-07-15 | [x] 2026-07-15 | [x] 2026-07-15 | [-] | [x] 2026-07-15 | [x] 2026-07-14 | [x] 2026-07-14 |
| **UserEvents Module** (`src/Modules/UserEvents/`) | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 |
| **MediaEvents Module** (`src/Modules/MediaEvents/`) | [-] | [x] 2026-07-13 | [x] 2026-07-13 | [-] | [x] 2026-07-13 | [x] 2026-07-13 | [x] 2026-07-13 |
| **ContactForm7 Module** (`src/Modules/ContactForm7/` — incl. tracker JS) | [-] | [x] 2026-07-14 | [x] 2026-07-14 | [-] | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 |
| **Blacklist Module** (`src/Modules/Blacklist/`) | [-] | [x] 2026-07-14 | [x] 2026-07-14 | [-] | [x] 2026-07-14 | [x] 2026-07-10 | [x] 2026-07-10 |
| **ClientDeviceData Module** (`src/Modules/ClientDeviceData/`) | [-] | [-] | [x] 2026-07-10 | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 |
| **AMP Module** (`src/Modules/Amp/`) | [-] | [x] 2026-07-14 | [x] 2026-07-14 | [-] | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 |
| **Admin — Notices/AJAX** (`src/Admin/Notices.php`) | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 |
| **Admin — Settings UI** (`src/Admin/SettingsPage.php`, `Admin.php`, `RestController.php`, `PluginRow.php`) | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 |
| **Module Admin Schemas** (`src/Modules/*/AdminSchema.php`) | [x] 2026-07-15 | [x] 2026-07-15 | [x] 2026-07-15 | [-] | [x] 2026-07-15 | [-] | [x] 2026-07-10 |
| **Frontend JS** (`js/frontend/`) | [-] | [x] 2026-07-15 | [x] 2026-07-15 | [-] | [x] 2026-07-15 | [x] 2026-07-15 | [-] |
| **Admin JS** (`js/admin/`) | [x] 2026-07-10 | [x] 2026-07-14 | [x] 2026-07-14 | [-] | [x] 2026-07-14 | [x] 2026-07-10 | [-] |
| **Tests** (`tests/`) | [-] | [-] | [-] | [-] | [x] 2026-07-10 | [-] | [-] |

> **Coverage note (Review 2, 2026-07-10):** every component group has now had a first full pass across all applicable dimensions. Cells marked `[-]` are not-applicable (e.g. frontend modules perform no state-changing admin mutation, so Cap/Nonce is N/A; no `$wpdb` usage exists anywhere in `src/`, so SQL is N/A except where a component was explicitly confirmed clean). The Admin — Notices Cap/Nonce cell is now `[x]`: dismiss handler has nonce + allow-listed input but **no capability check** — see finding #18 (self-scoped, negligible impact).
>
> **Coverage note (Review 5, 2026-07-15):** reviewed the 24 commits in `b15b034..HEAD` — the large **WooCommerce overhaul** (Store API / Cart-Checkout **block tracking** via the new `StoreApiData` + `gtm4wp-woocommerce-blocks.js` + `lib/gtm4wp-blocks-cart-diff.js`; GA4-spec dataLayer refinements; per-item price-basis/discount fixes #176/#348; cross-funnel **list attribution** #405; reliable purchase tracking + custom order-received page #369; new `gtm4wp-ecommerce-generic.js` split) and two new consent/checkout bridges (**CookieYes** #380, **CheckoutWC** #385). **No Critical/High/Medium.** Every new script/dataLayer sink conforms to RI-2 (full hex flags on main dataLayer + inline pushes + begin_checkout + thank-you), RI-4 (raw values to the sink), PA-5 (new SELECT/MULTISELECT options allow-list-sanitized on save), PA-7 (`addcslashes`/`preg_replace_callback` on the two block product-list injectors), PA-8 (`wp_add_inline_script`, no `wc_enqueue_js`), PA-9 (block trackers are dataLayer-only, same-origin `wc/store/*` data, no `postMessage`, capped/sanitized list cookie). Store API extension is a REST/delegated sink (FP-4 class; default slash-escaping already blocks `</script>`). CookieYes bridge = `esc_js`'d JS-identifier datalayer name only, sibling of Axeptio/WebToffee. One **Low**: #31 (block cross-sell items leak `internal_id` into GA4 — `gtm4wp_blocks_to_crosssell_item` misses the `delete` its sibling does; data-quality, not XSS). Baseline: **381 PHP tests / 213 JS tests green**, `phpcs` clean.
>
> **Coverage note (Review 4, 2026-07-14):** reviewed the 9 commits in `d36bca3..b15b034` — the native **Axeptio** CMP integration (new `ConsentMode/Axeptio.php` handler + new `AxeptioVersionControl.js` admin control; old standalone `Modules/Axeptio/` module **removed**, no dangling refs), the **Contact Form 7** modernization (rewritten `gtm4wp-contact-form-7-tracker.js` + PHP inputs/GA4 config), the **AMP** modernization against amp-wp 2.x (`amp_analytics_entries` cross-mode filter, FP-4), the **WebToffee** GDPR deprecation, the **`sandboxedScripts`** blacklist group class, and the `ContainerCode` `$echo`→`$echo_output` cleanup (resolves FP-3). **No Critical/High/Medium.** All new script/dataLayer sinks conform to RI-2 (full hex flags), PA-5 (sanitize-on-save via `Field::sanitize()`), PA-2 (Blacklist allow-list at the output sink), FP-4 (AMP delegated sink) and PA-9 (CF7/Axeptio dataLayer-only, SDK-mediated). Data-layer name stays a validated JS identifier, so the unquoted `window.<name>` in the Axeptio/WebToffee head JS is safe. Three Low: #28 (CF7 tracker no double-init guard), #29 (`header_top` raw-`wp_kses` ampersand drift vs `print_script_block`, latent), #30 (CF7 `inputs` default `full` = PII-by-default, documented/opt-out). Baseline: **281 tests green**, `phpcs` clean.
>
> **Coverage note (Review 3, 2026-07-13):** re-reviewed **MediaEvents Module** and **Frontend JS** after the MediaEvents expansion (12 `EVENTS_*` options, shared `lib/native-video-params.js`, 11 trackers incl. 8 new). MediaEvents PHP is enqueue-only (static bundles + hard-coded SDK URLs) — no server-side script/dataLayer sink; all i18n/ABSPATH/checkbox-schema correct. Trackers have no HTML/JS injection sink and push only to `dataLayer`; VideoPress validates `event.origin`, all others are SDK-mediated, none read request input. One Medium correctness bug (finding #19, undeclared `player` → strict-mode `ReferenceError`) + Low consistency/hygiene (#20-25). `ContainerCode` OFF-placement iframe fix verified clean; `PageDataLayer` `wc_enqueue_js`→`wp_add_inline_script` migration re-confirmed (PA-8). Baseline: 240 tests green, `phpcs` clean.

---

## Whole-Repo Sweeps

Dead code and cross-file duplication are **whole-repo** concerns — they do not map onto the per-component Coverage Matrix. Log each sweep here with the date last run and a one-line result. Run via the playbook in `.claude/commands/code-review.md` § B (grep-for-references, not eyeball). Treat a sweep older than ~4 weeks, or predating a significant feature landing, as stale.

| Sweep | Last run | Result summary |
|---|---|---|
| **Dead functions/methods** (private/public across `src/`, `compat/`, root `*.php`, `tests/`) | 2026-07-15 | Clean. New `StoreApiData` (wired on `woocommerce_blocks_loaded`), all new `Helpers`/`ProductData`/`PageDataLayer` methods and `WooCommerceModule` block-detection helpers reachable; all new `window.*` block globals consumed by their trackers. (2026-07-14: standalone `Modules/Axeptio/` removed cleanly, new Axeptio/CF7 helpers reachable; 2026-07-10: `Field::rest_type()` dead→fixed; `Plugin::registry()`/`options()`+`Registry::get()` kept as intentional public API.) |
| **Dead hooks** (`add_action`/`add_filter` with no callback; `do_action`/`apply_filters` constants with no listener) | 2026-07-15 | Clean. New `GTM4WP_WPFILTER_EEC_ITEM_AFFILIATION` is a documented public extension point (applied in `ProductData::process_product()`, no internal listener required). All new WooCommerce hooks in `WooCommerceModule::register_frontend_hooks()` bind live callbacks. (2026-07-14: `FILTER_DEFAULT_ENABLED`/`AXEPTIO_CONSENT_MODE_DEFAULT` verified; prior custom filters remain documented extension points with live listeners.) |
| **Dead option constants** (`GTM4WP_OPTION_*`/`GTM4WP_*` in `compat/constants.php`, never read) | 2026-07-15 | Clean. New WC (`WCPURCHASESTATUSES`/`WCPURCHASEONANYPAGE`/`WCCUSTOMORDERRECEIVEDPAGE`/`WCLISTATTRIBUTION`/`WC_CHECKOUTWC`) and `INTEGRATE_COOKIEYES` constants are all read (schema + module). (2026-07-14: CF7/Axeptio constants read; `BLACKLIST_SANDBOXED` kept for BC in `Migration::REMOVED_OPTION_KEYS`.) |
| **Dead JS** (`js/**/*.js` never enqueued / no `build/` entry) | 2026-07-15 | Clean. New `gtm4wp-ecommerce-generic.js` + `gtm4wp-woocommerce-blocks.js` enqueued by `WooCommerceModule::enqueue_scripts()`/`enqueue_blocks_tracker()`; `lib/gtm4wp-blocks-cart-diff.js` is a shared import (non-recursive `readdirSync(js/frontend)` so not an orphan bundle), `js/frontend/test/*` excluded. (2026-07-13: media trackers + `lib/native-video-params.js`; 2026-07-10: `gtm4wp-html5media.js` dead-then-wired.) |
| **Duplication / drift** (a helper coexisting with inline copies; a module escaping the dataLayer differently from siblings) | 2026-07-15 | One consistency gap found **and fixed** (finding #31, Low): the block `internal_id` strip was done in `gtm4wp_blocks_to_item()` but omitted in the sibling `gtm4wp_blocks_to_crosssell_item()`, so cross-sell events leaked it while every other path strips it — now both mappers strip it. Otherwise clean: cart price/discount logic centralized in `Helpers::cart_line_display_price/_discount` (used by cart/checkout/list callers, no inline copies); the eligibility/dedupe gauntlet shared by `PageDataLayer` + `PurchaseTracking` via `ProductData` helpers; both block product-list injectors use the safe replacement idiom (PA-7); CookieYes bridge mirrors the Axeptio/WebToffee sibling. (2026-07-14: `header_top`→`print_script_block` #29 fixed; 2026-07-13 media-tracker boilerplate.) |
| **Over-abstraction** (single-caller interfaces, forward-only wrappers, unread options) | 2026-07-15 | None material. `StoreApiData` is a thin, justified Store API adapter (2 endpoint callbacks + 2 schemas); `WooCommerceModule` block-detection helpers (`is_block_cart_or_checkout`/`store_uses_cart_blocks`/`page_uses_block`) each have distinct call sites; `lib/gtm4wp-blocks-cart-diff.js` pure helpers are unit-tested and reused by the tracker. (2026-07-14: `Axeptio` handler + `AxeptioVersionControl` justified; 2026-07-10: `Field::rest_type()` dead→fixed.) |

---

## Known Findings Log

Each finding is logged once. Status: `open` | `fixed` | `wontfix`.

> **Reports are local-only.** The detailed report files referenced below are git-ignored (see `.security/.gitignore`) because this is a public repo and reports carry exploit PoCs / possibly-unfixed detail. This log keeps only terse summaries — never paste a working payload or the full detail of an `open` Critical/High finding here.

### Report 1: `.security/code-review-report-2026-07-10-1501.md`

Reflected/stored XSS review of every path where HTML/`<script>` output depends on URL/request/header input. All findings share one root cause (`print_script_block()` decoding HTML entities) and were fixed in the working tree the same session, with two regression tests.

| # | Sev | Status | Summary | File(s) |
|---|-----|--------|---------|---------|
| 1 | Critical | fixed | Reflected XSS — `?s=` search term (`siteSearchTerm`) breaks out of the dataLayer JS string; `get_search_query()` returns `esc_attr`'d `&quot;` which `JSON_HEX_TAG` cannot catch and `print_script_block()`'s decode resurrects into a raw `"`. Fixed by adding `JSON_HEX_AMP\|JSON_HEX_QUOT\|JSON_HEX_APOS`. | `src/Modules/PageVariables/PageVariablesModule.php`, `src/Frontend/ContainerCode.php` |
| 2 | High | fixed | Root cause — `ScriptTag::print_script_block()` ran a blanket `htmlspecialchars_decode()` after `wp_kses`, resurrecting `&quot;`/`&lt;`/`&#039;` from any `esc_js`/`esc_attr`-escaped value into break-out characters. Reworked to restore only the ampersand (`str_replace('&amp;','&', …)`). | `src/Frontend/ScriptTag.php` |
| 3 | High | fixed | Stored XSS — WooCommerce purchase dataLayer: `esc_js`'d order/billing fields (e.g. a billing name containing a double quote, entered at checkout) break out via the same decode. Fixed by the hex flags on the purchase `wp_json_encode` + the print_script_block root-cause fix. | `src/Modules/WooCommerce/PurchaseTracking.php`, `src/Modules/WooCommerce/ProductData.php` |
| 4 | Medium | fixed | `geoCloudflareCountryCode` from the spoofable `HTTP_CF_IPCOUNTRY` header follows the same `esc_js` → decode break-out class. Covered by the two fixes above. | `src/Modules/PageVariables/PageVariablesModule.php` |
| 5 | Low | fixed | `siteSearchFrom` from `HTTP_REFERER` — lower risk (`esc_url_raw` strips `"`) but same class; covered by the hex-flag + amp-only-restore fixes. | `src/Modules/PageVariables/PageVariablesModule.php` |
| 6 | Low | fixed | `esc_js`'d values embedded directly in hardcoded `<script>` strings (data layer name, disabled user role) were also resurrected by the old decode. Now inert after the amp-only restore (RI-3). | `src/Frontend/ContainerCode.php` |

**Defense-in-depth flags** also added to `src/Frontend/DataLayer.php` (additional pushes) for uniformity, though that path (`wp_add_inline_script`) was not exploitable.

**Regression tests:** `tests/unit/Frontend/ScriptTagTest::test_print_script_block_does_not_decode_quote_and_tag_entities`, `tests/unit/Frontend/ContainerCodeTest::test_header_begin_does_not_decode_html_entities_in_datalayer_values`. Full suite: 140 tests green; `vendor/bin/phpcs` clean (bar the pre-existing `$echo` warnings, FP-3).

### Report 2: `.security/code-review-report-2026-07-10-1606.md`

First full pass over the previously-unreviewed component groups (Admin/REST/Settings, Options/Field, all non-PageVariables modules, bootstrap/compat, WooCommerce Cplx/Perf/Types) + all six whole-repo sweeps. **No Critical/High.** Findings 7-8 are the notable ones (both consequences of the Review-1 fix); 9-18 are dead-code/consistency/quality.

| # | Sev | Status | Summary | File(s) |
|---|-----|--------|---------|---------|
| 7 | Medium | fixed | Defense-in-depth (RI-2): the `wc_enqueue_js` checkout-products sink emitted `wp_json_encode` output into a raw `<script>` without hex flags. Added the full hex-flag set (now matches every sibling sink). | `src/Modules/WooCommerce/PageDataLayer.php` |
| 8 | Medium | fixed | Data-quality (RI-4): `get_raw_order_datalayer()` `esc_js`-pre-escaped ~30 order fields, corrupting data post-Review-1. Removed the `esc_js`; values now reach the hex-flag `wp_json_encode` raw. Regression test added (`ProductDataTest::test_raw_order_datalayer_passes_values_without_entity_escaping`). | `src/Modules/WooCommerce/ProductData.php` |
| 9 | Low | fixed | Dead JS: `gtm4wp-html5media.js` was built but never enqueued. Wired into MediaEvents as an opt-in tracker (new option `event-html5-media`, default off; constant `GTM4WP_OPTION_EVENTS_HTML5MEDIA`). | `js/frontend/gtm4wp-html5media.js`, `src/Modules/MediaEvents/*`, `compat/constants.php` |
| 10 | Low | fixed | `Field::rest_type()` was dead; now used to build per-field `properties` on the settings POST args schema (tightens REST-layer type validation; unknown keys still allowed as before). | `src/Options/Field.php`, `src/Admin/RestController.php` |
| 11 | Low | fixed | Output-XSS consistency: 4 script sinks used weaker-than-RI-2 flags. All now use the full hex-flag set. | `src/Admin/SettingsPage.php`, `src/Modules/ClientDeviceData/ClientDeviceDataModule.php`, `src/Modules/Amp/AmpModule.php`, `src/Frontend/ContainerCode.php` |
| 12 | Low | fixed | Data-quality (RI-4): residual `esc_js` on `visitorIP`/`geoCloudflareCountryCode` removed; values pass raw to the hex-flag JSON sink. | `src/Modules/PageVariables/PageVariablesModule.php` |
| 13 | Low | fixed | Duplication removed: the order-eligibility + tracking policy (max-age, already-tracked meta/cookie, tracked-flag write, new-customer) now lives in four `ProductData` helpers (`is_order_older_than_max_age`, `is_purchase_already_tracked`, `flag_order_tracked`, `is_new_customer`) used by both `PurchaseTracking::on_thankyou` and the PageDataLayer order-received branch. | `src/Modules/WooCommerce/ProductData.php`, `src/Modules/WooCommerce/PurchaseTracking.php`, `src/Modules/WooCommerce/PageDataLayer.php` |
| 14 | Low | fixed | God methods decomposed: `PageDataLayer::add_datalayer_data` (~455→~62-line dispatcher + 7 per-branch private methods); `ContainerCode::header_begin` (~108 lines → dispatcher + 4 string builders). Behavior preserved (155 tests green incl. ContainerCodeTest exact-string assertions). | `src/Modules/WooCommerce/PageDataLayer.php`, `src/Frontend/ContainerCode.php` |
| 15 | Low | fixed | i18n (RI-5): "Important Upgrade Notice:" now `esc_html__()`. | `src/Admin/PluginRow.php` |
| 16 | Low | fixed | Robustness: `add_productdata_to_wc_block` now `addcslashes()`-escapes the data-bearing `preg_replace` replacement so `$n`/`\1` in product data cannot mangle output. | `src/Modules/WooCommerce/ListTracking.php` |
| 17 | Low | fixed | Convention: AMP module now reads the compiled data layer via `DataLayer::compiled()` (a new no-recompute cache) instead of `$GLOBALS['gtm4wp_datalayer_data']`; also avoids any risk of re-firing the compile filter. | `src/Modules/Amp/AmpModule.php`, `src/Frontend/DataLayer.php` |
| 18 | Low | fixed | PA-1 defense-in-depth: `Notices::dismiss_notice()` now re-checks `current_user_can()` (was already gated at boot registration, this makes the handler self-protecting). | `src/Admin/Notices.php` |

**Open question (resolved by maintainer):** `Plugin::registry()`/`options()` and `Registry::get()` are kept — confirmed as deliberate public API on the singleton/registry, not dead code.

**Fix session (2026-07-10):** all 12 findings resolved. First 10 fixed directly; #13/#14 then unblocked by adding `PageDataLayerTest` (5) + `PurchaseTrackingTest` (9) — the first coverage for the WooCommerce purchase flow — and subsequently **fixed**: #13 extracted the shared eligibility/tracking helpers into `ProductData`; #14 decomposed `PageDataLayer::add_datalayer_data` (~455→~62-line dispatcher + 7 methods) and `ContainerCode::header_begin` (dispatcher + 4 string builders). Full suite **155 tests green** (was 141; +1 for #8, +14 new coverage); `vendor/bin/phpcs` clean (only pre-existing FP-3 `$echo` + unused-mock-param warnings remain). No `js/` source changed, so no `build/` regeneration was required. New public-API option key `event-html5-media` introduced for #9 (still `-dev`, renameable before release).

### Report 3: `.security/code-review-report-2026-07-13-2126.md`

Re-review of the MediaEvents expansion (11 media trackers incl. 8 new, shared `lib/native-video-params.js`, 12 `EVENTS_*` options) + `ContainerCode` OFF fix. **No Critical/High/Medium *security* findings** — trackers are static bundles with no HTML/JS injection sink (only `dataLayer.push`), VideoPress validates `event.origin`, all others SDK-mediated, none read request input; MediaEvents PHP is enqueue-only. One Medium *correctness* bug + Low consistency/hygiene.

| # | Sev | Status | Summary | File(s) |
|---|-----|--------|---------|---------|
| 19 | Medium | fixed | Correctness: undeclared `player` variable → strict-mode `ReferenceError`, aborting the per-iframe `forEach` (YouTube videos 2..N untracked). Fixed by dropping the unused assignment (`new YT.Player(...)`). Regression test: youtube "wires each embed … without throwing". | `js/frontend/gtm4wp-youtube.js` |
| 20 | Low | fixed | Consistency/correctness: added the `if(!duration)return` guard to the 3 older trackers. Not merely cosmetic — with `duration===0` and `time>0`, `time/0=Infinity` fired **all** milestones (e.g. live streams). Regression tests: soundcloud + youtube "no milestone when duration is 0". | `js/frontend/gtm4wp-soundcloud.js`, `gtm4wp-vimeo.js`, `gtm4wp-youtube.js` |
| 21 | Low | fixed | Hygiene: query-string assembly now uses the correct `?`/`&` separator (was `?&…`); origin kept raw to match the server-side oEmbed filter. Regression test: youtube "well-formed enablejsapi query". | `js/frontend/gtm4wp-youtube.js` |
| 22 | Low | fixed | Robustness: added `window.gtm4wp_<provider>_inited` double-init guards. Regression tests: wistia + spotify "does not re-register when loaded twice". | `js/frontend/gtm4wp-wistia.js`, `gtm4wp-spotify.js` |
| 23 | Low | fixed | Improvement (functional): YouTube-tracker enqueue now also matches modern `core/embed` blocks and classic URL auto-embeds (youtube.com / youtu.be), not only the legacy block. Regression tests added to `MediaEventsModuleTest`. | `src/Modules/MediaEvents/MediaEventsModule.php` |
| 24 | Low | fixed | Complexity: extracted `gtm4wpMediaMilestones()` + `gtm4wpOnReady()` into `lib/native-video-params.js`; migrated all 11 trackers (payloads byte-preserved). New lib unit tests cover both helpers. | `js/frontend/lib/native-video-params.js`, `gtm4wp-*.js` |

### Report 4: `.security/code-review-report-2026-07-14-1252.md`

Review of the 9 commits in `d36bca3..b15b034` (native Axeptio CMP integration, Contact Form 7 modernization, AMP amp-wp 2.x modernization, WebToffee deprecation, `sandboxedScripts` blacklist group class, `ContainerCode` `$echo` cleanup). **No Critical/High/Medium.** Every new `<script>`/dataLayer sink conforms to the established hardening (full hex flags, sanitize-on-save, output-sink allow-list, delegated AMP sink, React escaping). Three Low observations only.

| # | Sev | Status | Summary | File(s) |
|---|-----|--------|---------|---------|
| 28 | Low | fixed | Consistency/robustness: the CF7 tracker attaches `document`-level listeners in a bare IIFE with no double-init guard (unlike the media-tracker family, PA-9/#22); a twice-injected bundle would double-push. Fixed with a `window.gtm4wp_cf7_inited` guard; regression test `contact-form-7-tracker.test.js` "does not re-register … when the bundle loads twice". | `js/frontend/gtm4wp-contact-form-7-tracker.js` |
| 29 | Low | fixed | Latent drift: `ContainerCode::header_top` printed inline JS via raw `wp_kses()` (no ampersand-restore) vs `print_script_block()`. Fixed by routing `header_top` through `print_script_block()` (same path as `header_begin`), so head-block consent JS gets the ampersand restored (and the `type` attr is preserved on non-HTML5 themes). Regression test `ContainerCodeTest::test_header_top_restores_ampersands_in_head_block_js`. | `src/Frontend/ContainerCode.php` |
| 30 | Low | wontfix | Privacy-by-default (informational): CF7 `integrate-wpcf7-inputs` defaults to `full`, pushing submitted field values (potential PII) into the dataLayer. **Maintainer: expected behavior** — documented, opt-out provided (`names`/`none`), 1.x-compatible default. Not a vulnerability. | `src/Modules/ContactForm7/AdminSchema.php` |

**Fix session (2026-07-14):** #28 and #29 **fixed** (both with regression tests); #30 closed **wontfix** (intended, documented behavior). #29 routed `header_top` through `print_script_block()` — the same sanitizer as `header_begin` — resolving the ampersand-restore drift for all head-block consent JS and preserving the `<script>` `type` attribute on non-HTML5 themes. Verified: **PHP 282 tests green** (+1), **JS 161 tests / 17 suites green** (+11, new `contact-form-7-tracker.test.js`), `npm run build` + `npm run lint:js` clean, `phpcs` clean (bar the blessed unused-mock-param warning). CHANGELOG updated.

**Verification (no findings):** Axeptio settings sink RI-2-compliant with a hostile-input regression test (`ConsentModeAxeptioTest::test_head_js_hex_encodes_breakout_characters_in_settings`, both-directions); data-layer name validated to a JS identifier so unquoted `window.<name>` is safe; CF7 config SELECT-restricted + hex-flagged via `wp_add_inline_script`; Blacklist `sandboxedScripts` allow-list-validated at the sink AND on save; AMP delegated sink (FP-4) with `rawurlencode`d ID; admin JS React-escaped with `encodeURIComponent`'d fetch URL; standalone Axeptio module removed with no dangling refs. Baseline **281 tests green**, `phpcs` clean.

### Report 5: `.security/code-review-report-2026-07-15-1130.md`

Re-review of the WooCommerce overhaul + new consent/checkout bridges since Review 4 (`b15b034..HEAD`, 24 commits, ~6.8k insertions): Store API / Cart-Checkout **block tracking** (`StoreApiData`, `gtm4wp-woocommerce-blocks.js`, `lib/gtm4wp-blocks-cart-diff.js`), GA4-spec dataLayer refinements, per-item price-basis/discount fixes, cross-funnel list attribution (#405), reliable purchase tracking (#369), new `gtm4wp-ecommerce-generic.js` split, **CookieYes** bridge (#380), **CheckoutWC** compatibility (#385). **No Critical/High/Medium.** Every new script/dataLayer sink conforms to the established hardening (see the Review-5 coverage note). One Low.

| # | Sev | Status | Summary | File(s) |
|---|-----|--------|---------|---------|
| 31 | Low | fixed | Data-quality/consistency: block **cross-sell** `view_item_list`/`select_item` events leaked `internal_id` (internal product post id) into the GA4 dataLayer — the cross-sell item mapper omitted the `internal_id` strip its sibling cart-item mapper does. Not XSS (post id is public); inconsistent with every other tracking path. Fixed with a `delete item.internal_id` in `gtm4wp_blocks_to_crosssell_item()`; regression test "strips internal_id from cross-sell view_item_list / select_item items". | `js/frontend/gtm4wp-woocommerce-blocks.js` |

**Fix session (2026-07-15):** #31 **fixed** — one `delete item.internal_id` added to the cross-sell item mapper (mirroring the cart-item mapper), with a both-directions regression test (list identity present AND `internal_id` absent) on both the `view_item_list` and `select_item` cross-sell paths. Verified: **JS 214 tests / 21 suites green** (+1), `npm run build` + `npm run lint:js` clean; no PHP changed (PHP 381 tests still green). CHANGELOG updated.

**Verification (no findings):** main dataLayer sink retains the full hex flags (raw `orderData`/billing fields safe, RI-2/RI-4); inline pushes + begin_checkout + thank-you all hex-flagged via `wp_add_inline_script`/`print_script_block` (PA-8, FP-2); list-attribution cookie DoS-bounded + per-field-sanitized (#405); order-received `?order`/`?key` and `gtm4wp_orderid_tracked` cookie validated (RI-6); StoreApiData is a REST/delegated sink (FP-4 class, slash-escaping blocks `</script>`); new SELECT/MULTISELECT options allow-list-sanitized on save (PA-5); list markup `esc_attr(wp_json_encode())` + PA-7 replacement guards; CookieYes bridge = `esc_js`'d JS-identifier datalayer name only; block minicart double-count ruled out (store-default detection keeps `is_block_cart_or_checkout` and the minicart-load gate consistent); no new dead code. Baseline **381 PHP / 213 JS tests green**, `phpcs` clean.

### Report 3: fix session note

**Fix session (2026-07-13):** all six findings #19-24 **fixed** (#19 was resolved in the working tree by dropping the unused `player` assignment; #20-24 fixed in this session). #20 was upgraded Low→correctness once analysis showed `duration===0, time>0` fires `Infinity` milestones (not a benign `NaN` no-op). #24 refactor migrated all 11 trackers to the two new shared helpers with byte-preserved push payloads. New regression tests: youtube (enablejsapi query + duration-0 + the pre-existing undeclared-`player` guard), soundcloud (duration-0), wistia + spotify (double-init), and 7 `native-video-params` lib-helper tests; 2 `MediaEventsModuleTest` cases for #23. Verified: **JS 150 tests / 16 suites green** (was 138), **PHP 253 tests green**, `npm run build` + `npm run lint:js` clean, `phpcs` clean. CHANGELOG updated. Findings #23/#21 also fixed a pre-existing (Phase-0/Phase-2) functional gap, not just the new code.
