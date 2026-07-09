---
name: wordpress-security
description: Guide to maintain creating modern and secure code while developing WordPress plugins.
license: GPL-2.0-or-later
---

# WordPress Plugin Security Hardening Skill

## Overview
Comprehensive security guidelines for WordPress plugin development, with emphasis on WooCommerce extensions and external API integrations.

---

## 1. Input Sanitization

### Core Principle
**NEVER trust user input.** All data from users, URLs, forms, AJAX requests, or external APIs must be sanitized before processing or storage.

### Sanitization Functions by Data Type

```php
// Text input (strips tags, encodes special chars)
$clean_text = sanitize_text_field($_POST['field_name']);

// Textarea (allows line breaks, strips tags)
$clean_textarea = sanitize_textarea_field($_POST['description']);

// Email
$clean_email = sanitize_email($_POST['email']);

// URL
$clean_url = esc_url_raw($_POST['website']);

// File name
$clean_filename = sanitize_file_name($_FILES['upload']['name']);

// SQL LIKE query (escapes % and _)
$clean_search = $wpdb->esc_like($_POST['search_term']);

// HTML content (allows safe HTML tags)
$clean_html = wp_kses_post($_POST['rich_content']);

// Integer
$clean_id = absint($_POST['product_id']);

// Array of integers
$clean_ids = array_map('absint', $_POST['product_ids']);

// Boolean
$clean_bool = (bool) $_POST['is_enabled'];

// Alphanumeric only
$clean_code = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['code']);
```

### WooCommerce-Specific Sanitization

```php
// Product price
$price = wc_format_decimal($_POST['price']);

// Product stock quantity
$stock = wc_stock_amount($_POST['stock']);

// Clean product meta
$meta_value = wc_clean($_POST['custom_meta']);
```

### Custom Sanitization Pattern

```php
function sanitize_api_key($key) {
    // Remove whitespace
    $key = trim($key);
    
    // Allow only specific characters
    $key = preg_replace('/[^a-zA-Z0-9\-_]/', '', $key);
    
    // Validate length
    if (strlen($key) < 20 || strlen($key) > 100) {
        return new WP_Error('invalid_key', 'API key length invalid');
    }
    
    return $key;
}
```

---

## 2. Output Escaping

### Core Principle
**Escape late, escape often.** Always escape data when outputting to HTML, JavaScript, URLs, or attributes.

### Escaping Functions by Context

```php
// HTML content
echo esc_html($user_provided_text);

// HTML attributes
echo '<input value="' . esc_attr($value) . '">';

// URL
echo '<a href="' . esc_url($link) . '">Link</a>';

// JavaScript
echo '<script>var message = "' . esc_js($message) . '";</script>';

// Textarea content
echo '<textarea>' . esc_textarea($content) . '</textarea>';

// Translation with variables (SECURE)
echo sprintf(
    esc_html__('Welcome, %s!', 'textdomain'),
    esc_html($username)
);

// Translation with HTML (use wp_kses_post)
echo wp_kses_post(
    sprintf(__('Click <a href="%s">here</a>', 'textdomain'), esc_url($url))
);
```

### Admin UI Escaping

```php
// Admin notices
echo '<div class="notice notice-success"><p>' . 
     esc_html__('Settings saved successfully.', 'textdomain') . 
     '</p></div>';

// Settings field
add_settings_field(
    'api_key',
    esc_html__('API Key', 'textdomain'),
    function($args) {
        $value = get_option('my_api_key');
        echo '<input type="text" name="my_api_key" value="' . 
             esc_attr($value) . '" class="regular-text">';
    }
);
```

### JSON Output (AJAX)

```php
// ALWAYS use wp_send_json functions
wp_send_json_success(array(
    'message' => 'Product updated',
    'product_id' => absint($product_id)
));

wp_send_json_error(array(
    'message' => esc_html__('Invalid product ID', 'textdomain')
));

// NEVER use echo or print with json_encode directly
// BAD: echo json_encode($data);
```

---

## 3. CSRF Protection (Nonces)

