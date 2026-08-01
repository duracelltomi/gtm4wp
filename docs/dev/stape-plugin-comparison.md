# GTM4WP vs. the Stape GTM plugin — gap analysis

Reference notes from reading the source of Stape's WordPress plugin
side by side with GTM4WP 2.0. Nothing here is implemented; the point is to
record what the two plugins actually do, which differences matter, and which of
Stape's features we should deliberately *not* copy, so the decision does not
have to be researched again.

Read on `master` at plugin version 2.0.0-beta1, against Stape 2.3.4.

## 1. What the Stape plugin is

- Repo: `github.com/stape-io/gtm-server-side-wordpress-plugin`
- wordpress.org slug: `gtm-server-side`, listed as "Stape Conversion Tracking"
- Version read: 2.3.4. ~7.4k LOC, procedural `GTM_Server_Side_*` classes under
  `includes/`, one singleton trait, `add_settings_field()` admin.
- Four settings tabs: **General**, **Data Layer**, **Webhooks**, **Customer Match**.

It is a funnel plugin for Stape's paid sGTM hosting. Roughly 40% of its surface
(cookie keeper, custom loader, container identifier, container API key, Customer
Match upload) does nothing without a Stape account — see §4.

### It reaches into GTM4WP

`includes/class-gtm-server-side-tracking-gtm4wp.php` implements an "Update
existing web GTM script" mode: it detects GTM4WP, calls
`remove_action( 'wp_head', 'gtm4wp_wp_header_begin' )` at both priority 10 and 2,
re-runs the function inside `ob_start()`, and string-replaces
`www.googletagmanager.com/` and `/gtm.` in our output. It also filters
`gtm4wp_get_the_gtm_tag` for the `<noscript>` iframe.

The radio button that enables this mode is commented out in their 2.3.4 source,
so the path is currently dormant. It nonetheless reads
`$GLOBALS['gtm4wp_options']`, `GTM4WP_OPTION_GTM_CODE`, `GTM4WP_OPTION_LOADEARLY`
and the `gtm4wp_get_the_gtm_tag` filter — a live example of a third party
depending on our `compat/` layer and the 1.x global mirrors. Worth knowing before
anyone prunes them.

Note also that their integration assumes a **single** container ID in
`GTM4WP_OPTION_GTM_CODE`. Since 2.0 containers are per-row in `gtm-containers`
and the flat key is a derived, read-only mirror, that integration degrades to
"first container only" against 2.0.

## 2. Where GTM4WP is already ahead

No work implied by anything in this table; it is here so the comparison is not
read as one-sided.

| Area | GTM4WP 2.0 | Stape 2.3.4 |
|---|---|---|
| Page / post / visitor variables | 5 groups, ~40 fields, PublishPress Authors, WPML/Polylang, Yoast/Rank Math primary category | none |
| Media tracking | 12 players, dynamically inserted players, native GTM video variables | none |
| Consent Mode v2 | 7-flag `default` block, Cookiebot, CookieYes, Axeptio (loads the SDK itself), WebToffee, CSP nonce | none — only a "hold GTM until consent" gate |
| Contact Form 7 / AMP / tag blacklist-whitelist | full | none |
| GA4 ecommerce events | 10, incl. `select_item`, `add_shipping_info`, `add_payment_info` | 8, no shipping/payment step |
| WooCommerce Blocks | Store API extension, Cart/Checkout/Mini-Cart/Product Collection trackers | data attributes on classic markup + a partial `wc-block-grid` hook |
| Purchase deduplication | `_ga_tracked` order meta + cookie/localStorage + order max-age + status allow-list | `_ga` cookie + order id cookie |
| Enhanced Conversions | SHA-256 `user_data`, Google-normalized, built from the order so guests are covered | plaintext in the dataLayer, hashing left to the container |
| Containers | multiple rows, per-row environments, custom domain, custom path, omit-container-id | one container |
| Settings UX | React app, option search, JSON export/import | `add_settings_field` tabs |

## 3. Real gaps

### Tier A — server-side delivery

This is the axis GTM4WP has no code for at all. Confirmed by grep on `master`:
no `wp_remote_post` or `wp_remote_request` anywhere under `src/`.

#### A1 — Server-to-server event forwarding ("webhooks")

Stape POSTs JSON straight from PHP to the configured sGTM container URL:

| Event | Hook |
|---|---|
| `purchase` | `woocommerce_new_order` |
| `order_paid` | order reaches `processing` |
| `order_completed` | order reaches `completed` |
| `refund` | order refunded |

Payload: `event`, `cart_hash`, `ecommerce` (`transaction_id`, `value`, `tax`,
`shipping`, `currency`, `coupon`, `discount_amount`, `items`), `user_data`,
`client_id`, and a harvested cookie bundle so the container can rebuild identity
server-side:

- advertising / click IDs — `_ga`, `_ga_*`, `_fbp`, `_fbc`, `_gcl_aw`,
  `FPGCLAW`, `FPGCLGB`, `FPID`, `FPLC`, `ttclid`, `_ttp`, `_scid`, `_scclid`,
  `_uetmsclkid`, `li_fat_id`, `_epik`, `taboola_cid`, `outbrain_cid`,
  `impact_cid`, `_dcid`
- CMP state — `euconsent-v2`, `addtl_consent`, `usprivacy`, `OptanonConsent`,
  `CookieConsent`, `didomi_token`, `didomi_dcs`, `axeptio_cookies`,
  `axeptio_authorized_vendors`, `cookieyes-consent`, `complianz_consent_status`,
  `borlabs-cookie`, `uc_settings`

Filter hook for extension: `gtm_server_side_purchase_webhook_payload`.

This is what survives ad blockers, a closed browser tab, and ITP. It is the
single largest functional difference between the two plugins.

Their implementation is a bare `wp_remote_post` — no retry, no queue, no
idempotency key, blocking the request that created the order. If we build this,
that part is a floor to beat, not a pattern to copy: it wants Action Scheduler
or `wp_schedule_single_event`, a dedupe key derived from the order, and a
bounded retry.

#### A2 — Refunds

Our `readme.txt` states plainly that WooCommerce refunds are not supported.
A refund is only reachable server-side — the customer is not in a browser when
it happens — so it stays impossible until A1 exists. Cheapest high-value item in
this tier once the transport is there.

#### A3 — First-party loader path

Stape 2.3.0 added a "same-origin proxy": a rewrite rule on a configurable path
that reverse-proxies the loader and collect endpoints through WordPress, so they
are same-origin without touching DNS. Our per-container custom domain + custom
path covers the CNAME case; it does not cover a site with no DNS access. Design
notes in §5.

### Tier B — data-layer parity

Low risk, additive to existing modules.

- **`user_data` beyond `purchase`.** Stape attaches customer identity to `login`,
  `sign_up`, `view_item`, `view_item_list`, `view_cart` and `begin_checkout`.
  We attach it only to `purchase`. Enhanced Conversions for leads and Meta CAPI
  both want identity earlier in the funnel.
- **GA4-standard `login` / `sign_up`.** We push `gtm4wp.userLoggedIn` and
  `gtm4wp.userRegistered` from `UserEvents`; a GA4 tag therefore needs a custom
  trigger and event-name override. Emitting the standard names (or offering
  both) removes a setup step for every user.
- **Cart-token stitching.** Stape mints a UUID in the WC session
  (`_stape_cart_state_token`), echoes it as `cart_state.cart_id` on every
  ecommerce event, and copies it onto the order so an abandoned cart can be
  joined to the eventual purchase. Our `cartContent` (`WCEINCLUDECARTINDL`)
  carries the contents but no stable identifier, so that join is not possible.
- **Item fields we do not emit:** product **image URL** and **GTIN**
  (`WC_Product::get_global_unique_id()`, WooCommerce 9.2+). Both feed dynamic
  remarketing catalogs; neither exists anywhere in `src/`.
- **Event-name namespacing.** Stape can suffix every event it pushes
  (`purchase_stape`) so it cannot collide with another plugin pushing GA4 events
  into the same dataLayer. We have no equivalent, and double-firing caused by two
  tracking plugins is a recurring theme on the wordpress.org forum.
- **GA4 `search` event.** We expose `siteSearchTerm` as a variable but never push
  a `search` event on the results page.
- **`ecomm_pagetype`.** Stape sets `home` / `product` / `category` / `basket` /
  `purchase`. This is the legacy Google Ads remarketing parameter, superseded by
  GA4 events — recorded for completeness, recommend skipping.

