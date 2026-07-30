Perform an in-depth code review of the GTM4WP WordPress plugin. Focus on three categories:

1. **Security Issues** — vulnerabilities, unsafe patterns, OWASP Top 10, WordPress-specific escaping/sanitization/nonce/capability gaps
2. **Overcomplicated Code** — unnecessary abstractions, overly complex logic, dead code
3. **Improvement Opportunities** — performance, maintainability, consistency, WordPress/plugin best practices

This plugin injects a Google Tag Manager container and builds a JavaScript `dataLayer` from request state, so **output escaping in `<script>`/HTML contexts is the highest-value security dimension** — treat it as the default lens.

---

## Cumulative Review Checklist

**Before starting, read `.security/code-review-checklist.md`.** This file tracks what has been reviewed and what hasn't. It prevents duplicate findings and ensures cumulative coverage.

### Pre-review steps

1. **Inventory the surface — mechanical, and FIRST.** The Coverage Matrix can only surface a gap for a component it already has a row for: a component with **no row** is not `[ ]`, it is *invisible* to step 6's prioritization. So reconcile the matrix against the tree on disk before doing anything else:
   ```bash
   ls -d src/*/ src/Modules/*/                                    # component groups
   ls js/frontend/*.js js/admin/*.js                              # bundles
   grep -rnE "register_rest_route|permission_callback|wp_ajax_|admin_post_" src/
   ls .claude/hooks/ .claude/commands/ .claude/skills/ .githooks/ .github/workflows/  # toolchain (PA-14)
   ```
   - Any directory or bundle with no Coverage Matrix row → **add the row now**, all cells `[ ]`, before prioritizing.
   - Any route/handler absent from the **Public Surface Inventory** → **add the row now** with its actor + gate, `Reviewed [ ]`.
   - Reconcile counts in row labels ("18 trackers", "12 opts") against reality; a drifted count is a hint a row is stale.
   - **The toolchain counts as surface (PA-14).** `.claude/`, `.githooks/`, `.github/workflows/` and `tools/` are not plugin code, but the triage workflows ingest third-party text into a session holding pre-approved permissions, so D0 reaches D1 through them. They had no rows at all until 2026-07-29 — the same "absent, not `[ ]`" failure the VisitorData module had. Read `.claude/settings.local.json` **on disk** every run: it is git-ignored, so no diff will ever surface it.

   This step exists because the matrix gave no signal where it mattered most: the VisitorData module and its **public** REST route landed 2026-07-16 with no row at all, and were only backfilled by hand on 2026-07-17 — prioritization would otherwise have walked straight past a `__return_true` endpoint. Do not skip this because "the matrix looks complete." A complete-looking matrix is exactly the failure mode.
