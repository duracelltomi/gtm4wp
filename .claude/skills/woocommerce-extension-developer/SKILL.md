---
name: woocommerce-extension-developer
description: Guide to create WooCommerce related WordPress plugins that extends WooCommerce functionality with a consistent and maintainable approach.
license: GPL-2.0-or-later
---

# WooCommerce Extension Development Skill

## Overview
Comprehensive guidelines for developing WooCommerce extensions, covering hooks, product data, cart and checkout customization, order management, admin UI, performance optimization, and compatibility. Cross-reference with `wp-security/SKILL.md` for all security patterns.

---

## 1. Plugin Initialization & Bootstrap

### Core Principle
**Load only what is needed, when it is needed.** WooCommerce must be present and active before your plugin initializes. Fail gracefully if it is not.

### Dependency Check Pattern

**This project uses procedural PHP architecture.** Do not introduce classes, singletons, or namespaces. All functions use a plugin-specific prefix (e.g., `gtm4wp_`).

```php
// Check WooCommerce availability using globals
// This is the pattern used in this project (see frontend.php)
if (
    isset( $GLOBALS['gtm4wp_options'] )
    && $GLOBALS['gtm4wp_options'][ GTM4WP_OPTION_INTEGRATE_WCTRACKECOMMERCE ]
    && isset( $GLOBALS['woocommerce'] )
    && version_compare( WC()->version, '5.0', '>=' )
) {
    require_once dirname( __FILE__ ) . '/../integration/woocommerce.php';
}
```

### Procedural Bootstrap Pattern

```php
// Define constants
define( 'MYPLUGIN_VERSION', '1.0.0' );
define( 'MYPLUGIN_PATH', plugin_dir_path( __FILE__ ) );

// Load shared options/constants
require_once MYPLUGIN_PATH . 'common/readoptions.php';

// Use init hook for text domain and early setup
add_action( 'init', 'myplugin_init' );
function myplugin_init() {
    load_plugin_textdomain( 'myplugin', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

// Use plugins_loaded for conditional includes
add_action( 'plugins_loaded', 'myplugin_plugins_loaded' );
function myplugin_plugins_loaded() {
    if ( is_admin() ) {
        require_once MYPLUGIN_PATH . 'admin/admin.php';
    } else {
        require_once MYPLUGIN_PATH . 'public/frontend.php';
    }
}
```

### HPOS (High-Performance Order Storage) Declaration

```php
// REQUIRED for WooCommerce 7.1+ compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true // true = compatible, false = not compatible
        );
    }
});
```

---

## 2. Hook Architecture

### Core Principle
**Hooks are the contract between your plugin and WooCommerce.** Use correct priorities, always unhook what you hook, and never modify core files.

### Hook Priority Reference

```php
// Default priority is 10
// Lower number = earlier execution
// Higher number = later execution

// Run before most plugins
add_action('woocommerce_init', 'my_early_function', 5);

// Run after most plugins
add_action('woocommerce_init', 'my_late_function', 20);

// Run after WooCommerce default output (priority 10) but before others
add_action('woocommerce_after_add_to_cart_button', 'add_recommendation_button', 15);
```

### Essential WooCommerce Action Hooks

```php
// Initialization
add_action('woocommerce_init', 'on_wc_init');
add_action('woocommerce_loaded', 'on_wc_loaded');

// Product page
add_action('woocommerce_before_single_product', 'before_product');
add_action('woocommerce_after_single_product', 'after_product');
add_action('woocommerce_before_add_to_cart_button', 'before_add_to_cart');
add_action('woocommerce_after_add_to_cart_button', 'after_add_to_cart');

// Product loop (shop/archive pages)
add_action('woocommerce_before_shop_loop_item', 'before_loop_item');
add_action('woocommerce_after_shop_loop_item', 'after_loop_item');
add_action('woocommerce_after_shop_loop_item_title', 'after_item_title', 5);

// Cart
add_action('woocommerce_before_cart', 'before_cart');
add_action('woocommerce_after_cart', 'after_cart');
add_action('woocommerce_cart_contents', 'in_cart_contents');
add_action('woocommerce_after_cart_table', 'after_cart_table');

// Checkout
add_action('woocommerce_before_checkout_form', 'before_checkout');
add_action('woocommerce_checkout_before_order_review', 'before_order_review');
add_action('woocommerce_review_order_before_submit', 'before_submit_button');
add_action('woocommerce_checkout_after_order_review', 'after_order_review');

// Order placement
add_action('woocommerce_checkout_order_created', 'on_order_created');
add_action('woocommerce_payment_complete', 'on_payment_complete');
add_action('woocommerce_order_status_changed', 'on_order_status_changed', 10, 4);

// Thankyou page
add_action('woocommerce_thankyou', 'on_thankyou_page');

// My Account
add_action('woocommerce_account_dashboard', 'on_account_dashboard');
```

