# Pre-sale Feature Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add pre-sale password gating so customers cannot purchase tickets until they enter a valid pre-sale code, with automatic transition to general sale at a configured date/time.

**Architecture:** New `class-presale.php` handles all pre-sale logic (state determination, password validation, cookie management, order tracking). Admin tab added to `class-admin.php`. Frontend gating hooks into `woocommerce_before_add_to_cart_form` at priority 5, running before the seating interface (priority 10). No new database tables — uses product meta, order item meta, transients, and cookies.

**Tech Stack:** PHP 7.4+, WordPress/WooCommerce APIs, vanilla JavaScript, WordPress AJAX

**Design Doc:** `docs/plans/2026-03-05-presale-design.md`

---

## Task 1: Create `class-presale.php` — Core State Logic

**Files:**
- Create: `includes/class-presale.php`

**Step 1: Create the class with state determination**

```php
<?php
/**
 * Pre-sale functionality for HOPE Theater Seating
 *
 * Handles pre-sale password gating, validation, cookie management,
 * and order tracking. Works with both seated and general admission products.
 *
 * @since 2.8.27
 */

if (!defined('ABSPATH')) {
    exit;
}

class HOPE_Presale {

    /** Rate limit: max failed attempts before lockout */
    const MAX_ATTEMPTS = 5;

    /** Rate limit: lockout duration in seconds (15 minutes) */
    const LOCKOUT_DURATION = 900;

    public function __construct() {
        // AJAX endpoints for password validation (logged-in and guest)
        add_action('wp_ajax_hope_validate_presale_password', array($this, 'ajax_validate_password'));
        add_action('wp_ajax_nopriv_hope_validate_presale_password', array($this, 'ajax_validate_password'));

        // Frontend gate — runs before seating interface (priority 5)
        add_action('woocommerce_before_add_to_cart_form', array($this, 'maybe_gate_purchase'), 5);

        // Track pre-sale usage at checkout
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'save_presale_to_order_item'), 10, 4);
        add_action('woocommerce_order_status_processing', array($this, 'increment_presale_usage'), 20, 1);
        add_action('woocommerce_order_status_completed', array($this, 'increment_presale_usage'), 20, 1);
    }

    /**
     * Determine the current pre-sale state for a product.
     *
     * @param int $product_id
     * @return string 'disabled' | 'announced' | 'presale' | 'general_sale'
     */
    public function get_presale_state($product_id) {
        $enabled = get_post_meta($product_id, '_hope_presale_enabled', true);
        if ($enabled !== 'yes') {
            return 'disabled';
        }

        $presale_start = get_post_meta($product_id, '_hope_presale_start', true);
        $public_start = get_post_meta($product_id, '_hope_presale_public_start', true);

        if (!$presale_start || !$public_start) {
            return 'disabled';
        }

        $now = current_time('timestamp');

        if ($now >= (int) $public_start) {
            return 'general_sale';
        }

        if ($now >= (int) $presale_start) {
            return 'presale';
        }

        return 'announced';
    }

    /**
     * Check if the current visitor has a valid pre-sale cookie for a product.
     *
     * @param int $product_id
     * @return bool
     */
    public function has_valid_presale_cookie($product_id) {
        $cookie_name = 'hope_presale_' . $product_id;
        return isset($_COOKIE[$cookie_name]) && !empty($_COOKIE[$cookie_name]);
    }

    /**
     * Set the pre-sale access cookie.
     *
     * @param int    $product_id
     * @param string $password_hash MD5 hash of the matched password
     * @param int    $expiry        Unix timestamp when cookie expires
     */
    public function set_presale_cookie($product_id, $password_hash, $expiry) {
        $cookie_name = 'hope_presale_' . $product_id;
        setcookie($cookie_name, $password_hash, $expiry, '/', '', is_ssl(), false);
        // Also set in $_COOKIE so it's available in the current request
        $_COOKIE[$cookie_name] = $password_hash;
    }

    /**
     * Find which password entry matches a cookie hash value.
     *
     * @param int    $product_id
     * @param string $cookie_hash
     * @return array|null The matching password entry or null
     */
    public function get_password_entry_by_hash($product_id, $cookie_hash) {
        $passwords = get_post_meta($product_id, '_hope_presale_passwords', true);
        if (!is_array($passwords)) {
            return null;
        }
        foreach ($passwords as $entry) {
            if (md5(strtolower(trim($entry['password']))) === $cookie_hash) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Check rate limiting for an IP address.
     *
     * @param string $ip
     * @return bool True if the IP is currently locked out
     */
    public function is_rate_limited($ip) {
        $transient_key = 'hope_presale_attempts_' . md5($ip);
        $attempts = get_transient($transient_key);
        return $attempts !== false && (int) $attempts >= self::MAX_ATTEMPTS;
    }

    /**
     * Record a failed password attempt for an IP.
     *
     * @param string $ip
     */
    public function record_failed_attempt($ip) {
        $transient_key = 'hope_presale_attempts_' . md5($ip);
        $attempts = get_transient($transient_key);
        if ($attempts === false) {
            set_transient($transient_key, 1, self::LOCKOUT_DURATION);
        } else {
            set_transient($transient_key, (int) $attempts + 1, self::LOCKOUT_DURATION);
        }
    }

    /**
     * Format a timestamp for display using WordPress timezone.
     *
     * @param int $timestamp Unix timestamp
     * @return string Formatted date/time string
     */
    public function format_date_for_display($timestamp) {
        $date_format = get_option('date_format', 'F j, Y');
        $time_format = get_option('time_format', 'g:i A');
        $timezone = wp_timezone();
        $dt = new DateTime('@' . $timestamp);
        $dt->setTimezone($timezone);
        return $dt->format($date_format . ' \a\t ' . $time_format . ' T');
    }
}
```

