# GTM4WP - Google Tag Manager for WordPress

## Project Overview

WordPress plugin that integrates Google Tag Manager into WordPress websites with comprehensive WooCommerce e-commerce tracking (GA4). The plugin manages GTM container code injection, data layer population, and event tracking for product impressions, cart actions, checkout steps, and purchases.

## Review systems

Three cumulative, self-updating review systems live under `.security/`, `.testing/`
and `.upstream/`. Their pre-flight checklists are loaded into every session by the
SessionStart hooks in `.claude/settings.json` (no need to `@`-import them here) —
follow them **before** writing code (`.security/pre-flight-check.md`), tests
(`.testing/pre-flight-check.md`), or anything that hardcodes an external contract
(`.upstream/pre-flight-check.md`).

Each owns a different question: **is our code safe** (`.security/`) · **does our suite
prove it** (`.testing/`) · **is what we believe about the outside world still true**
(`.upstream/`).

- **Security** (`.security/`): `/code-review` runs the review and updates
  `code-review-checklist.md` + `code-review-patterns.md` (*what* to look for) and
  `threat-model.md` (*how bad* — the A0–A4 actor ladder, severity = lowest actor who
  can reach the sink, scope). Encoded in the `code-reviewer` subagent.
- **Testing** (`.testing/`): `/test-review` audits the *suite* (coverage + assertion
  quality, not the code) and updates `test-review-checklist.md` +
  `test-review-patterns.md`. Encoded in the `test-reviewer` subagent. A
  security-relevant code change ships its regression test in the same change.
- **Upstream** (`.upstream/`): `/upstream-review` sweeps the external dependencies —
  WP/WC/CF7 releases *and pre-releases*, Google specs published as undated doc pages,
  media SDKs, infrastructure headers, toolchain versions — and updates
  `upstream-review-checklist.md` (the registry + Release Radar + drift severity rubric)
  and `upstream-review-patterns.md` (UD/UC/UB). Encoded in the `upstream-reviewer`
  subagent. The default lens is **silence**: the plugin writes into a dataLayer
  something else reads, so most upstream breaks produce no error and no failed test.
  Register every hardcoded external string, list, selector or version in the same
  change that introduces it.
- ⛔ **Disclosure rule (hard):** public repo — committed == published. Never commit an
  exploit payload, repro steps, or unfixed-finding detail into any file (docs, code
  comments, commit messages); live detail stays only in the git-ignored
  `.security/code-review-report-*.md`. Canonical rule: top of `.security/code-review-checklist.md`.

## Architecture

Version 2.0 is a full OOP rewrite (this `master` branch; the released 1.x line
lives on the `1.x` branch). The public 1.x integration surface — hooks, template functions, wp-config constants and the
`gtm4wp-options` key — is kept backward compatible through the `compat/` layer.