### Essential WooCommerce Filter Hooks

```php
// Product data
add_filter('woocommerce_product_get_price', 'modify_price', 10, 2);
add_filter('woocommerce_product_get_description', 'modify_description', 10, 2);
add_filter('woocommerce_get_catalog_ordering_args', 'modify_catalog_ordering');

// Cart
add_filter('woocommerce_add_to_cart_validation', 'validate_add_to_cart', 10, 5);
add_filter('woocommerce_cart_item_price', 'modify_cart_item_price', 10, 3);
add_filter('woocommerce_cart_contents_count', 'modify_cart_count');

// Checkout
add_filter('woocommerce_checkout_fields', 'modify_checkout_fields');
add_filter('woocommerce_available_payment_gateways', 'filter_payment_gateways');

// Order
add_filter('woocommerce_order_status_changed', 'on_status_changed', 10, 3);
add_filter('woocommerce_order_get_items', 'modify_order_items', 10, 2);

// Emails
add_filter('woocommerce_email_subject_new_order', 'modify_email_subject', 10, 2);
add_filter('woocommerce_email_headers', 'modify_email_headers', 10, 3);
```

### Unhooking Core Functionality

```php
// Remove default WooCommerce behavior
remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_price', 10);
remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_rating', 10);

// Re-add with different priority
add_action('woocommerce_single_product_summary', 'woocommerce_template_single_price', 15);

// Remove and replace a filter
remove_filter('woocommerce_get_price_html', 'original_price_html_function', 10);
add_filter('woocommerce_get_price_html', 'my_custom_price_html', 10, 2);
```

---

## 3. Product Data & Meta

### Core Principle
**Use WooCommerce product API methods, not direct post meta calls.** This ensures compatibility with HPOS and future WooCommerce changes.

### Reading Product Data

```php
// Get product object
$product = wc_get_product($product_id);

if (!$product) {
    return; // Always check product exists
}

// Core product data - use getters, not get_post_meta()
$product_id    = $product->get_id();
$name          = $product->get_name();
$sku           = $product->get_sku();
$price         = $product->get_price();
$regular_price = $product->get_regular_price();
$sale_price    = $product->get_sale_price();
$stock         = $product->get_stock_quantity();
$status        = $product->get_status();
$description   = $product->get_description();
$short_desc    = $product->get_short_description();
$category_ids  = $product->get_category_ids();
$tag_ids       = $product->get_tag_ids();
$image_id      = $product->get_image_id();
$gallery_ids   = $product->get_gallery_image_ids();
$type          = $product->get_type(); // simple, variable, grouped, external
$weight        = $product->get_weight();
$dimensions    = array(
    'length' => $product->get_length(),
    'width'  => $product->get_width(),
    'height' => $product->get_height(),
);
```

### Product Types & Variations

```php
// Check product type
if ($product->is_type('simple')) {
    // Handle simple product
}

if ($product->is_type('variable')) {
    $variations = $product->get_available_variations();
    $variation_ids = $product->get_children();

    foreach ($variation_ids as $variation_id) {
        $variation = wc_get_product($variation_id);
        $attributes = $variation->get_variation_attributes();
        $variation_price = $variation->get_price();
    }
}

if ($product->is_type('grouped')) {
    $children = $product->get_children();
}
```

### Custom Product Meta

```php
// SECURE: Read custom meta
$ai_score = $product->get_meta('_custom_score', true);

// SECURE: Write custom meta
$product->update_meta_data('_custom_score', floatval($score));
$product->update_meta_data('_custom_last_updated', current_time('mysql'));
$product->save(); // Always call save() after updating meta

// SECURE: Delete custom meta
$product->delete_meta_data('_custom_score');
$product->save();

// For bulk operations, prime the cache first then use CRUD API (see section 8)
```

