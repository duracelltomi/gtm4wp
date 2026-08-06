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
| **Options** (`src/Options/` — Options, Field) | `Options/OptionsTest` (18), `Options/FieldTest` (17) | [x] | [x] 2026-07-13 | [x] 2026-07-13 | [-] | [x] 2026-08-05 | [x] 2026-08-05 |
| **Compat Layer** (`compat/*.php`, `src/Compat/Globals.php`) | `Compat/GlobalsTest` | [x] 2026-07-13 | [x] 2026-07-13 | [x] 2026-07-13 | [-] 2026-08-05 | [-] 2026-08-05 | [x] 2026-07-13 |
| **Migration** (`src/Migration.php`) | `MigrationTest` (11) | [x] | [x] 2026-08-05 | [x] 2026-08-05 | [-] | [-] | [x] 2026-08-05 |
| **Frontend Core** (`src/Frontend/` — DataLayer, ScriptTag, ContainerCode, ConsentDefaults, VisitorIp; Frontend = N/A) | `Frontend/DataLayerTest`, `ScriptTagTest`, `ContainerCodeTest`, `ConsentDefaultsTest`, `VisitorIpTest` | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 |
| **Module Framework** (`src/Module/`) | `Module/RegistryTest` (5, 6/6 public methods), `Modules/ModuleConsistencyTest`, `ModuleHooksTest` | [x] | [x] 2026-08-05 | [x] 2026-08-05 | [-] | [-] | [x] 2026-08-05 |
| **PageVariables Module** (`src/Modules/PageVariables/`) | `Modules/PageVariablesModuleTest`, **`PageVariablesAdminSchemaTest`** | [x] | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 |
| **Container Module** (`src/Modules/Container/` — incl. **`HardcodedContainers`**) | `Modules/ContainerRowsTest`, `ContainerAdminSchemaTest`, **`HardcodedContainersTest`** | [x] | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 |
| **WooCommerce Module** (`src/Modules/WooCommerce/` — PurchaseTracking, ProductData, PageDataLayer, ListTracking, Helpers, StoreApiData, Module, AdminSchema) | `Modules/PurchaseTrackingTest`, `ProductDataTest`, `PageDataLayerTest`, `ListTrackingTest`, `HelpersTest`, `StoreApiDataTest`, `WooCommerceModuleTest`, `WooCommerceAdminSchemaTest` | [x] | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 |
| **ConsentMode Module** (`src/Modules/ConsentMode/` — incl. Axeptio handler, CookieYes bridge) | `Modules/ConsentModeAxeptioTest`, `ConsentModeCookieYesTest`, `ModuleHooksTest` (gate + webtoffee + axeptio + cookieyes wiring) | [x] 2026-07-15 | [x] 2026-07-15 | [x] 2026-07-15 | [x] 2026-07-15 | [x] 2026-07-15 | [x] 2026-07-15 |
| **UserEvents Module** (`src/Modules/UserEvents/`) | **`Modules/UserEventsModuleTest`** (new, T29), `ModuleHooksTest` (gate) | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 | [-] | [x] 2026-08-05 |
| **MediaEvents Module** (`src/Modules/MediaEvents/` — 12 trackers) | `Modules/MediaEventsModuleTest` (enqueue gate + oEmbed rewrite), `ModuleHooksTest` (youtube gate), `ModuleConsistencyTest` (12 opts); JS: 14 tracker tests + `native-video-params` | [x] 2026-07-13 | [x] 2026-07-13 | [x] 2026-07-13 | [-] | [x] 2026-07-13 | [x] 2026-07-13 |
| **ContactForm7 Module** (`src/Modules/ContactForm7/` — incl. tracker JS) | `Modules/ContactForm7ModuleTest`, `ModuleHooksTest` (gate); JS: `contact-form-7-tracker` | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 |
| **Blacklist Module** (`src/Modules/Blacklist/` — incl. `sandboxedScripts` group class) | `Modules/BlacklistModuleTest` | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 |
| **ClientDeviceData Module** (`src/Modules/ClientDeviceData/`; JS tracker) | `Modules/ClientDeviceDataModuleTest`, `ModuleHooksTest` (gate); JS: `client-device-data-tracker` | [x] 2026-07-13 | [x] 2026-07-14 | [x] 2026-07-14 | [-] | [x] 2026-07-14 | [x] 2026-07-14 |
| **VisitorData Module** (`src/Modules/VisitorData/` — `VisitorDataEndpoint` **public** session route, `VisitorField`, `AdminSchema`; JS: `gtm4wp-visitor-data.js`) | `Modules/VisitorDataEndpointTest`, `VisitorDataModuleTest`, `ModuleHooksTest`; JS: `visitor-data-tracker` (**1 412 lines**, measured 2026-08-06) | [x] | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 |
| **AMP Module** (`src/Modules/Amp/` — amp-wp 2.x `amp_analytics_entries` sink) | `Modules/AmpModuleTest`, `ModuleHooksTest` (gate) | [x] | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 |
| **Admin — Notices/AJAX** (`src/Admin/Notices.php`) | `Admin/NoticesTest` | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 |
| **Admin — Settings UI** (`src/Admin/SettingsPage.php`, `Admin.php`, `RestController.php`, `PluginRow.php`) | `Admin/RestControllerTest`, `SettingsPageTest`, `PluginRowTest`, `AdminCapabilityFilterTest` | [x] | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 |
| **Module Admin Schemas** (`src/Modules/*/AdminSchema.php` — **10 custom sanitizers**) | `Modules/ModuleConsistencyTest` (every-field non-scalar sweep), `ContainerAdminSchemaTest`, `WooCommerceAdminSchemaTest`, `PageVariablesAdminSchemaTest` | [x] | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 (probe-verified) | [-] | [x] 2026-08-05 |
| **Frontend JS** (`js/frontend/` — **19** bundles + **3** libs: `lib/native-video-params`, `lib/blocks-cart-diff`, **`lib/gtm4wp-cookies`**) | **22** `js/frontend/test/*.test.js` (every bundle and every lib covered; `cookies.test.js` new) | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 (T33 closed: file-level listener detach) |
| **Admin JS** (`js/admin/` — **9 files / 1375 lines**: `App.js`, `utils.js`, `index.js` + **6 in `components/`**) | **7** test files: `utils`, `app`, `table-control`, `axeptio-version-control`, `import-export`, `field-control`, `module-panel`, `sidebar` (+ stand-ins in `js/admin/test-support/`, TC-15). `index.js` `[-]` N/A (3-line mount) | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-08-05 | [-] | [x] 2026-08-05 |

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
| **Test-order independence** (`vendor/bin/phpunit --order-by=random` — TS-8/TS-16) | 2026-08-05 (Run 6 + closes) | ✅ **Clean — 5 consecutive random seeds green at 767/2488**, the same assertion count as declaration order. Was 13 errors when the sweep was first run this session (T35, now closed): 12 on `wp_get_environment_type`, 1 on `get_multiple_authors`, three of them security regression guards, all passing only because another file had defined the function process-wide. Run this **3–5 times** every review — one green seed proves nothing. |
| ~~Test-order independence (Run 6, first run)~~ | 2026-08-05 (Run 6) | ⚠️ **13 errors** (seed 1785923095). 12 on `wp_get_environment_type`, 1 on `get_multiple_authors`: the tests never stub them and pass only because another file defines them process-wide (Brain Monkey makes `function_exists()` sticky). **Three of the 13 are security regression guards** (settings-import hostile payload, hostile container ID, the T13/#11 admin hex sink). Each file still passes *alone*, so it is within-file test ordering. Nothing silently passes — they error — but the suite's green is contingent on declaration order and randomized order cannot be adopted. Gap **H-1**. |
| **Missing test file** (`src/**/*.php` with no dedicated or documented-indirect test) | 2026-08-05 (Run 6 + closes) | **Clean — no real zero-test PHP class.** `uninstall.php` (outside `src/`, so the sweep never saw it) gained `UninstallTest` this session (T38). 22 raw hits over **55** files (Run 5: 23/56; the delta is `UserEventsModule.php` gaining T29's test). All 22 classify as BE-3 N/A (`Plugin`, `Autoloader`, `Admin/Admin`, `Frontend/Frontend`, the 3 `Module/` contracts) or basename over-reports (10 `AdminSchema.php`, `ConsentMode/*`, `Container/ContainerModule`, `VisitorData/VisitorField`). **The sweep is PHP-only and missed `js/admin/components/` entirely** (H-2) — the JS side needs its own recursive inventory. |
| ~~Missing test file (Run 5)~~ | 2026-08-05 (Run 5 + closes) | 23 raw hits over 56 PHP files, one real gap — `UserEvents/UserEventsModule.php` (T29), **closed the same session** by `UserEventsModuleTest`. **Zero real zero-test classes remain.** Everything else classified: BE-3 N/A (`Plugin`, `Autoloader`, `Admin/Admin`, `Frontend/Frontend`, the 3 `Module/` contracts) or indirectly covered (all 10 `AdminSchema.php` via `ModuleConsistencyTest` + 3 dedicated schema tests; `ConsentMode/*`, `Container/ContainerModule`, `VisitorData/VisitorField` — basename mismatches the sweep over-reports). |
| ~~Missing test file (Run 4)~~ | 2026-07-15 (Run 4) | No real zero-test class in the WooCommerce/consent batch — every new class ships a test (`StoreApiDataTest`, `WooCommerceModuleTest`, `WooCommerceAdminSchemaTest`, `ProductDataTest`, `PageDataLayerTest`, `ConsentModeCookieYesTest`). Sweep over-reports on basename mismatches (`WooCommerceAdminSchemaTest`≠`AdminSchema`, `ConsentModeCookieYesTest`≠`ConsentModeModule`). Remaining PHP BE-3 N/A unchanged (`Plugin`, `Frontend`, `Autoloader`, `Admin/Admin`, module orchestrators, interfaces). |
| **Untested public methods** (in classes that *do* have a test file — TS-10) | 2026-08-05 (Run 6, re-run) | **Clean — no new hits.** Re-swept the components whose cells were still `[ ]`: `Registry` 6/6 public methods covered by `RegistryTest`; `Migration::maybe_run()` plus all three private steps covered by 11 `MigrationTest` cases (idempotence, version gate, no-overwrite, missing options, post-meta seeding both ways); `RestCors` covers the predicate (data provider), the wrapper, the non-`WP_REST_Request` guard, `register()`'s priority-11 and a namespace-consistency test. Run 5's `[-]` N/A for `AdminSchema::unavailable_message` ×10 stands. |
| ~~Untested public methods (Run 5)~~ | 2026-08-05 (Run 5 + closes) | Swept globally (per-file over-reports). **All real hits closed:** `ScriptTag::print_markup_block` (T32, 6 tests incl. the scoped-restore property its sibling does not have), `StoreApiData::{product_schema,cart_item_schema}` + the 13 `ListTracking` loop hooks (T34). **Withdrawn on verification:** `ProductData::is_new_customer` (asserted via `PurchaseTrackingTest`), `VisitorDataModule::build_config` (5 config-shape tests), `RestController::get_settings` (thin wrapper over the tested `ui_values()`). `AdminSchema::unavailable_message` ×10 recorded **`[-]` N/A** — a translated literal, TS-4 if tested. |
| ~~Untested public methods (Run 4)~~ | 2026-07-15 (Run 4 + closes) | **Closed:** `StoreApiData::register()`; `ListTracking::{quick_view_before_single_product, cart_item_product_filter, cart_item_remove_link_filter, single_add_to_cart_tracking, grouped_product_list_column_label, after_template_part}` (T22); classic JS `gtm4wp_woocommerce_handle_cart_qty_change` + remove-link handler (T25). Prior remaining low-value (`ListTracking` list-name setters; `Notices::print_dismiss_script`) unchanged. |
| **Security-input coverage** (every `.security` PA-3/RI-2 sink has a hostile-input test — TS-1/TC-5) | 2026-08-05 (Run 6, re-run) | **Clean.** Run 5's "every superglobal read in `src/` has a hostile-input test" still holds (no production change). New this run: audited the **settings-import** input path, which is the one entry point that reaches `Field::sanitize()` **without** the REST layer's per-field type coercion. All **10** custom sanitizers correctly cast through `Field::to_string()`, and the contract is guarded by `ModuleConsistencyTest::test_every_field_sanitizer_handles_non_scalar_input_without_warning` — a whole-schema sweep that pins fields not yet written. **Probe-verified:** a bare `(string)` cast at all 14 call sites fails the suite. One weak-assertion note: the sweep's result check permits the literal `'Array'` (H-3). |
| ~~Security-input coverage (Run 5)~~ | 2026-08-05 (Run 5 + closes) | Enumerated **all 16** superglobal reads in `src/`. The two `UserEvents` event cookies were the only untested ones and are now covered by a 6-row non-integer-value provider (T29), so **every superglobal read in `src/` now has a hostile-input test**. New sinks all clean both-directions: the visitor-cart data-attribute fragment, the A0 visitor-data route's header round-trip, the settings import payload, `HTTP_CF_IPCOUNTRY`/`HTTP_REFERER` raw-passthrough (TS-11), the `?key=` ownership check (PA-10). |
| ~~Security-input coverage (Run 4)~~ | 2026-07-15 (Run 4) | **Batch clean — no open hostile-input sink gap.** Verified both-directions: list-attribution cookie #405 (the one new untrusted request surface — `ProductDataTest`+`PageDataLayerTest`); product-title + order-number `<script>` sinks; raw order billing/shipping passthrough (#8 contract); PA-7 replacement injectors (#16); CookieYes datalayer-name `esc_js` (`ConsentModeCookieYesTest`). Store-API block sink is a delegated REST sink (FP-4) — the only *open* item is a raw-passthrough **contract** test (T21, data-integrity not XSS) + `item_category` benign-only (T23). |
| **Regression-per-bug** (every `.security` Known Finding has a live regression test) | 2026-08-05 (Run 6, re-run) | **Clean — no new findings in range** (zero production commits since Run 5). Run 5's walk of #31–#113 stands, including T28's close for #33. Caveat surfaced this run: three of those guards (#11's admin hex sink, and the two settings-import sanitization tests) only *execute* in the default declaration order — see the test-order sweep (H-1). The guards are correct; their scheduling is not. |
| ~~Regression-per-bug (Run 5)~~ | 2026-08-05 (Run 5 + closes) | Walked **#31–#113** (reports 5–14). One unguarded fix — **#33 (High)** — found and **closed the same session** (T28); re-probed after: deleting the fix now fails the new test. **No `.security` finding is left without a live guard.** Everything else holds, several exemplary: #40 and #112 model the real collaborator (SDK `replaceChild` + a cap; the real `esc_url` allow-list), #106 models the sanitizer pair an identity stub would render vacuous, #91 asserts the helper is `->never()` called, #63 deletes `global.fetch`. #63 was logged as a candidate and **withdrawn on verification**. Process/ledger findings (#73, #87, #98, #101, #103, #104, #107, #109, #111, #113) are out of scope — no code sink. |
| ~~Regression-per-bug (Run 4)~~ | 2026-07-15 (Run 4) | `.security` Review 5 landed one Low (#31 block cross-sell `internal_id` strip) with a both-directions regression in `woocommerce-blocks-tracker.test.js` — verified live. Prior #1–#6/#8/#11–#18/#28–#30 unchanged. **No unverified findings remain.** |
| **JS test coverage** (`js/frontend/` trackers, `js/admin/` app) | 2026-08-05 (Run 6 + closes) | ✅ **30 suites / 404 tests green** (was 23/309). **T36 closed:** all 7 previously-untested `js/admin/` files now covered by 95 new tests — the lock guards in `TableControl`, the four third-party-response branches plus the stale-request guard in `AxeptioVersionControl`, the four import outcomes in `ImportExport`, the type routing in `FieldControl`, the group/tab arithmetic in `ModulePanel`, the search/dirty markers in `Sidebar`, and the dirty/save/import baseline bookkeeping in `App`. Harness recorded as **TC-15**. `index.js` `[-]` N/A. **No open JS gaps.** |
| ~~JS test coverage (Run 6, first run)~~ | 2026-08-05 (Run 6) | **`js/frontend/` clean** — 23 suites / 309 green, all 19 bundles + 3 libs covered, no open gap. **`js/admin/` is the open half and was never actually swept:** the row tracked `js/admin/` as one unit, so the **`components/` subdirectory was invisible**. Real state: **9 files / 1375 lines, only `utils.js` (246) tested → 1129 untested** (`App.js` + 6 components). Highest value: `AxeptioVersionControl.js` (external third-party fetch, 4 outcome branches + a cancellation race) and `ImportExport.js` (4 import outcomes). **Blocked on tooling** — `@testing-library/react` is not installed and `@wordpress/scripts` v30 does not provide it. Gap **H-2**. |
| ~~JS test coverage (Run 5)~~ | 2026-08-05 (Run 5 + closes) | **23 suites / 309 tests green.** Every one of the 19 bundles and all 3 libs has a test file — no untested bundle remains. New in range and covered: `visitor-data-tracker` (1 034 lines), `cookies`, plus growth in `native-video-params`, `spotify`, `vimeo`, `twitch`, `ecommerce-generic`, `admin/utils`. Double-init guards (#71/#83) present **and** tested in every bundle that has one. **T33 closed:** the listener capture/detach moved to file-level hooks, so no describe leaks into the next; the shape-based assertions became exact counts and the #71 double-init test now asserts the push half too. Probe: neutralizing the detach fails 4 tests that used to pass. **No open JS gaps.** |
| ~~JS test coverage (Run 4)~~ | 2026-07-15 (Run 4 + closes) | **JS suite 214 → 231 green (21 suites)** after closing T25 (classic cart qty-change + remove-link + variable/grouped/disabled add_to_cart + chunking + parse catches) and T26 (delegated-listener isolation harness, probe-verified). Also confirmed Run-4 baseline: `woocommerce` (last open tracker gap, now closed), `woocommerce-blocks`, `blocks-cart-diff`, `ecommerce-generic` all covered; pure cart-diff lib + block tracker core well-covered (real `subscribe()` drive, TC-10). **No open JS gaps.** |
| **Access-control coverage** (every `permission_callback` / capability gate has a grant+deny test; every filterable capability — `gtm4wp_admin_page_capability` — has a test that the filter customizes the required cap while the default stays unchanged — TS-12/TC-13) | 2026-08-05 (Run 6, re-run) | **Clean — no gap.** The grep returns **12 sites / 5 gates**, all governed. The four `gtm4wp_admin_page_capability` sites (`Notices`, `RestController`, `SettingsPage`, `Plugin::boot()`) stay pinned after T30/T31. `VisitorDataEndpoint`'s `__return_true` route keeps its per-resolver identity gates. `PageDataLayer::check_confirm_purchase_permission` (the A0 guest beacon) re-verified as **exemplary** — 15 assertions across nonce grant/deny, Origin accept / foreign / look-alike host / port / scheme, the Referer fallback both ways, the no-evidence refusal, and that Referer must not override a foreign Origin. Run 5's "sweep for the sites, don't close the ones a finding named" lesson holds: the grep is now the entry point and it found nothing new. |
| ~~Access-control coverage (Run 5)~~ | 2026-08-05 (Run 5 + closes) | **All four `gtm4wp_admin_page_capability` sites now governed** (was two). `RestController::can_manage()` and `SettingsPage::add_admin_page()` are clean (T27) — but `AdminCapabilityFilterTest` says in its own docblock that it pins "the two enforcement sites the issue names", and there are **four**: `Plugin::boot()` had **no** test (**T30** — 4 cases added, incl. that a denied admin request registers no admin hook and does not fall through to the frontend) and `Notices::dismiss_notice()` had grant+deny without pinning the capability (**T31** — now `expect()->with()` plus a filtered-cap pair). Both probe-verified: the downgrade that used to leave both files green now fails 3 and 4 tests respectively. Clean: the A0 guest-beacon Origin gate (13 cases, exemplary) and the `__return_true` visitor-data route (per-resolver identity gates asserted). **Lesson for the row: closing a gate gap at the sites a *finding* named is not the same as sweeping for the sites.** |
| ~~Access-control coverage (issue #143)~~ | 2026-07-15 (issue #143) | **Closed the one open gap.** `RestController::can_manage()` (REST `permission_callback`) and `SettingsPage::add_admin_page()` (`add_options_page()` cap arg = menu + render guard) were executed by **no** test — the unit tests call the REST handlers directly, bypassing the permission callback, so a coverage driver would show `can_manage()` at 0%. Added `AdminCapabilityFilterTest` (default `manage_options` unchanged + filtered-cap grant/deny at both sites). `Notices::dismiss_notice` deny-direction already covered (finding #18, T8). Module frontend hooks gate on *options*, not capability, so they are out of scope for this sweep. |
| **Assertion quality** (mutation testing — Infection, optional) | never (re-checked 2026-08-05, Run 6) | Still not installed, and **no PCOV/Xdebug driver is loaded either**, so `composer test:coverage` is inert. Run 5 makes the case concrete: **T28 is exactly the mutant Infection detects** — a fix whose deletion changes no test outcome. See `.claude/commands/test-review.md` § Optional tooling. (Note a driver alone would not have caught T30/T31: those are assertion-quality gaps on lines that *are* covered. Run 6 adds a third caveat — Infection **cannot run at all** until H-1 is fixed, since it reorders and re-runs tests.) |

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
