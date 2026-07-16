# Cache-safe data layer (issue #398)

A multi-phase effort to stop GTM4WP leaking one visitor's data to another on
full-page-cached sites (LiteSpeed, WP Rocket, Varnish, Cloudflare APO). The HTML
built for one visitor is served to everyone, so any visitor/session-specific
value baked into the server-rendered data layer leaks — the classic failure is a
logged-in editor's page cached with their email/username/roles, then served to
anonymous visitors.

The switch is the experimental **"Cache-safe data layer"** option
(`GTM4WP_OPTION_CACHE_SAFE_DATALAYER`, off by default), owned by
`GTM4WP\Modules\VisitorData\VisitorDataModule`.

## Delivery tiers (the framework)

Every visitor-specific field is one of three tiers (see
`VisitorField::TIER_*`). A module declares its visitor-scoped fields through the
`GTM4WP_WPFILTER_VISITOR_SCOPED_FIELDS` filter, tagging each with its tier, so
new tiers slot in behind the same option + client runtime without rework.

| Tier | What | Delivery | Cost |
|---|---|---|---|
| 1 — client | The browser already knows it (referrer, search term, anything derived from `location.*`). | Pushed client-side as `gtm4wp.visitorData`. | **Zero network.** |
| 2 — session | Server-only but constant per session (visitor IP, Cloudflare country). | One fetch per session, cached in `sessionStorage`. | One request / session. |
| 3 — action | Server-only, changes on an action (logged-in user data; WooCommerce customer & cart; one-shot events). | Fetch **gated by an existing cookie** (WP logged-in cookie for user data; a cart-version cookie for Woo; an event cookie for one-shots). | One request only when the gating cookie changed. |

### Hard constraint (all phases)

**No unconditional per-page `fetch()`.** An always-on fetch on page load is the
exact anti-pattern that takes sites down under a cache-clear traffic spike. Tier 1
uses zero network. Tiers 2/3 must be once-per-session or cookie-gated. If a change
adds a plain `fetch()` on `DOMContentLoaded`, it is wrong.

## Phase 1 — shipped (2.0 beta1)

- New client runtime `js/frontend/gtm4wp-visitor-data.js` pushes the Tier 1 fields
  as a `gtm4wp.visitorData` event, under the **same** data layer variable names,
  driven by a cache-safe per-request config baked in by `VisitorDataModule`. Each
  Tier 1 source is a *producer* in that file.
  - `siteSearchTerm` ← `location.search` (`?s=`)
  - `siteSearchFrom` ← `document.referrer` (normalized like the 1.x value)
  - Moving these off the server also removes their reflected-XSS surface.
- When the option is **on**, the server stops rendering the server-only
  visitor/session fields into the cacheable HTML — they are simply **omitted**
  (safe default is "no data", not "leaked data"):
  - `PageVariablesModule`: `visitorLoginState`, `visitorType`, `visitorEmail`,
    `visitorEmailHash`, `visitorRegistrationDate`, `visitorUsername`, `visitorId`,
    `visitorIP`, `geoCloudflareCountryCode`, and the server copies of
    `siteSearchTerm` / `siteSearchFrom` (moved to the client push).
  - `WooCommerce\PageDataLayer`: `add_customer_data`, `add_cart_content`,
    `maybe_add_readded_to_cart`, `maybe_add_pending_purchase`.
  - Untouched: content/URL/site data, and the content-driven ecommerce events
    (`view_item` / `view_cart` / `begin_checkout` / order-received `purchase`),
    which are URL-scoped or fire only on cache-excluded WooCommerce pages.

## Phase 2 — planned (not built yet)

Deliver the Tier 2/3 fields client-side, behind the same option and the same
`gtm4wp.visitorData` runtime, with **no** new per-page request:

1. **First-party session endpoint.** Add a first-party REST route (or reuse the
   WooCommerce Store API, already used in 2.0 for block tracking via
   `extensions.gtm4wp.item`, as the precedent for a first-party data channel). It
   returns the Tier 2/3 fields for the *current* authenticated request.
   - **Tier 2** (IP, Cloudflare country): fetched **once per session**, the result
     cached in `sessionStorage`; subsequent page views read the cache, no request.
   - **Tier 3** (logged-in user data; one-shot events): fetched only when the
     relevant **cookie** changed — the WordPress logged-in cookie for user data, an
     event cookie for one-shots — so anonymous cached visitors never fetch.
2. **WooCommerce customer & cart:** reuse WooCommerce **cart-fragments** (the
   existing AJAX the mini-cart already refreshes on every cart change) to carry the
   customer/cart data layer block, so no *new* per-page request is added — the
   fragment request already happens on cart mutation.
3. **Client runtime:** add producers to `gtm4wp-visitor-data.js` that read the
   session endpoint / fragment payload and push under the same field names. The
   `VisitorField` value object grows a server resolver + cookie-gate input
   (constructor args with defaults) — Phase 1 callers are unaffected.

The regression tests to add in Phase 2: the endpoint enforces the same capability
gate as the value it returns (a logged-out request never receives user data); the
cookie gate actually suppresses the fetch when the cookie is unchanged; hostile
header/IP inputs round-trip through the endpoint's JSON without breaking out.
