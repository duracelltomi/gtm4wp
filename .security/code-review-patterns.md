# Code Review — Learned Patterns

Accumulated patterns from past reviews of the GTM4WP WordPress plugin. The code review command reads this file before each review and appends new generalizable patterns after. It is also pre-loaded before writing any code — see `.security/pre-flight-check.md`.

> ⛔ **Disclosure rule (hard):** this is a public repo — committed == published. Patterns describe general *classes* of issue, never a specific finding's exploit payload, repro steps, or unfixed-vuln detail. Keep all such detail in the git-ignored `code-review-report-*.md`. Full rule at the top of `.security/code-review-checklist.md`.

**Categories:**
- **Recurring Issues (RI)** — patterns that keep appearing in new code
- **Project-Specific Anti-Patterns (PA)** — things unique to this codebase a generic review wouldn't catch
- **False Positive Suppressions (FP)** — things that look like issues but are intentional

---

## Quick Index

Scan this first. Each row is `ID — one-line litmus`. Jump to the full entry only when a candidate matches. The ⭐ tier is the **highest-recurrence / highest-impact** set — check these on every review before anything else.

**⭐ Highest impact — check first:**
- **RI-2** — every value entering the dataLayer / an inline `<script>` is `wp_json_encode`'d with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS`; a raw `<`/`>`/`"`/`&`/`'` in script context is a break-out.
- **RI-3** — never run a blanket `htmlspecialchars_decode()` over script output; the browser does not HTML-decode inside `<script>`, so decoding only re-enables `&quot;`/`&lt;` break-outs. `print_script_block()` restores the ampersand only.
- **RI-4** — a value that reaches a script sink already HTML-entity-encoded (`esc_attr`, `esc_js`, `get_search_query()`) is a trap: a downstream decode resurrects the entity into a raw quote/angle-bracket.
- **PA-1** — every admin form / `wp_ajax_*` / REST mutation verifies BOTH a nonce and a capability (`current_user_can`).
- **PA-3** — any new dataLayer field sourced from the URL/request/headers inherits the reflected-XSS class; it must go through the hex-flag JSON path.

**Recurring Issues (RI):**
- **RI-1** — every PHP file starts with `defined( 'ABSPATH' ) || exit;` (except the main plugin file).
- **RI-5** — every user-facing string uses `__()`/`esc_html__()` with text domain `duracelltomi-google-tag-manager`.
- **RI-6** — every `$_GET`/`$_POST`/`$_REQUEST`/`$_COOKIE`/`$_SERVER` read is `wp_unslash()`'d and sanitized/validated before use.
- **RI-7** — `$wpdb` queries with input use `$wpdb->prepare()`; no string-interpolated SQL.
- **RI-8** — WooCommerce order/customer data via WC CRUD API, never `get_post_meta()` on orders (HPOS).
- **RI-9** — a change to any `js/**/*.js` source is rebuilt into `build/` (`npm run build`) in the same commit.
- **RI-10** — every variable in a frontend JS file is declared with `const`/`let`; the files are ES modules bundled `"use strict"`, so a bare undeclared assignment (`player = …`) throws a `ReferenceError` at runtime.

**Project-Specific Anti-Patterns (PA):**
- **PA-2** — container ID/domain/path re-validated at the output sink (`GTM_ID_PATTERN`, `filter_var(FILTER_VALIDATE_DOMAIN)`, path allow-list), not trusted just because stored.
- **PA-4** — `esc_js()` is for HTML-attribute JS, not raw `<script>` bodies; use `wp_json_encode` (hex flags) for inline-script values.
- **PA-5** — options read via `Options::get()` with `GTM4WP_OPTION_*` constants and sanitized on save in the module's admin schema; the stored value is not assumed safe at output.
- **PA-6** — a new module registers through the `src/Module/` framework (AbstractModule + Registry + AdminSchema), not ad-hoc `add_action` scattered in the bootstrap.
- **PA-7** — a data-bearing string used as the *replacement* arg of `preg_replace`/`str_replace` (product-list attribute injection) is mangled by `$n`/`\1` sequences; use `preg_replace_callback` or `addcslashes(…, '\\$')`.
- **PA-8** — `wc_enqueue_js()` is deprecated (WC 10.4); do not reintroduce it — emit inline JS via `wp_add_inline_script()`. It was also a raw-`<script>` sink, so JSON in any inline-script body still needs the RI-2 hex flags.
- **PA-9** — a new embedded-media tracker follows the tracker template: a raw `window` `message` listener MUST validate `event.origin` against a host allow-list before trusting the payload (VideoPress is the reference; SDK callbacks are exempt); push only to `window[gtm4wp_datalayer_name]` (never an HTML/JS sink); parse only the embed iframe's own `src` (never `location.search`/`hash`/`referrer`/`cookie`); guard `if ( ! duration ) return;` before any percentage division; and guard against double-init.

**False Positive Suppressions (FP) — do NOT flag:**
- **FP-1** — `echo` in `ScriptTag::print_script_block()` with `phpcs:ignore WordPress.Security.EscapeOutput` — the string is `wp_kses`-sanitized and only the ampersand is restored; intentional and reviewed.
- **FP-2** — additional data layer pushes via `DataLayer::flush_pushes()` use `wp_add_inline_script` (no `htmlspecialchars_decode`), so they are safe without the ampersand hex flag; still hardened for consistency.
- **FP-3** — the `$echo` reserved-word-parameter warnings in `ContainerCode.php` are 1.x-signature-compat and cosmetic; low priority, not a security issue.
- **FP-4** — the AMP module (`AmpModule::add_amp_analytics_entries`) hands the **raw** compiled data layer to amp-wp's `amp_analytics_entries` filter as `config_data['vars']`; amp-wp serializes it to the `<script type="application/json">` itself (a *delegated* sink). The missing `JSON_HEX_*` flags here are intentional — pre-escaping would double-encode, and amp-wp re-parses/validates the whole document. Do NOT flag the raw passthrough or the absent hex flags.

---

## Recurring Issues

### RI-1: Missing `defined( 'ABSPATH' ) || exit;` guard
Every PHP file except the main plugin entry (`duracelltomi-google-tag-manager-for-wordpress.php`) must start with the ABSPATH guard to prevent direct execution. Check every new PHP file under `src/`, `compat/`, and root.

### RI-2: Data-layer / inline-`<script>` values must be JSON-encoded with the full hex flag set ⭐
Any PHP value serialized into the dataLayer or an inline `<script>` must use `wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS )` (plus `JSON_UNESCAPED_UNICODE` where wanted). `JSON_HEX_TAG` alone is **not** enough — the break-out character is often `"` (or `&`, once a downstream `htmlspecialchars_decode` is involved), not `<`/`>`.

