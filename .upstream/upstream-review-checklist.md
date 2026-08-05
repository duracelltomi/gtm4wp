# Upstream Review Checklist

> ⛔ **Disclosure rule (hard):** public repo — committed == published. A drift finding
> that names an unfixed incompatibility ("GTM4WP is broken on WC 10.7") is a public
> statement about a defect users are running. Committed entries carry the coupling
> site, the failure mode and a status — nothing more. Live detail stays in the
> git-ignored `.upstream/upstream-review-report-*.md`. Canonical rule: top of
> `.security/code-review-checklist.md`.

This is the third sibling of `.security/code-review-checklist.md` and
`.testing/test-review-checklist.md`. Those two ask whether *our code* is safe and
whether *our suite* proves it. This one asks a question neither can see:

**Did the world outside this repo move, and does the plugin need to follow?**

The judgment lives in `.upstream/upstream-review-patterns.md` (UD / UC / UB). This
file holds the state.

## How to use

1. `/upstream-review` runs the sweep. It reads this file, re-derives staleness, runs
   the mechanical layer, fetches the due channels, and writes a git-ignored report.
2. The sweep **never edits production code.** It updates this ledger and its report,
   then presents ranked findings and waits.
3. Fixes are a separate, approved change and carry their own `CHANGELOG.md` bullets.

## Status markers

Identical legend to the sibling systems:

- `[ ]` — never verified
- `[x] YYYY-MM-DD` — verified on that date (often tagged with the run, e.g. `[x] 2026-08-05 (S1)`)
- `[~]` — stale: the coupling site or the upstream moved since the recorded date
- `[-]` — N/A / not applicable

⚠️ **A coupling with no row is invisible, not unverified.** A complete-looking
registry is the failure mode this system exists to prevent. Sweep step 1 is a
mechanical hunt for couplings that have no row — never a read of the rows that exist.

## Staleness rule

Re-derive, never read. A `Last verified` date is a claim about the past, not a
statement about now. Every sweep:

- re-derives coupling-site staleness from `git log <last reviewed sha>..HEAD` over the
  paths named in the registry, and
- re-derives upstream staleness from the Release Radar — if an upstream shipped a
  release after a row's `Last verified`, that row is `[~]` regardless of its date.

A count written into a row (`71 tag ids`) is a measurement to re-derive, not a fact
to read. Every count carries its counting rule (UD-13).

## Check outcomes — three, never two

A sweep records one of three outcomes per item. Collapsing the third into the first is
the way this system fails silently:

| Outcome | Meaning | Ledger effect |
|---|---|---|
| `verified` | The probe ran and the claim held | Advance `Last verified`. No prose needed. |
| `drifted` | The probe ran and the claim is false | Open a `D#` finding with the delta. **`Last verified` stays at the last date the claim was TRUE.** |
| `fetch-failed` | The probe did not run, returned empty, or the page restructured | Advance **nothing**. Increment the unverified streak. |

`Last verified` means *the date the claim last held*, not *the date we last looked*.
Without that distinction a drifted item renders as freshly verified.

**Two consecutive `fetch-failed` on the same source is itself a finding** — a source
that has stopped being checkable is drift in our ability to observe the claim. The
remedy is to change the source to something machine-readable, recorded in the entry's
history.

**Prove the probe discriminates.** For every checked item, assert the extraction is
non-empty *and* shaped right (a count in a plausible range, a semver, N lines). An
empty extraction compared against an empty recorded value is a green cell built on two
nothings — the same shape as `.security/` #88 ("an mtime proves nothing").

## Run discipline

- **Fetch budget: at most 6 web checks in a normal sweep, 12 in escalation.** Take the
  highest-severity overdue items first. **Report the count of items skipped for
  budget** — skipping is fine, skipping silently is the failure.
- **Run local probes for every entry first**, then spend the fetch budget on what
  survives. A coupling that no longer exists needs no fetch.
- **Audit one.** Every sweep re-derives **one** already-`verified` entry from scratch,
  oldest first, and records the re-derivation. Mirrors `/code-review`'s proven
  "re-derive one False Positive Suppression per review" rule.
- **Two phases, two completion criteria, reported separately.** Declared versions
  (section F + the floors) are cheap, deterministic and local. Behavioral contracts
  (sections A–E) are fetch-bound and judgment-heavy. Under one aggregate number the
  cheap half is the only half that ever gets done while the dangerous half rots.
  Report `declared: n/n · behavioral: n/n, k overdue` — **never one percentage**.
- A run is **complete when the budget is spent**, not when the overdue list is empty.
  With ~87 rows on mixed cadences the overdue list is never empty, and a permanently
  red indicator is one nobody reads.

---

## Drift severity rubric

The analogue of `.security/threat-model.md`'s "severity = lowest actor who can reach
the sink". Here the driver is **how the breakage announces itself**, because an
upstream change that crashes gets reported by users within a day, and one that
quietly sends wrong analytics data never does.

### Base severity — by failure mode

| Failure mode | Meaning | Base |
|---|---|---|
| `silent-wrong` | Plugin runs; the data it sends is incorrect | **Critical / High** — nobody will report it |
| `silent-missing` | Plugin runs; data stops being sent | **High** — noticed eventually, in reporting |
| `loud` | Fatal, PHP notice, JS error, or visibly broken UI | **Medium** — a user reports it within days |
| `deprecation` | Works now; breaks at a known future release | **Low / Medium** — scheduled and plannable |

### Stage — the lead-time axis

| Stage | Meaning | What the sweep should say |
|---|---|---|
| `announced` | In a dev note, deprecation notice or developer blog post; no code yet | Plan for it. Record target release + date. |
| `beta` / `rc` | In a pre-release build we can test against today | Test now — this is the cheap window. |
| `shipped` | In stable; users are running it | We are behind. Full severity per the table above. |

A `silent-wrong` coupling caught at `rc` is a scheduled task. The same one caught at
`shipped` is a live data-quality bug. **Stage is the multiplier**, and it is the whole
reason the Release Radar sits above the registry.

### Modifiers

- **Blast radius** — every install / only when an option is enabled / only alongside a
  specific third-party plugin. Option-gated couplings drop a level; the container code
  and data layer affect every install and do not.
- **Would anything else catch it?** A coupling the test suite genuinely covers drops a
  level. A coupling the suite *appears* to cover but cannot **rises** a level — a green
  suite that cannot fail is read as evidence, so it is worse than no test (UC-3).
- **Reversibility** — a coupling whose breakage corrupts already-collected analytics
  data cannot be fixed retroactively; treat as one level higher.

---

## Release Radar

Refreshed every sweep. `We declare` is read from the plugin header and `readme.txt`,
so the gap between what upstream ships and what we claim is visible in one row.

| Upstream | Stable | Pre-release | Expected GA | We declare | Action |
|---|---|---|---|---|---|
| WordPress core | **7.0.2** [x] 2026-08-05 (S1) | **7.1-beta4** [x] 2026-08-05 (S1) | RC expected 2026-08-05 | `Requires at least: 6.3` / `Tested up to: 6.9.4` | **behind one major**; RC window opening — D4 |
| WooCommerce | **11.0.0** [x] 2026-08-05 (S1) | [ ] not reached | — | `WC requires at least: 5.0` / `WC tested up to: 10.6.1` | **behind one major** — D4 |
| Contact Form 7 | **6.1.6** [x] 2026-08-05 (S1) | [ ] not reached | — | (undeclared — no minimum stated) | declare a floor |
| Gutenberg (for `@wordpress/components`) | [ ] | [ ] | — | n/a — unpinned runtime external | U76 still unverified |
| PHP | — | — | — | `Requires PHP: 8.0` (CI tests 8.2, 8.4 — **executes the floor in no job**) | D5 |

Upstream's own declared floors, from the same probe: WooCommerce 11.0.0 requires WP 6.9
/ PHP 7.4; Contact Form 7 6.1.6 requires WP 6.7 / PHP 7.4. Both already declare
`tested: 7.0.2` — they support current core and we do not claim to.

An empty `Pre-release` cell must mean "no pre-release is currently open" — never "we
failed to look". A failed lookup is recorded in the Sweeps table as `fetch-failed`.

---

## Dependency Registry