### Core Principle
**Verify intent.** All state-changing operations must verify a nonce to prevent Cross-Site Request Forgery.

### Form Nonces

```php
// Create nonce in form
<form method="post" action="">
    <?php wp_nonce_field('save_product_settings', 'product_settings_nonce'); ?>
    <input type="text" name="product_name">
    <button type="submit">Save</button>
</form>

// Verify nonce when processing
if (!isset($_POST['product_settings_nonce']) || 
    !wp_verify_nonce($_POST['product_settings_nonce'], 'save_product_settings')) {
    wp_die(esc_html__('Security check failed', 'textdomain'));
}
```

### URL Nonces

```php
// Create nonce in URL
$delete_url = wp_nonce_url(
    admin_url('admin.php?action=delete_product&id=' . $product_id),
    'delete_product_' . $product_id
);

echo '<a href="' . esc_url($delete_url) . '">Delete</a>';

// Verify in handler
if (!isset($_GET['_wpnonce']) || 
    !wp_verify_nonce($_GET['_wpnonce'], 'delete_product_' . $product_id)) {
    wp_die(esc_html__('Security check failed', 'textdomain'));
}
```

### AJAX Nonces

```php
// Pass nonce to JavaScript
wp_localize_script('my-ajax-script', 'myAjax', array(
    'ajaxurl' => admin_url('admin-ajax.php'),
    'nonce' => wp_create_nonce('my_ajax_action')
));

// JavaScript (send with request)
jQuery.ajax({
    url: myAjax.ajaxurl,
    type: 'POST',
    data: {
        action: 'my_ajax_action',
        nonce: myAjax.nonce,
        product_id: productId
    },
    success: function(response) {
        console.log(response);
    }
});

// PHP (verify in AJAX handler)
add_action('wp_ajax_my_ajax_action', 'handle_my_ajax_action');

function handle_my_ajax_action() {
    // Verify nonce FIRST
    check_ajax_referer('my_ajax_action', 'nonce');
    
    // Verify capability
    if (!current_user_can('edit_products')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    // Process request
    $product_id = absint($_POST['product_id']);
    
    // Return response
    wp_send_json_success(array('message' => 'Success'));
}
```

### REST API Nonces

```php
// For custom REST endpoints, use nonce middleware
register_rest_route('myplugin/v1', '/update-settings', array(
    'methods' => 'POST',
    'callback' => 'update_settings_callback',
    'permission_callback' => function() {
        return current_user_can('manage_options');
    }
));

// If accessed via JavaScript, send nonce in header
wp_localize_script('my-rest-script', 'wpApiSettings', array(
    'root' => esc_url_raw(rest_url()),
    'nonce' => wp_create_nonce('wp_rest')
));

// JavaScript
fetch(wpApiSettings.root + 'myplugin/v1/update-settings', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': wpApiSettings.nonce
    },
    body: JSON.stringify(data)
});
```

---

## 4. SQL Injection Prevention

### Core Principle
**ALWAYS use prepared statements.** Never concatenate user input into SQL queries.

### Basic Prepared Statements

```php
global $wpdb;

// SELECT with placeholder
$product_id = absint($_GET['product_id']);
$results = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}my_table WHERE product_id = %d",
    $product_id
));

// INSERT with placeholders
$wpdb->insert(
    $wpdb->prefix . 'my_table',
    array(
        'product_id' => $product_id,
        'api_key' => $api_key,
        'created_at' => current_time('mysql')
    ),
    array('%d', '%s', '%s') // format types
);

// UPDATE with placeholders
$wpdb->update(
    $wpdb->prefix . 'my_table',
    array('status' => 'active'),      // data
    array('product_id' => $product_id), // where
    array('%s'),                         // data format
    array('%d')                          // where format
);

// DELETE with placeholders
$wpdb->delete(
    $wpdb->prefix . 'my_table',
    array('product_id' => $product_id),
    array('%d')
);
```

### Placeholder Types

```php
// %d = integer
// %f = float
// %s = string

$wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}products 
     WHERE price > %f AND category = %s AND stock > %d",
    99.99,
    'electronics',
    10
);
```

