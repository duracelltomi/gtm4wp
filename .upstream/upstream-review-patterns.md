# Upstream Review — Learned Patterns

Read this file **before** each `/upstream-review` sweep, and append to it after.
It is also pre-loaded before writing code that touches an external contract, via
`.upstream/pre-flight-check.md`.

> **Companion —** `.upstream/upstream-review-checklist.md` holds the state (what is
> tracked, when it was last verified, what drifted). This file holds the judgment
> (what kinds of upstream movement bite, and how this codebase invites them).

> ⛔ **Disclosure rule (hard):** public repo — committed == published. A drift
> finding that names an unfixed incompatibility is a public statement about a
> defect users are running. Keep committed entries to the pattern and the coupling
> site; live "GTM4WP is currently broken on X" detail stays in the git-ignored
> `.upstream/upstream-review-report-*.md`. Canonical rule: top of
> `.security/code-review-checklist.md`.

**Categories:** Upstream Drift patterns (**UD**) — recurring ways the outside world
moves · Upstream Coupling anti-patterns (**UC**) — how *this* codebase invites
breakage · Blessed Couplings (**UB**) — deliberate, do **not** flag.

---

## Quick Index

Each row is `ID — one-line litmus`.

**⭐ Highest impact — check first:** UD-1, UD-2, UD-7, UD-11, UD-14, UD-15, UC-1, UC-3

**Upstream Drift (UD):**

| ID | Litmus |
|----|--------|
| UD-1 ⭐ | A hand-maintained mirror of an upstream list carries no expiry date; it looks equally correct on the day it goes stale. |
| UD-2 ⭐ | Silent failure needs a canary, not a comment. A code comment recording the last manual sync does not fire when the sync goes stale. |
| UD-3 | A documentation page is a spec with no version and no changelog; diff the *claim*, never the page. |
| UD-4 | An upstream deprecation notice is a dated obligation, not news — it belongs in the ledger with the removal release as its due date. |
| UD-5 | A coupling to another vendor's DOM, CSS class or event name is unversioned by construction; it breaks on *their* minor release, not ours. |
| UD-6 | A floating remote asset (`sdk.latest.js`) updates with no release on either side — there is no version to compare. |
| UD-7 ⭐ | A breaking change is cheapest to absorb while it is still beta or RC; once stable, the same work is a bug fix under time pressure. |
| UD-8 | Dev notes and developer blogs are the only channel for behavior changes that ship with no deprecation notice — the version number will not tell you. |
| UD-9 | Pre-release channels are themselves dependencies; an endpoint that quietly stops resolving turns the whole watch into a false all-clear. |
| UD-10 | "Tested up to" is a claim we publish, not an observation — it goes stale on upstream's schedule, silently. |
| UD-11 ⭐ | "It evidently works, so the assumption must hold" is backwards. For a coupling nothing can observe, an unmeasured assumption is not evidence — measure it, or rate it as if it has already broken. |
| UD-12 | HTTP 200 proves the host is up, not that the API is unchanged. Verify the symbol you call or the section you cite, never the status code. |
| UD-13 | A count or date copied from a summary instead of measured from the file is already wrong. Write the counting rule next to the number. |
| UD-14 ⭐ | A truncated fetch of an **ordered** page is indistinguishable from deletion of everything after the cut. Every long-page probe carries a sentinel: the known-last item. No sentinel in the extraction → `fetch-failed`, never "removed". |
| UD-15 ⭐ | **One finding per upstream.** Two products that ship on different release trains never share a finding, even when they share a vendor — the moment one half is delegated or accepted, the other rides along silently. |

**Upstream Coupling anti-patterns (UC):**

| ID | Litmus |
|----|--------|
| UC-1 ⭐ | A version floor written in N places drifts; the runtime copy and the header copy disagree first. |
| UC-2 | A call into an upstream *internal* class has no compatibility promise; guard it or own the breakage. |
| UC-3 ⭐ | A test stand-in that "accepts and ignores" the prop it stands in for cannot fail when upstream changes it — coverage here is negative information. |
| UC-4 | A three-way contract (our PHP ↔ our JS ↔ their plugin) has no single owner and no single place to check. |
| UC-5 | A regex validating an upstream identifier format rejects the future by default. |
| UC-6 | An upstream string duplicated across two of our own files drifts internally before upstream ever moves. |
| UC-7 | A dependency pulled in as an unpinned *runtime* external breaks on the host's update, not on ours — `npm outdated` cannot see it. |

**Blessed Couplings (UB) — do NOT flag:**