Every external coupling gets a row. `Failure` uses the rubric vocabulary. `Cadence` is
`every-run` / `quarterly` / `on-WP-release` / `on-WC-release` / `on-demand`. `Watch`
names the pre-release channel that buys lead time, where one exists — Google doc pages
have none, which is exactly why they are `every-run`.

### A. WordPress core

| ID | Dependency | Coupling site | Source / check | Watch | Failure | Cadence | Last verified |
|---|---|---|---|---|---|---|---|
| U1 | WP version floor `6.3` — **5 sites** | plugin header; `readme.txt`; runtime `version_compare`; `phpcs.xml` `minimum_wp_version`; `.claude/CLAUDE.md` Requirements | grep agreement | — | loud | every-run | [x] 2026-08-05 (S1) all agree |
| U2 | `Tested up to: 6.9.4` claim | `readme.txt` | wp.org core API | U73 | deprecation | every-run | [ ] |
| U3 | Script `strategy` array (`defer`) — the reason for the 6.3 floor | `src/Module/AbstractModule.php` `enqueue_script()`; `src/Modules/MediaEvents/MediaEventsModule.php` | core script API | U74 | silent-missing | on-WP-release | [ ] |
| U4 | `wp_add_inline_script` + `wp_script_is(…,'done')` fallback | `src/Modules/WooCommerce/PageDataLayer.php` `print_deferred_checkout_js()` | `WP_Dependencies` behavior | U74 | silent-missing | on-WP-release | [ ] |
| U5 | `wp_register_script()` with empty `$src` as an inline carrier | `src/Frontend/DataLayer.php` | core script API | U74 | silent-missing | on-WP-release | [ ] |
| U6 | `wp_kses()` ampersand entity behavior | `src/Frontend/ScriptTag.php` `print_script_block()` / `restore_script_ampersands()` | core kses behavior | U74 | silent-wrong | on-WP-release | [ ] |
| U7 | `safe_style_css` / `safecss_filter_attr()` stripping `display`+`visibility` | `src/Frontend/ContainerCode.php` `the_tag()` | core kses behavior | U74 | loud | on-WP-release | [ ] |
| U8 | `rest_pre_serve_request` at priority 11, after core `rest_send_cors_headers` | `src/RestCors.php` | core REST behavior | U74 | silent-missing | on-WP-release | [ ] |
| U9 | `feature=oembed` literal spliced to inject `enablejsapi=1` | `src/Modules/MediaEvents/MediaEventsModule.php` `enable_youtube_js_api()` | core oEmbed + YouTube | — | silent-missing | quarterly | [ ] |
| U10 | Deprecated block name `core-embed/youtube` | `src/Modules/MediaEvents/MediaEventsModule.php` | core block registry | U74 | silent-missing | on-WP-release | [ ] |
| U11 | REST nonce lifetime / `wp_get_session_token()` | `src/Modules/VisitorData/VisitorDataModule.php`, `VisitorDataEndpoint.php` | core auth behavior | U74 | loud | on-WP-release | [ ] |
| U12 | Guarded core helpers: `wp_get_environment_type()`, `is_plugin_active()`, `amp_is_request()` | `src/Frontend/ContainerCode.php`, `src/Admin/Notices.php`, `src/Modules/Amp/AmpModule.php` | — | — | loud | quarterly | [-] UB-3 |
| U13 | `$GLOBALS['wp_version']` read directly in the requirements gate | plugin main file | core global | U74 | loud | on-WP-release | [ ] |
| U14 | `apply_filters_deprecated()` / `_deprecated_hook()` per-call notice behavior | `src/Modules/WooCommerce/ProductData.php` | core deprecation API | U74 | loud | on-WP-release | [ ] |

### B. WooCommerce

| ID | Dependency | Coupling site | Source / check | Watch | Failure | Cadence | Last verified |
|---|---|---|---|---|---|---|---|
| U15 | WC version floor `5.0` — **2 copies that can drift** | plugin header `WC requires at least`; `src/Modules/WooCommerce/WooCommerceModule.php` anchor `MIN_WC_VERSION` | grep agreement | — | loud | every-run | [x] 2026-08-05 (S1) both `5.0` |
| U16 | `WC tested up to: 10.6.1` claim | plugin header | wp.org plugin API | U77 | deprecation | every-run | [ ] |
| U17 | HPOS feature slug `custom_order_tables` | plugin main file, `before_woocommerce_init` | WC FeaturesUtil | U77 | loud | on-WC-release | [ ] |
| U18 | ~30 `woocommerce_*` actions/filters registered | `src/Modules/WooCommerce/WooCommerceModule.php` `register_frontend_hooks()` | WC hook reference | U77 | silent-missing | on-WC-release | [ ] |
| U19 | WC CRUD surface (`wc_get_order`, `wc_get_product`, order getters, `WC()->cart/session/customer`) | `src/Modules/WooCommerce/*.php` | WC public API | U77 | loud | on-WC-release | [ ] |
| U20 | **WC-Admin internal** `…\Reports\Orders\Stats\DataStore::is_returning_customer()` — unguarded | `src/Modules/WooCommerce/ProductData.php` | WC internals (no promise) | U77 | loud | on-WC-release | [ ] |
| U21 | `CartCheckoutUtils::is_cart_block_default()` / `is_checkout_block_default()` (guarded) | `src/Modules/WooCommerce/WooCommerceModule.php` | WC Blocks | U77 | silent-missing | on-WC-release | [ ] |
| U22 | Store API `ExtendSchema`, `ProductSchema::IDENTIFIER`, `CartItemSchema::IDENTIFIER`; cart-item extension values must be strings | `src/Modules/WooCommerce/StoreApiData.php` | WC Store API | U77 | silent-wrong | on-WC-release | [ ] |
| U23 | Block data stores `wc/store/cart`, `wc/store/payment`; selectors `getCartData`, `getActivePaymentMethod`; payload shape | `js/frontend/gtm4wp-woocommerce-blocks.js`, `js/frontend/lib/gtm4wp-blocks-cart-diff.js` | WC Blocks | U77 | silent-missing | on-WC-release | [ ] |
| U24 | ~40 classic WC DOM selectors | `js/frontend/gtm4wp-woocommerce.js` | WC templates (unversioned) | U77 | silent-missing | on-WC-release | [ ] |
| U25 | jQuery WC events `found_variation`, `checkout_place_order`, `ajaxSuccess` sniff | `js/frontend/gtm4wp-woocommerce.js` | WC templates | U77 | silent-missing | on-WC-release | [ ] |
| U26 | Server-side regex over block markup + Product Collection collection slugs | `src/Modules/WooCommerce/ListTracking.php` | WC Blocks markup | U77 | silent-missing | on-WC-release | [ ] |
| U27 | WC template filename literal `content-widget-product.php` | `src/Modules/WooCommerce/ListTracking.php` | WC templates | U77 | silent-missing | on-WC-release | [ ] |
| U28 | WC option/taxonomy literals: `woocommerce_tax_display_shop`, `product_cat`, `wc-` status prefix | `src/Modules/WooCommerce/{ListTracking,PageDataLayer,Helpers,AdminSchema}.php` | WC data model | U77 | silent-wrong | on-WC-release | [ ] |
| U29 | `WC_Product` / `WC_Product_Variation` `instanceof` structural variation detection | `src/Modules/WooCommerce/ProductData.php` | WC class hierarchy | U77 | silent-wrong | on-WC-release | [ ] |
| U30 | Cross-sell block selectors `.wp-block-woocommerce-cart-cross-sells-block`, `.wc-block-cart-cross-sells-product` | `js/frontend/gtm4wp-woocommerce-blocks.js` | WC Blocks markup | U77 | silent-missing | on-WC-release | [ ] |

### C. Third-party plugins & themes