### Saving Product Data in Admin

```php
// Hook into product save
add_action('woocommerce_process_product_meta', 'save_custom_product_meta');

function save_custom_product_meta($product_id) {
    // Verify nonce
    if (!isset($_POST['woocommerce_meta_nonce']) ||
        !wp_verify_nonce($_POST['woocommerce_meta_nonce'], 'woocommerce_save_data')) {
        return;
    }

    // Check capability
    if (!current_user_can('edit_product', $product_id)) {
        return;
    }

    // Get product object
    $product = wc_get_product($product_id);

    if (!$product) {
        return;
    }

    // Sanitize and save
    if (isset($_POST['_custom_enabled'])) {
        $product->update_meta_data('_custom_enabled', 'yes');
    } else {
        $product->update_meta_data('_custom_enabled', 'no');
    }

    if (isset($_POST['_custom_category'])) {
        $product->update_meta_data(
            '_custom_category',
            sanitize_text_field($_POST['_custom_category'])
        );
    }

    $product->save();
}
```

### Adding Product Data Tabs

```php
// Add a new tab to product data metabox
add_filter('woocommerce_product_data_tabs', 'add_custom_product_tab');

function add_custom_product_tab($tabs) {
    $tabs['custom'] = array(
        'label'  => esc_html__('AI Recommendations', 'myplugin'),
        'target' => 'custom_product_data',
        'class'  => array('show_if_simple', 'show_if_variable'),
        'priority' => 80
    );
    return $tabs;
}

// Render tab content
add_action('woocommerce_product_data_panels', 'render_custom_product_tab');

function render_custom_product_tab() {
    global $post;
    $product = wc_get_product($post->ID);
    $enabled = $product->get_meta('_custom_enabled', true);
    ?>
    <div id="custom_product_data" class="panel woocommerce_options_panel">
        <?php
        woocommerce_wp_checkbox(array(
            'id'          => '_custom_enabled',
            'label'       => esc_html__('Enable AI Recommendations', 'myplugin'),
            'description' => esc_html__('Show AI-powered recommendations on this product.', 'myplugin'),
            'value'       => esc_attr($enabled),
        ));

        woocommerce_wp_text_input(array(
            'id'          => '_custom_custom_tag',
            'label'       => esc_html__('Custom Tag', 'myplugin'),
            'description' => esc_html__('Override the default product tag for AI matching.', 'myplugin'),
            'desc_tip'    => true,
            'value'       => esc_attr($product->get_meta('_custom_custom_tag', true)),
        ));
        ?>
    </div>
    <?php
}
```

---

## 4. Cart Operations

### Core Principle
**Validate before adding to cart.** Use WooCommerce hooks to interact with the cart rather than manipulating it directly.

### Validating Add to Cart

```php
add_filter('woocommerce_add_to_cart_validation', 'validate_add_to_cart', 10, 5);

function validate_add_to_cart($passed, $product_id, $quantity, $variation_id = 0, $variations = array()) {
    $product = wc_get_product($product_id);

    if (!$product) {
        return false;
    }

    // Custom validation - e.g. AI recommendation required before purchase
    $ai_enabled = $product->get_meta('_custom_enabled', true);

    if ($ai_enabled === 'yes') {
        $session_recommendations = WC()->session->get('custom_recommendations');

        if (empty($session_recommendations)) {
            wc_add_notice(
                esc_html__('Please view recommendations before adding to cart.', 'myplugin'),
                'error'
            );
            return false;
        }
    }

    return $passed;
}
```

### Reading Cart Data

```php
// Get cart object
$cart = WC()->cart;

// Cart totals
$subtotal    = $cart->get_subtotal();
$total       = $cart->get_total('float'); // cast to float
$tax_total   = $cart->get_taxes_total();
$item_count  = $cart->get_cart_contents_count();

// Iterate cart items
foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
    $product_id   = $cart_item['product_id'];
    $variation_id = $cart_item['variation_id'];
    $quantity     = $cart_item['quantity'];
    $product      = $cart_item['data']; // WC_Product object

    $name  = $product->get_name();
    $price = $product->get_price();

    // Read custom cart item meta
    $ai_recommendation = $cart_item['custom_recommendation'] ?? null;
}
```

