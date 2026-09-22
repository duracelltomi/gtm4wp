# Threat Model

> ⛔ **Disclosure rule (hard):** this is a public repo — committed == published.
> This file defines *how to rate* a finding, never the detail of one. No payloads,
> no repro steps, no unfixed-finding detail. Canonical rule at the top of
> `.security/code-review-checklist.md`.

Read this before rating any security finding. `/code-review` loads it in pre-review
step 2 and the `code-reviewer` agent loads it alongside `code-review-patterns.md`.

The patterns file says **what to look for**; this file says **how bad it is once you
find it**, so severity is reproducible across runs instead of re-derived from scratch
every review. It is also the answer to "is this even a vulnerability?" — a question
past reviews have had to re-litigate (findings #30 and #32 both turned on it).

---

## What this plugin is, in security terms

GTM4WP's entire job is to take server-side request/page/order state and make it
readable by client-side JavaScript inside a `<script>` block on a public page. Two
structural risks follow from that job and never go away:

1. **Injection** — a value crosses from *data* into *code* at the script sink.
   Owned by RI-2/RI-3/RI-4, PA-3/PA-4.
2. **Exposure** — a value crosses from a higher actor's data into a lower actor's
   browser, perfectly escaped but never meant to be there. Owned by RI-11.

Every judgment below exists to rate those two.

### The stored credential (since 2026-09-03)

The google-auth module added a third asset unlike the first two: a **Google
service-account private key**, stored encrypted (AES-256-GCM, key HKDF-derived from
the wp-config salts, account id as AAD) in its own non-autoloaded option row
(`GTM4WP\Google\KeyVault`). Its compromise pivots **off-site** — whatever Google
products the account was granted — which no A-ladder rung and neither D-actor
expresses. Rate findings against it like this:

- **The custody invariant is the finding shape to watch:** the key is write-only —
  nothing but `KeyVault::open()` (called at signing time) may return key material,
  and no serialized payload (settings GET/export, React bootstrap, REST listing, a
  log, an error message) may carry the PEM, the ciphertext, or the sealed blob.
  Any new path that breaks that is rated by the lowest actor who can read the
  payload, and reaching **A0–A3 is at least High** even though the value is "just"
  ciphertext to most of them.
- **The encryption's honest boundary:** a database-only leak (SQLi, a stray backup)
  yields ciphertext; an attacker with the database **and** the files (wp-config
  salts) gets the key — that boundary is by design and documented to the admin, so
  "DB+files defeats it" is not a finding. **Caveat:** on sites where `wp_salt()`
  falls back to DB-*stored* salts, the DB-only protection is void; guidance leaning
  on the boundary must carry that caveat.
- **A4 uploading/deleting/testing their own key is A4 → A4** — not a vulnerability,
  same as the container-ID rule.

### The stored attribution and the send lane (since 2026-09-20)

The Google Data Manager phases 3–4 store three more asset kinds, none of which the
ladder above or the credential section names (R33, #246 — PA-20's "re-read the scope
claims in the same change" did not happen when they landed):

- **Pseudonymous visitor identifiers in order meta** — `_gtm4wp_ga_client_id`,
  `_gtm4wp_ga_session_ids`, `_gtm4wp_gclid` / `_gtm4wp_gbraid` / `_gtm4wp_wbraid`, and
  the consent map `_gtm4wp_consent_state`, written at order creation from the visitor's
  own cookies and by the guest-facing backfill route (write-only-if-absent). They are
  personal data in the GDPR sense (they tie a visit to a person's order), readable by
  **A3/A4 by design** like every other order field, exported and erased through the
  WordPress privacy tools (`PrivacyData`), and deliberately left behind by uninstall.
  Rate a finding against them like any other order field: **reaching an A0-readable
  page or dataLayer is exposure (RI-11) and at least Medium** — today no reader prints
  them anywhere on the site, and the receipt-page config prints only what the address
  bar already holds. A path that lets a caller *replace* a stored value (rather than
  fill a hole) is an integrity finding rated by the lowest actor who can reach it; the
  route's write-only-if-absent rule is the control to re-derive.
- **The send-log ring and the health/capture counters** (`gtm4wp_gdm_send_log`,
  `gtm4wp_gdm_destination_health`, `gtm4wp_gdm_capture_stats`) — order/refund ids,
  statuses, counts, reason classes, sanitized error summaries and Google request ids.
  Admin-only surfaces (an A4 REST read, the settings panel, Site Health). **Site
  Health's Info section is pasted into public threads**, so the rating question for a
  new row there is "may a stranger read it": no account address, no key material, no
  raw third-party error text, no property id. A log row that starts carrying a request
  or response body is the finding shape to watch, whatever the actor.
- **Queued job payloads** in Action Scheduler / WP-Cron — platform, order id, refund id,
  attempt, destination list, account id and request id. Scalars only; no token, no key.
  A payload that grows a secret would be readable in the Scheduled Actions screen (A3
  on stores where shop managers hold `manage_woocommerce`) and in the `cron` option.

What leaves the site is the refund event body (transaction id, client id, amounts,
item names/categories, a consent field) sent to the fixed Data Manager endpoint under
the site's own service account. That is the feature, not a leak; the consent gate
(`ConsentPolicy`) is the control that decides whether a given order's event may go,
and a bypass of it is rated on the visitor whose data crosses (A0/A1 → Google), not on
who triggers the send.

### The ability surface (since 2026-09-21)

The Abilities API layer (`src/Abilities/` plus each module's `Abilities.php` provider, WordPress 6.9+) is one more reader of A4
data, and its reader is not a person: the client is an AI assistant, so
**everything an ability returns leaves the site in a transcript held by a third
party**. Rate it as an **A4 delegate whose reach ends where the settings screen's
does**: an ability may return what the settings screen or the Site Health Info
section already shows to the same capability, and nothing more - no key material,
no service-account e-mail, no raw third-party error text, no GA4 property id, no
visitor data. Two questions for a new ability or a new output field:

1. would the settings screen show it to this capability, and
2. does an assistant need it to do the job the ability's description promises.

A field that fails (1) is exposure (RI-11), rated on the lowest actor who can call
the ability - A4 today, but the `gtm4wp_admin_page_capability` filter delegates
every ability along with the settings screen, so a site that widened that filter
has widened this too. A field that passes (1) and fails (2) is a review lead, not a
finding. A write ability goes through the same `SettingsStore` sanitizers as the
settings REST route and is rated exactly like that route; the
`gtm4wp_abilities_allow_write` and `gtm4wp_abilities_enabled` filters are
site-wide controls, never an authorization gate, and the `expected_hash` stale
guard of `update-settings` is a lost-update guard between two A4 writers, never a
security control (an A4 who omits it overwrites what an A4 may overwrite). The
confirmation protocol in a write's description and the `destructive` annotation
are the delegate's leash, not ours: the site's protection against a wrong write is
the same as against a wrong click on the settings screen, which is why a write
ability never gets a lower gate than that screen. The same holds for the `confirm:
true` input of the site-wide operations (`import-settings`, the refund replay):
a guard against an assistant acting without its user, never against the user.
The Google-facing abilities (`test-service-account`,
`test-google-data-manager-destination`, `replay-google-data-manager-refunds`) are
the panel's buttons behind the same gate plus the write switch: they run the shared
service the REST route runs (`TokenService::test_account()`, `DestinationProbe`,
`RefundReplay`), so an A4 delegate can cause the same outbound requests with the
site's stored credentials as an A4 click, and nothing else - the only identifiers
it supplies (`measurement_id`, the account `id`) resolve against stored rows and
refuse unknown ones before anything leaves the site; the account and property
ids in the request to Google are always the site's own. The disclosure property
is pinned by the serialised-answer assertions in
`tests/unit/Abilities/StatusAbilitiesTest.php` and
`tests/unit/Modules/GoogleAuthAbilitiesTest.php` (`get-service-accounts` returns
id, label, status and test time; never the account e-mail or key id the panel shows).

---

## Actors

Ordered lowest → highest trust. "Reaches" is cumulative down the list.

| Actor | Who | Reaches | Notes |
|---|---|---|---|
| **A0** | Unauthenticated visitor | Public pages; public REST routes; every request header, query arg and cookie they choose to send | The attacker in nearly every real finding |
| **A1** | Customer / subscriber | + their own account and order data; their own checkout input | Guest checkout means **A0 also supplies billing/shipping fields** |
| **A2** | Contributor / author / editor | + post content, titles, meta, terms | Can store content that renders on public pages and in admin lists |
| **A3** | Shop manager | + all orders, products, customer PII, WC settings | Reads other customers' PII *by design* — not a finding on its own |
| **A4** | Administrator (`manage_options`) | + plugin settings, container IDs, custom domains/paths | **Already trusted with script injection by WordPress itself** |

### Development-time actors (D0–D1)

A0–A4 rate risk to **a site running the plugin**. They cannot express the second target: **the maintainer's own machine**, reached through the repository's tooling rather than through the plugin. That path became real when the triage workflows began pulling third-party text (wp.org topics, GitHub issue bodies and comments) into an agent session that holds pre-approved tool permissions, and when `core.hooksPath` began executing scripts resolved from the checked-out worktree. Findings #76 and #77 (2026-07-29) are the first two of the class, and neither has a site actor at all — which is precisely why the A-ladder never prompted for them.

| Actor | Who | Reaches | Notes |
|---|---|---|---|
| **D0** | Outside contributor / author of any third-party text | Issue bodies and comments, forum topics, PR branch contents (including hook scripts, workflows, build config) | Unauthenticated and unlimited — anyone can open an issue or a PR |
| **D1** | Maintainer / reviewer at the keyboard | Their workstation: shell, SSH and `gh` credentials, signing keys, every repo they can push to | The sink. Trusted by definition, which is what makes reaching it valuable |

**The rule is the same one:** severity is set by the lowest actor who can reach the sink. **D0 → D1 is the finding** — third-party text or branch content causing code to run, or a credential to be used, on the reviewer's machine. Rate on what the execution reaches (credentials and push access to other repositories put it above "just this repo"), and note whether the boundary is *enforced* (an allowlist, a fixed script path) or merely *described* (prose in a command file, a skill's documented write surface). Only the enforced one counts — PA-14 owns the specifics.

D1 → D1 is not a finding, for the same reason A4 → A4 is not: a maintainer can already run anything locally.

---

## The severity rule

> **A finding's severity is set by the lowest actor who can reach the sink — not by
> what the sink is capable of.**

Apply it mechanically:

- **A4 → A4** (an admin's own setting rendering in their own site's script): **not a
  vulnerability.** An admin who can set a container ID can already ship arbitrary JS
  through GTM itself; `unfiltered_html` makes this explicit. Rate such an issue on
  correctness/robustness grounds (Low), and say *why* it isn't security — don't
  silently drop it. This is the rule behind #32 (`Low`, "admin-only, not a security
  vulnerability") and #30 (`wontfix`).
  - **Caveat — multisite:** `unfiltered_html` is restricted to *super* admins on
    multisite, so a single-site admin's assumption does not transfer. If a finding
    depends on the admin already being able to inject script, note that it changes
    on multisite rather than treating A4 → A4 as universally inert.
- **A0/A1 → A3/A4** (visitor-supplied input rendered in an admin/shop-manager
  screen): **real, and usually High.** Stored XSS against an administrator is
  privilege escalation — it borrows A4's capabilities. Checkout billing fields are
  the standing example: attacker-controlled at A0, rendered at A3.
- **A0 → A0** (request input reflected into a public page's script): **real.** The
  classic reflected-XSS path here (`?s=`, `HTTP_REFERER`, `HTTP_CF_IPCOUNTRY`,
  cookies) — findings #1, #4, #5.
- **A3/A4 → A0** (order, customer, or internal data reaching a public dataLayer):
  **exposure, not injection** — rate on what leaks and to whom (RI-11). #31
  (internal post id) is Low; an email or address would not be.
- **A1 → A1's own data**: not a finding. **A1 → another A1's data**: IDOR, High
  (PA-10).

When two actors could reach the same sink, rate for the lower one.

---

## Two questions for every new surface

Ask these of any new route, handler, or dataLayer field, and record the answers in
the **Public Surface Inventory** in `code-review-checklist.md`:

1. **Who can reach it?** (which actor, and what gate stops the one below)
2. **Whose data does it return or render?** (the actor whose data crosses)

A surface where the answer to (2) sits above the answer to (1) is a finding — that
gap *is* the vulnerability class, whether it manifests as injection or exposure.

---

## In scope

- Injection and exposure as defined above, at any actor boundary.
- **DoS, bounded.** Unlike a generic security review, DoS is in scope here — but
  only where the plugin itself creates an unbounded cost from A0-controlled input
  (an uncapped cookie/header read, an unbounded loop over request data). Review 5
  rating the #405 list-attribution cookie "DoS-bounded" is the precedent: the
  finding is the *missing bound*, not the traffic.
- Data exposure / PII in the dataLayer, per RI-11.
- IDOR and authorization-logic gaps on any route (PA-10, PA-1).
- Supply-chain of *this* plugin's own output (container ID/domain/path reaching a
  script `src` — PA-2).
- **The repository's own toolchain, rated on the D-axis** (PA-14): pre-approved tool
  permissions, hooks that execute a path resolved from the working tree, CI triggers,
  and the skills/commands that ingest third-party text. In scope even though none of
  it ships to a site, because D0 reaches D1 through it. Locally-scoped, git-ignored
  config counts — it never appears in a diff, so it is reviewed deliberately or never.

## Out of scope

- **Anything requiring A4 to attack themselves** (see the severity rule and its
  multisite caveat).
- **WordPress core / WooCommerce / amp-wp behavior** we delegate to. A sink where
  another party performs the escaping is theirs to get right — FP-4 (AMP) and the
  Store API extension are the blessed cases. Note the delegation; don't re-flag it.
- **Site-operator misconfiguration** — a wrong GTM container ID, a GTM tag doing
  something unsafe with a correctly-pushed dataLayer value. Escaping what a GTM tag
  writes to the DOM is the tag's job (PA-9).
- **Secrets committed to the repo** as a review category — a committed secret is a
  separate process concern. (This bullet used to say "the plugin stores no
  credentials"; that premise expired 2026-09-03 — see **The stored credential**
  above. Secrets the plugin *stores at runtime* are firmly in scope.)
- **Rate limiting** as a general expectation on public routes.

---

## Changelog

| Date | Action |
|---|---|
| 2026-07-17 | Seeded. Actor ladder A0–A4, the lowest-actor severity rule (+ multisite `unfiltered_html` caveat), the two new-surface questions, and the in/out-of-scope list. Codifies the calls previously re-derived ad hoc per review (#30 `wontfix`, #32 Low, #31 exposure, Review 5's "DoS-bounded"). |
| 2026-09-20 | Added **The stored attribution and the send lane** (R33, #246): phases 3–4 store pseudonymous visitor identifiers in order meta, a diagnostics ring, counters and queued job payloads, and the ladder had no vocabulary for any of them. Exposure rule for the identifiers, the paste-into-public rule for Site Health rows, and how a consent-gate bypass is rated. |
| 2026-09-03 | Added **The stored credential** (R30, #230): the google-auth module made the old out-of-scope line "the plugin stores no credentials" false, and the off-site pivot a compromised service-account key enables had no rating vocabulary. Custody invariant, the encryption's honest boundary (with the DB-stored-salts caveat), and the A4→A4 rule for the key's own management routes. |
| 2026-07-29 | Added the **development-time actors D0/D1** and brought the repository's own toolchain into scope (PA-14). A0–A4 rate risk to a *site*; they have no way to express third-party text or branch content causing code to run on the *maintainer's machine*, so findings #76/#77 had no severity vocabulary and, before that, no lens that would prompt for them. Same lowest-actor rule: D0 → D1 is the finding, rated on what the execution reaches, with enforced boundaries distinguished from described ones. |
