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

**⭐ Highest impact — check first:** UD-1, UD-2, UD-7, UD-11, UD-14, UD-15, UD-16, UD-18, UD-19, UD-20, UC-1, UC-3

**Upstream Drift (UD):**

| ID | Litmus |
|----|--------|
| UD-1 ⭐ | A hand-maintained mirror of an upstream list carries no expiry date; it looks equally correct on the day it goes stale. |
| UD-20 | Our own output can be an input to *their* render decision, so both branches of that decision are our problem. |
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
| UD-16 ⭐ | "Deprecated but still works" is scheduled work, not a free pass — the same migration is owed either way, plus `debug.log` entries meanwhile. Where the floor forbids removing it, the row carries a **named retire trigger**; loudness sets priority, never whether. |
| UD-17 | A gatekeeper's ruleset is an upstream dependency; its *additions* are the drift. Read its release notes for what is newly forbidden — our code can be frozen and still start failing. Prefer a construction that cannot acquire the violation over a check that catches it afterwards. |
| UD-18 ⭐ | A shipped `Fixed:` bullet is a claim about the past, not evidence. It records what someone believed at the time, and a silent defect never contradicts it — 1.22.3 claimed the select_item timeout was fixed and it was not, through every release since. When triaging "already fixed?", confirm in the released code (`git show <tag>:<path>`), never in the changelog. |
| UD-19 ⭐ | A vendor's **sample code is not the vendor's spec**, and porting the sample inherits whatever it omits. Google's enhanced-conversions page states the gmail plus-suffix rule in prose and leaves it out of the PHP sample on the same page; our helper was ported from the sample, so it was wrong from the day it was written and stayed wrong through a review that cited that URL. Port from the prose; if you port from a sample, diff it against the prose in the same change and say in the docblock which one you followed. |
| UD-20 ⭐ | Code that branches on the **existence** of an upstream object (a data store, class, DOM node) to decide **where it is running** depends on an exclusivity claim — "X exists only on page Y" — that no `silent-missing` row watches: the coupling breaks when X **appears somewhere new**, not when it vanishes. Register the exclusivity claim itself, or better, remove the inference and pass the identity explicitly from the side that knows it. |

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
| UB-6 ⭐ | `Text Domain: duracelltomi-google-tag-manager` **already matches the wp.org slug** — the long `-for-wordpress` name is only the git folder and main-file basename. Measured: 14 live language packs. Renaming the domain would orphan every one of them. Do not "fix" it. |

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

### UD-16: "Deprecated but still works" is scheduled work, not a free pass ⭐

The sibling of UD-4. UD-4 says register the removal date; this one says the *deprecation*
already obliges you, before any date arrives.

