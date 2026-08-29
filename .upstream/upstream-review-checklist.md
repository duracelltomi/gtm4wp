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
- `[~]` — stale: the coupling site or the upstream moved since the recorded date, **or**
  partially verified (say which part, and which part the probe did not reach)
- `[!]` — **drifted**: the probe ran and the claim is false. Carries the `D#`. Distinct from
  `[ ]` on purpose — "we checked and it is wrong" must never render as "we never checked",
  and `Last verified` stays at the last date the claim was TRUE (added 2026-08-06)
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
  With ~107 rows on mixed cadences (measured 2026-08-07: `^| U<n> |` in this file)
  the overdue list is never empty, and a permanently red indicator is one nobody
  reads.

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

## What "we support WordPress X" means

Maintainer's definition, 2026-08-06. `Tested up to:` is a published claim (UD-10), and
this is what the claim asserts. A sweep that only answers "did the new release break us"
has answered a *narrower* question than the one we publish.

Declaring support for a version means all four:

1. **We use nothing deprecated *or* removed in that version.** Deprecation is a removal
   with a date attached and a notice bolted on. Waiting for the removal buys nothing —
   the same migration is owed either way — and in the interim it buys `debug.log` entries
   that users report against *this* plugin. "Still works" is not the standard.
2. **We are ready for changed behavior**, not just for changed signatures. The dangerous
   upstream change is the one that keeps working and returns something different.
3. **Someone has actually run it** on that version. Reading the Field Guide is a
   documentation check; the claim asserts more (U92).
4. **Every unavoidable exception carries a named retire trigger** — see below.

### The floor exception, and why it is not a loophole

The plugin supports a **range** (6.3 → 7.1), so rules 1 and "works on the floor" can have
no common solution. `__next40pxDefaultSize` is the worked example: deprecated no-op on
7.1, load-bearing on 6.3–7.0 (U76). No version of that file satisfies both.

This exception is *forced by the floor decision*, not a way around rule 1 — the maintainer
decision to hold WP at 6.3 (2026-08-06) entails it. The rule is therefore:

> Remove the deprecated usage as soon as the supported floor allows. Where the floor does
> not allow it yet, it carries a **named retire trigger** in its registry row — not an
> open-ended wait.

"Tracked with a trigger" and "waiting for the inevitable" look identical in a codebase and
are opposite in intent. The trigger is what distinguishes them, and a deprecation with no
trigger recorded is the second one wearing the first one's clothes.

### Triage by loudness — priority, never verdict

Both get fixed; this decides the order.

| Class | Test | Cost while it lives |
|---|---|---|
| **Loud** | Emits `_deprecated_function()` / `_deprecated_argument()` / `_deprecated_hook()`, a JS `deprecated()` warning, or a PHP notice | `debug.log` entries and support tickets naming this plugin. **Jumps the queue.** |
| **Silent** | Accepted and ignored; upstream discards it deliberately | Zero user-visible cost. Hygiene only — dead code that reads as load-bearing. |

`__next40pxDefaultSize` is silent (the 7.1 dev note: *"ignored at runtime"*, plus explicit
discard guards in the components), which is why holding it to a floor trigger is
acceptable rather than a running debt.

---

## Release Radar

Refreshed every sweep. `We declare` is read from the plugin header and `readme.txt`,
so the gap between what upstream ships and what we claim is visible in one row.