**Step 2: Verify PHP syntax**

Run: `php -l includes/class-presale.php`
Expected: No syntax errors detected

**Step 3: Commit**

```bash
git add includes/class-presale.php
git commit -m "feat(presale): add core presale class with state logic and helpers (v2.8.27)"
```

---

## Task 2: Register `class-presale.php` in Plugin Bootstrap

**Files:**
- Modify: `hope-theater-seating.php:225-254` (includes array)
- Modify: `hope-theater-seating.php:282-309` (class instantiation)

**Step 1: Add to the includes array**

In the `includes()` method, add `'includes/class-presale.php'` to the `$files_to_include` array, after the admin order lookup line:

```php
'includes/class-admin-order-lookup.php',           // NEW: Admin order lookup by seat
'includes/class-presale.php',                       // NEW: Pre-sale password gating
```

**Step 2: Add class instantiation**

In the init hooks section (after the selective refund handler instantiation), add:

```php
// NEW: Initialize pre-sale handler
if (class_exists('HOPE_Presale')) {
    new HOPE_Presale();
}
```

**Step 3: Update version number**

Update `Version:` header and `HOPE_SEATING_VERSION` constant to `2.8.27`.

**Step 4: Verify PHP syntax**

Run: `php -l hope-theater-seating.php`
Expected: No syntax errors detected

**Step 5: Commit**

```bash
git add hope-theater-seating.php
git commit -m "feat(presale): register presale class in plugin bootstrap (v2.8.27)"
```

---

## Task 3: Add Admin "Pre-sale" Product Data Tab

**Files:**
- Modify: `includes/class-admin.php:18-19` (constructor — add tab hooks)
- Modify: `includes/class-admin.php` (add new methods after `save_product_venue_fields`)

**Step 1: Register the tab and panel hooks in the constructor**

In the constructor (after line 20: `add_action('woocommerce_process_product_meta', ...)`), add:

```php
// Pre-sale tab
add_action('woocommerce_product_data_tabs', array($this, 'add_presale_tab'));
add_action('woocommerce_product_data_panels', array($this, 'add_presale_fields'));
add_action('woocommerce_process_product_meta', array($this, 'save_presale_fields'));
```

**Step 2: Add the tab method**

Add after the `save_product_venue_fields()` method (after line 1597):

```php
/**
 * Add Pre-sale tab to WooCommerce product data tabs.
 */
public function add_presale_tab($tabs) {
    $tabs['hope_presale'] = array(
        'label'    => __('Pre-sale', 'hope-seating'),
        'target'   => 'hope_presale_options',
        'class'    => array('show_if_simple', 'show_if_variable'),
        'priority' => 22,
    );
    return $tabs;
}
```

**Step 3: Add the fields rendering method**