2. **Load the checklist** — read `.security/code-review-checklist.md` to see the Public Surface Inventory, coverage matrix, and known findings log.
3. **Load learned patterns and the threat model** — read `.security/code-review-patterns.md` (Recurring Issues and Project-Specific Anti-Patterns are an additional checklist during the review; respect False Positive Suppressions — do not re-flag suppressed patterns) **and `.security/threat-model.md`** (the actor ladder A0–A4, the D0/D1 development-time axis for toolchain findings, and the lowest-actor severity rule). The patterns file tells you what to look for; the threat model tells you how to rate it. Rate every security finding by the **lowest actor who can reach the sink**, and state that actor in the finding.
   - **Read a pattern's verdict as a hypothesis, not a fact.** An entry saying a class is "not XSS", "not exploitable", or "admin-only" recorded a conclusion someone reached at *one* call site, and a conclusion is exactly what stops you re-deriving it. Where an entry states a severity, check what that verdict depended on before you inherit it — PA-7 carried "not XSS" for eight reviews because its verdict was true at the site it was written from and false one file away (#74).
   - **Re-derive one False Positive Suppression per review**, oldest first, plus any FP whose blessed code appears in the diff. An FP is a standing waiver over live code, and its "do not re-flag" is the sentence that keeps it unexamined; FP-5 held a condition that read as satisfied and was not (#78). Record the re-derivation under Verification highlights.
4. **Scope the diff** — read the base commit from the last report's `Reviewed at:` sha in the Known Findings Log and review `<sha>..HEAD`. Rules:
   - **Never read a multi-megabyte diff.** Use `git diff --name-status <sha>..HEAD` and `git log --oneline <sha>..HEAD` to find *what* changed, then read the **current files on disk** — that is the code that ships.
   - **Out of scope for findings:** deleted code, vendored/third-party libraries, `tests/` and `js/**/test/`, and `*.md`. Do not report issues in code this branch removes.
   - If no sha is recorded (first run, or a report predating the convention), fall back to the last report's date, record the sha you actually reviewed, and note the fallback.
5. **Check staleness** — for cells marked `[x]`, run `git log <sha>..HEAD -- <path>` using the last review's recorded sha to see if files changed since. Mark stale cells `[~]`. (Use the sha, not `--since=<date>`: a date match is imprecise in both directions when commits and the review land on the same day.)
6. **Prioritize unreviewed areas** — focus on `[ ]` cells first, then `[~]` (stale), then `[x]` (already reviewed) only if time permits. Within `[ ]`, take the lowest-actor-reachable surfaces first: a public (A0) route outranks an admin-only (A4) screen.
7. **Cross-reference known findings** — do NOT re-report issues already in the Known Findings Log unless: (a) the status is `wontfix` and you disagree, or (b) the issue has regressed after being `fixed`.
8. **Check the Whole-Repo Sweeps table** — dead code, duplication, and new surface are tracked there, not in the Coverage Matrix. If any sweep is `never` or stale (older than ~4 weeks, or predating a significant feature landing), run it this review using the Section B playbook.
9. **Empty-diff escalation — DO NOT STOP when there is nothing new.** If there are no new commits since the last review AND no `[ ]` (unreviewed) or `[~]` (stale) cells, this is **not** a reason to conclude "nothing to review." It is the trigger to **go deep**:
   - Run **every** Whole-Repo Sweep (dead code, duplication, over-abstraction) via the Section B playbook — an empty diff is exactly when these are worth the cost.
   - Re-audit the **oldest-reviewed or highest-risk `[x]` component groups** (sort the Coverage Matrix by date; take the ~5 oldest, plus anything that outputs into a `<script>`/HTML context, handles order/customer data, or reads request headers) against the **current** patterns catalog.
   - Re-verify the **⭐ highest-recurrence patterns** still hold across their known call sites.
   - Treat "no new code" as **the deepest review mode, not the shortest.** Only after a genuine cross-codebase pass turns up nothing may you report a clean result — and say so explicitly.

### Post-review steps

1. **Update the checklist** — after the review:
   - **Record the reviewed HEAD sha** — add `Reviewed at: <sha>` to the report's entry in the Known Findings Log. The next review's step 4 reads it to scope its diff, and step 5 to check staleness. A report with no sha forces the next run onto the imprecise date fallback.
   - Append any NEW findings to the Known Findings Log (continue the numbering). State the **lowest actor** who can reach each security finding (threat model A0–A4).
   - If a previously-reported finding was fixed, update its status to `fixed`.
   - Add any new component groups or files that didn't exist during the last review (step 1 should already have done this — confirm nothing landed mid-review).
   - **Update the Public Surface Inventory** — flip the `Reviewed` cell of every route/handler you covered, and correct any gate that changed.
   - **Update the Whole-Repo Sweeps table** — for every sweep you ran, set its Last-run date and a one-line result summary.

2. **Update learned patterns** — after the review, update `.security/code-review-patterns.md`:
   - **Extract generalizable patterns** — if a finding could recur in future code (not a one-off bug), add it to the appropriate section (Recurring Issues, Project-Specific Anti-Patterns, or False Positive Suppressions) and to the Quick Index.
   - **Litmus test for patterns file vs. Known Findings Log**: ask "would this help me catch or dismiss the same kind of issue in a *different* file or in code that doesn't exist yet?" If yes → patterns file. If it only makes sense tied to a specific file/line → Known Findings Log only.
   - **Promote to recurring** — if a finding matches an issue type already fixed once before, it qualifies as a Recurring Issue.
   - **Fix the family, not the sibling — enumerate the call sites at the moment you write the pattern.** A pattern born from one fixed site is only as wide as the search that produced it. Grep for the *sink shape* (and through every helper that forwards to it, not just the module the finding was in), list every site the class touches, and record the count and the paths in the entry as a **ledger to re-derive, not a fact to read**. PA-7 recorded "two injectors" while four existed, because two reached `preg_replace` via a helper one file away (#74). This is the standing lesson of #66/#67/#71/#72 turned into a step: the sibling left behind is this codebase's most common finding shape.
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
- **Escape-then-transform (RI-17):** an escape is only valid at the instant of output. For every `esc_attr()`/`esc_html()`/`wp_json_encode()` whose result is not echoed on the same line, follow the variable to the sink and name every function it passes through — a `preg_replace` replacement expansion, a `sprintf`, or a concatenation running *after* the escaper can put the break-out character back, and the escaping cannot defend against it. A helper in the path counts; that is where #74 hid.
- **Input Sanitization:** every `$_GET`/`$_POST`/`$_REQUEST`/`$_COOKIE`/`$_SERVER` read must be `wp_unslash()`'d and sanitized/validated (`sanitize_text_field()`, `absint()`, `filter_var()`, allow-list) before use. Flag raw superglobal reads.
- **Input provenance (RI-18):** sanitization proves a value is safe to *handle*, never that it is what it *claims to be*. For each `$_SERVER['HTTP_*']` read, ask which hop the infrastructure actually guarantees (does the proxy append or replace?) and whether any caller could make an access decision on the result. Treat a docblock asserting a security property — "not spoofable", "no IDOR", "admin-only" — as a claim to test against the code, the same way PA-10 treats a `__return_true` gate.
- **Authorization strength, not just presence (PA-1/FP-5):** a nonce being *verified* is not a nonce that *binds*. For a guest-facing route, check what the token is derived from: `wp_rest` for a logged-out caller is uid 0 with an empty session token, so it is identical for every guest that tick and is published by the plugin's own public endpoint. Ask "what does this token prove about *this* caller?", not "is a nonce checked?".
- **Nonce / CSRF + Capability:** every admin form, AJAX handler (`wp_ajax_*`), and REST route that mutates state must verify a nonce (`check_admin_referer()`/`wp_verify_nonce()`/REST `permission_callback`) **and** a capability (`current_user_can('manage_options')` or narrower). A nonce without a capability check, or vice-versa, is a finding. Blessed exception: a guest-facing frontend mutation may substitute strict session-scoping (FP-5) — verify it meets all three FP-5 conditions rather than assuming.
- **IDOR / record ownership (PA-10):** for every route or handler that loads a record (order, user, session), trace where the **id** comes from. An id read from the request body/query/cookie must be ownership-checked; an id resolved from the server-side session is safe by construction. Ask the threat model's two questions: who can reach it, and whose data does it return? A public (`__return_true`) route returning anything actor-specific needs the identity gate demonstrated, not asserted.
- **SQL Safety:** any `$wpdb` query built with interpolated input must use `$wpdb->prepare()`. Flag string-concatenated SQL.
- **Options / Settings handling:** option values must be sanitized on save (admin schema), and re-validated at output if security-relevant (container IDs, domains, custom loader paths). Do not trust a stored option to be safe at the output sink.
- **SSRF / outbound & injected URLs:** container domain/path and any operator-supplied URL that ends up in markup must be validated (`GTM_ID_PATTERN`, `filter_var(FILTER_VALIDATE_DOMAIN)`, path allow-list) before output.
- **Deserialization / file handling:** no `unserialize()` on untrusted data; no path traversal in `uninstall.php` or any file read/write.
- **Secrets:** no API keys/credentials committed.
- **WooCommerce data:** order/customer fields are attacker-influenceable (billing name, address at checkout) → treat as untrusted when they reach the dataLayer; use WC CRUD API, never `get_post_meta()` on orders (HPOS).
- **Data exposure / PII (RI-11) — the second structural risk, alongside injection:** correct escaping only answers "can this value become code?", never "should this value be here at all?" The plugin's core function is copying server state into a client-readable dataLayer, so every new field is an exposure decision. For each one ask: does the client need it, and is the **lowest actor who can read the page** entitled to it? Internal ids, emails, addresses, order totals, and submitted form values are the recurring offenders (findings #30, #31; the `#398` work exists to *omit* server PII). An A3/A4-only value reaching an A0-readable dataLayer is a finding even when it is perfectly `wp_json_encode`'d.

#### New-surface sweep playbook (the *how*)

An externally-reachable entry point is the highest-value thing a review can miss, and it is the one thing a per-component matrix is structurally bad at surfacing (a brand-new module has no row → no `[ ]` → no signal). So sweep for surface directly, not via the matrix. Record the result in the **Whole-Repo Sweeps** table and reconcile every hit against the **Public Surface Inventory** in the checklist:

```bash
grep -rnE "register_rest_route|permission_callback" src/     # REST routes + their gates
grep -rn  "wp_ajax_\|admin_post_" src/                       # AJAX / admin-post handlers
grep -rn  "__return_true" src/                               # explicitly public gates
grep -rn  "add_query_arg\|template_redirect\|init'" src/     # request-time entry points
```

For each hit, answer the threat model's two questions and record them: **who can reach it** (A0–A4) and **whose data does it return**. Then:

- A route whose gate is `__return_true` is public (A0) — the identity gate must be *demonstrated in the code path*, not asserted in a doc block. Read the callback and every resolver it calls.
- A route taking an id from the request needs an ownership check (PA-10).
- A mutation needs nonce + capability (PA-1), or all three FP-5 conditions.
- A new surface with no inventory row is itself the finding to fix first — add the row before reviewing it.

#### Toolchain trust sweep playbook (PA-14, rated D0→D1)

The plugin's attack surface is the site; the *repository's* attack surface is the maintainer's machine, and the triage workflows connect them by pulling attacker-authored text into a session that holds pre-approved permissions. None of this is in `src/`, so it is invisible to every other lens here. Record the result in the **Toolchain trust** row of the Whole-Repo Sweeps table.

```bash
cat .claude/settings.json .claude/settings.local.json          # local file is git-ignored: read it on disk
grep -rn "hooksPath\|rev-parse --show-toplevel" .githooks/ .claude/
grep -rn "on:\|pull_request_target\|secrets\." .github/workflows/
grep -rln "gh issue\|gh api\|wporg\|forum" .claude/commands/ .claude/skills/
```

Pair every **entry point** (a command or skill that ingests third-party text) with every **sink** it can reach, and rate the pair:

- **Permissions.** Is each `permissions.allow` entry pinned to a verb *and* a path? A wildcard admitting state-changing verbs converts any successful injection into an unattended authenticated write. The enforced allowlist must be no wider than the write surface the skill *documents* — where prose and allowlist disagree, only the allowlist is real.
- **Hooks.** Does anything reached via `core.hooksPath` or a `.claude` Stop/SessionStart hook execute a script resolved from the **working tree**? Then a checked-out PR branch supplies that code. It must come from a fixed, maintainer-controlled location.
- **CI.** Does any workflow run with secrets on a trigger a fork can influence?
- **Prose is not a control.** Injection guards written in a command file share a context window with the attacker's text. Note them as mitigation; never close a finding on them.

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
- Reviewed at: `{sha}` — {the HEAD this review covers; copy this into the checklist's Known Findings Log entry}
- Base: `{sha}` — {the previous review's recorded sha; the range is base..HEAD}
- {component groups; how the review was scoped; anything deliberately excluded}

## New surface this review
{rows added to the Coverage Matrix / Public Surface Inventory by pre-review step 1, or "none"}

## Verification highlights (no findings)
{what was checked and confirmed clean — cite files/lines}

## Findings

Security findings additionally state the **Actor** (threat model A0–A4: the lowest
actor who can reach the sink — this is what sets severity) and a **Conf.**idence
0.0–1.0. Every security finding states its concrete input → sink path, in the
Finding cell or a prose block beneath the table.

**Nothing the review noticed is dropped or exiled to a lesser tier.** A low-confidence
or unverifiable item (a security concern below ~0.7, an assumption about an external
class's behavior, a runtime-context question, a smell not yet pinned to a concrete
failure) is still a **findings-table row at its appropriate severity** — usually Low —
marked **(open)** in its Category, carrying its real (low) `Conf.` value, with the
Finding cell stating *why it is unverified* and *what would resolve it* (a live repro,
reading an external source). The `Conf.` column carries the honesty; there is no
separate below-the-bar section. The maintainer triages — a small item they dismiss in
a second beats one they never see.

### Critical (fix immediately)
| # | Category | Actor | Conf. | File | Line(s) | Finding | Recommendation |
|---|----------|-------|-------|------|---------|---------|----------------|

### High (fix soon)
| # | Category | Actor | Conf. | File | Line(s) | Finding | Recommendation |

### Medium (plan to fix)
| # | Category | Actor | Conf. | File | Line(s) | Finding | Recommendation |

### Low (nice to have)
| # | Category | Actor | Conf. | File | Line(s) | Finding | Recommendation |

{Actor/Conf. are `—` for non-security findings: complexity, dead code, and
improvement findings are rated on their own merits and are NOT subject to the
confidence gate — a Low dead-code or correctness finding is a valid result.
Low-confidence / unverified items are **(open)**-marked rows in the tables above
(see the Findings note), not a separate section.}

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
- **Rate by actor, not by sink power** — severity comes from the *lowest actor who can reach the sink* (`.security/threat-model.md`), not from what the sink could theoretically do. An admin-only path an admin can already achieve via GTM is not a vulnerability; say why rather than dropping it silently. Note the multisite `unfiltered_html` caveat before leaning on that argument.
- **Never silently drop a finding for being small or low-confidence, and never exile one to a separate lesser tier.** Everything the review noticed is a Findings-table row at its appropriate severity (usually Low). Confidence is expressed in the `Conf.` column, not by hiding the item: a below-~0.7 or unverifiable finding is an **(open)**-marked row with its real (low) confidence and a note on what would resolve it. Complexity, dead-code, and improvement findings are not confidence-gated at all — a Low one is a valid result. The maintainer triages; a minor item they dismiss in a second beats one they never see. Report everything the review noticed.
- Verify exploitability where feasible (a tiny PHP repro, or a failing PHPUnit test) before rating a finding Critical/High. Use a **throwaway** probe (scratchpad dir), not a committed test — adding regression tests is the post-fix step.
- After saving the report, present a summary to the user and ask which findings they want to address.
