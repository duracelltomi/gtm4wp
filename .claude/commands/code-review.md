Perform an in-depth code review of the GTM4WP WordPress plugin. Focus on three categories:

1. **Security Issues** — vulnerabilities, unsafe patterns, OWASP Top 10, WordPress-specific escaping/sanitization/nonce/capability gaps
2. **Overcomplicated Code** — unnecessary abstractions, overly complex logic, dead code
3. **Improvement Opportunities** — performance, maintainability, consistency, WordPress/plugin best practices

This plugin injects a Google Tag Manager container and builds a JavaScript `dataLayer` from request state, so **output escaping in `<script>`/HTML contexts is the highest-value security dimension** — treat it as the default lens.

---

## Cumulative Review Checklist

**Before starting, read `.security/code-review-checklist.md`.** This file tracks what has been reviewed and what hasn't. It prevents duplicate findings and ensures cumulative coverage.

### Pre-review steps

1. **Load the checklist** — read `.security/code-review-checklist.md` to see the coverage matrix and known findings log.
2. **Load learned patterns** — read `.security/code-review-patterns.md`. Use the Recurring Issues and Project-Specific Anti-Patterns as an additional checklist during the review. Respect False Positive Suppressions — do not re-flag suppressed patterns.
3. **Check staleness** — for cells marked `[x]`, run `git log --since="YYYY-MM-DD" -- <path>` to see if files changed since the last review. Mark stale cells `[~]`.
4. **Prioritize unreviewed areas** — focus on `[ ]` cells first, then `[~]` (stale), then `[x]` (already reviewed) only if time permits.
5. **Cross-reference known findings** — do NOT re-report issues already in the Known Findings Log unless: (a) the status is `wontfix` and you disagree, or (b) the issue has regressed after being `fixed`.
6. **Check the Whole-Repo Sweeps table** — dead code and duplication are tracked there, not in the Coverage Matrix. If any sweep is `never` or stale (older than ~4 weeks, or predating a significant feature landing), run it this review using the Section B playbook.
7. **Empty-diff escalation — DO NOT STOP when there is nothing new.** If there are no new commits since the last review AND no `[ ]` (unreviewed) or `[~]` (stale) cells, this is **not** a reason to conclude "nothing to review." It is the trigger to **go deep**:
   - Run **every** Whole-Repo Sweep (dead code, duplication, over-abstraction) via the Section B playbook — an empty diff is exactly when these are worth the cost.
   - Re-audit the **oldest-reviewed or highest-risk `[x]` component groups** (sort the Coverage Matrix by date; take the ~5 oldest, plus anything that outputs into a `<script>`/HTML context, handles order/customer data, or reads request headers) against the **current** patterns catalog.
   - Re-verify the **⭐ highest-recurrence patterns** still hold across their known call sites.
   - Treat "no new code" as **the deepest review mode, not the shortest.** Only after a genuine cross-codebase pass turns up nothing may you report a clean result — and say so explicitly.

### Post-review steps

1. **Update the checklist** — after the review:
   - Append any NEW findings to the Known Findings Log (continue the numbering).
   - If a previously-reported finding was fixed, update its status to `fixed`.
   - Add any new component groups or files that didn't exist during the last review.
   - **Update the Whole-Repo Sweeps table** — for every sweep you ran, set its Last-run date and a one-line result summary.

2. **Update learned patterns** — after the review, update `.security/code-review-patterns.md`:
   - **Extract generalizable patterns** — if a finding could recur in future code (not a one-off bug), add it to the appropriate section (Recurring Issues, Project-Specific Anti-Patterns, or False Positive Suppressions) and to the Quick Index.
   - **Litmus test for patterns file vs. Known Findings Log**: ask "would this help me catch or dismiss the same kind of issue in a *different* file or in code that doesn't exist yet?" If yes → patterns file. If it only makes sense tied to a specific file/line → Known Findings Log only.
   - **Promote to recurring** — if a finding matches an issue type already fixed once before, it qualifies as a Recurring Issue.
   - **Deduplicate** — do not add patterns already covered; update the existing entry instead.
   - **Update the changelog** — append a row to the Changelog table with the date and a brief description.

### Post-fix steps

