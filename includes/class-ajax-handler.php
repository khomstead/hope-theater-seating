<?php
/**
 * AJAX Handler Class
 * Manages all AJAX requests for seat selection
 */
if (!defined('ABSPATH')) {
    exit;
}

class HOPE_Ajax_Handler {

    private $current_product_id = 0;

    public function __construct() {
        // Register AJAX actions
        add_action('wp_ajax_hope_check_availability', array($this, 'check_availability'));
        add_action('wp_ajax_nopriv_hope_check_availability', array($this, 'check_availability'));
        
        add_action('wp_ajax_hope_hold_seats', array($this, 'hold_seats'));
        add_action('wp_ajax_nopriv_hope_hold_seats', array($this, 'hold_seats'));
        
        add_action('wp_ajax_hope_add_to_cart', array($this, 'add_to_cart'));
        add_action('wp_ajax_nopriv_hope_add_to_cart', array($this, 'add_to_cart'));
        
        add_action('wp_ajax_hope_release_seats', array($this, 'release_seats'));
        add_action('wp_ajax_nopriv_hope_release_seats', array($this, 'release_seats'));
    }
    
    /**
     * Check seat availability
     */
    public function check_availability() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'hope_seating_nonce')) {
            wp_die('Security check failed');
        }
        
        $product_id = intval($_POST['product_id']);
        $venue_id = intval($_POST['venue_id']);
        $requested_seats = json_decode(stripslashes($_POST['seats']), true);
        $session_id = sanitize_text_field($_POST['session_id']);
        
        // Set current product ID for use in other methods
        $this->current_product_id = $product_id;
        
        global $wpdb;
        $table_bookings = $wpdb->prefix . 'hope_seating_bookings';
        $table_holds = $wpdb->prefix . 'hope_seating_holds';
        
        $unavailable_seats = [];
        $all_unavailable_seats = [];
        
        // If no specific seats requested, get all unavailable seats for the venue
        if (empty($requested_seats)) {
            // Get all booked seats
            $booked_seats = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT seat_id FROM $table_bookings 
                WHERE product_id = %d AND status IN ('confirmed', 'pending')",
                $product_id
            ), ARRAY_A);
            
            foreach ($booked_seats as $row) {
                $all_unavailable_seats[] = $row['seat_id'];
            }
            
            // Get all held seats (by other sessions)
            $held_seats = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT seat_id FROM $table_holds
                WHERE product_id = %d AND session_id != %s AND expires_at > NOW()",
                $product_id, $session_id
            ), ARRAY_A);

            foreach ($held_seats as $row) {
                $all_unavailable_seats[] = $row['seat_id'];
            }

            // Get blocked seats (admin seat blocking)
            $blocked_seats = array();
            if (class_exists('HOPE_Database_Selective_Refunds')) {
                $blocked_seats = HOPE_Database_Selective_Refunds::get_blocked_seat_ids($product_id);
            }

            // Combine all unavailable seats but separate blocked seats for different styling
            $all_unavailable_seats = array_merge($all_unavailable_seats, $blocked_seats);

            wp_send_json_success([
                'available' => true,
                'unavailable_seats' => array_unique($all_unavailable_seats),
                'blocked_seats' => $blocked_seats, // Separate blocked seats for styling
                'booked_seats' => array_column($booked_seats, 'seat_id') // Separate booked seats for styling
            ]);
        }
        
        // Check specific requested seats
        foreach ($requested_seats as $seat_id) {
            // Check if seat is booked
            $is_booked = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_bookings 
                WHERE product_id = %d AND seat_id = %s AND status IN ('confirmed', 'pending')",
                $product_id, $seat_id
            ));
            
            // Check if seat is held by another session
            $is_held = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_holds 
                WHERE product_id = %d AND seat_id = %s 
                AND session_id != %s AND expires_at > NOW()",
                $product_id, $seat_id, $session_id
            ));
            
            if ($is_booked || $is_held) {
                $unavailable_seats[] = $seat_id;
                error_log("HOPE: Seat {$seat_id} unavailable - booked: {$is_booked}, held: {$is_held}");
            }
        }
        
        if (empty($unavailable_seats)) {
            wp_send_json_success([
                'available' => true,
                'seats' => $requested_seats
            ]);
        } else {
            wp_send_json_success([
                'available' => false,
                'unavailable_seats' => $unavailable_seats,
                'available_seats' => array_diff($requested_seats, $unavailable_seats)
            ]);
        }
    }
    
    /**
     * Hold seats temporarily
     */
    public function hold_seats() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'hope_seating_nonce')) {
            wp_die('Security check failed');
        }
        
        $product_id = intval($_POST['product_id']);
        $seats = json_decode(stripslashes($_POST['seats']), true);
        $session_id = sanitize_text_field($_POST['session_id']);

        // Set current product ID for use in other methods
        $this->current_product_id = $product_id;

        if (empty($seats) || empty($session_id)) {
            wp_send_json_error(['message' => 'Invalid request']);
        }

        // Check maximum seats per order
        if (count($seats) > 10) {
            wp_send_json_error(['message' => 'Maximum 10 seats per order']);
        }

        global $wpdb;
        $table_holds = $wpdb->prefix . 'hope_seating_holds';

        // First, release any existing holds for this session
        $wpdb->delete($table_holds, [
            'session_id' => $session_id,
            'product_id' => $product_id
        ]);

        $held_seats = [];
        // Get hold duration from admin settings
        $hold_duration = class_exists('HOPE_Theater_Seating') ? HOPE_Theater_Seating::get_hold_duration() : 900;
        // Use gmdate() to match MySQL NOW() which uses UTC
        $expires_at = gmdate('Y-m-d H:i:s', time() + $hold_duration);

        $unavailable_seats = [];

        foreach ($seats as $seat_id) {
            // Check if seat is available
            $is_available = $this->is_seat_available($product_id, $seat_id, $session_id);

            if ($is_available) {
                $result = $wpdb->insert($table_holds, [
                    'product_id' => $product_id,
                    'seat_id' => $seat_id,
                    'session_id' => $session_id,
                    'expires_at' => $expires_at,
                    'created_at' => gmdate('Y-m-d H:i:s')
                ]);

                if ($result) {
                    $held_seats[] = $seat_id;
                }
            } else {
                $unavailable_seats[] = $seat_id;
            }
        }
        
        // Success if we held at least some seats (partial success allowed)
        if (count($held_seats) > 0) {
            $response = [
                'held_seats' => $held_seats,
                'expires_at' => $expires_at,
                'hold_duration' => $hold_duration
            ];
            
            // Include unavailable seats info if any
            if (!empty($unavailable_seats)) {
                $response['unavailable_seats'] = $unavailable_seats;
                $response['message'] = sprintf(
                    'Held %d seats. %d seats were unavailable: %s',
                    count($held_seats),
                    count($unavailable_seats),
                    implode(', ', $unavailable_seats)
                );
            }
            
            wp_send_json_success($response);
        } else {
            wp_send_json_error([
                'message' => 'Could not hold any requested seats - all are unavailable',
                'unavailable_seats' => $unavailable_seats
            ]);
        }
    }
    
    /**
     * Add selected seats to cart
     */
    public function add_to_cart() {
        $debug_file = '/tmp/hope_debug.log';
        file_put_contents($debug_file, "\n" . date('Y-m-d H:i:s') . " === add_to_cart CALLED ===\n", FILE_APPEND);

        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'hope_seating_nonce')) {
            wp_die('Security check failed');
        }

        $product_id = intval($_POST['product_id']);
        $seats = json_decode(stripslashes($_POST['seats']), true);
        $session_id = sanitize_text_field($_POST['session_id']);

        // Set current product ID for pricing methods
        $this->current_product_id = $product_id;

        file_put_contents($debug_file, date('Y-m-d H:i:s') . " Product: {$product_id}, Seats from JS: " . implode(', ', $seats ?: []) . ", Session: {$session_id}\n", FILE_APPEND);

        if (empty($seats)) {
            wp_send_json_error(['message' => 'No seats selected']);
        }

        // Check if WooCommerce is available
        if (!function_exists('WC') || !WC()->cart) {
            wp_send_json_error(['message' => 'Shopping cart not available']);
        }

        // Check if product exists
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(['message' => 'Product not found']);
        }

        // CRITICAL: Get valid cart seats BEFORE removing them (for back-from-checkout validation)
        // This checks cart items' own session IDs for valid holds, not the current session
        // Must happen before remove_existing_seats_from_cart() which clears the cart
        // Also get the original session IDs so we can properly transfer holds
        $cart_seats_data = $this->get_valid_cart_seats_with_holds($product_id, true);
        $valid_cart_seats_before_clear = $cart_seats_data['seats'];
        $original_seat_sessions = $cart_seats_data['sessions']; // Maps seat_id => original session_id
        file_put_contents($debug_file, date('Y-m-d H:i:s') . " Valid cart seats before clear: " . implode(', ', $valid_cart_seats_before_clear ?: ['none']) . "\n", FILE_APPEND);

        // Remove any existing cart items for this product to prevent duplicates
        $this->remove_existing_seats_from_cart($product_id);
        file_put_contents($debug_file, date('Y-m-d H:i:s') . " Cart cleared for product {$product_id}\n", FILE_APPEND);

        // Handle variable products - need to map seats to their appropriate variations
        $available_variations = [];
        $variation_map = [];

        if ($product->is_type('variable')) {
            $available_variations = $product->get_available_variations();

            if (empty($available_variations)) {
                wp_send_json_error(['message' => 'No available ticket options found']);
            }

            // Create a map of seating-tier to variation_id
            foreach ($available_variations as $variation) {
                $attributes = $variation['attributes'];
                $tier = null;

                // Look for tier attribute with different possible names and cases
                foreach ($attributes as $attr_key => $attr_value) {
                    if (stripos($attr_key, 'tier') !== false || stripos($attr_key, 'seating') !== false) {
                        $tier = strtolower($attr_value); // Normalize to lowercase
                        break;
                    }
                }

                if ($tier) {
                    $variation_map[$tier] = [
                        'variation_id' => $variation['variation_id'],
                        'attributes' => $variation['attributes']
                    ];
                }
            }
        }

        // Get currently held seats for this session (the NEW seats being selected)
        $actually_held_seats = $this->get_held_seats($product_id, $session_id);
        file_put_contents($debug_file, date('Y-m-d H:i:s') . " Held seats for current session: " . implode(',', $actually_held_seats ?: ['none']) . "\n", FILE_APPEND);

        // Use the valid cart seats we captured BEFORE clearing the cart
        // These are seats from cart items that still have valid holds (using their own session IDs)
        $valid_cart_seats = $valid_cart_seats_before_clear;

        // Combine: newly held seats (current session) + preserved cart seats (their own sessions)
        $all_valid_seats = array_unique(array_merge($actually_held_seats, $valid_cart_seats));
        file_put_contents($debug_file, date('Y-m-d H:i:s') . " All valid seats (held + cart): " . implode(',', $all_valid_seats ?: ['none']) . "\n", FILE_APPEND);

        // Filter requested seats to only include those that are valid
        // BUT ALSO include valid cart seats even if not in the new selection
        $seats_to_add = array_unique(array_merge(
            array_intersect($seats, $all_valid_seats),  // Requested seats that are valid
            $valid_cart_seats                            // Previously carted seats with valid holds
        ));
        file_put_contents($debug_file, date('Y-m-d H:i:s') . " Final seats to add: " . implode(',', $seats_to_add ?: ['none']) . "\n", FILE_APPEND);

        if (empty($seats_to_add)) {
            file_put_contents($debug_file, date('Y-m-d H:i:s') . " ERROR: No valid seats to add!\n", FILE_APPEND);
            wp_send_json_error(['message' => 'No seats are currently held. Please select seats first.']);
        }

        // Use validated seats
        $seats = array_values($seats_to_add);
        file_put_contents($debug_file, date('Y-m-d H:i:s') . " Proceeding to add " . count($seats) . " seats: " . implode(',', $seats) . "\n", FILE_APPEND);

        // CRITICAL: Create/transfer holds for preserved cart seats to current session
        // This ensures checkout validation will find valid holds for ALL seats
        // Preserved seats have holds under their old session - we need them under current session
        global $wpdb;
        $table_holds = $wpdb->prefix . 'hope_seating_holds';
        $hold_duration = class_exists('HOPE_Theater_Seating') ? HOPE_Theater_Seating::get_hold_duration() : 900;
        $expires_at = gmdate('Y-m-d H:i:s', time() + $hold_duration);

        foreach ($valid_cart_seats as $preserved_seat) {
            // Check if this seat already has a hold for the current session
            $existing_hold = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table_holds}
                WHERE seat_id = %s
                AND product_id = %d
                AND session_id = %s
                AND expires_at > NOW()",
                $preserved_seat,
                $product_id,
                $session_id
            ));

            if (!$existing_hold) {
                // Get the original session that held this seat
                $original_session = isset($original_seat_sessions[$preserved_seat]) ? $original_seat_sessions[$preserved_seat] : '';

                if ($original_session && $original_session !== $session_id) {
                    // Delete the old hold for this specific seat and session only
                    // This is safe because we know this user owns that hold
                    $wpdb->delete($table_holds, [
                        'seat_id' => $preserved_seat,
                        'product_id' => $product_id,
                        'session_id' => $original_session
                    ]);
                    file_put_contents($debug_file, date('Y-m-d H:i:s') . " Deleted old hold for {$preserved_seat} (session: {$original_session})\n", FILE_APPEND);
                }

                // Create a new hold under the current session
                $result = $wpdb->insert($table_holds, [
                    'product_id' => $product_id,
                    'seat_id' => $preserved_seat,
                    'session_id' => $session_id,
                    'expires_at' => $expires_at,
                    'created_at' => gmdate('Y-m-d H:i:s')
                ]);

                if ($result) {
                    file_put_contents($debug_file, date('Y-m-d H:i:s') . " Created new hold for preserved seat {$preserved_seat} under current session\n", FILE_APPEND);
                } else {
                    file_put_contents($debug_file, date('Y-m-d H:i:s') . " FAILED to create hold for preserved seat {$preserved_seat}\n", FILE_APPEND);
                }
            } else {
                file_put_contents($debug_file, date('Y-m-d H:i:s') . " Preserved seat {$preserved_seat} already has hold under current session\n", FILE_APPEND);
            }
        }

        // Group seats by their pricing tier
        $seats_by_tier = $this->group_seats_by_tier($seats);

        $successful_additions = 0;
        $total_seats_added = 0;

        // Add each seat as an individual cart item (quantity=1)
        // This ensures seats from different tiers create separate cart items
        foreach ($seats_by_tier as $tier => $tier_seats) {
            // Find the appropriate variation for this tier
            $variation_id = 0;
            $variation_data = [];

            $tier_key = strtolower($tier); // Normalize for map lookup
            if (isset($variation_map[$tier_key])) {
                $variation_id = $variation_map[$tier_key]['variation_id'];
                $variation_data = $variation_map[$tier_key]['attributes'];
            } else {
                // Try to find a matching variation by searching all available variations
                $matching_variation = $this->find_variation_for_tier($available_variations, $tier);
                if ($matching_variation) {
                    $variation_id = $matching_variation['variation_id'];
                    $variation_data = $matching_variation['attributes'];
                } elseif (!empty($available_variations)) {
                    // Last resort: use first available variation
                    $variation_id = $available_variations[0]['variation_id'];
                    $variation_data = $available_variations[0]['attributes'];
                }
            }

            if ($variation_id === 0) {
                continue;
            }

            // Calculate pricing for this tier using the actual variation price
            $price_per_seat = $this->get_variation_price($variation_id, $tier);

            // Add each seat as a separate cart item with quantity=1
            foreach ($tier_seats as $individual_seat) {
                // Create cart item data for this individual seat
                $cart_item_data = [
                    'hope_theater_seats' => [$individual_seat],
                    'hope_seat_details' => [[
                        'seat_id' => $individual_seat,
                        'price' => $price_per_seat,
                        'tier' => $tier
                    ]],
                    'hope_session_id' => $session_id,
                    'hope_total_price' => $price_per_seat,
                    'hope_price_per_seat' => $price_per_seat,
                    'hope_seat_count' => 1,
                    'hope_tier' => $tier,
                    'unique_key' => md5($individual_seat . microtime() . rand())
                ];

                try {
                    $cart_item_key = WC()->cart->add_to_cart(
                        $product_id,
                        1,
                        $variation_id,
                        $variation_data,
                        $cart_item_data
                    );

                    if ($cart_item_key) {
                        $successful_additions++;
                        $total_seats_added++;
                    }
                } catch (Exception $e) {
                    error_log("HOPE add_to_cart: Exception adding seat {$individual_seat}: " . $e->getMessage());
                }
            }
        }

        // Calculate total from actual cart items added
        $cart_total = 0;
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            if ($cart_item['product_id'] == $product_id && isset($cart_item['hope_theater_seats'])) {
                $cart_total += $cart_item['line_total'];
            }
        }

        // Log final cart state
        $final_cart_seats = [];
        foreach (WC()->cart->get_cart() as $cart_item) {
            if ($cart_item['product_id'] == $product_id && isset($cart_item['hope_theater_seats'])) {
                $final_cart_seats = array_merge($final_cart_seats, $cart_item['hope_theater_seats']);
            }
        }
        file_put_contents($debug_file, date('Y-m-d H:i:s') . " FINAL CART STATE: " . count(WC()->cart->get_cart()) . " items, seats: " . implode(',', $final_cart_seats ?: ['none']) . "\n", FILE_APPEND);
        file_put_contents($debug_file, date('Y-m-d H:i:s') . " Successfully added: {$total_seats_added} seats\n", FILE_APPEND);

        // Send response based on results
        if ($successful_additions > 0) {
            // Extend hold expiration before redirecting to checkout
            $this->extend_hold_expiration($product_id, $session_id, $seats);

            file_put_contents($debug_file, date('Y-m-d H:i:s') . " SUCCESS - returning to JS\n\n", FILE_APPEND);

            wp_send_json_success([
                'cart_url' => wc_get_checkout_url(),
                'total' => $cart_total,
                'seats_added' => $total_seats_added,
                'message' => sprintf(
                    __('%d seats added from %d pricing tiers - proceeding to checkout', 'hope-theater-seating'),
                    $total_seats_added,
                    $successful_additions
                )
            ]);
        } else {
            file_put_contents($debug_file, date('Y-m-d H:i:s') . " FAILED - no seats added\n\n", FILE_APPEND);
            wp_send_json_error(['message' => 'Could not add any seats to cart']);
        }
    }

    /**
     * Remove existing cart items for this product to prevent duplicates
     * Preserves applied coupons (including URL-based coupons from Advanced Coupons)
     */
    private function remove_existing_seats_from_cart($product_id) {
        if (!function_exists('WC') || !WC()->cart) {
            return;
        }

        // Save current applied coupons before removing items
        $applied_coupons = WC()->cart->get_applied_coupons();

        // Check for URL coupon parameter (Advanced Coupons compatibility)
        // Try POST/GET first (from JavaScript), then WooCommerce session (from PHP capture)
        $url_coupon = isset($_REQUEST['coupon']) ? sanitize_text_field($_REQUEST['coupon']) : '';

        if (!$url_coupon && WC()->session) {
            $url_coupon = WC()->session->get('hope_url_coupon', '');
            if ($url_coupon) {
                error_log("HOPE: Retrieved URL coupon from WC session: {$url_coupon}");
            }
        }

        if ($url_coupon && !in_array($url_coupon, $applied_coupons, true)) {
            $applied_coupons[] = $url_coupon;
            error_log("HOPE: Detected URL coupon parameter: {$url_coupon}");
        }

        $removed_count = 0;

        // Loop through cart items and remove any for this product
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            if ($cart_item['product_id'] == $product_id) {
                // Check if this item has seat data (to avoid removing non-seat items)
                if (isset($cart_item['hope_theater_seats']) || isset($cart_item['hope_seat_details'])) {
                    WC()->cart->remove_cart_item($cart_item_key);
                    $removed_count++;
                    error_log("HOPE: Removed existing cart item for product {$product_id}, key: {$cart_item_key}");
                }
            }
        }

        // Reapply coupons that were previously applied (fixes Advanced Coupons URL coupon issue)
        if (!empty($applied_coupons)) {
            foreach ($applied_coupons as $coupon_code) {
                if (!WC()->cart->has_discount($coupon_code)) {
                    WC()->cart->apply_coupon($coupon_code);
                    error_log("HOPE: Reapplied coupon after cart modification: {$coupon_code}");
                }
            }
        }

        if ($removed_count > 0) {
            error_log("HOPE: Removed {$removed_count} existing cart items for product {$product_id}");
        }
    }
    
    /**
     * Release seat holds
     */
    public function release_seats() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'hope_seating_nonce')) {
            wp_die('Security check failed');
        }
        
        $session_id = sanitize_text_field($_POST['session_id']);
        $product_id = intval($_POST['product_id']);
        $seats = isset($_POST['seats']) ? json_decode(stripslashes($_POST['seats']), true) : [];
        
        global $wpdb;
        $table_holds = $wpdb->prefix . 'hope_seating_holds';
        
        // Also clear any pending bookings for this session that haven't been completed
        $table_bookings = $wpdb->prefix . 'hope_seating_bookings';
        
        if (empty($seats)) {
            // Release all holds for this session
            $holds_result = $wpdb->delete($table_holds, [
                'session_id' => $session_id,
                'product_id' => $product_id
            ]);
            
            // Also remove any pending bookings with no order_id (cart items not yet checked out)
            $bookings_result = $wpdb->delete($table_bookings, [
                'product_id' => $product_id,
                'order_id' => 0,
                'status' => 'pending'
                // Note: we can't easily match by session_id here since bookings don't store it
                // This cleanup will happen during the abandoned cart cleanup cron
            ]);
            
            $result = $holds_result;
        } else {
            // Release specific seats
            $result = 0;
            foreach ($seats as $seat_id) {
                $deleted = $wpdb->delete($table_holds, [
                    'session_id' => $session_id,
                    'product_id' => $product_id,
                    'seat_id' => $seat_id
                ]);
                $result += $deleted;
                
                // Also remove pending booking for this seat if it exists
                $wpdb->delete($table_bookings, [
                    'product_id' => $product_id,
                    'seat_id' => $seat_id,
                    'order_id' => 0,
                    'status' => 'pending'
                ]);
            }
        }
        
        wp_send_json_success([
            'released' => $result,
            'message' => sprintf(
                __('%d seats released', 'hope-theater-seating'),
                $result
            )
        ]);
    }
    
    /**
     * Check if a seat is available
     */
    private function is_seat_available($product_id, $seat_id, $session_id) {
        global $wpdb;
        
        // Check bookings
        $is_booked = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hope_seating_bookings 
            WHERE product_id = %d AND seat_id = %s AND status IN ('confirmed', 'pending')",
            $product_id, $seat_id
        ));
        
        if ($is_booked) {
            return false;
        }
        
        // Check holds by other sessions
        $is_held = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hope_seating_holds 
            WHERE product_id = %d AND seat_id = %s 
            AND session_id != %s AND expires_at > NOW()",
            $product_id, $seat_id, $session_id
        ));
        
        return !$is_held;
    }
    
    /**
     * Verify seat holds belong to session
     */
    private function verify_seat_holds($product_id, $seats, $session_id) {
        global $wpdb;
        $table_holds = $wpdb->prefix . 'hope_seating_holds';
        
        $seat_list = "'" . implode("','", array_map('esc_sql', $seats)) . "'";
        
        $held_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_holds 
            WHERE product_id = %d 
            AND seat_id IN ($seat_list) 
            AND session_id = %s 
            AND expires_at > NOW()",
            $product_id, $session_id
        ));
        
        return $held_count == count($seats);
    }
    
    /**
     * Get seats currently held by this session
     */
    private function get_held_seats($product_id, $session_id) {
        global $wpdb;
        $table_holds = $wpdb->prefix . 'hope_seating_holds';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT seat_id FROM $table_holds
            WHERE product_id = %d AND session_id = %s AND expires_at > NOW()",
            $product_id, $session_id
        ), ARRAY_A);

        $seat_ids = [];
        foreach ($results as $row) {
            $seat_ids[] = $row['seat_id'];
        }

        return $seat_ids;
    }

    /**
     * Get seats currently in cart for a specific product
     * Used to validate seats when user returns from checkout and adds more
     */
    private function get_cart_seats_for_product($product_id) {
        if (!function_exists('WC') || !WC()->cart) {
            return [];
        }

        $cart_seats = [];
        foreach (WC()->cart->get_cart() as $cart_item) {
            if ($cart_item['product_id'] == $product_id && !empty($cart_item['hope_theater_seats'])) {
                $cart_seats = array_merge($cart_seats, $cart_item['hope_theater_seats']);
            }
        }

        return array_unique($cart_seats);
    }

    /**
     * Get cart seats that still have valid holds (using their original session IDs)
     * CRITICAL: Cart items store their own session_id which may differ from current session
     */
    private function get_valid_cart_seats_with_holds($product_id, $return_with_sessions = false) {
        $debug_file = '/tmp/hope_debug.log';

        if (!function_exists('WC') || !WC()->cart) {
            file_put_contents($debug_file, date('Y-m-d H:i:s') . " get_valid_cart_seats: WC or cart not available\n", FILE_APPEND);
            return $return_with_sessions ? ['seats' => [], 'sessions' => []] : [];
        }

        global $wpdb;
        $table_holds = $wpdb->prefix . 'hope_seating_holds';
        $valid_seats = [];
        $seat_sessions = []; // Maps seat_id => original session_id

        $cart_items = WC()->cart->get_cart();
        file_put_contents($debug_file, date('Y-m-d H:i:s') . " get_valid_cart_seats: Checking " . count($cart_items) . " cart items for product {$product_id}\n", FILE_APPEND);

        foreach ($cart_items as $cart_item) {
            if ($cart_item['product_id'] != $product_id || empty($cart_item['hope_theater_seats'])) {
                continue;
            }

            // Get the session ID that was used when this cart item was created
            $cart_session_id = isset($cart_item['hope_session_id']) ? $cart_item['hope_session_id'] : '';
            $cart_seats = $cart_item['hope_theater_seats'];
            file_put_contents($debug_file, date('Y-m-d H:i:s') . " get_valid_cart_seats: Cart item seats: " . implode(',', $cart_seats) . ", session: {$cart_session_id}\n", FILE_APPEND);

            if (empty($cart_session_id)) {
                file_put_contents($debug_file, date('Y-m-d H:i:s') . " get_valid_cart_seats: No session ID, skipping\n", FILE_APPEND);
                continue;
            }

            // Check each seat in this cart item for a valid hold with ITS session ID
            foreach ($cart_seats as $seat_id) {
                $hold_exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table_holds}
                    WHERE seat_id = %s
                    AND product_id = %d
                    AND session_id = %s
                    AND expires_at > NOW()",
                    $seat_id,
                    $product_id,
                    $cart_session_id
                ));

                file_put_contents($debug_file, date('Y-m-d H:i:s') . " get_valid_cart_seats: Seat {$seat_id} with session {$cart_session_id}: " . ($hold_exists ? "VALID" : "EXPIRED") . "\n", FILE_APPEND);

                if ($hold_exists) {
                    $valid_seats[] = $seat_id;
                    $seat_sessions[$seat_id] = $cart_session_id;
                }
            }
        }

        file_put_contents($debug_file, date('Y-m-d H:i:s') . " get_valid_cart_seats: Returning " . count($valid_seats) . " valid seats: " . implode(',', $valid_seats ?: ['none']) . "\n", FILE_APPEND);

        if ($return_with_sessions) {
            return ['seats' => array_unique($valid_seats), 'sessions' => $seat_sessions];
        }
        return array_unique($valid_seats);
    }

    /**
     * Extend hold expiration for seats being taken to checkout
     * CRITICAL: Prevents holds from expiring during redirect and checkout page load
     */
    private function extend_hold_expiration($product_id, $session_id, $seats) {
        global $wpdb;
        $table_holds = $wpdb->prefix . 'hope_seating_holds';

        // Extend holds by full hold duration (default 15 minutes)
        // This gives plenty of time for redirect and checkout completion
        $hold_duration = class_exists('HOPE_Theater_Seating') ? HOPE_Theater_Seating::get_hold_duration() : 900;
        $new_expiry = gmdate('Y-m-d H:i:s', time() + $hold_duration);

        // Update expiration for all seats in this session/product
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table_holds}
            SET expires_at = %s
            WHERE product_id = %d
            AND session_id = %s",
            $new_expiry,
            $product_id,
            $session_id
        ));

        if ($updated) {
            error_log("HOPE: Extended hold expiration for {$updated} seats to {$new_expiry} (session: {$session_id})");
        } else {
            error_log("HOPE: WARNING - Failed to extend hold expiration or no holds found (session: {$session_id})");
        }

        return $updated;
    }
    
    /**
     * Convert holds to bookings
     */
    private function convert_holds_to_bookings($product_id, $seats, $session_id) {
        global $wpdb;
        $table_bookings = $wpdb->prefix . 'hope_seating_bookings';
        $table_holds = $wpdb->prefix . 'hope_seating_holds';
        
        foreach ($seats as $seat_id) {
            // Create pending booking
            $wpdb->insert($table_bookings, [
                'product_id' => $product_id,
                'seat_id' => $seat_id,
                'order_id' => 0, // Will be updated when order is created
                'customer_email' => '', // Will be updated at checkout
                'status' => 'pending',
                'created_at' => current_time('mysql')
            ]);
            
            // Remove hold
            $wpdb->delete($table_holds, [
                'product_id' => $product_id,
                'seat_id' => $seat_id,
                'session_id' => $session_id
            ]);
        }
    }
    
    /**
     * Group seats by their pricing tier using new architecture
     */
    private function group_seats_by_tier($seats) {
        global $wpdb;
        $seats_by_tier = [];
        
        // Use new architecture to get seat pricing data
        if (class_exists('HOPE_Pricing_Maps_Manager')) {
            $pricing_manager = new HOPE_Pricing_Maps_Manager();
            
            // Get the pricing map ID for current product
            $pricing_map_id = get_post_meta($this->current_product_id, '_hope_seating_venue_id', true);
            
            if ($pricing_map_id) {
                // Get all seats with pricing for this map
                $seats_with_pricing = $pricing_manager->get_seats_with_pricing($pricing_map_id);
                
                // Create lookup map: seat_id -> pricing_tier
                $seat_tier_lookup = [];
                foreach ($seats_with_pricing as $seat) {
                    $seat_tier_lookup[$seat->seat_id] = $seat->pricing_tier;
                }
                
                // Group selected seats by their tier
                foreach ($seats as $seat_id) {
                    $tier = isset($seat_tier_lookup[$seat_id]) ? $seat_tier_lookup[$seat_id] : 'P2'; // fallback
                    
                    if (!isset($seats_by_tier[$tier])) {
                        $seats_by_tier[$tier] = [];
                    }
                    $seats_by_tier[$tier][] = $seat_id;
                    
                    error_log("HOPE: Seat {$seat_id} assigned to tier {$tier} via new architecture");
                }
                
                return $seats_by_tier;
            }
        }
        
        // Fallback to old system if new architecture not available
        error_log("HOPE: WARNING - Using fallback tier assignment, new architecture not available");
        
        foreach ($seats as $seat_id) {
            $tier = $this->extract_tier_from_seat_id($seat_id);
            
            if (!isset($seats_by_tier[$tier])) {
                $seats_by_tier[$tier] = [];
            }
            $seats_by_tier[$tier][] = $seat_id;
        }
        
        return $seats_by_tier;
    }
    
    /**
     * Extract pricing tier from seat ID
     */
    private function extract_tier_from_seat_id($seat_id) {
        // Parse seat ID to determine pricing tier
        // Format examples: C8-1, C9-1, C10-1, etc.
        if (preg_match('/^([A-Z])(\d+)-(\d+)$/', $seat_id, $matches)) {
            $section = $matches[1];
            $row = intval($matches[2]);
            
            // Theater pricing logic based on HOPE Theater seat map configuration
            // Must match the tier assignments in seat-map.js exactly
            
            if ($section === 'A') {
                // Section A: rows 1-2 are p1, rows 3-7 are p2, rows 8-9 are p3, row 10 is aa
                if ($row <= 2) return 'p1';
                elseif ($row <= 7) return 'p2';
                elseif ($row <= 9) return 'p3';
                else return 'aa'; // row 10
            } 
            elseif ($section === 'B') {
                // Section B: rows 1-3 are p1, rows 4-9 are p2
                if ($row <= 3) return 'p1';
                else return 'p2';
            } 
            elseif ($section === 'C') {
                // Section C: rows 1-3 are p1, rows 4-9 are p2, row 10 is p3
                if ($row <= 3) return 'p1';
                elseif ($row <= 9) return 'p2';
                else return 'p3'; // row 10
            } 
            elseif ($section === 'D') {
                // Section D: rows 1-3 are p1, rows 4-9 are p2, row 10 is aa
                if ($row <= 3) return 'p1';
                elseif ($row <= 9) return 'p2';
                else return 'aa'; // row 10
            } 
            elseif ($section === 'E') {
                // Section E: rows 1-2 are p1, rows 3-7 are p2, rows 8-9 are p3, row 10 is aa
                if ($row <= 2) return 'p1';
                elseif ($row <= 7) return 'p2';
                elseif ($row <= 9) return 'p3';
                else return 'aa'; // row 10
            } 
            elseif ($section === 'F') {
                // Section F (Balcony): rows 1 is p1, rows 2-3 are p2, row 4 is p3
                if ($row <= 1) return 'p1';
                elseif ($row <= 3) return 'p2';
                else return 'p3'; // row 4
            } 
            elseif ($section === 'G') {
                // Section G (Balcony): row 1 is p1, rows 2-3 are p2, row 4 is p3
                if ($row <= 1) return 'p1';
                elseif ($row <= 3) return 'p2';
                else return 'p3'; // row 4
            } 
            elseif ($section === 'H') {
                // Section H (Balcony): row 1 is p1, rows 2-3 are p2, row 4 is p3
                if ($row <= 1) return 'p1';
                elseif ($row <= 3) return 'p2';
                else return 'p3'; // row 4
            }
        }
        
        // Default fallback
        return 'p2';
    }
    
    /**
     * Get actual variation price by variation ID
     */
    private function get_variation_price($variation_id, $tier) {
        if ($variation_id > 0) {
            $variation = wc_get_product($variation_id);
            if ($variation && $variation->exists()) {
                $price = $variation->get_price();
                error_log("HOPE: Got actual variation price for tier {$tier} (variation_id {$variation_id}): {$price}");
                return floatval($price);
            }
        }
        
        error_log("HOPE: Could not get variation price for variation_id {$variation_id}, using fallback");
        return $this->get_price_for_tier($tier);
    }

    /**
     * Get price for a specific pricing tier from actual WooCommerce variations
     */
    private function get_price_for_tier($tier) {
        global $wpdb;
        
        // Try to get actual variation price for this tier
        $product = wc_get_product($this->current_product_id ?? 0);
        
        if ($product && $product->is_type('variable')) {
            $variations = $product->get_available_variations();
            
            foreach ($variations as $variation_data) {
                $attributes = $variation_data['attributes'];
                
                // Use flexible attribute matching like we do elsewhere
                foreach ($attributes as $attr_key => $attr_value) {
                    if (stripos($attr_key, 'tier') !== false || stripos($attr_key, 'seating') !== false) {
                        $normalized_value = strtolower($attr_value);
                        if ($normalized_value === $tier) {
                            $variation = wc_get_product($variation_data['variation_id']);
                            if ($variation) {
                                $price = $variation->get_price();
                                error_log("HOPE: Found WooCommerce variation price for tier {$tier}: {$price}");
                                return floatval($price);
                            }
                        }
                    }
                }
            }
        }
        
        // Fallback to hardcoded prices if no variation found
        $tier_prices = [
            'p1' => 50.00, // Premium - default fallback
            'p2' => 35.00, // Standard - default fallback
            'p3' => 25.00, // Value - default fallback
            'aa' => 25.00  // Accessible - default fallback
        ];
        
        $fallback_price = isset($tier_prices[$tier]) ? $tier_prices[$tier] : $tier_prices['p2'];
        error_log("HOPE: Using fallback price for tier {$tier}: {$fallback_price}");
        return $fallback_price;
    }
    
    /**
     * Find a variation that matches the given pricing tier
     */
    private function find_variation_for_tier($available_variations, $tier) {
        $target_tier = strtolower($tier); // Normalize target tier
        
        foreach ($available_variations as $variation) {
            $attributes = $variation['attributes'];
            
            // Check all attributes for tier information
            foreach ($attributes as $attr_key => $attr_value) {
                if (stripos($attr_key, 'tier') !== false || stripos($attr_key, 'seating') !== false) {
                    $variation_tier = strtolower($attr_value);
                    if ($variation_tier === $target_tier) {
                        error_log("HOPE: Found matching variation via attribute {$attr_key}: {$attr_value} for tier {$tier}");
                        return $variation;
                    }
                }
            }
            
            // Also check for price-based matching as fallback
            if (isset($variation['display_price'])) {
                $variation_price = floatval($variation['display_price']);
                $tier_price = $this->get_price_for_tier($tier);
                
                // Match if prices are within $1 of each other
                if (abs($variation_price - $tier_price) < 1.0) {
                    error_log("HOPE: Found matching variation via price matching: {$variation_price} for tier {$tier}");
                    return $variation;
                }
            }
        }
        
        error_log("HOPE: No matching variation found for tier {$tier}");
        return null;
    }
}