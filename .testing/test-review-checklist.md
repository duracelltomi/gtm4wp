# Test Review Checklist

> ## ⛔ Disclosure rule — HARD REQUIREMENT
>
> **This is a public repository. Committed == published.** A test gap is usually
> just a coverage note — safe to record here. But a *missing regression test on a
> security sink* can point at an unfixed vulnerability. This committed file (and
> `test-review-patterns.md`) MUST NOT contain exploit payloads, repro steps, or
> the technical detail of any **unfixed** security-relevant gap. Keep such a gap
> to a one-line "no hostile-input test for `<field>` in `<file>`" and put the
> detail in the git-ignored `.security/code-review-report-*.md`. When in doubt,
> write less here.

Persistent coverage tracker for systematic reviews of the GTM4WP **unit-test
suite** (PHP under `tests/`, JS under `js/**/test/`). This is the test-quality
sibling of `.security/code-review-checklist.md`; the two share the same component
groups so the matrices line up.

**How to use:** Before a review, read this file + `test-review-patterns.md`.
Prioritize `[ ]` (unreviewed) cells and the Test Debt Sweeps. After the review,
mark reviewed cells `[x]` with the date, update the sweeps, and append gaps to the
Known Test-Gaps Log.

**Status markers:**
- `[ ]` — not yet reviewed by the test-review system
- `[x] YYYY-MM-DD` — reviewed and judged satisfied on date
- `[~] YYYY-MM-DD` — reviewed but stale (source or test changed since)
- `[-]` — not applicable (e.g. no security sink → Sec-input is N/A; an intentionally-untested orchestrator — see patterns BE-3)

**Staleness rule:** A cell becomes `[~]` if the component's source **or** its test
changed after the review date. Check with `git log --since="YYYY-MM-DD" -- <src> <test>`.

**Dimensions:**
- *Exists* — a dedicated or documented-indirect test covers the component at all (the TS-6 sweep).
- *Public API* — every public method/behavior has at least one behavioral (non-tautological) test.
- *Edge/Err* — error / empty / boundary / invalid-input branches covered, not just the happy path (TS-5).
- *Sec-input* — a hostile-input regression test exists for each security-relevant sink in the component (TS-1/TS-2/TC-5), or `[-]` if it has none.
- *Regr* — a regression test exists for each past bug/finding touching the component (`.security` Known Findings + this log), or `[-]`.
- *Isol* — tests are deterministic and leak no global/superglobal state (TS-7/TS-8).

> **Access-control (authZ) is not a per-component column.** Authorization gates —
> `permission_callback`s, `current_user_can()` checks, filterable capabilities
> (`gtm4wp_admin_page_capability`) — are sparse and cross-cutting, so they are
> tracked by the **Access-control coverage** Test Debt Sweep below, not a matrix
> cell (TS-12/TC-13). A component's `[x]` cells cover its *output* behavior; they
> do **not** imply its capability gate is tested.

---

## Coverage Matrix

The *Test file(s)* column is the mechanical inventory (what exists today); the
dimension cells are the test-review system's judgment. A component can have tests
yet still carry `[ ]` cells — presence ≠ reviewed-and-complete.

