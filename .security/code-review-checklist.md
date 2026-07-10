# Code Review Checklist

> ## ⛔ Disclosure rule — HARD REQUIREMENT
>
> **This is a public repository. Committed == published.** Every committed `.md` file — this checklist, `code-review-patterns.md`, and any doc under `.security/`, `.claude/`, or elsewhere — MUST NOT contain:
> - working exploit payloads or proof-of-concept strings,
> - step-by-step reproduction instructions, or
> - the full technical detail of any `open` (unfixed) finding.
>
> Committed files may contain ONLY: a one-line summary, severity, status, and file path — plus, for `fixed` issues, the general vulnerability class. **All exploit detail lives solely in the git-ignored reports.** When in doubt, write less in the committed file and keep the detail in the local report.

Persistent coverage tracker for systematic reviews of the GTM4WP WordPress plugin. Updated after each review run.

**How to use:** Before running a review, read this file. Prioritize `[ ]` (unreviewed) cells. After the review, mark reviewed cells `[x]` with the date and append new findings to the Known Findings Log.

**Status markers:**
- `[ ]` — not yet reviewed
- `[x] YYYY-MM-DD` — reviewed on date
- `[~] YYYY-MM-DD` — reviewed but stale (files changed since)
- `[-]` — not applicable to this component

**Staleness rule:** A cell becomes `[~]` if any file in the component group was modified after the review date. Check with `git log --since="YYYY-MM-DD" -- <path>`.

**Dimensions:** *Cap/Nonce* = capability + nonce/CSRF on state changes · *Input San.* = `wp_unslash` + sanitize on request input · *Output XSS* = escaping into HTML/`<script>` (the primary dimension for this plugin) · *SQL* = `$wpdb->prepare` · *Cplx* = complexity/dead code · *Perf* = performance · *Types* = type hints/return types.

---

## Coverage Matrix

| Component Group | Cap/Nonce | Input San. | Output XSS | SQL | Cplx | Perf | Types |
|---|---|---|---|---|---|---|---|
| **Plugin Bootstrap** (`duracelltomi-google-tag-manager-for-wordpress.php`, `uninstall.php`, `src/Plugin.php`, `src/Autoloader.php`) | [ ] | [ ] | [ ] | [-] | [ ] | [ ] | [ ] |
| **Options** (`src/Options/`) | [ ] | [ ] | [ ] | [-] | [ ] | [ ] | [ ] |
| **Compat Layer** (`compat/constants.php`, `compat/functions.php`) | [-] | [ ] | [ ] | [-] | [ ] | [-] | [ ] |
| **Migration** (`src/Migration.php`) | [ ] | [ ] | [-] | [-] | [ ] | [ ] | [ ] |
| **Frontend Core** (`src/Frontend/` — DataLayer, ScriptTag, ContainerCode, ConsentDefaults, VisitorIp, Frontend) | [-] | [x] 2026-07-10 | [x] 2026-07-10 | [-] | [ ] | [ ] | [ ] |
| **Module Framework** (`src/Module/`) | [-] | [-] | [-] | [-] | [ ] | [-] | [ ] |
| **PageVariables Module** (`src/Modules/PageVariables/`) | [ ] | [x] 2026-07-10 | [x] 2026-07-10 | [-] | [ ] | [ ] | [ ] |
| **Container Module** (`src/Modules/Container/`) | [ ] | [ ] | [x] 2026-07-10 | [-] | [ ] | [ ] | [ ] |
| **WooCommerce Module** (`src/Modules/WooCommerce/` — PurchaseTracking, ProductData, PageDataLayer, ListTracking, Helpers) | [ ] | [x] 2026-07-10 | [x] 2026-07-10 | [ ] | [ ] | [ ] | [ ] |
| **ConsentMode Module** (`src/Modules/ConsentMode/`) | [ ] | [ ] | [ ] | [-] | [ ] | [ ] | [ ] |
| **UserEvents Module** (`src/Modules/UserEvents/`) | [ ] | [x] 2026-07-10 | [x] 2026-07-10 | [-] | [ ] | [ ] | [ ] |
| **MediaEvents Module** (`src/Modules/MediaEvents/`) | [ ] | [ ] | [ ] | [-] | [ ] | [ ] | [ ] |
| **ContactForm7 Module** (`src/Modules/ContactForm7/`) | [ ] | [ ] | [ ] | [-] | [ ] | [ ] | [ ] |
| **Blacklist Module** (`src/Modules/Blacklist/`) | [ ] | [ ] | [ ] | [-] | [ ] | [ ] | [ ] |
| **ClientDeviceData Module** (`src/Modules/ClientDeviceData/`) | [ ] | [ ] | [ ] | [-] | [ ] | [ ] | [ ] |
| **AMP Module** (`src/Modules/Amp/`) | [ ] | [ ] | [ ] | [-] | [ ] | [ ] | [ ] |
| **Admin — Notices/AJAX** (`src/Admin/Notices.php`) | [~] 2026-07-10 | [x] 2026-07-10 | [ ] | [-] | [ ] | [ ] | [ ] |
| **Admin — Settings UI** (`src/Admin/SettingsPage.php`, `Admin.php`, `RestController.php`, `PluginRow.php`) | [ ] | [ ] | [ ] | [-] | [ ] | [ ] | [ ] |
| **Module Admin Schemas** (`src/Modules/*/AdminSchema.php`) | [ ] | [ ] | [ ] | [-] | [ ] | [-] | [ ] |
| **Frontend JS** (`js/frontend/`) | [-] | [ ] | [ ] | [-] | [ ] | [ ] | [-] |
| **Admin JS** (`js/admin/`) | [ ] | [ ] | [ ] | [-] | [ ] | [ ] | [-] |
| **Tests** (`tests/`) | [-] | [-] | [-] | [-] | [x] 2026-07-10 | [-] | [-] |