### Adding Custom Cart Item Data

```php
// Add custom data when item is added to cart
add_filter('woocommerce_add_cart_item_data', 'add_custom_cart_data', 10, 3);

function add_custom_cart_data($cart_item_data, $product_id, $variation_id) {
    // Get recommendation session data
    $recommendation_id = WC()->session->get('custom_recommendation_id');

    if ($recommendation_id) {
        $cart_item_data['custom_recommendation_id'] = sanitize_text_field($recommendation_id);
        // Make cart item unique per recommendation
        $cart_item_data['unique_key'] = md5(microtime() . rand());
    }

    return $cart_item_data;
}

// Display custom data in cart
add_filter('woocommerce_get_item_data', 'display_custom_cart_data', 10, 2);

function display_custom_cart_data($item_data, $cart_item) {
    if (!empty($cart_item['custom_recommendation_id'])) {
        $item_data[] = array(
            'key'   => esc_html__('Recommended For You', 'myplugin'),
            'value' => esc_html__('AI Personalized', 'myplugin'),
        );
    }
    return $item_data;
}

// Save custom data to order meta
add_action('woocommerce_checkout_create_order_line_item', 'save_custom_order_item_meta', 10, 4);

function save_custom_order_item_meta($item, $cart_item_key, $values, $order) {
    if (!empty($values['custom_recommendation_id'])) {
        $item->add_meta_data(
            '_custom_recommendation_id',
            sanitize_text_field($values['custom_recommendation_id'])
        );
    }
}
```

---

## 5. Checkout Customization

### Core Principle
**Use WooCommerce checkout hooks and field APIs.** Never output directly to the checkout page without using the provided hook system.

### Adding Custom Checkout Fields

```php
add_filter('woocommerce_checkout_fields', 'add_custom_checkout_fields');

function add_custom_checkout_fields($fields) {
    $fields['billing']['billing_ai_preference'] = array(
        'type'        => 'select',
        'label'       => esc_html__('Recommendation Preference', 'myplugin'),
        'placeholder' => esc_html__('Select preference', 'myplugin'),
        'required'    => false,
        'class'       => array('form-row-wide'),
        'clear'       => true,
        'options'     => array(
            ''        => esc_html__('No preference', 'myplugin'),
            'price'   => esc_html__('Best value', 'myplugin'),
            'popular' => esc_html__('Most popular', 'myplugin'),
            'new'     => esc_html__('Newest arrivals', 'myplugin'),
        ),
        'priority'    => 120,
    );

    return $fields;
}
```

### Validating Custom Checkout Fields

```php
add_action('woocommerce_checkout_process', 'validate_custom_checkout_fields');

function validate_custom_checkout_fields() {
    // Only validate if field is present and has a value
    if (!empty($_POST['billing_ai_preference'])) {
        $allowed = array('price', 'popular', 'new');
        $value = sanitize_text_field($_POST['billing_ai_preference']);

        if (!in_array($value, $allowed)) {
            wc_add_notice(
                esc_html__('Invalid recommendation preference.', 'myplugin'),
                'error'
            );
        }
    }
}
```

### Saving Custom Checkout Data to Order

```php
add_action('woocommerce_checkout_update_order_meta', 'save_custom_checkout_data', 10, 2);

function save_custom_checkout_data($order_id, $data) {
    if (!empty($_POST['billing_ai_preference'])) {
        $allowed = array('price', 'popular', 'new');
        $value = sanitize_text_field($_POST['billing_ai_preference']);

        if (in_array($value, $allowed)) {
            $order = wc_get_order($order_id);

            if ($order) {
                $order->update_meta_data('_custom_preference', $value);
                $order->save();
            }
        }
    }
}
```

### Displaying Custom Data in Order Admin

```php
add_action('woocommerce_admin_order_data_after_billing_address', 'display_custom_order_data', 10, 1);

function display_custom_order_data($order) {
    $preference = $order->get_meta('_custom_preference');

    if ($preference) {
        echo '<p><strong>' . esc_html__('AI Preference:', 'myplugin') . '</strong> ' .
             esc_html($preference) . '</p>';
    }
}
```