| ID | Dependency | Coupling site | Source / check | Watch | Failure | Cadence | Last verified |
|---|---|---|---|---|---|---|---|
| U31 | CF7 hook `wpcf7_form_additional_atts` + `\WPCF7_ContactForm::get_current()->title()` | `src/Modules/ContactForm7/ContactForm7Module.php` | CF7 releases | U79 | loud | quarterly | [ ] |
| U32 | CF7's 8 DOM events + `detail` shape + `.wpcf7 form` / `input[name="_wpcf7"]` selectors | `js/frontend/gtm4wp-contact-form-7-tracker.js` | CF7 releases | U79 | silent-missing | quarterly | [ ] |
| U33 | CheckoutWC `cfw_step_changed` / `cfw_before_submit` + substring match on *their* step names | `js/frontend/gtm4wp-woocommerce.js` | CheckoutWC releases | — | silent-missing | quarterly | [ ] |
| U34 | WC Quick View: `wc-api=WC_Quick_View` URL literal ↔ `#gtm4wp_quickview_data` (3-way, UC-4) | `src/Modules/WooCommerce/ListTracking.php` + `js/frontend/gtm4wp-woocommerce.js` | Quick View plugins | — | silent-missing | quarterly | [ ] |
| U35 | WP Rocket `rocket_excluded_inline_js_content` | `src/Frontend/ContainerCode.php` | WP Rocket | — | loud | on-demand | [ ] |
| U36 | 6 theme/page-builder body-open fallback actions (Genesis, GeneratePress, Elementor, Oxygen, Beaver Builder, `body_open`) | `src/Frontend/ContainerCode.php` | each vendor | — | silent-missing | quarterly | [ ] |
| U37 | WPML `wpml_current_language` / Polylang `pll_current_language()` (guarded) | `src/Modules/PageVariables/PageVariablesModule.php` | — | — | silent-missing | on-demand | [-] UB-3 |
| U38 | Yoast `yoast_get_primary_term_id()`; Rank Math **raw meta key** `rank_math_primary_{taxonomy}` | `src/Modules/WooCommerce/Helpers.php`, `src/Modules/PageVariables/PageVariablesModule.php` | Yoast / Rank Math | — | silent-missing | quarterly | [ ] |
| U39 | PublishPress Authors `get_multiple_authors()` (guarded) | `src/Modules/PageVariables/PageVariablesModule.php` | — | — | silent-missing | on-demand | [-] UB-3 |
| U40 | Cookiebot attribute `data-cookieconsent="ignore"` | `src/Frontend/ScriptTag.php` | Cookiebot | — | loud | quarterly | [ ] |
| U41 | CookieLawInfo: overrides global `CookieLawInfo_Accept_Callback`, reads `window.CLI.consent` | `src/Modules/ConsentMode/ConsentModeModule.php` | WebToffee GDPR | — | silent-wrong | quarterly | [ ] |
| U42 | CookieYes events `cookieyes_consent_update` / `cookieyes_banner_load` + detail fields | `src/Modules/ConsentMode/ConsentModeModule.php` | CookieYes | — | silent-wrong | quarterly | [ ] |
| U43 | Axeptio: SDK URL, `window.axeptioSettings` / `window._axcb`, `cookies:complete` event | `src/Modules/ConsentMode/Axeptio.php` | Axeptio | — | silent-wrong | quarterly | [ ] |
| U44 | Axeptio admin API `https://client.axept.io/{projectId}.json` → `data.cookies[]` shape | `js/admin/components/AxeptioVersionControl.js` | Axeptio | — | loud | quarterly | [ ] |
| U45 | amp-wp `amp_analytics_entries` filter + entry shape (`attributes`, `config_data`) | `src/Modules/Amp/AmpModule.php` | amp-wp releases | — | silent-missing | quarterly | [ ] |
| U46 | Competing-plugin basenames for the deactivation notice | `src/Admin/Notices.php` | — | — | loud | on-demand | [ ] |

### D. Google specifications

No versions, no changelogs, no feeds. Verified by re-checking the recorded **claim**
(UD-3), and mostly `every-run` because there is no channel that would warn us.

| ID | Dependency | Coupling site | Source / check | Watch | Failure | Cadence | Last verified |
|---|---|---|---|---|---|---|---|
| U47 | GTM container snippet, byte-exact (UB-1) | `src/Frontend/ContainerCode.php` `container_loader()` | tagmanager quickstart docs | — | silent-missing | quarterly | [ ] |
| U48 | Loader host `www.googletagmanager.com`, `gtm.js`, `ns.html` noscript markup | `src/Frontend/ContainerCode.php` | tagmanager docs | — | silent-missing | quarterly | [ ] |
| U49 | Environment params `gtm_auth` / `gtm_preview` / `gtm_cookies_win` | `src/Frontend/ContainerCode.php` `container_environment()` | tagmanager environments docs | — | loud | quarterly | [ ] |
| U50 | Identifier grammar `/^GTM-[A-Z0-9]+$/`, `/^env-[0-9]+$/` (UC-5) | `src/Modules/Container/ContainerRows.php:38-41` | Google id formats | — | loud | quarterly | [ ] |
| U51 | Server-side tagging loader URL contract (custom domain/path, omit-id mode) | `src/Frontend/ContainerCode.php` | sGTM docs | — | silent-missing | quarterly | [ ] |
| U52 | Consent Mode v2 signal names — **2 copies** (UC-6) | `src/Frontend/ConsentDefaults.php`; `src/Modules/ConsentMode/Axeptio.php` | consent mode docs | — | silent-wrong | every-run | [ ] |
| U53 | **Tag-restriction entity ids** — 71 tags / 9 triggers / 16 variables / 1 group class (97 total, measured at `1daeddf`); `gtm.blocklist` / `gtm.allowlist` | `src/Modules/Blacklist/BlacklistModule.php` — anchors `TAG_IDS`, `TRIGGER_IDS`, `VARIABLE_IDS`, `GROUP_CLASS_IDS` | https://developers.google.com/tag-platform/tag-manager/restrict | — | silent-wrong | every-run | [ ] |
| U54 | **GA4 e-commerce event names (9)** — the core of the e-commerce integration | `src/Modules/WooCommerce/{PageDataLayer,ListTracking,ProductData}.php`; `js/frontend/gtm4wp-woocommerce*.js` | https://developers.google.com/analytics/devguides/collection/ga4/reference/events?client_type=gtm | — | silent-wrong | every-run | [ ] |
| U55 | **GA4 event + item parameter names**, incl. the `item_categoryN` cap of 5 | `src/Modules/WooCommerce/ProductData.php` | same page as U54 (`client_type=gtm` — documents the dataLayer shape, not gtag) | — | silent-wrong | every-run | [ ] |
| U56 | Google Ads `BUSINESS_VERTICALS` (9) + `flights`/`travel` → `destination` id mapping | `src/Modules/WooCommerce/Helpers.php:26-47` | Google Ads remarketing docs | — | silent-wrong | quarterly | [ ] |
| U57 | Enhanced Conversions `user_data` field names + sha256 normalization rules (incl. the gmail dot rule) | `src/Modules/WooCommerce/ProductData.php`, `src/Modules/WooCommerce/Helpers.php` | Google Ads EC docs | — | silent-wrong | quarterly | [ ] |
| U58 | `new_customer` signal semantics | `src/Modules/WooCommerce/ProductData.php` | Google Ads answer 9917012 | — | silent-wrong | quarterly | [ ] |
| U59 | GTM native video variable names `gtm.video*` + status vocabulary | `js/frontend/lib/native-video-params.js` | GTM video trigger docs | — | silent-wrong | quarterly | [ ] |
| U60 | AMP `https://www.googletagmanager.com/amp.json?id=…&gtm.url=SOURCE_URL` + `SOURCE_URL` macro | `src/Modules/Amp/AmpModule.php` | GTM AMP docs | — | silent-missing | quarterly | [ ] |
| U61 | `ecommerce: null` clear pattern | `js/frontend/gtm4wp-ecommerce-generic.js` | GA4 docs | — | silent-wrong | quarterly | [ ] |
| U62 | CF7 GA4 event names `form_start` / `form_submit` / `generate_lead` | `js/frontend/gtm4wp-contact-form-7-tracker.js` | GA4 form events | — | silent-wrong | quarterly | [ ] |

### E. Other external services & infrastructure