1. After fixing identified issues, run — only after all fixes are applied:
   - `vendor/bin/phpcs` (WordPress Coding Standards) and fix reported errors. Pre-existing warnings unrelated to the fix (e.g. the `$echo` reserved-word warnings in `ContainerCode.php`) may be left, but note them.
   - `vendor/bin/phpunit` — the full suite must stay green. Add a regression test for every security fix (see the XSS guard tests in `tests/unit/Frontend/` for the pattern).
   - **If any file under `js/` changed:** run `npm run build` (compiles `js/` → `build/` via `wp-scripts`) and `npm run lint:js`, and commit the regenerated `build/` output.
   - **Skip all of the above when the fix touched only documentation.** Verify with `git diff --name-only` against the pre-fix HEAD; if every changed file is `.md`, do not run phpcs/phpunit/build.

---

## Review Scope

Read every file before judging. Cover these areas in order.

### A. Security Review

- **Output Escaping / XSS (primary):** Every value echoed into HTML or an inline `<script>` must be escaped for its exact context — `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses()`, or `wp_json_encode()` with the full hex flag set (`JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS`) for data-layer/inline-JS output. Pay special attention to:
  - Anything flowing into `$GLOBALS['gtm4wp_datalayer_data']` / the dataLayer, additional pushes, and the container code.
  - Values that originate from the URL/request/headers: `?s=`/`get_search_query()`, `HTTP_REFERER`, `HTTP_CF_IPCOUNTRY`, `$_GET`/`$_POST`/`$_REQUEST`/`$_COOKIE`/`$_SERVER`.
  - `ScriptTag::print_script_block()` output (see PA/RI in the patterns file — the ampersand-restore contract and the "never reintroduce a blanket `htmlspecialchars_decode()`" rule).
  - `esc_js()` misuse: it is for HTML-attribute JS, not raw `<script>` bodies — it emits `&quot;` which a downstream decode can resurrect. Prefer `wp_json_encode` with hex flags for inline-script values.
- **Input Sanitization:** every `$_GET`/`$_POST`/`$_REQUEST`/`$_COOKIE`/`$_SERVER` read must be `wp_unslash()`'d and sanitized/validated (`sanitize_text_field()`, `absint()`, `filter_var()`, allow-list) before use. Flag raw superglobal reads.
- **Nonce / CSRF + Capability:** every admin form, AJAX handler (`wp_ajax_*`), and REST route that mutates state must verify a nonce (`check_admin_referer()`/`wp_verify_nonce()`/REST `permission_callback`) **and** a capability (`current_user_can('manage_options')` or narrower). A nonce without a capability check, or vice-versa, is a finding.
- **SQL Safety:** any `$wpdb` query built with interpolated input must use `$wpdb->prepare()`. Flag string-concatenated SQL.
- **Options / Settings handling:** option values must be sanitized on save (admin schema), and re-validated at output if security-relevant (container IDs, domains, custom loader paths). Do not trust a stored option to be safe at the output sink.
- **SSRF / outbound & injected URLs:** container domain/path and any operator-supplied URL that ends up in markup must be validated (`GTM_ID_PATTERN`, `filter_var(FILTER_VALIDATE_DOMAIN)`, path allow-list) before output.
- **Deserialization / file handling:** no `unserialize()` on untrusted data; no path traversal in `uninstall.php` or any file read/write.
- **Secrets:** no API keys/credentials committed.
- **WooCommerce data:** order/customer fields are attacker-influenceable (billing name, address at checkout) → treat as untrusted when they reach the dataLayer; use WC CRUD API, never `get_post_meta()` on orders (HPOS).

### B. Overcomplicated Code Review

Treat dead code and duplication as **whole-repo sweeps** (record them in the Whole-Repo Sweeps table), not per-component cells.

- **Unnecessary Abstractions:** classes/methods/interfaces wrapping a single trivial operation, speculative flexibility with one caller, forward-only wrappers, config/options nothing reads.
- **Dead Code:** unused functions/methods, `use` imports, hooks registered with no effect, option constants defined but never read, JS files in `js/` never enqueued, template output never rendered.
- **Overly Complex Logic:** methods over ~50 lines, deeply nested conditionals, dense ternaries a `match`/early-return would clarify.
- **Duplicate Logic:** repeated blocks that should be one helper — only when consolidation genuinely reduces complexity. A helper coexisting with un-migrated inline copies is itself a finding.
- **Inconsistent Patterns:** the same thing done differently across sibling modules (a strong drift signal — e.g. one module escaping the dataLayer correctly while a sibling uses `esc_js`).

#### Dead-code sweep playbook (the *how*)

Do not judge "unused" from a single file. For each candidate, `Grep` the whole repo for references and flag zero-hit results (excluding the definition itself):

