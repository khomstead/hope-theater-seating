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
        
        // Add FooEvents compatible seat data to cart
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_fooevents_seat_data_to_cart'), 20, 4);
        add_filter('woocommerce_cart_item_name', array($this, 'display_seat_info_in_cart'), 10, 3);
        add_filter('woocommerce_widget_cart_item_quantity', array($this, 'display_seat_info_in_mini_cart'), 10, 3);
        add_action('woocommerce_order_item_meta_start', array($this, 'display_seat_info_in_order'), 10, 4);
        
        // Additional cart display hooks for compatibility with different themes
        add_filter('woocommerce_get_item_data', array($this, 'display_seat_info_as_item_data'), 10, 2);
        
        // Handle pricing for seat products
        add_action('woocommerce_before_calculate_totals', array($this, 'set_seat_pricing'));
        
        // AJAX handlers for seat selection
        add_action('wp_ajax_hope_get_variation_for_seats', array($this, 'ajax_get_variation_for_seats'));
        add_action('wp_ajax_nopriv_hope_get_variation_for_seats', array($this, 'ajax_get_variation_for_seats'));
        
        // CRITICAL: Validate seat holds BEFORE order is created
        add_action('woocommerce_after_checkout_validation', array($this, 'validate_seat_holds_at_checkout'), 10, 2);

        // Integration with FooEvents ticket system (with error handling)
        add_action('woocommerce_checkout_order_processed', array($this, 'create_fooevents_ticket_data'), 20, 3);

        // Create FooEvents Seating compatibility layer
        $this->setup_fooevents_seating_compatibility();

        // Mark purchased seats as unavailable after successful payment
        add_action('woocommerce_order_status_processing', array($this, 'mark_seats_as_sold'), 10, 1);
        add_action('woocommerce_order_status_completed', array($this, 'mark_seats_as_sold'), 10, 1);
        
        // Hook into FooEvents to emulate seating plugin functionality
        add_action('init', array($this, 'emulate_fooevents_seating_plugin'));
        
        // Hook into FooEvents ticket data population
        add_filter('fooevents_custom_ticket_fields', array($this, 'add_seat_data_to_ticket'), 10, 2);
        
        
        // Ensure seat data gets saved to order items during checkout
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'save_seat_data_to_order_item'), 10, 4);
        
        // Redirect theater seating products to checkout instead of cart
        add_filter('woocommerce_add_to_cart_redirect', array($this, 'redirect_to_checkout_for_seating'), 10, 1);
        
        // Hide visible seat metadata from order details display  
        add_filter('woocommerce_order_item_display_meta_key', array($this, 'hide_seat_metadata_from_display'), 10, 3);
        add_filter('woocommerce_order_item_get_formatted_meta_data', array($this, 'filter_order_item_meta_data'), 10, 2);
    }
    
    /**
     * Setup FooEvents Seating compatibility layer
     * This makes FooEvents think the seating plugin is active and provides the expected interface
     */
    private function setup_fooevents_seating_compatibility() {
        // Only setup if FooEvents is active but seating plugin is not
        if (class_exists('FooEvents') && !class_exists('Fooevents_Seating')) {
            // Store global reference for the class to use
            global $hope_woo_integration_instance;
            $hope_woo_integration_instance = $this;
            
            // Include the compatibility class file
            require_once dirname(__FILE__) . '/class-fooevents-seating-compatibility.php';
            
            // Make the seating plugin appear "active" to FooEvents
            add_filter('pre_option_active_plugins', array($this, 'fake_seating_plugin_active'));
            
            // Hook into FooEvents ticket creation process more aggressively
            add_action('fooevents_create_ticket', array($this, 'debug_ticket_creation'), 5, 3);
            add_filter('fooevents_ticket_data', array($this, 'inject_seat_data_into_ticket'), 10, 2);
            
            error_log("HOPE: FooEvents Seating compatibility layer activated with enhanced hooks");
        }
    }
    
    /**
     * Make FooEvents think the seating plugin is active
     */
    public function fake_seating_plugin_active($active_plugins) {
        if (is_array($active_plugins) && !in_array('fooevents_seating/fooevents-seating.php', $active_plugins)) {
            $active_plugins[] = 'fooevents_seating/fooevents-seating.php';
            error_log("HOPE: Added fooevents_seating to active plugins list during checkout");
        }
        return $active_plugins;
    }
    
    /**
     * Capture HOPE seating options in FooEvents format
     */
    public function capture_hope_seating_options($product_id, $x, $y, $ticket_data) {
        try {
            error_log("HOPE: *** capture_hope_seating_options called - product: {$product_id}, x: {$x}, y: {$y} ***");
            error_log("HOPE: ticket_data keys: " . implode(', ', array_keys($ticket_data)));
            error_log("HOPE: ticket_data: " . print_r($ticket_data, true));
            
            // Get seat data from the current order being processed
            if (isset($ticket_data['seats']) && !empty($ticket_data['seats'])) {
                $seats_string = $ticket_data['seats'];
                $seats = explode(',', $seats_string);
                
                // Use the seat for this specific ticket (y-1 because y is 1-based)
                $seat_index = max(0, $y - 1);
                $seat_id = isset($seats[$seat_index]) ? $seats[$seat_index] : $seats[0];
                
                error_log("HOPE: Using seat {$seat_id} for ticket index {$seat_index}");
                
                // Parse seat ID and create FooEvents format
                $seat_parts = $this->parse_seat_id($seat_id);
                $section_name = $this->get_section_display_name($seat_parts['section']);
                
                $seating_fields = array(
                    'fooevents_seat_row_name_0' => $section_name . ' Row ' . $seat_parts['row'],
                    'fooevents_seat_number_0' => $seat_parts['seat']
                );
                
                error_log("HOPE: Generated seating fields: " . print_r($seating_fields, true));
                
                return $seating_fields;
            }
            
            error_log("HOPE: No seat data found in ticket_data");
            return array();
            
        } catch (Exception $e) {
            error_log("HOPE: Error in capture_hope_seating_options: " . $e->getMessage());
            return array();
        }
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
            'ajax_url' => '/wp-admin/admin-ajax.php', // Use relative path to avoid CORS issues
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
        .hope-seating-enabled .single_add_to_cart_button {
            display: none !important;
        }
        .hope-seating-enabled button[type="submit"] {
            display: none !important;
        }
        </style>';
        
        // Add class to product form and hide notices
        echo '<style>
        .hope-seating-enabled .woocommerce-notices-wrapper,
        .hope-seating-enabled .woocommerce-message,
        .hope-seating-enabled .woocommerce-error,
        .hope-seating-enabled .woocommerce-info,
        .hope-seating-enabled .wc-block-components-notice-banner {
            display: none !important;
        }
        </style>';
        
        echo '<script>
        jQuery(document).ready(function($) {
            $(".product").addClass("hope-seating-enabled");
            // Aggressively hide WooCommerce notices
            $(".woocommerce-notices-wrapper, .woocommerce-message, .woocommerce-error, .woocommerce-info").hide();
            
            // Also remove them from DOM after a delay
            setTimeout(function() {
                $(".woocommerce-notices-wrapper, .woocommerce-message, .woocommerce-error, .woocommerce-info").remove();
            }, 1000);
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
        
        error_log("HOPE: Product {$product_id} - seating_enabled: '{$seating_enabled}', venue_id: '{$venue_id}'");
        
        if ($seating_enabled !== 'yes' || !$venue_id) {
            error_log("HOPE: Seating interface not rendered - seating_enabled: '{$seating_enabled}', venue_id: '{$venue_id}'");
            return;
        }
        
        // Use new pricing maps architecture
        $pricing_map_id = $venue_id; // Actually pricing map ID now
        
        if (!class_exists('HOPE_Pricing_Maps_Manager')) {
            error_log('HOPE Seating: New architecture not available');
            return;
        }
        
        $pricing_manager = new HOPE_Pricing_Maps_Manager();
        $pricing_maps = $pricing_manager->get_pricing_maps();
        
        // Find the pricing map
        $pricing_map = null;
        foreach ($pricing_maps as $map) {
            if ($map->id == $pricing_map_id) {
                $pricing_map = $map;
                break;
            }
        }
        
        if (!$pricing_map) {
            error_log('HOPE Seating: Pricing map not found for ID ' . $pricing_map_id);
            return;
        }
        
        // Get seats count for display
        $seats_with_pricing = $pricing_manager->get_seats_with_pricing($pricing_map_id);
        error_log("HOPE: Found " . count($seats_with_pricing) . " seats for pricing map {$pricing_map_id}");
        
        // Direct database check
        global $wpdb;
        $direct_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}hope_seating_seat_pricing WHERE pricing_map_id = %d", $pricing_map_id));
        $physical_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}hope_seating_physical_seats");
        error_log("HOPE DEBUG: Direct DB count for map $pricing_map_id: $direct_count, Physical seats: $physical_count");
        
        if (empty($seats_with_pricing)) {
            error_log('HOPE Seating: No seats found for pricing map ' . $pricing_map_id);
            return;
        }
        
        ?>
        <div class="hope-seat-selection-interface" 
             data-product-id="<?php echo esc_attr($product_id); ?>"
             data-venue-id="<?php echo esc_attr($pricing_map_id); ?>"
             data-venue-name="<?php echo esc_attr($pricing_map->name); ?>"
             data-total-seats="<?php echo esc_attr(count($seats_with_pricing)); ?>">
            <div class="hope-seat-selection-button-wrapper">
                <button type="button" class="button alt hope-select-seats-btn" id="hope-select-seats-main">
                    <?php _e('Select Seats', 'hope-seating'); ?>
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
            <!-- FooEvents compatibility field -->
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
            flex-direction: column;
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
        
        .hope-select-seats-btn .btn-subtitle {
            font-size: 14px;
            font-weight: 400;
            opacity: 0.9;
            margin-top: 5px;
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
        // Check for HOPE Theater seating data first
        if (isset($_POST['hope_selected_seats']) && !empty($_POST['hope_selected_seats'])) {
            $selected_seats = json_decode(stripslashes($_POST['hope_selected_seats']), true);
            
            if (!empty($selected_seats)) {
                $cart_item_data['hope_selected_seats'] = $selected_seats;
                $cart_item_data['hope_seat_count'] = count($selected_seats);
                
                // Add FooEvents compatible data for compatibility
                if (isset($_POST['fooevents_seats__trans'])) {
                    $cart_item_data['fooevents_seats__trans'] = $_POST['fooevents_seats__trans'];
                }
                
                // Ensure each seat selection gets a unique cart item
                $cart_item_data['unique_key'] = md5(microtime() . rand());
            }
        }
        // Fallback to FooEvents format for compatibility
        elseif (isset($_POST['fooevents_seats__trans']) && !empty($_POST['fooevents_seats__trans'])) {
            $selected_seats = explode(',', sanitize_text_field($_POST['fooevents_seats__trans']));
            
            if (!empty($selected_seats)) {
                $cart_item_data['hope_selected_seats'] = $selected_seats;
                $cart_item_data['fooevents_seats__trans'] = $_POST['fooevents_seats__trans'];
                $cart_item_data['hope_seat_count'] = count($selected_seats);
                $cart_item_data['unique_key'] = md5(microtime() . rand());
            }
        }
        
        return $cart_item_data;
    }
    
    /**
     * Add FooEvents compatible seat data to cart
     */
    public function add_fooevents_seat_data_to_cart($cart_item_data, $product_id, $variation_id, $quantity) {
        // Only process if we have HOPE theater seats
        if (!isset($cart_item_data['hope_theater_seats']) || empty($cart_item_data['hope_theater_seats'])) {
            return $cart_item_data;
        }
        
        error_log("HOPE: Adding FooEvents compatible seat data");
        
        // Create FooEvents compatible seat string (comma-separated seat IDs)
        $seat_ids = array();
        foreach ($cart_item_data['hope_theater_seats'] as $seat_id) {
            $seat_ids[] = $seat_id;
        }
        $fooevents_seats_string = implode(',', $seat_ids);
        
        // Add the FooEvents format that their ticket system expects
        $cart_item_data['fooevents_seats'] = $fooevents_seats_string;
        
        error_log("HOPE: Added fooevents_seats: " . $fooevents_seats_string);
        
        return $cart_item_data;
    }
    
    /**
     * Display seat info in cart
     */
    public function display_seat_info_in_cart($name, $cart_item, $cart_item_key) {
        error_log('HOPE Cart Display: Cart item data: ' . print_r($cart_item, true));
        
        // Check for seats in either key (backwards compatibility)
        $seats = null;
        if (isset($cart_item['hope_theater_seats']) && !empty($cart_item['hope_theater_seats'])) {
            $seats = $cart_item['hope_theater_seats'];
            error_log('HOPE Cart Display: Found hope_theater_seats: ' . print_r($seats, true));
        } elseif (isset($cart_item['hope_selected_seats']) && !empty($cart_item['hope_selected_seats'])) {
            $seats = $cart_item['hope_selected_seats'];
            error_log('HOPE Cart Display: Found hope_selected_seats: ' . print_r($seats, true));
        }
        
        if ($seats) {
            $seat_list = is_array($seats) ? implode(', ', $seats) : $seats;
            $seat_count = is_array($seats) ? count($seats) : 1;
            
            error_log("HOPE Cart Display: Displaying {$seat_count} seats: {$seat_list}");
            
            $name .= '<br><small><strong>' . __('Seats:', 'hope-seating') . '</strong> ' . esc_html($seat_list) . '</small>';
            $name .= '<br><small><strong>' . __('Quantity:', 'hope-seating') . '</strong> ' . $seat_count . ' ' . __('seats', 'hope-seating') . '</small>';
        } else {
            error_log('HOPE Cart Display: No seat data found in cart item');
        }
        
        return $name;
    }
    
    /**
     * Display seat info in mini cart (slide cart)
     */
    public function display_seat_info_in_mini_cart($quantity_html, $cart_item, $cart_item_key) {
        error_log('HOPE Mini Cart Display: Cart item data: ' . print_r($cart_item, true));
        
        // Check for seats in either key (backwards compatibility)
        $seats = null;
        if (isset($cart_item['hope_theater_seats']) && !empty($cart_item['hope_theater_seats'])) {
            $seats = $cart_item['hope_theater_seats'];
            error_log('HOPE Mini Cart Display: Found hope_theater_seats: ' . print_r($seats, true));
        } elseif (isset($cart_item['hope_selected_seats']) && !empty($cart_item['hope_selected_seats'])) {
            $seats = $cart_item['hope_selected_seats'];
            error_log('HOPE Mini Cart Display: Found hope_selected_seats: ' . print_r($seats, true));
        }
        
        if ($seats) {
            $seat_list = is_array($seats) ? implode(', ', $seats) : $seats;
            $seat_count = is_array($seats) ? count($seats) : 1;
            
            error_log("HOPE Mini Cart Display: Displaying {$seat_count} seats: {$seat_list}");
            
            $quantity_html .= '<br><small><strong>' . __('Seats:', 'hope-seating') . '</strong> ' . esc_html($seat_list) . '</small>';
        } else {
            error_log('HOPE Mini Cart Display: No seat data found in cart item');
        }
        
        return $quantity_html;
    }
    
    /**
     * Display seat info as item data (for compatibility with more themes)
     */
    public function display_seat_info_as_item_data($item_data, $cart_item) {
        error_log('HOPE Item Data Display: Cart item data: ' . print_r($cart_item, true));
        
        // Check for seats in either key (backwards compatibility)
        $seats = null;
        if (isset($cart_item['hope_theater_seats']) && !empty($cart_item['hope_theater_seats'])) {
            $seats = $cart_item['hope_theater_seats'];
            error_log('HOPE Item Data Display: Found hope_theater_seats: ' . print_r($seats, true));
        } elseif (isset($cart_item['hope_selected_seats']) && !empty($cart_item['hope_selected_seats'])) {
            $seats = $cart_item['hope_selected_seats'];
            error_log('HOPE Item Data Display: Found hope_selected_seats: ' . print_r($seats, true));
        }
        
        if ($seats) {
            $seat_list = is_array($seats) ? implode(', ', $seats) : $seats;
            $seat_count = is_array($seats) ? count($seats) : 1;
            
            error_log("HOPE Item Data Display: Displaying {$seat_count} seats: {$seat_list}");
            
            $item_data[] = array(
                'key'   => __('Theater Seats', 'hope-seating'),
                'value' => $seat_list
            );
            
            $item_data[] = array(
                'key'   => __('Seat Count', 'hope-seating'),
                'value' => $seat_count . ' ' . __('seats', 'hope-seating')
            );
        } else {
            error_log('HOPE Item Data Display: No seat data found in cart item');
        }
        
        return $item_data;
    }
    
    /**
     * Display seat info in order
     */
    public function display_seat_info_in_order($item_id, $item, $order, $plain_text) {
        // Check for seat summary first (preferred)
        $seat_summary = $item->get_meta('hope_seat_summary');
        if (!empty($seat_summary)) {
            if ($plain_text) {
                echo "\n" . __('Seats:', 'hope-seating') . ' ' . $seat_summary;
            } else {
                echo '<br><small><strong>' . __('Seats:', 'hope-seating') . '</strong> ' . esc_html($seat_summary) . '</small>';
            }
            
            // Add additional seats info if multiple
            $additional_seats = $item->get_meta('hope_additional_seats');
            if (!empty($additional_seats)) {
                if ($plain_text) {
                    echo ' (+' . $additional_seats . ')';
                } else {
                    echo '<br><small><em>+' . esc_html($additional_seats) . '</em></small>';
                }
            }
            
            // Add pricing tier info
            $tier = $item->get_meta('hope_pricing_tier');
            if (!empty($tier)) {
                $tier_names = [
                    'P1' => __('Premium', 'hope-seating'),
                    'P2' => __('Standard', 'hope-seating'), 
                    'P3' => __('Value', 'hope-seating'),
                    'AA' => __('Accessible', 'hope-seating')
                ];
                $tier_name = isset($tier_names[$tier]) ? $tier_names[$tier] : $tier;
                
                if ($plain_text) {
                    echo ' (' . $tier_name . ')';
                } else {
                    echo '<br><small><strong>' . __('Seating Tier:', 'hope-seating') . '</strong> ' . esc_html($tier_name) . '</small>';
                }
            }
            
            return;
        }
        
        // Fallback: Check for seats in either meta key (backwards compatibility)
        $selected_seats = $item->get_meta('hope_theater_seats');
        if (empty($selected_seats)) {
            $selected_seats = $item->get_meta('hope_selected_seats');
        }
        
        if (!empty($selected_seats)) {
            $seats = is_array($selected_seats) ? $selected_seats : json_decode($selected_seats, true);
            if (!empty($seats)) {
                $seat_list = implode(', ', $seats);
                if ($plain_text) {
                    echo "\n" . __('Seats:', 'hope-seating') . ' ' . $seat_list;
                } else {
                    echo '<br><small><strong>' . __('Seats:', 'hope-seating') . '</strong> ' . esc_html($seat_list) . '</small>';
                }
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
        
        // Group seats by pricing tier using new architecture
        $seat_tiers = array();
        $pricing_map_id = get_post_meta($product_id, '_hope_seating_venue_id', true); // Actually pricing map ID
        
        if ($pricing_map_id && class_exists('HOPE_Pricing_Maps_Manager')) {
            $pricing_manager = new HOPE_Pricing_Maps_Manager();
            $seats_with_pricing = $pricing_manager->get_seats_with_pricing($pricing_map_id);
            
            // Create lookup array for seat IDs to pricing tiers
            $seat_pricing_lookup = array();
            foreach ($seats_with_pricing as $seat) {
                $seat_pricing_lookup[$seat->seat_id] = $seat->pricing_tier;
            }
            
            // Group selected seats by pricing tier
            foreach ($selected_seats as $seat_id) {
                if (isset($seat_pricing_lookup[$seat_id])) {
                    $pricing_tier = $seat_pricing_lookup[$seat_id];
                    if (!isset($seat_tiers[$pricing_tier])) {
                        $seat_tiers[$pricing_tier] = 0;
                    }
                    $seat_tiers[$pricing_tier]++;
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
     * Save seat data to order item during checkout
     */
    public function save_seat_data_to_order_item($item, $cart_item_key, $values, $order) {
        error_log("HOPE: save_seat_data_to_order_item called for cart item: " . print_r($values, true));
        
        // Check for seat data in cart item and save to order item metadata (hidden from customer display)
        if (isset($values['hope_theater_seats']) && !empty($values['hope_theater_seats'])) {
            $item->add_meta_data('_hope_theater_seats', $values['hope_theater_seats'], false);
            error_log("HOPE: Saved _hope_theater_seats to order item: " . print_r($values['hope_theater_seats'], true));
        }
        
        if (isset($values['hope_selected_seats']) && !empty($values['hope_selected_seats'])) {
            $item->add_meta_data('_hope_selected_seats', $values['hope_selected_seats'], false);
            error_log("HOPE: Saved _hope_selected_seats to order item: " . print_r($values['hope_selected_seats'], true));
        }
        
        if (isset($values['hope_tier']) && !empty($values['hope_tier'])) {
            $item->add_meta_data('_hope_tier', $values['hope_tier'], false);
            error_log("HOPE: Saved _hope_tier to order item: " . $values['hope_tier']);
        }
        
        if (isset($values['hope_seat_details']) && !empty($values['hope_seat_details'])) {
            $item->add_meta_data('_hope_seat_details', $values['hope_seat_details'], false);
            error_log("HOPE: Saved _hope_seat_details to order item: " . print_r($values['hope_seat_details'], true));
        }
        
        if (isset($values['hope_session_id']) && !empty($values['hope_session_id'])) {
            $item->add_meta_data('_hope_session_id', $values['hope_session_id'], false);
        }
        
        // CRITICAL: Save FooEvents compatible seat data for ticket templates (hidden from display)
        if (isset($values['fooevents_seats']) && !empty($values['fooevents_seats'])) {
            $item->add_meta_data('_fooevents_seats', $values['fooevents_seats'], false);
            error_log("HOPE: Saved _fooevents_seats to order item: " . $values['fooevents_seats']);
        }
        
        // Create FooEvents seating options array format for ticket templates (hidden from display)
        if (isset($values['hope_seat_details']) && !empty($values['hope_seat_details'])) {
            $seating_options = array();
            foreach ($values['hope_seat_details'] as $seat_detail) {
                if (isset($seat_detail['section'], $seat_detail['row'], $seat_detail['seat'])) {
                    $seat_key = $seat_detail['section'] . '_' . $seat_detail['row'] . '_' . $seat_detail['seat'];
                    $seating_options[$seat_key] = array(
                        'section' => $seat_detail['section'],
                        'row' => $seat_detail['row'], 
                        'seat' => $seat_detail['seat'],
                        'tier' => $seat_detail['tier'] ?? '',
                        'price' => $seat_detail['price'] ?? ''
                    );
                }
            }
            
            if (!empty($seating_options)) {
                $item->add_meta_data('_fooevents_seating_options_array', $seating_options, false);
                error_log("HOPE: Saved _fooevents_seating_options_array to order item: " . print_r($seating_options, true));
            }
        }
        
        // Hide internal HOPE metadata from customer display but keep for internal use
        if (isset($values['hope_session_id']) && !empty($values['hope_session_id'])) {
            $item->update_meta_data('_hope_session_id', $values['hope_session_id']);
            $item->delete_meta_data('hope_session_id');
        }
        
        // Hide duplicate seating tier fields that are showing on thank you page
        $existing_seating_tier = $item->get_meta('Seating Tier');
        if (!empty($existing_seating_tier)) {
            // Move to hidden field and remove visible one
            $item->add_meta_data('_seating_tier_display', $existing_seating_tier, false);
            $item->delete_meta_data('Seating Tier');
        }
        
        // Also hide any variation attributes that might show seating tier info
        $variation_attributes = $item->get_meta('');
        foreach ($item->get_meta_data() as $meta) {
            $key = $meta->get_data()['key'];
            if (stripos($key, 'seating') !== false && substr($key, 0, 1) !== '_') {
                $value = $meta->get_data()['value'];
                $item->add_meta_data('_hidden_' . $key, $value, false);
                $item->delete_meta_data($key);
            }
        }
        
        // Also hide hope_pricing_tier if it's being added elsewhere
        $hope_pricing_tier = $item->get_meta('hope_pricing_tier');
        if (!empty($hope_pricing_tier)) {
            $item->add_meta_data('_hope_pricing_tier_backup', $hope_pricing_tier, false);
            $item->delete_meta_data('hope_pricing_tier');
        }
        
        // Hide hope_seat_summary from customer display
        $hope_seat_summary = $item->get_meta('hope_seat_summary');
        if (!empty($hope_seat_summary)) {
            $item->add_meta_data('_hope_seat_summary_backup', $hope_seat_summary, false);
            $item->delete_meta_data('hope_seat_summary');
        }
    }
    
    /**
     * Create FooEvents compatible ticket data with enhanced HOPE Theater seating information
     */
    public function create_fooevents_ticket_data($order_id, $posted_data, $order) {
        try {
            if (!class_exists('FooEvents')) return;
            
            error_log("HOPE: create_fooevents_ticket_data called for order {$order_id}");
        
        foreach ($order->get_items() as $item_id => $item) {
            // Check for seat data (prioritize hidden fields)
            $selected_seats = $item->get_meta('_hope_theater_seats');
            if (empty($selected_seats)) {
                $selected_seats = $item->get_meta('_hope_selected_seats');
            }
            if (empty($selected_seats)) {
                $selected_seats = $item->get_meta('hope_theater_seats');
            }
            if (empty($selected_seats)) {
                $selected_seats = $item->get_meta('hope_selected_seats');
            }
            
            error_log("HOPE: Order item {$item_id} - found seats: " . print_r($selected_seats, true));
            error_log("HOPE: Order item {$item_id} - all meta: " . print_r($item->get_meta_data(), true));
            
            if (!empty($selected_seats)) {
                $seats = is_array($selected_seats) ? $selected_seats : json_decode($selected_seats, true);
                
                // Get tier information from item metadata
                $tier = $item->get_meta('hope_tier');
                if (empty($tier)) {
                    // Try to extract tier from first seat if not available in metadata
                    $tier = $this->extract_tier_from_seat_id($seats[0]);
                }
                
                // Create enhanced seating display
                $formatted_seats = array();
                foreach ($seats as $seat_id) {
                    $seat_parts = $this->parse_seat_id($seat_id);
                    $section_name = $this->get_section_display_name($seat_parts['section']);
                    $formatted_seats[] = $section_name . ' ' . $seat_parts['section'] . $seat_parts['row'] . '-' . $seat_parts['seat'];
                }
                
                if (count($seats) > 1) {
                    // Multiple seats - create comprehensive summary
                    $seat_list = implode(', ', $formatted_seats);
                    $item->add_meta_data('_hope_seat_summary', $seat_list, false);
                    
                    // ALSO store as visible metadata for FooEvents ticket template access
                    $item->add_meta_data('hope_seat_summary', $seat_list);
                    
                    // Also add FooEvents-style data for the first seat (for compatibility)
                    $first_seat = $seats[0];
                    $seat_parts = $this->parse_seat_id($first_seat);
                    $section_name = $this->get_section_display_name($seat_parts['section']);
                    $item->add_meta_data('_fooevents_seat_row_name_0', $section_name . ' Row ' . $seat_parts['row'], false);
                    $item->add_meta_data('_fooevents_seat_number_0', $seat_parts['seat'], false);
                    
                    // Add count indicator (hidden from customer)
                    $item->add_meta_data('_hope_seat_count', count($seats), false);
                    $item->add_meta_data('_hope_additional_seats', (count($seats) - 1) . ' additional seats', false);
                } else {
                    // Single seat - full compatibility with enhanced formatting
                    $seat_id = $seats[0];
                    $seat_parts = $this->parse_seat_id($seat_id);
                    $section_name = $this->get_section_display_name($seat_parts['section']);
                    
                    // Store in FooEvents format for compatibility
                    $item->add_meta_data('_fooevents_seat_row_name_0', $section_name . ' Row ' . $seat_parts['row'], false);
                    $item->add_meta_data('_fooevents_seat_number_0', $seat_parts['seat'], false);
                    
                    // Enhanced summary for HOPE Theater tickets (hidden metadata)
                    $item->add_meta_data('_hope_seat_summary', $section_name . ' ' . $seat_parts['section'] . $seat_parts['row'] . '-' . $seat_parts['seat'], false);
                    
                    // ALSO store as visible metadata for FooEvents ticket template access
                    $item->add_meta_data('hope_seat_summary', $section_name . ' ' . $seat_parts['section'] . $seat_parts['row'] . '-' . $seat_parts['seat']);
                }
                
                // Always store the tier information for ticket display (hidden from customer)
                if ($tier) {
                    $item->add_meta_data('_hope_pricing_tier', $tier, false);
                    
                    // ALSO store as visible metadata for FooEvents ticket template access
                    $item->add_meta_data('hope_pricing_tier', $tier);
                }
                
                $item->save();
                
                error_log("HOPE: Enhanced ticket data created for order {$order_id}, item {$item_id} with " . count($seats) . " seats, tier: {$tier}");
            } else {
                error_log("HOPE: Order item {$item_id} - no seats found, checking tier only");
                
                // Even without seat data, if we have tier info, we should still store it
                $tier = $item->get_meta('hope_tier');
                if (!empty($tier)) {
                    $item->add_meta_data('_hope_pricing_tier', $tier, false);
                    $item->save();
                    error_log("HOPE: Saved tier {$tier} for item without seat data");
                }
            }
        }
        } catch (Exception $e) {
            error_log("HOPE: Error in create_fooevents_ticket_data: " . $e->getMessage());
            // Don't re-throw the exception to avoid breaking checkout
        }
    }
    
    
    /**
     * Set custom pricing for seat products
     * 
     * Note: This method is kept for backward compatibility but may not be needed
     * since we now add items with correct variation IDs that should have proper pricing
     */
    public function set_seat_pricing($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            // Check if this cart item has seat data and no variation ID (legacy items)
            if (isset($cart_item['hope_price_per_seat']) && isset($cart_item['hope_seat_count'])) {
                
                // If this item has a variation ID, let WooCommerce handle the pricing
                if ($cart_item['variation_id'] > 0) {
                    error_log("HOPE Pricing: Item has variation ID {$cart_item['variation_id']}, letting WooCommerce handle pricing");
                    continue; // Let WooCommerce use the variation price
                }
                
                // Only override pricing for items without proper variation IDs (legacy)
                $price_per_seat = floatval($cart_item['hope_price_per_seat']);
                error_log("HOPE Pricing: Setting price per seat to {$price_per_seat} for legacy item without variation");
                $cart_item['data']->set_price($price_per_seat);
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
    
    /**
     * Get display name for theater section
     */
    private function get_section_display_name($section) {
        $section_names = array(
            'A' => 'Section A',
            'B' => 'Section B',
            'C' => 'Section C',
            'D' => 'Section D',
            'E' => 'Section E',
            'F' => 'Section F',
            'G' => 'Section G',
            'H' => 'Section H'
        );
        
        return isset($section_names[$section]) ? $section_names[$section] : 'Section';
    }
    
    /**
     * Extract pricing tier from seat ID (using same logic as AJAX handler)
     */
    private function extract_tier_from_seat_id($seat_id) {
        // Parse seat ID to determine pricing tier
        // Format examples: C8-1, C9-1, C10-1, etc.
        if (preg_match('/^([A-Z])(\d+)-(\d+)$/', $seat_id, $matches)) {
            $section = $matches[1];
            $row = intval($matches[2]);
            
            // Theater pricing logic based on HOPE Theater seat map configuration
            // Must match the tier assignments in seat-map.js and AJAX handler exactly
            
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
     * Redirect theater seating products to checkout instead of cart
     */
    public function redirect_to_checkout_for_seating($url) {
        // Check if any items in cart have theater seating data
        if (!WC()->cart) {
            return $url;
        }
        
        $has_seating_products = false;
        
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            // Check if this item has seat data
            if (isset($cart_item['hope_theater_seats']) || isset($cart_item['hope_selected_seats'])) {
                $has_seating_products = true;
                break;
            }
        }
        
        if ($has_seating_products) {
            // Redirect to checkout instead of cart
            return wc_get_checkout_url();
        }
        
        return $url;
    }
    
    /**
     * Hide visible seat metadata from order details display
     * This keeps the data accessible to FooEvents while hiding it from customer view
     */
    public function hide_seat_metadata_from_display($display_key, $meta, $item) {
        // Hide these specific keys from order details display
        $hidden_keys = array(
            'hope_seat_summary',
            'hope_pricing_tier'
        );
        
        if (in_array($display_key, $hidden_keys)) {
            return null; // Don't display this key
        }
        
        return $display_key;
    }
    
    /**
     * Filter order item meta data to completely remove visible seat metadata entries
     * This removes the entire metadata entry, not just the label
     */
    public function filter_order_item_meta_data($formatted_meta, $item) {
        $filtered_meta = array();
        
        foreach ($formatted_meta as $meta_id => $meta) {
            // Skip metadata entries we want to hide completely
            $hidden_keys = array(
                'hope_seat_summary',
                'hope_pricing_tier'
            );
            
            if (!in_array($meta->key, $hidden_keys)) {
                $filtered_meta[$meta_id] = $meta;
            }
        }
        
        return $filtered_meta;
    }
    
    /**
     * Add seat data to FooEvents ticket array
     * This ensures seat data is available in ticket templates
     */
    public function add_seat_data_to_ticket($ticket_data, $ticket_id) {
        try {
            // Get order and product info from ticket
            $order_id = get_post_meta($ticket_id, 'WooCommerceEventsOrderID', true);
            $product_id = get_post_meta($ticket_id, 'WooCommerceEventsProductID', true);
            $attendee_id = get_post_meta($ticket_id, 'WooCommerceEventsAttendeeID', true);
            
            if (!$order_id || !$product_id) {
                return $ticket_data;
            }
            
            $order = wc_get_order($order_id);
            if (!$order) {
                return $ticket_data;
            }
            
            error_log("HOPE: Processing ticket {$ticket_id} for order {$order_id}, product {$product_id}, attendee {$attendee_id}");
            
            // Find the matching order item and extract seat data
            foreach ($order->get_items() as $item_id => $item) {
                if ($item->get_product_id() == $product_id) {
                    
                    // Get the seats array for this order item
                    $selected_seats = $item->get_meta('_hope_theater_seats');
                    if (empty($selected_seats)) {
                        $selected_seats = $item->get_meta('_hope_selected_seats');
                    }
                    
                    if (!empty($selected_seats) && is_array($selected_seats)) {
                        
                        // CRITICAL FIX: Determine which ticket this is (1st, 2nd, 3rd, etc.)
                        // by finding all tickets for this order item
                        $all_tickets_for_item = get_posts(array(
                            'post_type' => 'event_magic_tickets',
                            'posts_per_page' => -1,
                            'meta_query' => array(
                                array('key' => 'WooCommerceEventsOrderID', 'value' => $order_id),
                                array('key' => 'WooCommerceEventsProductID', 'value' => $product_id)
                            ),
                            'orderby' => 'ID',
                            'order' => 'ASC'
                        ));
                        
                        error_log("HOPE: Found " . count($all_tickets_for_item) . " tickets for order {$order_id}, product {$product_id}");
                        error_log("HOPE: Selected seats array: " . print_r($selected_seats, true));
                        
                        // Find the index of current ticket
                        $ticket_index = 0;
                        foreach ($all_tickets_for_item as $index => $ticket_post) {
                            if ($ticket_post->ID == $ticket_id) {
                                $ticket_index = $index;
                                break;
                            }
                        }
                        
                        // FALLBACK: If ticket lookup failed, try using attendee ID as index
                        if (count($all_tickets_for_item) === 0 && !empty($attendee_id)) {
                            $ticket_index = intval($attendee_id) - 1; // Attendee IDs are usually 1-based
                            error_log("HOPE: Using attendee ID fallback - attendee {$attendee_id} becomes index {$ticket_index}");
                        }
                        
                        error_log("HOPE: Ticket {$ticket_id} is index {$ticket_index} out of " . count($all_tickets_for_item) . " tickets");
                        
                        // Get the specific seat for this ticket index
                        $specific_seat = isset($selected_seats[$ticket_index]) ? $selected_seats[$ticket_index] : $selected_seats[0];
                        error_log("HOPE: Assigning seat {$specific_seat} to ticket {$ticket_id} (index {$ticket_index})");
                        
                        // Parse the seat ID to get readable format
                        $seat_parts = $this->parse_seat_id($specific_seat);
                        $section_name = $this->get_section_display_name($seat_parts['section']);
                        $seat_display = $section_name . ' Row ' . $seat_parts['row'] . ', Seat ' . $seat_parts['seat'];
                        
                        // Add individual seat data to ticket
                        $ticket_data['hope_seat_summary'] = $seat_display;
                        $ticket_data['hope_seat_id'] = $specific_seat;
                        $ticket_data['fooevents_seats'] = $specific_seat;
                        
                        // Also add FooEvents compatible individual seat fields
                        $ticket_data['fooevents_seat_row_name_0'] = $section_name . ' Row ' . $seat_parts['row'];
                        $ticket_data['fooevents_seat_number_0'] = $seat_parts['seat'];
                        
                        error_log("HOPE: Added specific seat data to ticket {$ticket_id}: {$seat_display}");
                    }
                    
                    // Also add pricing tier if available
                    $pricing_tier = $item->get_meta('hope_pricing_tier');
                    if (!empty($pricing_tier)) {
                        $ticket_data['hope_pricing_tier'] = $pricing_tier;
                        error_log("HOPE: Added pricing_tier to ticket {$ticket_id}: {$pricing_tier}");
                    }
                    
                    break;
                }
            }
            
            return $ticket_data;
            
        } catch (Exception $e) {
            error_log("HOPE: Error adding seat data to ticket: " . $e->getMessage());
            return $ticket_data;
        }
    }
    
    /**
     * Emulate FooEvents Seating plugin to inject our seating data into tickets
     */
    public function emulate_fooevents_seating_plugin() {
        // Only do this if FooEvents is active but FooEvents Seating is not
        if (!class_exists('FooEvents') || class_exists('Fooevents_Seating')) {
            return;
        }
        
        error_log("HOPE: Setting up FooEvents Seating emulation");
    }
    
    
    /**
     * Mark seats as sold/unavailable when order is processed or completed
     */
    public function mark_seats_as_sold($order_id) {
        try {
            error_log("HOPE: Marking seats as sold for order: " . $order_id);
            
            $order = wc_get_order($order_id);
            if (!$order) {
                error_log("HOPE: Could not get order: " . $order_id);
                return;
            }
            
            global $wpdb;
            $bookings_table = $wpdb->prefix . 'hope_seating_bookings';
            
            // Ensure tables exist before trying to use them
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$bookings_table'") == $bookings_table;
            if (!$table_exists) {
                error_log("HOPE: Bookings table doesn't exist, creating database tables");
                if (class_exists('HOPE_Seating_Database')) {
                    HOPE_Seating_Database::create_tables();
                    error_log("HOPE: Database tables created");
                } else {
                    error_log("HOPE: Database class not found, cannot create tables");
                    return;
                }
            }
            
            foreach ($order->get_items() as $item_id => $item) {
                // Get seat data from order item (check both hidden and visible fields)
                $selected_seats = $item->get_meta('_hope_theater_seats');
                if (empty($selected_seats)) {
                    $selected_seats = $item->get_meta('_hope_selected_seats');
                }
                if (empty($selected_seats)) {
                    $selected_seats = $item->get_meta('hope_theater_seats');
                }
                if (empty($selected_seats)) {
                    $selected_seats = $item->get_meta('hope_selected_seats');
                }
                
                if (!empty($selected_seats) && is_array($selected_seats)) {
                    $product_id = $item->get_product_id();
                    $customer_id = $order->get_customer_id();
                    $customer_email = $order->get_billing_email();
                    
                    foreach ($selected_seats as $seat_id) {
                        // Check if booking already exists
                        $existing = $wpdb->get_var($wpdb->prepare(
                            "SELECT id FROM $bookings_table WHERE seat_id = %s AND order_id = %d",
                            $seat_id, $order_id
                        ));
                        
                        if (!$existing) {
                            // Insert new booking record
                            $result = $wpdb->insert(
                                $bookings_table,
                                array(
                                    'seat_id' => $seat_id,
                                    'product_id' => $product_id,
                                    'order_id' => $order_id,
                                    'order_item_id' => $item_id,
                                    'customer_id' => $customer_id,
                                    'customer_email' => $customer_email,
                                    'status' => 'confirmed',
                                    'created_at' => current_time('mysql'),
                                    'updated_at' => current_time('mysql')
                                ),
                                array('%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s')
                            );
                            
                            if ($result !== false) {
                                error_log("HOPE: Created booking record for seat {$seat_id} in order {$order_id}");
                            } else {
                                error_log("HOPE: Failed to create booking for seat {$seat_id}: " . $wpdb->last_error);
                            }
                        } else {
                            error_log("HOPE: Booking already exists for seat {$seat_id} in order {$order_id}");
                        }
                    }
                    
                    // Also release any holds for this session since the seats are now sold
                    if (class_exists('HOPE_Session_Manager')) {
                        $session_id = $item->get_meta('_hope_session_id');
                        if (!empty($session_id)) {
                            $session_manager = new HOPE_Session_Manager();
                            foreach ($selected_seats as $seat_id) {
                                $session_manager->release_seat_hold($item->get_product_id(), $seat_id, $session_id);
                            }
                        }
                    }
                }
            }
            
            error_log("HOPE: Completed marking seats as sold for order: " . $order_id);
            
        } catch (Exception $e) {
            error_log("HOPE: Error marking seats as sold: " . $e->getMessage());
        }
    }

    /**
     * CRITICAL: Validate seat holds exist before checkout
     * Prevents orders from being placed with expired or missing holds
     *
     * @param array $data Posted checkout data
     * @param WP_Error $errors Validation errors object
     */
    public function validate_seat_holds_at_checkout($data, $errors) {
        error_log('HOPE: Validating seat holds at checkout');

        if (!class_exists('HOPE_Session_Manager')) {
            error_log('HOPE: Session manager not available for validation');
            return;
        }

        global $wpdb;
        $holds_table = $wpdb->prefix . 'hope_seating_holds';
        $bookings_table = $wpdb->prefix . 'hope_seating_bookings';

        // Get current session ID
        $session_id = HOPE_Session_Manager::get_current_session_id();
        if (empty($session_id)) {
            error_log('HOPE: No session ID available for hold validation');
            return;
        }

        error_log("HOPE: Validating holds for session: {$session_id}");

        // Check each cart item for seat data
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            // Get seat data from cart item
            $selected_seats = null;
            if (isset($cart_item['_hope_theater_seats'])) {
                $selected_seats = $cart_item['_hope_theater_seats'];
            } elseif (isset($cart_item['_hope_selected_seats'])) {
                $selected_seats = $cart_item['_hope_selected_seats'];
            } elseif (isset($cart_item['hope_theater_seats'])) {
                $selected_seats = $cart_item['hope_theater_seats'];
            } elseif (isset($cart_item['hope_selected_seats'])) {
                $selected_seats = $cart_item['hope_selected_seats'];
            }

            if (empty($selected_seats) || !is_array($selected_seats)) {
                continue; // Not a seating product, skip validation
            }

            $product_id = $cart_item['product_id'];
            error_log("HOPE: Validating " . count($selected_seats) . " seats for product {$product_id}");

            // Check each seat
            foreach ($selected_seats as $seat_id) {
                // First check if seat is already booked by someone else
                $already_booked = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$bookings_table}
                    WHERE seat_id = %s AND product_id = %d AND status IN ('confirmed', 'active')",
                    $seat_id,
                    $product_id
                ));

                if ($already_booked) {
                    $product = wc_get_product($product_id);
                    $product_name = $product ? $product->get_name() : "Product #{$product_id}";
                    $errors->add('seat_unavailable', sprintf(
                        __('Sorry, seat %s for "%s" is no longer available. Please select different seats.', 'hope-theater-seating'),
                        $seat_id,
                        $product_name
                    ));
                    error_log("HOPE CHECKOUT BLOCKED: Seat {$seat_id} already booked");
                    continue;
                }

                // Check if hold exists for this seat and session
                $hold_exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$holds_table}
                    WHERE seat_id = %s
                    AND product_id = %d
                    AND session_id = %s
                    AND expires_at > NOW()",
                    $seat_id,
                    $product_id,
                    $session_id
                ));

                if (!$hold_exists) {
                    $product = wc_get_product($product_id);
                    $product_name = $product ? $product->get_name() : "Product #{$product_id}";
                    $errors->add('seat_hold_expired', sprintf(
                        __('Your reservation for seat %s for "%s" has expired. Please select your seats again.', 'hope-theater-seating'),
                        $seat_id,
                        $product_name
                    ));
                    error_log("HOPE CHECKOUT BLOCKED: No valid hold for seat {$seat_id}, product {$product_id}, session {$session_id}");
                }
            }
        }

        if ($errors->has_errors()) {
            error_log('HOPE: Checkout validation failed - ' . count($errors->get_error_messages()) . ' errors');
        } else {
            error_log('HOPE: All seat holds validated successfully');
        }
    }

    /**
     * Debug method to track when FooEvents creates tickets
     */
    public function debug_ticket_creation($ticket_id, $product_id = null, $ticket_number = null) {
        error_log("HOPE: FooEvents creating ticket - Ticket ID: {$ticket_id}, Product: " . ($product_id ?? 'unknown') . ", Ticket: " . ($ticket_number ?? 'unknown'));
        
        // The first parameter is actually a ticket post ID, not order ID
        // Get the order ID from the ticket post meta
        $order_id = get_post_meta($ticket_id, 'WooCommerceEventsOrderID', true);
        $ticket_product_id = get_post_meta($ticket_id, 'WooCommerceEventsProductID', true);
        
        error_log("HOPE: Ticket {$ticket_id} - Order ID: {$order_id}, Product ID: {$ticket_product_id}");
        
        if ($order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                error_log("HOPE: Order {$order_id} found, checking " . count($order->get_items()) . " items");
                
                foreach ($order->get_items() as $item_id => $item) {
                    $item_product_id = $item->get_product_id();
                    $seating_enabled = get_post_meta($item_product_id, '_hope_seating_enabled', true);
                    
                    error_log("HOPE: Item {$item_id} (Product {$item_product_id}) - seating enabled: " . ($seating_enabled ?: 'NO'));
                    
                    if ($seating_enabled === 'yes') {
                        error_log("HOPE: Checking all meta data for item {$item_id}");
                        
                        // Check all meta data keys
                        $all_meta = $item->get_meta_data();
                        $meta_keys = [];
                        foreach ($all_meta as $meta) {
                            $meta_keys[] = $meta->get_data()['key'];
                        }
                        error_log("HOPE: Item {$item_id} meta keys: " . implode(', ', $meta_keys));
                        
                        // Check specific seat data fields
                        $seat_data = $item->get_meta('_hope_theater_seats');
                        $seat_summary = $item->get_meta('_hope_seat_summary');
                        $hope_seats = $item->get_meta('hope_theater_seats');
                        
                        error_log("HOPE: Item {$item_id} _hope_theater_seats: " . (empty($seat_data) ? 'EMPTY' : print_r($seat_data, true)));
                        error_log("HOPE: Item {$item_id} _hope_seat_summary: " . (empty($seat_summary) ? 'EMPTY' : $seat_summary));
                        error_log("HOPE: Item {$item_id} hope_theater_seats: " . (empty($hope_seats) ? 'EMPTY' : print_r($hope_seats, true)));
                        
                        // CRITICAL FIX: Only assign the first unassigned item to this ticket
                        if (!empty($seat_data) && $item_product_id == $ticket_product_id) {
                            // Check if this item has already been assigned to another ticket
                            $assigned_items = get_transient('hope_assigned_items_' . $order_id) ?: array();
                            
                            if (!in_array($item_id, $assigned_items)) {
                                error_log("HOPE: Assigning item {$item_id} to ticket {$ticket_id} (first available)");
                                
                                // Mark this item as assigned
                                $assigned_items[] = $item_id;
                                set_transient('hope_assigned_items_' . $order_id, $assigned_items, 3600); // 1 hour
                                
                                // Store seating data on this ticket
                                $this->store_seat_data_on_ticket($ticket_id, $item, $seat_data);
                                
                                // Stop processing other items for this ticket
                                break;
                            } else {
                                error_log("HOPE: Item {$item_id} already assigned, skipping for ticket {$ticket_id}");
                            }
                        }
                    } else {
                        error_log("HOPE: Item {$item_id} does not have seating enabled");
                    }
                }
            } else {
                error_log("HOPE: Could not find order {$order_id}");
            }
        } else {
            error_log("HOPE: Could not find order ID for ticket {$ticket_id}");
        }
    }
    
    /**
     * Store seat data directly on the ticket post
     */
    private function store_seat_data_on_ticket($ticket_id, $order_item, $seat_data) {
        if (empty($seat_data) || !is_array($seat_data)) {
            return;
        }
        
        // Parse the first seat to get section/row/seat info
        $seat_id = $seat_data[0];
        $seat_parts = $this->parse_seat_id($seat_id);
        $section_name = $this->get_section_display_name($seat_parts['section']);
        
        // Store in FooEvents expected format
        $row_name = $section_name . ' Row ' . $seat_parts['row'];
        $seat_number = $seat_parts['seat'];
        
        update_post_meta($ticket_id, 'fooevents_seat_row_name_0', $row_name);
        update_post_meta($ticket_id, 'fooevents_seat_number_0', $seat_number);
        
        // Store complete seating fields array
        $seating_fields = array(
            'fooevents_seat_row_name_0' => $row_name,
            'fooevents_seat_number_0' => $seat_number
        );
        update_post_meta($ticket_id, 'WooCommerceEventsSeatingFields', $seating_fields);
        
        error_log("HOPE: Stored seating data on ticket {$ticket_id}: Row={$row_name}, Seat={$seat_number}");
    }
    
    /**
     * Inject seat data into FooEvents ticket data
     */
    public function inject_seat_data_into_ticket($ticket_data, $order) {
        error_log("HOPE: inject_seat_data_into_ticket called");
        error_log("HOPE: Initial ticket_data: " . print_r($ticket_data, true));
        
        // Check if order has seating data
        if ($order && method_exists($order, 'get_items')) {
            foreach ($order->get_items() as $item_id => $item) {
                $seat_data = $item->get_meta('_hope_theater_seats');
                if (!empty($seat_data)) {
                    // Add seat data to ticket_data in the format our capture method expects
                    $seat_string = is_array($seat_data) ? implode(',', $seat_data) : $seat_data;
                    $ticket_data['seats'] = $seat_string;
                    
                    error_log("HOPE: Added seats to ticket_data: {$seat_string}");
                    break; // Only need first seating item
                }
            }
        }
        
        return $ticket_data;
    }
}

// Initialize the integration
new HOPE_WooCommerce_Integration();

// Create safe FooEvents Seating class for ticket integration (only if FooEvents exists and seating doesn't)
if (class_exists('FooEvents') && !class_exists('Fooevents_Seating')) {
    class Fooevents_Seating {
        public function display_tickets_meta_seat_options_output_legacy($ticket_id) {
            return ''; // Legacy method - not used
        }
        
        public function capture_seating_options() {
            // Method called during checkout - return empty array to avoid interfering
            error_log("HOPE: FooEvents capture_seating_options() called - returning empty array");
            return array();
        }
        
        // Add any other methods that FooEvents might call to prevent fatal errors
        public function __call($method, $args) {
            error_log("HOPE: FooEvents called unknown method '{$method}' on seating class - returning empty array");
            return array();
        }
        
        public static function __callStatic($method, $args) {
            error_log("HOPE: FooEvents called unknown static method '{$method}' on seating class - returning empty array");
            return array();
        }
        
        public function display_tickets_meta_seat_options_output($ticket_id) {
            try {
                error_log("HOPE: FooEvents requesting seating data for ticket: " . $ticket_id);
                
                // Get order and product info from ticket
                $order_id = get_post_meta($ticket_id, 'WooCommerceEventsOrderID', true);
                $product_id = get_post_meta($ticket_id, 'WooCommerceEventsProductID', true);
                
                if (!$order_id || !$product_id) {
                    return array();
                }
                
                $order = wc_get_order($order_id);
                if (!$order) {
                    return array();
                }
                
                // Find the matching order item and get seat summary
                foreach ($order->get_items() as $item_id => $item) {
                    if ($item->get_product_id() == $product_id) {
                        // Check for seat summary in both visible and hidden fields
                        $seat_summary = $item->get_meta('_hope_seat_summary');
                        if (empty($seat_summary)) {
                            $seat_summary = $item->get_meta('hope_seat_summary');
                        }
                        if (empty($seat_summary)) {
                            $seat_summary = $item->get_meta('_hope_seat_summary_backup');
                        }
                        
                        // If still no summary, try to reconstruct from seat data
                        if (empty($seat_summary)) {
                            $selected_seats = $item->get_meta('_hope_theater_seats');
                            if (empty($selected_seats)) {
                                $selected_seats = $item->get_meta('_hope_selected_seats');
                            }
                            if (!empty($selected_seats) && is_array($selected_seats)) {
                                $seat_parts = array();
                                foreach ($selected_seats as $seat_id) {
                                    $parts = explode('-', $seat_id);
                                    if (count($parts) >= 2) {
                                        $section_name = in_array($parts[0], array('A', 'B', 'C', 'D', 'E')) ? 'Orchestra' : 'Balcony';
                                        $seat_parts[] = $section_name . ' Section ' . $parts[0] . ', Row ' . $parts[1] . ', Seat ' . $seat_id;
                                    }
                                }
                                if (!empty($seat_parts)) {
                                    $seat_summary = implode('; ', $seat_parts);
                                }
                            }
                        }
                        
                        $tier = $item->get_meta('_hope_tier');
                        
                        if (!empty($seat_summary)) {
                            error_log("HOPE: Returning seating data for ticket: " . $seat_summary);
                            return array(
                                'seat_assignment' => array(
                                    'display' => $seat_summary,
                                    'tier' => $tier ?? '',
                                    'raw' => $seat_summary
                                )
                            );
                        }
                        break;
                    }
                }
                
                return array();
            } catch (Exception $e) {
                error_log("HOPE: Error in FooEvents seating integration: " . $e->getMessage());
                return array();
            }
        }
    }
}