```php
/**
 * Render Pre-sale fields in the product data panel.
 */
public function add_presale_fields() {
    global $post;

    $enabled = get_post_meta($post->ID, '_hope_presale_enabled', true);
    $presale_start = get_post_meta($post->ID, '_hope_presale_start', true);
    $public_start = get_post_meta($post->ID, '_hope_presale_public_start', true);
    $passwords = get_post_meta($post->ID, '_hope_presale_passwords', true);
    $announcement_msg = get_post_meta($post->ID, '_hope_presale_announcement_message', true);
    $presale_msg = get_post_meta($post->ID, '_hope_presale_message', true);

    if (!is_array($passwords)) {
        $passwords = array();
    }

    // Convert timestamps to local datetime strings for the input fields
    $timezone = wp_timezone();
    $presale_start_local = '';
    $public_start_local = '';

    if ($presale_start) {
        $dt = new DateTime('@' . $presale_start);
        $dt->setTimezone($timezone);
        $presale_start_local = $dt->format('Y-m-d\TH:i');
    }
    if ($public_start) {
        $dt = new DateTime('@' . $public_start);
        $dt->setTimezone($timezone);
        $public_start_local = $dt->format('Y-m-d\TH:i');
    }

    ?>
    <div id="hope_presale_options" class="panel woocommerce_options_panel">
        <div class="options_group">
            <h3 style="padding-left: 10px;">Pre-sale Configuration</h3>

            <?php
            woocommerce_wp_checkbox(array(
                'id'          => '_hope_presale_enabled',
                'label'       => __('Enable Pre-sale', 'hope-seating'),
                'description' => __('Gate ticket purchases behind a pre-sale password during the pre-sale window', 'hope-seating'),
                'value'       => $enabled ? $enabled : 'no',
                'desc_tip'    => true,
            ));
            ?>
        </div>

        <div class="options_group hope-presale-details" style="<?php echo ($enabled !== 'yes') ? 'display:none;' : ''; ?>">
            <h4 style="padding-left: 10px;">Dates &amp; Times</h4>
            <p class="form-field" style="padding-left: 10px; color: #666; font-style: italic;">
                <?php
                $tz_string = wp_timezone_string();
                printf(__('All times are in %s timezone (configured in Settings &rarr; General)', 'hope-seating'), esc_html($tz_string));
                ?>
            </p>

            <p class="form-field">
                <label for="_hope_presale_start"><?php _e('Pre-sale Start', 'hope-seating'); ?></label>
                <input type="datetime-local" id="_hope_presale_start" name="_hope_presale_start" value="<?php echo esc_attr($presale_start_local); ?>" style="width: 250px;" />
            </p>

            <p class="form-field">
                <label for="_hope_presale_public_start"><?php _e('General Sale Start', 'hope-seating'); ?></label>
                <input type="datetime-local" id="_hope_presale_public_start" name="_hope_presale_public_start" value="<?php echo esc_attr($public_start_local); ?>" style="width: 250px;" />
            </p>

            <h4 style="padding-left: 10px;">Pre-sale Passwords</h4>
            <div class="hope-presale-passwords" style="padding: 0 12px;">
                <table class="widefat hope-presale-password-table" style="margin-bottom: 10px;">
                    <thead>
                        <tr>
                            <th style="width: 30%;"><?php _e('Password', 'hope-seating'); ?></th>
                            <th style="width: 30%;"><?php _e('Label', 'hope-seating'); ?></th>
                            <th style="width: 20%;"><?php _e('Uses', 'hope-seating'); ?></th>
                            <th style="width: 20%;"></th>
                        </tr>
                    </thead>
                    <tbody id="hope-presale-password-rows">
                        <?php if (!empty($passwords)) : ?>
                            <?php foreach ($passwords as $index => $entry) : ?>
                                <tr class="hope-presale-password-row">
                                    <td><input type="text" name="hope_presale_pw[<?php echo $index; ?>][password]" value="<?php echo esc_attr($entry['password']); ?>" style="width: 100%;" /></td>
                                    <td><input type="text" name="hope_presale_pw[<?php echo $index; ?>][label]" value="<?php echo esc_attr($entry['label']); ?>" placeholder="<?php _e('e.g., Fan Club', 'hope-seating'); ?>" style="width: 100%;" /></td>
                                    <td><span class="hope-presale-usage-count"><?php echo intval($entry['usage_count'] ?? 0); ?></span></td>
                                    <td><button type="button" class="button hope-presale-remove-pw">&times;</button></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <button type="button" class="button hope-presale-add-pw">+ <?php _e('Add Password', 'hope-seating'); ?></button>
            </div>

            <h4 style="padding-left: 10px; margin-top: 15px;">Messaging</h4>

            <?php
            woocommerce_wp_textarea_input(array(
                'id'          => '_hope_presale_announcement_message',
                'label'       => __('Announcement Message', 'hope-seating'),
                'description' => __('Shown before the pre-sale begins (e.g., "Tickets will be available soon!")', 'hope-seating'),
                'value'       => $announcement_msg ? $announcement_msg : '',
                'desc_tip'    => true,
                'style'       => 'height: 80px;',
            ));

            woocommerce_wp_textarea_input(array(
                'id'          => '_hope_presale_message',
                'label'       => __('Pre-sale Message', 'hope-seating'),
                'description' => __('Shown during the active pre-sale period (e.g., "Welcome! Enter your pre-sale code below.")', 'hope-seating'),
                'value'       => $presale_msg ? $presale_msg : '',
                'desc_tip'    => true,
                'style'       => 'height: 80px;',
            ));
            ?>
        </div>
    </div>

    <script type="text/javascript">
    jQuery(function($) {
        // Toggle pre-sale details visibility
        $('#_hope_presale_enabled').on('change', function() {
            if ($(this).is(':checked')) {
                $('.hope-presale-details').show();
            } else {
                $('.hope-presale-details').hide();
            }
        });

        // Add password row
        var pwIndex = <?php echo count($passwords); ?>;
        $('.hope-presale-add-pw').on('click', function() {
            var row = '<tr class="hope-presale-password-row">' +
                '<td><input type="text" name="hope_presale_pw[' + pwIndex + '][password]" value="" style="width: 100%;" /></td>' +
                '<td><input type="text" name="hope_presale_pw[' + pwIndex + '][label]" value="" placeholder="<?php echo esc_js(__('e.g., Fan Club', 'hope-seating')); ?>" style="width: 100%;" /></td>' +
                '<td><span class="hope-presale-usage-count">0</span></td>' +
                '<td><button type="button" class="button hope-presale-remove-pw">&times;</button></td>' +
                '</tr>';
            $('#hope-presale-password-rows').append(row);
            pwIndex++;
        });

        // Remove password row
        $(document).on('click', '.hope-presale-remove-pw', function() {
            $(this).closest('tr').remove();
        });
    });
    </script>
    <?php
}
```

