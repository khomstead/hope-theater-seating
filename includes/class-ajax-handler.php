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
        $session_id = sanitize_text_field($_POST['session_id']);
        
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
            
            wp_send_json_success([
                'available' => true,
                'unavailable_seats' => array_unique($all_unavailable_seats)
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
        $hold_duration = 600; // 10 minutes
        $expires_at = date('Y-m-d H:i:s', time() + $hold_duration);
        
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
                $tier = $variation['attributes']['attribute_seating-tier'] ?? '';
                if ($tier) {
                    $variation_map[$tier] = [
                        'variation_id' => $variation['variation_id'],
                        'attributes' => $variation['attributes']
                    ];
                }
            }
            
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
            
            if (isset($variation_map[$tier])) {
                $variation_id = $variation_map[$tier]['variation_id'];
                $variation_data = $variation_map[$tier]['attributes'];
                error_log("HOPE: Found exact variation match for tier {$tier}: variation_id {$variation_id}");
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
            
            // Calculate pricing for this tier
            $price_per_seat = $this->get_price_for_tier($tier);
            
            // Add each seat as a separate cart item with quantity=1
            foreach ($tier_seats as $individual_seat) {
                error_log("HOPE: Adding individual seat {$individual_seat} from tier {$tier} as separate cart item");
                
                // Create cart item data for this individual seat
                $cart_item_data = [
                    'hope_theater_seats' => [$individual_seat], // Array with single seat
                    'hope_seat_details' => [[
                        'seat_id' => $individual_seat,
                        'price' => $price_per_seat,
                        'tier' => $tier
                    ]],
                    'hope_session_id' => $session_id,
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
                        
                        // Convert hold to pending booking for this individual seat
                        $this->convert_holds_to_bookings($product_id, [$individual_seat], $session_id);
                    } else {
                        error_log("HOPE: Failed to add individual seat {$individual_seat} to cart");
                    }
                } catch (Exception $e) {
                    error_log("HOPE: Exception adding individual seat {$individual_seat} to cart: " . $e->getMessage());
                }
            }
        }
        
        // Send response based on results
        if ($successful_additions > 0) {
            wp_send_json_success([
                'cart_url' => wc_get_checkout_url(),
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
     * Group seats by their pricing tier
     */
    private function group_seats_by_tier($seats) {
        global $wpdb;
        $seat_maps_table = $wpdb->prefix . 'hope_seating_seat_maps';
        $seats_by_tier = [];
        
        foreach ($seats as $seat_id) {
            // Get the pricing tier for this seat
            $seat_data = $wpdb->get_row($wpdb->prepare(
                "SELECT pricing_tier FROM $seat_maps_table WHERE seat_id = %s LIMIT 1",
                $seat_id
            ));
            
            if ($seat_data && !empty($seat_data->pricing_tier)) {
                $tier = $seat_data->pricing_tier;
            } else {
                // Fallback: extract tier from seat ID (e.g., E10-1 -> P2)
                $tier = $this->extract_tier_from_seat_id($seat_id);
            }
            
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
        // Format examples: E10-1, E9-1, E7-1, E2-1
        if (preg_match('/^([A-Z])(\d+)-(\d+)$/', $seat_id, $matches)) {
            $section = $matches[1];
            $row = intval($matches[2]);
            
            // Theater pricing logic based on HOPE Theater layout
            if ($section === 'A' || $section === 'B') {
                return 'P1'; // Premium
            } elseif ($section === 'C' || $section === 'D') {
                return 'P2'; // Standard
            } elseif ($section === 'E' || $section === 'F') {
                if ($row <= 5) {
                    return 'P2'; // Standard
                } else {
                    return 'P3'; // Value
                }
            } elseif ($section === 'G' || $section === 'H') {
                return 'P3'; // Value
            }
        }
        
        // Default fallback
        return 'P2';
    }
    
    /**
     * Get price for a specific pricing tier
     */
    private function get_price_for_tier($tier) {
        // Define tier pricing - these would typically come from WooCommerce variations
        $tier_prices = [
            'P1' => 85.00, // Premium
            'P2' => 65.00, // Standard
            'P3' => 45.00, // Value
            'AA' => 95.00  // VIP/Accessible
        ];
        
        return isset($tier_prices[$tier]) ? $tier_prices[$tier] : $tier_prices['P2'];
    }
    
    /**
     * Find a variation that matches the given pricing tier
     */
    private function find_variation_for_tier($available_variations, $tier) {
        foreach ($available_variations as $variation) {
            // Check if this variation has a seating-tier attribute that matches
            if (isset($variation['attributes']['attribute_seating-tier'])) {
                $variation_tier = $variation['attributes']['attribute_seating-tier'];
                if ($variation_tier === $tier) {
                    return $variation;
                }
            }
            
            // Also check for price-based matching as fallback
            if (isset($variation['display_price'])) {
                $variation_price = floatval($variation['display_price']);
                $tier_price = $this->get_price_for_tier($tier);
                
                // Match if prices are within $1 of each other
                if (abs($variation_price - $tier_price) < 1.0) {
                    return $variation;
                }
            }
        }
        
        return null;
    }
}