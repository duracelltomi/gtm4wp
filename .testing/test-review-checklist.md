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
| **Frontend Core** (`src/Frontend/` — DataLayer, ScriptTag, ContainerCode, ConsentDefaults, VisitorIp; Frontend = N/A) | `Frontend/DataLayerTest`, `ScriptTagTest`, `ContainerCodeTest`, `ConsentDefaultsTest`, `VisitorIpTest` | [x] 2026-07-13 | [x] 2026-07-13 | [x] 2026-07-13 | [x] 2026-07-13 | [x] 2026-07-13 | [x] 2026-07-13 |
| **Module Framework** (`src/Module/`) | `Module/RegistryTest`, `Modules/ModuleConsistencyTest`, `ModuleHooksTest` | [x] | [ ] | [ ] | [-] | [-] | [ ] |
| **PageVariables Module** (`src/Modules/PageVariables/`) | `Modules/PageVariablesModuleTest` | [x] | [ ] | [ ] | [x] 2026-07-13 | [x] 2026-07-13 | [x] 2026-07-13 |
| **Container Module** (`src/Modules/Container/`) | `Modules/ContainerRowsTest`, `ContainerAdminSchemaTest` | [x] | [ ] | [ ] | [ ] | [ ] | [ ] |
| **WooCommerce Module** (`src/Modules/WooCommerce/` — PurchaseTracking, ProductData, PageDataLayer, ListTracking, Helpers, Module) | `Modules/PurchaseTrackingTest`, `ProductDataTest`, `PageDataLayerTest`, `ListTrackingTest`, `HelpersTest` | [x] | [ ] | [ ] | [x] 2026-07-13 | [x] 2026-07-13 | [ ] |
| **ConsentMode Module** (`src/Modules/ConsentMode/`) | `Modules/ModuleHooksTest` (gate + webtoffee only) | [x] | [ ] | [ ] | [-] | [ ] | [ ] |
| **UserEvents Module** (`src/Modules/UserEvents/`) | `Modules/ModuleHooksTest` (gate only) | [x] | [ ] | [ ] | [ ] | [-] | [ ] |
| **MediaEvents Module** (`src/Modules/MediaEvents/`) | `Modules/ModuleHooksTest` (youtube gate only) | [x] 2026-07-13 | [ ] | [ ] | [-] | [ ] | [ ] |
| **ContactForm7 Module** (`src/Modules/ContactForm7/`) | `Modules/ModuleHooksTest` (gate only) | [x] | [ ] | [ ] | [-] | [-] | [ ] |
| **Blacklist Module** (`src/Modules/Blacklist/`) | `Modules/BlacklistModuleTest` | [x] | [ ] | [ ] | [ ] | [ ] | [ ] |
| **ClientDeviceData Module** (`src/Modules/ClientDeviceData/`) | `Modules/ClientDeviceDataModuleTest`, `ModuleHooksTest` (gate) | [x] 2026-07-13 | [x] 2026-07-13 | [ ] | [-] | [ ] | [ ] |
| **AMP Module** (`src/Modules/Amp/`) | `Modules/AmpModuleTest`, `ModuleHooksTest` (gate) | [x] | [x] 2026-07-13 | [x] 2026-07-13 | [x] 2026-07-13 | [x] 2026-07-13 | [x] 2026-07-13 |
| **Admin — Notices/AJAX** (`src/Admin/Notices.php`) | `Admin/NoticesTest` | [x] 2026-07-13 | [ ] | [x] 2026-07-13 | [x] 2026-07-13 | [x] 2026-07-13 | [x] 2026-07-13 |
| **Admin — Settings UI** (`src/Admin/SettingsPage.php`, `Admin.php`, `RestController.php`, `PluginRow.php`) | `Admin/RestControllerTest` | [x] | [ ] | [ ] | [ ] | [ ] | [ ] |
| **Module Admin Schemas** (`src/Modules/*/AdminSchema.php`) | `Modules/ModuleConsistencyTest`, `ContainerAdminSchemaTest` | [x] | [ ] | [ ] | [ ] | [-] | [ ] |
| **Frontend JS** (`js/frontend/` — 9 trackers) | `js/frontend/test/ecommerce-generic.test.js`, `form-move-tracker.test.js` (2 of 9) | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |
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
| **Missing test file** (`src/**/*.php` with no dedicated or documented-indirect test) | 2026-07-13 (Run 1) | 46 src classes. Run 1 added test files for `ListTracking`, `Notices`, `Field`, `Helpers`, `Globals`, `ClientDeviceData`, `Amp` + JS `ecommerce-generic`/`form-move-tracker`, and gate coverage for `MediaEvents`. Real gaps remaining: `Admin/{Admin,PluginRow,SettingsPage}`, `Plugin`, `WooCommerce/WooCommerceModule`, 7 of 9 JS trackers. Blessed N/A (BE-3): interfaces, `Frontend`, `Autoloader`; SettingsPage/Admin/Plugin are wiring-heavy (see gaps T15). |
| **Untested public methods** (in classes that *do* have a test file — TS-10) | 2026-07-13 (Run 1) | Frontend Core cleared (Run 0). `ListTracking`: PA-7 path + shared builder covered; ~15 list-name setters / echo hook callbacks still untested (low value). `Notices`: `dismiss_notice` + `show_notices` covered; `print_dismiss_script` untested. `Amp`: body-injection + is_amp_request covered; header-render delegators untested (thin). |
| **Security-input coverage** (every `.security` PA-3/RI-2 sink has a hostile-input test — TS-1/TC-5) | 2026-07-13 (Run 1) | Frontend dataLayer + additional-pushes covered (Run 0). **Run 1 closed:** `PageVariables` geoCF (finding #12) + `visitorIP`; `WooCommerce/ListTracking` PA-7 (finding #16); `Admin/Notices` dismiss allow-list (finding #18); `Amp` render_amp_gtm_code hex flags (finding #11, proved fails-without-flags). ClientDeviceData config sink = N/A (booleans only, no break-out possible). **Open:** `SettingsPage` bootstrap_data hex sink (admin-context, no regression test — T13). |
| **Regression-per-bug** (every `.security` Known Finding has a live regression test) | 2026-07-13 (Run 1) | Findings #1–#6 (Frontend XSS tests); #8 (`ProductDataTest`); #11 AMP sink (`AmpModuleTest`, Run 1); #12 (`PageVariablesModuleTest`); #13/#14 (`PageDataLayerTest`/`PurchaseTrackingTest`); #16 (`ListTrackingTest`); #18 (`NoticesTest`). **Unverified:** #11 SettingsPage sink (T13); ClientDeviceData portion N/A (booleans). |
| **JS test coverage** (`js/frontend/` trackers, `js/admin/` app) | 2026-07-13 (Run 1) | Harness established under `js/frontend/test/`. **Run 1 added:** `ecommerce-generic` (public 1.x JS API incl. product-JSON reader) + `form-move-tracker` (DOM event → dataLayer). 25 JS tests green. **Remaining (T16):** 7 trackers — `woocommerce`, `client-device-data`, and the media-player wrappers (`youtube`/`vimeo`/`soundcloud`/`html5media`, which need external player-API mocks). |
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
| T13 | Med | open | Finding #11: AMP sink now guarded (`AmpModuleTest`). **Remaining:** `SettingsPage::bootstrap_data` hex sink has no regression test (admin-context, needs the registry+REST+schema harness). ClientDeviceData = N/A (booleans). | `src/Admin/SettingsPage.php` |
| T14 | Low | addressed | `Notices::show_notices` branches — `NoticesTest`. | `tests/unit/Admin/NoticesTest.php` |
| T15 | Low | open | `Admin/{SettingsPage,PluginRow,Admin}` + `Plugin` + `WooCommerce/WooCommerceModule` untested (wiring-heavy; several are BE-3 candidates — record `[-]` N/A with a reason before writing stub-asserting tests). | (various) |
| T16 | Med | open | 7 of 9 `js/frontend/` trackers still untested: `woocommerce`, `client-device-data`, and the media-player wrappers (`youtube`/`vimeo`/`soundcloud`/`html5media`, which need external player-API mocks). | `js/frontend/*.js` |
