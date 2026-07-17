# Pre-Flight Check Before Writing Code

> ⛔ **Disclosure rule (hard):** this is a public repo — committed == published. Never write a working exploit payload, repro steps, or unfixed-finding detail into ANY committed file (security docs, code comments, commit messages, CLAUDE.md). Such detail belongs only in the git-ignored `.security/code-review-report-*.md`. Full rule at the top of `.security/code-review-checklist.md`.

- **BEFORE generating any new or modified PHP or JavaScript**, read `.security/code-review-patterns.md` and actively avoid every listed pattern. Treat it as a **pre-flight checklist, not a post-flight audit** — catch issues during generation, not in review.
- This applies to everything: modules, frontend/admin classes, options schemas, the compat layer, JS trackers, tests, and the main plugin file.
- **BEFORE writing WooCommerce-related code**, follow the `woocommerce-extension-developer` skill. **BEFORE writing security-sensitive output/escaping code**, follow the `wordpress-security` skill.

Pay special attention to:

- **⭐ Script/HTML output escaping (RI-2, RI-3, RI-4, PA-3, PA-4):** anything written into the dataLayer or an inline `<script>` goes through `wp_json_encode( …, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS )`. The break-out char is usually `"` or `&`, not `<`/`>`. Never add a blanket `htmlspecialchars_decode()` to script output. `esc_js()` is for HTML-attribute JS, not raw `<script>` bodies. A dataLayer value sourced from `?s=`/`get_search_query()`, `HTTP_REFERER`, `HTTP_CF_IPCOUNTRY`, or a cookie is reflected/stored XSS surface — prefer passing the **raw** value to `wp_json_encode` over pre-escaping it.
- **Nonce + capability (PA-1):** every admin form, `wp_ajax_*` handler, and REST mutation verifies BOTH a nonce and `current_user_can(...)`. Guest-facing frontend mutations: FP-5's three conditions, not a capability gate.
- **⭐ Record ownership (PA-10):** a route that loads a record takes its id from the **server-side session**, or checks ownership — never trust an id from the request. Writing a `__return_true` route means every field it returns must be request-scoped and each resolver must enforce its own identity gate *in code*.
- **⭐ Exposure (RI-11):** before adding a dataLayer field, ask whether the client needs it AND whether the lowest actor who can read the page (usually an anonymous visitor) is entitled to it. Escaping never answers that question. Watch internal ids, emails/addresses, order totals, submitted form values. Rating guide: `.security/threat-model.md`.
- **Input sanitization (RI-6):** every `$_GET`/`$_POST`/`$_REQUEST`/`$_COOKIE`/`$_SERVER` read is `wp_unslash()`'d and sanitized/validated before use.
- **SQL safety (RI-7):** `$wpdb` queries with input use `$wpdb->prepare()`.
- **WooCommerce / HPOS (RI-8):** order data via the WC CRUD API, never `get_post_meta()` on orders.
- **WordPress hygiene (RI-1, RI-5):** `defined( 'ABSPATH' ) || exit;` on every PHP file except the main plugin file; user-facing strings use `__()`/`esc_html__()` with text domain `duracelltomi-google-tag-manager`.
- **Build step (RI-9):** after editing anything under `js/`, run `npm run build` (→ `build/`) and `npm run lint:js` in the same change.
- **Options (PA-5) & module wiring (PA-6):** read/write options via `Options::get()` + `GTM4WP_OPTION_*` constants with sanitization in the module's `AdminSchema`; register features through the `src/Module/` framework.

When implementing a multi-file feature, do a self-review pass against `.security/code-review-patterns.md` before presenting the code as complete. Every security-relevant change ships a PHPUnit regression test (see the XSS guard tests in `tests/unit/Frontend/`).