### IN Clause (Multiple Values)

```php
// Sanitize array of IDs
$product_ids = array_map('absint', $_POST['product_ids']);

// Create placeholders
$placeholders = implode(',', array_fill(0, count($product_ids), '%d'));

// Use in query
$results = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}products WHERE id IN ($placeholders)",
    $product_ids
));
```

### LIKE Queries

```php
$search_term = sanitize_text_field($_GET['search']);

// Escape LIKE wildcards
$like_term = '%' . $wpdb->esc_like($search_term) . '%';

$results = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}products WHERE name LIKE %s",
    $like_term
));
```

### Never Do This (Vulnerable)

```php
// NEVER EVER DO THIS - SQL INJECTION VULNERABILITY
$product_id = $_GET['product_id'];
$results = $wpdb->get_results(
    "SELECT * FROM {$wpdb->prefix}products WHERE id = $product_id"
);

// ALSO VULNERABLE
$search = $_GET['search'];
$results = $wpdb->get_results(
    "SELECT * FROM {$wpdb->prefix}products WHERE name LIKE '%$search%'"
);
```

---

## 5. Capability & Permission Checks

### Core Principle
**Check permissions early and often.** Never assume a user has the right to perform an action.

### Common Capabilities

```php
// Admin access
if (!current_user_can('manage_options')) {
    wp_die(esc_html__('Unauthorized access', 'textdomain'));
}

// WooCommerce product management
if (!current_user_can('edit_products')) {
    wp_send_json_error('Insufficient permissions');
}

// Specific product editing
if (!current_user_can('edit_product', $product_id)) {
    wp_send_json_error('Cannot edit this product');
}

// Shop management
if (!current_user_can('manage_woocommerce')) {
    return;
}
```

### Admin Menu/Page Protection

```php
add_menu_page(
    'Plugin Settings',
    'My Plugin',
    'manage_options', // REQUIRED capability
    'my-plugin-settings',
    'render_settings_page'
);

function render_settings_page() {
    // Double-check capability
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Unauthorized access', 'textdomain'));
    }
    
    // Render page
}
```

### AJAX Handler Protection

```php
add_action('wp_ajax_update_product', 'update_product_handler');

function update_product_handler() {
    // 1. Verify nonce
    check_ajax_referer('update_product_nonce', 'nonce');
    
    // 2. Check capability
    if (!current_user_can('edit_products')) {
        wp_send_json_error(array(
            'message' => esc_html__('Insufficient permissions', 'textdomain')
        ));
    }
    
    // 3. Check specific object permission
    $product_id = absint($_POST['product_id']);
    if (!current_user_can('edit_product', $product_id)) {
        wp_send_json_error(array(
            'message' => esc_html__('Cannot edit this product', 'textdomain')
        ));
    }
    
    // 4. Process request
    // ...
}
```

### Custom Capabilities

```php
// Add custom capability to role
$role = get_role('shop_manager');
$role->add_cap('manage_vertex_ai_settings');

// Check custom capability
if (!current_user_can('manage_vertex_ai_settings')) {
    wp_send_json_error('Unauthorized');
}
```

---

## 6. Encryption & Secrets Management

### Core Principle
**Never store sensitive data in plain text.** Use WordPress salts for key derivation, encrypt at rest, protect in transit.

### Key Derivation (Recommended Pattern)

```php
/**
 * Derive encryption key from WordPress salts using HKDF
 * This is SECURE - keys are derived, not stored
 */
function get_encryption_key() {
    // Use WordPress salts as key material
    $key_material = AUTH_KEY . SECURE_AUTH_KEY . LOGGED_IN_KEY . NONCE_KEY;
    
    // Derive a key using HKDF (key derivation function)
    $encryption_key = hash_hkdf(
        'sha256',                    // hash algorithm
        $key_material,               // input key material
        32,                          // output length (256 bits)
        'vertex-ai-encryption-key',  // context/purpose
        AUTH_SALT                    // salt
    );
    
    return $encryption_key;
}
```