| ID | Litmus |
|----|--------|
| UB-1 | The byte-exact GTM container snippet is deliberately verbatim; drift from *Google's* published snippet is a finding, drift from our formatting preference is not. |
| UB-2 | Dead 1.x constants in `compat/constants.php` for removed external services are backward compatibility, not stale couplings. |
| UB-3 | A `function_exists`/`method_exists`/`class_exists`-guarded optional integration that degrades cleanly is working as designed; absence is not drift. |
| UB-4 | `GROUP_CLASS_IDS` carrying only `sandboxedScripts` is deliberate scope, not an incomplete mirror — do not report "1 of 8". |
| UB-5 | A declared floor *below* the vendor-supported range is deliberate reach, not drift. The PHP 8.0 floor stays despite EOL; report it once per change of circumstance, not every sweep. |

---

## Upstream Drift patterns

### UD-1: A hand-maintained mirror of an upstream list has no expiry ⭐

Any constant array that transcribes a list someone else publishes — entity ids,
vertical names, signal names, event names — is correct on the day it is written and
degrades from then on with no signal. The array does not know it is stale. Neither
does the test suite, which asserts the array against itself.

**Litmus:** does a constant in `src/` enumerate values that a third party decides?
If yes, it needs a registry row with a source URL and a cadence, not a comment.

**Seen in:** `src/Modules/Blacklist/BlacklistModule.php` (94 Google entity ids);
`src/Modules/WooCommerce/Helpers.php` `BUSINESS_VERTICALS` (9 Google Ads verticals);
`src/Frontend/ConsentDefaults.php` (7 Consent Mode v2 signal names).

### UD-2: Silent failure needs a canary, not a comment ⭐

The standard response to UD-1 is a comment recording when the list was last synced.
A comment is inert — it does not fire, does not fail a build, and ages into
decoration. If a coupling's failure mode is `silent-wrong` or `silent-missing`, the
mitigation must be something that *breaks* when the assumption breaks: a unit test
asserting a count or an exact set, a runtime `_doing_it_wrong`, or at minimum a
ledger row with a due date that a sweep actually reads.

**Corollary:** a comment plus a ledger row is fine. A comment alone is not a control.

### UD-3: A doc page is a spec with no version — diff the claim, not the page

Google publishes normative behavior as documentation. There is no version, no
changelog, no feed, and the page is edited constantly for reasons that do not
concern us (navigation, examples, translations). Hashing or snapshotting the page
produces drift on every sweep, which trains the reader to ignore it, which is worse
than not checking.

Record instead the **specific claim** the plugin relies on, phrased so it is true or
false: "the documented restriction entity ids include exactly these 69 tag ids".
Re-verify the claim.

### UD-4: A deprecation notice is a dated obligation

When upstream announces a removal, the useful artifact is not "they announced it" —
it is the target release and its expected date. File it in the registry with
`Next due` set from the removal release, so the sweep raises it while there is still
a window. See UD-7.

### UD-5: Another vendor's DOM is unversioned by construction

CSS classes, element structure, jQuery event names and custom-event detail shapes
are not API. They carry no deprecation policy and change in minor releases. A
selector coupling can only be verified by running against the new version, so its
registry row's check is "test against the RC", not "read the docs".

**Seen in:** ~40 WooCommerce selectors in `js/frontend/gtm4wp-woocommerce.js`; the
eight `wpcf7*` DOM events in `js/frontend/gtm4wp-contact-form-7-tracker.js`;
CheckoutWC's `cfw_step_changed` plus substring matching on *their* step names.

### UD-6: A floating remote asset has no version to compare

`https://embed.cloudflarestream.com/embed/sdk.latest.js` updates whenever the vendor
ships. There is no version on either side of the coupling, so neither a lockfile nor
a release feed helps. These are verified behaviorally or not at all — the registry
row should say so rather than implying a check exists.

### UD-7: Absorb breaking changes while they are still pre-release ⭐

WordPress and WooCommerce both run long public beta/RC cycles, and WooCommerce
announces template and Store API changes on its developer blog months ahead. Work
done in that window is planned work. The identical work done after GA is a bug fix,
usually with users already affected and a support thread open.

This is why every finding carries a **stage** (`announced` / `beta` / `rc` /
`shipped`) and why the checklist opens with a Release Radar. A `silent-wrong`
coupling caught at `rc` is a scheduled task; caught at `shipped` it is a live
data-quality bug.

### UD-8: Dev notes are the only channel for silent behavior changes

