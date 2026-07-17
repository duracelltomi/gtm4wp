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

## Out of scope

- **Anything requiring A4 to attack themselves** (see the severity rule and its
  multisite caveat).
- **WordPress core / WooCommerce / amp-wp behavior** we delegate to. A sink where
  another party performs the escaping is theirs to get right — FP-4 (AMP) and the
  Store API extension are the blessed cases. Note the delegation; don't re-flag it.
- **Site-operator misconfiguration** — a wrong GTM container ID, a GTM tag doing
  something unsafe with a correctly-pushed dataLayer value. Escaping what a GTM tag
  writes to the DOM is the tag's job (PA-9).
- **Secrets on disk / in the repo** as a review category — the plugin stores no
  credentials; a committed secret is a separate process concern.
- **Rate limiting** as a general expectation on public routes.

---

## Changelog

| Date | Action |
|---|---|
| 2026-07-17 | Seeded. Actor ladder A0–A4, the lowest-actor severity rule (+ multisite `unfiltered_html` caveat), the two new-surface questions, and the in/out-of-scope list. Codifies the calls previously re-derived ad hoc per review (#30 `wontfix`, #32 Low, #31 exposure, Review 5's "DoS-bounded"). |