| Component Group | Test file(s) | Exists | Public API | Edge/Err | Sec-input | Regr | Isol |
|---|---|---|---|---|---|---|---|
| **Plugin Bootstrap** (main file, `uninstall.php`, `Plugin.php`, `Autoloader.php`) | — | [ ] | [ ] | [ ] | [-] | [-] | [ ] |
| **Options** (`src/Options/` — Options, Field) | `Options/OptionsTest`, `Options/FieldTest` | [x] | [x] 2026-07-13 | [x] 2026-07-13 | [-] | [ ] | [ ] |
| **Compat Layer** (`compat/*.php`, `src/Compat/Globals.php`) | `Compat/GlobalsTest` | [x] 2026-07-13 | [x] 2026-07-13 | [x] 2026-07-13 | [ ] | [ ] | [x] 2026-07-13 |
| **Migration** (`src/Migration.php`) | `MigrationTest` | [x] | [ ] | [ ] | [-] | [-] | [ ] |
| **Frontend Core** (`src/Frontend/` — DataLayer, ScriptTag, ContainerCode, ConsentDefaults, VisitorIp; Frontend = N/A) | `Frontend/DataLayerTest`, `ScriptTagTest`, `ContainerCodeTest`, `ConsentDefaultsTest`, `VisitorIpTest` | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 |
| **Module Framework** (`src/Module/`) | `Module/RegistryTest`, `Modules/ModuleConsistencyTest`, `ModuleHooksTest` | [x] | [ ] | [ ] | [-] | [-] | [ ] |
| **PageVariables Module** (`src/Modules/PageVariables/`) | `Modules/PageVariablesModuleTest` | [x] | [ ] | [ ] | [x] 2026-07-13 | [x] 2026-07-13 | [x] 2026-07-13 |
| **Container Module** (`src/Modules/Container/`) | `Modules/ContainerRowsTest`, `ContainerAdminSchemaTest` | [x] | [ ] | [ ] | [ ] | [ ] | [ ] |
| **WooCommerce Module** (`src/Modules/WooCommerce/` — PurchaseTracking, ProductData, PageDataLayer, ListTracking, Helpers, StoreApiData, Module, AdminSchema) | `Modules/PurchaseTrackingTest`, `ProductDataTest`, `PageDataLayerTest`, `ListTrackingTest`, `HelpersTest`, `StoreApiDataTest`, `WooCommerceModuleTest`, `WooCommerceAdminSchemaTest` | [x] | [x] 2026-07-15 | [x] 2026-07-15 | [x] 2026-07-15 | [x] 2026-07-15 | [x] 2026-07-15 |
| **ConsentMode Module** (`src/Modules/ConsentMode/` — incl. Axeptio handler, CookieYes bridge) | `Modules/ConsentModeAxeptioTest`, `ConsentModeCookieYesTest`, `ModuleHooksTest` (gate + webtoffee + axeptio + cookieyes wiring) | [x] 2026-07-15 | [x] 2026-07-15 | [x] 2026-07-15 | [x] 2026-07-15 | [x] 2026-07-15 | [x] 2026-07-15 |
| **UserEvents Module** (`src/Modules/UserEvents/`) | `Modules/ModuleHooksTest` (gate only) | [x] | [ ] | [ ] | [ ] | [-] | [ ] |
| **MediaEvents Module** (`src/Modules/MediaEvents/` — 12 trackers) | `Modules/MediaEventsModuleTest` (enqueue gate + oEmbed rewrite), `ModuleHooksTest` (youtube gate), `ModuleConsistencyTest` (12 opts); JS: 14 tracker tests + `native-video-params` | [x] 2026-07-13 | [x] 2026-07-13 | [x] 2026-07-13 | [-] | [x] 2026-07-13 | [x] 2026-07-13 |
| **ContactForm7 Module** (`src/Modules/ContactForm7/` — incl. tracker JS) | `Modules/ContactForm7ModuleTest`, `ModuleHooksTest` (gate); JS: `contact-form-7-tracker` | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 |
| **Blacklist Module** (`src/Modules/Blacklist/` — incl. `sandboxedScripts` group class) | `Modules/BlacklistModuleTest` | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 |
| **ClientDeviceData Module** (`src/Modules/ClientDeviceData/`; JS tracker) | `Modules/ClientDeviceDataModuleTest`, `ModuleHooksTest` (gate); JS: `client-device-data-tracker` | [x] 2026-07-13 | [x] 2026-07-14 | [x] 2026-07-14 | [-] | [x] 2026-07-14 | [x] 2026-07-14 |
| **AMP Module** (`src/Modules/Amp/` — amp-wp 2.x `amp_analytics_entries` sink) | `Modules/AmpModuleTest`, `ModuleHooksTest` (gate) | [x] | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 |
| **Admin — Notices/AJAX** (`src/Admin/Notices.php`) | `Admin/NoticesTest` | [x] 2026-07-13 | [ ] | [x] 2026-07-13 | [x] 2026-07-13 | [x] 2026-07-13 | [x] 2026-07-13 |
| **Admin — Settings UI** (`src/Admin/SettingsPage.php`, `Admin.php`, `RestController.php`, `PluginRow.php`) | `Admin/RestControllerTest`, `SettingsPageTest`, `PluginRowTest`, `AdminCapabilityFilterTest` | [x] | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 |
| **Module Admin Schemas** (`src/Modules/*/AdminSchema.php`) | `Modules/ModuleConsistencyTest`, `ContainerAdminSchemaTest` | [x] | [ ] | [ ] | [ ] | [-] | [ ] |
| **Frontend JS** (`js/frontend/` — 18 trackers + `lib/native-video-params` + `lib/blocks-cart-diff`) | 20 `js/frontend/test/*.test.js` (all trackers + libs; `woocommerce`/`woocommerce-blocks`/`blocks-cart-diff` now covered — the previously-open `woocommerce` gap is closed) | [x] 2026-07-15 | [x] 2026-07-15 | [x] 2026-07-15 | [x] 2026-07-15 | [x] 2026-07-15 | [x] 2026-07-15 |
| **Admin JS** (`js/admin/`) | `js/admin/test/utils.test.js` | [x] | [ ] | [ ] | [ ] | [ ] | [ ] |