### Tier C — composable field mapping

Stape's "Advanced Data Layer settings" metabox lets a merchant build:

- `item_id` and `item_sku` from up to three data points — product ID, parent ID,
  SKU, GTIN, or an arbitrary post meta key — joined by a configurable separator
  with an optional prefix
- `item_brand` from *any* product taxonomy or a custom meta key
- `transaction_id` from order number, order ID, or an order meta key

Ours are fixed toggles: `WCUSESKU`, `WCEECBRANDTAXONOMY`, `WCREMPRODIDPREFIX`,
`WCTRANSACTIONIDPREFIX`. That covers the common cases and is simpler to
understand, but not a merchant whose ad catalog keys on a meta field managed by
another plugin. Today their only route is the
`gtm4wp_eec_item_with_source` filter, i.e. writing PHP.

If this is ever built, the `Field` type system already has a `table` type
(used by `gtm-containers`), so a repeatable-row mapping UI has precedent in the
React settings app.

## 4. Deliberately not copying

- **Cookie Keeper** — prolongs cookie lifetime under ITP via a Stape power-up.
  Requires Stape hosting.
- **Custom loader** fetched from `api.app.stape.io` (with an EU endpoint
  fallback), **Stape container identifier**, **container API key** — all
  account-bound.
- **Google Ads Customer Match / Data Manager ingest** — a cron that uploads
  hashed purchaser email/phone/address to a Google Ads customer list
  (`stape_wp_purchasers`) through Stape's API, with a backfill mode over
  historical orders. Vendor-bound, and a materially different privacy posture
  from anything GTM4WP ships: it exports customer PII to a third party on a
  schedule rather than emitting it into a dataLayer the site owner already
  controls.
- **Consent-gated GTM injection** — rendering the container script as
  `type="text/plain"` and swapping the type once the CMP grants `ad_storage` or
  `analytics_storage`. This works against Consent Mode v2, whose entire premise
  is loading GTM *early* with denied defaults so that consent transitions and
  modeling work. Our `ConsentMode` module is the better answer and should stay
  the recommendation.
- **Stripping security response headers.** Their proxy removes
  `X-Frame-Options`, `X-Content-Type-Options` and `Referrer-Policy` from the
  upstream reply before forwarding it, and forwards nearly every inbound header
  upstream. Neither belongs in anything we ship.

## 5. Design decisions already taken

Recorded now so the implementation does not have to relitigate them.

### PII in server-side payloads: SHA-256 by default

Any server-to-server payload we build defaults to **hashed** identifiers, reusing
the existing `normalize_and_hash()` / `normalize_and_hash_email_address()`
helpers in `src/Modules/WooCommerce/` that already back Enhanced Conversions
(including the Gmail dot/alias normalization). Plaintext is a separate, explicit
opt-in, not the default.

Stape does the opposite — plaintext out of WordPress, hashing inside the
container. Defaulting the other way keeps this work inside the posture
`.security/` already encodes, and still satisfies Google and Meta matching, both
of which accept pre-hashed values.

### Same-origin proxy: designed, not built

Shape if it is ever implemented:

- a `Field::PHASE_EXPERIMENTAL` option, **off by default**
- vendor-neutral: proxies to whatever sGTM URL the container row already
  configures, so Stape, Cloud Run and self-hosted all work the same way
- mechanism, for reference from Stape's version: `add_rewrite_rule` on a
  configurable path → `template_redirect` handler at priority 0 →
  `wp_remote_request` with hop-by-hop headers filtered out → `Domain=` stripped
  from any `Set-Cookie` → WordPress output buffers drained before echoing the
  body → a `.load` suffix rewritten to `.js` upstream

Hard constraints to carry into the implementation:

1. **Do not strip security response headers** (§4).
2. **Never on by default.** Proxying the collect endpoint moves tracking traffic
   onto the origin's PHP worker pool; a busy shop would be routing every hit
   through WordPress.
3. **Assume it fights the page cache.** A rewrite-rule route inside WordPress on
   a full-page-cached site has the same hazards documented in
   `docs/dev/cache-safe-data-layer.md`, and the proxy path must be excluded from
   caching for it to work at all.
4. Rewrite rules must be flushed when the path option changes, and the flush
   debounced — Stape schedules a single flush on `shutdown`.