---

## 6. Order Management

### Core Principle
**Use WooCommerce order API methods, not direct post meta or database access.** This ensures HPOS compatibility.

### Reading Order Data

```php
// Get order
$order = wc_get_order($order_id);

if (!$order) {
    return;
}

// Order meta
$order_id      = $order->get_id();
$status        = $order->get_status(); // e.g. 'pending', 'processing', 'completed'
$total         = $order->get_total();
$currency      = $order->get_currency();
$payment_method = $order->get_payment_method();
$date_created  = $order->get_date_created();
$customer_id   = $order->get_customer_id();
$customer_note = $order->get_customer_note();

// Billing address
$billing = array(
    'first_name' => $order->get_billing_first_name(),
    'last_name'  => $order->get_billing_last_name(),
    'email'      => $order->get_billing_email(),
    'phone'      => $order->get_billing_phone(),
    'address_1'  => $order->get_billing_address_1(),
    'city'       => $order->get_billing_city(),
    'country'    => $order->get_billing_country(),
);

// Order items
foreach ($order->get_items() as $item_id => $item) {
    $product_id = $item->get_product_id();
    $product    = $item->get_product();
    $name       = $item->get_name();
    $quantity   = $item->get_quantity();
    $total      = $item->get_total();
    $meta       = $item->get_meta('_custom_recommendation_id');
}
```

### Updating Order Status & Notes

```php
// Update status
$order->update_status('completed', esc_html__('Order fulfilled via AI recommendation.', 'myplugin'));

// Add order note (private, visible only to admin)
$order->add_order_note(
    esc_html__('AI Recommendation score: 0.92', 'myplugin'),
    false // false = private note
);

// Add order note (visible to customer)
$order->add_order_note(
    esc_html__('Your personalized products have been dispatched.', 'myplugin'),
    true // true = customer note
);

$order->save();
```

### Order Status Transitions

```php
// React to order status changes
add_action('woocommerce_order_status_changed', 'on_order_status_changed', 10, 4);

function on_order_status_changed($order_id, $from_status, $to_status, $order) {
    // When order moves to processing, send data to Antigravity
    if ($to_status === 'processing') {
        $items = array();

        foreach ($order->get_items() as $item) {
            $items[] = array(
                'product_id' => absint($item->get_product_id()),
                'quantity'   => absint($item->get_quantity()),
            );
        }

        // Queue API call (use Action Scheduler for reliability)
        as_schedule_single_action(
            time(),
            'myplugin_send_purchase_event',
            array('order_id' => $order_id, 'items' => $items),
            'myplugin'
        );
    }
}
```

### Querying Orders

```php
// Use wc_get_orders() - HPOS compatible
$orders = wc_get_orders(array(
    'status'         => array('processing', 'completed'),
    'customer_id'    => get_current_user_id(),
    'date_created'   => '>' . (time() - 30 * DAY_IN_SECONDS),
    'limit'          => 10,
    'orderby'        => 'date',
    'order'          => 'DESC',
    'meta_query'     => array(
        array(
            'key'   => '_custom_preference',
            'value' => 'popular',
        )
    ),
));

foreach ($orders as $order) {
    // Process
}

// NEVER use this directly (not HPOS compatible)
// $wpdb->get_results("SELECT * FROM {$wpdb->prefix}posts WHERE post_type = 'shop_order'");
```

---

## 7. Admin UI

### Core Principle
**Follow WooCommerce admin UI patterns.** Use WooCommerce Settings API for consistency and built-in security handling.

### Adding a Settings Page