### Encrypting Sensitive Data

```php
/**
 * Encrypt sensitive data before storage
 */
function encrypt_data($plaintext) {
    $key = get_encryption_key();
    
    // Generate random IV
    $iv_length = openssl_cipher_iv_length('aes-256-cbc');
    $iv = openssl_random_pseudo_bytes($iv_length);
    
    // Encrypt
    $encrypted = openssl_encrypt(
        $plaintext,
        'aes-256-cbc',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );
    
    // Combine IV and encrypted data
    $result = base64_encode($iv . $encrypted);
    
    return $result;
}

/**
 * Decrypt sensitive data
 */
function decrypt_data($encrypted_data) {
    $key = get_encryption_key();
    $data = base64_decode($encrypted_data);
    
    // Extract IV
    $iv_length = openssl_cipher_iv_length('aes-256-cbc');
    $iv = substr($data, 0, $iv_length);
    $encrypted = substr($data, $iv_length);
    
    // Decrypt
    $plaintext = openssl_decrypt(
        $encrypted,
        'aes-256-cbc',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );
    
    return $plaintext;
}
```

### Storing API Credentials

```php
// SECURE: Encrypt before storing
$api_key = sanitize_text_field($_POST['api_key']);
$encrypted_key = encrypt_data($api_key);
update_option('vertex_ai_api_key', $encrypted_key, false); // autoload = false

// SECURE: Decrypt when retrieving
$encrypted_key = get_option('vertex_ai_api_key');
$api_key = decrypt_data($encrypted_key);

// INSECURE: Never do this
update_option('api_key', $_POST['api_key']); // Plain text storage - BAD
```

### What NOT to Do

```php
// NEVER store encryption keys in:
// - Database
// - wp-content files
// - Version control
// - JavaScript
// - HTML comments
// - Cookie values

// VULNERABLE PATTERN - DO NOT USE
define('ENCRYPTION_KEY', 'hardcoded-key-123'); // BAD
update_option('encryption_key', wp_generate_password(32)); // BAD
```

### Environment Variables (Alternative)

```php
// If using environment variables (wp-config.php)
define('VERTEX_AI_API_KEY', getenv('VERTEX_AI_API_KEY'));

// Access
$api_key = defined('VERTEX_AI_API_KEY') ? VERTEX_AI_API_KEY : '';

// Ensure wp-config.php is not in version control
```

---

## 7. File Upload Security

### Core Principle
**Validate everything about uploaded files.** File type, size, name, content, destination.

### Secure File Upload Handler

```php
function handle_file_upload() {
    // 1. Check nonce
    check_ajax_referer('file_upload_nonce', 'nonce');
    
    // 2. Check capability
    if (!current_user_can('upload_files')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    // 3. Validate file was uploaded
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        wp_send_json_error('Upload failed');
    }
    
    // 4. Validate file size
    $max_size = 5 * 1024 * 1024; // 5MB
    if ($_FILES['file']['size'] > $max_size) {
        wp_send_json_error('File too large');
    }
    
    // 5. Validate file type
    $allowed_types = array('image/jpeg', 'image/png', 'application/pdf');
    $file_type = wp_check_filetype_and_ext(
        $_FILES['file']['tmp_name'],
        $_FILES['file']['name']
    );
    
    if (!in_array($file_type['type'], $allowed_types)) {
        wp_send_json_error('Invalid file type');
    }
    
    // 6. Sanitize filename
    $filename = sanitize_file_name($_FILES['file']['name']);
    
    // 7. Use WordPress upload handler (handles security)
    $upload = wp_handle_upload($_FILES['file'], array(
        'test_form' => false,
        'mimes' => array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png' => 'image/png',
            'pdf' => 'application/pdf'
        )
    ));
    
    if (isset($upload['error'])) {
        wp_send_json_error($upload['error']);
    }
    
    // 8. Store file info securely
    $file_data = array(
        'url' => esc_url_raw($upload['url']),
        'path' => sanitize_text_field($upload['file']),
        'type' => sanitize_mime_type($upload['type'])
    );
    
    wp_send_json_success($file_data);
}
```

