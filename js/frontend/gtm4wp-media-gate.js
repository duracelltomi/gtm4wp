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
 * How to use it, from most to least specific:
 *   - block/dequeue `gtm4wp-<provider>` — stops that one provider's request, by
 *     stopping the tracker that would make it;
 *   - block/dequeue `gtm4wp-media-gate` (this file) — stops every media
 *     provider's request at once, while the trackers still run and still report
 *     players already on the page;
 *   - the `gtm4wp_media_sdk_blocked` PHP filter — the same effect, decided
 *     server-side, for a site that would rather not rely on a script handle.
 *
 * The flag is deliberately a plain `window` property and not a module export:
 * the readers are other bundles, and it has to survive whatever order they run
 * in. `window.` is correct here precisely because nothing prints this name as a
 * top-level `const` (RI-14 — the trap that rule exists for is the opposite
 * case).
 */

window.gtm4wp_media_sdk_allowed = true;
