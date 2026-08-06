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
| 3 — action | Server-only, changes on an action (logged-in user data; WooCommerce customer & cart; one-shot events). | Fetch **gated by an existing cookie** (WP logged-in cookie for user data; an event cookie for one-shots) — except the WooCommerce customer & cart, which ride WooCommerce's own `woocommerce_add_to_cart_fragments` response and so need no fetch of ours at all. | One request only when the gating cookie changed. |

### Hard constraint (all phases)

**No unconditional per-page `fetch()`.** An always-on fetch on page load is the
exact anti-pattern that takes sites down under a cache-clear traffic spike. Tier 1
uses zero network. Tiers 2/3 must be once-per-session or cookie-gated. If a change
adds a plain `fetch()` on `DOMContentLoaded`, it is wrong.

### One event per data family

The runtime pushes **three** data layer events, one per family, so a Google Tag
Manager setup can tell from the event name alone which keys arrived and a plain
Custom Event trigger is enough. The names are the public contract; they live as
`VisitorDataModule::EVENT_*` and reach the client through the config's `events` map.

| Event | Keys | Gated by |
|---|---|---|
| `gtm4wp.visitorData` | `siteSearchTerm`, `siteSearchFrom`, `visitorIP`, `geoCloudflareCountryCode`, `visitorLoginState`, `visitorType`, `visitorEmail`, `visitorEmailHash`, `visitorRegistrationDate`, `visitorUsername`, `visitorId` | the individual Page-variables options |
| `gtm4wp.customerData` | the 25 `customer*` keys | `GTM4WP_OPTION_INTEGRATE_WCCUSTOMERDATA` |
| `gtm4wp.cartData` | `cartContent` | `GTM4WP_OPTION_INTEGRATE_WCEINCLUDECARTINDL` |

Why not one event for everything: the WooCommerce families do not exist until
WooCommerce has applied its cart fragment, which is **always** later than the
visitor flush — the placeholder baked into the cacheable HTML is empty by design
(`PageDataLayer::output_visitor_cart_placeholder()`), so the data only ever arrives
on the fragments response. A single event name would therefore always fire twice
with two different key sets, which is exactly the ambiguity this split removes.

Three semantics worth stating, because each is easy to invert:

- **An event means "this family changed."** The fragment is re-applied as one blob,
  so the runtime de-dupes **per family**: a quantity change fires `cartData` alone, a
  billing-field change on checkout fires `customerData` alone, and WooCommerce
  replaying its cached fragment fires neither.
- **`gtm4wp.visitorData` can be absent entirely** — no search term, no referrer, no
  endpoint data (or Web Storage unavailable) means an empty map, and an empty map
  pushes nothing. A tag triggered on `customerData`/`cartData` must not assume a
  `visitor*` key is set. The visitor event is pushed **first** when it fires at all,
  so reading an earlier push's keys does work; the reverse does not.
