<?php
/**
 * WooCommerce Integration for HOPE Theater Seating
 * Replaces WooCommerce variation selector with seat selection modal
 */

if (!defined('ABSPATH')) {
    exit;
}

class HOPE_WooCommerce_Integration {
    
    public function __construct() {
        // Hide default variation selectors when seating is enabled
        add_action('wp_enqueue_scripts', array($this, 'enqueue_integration_scripts'));
        add_action('woocommerce_before_variations_form', array($this, 'maybe_hide_variations_form'));
        add_action('woocommerce_before_add_to_cart_button', array($this, 'add_seat_selection_interface'));
        
        // Handle cart integration
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_seat_data_to_cart'), 10, 4);
        add_action('woocommerce_cart_item_name', array($this, 'display_seat_info_in_cart'), 10, 3);
        add_action('woocommerce_order_item_meta_start', array($this, 'display_seat_info_in_order'), 10, 4);
        
        // AJAX handlers for seat selection
        add_action('wp_ajax_hope_get_variation_for_seats', array($this, 'ajax_get_variation_for_seats'));
        add_action('wp_ajax_nopriv_hope_get_variation_for_seats', array($this, 'ajax_get_variation_for_seats'));
        
        // Integration with FooEvents ticket system
        add_action('woocommerce_checkout_order_processed', array($this, 'create_fooevents_ticket_data'), 10, 3);
    }
    