- **Private/protected methods:** search the method name within its class file. Zero call sites → dead.
- **Public methods / prefixed functions (`gtm4wp_*`):** `Grep` the name across `src/`, `compat/`, root `*.php`, `js/`, `tests/`. Only test refs or none → likely dead (watch for dynamic dispatch, `call_user_func`, hook callbacks, and `do_action`/`apply_filters` string names as false positives — verify before flagging).
- **Hooks:** for each `add_action`/`add_filter`, confirm the callback exists and the hook fires; for custom `do_action`/`apply_filters` constants, confirm something listens.
- **Option constants:** for each `GTM4WP_OPTION_*`/`GTM4WP_*` constant in `compat/constants.php`, `Grep` its usage across `src/`. Defined but never read → dead (or a silent-default bug).
- **JS files:** for each `js/**/*.js`, confirm it is enqueued (`wp_enqueue_script`, `wp-scripts` entry, or referenced in `build/`). Zero hits → dead.
- **Imports:** flag `use` statements whose short name never appears again in the file body.

Report confirmed dead code with grep evidence ("0 references across src/, compat/, js/, tests/"). If a symbol *looks* dead but could be reached dynamically (hook name, `call_user_func`), flag it as a **question**, not a deletion.

### C. Improvement Opportunities

- **Performance:** autoloaded-option bloat, repeated `get_option()` without caching, queries inside loops, missing transients where appropriate, work done on every request that could be gated.
- **Error Handling:** missing guards, swallowed exceptions, unchecked return values from WC/WP APIs.
- **Type Safety:** the 2.0 code uses typed properties, return types, and constructor promotion — flag missing types, and PHP 7.4+/8.x features that would improve clarity.
- **WordPress Best Practices:** escape-at-output (not at assignment), use core helpers (`wp_json_encode`, `sanitize_*`, `wp_kses`), hook priorities, i18n via `__()`/`esc_html__()` with text domain `duracelltomi-google-tag-manager`, `defined( 'ABSPATH' ) || exit;` guard on every file except the main plugin file.
- **Code Organization:** modules doing too much, god methods, mixing data-building with output.

---

## Output Format

Save the full report to `.security/code-review-report-{YYYY-MM-DD}-{HHMM}.md`. **These report files are git-ignored (`.security/.gitignore`)** because this is a public repo and reports contain exploit PoCs and possibly-unfixed detail — the report is a local working artifact. The *committed* files (checklist, patterns) must keep only terse, fixed-issue summaries: never commit a working payload or the full detail of an `open` Critical/High finding. Report structure:

```markdown
# Code Review Report — {date} (Review {N})

## Executive Summary
{2-3 sentence overview of codebase health and the most critical findings}

## Scope reviewed
{commit range or component groups; how the review was scoped}

## Verification highlights (no findings)
{what was checked and confirmed clean — cite files/lines}

## Findings

### Critical (fix immediately)
| # | Category | File | Line(s) | Finding | Recommendation |
|---|----------|------|---------|---------|----------------|

### High (fix soon)
| # | Category | File | Line(s) | Finding | Recommendation |

### Medium (plan to fix)
| # | Category | File | Line(s) | Finding | Recommendation |

### Low (nice to have)
| # | Category | File | Line(s) | Finding | Recommendation |

## Statistics
- Files reviewed: X
- Total findings: X (Critical: X, High: X, Medium: X, Low: X)
- Security: X | Complexity: X | Improvement: X
- Patterns confirmed clean: {RI/PA ids}
```

---

## Rules

- ⛔ **Disclosure rule (hard):** this is a public repo — committed == published. The full report goes in the git-ignored `code-review-report-*.md`. When you update the committed files (checklist Known Findings Log, patterns), write ONLY a one-line summary + severity + status + file path (and, for `fixed` issues, the general class). Never commit a working payload, repro steps, or the detail of an `open` finding. Canonical rule at the top of `.security/code-review-checklist.md`.
- Read every file before making a judgment — do not guess from file names.
- Reference specific line numbers in findings.
- Do not flag things that are intentional project conventions — check `.claude/CLAUDE.md`, the `wordpress-security` and `woocommerce-extension-developer` skills, and the False Positive Suppressions in the patterns file first.
- The `phpcs:ignore WordPress.Security.EscapeOutput` on `ScriptTag::print_script_block()` is intentional and reviewed — do not re-flag it (see FP in patterns file).
- Prioritize real, reachable risks over theoretical ones. State the concrete input → sink path for every security finding.
- Verify exploitability where feasible (a tiny PHP repro, or a failing PHPUnit test) before rating a finding Critical/High.
- After saving the report, present a summary to the user and ask which findings they want to address.