| ID | Dependency | Coupling site | Source / check | Watch | Failure | Cadence | Last verified |
|---|---|---|---|---|---|---|---|
| U63 | Cloudflare `HTTP_CF_IPCOUNTRY` header | `src/Modules/PageVariables/PageVariablesModule.php` | Cloudflare docs | — | silent-missing | quarterly | [ ] |
| U64 | Proxy client-IP header semantics — `X-Forwarded-For` appends (rightmost), `CF-Connecting-IP`/`True-Client-IP`/`X-Real-IP` replace | `src/Frontend/VisitorIp.php` | proxy vendor docs | — | silent-wrong | quarterly | [ ] |
| U65 | 7 remote player SDK URLs — Vimeo, SoundCloud, Dailymotion, Mixcloud, **Cloudflare Stream `sdk.latest.js` (floating, UD-6)**, Spotify, Twitch | `src/Modules/MediaEvents/MediaEventsModule.php` | reachability + API shape | — | silent-missing | quarterly | [ ] |
| U66 | Player API globals: `YT.Player`, `Vimeo.Player`, `SC.Widget`, `DM.player`, `Mixcloud.PlayerWidget`, `Stream`, `window._wq`, `jwplayer`, `Twitch.Player`, `onSpotifyIframeApiReady` | `js/frontend/gtm4wp-*.js` | vendor SDK docs | — | silent-missing | quarterly | [ ] |
| U67 | Embed iframe host selectors (9 vendors) + constructed media URLs | `js/frontend/gtm4wp-*.js` | vendor embed formats | — | silent-missing | quarterly | [ ] |
| U68 | VideoPress `postMessage` origin allow-list | `js/frontend/gtm4wp-videopress.js` | Automattic | — | silent-missing | quarterly | [ ] |
| U69 | UA-CH `navigator.userAgentData.getHighEntropyValues()` + Chromium GREASE brand filter regex | `js/frontend/gtm4wp-client-device-data.js` | Chromium UA-CH spec | — | silent-wrong | quarterly | [ ] |
| U70 | Full-page cache behavior assumptions (LiteSpeed, WP Rocket, Varnish, Cloudflare APO) | `src/Modules/VisitorData/*`, `js/frontend/gtm4wp-visitor-data.js`, `docs/dev/cache-safe-data-layer.md` | — | — | silent-wrong | on-demand | [ ] |
| U71 | PHP↔JS name contracts: `gtm4wp_item_list_attr`, `gtm4wp_orderid_tracked`, `gtm4wp_woo_event`, `gtm4wp_readded_to_cart`, `div.gtm4wp-wc-visitor-data` (UC-4, internal) | `src/Modules/WooCommerce/{Helpers,ProductData,PageDataLayer}.php` ↔ `js/frontend/*.js` | internal grep | — | silent-missing | every-run | [ ] |

### F. Toolchain & build

| ID | Dependency | Coupling site | Source / check | Watch | Failure | Cadence | Last verified |
|---|---|---|---|---|---|---|---|
| U72 | PHP floor `8.0` — **6 sites**; CI executes **none** of them (matrix is 8.2 + 8.4) | plugin header, `readme.txt`, `composer.json`, `phpcs.xml` `testVersion`, runtime `version_compare`, `.claude/CLAUDE.md` | grep agreement + CI matrix | — | loud | every-run | [x] 2026-08-05 (S1) agree; **D5** on CI |
| U73 | Composer direct deps (phpcs, wpcs, php-compatibility, phpunit, brain/monkey) | `composer.json` | `composer outdated --direct --format=json` (exit 0 either way) | — | loud | every-run | [x] 2026-08-05 (S1) → D7 |
| U88 | `composer.lock` is **git-ignored**, so CI resolves dependencies fresh on every run | root `.gitignore`, `.github/workflows/ci.yml` | `git ls-files --error-unmatch composer.lock` | — | loud | every-run | [x] 2026-08-05 (S1) confirmed untracked → D6 |
| U74 | npm devDeps + the hand-maintained `overrides` block (11 transitive pins, count measured at `1daeddf`) | `package.json` | `npm outdated --json` (**exits 1 when anything is outdated**), `npm audit` | — | loud | every-run | [x] 2026-08-05 (S1) `outdated` only → D7; **`audit` not run** |
| U75 | `@wordpress/scripts` internal config shape (webpack `defaultConfig` spread; jest `hasBabelConfig()` avoidance) | `webpack.config.js`, `jest.config.js` | wp-scripts releases | — | loud | quarterly | [ ] |
| U76 | **`@wordpress/components` `__next*` opt-in props** — unpinned runtime external, breaks on the *user's* WP update, test stand-in cannot catch it (UC-3, UC-7) | `js/admin/components/*.js`; stand-in `js/admin/test-support/wp-components.js` | Gutenberg releases | U82 | silent-wrong | on-WP-release | [ ] |
| U77 | Build-asset declared handles (`wp-components`, `wp-element`, `wp-data`, `wp-api-fetch`, `wp-i18n`, `react-jsx-runtime`) | `build/*.asset.php` | core script handles | U78 | loud | on-WP-release | [ ] |
| U78 | Patchwork redefining PHP internals `headers_sent`, `setcookie` | `patchwork.json` | Patchwork releases | — | loud | quarterly | [ ] |
| U79 | Release packaging allow-list `DIST_FILES` — a new required top-level dir not listed ships broken | `tools/build-release.js` | grep vs repo tree | — | silent-missing | every-run | [ ] |

### G. Watch channels

These are dependencies too (UD-9). A channel that stops resolving makes every row that
relies on it report a false all-clear.

