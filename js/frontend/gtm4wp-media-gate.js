/**
 * Consent gate for the media players' third-party SDK requests.
 *
 * This file exists to be BLOCKED. It carries no tracking logic and does nothing
 * but raise a flag; its whole purpose is to be a real, enqueued
 * `<script src="…">` tag that a site — or a consent manager — can refuse, and to
 * have that refusal mean something.
 *
 * Why it is needed. Each media tracker fetches its provider's SDK itself, from
 * JavaScript, and only after it finds a matching embed on the finished page.
 * That is a large privacy win (a page with no player costs the vendor nothing,
 * and hands them no visitor IP, User-Agent or Referer), and it costs one thing:
 * no `<script src="https://player.vimeo.com/…">` tag is ever served, so the
 * request never passes through `script_loader_tag` and a consent manager whose
 * rule names the vendor's domain has nothing to match. A tag it CAN match is
 * what this file puts back.
 *
 * What this file is NOT, so nobody mistakes it for the privacy control. It is
 * served from the site's OWN domain, so a consent manager's third-party-domain
 * blocklist will never match it — pulling this lever takes a rule naming this
 * handle, which is deliberate configuration by somebody who read this. The
 * zero-configuration protection is elsewhere and already works without anyone
 * integrating with GTM4WP: every SDK-fetching tracker selects on
 * `iframe[src*="<vendor domain>"]` and only reaches its SDK fetch once such an
 * embed is found, so a consent manager that blocks the EMBED by domain — moving
 * `src` to `data-src`, or replacing the iframe with a placeholder, which is the
 * ordinary thing these plugins do — leaves the selector nothing to match and the
 * vendor is never contacted. Consent restores the `src`, the shared observer
 * sees the mutation, and tracking resumes. Do not widen a tracker's selector to
 * match a consent-blocked embed (`iframe[data-src*=…]`, a placeholder class):
 * that reads like a bug fix and silently re-opens the vendor request for every
 * visitor who has not consented.
 *
 * How to use this gate, from most to least specific:
 *   - block/dequeue `gtm4wp-<provider>` — stops that one provider's request, by
 *     stopping the tracker that would make it. Both verbs work here: nothing
 *     declares a tracker as a dependency;
 *   - block `gtm4wp-media-gate` (this file) — stops every media provider's
 *     request at once, while the trackers still run and still report players
 *     already on the page. **Blocking only.** `wp_dequeue_script()` on this
 *     handle does nothing: every tracker declares it as a dependency, and
 *     WordPress re-adds a registered dependency to the print queue whether or
 *     not it was dequeued;
 *   - the `gtm4wp_media_sdk_blocked` PHP filter — the same effect, decided
 *     server-side, and the right lever for a site writing its own snippet.
 *
 * The flag is deliberately a plain `window` property and not a module export:
 * the readers are other bundles, and it has to survive whatever order they run
 * in. `window.` is correct here precisely because nothing prints this name as a
 * top-level `const` (RI-14 — the trap that rule exists for is the opposite
 * case).
 */

window.gtm4wp_media_sdk_allowed = true;