> **Seed note (2026-07-13):** only **Frontend Core** has been through a full
> test-review pass (the session that added `VisitorIpTest` + 31 Frontend tests).
> Every other row is the mechanical inventory only — `Exists [x]` means *some*
> test touches the component, not that it is complete. The first real run of
> `/test-review` should clear `[ ]` cells starting with the ⭐ priorities in the
> sweeps below.

---

## Test Debt Sweeps

Whole-suite concerns that don't map onto per-component cells. Re-run each on every
review; treat a sweep older than ~4 weeks or predating a feature landing as stale.

| Sweep | Last run | Result summary |
|---|---|---|
| **Missing test file** (`src/**/*.php` with no dedicated or documented-indirect test) | 2026-07-15 (Run 4) | No real zero-test class in the WooCommerce/consent batch — every new class ships a test (`StoreApiDataTest`, `WooCommerceModuleTest`, `WooCommerceAdminSchemaTest`, `ProductDataTest`, `PageDataLayerTest`, `ConsentModeCookieYesTest`). Sweep over-reports on basename mismatches (`WooCommerceAdminSchemaTest`≠`AdminSchema`, `ConsentModeCookieYesTest`≠`ConsentModeModule`). Remaining PHP BE-3 N/A unchanged (`Plugin`, `Frontend`, `Autoloader`, `Admin/Admin`, module orchestrators, interfaces). |
| **Untested public methods** (in classes that *do* have a test file — TS-10) | 2026-07-15 (Run 4 + closes) | **Closed:** `StoreApiData::register()`; `ListTracking::{quick_view_before_single_product, cart_item_product_filter, cart_item_remove_link_filter, single_add_to_cart_tracking, grouped_product_list_column_label, after_template_part}` (T22); classic JS `gtm4wp_woocommerce_handle_cart_qty_change` + remove-link handler (T25). Prior remaining low-value (`ListTracking` list-name setters; `Notices::print_dismiss_script`) unchanged. |
| **Security-input coverage** (every `.security` PA-3/RI-2 sink has a hostile-input test — TS-1/TC-5) | 2026-07-15 (Run 4) | **Batch clean — no open hostile-input sink gap.** Verified both-directions: list-attribution cookie #405 (the one new untrusted request surface — `ProductDataTest`+`PageDataLayerTest`); product-title + order-number `<script>` sinks; raw order billing/shipping passthrough (#8 contract); PA-7 replacement injectors (#16); CookieYes datalayer-name `esc_js` (`ConsentModeCookieYesTest`). Store-API block sink is a delegated REST sink (FP-4) — the only *open* item is a raw-passthrough **contract** test (T21, data-integrity not XSS) + `item_category` benign-only (T23). |
| **Regression-per-bug** (every `.security` Known Finding has a live regression test) | 2026-07-15 (Run 4) | `.security` Review 5 landed one Low (#31 block cross-sell `internal_id` strip) with a both-directions regression in `woocommerce-blocks-tracker.test.js` — verified live. Prior #1–#6/#8/#11–#18/#28–#30 unchanged. **No unverified findings remain.** |
| **JS test coverage** (`js/frontend/` trackers, `js/admin/` app) | 2026-07-15 (Run 4 + closes) | **JS suite 214 → 231 green (21 suites)** after closing T25 (classic cart qty-change + remove-link + variable/grouped/disabled add_to_cart + chunking + parse catches) and T26 (delegated-listener isolation harness, probe-verified). Also confirmed Run-4 baseline: `woocommerce` (last open tracker gap, now closed), `woocommerce-blocks`, `blocks-cart-diff`, `ecommerce-generic` all covered; pure cart-diff lib + block tracker core well-covered (real `subscribe()` drive, TC-10). **No open JS gaps.** |
| **Access-control coverage** (every `permission_callback` / capability gate has a grant+deny test; every filterable capability — `gtm4wp_admin_page_capability` — has a test that the filter customizes the required cap while the default stays unchanged — TS-12/TC-13) | 2026-07-15 (issue #143) | **Closed the one open gap.** `RestController::can_manage()` (REST `permission_callback`) and `SettingsPage::add_admin_page()` (`add_options_page()` cap arg = menu + render guard) were executed by **no** test — the unit tests call the REST handlers directly, bypassing the permission callback, so a coverage driver would show `can_manage()` at 0%. Added `AdminCapabilityFilterTest` (default `manage_options` unchanged + filtered-cap grant/deny at both sites). `Notices::dismiss_notice` deny-direction already covered (finding #18, T8). Module frontend hooks gate on *options*, not capability, so they are out of scope for this sweep. |
| **Assertion quality** (mutation testing — Infection, optional) | never | Not yet enabled. See `.claude/commands/test-review.md` § Optional tooling to install `infection/infection` + a coverage driver. |

---

## Known Test-Gaps Log

Each gap is logged once. Status: `open` | `addressed` | `wontfix`.

> **Reports are local-only.** The detailed `test-review-report-*.md` files are
> git-ignored (`.testing/.gitignore`). This log keeps terse summaries; for a gap
> on a *security* sink, never restate exploit detail — defer to `.security/`.

### Run 0 (seed): `tests/unit/Frontend/` review — 2026-07-13

Session that broadened `tests/unit/Frontend/`; findings addressed in the same
session (commit "expand Frontend unit-test coverage and fix OFF-placement iframe
leak"). Recorded here as the seed baseline.

| # | Sev | Status | Summary | File(s) |
|---|-----|--------|---------|---------|
| T1 | High | addressed | `Frontend/VisitorIp` had zero tests (security-sensitive `$_SERVER`/header IP reader). Added `VisitorIpTest` (11 cases: fallback, precedence, spoof rejection, XFF scanning). | `tests/unit/Frontend/VisitorIpTest.php` |
| T2 | High | addressed | `DataLayer::flush_pushes()` additional-push sink was line-covered with benign data only; no hostile-input guard (TS-1). Added hex-encoding regression. | `tests/unit/Frontend/DataLayerTest.php` |
| T3 | Med | addressed | `ContainerCode::get_tag()` OFF-placement + console-log-off emitted the iframe (latent bug the missing test hid). Fixed source + added regression. | `src/Frontend/ContainerCode.php`, `tests/unit/Frontend/ContainerCodeTest.php` |
| T4 | Med | addressed | `container_path()` invalid-value fallback (PA-2 sink) untested while `container_domain()` had it (TS-5). Added. | `tests/unit/Frontend/ContainerCodeTest.php` |
| T5 | Low | addressed | Untested methods/branches of covered classes (TS-10): `compiled()`, `name()` non-string, `header_top($echo=false)`, `FILTER_HEADER_TOP_JS`, `body_open`/`footer`/`the_tag`, `register_hooks` LOADEARLY priority, rocket exclusion, combined `opening_tag()`, print_script_block rules override, consent denied→granted filter. | `tests/unit/Frontend/*` |
| T6 | Low | addressed | `enqueue_push_handle()` asserted register/enqueue but not the flush (TS-3). Strengthened. | `tests/unit/Frontend/DataLayerTest.php` |

### Run 1: module/admin security-sink pass — 2026-07-13

First cumulative run. Prioritized the open security-input + regression-per-bug
sweeps; proved each gap with a regression test (the PA-7 one by reverting the
source guard and watching it fail). All addressed in the same session; suite
187 → 198 green, phpcs clean.

| # | Sev | Status | Summary | File(s) |
|---|-----|--------|---------|---------|
| — | High | addressed | `ListTracking::add_productdata_to_wc_block` PA-7 `preg_replace`-replacement path (finding #16) proved un-guarded-would-mangle, now regression-tested (closes T7). | `tests/unit/Modules/ListTrackingTest.php` |
| — | High | addressed | `PageVariables` `geoCloudflareCountryCode` was benign-only (TS-1); `visitorIP` module branch untested. Added raw-passthrough (finding #12) + branch tests, plus `$_SERVER` isolation. | `tests/unit/Modules/PageVariablesModuleTest.php` |
| — | Med | addressed | `Notices::dismiss_notice` nonce + capability re-check (finding #18) + `basename`/allow-list on `noticeid` now tested (closes T8). | `tests/unit/Admin/NoticesTest.php` |

### Run 1 (continued): full remaining-gap sweep — 2026-07-13

Second batch of the same run (user asked to close every remaining gap found this
round). Suite 198 → 240 PHP tests + 25 JS tests, all green; phpcs 0 errors; no
`src/` changes (AMP flag revert used only to prove the regression, then restored).

| # | Sev | Status | Summary | File(s) |
|---|-----|--------|---------|---------|
| — | Med | addressed | `Amp::render_amp_gtm_code` hex-flag sink (finding #11) — reflection-injected Plugin→Frontend→DataLayer chain; proved fails-without-flags (raw `</script>` breakout), then restored. Closes the AMP part of T13. | `tests/unit/Modules/AmpModuleTest.php` |
| — | Med | addressed | `Compat/Globals::populate()` — datalayer-name fallback + no-clobber branches (closes T9). | `tests/unit/Compat/GlobalsTest.php` |
| — | Low | addressed | `Options/Field` per-type sanitizer + choice fallback + derive + rest_type + to_ui_array; `WooCommerce/Helpers` string/id/taxonomy helpers (closes T11 pure-logic part). | `tests/unit/Options/FieldTest.php`, `tests/unit/Modules/HelpersTest.php` |
| — | Low | addressed | `ClientDeviceData` + `MediaEvents` hook-gate (closes T12); ClientDeviceData config-output structure. | `tests/unit/Modules/ModuleHooksTest.php`, `ClientDeviceDataModuleTest.php` |
| — | Low | addressed | `Notices::show_notices` output branches — missing-GTM-ID, incomplete env-config, dismissed-state (closes T14). | `tests/unit/Admin/NoticesTest.php` |
| — | Med | addressed | `Frontend JS` harness established + `ecommerce-generic` (public 1.x JS API incl. product-JSON reader) + `form-move-tracker` (DOM event→dataLayer). Closes 2 of 9 (T10 → T16). | `js/frontend/test/*.test.js` |

### Run 2: media-tracker batch review — 2026-07-13

Reviewed commits `b196d45`..`44d5296` (native-video-params lib, modernized
YouTube/Vimeo/SoundCloud/HTML5, 8 new trackers, rewritten `MediaEvents` PHP
module). Report-only pass, then the user approved closing all four gaps.
**All addressed in the same session** — and a concurrent `/code-review` then
landed its own fixes to the same tracker sources. **Final settled tree is green:**
PHP 253 tests / 778 assertions, JS 16 suites / 150 tests, phpcs 0 errors, build +
lint clean. **A latent bug surfaced and was fixed** (see T17). Full detail in the
git-ignored `test-review-report-2026-07-13-2121.md`.

| # | Sev | Status | Summary | File(s) |
|---|-----|--------|---------|---------|
| T17 | High | addressed | `gtm4wp-youtube.js` had no dedicated test; writing it (TC-10 harness) **surfaced a latent bug** — an undeclared `player = new YT.Player(...)` threw `ReferenceError` in the strict-mode 2.0 module on every page with a YouTube embed. Fixed (dropped the dead assignment) + `youtube-tracker.test.js` (9 tests, incl. a not-throw regression proven to fail pre-fix). | `js/frontend/gtm4wp-youtube.js`, `js/frontend/test/youtube-tracker.test.js` |
| T18 | Med | addressed | `MediaEventsModule::enqueue_scripts()` — youtube block/`<iframe>`-content detection + option gates now tested (`MediaEventsModuleTest`). | `tests/unit/Modules/MediaEventsModuleTest.php` |
| T19 | Low | addressed | `MediaEventsModule::enable_youtube_js_api()` — oEmbed rewrite / non-youtube / non-string branches (`MediaEventsModuleTest`). | `tests/unit/Modules/MediaEventsModuleTest.php` |
| T20 | Low | addressed | VideoPress `gtm4wp_isVideoPressOrigin()` — subdomain + `video.wordpress.com` accept, look-alike + non-string + bad-JSON reject (`videopress-tracker.test.js` 8 → 14). | `js/frontend/test/videopress-tracker.test.js` |

### Run 3: 2026-07-14 batch review (Axeptio / CF7 / AMP / Blacklist / ContainerCode) — 2026-07-14

Report-only cumulative pass over `d36bca3..780875c`. The concurrent `/code-review`
(`.security` Report 4) shipped its TC-1 regression tests with the fixes; **Run 3
audited the quality of those shipped tests** and found the whole batch well-guarded
(hostile-input both-directions on every new sink; correct raw-passthrough contract
on the two delegated sinks). No latent bug surfaced. **No tests written this run.**
Baseline: PHP 282/863 green, JS 17 suites/161 green. Full detail in the git-ignored
`test-review-report-2026-07-14-1338.md`.

| # | Sev | Status | Summary | File(s) |
|---|-----|--------|---------|---------|
| — | — | verified | Axeptio head-JS sink hostile-input both-directions + wiring + consumer-suppress (both ends of `FILTER_DEFAULT_ENABLED`). | `tests/unit/Modules/ConsentModeAxeptioTest.php`, `tests/unit/Frontend/ConsentDefaultsTest.php`, `ModuleHooksTest.php` |
| — | — | verified | AMP delegated sink rewritten (`add_amp_analytics_entries`) — hostile raw-passthrough (finding #11 guard re-expressed), on-demand compile, fallback chain. | `tests/unit/Modules/AmpModuleTest.php` |
| — | — | verified | Blacklist `sandboxedScripts` group class + hostile `</script>` allow-list filter; CF7 form-title raw-passthrough + double-init (#28); `header_top` ampersand-restore (#29). | `BlacklistModuleTest`, `ContactForm7ModuleTest`, `contact-form-7-tracker.test.js`, `ContainerCodeTest` |

### Run 4: 2026-07-15 WooCommerce overhaul + consent/checkout bridges — 2026-07-15

Report-only cumulative pass over `780875c..HEAD` (24 commits; Store-API/block
tracking, GA4-spec dataLayer, list attribution #405, purchase reliability #369,
CookieYes #380, CheckoutWC #385). 5 parallel `test-reviewer` deep-reads, every
logged gap lead-verified against code + test. **Security/XSS well-guarded — no
High gap, no latent bug.** Two fan-out Mediums collapsed on verification
(`is_order_older_than_max_age` TRUE branch + `$_COOKIE[...tracked]` dedupe branch
are covered by `PurchaseTrackingTest`, not `ProductDataTest`). Full detail in the
git-ignored `test-review-report-2026-07-15-1304.md`. **No tests written this run.**
Baseline PHP 381/1067, JS 21 suites/214 green.

**All closed 2026-07-15** (same session, on the user's "fix all" go-ahead). Suite
PHP 381→416 / JS 214→231 green; phpcs 0 errors; lint:js clean; no production code
changed (tests-only → CHANGELOG exempt). Two security-contract tests were
throwaway-probe-verified (T21 fails if `item_name` is pre-escaped; T22 fails if
JSON_HEX_AMP is dropped from the purchase sink). New test stub
`tests/unit/Modules/store-api-stub.php` scaffolds the Store API for T21's `register()`.

| # | Sev | Status | Summary | File(s) |
|---|-----|--------|---------|---------|
| T21 | Med | addressed | `StoreApiData` block Store-API callbacks now have both-directions special-char raw-passthrough (product + cart-item round-trip) + a `register()` wiring test. Probe-verified (fails if `item_name` pre-escaped). | `tests/unit/Modules/StoreApiDataTest.php`, `store-api-stub.php` |
| T22 | Med | addressed | `PurchaseTracking` `WCORDERDATA` branch now driven with hostile billing/company/coupon — hex-encoded (each flag pinned), raw break-out + entity-encoding absent; probe-verified (fails if JSON_HEX_AMP dropped). `ListTracking` quick-view (3 branches), cart remove-link pair, single-add-to-cart, grouped-label, after-template-part now covered (+ TS-7 global reset). | `tests/unit/Modules/PurchaseTrackingTest.php`, `ListTrackingTest.php` |
| T23 | Med | addressed | `WooCommerceModule::register_frontend_hooks()` TC-4 gate test added to `ModuleHooksTest` (TC-8 Plugin-singleton harness): block-data feed + thankyou + global-vars wired on / absent off; purchase seed hooks only when reliability enabled. | `tests/unit/Modules/ModuleHooksTest.php` |
| T24 | Low | addressed | `item_category`/variant/brand + coupon raw-passthrough; `StoreApiData::register()`; `is_order_older_than_max_age` paid-date branch; PageDataLayer event cluster (view_cart/cartContent/re-added/variable-on-parent/grouped); WC AdminSchema trim sanitizers; `add_global_vars` CheckoutWC/list flags; `queue_push` precedence; `Field::to_ui_array` phase. | `tests/unit/Modules/*`, `tests/unit/Frontend/DataLayerTest.php`, `Options/FieldTest.php` |
| T25 | Med | addressed | Classic JS cart handlers now tested (exact counts): qty-change add/remove net-delta, remove-link (input + textContent qty + qty-0 guard), variable/grouped/disabled add_to_cart, view_item_list chunking, QuickView + found_variation parse catches. | `js/frontend/test/woocommerce-tracker.test.js` |
| T26 | Low | addressed | TS-7 JS isolation fixed in-test: a `bootWithCapture`/`detachCaptured` harness records and `afterEach`-removes the module-load delegated `document` listeners; probe-verified (neutralizing the detach double-fired `remove_from_cart`), enabling exact-count assertions. | `js/frontend/test/woocommerce-tracker.test.js`, `woocommerce-blocks-tracker.test.js` |

### Access-control coverage — new sweep/dimension — 2026-07-15

Triggered by issue #143: the `gtm4wp_admin_page_capability` filter (delegate GTM4WP
settings to a non-admin role) turned out to be **already implemented** (since 1.20,
from @emreerkan) and applied at every gate, but was **never regression-tested**.
Added the **Access-control coverage** sweep + patterns TS-12 / TC-13 and wired the
lens into the pre-flight, the `test-reviewer` agent and the `/test-review` command.
Tests-only (CHANGELOG exempt); suite PHP 439 → 444 green, phpcs 0 errors.

| # | Sev | Status | Summary | File(s) |
|---|-----|--------|---------|---------|
| T27 | Med | addressed | `gtm4wp_admin_page_capability` gate untested: `RestController::can_manage()` + `SettingsPage::add_admin_page()` had **zero** executing tests (TS-12). Added default-unchanged + filtered-cap grant/deny at both sites. **Why it slipped:** the review's XSS-first lens has no authZ dimension; the missing-test-file sweep (TS-6) is file-granular so both files counted as "covered"; and capability tests were only ever written where a `.security` finding forced one (Notices #18) — this gate never had a finding. | `tests/unit/Admin/AdminCapabilityFilterTest.php` |

### Open gaps (prioritized by the sweeps above)

Terse, non-exploit; prioritized by the sweeps above.

| # | Sev | Status | Summary | File(s) |
|---|-----|--------|---------|---------|
| T7 | High | addressed | `WooCommerce/ListTracking` PA-7 replacement-injection (finding #16) — `ListTrackingTest`. | `tests/unit/Modules/ListTrackingTest.php` |
| T8 | Med | addressed | `Admin/Notices` dismiss handler (finding #18) — `NoticesTest`. | `tests/unit/Admin/NoticesTest.php` |
| T9 | Med | addressed | `Compat/Globals` populate() — `GlobalsTest`. | `tests/unit/Compat/GlobalsTest.php` |
| T10 | Med | addressed | JS harness established; 2 of 9 trackers covered. Remaining trackers → T16. | `js/frontend/test/*` |
| T11 | Low | addressed | `Options/Field` + `WooCommerce/Helpers` + `ClientDeviceData`/`MediaEvents` covered. `Admin/{SettingsPage,PluginRow,Admin}` remain → T15. | (various) |
| T12 | Low | addressed | `ClientDeviceData`/`MediaEvents` gate now in `ModuleHooksTest`. | `tests/unit/Modules/ModuleHooksTest.php` |
| T13 | Med | addressed | `SettingsPage` bootstrap hex sink now regression-tested: `SettingsPageTest` drives `enqueue_assets()` with a hostile stored container domain (all four hex flags) and captures `wp_add_inline_script`; proved fails-without-a-flag via a throwaway probe, then restored. Closes the `SettingsPage` part of #11. | `tests/unit/Admin/SettingsPageTest.php` |
| T14 | Low | addressed | `Notices::show_notices` branches — `NoticesTest`. | `tests/unit/Admin/NoticesTest.php` |
| T15 | Low | addressed | `Admin/PluginRow` now tested (`PluginRowTest`): action-link gating + `show_upgrade_notification` both-directions escaping of the remote notice (finding #15) + empty/blank branches. Remaining untested classes `Plugin`, `Frontend`, `Autoloader`, `Admin/Admin`, `WooCommerce/WooCommerceModule` recorded **BE-3 N/A** (orchestrators; WC logic in tested helpers). | `tests/unit/Admin/PluginRowTest.php` |
| T16b | Low | addressed | `client-device-data` JS tracker now tested (`client-device-data-tracker.test.js`, 6 tests): both detection paths + config gating + reject branch + no-push guard. **`woocommerce` tracker left `open`** at the user's request (tracker under review / possible changes). | `js/frontend/test/client-device-data-tracker.test.js` |
| T16 | Med | addressed | Media batch (Run 2) added 12 tracker tests + `native-video-params`; JS 25 → 123 green. Untested trackers now 3: `woocommerce`, `client-device-data`, `contact-form-7-tracker` (→ tracked in the Run 2 sweep). `youtube` split out as its own new-code gap T17. | `js/frontend/test/*` |
