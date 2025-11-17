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
        
        // DEBUG: Log what we received
        error_log("HOPE hold_seats: Received raw seats data: " . $_POST['seats']);
        error_log("HOPE hold_seats: Decoded seats array: " . print_r($seats, true));
        error_log("HOPE hold_seats: Session ID: {$session_id}");
        
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
        $deleted = $wpdb->delete($table_holds, [
            'session_id' => $session_id,
            'product_id' => $product_id
        ]);
        
        error_log("Released {$deleted} existing holds for session {$session_id}");
        
        $success = true;
        $held_seats = [];
        // Get hold duration from admin settings
        $hold_duration = class_exists('HOPE_Theater_Seating') ? HOPE_Theater_Seating::get_hold_duration() : 900;
        // Use gmdate() to match MySQL NOW() which uses UTC
        $expires_at = gmdate('Y-m-d H:i:s', time() + $hold_duration);
        
        $unavailable_seats = [];
        
        foreach ($seats as $seat_id) {
            // Check if seat is available
            $is_available = $this->is_seat_available($product_id, $seat_id, $session_id);
            
            error_log("Seat {$seat_id} availability: " . ($is_available ? 'available' : 'not available'));
            
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
                    error_log("Successfully held seat {$seat_id}");
                } else {
                    $success = false;
                    error_log("Failed to hold seat {$seat_id}: " . $wpdb->last_error);
                }
            } else {
                $unavailable_seats[] = $seat_id;
                error_log("Seat {$seat_id} is not available for holding");
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
        error_log('HOPE: add_to_cart called with data: ' . print_r($_POST, true));
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'hope_seating_nonce')) {
            error_log('HOPE: Nonce verification failed');
            wp_die('Security check failed');
        }
        
        $product_id = intval($_POST['product_id']);
        $seats = json_decode(stripslashes($_POST['seats']), true);
        $session_id = sanitize_text_field($_POST['session_id']);
        
        // Set current product ID for pricing methods
        $this->current_product_id = $product_id;
        
        error_log("HOPE: Processing add_to_cart - Product ID: {$product_id}, Seats: " . print_r($seats, true) . ", Session: {$session_id}");
        
        if (empty($seats)) {
            error_log('HOPE: No seats selected');
            wp_send_json_error(['message' => 'No seats selected']);
        }
        
        // Check if WooCommerce is available
        if (!function_exists('WC') || !WC()->cart) {
            error_log('HOPE: WooCommerce cart not available');
            wp_send_json_error(['message' => 'Shopping cart not available']);
        }
        
        // Check if product exists
        $product = wc_get_product($product_id);
        if (!$product) {
            error_log("HOPE: Product {$product_id} not found");
            wp_send_json_error(['message' => 'Product not found']);
        }
        
        error_log("HOPE: Product found: {$product->get_name()}, Type: {$product->get_type()}");
        
        // Remove any existing cart items for this product to prevent duplicates
        $this->remove_existing_seats_from_cart($product_id);
        
        // Handle variable products - need to map seats to their appropriate variations
        $available_variations = [];
        $variation_map = [];
        
        if ($product->is_type('variable')) {
            error_log('HOPE: Product is variable, getting available variations');
            $available_variations = $product->get_available_variations();
            
            if (empty($available_variations)) {
                error_log('HOPE: No available variations found');
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
                        error_log("HOPE: Found tier attribute {$attr_key} = {$attr_value} (normalized: {$tier})");
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
            
            error_log('HOPE: All available variations: ' . print_r($available_variations, true));
            error_log('HOPE: Available variation map: ' . print_r($variation_map, true));
        }
        
        // Get currently held seats for this session (may be fewer than requested if some were unavailable)
        $actually_held_seats = $this->get_held_seats($product_id, $session_id);
        error_log('HOPE: Currently held seats: ' . print_r($actually_held_seats, true));
        error_log('HOPE: Requested seats: ' . print_r($seats, true));
        error_log('HOPE: Requested seat count: ' . count($seats) . ', Actually held count: ' . count($actually_held_seats));
        
        if (empty($actually_held_seats)) {
            error_log('HOPE: No seats are currently held for this session');
            wp_send_json_error(['message' => 'No seats are currently held. Please select seats first.']);
        }
        
        // Show discrepancy warning if counts don't match
        if (count($seats) !== count($actually_held_seats)) {
            $missing_seats = array_diff($seats, $actually_held_seats);
            error_log('HOPE: WARNING - Seat count mismatch! Missing seats: ' . print_r($missing_seats, true));
        }
        
        // Use only the seats that are actually held
        $seats = $actually_held_seats;
        error_log('HOPE: Using actually held seats for cart: ' . print_r($seats, true));
        
        error_log('HOPE: Seat holds verified successfully');
        
        // Group seats by their pricing tier
        $seats_by_tier = $this->group_seats_by_tier($seats);
        error_log('HOPE: Seats grouped by tier: ' . print_r($seats_by_tier, true));
        
        $successful_additions = 0;
        $total_seats_added = 0;
        
        // CRITICAL FIX: Add each seat as an individual cart item (quantity=1)
        // This ensures seats from different tiers create separate cart items
        foreach ($seats_by_tier as $tier => $tier_seats) {
            error_log("HOPE: Processing tier {$tier} with " . count($tier_seats) . " seats: " . implode(', ', $tier_seats));
            
            // Find the appropriate variation for this tier
            $variation_id = 0;
            $variation_data = [];
            
            $tier_key = strtolower($tier); // Normalize for map lookup
            if (isset($variation_map[$tier_key])) {
                $variation_id = $variation_map[$tier_key]['variation_id'];
                $variation_data = $variation_map[$tier_key]['attributes'];
                error_log("HOPE: Found exact variation match for tier {$tier} (key: {$tier_key}): variation_id {$variation_id}");
            } else {
                // Try to find a matching variation by searching all available variations
                $matching_variation = $this->find_variation_for_tier($available_variations, $tier);
                if ($matching_variation) {
                    $variation_id = $matching_variation['variation_id'];
                    $variation_data = $matching_variation['attributes'];
                    error_log("HOPE: Found fallback variation for tier {$tier}: variation_id {$variation_id}");
                } elseif (!empty($available_variations)) {
                    // Last resort: use first available variation but log the issue
                    $variation_id = $available_variations[0]['variation_id'];
                    $variation_data = $available_variations[0]['attributes'];
                    error_log("HOPE: WARNING - Using first available variation for tier {$tier} as no match found");
                }
            }
            
            if ($variation_id === 0) {
                error_log("HOPE: ERROR - No variation found for tier {$tier}, skipping");
                continue;
            }
            
            // Calculate pricing for this tier using the actual variation price
            $price_per_seat = $this->get_variation_price($variation_id, $tier);
            
            // Add each seat as a separate cart item with quantity=1
            foreach ($tier_seats as $individual_seat) {
                error_log("HOPE: Adding individual seat {$individual_seat} from tier {$tier} as separate cart item");

                // Get hold expiration time for countdown timer
                $holds_table = $wpdb->prefix . 'hope_seating_holds';
                $hold_expires_at = $wpdb->get_var($wpdb->prepare(
                    "SELECT expires_at FROM {$holds_table}
                    WHERE seat_id = %s AND product_id = %d AND session_id = %s
                    ORDER BY expires_at DESC LIMIT 1",
                    $individual_seat,
                    $product_id,
                    $session_id
                ));

                // Create cart item data for this individual seat
                $cart_item_data = [
                    'hope_theater_seats' => [$individual_seat], // Array with single seat
                    'hope_seat_details' => [[
                        'seat_id' => $individual_seat,
                        'price' => $price_per_seat,
                        'tier' => $tier
                    ]],
                    'hope_session_id' => $session_id,
                    'hope_hold_expires_at' => $hold_expires_at, // For checkout countdown timer
                    'hope_total_price' => $price_per_seat,
                    'hope_price_per_seat' => $price_per_seat,
                    'hope_seat_count' => 1, // Always 1 for individual seats
                    'hope_tier' => $tier,
                    'unique_key' => md5($individual_seat . microtime() . rand()) // Ensure uniqueness
                ];
                
                error_log("HOPE: Adding individual seat {$individual_seat} to cart - tier {$tier} at \${$price_per_seat}");
                error_log('HOPE: Individual seat cart data: ' . print_r($cart_item_data, true));
                
                try {
                    $cart_item_key = WC()->cart->add_to_cart(
                        $product_id,
                        1, // Always quantity 1 for individual seats
                        $variation_id,
                        $variation_data,
                        $cart_item_data
                    );
                    
                    if ($cart_item_key) {
                        error_log("HOPE: Successfully added individual seat {$individual_seat} to cart with key {$cart_item_key}");
                        $successful_additions++;
                        $total_seats_added += 1;
                        
                        // DO NOT convert holds to bookings yet - keep as holds until checkout
                        // This allows users to change their selection by going back to the modal
                        // Conversion to bookings will happen during checkout process
                        
                    } else {
                        error_log("HOPE: Failed to add individual seat {$individual_seat} to cart");
                    }
                } catch (Exception $e) {
                    error_log("HOPE: Exception adding individual seat {$individual_seat} to cart: " . $e->getMessage());
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
        
        // Send response based on results
        if ($successful_additions > 0) {
            // CRITICAL FIX: Extend hold expiration before redirecting to checkout
            // This prevents holds from expiring during redirect/page load
            $this->extend_hold_expiration($product_id, $session_id, $seats);

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
            error_log('HOPE: No cart items were successfully added');
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