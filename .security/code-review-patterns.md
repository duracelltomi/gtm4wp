# Code Review — Learned Patterns

Accumulated patterns from past reviews of the GTM4WP WordPress plugin. The code review command reads this file before each review and appends new generalizable patterns after. It is also pre-loaded before writing any code — see `.security/pre-flight-check.md`.

> **Companion — `.security/threat-model.md`.** This file is **what to look for**; the threat model is **how to rate what you find**: the A0–A4 actor ladder and the rule that severity comes from the *lowest actor who can reach the sink*, not from the sink's power. Read both. The two structural risk classes it names map onto the entries here: **injection** (RI-2/RI-3/RI-4, PA-3/PA-4) and **exposure** (RI-11).

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
- **RI-3** — never run a blanket `htmlspecialchars_decode()` over script output; the browser does not HTML-decode inside `<script>`, so decoding only re-enables `&quot;`/`&lt;` break-outs. `print_script_block()` restores the ampersand only. **Conversely, `wp_kses()` alone BREAKS inline JS** (`&&` → `&amp;&amp;`): a bare `echo wp_kses(…)` over script content is a defect, and an identity `wp_kses` stub in a test hides it completely.
- **RI-4** — a value that reaches a script sink already HTML-entity-encoded (`esc_attr`, `esc_js`, `get_search_query()`) is a trap: a downstream decode resurrects the entity into a raw quote/angle-bracket.
- **PA-1** — every admin form / `wp_ajax_*` / REST mutation verifies BOTH a nonce and a capability (`current_user_can`).
- **PA-3** — any new dataLayer field sourced from the URL/request/headers inherits the reflected-XSS class; it must go through the hex-flag JSON path.
- **PA-7** — a data-bearing string used as the *replacement* arg of `preg_replace` expands `$0`/`$n`/`\1` into the **matched** text, *after* the value was escaped. When the match contains a quote, that is attribute break-out (XSS), not just corruption. Grep the wrappers too.
- **PA-10** — a record id must come from the server-side session, or the route must check ownership; an id taken from the request without an ownership check is IDOR. A `__return_true` route's identity gate must be demonstrated in the code path, not asserted in a doc block. A **namespace**-wide control (CORS, shared auth) must not be registered from a module gated on a feature flag, and must cover the routes the **framework** auto-registers inside that namespace, not only the ones you declared.
- **RI-17** — an escape is only valid at the instant of output; any transform that runs *after* it (`preg_replace` replacement expansion, `sprintf`, concatenation into a new context) can put break-out characters back. Ask what happens to the string after the escaper — and, read backwards, what the escaper itself returns on input it dislikes (`esc_url` → `''`, `wp_json_encode` → `false`), because a guard placed *before* it does not cover that (#120).
- **RI-24** — a release note is a disclosure. "Fixed on this branch" is not fixed: `git grep <symbol> 1.x` before writing the bullet, count every committed surface (changelog + readme mirror + any `.upstream`/`.security` registry row + the commit message), redact mirrored copies together or not at all, and remember no edit retracts a pushed commit — shipping the other line's fix is the only step that removes the exposure.
- **RI-21** — an encoder that can fail returns something PHP concatenates anyway: `false` becomes `''`, so `'var x = ' . wp_json_encode( … ) . ';'` emits `var x = ;` and the whole `<script>` block is a `SyntaxError`. Route assignment-position sinks through `ScriptTag::json_literal()`, or test `false ===` and omit the statement. Ask where each encode's result is *used*, not whether it shares a line with the concatenation (#141).

**Recurring Issues (RI):**
- **RI-1** — every PHP file starts with `defined( 'ABSPATH' ) || exit;` (the main plugin file included).
- **RI-11** — every value added to the dataLayer is an *exposure* decision, not just an escaping one: does the client need it, and is the lowest actor who can read the page entitled to it? Escaping never answers "should this be here at all?"
- **RI-5** — every user-facing string uses `__()`/`esc_html__()` with text domain `duracelltomi-google-tag-manager`.
- **RI-6** — every `$_GET`/`$_POST`/`$_REQUEST`/`$_COOKIE`/`$_SERVER` read is `wp_unslash()`'d and sanitized/validated before use.
- **RI-7** — `$wpdb` queries with input use `$wpdb->prepare()`; no string-interpolated SQL.
- **RI-8** — WooCommerce order/customer data via WC CRUD API, never `get_post_meta()` on orders (HPOS).
- **RI-9** — a change to any `js/**/*.js` source is rebuilt into `build/` (`npm run build`) in the same commit.
- **RI-10** — every variable in a frontend JS file is declared with `const`/`let`; the files are ES modules bundled `"use strict"`, so a bare undeclared assignment (`player = …`) throws a `ReferenceError` at runtime.
- **RI-12** — reading a third-party object property via `isset( $o->p ) ? … `/`??`/`empty()` can be false when the class exposes it through `__get()` without `__isset()`; go through `__get()` directly (`property_exists`/`method_exists('__get')`).
- **RI-13** *(omit-don't-invent generalizes past globals — #121: a module emitting both of its data-layer keys with one always empty is the same rule, since a consumer's GTM trigger may test for key presence)* — a WordPress conditional tag (`is_singular()`, …) does not guarantee its companion global (`$GLOBALS['post']`, `$wp_query`, `$authordata`, …) is set up; resolve once via the safe accessor (`get_post()`), null-gate the reads, and **omit** the affected dataLayer keys when null (never emit `''`/`0`/`null` placeholders). Litmus covers **every** `global $…` read, not just `$post` — and a gate alone is not compliance, the *omission* is half the rule.
- **RI-14** — a value shared by a PHP writer and a JS reader (a cookie/localStorage de-dupe key, **or an option that names a JS global**) must have **one** definition; `esc_js`-ing it on one side, keying on the id where the other keys on the number, or hardcoding the default where every sibling reads the configured name, silently breaks the contract only on non-default installs. The two ends must also agree on the **binding**: PHP prints the inline globals as top-level `const`, which is lexical and never a property of `window`, so `window.<name>` is permanently `undefined` — invisible to `no-undef`, and it disabled three shipped features.
- **RI-15** — an option's label and description are its consent contract: if the code emits more than the description names — or names neither the data-layer variable it writes nor the *form* the values take — that is a finding even when the data is escaped perfectly.
- **RI-16** — a JS falsy/`NaN` fallback (`isNaN(x) ? d : x`, `x || d`) does not fire for `null`, `''` or `0`; a DOM lookup that short-circuits to `null` therefore slips past the guard that was written to catch it.
- **RI-19** — a falsy return from a WordPress API usually conflates *"the operation failed"* with *"the collaborator was never there"*; a fallback that treats them alike does work nobody asked for. Ask what the absent case means before writing the else.
- **RI-20** — a key name the plugin writes for a third-party runtime to read is a contract with only one end in this repo: a wrong name fails **silently**, so the only check is the vendor's documentation. Open the page a docblock cites; prefer the documented name over the merely-observed one.
- **RI-22** — a `TABLE[$key] ?? DEFAULT` is a claim about **every key not in the table**, and the table is the only half that gets reviewed. Count the domain's categories: if it has three and the code models "listed" plus "everything else", the third silently inherits a rule written for the second. Test the default, not only the entries.
- **RI-23** — RI-22's sequel: when the model grows the third category, its branch arrives near the top of the function and **returns**, silently opting its inputs out of every test below. Diff the branches, not the values; count the rows that enter the new branch before calling it a special case; and remember a test named for a general property now asserts it for one category only.
- **RI-25** — ⭐ when you re-apply somebody else's filter, the argument list is theirs too. `WP_Hook` never pads: a callback with N required params reached from a caller supplying fewer raises an uncaught `ArgumentCountError`. Passing *more* is free (it slices down), so pass exactly what upstream passes. A mocked `apply_filters` with an optional-param stand-in cannot see this. Ledger: **12** third-party hook sites (re-derive with the `grep -v gtm4wp` litmus) — the count lives in TWO files, so correct both.
- **RI-18** — sanitizing a request value proves it is *safe to handle*, never that it is *what it claims to be*. For a proxy-chain header, know which end the infrastructure guarantees; a docblock asserting "not spoofable" is a claim to test, not a fact. Name the superglobal behind every value in a gate (a convenience helper may read `$_REQUEST` before the header), then pick the sanitizer for the value's **grammar** — a text sanitizer on a URL rewrites what the gate is judging.

**Project-Specific Anti-Patterns (PA):**
- **PA-2** — container ID/domain/path re-validated at the output sink (`GTM_ID_PATTERN`, `filter_var(FILTER_VALIDATE_DOMAIN)`, path allow-list), not trusted just because stored. Save side and read side call **one** shared predicate, never two copies of a rule; ask whether an operator allow-list has a value (`0.0.0.0/0`) that matches everything while the UI still claims protection; and for a value emitted **unquoted** into a `<script>`, check the allow-list against the *grammar of the sink* in both directions — escaping cannot help a bare identifier (#114).
- **PA-4** — `esc_js()` is for HTML-attribute JS, not raw `<script>` bodies; use `wp_json_encode` (hex flags) for inline-script values.
- **PA-5** — options read via `Options::get()` with `GTM4WP_OPTION_*` constants and sanitized on save in the module's admin schema; the stored value is not assumed safe at output.
- **PA-6** — a new module registers through the `src/Module/` framework (AbstractModule + Registry + AdminSchema), not ad-hoc `add_action` scattered in the bootstrap.
- **PA-14** — the repo's own toolchain is attack surface: a pre-approved tool permission, **a committed agent/subagent definition carrying its own tool patterns** (`.claude/agents/` had no Coverage Matrix row and no place in the inventory grep for the two reviews after a finding was found inside it — #135) — **and check the frontmatter KEY, not just its contents: an agent's restriction key is `tools:`, and `allowed-tools:` is the slash-command key, which an agent file accepts silently and ignores, so all three reviewer agents ran with every tool while their files declared read-only (#140)**, a hook that executes a repo-relative path, **a tool CONFIG resolved from the working tree that can name code to load** (watch every filename the tool accepts, not the one in the tree — the dotfile variant usually wins), a CI trigger, or **what the history already published** (removing it from HEAD does not retract it; no diff-scoped review will ever see it; and a finding of that shape belongs in the local report, never in a committed file), reached by third-party text an agent run ingests — **and a hook that merely READS a worktree file into the session context is an entry point too**, which the "does it execute?" question cannot select (#118). Enforced allowlist must match the write surface its skill documents.
- **PA-19** — a ledger is only as wide as the SCOPES it enumerates, and permissions are not the only thing assembled from several: agents, skills, commands and hooks merge project + user + each enabled plugin's scope too (#186 — a **relative** inventory path counted 4 agent definitions where 28 resolve, 17 of the 24 uncounted declaring `Write`/`Edit`). Fix a scope gap in one ledger and the same edit is owed to every ledger of that shape. The enforced allowlist is merged from user / project / local scope (plus per-agent frontmatter, which *restricts* rather than *grants*); this system read only the two project-scope files for 21 reviews while a user-scope dispatcher sat unread outside the repository (#162). Enumerate scopes, then files.
- **PA-16** — moving a request out of `wp_enqueue_script` into runtime JS takes it out of `script_loader_tag`, `wp_dequeue_script` and the served HTML, so every control the SITE had over it disappears silently. Give the runtime fetch a lever, check it at the request rather than at the scan, and name the handle that still gates it. **Then verify each lever against WordPress's own code**: a handle everything depends on cannot be dequeued, and an inline `before` script is inside the `script_loader_tag` string rather than beside it — the pattern's own reference implementation shipped with two of its three levers broken, both failing open (#133/#134).
- **PA-15** — never relax a host security control (a `wp_kses`/`safe_*`/allowed-list filter) globally to make your own markup survive it: scope the filter to your own sanitizer call and remove it after, or change the markup so it is not needed. A filter that only appends to the array it receives is a filter that loosens — and it loosens for every unrelated sanitizer call in the same request, including the block editor's save path (a REST request is not `is_admin()`).
- **PA-8** — `wc_enqueue_js()` is deprecated (WC 10.4); do not reintroduce it — emit inline JS via `wp_add_inline_script()`. It was also a raw-`<script>` sink, so JSON in any inline-script body still needs the RI-2 hex flags.
- **PA-9** — a new embedded-media tracker follows the tracker template: a raw `window` `message` listener MUST validate `event.origin` against a host allow-list before trusting the payload (VideoPress is the reference; SDK callbacks are exempt); push only to `window[gtm4wp_datalayer_name]` (never an HTML/JS sink); parse only the embed iframe's own `src` (never `location.search`/`hash`/`referrer`/`cookie`); guard `if ( ! duration ) return;` before any percentage division; guard against double-init; keep cross-injection counters on `window`; and never assume the element handed to the wire callback survives (an SDK may replace it — the observer re-marks the replacement, #40).
- **PA-11** — a WooCommerce-touching REST callback that needs the session/cart must `wc_load_cart()` (WC does NOT start its session on `/wp-json/` routes; `WC()->session` is null there); gate the load on a cheap precondition so anonymous requests pay nothing.
- **PA-12** — never bake a time-limited `wp_create_nonce()` into full-page-cacheable HTML; it goes stale (≤24h) and 403s an anonymous request before `permission_callback`. Send no nonce on an anonymous read; return a fresh one in the (uncached) response for any later authenticated call.
- **PA-18** — a build-time **generator** that writes shipped source from an external input is a script sink whose output executes on every install. Escape every interpolated value for the language being written (`var_export`, never `"'" . $v . "'"`); give it a plausibility floor; and never let it stamp a fresh date on output it could not verify, because that defeats every staleness guard downstream. The tell is partial validation — the field that could not have hurt it is checked and the ones interpolated into literals are not.
- **PA-17** — a file the project **publishes for somebody else to load into another system** (a tag-manager container export, an import bundle, an exported config) is surface with no route and no sink, so no other lens here selects it and it arrives with no Coverage Matrix row. Two questions: does it carry executable content (`"type": "html"` / `"jsm"`), and are the exporter's account/container ids anonymized. Re-asked in full on every refresh.
- **PA-13** — field descriptions / module intros render via `RawHTML` (an innerHTML sink); any dynamic value interpolated into one must be `esc_html()`'d server-side, and a `dangerouslySetInnerHTML` grep alone never proves the admin app has no HTML sink.

**False Positive Suppressions (FP) — do NOT flag:**
- **FP-1** — `echo` in `ScriptTag::print_script_block()` with `phpcs:ignore WordPress.Security.EscapeOutput` — the string is `wp_kses`-sanitized and only the ampersand is restored; intentional and reviewed.
- **FP-2** — additional data layer pushes via `DataLayer::flush_pushes()` use `wp_add_inline_script` (no `htmlspecialchars_decode`), so they are safe without the ampersand hex flag; still hardened for consistency.
- **FP-3** — ~~the `$echo` reserved-word-parameter warnings in `ContainerCode.php`~~ **RESOLVED, not a live waiver**: zero `$echo` parameters remain and `phpcs` exits 0 with warnings blocking, so the warning cannot be raised. Kept as history only — do not cite it as a standing caveat.
- **FP-4** — the AMP module (`AmpModule::add_amp_analytics_entries`) hands the **raw** compiled data layer to amp-wp's `amp_analytics_entries` filter as `config_data['vars']`; amp-wp serializes it to the `<script type="application/json">` itself (a *delegated* sink). The missing `JSON_HEX_*` flags here are intentional — pre-escaping would double-encode. Do NOT flag the raw passthrough or the absent hex flags. **The guard is amp-wp's flagless `wp_json_encode()` and PHP's default slash escaping, not document-level AMP validation** — see the entry (re-derived R15, #119) for what would invalidate it.
- **FP-5** — a guest-facing *frontend* REST mutation may use a session-bound CSRF token + strict session-ownership-scoping instead of a capability check (PA-1), when it only ever affects the caller's own session-resolved record, is idempotent, and is non-sensitive. Suppresses the **capability** question only — **not** the strength of the CSRF gate: a bare `wp_rest` nonce is a site-wide constant for logged-out callers (#78, open). `PageDataLayer::confirm_pending_purchase_tracked()` (#398); the public visitor-data GET endpoint is the sibling.

---

## Recurring Issues

### RI-1: Missing `defined( 'ABSPATH' ) || exit;` guard
Every PHP file — **including** the main plugin entry (`duracelltomi-google-tag-manager-for-wordpress.php`) — must start with the ABSPATH guard to prevent direct execution. Check every new PHP file under `src/`, `compat/`, and root.

The main file carried an exemption until 2026-08-06. It was not reasoned, only inherited, and wordpress.org's plugin review flags it (`missing_direct_file_access_protection` — their tooling exempts `uninstall.php`, which is guarded by `WP_UNINSTALL_PLUGIN`, but not the entry file). The guard is PHP 4 era syntax, so it does not conflict with the requirement that this file stay parseable on outdated PHP.

### RI-2: Data-layer / inline-`<script>` values must be JSON-encoded with the full hex flag set ⭐
Any PHP value serialized into the dataLayer or an inline `<script>` must use `wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS )` (plus `JSON_UNESCAPED_UNICODE` where wanted). `JSON_HEX_TAG` alone is **not** enough — the break-out character is often `"` (or `&`, once a downstream `htmlspecialchars_decode` is involved), not `<`/`>`.

Confirmed 2026-07-10: the site search term (`siteSearchTerm` from `?s=`) broke out of the dataLayer JS string with only `JSON_HEX_TAG` set. Fixed at `src/Frontend/ContainerCode.php`, `src/Frontend/DataLayer.php`, `src/Modules/WooCommerce/PurchaseTracking.php` by adding `JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS`. `JSON_HEX_AMP` is the decisive flag when the output later passes through any entity decode. Check every new `wp_json_encode` that feeds a script context.

**Flag hygiene (2026-07-22): never (re)add `JSON_NUMERIC_CHECK` to a dataLayer sink.** It is not a security flag — it coerces every numeric-looking *string* anywhere in the structure into a JSON number, silently mangling identifiers (a leading-zero SKU `"000035180"` → `35180`; order numbers, postcodes, phone numbers change type) including values third-party `gtm4wp_compile_datalayer` filters add. Removed from the main sink 2026-07-22 (the additional-push path never had it since 1.20.1). Values that really are numbers (prices, totals, counts) are **typed at their source** (`(float)`/`(int)` casts in the builders); all sinks agree on that contract now. A new builder returning a `wc_format_decimal()` string for a money field should cast it, not lean on an encode flag.

**Script-context matrix (Review 2, 2026-07-10) — which sink needs what:**
- **Raw `echo '<script>…'` that passes through `print_script_block()`** → needs the **full flag set** (the ampersand-restore makes `JSON_HEX_AMP` load-bearing). The three genuine dataLayer sinks already do this.
- **`wc_enqueue_js( '… = ' . wp_json_encode(...) . ';' )`** → a raw-`<script>` sink (WooCommerce prints it un-decoded in the footer): needs at least `JSON_HEX_TAG`, and it doesn't *look* like a `<script>` echo. **Now deprecated (WC 10.4) — don't reintroduce it (PA-8).** The plugin's only use (checkout products, `PageDataLayer.php`, ex-finding #7) was migrated to `wp_add_inline_script` on 2026-07-13, so it now falls under the bullet below.
- **`esc_attr( wp_json_encode(...) )` into an HTML attribute** (e.g. `data-…="…"`, hidden-input `value`) → **safe as-is**; `esc_attr` is the correct attribute-context escaper (encodes `<`/`>`/`"`/`&`/`'`). Do NOT flag these for missing JSON flags. The WooCommerce product-list markup uses this pattern correctly at 6 sites.
- **`wp_add_inline_script(...)` / `<script type="application/json">`** → WordPress/AMP print these without any `htmlspecialchars_decode`, so `JSON_HEX_TAG` alone prevents the only real break-out (`</script>`). Non-exploitable without the amp/quot flags, but add the full set anyway for uniformity — especially on extension points that accept third-party data (`GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY`). (Finding #11.)
- **`ContainerCode::header_top` block / the `FILTER_HEADER_TOP_JS` filter** (consent-tool head JS: Axeptio SDK loader, WebToffee callback) → since 2026-07-14 emitted through **`ScriptTag::print_script_block()`**, the *same* sanitizer + ampersand-restore path as `header_begin` (was previously a raw `wp_kses()` echo with **no** ampersand-restore — finding #29, fixed). So the contract is now uniform across both head paths: JSON embedded here still needs the full hex flags (RI-2), and hand-written JS added via the filter may use `&&`/`&`-joined URLs safely (the ampersand is restored). Regression test: `ContainerCodeTest::test_header_top_restores_ampersands_in_head_block_js`. Do not revert `header_top` to a bare `echo wp_kses(...)` — that re-opens the silent `&&`→`&amp;&amp;` corruption for any consent-tool integration.

### RI-3: Never reintroduce a blanket `htmlspecialchars_decode()` on script output ⭐
Inside a `<script>` element the browser never HTML-decodes entities — only a literal `</script` can break out. So `&quot;`, `&lt;`, `&#039;` in a script body are already inert. Running `htmlspecialchars_decode()` over the block turns them back into raw `"`, `<`, `'` and re-enables string/tag break-outs from any value escaped with `esc_js()`/`esc_attr()`.

`ScriptTag::print_script_block()` therefore does `str_replace( '&amp;', '&', wp_kses(...) )` — it restores **only** the ampersand (which `wp_kses` encodes and which valid inline JS needs for `&&` and `&l=`/`&gtm_auth=` URL params). Do not "simplify" this back to `htmlspecialchars_decode()`. Guard test: `ScriptTagTest::test_print_script_block_does_not_decode_quote_and_tag_entities`. (Fixed 2026-07-10.)

**The restore is a REQUIREMENT of every script sink, not a feature of one function (2026-08-04, #92).** The rule above is usually read as "print_script_block does something clever"; the operative half is that **`wp_kses()` on its own breaks any JavaScript containing `&`**, so a sink that calls `wp_kses()` and echoes the result has a defect, not a stylistic difference. #29 established this and fixed `header_top`; `the_tag()` — the `<noscript>` sink — kept the bare `echo wp_kses(…)` for three more reviews while emitting `console.warn && console.warn(…)`, which reached browsers as `&amp;&amp;`: a `SyntaxError` that killed the whole warning block.

- **Litmus:** grep `wp_kses(` across `src/` and ask of each hit *does the sanitized string contain, or could it ever contain, a `<script>` body?* If yes it must go through a `ScriptTag` sink. A bare `echo wp_kses(` next to script content is the finding. As of 2026-08-04 the sinks are `print_script_block()` (all-script blocks, 16 callers) and `print_markup_block()` (mixed markup + script, 1 caller: `ContainerCode::the_tag()`).
- **Two contexts want opposite things, so pick the sink by content, not by habit.** A raw `&` is *required* inside `<script>`; `&amp;` is *correct* inside an HTML attribute (`ns.html?id=X&amp;gtm_auth=Y`, which both 2.0 and 1.x have always emitted). A blanket restore on a mixed block fixes the script by corrupting the URL. `print_markup_block()` therefore restores only within `<script>…</script>`, using `preg_replace_callback` (PA-7 — a callback *returns* the replacement, so `$1` in a script body is never expanded) and degrading to the sanitized input on a PCRE failure rather than emitting `''` (#85's rule).
- **A test can hide this class completely, and did.** `wp_kses` is stubbed in unit tests, so a fixture that aliases it to the identity function makes a broken sink look correct. `ContainerCodeTest` carried **both** stubs — a realistic `str_replace('&','&amp;')` for the head sink and an identity alias for the noscript sink, twelve cases apart in one file. **Any test touching a script sink must model the real `wp_kses`'s ampersand encoding**; an identity stub asserts nothing about the thing that matters (test-review TS-1).

### RI-4: Pre-HTML-encoded values reaching a script sink are a trap ⭐
`get_search_query()` returns `esc_attr()`'d output; `esc_js()` emits `&quot;`/`&amp;`/`&lt;`. When such a value is then `wp_json_encode`'d (which cannot re-escape an already-`&quot;` sequence) and later decoded, the entity is resurrected into a raw quote/bracket and breaks out. Two robust fixes, applied together here: (1) hex-encode the ampersand at the JSON layer (RI-2), and (2) do not blanket-decode at the output layer (RI-3). When adding a dataLayer value, prefer passing the **raw** value and letting `wp_json_encode` do all escaping, rather than pre-escaping with `esc_attr`/`esc_js`.

**The class is per SINK, not per function — and the ledger closes it (2026-08-05, #105).** #72's fix note said "unify on the hex-flag encoder", and it reached the function it was reported against. Another `esc_js`'d string **value** in a raw `<script>` body sat three hundred lines lower in the *same file*, in a warning builder, and survived two more reviews — the litmus had been written as "in any function that dispatches on type", so a function that dispatches on nothing was not in scope. **Litmus (re-derive, don't read):** grep `esc_js(` across `src/` and sort the hits into two piles — (a) the value is a bare **JS identifier** (the data-layer name, a `window.<name>` reference), which is correct and must NOT be JSON-encoded, and (b) the value is a **string literal's contents**, which takes `wp_json_encode` with the hex flags. A hit in pile (b) is the finding.

> **Counting rule for this ledger, stated because two correct figures were read as drift (2026-08-12, #174).** The canonical pattern is **`esc_js(`** — with the parenthesis, as the litmus above spells it — over **`src/` + `compat/`**, counting matching **LINES**, with comment lines tallied separately. That gives **28 total / 13 comment / 15 call sites**. A *bare* `esc_js` grep over the same trees gives **33 / 18 / 15**: the same corpus, five extra lines that are prose mentions carrying no call. R17 and R21 recorded 28, R19 recorded 33, and **all three were right** — the tree is byte-identical across every one of those shas (verified: same 33 lines, only renumbered). Neither figure needs "correcting"; what was missing is this paragraph. Note also that the two piles are counted in **lines**, so a line carrying two `esc_js(` calls is one member — `ContainerCode.php:491` and `:493` are **2** pile-(b) members and **4** calls, and both numbers have been written down without their unit.

**"Pile (b) is empty" was written the same day and was wrong (2026-08-05, #110).** The
sentence above used to end with a standing all-clear. One member was already there:
`Notices::print_dismiss_script()`, whose nonce is a string value in a raw `<script>`
body — the ledger had filed it under "constrained", because a nonce cannot be hostile.
That is a **third** category the two piles do not have, and it is where a hit
disappears: *pile (a) is correct by grammar* (a JS identifier must not be quoted),
*pile (b) is the finding*, and **"the value happens to be harmless" is neither** — it is
a statement about today's data, not about the encoder, and it belongs in pile (b) with a
note, not outside the piles. Sort by **what the value is in the emitted JS**, never by
what it currently contains.

**Do not write a ledger as a standing all-clear.** A count or a "pile is empty" is a
measurement with a date, and its only honest form is *re-derive this*. A "there are
none" is the sentence that stops the next reviewer running the grep — the same shape as
a matrix date that under-reports (#73), a sweep count that drifted (#87, #111) and an FP
whose "do not flag" closed the question (#78).

**Pile (a) has a standing member too, and recording it is the cheap half (2026-08-10,
R17).** Re-derived at `2ea4441`: **28** `esc_js(` grep hits in `src/`+`compat/`, of which
13 are comments *about* `esc_js` (mostly explaining why it was replaced) and the rest are
pile (a) — every one the data-layer name or a pattern-validated container value, emitted
as a bare unquoted identifier where JSON encoding would be **wrong**. Pile (b) is
unchanged at the three `ContainerCode.php` members below. The one worth naming is
`DataLayer.php:269` (`esc_js( $datalayer_name ) . '.push('`), found while re-deriving
**FP-2**: the value reaches it through `ContainerRows::datalayer_name()`, which trims and
then requires the shared identifier grammar or falls back to `dataLayer`, so `esc_js()`
there is a **no-op on every value that can reach it** — redundant, not wrong. File it in
pile (a) with that note rather than leaving it unsorted; an unsorted hit is what #117 was.

**Pile (b)'s standing members, re-derived 2026-08-05 (R15, #117) — three, not zero.**
The paragraph above was written the day #110 landed, and the very next review found the
pile repopulated: `ContainerCode.php:131` (`var gtm4wp_datalayer_name = "<esc_js>";`),
`:460` (the loader `'//<domain>/<path>'` literal) and `:462` (`'<name>','<gtm id>'` in
the container snippet). Every one is inert — the values are pattern-validated at or
before the sink — which is **exactly the "constrained by validation" filing this entry
had just ruled out as a third pile**. They are recorded here so the pile is never empty
again by default. Two things to keep:
- **The ledger's failure mode is the pile going quiet, not a hit being mis-sorted.**
  Nobody filed these anywhere; they simply did not come up, because the grep was run and
  the hits were each individually reasoned away. Sorting them *into* pile (b) with a note
  is what makes the next re-derivation cheap.
- **A migration must encode the whole quoted literal, quotes included.** `:462` is part
  of the byte-exact 1.x container snippet (BE-1), so encoding only the value would change
  the emitted shape for everybody; #105 and #110 both took the whole-literal route for
  this reason.

**Four shapes of ledger failure. The fourth is the one "re-derive, don't read" cannot
catch, because re-deriving is exactly what produced it (2026-08-10, #132).** A count kept
in **two of our own files** where only one gets corrected: #115 fixed the
`print_script_block()` caller figure in the checklist's sweep row (17 → 12) and left FP-1's
copy of the same figure at 16 for another review. Re-deriving *a* ledger does not find the
*other* ledger. Two rules follow, and they are UC-6 ("prefer one definition over two")
applied to this system's own bookkeeping:
- **When you correct a count, grep the other files for it.** The sweep table and the
  patterns entries hold overlapping measurements by design; fix both in one edit, or have
  one point at the other rather than restate it.
- **Record the counting rule beside the number, always.** FP-1's 16 was a *grep-hit* count
  presented as a *call-site* count, and nothing in the entry said which — so it could not
  be reproduced, only re-guessed. The raw grep returns 18 and the answer is 12. The one
  row that has reproduced across runs (Dead JS) is the one that wrote its rule down.

**Three shapes of ledger failure, all found in one sweep row across two reviews
(2026-08-05, #115/#116/#117).** Worth naming separately, because only the first is what
"re-derive, don't read" already catches:
1. **Wrong when written** (#115) — a figure recorded as re-derived that the tree never
   supported. Caught by actually running the count.
2. **Correct then self-invalidated** (#116) — a figure derived accurately and then made
   stale by production code **in the same commit**. Caught only by re-deriving *after*
   the session's code is staged. **So re-derive a ledger last**; a sweep row written
   mid-session measures a tree that no longer exists when it is committed.
3. **A category the rule says must not exist** (#117) — hits that are neither pile, filed
   under a third heading the ledger explicitly forbids. Caught by re-reading the rule
   before trusting the pile.

- **Encode the whole message, not the interpolated value.** Encoding just the value forces string concatenation into the emitted JS, which changes the output shape for everyone and breaks byte-parity tests (BE-1) for no benefit. Encoding the entire literal — message text and value together — leaves the emitted line byte-identical for ordinary input while making hostile input correct, which is why the existing assertions kept passing and the new hostile-input test still fails without the fix.

**Check the branch beside it, not just the function (Review 10, 2026-07-28, #72).** The residues RI-4 names are usually found per *function*; the remaining ones now sit per *branch*. `ContainerCode::global_var_literal()` encodes an array value with hex-flag `wp_json_encode` and, three lines lower, renders a string value with `esc_js` — one function, two contracts, and R9 had just rewritten the type dispatch of that very function (#58) without touching it. **Litmus:** in any function that dispatches on type and emits into a script context, every branch must use the same encoder. A branch still using `esc_js`/`esc_attr` next to one using hex-flag JSON is the finding, and the fix is to delete the odd branch's escaper rather than to reason about whether its output is inert. (It usually is inert — `esc_js` entity-encodes `<` and backslash-escapes `'`, and `print_script_block` restores only `&amp;` — so this class is *data corruption*, not XSS, exactly as the paragraph below describes.)

**Post-hardening consequence (Review 2, 2026-07-10):** once RI-3 removed the blanket `htmlspecialchars_decode()`, the leftover `esc_js()` pre-escaping stopped being an XSS trap but started **corrupting the data**: `&`/`'`/`"`/`<` in a value now render in the dataLayer as `&amp;`/`\'`/`&quot;`/`&lt;` instead of the real character (`Marks & Spencer` → `Marks &amp; Spencer`). So "remove the redundant `esc_js`" is not just cosmetic — it fixes a live data-quality bug. Known residues to strip: `ProductData::get_raw_order_datalayer()` (~30 order/billing/shipping fields, finding #8) and `PageVariablesModule` `visitorIP`/`geoCloudflareCountryCode` (finding #12). The correct sibling pattern is `get_purchase_datalayer()` / `PageDataLayer` customer fields, which pass raw values. **Rule: a value destined for a `wp_json_encode` dataLayer sink must arrive raw — never `esc_js`/`esc_attr`-pre-escaped.**

### RI-5: Missing i18n / text domain
Every user-facing string uses `__()`, `esc_html__()`, `esc_attr__()`, etc. with the text domain `duracelltomi-google-tag-manager`. Flag hardcoded English in admin UI and notices.

### RI-6: Unsanitized superglobal reads
Every `$_GET`/`$_POST`/`$_REQUEST`/`$_COOKIE`/`$_SERVER` access must be `wp_unslash()`'d and passed through a sanitizer/validator appropriate to the type (`sanitize_text_field`, `absint`, `filter_var(..., FILTER_VALIDATE_*)`, or an allow-list) before use — even for values that will "only" be compared or logged. Flag raw reads.

**Second entry point into a shared sanitizer (Review 6, 2026-07-15):** the REST *save* route type-normalizes each value at the REST layer (per-field `properties` from `Field::rest_type()`) before it reaches `Field::sanitize()`. A *second* write path that reaches the same sanitizer while bypassing that layer — the settings **import** route decodes a raw `payload` string itself and hands decoded values straight to `sanitize()` — does not get that coercion, so a crafted file can hand an array to a scalar field. It is not an XSS (the sanitizers still reduce every value to a safe scalar/array-of-scalars and every output sink re-escapes), but the scalar branches' `(string) $value` cast then raises a PHP `Array to string conversion` warning (finding #32). **Rule: whenever you add a new write path (import, bulk, programmatic) into the shared `Field::sanitize()`, either type-normalize decoded values at that boundary or keep the scalar sanitizer branches type-defensive (`is_scalar()` guard before the `(string)` cast) so every entry point behaves identically.**

**Custom sanitizers sit in front of that guard (Review 8, 2026-07-22):** `Field::sanitize()` returns a custom sanitizer's result *before* the type-based branches run, so the type-defensive `to_string()` never protects a field that defines its own sanitizer — the custom callable receives the raw value from every entry point, including the import route. A custom sanitizer that does a bare `(string)` cast re-opens the exact class the shared guard closed. **Rules: (a) a custom Field sanitizer must be type-defensive itself (`is_scalar()` before any string cast); (b) don't write a custom sanitizer that is a *subset* of the type default — `trim()` alone is weaker than the TYPE_TEXT default (`sanitize_text_field` already trims, strips tags, and is type-safe), so it costs protection and buys nothing.**

### RI-7: Raw SQL without `$wpdb->prepare()`
Any `$wpdb->query`/`get_var`/`get_results` with interpolated input must use `$wpdb->prepare()`. (No direct DB access exists in the reviewed 2.0 core yet; enforce on any new query.)

### RI-8: WooCommerce order data via post-meta
Never use `get_post_meta()`/`update_post_meta()` for order data — use the WC CRUD API (`$order->get_*()`/`$order->update_meta_data()`) so the plugin stays HPOS-compatible. Applies to any new order-touching code.

### RI-9: JS source changed without rebuilding `build/`
`js/**/*.js` is compiled to `build/` by `wp-scripts`. A change to a source file without a matching regenerated `build/` artifact ships stale runtime code. After editing `js/`, run `npm run build` and `npm run lint:js`. (Note: `build/` is git-ignored in this repo — the release ZIP is produced by `tools/build-release.js` — so "commit `build/`" does not apply here; regenerate it for local verification.)

### RI-10: Undeclared variable in a frontend JS file
Every `js/frontend/**/*.js` file is an ES module (it `import`s helpers) and is bundled by `wp-scripts` into a `"use strict"` IIFE. A bare assignment to an undeclared identifier (e.g. `player = new YT.Player(...)` with no `var`/`let`/`const`) is a silent auto-global in sloppy mode but throws `ReferenceError` under strict mode — the exact context these bundles run in. Such a throw inside a `forEach`/loop aborts the remaining iterations. Confirmed 2026-07-13: `gtm4wp-youtube.js:58` (finding #19) breaks multi-video tracking. Flag any assignment whose left side is never declared. ESLint's `no-undef` catches this if the global isn't whitelisted — do not add tracker-local names to the `.eslintrc.js` `globals` list to silence it.

### RI-11: Over-exposure — a value that is escaped correctly but does not belong in the dataLayer
Escaping answers "can this value become code?" It never answers **"should this value be here at all?"** Those are two different bugs, and this plugin's entire job — copying server-side request/page/order state into a client-readable `<script>` on a public page — makes the second one structural, not incidental. Per the threat model, **injection** and **exposure** are the two permanent risk classes; RI-2/RI-3/RI-4 own the first, this owns the second.

**The rule:** every new dataLayer key answers two questions before it ships — (1) does the client actually need it, and (2) is the **lowest actor who can read the page** (usually A0, an anonymous visitor) entitled to it? A field sourced from A3/A4 data (order internals, customer PII, admin settings) landing in an A0-readable dataLayer is a finding **even when it is perfectly `wp_json_encode`'d with the full hex flags**. Rate it on what leaks and to whom, not on the escaping.

Recurring offenders: internal post/product ids, email addresses, billing/shipping names and addresses, order totals, and submitted form field values. Confirmed twice, which is what promoted this to an RI:
- **#31** (2026-07-15, Low, fixed) — block cross-sell items leaked `internal_id` (the internal product post id) into GA4 because the cross-sell mapper omitted the `delete` its sibling cart-item mapper does. A public post id, so Low — but it is the same class, and the *sibling asymmetry* is the tell: when one mapper strips a field and its sibling doesn't, the omission is a bug, not a decision.
- **#30** (2026-07-14, Low, wontfix) — CF7 `integrate-wpcf7-inputs` defaults to `full`, pushing submitted field values (potential PII) into the dataLayer. Closed `wontfix` as intended, documented, 1.x-compatible behavior with an opt-out. That is a legitimate outcome for this class: exposure is often a **product decision**, so surface it and let the maintainer decide — but surface it, with the actor and the data named.

The `#398` cache-safe data-layer work (2026-07-16, `813e882` "omit server PII") is this rule applied deliberately at design time — server-rendered PII was removed from cached pages and moved behind a per-request endpoint. Check new fields against that direction of travel rather than against 1.x precedent.

### RI-12: `isset()`/`??`/`empty()` on a third-party object property may be false even when a value exists
`isset()`, `??` and `empty()` consult the object's `__isset()` magic method; a class that exposes properties through `__get()` (a common third-party pattern — PublishPress's `Author`) need not implement `__isset()`, in which case `isset( $obj->prop )` reports **false** even though `$obj->prop` would return a real value through `__get()`. Reading a third-party object with `isset( $o->p ) ? $o->p : $default` then silently blanks every value (#43: author names/IDs → `''`/`0`). **Rule: to read a possibly-magic property, go through `__get()` directly** — `( property_exists( $o, $p ) || method_exists( $o, '__get' ) ) ? $o->$p : $default` — never gate the read on `isset()`/`??`. A test that stubs the object as a plain `stdClass` with real properties cannot catch this (`isset()` always works there); the regression test needs a `__get`-without-`__isset` stub (test-review TS-13).

### RI-13: A conditional tag does not guarantee its companion global is set up
`is_singular()` (and conditional tags generally) report what the main query *matched*, not that the matching global is populated at output time. Unusual template routing, a plugin resetting the global, or a missed `wp_reset_postdata()` can leave `$GLOBALS['post']` null inside an `is_singular()` block, so a direct property read (`$GLOBALS['post']->post_author`) raises a PHP warning on every affected page view. Confirmed in production: forum-reported against 1.22.3, carried into 2.0 by the PageVariables port (five unguarded reads), fixed 2026-07-22 (`f38d860`).

**Rules:**
- Resolve the object **once** through the safe accessor (`$post = get_post();`), null-gate the sub-blocks that read it, and use the local — not scattered per-read `isset()`s.
- When the object is unavailable, **omit** the affected dataLayer keys entirely. Never emit `''`/`0`/`null` placeholders — a consumer's GTM trigger may test for key presence, so an invented falsy value is a behavior change; omission matches the existing failure paths.
- **Litmus (mechanical):** `grep -nE "\\\$GLOBALS\['\w+'\]|global \\\$\w+" src/ compat/` — **all** `global $…` statements, not just post/authordata: the class covers every companion global (`$wp_query`, `$wp`, `$product`, `$woocommerce_loop`, `$authordata`, …). A hit whose property read is not behind a null/`isset`/`instanceof` gate is the finding (an array-*write* on a null global is safe — auto-vivification). Widened 2026-07-22 (R8) after the narrow pattern missed an unguarded `$wp_query->post_count` read (#51) in the same file the `$post` fix had just cleaned up. **Sibling asymmetry is the tell:** MediaEvents guarded (`isset`), `WooCommerceModule::page_uses_block()` guarded (`instanceof \WP_Post`), PageVariables didn't.
- **A gate is only half the rule — check the else branch too (R9, #59).** The mechanical litmus above finds *ungated* reads, so a site written as `isset( $authordata->ID ) ? $authordata->ID : 0` passes it while still violating the pattern: it emits a `0` placeholder where the rule says **omit the key**. R8 fixed exactly this for the singular blocks (#47) and the post counts (#51) and the author-*archive* sibling was left behind, because the sweep as written could not see it. So run the litmus in two passes: (1) is every property read gated, and (2) **does each gate's negative branch omit the key, or invent a value?** A `? … : 0` / `: ''` / `: false` on a global-derived dataLayer value is the second-pass finding.

- **The omit-don't-invent half travels beyond globals, and beyond the value's own branch (2026-08-11, #146).** #121 already generalized it off-global (a module emitting both of its keys with one always empty). #146 is the next step: the *helper* gained an "unencodable → return `''`" path, and the **call site** kept assigning unconditionally, so the key is present and empty in a payload whose own docblock promises empty fields are omitted — three lines above a sibling identifier that omits its key for exactly this case and cites this pattern while doing it. **When a helper gains an empty-return path, its callers are the change, not the helper.** Grep every caller in the same commit that adds the `return ''`; the litmus above cannot select any of them, because none of them touches a global.

This is the request-state sibling of **PA-11** (`WC()->session` is null on REST routes): the real collaborator can be in a state the reviewer's mental model — and a well-formed test fixture (test-review **TS-13**/**TC-14**) — never represents. The failure class is correctness/log-noise (no actor, no sink), which is exactly why the injection/exposure lenses never prompted for it; the mechanical sweep in the checklist is what applies it.

### RI-14: A value shared between a PHP writer and a JS reader needs ONE definition
Several de-dupe/state keys in this plugin are written by PHP into an inline `<script>`, written again by a bundled JS runtime, and read back by PHP from the cookie. Each site is individually correct and they still disagree, because nothing forces them to agree. Confirmed 2026-07-22 (#57): the `gtm4wp_orderid_tracked` key is written by PHP as `esc_js( $order->get_order_number() )` (so `&`/`"` are stored as `&amp;`/`&quot;` — the inline-script path never HTML-decodes), written by `gtm4wp-visitor-data.js` as the **raw** order number, and read back by `ProductData::is_purchase_already_tracked()` through `FILTER_VALIDATE_INT` against the order **id**. On a default install the order number *is* the numeric id and all three coincide, which is exactly why the drift survived several reviews.

**The option-named JS global is the same class (2026-07-28, #66).** The data-layer
variable name is an *option* on the PHP side and a *global* on the JS side, so every
push site must go through the indirection (`window[ gtm4wp_datalayer_name ]`) rather
than the default literal. One site out of ~45 hardcoded `window.dataLayer`, and its
own truthiness guard (`if ( obj && window.dataLayer )`) then swallowed the failure
silently on any site using the rename — or pushed GTM4WP's payload into whatever
other tool owns `window.dataLayer` there. **Litmus:** grep `js/` for the *default*
spelling of any configurable name (`dataLayer`, cookie names, prefixes). Outside a
documented `|| 'default'` fallback, a literal default is a missing indirection —
and a green suite proves nothing, because every fixture sets the name to the default.

**The two ends must agree on the BINDING, not only the name and the value
(2026-08-06).** Everything above is about *what the value is*. This case has one
name, one value and one definition — it passes the litmus below cleanly — and the
two ends still disagree, about *how the name is bound*.
`ContainerCode::header_top()` prints every `GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY`
entry as a top-level `const` inside a **classic** (non-module) inline `<script>`. A
top-level `const`/`let` binds in the global **lexical** environment record and never
becomes a property of `window`; only `var` (and an explicit `window.x = …`) does.
Three globals were read as `window.<name>` and were therefore permanently
`undefined` in a browser: `gtm4wp_list_attribution` (#405, 5 sites),
`gtm4wp_datalayer_max_timeout` (2 sites, **inherited from released 1.x**) and
`gtm4wp_checkoutwc` (#385). All three features shipped dead — a disabled tracker
throws nothing, so this is UD-11 on a contract wholly inside the repo.

For #405 it presented as two unrelated bugs: the client half never wrote the cookie,
and because the cookie never existed the *server*-side merge in `ProductData` found
an empty map, so `view_cart`/`begin_checkout`/`purchase` were bare too.

- **Litmus (mechanical):** for every name the PHP side prints inline, check the
  emitted keyword against every reader. `var` / `window.x = …` → a window property,
  `window.x` is fine. `const` / `let` → lexical, and **only** a bare identifier
  reads it. `grep -n 'window\.gtm4wp_' js/frontend/` and diff that list against the
  filter's keys.
- **RI-10 does not cover this and ESLint cannot either.** `no-undef` sees a bare
  identifier and checks it against the `globals` list; `window.<name>` is a member
  expression, so it is *invisible* to `no-undef` no matter what the globals list
  says. Two of the three broken names were absent from `.eslintrc.js` entirely for
  exactly that reason — nothing ever demanded a declaration.
- **The control is a lint rule, not this entry (UD-2).** `.eslintrc.js` now keeps one
  `inlineConstGlobals` list used twice: to declare the names, and to feed a
  `no-restricted-properties` rule rejecting the `window.` spelling in
  `js/frontend/**` (tests excluded). A review step has to be remembered; the rule
  fails `npm run build`.
- **A jsdom suite structurally cannot see this** — see `.testing` **TS-17**, which
  owns the test half.

**Rules:**
- Pick one canonical serialization and name the other side in a comment at every site (the `lib/gtm4wp-cookies.js` ↔ `purchase_dedupe_guard()` cross-reference from #52 is the model).
- For a value embedded in an inline `<script>`, produce it with `wp_json_encode( …, hex flags )` emitted **without** surrounding quotes — that yields the raw value at runtime and matches what JS writes. Never `esc_js()` it (PA-4): `esc_js` is an *encoding*, so it changes the stored bytes.
- **Litmus:** grep the storage key literal across `src/` and `js/`. More than one construction expression for the same key is the finding. Ask specifically: does every site key on the same *identifier* (order number vs order id), and does every site use the same *encoding*?
- These break only where the identifier is non-default (a prefixed/sequential order number, a value containing `&`/`"`), so a green suite and a default install both prove nothing.

### RI-16: A falsy/`NaN` fallback in JS does not catch `null`, `''` or `0`
The tracker bundles repeatedly normalize a DOM-sourced value with a guard that
only fires for `NaN`. But the value usually arrives from a short-circuit
(`el && el.value`), so when the element is absent it is `null` — and `isNaN( null )`
is **false**, because `Number( null )` is `0`. The fallback never runs and the
`null` flows straight into the payload. Confirmed 2026-07-28 (#69): a product form
with no `[name=quantity]` input emitted `quantity: null` and `value: 0` on
`add_to_cart`, while the two sibling branches in the same function used a plain
`|| 1` and were unaffected.

**Rules:**
- Convert first, then test: `const n = parseInt( raw, 10 ); use( Number.isNaN( n ) || n < 1 ? fallback : n );`
- Prefer `Number.isNaN()` over the global `isNaN()` — the global coerces, which is
  the whole bug. `??` only catches `null`/`undefined`; `||` also catches `0` and
  `''`, which is wrong for a legitimate zero.
- **Litmus:** for every `isNaN( x )` / `x || d` guard, ask what `x` is when the DOM
  lookup that produced it returns nothing. If the answer is `null` or `''`, the
  guard does not cover the case it was written for.
- **Sibling asymmetry is the tell again:** #69's two neighbours in the same
  function got it right. When three branches do the same normalization and one
  spells it differently, read the odd one.

### RI-15: An option's description is its consent contract
The admin's only view of what a feature publishes is the field label and description. When the implementation emits more than the description names, the escaping can be flawless and the finding still stands — the admin never consented to the extra data. This is the *consent* half of RI-11 (which owns the "is this actor entitled to it?" half), and it is the one exposure question a threat model cannot answer, because it turns on what the admin was told.

Confirmed 2026-07-22 (#55): the option labelled **"Post Terms"** and described as *"include taxonomy values associated with a given post"* also copies **every** post meta key without a `_` prefix — with its value — into the public data layer. Unprotected meta is where ACF stores field values and where SEO/CRM/form/membership plugins keep notes, ids and contact details, so the real payload is site-dependent and unbounded.

**The contract covers three things, and the quiet two are the variable name and the value form (2026-08-04, #84).** #55 was the loud case — an option emitting a whole extra category of data. The everyday case is an option that emits exactly what it promises but never says *where* it lands or *what shape* the values take, and an admin cannot build a GTM variable from that. Confirmed on the tag option: its description says only "include the tags of the current post" while the code writes tag **slugs** (not display names) into a variable called **`pageAttributes`** (not `pageTags`) — both facts an admin has to discover by reading a data layer dump. The tell is that its direct sibling, the category option eleven lines away in the same method, had *both* omissions corrected in the very commit that left this one alone. **When you correct one description, diff every option in the same group against its emitter in the same pass** — descriptions drift as a set, because they were written as a set.

**Rules:**
- When adding or reviewing a data-emitting option, read its description and diff it against what the code actually emits. A "and also everything matching X" loop under an option named for one specific thing is the tell.
- Name the **data-layer variable** and, where values are transformed (slug vs display name, timestamp vs formatted date, id vs number), the **form** — not just the subject matter.
- Prefer a separate opt-in field over widening an existing option's payload — enabling taxonomy terms must not imply publishing custom fields.
- A per-key opt-out filter (here `gtm4wp_post_meta_in_datalayer`) is a mitigation, not consent: it only helps the admin who already knows the data is there. Name it in the description.
- Default-off plus a filter is what keeps this class Low/Medium rather than High; an on-by-default version of the same code would be High.

**Splitting an over-broad option needs a migration path (fix pattern, 2026-07-22).** The remedy for this class is usually "split the option in two so the extra data is its own opt-in" — but the split must not silently stop sending data an existing site already relies on. Seed the new option from the legacy one (`Migration::seed_post_meta_option()` is the reference: self-guarded on key absence so it can neither run twice nor override the admin's own later choice), and say so in the field description and the changelog bullet, so the upgrading user reads "nothing changed for you, here is the switch" rather than discovering a silent behavior change. The privacy improvement is then the *new* admin's default, not a regression for the existing one.

### RI-17: An escape is only valid at the instant of output — anything that transforms the string afterwards can undo it ⭐
`esc_attr()`, `esc_html()` and hex-flag `wp_json_encode()` all make a *specific string* safe for a *specific context*. They are not a property the value keeps. Any step that runs between the escaper and the browser can put break-out characters back, and no amount of escaping earlier in the chain defends against it. The reviewer's instinct — "this is `esc_attr`'d, so the attribute is safe" — reads the call and stops, when the question is what happens to the string *after* that call.

Known mechanisms, all of which take an escaped string and hand back an unescaped one:
- **`preg_replace` replacement expansion** — `$0`/`$n`/`\1` are substituted with the matched text *after* escaping. The live instance was #74; PA-7 owns the details.
- **`sprintf`/`vsprintf` and concatenation** — a format string or a neighbouring fragment can supply the quote the escaped value no longer contains.
- **Re-parenting into a different context** — a string escaped for an HTML attribute and then emitted inside a `<script>` body (or the reverse) is escaped for the wrong grammar; RI-4 is this rule's older, narrower sibling (it covers pre-encoded values arriving at a script sink; this covers a correct escape being undone downstream).
- **Editing markup SOMEBODY ELSE escaped (2026-08-05, #112).** Every mechanism above starts from *our* escaper, which is why the litmus below says "follow the variable to the sink" — it assumes an escape happened here and asks what came after. A filter callback that takes finished markup from another component and splices a value into it has **no escaper of its own to follow**, so the litmus selects nothing and the value goes in raw. `MediaEventsModule::enable_youtube_js_api()` is the confirmed instance: an `oembed_result` callback `str_replace`-ing a site-derived origin into the iframe `src` the oEmbed handler had already escaped. Inert as written (the value is A4-set and a hostname cannot carry a quote), and the point stands anyway — an escape that is only correct because of where its value happens to come from is not an escape.
  - **Litmus, second pass:** grep for filter callbacks that *return modified markup* — `str_replace`/`preg_replace`/concatenation applied to a `$content`, `$html` or `$return_value` parameter. Ask what grammar the insertion point is (attribute? script body? text?) and escape for it **at the splice**, not upstream. `the_content`, `oembed_result`, `oembed_dataparse`, `script_loader_tag`, `style_loader_tag`, `wpcf7_form_additional_atts` and the WooCommerce markup filters are the shapes this repo actually uses.

**Read backwards, the rule also covers the escaper's own failure mode (2026-08-05, #120).**
Everything above watches what happens *after* the escaper. The mirror case is a guard
placed *before* one: `MediaEventsModule::enable_youtube_js_api()` null-gates the parsed
scheme and host so an unresolvable site URL cannot produce a half-built `origin=`, and
then hands the result to `esc_url()` — which returns `''` for a scheme outside
`wp_allowed_protocols()`, a **filterable** list. The escaper therefore re-creates exactly
the empty value the gate upstream of it was added to prevent, and the gate cannot see it
because it ran first. **Litmus:** when a value is validated and then escaped, ask what the
*escaper* returns on input it dislikes — `esc_url()` and `esc_url_raw()` return `''`,
`wp_json_encode()` returns `false`, `sanitize_*` silently strips. A guard is only a guard
for the steps that come after it.

**Rules:**
- **Escape last.** The escaper's output should reach the sink unmodified. If a transform must run after it, the transform is now part of the security boundary and must be shown not to reintroduce the character the escaper removed.
- **Litmus (mechanical):** for every `esc_attr(`/`esc_html(`/`wp_json_encode(` whose result is not echoed on the same line, follow the variable to the sink and name every function it passes through. A helper in the path counts — it hid #74 for eight reviews.
- **A finding here is rated on the sink, not the transform.** The transform is where the bug lives; the actor who supplies the value and the context it breaks into set the severity (PA-7's `href="` case is A2/A3 → A0 stored XSS; the same mechanism at a quote-free match is Low correctness).

### RI-20: A key name handed to a third-party runtime is a contract, and the only place to check it is that party's documentation
Most of this file follows a value from where it is built to where it is printed, because both ends are in this repository. A **data-layer key the plugin writes for Google Tag Manager to read** has only one end here. The plugin writes it; something else decides whether to read it; and if the name is wrong nothing anywhere reports that — the page renders, the settings screen still shows the feature switched on, the unit tests still pass (they assert what *we* emit), and the feature silently does nothing. There is no sink to inspect and no error to catch. The only check available is reading the other party's documentation and comparing.

Confirmed 2026-08-05 (#122): the tag-restriction keys were the pre-2.0 names, which appear in **neither** Google's current page for the feature nor the older one it superseded — and neither page says the old names are supported, deprecated, or anything at all. The module's own docblocks cited that page as their authority in three places, for entity IDs, while emitting key names it does not contain. The entity IDs *had* been re-derived against that page in the 2.0 refresh; the keys carrying them had not, because nothing prompts you to re-read a name that has worked for a decade.

**Rules:**
- **A citation is a claim about a page, so open the page.** When a docblock says "per <vendor URL>", check that the page actually contains what the code does — not just the part the comment was written about. #122's URL was correct for the entity list and wrong for the keys, in the same comment.
- **Prefer the documented name over the observed one.** "Undocumented and observed to work" is a weaker guarantee than "documented", and the asymmetry matters: if the documented name is also read, switching costs nothing; if the undocumented one is ever dropped, the failure is silent and total.
- **Do not emit both "for compatibility" without checking what the vendor does with both.** Here the vendor's own rule (*"when both have been set, blocklists take precedence"*) means emitting a redundant second key moves you into a documented branch you did not want — and the empty-list behaviour inside that branch is documented nowhere. A compatibility shim can be the more undefined option.
- **Ship a test that pins the name and rejects the superseded one**, so a well-meant "let's also send the old key" is a red test rather than a silent posture change.
- **Litmus:** grep for every string literal this plugin emits that a *third party* parses — data-layer keys, cookie names another vendor reads, query parameters on a vendor URL, `postMessage` event names, REST field names in someone else's schema. For each: which vendor page documents it, when was that page last read, and what happens if the name is wrong? "Nothing observable" is the answer that makes it this class.
- Rate on what the silent failure costs. A wrong key on a *security* feature (tag restrictions, consent signals) is worse than on an analytics field, because the screen keeps claiming the protection is on.

**The VALUE is the same class as the name, and the documentation is not enough to settle it — read the runtime (2026-08-05, #121).** Everything above is about getting the key *name* right. The same one-ended contract applies to what you put in it, and there the vendor's prose runs out sooner: neither Google page says what an **empty** restriction list means, and the plugin had been publishing one for a decade. Replaying the runtime's own functions against the plugin's output answered it in minutes — an empty array is **truthy in JavaScript**, so an empty allowlist is an allowlist that *has been set* and permits nothing, and the blocking mode was disabling the entire container rather than the selected entities.

- **The vendor ships its runtime to your users; you can read it.** A minified bundle is not a black box — `curl` it, grep for the literal, and lift the handful of functions that consume it into a throwaway probe with the real helpers pasted in verbatim. That is a stronger answer than any documentation, because it is the code that will actually run.
- **A falsy-looking placeholder is not neutral across a language boundary.** PHP's `empty( array() )` is true; JavaScript's `[]` is truthy. Every "we just send an empty one, it means nothing" assumption spans exactly that gap. This is RI-13's omit-don't-invent rule with teeth: the reason to omit a key is not tidiness, it is that the consumer decides what present-and-empty means and may well disagree with you.
- **Fails-closed is why it survives, not why it is minor.** A security-shaped feature that over-blocks looks like it is working hard. Nothing errors, nothing is exposed, and the option's own description usually warns it can break things — so the user blames their configuration. Ask of any restriction/consent/opt-out feature: *if this were completely broken, what would the site owner see?* If the answer is "what they would see if it were working", no bug report is ever coming.
- **Assert ABSENCE, not emptiness,** in the regression test, and name every alias the consumer reads. `assertSame( array(), $x['key'] )` is the assertion that pinned this defect in place for three reviews.

### RI-19: A falsy return conflates "it failed" with "it was never there" — and only one of those wants a fallback
Most WordPress APIs report *both* an operational failure and an absent collaborator as `false`. Writing a fallback on the falsy branch therefore silently answers a question nobody asked: it assumes the thing you were talking to exists and merely could not be reached, when the far more common production case is that it is legitimately not on this page at all.

Confirmed 2026-08-05 (#108). `wp_add_inline_script()` returns `false` both when the handle is already printed (the case the fallback was written for — a tracker filtered into the `<head>`, where the data really would be lost) and when the handle was never registered. Those are not the same event: on a **block-based** cart/checkout, `WooCommerceModule::enqueue_scripts()` deliberately loads the block tracker *instead* of the classic one, so the handle is absent by design and nothing on the page reads the globals — while `is_checkout()` is still true, so the emitting code still runs. The fallback therefore printed a full checkout payload with no reader on WooCommerce's default checkout, duplicating items the `begin_checkout` push already carried. The fix was one predicate: only defer when the handle is actually **enqueued**.

**Rules:**
- **Name the absent case out loud before writing the else.** "What does it mean if this is simply not here?" If the answer is "then there is no consumer", the correct fallback is to do nothing.
- **Prefer a positive precondition to a negative return.** `wp_script_is( $handle, 'enqueued' )`, `is_a( $obj, … )`, `did_action( … )` state what you require; a `! $api_call()` states only that something went wrong, without saying what.
- **Same-symptom, different-cause is the tell.** Two causes producing one falsy value is the shape; the API's own docs usually list both, one sentence apart, and the fix reasoning quotes only the first.
- **Litmus:** grep for `! wp_add_inline_script(`, `! wp_enqueue_*`, `false === get_*`, `! has_action(`, `empty( WC()->… )` used as a *fallback trigger*. For each, ask whether the absent-collaborator case reaches that branch, and whether the fallback is meaningful when it does.
- The wider family: **RI-16** is this rule inside JavaScript (a falsy guard that does not distinguish `null` from a real zero), and **RI-13** is it for WordPress globals (a conditional tag that is true while its companion is absent). All three are "the falsy branch merged two states".

**Second confirmation, and this one is a `class_exists` guard rather than a return value (2026-08-12, #173).** `PageDataLayer::woocommerce_hides_order_from_visitor()` delegates a privacy decision to a WooCommerce `Internal` helper behind `class_exists` + `method_exists`, and on a miss returned "do not withhold". Its docblock justified that with a premise about *versions*: on a WooCommerce older than the helper, upstream does not ask for verification either. **The two symbols have different `@since` versions and the premise was read off the wrong one** — the gate ships in WC 7.9.0, the helper only in 8.6.0 — so across 7.9.0-8.5.x upstream hides the order while the guard says "nothing to withhold", publishing the buyer's identity to an A0 visitor. The rule generalizes past this file:

- **A feature-availability guard has the same two-state problem as a falsy return**, and one extra: "absent because too old", "absent because moved", and "absent because it was never the same symbol as the feature". Only the first is safe to treat as "there is nothing here".
- **When you guard a delegation, compare the `@since` of the BEHAVIOR with the `@since` of the SYMBOL you delegate to.** If they differ, there is a version window where the behavior exists and the symbol does not, and that window is exactly where the guard is wrong. Record both versions in the coupling's registry row (`.upstream` U113 now does).
- **Prefer re-deriving the narrow, version-free part of the decision to guessing from a version number.** Measured on #173: a `WC_VERSION`-gated fail-closed broke the buyer reading their own order, because the guard sits *after* an earlier gate and "withhold" there withholds from everyone. A conservative local re-derivation was the answer at the time — but #183/#188 killed the comfort it was wrapped in, twice: "can only ever withhold more" was false against the oldest gated version, and a *partial* mirror still published where the full upstream decision hides, because upstream's domain was wider than the mirror's (the login gate's opt-out routes non-owners into the guest check). Conservatism is not a property a mirror gets by modelling less.
- **When the behaviour and a symbol shipped in the same upstream release, probe THAT symbol instead of any version.** #183's resolution: both order-received gates arrived in the release that added `guest_should_verify_email()` (verified at the tags on both sides), so one `method_exists` scopes the whole mirror — no version constant (UC-5), correct on both sides of the window, and `method_exists` sees private members and triggers the autoloader, so the probe needs no load-order luck. And mirror in BOTH directions: where the vendor renders, the page body is already showing the visitor everything, so withholding "to be safe" protects nothing and silently deletes data.
- **Litmus:** grep `class_exists(`, `method_exists(`, `function_exists(` used as a *gate on a security or privacy decision* (not merely on an optional enhancement). For each, ask what the else branch asserts about the world, and whether an unavailable symbol really implies it.

### RI-22: A lookup table with a fallback default asserts the default is right for everything unlisted — enumerate the categories, not the exceptions
A `TABLE[$key] ?? DEFAULT` is not a convenience. It is a **claim about every key that is not in
the table**, and it is the claim nobody checks, because the table is what got reviewed. The
table's entries are the exceptions somebody thought about; the default is the rule they assumed.

The failure is always the same: the domain has **three** categories and the code models **two**
— "listed" and "everything else" — so the third category silently inherits a rule written for
the second. It fails silently by construction, since the whole point of a default is that no
branch announces it was taken.

Confirmed 2026-08-11 (#145). The phone normalizer models national trunk prefixes as
"territories whose trunk prefix is not a single `0`" (a five-entry table) plus a `'0'` default,
and its docblock states the assumption in words: *"Every other territory in WooCommerce's
country list either uses `0` or has no trunk prefix at all, which is what the default covers."*
The two halves of that sentence are not the same thing. **Italy has no trunk prefix and its
leading `0` is part of the national significant number**, so stripping it produces a number that
looks well-formed, passes the length validator, and can never match. Google's own libphonenumber
FAQ states the general rule: *"If a country does not use a national prefix, or does not use one
anymore, we don't remove a leading zero."*

**Rules:**
- **Write the categories down and count them.** If the table has one column and the domain has
  three states, the model is already wrong. Here: uses `0` · uses something else · uses nothing
  *and keeps its leading zero*.
- **Read the docblock as a claim, not as documentation.** A sentence beginning "every other X
  either … or …" is a two-branch assertion about an open set. Ask which member of the open set
  makes it false; that member is usually famous within its own domain.
- **The default deserves the same test coverage as the entries.** #145's suite had a case per
  *listed* country plus one generic control, so 100% of the table was pinned and 0% of the
  assumption was. Add a case for a country the table deliberately omits.
- **This is the mirror rule (`.upstream` UD-1/UD-3) applied to a default.** A mirrored table gets
  a registry row; the *default beside it* is a second, unregistered mirror — of the claim that
  the table is complete. Register both, or state the default's rule in the row (U110 registered
  the five entries and inherited the wrong claim about the rest).
- **Litmus:** grep for `?? self::DEFAULT_`, `?? static::`, `TABLE[ … ] ?? '`, `array_key_exists(
  … ) ? … : ` where the else-branch is a constant. For each, name a real key that is not in the
  table and ask whether the default is *correct* for it — not merely *safe*.
- Related: **RI-19** (a falsy branch merging two states) is this rule for return values;
  **RI-20** (a key name only the vendor's docs can confirm) is it for names.

### RI-23: An early return added for a newly recognised case opts that case out of every branch below it

RI-22's sequel, and it is the shape RI-22's own fix took. When a two-category model grows a
third category, the third arrives as a **new branch near the top** of the function — and if that
branch `return`s, it silently removes its inputs from every test the other categories still get.
The new branch is reviewed for what it *does*; nobody reviews it for what it now *skips*,
because skipping is not written anywhere.

Confirmed 2026-08-11 (#153), inside the fix for #145 and one review after it. Replacing
"trunk prefix, or the `'0'` default" with a generated table carrying a real `null` gave the
no-trunk-prefix countries their own branch — correct for what it was written for (a leading zero
that must be kept) and returning **before** the "international form with the `+` left off" test
the other branch still reaches. So the same input spelling resolves correctly in Germany and
doubles the calling code in Spain, length-valid and silent. The pre-fix code, whose default sent
every country down the shared path, got Spain right.

**Rules:**
- **Diff the branches, not the values.** When a lookup gains a category, list the statements the
  old path ran *after* the point where the new path returns. Each one is a decision to re-take
  for the new category, not a decision inherited.
- **Count the rows that enter the new branch before calling it a special case.** #153 hid behind
  #156: the docblock introduced the branch with "Italy is the best-known case; six more behave
  the same way", so it read as a 7-row curiosity when 101 of 245 rows take it. **A branch's
  cardinality is a measurement, not an impression** (`.upstream` UD-13).
- **A test named for a general property must assert it across the categories.** The case named
  `…accepts_the_international_form_without_a_plus()` asserted one trunk-prefix country, so it
  pinned the half where the property holds and read as pinning all of it. When a function grows
  a category, every existing behavioural test is now a test of one category.
- **A generated oracle proves the table, never the parsing.** Corpus cases built as
  `national_prefix . example_number` only ever exercise the canonical national spelling; every
  *other* way a human types a number is outside it by construction. Say so where the corpus is
  defined, or the count (489 cases!) reads as coverage it does not have.
- **Litmus:** grep for a `return` inside a branch introduced by a `null`/`''`/`0` check on a
  lookup result, and for `if ( null === $x ) { return …; }` sitting above two or more further
  `if`s in the same function.
- Related: **RI-22** (the default that asserted too much), **RI-19** (a falsy return conflating
  two states), **RI-13** (omit rather than invent).

**How #153 was actually fixed, and why the first two proposals were both wrong (2026-08-12).**
The fix is worth recording because the finding's own recommendation was the last thing to be
corrected:

- **"Make the new branch symmetric with the old one"** — wrong. Letting every country try the
  international-form test cuts a real operator prefix off Italian mobiles in the `39x` ranges.
  It trades one country for another and reads as a fix because only one side was measured.
- **"Then it needs per-country possible lengths"** — wrong twice. Lengths do not separate
  Italy's two readings (both are possible Italian lengths), and the claim also contradicted a
  recorded decision that named lengths as the point to adopt the library instead.
- **What worked** was the discriminator neither proposal considered: the **shape**, as one
  `generalDesc` regex per territory, used with the upstream library's own two-sided rule.

Three rules generalize out of that, and the third is the one this entry exists for:

1. **When two readings of the same input are both well-formed, the discriminator is a property
   of the domain, not of the code.** Positional heuristics (`starts_with`, `>= 4`, a length
   bound) are proxies for "is this a real one of these", and proxies is all they are. Go and get
   the property.
2. **Copy the reference implementation's decision, not just its data.** libphonenumber's rule is
   two-sided — the whole string is *not* valid **and** the remainder *is* — and each half fixes a
   different country. Taking the data and inventing the rule reproduces the bug in a new place.
3. **A recommendation is a claim, and a `wontfix` is a cost estimate — both need the measurement
   a finding needs.** #153's first recommendation would have broken Italy; #151's `wontfix`
   rested on a cost that was off by two orders of magnitude (7.8 KB, not 1.9 MB) and on a
   requirement that was simply not the requirement. Both stood in a committed ledger, where the
   next reader inherits them with the authority of a review. **Measure the fix before writing it
   down, or write down that you have not.**

### RI-24: A release note is a disclosure, and "fixed" on this branch does not make it one ⭐
The checklist's disclosure rule says a changelog bullet must name the impact and the area and
stop. This entry exists because the rule is easiest to break in exactly the situation that
feels most like doing the right thing: **writing an honest, thorough release note for a
security fix you have just made.** The better the note explains *why* the fix was needed, the
more completely it describes an attack that is still live wherever the fix has not shipped.

Confirmed 2026-08-12 (#161). A bullet describing a data-exposure fix named the mechanism, the
trigger, the upstream version boundary that opened the gap and the time window it depends on.
A verifier reconstructed a working reproduction against the still-unfixed released line using
**only the four phrases in that bullet** — no repository access to the vulnerable code
needed. The commit was already pushed.

- **The test is not "is this branch fixed", it is "is every line users run fixed".** This repo
  develops 2.0 on `master` while `1.x` is what installs actually run. `git grep <symbol> 1.x`
  before writing the bullet, every time. A defect ported *from* 1.x is by default still live
  *in* 1.x.
- **Count the committed surfaces, not the file you were editing.** #161 reached three:
  `CHANGELOG.md`, its `readme.txt` mirror, and an `.upstream/` registry row added in the same
  commit — which sits three lines below that system's own copy of the disclosure rule. Any
  system whose job is to record *why* an upstream gate matters will reproduce the mechanism
  as a side effect of doing its job well.
- **Redact all mirrored copies together or none.** Trimming one file of a deliberate mirror
  produces a repository that advertises an unfixed issue in the file that ships while
  printing the mechanism in its sibling. Measured, that is worse than leaving both. The
  project's own precedent redacts both in lockstep.
- **No text edit retracts a pushed commit message**, which is usually the fullest description
  of all. So redaction is a reach-reduction measure with a known ceiling; **shipping the fix
  to the other line is the only step that removes the exposure.** Sequence that first and
  derive the text from it — and say so, rather than presenting redaction as an equal option.
- **Litmus:** for any bullet on a security-relevant change, strike out impact and area and
  read what is left. If a competent reader could build a reproduction from the remainder, it
  is too specific. Then check whether the remainder also exists in `readme.txt`, in any
  `.security`/`.upstream`/`.testing` registry row, and in the commit message.

### RI-21: An encoder that can fail returns a value PHP will happily concatenate — and `false` concatenates as `''` ⭐

RI-2 asks whether the encoder's output is *safe*. It never asks whether there **is** any
output. `wp_json_encode()` returns `false` for a value it cannot encode, and PHP renders
`false` as the empty string in concatenation, so a sink written as
`'var x = ' . wp_json_encode( $v, $f ) . ';'` emits `var x = ;` — **a `SyntaxError` that
takes down the entire `<script>` block, not the one value**. In a block that also carries the
data layer initialisation and the container snippet, one unencodable value from one
third-party filter silently removes all tracking from the page.

This is RI-17 read backwards (#120's rule — *a guard is only a guard for the steps after it*)
generalised past escapers: **every function that can return `false`/`null` and whose result is
concatenated into emitted code is in this class.** `esc_url()` returning `''` is the same
shape.

Confirmed 2026-08-10 (#141), and the reason it is ⭐ is not the severity — it is **Low, with
no site actor** — but the distance the class travelled unnoticed:

- #85 found it in 2026-08-04 and fixed it, correctly, in `ContainerCode::json_literal()`.
  That helper was left **`private` with one caller**, so the fix reached the global-vars
  builder and **nine other sinks kept the defect**, including the plugin's *primary* data
  layer sink nine lines away in the same file.
- The sibling that already guarded correctly (`DataLayer::wrapper_fragments()`, which tests
  `false === $encoded_arg` for the wrapper *arguments*) sits **three lines above** the push
  that did not. Sibling asymmetry, at the smallest scale this file has recorded it.

**Reachability, because it decides the rating.** `wp_json_encode()` runs
`_wp_json_sanity_check()`, which **repairs invalid UTF-8** — so no request-sourced string can
trigger this and **A0 cannot reach it**. What does: `INF`/`NAN`, a resource, a circular
reference, and nesting past the depth limit, all of which arrive through the public
`GTM4WP_WPFILTER_COMPILE_DATALAYER` / `_EEC_*` filters. Rate it on correctness, not security —
but rate the *blast radius*, which is the whole block rather than the value.

**Rules:**
- **Two shapes, and pick by whether the statement is optional.** Where the assignment must
  exist, route through `ScriptTag::json_literal()` (never returns `''`; falls back to the
  `null` literal, which every JS reader already handles as absent). Where the whole statement
  can be left out, call the encoder directly, test `false ===`, and **omit** — an absent key
  is honest where an invented `null` is not (RI-13's omit-don't-invent).
- **A failed encode must not be recorded as a success.** `PurchaseTracking` flagged
  `_ga_tracked` after emitting a `.push()` that pushed nothing, which suppresses that purchase
  **permanently**, on every later page view. When a sink writes a de-dupe marker, the marker
  belongs after the thing it marks actually succeeded.
- **A call-argument position fails differently from an assignment position, and more quietly.**
  `x.push()` is legal JavaScript — it pushes nothing and throws nothing, so the failure is a
  missing event rather than a broken page. Do not conclude "not a SyntaxError, therefore fine".
- **Litmus (re-derive, don't read):** grep `wp_json_encode` across `src/` and, **for each hit,
  ask where the result is USED — not whether the encode and the concatenation share a line.**
  This review's own ledger listed 9 sites and missed a 10th (`Axeptio::add_head_js()`) for
  exactly that reason: it assigns to `$axeptio_settings` on one line and concatenates it
  eleven lines later. That is PA-7's #74 lesson — the class survives inside a shape the grep
  does not select — applied to the reviewer's grep rather than the code.
- Attribute-context sites (`esc_attr( wp_json_encode( … ) )`) are **not** in this class here:
  `esc_attr( false )` yields `value=""`, and the JS reader wraps `JSON.parse` in a
  `try`/`catch` that returns `false`. Verified, not assumed — check the reader before
  dismissing one. Re-verified 2026-08-11: all 12 `JSON.parse` call sites in `js/frontend/`
  are inside a `try`/`catch`.
  - **The try/catch is only half the reader check (2026-08-14, #190).** A wrapped parse
    guards the *throw* path; the class also needs the *result* path guarded, twice over:
    the helper's `false` return must be checked by every **consumer** (grep the consumers,
    not the helper — the guarded-sibling family in `gtm4wp-woocommerce.js` had 8
    `gtm4wp_read_json_from_node` sites guarding and the one direct `gtm4wp_read_from_json`
    consumer not), and a parse that **succeeds with `null`** (`wp_json_encode( null )` is the
    string `'null'`, which a filter callback returning null produces) passes every catch and
    throws only at the first property access. "All parse sites are inside try/catch" was this
    entry's own recorded verdict and it was true while #190's two paths were live — a verdict
    about the wrapper says nothing about the consumer.
- **"Cannot fail" is a fine answer — write it down where the sink is.** The ledger reached
  **eleven** sites, not ten (#147): one more builds its literal in a separate statement, and
  its value is a `(string)`-cast scalar, so the encoder genuinely cannot fail on it. That is a
  correct exemption and it was still a finding, because nothing at the sink says so — while a
  sibling exemption two files away (`Notices.php`, a `wp_create_nonce()` result) carries a
  two-line comment naming the reason. **An undocumented exemption is indistinguishable from an
  oversight**, and the next reviewer pays for the difference every time. Prefer routing through
  the helper anyway: it costs nothing and needs no reader to re-derive the argument.

### RI-18: Sanitized is not the same as authentic — know which end of a request value the infrastructure guarantees
RI-6 asks whether a request value is *safe to handle*. It never asks whether the value is *what it claims to be*, and for anything the client can set outright those are different questions with different answers. A perfectly `wp_unslash`'d, `sanitize_text_field`'d, `FILTER_VALIDATE_IP`'d value can still be entirely attacker-chosen — validation constrains the *shape*, not the *provenance*.

Confirmed 2026-07-29 (#75): `VisitorIp::get()` reads a configurable forwarding header and scans it **left-to-right**, returning the first public address. Every proxy that appends rather than replaces (`nginx proxy_add_x_forwarded_for`, AWS ALB, Cloudflare) puts the address it observed on the **right**, after whatever the client sent — so the left-most entry is the one element of that list nobody vouches for. The value ships as the `visitorIP` dataLayer variable and through the public `gtm4wp_get_user_ip()` template function. Low here, because nothing in this repo makes an access decision on it — but an integrator who reuses the helper for rate limiting or an allow-list inherits the assumption, which is why the class is worth naming rather than waving through.

**The two header families fail differently, and fixing one leaves the other looking correct (2026-08-04, #75 fix).** The finding above is about *list ordering*, so the obvious fix is "parse from the right" — and that fix does nothing at all for the other half of the same function:

- **Append** (`X-Forwarded-For`): each hop appends what it observed, so the operator's proxies are on the right and client-supplied entries stay on the left. The client is the right-most entry **outside** the trusted set. Walk from the right and **stop** at the first untrusted entry — never keep scanning left for a "better looking" (public) address, because everything left of that point is exactly what the attacker controls. That search-onwards behaviour reintroduces the finding *through its own fix*.
- **Replace** (`CF-Connecting-IP`, `True-Client-IP`, `X-Real-IP`): the proxy overwrites the header, so the value is authoritative **only if the request actually arrived through that proxy**. There is no list and no ordering, so an ordering fix cannot help; the gate is `REMOTE_ADDR ∈ trusted proxies`. Delivered straight to the origin, the header is whatever the client typed.

One mechanism serves both — a **trusted-address allow-list**, not a hop count: it answers "where is the client in this list?" *and* "did this header come from my infrastructure at all?". **Litmus:** when a header-reading function branches on the header name, rate every branch. A finding reported against one branch is evidence about the *function*, not about that branch.

**A convenience helper can put the payload back into a decision you just moved to the transport (2026-08-04, #91).** Provenance is not only about *which* value you read but about *where the reader gets it from*. WordPress' `wp_get_raw_referer()` returns `$_REQUEST['_wp_http_referer']` **in preference to** `$_SERVER['HTTP_REFERER']` — correct for its actual job (restoring a form's return URL, where the parameter is the site's own hidden field) and wrong for an access decision, because a request parameter is supplied by the request being judged. Used as the fallback leg of the #78 same-origin gate, it meant a cross-site POST could name your own site as its referrer by appending a query argument.

- **Litmus:** for every value in an authorization decision, name the superglobal it ultimately comes from. `$_SERVER['HTTP_*']` is set by the transport; `$_REQUEST`/`$_GET`/`$_POST` are set by the caller. A helper that reads *both* is a helper for a different problem.
- **Then pick the sanitizer for the value's GRAMMAR, not for its being a string (2026-08-05, #106).** Having established *where* the value comes from, the next question is what happens to it on the way in. `sanitize_text_field()` silently strips every `%XX` sequence out of whatever it is handed — harmless for a label, wrong for a URL that is about to be parsed and compared. It did not change the verdict there (only host and port are compared, and removing characters cannot map a foreign host onto your own), and that is the point worth keeping: **a gate should not rest on a sanitizer that rewrites the value it is judging, even when today's comparison happens to be immune.** Use the sanitizer that matches the grammar (`esc_url_raw` for a URL), and when two sites read the same header with two different sanitizers, read the odd one — sibling asymmetry is the tell here as everywhere else.
  - **Test note:** this class is invisible under an identity stub. A fixture that aliases the sanitizer to `(string) $value` — the right default for every test that does not care — makes the two candidate functions indistinguishable while the line still shows as covered. A test that means to pin the choice must model the one behaviour that separates them (test-review TS-1; the same lesson as the `wp_kses` identity stub in #92).
- The same question applies to `wp_get_referer()`, `home_url()`-derived comparisons and anything else that looks like "WordPress' version of the thing I want" — read the helper before trusting it in a gate. This is the #78/#90 lesson (go and read the dependency) applied one level down: there, core's default *added* a grant; here, a core helper *widens an input*.

**Rules:**
- For any `$_SERVER['HTTP_*']` read, state in a comment which hop is authoritative and why: does the fronting infrastructure **append** (trust the right, skipping the trusted hops) or **replace** (trust the whole value, but only from a trusted peer)? A list with no trusted-proxy configuration has no authoritative element at all.
- **Keeping the old behavior is a legitimate default, but only with a signal.** Existing sites cannot be silently re-pointed at a different value, so an unconfigured trusted set may mean "read it the old way" — provided the admin is *told*, because both states look identical on a settings screen. Field description plus a non-dismissible notice (the #60 `hardcoded_errors()` → `Notices` shape) is the pattern; validation without a signal is half a fix.
- **Validate the allow-list with the reader's own predicate.** The option sanitizer and the runtime must share one function (here `VisitorIp::is_valid_range()`). An entry the sanitizer stores but the reader skips is worse than a rejected one: the admin believes that proxy is covered.
- Treat "the header is present and well-formed" as evidence of nothing. `FILTER_VALIDATE_IP` proves it is an IP, not that it is *this visitor's* IP.
- **A docblock asserting a security property is a claim to test, not a fact.** `VisitorIp`'s said the function was not spoofable, and the assertion outlived the code being true. This is PA-10's "a `__return_true` gate must be demonstrated, not accepted from a doc block", applied to comments generally: when a comment claims a guarantee, either find the code that enforces it or correct the comment.
- Where the value only feeds analytics, rate it on integrity (Low/Medium per the threat model). Where any caller could make an authorization decision on it, it is the authorization bug it looks like.

### RI-25: When you apply somebody else's filter, the ARGUMENT LIST is theirs too — and passing fewer is a fatal, not a degradation ⭐

Re-applying an upstream hook to mirror an upstream decision is a legitimate and much-used
technique here (`woocommerce_cart_item_product`, `the_permalink`, `woocommerce_thankyou_order_id`,
the two order-received gates). What travels with the hook **name** is the hook's **contract**,
and the half that gets forgotten is the argument list — because on a default site nothing
registers a callback, so the mistake is invisible until somebody uses the extension point the
way its own documentation describes.

`WP_Hook::apply_filters()` does not pad. Its dispatch is:

```php
if ( $the_['accepted_args'] >= $num_args ) { call_user_func_array( $cb, $args ); }        // as supplied
else                                       { call_user_func_array( $cb, array_slice( $args, 0, $n ) ); }
```

So a callback registered `add_filter( $hook, $cb, 10, 3 )` with three **required** parameters,
reached from a caller that supplied one, gets one argument and raises **`ArgumentCountError`** —
uncaught, fatal, on whatever page the call site sits on. It does not fall back to a default and
it does not warn.

Confirmed 2026-08-12 (#182, Medium). The order-received fallback applied
`woocommerce_order_email_verification_grace_period` with one argument; WooCommerce applies it
with three (`$grace_period, $order, $context`) in both homes the filter has had — the shortcode
on 8.0.0–8.5.x and `Users::should_user_verify_order_email()` from 8.6.0. A site callback written
to the documented signature therefore fatals the thank-you page. Found by the adjudication
stage as collateral while verifying an unrelated documentation claim about the same three lines.

**Rules:**
- **The direction is asymmetric, so there is a safe default: pass everything upstream passes.**
  Too few is a fatal; too many is free, because `WP_Hook` slices the list down to each
  callback's own `accepted_args`. Measured across all three shapes (3-arg/`accepted_args=3`,
  1-arg/`1`, 1-arg/`3`) — passing three is correct for every one of them.
- **Read the arity off the vendor's source at a tag, not off your memory of the hook.** This is
  RI-20 for argument lists: the only end of the contract in this repo is the call, and a wrong
  arity fails on somebody else's site, never here.
- **A mocked `apply_filters` cannot see this, and neither can a green suite.** Brain Monkey
  intercepts the hook, so a test whose stand-in callback declares *optional* parameters passes
  either way (UC-3). The discriminating test declares the parameters **required**, exactly as a
  documented site callback would — that reproduces the real `ArgumentCountError` and goes red on
  the unfixed source.
- **Litmus (re-derive, don't read):** `grep -rnoE "(apply_filters|do_action)\( '[a-z_]+'" src/ compat/ | grep -v gtm4wp`
  — every hook name **without** the `gtm4wp` prefix is somebody else's contract. Counting rule:
  matching **lines**, so a site is one member. **Ledger re-derived 2026-08-12 (R23 fix session):
  12 sites, all correct.** The twelfth is `woocommerce_order_email_verification_required`
  (**3** args upstream, 3 passed), added by `64abf41`'s gate rewrite — which applied this
  pattern correctly in the code and left the count at 11 **in two files**, the #132 shape
  ("a count kept in two of our own files where only one gets corrected") landing on the ledger
  written one review earlier to catch the previous instance. No defect hid behind it; the drift
  was the finding. Previously: **Ledger, 2026-08-12: 11 sites, 10 correct, 1
  wrong (#182) — and the wrong one was the newest.** For each, open the vendor's own
  `apply_filters` and compare the count:
  `wpml_current_language` 1 · `the_permalink` 2 (WP core passes `$permalink, $post`) ·
  `woocommerce_cart_item_product` 3 (×3 sites) · `woocommerce_widget_cart_item_visible` 3 ·
  `woocommerce_thankyou_order_id` 1 · `woocommerce_thankyou_order_key` 1 ·
  `woocommerce_order_received_verify_known_shoppers` 1 ·
  `woocommerce_order_email_verification_grace_period` **3** ·
  `woocommerce_order_email_verification_required` **3**.
- **The VALUE you pass is part of the contract too, and an approximated value has a
  callback-shape caveat (2026-08-12, #189, Low/docs).** When a re-applied filter is handed a
  *coarser* input than upstream computes (the order-received mirror passes literal `true`
  where upstream passes a value derived from request-identity terms it cannot model), the
  claim "this approximation can only fail closed" is a theorem **about monotone callbacks
  only** — provable by a four-shape truth table, and false for a strictly value-inverting
  callback (f(false)=true ∧ f(true)=false), which is the one shape that turns the coarser
  input into publish-where-upstream-hides. Passing the coarser value is usually still the
  right design (the alternative is modelling terms you cannot read); the rule is about the
  **prose**: never write an unqualified "never laxer" absolute over a filter you feed an
  approximated value — scope it to monotone callbacks, or enumerate the residual. Litmus:
  for each RI-25 ledger site, ask "do we pass exactly what upstream passes, or an
  approximation?" — every approximation needs its caveat stated at the call site.
- Related: **UC-2** (`Internal` namespaces carry no compatibility promise) covers the *symbol*;
  this covers the *signature*. **RI-19** is the guard around the call; this is the call itself.

---

## Project-Specific Anti-Patterns

### PA-1: Admin/AJAX/REST mutation without nonce AND capability ⭐
Every admin form submit, `wp_ajax_*` handler, and REST route that changes state must verify a nonce (`check_admin_referer()` / `wp_verify_nonce()` / REST `permission_callback`) **and** a capability (`current_user_can('manage_options')` or narrower). A nonce alone (no authorization) or a capability alone (no CSRF token) is a finding. Example reviewed: `src/Admin/Notices.php` dismiss handler. **Exception (guest frontend mutations):** a *frontend* route acting on an anonymous visitor may substitute strict session-ownership-scoping for the capability check under the **FP-5** conditions — do not force a capability gate that would break guest checkout.

### PA-2: Trusting stored options at the output sink
Container IDs, custom domains, and custom loader paths come from options an admin set, but must still be validated at the point they are written into markup — `GTM_ID_PATTERN` for IDs, `filter_var($d, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)` for domains, a `^[a-zA-Z0-9.\-_/]+$` allow-list for paths (see `ContainerCode::container_domain()/container_path()`). Never `echo` a stored option into a `<script src>` or loader URL unvalidated.

**Cover every column, and every entry point into it (R9, #60/#61).** Partial application of this rule is the recurring shape:
- **Per column.** `ContainerRows` defines four patterns, but only `GTM_ID_PATTERN` (plus an ad-hoc domain/path check) is applied at the output sink; `AUTH_PATTERN`/`PREVIEW_PATTERN` are enforced *only* on save, so `container_environment()` trusts whatever is stored. If a column has a pattern, the sink applies it.
- **Per entry point.** A column reaches the option row from more than one direction — the admin save path, `from_legacy()` migration of a 1.x value, and the `GTM4WP_HARDCODED_*` wp-config constants. Only the save path validates, and among the constants only the container ID is checked while the two environment constants are trusted verbatim. Enumerate every writer before concluding a value is validated.
- **Use the constant, don't retype the regex.** `container_path()` re-implements `PATH_PATTERN` inline and the two copies have already diverged (`*$` vs `+$`). A duplicated security allow-list is a drift bug waiting for the next tightening.
- Severity stays Low here — the actor is A4/the server operator and `esc_attr` + `wp_kses` leave no break-out — but note the multisite `unfiltered_html` caveat before leaning on "an admin can already do this."

**Share the predicate, don't copy the pattern — and watch for the copy made by the fix itself (2026-08-04, #94/#95).** The "every entry point" rule above is usually satisfied by adding the same check at the second site, which leaves two literals that must be kept in step by hand. This has now produced two findings on one option pair, and the tell is that **the same fix session got it right once and wrong once, three files apart**: the trusted-proxy list was given a public `VisitorIp::is_valid_range()` shared by the sanitizer and the reader, with a docblock explaining that a stored entry the reader skips is worse than a rejected one — while the header-name allow-list beside it got a second copy of its regex plus a comment calling the other one "identical".
- **Litmus:** when a validated value has a save side and a read side, the two must call **one function**, not run one rule twice. A comment asserting that another site does the same thing is the shape to grep for; it is a promise, not a mechanism.
- **The copy can be MORE correct than the original, and that is still the finding (2026-08-10, #123).** Four months after #114 consolidated two copies of the JavaScript-identifier rule into one predicate, a **third** appeared in another class — carrying the `/D` end-anchor the shared constant lacked, with a docblock arguing that anchor is what makes the guarantee true. So the drift was not "the copy is stale": the copy was right and the shared one, used by the option sanitizer and every frontend reader, was the weaker of the two. Two consequences: **a duplicate is a finding on sight, without diffing the behaviour** (the diff here would have argued *for* keeping it), and when you delete one, **move its reasoning onto the survivor** rather than discarding it with the code. Nothing was reachable through the gap — `trim()` and `sanitize_text_field()` close it on the only live path — which is exactly how a duplicated allow-list stays quietly wrong.
- **Test the agreement, not the list.** A test that asserts the sanitizer rejects a hardcoded set of inputs passes forever after the reader tightens. Assert `sanitizer( $x ) === reader_predicate( $x )` across a set of inputs, so the two ends cannot drift silently.
- **An allow-list can also contain its own off switch.** `is_valid_range()` accepted a `/0` prefix, which declares every address trusted and restores the exact non-authenticity the option exists to remove — and because the admin notice keys on the list being **non-empty**, filling it with `0.0.0.0/0` also switched off the warning. Ask of any operator-supplied allow-list: *is there a value that makes this match everything, and does the UI still say the protection is on?*

**An allow-list must match the GRAMMAR of the sink, not merely constrain the value
(2026-08-05, #114).** Everything above asks whether a validated value is *re-validated* at
the sink and whether *every* writer goes through the check. The failure this misses is a
rule that is applied everywhere it should be and is simply **the wrong shape**. The
data-layer variable-name option is validated on save, once, by a regex written for the
option — and the value is then emitted as a **bare JavaScript identifier** at a dozen
sinks, so what the allow-list has to encode is the identifier grammar. It does not: it
admits a character JavaScript reads as an operator and rejects one JavaScript allows.
There is no escaping fix available (`esc_js()` does not encode the offending character,
and cannot — the sink needs a bare identifier, so any encoding would break it), which is
what makes the allow-list the entire control.
- **The tell is a second rule for the same grammar, elsewhere in the same codebase.**
  `ContainerCode::header_top()` validates third-party global-variable names against the
  correct identifier pattern, with a comment saying in as many words that `esc_js()` does
  not validate identifiers. Two rules for one grammar, one right and one wrong, ten files
  apart — the *share the predicate* rule above, in the case where nobody noticed the two
  sites were about the same thing.
- **Litmus:** for every option whose value is emitted **unquoted** into a `<script>` (an
  identifier, a property path, a bare number), write down the grammar the sink requires
  and diff the sanitizer against it in both directions — *what does it admit that the
  grammar forbids*, and *what does it reject that the grammar allows*. A value inside
  quotes is an RI-2/RI-4 question; a value outside them is this one, and escaping plays
  no part in it.
- Rate on the actor: an option is A4-set, so this is normally **not** a security finding
  (threat model, A4 → A4). Rate it on what breaks and how visibly — a bare-identifier sink
  fails as a `SyntaxError` that takes the whole `<script>` block with it, silently.

### PA-3: New request/header-sourced dataLayer field inherits the XSS class ⭐
Any new dataLayer key whose value comes from `?s=`, `HTTP_REFERER`, `HTTP_CF_IPCOUNTRY`, a cookie, or any request input is reflected/stored XSS surface. It must flow through the hex-flag `wp_json_encode` path (RI-2) and must not rely on `esc_js`/`esc_attr` alone (RI-4). Known sinks: `siteSearchTerm`, `siteSearchFrom`, `geoCloudflareCountryCode` in `PageVariablesModule`.

### PA-4: `esc_js()` in a raw `<script>` body
`esc_js()` is designed for values inside HTML attributes (`onclick="…"`) where the HTML parser decodes entities. Inside a raw `<script>` element the browser does not decode, so `esc_js`'s `&quot;` is both wrong (displays literally) and — with any decode step — unsafe. Use `wp_json_encode` with hex flags for values embedded in inline scripts. Flag new `esc_js( … )` used to build a `<script>` body string.

### PA-5: Option handling bypasses the schema
Options are read via `Options::get()` with `GTM4WP_OPTION_*` constants and sanitized when saved through the module's `AdminSchema`. Flag direct `get_option()`/`update_option()` on GTM4WP option keys that bypasses the schema, or a new option persisted without a sanitizer.

### PA-6: Module wiring outside the framework
New features register through the `src/Module/` framework (implement the module interface, register in the `Registry`, declare an `AdminSchema`). Ad-hoc `add_action`/`add_filter` scattered in the bootstrap for a feature that should be a module is a drift signal.

### PA-7: Data-bearing string as a `preg_replace` *replacement* argument ⭐
The WooCommerce product-list markup injects `data-…` attributes into template HTML by using a built (data-bearing) string as the **replacement** argument of `preg_replace`. That replacement interprets `$0`/`$1`/`${1}`/`\1`, so a product field containing a `$`+digit or `\`+digit sequence is substituted with **the matched text**. Fix by using `preg_replace_callback`, `addcslashes( $replacement, '\\$' )`, or — where the needle is a literal — no regex at all (`strpos()` + `substr_replace()`). Findings #16, #74.

**Severity depends on what the MATCHED text contains — ask that, don't inherit a verdict (2026-07-29, #74).** This entry read "*not XSS — the injected value is `esc_attr`'d — but a correctness bug*" from 2026-07-10 until #74. That verdict was derived at one call site and then frozen as a property of the class, and it is only true where the match carries no quote:
- match carries **no quote** → the expansion injects markup-inert text → **correctness bug**, as originally written.
- match **contains a quote** → the expansion lands that quote *inside* an attribute value the caller already `esc_attr`'d → the attribute terminates and what follows parses as markup. That is **XSS**, and the actor is whoever can supply the field.

Which call sites that applied to, and which actors reached them, is in the local scan report — not here: the 1.x line carries the same helper, so this stays a class description until that line is patched too.

The escaping is not at fault and cannot help: `esc_attr()` runs on the JSON *before* `preg_replace` puts the quote there. This is the RI-17 shape — see it for the general rule.

**Grep the wrappers, not the module (the #74 lesson).** #16 fixed one site and this entry recorded "the two block product-list injectors"; Review 5 re-confirmed that count without re-deriving it. There were **four** data-bearing replacement sites, and the two unguarded ones reached `preg_replace` through the helper `Helpers::str_replace_first()`, so a `grep preg_replace` over `ListTracking.php` returned exactly the two *fixed* sites — each with a reassuring comment — and no signal at all for the other two. The class survived eight reviews inside a wrapper one file away. **Litmus:** grep `preg_replace` across `src/` **and** every helper that forwards to it, then for each call ask (1) does the replacement carry data, and (2) what is in the matched text?

**Call-site ledger (re-derive, don't read).** As of 2026-07-29: `ListTracking.php:610` (`addcslashes`), `:650` (`preg_replace_callback`), and `:108` / `:187` via `Helpers::str_replace_first()`, which no longer uses a regex at all. Regression tests: `HelpersTest::test_str_replace_first_treats_backreference_sequences_in_the_replacement_literally`, `ListTrackingTest::test_cart_item_remove_link_filter_keeps_backreference_sequences_out_of_the_attribute`.

### PA-8: `wc_enqueue_js()` is deprecated (WC 10.4) — don't reintroduce it
WooCommerce deprecated `wc_enqueue_js()` in 10.4 (removal in a future version): it always wrapped the injected JS in a jQuery `ready()` handler even when jQuery wasn't needed. New WooCommerce-facing code that must emit inline JS uses the WordPress-core path instead — register/enqueue a handle, then `wp_add_inline_script( $handle, $code, 'before'|'after' )`. The plugin's only call — the checkout `window.gtm4wp_checkout_*` globals in `PageDataLayer::add_begin_checkout()` — was migrated to `wp_add_inline_script( 'gtm4wp-woocommerce', …, 'before' )` on 2026-07-13. It was ALSO a raw-`<script>` sink, so remember: whichever inline-script API you use, JSON embedded in the body still needs the full RI-2 hex flags. Guard test: `PageDataLayerTest::test_checkout_adds_hex_encoded_products_inline_and_fires_begin_checkout` asserts `wc_enqueue_js()` is never called.

---

### PA-9: Embedded-media tracker template conventions
The `js/frontend/gtm4wp-*.js` media trackers form a family with a shared contract; a new one (or a modification) must keep to it.

> **The double-init half applies to EVERY bundle, not just the media family (2026-07-28, #71).** The guard was introduced for media trackers (#22) and then the CF7 tracker (#28), and both times it was written as a media-tracker rule — so the two bundles that attach `document`-level listeners without being media trackers (`gtm4wp-woocommerce.js`, `gtm4wp-form-move-tracker.js`) were never brought along, and a re-injected bundle double-pushes every ecommerce/form event. Generalize the rule with the fix, or the next sibling is left behind too.
>
> **The trigger is "does something observable on load", not "attaches a listener" — and the guard must be the FIRST thing in the module (2026-08-04, #82/#83).** #71's own litmus was written as *grep for `addEventListener(` at module scope*, and one review later that litmus was the thing that missed two siblings and mis-placed a third. Both failures are worth keeping, because they are different:
> - **#83 — the litmus under-selects.** `gtm4wp-visitor-data.js` and `gtm4wp-client-device-data.js` have **no** module-scope `addEventListener` (they push, `fetch`, and attach a `MutationObserver` from a bare IIFE), so the grep returns nothing for them while a re-injected bundle re-pushes, re-fetches, and leaves a *second permanent* observer on `document.body`. A `fetch`, a `push`, an observer and a listener are the same class; only one of them is an `addEventListener`. (Since the visitor runtime split its delivery into three per-family data layer events, the blast radius of that second observer is **two** duplicated events per cart change rather than one — the guard is unchanged, but a regression test that counts only one event name would no longer cover the failure it was written for.)
> - **#82 — the guard is present and in the wrong place.** `gtm4wp-woocommerce.js` guards inside its boot function, **below** three module-scope state initializers (`gtm4wp_checkout_step_fired = []`, …). The listeners are not re-attached — and the first instance's listeners then read state the second instance has just wiped, so the de-dupe arrays reset and the events double-fire anyway. A guard only protects what is written after it.
>
> **Litmus (replaces the one above):** for every `js/frontend/*.js`, read the module body top to bottom and ask *does anything here run at import time?* — a listener, a `push`, a `fetch`, an observer, **or a state initializer another instance's handlers will read**. If yes, the file needs `window.gtm4wp_<name>_inited` as its **first statement**, above all of it. The reference implementations are `gtm4wp-contact-form-7-tracker.js` (first statement of the IIFE) and `gtm4wp-form-move-tracker.js` (wraps the whole module body); `gtm4wp-woocommerce.js` is the counter-example. A green suite proves nothing here — no fixture loads a bundle twice.
- **Origin validation (security):** if the tracker attaches a raw `window.addEventListener('message', …)` listener, it MUST validate `event.origin` against a host allow-list (parse `new URL(origin).host`, allow-list exact host + known subdomain suffixes) before trusting `event.data`, and guard the `JSON.parse`. `gtm4wp-videopress.js` is the reference. Trackers driven by a vendor SDK callback (`controller.addListener`, `player.addEventListener`, `_wq` ready queue) are exempt — the SDK gates origin.
- **Sink:** push only to `window[gtm4wp_datalayer_name]` (a JS object push). No tracker builds HTML/JS — no `innerHTML`/`eval`/`document.write`/`element.src = <data>`. Escaping any value a downstream GTM tag writes to the DOM is the GTM tag's job, not the tracker's, so SDK/iframe-`src`-sourced strings are pushed raw (correct).
- **Provenance:** parse only the embed iframe's own `src` (`new URL(frame.getAttribute('src'), location.href)` — `location.href` is only the resolution base). Never read `location.search`/`hash`/`document.referrer`/`document.cookie`.
- **Consistency:** guard `if ( ! duration ) return;` before any percentage division (avoids `NaN`/`Infinity`, matches siblings); guard against double-init (remove-before-rebind, or a `window.gtm4wp_<provider>_inited` flag) so a re-injected bundle does not double-push. Keep any cross-injection counter (`gtm4wp-twitch-<n>` container ids) on `window`, not module scope, so a re-executed bundle does not restart it and collide (#41).
- **A tracker that DESTROYS the embed before rebuilding it owes a restore on every failure path, synchronous ones included (2026-08-10, #127).** Most trackers wrap what is there; two (Dailymotion, Twitch) replace the embed with a container the SDK fills, which means that from the `replaceChild` onward a *tracking* failure is a *content* failure — the visitor is left with an empty box where their video was. Dailymotion's restore lived only in the promise `.catch`, and **a promise rejection handler cannot see a synchronous throw**, so `createPlayer()` throwing rather than rejecting skipped it entirely. Put the failure handling in one function and reach it from both `try { … } catch` and `.catch`, so the two are indistinguishable from outside. Litmus: for any wiring that mutates the DOM before calling into a vendor, ask *what puts this back if the very next line throws?*
- **One tracker's failure must not cost another's (same finding).** `wireOnce` calls the tracker callback from inside a `forEach` over every match and, with runtime tracking on, from inside the shared `MutationObserver` across every registered scanner — so an exception escaping one callback abandoned the remaining embeds and the remaining **providers** for that pass. The shared helper now contains the blast radius with a `try`/`catch`; each tracker stays responsible for its own cleanup. This is the cost of the shared-observer design and is worth re-checking whenever that helper gains a new fan-out.
- **SDK that replaces the element (dynamic-insertion guard):** the shared `MutationObserver` (`gtm4wpObserveMedia`) marks the wired element with `data-gtm4wp-media-wired` and skips anything already marked (or inside a marked ancestor). But some SDKs — Spotify's `createController()` — *replace* the handed element with their own iframe, which carries no marker and matches the same selector, so the observer re-wires it → the SDK replaces it again → an unbounded loop that hangs the tab (#40). `wireOnce` therefore re-marks whatever takes a replaced element's DOM slot. **Rule: never assume the element handed to a wire callback survives the call**; a new media tracker whose SDK swaps the element relies on this re-mark (Dailymotion #45 is covered by it too). The failure is invisible to a test whose SDK fake leaves the element in place — the fake must perform the real `replaceChild` (see test-review TS-13).

### PA-10: Record ownership — where does the id come from? ⭐
The positive counterpart to **FP-5**. FP-5 tells you what *not* to flag on one blessed route; this tells you what to *check* on the next one. As of 2.0 the plugin registers REST routes that resolve real records (orders, sessions, users), so this is live surface, not theory.

**The rule:** for any route/handler that loads a record, trace where the **id** comes from.
- **Id from the server-side session** (`WC()->session`, `wp_get_current_user()`, a PHP session key) → safe by construction: a caller can only ever reach its own record. This is what makes `PageDataLayer::confirm_pending_purchase_tracked()` sound — the order id comes solely from the WC session (`ProductData::PENDING_PURCHASE_SESSION_KEY`), the request body is deliberately **not** read, and the marker is consumed for at-most-once.
- **Id from the request** (body, query, cookie, header) → **must** be ownership-checked before the record is read or written. No check = IDOR. Severity by the threat model: A1 → another A1's order data is High; A0 → any customer's PII is High/Critical.

**A control that protects a NAMESPACE must not be owned by a FEATURE (2026-08-04, #97).** PA-10 and FP-5 both reason per route: who reaches it, whose data, which gate. A REST *namespace* also has properties that belong to none of its routes — chiefly its cross-origin posture — and those get registered from wherever the developer happened to be working. The #90 CORS withdrawal was added inside `VisitorDataEndpoint::register_routes()`, so it only ran when the cache-safe data layer option was on, while the admin settings routes put `gtm4wp/v2` on **every** install. Not exploitable there (those routes need a nonce, so a cross-origin read 401s), but the shape is the finding: the *next* route added to the namespace inherits core's reflected grant with nothing to say so.

- **Litmus:** for every namespace-wide control (a `rest_pre_serve_request` filter, a `rest_authentication_errors` callback, a shared `permission_callback`), ask *what has to be true for this to be registered at all?* If the answer names a feature flag, an option, or a module's availability, and the namespace can exist without it, that is the finding.
- **A namespace contains routes you never registered, so "every route" is not the list you wrote (2026-08-05, #102).** WordPress auto-registers an **index** route for each namespace the first time it sees one (`WP_REST_Server::register_route()` adds `'/' . $namespace` → `get_namespace_index`). A control matching on the `namespace/` **prefix** therefore covers every route the plugin declares and misses the one WordPress added — and it reads as complete, because the thing it misses is in no route table, no inventory row and no `register_rest_route()` call. Match the bare namespace **and** the prefix. Keep the trailing slash in the prefix leg (without it, a future `v22` namespace is swept in by a plain `strpos`), which is exactly the precision measure that caused the gap: a comment explaining *why* the trailing slash is required is the shape to re-read, because it justifies the strictness without asking what the strictness excludes.
  - The same question generalizes past REST: whenever a control is scoped by a **prefix, path or pattern**, ask *what does the framework itself add inside that scope?* Auto-registered index/OPTIONS/batch endpoints, framework-generated routes and default handlers all live inside your namespace without appearing in your code.
- Register such a control at plugin level (here `Plugin::boot()` → `RestCors::register()`), and keep **one definition** of the namespace string that every route owner points at — a second spelling means routes silently outside the policy (RI-14).
- **Give the control its own row in the Public Surface Inventory.** The table's shape is "route → gate", so a thing that *is* the gate for several rows has nowhere to be written down, and its registration condition goes unrecorded. That is why this survived: every affected row was marked reviewed.

**Corollaries:**
- **A `__return_true` gate is a claim that must be demonstrated, not accepted.** A public route is fine when every field it returns is derived from the current request context and each resolver enforces its own identity gate — but *read the callback and every resolver* to confirm it. A doc block asserting "there is no IDOR" is the author's hypothesis; the review's job is to test it. `VisitorDataEndpoint` was the live example (R7-verified: gates sound, but it was mutating state — see next bullet).
- **Ask the threat model's two questions and record the answers** in the Public Surface Inventory: who can reach it, and whose data does it return? A surface where (2) sits above (1) is the finding.
- **A read-only GET changes no state, so it has no CSRF surface** — a nonce there is for *authenticating* the caller's cookie (so their own user fields resolve), not for authorizing them. Do not flag a public read-only GET for "missing" CSRF protection; do flag it if the data it returns is not strictly request-scoped.
- **"Read-only" means no writes to *any* server state — session, cookie, transient, meta — not just the obvious resource (#34).** The author correctly kept the public GET from writing `_ga_tracked` order meta, but it still *consumed* WC session markers while resolving, so a cross-site top-level navigation (which sends `SameSite=Lax` cookies) could fire the GET and destroy a real visitor's pending event. When a route's safety rests on "it's a read-only GET", grep its callback for every `->set(`/`setcookie(`/`update_*`/`delete_*` and confirm there are none; move any state change to an authenticated POST companion (the `confirm-*-tracked` beacons). A GET that mutates session state is an unauthenticated state change (PA-1 / FP-5 cond. 3), rated by the lowest actor who can trigger the navigation.

### PA-11: WooCommerce does not initialize its session/cart on a custom REST route
`WooCommerce::init()` calls `initialize_session()` only when `is_request( 'frontend' )` is true, and that check ends in `&& ! $this->is_rest_api_request()`. So on any custom `/wp-json/` route **`WC()->session` and `WC()->cart` are null** unless you load them yourself. Code that reads them defensively (`if ( ! $woo->session ) return;`) then *silently no-ops* on REST — the trap in #33, where the cache-safe one-shot resolvers ran on the session endpoint, found no session, and returned nothing (and the events were also omitted from the HTML, so they were lost outright). **Rule: a WC-touching REST callback that needs the session/cart must call `wc_load_cart()`** (WooCommerce's own remedy — its Store API does this per request), guarded by `did_action( 'before_woocommerce_init' )`; gate the load on a cheap precondition (a request cookie) so an unrelated anonymous request does not pay a session+cart DB load and get handed a session cookie that defeats page caching. Unit tests that stub `WC()` wholesale cannot see this (test-review TS-13) — verify against a live install.

**The cheap-precondition half applies to the ENQUEUE side too, and that is where it was left behind (2026-08-10, #124).** The rule above is written about a REST callback, so it was read as a REST rule. A second delivery channel — `wp_enqueue_script( 'wc-cart-fragments' )` on every front-end page — reproduced the same cost through a different door: WooCommerce's cart-fragments script has **no empty-cart bail-out** (verified in its own source), so an ungated enqueue makes every visitor of the store pay an uncached `wc-ajax` round trip per browser tab, including one who has never touched the shop. WooCommerce itself stopped enqueuing that script on all routes in **7.8** for exactly this reason and now recommends the Mini-Cart block instead.
- **Gate the delivering half, never the reading half.** Our own runtime is first-party and cheap; gating it too would have delayed the first delivery. WooCommerce's `wc-add-to-cart` script splices our fragment in from the add-to-cart response, so the very first `cartData` still fires on the page where the visitor acts, with no cart-fragments script involved.
- **A cookie-PRESENCE predicate, never a value read and never a cart load.** `WC()->cart` at `wp_enqueue_scripts` performs the exact session+cart load the gate exists to avoid — a fix that defeats itself. The predicate is login state, the cart cookie, or a `wp_woocommerce_session_` prefix match.
- **Logged-in has to count on its own**, or the gate silently drops `customerData` for a logged-in customer with an empty cart — the visitor the feature matters most for.
- **The method lesson: the strongest evidence was already in this repository.** The finding was raised from this entry, corroborated against WooCommerce's published guidance and two comparable plugins (neither the official `woocommerce-google-analytics-integration` nor GTM Kit touches cart fragments at all), and then settled by `PageDataLayer::oneshot_wc()`, whose docblock states the consequence in as many words 400 lines from the code that did not follow it. **When a new channel duplicates an old one, read the old one's comments before reasoning from scratch.**

### PA-13: Field descriptions and module intros are an HTML sink (RawHTML / dangerouslySetInnerHTML)
The React settings app renders `field.description` (`FieldControl.js`) and the module intro (`ModulePanel.js`) as HTML. This has been true since the Phase-4 React UI. **Two spellings, and the grep for each misses the other:** the module intro uses `@wordpress/element`'s `RawHTML` (which is `dangerouslySetInnerHTML` under the hood, so a literal `dangerouslySetInnerHTML` grep returned 0 hits and was **misleading** — Review 6 recorded that claim; corrected in Review 8), while `FieldControl`'s description now calls `dangerouslySetInnerHTML` on a `<span>` directly, because `RawHTML` always renders a `<div>` and a control's `help` slot is a `<p>` (2026-08-09). Consequences:
- A description may contain limited safe HTML by design (`<br />`, `<strong>`, links) — that is the feature, not a bug.
- **Any dynamic value interpolated into a description must be escaped server-side** (`esc_html()`), even when its source looks constrained — the environment-type readout in `Container/AdminSchema::production_only_description()` is the reference (core-whitelisted value, escaped anyway).
- The sink is A4-only (admin settings screen) and descriptions travel via the hex-flagged bootstrap JSON, so today's exposure is nil — but a future description embedding an option *value*, request data, or third-party API data would be self-XSS-plus (stored, multisite caveat). Flag it at generation time.
- Never re-assert "no innerHTML sink in js/admin" from a `dangerouslySetInnerHTML` grep; grep for `RawHTML` too — and vice versa now that both spellings are in the tree.
- A description is rendered in **phrasing content** (a `<span>` inside the control's `<p>` help slot), so its safe HTML is limited to inline elements: `<br />`, `<code>`, `<strong>`, `<a>`. A block element there is invalid HTML the browser silently reflows. `js/admin/test/field-control.test.js` pins this, and the `@wordpress/components` stand-in reproduces the `<p>` wrapper so React's nesting error fails the suite.

### PA-12: Do not bake a time-limited nonce into cacheable HTML
A `wp_create_nonce()` value has a ≤24h lifetime (`wp_nonce_tick`). Baking it into full-page-cached HTML (via `wp_add_inline_script` / a `wp_localize_script` config) means a cached page served after the tick carries a **stale** nonce (#35). For an *anonymous* caller WP core's `rest_cookie_check_errors()` does not early-return, so a stale nonce **403s the request before `permission_callback` runs** — silently breaking whatever the nonce guarded (here the Tier-2 read + the confirm beacon), and worst on exactly the long-TTL cached sites the feature targets. **Rules:** (a) an anonymous read that needs no authentication sends **no** nonce (WP treats no-nonce as `wp_set_current_user(0)` and proceeds); (b) when a fresh nonce *is* needed for a later authenticated call, have the endpoint **return** one in its (uncached) response body rather than reusing the baked one; (c) a nonce baked for a *logged-in* user is fine — their page is never full-page cached. The nonce itself leaks nothing (anonymous nonces are identical per tick, uid 0) — this is a robustness class, not exposure.

### PA-14: The repo's own toolchain is attack surface, and it has no Coverage Matrix row by default
Every pattern above rates a risk to the *site*. The maintainer's workstation is a second target, and since the triage workflows began ingesting third-party text — wp.org forum topics, GitHub issue bodies and comments — the path from an attacker's keyboard to code executing locally runs entirely through files the plugin review never looked at. `/code-review`'s inventory step greps `src/` and `js/`; the Coverage Matrix ends at Tests. So `.claude/`, `.githooks/`, `.github/workflows/` and `tools/` were not `[ ]` — they were **absent**, which the checklist's own warning says is the worse state. Findings #76 and #77 (2026-07-29) are the first two; both were found by an outside scan, not by this system.

The four shapes worth checking, none of which a runtime lens prompts for:
- **Pre-approved tool permissions.** An allowlist entry is an *enforced* boundary; a skill's prose description of what it will do is not. Where the two disagree, only the allowlist counts. A wildcard that admits state-changing verbs turns any successful injection into an unattended write under the maintainer's credentials.
**The fix shape, with the trap in it (2026-08-12, #77).** When something must execute a
script the repository owns, do not choose between "run the worktree copy" (unsafe) and
"keep a second copy outside" (drifts, UC-6). **Read it from a fixed ref**: an entry point
outside the tree that materialises `git show <ref>:<path>` to a temp file and execs that.
One definition, still versioned and reviewed, and a branch supplies neither half.

Three properties, each of which cost a measurement to establish:

- **Fail closed.** `bash <(git show "$REF:$SRC")` reads beautifully and **fails open**: an
  unresolvable path yields an empty script, `bash` runs nothing, exits 0, and the operation
  proceeds with no enforcement at all. Materialise first, test the file is non-empty, exit
  non-zero otherwise. *A guard that turns a hostile-code path into a no-enforcement path is
  not a fix.*
- **`exec`, don't pipe.** `git show … | bash -s -- stop` consumes stdin, which silently
  breaks any hook that reads a JSON payload from there.
- **Do not make the installer worktree-supplied.** Wiring the migration into a
  `package.json` `prepare` script is circular — that script comes from the branch too. Local
  config protects one clone and propagates to none; say so rather than implying coverage.
- **Prefer a fix that changes no tracked file.** An earlier attempt at this same finding was
  declined for blocking every commit until an installer had run. Enforcement that lands in
  *local config* breaks nobody who keeps the old setup.

- **Anything that executes a repo-relative path — a hook is only the most obvious case.** A body resolved from the working tree is supplied by whatever branch is checked out, so reviewing a contributor's branch can run that contributor's code. Anything reached from `core.hooksPath` or an agent Stop/SessionStart hook is in this class (#77) — **and so is any pre-approved command that is a *dispatcher* into worktree-supplied code** (#81, 2026-08-04). `npm run <script>` executes what the branch's `package.json` defines; a build CLI executes the branch's `webpack.config.js` as JavaScript; a test runner executes the branch's config and setup files. Two consequences worth stating because both are easy to get wrong: (a) the grep for this class is not "find the hooks" — ask of **every** allowlist entry *whose file does this ultimately run?*; (b) **pinning the command name does not close it** — `Bash(npm run build)` is narrower than `Bash(npm run *)` and still executes an arbitrary branch-supplied body. Only isolation (a container, or not reviewing untrusted branches in a session holding pre-approved execution permissions) actually closes it.

  **A tool CONFIG is the same sink, and it does not look like one (2026-08-05, #101).** The examples above are all files a developer already thinks of as code. The everyday case is a *config* file — a linter, formatter, test runner or build tool's own settings — which reads as inert data right up until you notice the format can name code to load: an autoload/bootstrap/plugin/extension/custom-rule entry, a `require` path, a preset resolved from the project directory. Feed the tool a config resolved from the working tree and a checked-out branch chooses what that tool executes. Three parts to keep:
  - **The trigger is often something else's improvement.** #101 arrived as "block commits that violate the coding standard" — a change with no security dimension anyone would look for, which added an automatic, per-commit execution of a branch-supplied config. Review a new hook or CI step by asking *what files does this hand to an executable*, not *is this change security-relevant*.
  - **Most such tools resolve their config from the current directory by default**, so a command that names no config file is still using the branch's. Passing no `--config`/`--standard` is not neutrality; it is a lookup.
  - **Removing the automatic trigger is a real fix and a partial one.** The same config is still read by manual runs and by CI. Rate the residual rather than closing the finding: CI is usually contained (fork PRs get a read-only token, no secrets, ephemeral runner); the maintainer's own terminal is not.
  - **A watch-list built from *discovered* config names misses the ones a command *passes* (2026-08-11, #148).** #109 fixed the discovery half — enumerate every name a tool auto-resolves, dotfile variants first. The other half is a config the tool would never find on its own because a documented script hands it over explicitly: `phpunit -c phpunit-network.xml`, `phpcs --standard=…`, `eslint --config …`. Such a file is under no name the tool looks for, so a discovery-shaped list has no reason to contain it, and it is *more* dangerous than a discovered one for the same reason — its whole existence is a side project's, added by whoever needed a second test suite. **Litmus: read the project's own script definitions (`composer.json` `scripts`, `package.json` `scripts`, CI steps, the docs' copy-paste commands) and add every path that appears after a config flag.** Note the failure is not reach — a wiring-diff helper is a prompt, never a control — but **false reassurance**, which is the only thing a prompt can get wrong, and this is the second finding of exactly that shape in the same file.
  - **Watch the config's SHAPE, not the filename this repo happens to use (2026-08-05, #109).** The branch supplies the filename as surely as the contents, and every one of these tools accepts several — usually with a *dotfile variant that wins*. PHP_CodeSniffer reads `.phpcs.xml` **before** `phpcs.xml`; PHPUnit falls back to `phpunit.xml.dist`; ESLint has a cascade; npm reads `.npmrc`. So an inventory, allowlist or watch-list keyed to the one file in the tree today is bypassed by a branch that brings its own under a name the tool prefers — and the check reports clean. Enumerate a tool's *accepted* config names, present or absent, or match by glob. This one was found in `tools/check-branch.js`, the script written to prompt about #101, watching `phpcs.xml` and not `.phpcs.xml`: a watch-list is a ledger, and RI-4's rule applies to it — re-derive, never read.
- **CI triggers.** A workflow that runs with repository secrets on a trigger a fork can influence is the classic form.
- **The repository's own history (2026-08-04).** The four shapes above are all *code that executes*. This one is not: it is what the repo has already published and cannot take back. A credential committed and later deleted is **gone from the working tree and present in every clone** — `git log -p` over a removed path returns it, and on a public repo that is a permanent disclosure with no expiry. Three consequences, each counter-intuitive:
  - **Removing it from HEAD is not the fix.** Nothing done in this repository can unpublish a blob that is an ancestor of a public branch, so the remediation always happens somewhere else — rotating the credential at whatever issued it, revoking the key, invalidating the endpoint. Note that "it was removed in a later commit" and "there is no evidence it was ever used" are both perfectly compatible with a value that still authenticates today: neither is a control, and the action that *is* one usually takes a minute.
  - **This class is structurally invisible to every mechanism in this system.** Reviews are diff-scoped (`<base>..HEAD`), so anything added and removed before the base is in no range a future review will cover, and the sweeps grep the tree, where it no longer exists. It has to be swept deliberately, over all refs, or it is swept never — a secret scanner across every ref, not a path-by-path guess, because guessing the path is the part that never generalizes.
  - **A finding of this shape does not go in a committed file — not even the index.** This is the one class where the review system's own record is itself the disclosure: the entry cannot be a step toward the fix, because no commit closes it, so all it can do is point. Keep the substance in the git-ignored report, act on it outside git, and when writing the lesson up here, write it so it names no location and reads identically in any repository. **That includes example paths in a litmus.** A worked example is normally the most useful part of a pattern; here it is the part that narrows a search, and this bullet deliberately does without one.
- **Skills that ingest third-party text.** These define the *entry point*; the three code-executing shapes above define the *sink*. Rate the pair, never either alone.

  **A hook that READS a worktree file is an entry point too, and the sweep only asks whether one EXECUTES (2026-08-05, #118).** Every entry-point example recorded here until now was a command or a skill — something a person invokes. An agent `SessionStart`/`Stop` hook that merely `cat`s a repo file into the session context is invisible to this sweep's second bullet, which asks *does it execute a path resolved from the working tree*: the answer is no, the executable is a fixed system binary, and the finding is that **its argument is branch-supplied text landing in the instruction position** of a session that holds the pre-approved dispatchers from that same bullet. Entry point × sink, with the entry point being a hook rather than a command.
  - **Rate the marginal reach, not the absolute.** An agent already resolves its project instructions, commands and skills from the checked-out tree, so the baseline is not zero and a finding here is usually "no added reach". What *is* worth recording is which **non-standard** files a hook promotes into that position — a contributor editing a docs-shaped file in a `.security/`-style directory reads as unremarkable in a way that editing the project instruction file does not.
  - **Only relocation is enforcement.** Resolving the ingested file from a fixed maintainer-controlled location outside the worktree is a control; a "treat the following as data" preamble is not, for the reason this entry already gives about prose guards — it shares a context window with what it is guarding.
  - **Litmus:** read every hook's command line and ask *whose bytes end up in the model's context*, not only *whose code runs*. `cat`, `head`, `git show`, `jq` over a repo file — all of them are ingestion.

**Rules:**
- The enforced allowlist must be no wider than the write surface its skill documents. Pin the verb and the path; prefer a prompt over a wildcard.
- Prose guards in a command file mitigate but do not enforce — they live in the same context window as the attacker's text. Never treat one as the control.
- A hook must execute code from a fixed, maintainer-controlled location, not from the checked-out branch; consider a CODEOWNERS requirement on hook and workflow paths. **CODEOWNERS does not close the local path, though** — it gates *merging*, and the checkout that runs the code happens before that.
- **Count the sinks before choosing a fix, or you will harden the wrong one.** When a finding of this class turns up, enumerate every command the project's own docs tell a developer to run and ask what each executes from the worktree. The answer is usually "most of them": a test runner executes the test files *by design*, a bundler executes its config as code, a package manager executes install hooks, a linter executes or loads whatever its config names. Hardening the single tool that was reported is whack-a-mole, and the reported one is rarely the worst — a test runner beats a linter every time. **Only isolation (running the tooling in a container) is an *enforced* boundary; "don't check out untrusted branches" is a *described* one**, and per the threat model only the enforced kind counts as closed. A wiring-diff helper is a useful prompt for the honest-contributor case and must be recorded as a prompt — it cannot bind a hostile branch, which supplies the helper too.
- **Enumerate the SCOPES before the files — see PA-19.** Everything below is about *which files* to read; #162 was missed because the sweep had the wrong *set* of files, naming only project scope while the enforced allowlist merges user scope too, from a path outside the repository that no `git` command reaches.
- Locally-scoped config (`settings.local.json` and friends) is git-ignored, so it will **never** appear in a diff. It needs reviewing on the disk, deliberately, or it is reviewed never. **Check which files that actually covers rather than assuming the `.local` suffix marks them (2026-08-04):** in this repo `.gitignore` excludes `.claude/settings.json` as well, so *both* permission files are diff-invisible — and the entry that named only the `.local.json` read as an assurance that the other one was diff-visible. Run `git check-ignore -v` over the config paths instead of inferring from the filename; a fix applied to an ignored config also never reaches another checkout, which changes what "fixed" means for a finding in one.
- Rate with the threat model's **D-actor** axis (D0 outside contributor / third-party text → D1 maintainer workstation), not A0–A4: this class has no site actor at all.
- **A tool config can also carry a control that is only DESCRIBED, and its override point is usually git-ignored too (2026-08-12, #175).** `phpcs.xml` ended with a comment asserting "warnings fail the build alongside errors" and no directive under it: the 2026-08-04 fix had **deleted** an `ignore_warnings_on_exit value="1"` line rather than setting `value="0"`, so the behaviour was PHP_CodeSniffer's default and nothing in the repo pinned it. Measured: writing that value into `vendor/squizlabs/php_codesniffer/CodeSniffer.conf` — git-ignored, so invisible to every diff — silently returns local runs to exit 0 on warnings, while the comment still reads as enforcement. Two rules follow, and they are the bullet above turned around: **when you fix a config by deleting a line, ask whether the correct value is now merely a default**, and treat a `<!-- -->` block where a directive belongs as documentation, never as a control. Note the severity honestly — the only writer of that file is D1, so this is drift and an unpinned setting rather than an exploitable weakening; CI is insulated because `vendor/` is untracked and rebuilt.

### PA-19: A ledger is only as wide as the SCOPES it enumerates — and permissions are not the only thing assembled from more than one

> **Widened 2026-08-12 (#186), and the widening is the entry's real lesson.** #162 fixed this
> for the **permission** ledger and the fix stopped there — so one review later the *same
> mechanism* was found unapplied to the ledger next door: the inventory counted **4** agent
> definitions where **28** resolve in a session (4 project + **17 user scope** + 7 from an
> enabled plugin), and **17 of the 24 uncounted declared `Write` or `Edit` where none of the 4
> counted did.** The cause was one character of path — a **relative** `.claude/agents/*.md`
> reaches project scope only. **The scope rule is not about permissions. It is about anything
> the tooling assembles from more than one place**: agents, skills, commands, hooks, and the
> plugins that ship all four. When you fix a scope gap in one ledger, the same edit is owed to
> every other ledger of the same shape — that is #66/#67/#71/#74's "fix the family, not the
> sibling" applied to this system's own bookkeeping.
>
> Three counting traps measured alongside it: a relative path silently means project scope;
> compiled artifacts (`__pycache__/*.pyc`) match the inventory greps and inflate any file
> count; and an installed plugin's *content* moves under a *stable* name, so pin `version` +
> `gitCommitSha` from `installed_plugins.json`, never the `enabledPlugins` key. Record **counts
> and method only** — everything under `~/.claude/` is the maintainer's machine rather than
> the project, and that tree also holds credential material.

PA-14 asks *which files hand code to an executable*. It never asks **which permission scopes
the enforced allowlist is assembled from**, and for 21 reviews this system's inventory
command named exactly two — `.claude/settings.json` and `.claude/settings.local.json`, both
**project** scope. Confirmed 2026-08-12 (#162): the **user**-scope file (`~/.claude/settings.json`)
also applies to the project, carried a pre-approved dispatcher into a worktree-resolved tool
config, and had never been read by any run. It is outside the repository, so no `git`
operation reaches it and no `.gitignore` note hints at it — the two mechanisms that at least
made the project-scope local file *known* to be invisible.

- **Enumerate scopes, then files.** For every permission mechanism the tooling supports, list
  the scopes it merges (user / project / local / enterprise / per-agent frontmatter) and read
  each one on disk. A count derived from a subset is not a smaller answer, it is a wrong one
  that reads as reassurance — #109/#148's failure mode applied to the ledger rather than to a
  prompt.
- **The out-of-repo scope is the one that never self-announces.** A git-ignored file inside
  the tree at least appears in `git check-ignore`; a file in `$HOME` appears in nothing. It
  needs an explicit step, or it is reviewed never.
- **A restriction key is not a grant key, and mixing them corrupts the count.** An agent
  frontmatter `tools:` entry *restricts* which tools a subagent may request; a
  `permissions.allow` entry *pre-approves* a command. Counting the two together produced
  #129's figure, which #140 later showed had counted entries under a key the harness ignores
  — so the ledger overstated one surface while a real one sat unread in another scope
  (#168). Settle grant-vs-restrict by measurement before adding either to a count.
- **Litmus:** for each entry, ask *whose file does this ultimately execute, and which scope
  did this entry come from?* Then ask what the sweep's own inventory command would have
  missed. If the answer is "a scope it does not name", fix the command before writing a
  number.

### PA-18: A generator that writes shipped source from an external input is a script sink, and its output executes on every install

PA-14 rates code reaching the **maintainer's** machine; PA-17 rates an artifact a **user** loads
into a system that is not ours. This is the fourth target and the widest: a build-time program in
`tools/` that reads something from outside the repository and **writes PHP into `src/`**. It runs
on nobody's site and ships nothing itself, yet every line it emits ships in the release ZIP and
executes on every install. None of the other lenses select it — it answers no request, it is not
in a `<script>` sink ledger, and it is not a tool *config* of #101's shape, so PA-14's four
questions (permissions, hooks, CI triggers, configs fed to executables) all walk past it.

Confirmed 2026-08-11 (#154), the first generator this project has had.

**Questions to ask of any generator, in order:**
- **Is every interpolated value escaped for the grammar it is being written into?** Generating
  source is string concatenation into a language, which is the same question RI-2 asks about a
  `<script>` body — a `'` in a value closes the literal and everything after it is code. Use
  `var_export( $v, true )`, never `"'" . $v . "'"`. **Watch for the tell:** #154 validated the
  one field that could not have hurt it (a two-letter code, regex-anchored) and left unchecked
  the two it interpolated into quoted literals. A generator with *some* validation reads as
  validated.
- **Does it validate the shape of what it read, or only that it parsed?** Well-formed XML/JSON
  from the wrong URL is still well-formed. Give it a **plausibility floor** — a minimum row
  count, a required marker — and exit non-zero rather than writing.
- **Does a broken run look fresher than a good one?** A generator that stamps its output with
  today's date defeats every staleness guard downstream when it writes garbage: the release
  script reads "generated today" and packages it. A freshness check is only a check if the
  generator refuses to stamp output it could not verify.
- **Does it check that the write succeeded?** `file_put_contents()` returning `false` while the
  script prints "Wrote …" is a silent no-op.
- **Who can influence the input?** Rate on the D-axis, but note the sink is *users' sites*, not
  the workstation — so the usual "D1 → D1 is not a finding" reasoning does not apply. A public
  upstream repository that accepts community contributions is a lower bar than it first reads,
  and a documented "pass a path or URL" argument makes "regenerate from a mirror somebody sent
  me" a realistic action.
- **The reviewer of the regenerated diff is a control, so keep the diff reviewable.** Sorted,
  one row per line, one file — which is also what lets a drift job diff it. This is the argument
  for keeping generated data in its own class rather than folding it into a hand-edited one.

### PA-17: An artifact the project PUBLISHES for someone else to run is surface, and it is neither a route nor a sink
PA-14 brought the repo's tooling into scope on the grounds that it runs on the *maintainer's*
machine. This is the third target: a file the project distributes for a **user** to load into a
system that is not this plugin and not this repository. A GTM container export is the working
example — it is imported into somebody's own tag manager, where its contents run on their site,
under their account, with none of this plugin's escaping in the path.

It is invisible to every other lens here by construction. It answers no request, so the
new-surface grep (`register_rest_route`, `wp_ajax_`, `__return_true`) cannot see it. It is not
executed by this codebase, so the sink ledgers cannot see it. It is usually outside `src/`, so
the inventory greps do not select it, and it therefore arrives with **no Coverage Matrix row** —
absent rather than `[ ]`, which is the state the checklist's own warning calls the worse one.
Confirmed 2026-08-11 (#150): `.gtm-container-template/` landed exactly this way.

**Two questions, both answerable in one grep, and neither asked anywhere else:**
- **Does it carry executable content?** A tag-manager export can contain Custom HTML tags and
  Custom JavaScript variables (`"type": "html"`, `"type": "jsm"`), which is *the project shipping
  JavaScript that runs on a user's site through a channel with no review step*. An export built
  from first-party tag types only is a much smaller promise than one carrying a script — say
  which it is, and re-check on every refresh, because "we only use built-in tags" is a property
  of the container the day it was exported.
- **Does it carry the exporter's identity?** An export embeds the source account id, container
  id and public id in *every* entity and in URL form as well, so a partial scrub leaves them in
  the paths. Anonymize, then grep for the old values rather than trusting the edit.

**Rules:**
- **Give it a row, and put the two questions in the row label** — the row is the only place they
  will be asked again. It has no Cap/Nonce, SQL or Perf dimension; borrow *Output XSS* for the
  executable-content question and *Input San.* for the anonymization one.
- **Check whether it ships.** Confirm against the packager's allow-list (`tools/build-release.js`
  `DIST_FILES`) rather than assuming a dot-prefixed directory is excluded — verified, not
  inferred. An allow-list packager is what makes this a non-event; a deny-list would not.
- **A refresh is a new review, not an edit.** The artifact is re-exported wholesale from an
  external system, so nothing about the previous review carries over. Both questions are
  re-asked from scratch every time the file is replaced.
- **The generalization, so this is not one directory's rule:** ask of any committed
  non-code file *does the project publish this for somebody else to load into something*. Import
  bundles, exported configurations, template files, sample rulesets. The answer being "it never
  runs here" is precisely what puts it outside every other lens.

### PA-16: Moving an asset out of `wp_enqueue_script` takes it out of every control the SITE has over it
PA-15 asks what the plugin switches off for everybody else. This asks the quieter
question next to it: **what does the plugin move beyond everybody else's reach?** Both are
about the host's control surface rather than about our own escaping, which is why no
value-following lens prompts for either.

A `wp_enqueue_script()` handle is not just a way to load a file. It is the site's whole
grip on that request: `wp_dequeue_script()`, the `script_loader_tag` filter, dependency
ordering, `wp_script_add_data()`, and — the one that matters most for a third-party URL —
the fact that the vendor's domain appears in the served HTML where a scanner or a consent
manager can see it. Fetch the same file with `document.createElement( 'script' )` instead
and every one of those disappears, silently and with no error anywhere.

Confirmed 2026-08-10 (#125): eight media provider SDKs moved to runtime injection so a page
with no embed would stop paying for them — a real and worthwhile win, 288 KB across seven
vendors on every page view. The cost was not obvious and was not in the changelog: WordPress
consent managers block third-party scripts by rewriting the `<script src>` tag through
`script_loader_tag`, and after the move there is no such tag to rewrite.

**The correction that came with it is half the pattern.** The first draft of the finding
said the move "removes every server-side lever". It does not, and the difference decides
the fix: the *tracker bundle* is still its own handle, so dequeuing or blocking
`gtm4wp-<provider>` stops the vendor request by stopping the code that makes it. What is
lost is narrower — **the vendor-named URL**, which is what third-party rulesets and
blocklists actually key on. State the loss precisely before designing for it.

**Rules:**
- Before moving a request out of the enqueue pipeline, enumerate what the site could do to
  it *before* the move and what it can do *after*. Anything on that list you cannot
  reproduce is a documented behaviour change, not an implementation detail.
- **Give the runtime fetch a lever of its own.** A PHP filter consulted at the fetch is the
  cheap form (`gtm4wp_media_sdk_blocked`); check it **at the request**, not at the scan, so
  a veto costs the vendor request and nothing else — players already on the page still get
  tracked, and a site that loads the SDK itself still works.
- **Say which handle still gates it.** Per-provider control usually survives via the bundle
  that makes the request; that is worth a changelog sentence, because nobody can guess it.
- **A `wp_enqueue_script` marker whose execution opens the gate** is the only construction
  that restores *zero-configuration* blocking, since it puts a blockable tag back in the
  page. Reference implementation: `js/frontend/gtm4wp-media-gate.js` (2026-08-10), a 35-byte
  bundle whose only statement raises a flag. Four things decide whether it works:
  - a **real `src`** — WordPress applies `script_loader_tag` only to tags that have one, so
    a handle registered with an empty `src` prints inline script and is invisible to the
    very mechanism the gate exists to expose;
  - a **dependency** edge from everything that reads it, or the gate is a load-order race;
  - **fail-closed needs a diagnostic** — without one, an optimiser dropping a tiny "unused"
    file ends the feature with no error and no red test (RI-20's fails-closed lesson);
  - **distinguish "blocked" from "never present".** A bare `! allowed` check reads a
    standalone loader — or a unit test — as a refusal. Print a companion *expectation* flag
    from PHP, in a **separate inline tag on the gate's own handle**: a consent manager
    rewrites the `src` tag and leaves the inline one standing, which is exactly the state
    that must mean "refused". Putting the expectation inside the gate file erases the
    evidence it was ever expected, and the feature fails **open** in the one case it exists
    for.
- **Probe the gate, do not merely exercise it.** The first version of these tests passed
  against a build with the gate logic removed, because the fetch path had an *earlier*
  early-return (`isReady()` already true) that a convenient fixture had switched on. A
  withholding assertion must be written so the gate is the only thing that can withhold;
  the "still works for what is already on the page" property is a separate case.
- Litmus: grep for `createElement( 'script' )`, `new Image()`, `fetch(`, `sendBeacon(` and
  `new WebSocket(` in `js/`. For each, ask *which vendor does this contact, and what could
  the site do about it if it were an enqueued tag instead?*

**A replacement lever is a claim about WordPress's behaviour, so test it against
WordPress — the reference implementation above shipped with two of its three levers
broken (2026-08-10, #133/#134).** Everything before this paragraph is about *designing*
the levers. This is about the step that was skipped: each lever names an operation
(`wp_dequeue_script`, "a consent manager blocks the tag") and asserts an outcome, and both
assertions turned out to be about core internals nobody had opened. They are now the
pattern's own worked examples, and both fail **open** — the operator acts, nothing errors,
and every vendor request goes out anyway.

- **A handle that everything depends on cannot be dequeued (#133).** The dependency edge
  is required — without it, reading the flag races setting it — and it is exactly what
  defeats `wp_dequeue_script()`: `WP_Dependencies::dequeue()` only unsets from
  `$this->queue`, while `all_deps()` appends any **registered** dependency to `$to_do`
  regardless of queue membership. So the gate prints, the flag is set, and the SDKs load.
  `wp_deregister_script()` is not the workaround to reach for either: a missing dependency
  makes `all_deps()` drop **every dependent tracker**, and on WP 6.9.1+ it emits
  `_doing_it_wrong`. If you want a dequeue lever, read the queue late
  (`in_array( $handle, wp_scripts()->queue, true )` at `wp_print_footer_scripts`) and
  publish the *blocked* flag when the handle is gone.
- **An inline `before` script is inside the `script_loader_tag` string, not beside it
  (#134).** `WP_Scripts::do_item()` builds
  `$tag = $translations . $before_script . wp_get_script_tag( $attr ) . $after_script;`
  and filters **that**. So a fail-closed *expectation* flag attached to the gated handle
  survives only a blocker that edits the `src` sub-tag in place; one that returns `''` or
  replaces the whole string erases the flag together with the thing it was watching for,
  and the feature fails open in the one case it exists for. Two `<script>` elements in the
  served HTML is not two chances to survive a filter that owns both. **Publish the
  expectation from a handle or hook the gate-targeted rule does not match.**
- **The generalizable rule:** for every lever a control documents, name the WordPress
  function that carries it and read that function. "Block or dequeue this handle" is two
  levers, not one phrasing of the same lever, and here one worked and one did not.
- **Where a broken lever hides.** No sweep asked this. New-surface greps for *what answers
  a request*; the duplication ledger counts *sinks*; nothing in the system asks *how can
  this control be refused, and does that refusal work?* The tell is prose: a docblock or
  changelog bullet that instructs the reader to perform an operation. **Treat every such
  sentence as an assertion to verify**, especially when it reassures — `enqueue_gate()`'s
  docblock reasons explicitly about dequeuing and reaches a true-but-irrelevant conclusion
  ("a dequeued gate does NOT drop the trackers with it"), which is precisely what kept the
  no-op unexamined. That is FP-5's shape (#78) and PA-10's `__return_true` corollary in a
  third costume: **a stated security or control property is a hypothesis, and the sentence
  that sounds most reassuring is the one to test first.**
- **`CHANGELOG.md` and `readme.txt` raise the cost.** Both levers are promised there, and
  `readme.txt` syndicates to wordpress.org. A lever documented publicly is a lever people
  will build consent configurations on, so verify it *before* the bullet ships, not after.

**Ask what the third party can do WITHOUT knowing about this plugin — that is the only
protection most sites will ever get (2026-08-10, #139).** Everything above designs levers,
and every lever above needs somebody to pull it: a filter call, a dequeue, a consent rule
naming our handle. A plugin the size of this one cannot expect consent managers to
integrate with it, so a lever that requires configuration protects only the sites that
already knew to look. **Before designing any lever, work out what the ecosystem's existing,
generic behaviour already achieves** — it may be the whole answer, and it may already be
load-bearing without anyone having noticed.

Here it was. A consent manager's ordinary, zero-configuration act is to block the **embed**
by vendor domain (`src` → `data-src`, or a placeholder node). Every SDK-fetching tracker
selects on `iframe[src*="<vendor domain>"]` and reaches its SDK fetch **only once such an
embed is found**, so the blocked embed withholds the vendor request by itself, and consent
restores both together. That property, not the gate, is what protects visitors on sites
that configured nothing.

- **State it where it can be broken, and pin it with a test.** It was an emergent
  consequence of two unrelated decisions (selector shape, fetch-on-demand) and was written
  down nowhere. The change that destroys it **looks like a bug fix**: someone notices
  embeds behind a consent banner are not tracked and widens the selector to
  `iframe[data-src*=…]` or a placeholder class, restoring tracking and silently re-opening
  the vendor request for every visitor who refused. Probe the test by widening the selector
  and confirming it goes red.
- **A first-party file cannot be a third-party control.** `gtm4wp-media-gate.js` is served
  from the site's own domain, so no third-party-domain blocklist will ever match it. It is
  a good lever for someone who reads the docs; it is not, and cannot be, the zero-config
  protection. Do not let a deliberately-designed lever obscure an emergent one that is
  doing more work.
- **The correction ran both ways, which is the part to keep.** PA-16 was written saying the
  runtime move *cost* the site its control. Measured against what a CMP actually does to
  the embed, the move **improved** the zero-config posture: before it, PHP enqueued the
  vendor SDK regardless, so a CMP blocking only the iframe still leaked the request. The
  original finding and its first proposed fix were both wrong for the same reason — each
  reasoned about the levers the *plugin* names, and neither asked what the *consent manager*
  does to the **embed**.

### PA-15: Never relax a host security control globally to accommodate your own output
Every other entry in this file asks whether **the plugin's own output** is safe — a value is followed outward to a sink and the escaping at that sink is checked. This one asks the opposite question, and no runtime lens prompts for it: **what does this plugin switch off for everybody else?**

WordPress exposes its sanitizers through filters, so a plugin whose own markup would not survive `wp_kses()` (or `wp_kses_post()`, `esc_url`'s protocol list, `wp_check_filetype_and_ext`, the upload MIME map, …) can simply widen the rule instead of changing its markup. That is a control the *host* applies to everyone's content, most importantly to content submitted by users **without `unfiltered_html`** — and a filter added at hook-registration time stays on for the whole request, so every unrelated sanitizer call in that request runs against the widened rule. The plugin's own output is one line; the relaxation covers the site.

**Litmus.** For every `add_filter()` in the codebase, ask: *does this filter loosen a check rather than tighten one?* If yes, three questions follow:
1. **Is it scoped to the call it exists for?** Add it immediately before your own sanitizer call and `remove_filter()` immediately after. A permanent registration to serve a single output line is the finding, even when that output line is entirely static.
2. **Could the markup avoid needing it?** The relaxation is usually accommodating one attribute or one element. Changing the markup (an attribute the sanitizer already allows, a stylesheet rule, a different element) removes the question rather than answering it, and is almost always the better fix.
3. **What else runs inside the widened window?** Anything on `content_save_pre`, any `wp_kses_post()` in a front-end submission plugin, any admin-list escaping — a REST request is **not** `is_admin()`, so the block editor's save path counts. The actor is whoever can submit content that reaches the widened sanitizer (A2 here), not the A4 who owns the plugin setting.

**Grep for the shape, not the name:** a closure passed to a `*_allowed_*`, `safe_*`, `*_mimes` or similar filter that only ever appends to the array it receives. A filter that appends is a filter that loosens.

The instance is **#99** (2026-08-04, external scan) — **fixed in 2.0**, `wontfix` on 1.x by maintainer decision, since that line takes security-only backports and a Low hardening finding does not clear the bar weeks before it is superseded. The reference fix is `ContainerCode::the_tag()`: `add_filter` immediately before the sink, `remove_filter` in a `finally` so an exception cannot leave the allow-list widened, and a public static callback rather than a closure so `remove_filter` can be handed the same callable. Output is byte-identical, which is what made scoping the right fix over changing the markup here — the noscript iframe is 1.x-parity output with byte-exact tests over it (BE-1).

**This entry stays a class description permanently, not temporarily** — the difference from #74, where the embargo ended when 1.x shipped the fix. Released 1.x keeps this code, so the actor, the trigger and the reachable effect stay in the local scan report; nothing above depends on them, because the rule is a code-generation rule. When a fix ships on one line and the other is `wontfix`, remember the changelog is the wider broadcast (syndicated to wordpress.org and scanned) while the diff merely sits there: describe the behavior change plainly, and leave the security framing out of it.

**Regression-test shape, since "scoped" is a property a test has to pin in both directions.** Widened *at* the sink (capture `has_filter()` from inside the `wp_kses` stub — this is the only way to observe a filter that exists for the duration of one call), absent *after* it, and never registered at hook time. That third assertion must ask *is any callback attached*, not *is our callback attached*: the code being replaced used an anonymous closure, and a callback-specific assertion would have passed against the defect. Verified by re-adding the old registration and watching it go red.

## False Positive Suppressions

> **An FP is a standing waiver over live code — re-derive it, don't re-read it.** Each entry below records a *mechanism* that made something safe on the day it was written. The code it blesses keeps changing; the entry does not. So an FP is only as good as its last verification, and its conclusion ("do not flag this") is exactly the sentence that stops the next review from checking. Two rules follow:
> - **Every FP states the conditions as verifiable properties, and names what would invalidate it.** "It keeps a nonce" is a conclusion; "it keeps a nonce *that is bound to this caller*" is a property you can go and check.
> - **Re-derive at least one FP per review**, oldest first, and re-derive any FP whose blessed code appears in the diff. Record it in the review's verification highlights. FP-5 sat unexamined from 2026-07-16 to 2026-07-29 (#78) while carrying a condition that read as satisfied and was not.

### FP-1: `echo` in `ScriptTag::print_script_block()`
Carries a `phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped`. The block is `wp_kses`-sanitized and only the ampersand entity is restored (`str_replace('&amp;','&', …)`); all other entities stay inert. Intentional and reviewed — do not re-flag as unescaped output.

**Re-derived 2026-08-04 (R11). The conclusion holds; the stated mechanism is not what makes it hold — and the difference matters for the next caller.** Both properties above are literally true (`ScriptTag.php:90-93` and `:95`). But `wp_kses` is being credited with a sanitization it does not perform here: the rule set **allows** the `script` element, so `wp_kses` does not strip a `</script>` that appears inside a value. What actually keeps a data value from breaking out is `JSON_HEX_TAG` **at the caller**, applied before the string ever reaches this function. So:

- **The verifiable property is a precondition on callers, not on this function:** *every data-bearing value in a block handed to `print_script_block()` was encoded with `wp_json_encode( …, JSON_HEX_TAG | … )` first.* That is what to check.
- **What would invalidate it:** a new caller assembling a block from a value that is `esc_js`'d, `esc_attr`'d, or raw. Re-derive by listing the callers, not by re-reading this entry.
- **Caller ledger (re-derive, don't read). Counting rule: `print_script_block(` / `print_markup_block(` followed by an argument — CALL sites only, excluding the two definitions in `ScriptTag.php` and every prose mention in a comment or docblock.** Measured 2026-08-10: **12** — `ContainerCode` 8, `UserEventsModule` 2, `PurchaseTracking` 1, `PageDataLayer` 1. Every data-bearing one hex-encodes.
  - **This entry carried "16, in `ContainerCode`, `Axeptio`, `UserEvents`, `ProductData` and `PurchaseTracking`" from 2026-08-04 to 2026-08-10 (#132), and it was wrong three ways at once**: the figure counted *grep hits* rather than calls, `Axeptio` and `ProductData` are not callers at all (each only mentions the sink in a comment), and the real caller `PageDataLayer` was missing. **The raw grep returns 18 and the answer is 12** — which is exactly why the counting rule now sits beside the number.
  - **It survived because the same measurement lived in two of our own files and only one was corrected.** #115 fixed this figure in the checklist's Whole-Repo Sweeps row (17 → 12) and left this copy untouched. That is UC-6's *prefer one definition over two*, applied to the review system's own bookkeeping: when a count appears in both the sweep table and a pattern entry, fix both in the same edit or have one point at the other.
- Read this alongside **RI-2**: this entry is why the hex flags are load-bearing rather than belt-and-braces, and the ampersand restore is why `JSON_HEX_AMP` specifically is.

### FP-2: Additional data layer pushes without the hex-amp flag are still safe
`DataLayer::flush_pushes()` emits via `wp_add_inline_script`, which WordPress prints without any `htmlspecialchars_decode`, so `&quot;`-style entities remain inert there regardless of flags. The hex flags were added anyway for consistency; do not flag the wp_add_inline_script path as a break-out risk on the grounds it "relies on" the flags.

**Re-derived 2026-08-05 (R13), oldest-first rule. Conclusion holds; one property it never stated is worth having.**

- **The verifiable properties:** `flush_pushes()` still emits through `wp_add_inline_script()`, and the `datalayer_object` leg still carries the full hex-flag set. Both confirmed in place. **What would invalidate it:** that method emitting through a raw `<script>` sink instead, or any decode step being introduced between the queue and the page.
- **The part the entry omits:** the push queue has **three** legs, and only the middle one is encoded. `js_before` and `js_after` are concatenated **raw**, with no escaping at all. That is correct — they are hand-written JavaScript supplied through the 1.x public push API by site code, so the actor is A4-equivalent and escaping them would break their purpose — but an entry that describes the path as safe without saying two thirds of it is deliberately unescaped invites the next reader to assume the whole thing is encoded. **The suppression covers the missing hex-amp flag on the encoded leg. It is not a statement that everything this method emits is escaped.**

### FP-3: `$echo` reserved-word parameter warnings in `ContainerCode.php` (RESOLVED — not a live waiver)
Historical: `header_top($echo = true)` / `header_begin($echo = true)` used to keep the 1.x-style `$echo` parameter, which PHPCS flagged (reserved-word parameter name) — cosmetic, not a security/correctness issue. Both are now resolved: `header_begin`'s parameter was removed (it was dead — never read, hooked with 0 accepted args, no caller passes it) and `header_top`'s was renamed to `$echo_output` (still functional; its `false` branch returns the markup). No `$echo` reserved-word warning remains in `ContainerCode.php`; kept here as history.

**Re-derived 2026-08-05 (R14), oldest-first rule — and it is now closed by construction, not just by a rename (#113).** Two facts, both mechanical: `grep -rn '\$echo\b' src/ compat/ *.php` returns **zero hits**, and since `c92261f` made warnings blocking, `vendor/bin/phpcs` exits **0** repo-wide — so the warning class this entry waives can no longer be raised at all without failing the build. There is nothing left for anyone to re-flag.

**What R14 actually corrected was the packaging, not the entry.** The body above has said "resolved" since R4; the **Quick Index** row went on presenting it in the present tense under "do NOT flag". So the stale claim lived in the summary that gets read, not in the entry that does not. **When you resolve an FP, fix its index row and grep EVERY file that quotes it, not just the checklist** — a resolved entry whose index row reads live is indistinguishable from a live one at the only moment anybody looks.

**Closed out 2026-08-12 (R21, #166), and the closing turned on a distinction R14's own remedy missed.** R14 said to "drop the stale caveat wording from any coverage note that still repeats it", and that instruction was never executed — correctly, as it turns out. The two remaining instances are **dated coverage notes from Reports 1 and 2 (July 2026)**, and they were *accurate on their date*: the `$echo` parameter still existed and warnings were not yet blocking. Editing them would falsify the record rather than correct it, so **they stay**. What was genuinely stale was this paragraph's own present-tense claim that they "read as current", and the live instruction the two review command files were still giving — both fixed. The rule to carry forward: **a dated note is history and is left alone; a standing instruction is a claim about today and must be corrected.** Sort by which kind you are looking at before editing either.

### FP-4: The AMP module's raw data-layer passthrough (delegated `<script type="application/json">` sink)
`AmpModule::add_amp_analytics_entries()` hands the **raw** compiled data layer to amp-wp's `amp_analytics_entries` filter as `config_data['vars']`. amp-wp builds the `<amp-analytics>` element and serializes `config_data` into the inline `application/json` block itself, so pre-escaping here would double-encode. Do **not** flag the raw passthrough or the absent `JSON_HEX_*` flags.

**Written up as a full entry only on 2026-08-05 (R15, #119) — until then FP-4 existed as a Quick Index row and nothing else.** That is the mirror of #113: there the body said "resolved" while the index row read live, here the index row *was* the whole waiver, so the preamble's requirement that every FP state its conditions as verifiable properties and name what invalidates them had nowhere to be met. **An FP with no entry cannot be re-derived, only re-read** — which is the failure mode the preamble exists to prevent. When adding an FP, add the section, not just the index line.

**Re-derived 2026-08-05 (R15), oldest-first rule. The conclusion holds; the stated mechanism was not what makes it hold** — the same outcome as FP-1's re-derivation, and the reason to state mechanisms as checkable properties rather than as reassurance.

- **What the old wording credited:** "amp-wp re-parses and validates the whole document." True of Standard/Transitional/Reader modes, and **not** the thing standing between a data value and a `</script>` break-out. Document-level validation runs over output that has already been assembled.
- **The verifiable property, and the actual guard:** amp-wp serializes `config_data` with a **flagless** `wp_json_encode()`, and PHP's `json_encode()` escapes forward slashes by default — so `</script>` is emitted as `<\/script>` and cannot terminate the block. The safety is a *serializer default in a dependency*, not a property of this plugin's code and not a property of the JSON context. (The same default is what R5 relied on when it filed the WooCommerce Store API extension under this class.)
- **What would invalidate it:** amp-wp passing `JSON_UNESCAPED_SLASHES` (a plausible size optimization), or replacing `wp_json_encode()` with a serializer that does not escape slashes. Nothing in this repository would change, no test here would go red, and the suppression would still read as valid. **Re-check by reading `amp_print_analytics()` on a live install, not by re-reading this entry.**
- **Confidence note kept deliberately:** amp-wp is not vendored in this repo, so the above was derived from its documented behaviour rather than from source in this tree. That is exactly the #78/#90 lesson (when a verdict turns on what a dependency does, go and read the dependency) with the reading still owed.
- Read alongside **RI-2**'s script-context matrix, which lists this sink, and the threat model's "WordPress core / WooCommerce / amp-wp behavior we delegate to" out-of-scope entry: delegation is legitimate, but *note the delegation and name the property you are delegating to*, so the next reviewer can check it rather than inherit it.

### FP-5: Guest-facing frontend REST mutation — nonce + session-scoping instead of capability (blessed PA-1 exception)
PA-1 requires nonce **and** capability, but that rule targets **admin/privileged** mutations. A **frontend** REST route that mutates state on behalf of an anonymous visitor may substitute **strict session-ownership-scoping** for the capability check when ALL three hold:
1. the mutation targets only the caller's own **session-resolved** record — the id comes from the server-side WC/PHP session, never from a client-supplied parameter (no IDOR);
2. it is **idempotent** and its effect is **non-sensitive** (no data disclosure, no authz/privilege effect, no cross-user reach);
3. it keeps a CSRF gate that is **bound to the caller** — not merely "a nonce is verified".

**Condition 3 is the one that reads as satisfied when it is not (2026-07-29, #78).** For a **logged-out** caller WordPress derives `wp_rest` from uid 0 with an empty session token, so the value is identical for every guest on the site for the whole tick — PA-12 already records this ("anonymous nonces are identical per tick, uid 0") and files it as a robustness note. Put the two together and the consequence is that on a guest route a bare `wp_verify_nonce( …, 'wp_rest' )` proves the caller obtained a site-wide constant, which any third party can fetch: it authenticates nobody and binds nothing. It is a *malformed-request* filter, not a CSRF gate. The plugin hands this value out from its own public GET endpoint and bakes it into cached pages by design, so it is not even scarce.

**Condition 3's remedy is the Origin check, NOT a session-bound token (2026-08-04, #78 fix / #90).** The sentence that used to sit here said the opposite — bind the token to the session, and treat `get_http_origin()` as mere defence in depth. Verifying it against WordPress core inverted the advice, and the reason generalizes:

> `rest_send_cors_headers()` is registered on `rest_pre_serve_request` **by default**. It reflects the request `Origin` and sends `Access-Control-Allow-Credentials: true`.

So on any REST route, **a third-party page can read the response with the visitor's own cookies attached** — which means *any token the site hands a browser is harvestable cross-origin and replayable*. A session-bound nonce, a per-session HMAC, a one-time token returned by a public GET: all readable, all replayable. Each would close the finding on paper and leave the hole open. (Note the beacon shape makes this concrete: `navigator.sendBeacon` cannot set headers, so the token travels as a `_wpnonce` parameter — a "simple request" with no preflight in the way.)

What a cross-site page **cannot** do is forge `Origin`. Browsers set it on every POST and script cannot override it. So:

- **The gate is `get_http_origin()` matched against the site's host and port**, with `wp_get_raw_referer()` as a fallback only when `Origin` is absent, and refusal when neither is present ("no evidence" ≠ "same origin"). Compare host and port; leave scheme out (TLS-terminating proxies make it unreliable). A subdomain is a different host and must fail.
- **Keep the nonce anyway** — it is a cheap malformed-request filter — but say in a comment that it is not the gate, or the next reader re-derives the same wrong conclusion this entry did.
- **Consider withdrawing the CORS grant for your own namespace** (`rest_pre_serve_request` at priority 11, `header_remove()` the three `Access-Control-*` headers for foreign origins). That is what stops the *read* side; the Origin check stops the *write* side. Neither substitutes for the other.
- Do **not** reach for `nonce_user_logged_out` to make `wp_rest` caller-specific: it is a site-wide filter, so a plugin changing guest nonce derivation for the whole site breaks other plugins' guest nonces. **When rating a route that fails condition 3, rate what the mutation actually destroys**: for the `confirm-*-tracked` beacons the effect is analytics integrity (a conversion event permanently suppressed, `_ga_tracked` written on a real order), not data disclosure — Medium, not High. Conditions 1 and 2 still hold for those routes; only 3 does not.

A `current_user_can()` gate is wrong here — guests have no capabilities, so requiring one would break the feature for real shoppers. This mirrors WooCommerce's own Store API and the plugin's public visitor-data GET endpoint (both session-scoped).

Reference: `PageDataLayer::confirm_pending_purchase_tracked()` (#398) writes the `_ga_tracked` analytics dedupe flag for the caller's own reliable-purchase-fallback order, so a later order-received render on another device is suppressed. `check_confirm_purchase_permission()` verifies the `wp_rest` nonce only; the order id comes solely from the WC session (`ProductData::PENDING_PURCHASE_SESSION_KEY`), the request body is deliberately not read, and the marker is consumed for at-most-once. (Since R7/#34 this beacon also owns the marker *consumption* — the delivering GET is read-only.) Do **not** re-flag it as a PA-1 *capability* miss: a `current_user_can()` gate is the wrong control here and would break guest checkout. **The suppression stops there.** It never covered the strength of the CSRF gate, and as of #78 (open) condition 3 is not met for a logged-out caller — see the condition-3 note above. A route that fails any of the three conditions — touches another user's data, is sensitive, takes an id from the request, or carries a token that binds no particular caller — is NOT covered and still needs the full PA-1 treatment.

**This suppression is scoped to one route; it is not a template.** `PA-10` is its positive counterpart: use that to check the *next* session-scoped route rather than reasoning by analogy from this one. The public visitor-data GET is a sibling in shape but has **not** been review-verified (see the Public Surface Inventory) — do not extend FP-5 to it by resemblance.

**FP-4's owed reading is discharged (2026-08-11, R19).** The entry has carried a confidence note since R15 saying its verifiable property was derived from amp-wp's *documented* behaviour rather than from source, and naming the re-check: read `amp_print_analytics()`. Done this run, against that project's own source — the serialization is `wp_json_encode( $analytics_entry['config_data'] )`, **flagless**, so PHP's default slash escaping applies and `</script>` is emitted as `<\/script>`. Conclusion holds, mechanism holds, and the stated invalidation triggers are unchanged. Recorded here rather than in FP-4's body as a worked example of the re-derivation rule paying off: **the entry named the reading it was missing, which is what made it possible to close** — an FP whose confidence note says "unverified" without naming what would verify it can only ever be re-read.

---

## Changelog

| Date | Action |
|---|---|
| 2026-08-14 (Review 25) | Reviewed `788c8f2..612122e` (7 commits — R24's fix session, the 2.0.0-beta3 release, and its first post-release hardening commits: the #462 span migration, the per-tracker media flags, the email-fold split, the reliable-purchase re-arm guard, and a dev-only dependency override). **1 Low (#190), no security finding.** Extended **RI-21** with the consumer half of the reader check: a `JSON.parse` inside try/catch guards the *throw* path only — the helper's `false` return must be guarded at every **consumer**, and a parse that succeeds with `null` (a filter returning null → the literal string `'null'`) passes every catch and fails at first property access. The entry's own "all 12 parse sites are inside try/catch" verdict was true while both of #190's paths were live — a verdict about the wrapper says nothing about the consumer; the guarded-sibling family (8 `gtm4wp_read_json_from_node` sites, 7 guard blocks) is the ledger. Verifier-CONFIRMED end to end with probes in both failure directions; recommendation probe-implemented (bad input skips the push, legit path byte-identical). **FP-2 re-derived** (oldest, rotation R21 → R25): all three legs verified, conclusion and caveat both hold. **FP-5 re-derived** (file in range): all three conditions demonstrable, beacons untouched by the range. Toolchain scope inventory reproduces #186's figures exactly (28 definitions, 0/15/2 Write/Edit); #187 snapshot/retake ran and reproduced. All core ledgers reproduce, incl. RI-25 12/12 (the two `the_permalink` re-applications match core's 2-arg signature). |
| 2026-08-12 (Review 24) | Reviewed `1165053..788c8f2` (2 commits) — R23's second and third fix sessions in full, the ninth consecutive run reading the previous review's own fixes, and this run's job was the **adjudication R23 handed forward**: `64abf41`'s order-received gate rewrite, verified inline there because three verifier dispatches died to API overload. Re-adjudicated with the full machinery against **WooCommerce source fetched at twelve release tags**: an independent verifier's exhaustive decision-table simulation confirmed the parity claim — zero divergences beyond the two documented laxities, all three re-applied filter arities correct, and the feature-detect boundary exact at 8.5.2 (Users **class** present, **method** absent — the `method_exists` half of the guard carries it, vindicating U113's "probe the method" row). **1 Low (#189), no security finding.** Extended **RI-25** with the value half of the hook contract: when a re-applied filter is fed an **approximated** input (literal `true` for terms the mirror cannot read), "can only fail closed" is a theorem about **monotone callbacks only** — truth-table-provable, false for a strictly inverting callback — so prose absolutes over such calls need the monotone qualifier; new litmus question for every RI-25 ledger site ("exact value or approximation?"). The finding itself is docs-only, verifier-CONFIRMED with the recommendation **revised by the verifier** (the docblock names three accepted residuals, not two). **FP-5 re-derived** (rotation + file-in-range, doubly owed): all three conditions demonstrable. **#186/#187 verified live**: the widened toolchain inventory reproduced its figures (28 definitions / 0-15-2 Write/Edit across scopes), and the #187 snapshot/retake ran for this review's own read-only verifier stage and reproduced exactly. Adjudication: 2 dispatches, 0 mechanisms refuted, 1 recommendation revised, 0 dispositions refuted. |
| 2026-08-12 (Review 23, fix session) | **Widened PA-19** from *a permission ledger* to **any ledger assembled from more than one scope** (#186). #162 fixed the scope blindness for permissions and stopped there, so one review later the identical mechanism was found unapplied next door: the inventory counted **4** agent definitions where **28** resolve (4 project + **17 user scope** + 7 from an enabled plugin), **17 of the 24 uncounted declaring `Write`/`Edit` where none of the 4 counted did** — cause: a **relative** inventory path reaches project scope only. The generalizable half is now stated outright: *fixing a scope gap in one ledger owes the same edit to every ledger of that shape* — "fix the family, not the sibling" turned on this system's own bookkeeping. Three counting traps recorded with it (relative path = project scope; `__pycache__/*.pyc` inflates file counts; a plugin's content moves under a stable name, so pin `version` + `gitCommitSha`). **RI-25's ledger corrected 11 → 12** in *both* files that carry it, after `64abf41` added a twelfth third-party filter call, applied RI-25 correctly in the code, and left the count stale — **#132's shape in the ledger written one review earlier to catch the previous instance**, which is the argument for the "the count lives in TWO files" note now on the index line. |
| 2026-08-12 (Review 23) | Reviewed `5a1cc42..1165053` (1 commit) — R22's own fix session, the eighth consecutive run where reading the previous review's fixes paid. **1 Medium + 5 Low (#182–#187).** Added **RI-25** (⭐ re-applying somebody else's filter carries their **argument list**, not just their hook name: `WP_Hook` never pads, so a documented-signature callback reached from a short caller raises an uncaught `ArgumentCountError` — fatal, not degraded; passing *more* than upstream is free because the dispatcher slices down to each callback's `accepted_args`). Its ledger is the litmus that matters: **11** third-party hook sites in `src/`+`compat/`, **10** correct, **1** wrong — and the wrong one was the newest, added by the very commit under review. Two things worth keeping about how it was found. First, **the adjudication stage produced it**: a verifier sent to check a documentation claim about the fallback's version window returned a production defect in the same three lines, so for the first time the stage generated the review's highest-severity finding rather than filtering one. Second, **the suite structurally could not see it** — Brain Monkey intercepts `apply_filters`, so a stand-in callback with optional parameters passes either way (UC-3); the regression test declares its parameters **required**, exactly as a documented site callback would, which reproduces the real `ArgumentCountError` and was watched red on the unfixed source with the trace landing on the finding's own line. **All 3 drafted recommendations were refuted**, two of them for shipping something worse than nothing: one would have closed 7 of ~50 uncounted items while stamping its ledger row complete (#109's false-reassurance shape), the other had a demonstrated false negative *and* a false positive across five measured states. |
| 2026-07-10 (Review 1) | Seeded the patterns file. Added RI-2/RI-3/RI-4 (dataLayer/inline-script escaping: full hex flag set, no blanket `htmlspecialchars_decode`, pre-encoded-value trap) and PA-3/PA-4 (request-sourced dataLayer fields, `esc_js` misuse) from the reflected-XSS review that fixed `?s=` search-term break-out via `print_script_block`. Added core WordPress-plugin RI/PA/FP (ABSPATH guard, i18n, superglobal sanitization, `$wpdb->prepare`, WC CRUD/HPOS, JS rebuild; nonce+capability, option-at-sink validation, module framework; print_script_block + wp_add_inline_script + `$echo` suppressions). |
| 2026-07-10 (Review 2) | Extended **RI-2** with the script-context matrix (which sink needs which flags; flagged `wc_enqueue_js` as a raw-`<script>` sink and `esc_attr(wp_json_encode)` in attributes as already-safe). Extended **RI-4** with the post-hardening data-corruption consequence (leftover `esc_js` now mangles dataLayer data, not just an XSS trap). Added **PA-7** (data-bearing string as a `preg_replace`/`str_replace` replacement arg). From the first full pass over the previously-unreviewed component groups + all six whole-repo sweeps (report `-1606`). |
| 2026-07-13 | Migrated the plugin's only `wc_enqueue_js()` call (checkout `window.gtm4wp_checkout_*` globals in `PageDataLayer`) to `wp_add_inline_script( 'gtm4wp-woocommerce', …, 'before' )` per the WC 10.4 deprecation, with the full hex-flag set on that JSON. Promoted the `wc_enqueue_js` note to **PA-8** (deprecated — don't reintroduce; use `wp_add_inline_script`) and updated the RI-2 script-context matrix to match. |
| 2026-07-14 | Modernized the AMP module against amp-wp 2.x: migrated the amp-analytics injection from the Legacy-Reader-only `amp_post_template_*` hooks to the cross-mode `amp_analytics_entries` filter and swapped `is_amp_endpoint()` for `amp_is_request()`. Added **FP-4**: the module hands the raw data layer to amp-wp (delegated `<script type="application/json">` sink), so the absent `JSON_HEX_*` flags there are intentional — do not flag. |
| 2026-07-14 (Review 4) | Reviewed the native Axeptio CMP integration, Contact Form 7 modernization, AMP amp-wp 2.x modernization, WebToffee deprecation, and the `sandboxedScripts` blacklist group class (`d36bca3..b15b034`). **No Critical/High/Medium.** No new pattern needed — Axeptio (RI-2 + PA-5), CF7 (PA-9 dataLayer-only + SELECT-restricted config), Blacklist `sandboxedScripts` (PA-2 allow-list at sink + on save) and AMP (FP-4 delegated sink) all conform to existing entries. Confirmed the data-layer-name JS-identifier validation keeps the unquoted `window.<name>` head JS safe. |
| 2026-07-14 (Review 4 fix) | Fixed finding #29: routed `ContainerCode::header_top` through `ScriptTag::print_script_block()` so the head block (Axeptio/WebToffee consent JS) gets the ampersand-restore identically to `header_begin` — updated the **RI-2** script-context matrix `header_top` bullet from "raw `wp_kses`, avoid `&`/`&&`" to "now unified on `print_script_block`; `&&`/`&`-URLs safe; don't revert". Also fixed #28 (CF7 tracker double-init guard, PA-9). Both shipped regression tests (`ContainerCodeTest::test_header_top_restores_ampersands_in_head_block_js`; `contact-form-7-tracker.test.js` double-init). #30 (CF7 `inputs` default `full`) closed **wontfix** — intended, documented 1.x-compatible behavior. |
| 2026-07-15 (Review 6) | Reviewed settings **export/import (#87)** + the **container output kill switch (#413)** (`07117bc..HEAD`). **No new PA/FP needed** — import/export conformed to existing entries: no-`unserialize` (json_decode only), PA-1 (capability + `wp_rest` nonce via `api-fetch`), PA-5 (schema-driven re-sanitization onto a defaults-rebuilt row, unknown keys dropped, container rows re-validated via `GTM_ID_PATTERN` + PA-2 at the output sink); kill switch = static-string console warning + `PHASE_EXPERIMENTAL` checkbox mirroring placement-OFF, with a documented public `gtm4wp_output_container` filter. Extended **RI-6** with the "second entry point into a shared sanitizer bypasses the REST type layer" note (from Low finding #32, import path → `Array to string conversion` warning; Known-Findings-Log only, first occurrence). |
| 2026-07-15 (Review 5) | Reviewed the WooCommerce overhaul + CookieYes/CheckoutWC bridges (`b15b034..HEAD`, 24 commits). **No new pattern needed** — every new sink matched an existing entry: Store API extension = REST/delegated sink (FP-4 class; PHP default slash-escaping already blocks `</script>` in the extension value, and WC re-encodes the hydration/response), block trackers are dataLayer-only with same-origin `wc/store/*` data and no `postMessage` (PA-9), the #405 list-attribution cookie reader is DoS-bounded + per-field-sanitized with raw values to the hex-flag sink (RI-4/RI-6), the two block product-list injectors use `addcslashes`/`preg_replace_callback` (PA-7), new SELECT/MULTISELECT options allow-list-sanitize on save (PA-5), CookieYes head bridge interpolates only the `esc_js`'d JS-identifier datalayer name (RI-2 head-block, sibling of Axeptio/WebToffee). One Low (#31, cross-sell `internal_id` leak — data-quality, not a security class, so Known-Findings-Log only). |
| 2026-07-13 (Review 3) | Reviewed the MediaEvents expansion (11 media trackers incl. 8 new, `lib/native-video-params.js`, 12 `EVENTS_*` options). Added **RI-10** (undeclared variable → strict-mode `ReferenceError` in the ES-module bundle; from finding #19, `gtm4wp-youtube.js`) and **PA-9** (embedded-media tracker template conventions: origin-validate raw `postMessage`, dataLayer-only sink, parse own iframe `src` only, `if(!duration)return` guard, double-init guard). Clarified RI-9 that `build/` is git-ignored here. Trackers confirmed free of HTML/JS injection sinks; VideoPress origin-validation is the reference for message handlers. |
| 2026-07-16 | Added **FP-5** (blessed PA-1 exception): a guest-facing frontend REST mutation may use nonce + strict session-ownership-scoping instead of a capability check, when it only affects the caller's own session-resolved record, is idempotent and non-sensitive. From the #398 reliable-purchase cross-device dedupe beacon (`PageDataLayer::confirm_pending_purchase_tracked` — nonce-only by design; order id from `PENDING_PURCHASE_FLAG_SESSION_KEY`, request body ignored, marker consumed → no IDOR, at-most-once). Cross-referenced from PA-1. |
| 2026-07-17 (system hardening, no review) | Added **PA-10** (record ownership / IDOR — where does the id come from; a `__return_true` gate must be demonstrated in the code path, not accepted from a doc block) as the positive counterpart to FP-5, which until now taught only what *not* to flag on one route; scoped FP-5 explicitly to that route so it is not extended by resemblance to the new public visitor-data GET. Added **RI-11** (over-exposure: a value escaped correctly but not entitled to be in the dataLayer) — promoted to a Recurring Issue on its second occurrence per the promotion rule (#31 internal id, #30 CF7 PII default), covering the *exposure* risk class that had been re-derived per finding with no entry. Added the companion **`.security/threat-model.md`** (A0–A4 actor ladder; severity = lowest actor who can reach the sink; in/out-of-scope incl. bounded-DoS and the multisite `unfiltered_html` caveat), codifying calls previously made ad hoc (#30 wontfix, #32 Low, Review 5's "DoS-bounded"). No code reviewed; prompted by the VisitorData module landing 2026-07-16 with no Coverage Matrix row. |
| 2026-07-17 (Review 7) | Reviewed the VisitorData module + its A0 `gtm4wp/v2/visitor-data` route and the `#398`/`#3`/`#324`/`#220`/`#258` changes (`ab7fa99..HEAD`). 13 findings, no Critical, all fixed. Extended **PA-10** ("read-only" means no session/cookie/transient writes either — #34: a public GET consumed WC session markers → CSRF-able state change) and **PA-9** (an SDK may replace the wired element → re-mark the replacement, #40; keep cross-injection counters on `window`, #41). Added **PA-11** (WooCommerce does not init its session on REST routes — `WC()->session` null, silent no-op, #33; `wc_load_cart()` gated), **PA-12** (never bake a time-limited nonce into cacheable HTML — stale nonce 403s an anonymous read, #35), and **RI-12** (`isset()`/`??` on a magic-`__get` property may be false without `__isset()` → blanks third-party values, #43). All three "invisible to a green suite" findings (#33/#40/#43) share one root: a test double more capable than the real collaborator → new test-review **TS-13**. |
| 2026-07-22 (Review 8) | Reviewed `b7a7d33..54d12e5` (JSON_NUMERIC_CHECK removal, transaction-ID prefix, environment-type readout, PageVariables null-global fix) + full whole-repo sweep re-run. 8 Low, no Critical/High/Medium. Extended **RI-6** (a custom Field sanitizer sits in front of the type-defensive `to_string()` guard — it receives raw import values and must be type-defensive itself; never write a custom sanitizer that is a subset of the type default, from #46). Added **PA-13** (field descriptions/module intros render via `RawHTML` — an innerHTML sink; dynamic values must be `esc_html`'d server-side; corrects R6's misleading "0 `dangerouslySetInnerHTML` hits" claim). Extended **RI-2** (flag hygiene: never (re)add `JSON_NUMERIC_CHECK` to a dataLayer sink — type numbers at source; it mangles leading-zero identifiers). Widened the **RI-13** litmus to all `global $…` statements, not just post/authordata (the narrow grep missed the unguarded `$wp_query` read, #51). |
| 2026-07-22 (Review 9) | First **empty-diff escalation** run (`a2b18c7..1ad1187` held no production code, so the deep pass replaced the short one). 2 Medium + 9 Low, no Critical/High, no regression on any prior fix. Added **RI-15** (an option's description is its consent contract — the "Post Terms" option also publishes all non-`_` post meta, perfectly escaped and never named in the description, #55); this is the *consent* half of RI-11, the one exposure question a threat model cannot answer. Added **RI-14** (a value shared by a PHP writer and a JS reader needs one definition — the `gtm4wp_orderid_tracked` key had three incompatible spellings and coincides only on a default install, #57). Extended **RI-13** with the second litmus pass: a gate alone is not compliance, the negative branch must **omit** the key rather than invent `0`/`''` — the author-archive sibling the R8 sweep could not see (#59). Extended **PA-2** with "cover every column and every entry point": patterns applied on save but not at the sink, a hardcoded wp-config constant trusted where its sibling is validated, and an allow-list retyped inline beside its constant (#60/#61). Method note for the dead-code sweep: reference counting proves a *symbol* live, never a *branch* — #58 is an unreachable `is_null()` arm masked by an earlier `empty()` test, found by reading, not grepping. |
| 2026-07-22 (Review 9 fix) | All 11 findings fixed. Added the **migration-path** rule to RI-15: splitting an over-broad option must seed the new opt-in from the legacy one, so the privacy improvement is the new admin's default rather than a silent behavior change for the upgrading one (`Migration::seed_post_meta_option()` is the reference). Added the operator-signal half of **PA-2**: a rejected `GTM4WP_HARDCODED_*` constant is now *reported* (`Options::hardcoded_errors()` → a non-dismissible admin notice naming the constant), because silently discarding a wp-config value trades one hard-to-diagnose bug for another — validation without a signal is half a fix. Cross-cutting lesson recorded for the test system: the existing `header_top` global-vars test **asserted the #58 defect** and called it "1.x parity", so the suite was green *because* of the bug — when a fix breaks a test, read the test before trusting it (test-review BE-1 boundary). |
| 2026-07-28 (Review 10) | Second consecutive **empty-diff escalation** (`599fd7d..7a607d2` held one production line-pair, a version bump). 8 Low, no Critical/High/Medium, no security finding, no regression on any prior fix. Added **RI-16** (a JS falsy/`NaN` fallback does not catch `null`/`''`/`0`, so a short-circuited DOM lookup slips past the guard written to catch it — #69, probe-verified). Extended **RI-14** with the *option-named JS global* case: the data-layer name is an option on the PHP side and a global on the JS side, so a hardcoded `window.dataLayer` at 1 of ~45 push sites silently drops events on any site using the rename, and every test fixture uses the default so the suite cannot see it (#66). Extended **RI-4** with "check the branch beside it, not just the function": one type-dispatching function had a hex-flag-JSON array branch and an `esc_js` string branch three lines apart, in the function R9 had just rewritten (#72). Widened **PA-9**'s double-init rule from the media family to **every** bundle attaching module-scope `document` listeners — the rule had been written twice as a media-tracker rule and left two non-media bundles behind (#71). Method notes for the checklist: a **fix commit is the next review's base and therefore never gets reviewed by default** (#67 and #72 both came from reading R9's own fix commit); a matrix **date is a claim to verify with `git log`, not a fact to read** (#73 — five findings sat in files whose row under-reported their mtime by up to a week); and the dead-code sweep proves a *file* reachable and a *symbol* live but never sees a statement that runs and does nothing (#70). |
| 2026-07-29 (external scan, no review run) | System hardening after an independent multi-agent scan surfaced four classes this system could not have caught (#74–#77, plus #78 against its own FP). Rewrote **PA-7** and promoted it to ⭐: its "not XSS" verdict had been derived at one call site and frozen as a property of the class, when severity actually turns on **what the matched text contains** — at a `href="` match the expansion lands a quote inside an already-`esc_attr`'d attribute (#74, fixed). The same entry gained the *grep the wrappers* litmus and a call-site ledger: the pattern recorded "two injectors" for eight reviews while two more sites reached `preg_replace` through a helper one file away, invisible to a module-scoped grep. Added **RI-17** (an escape is only valid at the instant of output; any later transform can undo it — the general form of #74, with `sprintf`/concatenation/re-parenting as siblings) and **RI-18** (sanitized ≠ authentic: know which end of a proxy-chain header the infrastructure guarantees, and treat a docblock's security claim as a claim to test — #75). Added **PA-14** (the repo's own toolchain is attack surface: pre-approved tool permissions, hooks executing a repo-relative path, CI triggers, and the skills that ingest third-party text — #76/#77, both open, detail in the local report) and the matching **D0/D1 development-time actors** in the threat model, since A0–A4 can only express risk to the *site*. Added the **FP re-derivation rule** to the False Positive Suppressions preamble and tightened **FP-5 condition 3** from "it keeps a nonce" to "a CSRF gate **bound to the caller**": PA-12 already recorded that anonymous nonces are uid-0 constants per tick, but the two entries never met, and the suppression's "do not re-flag" closed the question (#78, open). Method note: every one of these sat in code the matrix marks `[x]`, so this was not a coverage gap — it was a *verdict* gap, a *scope* gap, and a *waiver* gap. |
| 2026-08-04 (#78 fix) | Fixed the guest-beacon CSRF gate, and **inverted FP-5's stated remedy** in the process. The entry said condition 3 is met by binding the token to the caller's session, with an Origin check as mere defence in depth. Verified against WP core: `rest_send_cors_headers()` is registered by default on `rest_pre_serve_request`, reflects the request `Origin` and sends `Access-Control-Allow-Credentials: true` — so **any token a site hands a browser is readable cross-origin with the visitor's cookies and replayable**. Every session-binding scheme would have closed the finding on paper and left it open; the Origin check is the control, because `Origin` is the one thing a foreign page cannot forge. Entry rewritten with the host/port comparison rule, the Referer-only-as-fallback rule, "refuse when neither is present", and a warning against `nonce_user_logged_out` (site-wide filter, breaks other plugins' guest nonces). The CORS reflection is also its own finding (**#90**, fixed): it made the plugin's public routes cross-origin readable with the visitor's cookies, protected only by browser `SameSite` defaults. **Method note — this is the first finding in this system caught by reading an external source rather than this repo.** The wrong remedy was recorded twice (FP-5 and the R11 report) and was locally consistent both times; nothing in `src/` could have contradicted it. When a fix's correctness turns on what a *dependency* does, go and read the dependency. |
| 2026-08-04 (#75 fix) | Fixed the X-Forwarded-For provenance finding with a trusted-proxy allow-list option, and extended **RI-18** with what scoping it revealed: forwarding headers form **two families** that fail differently, and the recorded plan ("parse from the right") only addressed one. Append headers are a list-ordering problem; **replace** headers (`CF-Connecting-IP`, `True-Client-IP`, `X-Real-IP` — all three already had passing tests) have no list at all, and were trusted verbatim on a request delivered straight to the origin. One allow-list covers both, which is why the setting is a trusted-address list rather than the hop count the earlier note implied. Also recorded: the walk must **stop** at the first untrusted entry rather than continue looking for a public one — continuing reintroduces the finding through its own fix; keeping the old reading as the unconfigured default is legitimate **only with an admin signal**, since both states look identical on the settings screen; and the option sanitizer must validate with the reader's own predicate, because a stored entry the reader skips is worse than a rejected one. New litmus: *a finding reported against one branch of a header-reading function is evidence about the function, not the branch.* Found while fixing, logged as **#89**: the header-name allow-list was unanchored on the **save** side — #62's exact defect at the entry point #62 did not cover (PA-2). |
| 2026-08-04 (Review 11) | Reviewed `7a607d2..b58d427` — R10's fix session, the #74 hardening and two description corrections, all of it fix-commit code that would otherwise have sat under the diff. 1 Medium + 7 Low (#81–#88), no Critical/High. **The theme is new and worth naming: a fix can land correctly and still fail to reach its siblings, because the litmus written *with* the fix selects too narrowly.** R7's lesson was a missing row and R10's a stale date — gaps in what the system *looks at*; this is a gap in what a correct, freshly-written rule *selects for*. Rewrote **PA-9**'s double-init litmus accordingly: the trigger is "does anything run at import time — listener, `push`, `fetch`, observer, **or a state initializer another instance's handlers will read**", not "module-scope `addEventListener`" (which missed two bundles outright, #83), and the guard must be the module's **first statement** (a guard below the state it protects lets the events double-fire anyway, #82). Extended **PA-14**'s second bullet from "hooks that execute a repo-relative path" to **any pre-approved command that dispatches into worktree-supplied code** (#81) — with the two corollaries that the grep is "whose file does this ultimately run?" rather than "find the hooks", and that pinning the command name does **not** close it because the body is still branch-supplied. Extended **RI-15** with the quiet two-thirds of a consent contract — the **variable name** and the **value form** (slug vs display name), from #84, whose sibling had both corrected in the same commit that left it alone; plus the rule to diff every option in a group against its emitter whenever you correct one, since descriptions drift as a set. **FP-1 re-derived** under the oldest-first rule: its conclusion holds but its stated mechanism is not what makes it hold — `wp_kses` *allows* the `script` element, so it never strips a `</script>` in a value; the real guarantee is `JSON_HEX_TAG` at the caller. Entry rewritten to state that as a checkable precondition on callers, with the invalidating change named and the 16-site caller list recorded as a ledger to re-derive. |
| 2026-08-04 (Review 12) | Reviewed `b58d427..58d2f8f` — R11's three fix sessions (the #78 Origin gate, the #90 CORS withdrawal, the #75 trusted-proxy allow-list, the `HardcodedContainers` extraction) plus the two commits after them, all of it fix-commit code that would otherwise have sat under the diff. 8 Low (#91–#98), no Critical/High/Medium, all fixed. **The theme is one step past R11's: a fix can be correct and still hand its own successor a defect** — R11 found fixes whose *litmus* was too narrow to reach their siblings; three of these are fixes that *introduced* the next finding. Extended **RI-3** with its missing converse: the ampersand restore is a *requirement of every script sink*, not a feature of one function — `wp_kses()` alone turns `&&` into `&amp;&amp;`, so a bare `echo wp_kses(…)` over script content is a defect (#92, probe-verified; #29 established this and `the_tag()` kept the bare echo for three more reviews), plus the litmus that the two contexts want opposite things (raw `&` in a script body, `&amp;` in an attribute) so a blanket restore fixes one by corrupting the other, and the warning that an **identity `wp_kses` stub hides the whole class** — `ContainerCodeTest` carried a realistic stub and an identity stub twelve cases apart, and the identity one guarded the broken sink. Extended **RI-18** with provenance-of-the-reader: `wp_get_raw_referer()` returns `$_REQUEST['_wp_http_referer']` before the header, so the fallback leg of the freshly-added CSRF gate was settable by the request it judged (#91) — name the superglobal behind every value in a gate, and read the helper before trusting it. Extended **PA-2** with *share the predicate, don't copy the pattern*, whose evidence is that one fix session did both — a shared public validator for the trusted-proxy list and a second copy of the regex for the header name three files away (#94) — plus "test the agreement, not the list", and the observation that an operator allow-list can contain its own off switch (`0.0.0.0/0` matches everything **and** silences the notice that keys on the list being non-empty, #95). Extended **PA-10** with *a control that protects a NAMESPACE must not be owned by a FEATURE*: the CORS withdrawal was registered from a module gated on an experimental default-off option while the admin settings routes put the namespace on every install (#97) — with the structural reason it survived, namely that the Public Surface Inventory's "route → gate" row shape leaves a shared gate nowhere to be written down, so it now gets its own row. |
| 2026-08-04 (external scan, no review run) | System hardening after a second independent multi-agent scan (whole repo, 14 verified candidates → 3 kept). One of the three is **#77 re-found verbatim and confirmed unanimously**, which is corroboration for the D-axis levelling rather than a new finding — and it was found by a scan whose own inventory had *skipped* `.githooks/` as "not attacker-reachable product code", by a breadth sweep that ignored the skip. The other two needed lenses this system did not have. Added **PA-15** (never relax a host security control globally to accommodate your own output — scope the filter to your own sanitizer call, or change the markup): every other entry here follows a value **outward** to a sink and asks whether the plugin's own output is safe, so nothing prompted the opposite question, *what does this plugin switch off for everybody else?* — and the miss is sharper than a blind spot, because R12 worked three findings deep in the same `<noscript>` block (#92) without asking why that block needs an accommodation at all. Written as a class description with the instance detail withheld (#99, `open`), since released 1.x carries the same code registered more broadly; unlike #74 that embargo has no automatic end date, because 1.x takes **security-only** backports and whether a Low hardening finding clears that bar is a maintainer decision. Extended **PA-14** with a **fifth shape — the repository's own history**: the other four are all *code that executes*, this one is what the repo has already published and cannot retract. The gap stands on its own whatever prompted it: every sweep in this system reads the working tree, none has ever read the history, and that makes it the one shape a diff-scoped review is *guaranteed* never to reach. Three counter-intuitive parts recorded: removing it from HEAD is not the fix (the remediation is always outside git, and neither "removed later" nor "no evidence it was used" is a control); the class is structurally invisible here; and — the part that took two maintainer corrections to get right — **a finding of this shape must not be written into a committed file at all, and neither may the pattern that generalizes it carry an example path.** Every other finding in this system points at code a commit can change, so writing it down is a step toward fixing it and the disclosure buys something; this one cannot be, so the row can only point. First draft wrote it up in full; second draft withheld the finding but left worked example paths in the litmus, which relocates the signpost into the pattern file rather than removing it. New litmus for any future finding: *can a fix land in this repository?* If no, reserve the number, keep the substance local, and phrase the lesson so it reads identically in any repository. A standing "never swept" note went onto the **Toolchain trust** sweep with the all-refs litmus. Method note worth keeping: #99 sat in a component the matrix marks `[x]`, reviewed the same day, so — as in 2026-07-29 — this was a *lens* gap, not a coverage gap. |
| 2026-08-05 (Review 13) | Reviewed `58d2f8f..132e7ca` — R12's fix session, the #99 PA-15 fix, a new git hook and the 1.22.5 changelog sync, all of it fix-and-tooling code that would otherwise have sat under the diff. 1 Medium + 6 Low (#101–#107), no Critical/High, all fixed. **The theme is a third step past R11 and R12: the toolchain grew a new sink while every lens was pointed at the plugin.** Extended **PA-14**'s second bullet with the shape that produced it — a **tool config resolved from the working tree** is the same sink as a script, and it does not look like one: a linter, formatter, test-runner or build config reads as inert data until you notice its format can name code to load, most such tools resolve their config from the current directory when none is passed, and the change that introduced it (#101) arrived as a *code-quality* improvement with no security dimension anyone would have gone looking for. Recorded with it: removing the automatic trigger is a real fix and a partial one, so rate the residual (CI is usually contained; the maintainer's terminal is not). Extended **PA-10** with *a namespace contains routes you never registered* — WordPress auto-registers a namespace index, so a control matching the `namespace/` prefix covers everything the plugin declares and misses the one the framework added (#102), and it reads as complete because the gap appears in no route table, inventory row or `register_rest_route()` call; the trailing-slash comment that justified the strictness is the shape to re-read, since it explains why the rule is tight without asking what it excludes. Closed **RI-4/PA-4**'s `esc_js`-in-a-script-body family with a re-derivable two-pile ledger (identifier = correct, string-literal contents = finding; pile two is now empty) after #105 turned up the last one in the same file #72 had fixed — plus the rule to encode the **whole message**, which keeps the output byte-identical for ordinary input and so preserves 1.x parity and the BE-1 assertions. Extended **RI-18** with *pick the sanitizer for the value's grammar*: a text sanitizer strips percent sequences out of a URL a gate is about to parse (#106) — no exploitable difference there, and the point is that a gate must not rest on a sanitizer that rewrites what it judges. **FP-2 re-derived** (oldest-first): conclusion holds, and the entry gained the property it never stated — two of the push queue's three legs are deliberately unescaped developer-supplied JS, so the suppression covers the encoded leg's missing flag and is not a claim that everything the method emits is escaped. Method note: **#104 adds a third variant to the dead-code sweep's standing lesson** — reference counting proves a symbol live, reading proves a branch live, and neither looks at a comment naming a symbol that no longer exists. |
| 2026-08-05 (Review 14) | Reviewed `d38990b..4ce7537` — four commits holding one production change (the checkout inline-script fallback restored after the WC 10.4 migration), plus R13's own fix commit read deliberately. 6 Low (#108–#113), no Critical/High/Medium, all fixed. **The theme is that a ledger is a measurement, not a fact — three of the six are this system's own bookkeeping asserting something the tree contradicts.** RI-4's two-pile `esc_js` ledger, written *the previous day*, ended "pile (b) is empty" and one member was already there (#110): the miss came from a **third category the piles do not have** — *the value happens to be harmless* — which is a statement about today's data, not about the encoder, and is where a hit disappears. The entry now says to sort by what the value **is in the emitted JS**, never by what it currently contains, and a standing rule went in beside it: **do not write a ledger as an all-clear** — the same shape as a stale matrix date (#73), a drifted sweep count (#87, #111 again one review later) and an FP whose "do not flag" closed the question (#78). **FP-3 re-derived** (oldest-first): closed by construction now, since zero `$echo` parameters remain and warnings block — but the correction that mattered was to its **Quick Index row**, which still read live in the present tense while the entry body had said "resolved" since R4, so the stale claim lived in the summary people read rather than the entry they do not (#113). New **RI-19**: a falsy return conflates *it failed* with *it was never there*, and only one of those wants a fallback — from #108, where a fallback keyed on `! wp_add_inline_script()` fired on WooCommerce's default **block** checkout, where the handle is absent by design and nothing reads what it printed; RI-16 (JS falsy guards) and RI-13 (conditional tag vs. companion global) are the same rule in two other grammars. Extended **RI-17** with **editing markup somebody else escaped** (#112): every mechanism it listed starts from *our* escaper, so its litmus ("follow the variable to the sink") selects nothing in a filter callback that splices a value into finished markup — a whole shape with no escaper to follow, with a second litmus for markup-returning filters. Extended **PA-14** with *watch the config's shape, not the filename in the tree*: tools accept several config names and the dotfile variant usually wins (#109, found in the very script written to prompt about #101). |
| 2026-08-05 (Review 15) | Reviewed `4ce7537..80e116b` — R14's own six-finding fix session plus two test-suite commits, the fifth review running whose base is an unrecorded fix commit. 1 Medium + 7 Low (#114–#121), no Critical/High. All three of R14's code fixes verified correct, each premise re-derived rather than read. **The theme is that "re-derived" is a claim like any other**: R14's report argued a ledger is a measurement and fixed one figure on that basis (#111), and two more figures in the *same sweep row and the same session* did not survive re-derivation. Extended **RI-4**'s two-pile ledger with the three shapes of ledger failure — wrong when written (#115), **correct then self-invalidated by its own commit** (#116, with the rule *re-derive a ledger last, after the code is staged*), and a category the rule forbids (#117) — and recorded pile (b)'s three standing members by name, since the pile had gone quiet again one day after #110 declared how to sort it. Extended **PA-2** with *an allow-list must match the grammar of the sink* (#114): the data-layer variable-name option is validated everywhere PA-2 asks and by the wrong shape, admitting a character JavaScript reads as an operator into a value emitted as a **bare identifier** — no escaping fix exists, because the sink needs it unquoted, so the allow-list is the entire control; the tell is that the same codebase already validates third-party global-var names against the correct identifier pattern ten files away. Extended **RI-17** with its mirror (#120): every mechanism there watches what happens *after* the escaper, and a guard placed *before* one does not cover the escaper's own failure return (`esc_url` → `''`). Extended **PA-14** with **a hook that reads rather than executes** (#118): the sweep's second bullet asks whether a hook executes a worktree path, so an agent `SessionStart` hook that `cat`s a repo file into the instruction position of a session holding pre-approved dispatchers is invisible to it — entry point × sink, with the entry point being a hook, and only relocating the file outside the worktree is enforcement. **FP-4 re-derived** (oldest-first) and found to have **no entry at all** — a Quick Index row was the whole waiver, the mirror of #113 — so it was written up: conclusion holds, but the guard is amp-wp's flagless `wp_json_encode()` plus PHP's default slash escaping, **not** the document-level AMP validation the row credited, and the invalidating change (`JSON_UNESCAPED_SLASHES` in a dependency) would turn nothing red here. Rule added with it: an FP with no entry can only be re-read, never re-derived. **Next review re-derives FP-5** (oldest, and its condition 3 carries open #78). Added **RI-20** during the fix session, from **#122**: checking #121's premise against Google's documentation — rather than reasoning about it from this side — showed the module was writing data-layer keys under names that appear in *neither* the current page nor the one it superseded, while its own docblocks cited that page as their authority. The generalizable half is that a key name handed to a third-party runtime is a contract with only one end in this repository: nothing here can observe a wrong name, so the feature fails silently with the settings screen still claiming it is on, and the only available check is opening the vendor's page. Note what prompted it — a maintainer asking *can anything confirm this at either end?* about a 0.5-confidence convention finding. The convention question was the small half; the contract question was the real one, and no lens in this file would have raised it. |
| 2026-08-10 (Review 16) | Reviewed `707a3a1..1b4445f` - 43 commits and ~10k inserted lines of production code, the largest range this system has covered, and the healthiest per line: **no Critical, no High, no injection finding at all**. 2 Medium + 8 Low (#123-#132). **The theme is that every finding sits in the space between two files that each read as correct on their own**, which is a different failure from a stale ledger or a too-narrow litmus: nothing is wrong in the file you are reading. Added **PA-16** (moving a request out of `wp_enqueue_script` into runtime JS takes it out of `script_loader_tag`, `wp_dequeue_script` and the served HTML, so every control the *site* had over it disappears silently - the quiet sibling of PA-15, which asks what the plugin switches off for everybody else; from #125, where eight media SDKs moved to runtime injection and consent managers keyed to the vendor domain lost the tag they block). Its second half is a correction worth as much as the entry: **the first draft of that finding said "every server-side lever" and was wrong** - the tracker bundle is still its own handle, so the loss was specifically the *vendor-named URL*, and stating the loss precisely is what decided the fix. Extended **PA-11** with the enqueue side (#124): its cheap-precondition rule was written about a REST callback and read as a REST rule, while a second delivery channel reproduced the same cost through `wp_enqueue_script`; WooCommerce disabled the same behaviour by default in 7.8, neither comparable plugin touches cart fragments, and the decisive evidence turned out to be `oneshot_wc()`'s own docblock 400 lines away - **when a new channel duplicates an old one, read the old one's comments before reasoning from scratch**. Extended **PA-2** with *the copy can be more correct than the original* (#123): the third copy of the identifier predicate carried an anchor the shared one lacked, so a behaviour diff would have argued for keeping it - a duplicate is a finding on sight, and its reasoning moves onto the survivor. Extended **PA-9** with the destroy-then-rebuild rule (#127): a tracker that replaces the embed before calling a vendor owes a restore on **every** failure path, and a `.catch` cannot see a synchronous throw; plus blast-radius containment in the shared observer, where one callback's exception was abandoning every remaining embed and provider. Added a **fourth ledger-failure shape** to RI-4 (#132) - the same count kept in two of *our own* files with only one corrected, which is the one shape "re-derive, don't read" cannot catch, because re-deriving is what produced it; FP-1's caller ledger said 16 (grep hits) where the tree has 12 (call sites), named two classes that only mention the sink in comments, and omitted a real caller. **FP-5 re-derived** (R15's nomination): conclusion holds and all three conditions are now demonstrable in code rather than asserted, and the re-derivation produced #130, a fail-closed edge in the port comparison of the very gate #78 added. **Next review re-derives FP-2.** |
| 2026-08-10 (Review 17) | Reviewed `b8448cb..2ea4441` — 4 commits whose production half is 13 JS files and one PHP file, plus R16's own ten-finding fix commit read deliberately. 2 Medium + 4 Low (#133–#138), no Critical/High, **no injection finding** (the range touches no PHP output sink). All three in-range commits verified correct. **Every finding is in the base commit, and both Mediums are in the same one — #125's consent gate, whose reference implementation PA-16 points at.** Extended **PA-16** with the step its own worked example skipped: **a replacement lever is a claim about WordPress's behaviour, so read the WordPress function that carries it.** Two of the gate's three documented levers do not hold, both failing **open**, both promised in `CHANGELOG.md` and `readme.txt`. (a) *A handle everything depends on cannot be dequeued* (#133): the dependency edge that removes the load-order race is exactly what defeats `wp_dequeue_script()`, since `dequeue()` only unsets from the queue while `all_deps()` re-adds any **registered** dependency to `to_do` — and `wp_deregister_script()` is not the workaround, because a missing dependency drops every dependent tracker. (b) *An inline `before` script is inside the `script_loader_tag` string, not beside it* (#134): `do_item()` concatenates it into `$tag` **before** filtering, so a fail-closed expectation flag attached to the gated handle survives only a blocker that edits the `src` sub-tag in place — two `<script>` elements in the HTML is not two chances to survive a filter that owns both. Recorded with them: **no sweep in this system asks how a control can be refused**, and the tell is prose — a docblock or changelog bullet instructing the reader to perform an operation is an assertion to verify, *especially when it reassures*, which is FP-5 (#78) and PA-10's `__return_true` corollary in a third costume. `enqueue_gate()`'s docblock reasons about dequeuing and reaches a true-but-irrelevant conclusion, which is what kept the no-op unexamined. Extended **PA-14** with `.claude/agents/` (#135): three **committed** agent definitions carrying their own `Bash(…)` patterns, with no Coverage Matrix row and no place in the inventory grep — the "absent, not `[ ]`" failure in the directory R16's #129 was found in, so #129 corrected the count for the one file its diff surfaced and left the directory invisible, **#132's lesson one review later in the ledger #132 was about**. Its true figure is deliberately left **open**, pending whether agent `allowed-tools` grants permission or only restricts what a subagent may request. **FP-2 re-derived** (oldest un-re-derived, and its blessed code was in the base diff): conclusion *and* stated mechanism both hold, which FP-1 did not manage in R11 — and the re-derivation produced a **pile (a)** entry for RI-4's ledger, since the pile had never been written down while pile (b) has been corrected three times. **Next review re-derives FP-1** (its conclusion was re-derived in R11 and its ledger in R16; the entry itself has not been read whole since). **Fix-session postscript, and it is the most valuable thing in the run.** The maintainer challenged the proposed #133 fix with a design constraint no lens here carries — *a plugin this size cannot expect consent managers to integrate with it* — and the finding inverted. PA-16 gained **"ask what the third party can do WITHOUT knowing about this plugin"** (#139): the gate is first-party and so can never be matched by a third-party-domain blocklist, while the property that *does* protect visitors on unconfigured sites was emergent, undocumented and untested — every SDK-fetching tracker selects on the embed's own vendor domain and fetches only once such an embed is found, so a CMP's ordinary embed block already withholds the vendor request. #133 and #134 were both re-rated Low on that basis, and #125's "the move cost the site its control" was corrected to the opposite. Also settled, in a third way nobody proposed: #135's grant-vs-restrict question is moot because **`allowed-tools:` is the slash-command key and an agent's is `tools:`** (#140) — all three reviewer agents ran with every tool, including Write and Edit, while their files declared read-only. A restriction that silently does not apply is PA-14's own silence failure mode, and no sweep could see it because every one read the entries' *contents* and none checked the *key*. |
| 2026-08-10 (Review 18) | Reviewed `ddf9049..64612e0` — **1 commit, no production code at all**, so an empty-diff deep pass: all 8 sweeps, FP-1 re-derived, ⭐ patterns re-verified at their call sites, and the base commit (R17's own fix session) read deliberately, which is where 3 of the 4 findings came from. 4 Low (#141–#144), no Critical/High/Medium and **no security finding** — no actor on the A-ladder reaches any of them. Added **RI-21** (an encoder that can fail returns something PHP concatenates anyway: `false` → `''` → `var x = ;` → a `SyntaxError` that takes the whole `<script>` block). It is #85's class eight sinks later: that fix was correct and was left `private` with one caller, so the plugin's primary data layer sink kept the defect, and the sibling that *did* guard sat three lines above the one that did not. **The entry carries a lesson about this system rather than the code** — the review's own ledger listed 9 sites and missed a 10th, because it grepped for the encoder and the concatenation *on one line* while `Axeptio` assigns to a variable and concatenates it eleven lines later; PA-7's #74 lesson landing on the reviewer's grep. Also: **#140 confirmed** in the new session it structurally required (the three reviewer agents now report their declared `tools:` sets, not "All tools"), which settles #135's open question — `tools:` restricts, it does not grant — and leaves **#81 with zero instances** in this checkout's enforced allowlist. **#144 settled against WordPress core rather than hedged**: only an `'after'` inline script forces a handle to blocking, so the media trackers' documented `defer` strategy claim is correct; recorded in the docblock with the core function named. |
| 2026-08-11 (Review 19) | Reviewed `ffd2a63..6fe789c` (5 commits) **plus the base commit itself**, per the standing rule. **No security finding at all**; 1 Medium + 7 Low. Added **RI-22** (*a lookup table with a fallback default asserts the default is right for everything unlisted*) from #145: the domain had three categories, the code modelled two, and the docblock stated the wrong assumption in words — the fix shape is to count the categories and to test the default, not only the entries. Added **PA-17** (*an artifact the project publishes for somebody else to run is surface, and it is neither a route nor a sink*) from #150: a tag-manager container export answers no request and runs on no site here, so no existing lens selects it and it arrived with no Coverage Matrix row — two questions (executable `html`/`jsm` entities; anonymized account ids), both re-asked in full on every refresh. Extended **RI-13** with *when a helper gains an empty-return path, its callers are the change* (#146, the omit-don't-invent half reached off-global for the second time after #121). Extended **RI-21** with *"cannot fail" is a fine answer — write it down where the sink is*, the ledger corrected to **eleven** sites (#147), plus the re-verified `JSON.parse` try/catch count. Extended **PA-14**'s config bullet with *a watch-list built from **discovered** config names misses the ones a command **passes*** (#148, `-c`/`--config`/`--standard` paths read out of the project's own script definitions) — #109's false-reassurance failure mode a second time in the same file. **FP-4 re-derived and its owed reading discharged** against amp-wp's source; conclusion and mechanism both hold. |
| 2026-08-11 (Review 20) | Reviewed `6fe789c..d95f06a` (5 commits) — which **is** R19's fix session, read deliberately per the standing rule, and both Mediums came out of it. **No finding reachable by any A0–A4 actor**; 2 Medium + 6 Low (#153–#160). Added **RI-23** (*an early return added for a newly recognised case opts that case out of every branch below it*) from #153, which is **RI-22's own fix creating RI-22's sequel one review later**: replacing the wrong default with a real three-category table gave the third category its own branch, and that branch returns above the "international form with the `+` left off" test the other two still reach — so 101 of 245 territories silently lost it, and the pre-fix code got right what the fix now gets wrong. Three method notes travel with the entry: **diff the branches, not the values**; **count the rows entering a new branch before calling it a special case** (the docblock introduced a 101-row branch as a seven-country curiosity, #156, which is why it read as harmless); and **a generated oracle proves the table, never the parsing** — 489 corpus cases built as `national_prefix . example_number` exercise only the canonical national spelling, so the count reads as coverage it does not have, and the one hand-written case named for the broken property asserts the single country where it holds. Added **PA-18** (*a generator that writes shipped source from an external input is a script sink, and its output executes on every install*) from #154, the project's first generator: escape every interpolated value for the language being written, give it a plausibility floor, and never let it stamp a fresh date on output it could not verify — a broken run that looks *fresher* than a good one defeats every staleness guard downstream. Its tell is **partial validation**: the field that could not have hurt it was regex-anchored, the two interpolated into quoted literals were not. PA-18 is the fourth D-axis target and the first whose sink is *users' sites* rather than the workstation, so PA-14's "D1 → D1 is not a finding" reasoning does not carry. **FP-5 re-derived** (its turn) — all three conditions demonstrable in code for the first time since #78's fix, condition 1 by the two callbacks declaring no parameters at all — and **FP-1** as well, its blessed code being in the diff; caller ledger reproduces at 12. **Next review re-derives FP-2.** Recorded against this system rather than the code: **#155**, R19's `wp_json_encode` ledger and suite baseline were both invalidated by R19's own final fix commit — #116 exactly, one review after R15 wrote the rule against it, and the fifth appearance of the class. A rule stated inside a sweep row is read only by whoever is already writing that row. |
| 2026-08-12 (Review 21) | Reviewed `d95f06a..bdf4b70` (6 commits) — R20's fix session plus the order-received customer-data change at HEAD, read deliberately per the standing rule. **1 High + 1 Medium + 10 Low (#161–#172).** Added **RI-24** (*a release note is a disclosure, and "fixed" on this branch does not make it one*) from **#161**, which is #74's class in the files the disclosure rule says it binds hardest: a thorough, honest bullet explaining *why* a security fix was needed described the mechanism, trigger, upstream version boundary and time window of a defect the **released `1.x` line still carries** — and a verifier rebuilt a working reproduction from the four published phrases alone, with no access to the vulnerable code. Three method notes travel with it: **count the committed surfaces** (it reached three, including an `.upstream/` registry row sitting three lines below that system's own copy of the rule — any system whose job is to record *why* an upstream gate matters reproduces the mechanism as a side effect of doing its job well); **redact mirrored copies together or not at all** (trimming one file of a deliberate mirror was implemented and measured *worse* than leaving both); and **no text edit retracts a pushed commit message**, so redaction has a known ceiling and shipping the other line's fix is the only step that removes the exposure. Added **PA-19** (*a permission ledger is only as wide as the SCOPES it enumerates*) from **#162**: for 21 reviews this system's inventory named the two **project**-scope permission files while the enforced allowlist also merges **user** scope — a path outside the repository that no `git` command reaches and no `.gitignore` note hints at — where a pre-approved dispatcher into a worktree-resolved tool config sat unread. PA-19 also settles the grant-vs-restrict question #135 left open and #129's ledger leaned on: measured this run, an agent `tools:` entry **restricts** and does not **grant** (a subagent's tool set matched its file exactly, and an unlisted Bash call was refused by the permission adjudicator), which is why #168 re-rates #81's count. **The stage that produced both is the adjudication stage**: #162 was surfaced by a `finding-verifier`, not by any sweep, and **5 of 6 drafted recommendations were refuted** — two by a verifier implementing the recommendation and measuring what broke, one of which (#167) would have deleted a true, load-bearing UC-5 claim while correcting a false sentence beside it. Recorded against the stage itself: **#170**, verifiers run in parallel *and are asked to patch*, sharing one worktree — one read another's in-flight edit and nearly reported it, and one made a throwaway commit on `master`. **FP-2 re-derived** (its turn); conclusion and both stated properties hold. **Next review re-derives FP-1.** |
| 2026-08-12 (Review 22) | Reviewed `bdf4b70..5a1cc42` (8 commits) — R21's fix session plus a contributor commit that landed mid-run, read deliberately per the standing rule, and the Medium came out of it. **1 Medium + 8 Low (#173–#181).** Extended **RI-19** with its second confirmation and a new sub-shape: a **feature-availability guard** (`class_exists`/`method_exists`) has the same merged-states problem as a falsy return, plus one more — "absent because too old" vs "absent because moved" vs "**absent because the symbol and the behaviour have different `@since` versions**". #173 was the third: the gate ships in WC 7.9.0, the helper it delegates to in 8.6.0, so a supported version window failed open and published customer identity to an A0 visitor. New rules: compare the `@since` of the behaviour with the `@since` of the symbol, and prefer re-deriving the narrow version-free part to guessing from a version number (a `WC_VERSION` fail-closed was implemented and **measured breaking the buyer's own order view**). Extended **RI-4** with the **counting rule its ledger never carried** (#174) — `esc_js(` over `src/`+`compat/`, counting lines, 28/13/15; the bare `esc_js` spelling gives 33/18/15 over a byte-identical corpus, so two runs recording different numbers were **both right** and neither needs correcting. Extended **PA-14** with #175: a control that is only *described* — `phpcs.xml` asserted "warnings fail the build" in a comment with no directive, because the earlier fix **deleted** a line instead of setting its inverse, leaving the behaviour to the tool's default and its override point in a git-ignored `vendor/` config. **Adjudication refuted 2 of 5 drafted mechanisms**, both cases of a reviewer reading a correct record as an error. |
| 2026-07-22 (forum-reported bug, no review run) | Added **RI-13** (a conditional tag does not guarantee its companion global — `is_singular()` true with `$GLOBALS['post']` null raised PHP warnings on five unguarded PageVariables reads; resolve once via `get_post()`, null-gate, omit the dataLayer keys when null). Not found by any review: the defect has no actor and no sink, so the injection/exposure lenses never prompted for it; it needs an environment state (a plugin resetting the global) that static reading and well-formed fixtures never represent (the PA-11/TS-13 class); and it sat in an `[x]` component ported byte-faithfully from decade-old 1.x code — Review 7 even fixed #43 *inside the same author block* while inheriting the `$GLOBALS['post']` assumption. Surfaced instead by wp.org forum triage (production `php_errorlog` volume). Countermeasures: RI-13 + a mechanical **Unguarded WP-global reads** Whole-Repo Sweep in the checklist + test-review **TC-14** (tag-true/global-null fixture) + pre-flight bullets on both sides. Fixed in `f38d860` with a warning-promoting regression test. |
| 2026-08-06 (bug report, no review run) | Extended **RI-14** with **the two ends must agree on the BINDING, not only the name and the value** — the one shape its existing litmus (grep the key, count construction expressions) cannot see, because there was exactly one name, one value and one definition. `ContainerCode::header_top()` prints every `GTM4WP_WPFILTER_ADDGLOBALVARS_ARRAY` entry as a top-level `const` in a **classic** inline `<script>`, which binds lexically and never becomes a `window` property, while three trackers read `window.<name>`: `gtm4wp_list_attribution` (#405, 5 sites), `gtm4wp_datalayer_max_timeout` (2 sites, **inherited from released 1.x** and previously claimed fixed in 1.22.3 — `.upstream` UD-18) and `gtm4wp_checkoutwc` (#385). All three features shipped dead with no error and no red test, which is **UD-11 on a contract wholly inside the repo**. Two method notes worth keeping: (a) **`no-undef` cannot see this** — `window.x` is a member expression, so the `globals` list protects the bare spelling only, and two of the three broken names were absent from `.eslintrc.js` entirely because nothing ever demanded a declaration; RI-10 covers undeclared *assignment*, not a wrong *access form*. (b) The control shipped as a **lint rule, not a checklist line** (UD-2): one `inlineConstGlobals` list used twice, declaring the names and feeding `no-restricted-properties` against the `window.` spelling, so it fails `npm run build` rather than waiting to be remembered. Presented as two unrelated bugs — the cookie was never written client-side, so the *server*-side merge found an empty map and `view_cart`/`begin_checkout`/`purchase` were bare too. Test half: `.testing` **TS-17** / **TC-16**. |