```php
add_filter('woocommerce_get_settings_pages', 'add_custom_settings_page');

function add_custom_settings_page($settings) {
    $settings[] = include MYPLUGIN_DIR . 'includes/admin/class-settings.php';
    return $settings;
}

// class-settings.php
class My_Plugin_Settings extends WC_Settings_Page {

    public function __construct() {
        $this->id    = 'custom';
        $this->label = esc_html__('AI Recommendations', 'myplugin');

        parent::__construct();
    }

    public function get_settings() {
        $settings = array(
            array(
                'title' => esc_html__('General Settings', 'myplugin'),
                'type'  => 'title',
                'id'    => 'custom_general_options',
            ),
            array(
                'title'   => esc_html__('Enable Recommendations', 'myplugin'),
                'desc'    => esc_html__('Enable AI-powered product recommendations.', 'myplugin'),
                'id'      => 'custom_enabled',
                'type'    => 'checkbox',
                'default' => 'yes',
            ),
            array(
                'title'    => esc_html__('API Key', 'myplugin'),
                'desc'     => esc_html__('Your Google Antigravity API key.', 'myplugin'),
                'id'       => 'custom_api_key',
                'type'     => 'password',
                'desc_tip' => true,
                'default'  => '',
            ),
            array(
                'title'   => esc_html__('Max Recommendations', 'myplugin'),
                'id'      => 'custom_max_results',
                'type'    => 'number',
                'default' => 4,
                'custom_attributes' => array(
                    'min'  => 1,
                    'max'  => 20,
                    'step' => 1,
                ),
            ),
            array(
                'type' => 'sectionend',
                'id'   => 'custom_general_options',
            ),
        );

        return apply_filters('custom_settings', $settings);
    }
}
```

### Adding Columns to Orders List

```php
// Add column
add_filter('manage_woocommerce_page_wc-orders_columns', 'add_custom_order_column');

function add_custom_order_column($columns) {
    $new_columns = array();

    foreach ($columns as $key => $value) {
        $new_columns[$key] = $value;
        if ($key === 'order_status') {
            $new_columns['custom_score'] = esc_html__('AI Score', 'myplugin');
        }
    }

    return $new_columns;
}

// Render column content
add_action('manage_woocommerce_page_wc-orders_custom_column', 'render_custom_order_column', 10, 2);

function render_custom_order_column($column, $order) {
    if ($column === 'custom_score') {
        $score = $order->get_meta('_custom_score');
        echo $score ? esc_html(number_format(floatval($score), 2)) : '—';
    }
}
```

### Adding Columns to Products List

```php
add_filter('manage_edit-product_columns', 'add_custom_product_column');

function add_custom_product_column($columns) {
    $columns['custom_enabled'] = esc_html__('AI Recommendations', 'myplugin');
    return $columns;
}

add_action('manage_product_posts_custom_column', 'render_custom_product_column', 10, 2);

function render_custom_product_column($column, $post_id) {
    if ($column === 'custom_enabled') {
        $product = wc_get_product($post_id);
        $enabled = $product ? $product->get_meta('_custom_enabled', true) : 'no';
        echo $enabled === 'yes'
            ? '<span class="dashicons dashicons-yes-alt" style="color:green;"></span>'
            : '<span class="dashicons dashicons-minus" style="color:#ccc;"></span>';
    }
}
```

---

## 8. Performance Optimization

### Core Principle
**Minimize queries, cache aggressively, and defer non-critical work.** Product loops and cart pages are high-traffic; every extra query compounds.

### Caching Recommendation Results

```php
function get_recommendations($product_id, $limit = 4) {
    // Build cache key
    $cache_key = 'custom_recommendations_' . $product_id . '_' . $limit;

    // Try cache first
    $cached = get_transient($cache_key);

    if ($cached !== false) {
        return $cached;
    }

    // Fetch from API
    $recommendations = fetch_recommendations_from_api($product_id, $limit);

    if (is_wp_error($recommendations)) {
        return array();
    }

    // Cache for 1 hour
    set_transient($cache_key, $recommendations, HOUR_IN_SECONDS);

    return $recommendations;
}

// Invalidate cache when product is updated
add_action('woocommerce_update_product', 'invalidate_recommendation_cache');

function invalidate_recommendation_cache($product_id) {
    // Delete all cache variants for this product
    for ($i = 1; $i <= 20; $i++) {
        delete_transient('custom_recommendations_' . $product_id . '_' . $i);
    }
}
```

### Avoiding N+1 Queries in Product Loops

**Never use direct `$wpdb` queries against postmeta.** Use `update_meta_cache()` to prime the WordPress object cache in a single query, then access meta via the CRUD API as usual.