### Image-Specific Validation

```php
function validate_image_upload($file_path) {
    // Verify it's actually an image
    $image_info = getimagesize($file_path);
    
    if ($image_info === false) {
        return new WP_Error('invalid_image', 'Not a valid image');
    }
    
    // Check dimensions
    list($width, $height) = $image_info;
    
    if ($width > 4000 || $height > 4000) {
        return new WP_Error('image_too_large', 'Image dimensions too large');
    }
    
    // Verify MIME type matches extension
    $allowed_types = array(IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF);
    
    if (!in_array($image_info[2], $allowed_types)) {
        return new WP_Error('invalid_type', 'Invalid image type');
    }
    
    return true;
}
```

### CSV Upload (External Data)

```php
function import_csv() {
    // Validate upload
    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        return new WP_Error('upload_error', 'CSV upload failed');
    }
    
    // Validate extension
    $file_ext = strtolower(pathinfo($_FILES['csv']['name'], PATHINFO_EXTENSION));
    if ($file_ext !== 'csv') {
        return new WP_Error('invalid_file', 'Must be a CSV file');
    }
    
    // Read and validate content
    $handle = fopen($_FILES['csv']['tmp_name'], 'r');
    
    if ($handle === false) {
        return new WP_Error('read_error', 'Cannot read CSV');
    }
    
    // Process rows
    $row_count = 0;
    while (($data = fgetcsv($handle)) !== false) {
        // Sanitize each cell
        $sanitized_row = array_map('sanitize_text_field', $data);
        
        // Validate data
        if (count($sanitized_row) < 3) {
            continue; // Skip invalid rows
        }
        
        // Process row
        // ...
        
        $row_count++;
        
        // Limit rows to prevent DoS
        if ($row_count > 10000) {
            break;
        }
    }
    
    fclose($handle);
    
    // Delete temp file
    unlink($_FILES['csv']['tmp_name']);
    
    return $row_count;
}
```

---

## 8. External API Security

### Core Principle
**Validate all API responses.** Never trust external data, implement rate limiting, handle errors securely.

### Secure API Request Pattern

```php
function make_vertex_ai_request($endpoint, $data) {
    // 1. Get and decrypt API key
    $encrypted_key = get_option('vertex_ai_api_key');
    $api_key = decrypt_data($encrypted_key);
    
    if (empty($api_key)) {
        return new WP_Error('missing_key', 'API key not configured');
    }
    
    // 2. Build request
    $url = 'https://api.vertex-ai.google.com/' . sanitize_text_field($endpoint);
    
    $args = array(
        'method' => 'POST',
        'timeout' => 30,
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ),
        'body' => wp_json_encode($data),
        'sslverify' => true // ALWAYS verify SSL
    );
    
    // 3. Make request with error handling
    $response = wp_remote_post($url, $args);
    
    // 4. Check for HTTP errors
    if (is_wp_error($response)) {
        error_log('Vertex AI API Error: ' . $response->get_error_message());
        return new WP_Error('api_error', 'API request failed');
    }
    
    // 5. Check response code
    $response_code = wp_remote_retrieve_response_code($response);
    
    if ($response_code !== 200) {
        error_log('Vertex AI API returned code: ' . $response_code);
        return new WP_Error('api_error', 'API returned error: ' . $response_code);
    }
    
    // 6. Get and validate response body
    $body = wp_remote_retrieve_body($response);
    $parsed = json_decode($body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('Vertex AI API returned invalid JSON');
        return new WP_Error('invalid_response', 'Invalid API response');
    }
    
    // 7. Validate response structure
    if (!isset($parsed['recommendations']) || !is_array($parsed['recommendations'])) {
        return new WP_Error('invalid_structure', 'Unexpected response structure');
    }
    
    // 8. Sanitize response data
    $recommendations = array();
    foreach ($parsed['recommendations'] as $item) {
        $recommendations[] = array(
            'product_id' => absint($item['product_id'] ?? 0),
            'score' => floatval($item['score'] ?? 0),
            'title' => sanitize_text_field($item['title'] ?? '')
        );
    }
    
    return $recommendations;
}
```

