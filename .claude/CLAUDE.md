# GTM4WP - Google Tag Manager for WordPress

## Project Overview

WordPress plugin that integrates Google Tag Manager into WordPress websites with comprehensive WooCommerce e-commerce tracking (GA4). The plugin manages GTM container code injection, data layer population, and event tracking for product impressions, cart actions, checkout steps, and purchases.

## Security review system

This repo has a cumulative security-review system under `.security/`:

- **Before writing or modifying any PHP/JS**, read `.security/pre-flight-check.md` and follow it — it points to `.security/code-review-patterns.md` (accumulated recurring issues, project anti-patterns, and false-positive suppressions) which you must actively avoid.
- **`/code-review`** (`.claude/commands/code-review.md`) runs a cumulative review that updates `.security/code-review-checklist.md` (coverage matrix + known findings) and the patterns file, and saves a report to `.security/code-review-report-{date}-{time}.md`. The `code-reviewer` subagent (`.claude/agents/code-reviewer.md`) encodes the same checklist.
- The single most important rule (from the first review): **anything written into the dataLayer or an inline `<script>` must go through `wp_json_encode` with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS`; never blanket-`htmlspecialchars_decode()` script output; `esc_js()` is not for raw `<script>` bodies.**
- ⛔ **Disclosure rule (hard):** this is a public repo — committed == published. Never put a working exploit payload, reproduction steps, or the detail of an unfixed finding into any committed file (security docs, code comments, commit messages). That detail lives only in the git-ignored `.security/code-review-report-*.md`; the canonical rule is at the top of `.security/code-review-checklist.md`.

@.security/pre-flight-check.md

## Test review system

This repo has a cumulative **test-review** system under `.testing/`, the
test-quality sibling of the security system above (same shape, so the two align):

- **Before writing or modifying any test**, read `.testing/pre-flight-check.md` and follow it — it points to `.testing/test-review-patterns.md` (accumulated test smells, project test conventions, and blessed exceptions) which you must actively avoid. A security-relevant code change ships its regression test in the same change.
- **`/test-review`** (`.claude/commands/test-review.md`) reviews the *test suite* (not the code — that is `/code-review`) for coverage completeness and assertion quality, updates `.testing/test-review-checklist.md` (coverage matrix + Test Debt Sweeps + gaps log) and the patterns file, and saves a report to `.testing/test-review-report-{date}-{time}.md`. The `test-reviewer` subagent (`.claude/agents/test-reviewer.md`) encodes the same checklist.
- The single most important rule: **a line that is covered is not a behavior that is asserted** — every value reaching a `<script>`/dataLayer sink needs a regression test with a *hostile* input, not just benign data. Coverage tooling can't see this; the review is what catches it.
- Coverage (optional) is scoped to `src/` in `phpunit.xml`; `composer test:coverage` reports once a PCOV/Xdebug driver is installed. The system also runs without a driver, on mechanical missing-test sweeps + judgment.
- ⛔ **Disclosure rule (hard):** same as the security system — a test gap on a security sink can point at an unfixed vuln, so keep committed `.testing/` notes terse and defer live-vuln detail to the git-ignored `.security/` report.

@.testing/pre-flight-check.md

## Architecture

Version 2.0 is a full OOP rewrite (see the 2.0-dev branch). The public 1.x
integration surface — hooks, template functions, wp-config constants and the
`gtm4wp-options` key — is kept backward compatible through the `compat/` layer.

- **Namespaced OOP PHP** — PSR-4 `GTM4WP\` → `src/`, one class per file, autoloaded via `src/Autoloader.php` (Composer autoloader used for tests). No global procedural code except the `compat/` shims.
- **Entry point**: `duracelltomi-google-tag-manager-for-wordpress.php` — stays parseable on old PHP so it can show a requirements notice; registers the autoloader, loads `compat/constants.php`, then boots `\GTM4WP\Plugin::instance()->boot()` on `plugins_loaded`.
- **Plugin core** (`src/Plugin.php`): singleton that builds the module `Registry` and `Options` service, populates the compat globals, then routes between the admin and frontend code paths (no admin code loads on frontend requests and vice versa). The settings REST controller is registered on `rest_api_init`.
- **Module framework** (`src/Module/`): each feature is a module under `src/Modules/<Name>/` — a lean `<Name>Module` (option defaults + frontend hooks, no translated strings) plus an admin-only `AdminSchema` (labels/groups/sanitizers). Built-ins are listed in `Registry::BUILTIN_MODULES`; third parties add modules via the `gtm4wp_register_modules` action.
- **Options** (`src/Options/`): `Options` reads the single `gtm4wp-options` row and merges module defaults; `Field` describes an option's admin schema. Containers are per-row since 2.0 (`gtm-containers`); the flat 1.x keys are derived, read-only mirrors.
- **Custom hooks**: defined as constants in `compat/constants.php` (e.g. `GTM4WP_WPFILTER_COMPILE_DATALAYER`).
- **Migration**: `src/Migration.php` runs on admin boot to clean up removed 1.x options.

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
- WordPress >= 6.3 (tested up to 6.9.4)
- WooCommerce >= 5.0 (tested up to 10.6.1)

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
- Every PHP file should have a `defined( 'ABSPATH' ) || exit;` guard (except the main plugin file)
- Use `wp_json_encode()` with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS` for any script-context output