- **Namespaced OOP PHP** — PSR-4 `GTM4WP\` → `src/`, one class per file, autoloaded via `src/Autoloader.php` (Composer autoloader used for tests). No global procedural code except the `compat/` shims.
- **Entry point**: `duracelltomi-google-tag-manager-for-wordpress.php` — stays parseable on old PHP so it can show a requirements notice; registers the autoloader, loads `compat/constants.php`, then boots `\GTM4WP\Plugin::instance()->boot()` on `plugins_loaded`.
- **Plugin core** (`src/Plugin.php`): singleton that builds the module `Registry` and `Options` service, populates the compat globals, then routes between the admin and frontend code paths (no admin code loads on frontend requests and vice versa). The settings REST controller is registered on `rest_api_init`.
- **Module framework** (`src/Module/`): each feature is a module under `src/Modules/<Name>/` — a lean `<Name>Module` (option defaults + frontend hooks, no translated strings) plus an admin-only `AdminSchema` (labels/groups/sanitizers). Built-ins are listed in `Registry::BUILTIN_MODULES`; third parties add modules via the `gtm4wp_register_modules` action.
- **Options** (`src/Options/`): `Options` reads the single `gtm4wp-options` row and merges module defaults; `Field` describes an option's admin schema. Containers are per-row since 2.0 (`gtm-containers`); the flat 1.x keys are derived, read-only mirrors.
- **Custom hooks**: defined as constants in `compat/constants.php` (e.g. `GTM4WP_WPFILTER_COMPILE_DATALAYER`).
- **Migration**: `src/Migration.php` runs on admin boot to clean up removed 1.x options.

### Option maturity phases (`Field::PHASE_*`)

Each option carries a per-*field* maturity signal (there is no module-level
status), rendered as an admin badge; `stable` shows none. Full criteria live in
the doc block on the constants in `src/Options/Field.php` — in short:

- **experimental** — correctness depends on things GTM4WP can't verify on every
  site (theme, a third-party embed/player API, external infra like Cloudflare, or
  logic still needing real-world validation). Off by default; caveat in the field
  description.
- **beta** — complete and expected to work on any standard WP/WC install; held in
  beta only until it has enough real-world usage to be called stable.
- **stable** (default, no badge) — proven in the field, no open reproducible
  issues. Promote experimental/beta → stable deliberately (change the constant +
  CHANGELOG bullet) after ~5 months / a few release cycles WITH real adoption AND
  no confirmed reproducible defect.
- **deprecated** — still works but superseded; no new development, replacement
  named in the description, kept for back-compat.

### Key directories

- `src/` — OOP source (PSR-4 `GTM4WP\`)
  - `src/Admin/` — settings page, REST controller, notices, plugin-row links
  - `src/Frontend/` — container code, data layer, script tag, consent defaults, visitor IP
  - `src/Module/` — module framework (`ModuleInterface`, `AbstractModule`, `AdminSchemaInterface`, `Registry`)
  - `src/Modules/<Name>/` — feature modules: Container, PageVariables, ClientDeviceData, UserEvents, MediaEvents, ConsentMode, ContactForm7, WooCommerce, Amp, Blacklist
  - `src/Options/` — options service + field schema
  - `src/Compat/` — read-only `$GLOBALS` mirrors for 1.x consumers
- `compat/` — 1.x public API kept alive: `constants.php` (option/hook/placement constants) and `functions.php` (template functions; frontend-only)
- `js/admin/` — React settings app built on `@wordpress/components`
- `js/frontend/` — per-feature frontend trackers (each becomes its own bundle)
- `build/` — compiled JS output (produced by `npm run build`; git-ignored)
- `tests/` — PHPUnit unit tests under `tests/unit/`; JS tests under `js/admin/test/`
- `tools/` — release build script (`build-release.js`)
- `.security/` — cumulative security-review system (see above)
- `.testing/` — cumulative test-review system (see above; mirrors `.security/`)
- `.upstream/` — cumulative upstream-dependency review system (see above; same quartet)

### Global data (backward-compatible, read-only)

Populated by `src/Compat/Globals.php` from the `Options` service. These are 1.x
mirrors that third-party code reads; internal 2.x code must never read them
back — use the `Options`/`Frontend` services instead.

- `$GLOBALS['gtm4wp_options']` - Merged plugin options
- `$GLOBALS['gtm4wp_datalayer_name']` - Data layer variable name (defaults to `dataLayer`)
- `$GLOBALS['gtm4wp_datalayer_data']` - Data layer content
- `$GLOBALS['gtm4wp_additional_datalayer_pushes']` - Additional push commands
- `$GLOBALS['gtm4wp_container_code_written']` - Whether the container code was already output

## Requirements

- PHP >= 8.0
- WordPress >= 6.3 (tested up to 7.1)
- WooCommerce >= 5.0 (tested up to 11.0.0)

## Coding Standards

- **WordPress Coding Standards** enforced via PHP_CodeSniffer (`phpcs.xml`), scoped to the main plugin file, `uninstall.php`, `compat/`, `src/`, `tests/`
- Rulesets: WordPress, WordPress-Core, WordPress-Extra, PHPCompatibility (`testVersion` 8.0-)
- PSR-4 class/file naming applies under `src/` and `tests/` (WPCS file-name and class-name rules are excluded there)
- **Indentation**: Tabs (4 spaces width)
- **Line endings**: LF (Unix)
- **Charset**: UTF-8
- **Security**: Always use `wp_kses()`, `sanitize_text_field()`, `esc_attr()`, and `wp_json_encode()` with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS` for script-context output
- Run `vendor/bin/phpcs` (or `composer phpcs`) to check code standards before committing

## Build System

- **Tooling**: `@wordpress/scripts` (`wp-scripts`) driving a custom `webpack.config.js`
- **Entry points**: every file in `js/frontend/` becomes its own bundle; `js/admin/index.js` becomes the `admin` bundle. Output goes to `build/`.
- **Commands**:
  - `npm run build` — production build (its `postbuild` runs `npm run lint:js`)
  - `npm run start` — watch/dev build
  - `npm run lint:js` — ESLint over `js/`
  - `npm run release` — package a release ZIP via `tools/build-release.js`
- Always run `npm run build` (and fix `lint:js`) after modifying anything under `js/`

### Script loading & minification (design decision)

The plugin **minifies but does not combine** its frontend scripts — this is
intentional, so don't re-add a plugin-local script concatenator/combiner or a
"load everything in one file" option (1.x had a minifier/combiner; 2.0 dropped
it on purpose):

- **Minification** is handled by the production build: `npm run build` runs
  `wp-scripts build` in webpack production mode (Terser), so every file in
  `build/` is already minified.
- **Combination** is delegated to caching/optimization plugins (WP Rocket,
  Autoptimize, LiteSpeed, …). They combine across the whole site and adapt to the
  host's HTTP setup; a plugin-local combiner would only ever see its own handful
  of files.