### Rate Limiting

```php
function check_api_rate_limit($user_id = null) {
    $user_id = $user_id ?? get_current_user_id();
    
    // Get transient for rate limiting
    $transient_key = 'api_calls_' . $user_id;
    $call_count = get_transient($transient_key);
    
    if ($call_count === false) {
        // First call in this period
        set_transient($transient_key, 1, HOUR_IN_SECONDS);
        return true;
    }
    
    // Check limit (100 calls per hour)
    if ($call_count >= 100) {
        return new WP_Error('rate_limit', 'Rate limit exceeded');
    }
    
    // Increment counter
    set_transient($transient_key, $call_count + 1, HOUR_IN_SECONDS);
    
    return true;
}

// Use before API call
$rate_check = check_api_rate_limit();
if (is_wp_error($rate_check)) {
    wp_send_json_error($rate_check->get_error_message());
}
```

### Retry Logic with Exponential Backoff

```php
function api_request_with_retry($endpoint, $data, $max_retries = 3) {
    $attempt = 0;
    
    while ($attempt < $max_retries) {
        $result = make_vertex_ai_request($endpoint, $data);
        
        // Success
        if (!is_wp_error($result)) {
            return $result;
        }
        
        // Don't retry client errors (4xx)
        $error_code = $result->get_error_code();
        if (in_array($error_code, array('invalid_key', 'missing_key'))) {
            return $result;
        }
        
        $attempt++;
        
        // Exponential backoff: 1s, 2s, 4s
        if ($attempt < $max_retries) {
            sleep(pow(2, $attempt - 1));
        }
    }
    
    return new WP_Error('max_retries', 'Maximum retry attempts exceeded');
}
```

### Webhook Validation

```php
function validate_webhook_signature() {
    // Get raw POST body
    $body = file_get_contents('php://input');
    
    // Get signature from header
    $signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
    
    if (empty($signature)) {
        wp_die('Missing signature', 403);
    }
    
    // Get webhook secret
    $secret = get_option('webhook_secret');
    
    // Calculate expected signature
    $expected = hash_hmac('sha256', $body, $secret);
    
    // Constant-time comparison to prevent timing attacks
    if (!hash_equals($expected, $signature)) {
        wp_die('Invalid signature', 403);
    }
    
    // Parse and process webhook
    $data = json_decode($body, true);
    
    // Validate structure
    if (!isset($data['event']) || !isset($data['timestamp'])) {
        wp_die('Invalid webhook data', 400);
    }
    
    // Check timestamp to prevent replay attacks (within 5 minutes)
    $timestamp = absint($data['timestamp']);
    if (abs(time() - $timestamp) > 300) {
        wp_die('Webhook expired', 400);
    }
    
    // Process webhook
    // ...
}
```

---

## 9. Common Vulnerabilities to Avoid

### Direct File Access

```php
// ALWAYS add this at the top of every PHP file
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
```

### eval() and Dynamic Code Execution

```php
// NEVER use eval()
eval($_POST['code']); // EXTREMELY DANGEROUS

// NEVER use create_function()
$func = create_function('$a', 'return ' . $_POST['expression'] . ';'); // DANGEROUS

// NEVER use variable functions with user input
$function = $_POST['function'];
$function(); // DANGEROUS
```

### Unserialize User Input

```php
// NEVER unserialize user input
$data = unserialize($_POST['data']); // DANGEROUS - Object injection vulnerability

// Use JSON instead
$data = json_decode($_POST['data'], true); // SAFE
```

### Information Disclosure

```php
// NEVER expose sensitive info in error messages
// BAD
if (!$user) {
    wp_die('User not found in database table wp_users');
}

// GOOD
if (!$user) {
    wp_die(esc_html__('Invalid user', 'textdomain'));
}

// NEVER output debug info to users
// BAD
echo 'SQL: ' . $wpdb->last_query;
echo 'Error: ' . $wpdb->last_error;

// GOOD (log instead)
error_log('SQL Error: ' . $wpdb->last_error);
```