Not every core change gets a deprecation notice. Behavior changes — how `wp_kses`
handles an entity, when `WP_Dependencies` clears `to_do`, what a script strategy
does — ship in a release and are described, if anywhere, in a Make/core dev note or
the release Field Guide. A version-number comparison will never surface them.

**Why it matters here:** `src/Frontend/ScriptTag.php`'s entire inline-script output
path rests on `wp_kses()` entity-encoding bare `&` and touching nothing else; the
deferred-checkout fallback in `src/Modules/WooCommerce/PageDataLayer.php` rests on
`wp_script_is( …, 'done' )` reflecting `WP_Dependencies` internals.

### UD-9: The watch channels are dependencies too

An endpoint that 404s, changes shape, or starts requiring auth makes the sweep
report "nothing found" — indistinguishable from "nothing changed". Every channel
gets its own registry row, and a failed fetch is recorded as `fetch-failed`, never
folded into `verified`.

### UD-10: "Tested up to" is a claim, not an observation

`Tested up to` and `WC tested up to` are published assertions about compatibility.
They go stale on upstream's release schedule regardless of anything happening in
this repo, and users read them as current. Bumping them is a decision that should
follow an actual test run, which is exactly what the pre-release window is for.

### UD-11: "It evidently works" is not evidence ⭐

The instinct that a long-running feature must be fine is exactly backwards for
`silent-*` couplings, because silence is the failure mode. If nothing on our side can
observe the breakage, then "no one has complained" carries no information at all.

**This is not hypothetical.** `.security/` finding **#121** was filed **Low at 0.5
confidence** on the reasoning that the feature evidently worked, so an empty companion
restriction key must be inert. Measuring it took ten minutes and it was re-rated
**High**: the empty key disabled the entire container. The finding's own words — *"the
inference was backwards"*.

**Rule:** for a coupling with `detection: none`, rate it as if the drift has already
happened until a probe says otherwise. Reviewers systematically discount what they
cannot see, which inverts the security instinct that "loud" is worse.

### UD-12: A 200 proves the host is up, nothing more

A reachability check on a remote SDK or a docs page answers a question nobody asked.
The page can still load with your cited section deleted; the SDK can still serve with
the constructor renamed. Verify the **symbol the tracker calls** in the fetched body,
or the **heading the docblock cites** — and treat "still 200, section gone" as drift,
not a pass.

### UD-13: A number copied is a number already wrong

Every count in the ledger — how many entity ids, how many overrides pins, how many
selectors — must be measured from the file at seed time with its **counting rule
written beside it**, never carried over from a summary, a previous run, or a code
comment.

**Seen immediately:** this system's own first draft recorded the Blacklist mirror as
69 tag ids / 94 total, from a summary. The file said 71 / 97. The ledger was wrong
before it was finished.

**Corollary:** an unmeasured field is `[ ]`, never a plausible-looking value. Writing
today's date on an unverified claim is the worst possible start (UD-2).

### UD-14: A truncated fetch of an ordered page reads as deletion ⭐

Long documentation pages get truncated in transit — by the fetcher, by a renderer, by
a context limit. When the page is **ordered** (alphabetical, chronological, by
version), truncation removes a contiguous tail, and a naive extraction reports exactly
what a deletion would: "these N items are no longer documented."

**This system produced the false positive on its first sweep.** The GA4 e-commerce
events reference is alphabetical. The fetch cut off mid-`refund`, so
`remove_from_cart`, `select_item`, `view_cart`, `view_item` and `view_item_list` — all
alphabetically at or after the cut — appeared to be gone. Five core e-commerce events,
which would have been the highest-severity finding in the ledger. The tell was
available and missed: **every "missing" item was alphabetically contiguous from the
truncation point.** A deletion that neatly removes the end of the alphabet is not a
deletion.

**Countermeasure — the sentinel rule.** Every probe against a long page records a
**sentinel**: the item that should appear last. The extraction must contain the
sentinel, or the outcome is `fetch-failed` and nothing is recorded as drift. For an
ordered page the sentinel is free — it is the last entry in sort order.

**Second tell:** if the reported "removals" are contiguous in the page's own ordering,
suspect the transport before the source. Real spec removals are scattered.

**Third:** ask the fetch to state explicitly whether the content was truncated and
where. It will often say so, as it did here — but only when asked.

### UD-15: One finding per upstream ⭐

