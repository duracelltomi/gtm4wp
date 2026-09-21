/**
 * Consent gate for the media players' third-party SDK requests. This file
 * exists to be BLOCKED: it only raises a flag, as a real enqueued
 * `<script src>` tag a consent manager can refuse. The trackers fetch their
 * SDK from JavaScript, only once a matching embed is found, so no vendor
 * `<script src>` ever passes `script_loader_tag`; this tag is what a rule can
 * match instead.
 *
 * It is NOT the privacy control: served from the site's own domain, a
 * third-party-domain blocklist never matches it. The zero-configuration
 * protection is the trackers' `iframe[src*="<vendor domain>"]` selector: a
 * consent manager that blocks the EMBED (src -> data-src, a placeholder)
 * leaves nothing to match and the vendor is never contacted; consent restores
 * the src and the shared observer resumes. Never widen a selector to match a
 * consent-blocked embed: it silently re-opens the vendor request.
 *
 * How to use this gate, from most to least specific:
 *   - block/dequeue `gtm4wp-<provider>`: stops that one provider's request
 *     (both verbs work; nothing declares a tracker as a dependency);
 *   - block `gtm4wp-media-gate` (this file): stops every provider's request
 *     while the trackers still report players already on the page. Blocking
 *     ONLY: every tracker depends on this handle, so WordPress prints it
 *     whether or not it was dequeued;
 *   - the `gtm4wp_media_sdk_blocked` PHP filter: the same, decided
 *     server-side.
 *
 * A plain `window` property, not a module export: the readers are other
 * bundles. `window.` is correct here because nothing prints this name as a
 * top-level `const` (RI-14 is the opposite case).
 */

window.gtm4wp_media_sdk_allowed = true;