- **Conditional, per-feature loading** is the point of the one-entry-point-per-file
  design (`webpack.config.js`): each module enqueues only its own script, only
  when its option is enabled (and, for media, only when the page contains that
  embed), all with the `defer` strategy via `AbstractModule::enqueue_script()`.
  On HTTP/2 this beats a single always-loaded bundle.

## Changelog policy

Every **production-code** change ships a matching bullet under the top `## 2.0`
heading in `CHANGELOG.md` (and usually a mirrored `readme.txt` block). This is
enforced by `.claude/hooks/require-changelog.sh` — a Claude Code `Stop` hook and a
git `commit-msg` hook that will **block** you if production code changed without a
`CHANGELOG.md` change (`[skip changelog]` in the commit message bypasses it).

The non-obvious part: while a version is unreleased, a fix to a feature added in
that *same* version **edits that feature's existing bullet** rather than adding a
new `* Fixed:` — a `Fixed:` bullet is only for a defect that shipped in a released
version. The full policy (theme grouping, `readme.txt` mirror, corollaries,
one-time `git config core.hooksPath .githooks` setup) lives in the **`changelog`
skill** — invoke it when editing `CHANGELOG.md`/`readme.txt` or when the hook blocks you.

## Testing

- **PHP**: PHPUnit 11 with Brain\Monkey for WordPress function mocking; bootstrap `tests/bootstrap.php`, WP/WC stubs under `tests/unit/`. Tests live in `tests/unit/` mirroring the `src/` namespaces (files suffixed `Test.php`). Run `vendor/bin/phpunit` (or `composer test`).
- **JS**: `npm run test:unit` (`wp-scripts test-unit-js`); tests under `js/admin/test/`.
- **Security regression tests**: JSON-encoding / XSS guards live in `tests/unit/Frontend/` — every security-relevant change ships one.
- **Test quality & coverage**: the `.testing/` test-review system (see above) tracks suite coverage and assertion quality. Follow `.testing/pre-flight-check.md` when writing tests; run `/test-review` to audit them. `composer test:coverage` gives a `src/`-scoped coverage report once a PCOV/Xdebug driver is installed.

## WooCommerce Integration

The WooCommerce integration is the largest module, split across
`src/Modules/WooCommerce/`: `WooCommerceModule` (wiring/hooks), `AdminSchema`
(settings), `Helpers`, `ProductData` (product array builder), `ListTracking`,
`PageDataLayer`, and `PurchaseTracking`. Key patterns:

- **Conditional loading**: the module's `is_available()` gates on WooCommerce being active; hooks are registered only when e-commerce tracking is enabled
- **HPOS compatible**: compatibility declared via `FeaturesUtil::declare_compatibility('custom_order_tables', ...)` in the main plugin file
- **GA4 e-commerce events**: `view_item`, `view_item_list`, `add_to_cart`, `remove_from_cart`, `view_cart`, `begin_checkout`, `add_shipping_info`, `add_payment_info`, `purchase`
- **Product data**: built by `ProductData` using the WC CRUD API
- **Extensibility filters**: `GTM4WP_WPFILTER_EEC_PRODUCT_ARRAY`, `GTM4WP_WPFILTER_EEC_CART_ITEM`, `GTM4WP_WPFILTER_EEC_ORDER_ITEM`, `GTM4WP_WPFILTER_EEC_ORDER_DATA`
- **Duplicate purchase prevention**: uses `_ga_tracked` order meta and `gtm4wp_orderid_tracked` cookie

See `.claude/skills/woocommerce-extension-developer/SKILL.md` for WooCommerce coding guidelines.

## Important Conventions

- Namespaced classes under `GTM4WP\` (PSR-4, one class per file); the `gtm4wp_`-prefixed procedural functions only survive as the compat template wrappers in `compat/functions.php`
- Option and hook names come from constants in `compat/constants.php` (`GTM4WP_OPTION_*`, `GTM4WP_WPFILTER_*`, `GTM4WP_WPACTION_*`); their string values are part of the public API and must never change
- Read options through `Options::get()` / `AbstractModule::opt()`, not the backward-compatible globals
- Register new features as modules under `src/Modules/` (lean `Module` + admin `AdminSchema`), keeping the defaults-vs-schema consistency unit test green
- Never use WordPress post functions (`get_post_meta`, etc.) for WooCommerce order data - use the WC CRUD API
- All user-facing strings must use `__()`/`esc_html__()` with text domain `'duracelltomi-google-tag-manager'`
- Every PHP file should have a `defined( 'ABSPATH' ) || exit;` guard, the main plugin file included
- Script-context output uses `wp_json_encode()` with the hex flags — see **Coding Standards** above and `.security/pre-flight-check.md` (loaded each session)
