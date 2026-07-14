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
| **WooCommerce Module** (`src/Modules/WooCommerce/` — PurchaseTracking, ProductData, PageDataLayer, ListTracking, Helpers, Module) | `Modules/PurchaseTrackingTest`, `ProductDataTest`, `PageDataLayerTest`, `ListTrackingTest`, `HelpersTest` | [x] | [ ] | [ ] | [x] 2026-07-13 | [x] 2026-07-13 | [ ] |
| **ConsentMode Module** (`src/Modules/ConsentMode/` — incl. Axeptio handler) | `Modules/ConsentModeAxeptioTest`, `ModuleHooksTest` (gate + webtoffee + axeptio wiring) | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 |
| **UserEvents Module** (`src/Modules/UserEvents/`) | `Modules/ModuleHooksTest` (gate only) | [x] | [ ] | [ ] | [ ] | [-] | [ ] |
| **MediaEvents Module** (`src/Modules/MediaEvents/` — 12 trackers) | `Modules/MediaEventsModuleTest` (enqueue gate + oEmbed rewrite), `ModuleHooksTest` (youtube gate), `ModuleConsistencyTest` (12 opts); JS: 14 tracker tests + `native-video-params` | [x] 2026-07-13 | [x] 2026-07-13 | [x] 2026-07-13 | [-] | [x] 2026-07-13 | [x] 2026-07-13 |
| **ContactForm7 Module** (`src/Modules/ContactForm7/` — incl. tracker JS) | `Modules/ContactForm7ModuleTest`, `ModuleHooksTest` (gate); JS: `contact-form-7-tracker` | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 |
| **Blacklist Module** (`src/Modules/Blacklist/` — incl. `sandboxedScripts` group class) | `Modules/BlacklistModuleTest` | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 |
| **ClientDeviceData Module** (`src/Modules/ClientDeviceData/`; JS tracker) | `Modules/ClientDeviceDataModuleTest`, `ModuleHooksTest` (gate); JS: `client-device-data-tracker` | [x] 2026-07-13 | [x] 2026-07-14 | [x] 2026-07-14 | [-] | [x] 2026-07-14 | [x] 2026-07-14 |
| **AMP Module** (`src/Modules/Amp/` — amp-wp 2.x `amp_analytics_entries` sink) | `Modules/AmpModuleTest`, `ModuleHooksTest` (gate) | [x] | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 |
| **Admin — Notices/AJAX** (`src/Admin/Notices.php`) | `Admin/NoticesTest` | [x] 2026-07-13 | [ ] | [x] 2026-07-13 | [x] 2026-07-13 | [x] 2026-07-13 | [x] 2026-07-13 |
| **Admin — Settings UI** (`src/Admin/SettingsPage.php`, `Admin.php`, `RestController.php`, `PluginRow.php`) | `Admin/RestControllerTest`, `SettingsPageTest`, `PluginRowTest` | [x] | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 |
| **Module Admin Schemas** (`src/Modules/*/AdminSchema.php`) | `Modules/ModuleConsistencyTest`, `ContainerAdminSchemaTest` | [x] | [ ] | [ ] | [ ] | [-] | [ ] |
| **Frontend JS** (`js/frontend/` — 17 trackers + `lib/native-video-params`) | 17 `js/frontend/test/*.test.js` (16 of 17 trackers + lib; untested: `woocommerce` — left open pending tracker review) | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 |
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
| **Missing test file** (`src/**/*.php` with no dedicated or documented-indirect test) | 2026-07-14 (Run 3 + closes) | Run 3 closes: `Admin/SettingsPage` (`SettingsPageTest`) + `Admin/PluginRow` (`PluginRowTest`) now tested. Remaining PHP BE-3 N/A: `Plugin`, `Frontend`, `Autoloader`, `Admin/Admin`, `WooCommerce/WooCommerceModule`, interfaces. **JS:** `client-device-data` closed; only `woocommerce` untested (left open pending tracker review). Standalone `Modules/Axeptio/` removed (moved under `ConsentMode/Axeptio.php`, tested by `ConsentModeAxeptioTest`). |
| **Untested public methods** (in classes that *do* have a test file — TS-10) | 2026-07-14 (Run 3 + closes) | Run 3: `Axeptio::*`, `ContactForm7Module::*`, `AmpModule::*`, `Blacklist::valid_*` covered. Closes: `SettingsPage::{enqueue_assets,bootstrap_data}` (`SettingsPageTest`), `PluginRow::{add_action_links,show_upgrade_notification}` (`PluginRowTest`). Remaining low-value: `ListTracking` ~15 list-name setters; `Notices::print_dismiss_script`. |
| **Security-input coverage** (every `.security` PA-3/RI-2 sink has a hostile-input test — TS-1/TC-5) | 2026-07-14 (Run 3 + closes) | Run 3 batch clean: Axeptio head-JS both-directions (`ConsentModeAxeptioTest`); Blacklist allow-list hostile `</script>` filtered (`BlacklistModuleTest`); ContainerCode `header_top` ampersand-restore both-directions (`ContainerCodeTest` #29); AMP delegated-sink raw-passthrough (`AmpModuleTest`); CF7 form-title raw-passthrough (`ContactForm7ModuleTest`). **Closed:** `SettingsPage` bootstrap hex sink now hostile-input both-directions + fails-without-a-flag (`SettingsPageTest`, T13); `PluginRow` remote upgrade-notice both-directions escape (`PluginRowTest`, #15). **No open hostile-input sink gaps remain.** |
| **Regression-per-bug** (every `.security` Known Finding has a live regression test) | 2026-07-14 (Run 3 + closes) | #28 (CF7 double-init, `contact-form-7-tracker.test.js`); #29 (`header_top` ampersand-restore, `ContainerCodeTest`); #30 wontfix; AMP #11 re-expressed for the delegated sink (`AmpModuleTest`). **Closed:** #11 `SettingsPage` sink now guarded (`SettingsPageTest`); #15 `PluginRow` i18n/escape now guarded (`PluginRowTest`). Prior #1–#6/#8/#12/#13/#14/#16/#18 unchanged. **No unverified findings remain.** |
| **JS test coverage** (`js/frontend/` trackers, `js/admin/` app) | 2026-07-14 (Run 3 + closes) | **JS suite 161 → 167 green (17 → 18 suites)** after closing `client-device-data-tracker.test.js` (6 tests: both detection paths, config gating, reject branch, no-push guard). CF7 tracker (finding #28) verified high-quality. **Remaining:** `woocommerce` only — left `open` at the user's request (tracker under review). |
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