Confirmed 2026-07-10: the site search term (`siteSearchTerm` from `?s=`) broke out of the dataLayer JS string with only `JSON_HEX_TAG` set. Fixed at `src/Frontend/ContainerCode.php`, `src/Frontend/DataLayer.php`, `src/Modules/WooCommerce/PurchaseTracking.php` by adding `JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS`. `JSON_HEX_AMP` is the decisive flag when the output later passes through any entity decode. Check every new `wp_json_encode` that feeds a script context.

**Script-context matrix (Review 2, 2026-07-10) — which sink needs what:**
- **Raw `echo '<script>…'` that passes through `print_script_block()`** → needs the **full flag set** (the ampersand-restore makes `JSON_HEX_AMP` load-bearing). The three genuine dataLayer sinks already do this.
- **`wc_enqueue_js( '… = ' . wp_json_encode(...) . ';' )`** → a raw-`<script>` sink (WooCommerce prints it un-decoded in the footer): needs at least `JSON_HEX_TAG`, and it doesn't *look* like a `<script>` echo. **Now deprecated (WC 10.4) — don't reintroduce it (PA-8).** The plugin's only use (checkout products, `PageDataLayer.php`, ex-finding #7) was migrated to `wp_add_inline_script` on 2026-07-13, so it now falls under the bullet below.
- **`esc_attr( wp_json_encode(...) )` into an HTML attribute** (e.g. `data-…="…"`, hidden-input `value`) → **safe as-is**; `esc_attr` is the correct attribute-context escaper (encodes `<`/`>`/`"`/`&`/`'`). Do NOT flag these for missing JSON flags. The WooCommerce product-list markup uses this pattern correctly at 6 sites.
- **`wp_add_inline_script(...)` / `<script type="application/json">`** → WordPress/AMP print these without any `htmlspecialchars_decode`, so `JSON_HEX_TAG` alone prevents the only real break-out (`</script>`). Non-exploitable without the amp/quot flags, but add the full set anyway for uniformity — especially on extension points that accept third-party data (`GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY`). (Finding #11.)

### RI-3: Never reintroduce a blanket `htmlspecialchars_decode()` on script output ⭐
Inside a `<script>` element the browser never HTML-decodes entities — only a literal `</script` can break out. So `&quot;`, `&lt;`, `&#039;` in a script body are already inert. Running `htmlspecialchars_decode()` over the block turns them back into raw `"`, `<`, `'` and re-enables string/tag break-outs from any value escaped with `esc_js()`/`esc_attr()`.

`ScriptTag::print_script_block()` therefore does `str_replace( '&amp;', '&', wp_kses(...) )` — it restores **only** the ampersand (which `wp_kses` encodes and which valid inline JS needs for `&&` and `&l=`/`&gtm_auth=` URL params). Do not "simplify" this back to `htmlspecialchars_decode()`. Guard test: `ScriptTagTest::test_print_script_block_does_not_decode_quote_and_tag_entities`. (Fixed 2026-07-10.)

### RI-4: Pre-HTML-encoded values reaching a script sink are a trap ⭐
`get_search_query()` returns `esc_attr()`'d output; `esc_js()` emits `&quot;`/`&amp;`/`&lt;`. When such a value is then `wp_json_encode`'d (which cannot re-escape an already-`&quot;` sequence) and later decoded, the entity is resurrected into a raw quote/bracket and breaks out. Two robust fixes, applied together here: (1) hex-encode the ampersand at the JSON layer (RI-2), and (2) do not blanket-decode at the output layer (RI-3). When adding a dataLayer value, prefer passing the **raw** value and letting `wp_json_encode` do all escaping, rather than pre-escaping with `esc_attr`/`esc_js`.

**Post-hardening consequence (Review 2, 2026-07-10):** once RI-3 removed the blanket `htmlspecialchars_decode()`, the leftover `esc_js()` pre-escaping stopped being an XSS trap but started **corrupting the data**: `&`/`'`/`"`/`<` in a value now render in the dataLayer as `&amp;`/`\'`/`&quot;`/`&lt;` instead of the real character (`Marks & Spencer` → `Marks &amp; Spencer`). So "remove the redundant `esc_js`" is not just cosmetic — it fixes a live data-quality bug. Known residues to strip: `ProductData::get_raw_order_datalayer()` (~30 order/billing/shipping fields, finding #8) and `PageVariablesModule` `visitorIP`/`geoCloudflareCountryCode` (finding #12). The correct sibling pattern is `get_purchase_datalayer()` / `PageDataLayer` customer fields, which pass raw values. **Rule: a value destined for a `wp_json_encode` dataLayer sink must arrive raw — never `esc_js`/`esc_attr`-pre-escaped.**

### RI-5: Missing i18n / text domain
Every user-facing string uses `__()`, `esc_html__()`, `esc_attr__()`, etc. with the text domain `duracelltomi-google-tag-manager`. Flag hardcoded English in admin UI and notices.

### RI-6: Unsanitized superglobal reads
Every `$_GET`/`$_POST`/`$_REQUEST`/`$_COOKIE`/`$_SERVER` access must be `wp_unslash()`'d and passed through a sanitizer/validator appropriate to the type (`sanitize_text_field`, `absint`, `filter_var(..., FILTER_VALIDATE_*)`, or an allow-list) before use — even for values that will "only" be compared or logged. Flag raw reads.

### RI-7: Raw SQL without `$wpdb->prepare()`
Any `$wpdb->query`/`get_var`/`get_results` with interpolated input must use `$wpdb->prepare()`. (No direct DB access exists in the reviewed 2.0 core yet; enforce on any new query.)

### RI-8: WooCommerce order data via post-meta
Never use `get_post_meta()`/`update_post_meta()` for order data — use the WC CRUD API (`$order->get_*()`/`$order->update_meta_data()`) so the plugin stays HPOS-compatible. Applies to any new order-touching code.

### RI-9: JS source changed without rebuilding `build/`
`js/**/*.js` is compiled to `build/` by `wp-scripts`. A change to a source file without a matching regenerated `build/` artifact ships stale runtime code. After editing `js/`, run `npm run build` and `npm run lint:js`. (Note: `build/` is git-ignored in this repo — the release ZIP is produced by `tools/build-release.js` — so "commit `build/`" does not apply here; regenerate it for local verification.)

### RI-10: Undeclared variable in a frontend JS file
Every `js/frontend/**/*.js` file is an ES module (it `import`s helpers) and is bundled by `wp-scripts` into a `"use strict"` IIFE. A bare assignment to an undeclared identifier (e.g. `player = new YT.Player(...)` with no `var`/`let`/`const`) is a silent auto-global in sloppy mode but throws `ReferenceError` under strict mode — the exact context these bundles run in. Such a throw inside a `forEach`/loop aborts the remaining iterations. Confirmed 2026-07-13: `gtm4wp-youtube.js:58` (finding #19) breaks multi-video tracking. Flag any assignment whose left side is never declared. ESLint's `no-undef` catches this if the global isn't whitelisted — do not add tracker-local names to the `.eslintrc.js` `globals` list to silence it.

---

## Project-Specific Anti-Patterns

### PA-1: Admin/AJAX/REST mutation without nonce AND capability ⭐
Every admin form submit, `wp_ajax_*` handler, and REST route that changes state must verify a nonce (`check_admin_referer()` / `wp_verify_nonce()` / REST `permission_callback`) **and** a capability (`current_user_can('manage_options')` or narrower). A nonce alone (no authorization) or a capability alone (no CSRF token) is a finding. Example reviewed: `src/Admin/Notices.php` dismiss handler.

### PA-2: Trusting stored options at the output sink
Container IDs, custom domains, and custom loader paths come from options an admin set, but must still be validated at the point they are written into markup — `GTM_ID_PATTERN` for IDs, `filter_var($d, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)` for domains, a `^[a-zA-Z0-9.\-_/]+$` allow-list for paths (see `ContainerCode::container_domain()/container_path()`). Never `echo` a stored option into a `<script src>` or loader URL unvalidated.

### PA-3: New request/header-sourced dataLayer field inherits the XSS class ⭐
Any new dataLayer key whose value comes from `?s=`, `HTTP_REFERER`, `HTTP_CF_IPCOUNTRY`, a cookie, or any request input is reflected/stored XSS surface. It must flow through the hex-flag `wp_json_encode` path (RI-2) and must not rely on `esc_js`/`esc_attr` alone (RI-4). Known sinks: `siteSearchTerm`, `siteSearchFrom`, `geoCloudflareCountryCode` in `PageVariablesModule`.

### PA-4: `esc_js()` in a raw `<script>` body
`esc_js()` is designed for values inside HTML attributes (`onclick="…"`) where the HTML parser decodes entities. Inside a raw `<script>` element the browser does not decode, so `esc_js`'s `&quot;` is both wrong (displays literally) and — with any decode step — unsafe. Use `wp_json_encode` with hex flags for values embedded in inline scripts. Flag new `esc_js( … )` used to build a `<script>` body string.

### PA-5: Option handling bypasses the schema
Options are read via `Options::get()` with `GTM4WP_OPTION_*` constants and sanitized when saved through the module's `AdminSchema`. Flag direct `get_option()`/`update_option()` on GTM4WP option keys that bypasses the schema, or a new option persisted without a sanitizer.

### PA-6: Module wiring outside the framework
New features register through the `src/Module/` framework (implement the module interface, register in the `Registry`, declare an `AdminSchema`). Ad-hoc `add_action`/`add_filter` scattered in the bootstrap for a feature that should be a module is a drift signal.

### PA-7: Data-bearing string as a `preg_replace`/`str_replace` *replacement* argument
The WooCommerce product-list markup injects `data-…` attributes into template HTML by using a built (data-bearing) string as the **replacement** argument of `preg_replace`/`str_replace` (e.g. `ListTracking::add_productdata_to_wc_block`, `after_template_part`). In `preg_replace` the replacement interprets `$0`/`$1`/`${1}`/`\1`, so a product field containing a `$`+digit or `\`+digit sequence gets mangled or duplicated (not XSS — the injected value is `esc_attr`'d — but a correctness bug). When the replacement carries data, use `preg_replace_callback`, or `addcslashes( $replacement, '\\$' )` first. Finding #16.

### PA-8: `wc_enqueue_js()` is deprecated (WC 10.4) — don't reintroduce it
WooCommerce deprecated `wc_enqueue_js()` in 10.4 (removal in a future version): it always wrapped the injected JS in a jQuery `ready()` handler even when jQuery wasn't needed. New WooCommerce-facing code that must emit inline JS uses the WordPress-core path instead — register/enqueue a handle, then `wp_add_inline_script( $handle, $code, 'before'|'after' )`. The plugin's only call — the checkout `window.gtm4wp_checkout_*` globals in `PageDataLayer::add_begin_checkout()` — was migrated to `wp_add_inline_script( 'gtm4wp-woocommerce', …, 'before' )` on 2026-07-13. It was ALSO a raw-`<script>` sink, so remember: whichever inline-script API you use, JSON embedded in the body still needs the full RI-2 hex flags. Guard test: `PageDataLayerTest::test_checkout_adds_hex_encoded_products_inline_and_fires_begin_checkout` asserts `wc_enqueue_js()` is never called.

---

### PA-9: Embedded-media tracker template conventions
The `js/frontend/gtm4wp-*.js` media trackers form a family with a shared contract; a new one (or a modification) must keep to it:
- **Origin validation (security):** if the tracker attaches a raw `window.addEventListener('message', …)` listener, it MUST validate `event.origin` against a host allow-list (parse `new URL(origin).host`, allow-list exact host + known subdomain suffixes) before trusting `event.data`, and guard the `JSON.parse`. `gtm4wp-videopress.js` is the reference. Trackers driven by a vendor SDK callback (`controller.addListener`, `player.addEventListener`, `_wq` ready queue) are exempt — the SDK gates origin.
- **Sink:** push only to `window[gtm4wp_datalayer_name]` (a JS object push). No tracker builds HTML/JS — no `innerHTML`/`eval`/`document.write`/`element.src = <data>`. Escaping any value a downstream GTM tag writes to the DOM is the GTM tag's job, not the tracker's, so SDK/iframe-`src`-sourced strings are pushed raw (correct).
- **Provenance:** parse only the embed iframe's own `src` (`new URL(frame.getAttribute('src'), location.href)` — `location.href` is only the resolution base). Never read `location.search`/`hash`/`document.referrer`/`document.cookie`.
- **Consistency:** guard `if ( ! duration ) return;` before any percentage division (avoids `NaN`, matches siblings); guard against double-init (remove-before-rebind, or a `window.gtm4wp_<provider>_inited` flag) so a re-injected bundle does not double-push.

## False Positive Suppressions

### FP-1: `echo` in `ScriptTag::print_script_block()`
Carries a `phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped`. The block is `wp_kses`-sanitized and only the ampersand entity is restored (`str_replace('&amp;','&', …)`); all other entities stay inert. Intentional and reviewed — do not re-flag as unescaped output.

### FP-2: Additional data layer pushes without the hex-amp flag are still safe
`DataLayer::flush_pushes()` emits via `wp_add_inline_script`, which WordPress prints without any `htmlspecialchars_decode`, so `&quot;`-style entities remain inert there regardless of flags. The hex flags were added anyway for consistency; do not flag the wp_add_inline_script path as a break-out risk on the grounds it "relies on" the flags.

### FP-3: `$echo` reserved-word parameter warnings in `ContainerCode.php` (resolved)
Historical: `header_top($echo = true)` / `header_begin($echo = true)` used to keep the 1.x-style `$echo` parameter, which PHPCS flagged (reserved-word parameter name) — cosmetic, not a security/correctness issue. Both are now resolved: `header_begin`'s parameter was removed (it was dead — never read, hooked with 0 accepted args, no caller passes it) and `header_top`'s was renamed to `$echo_output` (still functional; its `false` branch returns the markup). No `$echo` reserved-word warning remains in `ContainerCode.php`; kept here as history.

---

## Changelog

| Date | Action |
|---|---|
| 2026-07-10 (Review 1) | Seeded the patterns file. Added RI-2/RI-3/RI-4 (dataLayer/inline-script escaping: full hex flag set, no blanket `htmlspecialchars_decode`, pre-encoded-value trap) and PA-3/PA-4 (request-sourced dataLayer fields, `esc_js` misuse) from the reflected-XSS review that fixed `?s=` search-term break-out via `print_script_block`. Added core WordPress-plugin RI/PA/FP (ABSPATH guard, i18n, superglobal sanitization, `$wpdb->prepare`, WC CRUD/HPOS, JS rebuild; nonce+capability, option-at-sink validation, module framework; print_script_block + wp_add_inline_script + `$echo` suppressions). |
| 2026-07-10 (Review 2) | Extended **RI-2** with the script-context matrix (which sink needs which flags; flagged `wc_enqueue_js` as a raw-`<script>` sink and `esc_attr(wp_json_encode)` in attributes as already-safe). Extended **RI-4** with the post-hardening data-corruption consequence (leftover `esc_js` now mangles dataLayer data, not just an XSS trap). Added **PA-7** (data-bearing string as a `preg_replace`/`str_replace` replacement arg). From the first full pass over the previously-unreviewed component groups + all six whole-repo sweeps (report `-1606`). |
| 2026-07-13 | Migrated the plugin's only `wc_enqueue_js()` call (checkout `window.gtm4wp_checkout_*` globals in `PageDataLayer`) to `wp_add_inline_script( 'gtm4wp-woocommerce', …, 'before' )` per the WC 10.4 deprecation, with the full hex-flag set on that JSON. Promoted the `wc_enqueue_js` note to **PA-8** (deprecated — don't reintroduce; use `wp_add_inline_script`) and updated the RI-2 script-context matrix to match. |
| 2026-07-14 | Modernized the AMP module against amp-wp 2.x: migrated the amp-analytics injection from the Legacy-Reader-only `amp_post_template_*` hooks to the cross-mode `amp_analytics_entries` filter and swapped `is_amp_endpoint()` for `amp_is_request()`. Added **FP-4**: the module hands the raw data layer to amp-wp (delegated `<script type="application/json">` sink), so the absent `JSON_HEX_*` flags there are intentional — do not flag. |
| 2026-07-13 (Review 3) | Reviewed the MediaEvents expansion (11 media trackers incl. 8 new, `lib/native-video-params.js`, 12 `EVENTS_*` options). Added **RI-10** (undeclared variable → strict-mode `ReferenceError` in the ES-module bundle; from finding #19, `gtm4wp-youtube.js`) and **PA-9** (embedded-media tracker template conventions: origin-validate raw `postMessage`, dataLayer-only sink, parse own iframe `src` only, `if(!duration)return` guard, double-init guard). Clarified RI-9 that `build/` is git-ignored here. Trackers confirmed free of HTML/JS injection sinks; VideoPress origin-validation is the reference for message handlers. |