```php
// BAD - causes N+1 queries
function bad_product_loop( $product_ids ) {
    foreach ( $product_ids as $id ) {
        $product = wc_get_product( $id );
        $score   = $product->get_meta( '_custom_score', true ); // 1 query per product!
        echo esc_html( $score );
    }
}

// GOOD - prime cache, then use CRUD API
function good_product_loop( $product_ids ) {
    $ids = array_map( 'absint', $product_ids );

    // Single query primes the meta cache for all IDs
    update_meta_cache( 'post', $ids );

    foreach ( $ids as $id ) {
        $product = wc_get_product( $id );
        if ( $product ) {
            $score = $product->get_meta( '_custom_score', true );
            echo $score ? esc_html( number_format( floatval( $score ), 2 ) ) : '—';
        }
    }
}

// ALTERNATIVE - use wc_get_products() which handles caching internally
function good_product_loop_alt( $product_ids ) {
    $products = wc_get_products( array(
        'include' => array_map( 'absint', $product_ids ),
        'limit'   => -1,
    ) );

    foreach ( $products as $product ) {
        $score = $product->get_meta( '_custom_score', true );
        echo $score ? esc_html( number_format( floatval( $score ), 2 ) ) : '—';
    }
}
```

### Action Scheduler for Background Tasks

```php
// Install Action Scheduler (bundled with WooCommerce)
// Use for: API calls, report generation, bulk updates

// Schedule a one-off background job
as_schedule_single_action(
    time() + 60, // run in 60 seconds
    'myplugin_sync_recommendations',
    array('product_id' => $product_id),
    'myplugin' // group
);

// Register the handler
add_action('myplugin_sync_recommendations', 'handle_sync_recommendations');

function handle_sync_recommendations($product_id) {
    $product_id = absint($product_id);
    $product = wc_get_product($product_id);

    if (!$product) {
        return;
    }

    // Make API call in background
    $recommendations = fetch_recommendations_from_api($product_id);

    if (!is_wp_error($recommendations)) {
        $product->update_meta_data('_custom_recommendations', $recommendations);
        $product->update_meta_data('_custom_last_sync', current_time('mysql'));
        $product->save();
    }
}
```

### Script & Style Loading

```php
// Load assets only where needed
add_action('wp_enqueue_scripts', 'enqueue_custom_assets');

function enqueue_custom_assets() {
    // Only load on product pages
    if (!is_product() && !is_cart() && !is_checkout()) {
        return;
    }

    wp_enqueue_style(
        'duracelltomi-google-tag-manager',
        MYPLUGIN_URL . 'assets/css/recommendations.css',
        array(),
        MYPLUGIN_VERSION
    );

    wp_enqueue_script(
        'duracelltomi-google-tag-manager',
        MYPLUGIN_URL . 'assets/js/recommendations.js',
        array('jquery', 'wc-cart'),
        MYPLUGIN_VERSION,
        true // load in footer
    );

    // Pass data to JS
    wp_localize_script('duracelltomi-google-tag-manager', 'gtmData', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('custom_ajax'),
        'productId' => get_the_ID(),
    ));
}
```

---

## 9. WooCommerce Compatibility

### Core Principle
**Test against the last 3 major WooCommerce versions.** Declare compatibility explicitly and watch for deprecation notices.

### Version Compatibility Checks

```php
// Check WooCommerce version
function is_woocommerce_gte($version) {
    return version_compare(WC()->version, $version, '>=');
}

// Use newer APIs conditionally
function get_order_items_compat($order_id) {
    $order = wc_get_order($order_id);

    // WC 7.0+ - use get_items()
    if (is_woocommerce_gte('7.0')) {
        return $order->get_items();
    }

    // Fallback
    return $order ? $order->get_items() : array();
}
```

### Plugin Compatibility Matrix

```
| WooCommerce Version | Status         | Notes                              |
|---------------------|----------------|------------------------------------|
| 8.x                 | Fully tested   | HPOS compatible                    |
| 7.x                 | Tested         | HPOS declared compatible           |
| 6.x                 | Minimum        | Legacy order tables only           |
| 5.x and below       | Not supported  | Missing required hooks             |

| WordPress Version   | Status         |
|---------------------|----------------|
| 6.3+                | Fully tested   |
| 6.0 - 6.2           | Supported      |
| 5.9 and below       | Not supported  |
```

### Deprecation Pattern

