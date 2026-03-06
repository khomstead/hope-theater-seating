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

    /**
     * @param bool $register_hooks Pass false when creating an instance only
     *                             for helper method access (avoids duplicate hook registration).
     */
    public function __construct($register_hooks = true) {
        if (!$register_hooks) {
            return;
        }

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

        $now = (new DateTime('now', wp_timezone()))->getTimestamp();

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
        // Use WordPress COOKIEPATH and COOKIE_DOMAIN for consistency with WP cookies
        $path = defined('COOKIEPATH') ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
        setcookie($cookie_name, $password_hash, $expiry, $path, $domain, is_ssl(), false);
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
        $timezone = wp_timezone();
        $dt = new DateTime('@' . $timestamp);
        $dt->setTimezone($timezone);

        // Format: "March 6 at 10 AM Eastern" (human-friendly)
        $minutes = $dt->format('i');
        $time_format = ($minutes === '00') ? 'g A' : 'g:i A';
        $tz_name = $this->get_friendly_timezone_name($timezone);

        return $dt->format('F j') . ' at ' . $dt->format($time_format) . ' ' . $tz_name;
    }

    /**
     * Get a human-friendly timezone name (e.g., "Eastern" instead of "EST" or "America/New_York").
     *
     * @param DateTimeZone $timezone
     * @return string
     */
    private function get_friendly_timezone_name($timezone) {
        $tz_id = $timezone->getName();

        $friendly_names = array(
            'America/New_York'    => 'Eastern',
            'America/Chicago'     => 'Central',
            'America/Denver'      => 'Mountain',
            'America/Los_Angeles' => 'Pacific',
            'America/Anchorage'   => 'Alaska',
            'Pacific/Honolulu'    => 'Hawaii',
        );

        if (isset($friendly_names[$tz_id])) {
            return $friendly_names[$tz_id];
        }

        // Fallback to abbreviation (e.g., "EST", "PST")
        $dt = new DateTime('now', $timezone);
        return $dt->format('T');
    }

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
                        </div>
                        <button type="button" id="hope-presale-submit" class="hope-presale-submit-btn"><?php _e('Submit', 'hope-seating'); ?></button>
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
            margin-bottom: 12px;
        }
        .hope-presale-code-input {
            padding: 10px 14px;
            font-size: 1em;
            border: 1px solid #ccc;
            border-radius: 6px;
            width: 100%;
            max-width: 300px;
        }
        .hope-presale-submit-btn {
            display: inline-block;
            padding: 12px 30px;
            font-size: 16px;
            font-weight: 600;
            background: #7c3aed;
            color: white !important;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .hope-presale-submit-btn:hover {
            background: #6b21a8;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
        }
        .hope-presale-submit-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
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

                // Hide the add-to-cart form and any sibling elements after the gate
                // The gate is rendered before <form class="cart"> via woocommerce_before_add_to_cart_form,
                // so we need to hide siblings (including the form itself), not children inside the form.
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
                            // Show success message then reload page so server renders
                            // the full purchase interface (Select Seats or Add to Cart)
                            while (gate.firstChild) {
                                gate.removeChild(gate.firstChild);
                            }
                            var successDiv = document.createElement('div');
                            successDiv.className = 'hope-presale-success';
                            successDiv.textContent = '<?php echo esc_js(__('Pre-sale code accepted! Loading tickets...', 'hope-seating')); ?>';
                            gate.appendChild(successDiv);

                            setTimeout(function() {
                                window.location.reload();
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

        // Check per-password activation date (optional — if set, must have passed)
        if (!empty($matched_entry['activation_date'])) {
            $now = (new DateTime('now', wp_timezone()))->getTimestamp();
            if ($now < (int) $matched_entry['activation_date']) {
                $activation_display = $this->format_date_for_display((int) $matched_entry['activation_date']);
                $label = !empty($matched_entry['label']) ? $matched_entry['label'] : __('This', 'hope-seating');
                wp_send_json_error(sprintf(
                    __('%s pre-sale begins %s', 'hope-seating'),
                    esc_html($label),
                    esc_html($activation_display)
                ));
                return;
            }
        }

        // Password matched — set cookie
        $public_start = get_post_meta($product_id, '_hope_presale_public_start', true);
        $password_hash = md5($password_lower);
        $this->set_presale_cookie($product_id, $password_hash, (int) $public_start);

        wp_send_json_success(array(
            'message' => __('Pre-sale code accepted!', 'hope-seating'),
        ));
    }

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
}