> **Admin — Notices/AJAX** Cap/Nonce is `[~]`: the dismiss handler's nonce + `$_POST` input path was verified 2026-07-10, but a full capability audit of every admin handler was not completed — re-review before marking `[x]`.

---

## Whole-Repo Sweeps

Dead code and cross-file duplication are **whole-repo** concerns — they do not map onto the per-component Coverage Matrix. Log each sweep here with the date last run and a one-line result. Run via the playbook in `.claude/commands/code-review.md` § B (grep-for-references, not eyeball). Treat a sweep older than ~4 weeks, or predating a significant feature landing, as stale.

| Sweep | Last run | Result summary |
|---|---|---|
| **Dead functions/methods** (private/public across `src/`, `compat/`, root `*.php`, `tests/`) | never | Not yet run. |
| **Dead hooks** (`add_action`/`add_filter` with no callback; `do_action`/`apply_filters` constants with no listener) | never | Not yet run. |
| **Dead option constants** (`GTM4WP_OPTION_*`/`GTM4WP_*` in `compat/constants.php`, never read) | never | Not yet run. |
| **Dead JS** (`js/**/*.js` never enqueued / no `build/` entry) | never | Not yet run. |
| **Duplication / drift** (a helper coexisting with inline copies; a module escaping the dataLayer differently from siblings) | never | Not yet run. |
| **Over-abstraction** (single-caller interfaces, forward-only wrappers, unread options) | never | Not yet run. |

---

## Known Findings Log

Each finding is logged once. Status: `open` | `fixed` | `wontfix`.

> **Reports are local-only.** The detailed report files referenced below are git-ignored (see `.security/.gitignore`) because this is a public repo and reports carry exploit PoCs / possibly-unfixed detail. This log keeps only terse summaries — never paste a working payload or the full detail of an `open` Critical/High finding here.

### Report 1: `.security/code-review-report-2026-07-10-1501.md`

Reflected/stored XSS review of every path where HTML/`<script>` output depends on URL/request/header input. All findings share one root cause (`print_script_block()` decoding HTML entities) and were fixed in the working tree the same session, with two regression tests.

| # | Sev | Status | Summary | File(s) |
|---|-----|--------|---------|---------|
| 1 | Critical | fixed | Reflected XSS — `?s=` search term (`siteSearchTerm`) breaks out of the dataLayer JS string; `get_search_query()` returns `esc_attr`'d `&quot;` which `JSON_HEX_TAG` cannot catch and `print_script_block()`'s decode resurrects into a raw `"`. Fixed by adding `JSON_HEX_AMP\|JSON_HEX_QUOT\|JSON_HEX_APOS`. | `src/Modules/PageVariables/PageVariablesModule.php`, `src/Frontend/ContainerCode.php` |
| 2 | High | fixed | Root cause — `ScriptTag::print_script_block()` ran a blanket `htmlspecialchars_decode()` after `wp_kses`, resurrecting `&quot;`/`&lt;`/`&#039;` from any `esc_js`/`esc_attr`-escaped value into break-out characters. Reworked to restore only the ampersand (`str_replace('&amp;','&', …)`). | `src/Frontend/ScriptTag.php` |
| 3 | High | fixed | Stored XSS — WooCommerce purchase dataLayer: `esc_js`'d order/billing fields (e.g. a billing name containing a double quote, entered at checkout) break out via the same decode. Fixed by the hex flags on the purchase `wp_json_encode` + the print_script_block root-cause fix. | `src/Modules/WooCommerce/PurchaseTracking.php`, `src/Modules/WooCommerce/ProductData.php` |
| 4 | Medium | fixed | `geoCloudflareCountryCode` from the spoofable `HTTP_CF_IPCOUNTRY` header follows the same `esc_js` → decode break-out class. Covered by the two fixes above. | `src/Modules/PageVariables/PageVariablesModule.php` |
| 5 | Low | fixed | `siteSearchFrom` from `HTTP_REFERER` — lower risk (`esc_url_raw` strips `"`) but same class; covered by the hex-flag + amp-only-restore fixes. | `src/Modules/PageVariables/PageVariablesModule.php` |
| 6 | Low | fixed | `esc_js`'d values embedded directly in hardcoded `<script>` strings (data layer name, disabled user role) were also resurrected by the old decode. Now inert after the amp-only restore (RI-3). | `src/Frontend/ContainerCode.php` |

**Defense-in-depth flags** also added to `src/Frontend/DataLayer.php` (additional pushes) for uniformity, though that path (`wp_add_inline_script`) was not exploitable.

**Regression tests:** `tests/unit/Frontend/ScriptTagTest::test_print_script_block_does_not_decode_quote_and_tag_entities`, `tests/unit/Frontend/ContainerCodeTest::test_header_begin_does_not_decode_html_entities_in_datalayer_values`. Full suite: 140 tests green; `vendor/bin/phpcs` clean (bar the pre-existing `$echo` warnings, FP-3).