**Step 4: Add the save method**

```php
/**
 * Save Pre-sale fields when product is saved.
 */
public function save_presale_fields($post_id) {
    // Nonce already verified by WooCommerce in save_product_venue_fields
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Save enable toggle
    $enabled = isset($_POST['_hope_presale_enabled']) ? 'yes' : 'no';
    update_post_meta($post_id, '_hope_presale_enabled', $enabled);

    // Save dates (convert local datetime to Unix timestamp)
    $timezone = wp_timezone();

    if (!empty($_POST['_hope_presale_start'])) {
        $dt = new DateTime($_POST['_hope_presale_start'], $timezone);
        update_post_meta($post_id, '_hope_presale_start', $dt->getTimestamp());
    } else {
        delete_post_meta($post_id, '_hope_presale_start');
    }

    if (!empty($_POST['_hope_presale_public_start'])) {
        $dt = new DateTime($_POST['_hope_presale_public_start'], $timezone);
        update_post_meta($post_id, '_hope_presale_public_start', $dt->getTimestamp());
    } else {
        delete_post_meta($post_id, '_hope_presale_public_start');
    }

    // Save passwords
    $passwords = array();
    if (isset($_POST['hope_presale_pw']) && is_array($_POST['hope_presale_pw'])) {
        // Get existing passwords to preserve usage counts
        $existing = get_post_meta($post_id, '_hope_presale_passwords', true);
        $existing_by_pw = array();
        if (is_array($existing)) {
            foreach ($existing as $entry) {
                $existing_by_pw[strtolower(trim($entry['password']))] = $entry;
            }
        }

        foreach ($_POST['hope_presale_pw'] as $entry) {
            $pw = sanitize_text_field(trim($entry['password'] ?? ''));
            $label = sanitize_text_field(trim($entry['label'] ?? ''));
            if (empty($pw)) {
                continue;
            }

            // Preserve usage count if password already existed
            $existing_entry = $existing_by_pw[strtolower($pw)] ?? null;
            $usage_count = $existing_entry ? intval($existing_entry['usage_count'] ?? 0) : 0;

            $passwords[] = array(
                'password'    => $pw,
                'label'       => $label,
                'usage_count' => $usage_count,
            );
        }
    }
    update_post_meta($post_id, '_hope_presale_passwords', $passwords);

    // Save messages
    if (isset($_POST['_hope_presale_announcement_message'])) {
        update_post_meta($post_id, '_hope_presale_announcement_message', sanitize_textarea_field($_POST['_hope_presale_announcement_message']));
    }
    if (isset($_POST['_hope_presale_message'])) {
        update_post_meta($post_id, '_hope_presale_message', sanitize_textarea_field($_POST['_hope_presale_message']));
    }
}
```

**Step 5: Verify PHP syntax**

Run: `php -l includes/class-admin.php`
Expected: No syntax errors detected

**Step 6: Commit**

```bash
git add includes/class-admin.php
git commit -m "feat(presale): add Pre-sale product data tab with passwords, dates, messaging"
```

---

## Task 4: Implement Frontend Pre-sale Gate

**Files:**
- Modify: `includes/class-presale.php` (add `maybe_gate_purchase` and `render_presale_gate` methods)

**Step 1: Add the frontend gating method to `class-presale.php`**

Add these methods to the `HOPE_Presale` class:

```php
/**
 * Hook callback: Gate the purchase form based on pre-sale state.
 *
 * Fires at woocommerce_before_add_to_cart_form priority 5.
 * If pre-sale is gating, outputs messaging and hides the add-to-cart form via CSS/JS.
 * If not gating, does nothing (normal flow proceeds).
 */
public function maybe_gate_purchase() {
    global $product;

    if (!$product || !is_object($product)) {
        return;
    }

    $product_id = $product->get_id();
    $state = $this->get_presale_state($product_id);

    if ($state === 'disabled' || $state === 'general_sale') {
        return;
    }

    // Pre-sale active and customer has valid cookie — let normal flow proceed
    if ($state === 'presale' && $this->has_valid_presale_cookie($product_id)) {
        return;
    }

    // Gate is active — render pre-sale UI and hide the purchase form
    $this->render_presale_gate($product_id, $state);
}

/**
 * Render the pre-sale gate HTML for a product.
 *
 * @param int    $product_id
 * @param string $state 'announced' or 'presale'
 */
public function render_presale_gate($product_id, $state) {
    $presale_start = get_post_meta($product_id, '_hope_presale_start', true);
    $public_start = get_post_meta($product_id, '_hope_presale_public_start', true);
    $announcement_msg = get_post_meta($product_id, '_hope_presale_announcement_message', true);
    $presale_msg = get_post_meta($product_id, '_hope_presale_message', true);

    $public_date_display = $this->format_date_for_display((int) $public_start);
    $presale_date_display = $this->format_date_for_display((int) $presale_start);

    ?>
    <div class="hope-presale-gate" data-product-id="<?php echo esc_attr($product_id); ?>">
        <?php if ($state === 'announced') : ?>
            <div class="hope-presale-notice hope-presale-announced">
                <?php if (!empty($announcement_msg)) : ?>
                    <div class="hope-presale-custom-message"><?php echo wp_kses_post(wpautop($announcement_msg)); ?></div>
                <?php endif; ?>
                <p class="hope-presale-date-info">
                    <?php printf(__('Pre-sale begins %s', 'hope-seating'), '<strong>' . esc_html($presale_date_display) . '</strong>'); ?>
                </p>
                <p class="hope-presale-date-info">
                    <?php printf(__('Tickets on sale to the general public %s', 'hope-seating'), '<strong>' . esc_html($public_date_display) . '</strong>'); ?>
                </p>
            </div>

        <?php elseif ($state === 'presale') : ?>
            <div class="hope-presale-notice hope-presale-active">
                <?php if (!empty($presale_msg)) : ?>
                    <div class="hope-presale-custom-message"><?php echo wp_kses_post(wpautop($presale_msg)); ?></div>
                <?php endif; ?>
                <p class="hope-presale-date-info">
                    <?php printf(__('Tickets on sale to the general public %s', 'hope-seating'), '<strong>' . esc_html($public_date_display) . '</strong>'); ?>
                </p>

                <div class="hope-presale-password-form">
                    <label for="hope-presale-code"><?php _e('Enter your pre-sale code:', 'hope-seating'); ?></label>
                    <div class="hope-presale-input-group">
                        <input type="text" id="hope-presale-code" class="hope-presale-code-input" placeholder="<?php esc_attr_e('Pre-sale code', 'hope-seating'); ?>" autocomplete="off" />
                        <button type="button" id="hope-presale-submit" class="button alt"><?php _e('Submit', 'hope-seating'); ?></button>
                    </div>
                    <div class="hope-presale-error" style="display: none;"></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <style>
    .hope-presale-gate {
        margin: 15px 0;
        padding: 20px;
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 6px;
    }
    .hope-presale-custom-message {
        margin-bottom: 10px;
        font-size: 1.05em;
    }
    .hope-presale-date-info {
        color: #555;
        margin: 5px 0;
    }
    .hope-presale-password-form {
        margin-top: 15px;
        padding-top: 15px;
        border-top: 1px solid #dee2e6;
    }
    .hope-presale-password-form label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
    }
    .hope-presale-input-group {
        display: flex;
        gap: 8px;
        align-items: center;
    }
    .hope-presale-code-input {
        padding: 8px 12px;
        font-size: 1em;
        border: 1px solid #ccc;
        border-radius: 4px;
        flex: 1;
        max-width: 300px;
    }
    .hope-presale-error {
        color: #dc3545;
        margin-top: 8px;
        font-size: 0.9em;
    }
    .hope-presale-success {
        color: #28a745;
        margin-top: 8px;
        font-size: 0.9em;
    }
    </style>

    <script type="text/javascript">
    (function() {
        document.addEventListener('DOMContentLoaded', function() {
            var gate = document.querySelector('.hope-presale-gate');
            if (!gate) return;

            // Hide the add-to-cart form elements that follow the gate
            var form = gate.closest('form.cart');
            if (form) {
                var siblings = gate.parentNode.children;
                var pastGate = false;
                for (var i = 0; i < siblings.length; i++) {
                    if (siblings[i] === gate) {
                        pastGate = true;
                        continue;
                    }
                    if (pastGate && !siblings[i].classList.contains('hope-presale-gate')) {
                        siblings[i].style.display = 'none';
                        siblings[i].setAttribute('data-hope-presale-hidden', 'true');
                    }
                }
            }

            // Password submission handler
            var submitBtn = document.getElementById('hope-presale-submit');
            var codeInput = document.getElementById('hope-presale-code');
            var errorDiv = gate.querySelector('.hope-presale-error');

            if (!submitBtn || !codeInput) return;

            function submitPassword() {
                var password = codeInput.value.trim();
                if (!password) {
                    errorDiv.textContent = '<?php echo esc_js(__('Please enter a pre-sale code.', 'hope-seating')); ?>';
                    errorDiv.style.display = 'block';
                    return;
                }

                submitBtn.disabled = true;
                submitBtn.textContent = '<?php echo esc_js(__('Checking...', 'hope-seating')); ?>';
                errorDiv.style.display = 'none';

                var formData = new FormData();
                formData.append('action', 'hope_validate_presale_password');
                formData.append('product_id', gate.dataset.productId);
                formData.append('password', password);
                formData.append('nonce', '<?php echo wp_create_nonce('hope_presale_nonce'); ?>');

                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.success) {
                        // Replace gate content with success message using safe DOM methods
                        while (gate.firstChild) {
                            gate.removeChild(gate.firstChild);
                        }
                        var successDiv = document.createElement('div');
                        successDiv.className = 'hope-presale-success';
                        successDiv.textContent = '<?php echo esc_js(__('Pre-sale code accepted!', 'hope-seating')); ?>';
                        gate.appendChild(successDiv);

                        setTimeout(function() {
                            // Unhide the form elements
                            var hidden = document.querySelectorAll('[data-hope-presale-hidden="true"]');
                            for (var i = 0; i < hidden.length; i++) {
                                hidden[i].style.display = '';
                                hidden[i].removeAttribute('data-hope-presale-hidden');
                            }
                            gate.parentNode.removeChild(gate);
                        }, 800);
                    } else {
                        errorDiv.textContent = data.data || '<?php echo esc_js(__('Invalid pre-sale code. Please try again.', 'hope-seating')); ?>';
                        errorDiv.style.display = 'block';
                        submitBtn.disabled = false;
                        submitBtn.textContent = '<?php echo esc_js(__('Submit', 'hope-seating')); ?>';
                        codeInput.value = '';
                        codeInput.focus();
                    }
                })
                .catch(function() {
                    errorDiv.textContent = '<?php echo esc_js(__('An error occurred. Please try again.', 'hope-seating')); ?>';
                    errorDiv.style.display = 'block';
                    submitBtn.disabled = false;
                    submitBtn.textContent = '<?php echo esc_js(__('Submit', 'hope-seating')); ?>';
                });
            }

            submitBtn.addEventListener('click', submitPassword);
            codeInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    submitPassword();
                }
            });
        });
    })();
    </script>
    <?php
}
```

