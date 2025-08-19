<?php
/**
 * AJAX Handler Class
 * Manages all AJAX requests for seat selection
 */

if (!defined('ABSPATH')) {
    exit;
}

class HOPE_Ajax_Handler {
    
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
        
        if (empty($requested_seats)) {
            wp_send_json_error(['message' => 'No seats selected']);
        }
        
        global $wpdb;
        $table_bookings = $wpdb->prefix . 'hope_seating_bookings';
        $table_holds = $wpdb->prefix . 'hope_seating_holds';
        
        $unavailable_seats = [];
        
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
                $product_id, $seat_id, $_POST['session_id']
            ));
            
            if ($is_booked || $is_held) {
                $unavailable_seats[] = $seat_id;
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
        $hold_duration = 600; // 10 minutes
        $expires_at = date('Y-m-d H:i:s', time() + $hold_duration);
        
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
                    'created_at' => current_time('mysql')
                ]);
                
                if ($result) {
                    $held_seats[] = $seat_id;
                    error_log("Successfully held seat {$seat_id}");
                } else {
                    $success = false;
                    error_log("Failed to hold seat {$seat_id}: " . $wpdb->last_error);
                }
            } else {
                error_log("Seat {$seat_id} is not available for holding");
            }
        }
        
        if ($success && count($held_seats) > 0) {
            wp_send_json_success([
                'held_seats' => $held_seats,
                'expires_at' => $expires_at,
                'hold_duration' => $hold_duration
            ]);
        } else {
            wp_send_json_error([
                'message' => 'Could not hold all requested seats',
                'held_seats' => $held_seats
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
        
        // Handle variable products - get the first available variation
        $variation_id = 0;
        $variation_data = [];
        
        if ($product->is_type('variable')) {
            error_log('HOPE: Product is variable, getting available variations');
            $available_variations = $product->get_available_variations();
            
            if (empty($available_variations)) {
                error_log('HOPE: No available variations found');
                wp_send_json_error(['message' => 'No available ticket options found']);
            }
            
            // Use the first available variation
            $first_variation = $available_variations[0];
            $variation_id = $first_variation['variation_id'];
            $variation_data = $first_variation['attributes'] ?? [];
            
            error_log("HOPE: Using variation ID: {$variation_id}");
            error_log('HOPE: Variation attributes: ' . print_r($variation_data, true));
        }
        
        // Verify all seats are held by this session
        if (!$this->verify_seat_holds($product_id, $seats, $session_id)) {
            error_log('HOPE: Seat holds verification failed');
            wp_send_json_error(['message' => 'Seat selection expired. Please try again.']);
        }
        
        error_log('HOPE: Seat holds verified successfully');
        
        // Get seat details and calculate price - simplified for now
        $total_price = count($seats) * 120; // Default price per seat
        
        // Create seat details array
        $seat_details = [];
        foreach ($seats as $seat_id) {
            $seat_details[] = [
                'seat_id' => $seat_id,
                'price' => 120 // Default price
            ];
        }
        
        // Create cart item data
        $cart_item_data = [
            'hope_theater_seats' => $seats,
            'hope_seat_details' => $seat_details,
            'hope_session_id' => $session_id,
            'hope_total_price' => $total_price
        ];
        
        error_log('HOPE: Adding to cart with data: ' . print_r($cart_item_data, true));
        
        // Add to WooCommerce cart
        try {
            $cart_item_key = WC()->cart->add_to_cart(
                $product_id,
                1,
                $variation_id,
                $variation_data,
                $cart_item_data
            );
            
            error_log("HOPE: WooCommerce add_to_cart returned: " . ($cart_item_key ? $cart_item_key : 'FALSE'));
            
            if ($cart_item_key) {
                // Convert holds to pending bookings
                $this->convert_holds_to_bookings($product_id, $seats, $session_id);
                
                error_log('HOPE: Successfully added seats to cart and converted holds to bookings');
                
                wp_send_json_success([
                    'cart_url' => wc_get_cart_url(),
                    'message' => sprintf(
                        __('%d seats added to cart', 'hope-theater-seating'),
                        count($seats)
                    )
                ]);
            } else {
                error_log('HOPE: WooCommerce add_to_cart failed - no cart item key returned');
                
                // Get WooCommerce notices to see what went wrong
                $notices = wc_get_notices('error');
                error_log('HOPE: WooCommerce error notices: ' . print_r($notices, true));
                
                wp_send_json_error(['message' => 'Could not add seats to cart - WooCommerce error']);
            }
        } catch (Exception $e) {
            error_log('HOPE: Exception during add_to_cart: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Error adding seats to cart: ' . $e->getMessage()]);
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
        
        if (empty($seats)) {
            // Release all holds for this session
            $result = $wpdb->delete($table_holds, [
                'session_id' => $session_id,
                'product_id' => $product_id
            ]);
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
}