    /**
     * Enqueue integration scripts
     */
    public function enqueue_integration_scripts() {
        if (!is_product()) return;
        
        global $product;
        if (!$product || !is_object($product)) return;
        
        $product_id = $product->get_id();
        if (!$product_id) return;
        
        $seating_enabled = get_post_meta($product_id, '_hope_seating_enabled', true);
        if ($seating_enabled !== 'yes') return;
        
        wp_enqueue_script(
            'hope-woocommerce-integration',
            HOPE_SEATING_PLUGIN_URL . 'assets/js/woocommerce-integration.js',
            array('jquery'),
            HOPE_SEATING_VERSION,
            true
        );
        
        wp_localize_script('hope-woocommerce-integration', 'hopeWooIntegration', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hope_woo_integration_nonce'),
            'product_id' => $product->get_id(),
            'is_variable' => $product->is_type('variable'),
            'messages' => array(
                'select_seats_first' => __('Please select your seats first', 'hope-seating'),
                'seats_selected' => __('Seats Selected', 'hope-seating'),
                'change_seats' => __('Change Seats', 'hope-seating')
            )
        ));
    }
    
    /**
     * Hide variations form when seating is enabled
     */
    public function maybe_hide_variations_form() {
        global $product;
        
        if (!$product || !$product->is_type('variable')) return;
        
        $seating_enabled = get_post_meta($product->get_id(), '_hope_seating_enabled', true);
        if ($seating_enabled !== 'yes') return;
        
        // Add CSS to hide variations form but keep our seat selection interface
        echo '<style>
        .variations_form .variations {
            display: none !important;
        }
        .variations_form .single_variation_wrap .woocommerce-variation {
            display: none !important;
        }
        .hope-seating-enabled .quantity {
            display: none !important;
        }
        </style>';
        
        // Add class to product form
        echo '<script>
        jQuery(document).ready(function($) {
            $(".product").addClass("hope-seating-enabled");
        });
        </script>';
    }
    
    /**
     * Add seat selection interface before add to cart button
     */
    public function add_seat_selection_interface() {
        global $product;
        
        if (!$product || !is_object($product)) return;
        
        $product_id = $product->get_id();
        if (!$product_id) return;
        
        $seating_enabled = get_post_meta($product_id, '_hope_seating_enabled', true);
        $venue_id = get_post_meta($product_id, '_hope_seating_venue_id', true);
        
        if ($seating_enabled !== 'yes' || !$venue_id) return;
        
        // Get venue details
        global $wpdb;
        
        // Check if venues table exists
        $venues_table = $wpdb->prefix . 'hope_seating_venues';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$venues_table'") == $venues_table;
        
        if (!$table_exists) {
            error_log('HOPE Seating: Venues table does not exist');
            return;
        }
        
        $venue = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$venues_table} WHERE id = %d",
            $venue_id
        ));
        
        if (!$venue) {
            error_log('HOPE Seating: Venue not found for ID ' . $venue_id);
            return;
        }
        
        ?>
        <div class="hope-seat-selection-interface" data-product-id="<?php echo esc_attr($product_id); ?>">
            <div class="hope-seat-selection-prompt">
                <h4><?php _e('Seat Selection Required', 'hope-seating'); ?></h4>
                <p><?php printf(__('This event takes place at %s. Please select your seats to continue.', 'hope-seating'), esc_html($venue->name)); ?></p>
            </div>
            
            <div class="hope-seat-selection-button-wrapper">
                <button type="button" class="button alt hope-select-seats-btn" id="hope-select-seats-main">
                    <span class="btn-text"><?php _e('Select Your Seats', 'hope-seating'); ?></span>
                    <span class="btn-icon">🎭</span>
                </button>
            </div>
            
            <!-- Selected seats display (hidden initially) -->
            <div class="hope-selected-seats-display" style="display: none;">
                <h4><?php _e('Selected Seats:', 'hope-seating'); ?></h4>
                <div class="hope-seats-list"></div>
                <div class="hope-seats-total">
                    <span class="total-label"><?php _e('Total:', 'hope-seating'); ?></span>
                    <span class="total-amount">$0.00</span>
                </div>
                <button type="button" class="button hope-change-seats-btn">
                    <?php _e('Change Seats', 'hope-seating'); ?>
                </button>
            </div>
            
            <!-- Hidden fields for form submission -->
            <input type="hidden" name="hope_selected_seats" id="hope_selected_seats" value="">
            <input type="hidden" name="hope_selected_variation" id="hope_selected_variation" value="">
            <input type="hidden" name="fooevents_seats__trans" id="fooevents_seats__trans" value="">
        </div>
        
        <style>
        .hope-seat-selection-interface {
            margin: 20px 0;
            padding: 20px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            background: #f8f9fa;
        }
        
        .hope-seat-selection-prompt h4 {
            margin: 0 0 10px 0;
            color: #2c3e50;
            font-size: 18px;
        }
        
        .hope-seat-selection-prompt p {
            margin: 0 0 20px 0;
            color: #5a6c7d;
        }
        
        .hope-select-seats-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 15px 30px;
            font-size: 16px;
            font-weight: 600;
            background: #7c3aed;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .hope-select-seats-btn:hover {
            background: #6b21a8;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
        }
        
        .hope-select-seats-btn .btn-icon {
            font-size: 20px;
        }
        
        .hope-selected-seats-display {
            margin-top: 20px;
            padding: 20px;
            background: white;
            border: 1px solid #d1d5db;
            border-radius: 6px;
        }
        
        .hope-selected-seats-display h4 {
            margin: 0 0 15px 0;
            color: #059669;
            font-size: 16px;
        }
        
        .hope-seats-list {
            margin-bottom: 15px;
        }
        
        .hope-seat-tag {
            display: inline-block;
            margin: 0 8px 8px 0;
            padding: 6px 12px;
            background: #7c3aed;
            color: white;
            border-radius: 16px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .hope-seats-total {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-top: 1px solid #e5e7eb;
            font-size: 18px;
            font-weight: 600;
        }
        
        .hope-change-seats-btn {
            background: #6b7280;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
        }
        
        .hope-change-seats-btn:hover {
            background: #4b5563;
        }
        </style>
        <?php
    }
    
    /**
     * Add seat data to cart item
     */
    public function add_seat_data_to_cart($cart_item_data, $product_id, $variation_id, $quantity) {
        if (isset($_POST['hope_selected_seats']) && !empty($_POST['hope_selected_seats'])) {
            $selected_seats = json_decode(stripslashes($_POST['hope_selected_seats']), true);
            
            if (!empty($selected_seats)) {
                $cart_item_data['hope_selected_seats'] = $selected_seats;
                $cart_item_data['hope_seat_count'] = count($selected_seats);
                
                // Add FooEvents compatible data
                if (isset($_POST['fooevents_seats__trans'])) {
                    $cart_item_data['fooevents_seats__trans'] = $_POST['fooevents_seats__trans'];
                }
                
                // Ensure each seat selection gets a unique cart item
                $cart_item_data['unique_key'] = md5(microtime() . rand());
            }
        }
        
        return $cart_item_data;
    }
    
    /**
     * Display seat info in cart
     */
    public function display_seat_info_in_cart($name, $cart_item, $cart_item_key) {
        // Check for seats in either key (backwards compatibility)
        $seats = null;
        if (isset($cart_item['hope_theater_seats']) && !empty($cart_item['hope_theater_seats'])) {
            $seats = $cart_item['hope_theater_seats'];
        } elseif (isset($cart_item['hope_selected_seats']) && !empty($cart_item['hope_selected_seats'])) {
            $seats = $cart_item['hope_selected_seats'];
        }
        
        if ($seats) {
            $seat_list = is_array($seats) ? implode(', ', $seats) : $seats;
            $seat_count = is_array($seats) ? count($seats) : 1;
            
            $name .= '<br><small><strong>' . __('Seats:', 'hope-seating') . '</strong> ' . esc_html($seat_list) . '</small>';
            $name .= '<br><small><strong>' . __('Quantity:', 'hope-seating') . '</strong> ' . $seat_count . ' ' . __('seats', 'hope-seating') . '</small>';
        }
        
        return $name;
    }
    
    /**
     * Display seat info in order
     */
    public function display_seat_info_in_order($item_id, $item, $order, $plain_text) {
        // Check for seats in either meta key (backwards compatibility)
        $selected_seats = $item->get_meta('hope_theater_seats');
        if (empty($selected_seats)) {
            $selected_seats = $item->get_meta('hope_selected_seats');
        }
        
        if (!empty($selected_seats)) {
            $seats = is_array($selected_seats) ? $selected_seats : json_decode($selected_seats, true);
            if (!empty($seats)) {
                $seat_list = implode(', ', $seats);
                echo '<br><small><strong>' . __('Seats:', 'hope-seating') . '</strong> ' . esc_html($seat_list) . '</small>';
            }
        }
    }
    
    /**
     * AJAX: Get variation for selected seats
     */
    public function ajax_get_variation_for_seats() {
        check_ajax_referer('hope_woo_integration_nonce', 'nonce');
        
        $product_id = intval($_POST['product_id']);
        $selected_seats = json_decode(stripslashes($_POST['selected_seats']), true);
        
        if (empty($selected_seats)) {
            wp_send_json_error(array('message' => 'No seats selected'));
        }
        
        $product = wc_get_product($product_id);
        if (!$product || !$product->is_type('variable')) {
            wp_send_json_success(array('variation_id' => 0));
            return;
        }
        
        // Group seats by pricing tier to find appropriate variation
        $seat_tiers = array();
        if (class_exists('HOPE_Seat_Manager')) {
            $venue_id = get_post_meta($product_id, '_hope_seating_venue_id', true);
            $seat_manager = new HOPE_Seat_Manager($venue_id);
            
            global $wpdb;
            $seat_maps_table = $wpdb->prefix . 'hope_seating_seat_maps';
            
            foreach ($selected_seats as $seat_id) {
                $seat_data = $wpdb->get_row($wpdb->prepare(
                    "SELECT pricing_tier FROM $seat_maps_table WHERE venue_id = %d AND seat_id = %s",
                    $venue_id, $seat_id
                ));
                
                if ($seat_data) {
                    if (!isset($seat_tiers[$seat_data->pricing_tier])) {
                        $seat_tiers[$seat_data->pricing_tier] = 0;
                    }
                    $seat_tiers[$seat_data->pricing_tier]++;
                }
            }
        }
        
        // Find variation matching the primary pricing tier
        $primary_tier = array_keys($seat_tiers)[0] ?? 'P1';
        $variations = $product->get_available_variations();
        
        $matching_variation = null;
        foreach ($variations as $variation) {
            $attributes = $variation['attributes'];
            if (isset($attributes['attribute_seating-tier']) && $attributes['attribute_seating-tier'] === $primary_tier) {
                $matching_variation = $variation;
                break;
            }
        }
        
        if ($matching_variation) {
            wp_send_json_success(array(
                'variation_id' => $matching_variation['variation_id'],
                'price_html' => $matching_variation['price_html'],
                'seat_tiers' => $seat_tiers
            ));
        } else {
            wp_send_json_success(array('variation_id' => 0));
        }
    }
    
    /**
     * Create FooEvents compatible ticket data
     */
    public function create_fooevents_ticket_data($order_id, $posted_data, $order) {
        if (!class_exists('FooEvents')) return;
        
        foreach ($order->get_items() as $item_id => $item) {
            $selected_seats = $item->get_meta('hope_selected_seats');
            
            if (!empty($selected_seats)) {
                $seats = is_array($selected_seats) ? $selected_seats : json_decode($selected_seats, true);
                
                // Convert our seat format to FooEvents format
                foreach ($seats as $index => $seat_id) {
                    // Parse seat ID (e.g., "A1-5" -> Section A, Row 1, Seat 5)
                    $seat_parts = $this->parse_seat_id($seat_id);
                    
                    // Store in FooEvents format
                    $item->add_meta_data('fooevents_seat_row_name_' . $index, $seat_parts['section'] . ' ' . $seat_parts['row']);
                    $item->add_meta_data('fooevents_seat_number_' . $index, $seat_parts['seat']);
                }
                
                $item->save();
            }
        }
    }
    
    /**
     * Parse seat ID into components
     */
    private function parse_seat_id($seat_id) {
        // Handle format like "A1-5" (Section A, Row 1, Seat 5)
        if (preg_match('/([A-Z])(\d+)-(\d+)/', $seat_id, $matches)) {
            return array(
                'section' => $matches[1],
                'row' => $matches[2],
                'seat' => $matches[3]
            );
        }
        
        // Fallback format
        return array(
            'section' => substr($seat_id, 0, 1),
            'row' => '1',
            'seat' => $seat_id
        );
    }
}

// Initialize the integration
new HOPE_WooCommerce_Integration();