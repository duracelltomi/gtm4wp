# GTM4WP - Google Tag Manager for WordPress

## Project Overview

WordPress plugin that integrates Google Tag Manager into WordPress websites with comprehensive WooCommerce e-commerce tracking (GA4). The plugin manages GTM container code injection, data layer population, and event tracking for product impressions, cart actions, checkout steps, and purchases.

## Architecture

- **Procedural PHP** - No classes or namespaces. All code uses prefixed functions (`gtm4wp_*`) and global arrays.
- **Entry point**: `duracelltomi-google-tag-manager-for-wordpress.php`
- **Admin/Frontend split**: Admin code in `admin/`, frontend in `public/`, integrations in `integration/`
- **Options via constants**: 91+ option constants defined in `common/readoptions.php`
- **Custom hooks**: Defined as constants (e.g., `GTM4WP_WPFILTER_COMPILE_DATALAYER`)

### Key directories

- `admin/` - Settings UI, option management, validation
- `public/` - GTM container output, data layer, frontend hooks
- `integration/` - WooCommerce, AMP, media players, consent tools
- `common/` - Shared constants and option defaults
- `js/` - Source JavaScript (ES6+)
- `dist/` - Compiled/minified JavaScript output
- `tests/` - PHPUnit tests

### Global data

- `$GLOBALS['gtm4wp_datalayer_data']` - Data layer content
- `$GLOBALS['gtm4wp_additional_datalayer_pushes']` - Additional push commands
- `$GLOBALS['gtm4wp_datalayer_name']` - Data layer variable name
- `$GLOBALS['gtm4wp_options']` - Plugin options from database

## Requirements

- PHP >= 7.4
- WordPress >= 3.4.0 (tested up to 6.9.4)
- WooCommerce >= 5.0 (tested up to 9.8)

## Coding Standards

- **WordPress Coding Standards** enforced via PHP_CodeSniffer (`phpcs.xml`)
- Rulesets: WordPress, WordPress-Core, WordPress-Extra, PHPCompatibility
- **Indentation**: Tabs (4 spaces width)
- **Line endings**: LF (Unix)
- **Charset**: UTF-8
- **Security**: Always use `wp_kses()`, `sanitize_text_field()`, `esc_attr()`, `wp_json_encode()` with `JSON_HEX_TAG`
- Run `vendor/bin/phpcs` to check code standards before committing

## Build System

- **Task runner**: Gulp 5.0.0
- **Transpilation**: Babel 7 with `@babel/preset-env` (ES6+ to ES5)
- **Minification**: gulp-uglify
- **Build command**: `npm run build` (compiles `js/*.js` to `dist/js/*.js`)
- Always run the build after modifying any JS file in `js/`

## Testing

- **Framework**: PHPUnit with custom WordPress function mocks (`tests/bootstrap.php`)
- **Run tests**: `vendor/bin/phpunit`
- **Existing tests**: JSON encoding security, XSS prevention

## WooCommerce Integration

The WooCommerce integration (`integration/woocommerce.php`, ~1537 lines) is the largest module. Key patterns:

- **Conditional loading**: Only loaded when WooCommerce is active and e-commerce tracking is enabled
- **HPOS compatible**: Declares compatibility via `FeaturesUtil::declare_compatibility('custom_order_tables', ...)`
- **GA4 e-commerce events**: `view_item`, `view_item_list`, `add_to_cart`, `remove_from_cart`, `view_cart`, `begin_checkout`, `add_shipping_info`, `add_payment_info`, `purchase`
- **Product data**: Built by `gtm4wp_woocommerce_process_product()` using WC CRUD API
- **Extensibility filters**: `GTM4WP_WPFILTER_EEC_PRODUCT_ARRAY`, `GTM4WP_WPFILTER_EEC_CART_ITEM`, `GTM4WP_WPFILTER_EEC_ORDER_ITEM`, `GTM4WP_WPFILTER_EEC_ORDER_DATA`
- **Duplicate purchase prevention**: Uses `_ga_tracked` order meta and `gtm4wp_orderid_tracked` cookie

See `.claude/skills/woocommerce-extension-developer/SKILL.md` for WooCommerce coding guidelines.

## Important Conventions

- Function names: `gtm4wp_` prefix with `snake_case`
- Hook names: Use existing constants from `common/readoptions.php`
- Never use WordPress post functions (`get_post_meta`, etc.) for WooCommerce order data - use WC CRUD API
- All user-facing strings must use `__()` with text domain `'duracelltomi-google-tag-manager'`
- Every PHP file should have `defined( 'ABSPATH' ) || exit;` guard (except main plugin file)
- Use `wp_json_encode()` with `JSON_HEX_TAG` flag for script context output