### Server-Side Request Forgery (SSRF)

```php
// NEVER make requests to user-provided URLs without validation
// BAD
$url = $_POST['url'];
wp_remote_get($url); // DANGEROUS - can access internal resources

// GOOD - Validate URL
$url = esc_url_raw($_POST['url'], array('http', 'https'));

// Check it's not a private/local IP
$parsed = parse_url($url);
if (!$parsed || !isset($parsed['host'])) {
    return new WP_Error('invalid_url', 'Invalid URL');
}

// Block private IPs
$ip = gethostbyname($parsed['host']);
if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
    return new WP_Error('private_ip', 'Private IP addresses not allowed');
}
```

### Path Traversal

```php
// NEVER use user input in file paths without validation
// BAD
$file = $_GET['file'];
include($file); // DANGEROUS - can include any file

// GOOD - Validate against whitelist
$allowed_files = array('template1.php', 'template2.php');
$file = sanitize_file_name($_GET['file']);

if (!in_array($file, $allowed_files)) {
    wp_die('Invalid file');
}

include(plugin_dir_path(__FILE__) . 'templates/' . $file);
```

---

## 10. Security Checklist

Use this checklist before releasing or deploying code:

### Input/Output
- [ ] All user input sanitized with appropriate functions
- [ ] All output escaped for context (HTML, URL, JS, attributes)
- [ ] No direct `$_POST`, `$_GET`, `$_REQUEST` access without sanitization
- [ ] JSON responses use `wp_send_json_*` functions

### Authentication/Authorization
- [ ] All forms have nonce fields
- [ ] All state-changing operations verify nonces
- [ ] AJAX handlers use `check_ajax_referer()`
- [ ] All operations check `current_user_can()`
- [ ] Admin pages check capabilities

### Database
- [ ] All queries use `$wpdb->prepare()` or equivalent
- [ ] No string concatenation in SQL
- [ ] LIKE queries use `$wpdb->esc_like()`
- [ ] Array values properly sanitized before IN clauses

### Files
- [ ] All files start with `if (!defined('ABSPATH')) exit;`
- [ ] File uploads validate type, size, and content
- [ ] File paths don't use user input directly
- [ ] Uploaded files stored in secure location

### API/External Data
- [ ] API credentials encrypted before storage
- [ ] SSL verification enabled (`sslverify => true`)
- [ ] API responses validated and sanitized
- [ ] Rate limiting implemented
- [ ] Timeout values set
- [ ] Error messages don't leak sensitive info

### Encryption
- [ ] Sensitive data encrypted at rest
- [ ] Encryption keys derived from WordPress salts (HKDF)
- [ ] No hardcoded keys or secrets
- [ ] API keys not in version control

### General
- [ ] No `eval()`, `create_function()`, or dynamic code execution
- [ ] No `unserialize()` of user input
- [ ] Error logging used instead of displaying errors
- [ ] Debug mode disabled in production
- [ ] Regular security updates and dependency checks

---

## 11. Additional Resources

### WordPress Security Documentation
- [WordPress Plugin Security Guidelines](https://developer.wordpress.org/plugins/security/)
- [Data Validation](https://developer.wordpress.org/plugins/security/data-validation/)
- [Nonces](https://developer.wordpress.org/plugins/security/nonces/)

### WooCommerce Specific
- [WooCommerce Security Best Practices](https://woocommerce.com/document/woocommerce-security/)
- [WooCommerce Coding Standards](https://github.com/woocommerce/woocommerce/wiki/JavaScript-Coding-Standards)

### Tools
- [Plugin Check Plugin](https://wordpress.org/plugins/plugin-check/) - Automated security checks
- [PHP_CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer) - Code standards checking
- [WordPress Coding Standards](https://github.com/WordPress/WordPress-Coding-Standards)

---

## Conclusion

Security is not optional. Every line of code that handles user input, database queries, file operations, or external API calls must follow these patterns. When in doubt, be more restrictive rather than more permissive.

**Remember: Security is a mindset, not a checklist.**