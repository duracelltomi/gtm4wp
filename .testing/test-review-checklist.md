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
| **Options** (`src/Options/` — Options, Field) | `Options/OptionsTest` | [x] | [ ] | [ ] | [-] | [ ] | [ ] |
| **Compat Layer** (`compat/*.php`, `src/Compat/Globals.php`) | — | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |
| **Migration** (`src/Migration.php`) | `MigrationTest` | [x] | [ ] | [ ] | [-] | [-] | [ ] |
| **Frontend Core** (`src/Frontend/` — DataLayer, ScriptTag, ContainerCode, ConsentDefaults, VisitorIp; Frontend = N/A) | `Frontend/DataLayerTest`, `ScriptTagTest`, `ContainerCodeTest`, `ConsentDefaultsTest`, `VisitorIpTest` | [x] 2026-07-13 | [x] 2026-07-13 | [x] 2026-07-13 | [x] 2026-07-13 | [x] 2026-07-13 | [x] 2026-07-13 |
| **Module Framework** (`src/Module/`) | `Module/RegistryTest`, `Modules/ModuleConsistencyTest`, `ModuleHooksTest` | [x] | [ ] | [ ] | [-] | [-] | [ ] |
| **PageVariables Module** (`src/Modules/PageVariables/`) | `Modules/PageVariablesModuleTest` | [x] | [ ] | [ ] | [ ] | [ ] | [ ] |
| **Container Module** (`src/Modules/Container/`) | `Modules/ContainerRowsTest`, `ContainerAdminSchemaTest` | [x] | [ ] | [ ] | [ ] | [ ] | [ ] |
| **WooCommerce Module** (`src/Modules/WooCommerce/` — PurchaseTracking, ProductData, PageDataLayer, **ListTracking**, **Helpers**, Module) | `Modules/PurchaseTrackingTest`, `ProductDataTest`, `PageDataLayerTest` | [x] | [ ] | [ ] | [ ] | [ ] | [ ] |
| **ConsentMode Module** (`src/Modules/ConsentMode/`) | `Modules/ModuleHooksTest` (gate + webtoffee only) | [x] | [ ] | [ ] | [-] | [ ] | [ ] |
| **UserEvents Module** (`src/Modules/UserEvents/`) | `Modules/ModuleHooksTest` (gate only) | [x] | [ ] | [ ] | [ ] | [-] | [ ] |
| **MediaEvents Module** (`src/Modules/MediaEvents/`) | — | [ ] | [ ] | [ ] | [-] | [ ] | [ ] |
| **ContactForm7 Module** (`src/Modules/ContactForm7/`) | `Modules/ModuleHooksTest` (gate only) | [x] | [ ] | [ ] | [-] | [-] | [ ] |
| **Blacklist Module** (`src/Modules/Blacklist/`) | `Modules/BlacklistModuleTest` | [x] | [ ] | [ ] | [ ] | [ ] | [ ] |
| **ClientDeviceData Module** (`src/Modules/ClientDeviceData/`) | — | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |
| **AMP Module** (`src/Modules/Amp/`) | `Modules/ModuleHooksTest` (gate only) | [x] | [ ] | [ ] | [ ] | [ ] | [ ] |
| **Admin — Notices/AJAX** (`src/Admin/Notices.php`) | — | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |
| **Admin — Settings UI** (`src/Admin/SettingsPage.php`, `Admin.php`, `RestController.php`, `PluginRow.php`) | `Admin/RestControllerTest` | [x] | [ ] | [ ] | [ ] | [ ] | [ ] |
| **Module Admin Schemas** (`src/Modules/*/AdminSchema.php`) | `Modules/ModuleConsistencyTest`, `ContainerAdminSchemaTest` | [x] | [ ] | [ ] | [ ] | [-] | [ ] |
| **Frontend JS** (`js/frontend/` — 9 trackers) | — | [ ] | [ ] | [ ] | [ ] | [ ] | [ ] |
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
| **Missing test file** (`src/**/*.php` with no dedicated or documented-indirect test) | 2026-07-13 | 47 src classes / 18 test files. Real gaps (excluding interfaces + `ModuleHooksTest`-covered gate-only modules): `Admin/{Admin,Notices,PluginRow,SettingsPage}`, `Compat/Globals`, `Plugin`, `Options/Field`, `WooCommerce/{ListTracking,Helpers,WooCommerceModule}`, `Modules/{ClientDeviceData,MediaEvents}Module`. Blessed N/A (BE-3): interfaces, `Frontend`, `Autoloader`. |
| **Untested public methods** (in classes that *do* have a test file — TS-10) | 2026-07-13 | Cleared for Frontend Core this session (compiled/name-fallback/echo=false/body_open/footer/the_tag/register_hooks/rocket). Not yet swept elsewhere. |
| **Security-input coverage** (every `.security` PA-3/RI-2 sink has a hostile-input test — TS-1/TC-5) | 2026-07-13 | Frontend dataLayer + additional-pushes covered. **Open:** `PageVariables` (`geoCloudflareCountryCode`/`siteSearchFrom`/`visitorIP` — regression tests added during the security review, re-verify), `WooCommerce/ListTracking` (PA-7 `preg_replace` replacement injection — untested), `Admin/Notices` dismiss input. |
| **Regression-per-bug** (every `.security` Known Finding has a live regression test) | 2026-07-13 | Findings #1–#6 guarded by the two `tests/unit/Frontend` XSS tests; #8 by `ProductDataTest`; #13/#14 by `PageDataLayerTest`/`PurchaseTrackingTest`. **Unverified:** #11 (4-sink flag drift — SettingsPage/ClientDeviceData/Amp), #16 (ListTracking `addcslashes`), #18 (Notices capability re-check). |
| **JS test coverage** (`js/frontend/` trackers, `js/admin/` app) | 2026-07-13 | Near-zero: 9 frontend trackers with **0** dedicated tests; `js/admin/` has 1 (`utils.test.js`). Largest hole in the suite. |
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

### Open gaps (from the seed sweeps — to be worked by `/test-review` runs)

Terse, non-exploit; prioritized by the sweeps above.

| # | Sev | Status | Summary | File(s) |
|---|-----|--------|---------|---------|
| T7 | High | open | `WooCommerce/ListTracking` has no test — includes the PA-7 `preg_replace` replacement-injection path (`addcslashes` guard, finding #16). Whole-class gap on a data-bearing output builder. | `src/Modules/WooCommerce/ListTracking.php` |
| T8 | Med | open | `Admin/Notices` dismiss handler (nonce + capability, finding #18) has no test — a state-changing AJAX path. | `src/Admin/Notices.php` |
| T9 | Med | open | `Compat/Globals` (read-only 1.x mirror population) untested — third-party BC surface. | `src/Compat/Globals.php` |
| T10 | Med | open | `Frontend JS` trackers (9 files) have zero unit tests — the suite's largest hole. | `js/frontend/*.js` |
| T11 | Low | open | `Options/Field`, `WooCommerce/Helpers`, `ClientDeviceData`/`MediaEvents` modules, `Admin/{SettingsPage,PluginRow,Admin}` untested (whole-class). | (various) |
| T12 | Low | open | `ClientDeviceData`/`MediaEvents` module hook gating not covered by `ModuleHooksTest` (unlike Consent/CF7/UserEvents/AMP). | `src/Modules/{ClientDeviceData,MediaEvents}/` |
