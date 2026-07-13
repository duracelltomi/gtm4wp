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
| **Options** (`src/Options/`) | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 |
| **Compat Layer** (`compat/constants.php`, `compat/functions.php`, `src/Compat/Globals.php`) | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [-] | [x] 2026-07-10 | [-] | [x] 2026-07-10 |
| **Migration** (`src/Migration.php`) | [x] 2026-07-10 | [x] 2026-07-10 | [-] | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 |
| **Frontend Core** (`src/Frontend/` — DataLayer, ScriptTag, ContainerCode, ConsentDefaults, VisitorIp, Frontend) | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 |
| **Module Framework** (`src/Module/`) | [-] | [-] | [-] | [-] | [x] 2026-07-10 | [-] | [x] 2026-07-10 |
| **PageVariables Module** (`src/Modules/PageVariables/`) | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 |
| **Container Module** (`src/Modules/Container/`) | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 |
| **WooCommerce Module** (`src/Modules/WooCommerce/` — PurchaseTracking, ProductData, PageDataLayer, ListTracking, Helpers) | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 |
| **ConsentMode Module** (`src/Modules/ConsentMode/`) | [-] | [-] | [x] 2026-07-10 | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 |
| **UserEvents Module** (`src/Modules/UserEvents/`) | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 |
| **MediaEvents Module** (`src/Modules/MediaEvents/`) | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 |
| **ContactForm7 Module** (`src/Modules/ContactForm7/`) | [-] | [-] | [x] 2026-07-10 | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 |
| **Blacklist Module** (`src/Modules/Blacklist/`) | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 |
| **ClientDeviceData Module** (`src/Modules/ClientDeviceData/`) | [-] | [-] | [x] 2026-07-10 | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 |
| **AMP Module** (`src/Modules/Amp/`) | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 |
| **Admin — Notices/AJAX** (`src/Admin/Notices.php`) | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 |
| **Admin — Settings UI** (`src/Admin/SettingsPage.php`, `Admin.php`, `RestController.php`, `PluginRow.php`) | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 |
| **Module Admin Schemas** (`src/Modules/*/AdminSchema.php`) | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 | [-] | [x] 2026-07-10 | [-] | [x] 2026-07-10 |
| **Frontend JS** (`js/frontend/`) | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [-] |
| **Admin JS** (`js/admin/`) | [x] 2026-07-10 | [x] 2026-07-10 | [x] 2026-07-10 | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [-] |
| **Tests** (`tests/`) | [-] | [-] | [-] | [-] | [x] 2026-07-10 | [-] | [-] |

> **Coverage note (Review 2, 2026-07-10):** every component group has now had a first full pass across all applicable dimensions. Cells marked `[-]` are not-applicable (e.g. frontend modules perform no state-changing admin mutation, so Cap/Nonce is N/A; no `$wpdb` usage exists anywhere in `src/`, so SQL is N/A except where a component was explicitly confirmed clean). The Admin — Notices Cap/Nonce cell is now `[x]`: dismiss handler has nonce + allow-listed input but **no capability check** — see finding #18 (self-scoped, negligible impact).

---

## Whole-Repo Sweeps

Dead code and cross-file duplication are **whole-repo** concerns — they do not map onto the per-component Coverage Matrix. Log each sweep here with the date last run and a one-line result. Run via the playbook in `.claude/commands/code-review.md` § B (grep-for-references, not eyeball). Treat a sweep older than ~4 weeks, or predating a significant feature landing, as stale.

| Sweep | Last run | Result summary |
|---|---|---|
| **Dead functions/methods** (private/public across `src/`, `compat/`, root `*.php`, `tests/`) | 2026-07-10 | `Field::rest_type()` was dead (finding #10) — now used by `RestController::value_schema()`, fixed. `Plugin::registry()`/`options()` + `Registry::get()` kept as intentional public API. All other methods reachable. |
| **Dead hooks** (`add_action`/`add_filter` with no callback; `do_action`/`apply_filters` constants with no listener) | 2026-07-10 | Clean. All custom `do_action`/`apply_filters` constants are documented public extension points (`AFTER_DATALAYER`, `AFTER_CONTAINER_CODE`, `EEC_*`, `GET_CSP_NONCE`, `OVERWRITE_COMO_FLAG`, `ADDGLOBALVARS_ARRAY`); internal filters (`FILTER_HEADER_TOP_JS`, `FILTER_AMP_RUNNING`, `COMPILE_DATALAYER`) all have live listeners. |
| **Dead option constants** (`GTM4WP_OPTION_*`/`GTM4WP_*` in `compat/constants.php`, never read) | 2026-07-10 | Clean. Removed-feature constants (WEATHER*/SCROLLER*/WPECOMMERCE/MISCGEO/MISCGEOAPI/BLACKLIST_SANDBOXED) are intentionally kept for BC and referenced in `Migration::REMOVED_OPTION_KEYS`. All active option constants are read. |
| **Dead JS** (`js/**/*.js` never enqueued / no `build/` entry) | 2026-07-10 | `js/frontend/gtm4wp-html5media.js` was built but never enqueued (finding #9) — now wired into MediaEvents as an opt-in, fixed. All other frontend bundles enqueued by their module; `js/admin/*` wired via the `admin` entry. |
| **Duplication / drift** (a helper coexisting with inline copies; a module escaping the dataLayer differently from siblings) | 2026-07-10 | Order-eligibility + purchase-datalayer logic duplicated across `PageDataLayer`/`PurchaseTracking` (finding #13). Script-JSON flag drift across 4 sinks (finding #11) + `esc_js` pre-escape drift (`ProductData::get_raw_order_datalayer` vs siblings, findings #8/#12). |
| **Over-abstraction** (single-caller interfaces, forward-only wrappers, unread options) | 2026-07-10 | None material. Module framework (AbstractModule/Registry/interfaces) justified by 10 built-ins + third-party extension point. `Field::rest_type()` dead (finding #10). `Plugin`/`Registry` getters likely public API (open question). |

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