| Upstream | Stable | Pre-release | Expected GA | We declare | Action |
|---|---|---|---|---|---|
| WordPress core | **7.0.2** [x] 2026-08-06 (S1a) | **7.1-RC1** [x] 2026-08-06 (S1a) | **GA 2026-08-19** | `Requires at least: 6.3` / `Tested up to: 7.1` | D4a closed: both Field Guides swept, declaration bumped **ahead** of stable during the RC window. Residual: **no runtime smoke test on 7.1** — U2 is `[~]`, not `[x]` |
| WooCommerce | **11.0.0** [x] 2026-08-06 (S1a) | [ ] not reached | — | `WC requires at least: 5.0` / `WC tested up to: 11.0.0` | D4b closed: source-checked across U17–U30 (2 findings, both fixed) and smoke-tested; claim now current |
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
| U2 | `Tested up to: 7.1` claim | `readme.txt` | wp.org core API | U73 | deprecation | every-run | [x] 2026-08-06 (S1a) bumped 6.9.4 → 7.1 on the 7.0 + 7.1 Field Guides **plus** a maintainer smoke test of the `2.0.0-beta2` build (U92's first run). Manual evidence — re-run per release |
| U3 | Script `strategy` array (`defer`) — the reason for the 6.3 floor | `src/Module/AbstractModule.php` `enqueue_script()`; `src/Modules/MediaEvents/MediaEventsModule.php` | core script API | U74 | silent-missing | on-WP-release | [x] 2026-08-06 (S1a) |
| U4 | `wp_add_inline_script` + `wp_script_is(…,'done')` fallback | `src/Modules/WooCommerce/PageDataLayer.php` `print_deferred_checkout_js()` | `WP_Dependencies` behavior | U74 | silent-missing | on-WP-release | [x] 2026-08-06 (S1a) |
| U5 | `wp_register_script()` with empty `$src` as an inline carrier | `src/Frontend/DataLayer.php` | core script API | U74 | silent-missing | on-WP-release | [ ] probe inconclusive 2026-08-06 (S1a) — `fetch-failed`, not a pass |
| U100 | Load order the product-detail `view_item` list attribution depends on (#405). Two claims about core, both absorbed silently: (1) an inline `after` script on a src-less handle is **parser-blocking**, so it runs ahead of every `defer`red bundle — which is why `gtm4wp-ecommerce-generic` is enqueued with no strategy on that one page; (2) `WP_Scripts` prints a handle's dependencies before it, and **drops a script whose dependency is unregistered** — the reason the dependency is added through a guarded helper rather than assigned. If (1) or (2) moves, the wrapper resolves to its identity fallback and every product view loses its list, with no error and a green suite | `src/Modules/WooCommerce/WooCommerceModule.php` `order_generic_before_pushes()`; `src/Frontend/DataLayer.php` `add_push_handle_dependency()` | `WP_Scripts::do_item()` / `get_eligible_loading_strategy()` in core | U74, U3, U5 | silent-wrong | on-WP-release | [x] 2026-08-06 against the WP 6.3+ strategy API |
| U6 | `wp_kses()` ampersand entity behavior | `src/Frontend/ScriptTag.php` `print_script_block()` / `restore_script_ampersands()` | core kses behavior | U74 | silent-wrong | on-WP-release | [x] 2026-08-06 (S1a) |
| U7 | `safe_style_css` / `safecss_filter_attr()` stripping `display`+`visibility` | `src/Frontend/ContainerCode.php` `the_tag()` | core kses behavior | U74 | loud | on-WP-release | [x] 2026-08-06 (S1a) D10 fixed — claim re-stated with its version qualifier; filter kept, retire trigger recorded in code |
| U8 | `rest_pre_serve_request` at priority 11, after core `rest_send_cors_headers` | `src/RestCors.php` | core REST behavior | U74 | silent-missing | on-WP-release | [x] 2026-08-06 (S1a) |
| U9 | `feature=oembed` literal spliced to inject `enablejsapi=1` | `src/Modules/MediaEvents/MediaEventsModule.php` `enable_youtube_js_api()` | core oEmbed + YouTube | — | silent-missing | quarterly | [ ] not verifiable locally — the literal is YouTube’s oEmbed HTML, not core |
| U106 | **Core rewrites the iframe of every oEmbed result whose provider is not in its own trusted list, and a fragment on the embed src is therefore the normal WordPress rendering — not an edge case.** `wp_filter_oembed_result()` appends `#?secret=<10 chars>` to the src, adds `class="wp-embedded-content" sandbox="allow-scripts" security="restricted"` plus a matching `data-secret`, and its `wp_kses` allow-list keeps only `src`/`width`/`height`/`frameborder`/`marginwidth`/`marginheight`/`scrolling`/**`title`** — so `allow=` and `allowfullscreen` are dropped on the way. Cloudflare Stream is one such provider, and this is what a WordPress page actually serves for it. **The failure it caused is the whole reason this row exists**: an iframe parse that cut the src at the `?` left the `#` sitting on the last path segment, the `…/{uid}/iframe` test therefore missed, and every media event reported the video's id, title and URL as `iframe#` — no error, no failing test, visible only by reading the data layer of a live page. The other half of the row runs in our favour: `wp_filter_oembed_iframe_title_attribute()` **adds** `title="<the oEmbed response's title>"` when the provider supplied one and the iframe carries none, and the sanitizer above preserves it, which is what makes the `title` attribute a real title source for a player whose SDK exposes none (Cloudflare Stream here; Spotify in U104) | `js/frontend/lib/native-video-params.js` `gtm4wpMediaBareUrl()` and its attribute-reading wrapper `gtm4wpMediaSrcUrl()` — the single definition every tracker parses a media URL through, so the fragment is handled once rather than per tracker (UC-6); the `title` attribute read in `js/frontend/gtm4wp-cloudflarestream.js`; `js/frontend/gtm4wp-youtube.js`, whose `enablejsapi=1` splice has to stay on the base-URL side of the `#`; and `js/frontend/gtm4wp-html5media.js`, which takes the file name off a media element's `currentSrc` — **not an oEmbed case at all**, but the same rule for the same reason: an identifier read out of a URL must not carry the URL's parameters, or a `?ver=` cache buster and a rotating signed CDN token become part of the video's identity (and the token part of the data layer) — parameters appended after a fragment are part of the fragment, so YouTube never sees them and the JS API stays switched off | `wp_filter_oembed_result()` + `wp_filter_oembed_iframe_title_attribute()` in `wp-includes/embed.php`. Claims to re-verify: (1) the appended fragment is still `#?secret=…`; (2) the kses allow-list still keeps `title`; (3) **whether a provider we track has since entered core's trusted list** (`WP_oEmbed::$providers`) — Cloudflare Stream is absent from it today, and a provider joining it silently stops exercising the fragment path rather than breaking anything. Pinned from our side by the `gtm4wpMediaSrcUrl` describe in `js/frontend/test/native-video-params.test.js` and by `#`-carrying fixtures in the cloudflarestream / vimeo / youtube tracker tests, which use the byte-exact src a live WordPress page renders | U74 | silent-wrong | on-WP-release | [x] 2026-08-07 read out of core's `wp-includes/embed.php` **and** confirmed against the live sandbox: the Stream embed on `woo.gtm4wp.dev/cloudflare-stream-player-test-page` renders as `…/6b9e68b07dfee8cc2d116e4c51d6a957/iframe#?secret=ty1V7TXUb1` with `class="wp-embedded-content" sandbox="allow-scripts" security="restricted"`, no `title` attribute (its oEmbed response carries none) and no `allow=` |
| U10 | Deprecated block name `core-embed/youtube` | `src/Modules/MediaEvents/MediaEventsModule.php` | core block registry | U74 | silent-missing | on-WP-release | [x] 2026-08-06 (S1a) |
| U11 | REST nonce lifetime / `wp_get_session_token()` | `src/Modules/VisitorData/VisitorDataModule.php`, `VisitorDataEndpoint.php` | core auth behavior | U74 | loud | on-WP-release | [x] 2026-08-06 (S1a) |
| U12 | Guarded core helpers: `wp_get_environment_type()`, `is_plugin_active()`, `amp_is_request()` | `src/Frontend/ContainerCode.php`, `src/Admin/Notices.php`, `src/Modules/Amp/AmpModule.php` | — | — | loud | quarterly | [-] UB-3 |
| U13 | `$GLOBALS['wp_version']` read directly in the requirements gate | plugin main file | core global | U74 | loud | on-WP-release | [x] 2026-08-06 (S1a) |
| U14 | `apply_filters_deprecated()` / `_deprecated_hook()` per-call notice behavior | `src/Modules/WooCommerce/ProductData.php` | core deprecation API | U74 | loud | on-WP-release | [x] 2026-08-06 (S1a) |
| U90 | `current_theme_supports( 'html5' )` — **no second argument** — decides whether the hand-built script tag carries `type="text/javascript"` | `src/Frontend/ScriptTag.php` `opening_tag()`; `compat/functions.php` `gtm4wp_generate_script_opening_tag()` | core theme-support API (`src/wp-includes/theme.php`) | U74, U93 | loud (cosmetic) | on-WP-release | [x] 2026-08-06 (S1a) verified against core trunk source — claim holds, no behaviour change |
| U91 | `wp-theme` design tokens (`--wpds-*`) and `@wordpress/ui` — the successor styling contract for admin React screens | not yet coupled; `js/admin/style.scss` `--gtm4wp-*` is the seam that would consume it | WP 7.1 Design System Theming | U74, U86 | deprecation | on-WP-release | [x] 2026-08-06 (S1a) **deliberately not adopted** — see below |
| U115 | **`get_post_meta( $id )` with NO key does not unserialize** — the one branch of the core meta API that returns the raw DB column. This asymmetry is the entire premise of the `is_serialized()` skip: if core ever normalized this branch, `is_serialized()` would stop matching and every packed custom field would be silently republished to the public page. | `src/Modules/PageVariables/PageVariablesModule.php` — the post-meta block and `drop_serialized_meta_values()` | core `src/wp-includes/meta.php`: `get_metadata_raw()` must still read `if ( ! $meta_key ) { return $meta_cache; }`, and `update_meta_cache()` must still store `$metarow['meta_value']` unchanged. Check: `awk '/^function get_metadata_raw/,/^}/' wp-includes/meta.php` | U74 | silent-wrong | on-WP-release | [x] 2026-08-29 (R26) verified at **6.3 (our floor), 6.9 and master** — all three carry the raw return |
| U116 | **`is_protected_meta()` as a withhold signal** — its return value IS the `is_protected_meta` filter's return value, so a site callback can move the answer in EITHER direction. Belt-and-braces since R26: the plugin keeps its own leading-underscore test as the floor and uses this only to ADD exclusions, so a callback that unprotects a key can no longer widen what is published. Note core strips non-printable/non-letter characters from the key before testing the first character, so `" _secret"` (leading space) is not protected by the default rule. | `src/Modules/PageVariables/PageVariablesModule.php` — the post-meta publish gate | core `src/wp-includes/meta.php` `is_protected_meta()`: confirm the filter is still the returned value and the default rule is still the leading underscore of the sanitized key | U74 | silent-wrong | on-WP-release | [x] 2026-08-29 (R26) read from core source; floor added the same day so a filter can only narrow |

### B. WooCommerce

| ID | Dependency | Coupling site | Source / check | Watch | Failure | Cadence | Last verified |
|---|---|---|---|---|---|---|---|
| U15 | WC version floor `5.0` — **2 copies that can drift** | plugin header `WC requires at least`; `src/Modules/WooCommerce/WooCommerceModule.php` anchor `MIN_WC_VERSION` | grep agreement | — | loud | every-run | [x] 2026-08-05 (S1) both `5.0` |
| U16 | `WC tested up to: 11.0.0` claim | plugin header | wp.org plugin API | U77 | deprecation | every-run | [~] 2026-08-06 (S1a) bumped 10.6.1 → 11.0.0 after the D4b source check + a maintainer smoke test. Source-verified across U17–U30; no automated runtime coverage against WC 11.0 |
| U17 | HPOS feature slug `custom_order_tables` | plugin main file, `before_woocommerce_init` | WC FeaturesUtil | U77 | loud | on-WC-release | [x] 2026-08-06 (S1a) |
| U18 | ~30 `woocommerce_*` actions/filters registered | `src/Modules/WooCommerce/WooCommerceModule.php` `register_frontend_hooks()` | WC hook reference | U77 | silent-missing | on-WC-release | [x] 2026-08-06 (S1a) D11 fixed — dead registration removed; all 26 remaining verified present in WC 11.0.0 |
| U19 | WC CRUD surface (`wc_get_order`, `wc_get_product`, order getters, `WC()->cart/session/customer`) | `src/Modules/WooCommerce/*.php` | WC public API | U77 | loud | on-WC-release | [x] 2026-08-06 (S1a) |
| U20 | **WC-Admin internal** `…\Reports\Orders\Stats\DataStore::is_returning_customer()` — unguarded | `src/Modules/WooCommerce/ProductData.php` | WC internals (no promise) | U77 | loud | on-WC-release | [x] 2026-08-06 (S1a) |
| U21 | `CartCheckoutUtils::is_cart_block_default()` / `is_checkout_block_default()` (guarded) | `src/Modules/WooCommerce/WooCommerceModule.php` | WC Blocks | U77 | silent-missing | on-WC-release | [x] 2026-08-06 (S1a) |
| U22 | Store API `ExtendSchema`, `ProductSchema::IDENTIFIER`, `CartItemSchema::IDENTIFIER`; cart-item extension values must be strings | `src/Modules/WooCommerce/StoreApiData.php` | WC Store API | U77 | silent-wrong | on-WC-release | [x] 2026-08-06 (S1a) |
| U23 | Block data stores `wc/store/cart`, `wc/store/payment`; selectors `getCartData`, `getActivePaymentMethod`; payload shape | `js/frontend/gtm4wp-woocommerce-blocks.js`, `js/frontend/lib/gtm4wp-blocks-cart-diff.js` | WC Blocks | U77 | silent-missing | on-WC-release | [x] 2026-08-06 (S1a) |
| U24 | ~40 classic WC DOM selectors | `js/frontend/gtm4wp-woocommerce.js` | WC templates (unversioned) | U77 | silent-missing | on-WC-release | [~] 2026-08-06 (S1a) 14 of 14 *class tokens* extracted all present; probe did not reach the full ~40 selector set |
| U25 | jQuery WC events `found_variation`, `checkout_place_order`, `ajaxSuccess` sniff | `js/frontend/gtm4wp-woocommerce.js` | WC templates | U77 | silent-missing | on-WC-release | [x] 2026-08-06 (S1a) |
| U26 | Server-side regex over block markup + the Product Collection collection slugs mapped in `product_collection_list_key()` | `src/Modules/WooCommerce/ListTracking.php` | WC `CoreCollectionNames` enum in `client/blocks/assets/js/blocks/product-collection/types.ts` | U77 | silent-missing | on-WC-release | [x] 2026-08-06 upstream set **measured, not copied**: 14 core slugs, identical on trunk and the 11.0.0 tag (`product-catalog, best-sellers, featured, new-arrivals, on-sale, top-rated, hand-picked, related, upsells, cross-sells, by-category, by-tag, by-brand, cart-contents`). All 14 now mapped (was 8); an unmapped or third-party-registered collection falls back to the generic list. Pinned by the 15-row table in `ListTrackingTest::provide_product_collection_identities()` |
| U27 | WC template filename literal `content-widget-product.php` | `src/Modules/WooCommerce/ListTracking.php` | WC templates | U77 | silent-missing | on-WC-release | [x] 2026-08-06 (S1a) |
| U28 | WC option/taxonomy literals: `woocommerce_tax_display_shop`, `product_cat`, `wc-` status prefix | `src/Modules/WooCommerce/{ListTracking,PageDataLayer,Helpers,AdminSchema}.php` | WC data model | U77 | silent-wrong | on-WC-release | [x] 2026-08-06 (S1a) |
| U29 | `WC_Product` / `WC_Product_Variation` `instanceof` structural variation detection | `src/Modules/WooCommerce/ProductData.php` | WC class hierarchy | U77 | silent-wrong | on-WC-release | [x] 2026-08-06 (S1a) |
| U30 | Cross-sell block selectors `.wp-block-woocommerce-cart-cross-sells-block`, `.wc-block-cart-cross-sells-product` | `js/frontend/gtm4wp-woocommerce-blocks.js` | WC Blocks markup | U77 | silent-missing | on-WC-release | [x] 2026-08-06 (S1a) D12 fixed — prefix corrected and pinned by a regression test that fails on the old value |
| U97 | **The cart-fragments delivery channel** (cache-safe data layer only). Three claims, all silent if wrong: (a) the `woocommerce_add_to_cart_fragments` filter still exists and its selector⇒HTML contract is unchanged — applied in `WC_AJAX::get_refreshed_fragments()`, reached by the `get_refreshed_fragments`, `add_to_cart` and `remove_from_cart` endpoints; (b) WC still re-applies fragments from its own `sessionStorage` cache on a normal page load, which is what delivers the block when no cart mutation happened; (c) **the handle `wc-cart-fragments` is still registered under that name** — we now `wp_enqueue_script()` it ourselves, unguarded, and WP drops an unregistered queued handle with no notice. Covered by the L-1 support policy that `WC_Frontend_Scripts`' own docblock grants third-party enqueueing. **Do not "fix" (c) by adding a `wp_script_is( …, 'registered' )` guard** — our callback and WC's `load_scripts()` share `wp_enqueue_scripts` priority 10, so the guard would be a race that silently skips the enqueue; queue resolution happens at print time | `src/Modules/WooCommerce/PageDataLayer.php` (`add_visitor_cart_fragment`, `output_visitor_cart_placeholder`), `src/Modules/WooCommerce/WooCommerceModule.php` (`enqueue_scripts`), `js/frontend/gtm4wp-visitor-data.js` | WC `includes/class-wc-frontend-scripts.php`, `includes/widgets/class-wc-widget-cart.php`, `includes/class-wc-ajax.php`, `client/legacy/js/frontend/cart-fragments.js` | U77, U70 | silent-missing | on-WC-release | [x] 2026-08-06 read on `trunk` (11.1.0-dev); `cart-fragments.js` byte-identical on tags `11.0.0` and `10.4.0`. **Measured, not assumed:** the handle is registered unconditionally by `load_scripts()` (sole gate `did_action('before_woocommerce_init')`) but WC enqueues it from exactly **one** frontend path, `WC_Widget_Cart::widget()`, which returns early when `woocommerce_widget_cart_is_hidden` is true — **default `is_cart() \|\| is_checkout()`** — and the Mini-Cart *block* never enqueues it (it uses a parallel Store API layer on `localStorage.storeApiCartHash`). That is why (c) was failing in the field. `wc_load_cart_fragments()`, `woocommerce_enqueue_cart_fragments` and `woocommerce_cart_fragments_refresh` **do not exist** — do not look for them. Cost claim behind the changelog wording: the refresh XHR fires once per *tab* (its cache is `sessionStorage`), and on **every** page load when Web Storage is blocked (`cart-fragments.js` has no guard for that) |
| U99 | **Legacy product grid block container classes → GA4 list identity.** The 8 `wp-block-{block_name}` classes `AbstractProductGrid::get_container_classes()` emits, each mapped to a hardcoded `item_list_name` **and** `item_list_id`, plus the `.wc-block-grid` / `.wc-block-grid__products` wrapper shape the lookup walks. WC's `woocommerce_blocks_product_grid_item_html` carries no block context, so PHP writes a generic placeholder pair and the browser resolves the real identity from the container class. The 8 ids are the same literals `list_identity()` gives those lists on the Product Collection path (U26) — one list, two files, and they must not drift (UC-6) | `js/frontend/gtm4wp-woocommerce.js` (`gtm4wp_product_block_names` + its three `gtm4wp_update_json_in_node` calls); placeholder pair from `src/Modules/WooCommerce/ListTracking.php` | `AbstractProductGrid::get_container_classes()` + each subclass's `$block_name`, via `gh api repos/woocommerce/woocommerce/contents/…` | U77 — **soft-deprecated since WC 9.5: every legacy grid `block.json` carries `"supports": { "inserter": false }` (confirmed at 11.0.0), the blocks stay registered and server-rendered for existing content, and the successor is `woocommerce/product-collection`. Removal, not renaming, is the expected end state; watch WC deprecation notes and the subclass count, and do NOT drop our support — widget areas and saved content still render these** | silent-wrong | on-WC-release | [x] 2026-08-06 measured on the **11.0.0 tag**: `search/code repo:woocommerce/woocommerce "extends AbstractProductGrid"` → `total_count: 8` (`ProductNew, ProductOnSale, ProductTopRated, ProductCategory, ProductTag, HandpickedProducts, ProductBestSellers, ProductsByAttribute`), map keys = `wp-block-` + each `$block_name`, so the map is exactly complete: 8 keys vs 8 subclasses. `get_container_classes()` verified to put `wc-block-grid` and `wp-block-{name}` on the same element. Pinned by the 8-row table in `js/frontend/test/woocommerce-tracker.test.js` |
| U114 | **`AddToCartWithOptions::has_form_elements()` legacy-mode scan** (blockified Add to Cart + Options block, WC 10.0+). Two claims, both silent if wrong: (a) the block buffers the output of the classic `woocommerce_before/after_add_to_cart_*` hooks and renders a classic POST form (no `data-wp-on--submit`, interactive add to cart off) when a buffer contains an `INPUT`, `TEXTAREA`, `SELECT`, `BUTTON` or `FORM` tag — so the single-product tracking markup must stay a non-form element (hidden `span`, #462); (b) a `SPAN` does not trip the scan. If the tag list widens, the interactive add to cart dies again on block themes with no error and a green suite | `src/Modules/WooCommerce/ListTracking.php` `single_add_to_cart_tracking()` | `plugins/woocommerce/src/Blocks/BlockTypes/AddToCartWithOptions/AddToCartWithOptions.php` (`has_form_elements()`, `$legacy_mode`), via `gh api repos/woocommerce/woocommerce/contents/…` | U77 | silent-wrong | on-WC-release | [x] 2026-08-13 read at tag 10.9.1: tag list `INPUT, TEXTAREA, SELECT, BUTTON, FORM`; `$hooks_after` buffers `woocommerce_after_add_to_cart_button`; legacy mode omits `data-wp-on--submit`. Our side pinned by `ListTrackingTest::test_single_add_to_cart_tracking_output_contains_no_form_elements()` |

| U113 | **The visitor checks WooCommerce's `order_received()` applies once it has resolved an order**, which return before `woocommerce_thankyou` and are therefore invisible to this plugin. Read so the data layer includes `orderData.customer`, `new_customer`/`customer_type` and the purchase event's `user_data` exactly when WooCommerce itself renders the order — parity in **both** directions, because wherever upstream renders, the page body is already showing this visitor the order, so withholding more than upstream deletes tracking data without hiding anything. Five couplings, measured at release tags (not trunk): **(a) `WC_Shortcode_Checkout::guest_should_verify_email( WC_Order, string ): bool`, `private static` — the feature-detect symbol.** Both gates and this member shipped together in **7.9.0** (absent at 7.8.0, present 7.9.0→11.0.0); `method_exists()` sees private members and triggers `WC_Autoloader` (`wc_shortcode_` → `shortcodes/`, mapped since ≤5.0.0), so the probe needs no load-order luck. Absent ⇒ a 5.0–7.8.x WooCommerce that renders the order to any valid key holder ⇒ nothing to mirror, **nothing withheld, no version compared anywhere**. **(b)** the known-shopper login gate: **behaviour 7.9.0 (unconditional); its filter `woocommerce_order_received_verify_known_shoppers` (default `true`, 1 arg) only 8.4.0** — one upstream line, two `@since` values; with the filter off upstream still routes a non-owner into the guest email verification, whose only customer-id term is the logged-in-owner short-circuit. **(c)** `Automattic\WooCommerce\Internal\Utilities\Users::should_user_verify_order_email( $order_id, $supplied_email = null, $context = 'view' )` — `public static`, order **id** not object; **the METHOD is 8.6.0+ while the CLASS exists from ≥7.7.0 — re-verify with `method_exists`; `class_exists` alone reports the wrong half**. `Internal` namespace, no compatibility promise (UC-2), hence the guard. **(d)** `woocommerce_order_email_verification_grace_period` — **`@since` 8.0.0, 3 args**, homed in the shortcode 8.0.0–8.5.x and in the helper from 8.6.0; upstream compares elapsed `<=` grace (mirrored exactly); **7.9.x has no grace at all** — the one window where the mirror is laxer than upstream, bounded by the order max-age gate. **(e)** `woocommerce_order_email_verification_required` — **`@since` 7.9.0, 3 args**, upstream's final say and documented opt-out, applied only past the grace short-circuit (both facts mirrored). On 7.9.0–8.5.x (helper absent) the fallback mirrors (b)–(e) term by term for **every order shape reaching it**; the three request-identity terms — session email match, the POSTed-email escape hatch (`check_submission` / nonce `wc_verify_email`), `read_private_shop_orders` — are deliberately not modelled and each fails **closed** | `src/Modules/WooCommerce/PageDataLayer.php` `woocommerce_hides_order_from_visitor()` | `includes/shortcodes/class-wc-shortcode-checkout.php` `order_received()` + `guest_should_verify_email()`; `src/Internal/Utilities/Users.php` | U77 | silent-wrong | on-WC-release | [x] 2026-08-12 read at tags 5.0.0 / 7.8.0 / 7.9.0 / 8.0.0 / 8.4.0 / 8.5.2 / 8.6.0 / 11.0.0; an executable old/new/upstream decision table swept every version window × order shape × filter state with zero unexplained divergences |

**Why U113 is `silent-wrong`, not `silent-missing`:** the two halves fail in opposite
directions. If the helper is renamed, moved out of `Internal` or dropped, the
`class_exists`/`method_exists` guard resolves to "not available" — with no error, and
(until 2026-08-12) with a green suite, because the tests drive our own stub, which
cannot notice upstream moving. The known-shopper half fails the other way: a renamed
filter means we withhold on a site that turned the gate off, which is merely missing
data. **Re-verify the helper first**, and treat a green suite as no evidence either way.

**What "not available" now does, and why it changed twice (2026-08-12, `.security` #173
→ #183).** It originally meant "not withheld", justified by "a WooCommerce older than the
helper does not ask for verification either" — read off the *gate's* `@since` and false
for 7.9.0-8.5.x, where the fallback failed **open** (#173). The first fix re-derived a
guest-only age check and described itself as "deliberately more conservative … can only
withhold more" — false twice over: on ≤7.8.x upstream has no gate at all, so the
conservatism withheld data the rendered page was already showing (#183), and on
8.4.0-8.5.x with the login gate filtered off, upstream still email-verifies a non-owner
while the guest-only shape published (the leak direction; found and closed in #183's fix
session). The resolution keeps version numbers out entirely: probe the SYMBOL both gates
shipped with (coupling (a) in the row), publish when it is absent because there is
nothing to mirror, and when the helper cannot be asked mirror the shortcode-era guest
gate term by term for every order shape. **The lessons for this registry:** record the
introduction version of the *behaviour* and of every *symbol* separately — they differed
by seven minor releases here, and even one upstream line carried two (`gate 7.9.0, its
filter 8.4.0`); and when a mirror must span versions, prefer probing a symbol that
shipped WITH the behaviour over comparing versions (UC-5) — it is the only form that
stays correct on both sides of the window.

The fallback branches carried **zero** test executions across the whole suite until
#173, because `tests/unit/Modules/wc-users-stub.php` defines the stand-in
unconditionally — a live case of UC-3 (the double absorbing the coupling). Every branch
is now reachable through the namespaced `method_exists()` shim in
`tests/unit/Modules/wc-feature-guard-shim.php` (the gates-absent branch via
`tests/unit/Modules/wc-shortcode-checkout-stub.php` plus the same shim), and each new
guard was watched red under mutation before this row was written.

> **Disclosure note (2026-08-12, `.security` #161).** This row is deliberately written
> to the coupling, not to the consequence: what it names is the upstream symbols, the
> guard and the failure direction. It does **not** describe the prior behaviour, the
> conditions under which it applied, or what a visitor could obtain — because the `1.x`
> line carries that behaviour and is not scheduled for a fix, so the description would
> be a standing disclosure rather than a temporary one. This registry's job is to record
> *why an upstream gate matters*, and doing that job well reproduces a mechanism as a
> side effect; that is the trap. Keep such detail in the git-ignored
> `.upstream/upstream-review-report-*.md` / `.security/code-review-report-*.md`.

**Why U99 is `silent-wrong`, not `silent-missing`:** if WooCommerce renames a container
class the lookup stops matching and every grid on the page reports the generic
`General Product List` / `general-product-list` pair — plausible-looking, uniformly
wrong data that produces no error and no failed test, and that corrupted GA4 list
history cannot be backfilled. That is not hypothetical: it is the exact shape of the
defect fixed on 2026-08-06, where the name was resolved per block and the id was not,
so every grid on a page collapsed onto one id.

**Release Radar note (no work yet):** WC 9.4 added the DOM events
`wc-blocks_product_list_rendered`, `wc-blocks_viewed_product` and
`wc-blocks_added_to_cart`. They are a *supported* successor to scraping block markup
for list impressions and clicks (U24/U26/U30/U99 all exist because we scrape). Worth
evaluating before the next block-markup break, not after.

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
| U53 | **Tag-restriction entity ids** — 72 tags / 9 triggers / 16 variables / 1 group class (98 total, measured at `b8f78a1`); `gtm.blocklist` / `gtm.allowlist` | `src/Modules/Blacklist/BlacklistModule.php` — anchors `TAG_IDS`, `TRIGGER_IDS`, `VARIABLE_IDS`, `GROUP_CLASS_IDS` | https://developers.google.com/tag-platform/tag-manager/restrict | — | silent-wrong | every-run | [ ] |
| U54 | **GA4 e-commerce event names (9)** — the core of the e-commerce integration | `src/Modules/WooCommerce/{PageDataLayer,ListTracking,ProductData}.php`; `js/frontend/gtm4wp-woocommerce*.js` | https://developers.google.com/analytics/devguides/collection/ga4/reference/events?client_type=gtm | — | silent-wrong | every-run | [ ] |
| U55 | **GA4 event + item parameter names**, incl. the `item_categoryN` cap of 5 | `src/Modules/WooCommerce/ProductData.php` | same page as U54 (`client_type=gtm` — documents the dataLayer shape, not gtag) | — | silent-wrong | every-run | [ ] |
| U56 | Google Ads `BUSINESS_VERTICALS` (9) + `flights`/`travel` → `destination` id mapping | `src/Modules/WooCommerce/Helpers.php:26-47` | Google Ads remarketing docs | — | silent-wrong | quarterly | [ ] |
| U57 | Enhanced Conversions `user_data` field names + sha256 normalization rules: **E.164 on phone numbers**, the gmail **dot AND plus-suffix** rules, lowercase+trim on names | `src/Modules/WooCommerce/ProductData.php`, `src/Modules/WooCommerce/Helpers.php:543` (`normalize_and_hash_email_address`), `:682` (`normalize_phone_number`) | https://developers.google.com/google-ads/api/docs/conversions/enhanced-conversions/web — **the prose, not the PHP sample on the same page** | — | silent-wrong | quarterly | [x] 2026-08-10 phone rule verbatim "Format phone numbers according to the E164 standard"; names lowercase + trim; street/first/last hashed while country, state, city and postal code stay plain text. Gmail/googlemail local parts lose **both** all `.` and everything from the first `+`, worked example `Jane.Doe+Shopping@googlemail.com` → `janedoe@googlemail.com`; no other domain is folded. Both gaps fixed the same day, each with a regression test proven to fail against the old behavior |
| U110 | **The per-country dialling table behind E.164 phone normalization** — 245 territories, each carrying **three** columns: its calling code; its national (trunk) prefix, or `null` where the country has **no** trunk prefix and a leading zero is therefore part of the number (101 of the 245); and its **general national-number pattern**. The third column was added 2026-08-12 and is worth reading as a correction to what this row used to say. Two positional facts are enough to take a number apart and not enough to decide *which way*: `34 612 345 678` from a Spanish address is the international form with the `+` left off, while `391 234 5678` from an Italian one is a national mobile beginning with Italy's own calling code. The earlier note here claimed telling those apart needed per-country **possible lengths**; measured, that is wrong twice — lengths do not settle Italy (both readings are possible Italian lengths), and what does settle it is the shape, at 32 bytes per territory. It is a **tie-breaker, never a validator**: a number the pattern does not recognise falls through to the positional rules rather than being refused, so a pattern gone stale can fail to improve a number but cannot throw one away (UC-5). That property is the reason this column does not carry the usual mirrored-validator risk. **It was NOT pinned by a test until 2026-08-12** — this row said it was, and instrumenting the fall-through showed its calling-code leg executed **zero** times across the whole suite, for trunk and no-trunk territories alike (`.security` #167). `HelpersTest::test_normalize_phone_number_falls_back_when_the_plan_recognises_neither_reading()` now covers both halves; the ES case is probe-verified red against the pre-column code, the HU case is a must-not-break guard and green either way. Note also what the fall-through is *not*: it refuses nothing a stale pattern would have accepted (measured, 558,103 inputs), but it is not the pre-column behaviour either — the positional rules are now applied uniformly, where a no-trunk-prefix territory used to return early. **GENERATED, not written** (`composer generate:phone-table` → `tools/generate-phone-table.php`), which is the whole point of this row's rewrite on 2026-08-11: the previous version was 5 hand-written exceptions plus a `0` default, and a default can only express two categories where the domain has three. It was wrong for **15 territories** — Italy's landlines being the commercially important case (a leading zero that is part of the number, stripped as if it were a prefix), plus a missing `TM`, a wrong `LT`, and six territories whose calling code was taken from WooCommerce's `i18n/phone.php`, which embeds an **area** code for `AX`/`GG`/`IM`/`JE` (pushing three of them past E.164's 15-digit ceiling, so the number was dropped) and is out of date for `SX`/`VA`. Claim to re-verify: **the generated table still matches upstream** — re-run the generator and check the diff is empty; a non-empty diff is a numbering-plan change, not a defect. **Two controls carry that claim, because a cadence written in this cell does not fire (UD-2).** (1) A monthly CI job (`.github/workflows/upstream-drift.yml`, also `workflow_dispatch`) regenerates from upstream and fails on a non-empty diff, printing it. It compares with `git diff -I '^ \* Generated: '`, because the generator stamps the day it ran: without that the job is red on every run but the one falling on the day the table was last committed, reporting a **date** as a numbering-plan change — and a monthly job that cries wolf gets muted, which is worse than not having one. Found by running the job's steps in a fresh clone rather than by reading them, and verified in both directions (passes when only the stamp moved, still fails on a real entry change). The generator asserts its own SimpleXML requirement rather than trusting the runner's default extension set, and `tools/` was added to CI's PHP-8.0 syntax lint, since this job runs it on the floor and 8.1+ syntax there would otherwise surface as a monthly red build instead of at the commit that caused it. It **reports and never writes**: regenerating on a schedule and pushing would put a behaviour change into the tree that no test run and no changelog ever saw. (2) `tools/build-release.js` refuses to package a table whose `Generated:` stamp is over a year old and warns past six months, reading the **stamp** rather than the mtime, which a checkout or rebase rewrites. The release gate answers "nobody has looked in a long while"; only the CI job answers "the source changed last week", and the age of our copy says nothing about the second. Deliberately NOT wired into the packager: it needs the network, so packaging would stop being reproducible from a checkout, and it would change what ships after the suite ran and the changelog was written. **Deliberately NOT modelled, and this is now the trigger stated precisely**: `nationalPrefixForParsing` (Lithuania's legacy `8`), `nationalPrefixTransformRule` (Argentina's mobile `9`, Brazil's carrier-selection codes), and per-country international access codes other than `00` (US `011`, JP `010`, AU `0011`, RU `810`). What separates these from the three columns we do carry is that they are **transform rules rather than facts** — they rewrite the number rather than describing it, and implementing them is reimplementing the library one behaviour at a time. **The next time this table needs one of those, adopt the library instead.** The earlier wording made the trigger "a third column", which measurement showed was the wrong axis: the third column cost ~11 KB against the library's ~1.9 MB and removed two whole defect classes, so counting columns was never what the decision turned on. | `src/Modules/WooCommerce/CountryPhoneData.php` (generated), `tools/generate-phone-table.php`, `src/Modules/WooCommerce/Helpers.php` `normalize_phone_number()` | https://raw.githubusercontent.com/google/libphonenumber/master/resources/PhoneNumberMetadata.xml — Google's own numbering-plan metadata, the same source a phone library uses | U57 | silent-wrong | **monthly, enforced** (`.github/workflows/upstream-drift.yml`) + a release gate | [x] 2026-08-12 regenerated and pinned from our side by **972 cases in `tests/unit/Modules/phone-corpus.php`**, generated from the same parse. That fixture is an **oracle, not a mirror of our output** — both halves are read out of the metadata — so it fails when the normalizer disagrees with the numbering plan rather than when it changes. **Two spellings per example number, because one proves nothing about the other**: `national` (trunk prefix where one exists) proves the calling-code and trunk columns; `intl-no-plus` (the calling code with the `+` left off) proves the pattern column, and it is the spelling the 489-case single-spelling fixture could not see — 201 of those cases were silently wrong before the third column landed. **6 example numbers carry no `intl-no-plus` case** (DE, ID, SE, XK) because for them the calling code plus the national number is itself a valid national number: both readings are correct, the metadata prefers neither, and a fixture picking one would assert a guess (UC-3). Excluded and counted in the generated header, never silently dropped. Beyond the two spellings it proves nothing about how people type; courtesy zeros, extensions, international access codes and the pattern **fall-through** are pinned by hand-written cases in `HelpersTest` instead — the fall-through deliberately, since the corpus cannot reach it by construction. |
| ~~U111~~ **RETIRED 2026-08-12** | **No longer a coupling: the reach was deleted, and this row outlived it by a day.** `Helpers::country_calling_code()` was removed on 2026-08-11 when the calling codes moved to the generated `CountryPhoneData`, and `get_country_calling_code` now has **0 references** anywhere in the tree (verified across `src/`, `compat/`, `tests/`, `tools/` and the root). The test double went with it in the same commit. The row is kept rather than deleted so the id is not re-used, and it is worth keeping for what it demonstrates: that commit retired U110 and narrowed U57 but did not reach U111, which is the same sibling-left-behind shape the code-review system keeps finding — **a registry row is a coupling's other end, so deleting the coupling is only half the change**. Historical content follows. — **`WC_Countries::get_country_calling_code()` return shape** — prefixed with `+`, an **array** for territories carrying more than one code, and `''` for a country it does not know. We delegate the ~200-entry calling-code table to WooCommerce rather than mirroring it, so this method's *shape* is the coupling. Available since WC 3.6, below our 5.0 floor, and reached through `property_exists`/`method_exists` guards so a removal degrades to "no phone hash" rather than a fatal | `src/Modules/WooCommerce/Helpers.php:609` `country_calling_code()` | https://woocommerce.github.io/code-reference/classes/WC-Countries.html | WC pre-releases (U84/U85) | silent-missing | quarterly | [x] 2026-08-10 signature `get_country_calling_code( string $cc ): string\|array`, "stripped of - and spaces and always be prefixed with +", `@since 3.6.0`. Test double in `tests/unit/Modules/wc-stubs.php` is held to the same shape incl. the unknown-country `''` (UC-3) |
| U58 | `new_customer` signal semantics | `src/Modules/WooCommerce/ProductData.php` | Google Ads answer 9917012 | — | silent-wrong | quarterly | [ ] |
| U108 | **GTM built-in Form variable keys `gtm.element*` (4 of 6 filled), and the fact that there is NO "Form Name" built-in.** The form interaction events fill `gtm.elementId`, `gtm.elementClasses`, `gtm.elementUrl` and `gtm.elementTarget` so a container can read them with the built-in **Form ID / Form Classes / Form URL / Form Target** variables. Three claims in one row: (1) those four keys still back those four built-ins; (2) Google still publishes **no Form Name built-in** — the form's `name` attribute therefore stays on our own `formName` key and must NOT be "completed" into a `gtm.elementName`, which no built-in reads and which Google could later define with a different meaning; (3) the Forms and Clicks categories still **share these six keys**, so on our events Click ID and Click Classes resolve to the form too — that is Google's design, not something we can separate, and it is why the keys are omitted entirely rather than emptied when the focused element belongs to no form. `gtm.element` and `gtm.elementText` are deliberately left unfilled: a DOM node reference and a whole form's `textContent` on every focus event. Values come from the DOM **properties** (`form.action` resolves to an absolute URL, falling back to the document URL) so they match what GTM itself pushes on a form submission, rather than from `getAttribute()` | `js/frontend/gtm4wp-form-move-tracker.js` `gtm4wp_form_move_native_params()` — one definition, spread into both events (UC-6) | GTM built-in variables reference, Forms and Clicks categories. Claim to re-verify: **the six Forms built-ins and their underlying keys are unchanged, and Form Name still does not exist** | — | silent-wrong | quarterly | [x] 2026-08-09 read from Google's built-in variables reference: Forms = Form Classes / Element / ID / Target / Text / URL over `gtm.elementClasses` / `gtm.element` / `gtm.elementId` / `gtm.elementTarget` / `gtm.elementText` / `gtm.elementUrl`, no Form Name, and Clicks reads the same six keys. Pinned from our side by the built-in-variable describe in `js/frontend/test/form-move-tracker.test.js`, including a negative case asserting no `gtm.elementName` is ever emitted |
| U59 | GTM native video variable names `gtm.video*` (**8**, `gtm.videoVisible` included) + status vocabulary | `js/frontend/lib/native-video-params.js` | GTM video trigger docs | — | silent-wrong | quarterly | [ ] |
| U102 | **`gtm.videoVisible` semantics — a rule we chose, not one Google publishes.** GTM's built-in Video Visible variable is documented only as "true if the video is visible in the viewport"; no threshold, no definition of *visible*, and its own YouTube trigger's rule is unpublished. `gtm4wpMediaVisible()` therefore measures at push time, in two parts: visible = `document.visibilityState !== 'hidden'` AND the player's box overlaps the viewport AND it is not `visibility:hidden`/`display:none`/0-area — i.e. **any** overlap, no percentage. The tab half was added after a dev-site report: media keeps playing in a background tab, so its progress milestones keep firing, and a geometry-only rule called every one of them visible. Two states stay undetectable and follow the page: a window covered by another window, and a player popped out to Picture-in-Picture. Claims to re-verify: (1) Google still publishes no threshold (if it ever does, ours becomes a *disagreement*, not a gap); (2) the wording is still viewport-based, and whether GTM's own trigger counts a background tab as visible — **the one place ours could differ from GTM's in the same container**. **This never fails loudly** — a container comparing Video Visible against GTM's own YouTube-trigger events sees two differently-derived booleans under one variable name, and nothing on our side can observe the divergence (UD-11: nobody complaining is not evidence). Deliberately NOT a stored IntersectionObserver ratio: a synchronous read cannot be stale | `js/frontend/lib/native-video-params.js` `gtm4wpMediaVisible()`; every `gtm4wpNativeVideoParams()` caller passes an `element` | https://support.google.com/tagmanager/answer/7182738 (built-in variables) + https://support.google.com/tagmanager/answer/7679325 (YouTube trigger); pinned from our side by `native-video-params.test.js` (partly-visible ⇒ true, the four scroll-past directions ⇒ false) | — | silent-wrong | quarterly | [x] 2026-08-07 both pages state only "visible in the viewport"; no threshold documented on either |
| U60 | AMP `https://www.googletagmanager.com/amp.json?id=…&gtm.url=SOURCE_URL` + `SOURCE_URL` macro | `src/Modules/Amp/AmpModule.php` | GTM AMP docs | — | silent-missing | quarterly | [ ] |
| U61 | `ecommerce: null` clear pattern | `js/frontend/gtm4wp-ecommerce-generic.js` | GA4 docs | — | silent-wrong | quarterly | [ ] |
| U62 | CF7 GA4 event names `form_start` / `form_submit` / `generate_lead` + the `form_status` parameter on the two submission events. `form_status` is **our own** name, but it rests on two external claims: (1) GA4 neither reserves nor auto-collects a `form_status` parameter (EM's form events collect `form_id` / `form_name` / `form_destination` / `form_submit_text`), so ours cannot collide with a differently-defined built-in; (2) EM's `form_submit` still means *submission attempt*, fired on the browser submit event before any server result — that is why ours fires on `wpcf7submit` regardless of outcome and needs the status parameter at all. If Google ever redefines `form_submit` as acceptance-only, ours becomes a **disagreement** with every other source in the same property, not a gap. Values are CF7's own status vocabulary, forwarded verbatim (no local list to drift — U32). Third claim, this one about **CF7's dispatch order**: both submission events are derived from `wpcf7submit` alone (with `generate_lead` gated on `status === 'mail_sent'`) *because* CF7 fires `wpcf7mailsent` **before** `wpcf7submit`, and GA4's convention is submit-then-lead. If CF7 ever reordered the two, or stopped firing `wpcf7submit` for an accepted send, `generate_lead` would go missing entirely rather than merely arrive early — check the order, not just the event names, when re-verifying U32 | `js/frontend/gtm4wp-contact-form-7-tracker.js` | GA4 form events / Enhanced Measurement docs; CF7 `includes/js/src/submit.js` for the dispatch order; pinned from our side by `contact-form-7-tracker.test.js` (status forwarded verbatim incl. an unknown status, omitted on `form_start`, and the exact `form_submit` → `generate_lead` sequence) | — | silent-wrong | quarterly | [ ] |

### E. Other external services & infrastructure

| ID | Dependency | Coupling site | Source / check | Watch | Failure | Cadence | Last verified |
|---|---|---|---|---|---|---|---|
| U101 | **Browser per-cookie size limit (~4096 bytes) — and *what* the 4096 counts.** The list-attribution writer (#405) evicts by measured encoded size against `GTM4WP_LIST_ATTR_MAX_BYTES = 3900`. Two readings of the same number: Chromium enforces it over **name + value** (`kMaxCookieNamePlusValueSize`), RFC 6265 §6.1 states it as **name + value + attributes**. Our writer emits a fixed 58 attribute bytes, so 3900 clears the stricter reading by 138 and holds either way. Claims to re-verify: (1) no shipping engine has lowered the per-cookie cap below 3900; (2) our attribute string is still ≤ 158 bytes — **a new cookie attribute is what silently eats this margin**, so re-measure `gtm4wp_write_cookie()` if one is added (`Partitioned` alone is 13). Headroom is not free: every byte withheld is a product the visitor loses attribution for, so do not "round down to be safe" without measuring the cost. Failure is silent and self-perpetuating - the browser rejects an oversized assignment whole and keeps the old value, so the next click re-reads the same oversized map and is rejected again, freezing attribution until the 3-day TTL. The PHP reader's `LIST_ATTRIBUTION_COOKIE_MAX_BYTES = 4096` is **not** this contract's other half: it measures the URL-decoded value and is a crafted-cookie guard | `js/frontend/gtm4wp-ecommerce-generic.js` (`GTM4WP_LIST_ATTR_MAX_BYTES`, the eviction loop in `gtm4wp_store_item_list_attribution()`); attribute bytes from `js/frontend/lib/gtm4wp-cookies.js` `gtm4wp_write_cookie()` | RFC 6265 §6.1; Chromium `net/cookies/canonical_cookie.h`; pinned from our side by `ecommerce-generic.test.js` asserting the written cookie stays ≤ 4096 bytes with long list names | — | silent-wrong | yearly | [x] 2026-08-07 measured: attributes = **58 bytes** (`;expires=` + fixed-width UTC date + `;path=/;SameSite=Lax`) → strict ceiling 4038. 20 entries of built-in (16-char) names = 2 636 bytes encoded, all 20 retained; entries retained at 3900 vs the 20-entry cap: 30-char names 20, 45-char 20, 60-char 15, 80-char 15. With the cap disabled, 20 entries of a 76-char widget title reached 5 508 bytes |
| U107 | **HTML input types with no "filled in" state.** The form interaction tracker's filled-only sub-option (`event-form-move-filled-only`) suppresses `gtm4wp.formElementLeave` unless the field went from empty to non-empty during one focus visit. That check is meaningful only for elements that carry a text value, so `input` types **checkbox, radio, button, submit, reset, image** are exempt and always reported, as are `button`, `meter` and `progress` elements. The list is a snapshot of the HTML standard's control types (UD-1) and the tracker reads the DOM `type` property, which normalizes a missing or unrecognized attribute to `text`. **Failure mode is silent and one-directional**: a control type added to the standard later that behaves like a button lands on the checked side by default, its `value` reads empty, and its leave event simply stops being reported on sites that switched the option on — no error, no failing test | `js/frontend/gtm4wp-form-move-tracker.js` `gtm4wp_form_move_unfillable_types` (one definition, read by both handlers) | HTML Living Standard, the `input` element's type keyword table. Claim to re-verify: **no control type added since this row was written belongs on the exempt side** | — | silent-missing | yearly | [x] 2026-08-09 written against the current type list; all six exempt types and the three exempt elements are pinned by the `it.each` case in `js/frontend/test/form-move-tracker-filled-only.test.js`, each with an explicit empty `value` so the assertion fails if the exemption is dropped |
| U112 | **The gtm4wp.com documentation URLs behind the settings screen's help links — 42 page paths and 110 option anchors.** Two halves that fail differently. (1) **The page path**, one per `doc:` on a Field plus one per schema `doc_url()`, resolved against the single `Docs::BASE`. A page renamed or re-parented on the site leaves a link the plugin cannot notice. (2) **The anchor**, which is always the option key and therefore ours: option keys are frozen 1.x public API, so the fragment cannot drift — but the `<a name="<option key>">` it points at on the page can be dropped by an editor, and the link then silently degrades to "opens the page at the top". ⚠️ **A redirect counts as drifted, not verified.** The two links this feature generalised had pointed at the retired `/how-to-articles/…` path since 1.x and nobody ever noticed, precisely because WordPress kept redirecting them — the textbook UD-11 shape, and the reason the probe below refuses a 3xx. Failure is invisible from inside the plugin: no PHP error, no failing unit test, and no report from the user who gave up. The `unit`-suite half of the control (`ModuleConsistencyTest::test_every_field_declares_a_documentation_page`) pins only that every option **names** a target, and additionally that the value is a path and carries no `#` — so the single definition of the domain and of the anchor rule cannot be escaped field by field | `doc:` on every Field and `doc_url()` on every schema in `src/Modules/*/AdminSchema.php`; `src/Admin/Docs.php` (`BASE`, `gtm4wp_admin_doc_url`); the two editorial in-description links in `src/Modules/WooCommerce/AdminSchema.php` | `composer test:doc-links` (`tests/network/DocLinksTest.php`, outside the unit suite and outside CI because it needs the network) — asserts 200 **without a redirect** per path, and the anchor present per option. It reads the paths out of the built schemas rather than regexing the PHP, because they are assembled from class constants | — | silent-missing | on-demand (before a release) | [x] 2026-08-11 **both halves verified.** Measured by the probe, not estimated: the **40 distinct page paths** it fetches (the de-duplicated set — one request per path, however many options point at it) all answer 200 with no redirect, and all **110 option anchors** resolve to a `name=`/`id=` equal to the option key on the page that option links to. The anchor half was finished in this run: the outstanding **51** anchors were added across 29 pages, each on the heading that documents its option rather than on the page's first heading, so the link lands on the prose that explains the setting. Re-derive both counts with `composer test:doc-links`; do not read them from here |
| U63 | Cloudflare `HTTP_CF_IPCOUNTRY` header | `src/Modules/PageVariables/PageVariablesModule.php` | Cloudflare docs | — | silent-missing | quarterly | [ ] |
| U64 | Proxy client-IP header semantics — `X-Forwarded-For` appends (rightmost), `CF-Connecting-IP`/`True-Client-IP`/`X-Real-IP` replace | `src/Frontend/VisitorIp.php` | proxy vendor docs | — | silent-wrong | quarterly | [ ] |
| U65 | **8** remote player SDK URLs — YouTube, Vimeo, SoundCloud, Dailymotion, Mixcloud, **Cloudflare Stream `sdk.latest.js` (floating, UD-6)**, Spotify, Twitch. **Dailymotion is the only parameterized one, and the only one still built in PHP**: `https://geo.dailymotion.com/libs/player/<PLAYER_ID>.js` when the site configured a player ID, else the ID-less `https://geo.dailymotion.com/libs/player.js`. Its predecessor `api.dmcdn.net/all.js` was sunset for player integration on **2026-02-03** and is the textbook UD-11 case — the file still returns 200 and only `console.warn`s, so nothing on our side ever observed that every Dailymotion event had stopped firing. Three Dailymotion claims, each weaker than a plain "does the URL resolve": (1) the **ID-less** `libs/player.js` still returns a working library (`"player":{"id":"default",…,"status":"ok"}`) — this is **our own discovery, not a documented entry point**, so it carries a weaker promise than the per-player URL and is what keeps the feature usable without a Dailymotion Studio account; (2) the file **must not be self-hosted or cached** — its own header says *"generated dynamically, do not host it yourself. Statically hosting or caching this file will break your integration"*, recorded here because "self-host the SDK for performance" is exactly the optimization a future contributor reaches for; (3) `createPlayer`'s `player` option still overrides the loaded library's configuration, which is what lets a `/player/<pid>.html` embed keep its own player on a site that configured none. YouTube's `//www.youtube.com/iframe_api` is **protocol-relative and was previously unregistered** — it was the one SDK the tracker already injected itself, so it never appeared in the PHP enqueue list this row used to describe (UD-1: a coupling with no row is invisible, not unverified). **Mixcloud has split its loader from its app** — the URL we load, `https://widget.mixcloud.com/media/js/widgetApi.js`, is still the loader and still the API surface we bind to, while the player *application* now ships from `player-widget.mixcloud.com` as a Vite build (`/assets/index-<hash>.js`). The two hosts can retire independently, so a 200 on the app host says nothing about ours, and the plausible-looking `player-widget.mixcloud.com/widget/js/api.js` is a **404** — do not "modernize" the URL to a host that does not serve it | `js/frontend/gtm4wp-*.js` — the 4th argument to `gtm4wpObserveMedia()` (a bare URL, or `{ src, subscribe }` for YouTube/Spotify, whose SDKs signal readiness through a global callback). **Moved here from `MediaEventsModule.php` when the SDKs stopped being enqueued for every page**: PHP cannot see whether the rendered page has an embed, so all 8 are now fetched by the tracker only after its DOM scan finds one. ⚠️ **Three of the eight are two-hop loaders, not the API** — measured 2026-08-07: YouTube's `iframe_api` is ~1.2 KB that only assigns `YT={loading:0,loaded:0}` and injects `www-widgetapi-script`; Spotify's `/embed/iframe-api/v1` is ~1.1 KB that does **not** itself define `onSpotifyIframeApiReady` or `createController`; Mixcloud's `widgetApi.js` is the loader for an app now shipped from `player-widget.mixcloud.com`. For these three the URL we pin and the code that actually defines the global are **different, independently retireable artifacts**, and the second hop is unversioned and invisible to us — so a 200 on the loader is evidence about the loader and nothing else. Both readiness gates that survive this are the ones testing the *member* (`YT.Player`, the fired callback), never the bare global. **Dailymotion is the half-exception**: `MediaEventsModule::enqueue_scripts()` still *builds* its URL (only PHP has the stored player ID, and only PHP can `rawurlencode()` it where it enters the path) and publishes it as `gtm4wp_dailymotion_config.sdk` via `wp_add_inline_script(..., 'before')` — the tracker fetches it, never assembles it, so the URL still has exactly one definition | reachability + API shape. Claim to re-verify per SDK: **the URL still returns 2xx JavaScript and still assigns the global U66 names** — measured with a request, never inferred from the absence of complaints (UD-11: nothing on our side observes a dead SDK; the tracker simply never wires). Pinned from our side by a per-tracker `SDK loading` describe in `js/frontend/test/<provider>-tracker.test.js`, which asserts the exact URL is requested when an embed is present and that **nothing** is requested when it is not. ⚠️ **Reachability is the weaker half of this row and cannot be the whole check** — see the Watch column: Dailymotion's sunset SDK kept returning 200 for six months after it stopped working | **Vendor deprecation / changelog channels — check these, not just the CDN.** Verified live 2026-08-07: YouTube → the **Revision History** section of `developers.google.com/youtube/iframe_api_reference` (last entry 2025-07-09; already lists `getPlaybackQuality`/`setPlaybackQuality` as no-ops); Vimeo → `github.com/vimeo/player.js/releases` (**the strongest channel here — the only SDK that ships a real version number**, so it can be diffed rather than sniffed); Cloudflare Stream → `developers.cloudflare.com/changelog/` filtered to Stream, RSS at `/changelog/rss/index.xml`; Twitch → `dev.twitch.tv/docs/change-log`; SoundCloud → `github.com/soundcloud/Widget-JS-API` + `developers.soundcloud.com/docs/api/html5-widget` (**weak**: repo is unarchived but near-dormant, README carries no version and no notice, so absence of news is not news); Spotify → `developer.spotify.com/community/news/` (**candidate, not yet verified**) — note the `/v1` in our URL is itself the signal, a `/v2` appearing is the drift event; Mixcloud → **no formal channel exists**, which is why its loader/app split had to be discovered by fetching. Dailymotion → **`reference/migration-guide-new-embed-endpoint` and the `dailymotion/dailymotion-sdk-js` README, NOT `reference/deprecations`**: that page read *"We don't have ongoing deprecation for the moment"* throughout the entire player sunset and still does, so watching the page named "deprecations" would have caught nothing. The lesson generalises — a vendor's *migration guide* carries dated milestones its deprecation page may never mention | silent-missing | quarterly | [x] 2026-08-07 **all 8 fetched, all current, none deprecated.** YouTube `iframe_api` → 200, ~1.2 KB, assigns `YT={loading:0,loaded:0}` and injects `www-widgetapi-script`; Vimeo → 200, banner `@vimeo/player v2.30.4 \| (c) 2026`, assigns `Vimeo.Player` — **matches the latest GitHub release (v2.30.4, 29 Apr), so we are exactly current**; SoundCloud → 200, ~3.8 KB, `window.SC=window.SC\|\|{},window.SC.Widget=i`; Dailymotion → the new `geo.dailymotion.com/libs/player.js`, 200, `"player":{"id":"default",…,"status":"ok"}`; Mixcloud → 200, 194 597 bytes (verified 2026-08-05, unchanged); Cloudflare Stream → 200, ~20 KB, `window.Stream=function(r)`, internal `1.6.7`, and the docs still name this exact host+path; Spotify → 200, ~1.1 KB **loader only**; Twitch → 200, ~19.3 KB UMD assigning `Twitch` with `Embed` and `Player`, changelog current to 2026-07-31 with nothing about embeds. **No vendor body contained "deprecat", "sunset", "no longer supported" or "legacy"** |
| U66 | Player API globals: `YT.Player`, `Vimeo.Player`, `SC.Widget`, **`dailymotion.createPlayer` *and* `dailymotion.events`** (both, because the readiness gate tests both — and here **the bare global is not the claim**: Dailymotion's documented bootstrap has the page define `window.dailymotion = { onScriptLoaded: … }` *before* the library loads, so `typeof dailymotion !== 'undefined'` is routinely true with no API on it. A gate that checked only the global would tear the embed out and then fail to build anything. The predecessor `DM.player` is gone with the 2026-02-03 sunset, see U65), `Mixcloud.PlayerWidget`, `Stream`, `window._wq`, `jwplayer`, `Twitch.Player`, `onSpotifyIframeApiReady`. **Plus the two DOM accessors the U102 visibility measurement needs**, which are a weaker class of promise than the globals: `YT.Player.getIframe()` (documented) and Wistia `video.elem()` (**not** in the Player API reference — guarded, with the documented `wistia_async_<hashedId>` embed class as the fallback, so losing it degrades to the markup path rather than throwing) | `js/frontend/gtm4wp-*.js` | vendor SDK docs. Claim to re-verify, **in two parts, because the first part alone is what let the Dailymotion break run undetected for six months**: (1) the SDK body still assigns the global and still exports the member the readiness gate tests — read it out of the fetched source, never assume it from the URL resolving (U65); **and (2) the vendor still SUPPORTS this integration method.** Part 2 is not implied by part 1 and is not checkable from the SDK body: Dailymotion sunset `DM.player` on 2026-02-03 and kept serving a file that still defined `DM` and still defined `DM.player` — the symbol resolved, the readiness gate passed, and every call did nothing but `console.warn`. A symbol-existence check would have returned PASS on any day of those six months. UD-6's "if you bind to a global, the claim is that *the symbol* still exists" is therefore **necessary but not sufficient**, and this row is the counter-example: part 2 is answered at the vendor's migration guide / changelog (U65's Watch column), never at their CDN | see **U65's Watch column** — the deprecation/changelog channel per vendor is shared between the two rows and verified once | silent-missing | quarterly | [x] 2026-08-07 **8 of 10 globals confirmed from the fetched SDK body**: `Vimeo.Player` (`e.Vimeo.Player=t()`), `SC.Widget` (`window.SC=window.SC\|\|{},window.SC.Widget=i`), `Stream` (`window.Stream=function(r)`), `Twitch.Player` (UMD `e.Twitch=t()`, exports `Embed` + `Player`), `Mixcloud.PlayerWidget` (`PlayerWidget: t => new i(t).external`), `dailymotion.createPlayer` + `dailymotion.events` (the `DM.player` replacement). `YT.Player` and `onSpotifyIframeApiReady` are **not** in their fetched bodies and are **not expected to be** — both live on the second hop (U65), so for those two the gate is the only observation we have. `window._wq` / `jwplayer` unverified: no SDK is fetched for them. **Part 2 checked for all 8: no vendor currently announces a deprecation of the method we use** |
| U67 | Embed iframe host selectors (9 vendors) + constructed media URLs. **Dailymotion carries three extra sub-claims**, because the tracker no longer just *matches* the embed, it *replaces* it: (a) the four src forms the parser handles — `geo.dailymotion.com/player.html?video=`, `geo.dailymotion.com/player/<pid>.html?video=`, legacy `www.dailymotion.com/embed/video/<id>` (301s to the first), `dai.ly/<id>` — **and that WordPress' oEmbed still emits the first of them** (WP sends no `player` parameter, which is why the embed is ID-less); a silent switch to a `?playlist=`-style or new-host output would make the tracker bail on every embed with nothing to observe; (b) `https://www.dailymotion.com/video/<id>` is still the canonical watch URL the pushes report as `gtm.videoUrl` (the embed src cannot be used — every video on a site is served from the same `player.html`); (c) **the SDK still injects its player iframe *into* the container** rather than replacing the container. If that flips, the marked-ancestor skip stops applying and only `gtm4wpObserveMedia`'s slot re-mark stands between us and an unbounded re-wire loop. ⚠️ **The src the selector matches is not always the src the vendor emitted**: WordPress rewrites the iframe of every oEmbed result from a provider outside core's trusted list (U106), so the *constructed media URL* half of this row is read through `gtm4wpMediaSrcUrl()` — a parse that cut at the `?` alone reported a Cloudflare Stream video as `iframe#` | `js/frontend/gtm4wp-*.js` | vendor embed formats. Claim to re-verify: **the selector still matches the markup the vendor's current oEmbed/embed code actually produces** — check against a live embed, since the vendor owns that markup end to end and a host change breaks the selector silently | — | silent-missing | quarterly | [~] 2026-08-07 **Mixcloud only**: live embed is `<iframe src="https://www.mixcloud.com/widget/iframe/?feed=…">`, so `iframe[src*="mixcloud.com"]` still matches. Probe did not reach the other 8 vendors |
| U103 | **Dailymotion Player Embeds vocabulary — 11 event names subscribed of 15 known, plus the 8 state fields the pushes read.** Subscribed: `PLAYER_CRITICALPATHREADY`, `VIDEO_PLAY`, `VIDEO_PAUSE`, `VIDEO_END`, `VIDEO_SEEKEND`, `VIDEO_BUFFERING`, `VIDEO_TIMECHANGE`, `PLAYER_VOLUMECHANGE`, `VIDEO_QUALITYCHANGE`, `PLAYER_PRESENTATIONMODECHANGE`, `PLAYER_ERROR`. Deliberately **not** subscribed: `VIDEO_START` (once per content video; `VIDEO_PLAY` is "no longer paused", i.e. per resume, which is what the family reports as `start` — subscribing to both double-counts the first play), `VIDEO_SEEKSTART` (would double every scrub with the pre-seek position), `VIDEO_DURATIONCHANGE`, `PLAYER_VIEWABILITYCHANGE`. State fields read: `videoTitle`, `videoOwnerScreenname`, `videoDuration`, `videoTime`, `videoQuality`, `playerVolume`, `playerError`, `playerPresentationMode`. Two names worth recording because they mislead: **`videoId` exists but is deliberately unused** (the id is parsed from the src, so it is available before the player is and cannot change identity mid-playback and re-fire every milestone), and **`playerVideoId` does not exist** — a plausible-looking name that is not real. Unlike every other player SDK here, a Player Embeds event carries the **full player state**, not a per-event payload | `js/frontend/gtm4wp-dailymotion.js` | Dailymotion Player Embeds event + state reference. Claim to re-verify: **these exact 11 names still resolve on `dailymotion.events`, and these exact 8 state fields still carry the values the pushes report**. Pinned from our side by `js/frontend/test/dailymotion-tracker.test.js`, whose fake exposes only real event names, throws on anything else (UC-3), and asserts the subscribed set exactly — so a rename, an addition, a removal or a typo all fail rather than registering a handler nothing will ever emit | — | silent-wrong | quarterly | [ ] |
| U104 | **The real Spotify title lives in the embed's `title` attribute, and Spotify prefixes it with the literal `Spotify Embed: `.** The Embed IFrame API carries no title at all — `playback_update` reports only `isPaused`/`isBuffering`/`position`/`duration`/`playingURI` — so the attribute is the primary title source and the raw `spotify:<type>:<id>` URI is what gets reported when it is absent. Two claims, failing differently: (1) **Spotify's oEmbed `html` still carries a `title` attribute** — if it stops, every title silently reverts to the URI (`silent-missing`); (2) **the prefix is still exactly `Spotify Embed: `** — if Spotify rewords it the new prefix leaks into every GA4 **Video Title** row (`silent-wrong`), the worse of the two because the data still looks populated. ⚠️ **The prefix is Spotify's own string, not one WordPress composes.** Core's `wp_filter_oembed_iframe_title_attribute()` (WP 5.2+, `oembed_dataparse`) *keeps* an existing `title` attribute rather than building one, so this is a fixed English literal on every locale and matching it by string is safe — do not "fix" it into a translated-prefix matcher. WP populates the same attribute for every oEmbed provider it handles, but the prefix is per-vendor, which is why this stays a Spotify-scoped row | `js/frontend/gtm4wp-spotify.js` (`gtm4wp_spotify_title_prefix`, `gtm4wp_spotifyTitleFromFrame()`) | fetch `https://open.spotify.com/oembed?url=<content URL>` and read the `title=` attribute out of the returned `html`, then diff it against the sibling top-level `title` field — **the difference between the two IS the prefix**, so the check needs no stored literal to compare against. Pinned from our side by the `title resolution` describe in `js/frontend/test/spotify-tracker.test.js`, which asserts the prefixed form is stripped AND that an unprefixed attribute survives verbatim | — | silent-wrong | quarterly | [x] 2026-08-07 measured across **5 content types, prefix identical on all**: track `Spotify Embed: Never Gonna Give You Up`; album `…: Scorpion`; playlist `…: Today's Top Hits`; show `…: #2536 - Eric Goode &amp; Jeremy McBride`; episode `…: Csukás István - Pom Pom meséi - Festéktüsszentő Hapci Benő`. Live check: a WP-oEmbed'd episode on woo.gtm4wp.dev rendered with the prefixed attribute intact, confirming core preserves rather than rewrites it |
| U105 | **Spotify oEmbed endpoint `https://open.spotify.com/oembed?url=<content URL>`** — the fallback title source, requested from the visitor's browser only when the embed carries no `title` attribute (U104), and at most once per URI per page. Three claims, all silent: (1) it still answers **2xx JSON carrying a string `title`**; (2) it still sends **`access-control-allow-origin: *`** — a CORS tightening alone kills the fallback with nothing on our side to observe, and is the likeliest of the three to happen since it costs Spotify nothing; (3) the `spotify:<type>:<id>` → `https://open.spotify.com/<type>/<id>` mapping the request URL is built from still addresses the content. The endpoint is an **alias**: the response's own `sp_landing` cookie names `open.spotify.com/api/oembed`, so `/oembed` is the front for `/api/oembed` and the two could diverge independently. ⚠️ **This is the only network request any media tracker makes** — the rest of the family is deliberately request-free — so this is also the row to re-read before asserting that property again anywhere | `js/frontend/gtm4wp-spotify.js` (`gtm4wp_spotify_oembed_endpoint`, `gtm4wp_resolveSpotifyTitle()`) | request it with an `Origin` header and read back **both** the body and the CORS response header — a 200 alone answers only claim 1. Pinned from our side by `js/frontend/test/spotify-tracker.test.js`, which asserts the exact request URL and `credentials: 'omit'`, and that a failed lookup is cached so it is never retried | — | silent-missing | quarterly | [x] 2026-08-07 `?url=…/episode/33zC…` sent with `Origin: https://woo.gtm4wp.dev` → **200**, `content-type: application/json`, **`access-control-allow-origin: *`**, body carries `"title":"Csukás István - Pom Pom meséi…"`. Same shape confirmed for track/album/playlist/show. Response sets `sp_t` for `.spotify.com` (`Secure; SameSite=none`), which is why the lookup is issued with `credentials: 'omit'` |
| U68 | VideoPress `postMessage` origin allow-list | `js/frontend/gtm4wp-videopress.js` | Automattic | — | silent-missing | quarterly | [ ] |
| U109 | **The VideoPress `postMessage` vocabulary, and the fact that each message carries only *its own* payload.** Two halves, and the second is what broke. (1) **Event names.** The player emits `videopress_playing`, `_pause`, `_ended`, `_seeking`, `_volumechange`, `_timeupdate`, `_durationchange`, `_loading_state`, `_toggle-source` and — the one exception — **`toggle_fullscreen` with NO `videopress_` prefix**, which a bare prefix test drops on the floor. There is **no** `videopress_loadedmetadata`, `_play`, `_paused` or `_seeked`; `play` is kept as a tolerated alias only because Sensei's own adapter listens for it. (2) **Payload distribution.** Unlike every other embedded player here, VideoPress does **not** repeat the playback values on each message: `_timeupdate` carries `currentTime`/`currentTimeMs` and **no duration**, `_durationchange` carries `duration`/`durationMs` and **no position**, and every state message carries **neither** — only `event` and `id`. Reading them off the message in hand therefore reports 0 for both on every state change, and starves the percentage tracking of the duration it divides by, so `gtm4wp.mediaPlaybackPercentage` stops firing **entirely**. That is exactly what shipped, and it is the textbook UD-11/UC-3 pair: nothing errors, and the suite was green *because* its fixtures put both fields on every message — a test double more generous than the player. The tracker therefore keeps a per-guid record of the last reported position and duration and feeds that into every push, and `_durationchange` doubles as the ready signal (once per guid — a quality switch reports the duration again) | `js/frontend/gtm4wp-videopress.js`: `gtm4wp_videopress_playback` + `gtm4wp_videoPressPlayback()` (the record), `gtm4wp_videopress_unprefixed_events` (the fullscreen exception), `gtm4wp_videopress_ready` (the once-per-video ready) | https://github.com/Automattic/videopress-player-api-doc/blob/trunk/postmessage.md, cross-read against two independent consumers: Jetpack's `projects/packages/videopress/src/client/lib/player-bridge/index.ts` (the name list) and Sensei's `assets/shared/helpers/player/videopress-adapter.js` (which stores the duration in `element.dataset.duration` for the same reason). Claims to re-verify: **(a) the name list above, `toggle_fullscreen`'s missing prefix included; (b) that `_timeupdate` still carries no duration and the state messages still carry no times.** Pinned from our side by `js/frontend/test/videopress-tracker.test.js`, whose fixtures carry only the properties the real message carries — keep them that way, a generous fixture here is what hid this for a whole release cycle | U68, U102 | silent-wrong | quarterly | [x] 2026-08-10 read from the postMessage doc and both consumers; reproduced against the live sandbox embed `video.wordpress.com/embed/GjfCen6N` on `woo.gtm4wp.dev/videopress-player-test`, where every event reported time 0 / duration 0 and no percentage event fired at all |
| U69 | UA-CH `navigator.userAgentData.getHighEntropyValues()` + Chromium GREASE brand filter regex | `js/frontend/gtm4wp-client-device-data.js` | Chromium UA-CH spec | — | silent-wrong | quarterly | [ ] |
| U70 | Full-page cache behavior assumptions (LiteSpeed, WP Rocket, Varnish, Cloudflare APO) | `src/Modules/VisitorData/*`, `js/frontend/gtm4wp-visitor-data.js`, `docs/dev/cache-safe-data-layer.md` | — | — | silent-wrong | on-demand | [ ] |
| U71 | PHP↔JS name contracts: `gtm4wp_item_list_attr`, `gtm4wp_orderid_tracked`, `gtm4wp_woo_event`, `gtm4wp_readded_to_cart`, `div.gtm4wp-wc-visitor-data`, **`gtm4wp_apply_stored_item_list_to_event`** (`Helpers::LIST_ATTRIBUTION_JS_WRAPPER`, emitted by `DataLayer::queue_push()` as a `window.` lookup into the product-detail `view_item` push), the cart-fragment payload's `customer`/`cart` envelope keys, and the three data layer event names in `config.events` (`VisitorDataModule::EVENT_*` ↔ the `resolveEventName()` fallbacks) (UC-4, internal) | `src/Modules/WooCommerce/{Helpers,ProductData,PageDataLayer}.php`, `src/Frontend/DataLayer.php`, `src/Modules/VisitorData/VisitorDataModule.php` ↔ `js/frontend/*.js` | internal grep; the wrapper name is pinned from both sides (`PageDataLayerTest::test_list_attribution_wrapper_name_matches_the_javascript_export()` reads the JS source; `ecommerce-generic.test.js` asserts the export) | — | silent-missing | every-run | [ ] |

### F. Toolchain & build

| ID | Dependency | Coupling site | Source / check | Watch | Failure | Cadence | Last verified |
|---|---|---|---|---|---|---|---|
| U72 | PHP floor `8.0` — **6 sites**; CI executes **none** of them (matrix is 8.2 + 8.4) | plugin header, `readme.txt`, `composer.json`, `phpcs.xml` `testVersion`, runtime `version_compare`, `.claude/CLAUDE.md` | grep agreement + CI matrix | — | loud | every-run | [x] 2026-08-05 (S1) agree; **D5** on CI |
| U73 | Composer direct deps (phpcs, wpcs, php-compatibility, phpunit, brain/monkey) | `composer.json` | `composer outdated --direct --format=json` (exit 0 either way) | — | loud | every-run | [x] 2026-08-05 (S1) → D7 |
| U88 | `composer.lock` **tracking status** — if it is not committed, `composer install` behaves like `composer update` and CI runs freshly published dev dependencies (and transitive Composer plugins, which execute code at install time) unreviewed | root `.gitignore`, `.github/workflows/ci.yml` | `git ls-files --error-unmatch composer.lock` | — | loud | every-run | [x] 2026-08-06 **now tracked** — `ls-files` exits 0, `check-ignore` does not match. Reverses the 2026-08-05 (S1) reading that raised D6; `ci.yml` documents the pinning rationale in place |
| U74 | npm devDeps + the hand-maintained `overrides` block (12 transitive pins, count measured 2026-08-13; `puppeteer-core ^25` added to shed the unfixable `extract-zip` chain — droppable once `@wordpress/e2e-test-utils-playwright` moves to `lighthouse ^13`) | `package.json` | `npm outdated --json` (**exits 1 when anything is outdated**), `npm audit` | — | loud | every-run | [x] 2026-08-05 (S1) `outdated` only → D7; **`audit` not run** |
| U75 | `@wordpress/scripts` internal config shape (webpack `defaultConfig` spread; jest `hasBabelConfig()` avoidance) | `webpack.config.js`, `jest.config.js` | wp-scripts releases | — | loud | quarterly | [ ] |
| U76 | **`@wordpress/components` `__next*` opt-in props** — unpinned runtime external, breaks on the *user's* WP update, test stand-in cannot catch it (UC-3, UC-7) | `js/admin/components/*.js`; stand-in `js/admin/test-support/wp-components.js` | Gutenberg releases | U82 | silent-wrong | on-WP-release | [ ] |
| U94 | **`@wordpress/components` `TabPanel` — `initialTabName` + `onSelect`** — the settings-screen deep link (`?gtm4wp-focus=`) rides on the first and the bookmarkable `#module/tab` URL on the second, and `TabPanel` is the control Gutenberg's newer `Tabs` is meant to replace | `js/admin/components/ModulePanel.js`; stand-in `js/admin/test-support/wp-components.js` | Gutenberg releases | U82, U86 | silent-wrong | on-WP-release | [ ] |
| U77 | Build-asset declared handles (`wp-components`, `wp-element`, `wp-data`, `wp-api-fetch`, `wp-i18n`, `react-jsx-runtime`) | `build/*.asset.php` | core script handles | U78 | loud | on-WP-release | [ ] |
| U78 | Patchwork redefining PHP internals `headers_sent`, `setcookie` | `patchwork.json` | Patchwork releases | — | loud | quarterly | [ ] |
| U79 | Release packaging allow-list `DIST_FILES` — a new required top-level dir not listed ships broken | `tools/build-release.js` | grep vs repo tree | — | silent-missing | every-run | [ ] |
| U97 | **Text domain `duracelltomi-google-tag-manager` == the wp.org slug** — core resolves `.mo` files by domain alone (no fallback), wp.org names packs from the GlotPress slug, so the two must agree. 14 live packs depend on it. See UB-6; the long `-for-wordpress` folder name is *not* the slug | plugin header `Text Domain`; 348 i18n calls in `src/`; `phpcs.xml` `text_domain` property | `api.wordpress.org/plugins/info/1.2/` + `/translations/plugins/1.0/` | — | silent-missing | on-demand | [x] 2026-08-06 measured — short slug 200, long slug 404, 14 packs. **Never rename**; it would orphan all 14 silently |
| U98 | **Admin React app JS translations** — `wp_set_script_translations( 'gtm4wp-admin-app', … )` is called with no `$path`, so core requests `WP_LANG_DIR/plugins/{domain}-{locale}-{md5}.json` keyed on **`md5('build/admin.js')` = `aa377c165c6b87664fc2deacf28cbe53`**. The claim is that wp.org's pack builder hashes the same string. 2.0 is the first release this can fail on — the 1.22.5 packs contain no `.json` at all, 1.x having no JS strings | `src/Admin/SettingsPage.php:137` (handle + domain, no `$path`); `webpack.config.js` (output filename); `tools/build-release.js` `DIST_FILES` (`build`) | core `_load_script_textdomain_from_src()` vs wp.org `class-language-pack.php` | U97 | silent-missing | pre-release / after first 2.0 pack build | [~] 2026-08-06 **three of four links verified, one unobservable** — see below |

**On U98 — what was measured 2026-08-06, and the one link that cannot be.** The chain
has four links; three were tested directly.

1. **Extraction records `build/admin.js`.** Ran the real `wp i18n make-pot` (WP-CLI 2.12.0)
   against the **expanded release ZIP**, not the repo tree — the shipped tree has `build/`
   but no `js/`, which is what wp.org receives. Result: **31 strings, every reference
   `#: build/admin.js:1`.** The bundle is minified but not named `*.min.js`, and that
   filename pattern is the extractor's only minification exclusion (there is no
   line-length heuristic), so it is parsed. Terser preserves the `.__` property access —
   `(0,s.__)("Import settings","duracelltomi-google-tag-manager")` — and the scanner reads it.
2. **wp.org hashes that reference verbatim.** `class-language-pack.php:434` —
   `$hash = md5( $file ); $dest = "{$base_dest}-{$hash}.json";`. No stripping, no
   normalisation; the only filter skips paths under `src/` (ours are under `build/`).
3. **Core requests the same name.** `_load_script_textdomain_from_src()` strips the content
   URL path, drops the first two segments (`plugins/<slug>`), leaving `build/admin.js`, then
   builds `$file_base . '-' . md5( $relative ) . '.json'`. Same input, same hash.
4. **Unobservable until release:** whether wp.org's GlotPress project actually builds a JS
   sub-project for this plugin and clears the **90 % pack threshold** per locale. Nothing
   local can answer it. Re-check after the first 2.0 pack build.

**False alarm worth recording so it is not re-raised:** WP-CLI's own `make-json` emits
`"source":"build/a.js"` for this input and therefore a different md5. That is a `make-json`
filename-rewriting artifact (it rewrites `*.min.js` names); wp.org's builder does no
rewriting and does not share it. Do not "fix" the build to satisfy `make-json`.

**Pinned by a test, so link 3 cannot rot silently:**
`SettingsPageTest::test_admin_app_script_path_and_text_domain_stay_pinned_for_translations`
asserts the registered src ends in `build/admin.js` and that the domain is
`duracelltomi-google-tag-manager`. Verified to fail on a mutated path. The doubles for
`wp_enqueue_script` / `wp_set_script_translations` were changed from `justReturn( true )`
to argument-recording in the same change — they previously absorbed the exact arguments
the coupling rides on (UC-3).
| U95 | `readme.txt` header block as **published claims** — `Stable tag`, `Requires at least`, `Requires PHP` must agree with the plugin header. U2 covers the fourth sibling (`Tested up to`) and had a row while these three did not | `readme.txt`; plugin header | grep agreement + wp.org readme spec | — | silent-wrong | every-run | [x] 2026-08-06 `Requires at least` (6.3) and `Requires PHP` (8.0) agree. **`Stable tag: 1.22.5` deliberately trails the header `2.0.0-beta2`** — `master` is the unreleased 2.0 line, the live line is `1.x`. Retire the exception the moment they converge at 2.0.0 GA |
| U96 | **wordpress.org Plugin Directory guidelines** + the Plugin Check ruleset the review team runs. Standing constraints this plugin must never violate: no self-update routine or `Update URI` header, no offloading assets to a CDN, no obfuscated code, no `unfiltered_uploads`, no external admin-menu links, no direct-execution-unguarded PHP file | whole release artifact; `tools/build-release.js` `DIST_FILES`; plugin header; `readme.txt` | guidelines page (undated) + Plugin Check release diffs | — | silent-missing | on-demand / pre-release | [x] 2026-08-06 tool analysed and **deliberately not adopted** — see below |

**On U96 — why the tool is not wired in (2026-08-06).** Plugin Check (PCP) was evaluated
against its own source after a wp.org recommendation. Its `security` category is
redundant here: `late_escaping` wraps `WordPress.Security.EscapeOutput`, the same sniff
`phpcs.xml` already runs with warnings blocking, and `wp_json_encode` is on WPCS's
escaping-function list so the dataLayer idiom is already recognised. It performs **no
authorization analysis at all** — no capability, permission-callback, ownership or taint
checking — which is where `.security/` does its real work (PA-1, PA-10, FP-5). Its
`plugin_repo` category is the part with no owner, and it is what this row now carries.
Running the tool needs Docker + wp-env and a third-party composite action; that trade was
declined in favour of registering the contract and moving the mechanical half into
`phpcs.xml`, which runs in the existing CI job. **Re-open if** a wp.org submission is
rejected on a code we do not already check, or if the review team makes a non-`plugin_repo`
category gating.

### G. Watch channels

These are dependencies too (UD-9). A channel that stops resolving makes every row that
relies on it report a false all-clear.

| ID | Channel | Endpoint | Feeds | Failure | Cadence | Last verified |
|---|---|---|---|---|---|---|
| U80 | WP core stable | `https://api.wordpress.org/core/version-check/1.7/` → `.offers[0].version` | U2, Radar | silent-wrong | every-run | [x] 2026-08-05 (S1) → `7.0.2` |
| U81 | WP core beta/RC | `https://api.wordpress.org/core/version-check/1.7/?channel=beta` → `.offers[0].version` | Radar, U3–U14 | silent-wrong | every-run | [x] 2026-08-05 (S1) → `7.1-beta4` |
| U82 | WP core dev notes / Field Guide | `https://make.wordpress.org/core/tag/dev-notes/feed/` · `https://make.wordpress.org/core/tag/field-guide/` | U3–U14 (UD-8) | silent-wrong | every-run | [x] 2026-08-06 (S1a) **both swept**: 7.0 Field Guide (2026-05-14) and 7.1 Field Guide (2026-08-05), plus all 16 individual 7.1 dev notes. One finding (U90); nothing else in A reached |
| U92 | **Runtime smoke test on a pre-release core** — the evidence channel behind the `Tested up to` claim, the same way U89 backs the PHP floor. A Field-Guide read is a *documentation* check; UD-10 says the published claim asserts more than that | WordPress Playground (`https://playground.wordpress.net/?wp=beta`) or a local beta/RC install | U2, U16, Radar | silent-wrong | on-WP-release | [x] 2026-08-06 (S1a) **first run** — maintainer smoke-tested the `2.0.0-beta2` build; WP `Tested up to: 7.1` and `WC tested up to: 11.0.0` both rest on it. Manual, so it re-runs per release, not per commit |
| U93 | **Core source of truth — use the GitHub mirror, not Trac.** `core.trac.wordpress.org` returns **HTTP 403** to this environment, so a dev note citing a ticket number is a dead end on its own. `gh` reaches the mirror and yields the commit messages *and* the actual file source, which is strictly better evidence than a ticket summary | `gh api search/commits -f q='repo:WordPress/wordpress-develop <ticket#>'` · `gh api repos/WordPress/wordpress-develop/contents/src/wp-includes/<file>.php` | U3–U14, U90, any Trac-cited dev note | — | on-demand | [x] 2026-08-06 (S1a) both forms work; settled U90 after Trac 403 twice |
| U83 | WooCommerce stable | `https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=woocommerce` → `.version` | U16, Radar | silent-wrong | every-run | [x] 2026-08-05 (S1) → `11.0.0` |
| U84 | WooCommerce beta/RC | `https://api.github.com/repos/woocommerce/woocommerce/releases` | Radar, U17–U30 | silent-wrong | every-run | [ ] |
| U85 | WooCommerce developer blog | `https://developer.woocommerce.com/blog/` | U17–U30 (UD-8) | silent-wrong | quarterly | [ ] |
| U86 | Gutenberg releases | `https://api.github.com/repos/WordPress/gutenberg/releases` | U76 (earliest `__next*` warning) | silent-wrong | every-run | [ ] |
| U89 | **PHP floor evidence — two sources, two questions.** *Usage:* the share still on each version (the actual cost of a floor). *Lifecycle:* whether a version is still supported at all (the argument for the floor). Feeds D7b and UB-5's "change of circumstance" | usage → `https://api.wordpress.org/stats/php/1.0/` (JSON; the `/about/stats/` page renders this via JS and is not machine-readable) · lifecycle → `https://www.php.net/supported-versions.php` | U72, D5, D7b | — | quarterly | [x] 2026-08-06 (S1a) **first measurement** — see the numbers under Sweep 1a |
| U87 | Contact Form 7 | `https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=contact-form-7` → `.version` | U31, U32 | silent-wrong | every-run | [x] 2026-08-05 (S1) → `6.1.6` |

---

## Tracked Claims (Tier 1)

The registry says *what* is coupled. These blocks say *what exactly to re-verify*, for
the couplings where "check it" is otherwise too vague to act on. Storing the claim
rather than a page snapshot is deliberate (UD-3).

### U53 — GTM tag-restriction entity ids ⭐

- **We depend on:** `TAG_IDS` (72), `TRIGGER_IDS` (9), `VARIABLE_IDS` (16),
  `GROUP_CLASS_IDS` (1) — 98 total — in `src/Modules/Blacklist/BlacklistModule.php`
  matching Google's published entity list; emitted as `gtm.blocklist` /
  `gtm.allowlist`.
- **Counting rule** (so the number is reproducible): lines matching `^\t\t'…',` inside
  each `const` block. Measured 72 / 9 / 16 / 1 at `b8f78a1` (the last commit to touch
  the lists), 2026-08-06 — it was 71 / 97 at `1daeddf`, because the D2 fix restored `mf`
  in between. Re-measure; never carry the number forward.
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
- **Detection:** `none` **against upstream** — `tests/unit/Modules/BlacklistModuleTest.php`
  pins our list to itself, which proves we emit what we intend and says nothing about
  whether the intent is current. **Canary wanted:** one live check against a real
  container (recorded as the open residual on `.security/` finding #122).
  - *Internal* consistency is pinned since 2026-08-06:
    `tests/unit/Modules/BlacklistAdminSchemaTest.php` asserts the admin choices equal
    `BlacklistModule::valid_restrictions()` and that the four choice sections partition
    them exactly once. That closes the second half of the D2 failure — an id present in
    the entity table but missing from the settings screen cannot be restricted at all,
    and nothing reported it — but it is a UC-6 same-repo check, not upstream detection.
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

**Resolved 2026-08-06 (S1a) — the flip has landed, and the answer is "keep them".**
Both defaults are now flipped in core: `__nextHasNoMarginBottom` in components **32.0.0**
(shipped in WP **7.0**) and `__next40pxDefaultSize` in **37.0.0** (shipped in WP **7.1**).
The predicted failure did **not** occur, for a reason worth recording: the components
retain an explicit discard (`__nextHasNoMarginBottom: _  // Prevent passing to internal
component`), so the props never reach the DOM and log nothing. The 7.1 dev note is
unambiguous — *"Passing `__next40pxDefaultSize` is ignored at runtime."*

- **The props must stay.** `build/admin.asset.php` externalises to core's `wp-components`,
  so the app renders against the *site's* WP version, and the plugin still declares
  `Requires at least: 6.3`. Removing them as dead code would revert controls to 36px and
  re-add bottom margins on **6.3–7.0**. They are inert only on 7.1+.
- **Retire condition:** delete them in the same change that raises the WP floor past 7.1,
  never before, and never as a "dead code" cleanup.

### U94 — `TabPanel` `initialTabName` + `onSelect`

- **We depend on:** `TabPanel` still selecting the named tab at mount when handed
  `initialTabName` (`ModulePanel` passes it the group the URL resolved to), and still
  calling `onSelect` with the tab name when the visitor switches tab (which is the only
  way the app learns where it is, and therefore what to put in the location fragment).
- **Claim:** both props are still accepted and still behave that way on the WP versions
  we declare (6.3 through the current release).
- **Failure:** `silent-wrong`, and quietly so. Lose `initialTabName` and the panel opens
  on its first tab — exactly where a link used to land before the feature existed. Lose
  `onSelect` and the URL simply stops following the visitor. Nothing errors, every
  setting is still reachable, so nobody reports either one.
- **Unverified either way:** whether the real control fires `onSelect` once on mount. The
  app is written to be correct under both, and the stand-in takes the harsher branch
  (it does fire), so the untested half is the *benign* one.
- **Would a test catch it?** **No.** `js/admin/test-support/wp-components.js` honours both
  props deliberately (UC-3 — a stand-in that swallowed them would make the suite green
  *because* the coupling is untested); undoing either fails tests. But the stand-in is our
  code: it pins *our* end of the contract, never upstream's. Only a run against a real
  `wp-components` answers this row.
- **The forward risk is `TabPanel` itself, not the prop.** Gutenberg's `Tabs` is its
  intended replacement. Watch for `TabPanel` picking up a deprecation notice (U86); the
  migration is a separate change and cannot happen while the floor is 6.3, which has no
  `Tabs`.
- **Fixed in passing:** `SearchControl` in `js/admin/components/Sidebar.js` had never opted
  into 40px, so it rendered 36px next to 40px siblings on 6.7–7.0. 7.1 hides this by making
  40px the default; the prop was added so the older supported range matches too.
- **UC-3 residual, unchanged:** the `wp-components` stand-in still absorbs both props, so
  the suite remains green either way. The claim above was settled by reading upstream, not
  by a test, and no test can settle the next one.

### U90 — `current_theme_supports( 'html5' )` gating the script `type` attribute

- **We depend on:** `current_theme_supports( 'html5' )`, called with **no second
  argument**, still answering "did this theme register html5 support at all". When it is
  false the hand-built opening tag carries `type="text/javascript"`.
- **How it was found:** the WP 7.0 Field Guide line *"Script Loader: HTML5 script theme
  support deprecated and removed"* (Trac #64442). Found only because the 7.0 guide was
  finally swept (U82) — Sweep 1 had never reached it, and no registry row pointed here.
- **Why it did not break anything:** the 7.0 change is scoped to **core's** script loader.
  GTM4WP does not use `wp_get_script_tag()`; it composes the tag itself, so core's own
  emission rule is not in the path. `current_theme_supports()` is untouched, and with no
  second argument it short-circuits before the `html5` case and returns true whenever the
  feature is registered.
- **Claim — SETTLED 2026-08-06, verified against core trunk source (not the ticket).**
  `add_theme_support( 'html5', array( 'script' ) )` still registers the feature: the
  `case 'html5'` in `add_theme_support()` accepts **any** array of types, merges repeat
  calls, and filters nothing — `'script'` and `'style'` were *documented* as deprecated
  (a `@since` annotation, r61791) but are still accepted and stored. And
  `current_theme_supports( 'html5' )` short-circuits on "no args passed" before reaching
  its `case`, so it returns true for any theme with html5 support. **Our output is
  therefore unchanged on 7.0 and 7.1, including for a theme declaring only `'script'`** —
  the one path that could have flipped it.
- **What core actually did (r61415, r61411):** removed CDATA wrappers, boolean-attribute
  expansion, and the default `type` attribute, so core now **always omits**
  `type="text/javascript"`. Note the direction: the consequence is that *our* conditional
  is now dead weight, not that our tags changed. On a theme without html5 support the
  plugin still emits an attribute core no longer emits anywhere.
- **Failure:** `loud` by the rubric but **cosmetic in effect** — an obsolete attribute
  browsers ignore. Low severity; recorded because it was an unregistered coupling, not
  because it is dangerous.
- **Open cleanup (maintainer's call, not a compat fix):** the `$has_html5_support`
  conditional could be dropped entirely so the tag always omits `type`, matching core
  7.0+. It changes output only on old-WP + non-html5-theme sites, and only by removing an
  obsolete attribute. Deliberately not done here — it is a behaviour change dressed as
  tidying.
- **⚠️ A web search got this backwards.** A search-engine summary asserted that after the
  removal `type="text/javascript"` *"will now always be included"*. The source says the
  opposite. The claim above was settled by reading `theme.php` and the commit messages.
  Instance of UD-11 in a new costume: a plausible secondary summary is not evidence.
- **The narrower bug underneath:** the call asks a broader question than the code means.
  `current_theme_supports( 'html5', 'script' )` is what the surrounding logic is actually
  about. Pre-existing, unrelated to 7.x, and left alone deliberately — changing it changes
  output on themes that register html5 without `'script'`. Maintainer's call, not a
  compat fix.

### U91 — `wp-theme` design tokens / `@wordpress/ui` (watch only, not adopted)

- **What upstream shipped (WP 7.1):** a `wp-theme` **stylesheet** defining every `--wpds-*`
  design token at `:root`, plus a `wp-theme` **script** exposing a `ThemeProvider`. The
  handbook states the stylesheet is auto-loaded in admin contexts, so on 7.1 the tokens are
  already present on our settings screen without us doing anything.
- **Decision 2026-08-06: do not adopt, and do not enqueue.** Three reasons, recorded so the
  next sweep does not re-litigate:
  1. **The tokens do not reach our controls.** They are the basis for `@wordpress/ui`, not
     `@wordpress/components`. The settings app is entirely the latter, so adopting `--wpds-*`
     in `style.scss` would restyle our chrome while every control inside it stayed on the old
     system — a cohesion regression, not a gain.
  2. **`@wordpress/ui` is not usable yet.** Upstream's own wording: *"still experimental …
     an early implementation subject to drastic and breaking changes."* Unlike
     `@wordpress/components` it is **not** on `window.wp`, so it must be bundled from npm —
     against a today-tiny bundle where every dep is externalised. `@wordpress/components` is
     **not** deprecated.
  3. **The 6.3 floor.** `--wpds-*` does not exist below 7.1, so every use needs a fallback —
     which is exactly what the existing `--gtm4wp-*` layer already provides, including the
     `var( --wp-admin-theme-color, #2271b1 )` bridge.
- **⚠️ Trap for whoever adopts this:** do **not** add `'wp-theme'` to the `$deps` array of the
  `wp_enqueue_style()` call in `src/Admin/SettingsPage.php`. `WP_Dependencies::all_deps()`
  drops any item whose declared dependency is not registered, so on 6.3–7.0 that one word
  silently removes the plugin's **entire** admin stylesheet — not just the tokens. Guard with
  `wp_style_is( 'wp-theme', 'registered' )` if it is ever needed at all.
- **Re-open trigger (the real one):** `@wordpress/components` beginning to consume `--wpds-*`,
  or `@wordpress/ui` dropping "experimental". Watch via U86 (Gutenberg releases). A WP-floor
  rise past 7.1 makes the migration cheap but is not by itself a reason to do it.
- **Where it would land when it does:** `js/admin/style.scss` `--gtm4wp-*` is already the
  seam — the ~10 colour tokens get re-pointed at `--wpds-*` with the current hex as fallback,
  in one file, with no component changes.

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

### U113 — the order-received visitor gates ⭐

- **Claim (a), the feature-detect — check this one FIRST, at every WooCommerce RC:**
  `WC_Shortcode_Checkout::guest_should_verify_email()` still exists under that name
  (`private static` is fine — `method_exists()` sees it, probed). It is what tells the
  plugin "this WooCommerce has visitor gates at all": absent means 5.0-7.8.x behaviour
  (order rendered to any valid key holder) and the plugin publishes the identity
  blocks. **If upstream renames or moves it while the `Users` helper is also
  unavailable, the plugin publishes where upstream hides — silent and in the leak
  direction.** This is the row's sharpest edge.
- **Claim (b):** `order_received()` still requires login for a non-guest order viewed
  by anyone but its customer (behaviour since **7.9.0**), still reads
  `woocommerce_order_received_verify_known_shoppers` (filter since **8.4.0**, default
  `true`, **1 arg**), and with the filter off still falls through to the guest email
  verification (whose only customer-id term is the logged-in-owner short-circuit).
- **Claim (c):** `Users::should_user_verify_order_email()` still exists, `public
  static`, still takes the **order id** (not an order object) first, and still applies
  `woocommerce_order_email_verification_grace_period` (elapsed `<=` grace renders) and
  `woocommerce_order_email_verification_required` (final say, past grace only) with
  **3 args** each. **Verify the METHOD, not the class** — the class predates it
  (≥7.7.0 vs 8.6.0), so `class_exists` alone reports the wrong half.
- **Claim (d):** both gates still run **after** the order-key check and **before**
  `woocommerce_thankyou`, so nothing on our side can observe their outcome — this is
  the whole reason the decision is re-derived rather than read off a hook.
- **How to verify:** read the two source files at the release **tag** (they are
  short); existence-on-trunk cannot see a version window (`.security` #173/#183 both
  turned on exactly that). A green suite proves nothing here — our tests drive
  stand-in classes, so upstream moving is invisible to them (UC-3).

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

### U104 / U105 — Spotify titles come from outside the player API

- **Claim:** the Spotify embed still publishes its title in the iframe `title`
  attribute prefixed with `Spotify Embed: ` (U104), and `open.spotify.com/oembed`
  still answers CORS-open JSON carrying a `title` (U105).
- **Why both:** the Embed IFrame API has no title field of any kind, so unlike every
  other player in the family there is no in-SDK source to fall back to. If both of
  these go, the only honest thing left to report is the `spotify:<type>:<id>` URI —
  which is exactly what the feature replaced.
- **Check without a stored literal:** the prefix is the *difference* between the
  oEmbed response's `html` `title=` attribute and its own top-level `title` field.
  Diff the two rather than comparing against a copy of the string, so the check
  cannot go stale in the same way the code can (UD-2).
- **Do not infer the CORS claim from a browser working.** `access-control-allow-origin`
  is the one part a server-side probe answers only if it is asked for explicitly, and
  a tightening produces no error we can see — the tracker just keeps reporting URIs
  (UD-11).

### U112 — gtm4wp.com documentation links

- **Claim:** every path a schema points at answers **200 with no redirect**, and the
  page defines `name=`/`id=` equal to the option key of each option pointing at it.
- **Measured, not read (UD-13):** the counts in the row are re-derived by the probe,
  which walks the built schemas. Do not update them by hand.
- **A 3xx is a `drifted` outcome.** The page still opens, which is exactly why this
  needs saying: the redirect is the site being kind, not the link being right, and
  the next site reorganisation is what turns it into a 404.
- **The two halves have different owners.** A failing *path* is fixed in the plugin
  (or by restoring the page); a failing *anchor* is fixed on the page. Neither is
  fixed by relaxing the probe.
- **Do not move the probe into CI.** It would make every build depend on a web server
  the repo does not control, and a `fetch-failed` there would read as a code failure.
- **The anchor is added, never substituted.** The option-key anchor goes alongside
  whatever the heading already carries — its `id` and any editorial `<a name>` stay put,
  because those are what the site's own cross-links point at. A heading therefore ends up
  as `<h2 id="visitorip"><a name="include-visitor-ip"></a><a name="visitorIP"></a>…`, and
  several option keys can share one heading where several options describe one section.

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
| Release Radar refresh (U80–U87) | 2026-08-06 (S1a) | ⚠️ WP-only refresh: stable **7.0.2**, pre-release **7.1-RC1**, GA **2026-08-19**. WP claim bumped 6.9.4 → **7.1**, closing D4a. WC and CF7 not re-probed this run — D4b still open. (2026-08-05 (S1): WP stable 7.0.2 / beta 7.1-beta4; WC 11.0.0; CF7 6.1.6 — both "tested up to" claims one major behind → D4; Gutenberg + WC pre-release not reached.) |
| Package drift (`composer outdated --direct`, `npm outdated`) | 2026-08-05 (S1) | ⚠️ phpunit 11.5.56→13.2.6, php_codesniffer 3.13.5→4.0.1, @wordpress/scripts 30.27.0→**34.0.0**, react/react-dom 18.3.1→19.2.8, typescript 5.3.3→7.0.2 → D7. `npm audit` not run. |
| Coupling-site existence (every registry row's path + anchor still resolves) | 2026-08-05 (S1) | ✅ Clean: all 40 distinct registry paths resolve. |
| **Behavioral contracts (A: U3–U14, B: U15–U30) against vendor source** | 2026-08-06 (S1a) | ⚠️ First real run. **24 rows `[ ]` → `[x]`** against WP 7.1-RC1 + WC 11.0.0 extracted locally. 3 findings, all "dead compensation that still reads as load-bearing": D10 (`safe_style_css` gained `display` in 7.0, `visibility` in 7.1 — our filter still needed at the floor), D11 (`woocommerce_related_products_args` never existed in our range), D12 (`wc-` vs `wp-` cross-sell class). U5 inconclusive, U9 is not a core coupling. **Lesson: these were never fetch-bound — download the vendor source and most of section A/B is a local grep.** |
| New-coupling detection (diff-scoped hunt for unregistered upstream strings) | [ ] | Not run — no base sha before this sweep (Run 0 was the seed). Starts at Sweep 2 from `1daeddf`. |
| Watch-channel health (each endpoint resolves and parses) | 2026-08-06 (S1a) | ⚠️ **U82 fully reached** — 7.0 + 7.1 Field Guides and all 16 individual 7.1 dev notes swept; one finding (U90, since verified). `core.trac.wordpress.org` returned **HTTP 403** on both attempts → new channel **U93** records the working substitute (`gh` against the `wordpress-develop` mirror), which settled U90 from source. New channel **U92** (runtime smoke test) registered, never run. U84/U85/U86 still not reached. (2026-08-05 (S1): U80/U81/U83/U87 resolved and parsed; U82 resolved but no 7.1 Field Guide yet and the 7.0 one (2026-05-14) never swept; U84/U85/U86 not reached.) |
| Internal duplicate-contract agreement (U52, U71) | [ ] | Not run this sweep. |
| **Deprecation audit (UD-16 / support-claim rule 1)** — deprecated *and* removed, PHP functions + arguments + hooks + JS component props, against the version we declare | 2026-08-06 (S1a) | ✅ **Clean: 0 hits across all four surfaces**, against WP **7.1-RC1** source. 465 deprecated core function names vs 555 plugin call names → 0. 37 deprecated core hooks vs 60 registered → 0. 30 `@wordpress/components` deprecation subjects; 3 touch components we use, we pass none. 5 deprecated-argument candidates, all verified false. Method + its two extraction bugs recorded under Sweep 1a — **re-read before re-running**. |
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
| D7a | — | shipped | **blocked upstream — waiting** | `squizlabs/php_codesniffer` 3.13.5 → 4.0.1 is **not a choice**: `wp-coding-standards/wpcs 3.4.1` requires `^3.13.5` and `phpcompatibility/php-compatibility 9.3.5` requires `^2.3 \|\| ^3.0.2`. Both cap below 4. **Maintainer 2026-08-05: wait for full-chain compatibility.** **Trigger to re-open:** WPCS *and* PHPCompatibility both publishing PHPCS-4-compatible releases — not one of them. Re-check via `composer prohibits squizlabs/php_codesniffer 4.0.1`; a sweep reporting "phpcs is a major behind" without checking the chain is reporting noise. **2026-08-09:** CVE-2026-67434 (high, command injection) hit `<3.13.6` *and* `>=4.0.0,<4.0.2`, so it was no argument for jumping the chain — fixed within the 3.x line by moving to 3.13.6, which WPCS's `^3.13.5` accepts. The floor in `composer.json` was raised from `^3.7` to `^3.13.6` at the same time: the lockfile alone would have left a fresh resolve free to pick a vulnerable 3.7-3.13.5. Still waiting on 4.x. | `composer.json` |
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
- **D4a** — **fixed 2026-08-06 (S1a).** See the Sweep 1a entry below.
- **D4b** — **closed 2026-08-06 (S1a).** WC 11.0.0 verified from source across U17–U30
  (D11, D12 found and fixed), then **smoke-tested by the maintainer**, which is the U92
  criterion the WP side is still missing. `WC tested up to:` bumped 10.6.1 → **11.0.0**,
  shipped in `2.0.0-beta2`. U16 stays `[~]` rather than `[x]`: the claim now rests on a
  source check plus one manual run, not on automated runtime coverage.
- **D7a** — blocked upstream; trigger recorded.
- **D7b** — **re-deferred 2026-08-06 (S1a) with a dated trigger**, replacing the open-ended
  one. U89 now measured; see Sweep 1a.
- **D7c** — deferred to a dedicated `@wordpress/scripts` session; exit criteria recorded.
- **D7d** — open, low.
- **U54** — retracted false positive; sentinel probe now mandatory.
- **U82** — **closed 2026-08-06 (S1a).** Both Field Guides swept; produced U90.

### Sweep 1a (delegated follow-up) — 2026-08-06

**Reviewed at:** `b58d427` (+ uncommitted work from a parallel session) · **Base:** Sweep 1
· **Scope:** D4a only — the WordPress 7.1 compatibility question, not a full sweep.

This is the session D4a was delegated to. It held until the 7.1 Field Guide published
(2026-08-05, one day after RC1) rather than sweeping the beta piecemeal, which is the
behaviour the `Watch` column is meant to buy.

**What was actually read:** the WP **7.0** Field Guide (never swept — the older half of
U82's debt), the WP **7.1** Field Guide, and all 16 individual 7.1 dev notes. Each was
checked against a locally-derived inventory of what the plugin actually touches: its core
function surface, its hook registrations, its admin enqueue gating, and its
`@wordpress/*` imports.

**Result: no 7.1 change reaches this plugin.** The negative is worth recording as
specifically as the positives, because "we looked and found nothing" and "we did not look"
render identically next sweep. The four notes that *sounded* applicable and are not:
enforced iframed editor (we register and extend no blocks), client-side media processing's
`Document-Isolation-Policy` header (scoped to `post.php`/`post-new.php`/`site-editor.php`/
`widgets.php`; our enqueue bails unless the hook is `settings_page_<slug>`), jQuery UI
1.14.2 (jQuery **core** untouched; we use no jQuery UI), and JSON Schema client
preparation (client-facing schemas only — *"does not change the server-side behaviour of
`rest_validate_value_from_schema()`"*). The Field Guide also has **no** security,
performance, script-loading or requirements section, which is the useful negative: the
areas this plugin lives in did not move.

| # | Sev | Stage | Status | Summary | File(s) |
|---|-----|-------|--------|---------|---------|
| D4a | Medium | rc | **fixed** | `Tested up to:` bumped **6.9.4 → 7.1** after sweeping both Field Guides. Also fixed the sibling site the original finding did not name — `.claude/CLAUDE.md` carried its own `tested up to 6.9.4` copy, so the claim lived in **2** places, not 1 (UC-1 again). **Residual, deliberately left open:** the bump rests on a documentation review; nothing has *run* on 7.1. Registered as **U92** rather than waved through. | `readme.txt`, `.claude/CLAUDE.md` |
| D8 | Low | shipped | **verified — no action** | **Unregistered coupling found by sweeping the 7.0 guide:** `current_theme_supports( 'html5' )` gates the `type="text/javascript"` attribute on the hand-built script tag, and 7.0 removed html5 script theme support from the script loader (Trac #64442). **Settled from core trunk source** after Trac 403'd twice: `add_theme_support( 'html5', array( 'script' ) )` still registers the feature, and the no-arg `current_theme_supports()` short-circuits before its `case` — so our output is unchanged, including on the one path that could have flipped it. Registered as **U90**. Note the gap it exposes: this coupling predates the registry and would still be invisible if the 7.0 guide had stayed unswept. | `src/Frontend/ScriptTag.php`, `compat/functions.php` |
| D9 | — | — | **process** | **Trac is unreachable from this environment (HTTP 403, both attempts).** Dev notes cite ticket numbers as their authority, so without a substitute every Trac-cited claim degrades to `fetch-failed`. Registered the working path as **U93**: `gh` against the `WordPress/wordpress-develop` mirror returns both the commit messages and the file source — better evidence than a ticket summary, since it settles behaviour by reading the code. Used here to convert D8 from unsettled to verified. | `.upstream/upstream-review-checklist.md` |

**Not findings — decisions recorded so they are not re-litigated:**

- **U76 resolved.** The `__next*` flip landed (7.0 and 7.1) and did **not** break anything;
  the props must now be *kept* for the 6.3–7.0 range rather than cleaned up. The retire
  condition is written into the claim block. `SearchControl` gained the 40px opt-in it had
  always been missing.
- **U91 opened as watch-only.** `wp-theme` / `--wpds-*` / `@wordpress/ui` — deliberately not
  adopted, with the reasoning and the `wp_enqueue_style` dependency trap recorded in the
  claim block. Re-open trigger is `@wordpress/components` consuming the tokens, not a
  version number.

### Behavioral-contract verification — 2026-08-06, first real run (U3–U14, U15–U30)

The half of the registry that had never been touched. Sweep 1 reported
`behavioral: 6/60` because these were judged "fetch-bound and judgment-heavy" — but with
vendor source downloaded and grepped locally they are **mostly deterministic**, and 24 rows
moved from `[ ]` to `[x]` in one pass. **The method, not the budget, was the blocker.**
Sources: WP **7.1-RC1** and WooCommerce **11.0.0**, both extracted locally.

**WordPress core (U3–U14):** U3 (`strategy`/defer), U4 (`wp_add_inline_script`, `'done'`
status at `class-wp-dependencies.php:493`), U6 (`wp_kses_normalize_entities` still does
`str_replace( '&', '&amp;' )` first — the exact behavior `restore_script_ampersands()`
compensates for), U8, U10, U11, U13, U14 all verified. **U8 is the satisfying one:** core
registers `add_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' )` with *no*
priority → 10, so our 11 still runs after it, which is the whole point of that row.
U7 drifted (**D10**). U5's probe was inconclusive and is recorded as such, not as a pass.
U9 turns out not to be a core coupling at all — the `feature=oembed` literal is YouTube's
oEmbed HTML; core has no such string. The row's `Source` should say so.

**WooCommerce (U15–U30) — this closes D4b's check.** U17, U19, U20, U21, U22, U23, U25,
U26, U27, U28, U29 verified against WC 11.0.0. Highlights: **U20's unguarded internal
survived** — `…\Reports\Orders\Stats\DataStore::is_returning_customer( $order, $customer_id
= null )` is still there, still compatible with our one-argument call (it remains
unguarded, which is a standing risk, not new drift); Store API `ProductSchema::IDENTIFIER`
is still `'product'` and `CartItemSchema::IDENTIFIER` still `'cart-item'`; all six jQuery
events and all five Product Collection slugs present. Two findings: **D11**, **D12**.

| # | Sev | Stage | Status | Summary | File(s) |
|---|-----|-------|--------|---------|---------|
| D10 | Low | shipped | **fixed** | **U7's claim is false, and version-dependent.** The code compensates for `safecss_filter_attr()` stripping `display`+`visibility`, but core added `display` to the `safe_style_css` default list in **7.0** and `visibility` in **7.1**. Measured inside the default array only: 6.3/6.7/6.9 → neither; 7.0 → `display`; 7.1 → both. **The filter must stay** — it is still load-bearing across 6.3–7.0, the same shape as U76. What is now wrong is the *comment*, which states core's behavior unconditionally; it needs the version qualifier, or the next maintainer reads it as universally true and deletes the filter. | `src/Frontend/ContainerCode.php` |
| D11 | Low | shipped | **fixed** | **`woocommerce_related_products_args` has never existed in our supported range.** Registered in `register_frontend_hooks()`, but absent from WC **5.0.0, 8.0.0, 10.6.1 and 11.0.0** — so this is *not* 11.0 drift, it is a dead registration that predates the floor. The "Related Products" list type is set only by the sibling `woocommerce_related_products_columns`, which does exist and fires before the loop renders, so **the feature works**. The modern equivalent is `woocommerce_output_related_products_args` — but it appears in 8.0 and *not* in 5.0.0, so adopting it would need a WC-version guard. Cheapest correct fix is to drop the dead line. | `src/Modules/WooCommerce/WooCommerceModule.php` |
| D12 | Low | shipped | **fixed** | **Cross-sell fallback selector never matches.** We select `.wp-block-woocommerce-cart-cross-sells-block a[href], .wc-block-cart-cross-sells-product a[href]`. WC 11.0 emits `wp-block-cart-cross-sells-product` — **`wp-`**, not `wc-`. The container selector is a descendant match and still catches every link inside the block, so click tracking is intact; the second selector is dead weight. Fix is a one-character class rename or deletion — but confirm against the WC floor first, since the `wc-block-*` prefix may be what older WC Blocks emitted. | `js/frontend/gtm4wp-woocommerce-blocks.js` |

**All three are the same species:** dead compensation that still reads as load-bearing.
None breaks a feature; each one lies to the next maintainer about why the code is there.
That is precisely the class UD-2 predicts and the class no test can catch, because the
sibling path keeps the suite green.

**Disposition — all three fixed 2026-08-06** (811 PHP + 405 JS tests green, `phpcs` clean):

- **D10 — fixed.** The filter stays; the *comment* now carries the version qualifier
  (`display` added to the default list in 7.0, `visibility` in 7.1, so 6.3–6.9 strip both
  and 7.0 still strips one) plus an explicit retire trigger: drop it in the change that
  raises the floor past 7.1, never as a "core allows it now" cleanup. Same retire-trigger
  discipline as U76, now written where the next reader will actually be standing.
  - *Caught while writing it:* the first draft of that comment said 6.9/7.0 instead of
    7.0/7.1. A comment fixing a wrong comment, wrongly — re-read against the measurement
    before committing, not after.
- **D11 — fixed.** The dead `woocommerce_related_products_args` registration removed, with
  a note recording *why* only `_columns` is registered so it does not get "restored" as an
  apparent omission. Docblocks in `ListTracking.php` and `ListTrackingTest.php` corrected —
  both named the non-existent hook as though it fired.
- **D12 — fixed, and the UC-3 hole it sat in is closed.** Prefix corrected to
  `wp-block-cart-cross-sells-product`. The existing click test wrapped its link in the
  *container* block, so it passed through the first selector and **could never fail** on a
  wrong per-product selector — textbook UC-3. Added a case with no container, so only the
  per-product selector can match. **Verified it fails on the old value before restoring the
  fix**, which is the only thing that makes it a regression test rather than decoration.

**What this run says about method.** Two of the three were found by diffing our beliefs
against vendor source, and the third (D12's test hole) was found by asking "would this test
have failed?" rather than "does this test pass?". Neither question is answered by a green
suite, and both are cheap once the vendor source is on disk.

**Registry correction:** `wc_quick_view_before_single_product` sits in section B
(WooCommerce) but is a **third-party** quick-view plugin hook — 0 hits anywhere in WC
11.0.0, as expected. It belongs in section C. Filed as a classification fix, not drift.

### Deprecation audit — 2026-08-06, first run, clean

The audit rule 1 of the support claim demands, run for the first time. Distinct from the
7.1 sweep above: that one asked *"did 7.1 break us"*, this asks *"are we using anything
deprecated or removed"*, and a change-detection sweep answers the first while staying
silent on the second (UD-16).

**Target:** WP **7.1-RC1** source (the version we now declare), downloaded and grepped
locally rather than inferred from dev notes.

| Surface | Core inventory | Plugin surface | Hits |
|---|---|---|---|
| PHP functions — deprecated | 465 names | 555 call names | **0** |
| PHP functions — **removed** | 4,590 defined | 135 WP-shaped calls | **0** |
| Deprecated arguments | 74 sites | 5 candidates | **0** (all false) |
| Deprecated hooks | 37 | 60 registered | **0** |
| JS `@wordpress/components` props | 30 subjects | 10 components used | **0** |

**The five deprecated-argument candidates, each dismissed on evidence:** `get_option` /
`update_option` notify only for the renamed option *keys* (`blacklist_keys`,
`comment_whitelist`) — we use neither; `get_the_author( $deprecated )` — we call it with no
arguments; `safecss_filter_attr( $css, $deprecated )` — we never call it, it appears only in
a comment; `wp_get_environment_type` — the notice fires on the *site* defining
`WP_ENVIRONMENT_TYPES`, which we do not.

**The three JS deprecations that touch components we use** — `onClose` on `SearchControl`,
`heading` on `CheckboxControl`, `isDefault` on `Button` — we pass none of them (`Button` uses
the current `variant` API throughout).

**⚠️ Two extraction bugs found mid-run. Read these before trusting a re-run:**

1. **`grep -E "[ \t]"` does not match a tab.** In POSIX ERE a bracket expression takes `\t`
   literally — the class matches space, backslash and the letter `t`. WordPress is
   tab-indented, so this silently dropped every indented function definition: the core
   inventory read 4,394 instead of 4,590, and six pluggable functions we *do* call
   (`wp_create_nonce`, `wp_verify_nonce`, `wp_hash`, `wp_get_current_user`, `get_userdata`,
   `is_user_logged_in`) surfaced as "removed from core". Use `[[:space:]]` in grep; `awk`
   handles `\t` correctly, which is why the deprecated-function pass was unaffected.
2. **`^\s*function` in the deprecated.php graveyards catches methods of deprecated classes,
   not just deprecated global functions.** That produced the run's only false finding —
   `is_search`, flagged because `wp-admin/includes/deprecated.php` defines it as a *method*
   at line 672 while the live global lives in `wp-includes/query.php:716`. Anchor at column
   zero (`^function `) for globals.

Both bugs failed in the direction of a **false positive**, which is the survivable
direction. Bug 1 could equally have hidden a real removal behind a longer list — the
discrimination check (does the probe find things we know are there?) is what caught it.

**Not covered, deliberately:** WooCommerce deprecations. That is D4b's scope and it stays
open — this audit is core-only, and reporting it as "the deprecation audit is done" without
that qualifier would be exactly the over-claim UD-15 warns about.

### Version-floor decisions — 2026-08-06, both measured before deciding

Prompted by the deprecation-policy question ("declaring a WP version means we use nothing
deprecated"), both floors were re-examined against public data rather than intuition. Both
**stay where they are**, for opposite reasons.

**WP floor stays 6.3 — maintainer decision, no in-between win exists.** Measured from
`api.wordpress.org/stats/wordpress/1.0/` on 2026-08-06 (share of all installs, cumulative
exclusion by floor):

| Floor | Excluded | Reach | Δ vs 6.3 | Unlocks |
|---|---|---|---|---|
| **6.3** (current) | 10.9% | 89.1% | — | — |
| 6.7 | 15.7% | 84.3% | +4.8 pp | nothing |
| 6.9 | 25.3% | 74.7% | +14.5 pp | nothing |
| 7.0 | 36.4% | 63.6% | +25.5 pp | drop `__nextHasNoMarginBottom` |
| 7.1 | ~100% | 0.01% | +89.1 pp | drop `__next40pxDefaultSize` |

Individual shares: 7.0 = **63.6%**, 6.9 = 11.0%, 6.8 = 6.7%, 6.7 = 3.0%, 7.1 = 0.011%
(pre-GA testers).

A 6.7 floor was investigated specifically and **falsified**. The hypothesis was that
`__nextHasNoMarginBottom` only became a *known* prop in 6.7, meaning 6.3–6.6 would currently
receive unknown props that leak to the DOM — a real defect a 6.7 floor would repair. Counting
occurrences in each version's shipped `components.js` kills it: both props are already handled
at **6.3** (`__next40pxDefaultSize` 19, `__nextHasNoMarginBottom` 89), rising to 135/77 at 6.6
and collapsing to 155/**10** at 7.0 as the flip lands. No leak, nothing to repair. *Limitation:*
this counts strings in the bundle, so it proves the props are handled somewhere, not that every
component we pass them to handled them in 6.3 — the 19 → 135 spread says coverage was still
filling in. That residual is cosmetic (a control at 36px on old WP), not correctness.

Confirmed at the same time: the plugin has **no** WP-version-gated code beyond the requirements
gate itself. The `function_exists` guards (`wp_get_environment_type`, `wp_roles`,
`wp_get_session_token`) all cover functions predating 6.3, so they are redundant *today* —
a floor-independent cleanup, not an argument for raising anything.

**PHP floor stays 8.0 for now — maintainer decision, on an explicit educative rationale.**
The position on record: a plugin's `Requires PHP` is not merely advisory — wp.org and core
*refuse to install or auto-update* below it, so it is an enforcement point that tells the user
why and points them at their host. Maintainers should use it to signal that an outdated stack
is not something to keep supporting. Recorded because it is a values decision, not a data one,
and future sweeps should stop re-deriving it from the usage numbers alone.

Measured 2026-08-06 (`api.wordpress.org/stats/php/1.0/`): **PHP < 8.0 = 23.9%** already excluded
by today's floor (7.4 alone = **17.7%**); **8.0 itself = 4.27%**; 8.1 = 12.0%, 8.2 = 25.0%,
8.3 = 24.5%, 8.4 = 8.1%, 8.5 = 2.4%.

Two anchors worth keeping in view:

- **WordPress core itself still requires PHP 7.4** — verified for both 7.0.2 and 7.1-RC1 via
  the core version-check API. The plugin's floor is already two EOL generations ahead of core,
  which is the educative argument working, not the plugin lagging.
- **EOL is a poor predictor of usage.** PHP 7.4 has been end-of-life since Nov 2022 and is
  still the second-most-installed version at 17.7%. A floor decision driven by the support
  calendar alone would be years ahead of reality.

**D7b re-deferred with a dated trigger** (replacing "wait for confirmation that dropping 8.0 is
low risk", which had no date and would never have fired). From `php.net/supported-versions.php`
on 2026-08-06 — active: 8.4, 8.5 · security-only: 8.2 (ends **31 Dec 2026**), 8.3 (ends 31 Dec
2027) · EOL: 8.0 (Nov 2023), 8.1 (Dec 2025):

- **Next check: 1 January 2027.** Not arbitrary — it is the day after 8.2 leaves security
  support, so the oldest supported branch becomes 8.3, and it follows PHP 8.6's November
  release, after which the annual usage shift has begun. The maintainer's instinct ("January")
  and PHP's own calendar landed on the same date.
- **Act early if either fires:** WordPress core raises its own PHP floor above 7.4 (the
  strongest ecosystem signal — it moves every host at once), or PHP 8.0's share falls below a
  threshold the maintainer sets. It is 4.27% today; no threshold is set yet, deliberately.
- **Re-measure both U89 endpoints at that check** — usage *and* lifecycle. They answer
  different questions and the numbers above are a snapshot, not a fact (staleness rule).

**Process note — finding splitting.** Sweep 1 filed D4 as one finding over two upstreams and
the maintainer split it into D4a/D4b on triage. That split is what let this session close
half of it cleanly while the WooCommerce half stayed open and visible. Findings that bundle
two independent upstreams should be filed split.

**Process note — a secondary summary is not a source (D9, and the second instance of this
class after U54).** The Trac 403 initially left U90 unsettled, and a web-search summary
filled the gap with a confident, *backwards* answer: that core would now *always include*
`type="text/javascript"`. Core does the opposite. Had the ledger recorded that, U90 would
have carried an inverted claim while reading `[x] verified`. Two rules earned:

1. **Prefer the source over any summary of it.** `gh` on `wordpress-develop` (U93) returns
   commit messages and the file itself. A dev note's ticket number is a pointer to evidence,
   not the evidence.
2. **A blocked probe must stay `fetch-failed` until something authoritative replaces it.**
   The three-outcome rule held here only because the 403 was recorded as a 403. The failure
   mode this system is most exposed to is not a missed check — it is a plausible answer
   arriving to fill a gap the ledger had honestly marked empty.