**Step 2: Verify PHP syntax**

Run: `php -l includes/class-presale.php`
Expected: No syntax errors detected

**Step 3: Commit**

```bash
git add includes/class-presale.php
git commit -m "feat(presale): add frontend gate rendering with password form and AJAX submission"
```

---

## Task 5: Implement AJAX Password Validation

**Files:**
- Modify: `includes/class-presale.php` (add `ajax_validate_password` method)

**Step 1: Add the AJAX handler to `class-presale.php`**

```php
/**
 * AJAX handler: Validate a pre-sale password.
 */
public function ajax_validate_password() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'hope_presale_nonce')) {
        wp_send_json_error(__('Security check failed. Please refresh the page and try again.', 'hope-seating'));
        return;
    }

    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';

    if (!$product_id || empty($password)) {
        wp_send_json_error(__('Invalid request.', 'hope-seating'));
        return;
    }

    // Check rate limiting
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if ($this->is_rate_limited($ip)) {
        wp_send_json_error(__('Too many attempts. Please try again in 15 minutes.', 'hope-seating'));
        return;
    }

    // Verify pre-sale is active for this product
    $state = $this->get_presale_state($product_id);
    if ($state !== 'presale') {
        wp_send_json_error(__('Pre-sale is not currently active for this product.', 'hope-seating'));
        return;
    }

    // Check password against stored passwords (case-insensitive)
    $passwords = get_post_meta($product_id, '_hope_presale_passwords', true);
    if (!is_array($passwords) || empty($passwords)) {
        wp_send_json_error(__('Pre-sale is not configured for this product.', 'hope-seating'));
        return;
    }

    $matched_entry = null;
    $password_lower = strtolower($password);
    foreach ($passwords as $entry) {
        if (strtolower(trim($entry['password'])) === $password_lower) {
            $matched_entry = $entry;
            break;
        }
    }

    if (!$matched_entry) {
        $this->record_failed_attempt($ip);
        wp_send_json_error(__('Invalid pre-sale code. Please try again.', 'hope-seating'));
        return;
    }

    // Password matched — set cookie
    $public_start = get_post_meta($product_id, '_hope_presale_public_start', true);
    $password_hash = md5($password_lower);
    $this->set_presale_cookie($product_id, $password_hash, (int) $public_start);

    wp_send_json_success(array(
        'message' => __('Pre-sale code accepted!', 'hope-seating'),
    ));
}
```