| ID | Channel | Endpoint | Feeds | Failure | Cadence | Last verified |
|---|---|---|---|---|---|---|
| U80 | WP core stable | `https://api.wordpress.org/core/version-check/1.7/` → `.offers[0].version` | U2, Radar | silent-wrong | every-run | [x] 2026-08-05 (S1) → `7.0.2` |
| U81 | WP core beta/RC | `https://api.wordpress.org/core/version-check/1.7/?channel=beta` → `.offers[0].version` | Radar, U3–U14 | silent-wrong | every-run | [x] 2026-08-05 (S1) → `7.1-beta4` |
| U82 | WP core dev notes / Field Guide | `https://make.wordpress.org/core/tag/dev-notes/feed/` · `https://make.wordpress.org/core/tag/field-guide/` | U3–U14 (UD-8) | silent-wrong | every-run | [~] 2026-08-05 (S1) channel resolves; **7.0 Field Guide (2026-05-14) never swept**, 7.1 not published yet |
| U83 | WooCommerce stable | `https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=woocommerce` → `.version` | U16, Radar | silent-wrong | every-run | [x] 2026-08-05 (S1) → `11.0.0` |
| U84 | WooCommerce beta/RC | `https://api.github.com/repos/woocommerce/woocommerce/releases` | Radar, U17–U30 | silent-wrong | every-run | [ ] |
| U85 | WooCommerce developer blog | `https://developer.woocommerce.com/blog/` | U17–U30 (UD-8) | silent-wrong | quarterly | [ ] |
| U86 | Gutenberg releases | `https://api.github.com/repos/WordPress/gutenberg/releases` | U76 (earliest `__next*` warning) | silent-wrong | every-run | [ ] |
| U89 | **wp.org PHP-version usage statistics** — the evidence channel for the 8.0 floor decision (D7b trigger, UB-5's "change of circumstance") | `https://wordpress.org/about/stats/` (PHP version breakdown) | U72, D5, D7b | — | quarterly | [ ] never checked |
| U87 | Contact Form 7 | `https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=contact-form-7` → `.version` | U31, U32 | silent-wrong | every-run | [x] 2026-08-05 (S1) → `6.1.6` |

---

## Tracked Claims (Tier 1)

The registry says *what* is coupled. These blocks say *what exactly to re-verify*, for
the couplings where "check it" is otherwise too vague to act on. Storing the claim
rather than a page snapshot is deliberate (UD-3).

### U53 — GTM tag-restriction entity ids ⭐

- **We depend on:** `TAG_IDS` (71), `TRIGGER_IDS` (9), `VARIABLE_IDS` (16),
  `GROUP_CLASS_IDS` (1) — 97 total — in `src/Modules/Blacklist/BlacklistModule.php`
  matching Google's published entity list; emitted as `gtm.blocklist` /
  `gtm.allowlist`.
- **Counting rule** (so the number is reproducible): lines matching `^\t\t'…',` inside
  each `const` block. Measured 71 / 9 / 16 / 1 at `1daeddf`, 2026-08-05.
- **Claim (tags, triggers, variables):** every id in `TAG_IDS`, `TRIGGER_IDS` and
  `VARIABLE_IDS` still appears on the source page, **and** the page lists no id absent
  from them.
- **Claim (group classes) — narrowed by decision, 2026-08-05:** `sandboxedScripts` still
  exists upstream and still means what we rely on (controlling GTM community
  templates). The plugin deliberately carries **only** this one; the other seven
  documented group classes are out of scope, so their absence is **not** drift and must
  not be re-reported (UB-4). Drift for this sub-claim would be `sandboxedScripts` being
  renamed, removed, or changing meaning — not the count.
- **Re-derive the counts** from the file before comparing — the numbers above are a
  measurement, not a fact. *(This entry was first seeded with 69/94 from a summary
  rather than a count; the file said 71/97. That is the exact failure this rule
  exists to prevent.)*
- **Failure:** `silent-wrong`. An unknown id is dropped by the validator and the
  restriction the admin configured silently stops applying. The code acknowledges this.
- **Detection:** `none` — `tests/unit/Modules/Blacklist/` pins our list to itself,
  which proves we emit what we intend and says nothing about whether the intent is
  current. **Canary wanted:** one live check against a real container (recorded as the
  open residual on `.security/` finding #122).
- **Sweep 1 result (2026-08-05, `1daeddf`):** partially drifted.
  - `TRIGGER_IDS` 9/9 exact ✅ · `VARIABLE_IDS` 16/16 exact ✅ ·
    `gtm.allowlist` / `gtm.blocklist` key names confirmed current ✅
  - `TAG_IDS` **71 of 72** — Google documents `mf` (Mouseflow), the plugin does not
    (**D2**). Nothing in the plugin is undocumented upstream; the diff is one-directional.
  - `GROUP_CLASS_IDS` **1 of 8** — upstream documents `customPixels`, `customScripts`,
    `google`, `html`, `nonGooglePixels`, `nonGoogleScripts`, `nonGoogleIframes`,
    `sandboxedScripts`. **D3 — accepted, not a gap.** `sandboxedScripts` is carried for
    one purpose: controlling GTM **community templates**. The other seven are out of
    scope by decision. **The claim below is narrowed to match** — a future sweep that
    re-reports "1 of 8" is reading the wrong claim.
  - Upstream ambiguity worth knowing: `html` appears in **both** the tag-id list and
    the group-class list. The validator does not distinguish them.
  - `Last verified` stays `[ ]` because the entry drifted — per the three-outcome rule
    it advances only when the claim holds.
- **D2 implementation scope (measured 2026-08-05).** Re-adding `mf` touches **five**
  sites, not the two the registry row implied. `mf` was not merely omitted — it is
  actively removed at three layers:
  1. `src/Modules/Blacklist/BlacklistModule.php` — add `'mf'` to `TAG_IDS`, between
     `'messagemate'` and `'ndcr'` (the constant follows the source page's order).
  2. `src/Modules/Blacklist/AdminSchema.php` — add the matching label between the same
     two neighbours; the schema mirrors the keys, so without this the id has no UI.
  3. `src/Modules/Blacklist/BlacklistModule.php` docblock — corrects a claim that is
     now false ("removed … mf (Mouseflow)").
  4. **`src/Migration.php` — `REMOVED_BLACKLIST_ENTITIES = array( 'ua', 'mf' )`.** This
     is the one that would silently defeat the fix: the migration strips `mf` from
     saved user options on upgrade, so re-adding it to the list alone would still see
     the user's selection wiped. `'ua'` stays — upstream really did drop it.
  5. `tests/unit/Modules/BlacklistModuleTest.php` — line 48 asserts `mf` is **absent**,
     with the message *"Mouseflow tag is no longer documented."* That assertion is a
     false claim about upstream, pinned into the suite. It must invert, and the
     line-183 comment calling `mf` a "stale 1.x entry" must be corrected.
  - **Pattern instance (UC-3 / UD-1):** the suite pinned our *intent* and asserted it
    against nothing external, so it locked in the wrong belief and would have kept
    doing so indefinitely. Its failure on the fix is the guard working, not a problem.
- **History:**
  - The in-file docblock names the source page and records a diff versus 1.x (added
    `gaawc`, `gaawe`, `gas`; removed `ua`, `mf`) — but carries **no date**, so it
    cannot answer "how stale is this?". A comment is not a control (UD-2).
  - `.security/` **#122** (Medium, fixed): the module emitted key names appearing
    nowhere in Google's current documentation, while three docblocks cited that page
    as their authority. Found by *checking the page* rather than reasoning about it.
  - `.security/` **#121** (filed Low @0.5 confidence, re-rated **High** after
    measurement): an empty companion key disabled the entire container. The original
    reasoning — "the feature evidently works, so the empty key must be inert" — was
    backwards. See UD-11.

### U76 — `@wordpress/components` `__next*` opt-in props ⭐

- **We depend on:** `__next40pxDefaultSize` and `__nextHasNoMarginBottom` still being
  accepted props across `js/admin/components/*.js`.
- **Claim:** the current Gutenberg release still accepts both props on the components
  we pass them to, and has not flipped the default and removed them.
- **Why Gutenberg, not WP core:** the flip lands in a Gutenberg release months before
  the WordPress core release that contains it. That is the lead time.
- **Failure:** `silent-wrong` — React unknown-prop warnings, or the controls silently
  change size and spacing.
- **Would a test catch it?** **No, and worse:** `js/admin/test-support/wp-components.js`
  accepts and ignores these props, so the suite is green either way (UC-3). Severity
  rises a level.

### U1 / U15 / U72 — version floors written N times

- **Claim:** every copy of each floor agrees.
  - **PHP `8.0`, 6 sites:** plugin header, `readme.txt`, `composer.json`,
    `phpcs.xml` `testVersion` (carries a `-` suffix — compare the numeric prefix),
    the runtime `version_compare( PHP_VERSION, … )`, `.claude/CLAUDE.md`.
  - **WP `6.3`, 5 sites:** plugin header, `readme.txt`, the runtime gate,
    `phpcs.xml` `minimum_wp_version`, `.claude/CLAUDE.md`.
  - **WC `5.0`, 2 sites:** plugin header `WC requires at least` **and**
    `WooCommerceModule::MIN_WC_VERSION`.
- **The likeliest single failure:** header vs `MIN_WC_VERSION`, because bumping a
  header is a documentation act and bumping a constant is a code act, done in
  different changes (UC-1).
- **The check over-reports by design.** A floor appearing more than once is the point —
  the assertion is that they *match*, not that they are unique.
- **It under-reports** a floor stated in prose (a docblock, a skill file, a support
  answer). Adding a site means adding it to this list.
- **Also assert — CI does not exercise the floor.** The matrix runs PHP 8.2 and 8.4
  while every site declares 8.0. The floor is asserted in six places and executed in
  none; only the PHPCompatibility sniff covers it statically. **D5, decided: matrix
  becomes `[ '8.0', '8.4' ]`.**
- **Standing decision — the 8.0 floor is deliberate and does not need re-litigating
  (2026-08-05, UB-5).** PHP 8.0 has been end-of-life since Nov 2023, and a future sweep
  will notice. It stays anyway, for reasons on record: many hosts still run it;
  **WordPress core itself requires only PHP 7.4** (measured this sweep from
  `version-check/1.7/` → `php_version`), as do WooCommerce 11.0.0 and CF7 6.1.6; and
  GTM4WP will not force users onto a PHP upgrade for the sake of one plugin among many.
  A sweep may report that the floor is EOL **once per change of circumstance** — not
  every run.
- **Upstream floors measured 2026-08-05:** WP core 7.0.2 → PHP **7.4**; WooCommerce
  11.0.0 → **7.4**; CF7 6.1.6 → **7.4**. GTM4WP's 8.0 is stricter than all three.
- **⚠️ D5 × D7 COLLISION — D5 cannot be implemented as decided (found 2026-08-05).**
  `phpunit/phpunit` (current, v11) requires **`php >=8.2`**. A CI job on PHP 8.0 that
  runs `composer install` therefore **fails at dependency resolution**, before a single
  test executes. Bumping to PHPUnit 13 (D7b) raises that requirement further, not lower.
  So "add 8.0 to the matrix and run the existing steps" is not achievable.
  - What the 8.0 job is actually *for* is catching "we used something newer than our
    declared floor". That is reachable without PHPUnit:
    - `php -l` across `src/`, `compat/` and the main file → catches 8.1+ **syntax**
      (enums, `readonly`, `never`, first-class callables) at parse time.
    - `phpcs` with PHPCompatibility `testVersion 8.0-` → catches newer **function/API**
      use. Note this is static, so it already runs correctly on the 8.4 job; the PHP
      version of the *runner* does not change what `testVersion` checks.
  - Resolving the install itself needs `--ignore-platform-reqs` or a separate minimal
    dependency set, because phpunit's `>=8.2` blocks resolution regardless of whether
    the job intends to run it.
  - **Recommended shape:** 8.0 job = `php -l` (+ optionally `phpcs`); 8.4 job = the
    full `phpcs` + `phpunit` as today. Needs maintainer confirmation — it is narrower
    than what option A implied.
- **Open note for a later sweep (not a finding):** the "newest" leg of the matrix is
  itself an upstream dependency and drifts. PHP 8.5 shipped Nov 2025 and is untested;
  8.4 is not the newest any more. Worth a decision at some point, not now.
- **Related (U88):** `composer.lock` is git-ignored, so CI resolves fresh rather than
  installing a pinned set. Any claim elsewhere that the lockfile is committed is false
  and is itself a finding.

### U6 — `wp_kses()` ampersand behavior

- **We depend on:** `wp_kses()` entity-encoding bare `&` in script bodies and leaving
  other characters alone, so `ScriptTag::print_script_block()` can `str_replace`
  `&amp;` back to `&` and `restore_script_ampersands()` can undo it in markup.
- **Claim:** core kses still encodes `&` and only `&`, in the paths this file uses.
- **Failure:** `silent-wrong` — emitted JavaScript changes byte-for-byte. This is the
  foundation the whole inline-script output path stands on.
- **Check via:** Make/core dev notes (U82), not a version number (UD-8).

### U20 — WC-Admin internal `is_returning_customer()`

- **Claim:** `\Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore` still
  exists and still exposes `is_returning_customer( $order )` with the same semantics.
- **Failure:** `loud` — fatal if the class or method disappears, since the call is
  unguarded (UC-2).
- **Check against:** the WooCommerce RC before each GA, not after.

### U22 — Store API cart-item extension values must be strings

- **Claim:** `ExtendSchema::register_endpoint_data()` with
  `schema_type => ARRAY_A` still requires cart-item extension values to be strings,
  and `ProductSchema::IDENTIFIER` / `CartItemSchema::IDENTIFIER` still resolve.
- **Failure:** `silent-wrong` — the item is `wp_json_encode`d and re-parsed client
  side, so a type change corrupts item data rather than erroring.

### U24 / U25 — classic WooCommerce DOM selectors and jQuery events

- **Claim:** the ~40 selectors and the `found_variation` / `checkout_place_order`
  events still match WooCommerce's shipped templates.
- **How to verify:** this one cannot be read from docs (UD-5). It is verified by
  running the cart/checkout/product flows against the WooCommerce **RC**. If that has
  not happened, the honest record is `[ ]`, not a date.

### U47 — GTM container snippet, byte-exact

- **Claim:** Google's currently published loader snippet still matches the bytes in
  `ContainerCode::container_loader()`.
- **Blessing boundary (UB-1):** our formatting is deliberate and guarded by byte-exact
  tests. Drift from *Google's published shape* is a finding; drift from our own
  preferred formatting is not.

### U50 — Google container identifier grammar

- **Claim:** every container id Google issues still matches `/^GTM-[A-Z0-9]+$/`, and
  every preview id still matches `/^env-[0-9]+$/`.
- **Failure:** `loud`, but it presents as user error — a site owner with a valid id is
  told it is invalid, at both output sinks (UC-5).
- **Signal to watch for:** support reports of "my container ID is rejected". Worth a
  cross-check against `/wporg-forum-review` and `/issue-review` findings.

### U52 — Consent Mode v2 signal names (two copies)

- **Claim (external):** the v2 signal set is still exactly `analytics_storage`,
  `ad_storage`, `ad_user_data`, `ad_personalization`, `functionality_storage`,
  `security_storage`, `personalization_storage`.
- **Claim (internal, UC-6):** `src/Frontend/ConsentDefaults.php` and
  `src/Modules/ConsentMode/Axeptio.php` still agree with **each other**. This is the
  likelier and cheaper failure, and nothing checks it.

### U54 / U55 — GA4 e-commerce events and parameter names ⭐

**The single highest-consequence Google coupling in the plugin.** These names are the
core of the e-commerce integration; a rename anywhere in this set breaks tracking for
every WooCommerce site running the plugin.

- **Source (canonical, use this exact URL):**
  `https://developers.google.com/analytics/devguides/collection/ga4/reference/events?client_type=gtm`
  The `client_type=gtm` query parameter is **load-bearing** — it switches the page to
  the dataLayer/GTM shape. The default (`gtag`) variant documents a different calling
  convention and must not be used to verify this plugin, which pushes to a dataLayer.
- **Claim (events):** the 9 event names the plugin emits — `view_item`,
  `view_item_list`, `select_item`, `add_to_cart`, `remove_from_cart`, `view_cart`,
  `begin_checkout`, `add_shipping_info`, `add_payment_info`, `purchase` — still appear
  on that page with the same names, and the page lists no *required* e-commerce event
  the plugin does not emit.
- **Claim (event-level params):** `currency`, `value`, `transaction_id`, `tax`,
  `shipping`, `coupon`, `affiliation`, `items` still carry those names, and the
  required/optional split is unchanged. `currency` + `value` being required alongside
  `items` is the constraint most often changed.
- **Claim (item params):** `item_id`, `item_name`, `affiliation`, `coupon`, `discount`,
  `index`, `item_brand`, `item_category`…`item_category5`, `item_list_id`,
  `item_list_name`, `item_variant`, `price`, `quantity` — and `item_category` levels are
  still capped at **5**, which the code hardcodes as `min( 5, … )`.
- **Failure:** `silent-wrong`. GA4 ignores unknown parameters rather than erroring, so
  a rename produces missing dimensions in reports, months later, with nothing on this
  side to see it (CP-1 / UD-2). Historically irreversible — data not collected cannot
  be back-filled — so it rises a level for reversibility.
- **Would a test catch it?** No. The suite pins the names to themselves, which proves
  we emit what we intend and says nothing about whether the intent is still current.
- **Cadence:** `every-run`, no exceptions. There is no version, no changelog and no
  feed on this page (UD-3) — the only way to know is to look.
- **Probe (mandatory sentinel, UD-14):** this page is **alphabetically ordered**. The
  extraction must contain **`view_item_list`** — the last event in sort order. If it
  does not, the fetch was truncated: record `fetch-failed` and record nothing as drift.
  Also ask the fetch to state explicitly whether the content was truncated and where.
- **Sweep 1 result (2026-08-05):**
  - ✅ `item_category` … `item_category5`, cap of 5 intact.
  - ✅ Events verified present: `add_payment_info`, `add_shipping_info`, `add_to_cart`,
    `begin_checkout`, `purchase` (plus `add_to_wishlist` and `refund`, which the plugin
    does not emit — coverage note, not drift).
  - ❌ **False positive, retracted.** The sweep first reported `view_item`,
    `view_item_list`, `select_item`, `view_cart` and `remove_from_cart` as
    undocumented. They are documented. The fetch truncated mid-`refund`, and every
    "missing" event is alphabetically at or after that cut. Caught by the maintainer,
    who opened the page and saw `#view_item`. This is the origin of UD-14 and of the
    sentinel rule above. Correctly recorded as `fetch-failed` rather than drift at the
    time, but the tell — contiguity in the page's own ordering — was there and missed.
  - ⚠️ **D1 — resolved as a gap, not drift (maintainer decision, 2026-08-05).**
    `new_customer` and `customer_type` are **two signals for two products**, not a
    rename:
    - Google Ads customer-acquisition uses **`new_customer`**
      (`support.google.com/google-ads/answer/12077475`) — what the plugin emits today.
    - The GA4 e-commerce reference documents **`customer_type`** (string
      `new`/`returning`) on `purchase`.
    **Decision: implement `customer_type` while keeping `new_customer`.** Both stay.
    Reclassified from "possible rename" to a coverage gap against the GA4 reference.
  - Coverage note (not drift): item-level `discount` and `location_id` are documented;
    check whether `ProductData` emits them.

### U57 — Enhanced Conversions normalization

- **Claim:** the `user_data` field names (`sha256_email_address`,
  `sha256_phone_number`, `address.sha256_*`) and the normalization rules
  (lowercase, strip whitespace from phone, strip `.` before `gmail.com`/`googlemail.com`)
  still match Google's spec.
- **Failure:** `silent-wrong` and **irreversible** — mis-normalized hashes simply fail
  to match, degrading conversion attribution with no error. Rises a level for
  reversibility.

### U56 — Google Ads business verticals

- **Claim:** the 9 values in `Helpers::BUSINESS_VERTICALS` are still the documented
  vertical list, and the `flights`/`travel` → `destination` id-variable mapping still
  holds.

### U65 — floating player SDKs

- **Claim:** each of the 7 SDK URLs still resolves and still exposes the global the
  tracker binds to (U66).
- **Special case:** Cloudflare Stream's `sdk.latest.js` is *by name* floating (UD-6) —
  there is no version on either side, so the only verification is behavioral.

### U79 — release packaging allow-list

- **Claim:** `DIST_FILES` in `tools/build-release.js` still lists every top-level path
  the plugin needs at runtime.
- **Failure:** `silent-missing` — the ZIP builds successfully and the plugin is broken
  once installed. Verified mechanically by diffing the allow-list against the repo's
  top-level runtime directories.

---

## Sweeps

| Sweep | Last run | Result summary |
|---|---|---|
| Version-floor agreement (U1, U15, U72) | 2026-08-05 (S1) | ✅ Clean: all sites agree — PHP `8.0` × 6, WP `6.3` × 5, WC `5.0` × 2. **But** no CI job executes the PHP floor (matrix 8.2/8.4) → D5. |
| Release Radar refresh (U80–U87) | 2026-08-05 (S1) | ⚠️ WP stable **7.0.2** / beta **7.1-beta4**; WC **11.0.0**; CF7 **6.1.6**. Both our "tested up to" claims are one major behind → D4. Gutenberg + WC pre-release not reached. |
| Package drift (`composer outdated --direct`, `npm outdated`) | 2026-08-05 (S1) | ⚠️ phpunit 11.5.56→13.2.6, php_codesniffer 3.13.5→4.0.1, @wordpress/scripts 30.27.0→**34.0.0**, react/react-dom 18.3.1→19.2.8, typescript 5.3.3→7.0.2 → D7. `npm audit` not run. |
| Coupling-site existence (every registry row's path + anchor still resolves) | 2026-08-05 (S1) | ✅ Clean: all 40 distinct registry paths resolve. |
| New-coupling detection (diff-scoped hunt for unregistered upstream strings) | [ ] | Not run — no base sha before this sweep (Run 0 was the seed). Starts at Sweep 2 from `1daeddf`. |
| Watch-channel health (each endpoint resolves and parses) | 2026-08-05 (S1) | ⚠️ U80/U81/U83/U87 resolved and parsed. U82 resolved but **no 7.1 Field Guide yet, and the 7.0 one (2026-05-14) has never been swept**. U84/U85/U86 not reached. |
| Internal duplicate-contract agreement (U52, U71) | [ ] | Not run this sweep. |
| Mirrored-list set diff (U53) | 2026-08-05 (S1) | ⚠️ Triggers 9/9 ✅, variables 16/16 ✅, `gtm.allowlist`/`gtm.blocklist` ✅. Tags **71 of 72** — `mf` documented upstream, absent here → D2. Group classes **1 of 8** → D3. |

History is appended in place, newest first, nested in parentheses — same convention as
the sibling systems.

---

## Drift Findings Log

Findings are numbered `D1…Dn`, globally, never reset per run. Status is
`open | fixed | wontfix | accepted`. `accepted` means the coupling is knowingly left
as-is with the risk recorded.

### Run 0 (seed) — 2026-08-05

**Reviewed at:** `1daeddf` · **Base:** — (initial seed) · **Fixes:** n/a

Seeded the system from a full-repo dependency inventory: 88 registry rows across
WordPress core, WooCommerce, third-party plugins and themes, Google specifications,
external services and media SDKs, the toolchain, and the watch channels themselves.
15 Tier-1 claim blocks written. No verification performed — every `Last verified` cell
is `[ ]` by design, so the first real sweep starts from an honest empty state rather
than an assumed-good one.

**Prior art this system inherits.** Two `.security/` findings on
`src/Modules/Blacklist/BlacklistModule.php` are upstream-drift findings that
`/code-review` caught without a lens designed for them:

- **#122** (Medium, fixed) — the module emitted data-layer key names appearing nowhere
  in Google's current documentation, while three docblocks cited that page as their
  authority. Found by *checking the page* instead of reasoning about it. This is the
  motivating case for the whole system. Its stated residual — one live check against a
  real container — is still open and is recorded as U53's wanted canary.
- **#121** (filed Low @0.5 confidence, re-rated **High** after measurement, fixed) —
  supplies UD-11: "it evidently works" is not evidence.

Both stay owned by `.security/`; they are cross-referenced here as history, not
re-opened. Going forward this class of finding opens a `U#` registry row so the next
drift is caught by sweep rather than by luck.

**Seeding corrections made before the ledger was finished** (recorded because they are
the system's own first instance of UD-13): the Blacklist mirror was first written as
69 tag ids / 94 total from a summary; measured from the file it is **71 / 97**. The PHP
and WP floor site counts were first written as 3 each; enumerated they are **6** and
**5**. `composer.lock` was assumed tracked; it is git-ignored (now U88).

| # | Sev | Stage | Status | Summary | File(s) |
|---|-----|-------|--------|---------|---------|
| — | — | — | — | No findings; seeding run only. Verification begins at Sweep 1. | — |

### Sweep 1 — 2026-08-05

**Reviewed at:** `1daeddf` · **Base:** — (first sweep after the seed) · **Fixes:** pending
· **Report:** `.upstream/upstream-review-report-2026-08-05-1508.md`

Declared versions **13/13**; behavioral contracts **6/60**. Four distinct upstream
sources reached, budget 6, none skipped for budget. The mechanical layer came back
clean on the two things most likely to be quietly wrong — floor agreement and coupling-
site existence — while the web layer produced every finding, which is the expected
shape: upstream drift leaves no trace on our side of the diff.

| # | Sev | Stage | Status | Summary | File(s) |
|---|-----|-------|--------|---------|---------|
| D1 | Medium | shipped | **accepted → implement** | **Resolved 2026-08-05 (maintainer):** not a rename. Google Ads customer-acquisition uses `new_customer` (answer 12077475); the GA4 e-commerce reference uses `customer_type` (string `new`/`returning`) on `purchase`. Two products, two signals. Re-rated from High (possible silent rename) to Medium (coverage gap against the GA4 reference). **Decision: add `customer_type`, keep `new_customer`.** Pending implementation. | `src/Modules/WooCommerce/{PageDataLayer,ProductData,PurchaseTracking}.php` |
| D2 | Medium | shipped | **decided: fix** | Google documents 72 tag ids; the plugin carries 71. Missing: `mf` (Mouseflow). The docblock records dropping `mf` + `ua` vs 1.x, but upstream dropped only `ua`. **Maintainer decision 2026-08-05: re-add `mf`.** Scope is wider than the registry recorded — `mf` is actively purged in five places, see the U53 note below. Pending batch implementation. | `src/Modules/Blacklist/{BlacklistModule,AdminSchema}.php`, `src/Migration.php`, `tests/unit/Modules/BlacklistModuleTest.php` |
| D3 | — | shipped | **accepted** | Google documents 8 group classes; `GROUP_CLASS_IDS` carries 1. **Maintainer decision 2026-08-05: intentional and closed.** `sandboxedScripts` is present for one specific purpose — controlling GTM **community templates** — not as the first of eight. The other seven are deliberately out of scope. No code change. The tracked claim is narrowed accordingly (see U53) so future sweeps stop re-reporting the count. See UB-4. | `src/Modules/Blacklist/BlacklistModule.php` |
| D4a | Medium | beta→rc | **delegated** | WP `Tested up to: 6.9.4` vs stable 7.0.2, with 7.1-beta4 open and RC imminent. **Maintainer 2026-08-05: owned by a separate session**, which is holding for the 7.1 RC Field Guide and will make the plugin-code updates and the header bump. Not actioned here. Covers U2 and U82, and by extension the core-behavior couplings U3–U14 that the Field Guide is the only channel for. | `readme.txt` |
| D4b | Medium | shipped | **decided: check required** | `WC tested up to: 10.6.1` vs stable **11.0.0** — one major behind, **not** covered by the WP 7.1 session. **Maintainer 2026-08-05: a WooCommerce compatibility check is required.** Scope: what WC 11.0 changed across U15–U30 — hooks, template markup and the ~40 unversioned DOM selectors, the Store API extension schema, the block data stores, and the unguarded WC-Admin internal (U20). Reach U84/U85 (GitHub releases + developer blog) first; the header bump follows the check, not the other way round. | plugin header, `src/Modules/WooCommerce/*`, `js/frontend/gtm4wp-woocommerce*.js` |
| D5 | Medium | shipped | **decided: fix** | PHP floor `8.0` declared in six agreeing places and executed by no CI job (matrix 8.2/8.4). **Maintainer 2026-08-05: option A** — change the matrix to `[ '8.0', '8.4' ]`, floor **stays 8.0**. Job count unchanged; the floor becomes executed rather than only statically sniffed. The floor-raising alternative was rejected on record (see U72). Pending batch implementation. | `.github/workflows/ci.yml` |
| D6 | Medium | shipped | **decided: fix** | `ci.yml:33` states "composer.lock is committed"; it is git-ignored, so CI resolves fresh and `dealerdirect/phpcodesniffer-composer-installer` — an allow-listed Composer **plugin** that executes at install time — runs unreviewed on first publish of any new version. **Maintainer 2026-08-05: commit the lockfile.** Verified no private repos (no credential leakage) and `vendor/` is absent from `DIST_FILES` (never ships to users), so committing carries no exposure. Remove `composer.lock` from `.gitignore:29`, where it sits misfiled under `# Composer` next to the `/vendor/` build artifact. **Maintainer 2026-08-05: also add `composer audit` to CI** — the lockfile pins the set, the audit checks it, and pinning without auditing just freezes known vulnerabilities in place. | `.gitignore`, `.github/workflows/ci.yml` |
| D7a | — | shipped | **blocked upstream — waiting** | `squizlabs/php_codesniffer` 3.13.5 → 4.0.1 is **not a choice**: `wp-coding-standards/wpcs 3.4.1` requires `^3.13.5` and `phpcompatibility/php-compatibility 9.3.5` requires `^2.3 \|\| ^3.0.2`. Both cap below 4. **Maintainer 2026-08-05: wait for full-chain compatibility.** **Trigger to re-open:** WPCS *and* PHPCompatibility both publishing PHPCS-4-compatible releases — not one of them. Re-check via `composer prohibits squizlabs/php_codesniffer 4.0.1`; a sweep reporting "phpcs is a major behind" without checking the chain is reporting noise. | `composer.json` |
| D7b | Low | shipped | **deferred — trigger set** | `phpunit/phpunit` 11.5.56 → 13.2.6: no blocker beyond transitive churn, but it raises the dev-side PHP requirement further (v11 already needs `>=8.2`). **Maintainer 2026-08-05: wait for confirmation that dropping the plugin's 8.0 floor is low risk.** **Trigger to re-open:** measurable evidence that 8.0 usage has collapsed — WordPress core raising its own floor above 7.4, WooCommerce raising its floor, or the wp.org PHP-version statistics (new channel **U89**) showing 8.0 below a threshold the maintainer sets. Note the two floors are independent: the plugin's *runtime* floor is 8.0, the *test toolchain* already requires 8.2, and that is normal. | `composer.json` |
| D7c | Medium | shipped | **deferred — isolated session** | `@wordpress/scripts` 30.27.0 → **34.0.0** (4 majors). **Maintainer 2026-08-05: do this in its own isolated session**, never batched with feature work. Three couplings at risk, highest first: (1) **`.eslintrc.js` is legacy eslintrc format** — an ESLint 9 flat-config default stops reading it, and `postbuild` runs `lint:js`, so `npm run build` breaks; the file encodes 30+ globals and 6 rationale-backed rule overrides that *are* the 1.x public JS API contract (`camelcase: off` because `gtm4wp_*` snake_case is public API); (2) `webpack.config.js` spreads `defaultConfig` and replaces `entry`/`output` (U75) — a shape change yields a silently wrong config rather than an error; (3) React 18→19 across the jest preset while `react`/`react-dom` are pinned `^18.3.1`. Also re-justify all 11 `overrides` pins. **Exit criteria for that session:** `npm run build`, `npm run lint:js` and `npm run test:unit` all green, and the `__next*` props (U76) re-checked against the new components version while the tooling is already open. | `package.json`, `webpack.config.js`, `jest.config.js`, `.eslintrc.js` |
| D7d | Low | shipped | open | `typescript ~5.3.3` → 7.0.2. No `tsconfig.json` in the repo; likely present only for tooling type-resolution. Establish whether anything consumes it before bumping — it may be droppable. | `package.json` |

**Retracted the same day — U54 was a false positive.** The sweep reported five core
e-commerce events as undocumented. They are documented; the fetch truncated mid-`refund`
on an alphabetically-ordered page, so every "missing" event was alphabetically at or
after the cut. Caught by the maintainer opening the page. Recorded correctly as
`fetch-failed` rather than drift at the time, but the tell — the removals being
contiguous in the page's own sort order — was available and missed. This produced
**UD-14** and the mandatory sentinel on U54's probe (`view_item_list` must appear in any
extraction, or the outcome is `fetch-failed`).

**Process note for future sweeps:** the first sweep of a new system generated one false
positive out of eight findings, and it was in the highest-consequence entry. The
three-outcome rule (`verified` / `drifted` / `fetch-failed`) is what kept it from
entering the ledger as drift. Do not weaken it.

### Disposition — all Sweep 1 findings triaged 2026-08-05

**Implemented in the batch of 2026-08-05** (811 tests green, `phpcs` clean):

- **D1 — fixed.** `customer_type` (`new`/`returning`) added alongside the unchanged
  `new_customer` boolean, via `ProductData::customer_signals()` returning both. Each of
  the three emission sites is now one `array_merge` line, and **both Google key names
  plus the new/returning vocabulary live in exactly one place** — the first shape
  attempted deduplicated only the values and left each key name written three times,
  which is UC-6 one level up. Ships the pair asserted together in `PurchaseTrackingTest`
  so neither can be dropped as a duplicate, plus both branches in `ProductDataTest`.
  - **UC-3 instance found while testing:** `tests/unit/Modules/wc-datastore-stub.php`
    hardcoded `is_returning_customer()` to `false`, making the returning branch
    unreachable — a double *less* capable than the real collaborator, so half the
    mapping could never be exercised. Now settable, with a `finally` reset because the
    static is process-wide.
  - **TS-16 instance found at the same time:** `ProductDataTest` did not require the
    datastore stub at all; it would have passed only because `PageDataLayerTest` or
    `PurchaseTrackingTest` loaded it first. Now required explicitly. Verified with
    three `--order-by=random` runs.
- **D2 — fixed.** `mf` restored in `TAG_IDS` (now **72**, matching upstream exactly),
  labelled in `AdminSchema`, removed from `Migration::REMOVED_BLACKLIST_ENTITIES` so
  saved settings survive upgrade, and the two false test assertions inverted. Both
  guards failed on the change before being corrected, which is them working.
- **D5 — fixed.** New `php-floor` CI job runs `php -l` over the shipped PHP on **8.0**.
  The `php` job keeps `[ '8.2', '8.4' ]` — 8.2 is the lowest PHPUnit 11 supports, so
  the floor could not be added to that matrix.
- **D6 — fixed.** `composer.lock` unignored (the `.gitignore` entry now explains why it
  is source, not a build artifact), the false `ci.yml` comment corrected, and
  `composer audit` added to CI.

**Standing / not actioned:**

- **D3** — `accepted`, closed. Claim narrowed (UB-4); do not re-report "1 of 8".
- **D4a** — **delegated** to the WP 7.1 session.
- **D4b** — **open.** WooCommerce 11.0 compatibility check required before any header
  bump. Blocked on U84/U85, the two WC channels this sweep missed.
- **D7a** — blocked upstream; trigger recorded.
- **D7b** — deferred; trigger is U89 (wp.org PHP statistics) or an upstream floor rise.
- **D7c** — deferred to a dedicated `@wordpress/scripts` session; exit criteria recorded.
- **D7d** — open, low.
- **U54** — retracted false positive; sentinel probe now mandatory.
- **U82** — the WP 7.0 Field Guide (2026-05-14) has never been swept, and 7.0 is
  already stable. Owned by the WP 7.1 session (D4a).