- **Absence of `cartData` is not an empty cart.** It means the feature is off, or the
  fragment has not arrived. An *empty cart* is delivered, as `items: []` with zeroed
  totals — that is the state a tag reads after the last `remove_from_cart`.

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
same `gtm4wp-visitor-data` runtime, with **no** new per-page request. The
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
   (`woocommerce_add_to_cart_fragments`), so GTM4WP adds no request of its own — the
   fragment AJAX already fires on cart mutation and re-applies from its
   `sessionStorage` cache on every page. `PageDataLayer::add_visitor_cart_fragment()`
   JSON-encodes the customer/cart block (built by the same `add_customer_data` /
   `add_cart_content` server builders) into a data attribute of a cache-safe
   placeholder (`.gtm4wp-wc-visitor-data`, output in `wp_footer`); the client reads it
   (and re-reads it via a `MutationObserver` when the fragment refreshes) and pushes
   it under the same 1.x key names. `esc_attr( wp_json_encode( …, hex flags ) )` keeps
   a hostile customer field from breaking out of the attribute.
   - The payload is a **two-part envelope**, `{ "customer": {…}, "cart": {…} }`, so
     each family can be pushed as its own event. The split is made in PHP, by
     `visitor_cart_datalayer()`, where the builder that produced each key is known —
     the client never classifies keys by name prefix, which would freeze today's
     naming into a client-side validator. A part is omitted when its builder wrote no
     keys, and that test is deliberately **not** about the part's contents: an empty
     cart and an anonymous visitor both still produce their part.
   - **GTM4WP enqueues `wc-cart-fragments` itself** (in
     `WooCommerceModule::enqueue_scripts()`, behind the same predicate), because
     otherwise the channel is simply absent on most stores and the block silently never
     arrives on a page load. WooCommerce enqueues that handle from exactly **one**
     frontend path — `WC_Widget_Cart::widget()` — and that method returns early when
     `woocommerce_widget_cart_is_hidden` is true, whose **default is
     `is_cart() || is_checkout()`**; the Mini-Cart *block* never enqueues it at all. So
     the gap covered both stores with no legacy mini-cart widget **and** the cart and
     checkout pages of stores that have one. The handle itself is registered on every
     frontend request by `WC_Frontend_Scripts::load_scripts()`, and that class's docblock
     explicitly supports third-party enqueueing under WooCommerce's L-1 policy (U97).
   - **What this costs**, on a store that did not already load the script: WooCommerce's
     `wc-ajax=get_refreshed_fragments` request fires **once per browser tab** — its cache
     is `sessionStorage`, so a new tab refetches — including for an empty cart, after
     which that tab goes quiet. For a visitor with Web Storage blocked it fires on
     **every** page load: `cart-fragments.js` has no guard for that case and falls
     straight through to `refresh_cart_fragment()`. The response renders
     `woocommerce_mini_cart()` server-side. This is WooCommerce's request, not one of
     ours, and it is what every mini-cart store already pays — but the storage-blocked
     case is a genuine per-page request, so it belongs in the open next to the hard
     constraint above rather than being described as free.
   - Note what already worked and must not regress: `wc-add-to-cart` replaces the
     fragment nodes itself from the add-to-cart AJAX response, so an AJAX add-to-cart
     delivered the block even with no cart-fragments script. Only page-load delivery
     was missing.
3. **Client runtime** (`gtm4wp-visitor-data.js`) gained the endpoint fetch
   (once-per-session + cookie-gated, with the sessionStorage cache and logout
   cleanup) and the cart-fragment reader. Tier 1 and the endpoint fields are gathered
   into **one** `gtm4wp.visitorData` push so a GTM setup sees them arrive together: on
   a cached view the endpoint replays synchronously and that push is synchronous; on
   the first view of a session it fires when the endpoint responds. The two
   WooCommerce families are pushed as `gtm4wp.customerData` / `gtm4wp.cartData` as
   soon as the fragment is readable, and re-pushed per family on a cart change — see
   *One event per data family* above for why they cannot join the visitor push and for
   the three semantics a GTM setup has to get right. Because all of these values are
   delivered on an event rather than baked into the page-view data layer, GTM tags
   that read them must trigger on the matching Custom Event. When Web Storage is
   unavailable the runtime does **not** fetch (safe default = no extra data, never a
   per-page request).