**Step 2: Verify PHP syntax**

Run: `php -l includes/class-presale.php`
Expected: No syntax errors detected

**Step 3: Commit**

```bash
git add includes/class-presale.php
git commit -m "feat(presale): add AJAX password validation with rate limiting"
```

---

## Task 6: Implement Order Tracking (Usage Counts)

**Files:**
- Modify: `includes/class-presale.php` (add `save_presale_to_order_item` and `increment_presale_usage` methods)

**Step 1: Add order tracking methods to `class-presale.php`**

```php
/**
 * Save pre-sale password info to order line item metadata.
 *
 * Hooks into woocommerce_checkout_create_order_line_item.
 *
 * @param WC_Order_Item_Product $item
 * @param string                $cart_item_key
 * @param array                 $values Cart item values
 * @param WC_Order              $order
 */
public function save_presale_to_order_item($item, $cart_item_key, $values, $order) {
    $product_id = $item->get_product_id();
    $state = $this->get_presale_state($product_id);

    // Only track if pre-sale is active (or was active — general_sale means it just ended)
    if ($state !== 'presale' && $state !== 'general_sale') {
        return;
    }

    $cookie_name = 'hope_presale_' . $product_id;
    if (!isset($_COOKIE[$cookie_name])) {
        return;
    }

    $cookie_hash = $_COOKIE[$cookie_name];
    $entry = $this->get_password_entry_by_hash($product_id, $cookie_hash);

    if ($entry) {
        $item->add_meta_data('_hope_presale_password', $entry['password'], true);
        $item->add_meta_data('_hope_presale_label', $entry['label'], true);
    }
}

/**
 * Increment pre-sale usage count when order is confirmed.
 *
 * Hooks into woocommerce_order_status_processing and woocommerce_order_status_completed.
 *
 * @param int $order_id
 */
public function increment_presale_usage($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }

    // Prevent double-counting (check if we already tracked this order)
    $already_tracked = $order->get_meta('_hope_presale_usage_tracked');
    if ($already_tracked === 'yes') {
        return;
    }

    $has_presale_items = false;

    foreach ($order->get_items() as $item) {
        $presale_password = $item->get_meta('_hope_presale_password');
        if (empty($presale_password)) {
            continue;
        }

        $has_presale_items = true;
        $product_id = $item->get_product_id();
        $passwords = get_post_meta($product_id, '_hope_presale_passwords', true);
        if (!is_array($passwords)) {
            continue;
        }

        $updated = false;
        foreach ($passwords as &$entry) {
            if (strtolower(trim($entry['password'])) === strtolower(trim($presale_password))) {
                $entry['usage_count'] = intval($entry['usage_count'] ?? 0) + 1;
                $updated = true;
                break;
            }
        }
        unset($entry);

        if ($updated) {
            update_post_meta($product_id, '_hope_presale_passwords', $passwords);
        }
    }

    // Mark order as tracked to prevent double-counting
    if ($has_presale_items) {
        $order->update_meta_data('_hope_presale_usage_tracked', 'yes');
        $order->save();
    }
}
```

**Step 2: Verify PHP syntax**

Run: `php -l includes/class-presale.php`
Expected: No syntax errors detected

**Step 3: Commit**

```bash
git add includes/class-presale.php
git commit -m "feat(presale): add order tracking with usage count increments"
```

---

## Task 7: Add Pre-sale Gate to Modal Handler

**Files:**
- Modify: `includes/class-modal-handler.php:59-67` (gate check)

**Step 1: Add pre-sale state check to `render_modal_wrapper()`**

After the existing gate checks (after line 67 in `render_modal_wrapper()`), before the pricing map lookup, add:

```php
// Pre-sale gate: don't render modal if customer can't access it yet
if (class_exists('HOPE_Presale')) {
    $presale = new HOPE_Presale();
    $presale_state = $presale->get_presale_state($product->get_id());
    if ($presale_state === 'announced') {
        return;
    }
    if ($presale_state === 'presale' && !$presale->has_valid_presale_cookie($product->get_id())) {
        return;
    }
}
```

**Step 2: Verify PHP syntax**

Run: `php -l includes/class-modal-handler.php`
Expected: No syntax errors detected

**Step 3: Commit**

```bash
git add includes/class-modal-handler.php
git commit -m "feat(presale): skip modal rendering when pre-sale gate is active"
```

---

## Task 8: Skip Script Enqueuing During Pre-sale Gate

**Files:**
- Modify: `includes/class-frontend.php:39-62` (enqueue_scripts gate check)

**Step 1: Add pre-sale check to `enqueue_scripts()`**

After `$load_scripts = true` is set by the seating check (around line 49), add:

```php
// Pre-sale gate override: don't load seat map scripts if customer can't access yet
if ($load_scripts && class_exists('HOPE_Presale')) {
    $presale = new HOPE_Presale();
    $presale_state = $presale->get_presale_state($post->ID);
    if ($presale_state === 'announced') {
        $load_scripts = false;
    } elseif ($presale_state === 'presale' && !$presale->has_valid_presale_cookie($post->ID)) {
        $load_scripts = false;
    }
}
```

**Step 2: Verify PHP syntax**

Run: `php -l includes/class-frontend.php`
Expected: No syntax errors detected

**Step 3: Commit**

```bash
git add includes/class-frontend.php
git commit -m "feat(presale): skip seat map script loading during pre-sale gate"
```

---

## Task 9: Update CHANGELOG and Version

**Files:**
- Modify: `CHANGELOG.md` (add v2.8.27 entry at top)
- Modify: `hope-theater-seating.php` (version already updated in Task 2)

**Step 1: Add changelog entry**

Add at the top of CHANGELOG.md, after the `# Changelog` header:

```markdown
## [2.8.27] - 2026-03-XX

### Added
- **Pre-sale Password Gating** - New feature to gate ticket purchases behind pre-sale passwords
  - Product-level "Pre-sale" tab in WooCommerce product editor
  - Enable/disable toggle with pre-sale start date and general sale date
  - Multiple password support with labels (e.g., "Fan Club", "Spotify VIP")
  - Password usage tracking correlated to orders (similar to coupon tracking)
  - Three customer-facing states: Announced (info only), Pre-sale (password required), General Sale (normal)
  - Custom messaging fields for announcement and pre-sale periods
  - AJAX password validation with rate limiting (5 attempts / 15 min per IP)
  - Cookie-based session persistence (no re-entry needed during pre-sale window)
  - Works with both seated events and general admission products
  - Automatic transition to general sale at configured date/time
  - All dates respect WordPress timezone setting
  - Pre-sale info saved to order item metadata for reporting
  - Future-proof data structure for per-password pricing tiers and section restrictions
```

**Step 2: Commit**

```bash
git add CHANGELOG.md
git commit -m "docs: add v2.8.27 changelog entry for pre-sale feature"
```

---

## Task 10: Local Testing

**Do NOT commit. This is manual verification.**

### Test 1: Admin Tab
1. Open any WooCommerce product in the editor
2. Verify "Pre-sale" tab appears in product data tabs
3. Check the Enable Pre-sale checkbox — verify date fields, password repeater, and message textareas appear
4. Add two passwords with labels
5. Set pre-sale start to 1 hour ago, general sale to tomorrow
6. Add both custom messages
7. Save the product
8. Reload — verify all fields preserved correctly

### Test 2: Announced State
1. Edit product, set pre-sale start to tomorrow, general sale to next week
2. Save and view product page
3. Verify: announcement message shown, both dates shown, no password field, no add-to-cart/select-seats button

### Test 3: Pre-sale Active State
1. Edit product, set pre-sale start to 1 hour ago, general sale to tomorrow
2. View product page
3. Verify: pre-sale message shown, general sale date shown, password field + submit button visible
4. Enter wrong password — verify error message
5. Enter wrong password 5 times — verify rate limit message
6. Wait 15 minutes (or delete transient via DB) and enter correct password
7. Verify: gate disappears, normal purchase flow appears (Select Seats button or Add to Cart)

### Test 4: Cookie Persistence
1. After successful password entry, refresh the page
2. Verify: normal purchase flow shown immediately (no password prompt)

### Test 5: General Sale State
1. Edit product, set both dates to yesterday
2. View product page
3. Verify: normal product page, no pre-sale messaging

### Test 6: Order Tracking
1. With pre-sale active and password validated, complete a purchase
2. View the order in WooCommerce admin
3. Verify: `_hope_presale_password` and `_hope_presale_label` in order item meta
4. View the product Pre-sale tab
5. Verify: usage count incremented for the password used

### Test 7: Non-Seated Product
1. Create/find a product WITHOUT seating enabled
2. Enable pre-sale with dates and passwords
3. Verify: pre-sale gate hides the standard Add to Cart button
4. After password validation, verify standard Add to Cart appears

### Test 8: Browser Console
1. During all tests above, check browser console for JavaScript errors
2. Check `wp-content/debug.log` for PHP errors

---

## Summary of All Files Changed

| File | Action | Purpose |
|------|--------|---------|
| `includes/class-presale.php` | CREATE | Core pre-sale class (state, validation, cookies, order tracking, rendering) |
| `hope-theater-seating.php` | MODIFY | Register class in includes array and instantiation |
| `includes/class-admin.php` | MODIFY | Add Pre-sale product data tab, fields, and save handler |
| `includes/class-modal-handler.php` | MODIFY | Skip modal render during pre-sale gate |
| `includes/class-frontend.php` | MODIFY | Skip script enqueue during pre-sale gate |
| `CHANGELOG.md` | MODIFY | Add v2.8.27 entry |
