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
| **Plugin Bootstrap** (main file, `uninstall.php`, `Plugin.php`, `Autoloader.php`, **`src/RestCors.php`**, **`src/RequestOrigin.php`** — the shared guest-route gate, extracted in phase 3 (`9c94bb8`, ledger-only), documented-indirect via `PageDataLayerTest` (20 permission cases, real class) + `GoogleDataManagerBackfillTest`; **`Plugin::boot_refund_lane()` (phase 4)**) | `RestCorsTest` (5, incl. wrapper + non-request guard + priority-11 + namespace consistency); `Admin/AdminCapabilityFilterTest` (the `Plugin::boot()` gate, T30; + KeyNotice attachment, T74); **`PluginRestWiringTest`** (the `rest_api_init` closure — settings + google routes + RestCors, T66, probe-red; + the GDM destinations route incl. callback identity AND the service-account deletion veto attach+effect from a real `boot()`, Run 11; + backfill route both directions of its option gate, privacy boot attachment, relabel route identity, Run 12); `UninstallTest` (#240 `as_unschedule_all_actions` tuple pinned with the library defaults — revert red; subprocess guard halt); rest BE-3 N/A | [x] 2026-09-20 | [x] 2026-09-20 | [x] 2026-08-05 | [x] 2026-08-05 | [x] 2026-09-20 (#240 pinned; T82 closed 2026-09-21 — `boot_refund_lane()` asserted from a real `boot()` both ways, probe-red; T94a replay-gate options attachment probe-red) | [x] 2026-09-20 |
| **Options** (`src/Options/` — Options, Field incl. `$doc` + `choice_sections`) | `Options/OptionsTest` (18), `Options/FieldTest` | [x] | [x] 2026-08-13 (T48a: `to_ui_array()` `'sections'` pass-through unpinned) | [x] 2026-08-13 | [-] | [x] 2026-08-13 | [x] 2026-08-13 |
| **Compat Layer** (`compat/*.php`, `src/Compat/Globals.php`) | `Compat/GlobalsTest` | [x] 2026-07-13 | [x] 2026-07-13 | [x] 2026-07-13 | [-] 2026-08-05 | [-] 2026-08-05 | [x] 2026-07-13 |
| **Migration** (`src/Migration.php`) | `MigrationTest` (11) | [x] | [x] 2026-08-05 | [x] 2026-08-05 | [-] | [-] | [x] 2026-08-05 |
| **Frontend Core** (`src/Frontend/` — DataLayer, ScriptTag (**`json_literal()` public static, 10 callers**), ContainerCode, ConsentDefaults, VisitorIp, **`DefaultLanguage` (2026-09-01, #145)**; Frontend = N/A) | `Frontend/DataLayerTest`, `ScriptTagTest`, `ContainerCodeTest`, `ConsentDefaultsTest`, `VisitorIpTest`, **`DefaultLanguageTest`** (exemplary TS-16 RunInSeparateProcess artifact); `https://` loader scheme (`d262b7b`) pinned ×10 in `ContainerCodeTest` on the `j.src` token — the whole loader is NOT byte-pinned (T96c closed 2026-09-21 — whole-loader contract test against Google's published block, probe-red) | [x] 2026-09-02 | [x] 2026-09-02 | [x] 2026-09-02 (T61/T63 closed — WPML null + empty-default + half-Polylang legs) | [x] 2026-09-20 (#225 noscript esc_attr untestable-red by construction — T65 note; wrong-fix direction pinned by BE-1 raw-`&` tests; loader scheme revert red ×10) | [x] 2026-09-02 | [x] 2026-09-02 |
| **Module Framework** (`src/Module/` — incl. **`DocumentedSchemaInterface`**, BE-3 N/A interface exercised via the doc sweep; **`SiteHealthInfoInterface`** (2026-09-19 `7890b81`), BE-3 N/A interface exercised via `Admin/SiteHealthInfoTest`'s instanceof opt-in + `ReportingThirdParty{Module,Schema}` / `MalformedReportingSchema` fixtures) | `Module/RegistryTest` (5, 6/6 public methods), `Modules/ModuleConsistencyTest` (+ **whole-schema doc sweep**; registry-driven, so EDD entered every sweep automatically with only the deliberate id-list acknowledgment changing — the design works), `ModuleHooksTest` | [x] 2026-09-20 | [x] 2026-09-20 | [x] 2026-09-02 | [-] | [-] | [x] 2026-09-02 |
| **PageVariables Module** (`src/Modules/PageVariables/` — post-meta allow-list + serialized skip + underscore floor, R27) | `Modules/PageVariablesModuleTest` (+ `6c5af9b` PublishPress unresolvable author: `false`-in-array modelled, all four keys asserted **absent**, filter-before-count pinned on the mixed list — removing the filter fatals red), **`PageVariablesAdminSchemaTest`** | [x] | [x] 2026-09-02 | [x] 2026-09-20 (T63 primary-category omission pinned; T64 agreement oracle added; unresolvable-author ×3) | [x] 2026-09-02 (#191 RI-26 floor + #198 guard-order + #194/#195/#197 all pinned discriminating) | [x] 2026-09-02 (T53 closed — #203 non-scalar case, probe-red) | [x] 2026-09-02 |
| **Container Module** (`src/Modules/Container/` — incl. **`HardcodedContainers`**) | `Modules/ContainerRowsTest`, `ContainerAdminSchemaTest`, **`HardcodedContainersTest`** | [x] | [x] 2026-09-02 | [x] 2026-09-02 (#223 `/D` anchors pinned ×4 both directions + wp-config subprocess leg) | [x] 2026-09-02 | [x] 2026-09-02 | [x] 2026-09-02 |
| **WooCommerce Module** (`src/Modules/WooCommerce/` — PurchaseTracking, ProductData, PageDataLayer, ListTracking, StoreApiData, Module, AdminSchema; **`Helpers` is 10 forwarding wrappers since `e58c861`** (#221 — implementation moved to `src/Ecommerce/`); classic widget list tracking **removed** `62fdb33` (#215, dead-test deletion verified remnant-free)) | `Modules/PurchaseTrackingTest`, `ProductDataTest`, `PageDataLayerTest`, `ListTrackingTest`, `HelpersTest` (drives the WC wrappers → pins delegation + shared impl in one pass), `StoreApiDataTest`, `WooCommerceModuleTest` (+ #236 block-context order-received precedence `:237`, red on the old shape; `is_order_received_page` in own setUp), `WooCommerceAdminSchemaTest`; stubs `wc-users-stub`, `wc-shortcode-checkout-stub`, `wc-feature-guard-shim`, `wc-stubs.php` (+ `WC_Order_Refund` deliberately NOT extending `WC_Order`, `get_parent_id` default 0). Run 12: term-name decode (`3384cf9`) pinned at all three sites + string sku + hex sink both directions (`ProductDataTest:1341/1353/1372/695`, `PageDataLayerTest:2284`); line-tax phantom discount (`dc1a061`) ×3 with the fixture corrected to the real key (anti-TS-13 model case); checkout-over-cart + order-received precedence (`6538c9b`) both directions | [x] | [x] 2026-09-20 | [x] 2026-09-20 (T63 closed; T87 closed 2026-09-21 — the classic handle's `inline_store_api_cart_url()` pinned, probe-red) | [x] 2026-09-20 (item_id string cast pinned ×11; #463 PHP producer both directions; decode → sink both directions TC-2; T96b `rest_url` sink `[-]` site-config) | [x] 2026-09-20 (#236 red-on-revert) | [x] 2026-09-20 |
| **EasyDigitalDownloads Module** (`src/Modules/EasyDigitalDownloads/` — **5 files**: Module 178, DownloadData 919, PageDataLayer 644, ListTracking 217, AdminSchema 389; landed 2026-09-01, **row added Run 9**; JS bundle tracked in Frontend JS row) | `Modules/DownloadDataTest`, `EddPageDataLayerTest`, `EddListTrackingTest`, `EasyDigitalDownloadsModuleTest`, `edd-stubs.php` (models `__get`-without-`__isset` — **the docblock's rationale is wrong** (Run 12, verified from EDD 3.7.0 source): `EDD\Database\Base::__isset()` exists and is true for every declared row property, so the stub is *less* capable than the real row — the safe direction, but RI-12 is a PublishPress fact, not an EDD one; T93f → `/upstream-review` row + docblock fix); AdminSchema swept automatically by the registry-driven `ModuleConsistencyTest` | [x] 2026-09-02 | [x] 2026-09-02 | [x] 2026-09-20 (T58/T59/T62 closed; T96a closed 2026-09-21 — EDD decode pinned at the item and at the hex sink) | [x] 2026-09-02 (T52/T57 closed, both probe-red) | [x] 2026-09-02 (#217/#219/#220 exemplary — grant+deny+withholding, red pre-fix; #218 discriminating double-init; #222 guard now pinned, T54) | [x] 2026-09-02 |
| **Ecommerce Shared Services** (`src/Ecommerce/` — **`Helpers`** (722, extracted `e58c861`, all moved bodies verified byte-identical), **`CountryPhoneData`** (generated, moved from WooCommerce `7eba61f`-repointed); **row added Run 9**) | Documented-indirect by design: `HelpersTest` (811 lines, through the WC wrappers — deliberate, pins both layers; **modernizing it to direct `Ecommerce\Helpers` calls would strand the wrappers**), phone corpus (972 cases, verified driving the new namespace), `purchase_dedupe_guard` hostile both-directions at the WC call site (`PageDataLayerTest`) + EDD sites | [x] 2026-09-02 | [x] 2026-09-02 | [x] 2026-09-02 | [x] 2026-09-02 | [x] 2026-09-02 | [x] 2026-09-02 |
| **Google API client** (`src/Google/` — **6 files** (ConsentRegions added in phase 3): KeyVault (clock seam Run 10; herd guard `0b9e969` + `relabel()` `6b39fcc`), ServiceAccountKey, TokenService (`$fresh` → `$force` forwarding `0b9e969`), Transport (interface, BE-3 N/A; its phase-2 methods ARE allow-list-tested), WpTransport (SSRF egress guard; port refusal Run 10). Landed 2026-09-03 `ea7ece7`, **row added Run 10**) | `Google/KeyVaultTest` (herd guard pinned as an **effect** — no `update_option` call — with every must-still-write branch `:531-620`; relabel hostile+cap+unknown-id), `TokenServiceTest` (26, incl. a RunInSeparateProcess sign-failure case), `ServiceAccountKeyTest`, `WpTransportTest` (16-row deny provider ×2 tests + seam), helpers `FakeTransport` (enforces the real allow-list, TS-13), `KeyFileFixture` (real RSA pair per process), `OptionStoreTrait` (core-faithful add/update semantics incl. unchanged-value false), `ConsentRegionsTest` (exact set + count, EU-27 written out independently of the source constant) | [x] 2026-09-20 | [x] 2026-09-20 | [x] 2026-09-20 (T70/T71 closed; openssl no-extension legs NOTE-documented untestable in-process; T90 closed 2026-09-21 — `$fresh`→`$force` pinned as a write, probe-red) | [x] 2026-09-03 (deny-before-socket ×16 shapes ×3 methods; look-alike suffix + port + case rows) | [x] 2026-09-03 (#226/#227/#228 all revert-traced or probe-red; #231 wontfix no guard owed) | [x] 2026-09-03 |
| **GoogleAuth Module** (`src/Modules/GoogleAuth/` — RestController (4 A4 REST routes), KeyNotice, GoogleAuthModule, AdminSchema (first `PanelSchemaInterface` panel); JS `js/admin/components/panels/` ×2. Landed 2026-09-03 `ea7ece7`, **row added Run 10**) | `Modules/GoogleAuthRestControllerTest` (25: grant/deny/filtered-cap + registration-args identity + hostile upload table + store-failure 500), `GoogleAuthCustodyTest` (7, anchored), `GoogleAuthKeyNoticeTest`, **`PluginRestWiringTest`** (the rest_api_init attachment — T66), `ModuleHooksTest` (frontend-absence), `ModuleConsistencyTest` (registry-swept), `UninstallTest` (vault row both ways); JS `service-accounts-panel.test.js` (30) + `module-panel.test.js` custom-panels | [x] 2026-09-03 | [x] 2026-09-03 | [x] 2026-09-03 (T73 closed; load-effect `cancelled` cleanup `[-]` N/A — `key=` remount makes the race unobservable) | [x] 2026-09-03 (custody + hostile label/upload; no HTML sink — REST JSON + esc'd notice) | [x] 2026-09-03 (T66/T68/T74 probe-red) | [x] 2026-09-03 |
| **GoogleDataManager Module** (`src/Modules/GoogleDataManager/` — **25 files** (counting rule `ls src/Modules/GoogleDataManager/*.php`, Run 12): the original **7** (Module, AdminSchema, RestController — now **3 A4 routes**: destinations/test, send-log GET, send-log/replay POST —, DestinationRows, DestinationHealth, EventsIngest, HealthNotice; landed 2026-09-03 `f0b3667` + `cc4b0ac`, R31); **phase 3 / DM2 capture** (+8, `216c687..193daa0`, R32 ledger `9c94bb8`): AttributionCookies, AttributionCapture, CaptureHooks, CaptureStats, BackfillEndpoint (A0 route), ReceiptPage, PrivacyData, ConsentPolicy; **phase 4 / refunds + send lanes** (+10, `879b4b6..b829b85` + R33 fixes `ba57575`): RefundData, RefundEvent, RefundSender, RefundSource, WooCommerceRefunds, EddRefunds, SendQueue, SendLog, StatusPoller, SiteHealth. **Row added Run 11; first full review of phases 3+4 = Run 12 (2026-09-20).** JS panel + SendLogList tracked in the Admin JS row; `gtm4wp-attribution.js` in the Frontend JS row) | `Modules/GoogleDataManagerAdminSchemaTest` (incl. #232 pair + panel/health pins T78), `GoogleDataManagerRestControllerTest` (grant/deny/filtered-cap + registration args T77 + hostile summary T79), `GoogleDataManagerDestinationRowsTest`, `GoogleDataManagerHealthTest`, `GoogleDataManagerHealthNoticeTest`, `PluginRestWiringTest` (route attachment + deletion veto from real `boot()`), `AdminCapabilityFilterTest` (HealthNotice boot attachment, T75), `ModuleHooksTest` (frontend absence, phase-frozen), `ModuleConsistencyTest` (registry-swept), `UninstallTest` (health row both ways); EventsIngest documented-indirect via the RestController suite (`MAX_*` constants BE-3 scaffolding, #233); **Run 12 (DM2 capture)** adds `GoogleDataManagerCaptureTest` (parser as a hostile boundary: 11-row hostile-id provider, per-kind length caps, session/signal/depth caps, double-decode regression), `GoogleDataManagerCaptureHooksTest` (both platforms mirrored, callback identity, save() counted, capture-rate counters + non-autoload pin), `GoogleDataManagerBackfillTest` (the guest-facing route: registration args incl. permission-callback identity, origin/nonce grant+deny, per-platform purchase proof, write-only-if-absent incl. array-valued fields, consent filter + server-side gate, uniform-refusal pin), `GoogleDataManagerReceiptPageTest` (flag only when needed; query-var branch; EDD session-branch withheld), `GoogleDataManagerPrivacyTest` (export/erase both platforms, erasure proved by a second export, no foreign key touched), `GoogleDataManagerConsentPolicyTest` (17-row policy matrix, both halves of the answer), `GoogleDataManagerModuleTest` (enqueue + wiring + hostile value through the real inline-script sink), `PluginRestWiringTest` (backfill route both directions of its option gate; privacy boot attachment); JS `attribution-tracker.test.js` (35 → consent-engine read `308235c`, push re-hook `0f01a57`, engine-outranks-push pin `3defc68`). **Phase 4 (Run 12)** adds `GoogleDataManagerRefundEventTest` (one-shape refunds `348a7db`, shipping+tax `f3e5faf`), `GoogleDataManagerWooRefundsTest` (#242 parent mismatch, discount/affiliation `b829b85`, variation, whole-order slice), `GoogleDataManagerEddRefundsTest` (type/parent refusal, whole-order slice, date fallback, tax), `GoogleDataManagerRefundSenderTest` (targeted replay bypasses the sent marker; site-wide fault ahead of per-order `2ee5780`; consent-caused missing id `9da9bc4`), `GoogleDataManagerSendQueueTest` (both legs, GROUP + hook-name pair pinned), `GoogleDataManagerSendLogTest` (#244 both directions; allow-list; eviction keeps problems), `GoogleDataManagerStatusPollerTest` (terminal states, 24 h window both edges), `GoogleDataManagerIngestSendTest` (per-chunk outcomes; hostile status name cannot become a reason class), `GoogleDataManagerSiteHealthTest` (no email/key/property/raw error in the section), `GoogleDataManagerRestControllerTest` (rung 1+2 for all **three** routes; replay of a settled failure `0fa3036`; hostile Google error text), `UninstallTest` (#240 tuple), harness `gdm-platform-guard-shim.php` (TS-13-conscious: delegates unless forced) | [x] 2026-09-20 | [x] 2026-09-20 (T94b/c: `is_active()` both adapters, `register_hooks()` inactive skip, `consent_policy_choices()`/`type_choices()` unpinned) | [x] 2026-09-20 (T89/T91/T92/T93/T94 closed 2026-09-21 — mixed-outcome retry (two accounts) probe-red; wrong-kind refund object both ways; EDD variant + affiliation parity; slice bound on cookie + route; creation-path filter arg; every residue item) | [x] 2026-09-20 (cookie parser + backfill body hostile boundary; inline-script sink TAG/AMP/APOS discriminated (QUOT T93b); `record_status()` both directions; T81 closed 2026-09-21 — the order-creation consent gate pinned ×4, probe-red) | [x] 2026-09-20 (#240/#242/#243/#244/#249/#250 each revert-traced red; #245 non-issue; #232 pair unchanged) | [x] 2026-09-21 (T84/T85 harness defects closed — `BackfillTest` green by path, `CaptureHooksTest` green alone + seeds 4/5; T94f delays bracketed) |
| **ConsentMode Module** (`src/Modules/ConsentMode/` — incl. Axeptio handler, CookieYes bridge) | `Modules/ConsentModeAxeptioTest`, `ConsentModeCookieYesTest`, `ModuleHooksTest` (gate + webtoffee + axeptio + cookieyes wiring) | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 (T40 closed same session) | [x] 2026-08-13 | [x] 2026-08-13 |
| **UserEvents Module** (`src/Modules/UserEvents/` — incl. form-fill `gtm.element*` keys + "filled in only" sub-option) | `Modules/UserEventsModuleTest` (exact printed literal incl. the load-bearing `var`, boolean cast, 1.x-upgrade default), `ModuleHooksTest`; JS: `form-move-tracker` + **`form-move-tracker-filled-only`** realms | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 (T50 closed same session) | [x] 2026-08-13 | [-] | [x] 2026-08-13 |
| **MediaEvents Module** (`src/Modules/MediaEvents/` — 12 trackers + consent gate + shared observer lib) | `Modules/MediaEventsModuleTest` (35 tests: gates, consent gate/expectation matrix, oEmbed splice sinks), **`MediaEventsAdminSchemaTest`** (phase map), `ModuleHooksTest`, `ModuleConsistencyTest`; JS: 14 tracker tests + `native-video-params` (**`media-gate.test.js`** covers the gate file itself, T41) | [x] 2026-08-14 | [x] 2026-08-14 (per-tracker flag rewrite exemplary: exact handle arrays + `->once()` memoization pin) | [x] 2026-08-14 | [x] 2026-08-14 (was `[-]`; #112 origin splice + Dailymotion hex/path sinks now exist and are guarded) | [x] 2026-08-14 | [x] 2026-08-14 |
| **ContactForm7 Module** (`src/Modules/ContactForm7/` — incl. tracker JS; GA4 `form_status` + submit-before-lead ordering, 2026-08; master-language title override, 2026-09) | `Modules/ContactForm7ModuleTest` (master-language on + off-gate with different-master-value fixtures), `ModuleHooksTest` (gate); JS: `contact-form-7-tracker` (order-as-effect test) | [x] 2026-09-02 | [x] 2026-09-02 | [x] 2026-09-02 (T63's optional master-path TS-11 leg is Low) | [x] 2026-09-02 | [x] 2026-09-02 | [x] 2026-09-02 |
| **Blacklist Module** (`src/Modules/Blacklist/` — incl. `sandboxedScripts` group class) | `Modules/BlacklistModuleTest` (#121/#122 absence-under-every-name guards), **`BlacklistAdminSchemaTest`** (structure + behavioral sanitizer cases, T45) | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 (T45 closed same session) | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 |
| **ClientDeviceData Module** (`src/Modules/ClientDeviceData/`; JS tracker) | `Modules/ClientDeviceDataModuleTest`, `ModuleHooksTest` (gate); JS: `client-device-data-tracker` | [x] 2026-07-13 | [x] 2026-07-14 | [x] 2026-07-14 | [-] | [x] 2026-07-14 | [x] 2026-07-14 |
| **VisitorData Module** (`src/Modules/VisitorData/` — `VisitorDataEndpoint` **public** session route, `VisitorField`, `AdminSchema`; JS: `gtm4wp-visitor-data.js`) | `Modules/VisitorDataEndpointTest`, `VisitorDataModuleTest` (event-name map: constants + literal contract pinned separately), `ModuleHooksTest`; JS: `visitor-data-tracker` | [x] | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 |
| **AMP Module** (`src/Modules/Amp/` — amp-wp 2.x `amp_analytics_entries` sink) | `Modules/AmpModuleTest`, `ModuleHooksTest` (gate) | [x] | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 | [x] 2026-07-14 |
| **Admin — Notices/AJAX** (`src/Admin/Notices.php`) | `Admin/NoticesTest` (#110 encoder-discriminating nonce guard; #131 core-faithful `sanitize_key` pair; invalid-dataLayer-name notice grant+deny) | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 |
| **Admin — Settings UI** (`src/Admin/SettingsPage.php` — boot-fallback notice `232fea6`, `Admin.php`, `RestController.php`, `PluginRow.php`, **`Docs.php` (2026-08-11)**, **`SiteHealthInfo.php`** (2026-09-19 `7890b81`, the plugin-wide Site Health Info section — core `esc_html`s every value, so the collector must NOT pre-escape)) | `Admin/RestControllerTest`, `SettingsPageTest` (+ columnChoices merge both directions, Run 11), `PluginRowTest`, `AdminCapabilityFilterTest` (+ KeyNotice AND HealthNotice boot attachments — T74/T75, both probe-red), **`DocsTest`** (209 lines: allow-list `->with`, #152/#158 both-directions) + fixtures `UndocumentedThirdParty{Module,Schema}`, **`SiteHealthInfoTest`** (10: instanceof opt-in without fatal, non-array row dropped, junk filter ignored, prefixed keys as written, no empty heading; own stubs, green alone) + fixtures `ReportingThirdParty{Module,Schema}`, `MalformedReportingSchema` | [x] 2026-09-20 | [x] 2026-09-20 | [x] 2026-09-20 (T60 closed — byte-exact containment, probe-red) | [x] 2026-09-20 (T95a closed 2026-09-21 — raw `& " < '` row through the collector with the real escaper stubbed, probe-red) | [x] 2026-09-21 (T75 closed 09-03; **T83 closed** — the third recurrence of the attachment lesson in `Admin::boot()`, both Site Health wirings now asserted grant + deny, probe-red) | [x] 2026-09-20 |
| **Module Admin Schemas** (`src/Modules/*/AdminSchema.php` — custom sanitizers; doc-link additions across all schemas 2026-08) | `Modules/ModuleConsistencyTest` (non-scalar sweep + doc sweep), `ContainerAdminSchemaTest`, `WooCommerceAdminSchemaTest`, `PageVariablesAdminSchemaTest`, **`MediaEventsAdminSchemaTest`**, **`BlacklistAdminSchemaTest`** (sanitizers behaviorally tested since T45) | [x] | [x] 2026-08-13 | [x] 2026-08-13 | [x] 2026-08-13 (T45 closed same session) | [-] | [x] 2026-08-13 |
| **Frontend JS** (`js/frontend/` — **22** bundles (+`gtm4wp-attribution.js`, phase 3) + **3** libs: `lib/native-video-params`, `lib/blocks-cart-diff`, `lib/gtm4wp-cookies`; counting rule `ls js/frontend/*.js`, Run 12) | **28** `js/frontend/test/*.test.js` (+`attribution-tracker.test.js`, 35); every bundle covered. **Strict-mode flip measured Run 12 (TS-17 v3 "it can flip under you", for real):** `grep -c '"use strict"' build/gtm4wp-*.js` → woocommerce **1** (was 0 at R26 — `c5499f9`'s `import { gtm4wp_parse_block_item }` did it), woocommerce-blocks 1, attribution 1, **edd 0**; the three `not.toThrow` sites in the WC suite each pair with an absence-of-push assertion, so they stay valid — re-measure on every run | [x] 2026-09-20 | [x] 2026-09-20 (T62 closed — API grant/deny; Interactivity API add_to_cart TC-17 fixture pair `c0144db`/`c5499f9`, held-until-confirm both directions) | [x] 2026-09-20 (T55/T62 closed; T88 closed 2026-09-21 — coalescing pinned as one-read-in-flight (probe-red on the second draft), lost/refused/malformed legs (the malformed case surfaced + fixed a whole-cart phantom removal); T95e classic fetch error legs `it.each`) | [x] 2026-09-20 (#463 discriminating cart/checkout split; consent-engine stand-in models defined-but-`undefined` `update`; TC-11 applies) | [x] 2026-09-20 (T54 closed — #222 pinned; #238/#239 open design limitations, no guard owed) | [x] 2026-09-20 (T56 closed; `google_tag_data` reset per test; window-listener capture harness in the blocks suite) |
| **Admin JS** (`js/admin/` — **15 files** (counting rule `find js/admin -name '*.js' -not -path '*/test*'`, Run 12): `App.js` (unsaved-changes guard `8adbc1c`), `utils.js`, `index.js` + **8 in `components/`** incl. `MobileNav.js` + `DocLink.js` + `TableControl.js` (delete confirm `0238eb5`) + `FieldControl.js` (greyed-setting note `c4ec62f`/`3c75f60`) + **4 in `components/panels/`** (ServiceAccountsPanel + DestinationsPanel + **SendLogList (phase 4)** + registry)) | **12** test files (+`send-log-list.test.js`); `index.js` `[-]` N/A (3-line mount); `panels/index.js` covered via `module-panel.test.js` custom-panels incl. the two-entry discrimination + DOM-order pin; `DocLink.js` `[-]` N/A documented-indirect | [x] 2026-09-20 | [x] 2026-09-20 | [x] 2026-09-20 (T80 closed; SendLogList load error / malformed envelope / hideWhenEmpty ×4 / replay counts refunds not rows; TableControl deny path as effect; App guard as effect both ways; T95b/c/f residue) | [x] 2026-09-20 (no HTML sink in SendLogList — structured React, `toneClass` whitelisted; Notice stand-in honours `isDismissible`/`onRemove`, no accept-and-ignore prop added) | [x] 2026-09-21 (#234 unchanged; T86 closed — `panel_data()` send-log keys pinned with the TS-19 litmus) | [x] 2026-09-20 (…Once + `mockClear` in the new suite; T95g closed 2026-09-21 — `mockClear` + `…Once` file-wide) |

> **Coverage note (Run 12, 2026-09-20):** post-Data-Manager-phase-3+4 pass over
> `75c942d..b085e0e` — **80 commits**, +6 709 production / +17 003 test lines, 26 new
> `src/` files (17 in `GoogleDataManager/`), 20 new PHP test files, 2 new JS suites,
> security reviews 32–33 (#235–#250). **Reviewed at: `b085e0e`.** Baseline **PHP
> 2852/7809** (declaration + 3 seeds 1789930391/407/422, identical), **JS 40/961**,
> coverage **96.40% lines (8624/8946)**, methods 81.58% (PCOV). Report-only pass; **no
> tests written this run.** Inventory: no invisible component — the phase-3 ledger
> commit `9c94bb8` (2026-09-04) had pre-added the GDM/`RequestOrigin`/`ConsentRegions`
> rows, labelled "Run 12" although no run happened (this is the actual Run 12); labels
> reconciled (GDM 25 files, Frontend JS 22+3/28, Admin JS 15/12, `SiteHealthInfo` +
> its interface added). 4 parallel read-only deep-reads. **1 High (T81), 11 Medium
> (T82–T92), 4 Low bundles (T93–T96); 1 latent production bug candidate routed to
> `/code-review` (an over-reported block `add_to_cart` — a stale held-back event
> released by an unrelated confirmation); 2 harness defects MEASURED in the main
> thread** (T84: `GoogleDataManagerBackfillTest` errors 4× when run by path — never
> requires `edd-stubs.php`, invisible to every random seed because PHPUnit loads all
> files first; T85: a CaptureHooks test fails alone and under seeds 4/5 — an in-file
> `function_exists()` dependency). Theme: **the guard is fully tested, its attachment
> or its sibling is not** — the T75 attachment lesson recurred a THIRD time in the same
> `Admin::boot()` (T83) and a fourth one hop up in `Plugin::boot_refund_lane()` (T82,
> found by two deep-reads independently); a consent gate at order creation is
> deletable green on both platforms (T81); a TS-19 boundary declaration (T86) and a
> classic-handle inline script (T87) revert green. The R33 hand-over's six
> collaterals: 2 covered by `ba57575` (#240 tuple, #244 both directions), 1 covered
> elsewhere with a TS-14-shaped side effect (#249), 3 real Low-Med (T91a, T92 ×2).
> **One upstream claim settled from source** (EDD 3.7.0 `Database\Base::__isset()`
> exists — the `edd-stubs.php` docblock's rationale is wrong, the DM2 `?? 0` reads are
> right; → `/upstream-review`). **Patterns:** new **TS-20** (a recording double that
> drops a parameter the collaborator branches on — the #240 mechanism, swept clean
> elsewhere), **TS-16 corollary** (run each changed test file by path, alone, plus
> seeds), **TS-11 corollary** (a decode between source and sink narrows a
> raw-passthrough pin). Strict-mode flip measured: `build/gtm4wp-woocommerce.js` now
> carries `"use strict"` (TS-17 v3 clause, for real).
>
> **Closes note (Run 12, 2026-09-21):** T81–T96 all closed the same session on the
> user's "fix straightforward, ask with options otherwise" go-ahead (four forks
> answered up front: fix the over-report now; one `save()` per erased order; decode
> the PageVariables term names too; write the whole-loader pin). **PHP 2852 → 2893 /
> 7809 → 7956; JS 40 suites, 961 → 974**; green in declaration order **and 4 random
> seeds** at identical counts; `phpcs` exit 0 repo-wide (one auto-fixable array
> layout caught); `lint:js` clean (two prettier findings fixed with the project
> fixer); `npm run build` rebuilt (frontend production JS changed; `build/admin.js`
> byte-identical). **14 revert probes run and red** (T82, T83, T87, T89, T90, T91a,
> T91b, T94a, T95a, T96c, the over-report fix stashed, the malformed-body guard,
> T88 — after its first draft was found NOT to discriminate). Two harness defects
> measured fixed (T84 by path, T85 alone + seeds 4/5). **Production changes (4)**:
> `PrivacyData::erase()` one write per order; `PageVariablesModule` decodes
> `pagePostTerms`/`pagePrimaryCategoryName` through the shared helper (2.1 `Changed:`
> bullet); `gtm4wp-woocommerce.js` list add supersedes the held-back block add
> (2.0.3 `Fixed:`); `gtm4wp-woocommerce-blocks.js` treats a non-cart body as no
> reading (2.0.3 `Fixed:` — **the latent defect the T88 malformed case surfaced**,
> the T55/T80 class a third time: writing the error-leg test is what found it). The
> two 2.0.3 fixes are split into their own commit for the `2.0` branch.
>
> **Coverage note (Run 11, 2026-09-03):** post-Data-Manager-phase-2 pass over
> `99ee59a..75c942d` — 4 commits, dominated by the **GoogleDataManager module**
> (`f0b3667` + hardening `cc4b0ac`, security review 31, findings #232–#234, all Low).
> **Reviewed at: `75c942d`.** Baseline **PHP 2394/6539** (declaration + 3 seeds,
> identical), **JS 38/807**, coverage **96.38% lines** (PCOV). The inventory step
> earned its keep a **fifth** time: `src/Modules/GoogleDataManager/` and
> `DestinationsPanel.js` had no row — added. 3 parallel deep-reads; verdict: second
> consecutive greenfield module landing **with its pins** (TS-12 three-rung ladder
> unprompted, #232/#234 revert-traced, FakeTransport TS-13 by construction) — the
> gaps are fine-grain TS-18 residue: 2 Medium (T75 — the T74/KeyNotice attachment
> lesson recurring for HealthNotice in the same range; T76 — the sibling's busy-guard
> test not ported), 4 Low (T77–T80). No High.
>
> **Closes note (Run 11, 2026-09-03):** all closed the same session on the user's
> "fill straightforward, ask on forks" go-ahead (no fork arose — every gap had one
> obvious implementation; null-clock legs recorded `[-]` N/A per the Run 10 fork
> answer). **PHP 2394 → 2397 / 6539 → 6552; JS 38 suites, 807 → 812**; green in
> declaration order **and 3 randomized seeds** at identical counts; `phpcs` exit 0;
> `lint:js` clean; `npm run build` rebuilt (production JS changed). **3 revert probes
> red**: T75 (deleted `Admin.php:49` → the new attachment assertion), T76 (both
> busy-guard layers neutralized → 2 calls where 1 expected), T80g (the locked-select
> re-check — the existing test discriminates, closing the missing-probe note).
> **Writing T80's malformed-response case surfaced a LATENT PANEL CRASH** (the
> T17/T3 class): `response.ok` on a malformed reply threw inside the `setResults`
> updater — during React's render, past the `try/catch` — unmounting the whole
> destinations panel; the deep-read had predicted it would land in the catch, so
> only the executed probe caught it. **Production change (1)**: `DestinationsPanel`
> builds the verdict before `setResults` (malformed response = failure verdict with
> fallback text; the new test was red pre-fix by construction); changelog = the GDM
> bullet's Test-button sentence refined in both files (same-version dev repair, no
> `Fixed:` bullet per policy).
>
> **Coverage note (Run 10, 2026-09-03):** post-google-auth pass over `1c1164f..99ee59a`
> — 6 commits, dominated by the **google-auth module** (`ea7ece7` + hardening `16f67c0`,
> security review 30, findings #226–#231, all Low). **Reviewed at: `99ee59a`.** Baseline
> **PHP 2296/6207** (declaration + 3 random seeds, identical counts), **JS 37/780**,
> coverage **95.25% lines** (PCOV). The inventory step earned its keep a **fourth** time:
> `src/Google/` and `src/Modules/GoogleAuth/` had no row, and the Admin JS row could not
> see `components/panels/` — all added. 5 parallel deep-reads; overall verdict: **the
> strongest new-module suites this system has reviewed** (real-crypto custody tests,
> registration-args pinning applied unprompted per the T39 lesson, deny-before-socket
> SSRF matrix). 4 Medium (T66–T69), 5 Low bundles (T70–T74); no latent production bug.
> Headline: the **T39/#143 attachment lesson recurred one hop up** — the `rest_api_init`
> closure in `Plugin::boot()` was executed by no test, so deleting the google-route
> registration (and the settings one) stayed green (T66, probe-verified). One production
> observation routed to `/code-review` at close time as a fix: the transport accepted an
> explicit port on an allowed host (T67).
>
> **Closes note (Run 10, 2026-09-03):** all gaps closed the same session on the user's
> "close all, ask on forks" go-ahead (4 forks answered: **refuse ports** in production;
> **RunInSeparateProcess** sign-failure test; **inject a clock** into KeyVault; **handler
> re-check + test** for the JS busy guard). **PHP 2296 → 2313 / 6207 → 6299; JS 37
> suites, 780 → 787**; green in declaration order **and 4 randomized seeds** at identical
> counts; `phpcs` exit 0; `lint:js` clean; `npm run build` rebuilt (production JS
> changed). **9 revert probes run and red**: T66 (deleted google registration), T67 (port
> row red pre-fix), T68 (dropped WP_Error propagation → the predicted TypeError), the F2
> status-check mutant, the F3 cache-guard revert, the G10-2 KeyNotice attachment, the
> G10-3 hook-added probe, the JS prototype-key panel fallback, and the JS busy-guard
> double probe — which showed the deferred-promise test pins the **disabled-prop layer**
> (jsdom never delivers clicks to disabled buttons), so the new handler-level re-check is
> a **documented in-harness blind spot** (TS-17 note), guarded by ESLint-free code review
> only. **Production changes (3)**: WpTransport port refusal, KeyVault injectable clock
> (timestamps now exact-asserted), ServiceAccountsPanel handler-level busy re-checks
> (TableControl pattern); changelog = one sentence added to the unreleased google-auth
> bullet in both files. Test-support: `WP_Error` stub gained core-faithful `$data`/
> `get_error_data()`; `OptionStoreTrait::update_option` now returns core's
> false-on-unchanged; `FakeTransport` now throws on a URL the real transport would refuse
> (TS-13 by construction, `wp_parse_url` stubbed in both consumer setUps).
>
> **Coverage note (Run 9, 2026-09-02):** post-2.0.0 cumulative pass over
> `65c9670..1c1164f` — **45 commits** (EDD module + master-language #145 merge,
> +5 267 production / +4 456 test lines, security reviews 25–29 / findings
> #191–#225). **Reviewed at: `1c1164f`.** Baseline **PHP 2120/5333** (declaration
> order + 3 random seeds, identical counts) and **JS 36 suites / 746**, all green.
> Report-only pass; **no tests written this run.** The inventory step earned its keep
> a third time: `src/Ecommerce/` and `src/Modules/EasyDigitalDownloads/` (+
> `gtm4wp-edd.js`, `DefaultLanguage`) had **no row** — invisible, not unreviewed —
> now added. 5 parallel deep-reads; 1 High (T52), 9 Medium (T53–T61), 4 Low
> bundles (T62–T65); **no latent production bug**. Theme: **the port carried the
> guards but not the pins** (TS-18) — four EDD protections inherited from
> WooCommerce lack the discriminating tests their WC siblings have — plus two
> `fixed` security findings (#203, #222) with no live guard. ⚠️ **TS-15 revert
> probes were blocked by the permission classifier this run** (running phpunit over
> a guard-reverted tree was denied twice); the four revertible-green claims rest on
> static traces naming the exact surviving assertions, and each close must be
> watched red-first against the reverted source to supply the missing probe
> evidence. Two production observations routed to `/code-review` (the regenerated
> `CountryPhoneData` mojibake line; the overstated "kept LAST" comments). Compat
> Layer delta is 29 new constants only (no test-side change owed). Rows updated:
> Frontend Core, Container, WooCommerce, **EasyDigitalDownloads (new)**,
> **Ecommerce Shared Services (new)**, PageVariables, ContactForm7, Module
> Framework, Admin Settings UI, Frontend JS.
>
> **Closes note (Run 9, 2026-09-02):** T52–T65 all closed the same session on the
> user's "close straightforward, ask about the rest" go-ahead (the four judgment
> calls — the mixed-identity variation pin, the primary-category omission pin, the
> `CountryPhoneData` mojibake fix, PCOV+Infection — were answered explicitly).
> **PHP 2120 → 2143 tests / 5333 → 5419 assertions; JS 36 suites, 746 → 757 tests**
> (`edd-tracker.test.js` 15 → 28); green in declaration order **and 4 randomized
> seeds** at identical counts; `phpcs` exit 0 repo-wide; `lint:js` clean (fixer:
> `npx wp-scripts lint-js js --fix`, never bare prettier); no `build/` change owed
> (frontend test files are not bundled). **TS-15 as the acceptance criterion: 9
> revert probes run and red** — T52 (esc_attr removal), T53 (bare cast), T54
> (`\|\| ''` swap), T55 (grid parse bail), T58 (version clause), T60 (notice moved
> outside), T61 (WPML numeric guard), #218 (guard neutralized), plus the TC-14
> null-post guard — the last one catching a wrong first draft (the test passed for
> the wrong routing; `is_singular` had to become argument-sensitive before the
> probe went red — the "probe tests the test" lesson again). The report-stage
> classifier block on probes did NOT recur at close time: probes with a
> discriminating test present ran normally, so the "watched red-first" protocol
> held for every close. **Production code changed only trivially**: the
> `CountryPhoneData.php` line-11 re-encode (O1, byte-parity with the pre-move file
> restored) — comment-only, `[skip changelog]`. Tooling: **PCOV working + first
> coverage baseline (Lines 95.21%)**; **Infection installed but non-functional on
> this PHP 8.4.15 ZTS Windows build** (silent exit during mutant generation —
> documented in `infection.json5`; re-test on NTS/Linux or after version bumps).
>
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
| **Test-order independence** (`vendor/bin/phpunit --order-by=random` — TS-8/TS-16) | 2026-09-21 (Run 12 + closes) | ✅ **Clean after the closes — declaration + 4 seeds green at 2893/7956, identical counts; T84/T85 fixed and re-measured by path / alone + seeds 4/5.** At report stage: ⚠️ Whole-suite clean — declaration + 3 seeds green at 2852/7809 — but two FILE-LEVEL dependencies the shuffle cannot see (T84, T85, both measured):** `GoogleDataManagerBackfillTest` never requires `edd-stubs.php` and errors 4× when run by path (PHPUnit loads every file before running any, so every seed is green); a `CaptureHooksTest` case depends on an earlier test in the same file defining `edd_update_order_meta` (fails alone; fails under seeds 4/5). New TS-16 corollary: run each changed test file **by path, alone**, in declaration order and under a few seeds. |
| ~~Test-order independence (Run 11 + closes)~~ | 2026-09-03 (Run 11 + closes) | ✅ **Clean — declaration + 3 random seeds green at 2397/6552 after the closes** (2394/6539 + 3 seeds at report stage), identical counts. The new GDM suites stub `wp_parse_url` and their own store in-file with TS-16 comments; no new hazard in range. |
| ~~Test-order independence (Run 10 + closes)~~ | 2026-09-03 | ✅ **Clean — declaration + 4 random seeds green at 2313/6299 after the closes** (2296/6207 + 3 seeds at report stage), identical counts. The two hazards this range could have introduced were both pre-empted: the sign-failure stub runs `RunInSeparateProcess` (a namespaced `openssl_sign` shadow would otherwise poison every later signing test), and `FakeTransport`'s new allow-list check has `wp_parse_url` stubbed in both consumer files' own setUp. |
| ~~Test-order independence (Run 9 + closes)~~ | 2026-09-02 | ✅ **Clean — declaration order + 4 random seeds all green at 2143/5419 after the closes** (2120/5333 + 3 seeds at report stage), identical counts. The two "kept LAST" comments that overstated their protection (O2) were rewritten to state the real defenses. |
| ~~Test-order independence (Run 8)~~ | 2026-08-14 | ✅ Clean — declaration order + 3 random seeds all green at 2002/4686, identical assertion counts. The four fix commits in range each recorded their own randomized runs too. |
| ~~Test-order independence (Run 7 + closes)~~ | 2026-08-13 | ✅ **Clean — declaration order + 4 random seeds all green at 1996/4669 after the closes** (1965/4601 at report stage, 3 seeds), identical assertion counts. The suite grew 767 → 1965 since Run 6 (security fix sessions ship their tests, TC-1) and stayed order-independent throughout — the security reviews' own randomized runs (R21–R24 close-outs) corroborate. |
| ~~Test-order independence (Run 6 + closes)~~ | 2026-08-05 | ✅ Clean — 5 consecutive random seeds green at 767/2488, the same assertion count as declaration order. Was 13 errors when the sweep was first run this session (T35, now closed): 12 on `wp_get_environment_type`, 1 on `get_multiple_authors`, three of them security regression guards, all passing only because another file had defined the function process-wide. Run this **3–5 times** every review — one green seed proves nothing. |
| ~~Test-order independence (Run 6, first run)~~ | 2026-08-05 (Run 6) | ⚠️ **13 errors** (seed 1785923095). 12 on `wp_get_environment_type`, 1 on `get_multiple_authors`: the tests never stub them and pass only because another file defines them process-wide (Brain Monkey makes `function_exists()` sticky). **Three of the 13 are security regression guards** (settings-import hostile payload, hostile container ID, the T13/#11 admin hex sink). Each file still passes *alone*, so it is within-file test ordering. Nothing silently passes — they error — but the suite's green is contingent on declaration order and randomized order cannot be adopted. Gap **H-1**. |
| **Missing test file** (`src/**/*.php` with no dedicated or documented-indirect test) | 2026-09-20 (Run 12) | **Clean — no real zero-test class.** 56 raw hits over **104** files (was 36/82). The 20 new hits classify: 17 GDM basename over-reports (dedicated `GoogleDataManager*Test` suites; `RefundData`/`RefundSource` via `RefundEventTest` + the adapter suites), `SiteHealthInfoInterface` BE-3 N/A (exercised via `SiteHealthInfoTest`'s instanceof opt-in), `RequestOrigin` documented-indirect (real class under 20 `PageDataLayerTest` permission cases + `BackfillTest` both directions), `ConsentRegions` → `Google/ConsentRegionsTest`. |
| ~~Missing test file (Run 11)~~ | 2026-09-03 (Run 11) | **Clean — no real zero-test class.** 36 raw hits over **82** files. The 7 new hits classify: 5 GDM basename over-reports (dedicated `GoogleDataManager*Test` suites), `EventsIngest` documented-indirect (all 4 methods discriminating-asserted via `GoogleDataManagerRestControllerTest`; `MAX_*` constants BE-3 — #233 phase-3 scaffolding), `GoogleDataManagerModule` via `ModuleHooksTest` + registry sweep, `PanelSchemaInterface` BE-3 N/A (interface, doc-only change in range). |
| ~~Missing test file (Run 10)~~ | 2026-09-03 | **Clean — no real zero-test class.** 30 raw hits over **75** files. The 5 new hits classify: `Google/Transport` (interface; its default methods ARE exercised — `WpTransportTest` drives all three through the seam), `Module/PanelSchemaInterface` (BE-3 N/A; the instanceof routing is custody-tested both directions), `GoogleAuth/AdminSchema` (registry-driven `ModuleConsistencyTest`), `GoogleAuth/GoogleAuthModule` (`ModuleHooksTest` absence case + `ModuleConsistencyTest`), `GoogleAuth/KeyNotice` (basename over-report — `GoogleAuthKeyNoticeTest` exists). Beware: `GoogleAuth/RestController.php` false-matches `Admin/RestControllerTest` by basename — it has its own `GoogleAuthRestControllerTest`. |
| ~~Missing test file (Run 9)~~ | 2026-09-02 | **Clean — no real zero-test class.** 25 raw hits over **65** files. New hits classify: `Ecommerce/CountryPhoneData` (moved — documented-indirect via the phone corpus, verified driving the new namespace), `EasyDigitalDownloads/AdminSchema` (basename over-report — registry-driven `ModuleConsistencyTest` sweeps it), `Ecommerce/Helpers` (documented-indirect via `HelpersTest` through the WC wrappers — deliberate, pins both layers; fragility noted in its row). JS: `gtm4wp-edd.js` has `edd-tracker.test.js`. |
| ~~Missing test file (Run 8)~~ | 2026-08-14 | Clean. 24 raw hits over 58 files — the identical, previously-classified set; no new src or JS file in range. |
| ~~Missing test file (Run 7)~~ | 2026-08-13 | **PHP clean — no real zero-test class.** 24 raw hits over **58** files; the two new hits classify: `DocumentedSchemaInterface` BE-3 N/A; `CountryPhoneData` **documented-indirect** (all 245 territories exercised by the 972-case phone corpus, generator fatal-validation, monthly CI drift diff — a dedicated shape test would duplicate those). `Admin/Docs.php` has a dedicated 209-line test. **JS: the new 20th bundle `gtm4wp-media-gate.js` was the one uncovered file → T41 (probe-verified: emptying it left 713 JS tests green), closed the same session by `media-gate.test.js` — the probe now fails 2 of its 3 cases.** |
| ~~Missing test file (Run 6 + closes)~~ | 2026-08-05 | **Clean — no real zero-test PHP class.** `uninstall.php` (outside `src/`, so the sweep never saw it) gained `UninstallTest` this session (T38). 22 raw hits over **55** files (Run 5: 23/56; the delta is `UserEventsModule.php` gaining T29's test). All 22 classify as BE-3 N/A (`Plugin`, `Autoloader`, `Admin/Admin`, `Frontend/Frontend`, the 3 `Module/` contracts) or basename over-reports (10 `AdminSchema.php`, `ConsentMode/*`, `Container/ContainerModule`, `VisitorData/VisitorField`). **The sweep is PHP-only and missed `js/admin/components/` entirely** (H-2) — the JS side needs its own recursive inventory. |
| ~~Missing test file (Run 5)~~ | 2026-08-05 (Run 5 + closes) | 23 raw hits over 56 PHP files, one real gap — `UserEvents/UserEventsModule.php` (T29), **closed the same session** by `UserEventsModuleTest`. **Zero real zero-test classes remain.** Everything else classified: BE-3 N/A (`Plugin`, `Autoloader`, `Admin/Admin`, `Frontend/Frontend`, the 3 `Module/` contracts) or indirectly covered (all 10 `AdminSchema.php` via `ModuleConsistencyTest` + 3 dedicated schema tests; `ConsentMode/*`, `Container/ContainerModule`, `VisitorData/VisitorField` — basename mismatches the sweep over-reports). |
| ~~Missing test file (Run 4)~~ | 2026-07-15 (Run 4) | No real zero-test class in the WooCommerce/consent batch — every new class ships a test (`StoreApiDataTest`, `WooCommerceModuleTest`, `WooCommerceAdminSchemaTest`, `ProductDataTest`, `PageDataLayerTest`, `ConsentModeCookieYesTest`). Sweep over-reports on basename mismatches (`WooCommerceAdminSchemaTest`≠`AdminSchema`, `ConsentModeCookieYesTest`≠`ConsentModeModule`). Remaining PHP BE-3 N/A unchanged (`Plugin`, `Frontend`, `Autoloader`, `Admin/Admin`, module orchestrators, interfaces). |
| **Untested public methods** (in classes that *do* have a test file — TS-10) | 2026-09-20 (Run 12) | 16 grep misses across the new classes; 13 reached indirectly (`parse_*` via the route/cookie tests, `replay_*`/`is_replayable`/`latest_is_accepted` via the replay suite, `is_same_origin_request`/`has_rest_nonce` via both routes, `clear_failures`, `validate_destination`, `script_config`). **Real, Low (T94b/c):** `SendQueue::is_active()` on both adapters, `RefundSender::register_hooks()`'s inactive-source skip + enqueue-closure platform binding, `AdminSchema::consent_policy_choices()`/`type_choices()`. Coverage corroborates: SendQueue 93.94% (cron else-leg), EddRefunds 93.18% / WooCommerceRefunds 91.86% (T91/T94h legs). |
| ~~Untested public methods (Run 11 + closes)~~ | 2026-09-03 (Run 11 + closes) | ✅ **Clean after the same-session closes** — the two uncovered methods the coverage report corroborated are pinned: `AdminSchema::panel()` (T78, the PHP↔JS registry-key drift guard) and the HealthNotice boot attachment (T75, probe-red). Null-clock fallback legs recorded `[-]` N/A. |
| ~~Untested public methods (Run 10 + closes)~~ | 2026-09-03 | ✅ **Clean after the same-session closes** — every public method of the 8 new google classes has an executing test (the deep-reads enumerated them). The report-stage branch hits (sign-failure leg, corrupt-cache leg, scope-dedupe, store-failure 500) are all closed (T68/T70/T71) or NOTE-documented untestable (openssl no-extension legs). |
| ~~Untested public methods (Run 9 + closes)~~ | 2026-09-02 | ✅ **Clean after the same-session closes** — the JS theme API `gtm4wp_edd_track_add_to_cart` (T62, grant + null/disabled deny) and the EDD version-floor deny leg (T58, probe-red) are now covered. |
| ~~Untested public methods (Run 8)~~ | 2026-08-14 | Clean — no new public method in range. |
| ~~Untested public methods (Run 7)~~ | 2026-08-13 | **One real hit, closed same session:** `PageDataLayer::register_confirm_purchase_route()` was executed by no test (T39, probe-verified both ways — the `__return_true` swap now fails the new registration test). `Helpers::visitor_has_wc_state()` fully covered (the cart-hash cookie leg closed with T46). Everything else surveyed by the 5 deep-reads resolves to a test or a logged Low. |
| ~~Untested public methods (Run 6, re-run)~~ | 2026-08-05 | **Clean — no new hits.** Re-swept the components whose cells were still `[ ]`: `Registry` 6/6 public methods covered by `RegistryTest`; `Migration::maybe_run()` plus all three private steps covered by 11 `MigrationTest` cases (idempotence, version gate, no-overwrite, missing options, post-meta seeding both ways); `RestCors` covers the predicate (data provider), the wrapper, the non-`WP_REST_Request` guard, `register()`'s priority-11 and a namespace-consistency test. Run 5's `[-]` N/A for `AdminSchema::unavailable_message` ×10 stands. |
| ~~Untested public methods (Run 5)~~ | 2026-08-05 (Run 5 + closes) | Swept globally (per-file over-reports). **All real hits closed:** `ScriptTag::print_markup_block` (T32, 6 tests incl. the scoped-restore property its sibling does not have), `StoreApiData::{product_schema,cart_item_schema}` + the 13 `ListTracking` loop hooks (T34). **Withdrawn on verification:** `ProductData::is_new_customer` (asserted via `PurchaseTrackingTest`), `VisitorDataModule::build_config` (5 config-shape tests), `RestController::get_settings` (thin wrapper over the tested `ui_values()`). `AdminSchema::unavailable_message` ×10 recorded **`[-]` N/A** — a translated literal, TS-4 if tested. |
| ~~Untested public methods (Run 4)~~ | 2026-07-15 (Run 4 + closes) | **Closed:** `StoreApiData::register()`; `ListTracking::{quick_view_before_single_product, cart_item_product_filter, cart_item_remove_link_filter, single_add_to_cart_tracking, grouped_product_list_column_label, after_template_part}` (T22); classic JS `gtm4wp_woocommerce_handle_cart_qty_change` + remove-link handler (T25). Prior remaining low-value (`ListTracking` list-name setters; `Notices::print_dismiss_script`) unchanged. |
| **Security-input coverage** (every `.security` PA-3/RI-2 sink has a hostile-input test — TS-1/TC-5) | 2026-09-20 (Run 12) | ✅ **Clean on every sink the range adds.** Cookie parser + backfill body as a hostile boundary (`CaptureTest:206-361`, `BackfillTest:838-889`); the one new inline `<script>` sink through the real enqueue with a hostile order key (`GoogleDataManagerModuleTest:361-386` — TAG/AMP/APOS discriminated, QUOT belt-and-braces T93b); `record_status()` both directions (`SendLogTest:361`); hostile Google error text + status names (`RestControllerTest:450`, `IngestSendTest:430`); Site Health section carries no secret; SendLogList is a structured React sink (TC-11). Term-name decode → hex sink both directions with a TC-2-computed expectation (`PageDataLayerTest:2284`). Residue: `SiteHealthInfo` must-not-pre-escape pinned by `'on'` only (T95a); `last_error_class` no hostile case (T94e); `rest_url` sink benign-only, site-config `[-]` (T96b). |
| ~~Security-input coverage (Run 11 + closes)~~ | 2026-09-03 (Run 11 + closes) | ✅ **Clean.** The GDM range adds no dataLayer/HTML sink (REST JSON + an escaped admin notice, hostile-pinned both directions by `GoogleDataManagerHealthNoticeTest`). The Google error-summary cap — the one benign-only sibling of the hostile-pinned `DestinationHealth` cap — is now hostile-pinned too (T79: tag stripped, ≤200 chars, refusal text survives). |
| ~~Security-input coverage (Run 10 + closes)~~ | 2026-09-03 | ✅ **Clean.** The google-auth range adds no dataLayer/HTML sink (REST JSON + an escaped admin notice, both hostile-pinned by the module's own suites). The new security surface is *egress*, not output: the SSRF allow-list now carries a 16-shape deny provider driven through both the pure check and the pre-socket seam (suffix-label, port, case, userinfo, path, subdomain, sibling-host…), the upload path keeps its 7-row hostile table + stores-nothing pin, and custody is asserted with a real sealed key in the store (anchored, T71). |
| ~~Security-input coverage (Run 9 + closes)~~ | 2026-09-02 | ✅ **Clean after the same-session closes.** T52 (the EDD purchase-form `esc_attr` sink) and T57 (customer/coupon raw-passthrough) both closed probe-red; all three EDD attribute sinks now hostile-pinned both directions; the success-page lookup-miss legs (T59) closed. The EDD inline-script sinks were already exemplary (all four hex flags individually discriminated). |
| ~~Security-input coverage (Run 8)~~ | 2026-08-14 | Clean — no new superglobal/header read in range. The #462 PDP span keeps its escaper revert-provable via the structural assertion; the #190 JS guards shipped watched-red-first. |
| ~~Security-input coverage (Run 7)~~ | 2026-08-13 | **Hostile-input clean; the two T40 robustness-guard legs closed same session.** No new superglobal/header read in range lacks a hostile-input test (the only new `$_COOKIE` touches are presence-only checks — value never read, TC-5 not triggered). The new `<script>` sinks all guard both-directions (visitor-cart fragment whole-attribute equality; Dailymotion hex/path; #112 modeled `esc_url`; #110 encoder-discriminating nonce). The T40 items were #141-class robustness, not XSS: the checkout-products and Axeptio `json_literal` call sites reverted green (probe-verified) because the encoder-failure guard was pinned only at the helper — both filter-fed sites now carry their own NAN case and the same reverts go red. |
| ~~Security-input coverage (Run 6, re-run)~~ | 2026-08-05 | **Clean.** Run 5's "every superglobal read in `src/` has a hostile-input test" still holds (no production change). New this run: audited the **settings-import** input path, which is the one entry point that reaches `Field::sanitize()` **without** the REST layer's per-field type coercion. All **10** custom sanitizers correctly cast through `Field::to_string()`, and the contract is guarded by `ModuleConsistencyTest::test_every_field_sanitizer_handles_non_scalar_input_without_warning` — a whole-schema sweep that pins fields not yet written. **Probe-verified:** a bare `(string)` cast at all 14 call sites fails the suite. One weak-assertion note: the sweep's result check permits the literal `'Array'` (H-3). |
| ~~Security-input coverage (Run 5)~~ | 2026-08-05 (Run 5 + closes) | Enumerated **all 16** superglobal reads in `src/`. The two `UserEvents` event cookies were the only untested ones and are now covered by a 6-row non-integer-value provider (T29), so **every superglobal read in `src/` now has a hostile-input test**. New sinks all clean both-directions: the visitor-cart data-attribute fragment, the A0 visitor-data route's header round-trip, the settings import payload, `HTTP_CF_IPCOUNTRY`/`HTTP_REFERER` raw-passthrough (TS-11), the `?key=` ownership check (PA-10). |
| ~~Security-input coverage (Run 4)~~ | 2026-07-15 (Run 4) | **Batch clean — no open hostile-input sink gap.** Verified both-directions: list-attribution cookie #405 (the one new untrusted request surface — `ProductDataTest`+`PageDataLayerTest`); product-title + order-number `<script>` sinks; raw order billing/shipping passthrough (#8 contract); PA-7 replacement injectors (#16); CookieYes datalayer-name `esc_js` (`ConsentModeCookieYesTest`). Store-API block sink is a delegated REST sink (FP-4) — the only *open* item is a raw-passthrough **contract** test (T21, data-integrity not XSS) + `item_category` benign-only (T23). |
| **Regression-per-bug** (every `.security` Known Finding has a live regression test) | 2026-09-20 (Run 12) | ✅ **Clean.** Walked #235–#250: **#236** `WooCommerceModuleTest:237` (red on the old shape); **#240** `UninstallTest:190,217` (whole tuple with the library defaults — revert red); **#242** `WooRefundsTest:470` (+ EDD `:305`); **#243** `BackfillTest:722-767` (object identity / strict int on the backfill path — the creation path is T92); **#244** `SendLogTest:361` (both directions); **#249** `BackfillTest:622-673` (both platforms; revert traces `BackfillEndpoint:234`/`:304`); **#250** `ReceiptPageTest:257-284` (hash printed AND key absent) + hash-refusal guards. #235/#237 process; #238/#239 open design limitations, no guard owed; #245 documented non-issue; #246–#248 ledger. |
| ~~Regression-per-bug (Run 11)~~ | 2026-09-03 (Run 11) | ✅ **Clean.** Walked #232–#234: **#232** pinned by the seeded-row-drop + screen-numbering pair, both revert-traced (reverting the `unset` or the counter placement goes red); **#233** wontfix scaffolding, no guard owed (registered in R31's over-abstraction row; #231's "re-evaluate at phase 2" is the security ledger's row, re-derived by R31 itself); **#234** pinned by the fingerprint trio + the labeled must-not-break guard, all revert-traced (the health-suppression collateral has its own test). |
| ~~Regression-per-bug (Run 10 + closes)~~ | 2026-09-03 | ✅ **Clean.** Walked #226–#231: **#226** double-guarded (vault purge loop + scope-recording, both revert-traced; controller-level companion test); **#227** revert-traced to its exact assertion, precedence rule pinned both ways; **#228** vault half pinned on both write branches — the REST half's 500-propagation branch was the gap (T68, closed probe-red with the predicted TypeError); **#229/#231** wontfix, no guard owed (#231 re-evaluate at Data Manager phase 2); **#230** doc-level N/A. |
| ~~Regression-per-bug (Run 9 + closes)~~ | 2026-09-02 | ✅ **Clean after the same-session closes** — the two `fixed` findings without a live guard (#203 → T53, #222 → T54) are now pinned, both signed off probe-red. The rest of the #191–#225 walk held at report stage: #191/#194/#195/#197/#198 discriminating (RI-26 claim verified); #217/#218/#219/#220 exemplary, red pre-fix; #223 pinned ×4 + subprocess leg; #225 untestable-red by construction (T65); #215 fix-by-removal, no guard owed; #192/#204/#205 doc-level N/A; open/wontfix out of scope. |
| ~~Regression-per-bug (Run 8)~~ | 2026-08-14 | Clean. #190 `fixed` with live guards watched red by their own fix session; the adjacent find was T51. |
| ~~Regression-per-bug (Run 7)~~ | 2026-08-13 | **Clean.** Walked **#114–#189** (reports 15–24): every `fixed` finding with a code sink carries a live guard, most recorded probe-verified-red by their own fix sessions and re-read here (the standouts are cited in the Run 7 report's Verified-solid section). `wontfix`/process/ledger findings (#118, #128, #159, #163, #161-residual, bookkeeping rows) have no code sink — out of scope. The adjacent-surface gaps this walk exposed (T39/T40/T42 — untested *surroundings* of correctly-guarded fixes, not missing finding guards) were all closed the same session. |
| ~~Regression-per-bug (Run 6, re-run)~~ | 2026-08-05 | **Clean — no new findings in range** (zero production commits since Run 5). Run 5's walk of #31–#113 stands, including T28's close for #33. Caveat surfaced this run: three of those guards (#11's admin hex sink, and the two settings-import sanitization tests) only *execute* in the default declaration order — see the test-order sweep (H-1). The guards are correct; their scheduling is not. |
| ~~Regression-per-bug (Run 5)~~ | 2026-08-05 (Run 5 + closes) | Walked **#31–#113** (reports 5–14). One unguarded fix — **#33 (High)** — found and **closed the same session** (T28); re-probed after: deleting the fix now fails the new test. **No `.security` finding is left without a live guard.** Everything else holds, several exemplary: #40 and #112 model the real collaborator (SDK `replaceChild` + a cap; the real `esc_url` allow-list), #106 models the sanitizer pair an identity stub would render vacuous, #91 asserts the helper is `->never()` called, #63 deletes `global.fetch`. #63 was logged as a candidate and **withdrawn on verification**. Process/ledger findings (#73, #87, #98, #101, #103, #104, #107, #109, #111, #113) are out of scope — no code sink. |
| ~~Regression-per-bug (Run 4)~~ | 2026-07-15 (Run 4) | `.security` Review 5 landed one Low (#31 block cross-sell `internal_id` strip) with a both-directions regression in `woocommerce-blocks-tracker.test.js` — verified live. Prior #1–#6/#8/#11–#18/#28–#30 unchanged. **No unverified findings remain.** |
| **JS test coverage** (`js/frontend/` trackers, `js/admin/` app) | 2026-09-21 (Run 12 + closes) | ✅ **40 suites / 974 green after the closes** (T88/T95b–g closed; the T88 malformed-body case surfaced and fixed a phantom whole-cart `remove_from_cart`; the over-report fixed with its discriminating test red-first). At report stage: 961 green (+`attribution-tracker` 35, +`send-log-list`). Every bundle and admin component covered; consent-engine stand-in models the measured shape incl. defined-but-`undefined` `update`; push re-hook harness leak fixed in-file. **Strict-mode flip measured** (woocommerce/blocks/attribution 1, edd 0) — `not.toThrow` sites re-checked, each paired with an absence-of-push assert. Gaps: **T88** (blocks fallback coalescing + lost-request leg, over-report direction, revertible green), T95b–g (beforeunload clean-again is red by test order only; vacuous locked-rows click; REST-shape fixture; classic Store-API fetch error legs; rename double-submit; `mockReset` residue). **Latent bug candidate → `/code-review`:** stale held-back block `add_to_cart` released by an unrelated `wc-blocks_added_to_cart` (trace verified in the main thread). |
| ~~JS test coverage (Run 11 + closes)~~ | 2026-09-03 (Run 11 + closes) | ✅ **38 suites / 812 green** (`destinations-panel.test.js` 22 → 27 after T76/T80; two probes red — the busy guard both-layers-neutralized and the locked-select re-check). **Writing the malformed-response case surfaced and fixed a latent panel crash** (verdict now built before `setResults` — the throw used to land in React's render, past the catch). `…Once` mocks adopted file-wide (TC-15 r6 corollary). |
| ~~JS test coverage (Run 10 + closes)~~ | 2026-09-03 | ✅ **37 suites / 787 green** (`service-accounts-panel.test.js` 20 → 26 + `module-panel.test.js` +1 after the T69/T73 closes; two probes red — the prototype-key panel fallback and the busy-guard disabled layer). The panel suite landed exemplary (wire shapes, two-step delete, custody by DOM absence with a positive anchor). Harness rule adopted in both apiFetch-using files: **mockClear, never mockReset** (reset erases the stand-in's documented default rejection — TC-15). |
| ~~JS test coverage (Run 9 + closes)~~ | 2026-09-02 | ✅ **36 suites / 757 tests green** (`edd-tracker.test.js` 15 → 28 after the T54/T55/T56/T62 closes; three revert probes each failed exactly the intended test — the `\|\| ''` swap, the deleted grid parse bail, the neutralized #218 guard). Faithful fixtures now carry string `item_id` mirroring production. Admin JS unchanged. |
| ~~JS test coverage (Run 8)~~ | 2026-08-14 | ✅ 35 suites / 726 green. No new bundle; every bundle, lib and admin file covered or `[-]` N/A. |
| ~~JS test coverage (Run 7 + closes)~~ | 2026-08-13 | ✅ **35 suites / 722 tests green** (was 30/404 at Run 6; 33/713 at this run's report stage). Every one of the 20 bundles, 3 libs and 11 admin files is covered or `[-]` N/A. Run-7 report found two JS gaps, **both closed the same session**: T41 (`gtm4wp-media-gate.js` executed by no test → `media-gate.test.js`, own-property + integration + shut-gate cases) and T43 (VideoPress double-load → the re-rendered-page re-attach case, rewritten once under the probe before being trusted). Also new: `form-move-tracker-config-false.test.js` (the shipped default config shape, T50). `DocLink.js` recorded `[-]` N/A documented-indirect. |
| ~~JS test coverage (Run 6 + closes)~~ | 2026-08-05 | ✅ **30 suites / 404 tests green** (was 23/309). **T36 closed:** all 7 previously-untested `js/admin/` files now covered by 95 new tests — the lock guards in `TableControl`, the four third-party-response branches plus the stale-request guard in `AxeptioVersionControl`, the four import outcomes in `ImportExport`, the type routing in `FieldControl`, the group/tab arithmetic in `ModulePanel`, the search/dirty markers in `Sidebar`, and the dirty/save/import baseline bookkeeping in `App`. Harness recorded as **TC-15**. `index.js` `[-]` N/A. **No open JS gaps.** |
| ~~JS test coverage (Run 6, first run)~~ | 2026-08-05 (Run 6) | **`js/frontend/` clean** — 23 suites / 309 green, all 19 bundles + 3 libs covered, no open gap. **`js/admin/` is the open half and was never actually swept:** the row tracked `js/admin/` as one unit, so the **`components/` subdirectory was invisible**. Real state: **9 files / 1375 lines, only `utils.js` (246) tested → 1129 untested** (`App.js` + 6 components). Highest value: `AxeptioVersionControl.js` (external third-party fetch, 4 outcome branches + a cancellation race) and `ImportExport.js` (4 import outcomes). **Blocked on tooling** — `@testing-library/react` is not installed and `@wordpress/scripts` v30 does not provide it. Gap **H-2**. |
| ~~JS test coverage (Run 5)~~ | 2026-08-05 (Run 5 + closes) | **23 suites / 309 tests green.** Every one of the 19 bundles and all 3 libs has a test file — no untested bundle remains. New in range and covered: `visitor-data-tracker` (1 034 lines), `cookies`, plus growth in `native-video-params`, `spotify`, `vimeo`, `twitch`, `ecommerce-generic`, `admin/utils`. Double-init guards (#71/#83) present **and** tested in every bundle that has one. **T33 closed:** the listener capture/detach moved to file-level hooks, so no describe leaks into the next; the shape-based assertions became exact counts and the #71 double-init test now asserts the push half too. Probe: neutralizing the detach fails 4 tests that used to pass. **No open JS gaps.** |
| ~~JS test coverage (Run 4)~~ | 2026-07-15 (Run 4 + closes) | **JS suite 214 → 231 green (21 suites)** after closing T25 (classic cart qty-change + remove-link + variable/grouped/disabled add_to_cart + chunking + parse catches) and T26 (delegated-listener isolation harness, probe-verified). Also confirmed Run-4 baseline: `woocommerce` (last open tracker gap, now closed), `woocommerce-blocks`, `blocks-cart-diff`, `ecommerce-generic` all covered; pure cart-diff lib + block tracker core well-covered (real `subscribe()` drive, TC-10). **No open JS gaps.** |
| **Access-control coverage** (every `permission_callback` / capability gate has a grant+deny test; every filterable capability — `gtm4wp_admin_page_capability` — has a test that the filter customizes the required cap while the default stays unchanged — TS-12/TC-13) | 2026-09-20 (Run 12) | **22 sites / 8 gates** (was 19/7). New gate: `BackfillEndpoint::check_permission` (**A0** guest route) — **all three rungs pinned by its author**: grant/deny/neighbour-host/no-evidence/Referer/nonce (`BackfillTest:239-282`), registration identity + enum + required args (`:200-235`), attachment from a real `Plugin::boot()` both directions of the option gate (`PluginRestWiringTest:253-272`; a `__return_true` swap fails there). New sites on the existing GDM `can_manage`: `send-log` GET + `send-log/replay` POST — rung 1 `RestControllerTest:143-161`, rung 2 identity for all three routes `:163-229`, rung 3 executed from `boot()` but filtered to the first route (Low symmetry pin, T94g). GoogleAuth relabel POST/PUT/PATCH: `can_manage` on every endpoint (`GoogleAuthRestControllerTest:160-204`, `PluginRestWiringTest:178-185`). Nothing owed at any rung. |
| ~~Access-control coverage (Run 11)~~ | 2026-09-03 (Run 11) | **19 sites / 7 gates** (was 17/6): the GDM module adds `GoogleDataManager\RestController::can_manage` (1 `permission_callback` site + the filterable cap). The new gate arrived with **all three rungs of the ladder pinned by its own author**: grant/deny/filtered-cap `->with()` trio, registration args incl. callback identity (+ the args schema pinned exactly this run, T77), and the attachment executed from a real `Plugin::boot()` (`PluginRestWiringTest` — deleting the registration or swapping the gate to `__return_true` goes red by static trace). Nothing was owed at any rung. |
| ~~Access-control coverage (Run 10 + closes)~~ | 2026-09-03 | **17 sites / 6 gates** (was 12/5): the google-auth module adds `GoogleAuth\RestController::can_manage` (4 `permission_callback` sites + the filterable cap). The new gate arrived **already exemplary at both halves the sweep checks** — grant/deny/filtered-cap pinned `->with()`, and the `register_rest_route` args test pins the callback *identity* (the T39 lesson applied unprompted by the module's author). What was missing was the half **one hop further up**: no test executed the `rest_api_init` closure in `Plugin::boot()`, so the whole registration (settings routes included) was deletable green — T66, closed by `PluginRestWiringTest`, probe-red. The sweep's lesson ladder is now three rungs: the callback (#143), its registration (T39), and the registration's own attachment (T66). |
| ~~Access-control coverage (Run 9)~~ | 2026-09-02 | **Clean — same 12 sites / 5 gates, no new gate in range.** The EDD module adds no capability gate; its authorization surface is the success-page order resolution + `edd_can_view_receipt` gate, covered grant+deny+withholding by the #220 tests (red pre-fix). The `function_exists` withhold leg is the blessed untestable limitation — NOTE owed (T65). |
| ~~Access-control coverage (Run 8)~~ | 2026-08-14 | Clean — same 12 sites / 5 gates as Run 7, no new gate in range. |
| ~~Access-control coverage (Run 7)~~ | 2026-08-13 | **Gates governed; the T39 attachment gap closed same session.** The grep still returns **12 sites / 5 gates** (no new gate in range — `RestController.php` untouched); all 5 keep grant+deny + filter-customization coverage, and the confirm-purchase *callback* remains exemplary (nonce/origin/referer matrix). **The new find was the attachment form:** `register_confirm_purchase_route()` was executed by no test, so swapping both POST routes' `permission_callback` to `__return_true` left all 1965 tests green — probe-verified, and now pinned (registration args in `PageDataLayerTest`, wiring both ways in `ModuleHooksTest`; the same swap goes red). The sweep's lesson extends TS-12: a gate has two halves, the callback and its registration, and grepping for the *sites* only proves the first. |
| ~~Access-control coverage (Run 6, re-run)~~ | 2026-08-05 | **Clean — no gap.** The grep returns **12 sites / 5 gates**, all governed. The four `gtm4wp_admin_page_capability` sites (`Notices`, `RestController`, `SettingsPage`, `Plugin::boot()`) stay pinned after T30/T31. `VisitorDataEndpoint`'s `__return_true` route keeps its per-resolver identity gates. `PageDataLayer::check_confirm_purchase_permission` (the A0 guest beacon) re-verified as **exemplary** — 15 assertions across nonce grant/deny, Origin accept / foreign / look-alike host / port / scheme, the Referer fallback both ways, the no-evidence refusal, and that Referer must not override a foreign Origin. Run 5's "sweep for the sites, don't close the ones a finding named" lesson holds: the grep is now the entry point and it found nothing new. |
| ~~Access-control coverage (Run 5)~~ | 2026-08-05 (Run 5 + closes) | **All four `gtm4wp_admin_page_capability` sites now governed** (was two). `RestController::can_manage()` and `SettingsPage::add_admin_page()` are clean (T27) — but `AdminCapabilityFilterTest` says in its own docblock that it pins "the two enforcement sites the issue names", and there are **four**: `Plugin::boot()` had **no** test (**T30** — 4 cases added, incl. that a denied admin request registers no admin hook and does not fall through to the frontend) and `Notices::dismiss_notice()` had grant+deny without pinning the capability (**T31** — now `expect()->with()` plus a filtered-cap pair). Both probe-verified: the downgrade that used to leave both files green now fails 3 and 4 tests respectively. Clean: the A0 guest-beacon Origin gate (13 cases, exemplary) and the `__return_true` visitor-data route (per-resolver identity gates asserted). **Lesson for the row: closing a gate gap at the sites a *finding* named is not the same as sweeping for the sites.** |
| ~~Access-control coverage (issue #143)~~ | 2026-07-15 (issue #143) | **Closed the one open gap.** `RestController::can_manage()` (REST `permission_callback`) and `SettingsPage::add_admin_page()` (`add_options_page()` cap arg = menu + render guard) were executed by **no** test — the unit tests call the REST handlers directly, bypassing the permission callback, so a coverage driver would show `can_manage()` at 0%. Added `AdminCapabilityFilterTest` (default `manage_options` unchanged + filtered-cap grant/deny at both sites). `Notices::dismiss_notice` deny-direction already covered (finding #18, T8). Module frontend hooks gate on *options*, not capability, so they are out of scope for this sweep. |
| **Assertion quality** (coverage driver + mutation testing) | 2026-09-20 (Run 12) | **Coverage 96.40% lines (8624/8946), methods 81.58%, classes 49.48%** at report stage (was 96.38%). New GDM classes 93–100% lines; the sub-100% ones name exactly the T91/T94 legs. **Infection still non-functional on this PHP 8.4.15 ZTS Windows build** (unchanged since Run 9). Judgment-layer TS-1 finds this run: the mixed-outcome retry rule with single-account fixtures only (T89), the consent gate with granted-only fixtures (T81). |
| ~~Assertion quality (Run 11)~~ | 2026-09-03 (Run 11) | **Coverage 96.38% lines (7004/7267), methods 82.42%** at report stage (was 95.25%) — the GDM classes land at RestController/DestinationRows/DestinationHealth/EventsIngest/Module 100%, AdminSchema 99.38%, HealthNotice 96.77%; the two uncovered methods the report named (`panel()`, the boot attachment) were exactly T78/T75, both closed. **Infection still non-functional on this PHP 8.4.15 ZTS Windows build** (unchanged; re-test after version bumps). |
| ~~Assertion quality (Run 10)~~ | 2026-09-03 | **Coverage 95.25% lines (6534/6860), methods 80.40%** at report stage (was 95.21% at Run 9) — the new google classes land at WpTransport 100%, RestController 98.98%, KeyVault 93.06%, TokenService 92.00% lines, with the uncovered legs exactly the branches the deep-reads named (all since closed or NOTE-documented). Two surviving-mutant-shaped finds closed manually (the F2 status-check flip, the L-2 self-referential TIMEOUT assertion — now a literal). **Infection still non-functional on this PHP 8.4.15 ZTS Windows build** (unchanged from Run 9; re-test after version bumps). |
| ~~Assertion quality (Run 9 closes)~~ | 2026-09-02 | **PCOV installed and working** (user-approved): DLL at `~/.php-ext/php_pcov.dll`, loaded via `PHP_INI_SCAN_DIR=';C:/Users/DuracellTomi/.php-ext/ini'` (the scan-dir form reaches `RunInSeparateProcess` children — a `-d` flag does not). **First coverage baseline: Lines 95.21% (6083/6389), Methods 79.55% (389/489), Classes 50.00%** (class figure diluted by BE-3 N/A orchestrators/interfaces) at 2143/5419 green. **Infection installed (0.34/0.35) but NON-FUNCTIONAL on this machine**: exits silently (code 0) during mutant generation on the PHP 8.4.15 **ZTS** Windows build — reproduced single-file, `--dry-run`, one thread, default tmpDir; suspected environment, not config (details in `infection.json5`'s header comment). Expected to work on NTS/Linux; re-test after the next PHP or Infection bump. |

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

### Run 9: 2026-09-02 post-2.0.0 cumulative pass (`65c9670..1c1164f`, 45 commits) — 2026-09-02

EDD module + master-language merge + security reviews 25–29. Report-only pass; **no
tests written this run.** Baseline **PHP 2120/5333** (declaration + 3 random seeds)
and **JS 36 suites / 746**, all green. **Revert probes classifier-blocked** — the
revertible-green claims below are static-traced (exact surviving assertions named in
the git-ignored report); each close must be watched red-first against the reverted
source. Theme: **the port carried the guards but not the pins** (TS-18 — T52, T54,
T55, T56 are all WC-pinned/EDD-unpinned). No latent production bug; two production
observations routed to `/code-review`. Full detail in the git-ignored
`test-review-report-2026-09-02-2014.md`. **Reviewed at: `1c1164f`.**

| # | Sev | Status | Summary | File(s) |
|---|-----|--------|---------|---------|
| T52 | High | addressed | TS-1/TS-15: the EDD purchase-form input's `esc_attr` attribute sink was benign-only while the sibling grid-span sink was hostile-pinned. **Closed** with the mirrored both-directions case (hostile download title AND hostile price-option name). **Probe red:** deleting the `esc_attr` fails exactly the new test. | `tests/unit/Modules/EddListTrackingTest.php` |
| T53 | Med | addressed | Regression-per-bug: fixed finding **#203** had no live guard. **Closed** with a non-scalar stored allow-list case under the TC-14 throwing handler asserting the empty-default behavior (public keys still publish). **Probe red:** the bare-cast revert now errors on the warning trap. | `tests/unit/Modules/PageVariablesModuleTest.php` |
| T54 | Med | addressed | Regression-per-bug: fixed finding **#222** had no live guard. **Closed** mirroring the WC pin (pre-set `0` survives a boot) plus the undefined→`''` init case. **Probe red:** the `\|\| ''` revert fails exactly the new test. | `js/frontend/test/edd-tracker.test.js` |
| T55 | Med | addressed | TS-1/TS-5: the EDD-tracker parse-guard bails were covered only by well-formed JSON. **Closed:** malformed grid JSON (item skipped, valid sibling still reported, exact count), `'null'` purchase-form payload (click → no push), cart row without its data span (remove → no push) — all absence-of-push shapes, no `toThrow` (non-strict bundle). **Probe red:** deleting the grid bail fails the sibling-survives test. | `js/frontend/test/edd-tracker.test.js` |
| T56 | Med | addressed | TS-7 (JS variant): **closed** by adopting the T33 file-level `document.addEventListener` capture + `afterEach` detach harness; the new exact-count assertions (parse-guard sibling, exposed-API single push) rely on it. | `js/frontend/test/edd-tracker.test.js` |
| T57 | Med | addressed | TS-11: EDD customer/coupon fields were benign-only. **Closed** with the canonical raw-passthrough case (hostile first_name/city/coupon via `\xNN`, `assertSame` raw + entity forms absent, escape stubs installed). **Probe red:** a re-added pre-escape fails 1 + errors 1. | `tests/unit/Modules/DownloadDataTest.php` |
| T58 | Med | addressed | TS-5/TS-10: the EDD version-floor deny branch was untested. **Closed** with a `RunInSeparateProcess` "EDD active at 2.9.4" deny (the constant is process-sticky; in-file rationale). **Probe red:** dropping the `version_compare` clause fails it. | `tests/unit/Modules/EasyDigitalDownloadsModuleTest.php` |
| T59 | Med | addressed | TS-5/PA-10: **closed** with three silent-return cases — wrong `?payment_key` (lookup false), bogus `?id=` (order null, `edd_get_order_by` `->never()`), and bare `?id=` without the hash (branch not entered, session fallback empty) — each asserting no orderData, no purchase, no meta write. | `tests/unit/Modules/EddPageDataLayerTest.php` |
| T60 | Med | addressed | TS-3/TS-9: the containment half of the boot-fallback contract was unpinned. **Closed** with the byte-exact first-child assertion (BE-1 spirit — the render is deterministic). **Probe red:** moving the notice after the container's closing tag fails it. | `tests/unit/Admin/SettingsPageTest.php` |
| T61 | Med | addressed | TS-5/TS-13/TS-15: the WPML numeric guard was not revert-provable. **Closed** with the null-resolution case (real WPML returns null for an unregistered element type) for `post_id()` AND `term_id()`, plus the empty-default-language short-circuit (`wpml_object_id` `->never()`). **Probe red:** deleting the numeric guard fails 1. | `tests/unit/Frontend/DefaultLanguageTest.php` |
| T62 | Low | addressed | EDD low-branch bundle **closed** — PHP: checkout-cart hostile symmetry, search list name, fixed-price `price_options` omission, non-array price data (both shapes), `business_vertical` invalid→retail, `add_global_vars` off-legs, in-footer filter (incl. the deliberate no-defer pin on the EDD handle), TC-14 cart-page tag-true/post-null (argument-sensitive `is_singular` stub — first draft rewritten under the probe), bare-`?id=` fall-through; JS: exposed API grant + null/disabled deny, quantity clamp, unchunked else, href-mismatch bail, `(payment type not found)` label, eventCallback wrong-container early-return, fixtures moved to string `item_id`. `readyState==='loading'` boot path left untested (jsdom lifecycle; not worth a fake). | `tests/unit/Modules/*`, `js/frontend/test/edd-tracker.test.js` |
| T63 | Low | addressed | Master-language bundle **closed** per maintainer decisions: WPML empty-default + null-resolution legs; half-present-Polylang subprocess case; ProductData master-product-gone (identity stays current) AND the mixed-identity variation edge **pinned as measured behavior, labeled behavior-not-contract** (design question routed to /code-review); primary-category master-term-null **pinned as omission** (house RI-13; the `localized_term_field` fallback asymmetry routed as an observation); CF7 master-path raw-passthrough (TS-11 sibling). | `tests/unit/Frontend/DefaultLanguageTest.php`, `tests/unit/Modules/{ProductDataTest,PageVariablesModuleTest,ContactForm7ModuleTest}.php` |
| T64 | Low | addressed | TS-5 symmetry: **closed** — the trusted-proxy test now carries the sanitizer↔reader round-trip agreement assertion (`VisitorIp::parse_trusted_proxies` on raw vs sanitized), mirroring the meta-key oracle. | `tests/unit/Modules/PageVariablesAdminSchemaTest.php` |
| T65 | Low | addressed | Bookkeeping **closed**: the EDD `edd_can_view_receipt` `function_exists` withhold leg carries its NOTE at the setUp stub; the #225 noscript `esc_attr` untestable-red-by-construction status is recorded in the Frontend Core matrix row + this log (wrong-fix direction pinned by the BE-1 raw-`&` tests) so TS-15 sweeps skip it deliberately. | `tests/unit/Modules/EddPageDataLayerTest.php`, matrix row |

### Run 10: 2026-09-03 google-auth module pass (`1c1164f..99ee59a`, 6 commits) — 2026-09-03

The whole google-auth module (security review 30, #226–#231 all Low). 5 parallel
deep-reads; **the strongest new-module suites this system has reviewed** — findings
are pins-on-strong-suites, not missing suites. All gaps **closed the same session**
("close all, ask on forks"; 4 forks answered — refuse ports / RunInSeparateProcess /
inject a clock / handler re-check). 9 revert probes red; suite 2296/6207 → 2313/6299
PHP + 37/787 JS, 4 random seeds, phpcs 0, build + lint clean. Three production
changes (transport port refusal, KeyVault clock seam, panel busy re-checks) — one
sentence added to the unreleased google-auth changelog bullet. Full detail in the
git-ignored `test-review-report-2026-09-03-0910.md`. **Reviewed at: `99ee59a`.**

| # | Sev | Status | Summary | File(s) |
|---|-----|--------|---------|---------|
| T66 | Med | addressed | TS-12 attachment, one hop above T39: the `rest_api_init` closure in `Plugin::boot()` (settings + google routes + RestCors) was executed by no test — deleting the google registration stayed green (probe-verified). **Closed** by `PluginRestWiringTest`: boots the real `boot()`, captures and invokes the closure, pins all 4 google endpoints' `can_manage` identity + the settings routes + the CORS filter + single-namespace. **Probe red** (0 vs 3 registrations). | `tests/unit/PluginRestWiringTest.php` |
| T67 | Med | addressed | TS-2/TS-5 on the SSRF deny provider: no suffix-label row (`evil-oauth2.…` — the one shape discriminating a `str_ends_with` regression) and an explicit **port was accepted** untested. **Closed:** maintainer chose refusal — `is_allowed_url()` rejects any explicit port (port row watched red pre-fix), + suffix/case rows, literal-15 timeout pin, over-depth body case, docblock nits. | `src/Google/WpTransport.php`, `tests/unit/Google/WpTransportTest.php` |
| T68 | Med | addressed | TS-15 layer-split on #228: the vault half was pinned but the REST handler's store-failure→500 branch was uncovered (a dropped `instanceof` check crashes only in the scenario no test built). **Closed** with the refused-write upload case (500 + code + no phantom account). **Probe red** — the predicted TypeError. | `tests/unit/Modules/GoogleAuthRestControllerTest.php` |
| T69 | Med | addressed | TC-15 §3: the JS panel's busy guard was only the rendered `disabled` prop — no handler re-check, no test. **Closed:** handlers re-check `isBusy` (TableControl pattern) + deferred-promise double-submit test. **Probe measured:** the test pins the *disabled-prop layer* (jsdom never delivers clicks to disabled buttons); the handler layer is a documented in-harness blind spot (TS-17 note added). | `js/admin/components/panels/ServiceAccountsPanel.js`, `js/admin/test/service-accounts-panel.test.js` |
| T70 | Low | addressed | TokenService bundle **closed**: sign-failure leg via `RunInSeparateProcess` namespaced-shadow (in-process would poison later signing tests, TS-16); "HTTP 400 carrying a token" provider row (the only row where the status check alone discriminates — probe red); corrupt-cached-transient re-mint (`''` + array, probe red); non-string `private_key_id` accepted-as-empty asymmetry pinned with rationale. | `tests/unit/Google/TokenServiceTest.php`, `ServiceAccountKeyTest.php` |
| T71 | Low | addressed | KeyVault bundle **closed**: scope-dedupe purge-once case; `OptionStoreTrait::update_option` now returns core's false-on-unchanged (TS-13 fidelity); custody bootstrap test gained its positive anchor; openssl no-extension/encrypt-false legs NOTE-documented untestable (patchwork redefines no openssl internals); **clock seam injected** (maintainer's call) — `uploaded_at`/`last_checked` exact-asserted. | `src/Google/KeyVault.php`, `tests/unit/Google/{KeyVaultTest,OptionStoreTrait}.php`, `GoogleAuthCustodyTest.php` |
| T72 | Low | addressed | TS-13 by construction: `FakeTransport` answered any URL while the real transport refuses; **closed** — the fake now throws on a URL `WpTransport::is_allowed_url()` refuses (one definition; `wp_parse_url` stubbed in both consumer setUps). Pre-empts Data Manager phase 2 callers. | `tests/unit/Google/FakeTransport.php` |
| T73 | Low | addressed | JS panel Lows **closed**: `file.text()` rejection (notice + nothing posted), unknown-status verbatim, adopt-only-the-matching-row (two-account discrimination), dash fallbacks, upload-success-without-account, prototype-key `'constructor'` panel fallback (**probe red** against a bare lookup), `mockClear`-not-`mockReset` in both apiFetch files; load-effect `cancelled` cleanup recorded `[-]` N/A (the `key=` remount makes the race unobservable). | `js/admin/test/{service-accounts-panel,module-panel}.test.js` |
| T74 | Low | addressed | TS-15 hand-set-precondition pair **closed**: KeyNotice's attachment in `Admin::boot()` (grant `has_action` + deny absence in `AdminCapabilityFilterTest`, **probe red**); GoogleAuthModule frontend-absence case in `ModuleHooksTest` (`expect('add_action')->never()`, **probe red** against an injected hook). | `tests/unit/Admin/AdminCapabilityFilterTest.php`, `tests/unit/Modules/ModuleHooksTest.php` |

### Run 11: 2026-09-03 Data-Manager-phase-2 pass (`99ee59a..75c942d`) — 2026-09-03

Report + same-session closes on the user's "fill straightforward, ask on forks"
go-ahead (no fork arose). PHP 2394 → 2397 / 6539 → 6552, JS 807 → 812, declaration
+ 3 seeds identical, phpcs exit 0, lint:js clean, build rebuilt. 3 revert probes
red (T75, T76, T80g). One latent production bug surfaced and fixed (T80's
malformed-response case — the crash-in-render class; see the Run 11 closes note).

| # | Sev | Status | Summary | File(s) |
|---|-----|--------|---------|---------|
| T75 | Med | addressed | TS-15/TS-18/TS-3: `Admin::boot()`'s HealthNotice wiring executed by no test (its own suite drives a hand-built instance) — deletable green; the T74/KeyNotice lesson recurring for the sibling notice in the same range. **Closed** with the KeyNotice-shaped boot-attachment assertion; **probe red** against the deleted wiring line. Found independently by two deep-reads. | `src/Admin/Admin.php`, `tests/unit/Admin/AdminCapabilityFilterTest.php` |
| T76 | Med | addressed | TS-18: DestinationsPanel's busy/double-submit guard entirely unpinned (neither the `disabled` prop nor the handler re-check; the sibling's deferred-promise test not ported; no `apiFetch` call-count assertion anywhere). **Closed** by porting the sibling test; **probe red** (both layers neutralized → 2 calls). Pins the prop layer; the handler re-check stays the TC-15 r7 blind spot, noted in-file. | `js/admin/test/destinations-panel.test.js` |
| T77 | Low | addressed | TS-18/T39: the GDM route registration test pinned ns/route/POST/gate identity but not the `args` schema or the handler callback identity. **Closed** — the whole four-column args block `assertSame`'d exactly + `test_destination` identity. | `tests/unit/Modules/GoogleDataManagerRestControllerTest.php` |
| T78 | Low | addressed | TS-18/TS-1: no PHP test pinned `panel()` = the React registry key (both realms used their own literals — silent-missing-panel drift; corroborated by the one uncovered AdminSchema method), and `panel_data()['health']`/`['threshold']` were `assertArrayHasKey` only. **Closed** — literal pin with the registry rationale + seeded health record round-trip + threshold identity. | `tests/unit/Modules/GoogleDataManagerAdminSchemaTest.php` |
| T79 | Low | addressed | TS-1/TS-18 sink asymmetry: `EventsIngest::error_summary()`'s sanitize+200-char cap exercised benign-only while the sibling `DestinationHealth` cap is hostile-pinned. **Closed** — tag-bearing >200-char 403 message: tag stripped, capped, refusal text survives. | `tests/unit/Modules/GoogleDataManagerRestControllerTest.php` |
| T80 | Low | addressed | JS hardening bundle **closed**: `…Once` mocks file-wide (a persistent `mockResolvedValue` erases the stand-in's loud-reject default — TC-15 r6 corollary); rejected-with-message + malformed-resolve error legs (**the malformed case surfaced a latent panel crash, fixed**: verdict built before `setResults` so a no-envelope reply is a failure verdict, not a TypeError in React's render past the catch — red pre-fix by construction); `--ok`/`--error` class discrimination; `type:''→'ga4'` wire default; threshold `==3` boundary + `unknown` fallback; module-panel DOM-order pin; locked-select re-check **probe red** (closing its missing-probe note). | `js/admin/components/panels/DestinationsPanel.js`, `js/admin/test/destinations-panel.test.js`, `js/admin/test/module-panel.test.js` |

### Run 12: 2026-09-20 Data-Manager-phase-3+4 pass (`75c942d..b085e0e`, 80 commits) — 2026-09-20

Report-only pass; full detail in the git-ignored
`test-review-report-2026-09-20-2230.md`. **Reviewed at: `b085e0e`.** PHP 2852/7809
(declaration + 3 seeds identical), JS 40/961, coverage 96.40%. 4 parallel
read-only deep-reads; two harness defects measured in the main thread (T84, T85);
one upstream claim settled from EDD source (T93f). Latent production bug candidate
(over-reported block `add_to_cart`) routed to `/code-review`. Patterns added: TS-20,
TS-16 corollary (run changed files by path), TS-11 corollary (decode narrows a
raw-passthrough pin).

| # | Sev | Status | Summary | File(s) |
|---|-----|--------|---------|---------|
| T81 | **High** | addressed | TC-1/TS-15: the server-side consent gate at **order creation** (`AttributionCapture:382`) has no test on either platform — every capture fixture is `granted`, the only denial fixtures are on the backfill route which never reaches `meta_for_order()`. Deletable green; identifiers a recorded denial forbids would be stored on every order, silently. **Closed 2026-09-21** — four gate cases at order creation (analytics denied → ids absent, ads denied → click ids absent, absent signal ≠ denial, filter-supplied denial honoured); probe: deleting `:382` fails all four. | `tests/unit/Modules/GoogleDataManagerCaptureTest.php`, `GoogleDataManagerCaptureHooksTest.php` |
| T82 | Med | addressed | TS-15 attachment: `Plugin::boot_refund_lane()` executed by no test — option gate + `RefundSender->run`/`StatusPoller->poll`/platform refund hooks pinned only on hand-built instances; deleting the boot call silences the lane green. Found by two deep-reads independently. **Closed 2026-09-21** — `PluginRestWiringTest` boots with the option on/off and asserts `HOOK_SEND`/`HOOK_STATUS` + both platform refund hooks through the shim (TC-4 both ways); **probe red** (boot call removed). | `tests/unit/PluginRestWiringTest.php` |
| T83 | Med | addressed | TS-15 attachment — **T75 recurring a third time in the same `Admin::boot()`**: the `SiteHealth` + `SiteHealthInfo` `register_hooks()` lines (`Admin.php:52-53`) are asserted by nothing (both suites drive hand-built instances). **Closed 2026-09-21** — both `has_filter` assertions in the grant case, both `assertFalse` in the deny case; **probe red** (the two `Admin.php` lines removed). | `tests/unit/Admin/AdminCapabilityFilterTest.php` |
| T84 | Med | addressed | TS-16 (load-time, **measured**): `GoogleDataManagerBackfillTest` constructs `\EDD\Orders\Order` without requiring `edd-stubs.php` — green under every seed (PHPUnit loads all files first), **4 errors by path**. **Closed 2026-09-21** — `require_once edd-stubs.php` added; the file runs green by path (35/91). | `tests/unit/Modules/GoogleDataManagerBackfillTest.php:15` |
| T85 | Med | addressed | TS-16 (in-file `function_exists`, **measured**): `test_orders_flowing_with_nothing_captured_is_a_reportable_state` needs `edd_update_order_meta` defined by an earlier test; fails alone and under seeds 4/5. **Closed 2026-09-21** — `expect( 'edd_update_order_meta' )->never()` in the case itself; green alone and under seeds 4/5. | `tests/unit/Modules/GoogleDataManagerCaptureHooksTest.php:329` |
| T86 | Med | addressed | TS-19: `panel_data()`'s `logPath`/`replayPath`/`sendKeys` declared PHP-side, read JS-side, pinned by neither (JS fixtures hand-feed them; PHP test pins five other keys). Deleting either → both suites green, log hidden/never rendered. **Closed 2026-09-21** — `logPath`/`replayPath`/`sendKeys` pinned exactly plus the TS-19 litmus (every `sendKeys` entry is a declared field key). | `tests/unit/Modules/GoogleDataManagerAdminSchemaTest.php:231` |
| T87 | Med | addressed | TS-15 hand-set precondition: the classic tracker's `inline_store_api_cart_url( 'gtm4wp-woocommerce' )` (`WooCommerceModule:323`, the `c5499f9` forum fix) asserted by nothing — no test reads `$result['inline']['gtm4wp-woocommerce']`; every JS case hand-sets the URL. **Closed 2026-09-21** — classic-store case reads `$result['inline']['gtm4wp-woocommerce']` with the TC-2-built literal; **probe red** (`:323` removed). | `tests/unit/Modules/WooCommerceModuleTest.php` |
| T88 | Med | addressed | TS-5 over-report direction: blocks Store-API fallback `refresh_running`/`refresh_again` coalescing untested (every fallback test settles between syncs — removing the guard double-pushes green); lost-request / `ok:false` / non-array `items` legs untested. **Closed 2026-09-21** — coalescing pinned as WHEN reads are issued (2 fetches after two unsettled syncs, 3 after settling); lost-request and refused/malformed legs assert absence of push. **The first draft asserted the push count and stayed green with the guard removed** (racing reads still resolve one after another) — the probe rewrote the test. **Writing the malformed-body case surfaced a latent defect** (a `200` body whose `items` is not a list reported the whole baseline as `remove_from_cart`), fixed in `gtm4wp_blocks_fetch_cart()`. | `js/frontend/test/woocommerce-blocks-tracker.test.js` |
| T89 | Med | addressed | TS-5 + TS-14 lead: `RefundSender::deliver()`'s retry-only-the-failed-destination rule has no mixed-outcome fixture (every test = one account = one chunk); `test_only_the_destination_that_failed_is_retried`'s comment describes a refusal its fixture does not produce; `mark_sent` withheld after partial acceptance unpinned. **Closed 2026-09-21** — two-account fixture (200 then 503): `only === ['G-BBB']`, not marked sent, accepted half polled, per-destination health + ring outcomes; attempt-6 variant marks sent with the accepted request. The mislabelled single-account test renamed. **Probe red** (retry-everything mutant, using the real column constant). | `tests/unit/Modules/GoogleDataManagerRefundSenderTest.php:654` |
| T90 | Med | addressed | TS-15 shared-helper routing: `TokenService:145` `$fresh` → `$force` unpinned (vault's `force` pinned; nothing drives the service with `true` and asserts a write) — revert green, Test button cannot refresh `last_checked` within 15 min. Two deep-reads. **Closed 2026-09-21** — `TokenServiceTest`: option-write count grows on the forced mint at the frozen clock; **probe red** (`$fresh` → `false`). | `tests/unit/Google/TokenServiceTest.php:237` |
| T91 | Med | addressed | TS-13/TS-18 refund-adapter parity (R33 collateral 5): (a) the WC not-a-refund case passes `null`; the typed `stub_orders()` cannot construct the wrong-kind object; weakening `:87` to `is_object()` stays green (EDD pins the shape). (b) EDD `price_id` → `item_variant` never exercised; WC pins variation + affiliation, EDD neither. **Closed 2026-09-21** — (a) `WC_Order` at the refund id with the right parent + refund object at the order id, both `assertNull`; **probe red** (`instanceof` → `is_object`, 2 errors). (b) EDD `price_id` → `item_variant` mirrored on the purchase builder + affiliation filter case; **probe red** (5th argument dropped). | `tests/unit/Modules/GoogleDataManagerWooRefundsTest.php:455`, `GoogleDataManagerEddRefundsTest.php` |
| T92 | Med | addressed | TS-5/TS-18 (R33 collaterals 6 + 2): the `array_slice` bound is applied before validation (probe-confirmed: a valid tail past MAX is dropped) with no pin — a count-accepted cap reverts green; the creation path's `gtm4wp_gdm_order_consent` second arg (WC object / EDD int) pinned by nothing (`CaptureHooksTest:41` discards it). **Closed 2026-09-21** — slice bound pinned on the cookie AND the route for sessions and signals (invalid-then-valid maps, valid tail absent, labelled behaviour-not-contract); creation-path filter argument pinned as the `WC_Order` object / strict int in `CaptureHooksTest`. | `tests/unit/Modules/GoogleDataManagerCaptureTest.php`, `GoogleDataManagerCaptureHooksTest.php` |
| T93 | Low | addressed | Capture/backfill residue bundle: (a) EDD empty-token guard WC-only; (b) inline-script QUOT not discriminated; (c) `assertGreaterThan(0, save_count)` hides per-key `save()`; (d) `:556` fixture's denial makes the array-valued assertion hold for a different reason than its docblock; (e) EDD array-shape sibling `[-]`; (f) **`edd-stubs.php` docblock overclaims** — EDD 3.7.0 `Database\Base::__isset()` exists, verified from source → correct the rationale + `/upstream-review` row. **Closed 2026-09-21** — (a) EDD empty-token case with `edd_get_order_by`/`edd_get_order` `->never()`; (b) `\u0022` present + `\"` absent; (c) **production**: `PrivacyData::erase()` saves once per order (`persist()`), count pinned `=== 1` and `=== 0` when nothing of ours is stored; (d) fixture stores a grant so the array-valued guard is what the test proves; (e) `[-]`; (f) docblock corrected (stub is stricter than real EDD, the safe direction) — registry row owed to `/upstream-review`. | `tests/unit/Modules/GoogleDataManager{Backfill,Module,Privacy}Test.php`, `edd-stubs.php` |
| T94 | Low | addressed | Send-lane residue bundle: (a) replay 409 gate's options attachment; (b) `SendQueue::schedule()` cron else-leg via the shim + `is_active()` both adapters; (c) `register_hooks()` inactive skip; (d) `record_status()` non-array/negative rows; (e) `last_error_class` no hostile case, `last_error` one-direction; (f) `'delay' => $ts - time()` two wall-clock reads can flake (bracket or inject); (g) replay `args` schema, `queued` on refused schedule, `gdm-send-refunds` Field, rung-3 three-route count; (h) WC `items()` tax basis + date fallback legs. **Closed 2026-09-21** — (a) replay callback captured from a real `boot()` refuses 409 with sending off, **probe red** (`null` options); (b) dispatcher else-leg through the shim + `is_active()` both ways on both adapters; (c) hooking-source double: inactive source not asked, closure carries its platform; (d) malformed row skipped, negative counts clamped; (e) `last_error_class` hostile + `last_error` both directions; (f) `assertEqualsWithDelta( …, 1 )`; (g) replay `args` schema exact, refused schedule not counted, `gdm-send-refunds` Field pinned, rung-3 three-route count; (h) `'incl'` basis, `WCEXCLUDETAX` override, `get_date_created()` null fallback. | `tests/unit/Modules/GoogleDataManager*Test.php`, `PluginRestWiringTest.php` |
| T95 | Low | addressed | Admin/JS residue bundle: (a) `SiteHealthInfo` must-not-pre-escape pinned by `'on'` only (TS-11, needs `stubEscapeFunctions`); (b) beforeunload clean-again transition; cleanup revert red by test order only; (c) vacuous locked-rows click + mark-clearing asserted as a call; (d) blocks fallback fixture uses the data-store object shape, the REST path consumes a JSON string; (e) classic Store-API fetch error legs → assert absence of push; (f) rename double-submit (T76 shape); (g) `mockReset`/persistent mocks in `app.test.js`/`module-panel.test.js` (TC-15 r6). **Closed 2026-09-21** — (a) raw row with `& " < '` through the collector with `stubEscapeFunctions()`, **probe red** (`esc_html` at the boundary); (b) edit → revert → `beforeunload` not prevented, one order-independent case; (c) `toBeDisabled()` + no confirm rendered; mark-clearing via `rerender` with the round-tripped value; (d) REST string-shaped extension line on the fallback path; (e) `it.each` over rejected/refused/non-list/unparsable + no variation + no cart URL, absence of push; (f) deferred-promise rename, second click swallowed at the prop layer (handler re-check stays the TC-15 r7 blind spot); (g) `mockClear` + `…Once` in `app.test.js`/`module-panel.test.js`. | `tests/unit/Admin/SiteHealthInfoTest.php`, `js/admin/test/{app,table-control,service-accounts-panel,module-panel}.test.js`, `js/frontend/test/woocommerce*.test.js` |
| T96 | Low | addressed | WC/EDD/container residue bundle: (a) TS-18 — `eec17df` EDD term-name decode route has no EDD-side pin (WC: 3 decode + 1 sink); (b) `rest_url` inline sink benign-only, site-config → `[-]` unless wanted; (c) U47's "byte-exact loader" is a fragment claim (ten `j.src` pins, `Date.now()` swap reverts green) — a whole-loader contract test would pin it; (d) `test_a_whole_screen_panel_names_no_group` asserts only inside an `if`. **Closed 2026-09-21** — (a) EDD `item_category` decode ×2 in `DownloadDataTest` + hex-sink both directions in `EddPageDataLayerTest`; (b) `[-]` site-config; (c) whole-loader contract test against Google's published block (id interpolated), **probe red** (`Date.now()` swap); (d) descriptor collected, `assertNotNull` first. | `tests/unit/Modules/{DownloadData,EddPageDataLayer,WooCommerceModule,GoogleAuthCustody}Test.php`, `tests/unit/Frontend/ContainerCodeTest.php` |

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