**Never bundle two upstreams into one finding, even when a vendor relationship makes it
tempting.** WordPress and WooCommerce are different software on different release
trains with different compatibility surfaces. So are Gutenberg and WordPress core. So
are the GA4 reference and the Google Ads help centre — the same company publishing two
specs that disagree (see U54/U55 and `new_customer` vs `customer_type`).

**The failure mode is disposal, not detection.** A bundled finding gets *one*
disposition. The moment half of it is delegated, accepted, or fixed, the other half
inherits that status and stops being tracked — without anyone deciding it should.

**This nearly happened on Sweep 1.** WP `Tested up to` and `WC tested up to` were filed
as a single D4 because both were "one major behind". The WP half was delegated to
another session holding for the 7.1 RC Field Guide. Had they stayed one finding, the
WooCommerce half — 11.0.0 against a declared 10.6.1, across the plugin's largest
integration surface — would have been closed by a decision that never considered it.
Maintainer's words: *"never treat WP and WC compat in one finding. Same company, two
software."*

**Rule:** if two halves of a finding could receive different dispositions, they are two
findings. Split at file-writing time, not when someone notices.

**Corollary for the registry:** the same rule governs entries — one entry per
(upstream source × cadence). A row covering two sources cannot be verified, only
half-verified.

---

## Upstream Coupling anti-patterns

### UC-1: A version floor written in N places drifts ⭐

The PHP floor appears in the plugin header, the runtime `version_compare` gate, and
`phpcs.xml`; the WP floor in the header, the runtime gate and `phpcs.xml`; the WC
floor in the plugin header **and** `WooCommerceModule::MIN_WC_VERSION`. Nothing
enforces agreement. The header and the runtime constant are the pair most likely to
disagree, because bumping a header is a documentation act and bumping a constant is
a code act, and they get done in different changes.

**Litmus:** grep every floor literal; if the same requirement appears more than once,
either they agree today by luck or one is already wrong.

### UC-2: A call into an upstream internal has no compatibility promise

`\Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore::is_returning_customer()`
is a WC-Admin analytics internal, not public API, and it is called unguarded from
`src/Modules/WooCommerce/ProductData.php`. Internals move in minor releases without
a deprecation cycle. Either guard the call (`class_exists` + `method_exists`) and
degrade, or accept that the coupling is a `loud`-failure registry row with a
pre-release check.

### UC-3: A stand-in that ignores the prop cannot fail when the prop changes ⭐

`js/admin/test-support/wp-components.js` accepts and ignores
`__next40pxDefaultSize` / `__nextHasNoMarginBottom`. So the admin suite passes
whether or not those props still exist upstream. This is worse than no coverage:
the green suite reads as evidence.

**Generalized:** whenever a test double absorbs the exact thing the coupling depends
on, the coverage is negative information. Such a coupling *rises* a severity level in
the rubric.

### UC-4: A three-way contract has no owner

