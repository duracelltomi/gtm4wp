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

**Project-Specific Anti-Patterns (PA):**
- **PA-2** — container ID/domain/path re-validated at the output sink (`GTM_ID_PATTERN`, `filter_var(FILTER_VALIDATE_DOMAIN)`, path allow-list), not trusted just because stored.
- **PA-4** — `esc_js()` is for HTML-attribute JS, not raw `<script>` bodies; use `wp_json_encode` (hex flags) for inline-script values.
- **PA-5** — options read via `Options::get()` with `GTM4WP_OPTION_*` constants and sanitized on save in the module's admin schema; the stored value is not assumed safe at output.
- **PA-6** — a new module registers through the `src/Module/` framework (AbstractModule + Registry + AdminSchema), not ad-hoc `add_action` scattered in the bootstrap.

**False Positive Suppressions (FP) — do NOT flag:**
- **FP-1** — `echo` in `ScriptTag::print_script_block()` with `phpcs:ignore WordPress.Security.EscapeOutput` — the string is `wp_kses`-sanitized and only the ampersand is restored; intentional and reviewed.
- **FP-2** — additional data layer pushes via `DataLayer::flush_pushes()` use `wp_add_inline_script` (no `htmlspecialchars_decode`), so they are safe without the ampersand hex flag; still hardened for consistency.
- **FP-3** — the `$echo` reserved-word-parameter warnings in `ContainerCode.php` are 1.x-signature-compat and cosmetic; low priority, not a security issue.

---

## Recurring Issues

### RI-1: Missing `defined( 'ABSPATH' ) || exit;` guard
Every PHP file except the main plugin entry (`duracelltomi-google-tag-manager-for-wordpress.php`) must start with the ABSPATH guard to prevent direct execution. Check every new PHP file under `src/`, `compat/`, and root.

### RI-2: Data-layer / inline-`<script>` values must be JSON-encoded with the full hex flag set ⭐
Any PHP value serialized into the dataLayer or an inline `<script>` must use `wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS )` (plus `JSON_UNESCAPED_UNICODE` where wanted). `JSON_HEX_TAG` alone is **not** enough — the break-out character is often `"` (or `&`, once a downstream `htmlspecialchars_decode` is involved), not `<`/`>`.

Confirmed 2026-07-10: the site search term (`siteSearchTerm` from `?s=`) broke out of the dataLayer JS string with only `JSON_HEX_TAG` set. Fixed at `src/Frontend/ContainerCode.php`, `src/Frontend/DataLayer.php`, `src/Modules/WooCommerce/PurchaseTracking.php` by adding `JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS`. `JSON_HEX_AMP` is the decisive flag when the output later passes through any entity decode. Check every new `wp_json_encode` that feeds a script context.

### RI-3: Never reintroduce a blanket `htmlspecialchars_decode()` on script output ⭐
Inside a `<script>` element the browser never HTML-decodes entities — only a literal `</script` can break out. So `&quot;`, `&lt;`, `&#039;` in a script body are already inert. Running `htmlspecialchars_decode()` over the block turns them back into raw `"`, `<`, `'` and re-enables string/tag break-outs from any value escaped with `esc_js()`/`esc_attr()`.

`ScriptTag::print_script_block()` therefore does `str_replace( '&amp;', '&', wp_kses(...) )` — it restores **only** the ampersand (which `wp_kses` encodes and which valid inline JS needs for `&&` and `&l=`/`&gtm_auth=` URL params). Do not "simplify" this back to `htmlspecialchars_decode()`. Guard test: `ScriptTagTest::test_print_script_block_does_not_decode_quote_and_tag_entities`. (Fixed 2026-07-10.)

### RI-4: Pre-HTML-encoded values reaching a script sink are a trap ⭐
`get_search_query()` returns `esc_attr()`'d output; `esc_js()` emits `&quot;`/`&amp;`/`&lt;`. When such a value is then `wp_json_encode`'d (which cannot re-escape an already-`&quot;` sequence) and later decoded, the entity is resurrected into a raw quote/bracket and breaks out. Two robust fixes, applied together here: (1) hex-encode the ampersand at the JSON layer (RI-2), and (2) do not blanket-decode at the output layer (RI-3). When adding a dataLayer value, prefer passing the **raw** value and letting `wp_json_encode` do all escaping, rather than pre-escaping with `esc_attr`/`esc_js`.

### RI-5: Missing i18n / text domain
Every user-facing string uses `__()`, `esc_html__()`, `esc_attr__()`, etc. with the text domain `duracelltomi-google-tag-manager`. Flag hardcoded English in admin UI and notices.

### RI-6: Unsanitized superglobal reads
Every `$_GET`/`$_POST`/`$_REQUEST`/`$_COOKIE`/`$_SERVER` access must be `wp_unslash()`'d and passed through a sanitizer/validator appropriate to the type (`sanitize_text_field`, `absint`, `filter_var(..., FILTER_VALIDATE_*)`, or an allow-list) before use — even for values that will "only" be compared or logged. Flag raw reads.

### RI-7: Raw SQL without `$wpdb->prepare()`
Any `$wpdb->query`/`get_var`/`get_results` with interpolated input must use `$wpdb->prepare()`. (No direct DB access exists in the reviewed 2.0 core yet; enforce on any new query.)

### RI-8: WooCommerce order data via post-meta
Never use `get_post_meta()`/`update_post_meta()` for order data — use the WC CRUD API (`$order->get_*()`/`$order->update_meta_data()`) so the plugin stays HPOS-compatible. Applies to any new order-touching code.

### RI-9: JS source changed without rebuilding `build/`
`js/**/*.js` is compiled to `build/` by `wp-scripts`. A change to a source file without a matching regenerated `build/` artifact ships stale runtime code. After editing `js/`, run `npm run build` and `npm run lint:js` and commit the `build/` output.

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

---

## False Positive Suppressions

### FP-1: `echo` in `ScriptTag::print_script_block()`
Carries a `phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped`. The block is `wp_kses`-sanitized and only the ampersand entity is restored (`str_replace('&amp;','&', …)`); all other entities stay inert. Intentional and reviewed — do not re-flag as unescaped output.

### FP-2: Additional data layer pushes without the hex-amp flag are still safe
`DataLayer::flush_pushes()` emits via `wp_add_inline_script`, which WordPress prints without any `htmlspecialchars_decode`, so `&quot;`-style entities remain inert there regardless of flags. The hex flags were added anyway for consistency; do not flag the wp_add_inline_script path as a break-out risk on the grounds it "relies on" the flags.

### FP-3: `$echo` reserved-word parameter warnings in `ContainerCode.php`
`header_top($echo = true)` / `header_begin($echo = true)` keep the 1.x method signature for AMP-integration compatibility. PHPCS warns about the reserved-word parameter name; it is cosmetic, not a security or correctness issue. Do not raise as a finding (may be noted as low-priority tidy-up).

---

## Changelog

| Date | Action |
|---|---|
| 2026-07-10 (Review 1) | Seeded the patterns file. Added RI-2/RI-3/RI-4 (dataLayer/inline-script escaping: full hex flag set, no blanket `htmlspecialchars_decode`, pre-encoded-value trap) and PA-3/PA-4 (request-sourced dataLayer fields, `esc_js` misuse) from the reflected-XSS review that fixed `?s=` search-term break-out via `print_script_block`. Added core WordPress-plugin RI/PA/FP (ABSPATH guard, i18n, superglobal sanitization, `$wpdb->prepare`, WC CRUD/HPOS, JS rebuild; nonce+capability, option-at-sink validation, module framework; print_script_block + wp_add_inline_script + `$echo` suppressions). |