Deprecation is a removal with a date attached and a notice bolted on. Deferring costs the
same migration later **plus** the notice in the meantime — and a `_deprecated_function()`
entry in `debug.log` names a function, not a vendor, so the ticket lands here regardless
of whose deprecation it is. Declaring support for a version asserts we use nothing
deprecated in it (see the checklist's "What 'we support WordPress X' means").

Two qualifiers, both load-bearing:

- **The floor can make compliance impossible.** Supporting a *range* means a symbol can be
  deprecated at the ceiling and required at the floor — `__next40pxDefaultSize` is exactly
  that (U76). Then the rule is: remove it as soon as the floor allows, and until then carry
  a **named retire trigger** in the registry row. A deprecation with no trigger is
  indistinguishable from having forgotten about it.
- **Triage by loudness, decide by neither.** Loud (emits a notice) jumps the queue because
  it costs support tickets today. Silent (accepted and discarded upstream) is hygiene only.
  Loudness sets order, never whether.

**The trap this exists to prevent:** a change-detection sweep — "did the new release break
us" — answers a narrower question than the claim we publish, and comes back clean while
rule 1 is being violated by something deprecated three versions ago. The WP 7.1 sweep
(2026-08-06) was exactly this shape: clean, and silent on the question.

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

**It is not only upstream couplings (2026-08-06).** The same inference failed on a
contract wholly inside this repo: our PHP printed three inline globals as top-level
`const` (lexical) while our JS read them as `window.<name>` (property), so
`gtm4wp_list_attribution`, `gtm4wp_datalayer_max_timeout` and `gtm4wp_checkoutwc` were
permanently `undefined` in a browser. Three features shipped dead — one of them
inherited from released 1.x, where a `Fixed:` bullet had already claimed it repaired
(UD-18) — with no error, no red test and no user report, because a silently disabled
tracker looks exactly like a correctly configured one. `silent-*` is a property of the
**failure mode**, not of who owns the other end; see `.security/` **RI-14**.

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

### UD-17: A gatekeeper's ruleset is an upstream dependency, and its *additions* are the drift

**You never fail the check you already knew about. You fail the one that was added.** A
gatekeeper — wordpress.org's plugin review, a store review, a CI policy someone else
owns — publishes a ruleset that moves on its own schedule. Our code can be frozen and
still start failing.

**The worked example.** Plugin Check 2.0 added a `file_type` finding for AI-instruction
directories (`.claude/`, `.cursor/`, `.aider/`) and for `.github/`. Nothing in this
repository changed, no test went red, and no user could have reported it. The shipped
artifact was unaffected — but only because `tools/build-release.js` (U79) packages from
an **allow-list**, `DIST_FILES`, rather than a `.distignore` deny-list.

**That structural property is the actual control, and it generalises.** An allow-list
packager excludes the next dev-only directory *by construction*; a deny-list excludes it
only if somebody remembers to add it. `.security/`, `.testing/`, `.upstream/` and
`.claude/` were all born after the packager and none of them needed a packaging change.
A deny-list would have shipped every one of them until someone noticed. When a packaging
change is proposed, this is the property to protect — the choice is not a style
preference.

**Rule:** register the gatekeeper's ruleset itself, with its release feed as the watch
channel, and read its release notes for *additions* rather than re-running the whole
tool. The generic form of the question is: **what does this ruleset now forbid that it
did not forbid when we last passed it?** And where the answer would be expensive to
absorb, prefer the structural defence — a construction that cannot acquire the violation
— over a check that catches it afterwards.

### UD-18: A shipped `Fixed:` bullet is a claim about the past, not evidence ⭐

Same shape as UD-10 — a published assertion read as an observation — but pointed at our
own history rather than at a compatibility range. A changelog entry records what someone
*believed* they fixed at the time. Nothing re-checks it afterwards, and if the defect was
silent there was never a signal to contradict it.

**Confirmed 2026-08-06.** `CHANGELOG.md` carries, under released **1.22.3**:
*"Fixed: properly reading timeout for select_item eventCallback"*. The option still did
not work. 1.x read `window.gtm4wp_datalayer_max_timeout` while the same 1.x PHP emitted
it as a `const` (RI-14), so the configured value was never found and the hardcoded 2000
ms default applied on every product-list click — through that "fix" and every release
after it. Anyone triaging *"is this already fixed?"* from the changelog would have closed
the report as resolved.

**Rules:**
- When deciding whether something is already fixed — issue triage, forum triage, a
  duplicate check — the changelog names *where to look*, never *what is true*. Confirm
  in the code of the released version: `git show <tag-or-branch>:<path>`.
- Treat this as strongest exactly where the failure is silent. A loud bug that recurs
  gets re-reported, so a wrong `Fixed:` bullet is self-correcting; a silent one is not,
  and the bullet then actively suppresses the next investigation.
- Applies to our own ledgers too, which `.security/` already learned independently
  (Review 14: *"a ledger is a measurement, not a fact"*, and *"do not write a ledger as
  an all-clear"*). A changelog is the user-facing member of that family.

### UD-19: A vendor's sample code is not the vendor's spec ⭐

The usual drift story is that the source moves and our copy stays still (UD-1). This one
is worse, because there is nothing to drift *from*: the copy was incomplete on the day it
was made, and the page it cites has said so the whole time.

**Confirmed 2026-08-10.** Google's enhanced-conversions page carries both a prose list of
the normalization rules and a set of language samples. For gmail/googlemail the prose
requires removing **all periods** *and* **the plus suffix**; the **PHP sample on the same
page implements only the periods** (the Java sample handles both).
`Helpers::normalize_and_hash_email_address()` was ported from the PHP sample under issue
**#321** and inherited exactly that omission, so every customer using a `+tag` gmail
address produced a hash Google could never match. The docblock cited the page — with the
`#php` anchor, which is the tell — and the code disagreed with the page's own prose.

Note what this defeats. UD-2 says a comment is not a control; here the comment was not
merely stale, it was *accurate about the sample it named*. RI-20's "open the page a
docblock cites" was performed and would still pass, because the cited anchor said what
the code did. The question that catches this is one level up: **which artifact on that
page is the specification, and did I port from that one?**

**Rules:**
- **Port from the prose; treat samples as illustration.** A vendor's sample is written
  once, by one person, in N languages, and is not what their own service validates
  against. Where a sample and the prose disagree, the prose is the contract.
- **If you do port a sample, diff it against the prose in the same change** and record
  which you followed in the docblock. Ours now says so explicitly, including a
  *do not "restore" this to match the sample* line, because the sample is still wrong
  upstream and the next reader will find it.
- **Drop the `#anchor` from a cited URL when the anchor is a sample.** It narrows the
  next reviewer's attention to the artifact you must not trust.
- **A per-language sample gap is invisible to every check we own.** No test, no type, no
  lint compares a port against its source; only reading both does. So the registry row's
  claim must name the *rules*, never "matches Google's PHP sample".

### UD-20: A presence check used as a location signal is an exclusivity claim — and the registry only watched the disappearance direction ⭐

**Confirmed in the field 2026-09-01 (GH #463), shipped in 2.0.0 and reported the day of
release.** The blocks tracker fired `add_shipping_info` / `add_payment_info` whenever the
`wc/store/payment` data store was present, on the theory that the store exists only on the
Checkout block page. WooCommerce (observed on 11.0.1) registers it on the Cart block page
too, so both events fired there with no interaction and then repeated on Checkout.

What makes this a registry pattern rather than only a code bug: **U23 existed, was
verified, and looked exactly right.** The row registered the store names, the selectors
and the payload shape, with failure type `silent-missing`, and the 2026-08-06 sweep
confirmed all of it — correctly. The code's actual dependency was a stronger claim the
row never carried: *presence implies the Checkout page*. That claim lived in a code
comment ("i.e. on the Checkout block" — UD-2, a comment is not a control) and in the unit
test's double, which mocked the store as absent on the cart case (UC-3's mirror: the
stand-in encoded our assumption instead of pinning upstream's behavior). Every drift type
in the rubric watches the **vanish** direction — a store, hook or selector disappearing.
An exclusivity inference breaks in the **appear** direction, which nothing watched.

**Rules:**
- **When code infers location or identity from presence, the registered claim is the
  exclusivity statement**, and its probe is "search upstream for every place this thing
  is registered", not "confirm it exists".
- **Prefer deleting the inference over registering it.** The side that knows the identity
  should say so explicitly — the #463 fix has PHP send distinct `cart`/`checkout`
  contexts from `is_cart()`/`is_checkout()` instead of letting JS guess from the store. A
  registered claim needs a sweep to save you; an explicit signal cannot break this way.
- **Capability guards are not this pattern.** `class_exists`/`method_exists` gating
  whether a call can be made degrades cleanly and is blessed (UB-3). The trap is presence
  deciding *where you are* or *which mode to run in* — a claim upstream never made and
  can falsify by registering the thing more widely.
- **Sweep question for existing rows:** for each `silent-missing` row, ask whether the
  code also misbehaves if the dependency shows up in MORE places. If yes, the row is
  understating its claim.

---

## Upstream Coupling anti-patterns

### UD-20: Our output is an input to their render decision

The couplings this file usually tracks run one way: we read a string, a selector or a
version that upstream publishes. This one runs the other way. What GTM4WP prints into
somebody else's hook can decide which of *their* code paths runs, and that makes our own
output an upstream contract with no registry row and no obvious owner.

Confirmed 2026-09-05. WooCommerce's Add to Cart + Options block buffers the output of the
classic `woocommerce_before/after_add_to_cart_*` hooks and scans it for form elements. Any
INPUT, TEXTAREA, SELECT, BUTTON or FORM found there makes it render the classic POST form
instead of its Interactivity API flow. GTM4WP printed a hidden input into that hook, so
every block store rendered the legacy form, and when the input was changed to a span in
2.0.0 the stores switched mode, taking a DOM assumption in our own click tracker with them
(`.security` RI-29).

- **If our output feeds somebody's detector, both branches of their decision are our
  problem**, and the row records which branch we are currently in and what changes if we
  leave it.
- The tell in a diff is a hook callback whose output shape changes: an `<input>` becoming a
  `<span>` is invisible as a *value* change and total as a *mode* change.
- Register the element **kind**, not the markup. What the scan tests for is the tag name.

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
Form 7 6.1.6. Supporting a runtime the vendor no longer patches is a *distribution*
decision, not a correctness one, and it is the maintainer's to make.

**Rationale corrected 2026-08-06.** The original entry also said the plugin "will not force
users onto a PHP upgrade for the sake of one plugin among the many they run". That is not
the maintainer's position and it was inferred, not stated. The actual position: `Requires
PHP` is **enforcement, not advice** — wp.org and core refuse to install or auto-update
below it, so it tells the user why and points them at their host, and maintainers *should*
use it to signal that an unsupported stack is not something to keep supporting. The floor
stays 8.0 for now on timing, **not** on reluctance to push.

This matters because the two rationales diverge on the *next* decision, not this one: the
withdrawn version would never raise the floor, the real one raises it when the trigger
fires. Trigger and measurements: D7b and U89. **Next scheduled check: 1 January 2027.**

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

### UB-6: The text domain matches the wp.org slug — the *directory name* is what differs ⭐

`Text Domain: duracelltomi-google-tag-manager` next to a repo folder and main file called
`duracelltomi-google-tag-manager-for-wordpress` looks like the classic
domain-does-not-match-slug defect. **It is not. Do not "fix" it.**

Measured 2026-08-06:

| Check | Result |
|---|---|
| `api.wordpress.org/plugins/info/1.2/?…slug=duracelltomi-google-tag-manager` | 200, `"slug":"duracelltomi-google-tag-manager"` |
| same API, `…-for-wordpress` | **HTTP 404 — no such plugin** |
| `api.wordpress.org/translations/plugins/1.0/?slug=duracelltomi-google-tag-manager` | **14 active language packs** (ca, en_GB, es_CL/CO/EC/ES/PE/VE, fr_FR, ja, nl_NL, nl_NL_formal, uk, zh_TW) |

The wp.org **slug is the short name**. The long name is only the git directory and the main
file's basename; on a real install the plugin lives in
`wp-content/plugins/duracelltomi-google-tag-manager/`. So domain == slug, and the packs
wp.org builds are named `duracelltomi-google-tag-manager-{locale}.mo` — exactly what core
looks for.

**The mechanism, so the reasoning can be re-derived rather than re-researched.** Core
resolves translations purely by domain — `_load_textdomain_just_in_time()` builds
`"{$path}{$domain}-{$locale}.mo"`, and `WP_Textdomain_Registry::get_paths_for_domain()`
only ever offers `WP_LANG_DIR/plugins` and `/themes`. Nothing derived from the plugin
folder is consulted and **there is no fallback**. wp.org's pack builder names files from
the **GlotPress project slug**, never from the `Text Domain:` header. The two therefore
have to agree, and here they do.

**Renaming the domain to the folder name would be strictly destructive**: it orphans all
14 packs instantly, on every site, with no error, no failing test and no user report —
`silent-missing` at its purest. The damage is also one-directional, since the packs keep
the slug name forever.

**Why this needed writing down:** during the 2026-08-06 Plugin Check evaluation this was
worked up as a probable finding — "translations silently never load, and 2.0 is the cheap
moment to fix it" — and a 351-site rename was planned. The premise was a guess that the
folder name was the slug. One API call refuted it. Register the *evidence*, not the
conclusion, so the next reader can see it was measured. Related: `Domain Path: /languages`
with no such directory is also **harmless** — it only feeds `load_plugin_textdomain()` for
translating plugin-header fields, and `WP_LANG_DIR` is checked ahead of that custom path.

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
| 2026-08-06 | Added **UD-18** (⭐ a shipped `Fixed:` bullet is a claim about the past, not evidence) after finding that `CHANGELOG.md`'s 1.22.3 entry *"properly reading timeout for select_item eventCallback"* did not fix it: 1.x read `window.gtm4wp_datalayer_max_timeout` while 1.x PHP emitted it as a `const`, so the option never took effect in any released version and the hardcoded 2000 ms default always applied. Same shape as UD-10 (a published claim read as an observation) pointed at our own history; strongest exactly where the defect is silent, since a loud bug gets re-reported and a wrong bullet is then self-correcting. Extended **UD-11** with *it is not only upstream couplings* — the same "it evidently works" inference failed on a PHP↔JS contract entirely inside this repo, disabling three shipped features; `silent-*` is a property of the failure mode, not of who owns the other end. Companion security entry: `.security/` **RI-14** (binding vs. name/value). ⭐ tier now UD-1, UD-2, UD-7, UD-11, UD-14, UD-15, UD-16, UD-18, UC-1, UC-3. |
| 2026-09-01 | Added **UD-20** (⭐ a presence check used as a location signal is an exclusivity claim, and the registry only watched the disappearance direction) after GH #463: 2.0.0 shipped the blocks tracker firing `add_shipping_info`/`add_payment_info` on the block Cart page because it inferred "Checkout page" from the presence of `wc/store/payment`, which WooCommerce registers on Cart too. **U23 was registered and verified and the break sailed through anyway** — the row carried the store names/selectors/payload (all still true), not the presence-implies-page inference the code depended on; that inference existed only as a code comment (UD-2) and as the unit-test double's assumption (UC-3's mirror: the cart case mocked the store as absent). Fixed same day on both branches (`ee49e95`/`df82acd`) by deleting the inference: PHP now passes distinct `cart`/`checkout` contexts. U23 rewritten to record the residual legacy-value coupling. ⭐ tier now UD-1, UD-2, UD-7, UD-11, UD-14, UD-15, UD-16, UD-18, UD-19, UD-20, UC-1, UC-3. |
| 2026-08-10 | Added **UD-19** (⭐ a vendor's sample code is not the vendor's spec) after fixing two enhanced-conversions normalization gaps in one session. Google's page states the gmail **plus-suffix** rule in prose and omits it from the PHP sample on the same page; `normalize_and_hash_email_address()` was ported from that sample under #321 and inherited the omission, so every `+tag` gmail address had produced an unmatchable hash since the feature shipped. The sibling gap was **E.164** on phone numbers — an explicitly deferred TODO on #321, closed here by delegating the calling-code table to `WC_Countries::get_country_calling_code()` (new **U111**) plus a 5-entry trunk-prefix mirror (new **U110**), which avoids the libphonenumber dependency that blocked it in 2024. Both fixes ship regression tests proven to fail against the old behavior. UD-19 is the case RI-20's "open the page a docblock cites" does **not** catch: the citation was accurate about the artifact it named, and the artifact was the wrong one. ⭐ tier now UD-1, UD-2, UD-7, UD-11, UD-14, UD-15, UD-16, UD-18, UD-19, UC-1, UC-3. |