Regression tests: `VisitorDataEndpointTest` (identity gate — logged-out receives no
user data; hostile header round-trips hex-encoded; no-cache headers),
`PageVariablesModuleTest` (Tier 2/3 field declaration + resolvers, anonymous → null,
hostile CF country raw), `VisitorDataModuleTest` (endpoint config baking, the
per-family event-name map on every config, login gate cookie set/clear/unchanged),
`PageDataLayerTest` (cart-fragment carrier, hostile customer field safe, the
two-part envelope — both parts, each part omitted when its builder wrote nothing,
exact classification, empty cart still delivered, guest customer part, the fragment
key emitted for an empty payload, flag-set parity), and the JS suite
(once-per-session, **cookie gate suppresses the fetch when unchanged**, anonymous
never fetches user data, logout drops cached identity, the two WooCommerce families
as separate events, per-family de-dupe on a cart change, empty cart delivered,
cross-family isolation, event ordering, the malformed/legacy-flat payload matrix
delivering nothing without throwing, and the config-name fallbacks).

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
   gauntlet as the page path, **consume** the delivery marker (so a lingering cookie
   can't re-resolve), and return the event payload plus its dedupe key — the order
   *number* for the purchase, the re-add token for the cart. `resolve_pending_purchase()`
   deliberately does **not** write `_ga_tracked` (it is a public GET); instead — when
   order-tracked flagging is on — it stashes the resolved order id in a *dedicated*
   needs-flag session marker (`PageDataLayer::PENDING_PURCHASE_FLAG_SESSION_KEY`) that
   the authenticated POST beacon (point 5) consumes to write `_ga_tracked`. The GET
   still mutates no order state.
4. **Client dedupe (the point of the phase).** In `gtm4wp-visitor-data.js` the one-shot
   handlers push each event under the same event name the server used, guarded so it
   fires once:
   - **Purchase fallback** reuses the **existing** de-dupe — the same
     `gtm4wp_orderid_tracked` localStorage/cookie guard (keyed on the order number)
     the order-received page's inline block writes (`purchase_dedupe_guard`), read/written
     through one shared protocol. Within one browser a fallback fire on page N and a
     real order-received purchase for the same order can **never both count** — whichever
     runs first records the guard; the other is suppressed (client guard) or omitted
     (server `_ga_tracked` gauntlet + marker clear). *Across devices*, the shared client
     guard is not enough (device B has neither the localStorage guard nor, historically,
     the server flag), so the POST beacon in point 5 restores the `_ga_tracked` parity
     the classic order-received page has. When "Do not flag orders as being tracked" is
     on, the resolver sets `flag = false` and the client writes/reads **no** order-tracked
     state anywhere and sends **no** beacon, matching the server path.
   - **Re-added-to-cart** de-dupes on the per-event token (the WC session re-add key),
     recorded in `localStorage` after the push so a page reload does not re-fire it.
   After delivering, the client clears the event cookie, so a later page makes no request.
5. **Cross-device purchase parity — the confirm-purchase POST beacon.** The classic
   order-received page flags `_ga_tracked` so a second render for the same order is
   suppressed *everywhere*; the fallback GET must not do that (a GET changing order
   state is the anti-pattern). So after a fallback purchase delivery the client fires
   **one** authenticated `POST` to `gtm4wp/v2/confirm-purchase-tracked`
   (`PageDataLayer::confirm_pending_purchase_tracked()`), which loads the order via
   `wc_get_order()` and calls `ProductData::flag_order_tracked()` — the same
   `_ga_tracked` write the order-received page performs (and which already no-ops under
   "Do not flag orders as being tracked"). Its security model mirrors the GET endpoint's:
   the order id comes **only** from the needs-flag session marker (never the request
   body → no IDOR; the callback reads no request param at all), and it is a state
   change so it verifies the `wp_rest` REST nonce (sent as `X-WP-Nonce`, or as the
   `_wpnonce` parameter for the `navigator.sendBeacon` fallback that cannot set headers)
   rather than a capability — guest checkout is common and the request only ever flags
   the caller's own session order. The marker is consumed on the first POST, so it is
   idempotent (a second POST no-ops). The beacon fires **only** on the rare fallback
   delivery — never a new per-page request — uses `fetch` keepalive (or `sendBeacon`)
   so it survives navigation, and is best-effort: a failed beacon degrades to the
   same-browser guard (no cross-device flag that time, no regression). The confirm URL
   rides the client config as a per-key `confirm` entry on the one-shot `actions` list
   (`VisitorField::$confirm_url` → `VisitorDataModule::build_config()`), reusing the
   config's existing `wp_rest` nonce. With the beacon in place the fallback is
   **exactly-once** — not just per browser — with the cross-device double-count closed.

Regression tests: `PageDataLayerTest` (resolver payloads, marker consumption, no
`_ga_tracked` write on the endpoint, the needs-flag marker stashed only when flagging
is on, the confirm-purchase POST flags via the session marker only — no-IDOR, is
idempotent + returns 204, honours "do not flag", the nonce/CSRF permission gate, the
headline cross-device flow where the beacon then suppresses the order-received render,
the two exactly-once orderings, `flag=false`, hostile order number round-trips
hex-encoded, one-shots absent from server HTML),
`HelpersTest` / `PurchaseTrackingTest` / `ListTrackingTest` (event cookie set only in
cache-safe mode + headers-sent guard), `VisitorDataModuleTest` (`config.actions`
routing + the per-key `confirm` map), and the JS suite (cookie gate → no fetch; fires
once; reload with the token in localStorage does not re-fire; the shared guard
suppresses the second purchase in both orderings; the confirm beacon fires with the
nonce after a fallback delivery, and is skipped on `flag=false` / when suppressed as
already tracked / degrades gracefully on failure; one-shots stay out of all three
data-family events and out of the cache; stale cookie cleared).
