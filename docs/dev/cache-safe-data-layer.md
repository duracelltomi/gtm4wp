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

## Phase 2 — shipped (2.0 beta1)

The Tier 2/3 fields are now delivered client-side, behind the same option and the
same `gtm4wp.visitorData` runtime, with **no** new per-page request. The
`VisitorField` value object grew a server `resolver` + `cookie_gate` input
(constructor args with defaults) — Phase 1 Tier 1 callers are unaffected.

1. **First-party session endpoint** — `GET gtm4wp/v2/visitor-data`
   (`VisitorData\VisitorDataEndpoint`). It resolves the Tier 2/3 fields declared
   through `GTM4WP_WPFILTER_VISITOR_SCOPED_FIELDS` for the **current request only**
   (everything from `wp_get_current_user()`, `WC()` and `$_SERVER` — it accepts no
   user/session id, so there is no IDOR) and returns them as a hex-encoded JSON
   string payload (`{ "payload": "…" }`, mirroring the Store API cart-item pattern
   in `StoreApiData`) with `no-cache` headers so the per-visitor response is never
   cached.
   - **Identity gate.** Each field's resolver is its own gate: a logged-in-user
     resolver returns `null` for an anonymous request, so the field is omitted — a
     logged-out request receives **no** user data. The route's `permission_callback`
     is public (read-only GET, self-owned request-scoped data); the REST nonce, sent
     as `X-WP-Nonce`, is what lets WordPress authenticate a logged-in caller's cookie
     so their fields resolve at all.
   - **Tier 2** (`visitorIP`, `geoCloudflareCountryCode`): fetched **once per
     session**, cached in `sessionStorage`; later page views replay the cache, no
     request.
   - **Tier 3** (logged-in user data — login state, roles, email + hash, reg date,
     username, id): fetched only when the **login gate cookie changed**. Because JS
     cannot read the HttpOnly WordPress auth cookie, `VisitorDataModule` maintains a
     JS-readable companion cookie (`gtm4wp_login`, an opaque per-session token via
     `wp_hash()`) — set on login, cleared on logout, refreshed opportunistically on
     `init`. An anonymous visitor never has it, so it never fetches user data.
2. **WooCommerce customer & cart** ride the existing **cart-fragments** response
   (`woocommerce_add_to_cart_fragments`), so no *new* per-page request is added —
   the fragment AJAX already fires on cart mutation and re-applies from its
   `sessionStorage` cache on every page. `PageDataLayer::add_visitor_cart_fragment()`
   JSON-encodes the same customer/cart block (built by the same `add_customer_data`
   / `add_cart_content` server builders) into a data attribute of a cache-safe
   placeholder (`.gtm4wp-wc-visitor-data`, output in `wp_footer`); the client reads
   it (and re-reads it via a `MutationObserver` when the fragment refreshes) and
   pushes it under the same 1.x key names. `esc_attr( wp_json_encode( …, hex flags ) )`
   keeps a hostile customer field from breaking out of the attribute.
3. **Client runtime** (`gtm4wp-visitor-data.js`) gained the endpoint fetch
   (once-per-session + cookie-gated, with the sessionStorage cache and logout
   cleanup) and the cart-fragment reader. All the load-time sources (Tier 1, the
   endpoint fields, the initial cart) are gathered into **one** `gtm4wp.visitorData`
   push so a GTM setup sees them arrive together: on a cached view the endpoint
   replays synchronously and the single push is synchronous; on the first view of a
   session the one push fires when the endpoint responds. Only a subsequent cart
   change fires an additional `gtm4wp.visitorData` event (the cart genuinely
   changed). Because these values are delivered on the event rather than baked into
   the page-view data layer, GTM tags that read them should trigger on the
   `gtm4wp.visitorData` Custom Event. When Web Storage is unavailable the runtime
   does **not** fetch (safe default = no extra data, never a per-page request).

Regression tests: `VisitorDataEndpointTest` (identity gate — logged-out receives no
user data; hostile header round-trips hex-encoded; no-cache headers),
`PageVariablesModuleTest` (Tier 2/3 field declaration + resolvers, anonymous → null,
hostile CF country raw), `VisitorDataModuleTest` (endpoint config baking, login gate
cookie set/clear/unchanged), `PageDataLayerTest` (cart-fragment carrier, hostile
customer field safe), and the JS suite (once-per-session, **cookie gate suppresses
the fetch when unchanged**, anonymous never fetches user data, logout drops cached
identity, cart-fragment push).