```php
// When deprecating your own functions
function old_function_name($param) {
    wc_deprecated_function(
        'old_function_name',
        '1.2.0',
        'new_function_name'
    );
    return new_function_name($param);
}

// Checking for removed WooCommerce functions before use
if (function_exists('wc_get_template_part')) {
    wc_get_template_part('content', 'product');
} else {
    // Fallback implementation
}
```

### WooCommerce Blocks Compatibility

```php
// Register block support for your custom data
add_action('woocommerce_blocks_loaded', function() {
    if (class_exists('Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface')) {
        require_once MYPLUGIN_DIR . 'includes/class-blocks-integration.php';

        add_action(
            'woocommerce_blocks_cart_block_registration',
            function($integration_registry) {
                $integration_registry->register(new My_Blocks_Integration());
            }
        );
    }
});
```

### Classic Hooks That DO NOT Work in Blocks

Layout/presentation hooks do not fire in block-based cart/checkout:
- `woocommerce_before_cart`, `woocommerce_after_checkout_form`, etc.
- `woocommerce_checkout_fields` (for core fields)
- Cart item HTML manipulation hooks

### Classic Hooks That DO Work in Blocks

Data and calculation hooks still fire:
- `woocommerce_cart_calculate_fees`
- `woocommerce_before_calculate_totals`
- `woocommerce_store_api_checkout_order_processed`
- `woocommerce_store_api_checkout_update_order_meta`

### Block Alternatives for Presentation Hooks

- **Slot/Fill patterns**: `ExperimentalOrderMeta`, `ExperimentalOrderShippingPackages`
- **Checkout Filters API**: Modify display values (item names, prices)
- **Additional Checkout Fields API**: Add custom fields
- **Store API filters**: e.g., `woocommerce_store_api_product_quantity_{$value_type}`

### Namespace Warning

Never reference code in the `Automattic\WooCommerce\Internal` namespace - backward compatibility is not guaranteed. Only use public APIs from `Automattic\WooCommerce\Utilities` and other public namespaces.

---

## 10. Development Checklist

Use before each feature merge or release:

### Hooks & Architecture
- [ ] `init` hook used for text domain and early setup; `plugins_loaded` for conditional includes
- [ ] WooCommerce dependency check in place
- [ ] HPOS compatibility declared
- [ ] Hooks use appropriate priorities
- [ ] All hooks cleaned up on deactivation where needed

### Product Data
- [ ] Product getters used instead of `get_post_meta()` directly
- [ ] `$product->save()` called after all meta updates
- [ ] Custom meta keys prefixed with `_myplugin_`

### Cart & Checkout
- [ ] Add to cart validation hooks used
- [ ] Custom checkout fields validated server-side
- [ ] Custom cart data persisted to order meta

### Orders
- [ ] `wc_get_orders()` used instead of direct DB queries
- [ ] Order notes added for significant automated actions
- [ ] Status transitions handled via hooks, not manual updates

### Admin
- [ ] Settings use WooCommerce Settings API
- [ ] Admin pages check `current_user_can()` capability
- [ ] All output in admin templates escaped

### Performance
- [ ] API results cached with transients
- [ ] Cache invalidated on relevant data changes
- [ ] Scripts and styles load only on required pages
- [ ] Bulk operations use single queries (no N+1)
- [ ] Background jobs use Action Scheduler

### Compatibility
- [ ] Plugin header includes `WC requires at least` and `WC tested up to`
- [ ] Tested against minimum supported WooCommerce version
- [ ] No direct `$wpdb` queries for order data (use WC API)

---

## 11. Additional Resources

### WooCommerce Developer Documentation
- [WooCommerce Developer Docs](https://developer.woocommerce.com/)
- [WooCommerce Hook Reference](https://woocommerce.github.io/code-reference/)
- [HPOS Developer Guide](https://developer.woocommerce.com/docs/hpos-compatibility-guide/)
- [Action Scheduler](https://actionscheduler.org/)

### Related Skill Files
- `wp-security/SKILL.md` — All input/output security patterns
- `google-antigravity-api/SKILL.md` — API integration patterns _(to be created)_

---

## Conclusion

WooCommerce is a highly extensible platform, but that extensibility comes with responsibility. Follow the hook system, use the product and order APIs rather than direct database access, and always validate and sanitize at every boundary. The difference between a good WooCommerce extension and a fragile one is almost always found in how consistently these fundamentals are applied.