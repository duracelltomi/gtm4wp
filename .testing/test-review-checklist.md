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
**Reconcile the tree on disk against the matrix first** (pre-review step 1), then
prioritize `[ ]` (unreviewed) cells and the Test Debt Sweeps. After the review,
mark reviewed cells `[x]` with the date, record the reviewed sha, update the
sweeps, and append gaps to the Known Test-Gaps Log.

> ⚠️ **A component with no row is invisible, not unreviewed.** The matrix can only
> signal a gap for a component it already lists — a new module is not `[ ]`, it is
> *absent*, and prioritization walks straight past it. Not hypothetical: the
> **VisitorData** module and `gtm4wp-visitor-data.js` landed 2026-07-16 with no row
> in this file *or* `.security/code-review-checklist.md`, and were backfilled by
> hand on 2026-07-17. Run the inventory step every time; a complete-looking matrix
> is the failure mode.

**Status markers:**
- `[ ]` — not yet reviewed by the test-review system
- `[x] YYYY-MM-DD` — reviewed and judged satisfied on date
- `[~] YYYY-MM-DD` — reviewed but stale (source or test changed since)
- `[-]` — not applicable (e.g. no security sink → Sec-input is N/A; an intentionally-untested orchestrator — see patterns BE-3)

**Staleness rule:** A cell becomes `[~]` if the component's source **or** its test
changed after the review. Check with `git log <sha>..HEAD -- <src> <test>`, using the
**`Reviewed at:` sha** recorded on the last run. Use the sha, not
`--since="YYYY-MM-DD"`: a date is imprecise in both directions when commits and the
review land on the same day. (Mirrors the same rule in `.security/code-review-checklist.md`.)

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
| **Plugin Bootstrap** (main file, `uninstall.php`, `Plugin.php`, `Autoloader.php`, **`src/RestCors.php`**) | `RestCorsTest` (5, incl. wrapper + non-request guard + priority-11 + namespace consistency); `Admin/AdminCapabilityFilterTest` (the `Plugin::boot()` gate, T30); rest BE-3 N/A except `uninstall.php` (H-4) | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 |
| **Options** (`src/Options/` — Options, Field incl. `$doc` + `choice_sections`) | `Options/OptionsTest` (18), `Options/FieldTest` | [x] | [x] 2026-08-13 (T48a: `to_ui_array()` `'sections'` pass-through unpinned) | [x] 2026-08-13 | [-] | [x] 2026-08-13 | [x] 2026-08-13 |
| **Compat Layer** (`compat/*.php`, `src/Compat/Globals.php`) | `Compat/GlobalsTest` | [x] 2026-07-13 | [x] 2026-07-13 | [x] 2026-07-13 | [-] 2026-08-05 | [-] 2026-08-05 | [x] 2026-07-13 |
| **Migration** (`src/Migration.php`) | `MigrationTest` (11) | [x] | [x] 2026-08-05 | [x] 2026-08-05 | [-] | [-] | [x] 2026-08-05 |
| **Frontend Core** (`src/Frontend/` — DataLayer, ScriptTag (**`json_literal()` public static, 10 callers**), ContainerCode, ConsentDefaults, VisitorIp; Frontend = N/A) | `Frontend/DataLayerTest`, `ScriptTagTest`, `ContainerCodeTest`, `ConsentDefaultsTest`, `VisitorIpTest` | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 (#114/#123/#141 chains verified; T48 closed same session) | [x] 2026-08-13 | [x] 2026-08-13 |
| **Module Framework** (`src/Module/` — incl. **`DocumentedSchemaInterface`**, BE-3 N/A interface exercised via the doc sweep) | `Module/RegistryTest` (5, 6/6 public methods), `Modules/ModuleConsistencyTest` (+ **whole-schema doc sweep**: every field declares a documentation page, path-form, fragment-free — pins unwritten fields), `ModuleHooksTest` | [x] | [x] 2026-08-13 | [x] 2026-08-13 | [-] | [-] | [x] 2026-08-13 |
| **PageVariables Module** (`src/Modules/PageVariables/`) | `Modules/PageVariablesModuleTest`, **`PageVariablesAdminSchemaTest`** | [x] | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 |
| **Container Module** (`src/Modules/Container/` — incl. **`HardcodedContainers`**) | `Modules/ContainerRowsTest`, `ContainerAdminSchemaTest`, **`HardcodedContainersTest`** | [x] | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 |
| **WooCommerce Module** (`src/Modules/WooCommerce/` — PurchaseTracking, ProductData, PageDataLayer, ListTracking, Helpers, StoreApiData, Module, AdminSchema, **`CountryPhoneData` (generated — hand edits are themselves a finding)**) | `Modules/PurchaseTrackingTest`, `ProductDataTest`, `PageDataLayerTest`, `ListTrackingTest`, `HelpersTest` (+ **`phone-corpus.php`**, 972 generated oracle cases), `StoreApiDataTest`, `WooCommerceModuleTest`, `WooCommerceAdminSchemaTest`; stubs `wc-users-stub`, `wc-shortcode-checkout-stub`, `wc-feature-guard-shim` | [x] | [x] 2026-08-14 | [x] 2026-08-14 | [x] 2026-08-14 | [x] 2026-08-14 (T51 closed same session — the flag's PageDataLayer legs now pinned, both probes red) | [x] 2026-08-14 |
| **ConsentMode Module** (`src/Modules/ConsentMode/` — incl. Axeptio handler, CookieYes bridge) | `Modules/ConsentModeAxeptioTest`, `ConsentModeCookieYesTest`, `ModuleHooksTest` (gate + webtoffee + axeptio + cookieyes wiring) | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 (T40 closed same session) | [x] 2026-08-13 | [x] 2026-08-13 |
| **UserEvents Module** (`src/Modules/UserEvents/` — incl. form-fill `gtm.element*` keys + "filled in only" sub-option) | `Modules/UserEventsModuleTest` (exact printed literal incl. the load-bearing `var`, boolean cast, 1.x-upgrade default), `ModuleHooksTest`; JS: `form-move-tracker` + **`form-move-tracker-filled-only`** realms | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 (T50 closed same session) | [x] 2026-08-13 | [-] | [x] 2026-08-13 |
| **MediaEvents Module** (`src/Modules/MediaEvents/` — 12 trackers + consent gate + shared observer lib) | `Modules/MediaEventsModuleTest` (35 tests: gates, consent gate/expectation matrix, oEmbed splice sinks), **`MediaEventsAdminSchemaTest`** (phase map), `ModuleHooksTest`, `ModuleConsistencyTest`; JS: 14 tracker tests + `native-video-params` (**`media-gate.test.js`** covers the gate file itself, T41) | [x] 2026-08-14 | [x] 2026-08-14 (per-tracker flag rewrite exemplary: exact handle arrays + `->once()` memoization pin) | [x] 2026-08-14 | [x] 2026-08-14 (was `[-]`; #112 origin splice + Dailymotion hex/path sinks now exist and are guarded) | [x] 2026-08-14 | [x] 2026-08-14 |
| **ContactForm7 Module** (`src/Modules/ContactForm7/` — incl. tracker JS; GA4 `form_status` + submit-before-lead ordering, 2026-08) | `Modules/ContactForm7ModuleTest`, `ModuleHooksTest` (gate); JS: `contact-form-7-tracker` (order-as-effect test) | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 (T50 closed same session) | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 |
| **Blacklist Module** (`src/Modules/Blacklist/` — incl. `sandboxedScripts` group class) | `Modules/BlacklistModuleTest` (#121/#122 absence-under-every-name guards), **`BlacklistAdminSchemaTest`** (structure + behavioral sanitizer cases, T45) | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 (T45 closed same session) | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 |
| **ClientDeviceData Module** (`src/Modules/ClientDeviceData/`; JS tracker) | `Modules/ClientDeviceDataModuleTest`, `ModuleHooksTest` (gate); JS: `client-device-data-tracker` | [x] 2026-07-13 | [x] 2026-07-14 | [x] 2026-07-14 | [-] | [x] 2026-07-14 | [x] 2026-07-14 |
| **VisitorData Module** (`src/Modules/VisitorData/` — `VisitorDataEndpoint` **public** session route, `VisitorField`, `AdminSchema`; JS: `gtm4wp-visitor-data.js`) | `Modules/VisitorDataEndpointTest`, `VisitorDataModuleTest` (event-name map: constants + literal contract pinned separately), `ModuleHooksTest`; JS: `visitor-data-tracker` | [x] | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 |
| **AMP Module** (`src/Modules/Amp/` — amp-wp 2.x `amp_analytics_entries` sink) | `Modules/AmpModuleTest`, `ModuleHooksTest` (gate) | [x] | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 |
| **Admin — Notices/AJAX** (`src/Admin/Notices.php`) | `Admin/NoticesTest` (#110 encoder-discriminating nonce guard; #131 core-faithful `sanitize_key` pair; invalid-dataLayer-name notice grant+deny) | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 |
| **Admin — Settings UI** (`src/Admin/SettingsPage.php`, `Admin.php`, `RestController.php`, `PluginRow.php`, **`Docs.php` (2026-08-11)**) | `Admin/RestControllerTest`, `SettingsPageTest`, `PluginRowTest`, `AdminCapabilityFilterTest`, **`DocsTest`** (209 lines: allow-list `->with`, #152/#158 both-directions) + fixtures `UndocumentedThirdParty{Module,Schema}` | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 |
| **Module Admin Schemas** (`src/Modules/*/AdminSchema.php` — custom sanitizers; doc-link additions across all schemas 2026-08) | `Modules/ModuleConsistencyTest` (non-scalar sweep + doc sweep), `ContainerAdminSchemaTest`, `WooCommerceAdminSchemaTest`, `PageVariablesAdminSchemaTest`, **`MediaEventsAdminSchemaTest`**, **`BlacklistAdminSchemaTest`** (sanitizers behaviorally tested since T45) | [x] | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 (T45 closed same session) | [-] | [x] 2026-08-13 |
| **Frontend JS** (`js/frontend/` — **20** bundles (+`gtm4wp-media-gate.js`, 2026-08-10) + **3** libs: `lib/native-video-params`, `lib/blocks-cart-diff`, `lib/gtm4wp-cookies`) | **24** `js/frontend/test/*.test.js` (+`inline-head-globals`, `form-move-tracker-filled-only`); **`media-gate.test.js` + `form-move-tracker-config-false.test.js` new (T41/T50 closes)** — 26 test files, every bundle covered | [x] 2026-08-14 | [x] 2026-08-14 | [x] 2026-08-14 | [x] 2026-08-14 (#462 span paths + #190 guards watched-red-first; stub swapped to model the real helper's false return) | [x] 2026-08-14 | [x] 2026-08-14 |
| **Admin JS** (`js/admin/` — **11 files**: `App.js`, `utils.js`, `index.js` + **8 in `components/`** incl. `MobileNav.js` + `DocLink.js`, both 2026-08) | **9** test files (+`mobile-nav`); `index.js` `[-]` N/A (3-line mount); `DocLink.js` `[-]` N/A documented-indirect — asserted by effect in `module-panel.test.js:344-` incl. its one security prop (`rel="noopener noreferrer"`) | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 | [-] | [x] 2026-08-13 |

> **Coverage note (Run 8, 2026-08-14):** post-beta3 pass over `52f3c4f..65c9670` — 8
> commits, 4 with production code, **every one shipping its tests in the same commit**
> (three with recorded watched-red-first probes). **Reviewed at: `65c9670`.** Baseline
> **PHP 2002/4686** (declaration order + 3 random seeds, identical counts) and **JS 35
> suites / 726**, all green. Report-only pass; **no tests written this run.** One Medium
> gap (T51), **probe-verified twice in the main thread** — both PageDataLayer legs of the
> request-scoped purchase-pushed flag revert green individually (tree restored byte-exact
> after each probe). The `gtm4wp-visitor-data.js` delta in range is comment-only (N/A).
> Rows updated: WooCommerce Module, MediaEvents Module, Frontend JS. Theme: third
> consecutive run where a correct, well-tested guard's *surroundings* are the find —
> Run 7's attachment/call-site lesson recurring as "the producer of a hand-set fixture
> state" (TS-15 corollary added). One code observation (the flag name as a bare literal
> at 7 sites / 3 files) routed to `/code-review`.
>
> **Closes note (Run 8, 2026-08-14):** T51 closed the same session on the user's
> go-ahead. **PHP 2002 → 2006 tests / 4686 → 4694 assertions**, green in declaration
> order **and 4 randomized seeds** at identical counts; `phpcs` exit 0 repo-wide; no
> JS change; **no production code changed** (tests + tracking only → CHANGELOG exempt).
> TS-15 as the acceptance criterion: both report-stage revert probes re-run against the
> new tests and now go red — the 732 raise deletion fails 3 (including the cross-class
> chain test failing on the re-seeded order id, the literal bug shape), the 960 guard
> deletion fails 1.
>
> **Coverage note (Run 7, 2026-08-13):** full cumulative pass over `f142f4e..52f3c4f` —
> **81 commits**, the largest range this system has covered, spanning `.security` reviews
> 15–24 (findings #114–#189). **Reviewed at: `52f3c4f`.** Baseline **PHP 1965/4601**
> (declaration order + 3 random seeds, identical counts — TS-16 holds) and **JS 33
> suites / 713**, all green. Report-only pass; **no tests written this run.** 5 parallel
> read-only deep-reads; **all 6 Medium gaps (T39–T44) were probe-verified in the main
> thread by reverting the guard and watching the suite stay green** — probes serialized,
> tree restored byte-exact after each. No High gap, no latent production bug; 2
> low-severity production observations routed to `/code-review` (detail in the
> git-ignored report). The run's theme: **the attachment/call-site is the untested half
> of a fully-tested guard** (a permission callback whose *registration* swaps to
> `__return_true` green; a `json_literal` guard revertible at its two filter-fed call
> sites) — plus one recurrence of "the test supplies the collaborator's only behavior"
> (the new consent-gate bundle, emptied, leaves all 713 JS tests green). Matrix labels
> reconciled: 20 frontend bundles / 24 test files, 11 admin JS files / 9 test files,
> `CountryPhoneData` + `Docs.php` + `DocumentedSchemaInterface` + the two new schema
> tests added to their rows. PageVariables/ClientDeviceData/Amp module rows keep their
> earlier dates: their only in-range deltas are AdminSchema doc-link/phase additions,
> reviewed under the Module Admin Schemas row's doc sweep, not a module deep-read.
>
> **Closes note (Run 6, 2026-08-05):** all four gaps closed the same session. **PHP
> 763 → 767 / 2463 → 2488 assertions; JS 23 → 30 suites, 309 → 404 tests**; `phpcs`
> exit 0 repo-wide; `lint:js` clean; **`npm run build` byte-identical** (the jest
> config keeps its JSX transform inline so `hasBabelConfig()` stays false and the
> production build is untouched); no production code changed. New devDependencies:
> `@testing-library/react`, `@testing-library/jest-dom`, `@testing-library/user-event`,
> `react`/`react-dom` pinned to **^18** to match what WordPress ships. The
> `@wordpress/*` packages are deliberately **not** installed — see **TC-15**.
> `/test-review`'s own inventory one-liner was fixed to recurse (`find js -name '*.js'`),
> which is what let T36 hide, and an order-independence step was added to its
> mechanical layer.
>
> **Coverage note (Run 6, 2026-08-05):** **empty-diff escalation run** — `b2bd170..f142f4e`
> is one commit (Run 5's own closures) with **zero production lines changed**, which is the
> trigger to go deep. **Reviewed at: `f142f4e`.** Baseline unchanged: PHP **763/2463**, JS
> **23 suites / 309**. Every remaining `[ ]` cell was cleared except **Admin JS**, which is
> the run's main find: the row said `js/admin/` and hid a **`components/` subdirectory** — the
> group is **9 files / 1375 lines** and only `utils.js` (246) has a test, so **1129 lines are
> untested** (H-2), including the settings-import flow and a component that fetches an
> external third-party JSON endpoint. The command's own inventory one-liner (`ls js/admin/*.js`)
> cannot see a subdirectory; **the inventory step needs to recurse.** Second find: a new
> mechanical check — `--order-by=random` — shows the suite is **order-dependent** (13 errors,
> three of them security regression guards; H-1). No High gap, **no latent production bug**,
> nothing routed to `/code-review`. The run's largest probe came back **clean**: reverting
> `Field::to_string()` at all 14 AdminSchema call sites went red immediately.
>
> **Coverage note (Run 5, 2026-08-05):** first full pass since Run 4 — `ab7fa99..b2bd170`,
> **83 commits**, +7 525 production lines and +8 580 test lines, spanning security reports
> 5→14 (findings #31–#113). **Reviewed at: `b2bd170`.** Baseline PHP **706/2325**, JS **23
> suites / 309** green. The inventory step earned its keep again: **four** components were
> invisible to the matrix, not `[ ]` — `src/RestCors.php` (an A0 CORS gate), `HardcodedContainers`,
> `js/frontend/lib/gtm4wp-cookies.js` and `PageVariablesAdminSchemaTest`. All four already had
> tests; only the ledger was behind. Row counts corrected (19 bundles + **3** libs, **22**
> frontend JS test files). Two High gaps found, both "a security fix whose test does not exist,
> inside a component the matrix counts as covered" (T28, T29), and two Medium access-control
> gaps (T30, T31) — T28 and T31 **probe-verified by reverting the guard and watching the suite
> stay green**. No latent production bug surfaced; nothing routed to `/code-review`.
>
> **Process note (2026-07-17, no review run):** system hardening only — no tests
> were reviewed. Mirrored the `.security/` changes: the **inventory step** (pre-review
> step 1) and **sha-based staleness**. Backfilled the **VisitorData** row (landed
> 2026-07-16 with no row; PHP + JS tests *do* exist, so `Exists [x]` — but no cell has
> been judged, hence `[ ]` across the board), and corrected **Frontend JS** to 19
> bundles / 21 test files, marking its cells `[~]`: 17 files in that group changed
> after its 2026-07-15 review (`ab7fa99..HEAD` — the visitor-data tracker, the
> MutationObserver media work, `#398` Phase 3). Only the rows touched here were
> reconciled — the **next `/test-review` owns the full step-4 staleness pass**, and
> should start with the VisitorData row: `VisitorDataEndpointTest` guards a route
> that is public (A0) and reads request headers, so it needs both a grant/deny
> access-control case and hostile-input coverage (TS-12, TC-5).
>
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
| **Test-order independence** (`vendor/bin/phpunit --order-by=random` — TS-8/TS-16) | 2026-08-14 (Run 8) | ✅ **Clean — declaration order + 3 random seeds all green at 2002/4686**, identical assertion counts. The four fix commits in range each recorded their own randomized runs too. |
| ~~Test-order independence (Run 7 + closes)~~ | 2026-08-13 | ✅ **Clean — declaration order + 4 random seeds all green at 1996/4669 after the closes** (1965/4601 at report stage, 3 seeds), identical assertion counts. The suite grew 767 → 1965 since Run 6 (security fix sessions ship their tests, TC-1) and stayed order-independent throughout — the security reviews' own randomized runs (R21–R24 close-outs) corroborate. |
| ~~Test-order independence (Run 6 + closes)~~ | 2026-08-05 | ✅ Clean — 5 consecutive random seeds green at 767/2488, the same assertion count as declaration order. Was 13 errors when the sweep was first run this session (T35, now closed): 12 on `wp_get_environment_type`, 1 on `get_multiple_authors`, three of them security regression guards, all passing only because another file had defined the function process-wide. Run this **3–5 times** every review — one green seed proves nothing. |
| ~~Test-order independence (Run 6, first run)~~ | 2026-08-05 (Run 6) | ⚠️ **13 errors** (seed 1785923095). 12 on `wp_get_environment_type`, 1 on `get_multiple_authors`: the tests never stub them and pass only because another file defines them process-wide (Brain Monkey makes `function_exists()` sticky). **Three of the 13 are security regression guards** (settings-import hostile payload, hostile container ID, the T13/#11 admin hex sink). Each file still passes *alone*, so it is within-file test ordering. Nothing silently passes — they error — but the suite's green is contingent on declaration order and randomized order cannot be adopted. Gap **H-1**. |
| **Missing test file** (`src/**/*.php` with no dedicated or documented-indirect test) | 2026-08-14 (Run 8) | **Clean.** 24 raw hits over **58** files — the identical, previously-classified set (BE-3 N/A / basename over-reports / documented-indirect); **no new src or JS file in range**. |
| ~~Missing test file (Run 7)~~ | 2026-08-13 | **PHP clean — no real zero-test class.** 24 raw hits over **58** files; the two new hits classify: `DocumentedSchemaInterface` BE-3 N/A; `CountryPhoneData` **documented-indirect** (all 245 territories exercised by the 972-case phone corpus, generator fatal-validation, monthly CI drift diff — a dedicated shape test would duplicate those). `Admin/Docs.php` has a dedicated 209-line test. **JS: the new 20th bundle `gtm4wp-media-gate.js` was the one uncovered file → T41 (probe-verified: emptying it left 713 JS tests green), closed the same session by `media-gate.test.js` — the probe now fails 2 of its 3 cases.** |
| ~~Missing test file (Run 6 + closes)~~ | 2026-08-05 | **Clean — no real zero-test PHP class.** `uninstall.php` (outside `src/`, so the sweep never saw it) gained `UninstallTest` this session (T38). 22 raw hits over **55** files (Run 5: 23/56; the delta is `UserEventsModule.php` gaining T29's test). All 22 classify as BE-3 N/A (`Plugin`, `Autoloader`, `Admin/Admin`, `Frontend/Frontend`, the 3 `Module/` contracts) or basename over-reports (10 `AdminSchema.php`, `ConsentMode/*`, `Container/ContainerModule`, `VisitorData/VisitorField`). **The sweep is PHP-only and missed `js/admin/components/` entirely** (H-2) — the JS side needs its own recursive inventory. |
| ~~Missing test file (Run 5)~~ | 2026-08-05 (Run 5 + closes) | 23 raw hits over 56 PHP files, one real gap — `UserEvents/UserEventsModule.php` (T29), **closed the same session** by `UserEventsModuleTest`. **Zero real zero-test classes remain.** Everything else classified: BE-3 N/A (`Plugin`, `Autoloader`, `Admin/Admin`, `Frontend/Frontend`, the 3 `Module/` contracts) or indirectly covered (all 10 `AdminSchema.php` via `ModuleConsistencyTest` + 3 dedicated schema tests; `ConsentMode/*`, `Container/ContainerModule`, `VisitorData/VisitorField` — basename mismatches the sweep over-reports). |
| ~~Missing test file (Run 4)~~ | 2026-07-15 (Run 4) | No real zero-test class in the WooCommerce/consent batch — every new class ships a test (`StoreApiDataTest`, `WooCommerceModuleTest`, `WooCommerceAdminSchemaTest`, `ProductDataTest`, `PageDataLayerTest`, `ConsentModeCookieYesTest`). Sweep over-reports on basename mismatches (`WooCommerceAdminSchemaTest`≠`AdminSchema`, `ConsentModeCookieYesTest`≠`ConsentModeModule`). Remaining PHP BE-3 N/A unchanged (`Plugin`, `Frontend`, `Autoloader`, `Admin/Admin`, module orchestrators, interfaces). |
| **Untested public methods** (in classes that *do* have a test file — TS-10) | 2026-08-14 (Run 8) | **Clean — no new public method in range.** `MediaEventsModule::sdk_blocked()` is private; its memoization is pinned by the `->once()` filter expectation in the rewritten flag tests. |
| ~~Untested public methods (Run 7)~~ | 2026-08-13 | **One real hit, closed same session:** `PageDataLayer::register_confirm_purchase_route()` was executed by no test (T39, probe-verified both ways — the `__return_true` swap now fails the new registration test). `Helpers::visitor_has_wc_state()` fully covered (the cart-hash cookie leg closed with T46). Everything else surveyed by the 5 deep-reads resolves to a test or a logged Low. |
| ~~Untested public methods (Run 6, re-run)~~ | 2026-08-05 | **Clean — no new hits.** Re-swept the components whose cells were still `[ ]`: `Registry` 6/6 public methods covered by `RegistryTest`; `Migration::maybe_run()` plus all three private steps covered by 11 `MigrationTest` cases (idempotence, version gate, no-overwrite, missing options, post-meta seeding both ways); `RestCors` covers the predicate (data provider), the wrapper, the non-`WP_REST_Request` guard, `register()`'s priority-11 and a namespace-consistency test. Run 5's `[-]` N/A for `AdminSchema::unavailable_message` ×10 stands. |
| ~~Untested public methods (Run 5)~~ | 2026-08-05 (Run 5 + closes) | Swept globally (per-file over-reports). **All real hits closed:** `ScriptTag::print_markup_block` (T32, 6 tests incl. the scoped-restore property its sibling does not have), `StoreApiData::{product_schema,cart_item_schema}` + the 13 `ListTracking` loop hooks (T34). **Withdrawn on verification:** `ProductData::is_new_customer` (asserted via `PurchaseTrackingTest`), `VisitorDataModule::build_config` (5 config-shape tests), `RestController::get_settings` (thin wrapper over the tested `ui_values()`). `AdminSchema::unavailable_message` ×10 recorded **`[-]` N/A** — a translated literal, TS-4 if tested. |
| ~~Untested public methods (Run 4)~~ | 2026-07-15 (Run 4 + closes) | **Closed:** `StoreApiData::register()`; `ListTracking::{quick_view_before_single_product, cart_item_product_filter, cart_item_remove_link_filter, single_add_to_cart_tracking, grouped_product_list_column_label, after_template_part}` (T22); classic JS `gtm4wp_woocommerce_handle_cart_qty_change` + remove-link handler (T25). Prior remaining low-value (`ListTracking` list-name setters; `Notices::print_dismiss_script`) unchanged. |
| **Security-input coverage** (every `.security` PA-3/RI-2 sink has a hostile-input test — TS-1/TC-5) | 2026-08-14 (Run 8) | **Clean — no new superglobal/header read in range.** The one new HTML-attribute sink (the #462 PDP span, `esc_attr( wp_json_encode() )`) keeps its escaper revert-provable via the structural `&quot;item_name&quot;` assertion (JSON always carries `"`, so the guard is discriminated without a hostile fixture); the #190 JS guards shipped watched-red-first. |
| ~~Security-input coverage (Run 7)~~ | 2026-08-13 | **Hostile-input clean; the two T40 robustness-guard legs closed same session.** No new superglobal/header read in range lacks a hostile-input test (the only new `$_COOKIE` touches are presence-only checks — value never read, TC-5 not triggered). The new `<script>` sinks all guard both-directions (visitor-cart fragment whole-attribute equality; Dailymotion hex/path; #112 modeled `esc_url`; #110 encoder-discriminating nonce). The T40 items were #141-class robustness, not XSS: the checkout-products and Axeptio `json_literal` call sites reverted green (probe-verified) because the encoder-failure guard was pinned only at the helper — both filter-fed sites now carry their own NAN case and the same reverts go red. |
| ~~Security-input coverage (Run 6, re-run)~~ | 2026-08-05 | **Clean.** Run 5's "every superglobal read in `src/` has a hostile-input test" still holds (no production change). New this run: audited the **settings-import** input path, which is the one entry point that reaches `Field::sanitize()` **without** the REST layer's per-field type coercion. All **10** custom sanitizers correctly cast through `Field::to_string()`, and the contract is guarded by `ModuleConsistencyTest::test_every_field_sanitizer_handles_non_scalar_input_without_warning` — a whole-schema sweep that pins fields not yet written. **Probe-verified:** a bare `(string)` cast at all 14 call sites fails the suite. One weak-assertion note: the sweep's result check permits the literal `'Array'` (H-3). |
| ~~Security-input coverage (Run 5)~~ | 2026-08-05 (Run 5 + closes) | Enumerated **all 16** superglobal reads in `src/`. The two `UserEvents` event cookies were the only untested ones and are now covered by a 6-row non-integer-value provider (T29), so **every superglobal read in `src/` now has a hostile-input test**. New sinks all clean both-directions: the visitor-cart data-attribute fragment, the A0 visitor-data route's header round-trip, the settings import payload, `HTTP_CF_IPCOUNTRY`/`HTTP_REFERER` raw-passthrough (TS-11), the `?key=` ownership check (PA-10). |
| ~~Security-input coverage (Run 4)~~ | 2026-07-15 (Run 4) | **Batch clean — no open hostile-input sink gap.** Verified both-directions: list-attribution cookie #405 (the one new untrusted request surface — `ProductDataTest`+`PageDataLayerTest`); product-title + order-number `<script>` sinks; raw order billing/shipping passthrough (#8 contract); PA-7 replacement injectors (#16); CookieYes datalayer-name `esc_js` (`ConsentModeCookieYesTest`). Store-API block sink is a delegated REST sink (FP-4) — the only *open* item is a raw-passthrough **contract** test (T21, data-integrity not XSS) + `item_category` benign-only (T23). |
| **Regression-per-bug** (every `.security` Known Finding has a live regression test) | 2026-08-14 (Run 8) | **Clean.** #190 (the only finding since #189) is `fixed` with live guards in `woocommerce-tracker.test.js`, both watched red on the unfixed source by their own fix session. The adjacent find is T51 — not a missing finding guard, but the fix-adjacent producer of the `6d9e7a7` flag left unpinned (the Run-7 theme again). |
| ~~Regression-per-bug (Run 7)~~ | 2026-08-13 | **Clean.** Walked **#114–#189** (reports 15–24): every `fixed` finding with a code sink carries a live guard, most recorded probe-verified-red by their own fix sessions and re-read here (the standouts are cited in the Run 7 report's Verified-solid section). `wontfix`/process/ledger findings (#118, #128, #159, #163, #161-residual, bookkeeping rows) have no code sink — out of scope. The adjacent-surface gaps this walk exposed (T39/T40/T42 — untested *surroundings* of correctly-guarded fixes, not missing finding guards) were all closed the same session. |
| ~~Regression-per-bug (Run 6, re-run)~~ | 2026-08-05 | **Clean — no new findings in range** (zero production commits since Run 5). Run 5's walk of #31–#113 stands, including T28's close for #33. Caveat surfaced this run: three of those guards (#11's admin hex sink, and the two settings-import sanitization tests) only *execute* in the default declaration order — see the test-order sweep (H-1). The guards are correct; their scheduling is not. |
| ~~Regression-per-bug (Run 5)~~ | 2026-08-05 (Run 5 + closes) | Walked **#31–#113** (reports 5–14). One unguarded fix — **#33 (High)** — found and **closed the same session** (T28); re-probed after: deleting the fix now fails the new test. **No `.security` finding is left without a live guard.** Everything else holds, several exemplary: #40 and #112 model the real collaborator (SDK `replaceChild` + a cap; the real `esc_url` allow-list), #106 models the sanitizer pair an identity stub would render vacuous, #91 asserts the helper is `->never()` called, #63 deletes `global.fetch`. #63 was logged as a candidate and **withdrawn on verification**. Process/ledger findings (#73, #87, #98, #101, #103, #104, #107, #109, #111, #113) are out of scope — no code sink. |
| ~~Regression-per-bug (Run 4)~~ | 2026-07-15 (Run 4) | `.security` Review 5 landed one Low (#31 block cross-sell `internal_id` strip) with a both-directions regression in `woocommerce-blocks-tracker.test.js` — verified live. Prior #1–#6/#8/#11–#18/#28–#30 unchanged. **No unverified findings remain.** |
| **JS test coverage** (`js/frontend/` trackers, `js/admin/` app) | 2026-08-14 (Run 8) | ✅ **35 suites / 726 tests green** (+4 in range: two #462 span cases, two #190 guards — all in `woocommerce-tracker.test.js`). No new bundle; every bundle, lib and admin file covered or `[-]` N/A. |
| ~~JS test coverage (Run 7 + closes)~~ | 2026-08-13 | ✅ **35 suites / 722 tests green** (was 30/404 at Run 6; 33/713 at this run's report stage). Every one of the 20 bundles, 3 libs and 11 admin files is covered or `[-]` N/A. Run-7 report found two JS gaps, **both closed the same session**: T41 (`gtm4wp-media-gate.js` executed by no test → `media-gate.test.js`, own-property + integration + shut-gate cases) and T43 (VideoPress double-load → the re-rendered-page re-attach case, rewritten once under the probe before being trusted). Also new: `form-move-tracker-config-false.test.js` (the shipped default config shape, T50). `DocLink.js` recorded `[-]` N/A documented-indirect. |
| ~~JS test coverage (Run 6 + closes)~~ | 2026-08-05 | ✅ **30 suites / 404 tests green** (was 23/309). **T36 closed:** all 7 previously-untested `js/admin/` files now covered by 95 new tests — the lock guards in `TableControl`, the four third-party-response branches plus the stale-request guard in `AxeptioVersionControl`, the four import outcomes in `ImportExport`, the type routing in `FieldControl`, the group/tab arithmetic in `ModulePanel`, the search/dirty markers in `Sidebar`, and the dirty/save/import baseline bookkeeping in `App`. Harness recorded as **TC-15**. `index.js` `[-]` N/A. **No open JS gaps.** |
| ~~JS test coverage (Run 6, first run)~~ | 2026-08-05 (Run 6) | **`js/frontend/` clean** — 23 suites / 309 green, all 19 bundles + 3 libs covered, no open gap. **`js/admin/` is the open half and was never actually swept:** the row tracked `js/admin/` as one unit, so the **`components/` subdirectory was invisible**. Real state: **9 files / 1375 lines, only `utils.js` (246) tested → 1129 untested** (`App.js` + 6 components). Highest value: `AxeptioVersionControl.js` (external third-party fetch, 4 outcome branches + a cancellation race) and `ImportExport.js` (4 import outcomes). **Blocked on tooling** — `@testing-library/react` is not installed and `@wordpress/scripts` v30 does not provide it. Gap **H-2**. |
| ~~JS test coverage (Run 5)~~ | 2026-08-05 (Run 5 + closes) | **23 suites / 309 tests green.** Every one of the 19 bundles and all 3 libs has a test file — no untested bundle remains. New in range and covered: `visitor-data-tracker` (1 034 lines), `cookies`, plus growth in `native-video-params`, `spotify`, `vimeo`, `twitch`, `ecommerce-generic`, `admin/utils`. Double-init guards (#71/#83) present **and** tested in every bundle that has one. **T33 closed:** the listener capture/detach moved to file-level hooks, so no describe leaks into the next; the shape-based assertions became exact counts and the #71 double-init test now asserts the push half too. Probe: neutralizing the detach fails 4 tests that used to pass. **No open JS gaps.** |
| ~~JS test coverage (Run 4)~~ | 2026-07-15 (Run 4 + closes) | **JS suite 214 → 231 green (21 suites)** after closing T25 (classic cart qty-change + remove-link + variable/grouped/disabled add_to_cart + chunking + parse catches) and T26 (delegated-listener isolation harness, probe-verified). Also confirmed Run-4 baseline: `woocommerce` (last open tracker gap, now closed), `woocommerce-blocks`, `blocks-cart-diff`, `ecommerce-generic` all covered; pure cart-diff lib + block tracker core well-covered (real `subscribe()` drive, TC-10). **No open JS gaps.** |
| **Access-control coverage** (every `permission_callback` / capability gate has a grant+deny test; every filterable capability — `gtm4wp_admin_page_capability` — has a test that the filter customizes the required cap while the default stays unchanged — TS-12/TC-13) | 2026-08-14 (Run 8) | **Clean — same 12 sites / 5 gates as Run 7, no new gate in range** (the two `PageDataLayer` hits are the T39-pinned confirm-purchase registration; none of the range's commits touched a gate). |
| ~~Access-control coverage (Run 7)~~ | 2026-08-13 | **Gates governed; the T39 attachment gap closed same session.** The grep still returns **12 sites / 5 gates** (no new gate in range — `RestController.php` untouched); all 5 keep grant+deny + filter-customization coverage, and the confirm-purchase *callback* remains exemplary (nonce/origin/referer matrix). **The new find was the attachment form:** `register_confirm_purchase_route()` was executed by no test, so swapping both POST routes' `permission_callback` to `__return_true` left all 1965 tests green — probe-verified, and now pinned (registration args in `PageDataLayerTest`, wiring both ways in `ModuleHooksTest`; the same swap goes red). The sweep's lesson extends TS-12: a gate has two halves, the callback and its registration, and grepping for the *sites* only proves the first. |
| ~~Access-control coverage (Run 6, re-run)~~ | 2026-08-05 | **Clean — no gap.** The grep returns **12 sites / 5 gates**, all governed. The four `gtm4wp_admin_page_capability` sites (`Notices`, `RestController`, `SettingsPage`, `Plugin::boot()`) stay pinned after T30/T31. `VisitorDataEndpoint`'s `__return_true` route keeps its per-resolver identity gates. `PageDataLayer::check_confirm_purchase_permission` (the A0 guest beacon) re-verified as **exemplary** — 15 assertions across nonce grant/deny, Origin accept / foreign / look-alike host / port / scheme, the Referer fallback both ways, the no-evidence refusal, and that Referer must not override a foreign Origin. Run 5's "sweep for the sites, don't close the ones a finding named" lesson holds: the grep is now the entry point and it found nothing new. |
| ~~Access-control coverage (Run 5)~~ | 2026-08-05 (Run 5 + closes) | **All four `gtm4wp_admin_page_capability` sites now governed** (was two). `RestController::can_manage()` and `SettingsPage::add_admin_page()` are clean (T27) — but `AdminCapabilityFilterTest` says in its own docblock that it pins "the two enforcement sites the issue names", and there are **four**: `Plugin::boot()` had **no** test (**T30** — 4 cases added, incl. that a denied admin request registers no admin hook and does not fall through to the frontend) and `Notices::dismiss_notice()` had grant+deny without pinning the capability (**T31** — now `expect()->with()` plus a filtered-cap pair). Both probe-verified: the downgrade that used to leave both files green now fails 3 and 4 tests respectively. Clean: the A0 guest-beacon Origin gate (13 cases, exemplary) and the `__return_true` visitor-data route (per-resolver identity gates asserted). **Lesson for the row: closing a gate gap at the sites a *finding* named is not the same as sweeping for the sites.** |
| ~~Access-control coverage (issue #143)~~ | 2026-07-15 (issue #143) | **Closed the one open gap.** `RestController::can_manage()` (REST `permission_callback`) and `SettingsPage::add_admin_page()` (`add_options_page()` cap arg = menu + render guard) were executed by **no** test — the unit tests call the REST handlers directly, bypassing the permission callback, so a coverage driver would show `can_manage()` at 0%. Added `AdminCapabilityFilterTest` (default `manage_options` unchanged + filtered-cap grant/deny at both sites). `Notices::dismiss_notice` deny-direction already covered (finding #18, T8). Module frontend hooks gate on *options*, not capability, so they are out of scope for this sweep. |
| **Assertion quality** (mutation testing — Infection, optional) | never (re-checked 2026-08-14, Run 8) | Still not installed; **no PCOV/Xdebug driver loaded**, so `composer test:coverage` is inert. Run 8's only gap (T51) is again exactly the surviving-mutant class — two statement deletions no test detects. The randomized-order blocker remains gone; Infection could run once a driver is installed. See `.claude/commands/test-review.md` § Optional tooling. |

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

### Run 5: 2026-08-05 full cumulative pass (`ab7fa99..b2bd170`, 83 commits) — 2026-08-05

First full run since Run 4 — the range spans security reports 5→14 (#31–#113),
+7 525 production and +8 580 test lines. Report-only pass; **no tests written this
run.** Baseline PHP **706/2325**, JS **23 suites / 309**, all green. Full detail in the
git-ignored `test-review-report-2026-08-05-1056.md`.

The theme is **a security fix whose test does not exist, inside a component the matrix
already counts as covered** — twice at High. Both were invisible to every mechanical
signal the system has: the missing-test-file sweep is file-granular so `PageDataLayer.php`
counts as covered; a coverage driver would have shown T28's branch as one uncovered line
inside an otherwise-green method; and T30/T31 sit on lines that *are* covered. Only
reverting the guard and watching the suite stay green finds them.

**All closed 2026-08-05** (same session, on the user's "close all gaps" go-ahead).
Suite **PHP 706 → 763 / 2325 → 2463 assertions**, JS 23 suites / 309 (T33 rewrote
existing assertions rather than adding tests); `phpcs` **exit 0** repo-wide with
warnings blocking; `lint:js` clean; **no production code changed** (tests-only →
CHANGELOG exempt, verified with `git diff --name-only -- src/ js/frontend js/admin compat`).

**Four of the seven were probe-verified by reverting the guard** and watching the
new tests go red — T28 (the #33 fix), T30 (the `Plugin::boot()` gate), T31 (the
`Notices` gate) and T32 (the scoped ampersand restore); T33's exact-count
assertions were verified by neutralizing the detach, which failed 4 tests that had
passed as shape assertions. This is TS-15 applied as the acceptance criterion for
the fix, not just the diagnosis.

| # | Sev | Status | Summary | File(s) |
|---|-----|--------|---------|---------|
| T28 | High | addressed | Regression-per-bug + TS-13: the fix for security finding **#33 (High)** was executed by no test — every `WC()` double supplied the collaborator state the real one does not have on this path. Added a REST-shaped double (`session` null, and the session-loader modelled with its real effect) + 4 cases: the one-shot resolves, the loader runs exactly once, and the two guard legs that can vary per request hold. Probe-verified both ways. The `function_exists` leg is documented as not modellable in-process (Brain Monkey defines a mocked function permanently). | `tests/unit/Modules/PageDataLayerTest.php` |
| T29 | High | addressed | TS-6 + TC-5: new `UserEventsModuleTest` (26 cases) for the module that had **no test file** — the `wp_footer` `<script>` sink (both events, both option gates, cookie absent, cookie consumed after emit, null-frontend bail, configured data-layer name, CSP nonce), a 6-row data provider for non-integer cookie values (**the last untested superglobal reads in `src/`**), the cookie writers' session/secure/HttpOnly flags with both `is_ssl()`/`home` legs, the expiry path, and the tracker enqueue gate + its placement filter. **No latent bug** — the sink behaves and matches the house pattern. | `tests/unit/Modules/UserEventsModuleTest.php` (new) |
| T30 | Med | addressed | TS-12: `AdminCapabilityFilterTest` now pins **all four** `gtm4wp_admin_page_capability` sites, not the two a finding named. Added 4 `Plugin::boot()` cases: default cap unfiltered, filtered-cap grant, filtered-cap deny (no admin hook registered at all), and that a denied admin request never falls through to the frontend path. | `tests/unit/Admin/AdminCapabilityFilterTest.php` |
| T31 | Med | addressed | TS-12/TC-13: the two `dismiss_notice()` gate tests now use `expect()->with('manage_options')` instead of `justReturn`, and a filtered-cap grant/deny pair was added. Probe: the same downgrade that used to leave all 17 green now fails 4. | `tests/unit/Admin/NoticesTest.php` |
| T32 | Low | addressed | TS-5 sibling asymmetry: 6 direct `print_markup_block()` tests mirroring its sibling's four, plus the one property that is the reason the method exists — the ampersand restore is **scoped to `<script>` bodies** while an `&amp;` in an iframe attribute stays encoded. Probe-verified (a blanket restore fails 2). | `tests/unit/Frontend/ScriptTagTest.php` |
| T33 | Low | addressed | TS-14: the listener capture/detach moved from one `describe` to **file-level `beforeEach`/`afterEach`**, so no describe can leak into the next. The loosened `find()` assertions became exact counts, the comment explaining why they were weak is gone, and the #71 double-init test now asserts **both** halves (no second listener AND one click → exactly one push) — the push half was previously impossible in this file. | `js/frontend/test/woocommerce-tracker.test.js` |
| T34 | Low | addressed | TS-10 leftovers: `StoreApiData::{product_schema, cart_item_schema}` (read-only + type + a schema-keys-match-data-keys contract test) and the `ListTracking` loop hooks (two data providers over the 6 shortcode + 3 filter-position list-name setters, the filter-position pass-through, `reset_loop` both arities, `widget_title_filter`, and the `before_/after_template_part` buffer balance). `$woocommerce_loop` added to the tearDown reset (TS-7). `AdminSchema::unavailable_message()` ×10 left `[-]` **N/A**. | `tests/unit/Modules/StoreApiDataTest.php`, `ListTrackingTest.php` |

### Run 6: 2026-08-05 empty-diff escalation pass (`b2bd170..f142f4e`) — 2026-08-05

Ran ~50 minutes after Run 5 closed its gaps, over a range with **zero production
lines changed** — the command's step-8 trigger to go deep. Report-only pass; **no
tests written this run.** Baseline unchanged: PHP **763/2463**, JS **23 suites / 309**,
all green. Full detail in the git-ignored `test-review-report-2026-08-05-1146.md`.
**Reviewed at: `f142f4e`.**

**No High gap and no latent production bug.** Both entry candidates collapsed on
verification, which is the run's most reassuring result: the `Field::to_string()`
custom-sanitizer contract is guarded by a genuine whole-schema sweep test
(probe-verified — a bare `(string)` cast at all 14 AdminSchema call sites fails the
suite immediately), and the access-control grep found nothing the four-site sweep
had missed.

The two real finds are both **structural blind spots in the review system itself**,
not in any one test:

1. **The inventory step does not recurse** (H-2). The Admin JS row read `js/admin/`
   and the command's own one-liner is `ls js/admin/*.js`, so a whole `components/`
   subdirectory — 6 files, 939 lines — was never inventoried. This is the Run-5
   lesson ("a component with no row is invisible, not unreviewed") recurring one
   directory level down.
2. **Nothing had ever checked test-order independence** (H-1). A one-flag run
   (`--order-by=random`) shows 13 tests error, three of them security regression
   guards, hidden because Brain Monkey's function definitions are sticky
   process-wide — the very mechanism Run 5 documented as a *limitation* and did not
   follow through as a *risk*.

**All closed 2026-08-05** (same session, on the user's "close all gaps" go-ahead).
Suite **PHP 763 → 767 / 2463 → 2488 assertions**, **JS 23 → 30 suites / 309 → 404
tests**; `phpcs` **exit 0** repo-wide; `lint:js` clean; **`npm run build` output
verified byte-identical**; **no production code changed** (tests + tooling only →
CHANGELOG exempt).

**TS-16 was applied as the acceptance criterion:** the suite now passes
`--order-by=random` across **5 seeds** with an assertion count identical to the
declaration-order run — one green seed would have proved nothing. Three closes were
probe-verified by reverting the guard (the `uninstall.php` gate, `TableControl`'s
locked-cell rejection, `AxeptioVersionControl`'s `cancelled` guard), and T37's new
assertion was probed with a **warning-free** `'Array'` stringify — precisely the
case its predecessor let through.

| # | Sev | Status | Summary | File(s) |
|---|-----|--------|---------|---------|
| T35 | Med | addressed | TS-8: the suite is **order-dependent** — `--order-by=random` errors 13 tests (12 on `wp_get_environment_type`, 1 on `get_multiple_authors`) that never stub the function and pass only because another file defined it process-wide. **Three are security regression guards** (settings-import hostile payload, hostile container ID, the T13/#11 admin hex sink). Each file passes alone, so it is within-file ordering. Nothing silently passes — they error — but green is contingent on declaration order, randomized order can't be adopted, and Infection can't run until it's fixed. **Closed** by stubbing `wp_get_environment_type` in both Admin `setUp`s (pinning `'production'`, the value the source's own `function_exists()` fallback returns, instead of inheriting another file's `'staging'`) and declaring `get_multiple_authors` in `PageVariablesModuleTest::setUp()` as "no PublishPress authors" — **outcome-equivalent to the function being absent**, so the four PublishPress tests simply override it and the "these must run last" NOTE was deleted rather than reworded. | `tests/unit/Admin/RestControllerTest.php`, `SettingsPageTest.php`, `tests/unit/Modules/PageVariablesModuleTest.php` |
| T36 | Med | addressed | TS-6: **`js/admin/` is 9 files / 1375 lines and only `utils.js` (246) has a test — 1129 lines untested**, invisible until this run because the row and the inventory one-liner both stop at `js/admin/*.js`. Highest value: `AxeptioVersionControl.js` (fetches an **external third-party JSON endpoint**; 4 outcome branches + a `cancelled` race guard) and `ImportExport.js` (4 import outcomes + the re-select reset). `TableControl.js`/`FieldControl.js` carry real branching; `Sidebar`/`ModulePanel` are reasonable `[-]` N/A. **Closed with 95 new tests across 6 files** covering all 7 untested components. Tooling decision recorded as **TC-15**: `@testing-library/react` + `jest-dom` + `react@^18` added as devDependencies, but the `@wordpress/*` packages are **mapped to local stand-ins** (`js/admin/test-support/`) rather than installed — they are build-time externals, so no installed version is "the real one". Guards are asserted by **effect**, never by a rendered prop. | `js/admin/test/{table-control,axeptio-version-control,import-export,field-control,module-panel,sidebar,app}.test.js`, `js/admin/test-support/*`, `jest.config.js` |
| T37 | Low | addressed | Weak assertion (TS-1 family): the every-field non-scalar sweep asserts only `is_scalar() \|\| is_array() \|\| null \|\| WP_Error`, and a string is scalar — so a sanitizer returning the literal `'Array'` **without** a warning would pass. The throwing error handler is the sole load-bearing detector; the value contract is asserted for exactly one field. **Closed:** the sweep now also asserts that no string leaf of the result contains the literal `'Array'`, recursing into table/multiselect shapes. Probe-verified with a *warning-free* stringify — exactly the case the old assertion passed. | `tests/unit/Modules/ModuleConsistencyTest.php:189` |
| T38 | Low | addressed | TS-6: `uninstall.php` had no test for the `WP_UNINSTALL_PLUGIN` guard or the delete set, including a bulk `delete_metadata( 'user', 0, …, '', true )` across every user. **Closed** by `UninstallTest` (4 cases). The guard's `die()` cannot be observed in-process (TC-7's throw-and-observe needs a stubbable function), so both directions are driven in a **subprocess** — which also keeps the process-wide `WP_UNINSTALL_PLUGIN` constant out of the test runner, the sticky state TS-16 is about. Probe-verified: neutralising the guard fails the deny test. | `tests/unit/UninstallTest.php` (new) |

### Run 7: 2026-08-13 full cumulative pass (`f142f4e..52f3c4f`, 81 commits) — 2026-08-13

Largest range yet — spans `.security` reviews 15–24 (#114–#189), the media-SDK overhaul,
CF7 GA4 events, enhanced-conversions hashing, the generated phone table, and the
order-received gate. Report-only pass; **no tests written this run.** Baseline **PHP
1965/4601** (declaration + 3 random seeds) and **JS 33 suites / 713**, all green.
**All six Mediums probe-verified by reverting the guard and watching the suite stay
green**, probes serialized in the main thread, tree restored byte-exact. Full detail in
the git-ignored `test-review-report-2026-08-13-0942.md`. **Reviewed at: `52f3c4f`.**

**No High gap and no latent production bug.** Two low-severity production observations
routed to `/code-review` (detail stays in the git-ignored report). The theme: **the
attachment/call-site is the untested half of a fully-tested guard** — three of the six
Mediums are a correct, well-tested guard whose *surroundings* (a route registration, a
call site, a fixture shape) let it be removed silently.

**All closed 2026-08-13** (same session, on the user's "fix all gaps" go-ahead). Suite
**PHP 1965 → 1996 tests / 4601 → 4669 assertions**, **JS 33 → 35 suites / 713 → 722
tests** (new: `media-gate.test.js`, `form-move-tracker-config-false.test.js`); all green
in declaration order **and 4 randomized seeds** at identical counts (TS-16); `phpcs`
**exit 0** repo-wide; `lint:js` clean; **no production code changed** (tests + `.testing/`
tracking only → CHANGELOG exempt, verified with `git status -- src/ js/frontend/*.js compat/`).

**TS-15 applied as the acceptance criterion: all six Medium closes were signed off by
re-running the six revert probes and watching the NEW tests go red** — checkout
`json_literal` (1 red), Axeptio `json_literal` (1 red), the `__return_true` route swap
(1 red), the emptied gate file (2 red), the neutralized VideoPress detach (2 red), the
always-collapse name hash (2 red). The VideoPress probe earned its keep twice: the first
version of T43's test stayed green under the neutralized detach — the second load never
re-attaches because the embed keeps its wired marker — and was rewritten to the
discriminating shape (re-rendered page → fresh unmarked embed → re-attach) before being
trusted. Two smaller catches the same way: the CF7 fail-closed comment first mis-attributed
the lead to `wpcf7mailsent` and was corrected against the tracker source, and a
trailing-newline row added to the *unusable*-names provider turned out to pass for the
wrong reason (the name path trims before validating) — it became its own
trims-before-validating case with a non-default name so trimmed-accept and reject cannot
coincide.

| # | Sev | Status | Summary | File(s) |
|---|-----|--------|---------|---------|
| T39 | Med | addressed | TS-12 (attachment form): `register_confirm_purchase_route()` was executed by no test — swapping both POST routes' `permission_callback` to `__return_true` left all 1965 tests green (probe-verified). **Closed:** the registration args (namespace, POST, callback + permission-callback identity per route) and the purchase route's option gate are pinned in `PageDataLayerTest`; the `rest_api_init` wiring both ways (cache-safe on/off) in `ModuleHooksTest`. | `tests/unit/Modules/PageDataLayerTest.php`, `tests/unit/Modules/ModuleHooksTest.php` |
| T40 | Med | addressed | TS-1/TS-15: the #141 `json_literal` guard was revertible green at its two filter-fed call sites. **Closed:** filter-injected NAN at both — checkout products (`= null;` present, `= ;` absent, sibling global survives) and Axeptio settings (SDK loader + bridge survive). Remaining internally-typed config-site callers recorded `[-]` N/A (BE-3). | `tests/unit/Modules/PageDataLayerTest.php`, `tests/unit/Modules/ConsentModeAxeptioTest.php` |
| T41 | Med | addressed | TS-6/TS-13/TS-17: `gtm4wp-media-gate.js` was executed by no test (every gate case hand-set the flag; emptying the file left 713 JS tests green). **Closed** by `media-gate.test.js`: the flag as a window OWN property (with the TC-16-style precondition pin), the real-file integration open case, and the expected-but-never-ran shut case. | `js/frontend/test/media-gate.test.js` (new) |
| T42 | Med | addressed | TS-1/TS-3: the "unset `orderData['customer']` AFTER the order-data filter" contract was asserted nowhere. **Closed:** a filter callback records seeing `customer`, copies a billing field to its own key, and the test asserts saw-it + own-key-survives + `customer`-gone. | `tests/unit/Modules/PageDataLayerTest.php` |
| T43 | Med | addressed | TS-5 sibling asymmetry: VideoPress was the only guard-carrying bundle without a double-load test. **Closed** with the re-rendered-page shape (fresh unmarked embed between loads, exactly one push) — the first draft did not discriminate and was rewritten under the probe, which is the row worth remembering. | `js/frontend/test/videopress-tracker.test.js` |
| T44 | Med | addressed | TS-1: the name-hash keep-interior-spaces contract was unpinned (single-token fixtures). **Closed** both halves: the helper's two flag behaviors discriminated by a two-token input (TS-2 both directions), and the ProductData sink with 'Mary Ann' / 'van der Berg' asserting space-keeping hashes present AND collapsed hashes absent. | `tests/unit/Modules/HelpersTest.php`, `tests/unit/Modules/ProductDataTest.php` |
| T45 | Low | addressed | TS-10/TS-1: `BlacklistAdminSchemaTest` was structure-only. **Closed:** a 6-row mode-clamp provider, hostile-entity filtering in BOTH input shapes (comma string with a `</script>` id, array with a nested-array member) over a core-faithful `sanitize_text_field` stub (#131's lesson), and the module's array-form stored-status branch. | `tests/unit/Modules/BlacklistAdminSchemaTest.php`, `tests/unit/Modules/BlacklistModuleTest.php` |
| T46 | Low | addressed | WC misc. **Closed:** the view_item dependency positive case + priority-20 pin (in `ModuleHooksTest`, where the TC-8 singleton harness lives — the negative provider's home cannot reach past the early return); a non-string `woocommerce_thankyou_order_key` filter return denying without a TypeError; `customer_signals` keys pinned at the `resolve_pending_purchase` emission site; the `woocommerce_cart_hash` cookie leg of `visitor_has_wc_state()`. | `tests/unit/Modules/ModuleHooksTest.php`, `PageDataLayerTest.php`, `WooCommerceModuleTest.php` |
| T47 | Low | addressed | Phone/hash misc. **Closed:** a 3-row pinning provider for the documented-unmodelled international access codes (AU 0011 / RU 810 / US 011, outputs measured before pinning, labelled as behavior-not-contract); the multi-`@` fold pinned; the email keeps-key sibling on the raw order path; `lookup()` case/padding tolerance. Non-UTF8/IDN email inputs recorded `[-]` N/A (byte-wise ops; a test would be TS-4-adjacent). | `tests/unit/Modules/HelpersTest.php`, `tests/unit/Modules/ProductDataTest.php` |
| T48 | Low | addressed | Frontend misc. **Closed:** `to_ui_array()['sections']` pinned (declared + empty-default legs; `make_field` extended to reach the constructor params); both sibling guards' invalid-UTF-8 triggers swapped to NAN with the TS-13 rationale in place; the trailing-newline case landed as its own trims-before-validating test (see the close note — the provider row passed for the wrong reason); the UC-3→TS-13 cite fixed. | `tests/unit/Options/FieldTest.php`, `tests/unit/Frontend/{ContainerCodeTest,DataLayerTest,ScriptTagTest}.php` |
| T49 | Low | addressed | MediaEvents misc. **Closed:** one in_footer-filter case pinning filtered-false placement for tracker + gate + surviving defer strategy; Spotify pre-existing `onSpotifyIframeApiReady` chained (previous fn called once with the API, tracker still wires); the three html5 filename edges (trailing slash, percent-encoded, extensionless); the post-consent latch pinned **as a decision** (refusal is page-scoped fail-closed; the embed-blocked path is the one that resumes without reload). | `tests/unit/Modules/MediaEventsModuleTest.php`, `js/frontend/test/{spotify-tracker,html5media-tracker,native-video-params}.test.js` |
| T50 | Low | addressed | CF7/UserEvents/List misc. **Closed:** the status-less CF7 submit now asserts `generate_lead` count 0 with the fail-closed intent stated (and the case named as the upstream `detail.status` tripwire); the shipped `{filledOnly:false}` config shape got its own JS realm (`form-move-tracker-config-false.test.js`) with the case that discriminates false-flag from config-presence; `reset_loop` asserts the identity-key clear in both arities; a multibyte byte-bound eviction case pins post-encoding measurement. | `js/frontend/test/{contact-form-7-tracker,form-move-tracker-config-false,ecommerce-generic}.test.js`, `tests/unit/Modules/ListTrackingTest.php` |

### Run 8: 2026-08-14 post-beta3 range review (`52f3c4f..65c9670`) — 2026-08-14

Small range: 8 commits, 4 with production code, every one shipping its tests in the
same commit (three with recorded watched-red-first probes). Report-only pass; **no
tests written this run.** Baseline **PHP 2002/4686** (declaration + 3 random seeds)
and **JS 35 suites / 726**, all green. The `gtm4wp-visitor-data.js` delta is
comment-only (N/A). One Medium gap, **both legs probe-verified in the main thread**
(tree restored byte-exact after each). One code observation (the flag name as a bare
literal at 7 sites across 3 src files) routed to `/code-review`. Full detail in the
git-ignored `test-review-report-2026-08-14-1615.md`. **Reviewed at: `65c9670`.**

| # | Sev | Status | Summary | File(s) |
|---|-----|--------|---------|---------|
| T51 | Med | addressed | TS-15 (producer of a hand-set fixture state): the request-scoped purchase-pushed flag was pinned only on its PurchaseTracking half — every test that needed it set it by hand, so the two PageDataLayer legs (the raise in the standard order-received render, the honor in the any-page fallback) each reverted green at 2002/4686. **Closed with 4 tests:** the flag raised on the order-received harness (order-resolved + the deliberate no-order leg), the fallback suppressed with the flag pre-set (marker untouched), and the cross-class chain with **no hand-set state** — real render raises, real `remember_order()` refuses to re-seed the marker the render consumed. Both probes re-run and now red (3 + 1 failures; the chain test fails on the re-seeded order id, the literal bug shape). | `tests/unit/Modules/PageDataLayerTest.php` |

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