## Phase 3 — shipped (2.0 beta1): the WooCommerce one-shot events

The two WooCommerce one-shot events — `maybe_add_readded_to_cart` (an `add_to_cart`
after the cart "Undo") and `maybe_add_pending_purchase` (the reliable-tracking
`purchase` fallback) — are now **delivered client-side** too, so with the mode on
**no** visitor/session value is rendered into cacheable HTML at all. The epic is
complete.

Unlike the customer/cart block, a one-shot must fire **exactly once**, and the
`purchase` fallback carries revenue where a double-count is costly. So they are
delivered as **Tier 3 one-shot events** (`VisitorField::$one_shot`), distinct from a
persistent gate: fetched only while an **event cookie** is present, fired once with a
per-event dedupe guard, then the cookie is cleared — never cached or replayed.

1. **Declaration.** `WooCommerce\PageDataLayer::declare_visitor_scoped_fields()`
   registers `readdedToCart` and (only when "purchase on any page" is on)
   `pendingPurchase` through `GTM4WP_WPFILTER_VISITOR_SCOPED_FIELDS`, both Tier 3,
   `one_shot = true`, gated by the shared event cookie
   `Helpers::ONESHOT_EVENT_COOKIE` (`gtm4wp_woo_event`). They are declared whenever
   the mode is on, independent of marker state, because the delivering fetch happens
   on a *later* page than the one that queued the event.
2. **Event cookie.** `Helpers::flag_oneshot_event()` sets the short-lived, JS-readable
   event cookie on the **same hooks that seed the session markers**
   (`ListTracking::cart_item_restored`, `PurchaseTracking::remember_order`), only when
   the mode is on and headers are not yet sent — so it never lands on a cacheable
   response. `VisitorDataModule::build_config()` routes one-shot fields into a
   `config.actions` list (separate from `gates`), so the client knows which cookie to
   watch. An anonymous cached-page visitor without the cookie never fetches.
3. **Resolvers.** On the session endpoint the resolvers resolve the order/re-add from
   the **current WC session** (no id parameter → no IDOR), run the same eligibility
   gauntlet as the page path, **consume** the session marker (so a lingering cookie
   can't re-resolve), and return the event payload plus its dedupe key — the order
   *number* for the purchase, the re-add token for the cart. `resolve_pending_purchase()`
   deliberately does **not** write `_ga_tracked` (it is a public GET); that server-side
   write stays on the authenticated order-received page.
4. **Client dedupe (the point of the phase).** In `gtm4wp-visitor-data.js` the one-shot
   handlers push each event under the same event name the server used, guarded so it
   fires once:
   - **Purchase fallback** reuses the **existing** de-dupe — the same
     `gtm4wp_orderid_tracked` localStorage/cookie guard (keyed on the order number)
     the order-received page's inline block writes (`purchase_dedupe_guard`), read/written
     through one shared protocol. So a fallback fire on page N and a real
     order-received purchase for the same order can **never both count** — whichever
     runs first records the guard; the other is suppressed (client guard) or omitted
     (server `_ga_tracked` gauntlet + marker clear). When "Do not flag orders as being
     tracked" is on, the resolver sets `flag = false` and the client writes/reads **no**
     order-tracked state anywhere, matching the server path.
   - **Re-added-to-cart** de-dupes on the per-event token (the WC session re-add key),
     recorded in `localStorage` after the push so a page reload does not re-fire it.
   After delivering, the client clears the event cookie, so a later page makes no request.

Regression tests: `PageDataLayerTest` (resolver payloads, marker consumption, no
`_ga_tracked` write on the endpoint, the two exactly-once orderings, `flag=false`,
hostile order number round-trips hex-encoded, one-shots absent from server HTML),
`HelpersTest` / `PurchaseTrackingTest` / `ListTrackingTest` (event cookie set only in
cache-safe mode + headers-sent guard), `VisitorDataModuleTest` (`config.actions`
routing), and the JS suite (cookie gate → no fetch; fires once; reload with the token
in localStorage does not re-fire; the shared guard suppresses the second purchase in
both orderings; one-shots stay out of the merged push and the cache; stale cookie
cleared).