`wc-api=WC_Quick_View` (a third-party plugin's AJAX URL) is matched in our JS, while
our PHP emits `#gtm4wp_quickview_data` for that same flow. Three parties, no shared
definition, and each can change independently. Same shape: the PHP↔JS cookie-name
pairs (`gtm4wp_item_list_attr`, `gtm4wp_orderid_tracked`, `gtm4wp_woo_event`) where
one side is a class constant and the other a JS literal.

**Mitigation is not "check upstream"** — it is to make the two sides we own share a
single definition, so only one edge is external.

### UC-5: A validating regex rejects the future

`ContainerRows::GTM_ID_PATTERN = /^GTM-[A-Z0-9]+$/` and
`PREVIEW_PATTERN = /^env-[0-9]+$/` encode today's Google identifier grammar as a
gate. If Google issues a container id in any other shape, the plugin rejects a valid
id at both output sinks — and the failure looks like a user error, not a plugin bug.

**Litmus:** does a regex validate a *third party's* identifier format? Then its
strictness is a bet on upstream never changing.

### UC-6: A duplicated upstream string drifts internally first

The Consent Mode v2 signal set is written out in `src/Frontend/ConsentDefaults.php`
and again in `src/Modules/ConsentMode/Axeptio.php`. Before Google ever changes the
spec, these two can disagree with each other through an ordinary edit. Internal
divergence is the cheaper, likelier failure and nothing checks for it.

### UC-7: An unpinned runtime external breaks on the host's update

`@wordpress/*` packages are build-time externals: the admin app runs against
whatever WordPress ships, 6.3 → 7.x. `npm outdated` and the lockfile see the
*devDependency* used to build, not the runtime the user has. So this class of
breakage arrives via a **WordPress core update on the user's site** and is invisible
to every local tool. The only usable signal is the Gutenberg release stream, which
runs ahead of core.

---

## Blessed Couplings — do NOT flag

### UB-1: The byte-exact GTM container snippet

`src/Frontend/ContainerCode.php` reproduces Google's loader snippet verbatim,
single quotes and all, with `esc_js()` on the interpolated values kept for byte
parity. The long rationale is in the file, and byte-exact tests guard it
(`.testing` BE-1). Do not "clean it up".

**But:** drift from *Google's currently published* snippet **is** a finding. The
blessing covers our formatting, not the upstream shape.

### UB-2: Dead 1.x constants for removed external services

`GTM4WP_OPTION_INCLUDE_MISCGEOAPI`, `GTM4WP_OPTION_INCLUDE_WEATHER*` and friends in
`compat/constants.php` refer to geolocation and weather providers the plugin no
longer calls. They survive as backward-compatible option keys whose string values
are frozen public API. They are not live couplings and must not be given registry
rows or "checked".

### UB-5: A floor below the supported range is reach, not drift

GTM4WP declares PHP 8.0, which has been end-of-life since November 2023. Every future
sweep will notice, and the answer is already decided: **it stays.**

The reasoning, on record 2026-08-05: many hosts still run 8.0; **WordPress core itself
requires only PHP 7.4** — measured, not assumed — as do WooCommerce 11.0.0 and Contact
Form 7 6.1.6; and the plugin will not force users onto a PHP upgrade for the sake of
one plugin among the many they run. Supporting a runtime the vendor no longer patches
is a *distribution* decision, not a correctness one, and it is the maintainer's to make.

**Do not re-report "your floor is EOL" every sweep.** Report it once per change of
circumstance — WordPress core raising its own floor above ours, a dependency dropping
8.0, or a security issue that only an unsupported PHP exposes. Those are new facts. The
EOL date is not; it was already true when the decision was made.

**Generalizes:** whenever the plugin deliberately reaches *below* what upstream
supports, the gap is the feature. What the ledger should track is the day the reach
stops being possible — not the gap itself.

### UB-4: `GROUP_CLASS_IDS` is scoped, not incomplete

Google documents eight tag group classes. `BlacklistModule::GROUP_CLASS_IDS` carries
one, `sandboxedScripts`, and that is **deliberate**: it exists to control GTM
**community templates**, which is a specific need, not the first slice of a mirror
someone forgot to finish.

**Do not report "1 of 8".** The count is not the claim. Drift here would be
`sandboxedScripts` being renamed, removed, or changing meaning — and *that* is worth
flagging, because the community-template control depends on it.

**Why this needed writing down:** the first sweep reported the count as a Medium gap.
It was a reasonable read of a mirrored list (UD-1) and it was wrong, because the mirror
was never intended to be complete. When an `accepted` decision lands, **narrow the
claim** rather than muting the finding — otherwise the next sweep re-derives the same
false gap and the maintainer answers the same question again.

### UB-3: A guarded optional integration degrading cleanly

WPML/Polylang (`has_filter` + `function_exists`), PublishPress Authors, Yoast,
`amp_is_request()` with an `is_amp_endpoint()` fallback, `wp_get_environment_type()`
— each is guarded and degrades to a defined behavior when absent. The integration
being unavailable is not drift. Only a change in the *shape* of what it returns is,
and that is what the registry row tracks.

---

## Changelog

| Date | Action |
|------|--------|
| 2026-08-05 | Seeded: UD-1..UD-10, UC-1..UC-7, UB-1..UB-3 from the initial dependency inventory (88 couplings across WordPress core, WooCommerce, third-party plugins, Google specs, media SDKs and the toolchain). |
| 2026-08-05 | Added **UD-14** (⭐ a truncated fetch of an ordered page reads as deletion) after Sweep 1 produced exactly that false positive on U54: five core GA4 e-commerce events reported undocumented because the alphabetical page truncated mid-`refund`. Caught by the maintainer. Countermeasure: every long-page probe carries a sentinel (the known-last item); no sentinel in the extraction → `fetch-failed`. |
| 2026-08-05 | Added UD-11 (⭐ "it evidently works" is not evidence — from `.security/` #121, filed Low @0.5 and re-rated High after ten minutes of measurement), UD-12 (a 200 proves the host is up, nothing more), UD-13 (a copied number is already wrong — this file's own seeding produced 69/94 where the source said 71/97). ⭐ tier now UD-1, UD-2, UD-7, UD-11, UC-1, UC-3. |
