<?php
/**
 * Plugin Name: HOPE Theater Seating
 * Plugin URI: https://hopecenterforthearts.org
 * GitHub Plugin URI: khomstead/hope-theater-seating
 * GitHub Branch: main
 * Primary Branch: main
 * Release Asset: true
 * Description: Custom seating chart system for HOPE Theater venues with WooCommerce/FooEvents integration
 * Version: 2.3.1
 * Author: HOPE Center Development Team
 * License: GPL v2 or later
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('HOPE_SEATING_VERSION', '2.3.1');
define('HOPE_SEATING_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HOPE_SEATING_PLUGIN_URL', plugin_dir_url(__FILE__));
define('HOPE_SEATING_PLUGIN_FILE', __FILE__);

// Activation hook - THIS IS WHERE THE NEW CODE GOES
register_activation_hook(__FILE__, 'hope_seating_activate');

function hope_seating_activate() {
    // Check dependencies
    if (!is_plugin_active('woocommerce/woocommerce.php')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('HOPE Theater Seating requires WooCommerce to be installed and activated.');
    }
    
    if (!is_plugin_active('fooevents/fooevents.php')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('HOPE Theater Seating requires FooEvents for WooCommerce to be installed and activated.');
    }
    
    // Include required files for activation
    require_once HOPE_SEATING_PLUGIN_DIR . 'includes/class-database.php';
    require_once HOPE_SEATING_PLUGIN_DIR . 'includes/class-physical-seats.php';
    require_once HOPE_SEATING_PLUGIN_DIR . 'includes/class-pricing-maps.php';
    
    // Create database tables (includes new architecture tables)
    $database = new HOPE_Seating_Database();
    $database->create_tables();
    
    // NEW ARCHITECTURE: Initialize separated system
    try {
        // Initialize physical seats (497 seats)
        $physical_manager = new HOPE_Physical_Seats_Manager();
        $physical_seats_created = $physical_manager->populate_physical_seats();
        
        // Initialize standard pricing map
        $pricing_manager = new HOPE_Pricing_Maps_Manager();
        $pricing_map_created = $pricing_manager->create_standard_pricing_map();
        
        error_log("HOPE Theater Seating v2.3.0 activated. New architecture initialized: {$physical_seats_created} physical seats, pricing map created: " . ($pricing_map_created ? 'yes' : 'no'));
        
    } catch (Exception $e) {
        error_log('HOPE Theater Seating activation error: ' . $e->getMessage());
    }
    
    // Schedule cleanup cron
    if (!wp_next_scheduled('hope_seating_cleanup')) {
        wp_schedule_event(time(), 'hourly', 'hope_seating_cleanup');
    }
    
    // NEW: Schedule holds cleanup cron (hourly - safe WordPress default)
    if (!wp_next_scheduled('hope_seating_cleanup_holds')) {
        wp_schedule_event(time(), 'hourly', 'hope_seating_cleanup_holds');
    }
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'hope_seating_deactivate');

function hope_seating_deactivate() {
    // Clear all scheduled crons
    wp_clear_scheduled_hook('hope_seating_cleanup');
    wp_clear_scheduled_hook('hope_seating_cleanup_holds');
}

// Main plugin class
class HOPE_Theater_Seating {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
        add_action('before_woocommerce_init', array($this, 'declare_wc_compatibility'));
    }
    
    public function init() {
        // Check dependencies
        if (!$this->check_dependencies()) {
            return;
        }
        
        // Include required files
        $this->includes();
        
        // Initialize components
        $this->init_hooks();
        
        // Initialize new architecture if needed
        $this->maybe_initialize_new_architecture();
    }
    
    private function check_dependencies() {
        return (
            class_exists('WooCommerce') && 
            class_exists('FooEvents')
        );
    }
    
    /**
     * Declare WooCommerce compatibility (called via action hook)
     */
    public function declare_wc_compatibility() {
        // Safety check to prevent multiple declarations
        static $declared = false;
        if ($declared) {
            return;
        }
        
        if (class_exists('Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                __FILE__,
                true
            );
            $declared = true;
        }
    }
    
    private function includes() {
        // Database and core classes
        require_once HOPE_SEATING_PLUGIN_DIR . 'includes/class-database.php';
        
        // Include only new architecture and required files
        $files_to_include = array(
            // OLD VENUE SYSTEM - DISABLED
            // 'includes/class-venues.php',        // DEPRECATED: Old venue management
            // 'includes/class-seat-maps.php',     // DEPRECATED: Old combined seat/pricing
            
            // NEW SEPARATED ARCHITECTURE
            'includes/class-physical-seats.php',   // NEW: Physical layout management
            'includes/class-pricing-maps.php',     // NEW: Pricing configurations
            
            // LEGACY - Keep for now during transition
            'includes/class-seat-manager.php',     // Still used for legacy compatibility
            
            // CORE FUNCTIONALITY
            'includes/class-session-manager.php',   
            'includes/class-mobile-detector.php',   
            'includes/class-admin.php',
            'includes/class-frontend.php',
            'includes/class-ajax.php',
            'includes/class-modal-handler.php',
            'includes/class-ajax-handler.php',
            'includes/class-integration.php',
            'includes/class-woocommerce-integration.php'
        );
        
        foreach ($files_to_include as $file) {
            $file_path = HOPE_SEATING_PLUGIN_DIR . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }
        
        // Diagnostic functionality is now built into the admin class
    }
    
    private function init_hooks() {
        // Check for database updates on admin init
        if (is_admin()) {
            add_action('admin_init', array($this, 'check_database_version'));
        }
        
        // Initialize admin if class exists
        if (is_admin() && class_exists('HOPE_Seating_Admin')) {
            new HOPE_Seating_Admin();
        }
        
        // Initialize frontend if class exists
        if (class_exists('HOPE_Seating_Frontend')) {
            new HOPE_Seating_Frontend();
        }
        
        // Initialize AJAX handlers if they exist
        if (class_exists('HOPE_Seating_Ajax')) {
            new HOPE_Seating_Ajax();
        }
        
        if (class_exists('HOPE_Ajax_Handler')) {
            new HOPE_Ajax_Handler();
        }
        
        // Initialize modal handler if it exists
        if (class_exists('HOPE_Modal_Handler')) {
            new HOPE_Modal_Handler();
        }
        
        // NEW: Initialize session manager (handles holds cron)
        if (class_exists('HOPE_Session_Manager')) {
            new HOPE_Session_Manager();
        }
        
        // NEW: Make mobile detector available globally
        if (class_exists('HOPE_Mobile_Detector')) {
            $GLOBALS['hope_mobile_detector'] = HOPE_Mobile_Detector::get_instance();
        }
        
        // Add cleanup cron action
        add_action('hope_seating_cleanup', array($this, 'cleanup_temp_seats'));
        
        // Add AJAX endpoint for variation pricing
        add_action('wp_ajax_hope_get_variation_pricing', array($this, 'get_variation_pricing'));
        add_action('wp_ajax_nopriv_hope_get_variation_pricing', array($this, 'get_variation_pricing'));
        
        // Add AJAX endpoint for getting cart seats
        add_action('wp_ajax_hope_get_cart_seats', array($this, 'get_cart_seats'));
        add_action('wp_ajax_nopriv_hope_get_cart_seats', array($this, 'get_cart_seats'));
        
        // Temporary debug endpoint
        add_action('wp_ajax_hope_debug_cart', array($this, 'debug_cart'));
        add_action('wp_ajax_nopriv_hope_debug_cart', array($this, 'debug_cart'));
        
        // Debug endpoint for variations
        add_action('wp_ajax_hope_debug_variations', array($this, 'debug_variations'));
        add_action('wp_ajax_nopriv_hope_debug_variations', array($this, 'debug_variations'));
        
        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // Add body classes for device detection
        if (class_exists('HOPE_Mobile_Detector')) {
            add_filter('body_class', array(HOPE_Mobile_Detector::get_instance(), 'add_body_classes'));
        }
        
        // Cart synchronization hooks
        add_action('woocommerce_remove_cart_item', array($this, 'handle_cart_item_removal'), 10, 2);
        add_action('woocommerce_cart_item_removed', array($this, 'handle_cart_item_removed'), 10, 2);
    }
    
    public function enqueue_frontend_assets() {
        // Check if we need seating scripts - only on product pages with seating enabled
        $should_enqueue_seating = false;
        $product_id = 0;
        $venue_id = 0;
        
        if (is_product()) {
            global $product;
            if ($product && is_object($product)) {
                $product_id = $product->get_id();
                $seating_enabled = get_post_meta($product_id, '_hope_seating_enabled', true);
                if ($seating_enabled === 'yes') {
                    $venue_id = get_post_meta($product_id, '_hope_seating_venue_id', true);
                    $should_enqueue_seating = true;
                }
            }
        }
        
        // Get mobile detector instance if available
        $viewport_config = array();
        if (class_exists('HOPE_Mobile_Detector')) {
            $mobile_detector = HOPE_Mobile_Detector::get_instance();
            $viewport_config = $mobile_detector->get_viewport_config();
        }
        
        // Only enqueue seating assets if we're on a product page with seating enabled
        if ($should_enqueue_seating) {
            // Enqueue styles - both frontend basics and seat map animations
            wp_enqueue_style(
                'hope-seating-frontend',
                HOPE_SEATING_PLUGIN_URL . 'assets/css/frontend.css',
                array(),
                HOPE_SEATING_VERSION
            );
            
            wp_enqueue_style(
                'hope-seating-seat-map',
                HOPE_SEATING_PLUGIN_URL . 'assets/css/seat-map.css',
                array('hope-seating-frontend'),
                HOPE_SEATING_VERSION
            );
            
            wp_enqueue_style(
                'hope-seating-modal',
                HOPE_SEATING_PLUGIN_URL . 'assets/css/modal.css',
                array(),
                HOPE_SEATING_VERSION
            );
            
            // Enqueue scripts
            wp_enqueue_script(
                'hope-seating-seat-map',
                HOPE_SEATING_PLUGIN_URL . 'assets/js/seat-map.js',
                array(),
                HOPE_SEATING_VERSION,
                true
            );
            
            wp_enqueue_script(
                'hope-seating-modal',
                HOPE_SEATING_PLUGIN_URL . 'assets/js/modal-handler.js',
                array('hope-seating-seat-map'),
                HOPE_SEATING_VERSION,
                true
            );
            
            // Add mobile handler if touch device
            if (isset($mobile_detector) && $mobile_detector->is_touch_device()) {
                wp_enqueue_script(
                    'hope-seating-mobile',
                    HOPE_SEATING_PLUGIN_URL . 'assets/js/mobile-handler.js',
                    array('hope-seating-seat-map'),
                    HOPE_SEATING_VERSION,
                    true
                );
            }
        }
        
        // Only localize scripts if we enqueued them
        if ($should_enqueue_seating) {
            // Get session ID
            $session_id = '';
            if (class_exists('HOPE_Session_Manager')) {
                $session_id = HOPE_Session_Manager::get_current_session_id();
            }
            
            // Localize script with configuration (using the variables we already calculated)
            wp_localize_script('hope-seating-seat-map', 'hope_ajax', array(
                'ajax_url' => '/wp-admin/admin-ajax.php', // Use relative path to avoid CORS issues
                'nonce' => wp_create_nonce('hope_seating_nonce'),
                'session_id' => $session_id,
                'product_id' => $product_id,
                'venue_id' => $venue_id,
                'hold_duration' => 600, // 10 minutes
                'device_type' => isset($mobile_detector) ? $mobile_detector->get_device_type() : 'desktop',
                'is_mobile' => isset($mobile_detector) ? $mobile_detector->is_mobile() : false,
                'viewport_config' => $viewport_config,
                'checkout_url' => wc_get_checkout_url(),
                'messages' => array(
                    'seat_unavailable' => __('This seat is no longer available', 'hope-seating'),
                    'max_seats' => __('Maximum number of seats selected', 'hope-seating'),
                    'connection_error' => __('Connection error. Please try again.', 'hope-seating'),
                    'session_expired' => __('Your session has expired. Please refresh the page.', 'hope-seating')
                )
            ));
        }
    }
    
    public function enqueue_admin_assets($hook) {
        // Only load on our admin pages
        if (strpos($hook, 'hope-seating') === false) {
            return;
        }
        
        wp_enqueue_style(
            'hope-seating-admin',
            HOPE_SEATING_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            HOPE_SEATING_VERSION
        );
        
        wp_enqueue_script(
            'hope-seating-admin',
            HOPE_SEATING_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            HOPE_SEATING_VERSION,
            true
        );
    }
    
    public function cleanup_temp_seats() {
        // Cleanup expired holds if session manager exists
        if (class_exists('HOPE_Session_Manager')) {
            $session_manager = new HOPE_Session_Manager();
            $cleaned = $session_manager->cleanup_expired_holds();
            
            if ($cleaned > 0) {
                error_log('HOPE Theater Seating: Cleaned up ' . $cleaned . ' expired holds.');
            }
        }
    }
    
    /**
     * Check if database needs to be updated
     */
    public function check_database_version() {
        $current_version = get_option('hope_seating_db_version', '0');
        $plugin_version = HOPE_SEATING_VERSION;
        
        // Check if tables exist first
        global $wpdb;
        $bookings_table = $wpdb->prefix . 'hope_seating_bookings';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$bookings_table'") == $bookings_table;
        
        if (!$table_exists || version_compare($current_version, '2.2.17', '<')) {
            if (class_exists('HOPE_Seating_Database')) {
                // Create tables if they don't exist
                if (!$table_exists) {
                    HOPE_Seating_Database::create_tables();
                    error_log('HOPE Seating: Database tables created during version check');
                } else {
                    HOPE_Seating_Database::update_database_schema();
                }
                
                update_option('hope_seating_db_version', $plugin_version);
                
                // Show admin notice
                add_action('admin_notices', array($this, 'database_updated_notice'));
            }
        }
    }
    
    /**
     * Show database update notice
     */
    public function database_updated_notice() {
        echo '<div class="notice notice-success is-dismissible">';
        echo '<p><strong>HOPE Theater Seating:</strong> Database schema updated successfully for version 2.2.17</p>';
        echo '</div>';
    }
    
    /**
     * AJAX handler to get actual WooCommerce variation pricing
     */
    public function get_variation_pricing() {
        // Check nonce
        if (!wp_verify_nonce($_POST['nonce'], 'hope_seating_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $product_id = intval($_POST['product_id']);
        
        if (!$product_id) {
            wp_send_json_error('Invalid product ID');
            return;
        }
        
        $product = wc_get_product($product_id);
        
        if (!$product || !$product->is_type('variable')) {
            wp_send_json_error('Product not found or not variable');
            return;
        }
        
        $pricing = array();
        $variations = $product->get_available_variations();
        
        foreach ($variations as $variation_data) {
            $variation = wc_get_product($variation_data['variation_id']);
            
            if (!$variation) continue;
            
            // Get the tier from variation attributes
            $attributes = $variation->get_attributes();
            $tier = null;
            
            // Look for tier attribute (might be pa_tier, attribute_tier, seating-tier, etc.)
            foreach ($attributes as $key => $value) {
                if (stripos($key, 'tier') !== false || stripos($key, 'seating') !== false) {
                    $tier = strtoupper($value); // Keep uppercase to match pricing map tiers (P1, P2, P3, AA)
                    error_log("HOPE: Found pricing tier attribute {$key} = {$value} (normalized: {$tier})");
                    break;
                }
            }
            
            if ($tier) {
                $pricing[$tier] = array(
                    'price' => $variation->get_price(),
                    'variation_id' => $variation->get_id(),
                    'name' => $variation->get_name()
                );
            }
        }
        
        wp_send_json_success(array('pricing' => $pricing));
    }
    
    /**
     * AJAX handler to get seats from current WooCommerce cart
     */
    public function get_cart_seats() {
        // Check nonce
        if (!wp_verify_nonce($_POST['nonce'], 'hope_seating_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $product_id = intval($_POST['product_id']);
        
        if (!$product_id) {
            wp_send_json_error('Invalid product ID');
            return;
        }
        
        // Get current cart
        if (!function_exists('WC') || !WC()->cart) {
            wp_send_json_success(array('seats' => array()));
            return;
        }
        
        $cart_seats = array();
        $cart_total = 0;
        
        // Loop through cart items to find seats for this product
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            if ($cart_item['product_id'] == $product_id) {
                // Add to cart total for this product
                $cart_total += $cart_item['line_total'];
                
                // Check for HOPE theater seats (this is the correct format)
                if (isset($cart_item['hope_theater_seats']) && is_array($cart_item['hope_theater_seats'])) {
                    foreach ($cart_item['hope_theater_seats'] as $seat_id) {
                        if ($seat_id && !in_array($seat_id, $cart_seats)) {
                            $cart_seats[] = $seat_id;
                        }
                    }
                }
                
                // Alternative: check seat details array
                if (isset($cart_item['hope_seat_details']) && is_array($cart_item['hope_seat_details'])) {
                    foreach ($cart_item['hope_seat_details'] as $seat_detail) {
                        if (isset($seat_detail['seat_id']) && !in_array($seat_detail['seat_id'], $cart_seats)) {
                            $cart_seats[] = $seat_detail['seat_id'];
                        }
                    }
                }
                
                // Fallback: check for other seat data formats
                if (isset($cart_item['fooevents_seats_trans'])) {
                    $seats = explode(',', $cart_item['fooevents_seats_trans']);
                    foreach ($seats as $seat) {
                        $seat = trim($seat);
                        if ($seat && !in_array($seat, $cart_seats)) {
                            $cart_seats[] = $seat;
                        }
                    }
                }
            }
        }
        
        wp_send_json_success(array(
            'seats' => array_unique($cart_seats),
            'total' => $cart_total
        ));
    }
    
    /**
     * Debug endpoint to see cart contents
     */
    public function debug_cart() {
        // Check nonce
        if (!wp_verify_nonce($_POST['nonce'], 'hope_seating_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $product_id = intval($_POST['product_id']);
        
        // Get current cart
        if (!function_exists('WC') || !WC()->cart) {
            wp_send_json_error('No cart available');
            return;
        }
        
        $cart_debug = array();
        
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $item_debug = array(
                'product_id' => $cart_item['product_id'],
                'variation_id' => isset($cart_item['variation_id']) ? $cart_item['variation_id'] : null,
                'quantity' => $cart_item['quantity'],
                'keys' => array_keys($cart_item)
            );
            
            // Check for seat-related data
            foreach ($cart_item as $key => $value) {
                if (strpos($key, 'seat') !== false || strpos($key, 'fooevents') !== false || $key === 'variation') {
                    $item_debug['seat_data'][$key] = $value;
                }
            }
            
            $cart_debug[] = $item_debug;
        }
        
        wp_send_json_success(array(
            'cart_count' => count($cart_debug),
            'target_product_id' => $product_id,
            'cart_items' => $cart_debug
        ));
    }
    
    /**
     * Debug endpoint to see product variations
     */
    public function debug_variations() {
        // Check nonce
        if (!wp_verify_nonce($_POST['nonce'], 'hope_seating_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $product_id = intval($_POST['product_id']);
        
        if (!$product_id) {
            wp_send_json_error('Invalid product ID');
            return;
        }
        
        $product = wc_get_product($product_id);
        
        if (!$product) {
            wp_send_json_error('Product not found');
            return;
        }
        
        $debug_data = array(
            'product_type' => $product->get_type(),
            'is_variable' => $product->is_type('variable')
        );
        
        if ($product->is_type('variable')) {
            $variations = $product->get_available_variations();
            $debug_data['variation_count'] = count($variations);
            $debug_data['variations'] = array();
            
            foreach ($variations as $i => $variation_data) {
                $variation = wc_get_product($variation_data['variation_id']);
                if ($variation) {
                    $debug_data['variations'][$i] = array(
                        'variation_id' => $variation_data['variation_id'],
                        'attributes' => $variation_data['attributes'],
                        'price' => $variation->get_price(),
                        'display_price' => $variation_data['display_price'] ?? null,
                        'raw_attributes' => $variation->get_attributes()
                    );
                }
            }
        }
        
        wp_send_json_success($debug_data);
    }
    
    /**
     * Handle cart item removal - release seat holds
     */
    public function handle_cart_item_removal($cart_item_key, $cart) {
        $cart_item = $cart->cart_contents[$cart_item_key];
        
        error_log('HOPE: Cart item being removed: ' . print_r($cart_item, true));
        
        // Check if this cart item contains HOPE theater seats
        if (isset($cart_item['hope_theater_seats']) && is_array($cart_item['hope_theater_seats'])) {
            $seats_to_release = $cart_item['hope_theater_seats'];
            $product_id = $cart_item['product_id'];
            $session_id = $cart_item['hope_session_id'] ?? '';
            
            error_log("HOPE: Releasing seats from cart removal - Product: {$product_id}, Seats: " . implode(', ', $seats_to_release));
            
            if (class_exists('HOPE_Session_Manager') && !empty($session_id)) {
                $session_manager = new HOPE_Session_Manager();
                foreach ($seats_to_release as $seat_id) {
                    $released = $session_manager->release_seat_hold($product_id, $seat_id, $session_id);
                    error_log("HOPE: Released seat {$seat_id}: " . ($released ? 'success' : 'failed'));
                }
            }
        }
    }
    
    /**
     * Handle after cart item removal - cleanup and notifications
     */
    public function handle_cart_item_removed($cart_item_key, $cart) {
        // Log the removal for debugging
        error_log("HOPE: Cart item {$cart_item_key} has been removed");
        
        // Trigger a custom action that can be hooked by other components
        do_action('hope_cart_seats_removed', $cart_item_key);
    }
    
    /**
     * Initialize new architecture (physical seats + pricing maps)
     * This runs once to set up the separated architecture
     */
    public function maybe_initialize_new_architecture() {
        // Check if we need to initialize the new architecture
        $initialized = get_option('hope_seating_new_architecture_initialized', false);
        
        // Removed force reinitialization - system is now working
        
        // Clean up any duplicate pricing maps (run once)
        $this->cleanup_duplicate_pricing_maps();
        
        // Regenerate pricing with corrected logic (DISABLED - using manual assignment instead)
        // delete_option('hope_seating_pricing_corrected'); // Force it to run again
        // $this->regenerate_pricing_with_corrections();
        
        if (!$initialized && is_admin()) {
            // Only initialize if we have the new classes available
            if (class_exists('HOPE_Physical_Seats_Manager') && class_exists('HOPE_Pricing_Maps_Manager')) {
                
                // Create physical seats
                $physical_seats = new HOPE_Physical_Seats_Manager();
                $total_seats = $physical_seats->populate_physical_seats();
                
                if ($total_seats > 0) {
                    // Create standard pricing map
                    $pricing_maps = new HOPE_Pricing_Maps_Manager();
                    $pricing_map_id = $pricing_maps->create_standard_pricing_map();
                    
                    if ($pricing_map_id) {
                        // Mark as initialized
                        update_option('hope_seating_new_architecture_initialized', true);
                        
                        // Log success
                        error_log("HOPE Seating: New architecture initialized successfully. {$total_seats} physical seats created with pricing map ID {$pricing_map_id}");
                        
                        // Show admin notice
                        add_action('admin_notices', array($this, 'new_architecture_notice'));
                    }
                }
            }
        }
    }
    
    /**
     * Show new architecture initialization notice
     */
    public function new_architecture_notice() {
        echo '<div class="notice notice-success is-dismissible">';
        echo '<p><strong>HOPE Theater Seating:</strong> New separated architecture initialized! Physical seats and pricing maps are now ready.</p>';
        echo '</div>';
    }
    
    /**
     * Clean up duplicate pricing maps that were created during testing
     */
    private function cleanup_duplicate_pricing_maps() {
        // Only run once
        if (get_option('hope_seating_duplicates_cleaned', false)) {
            return;
        }
        
        global $wpdb;
        $pricing_maps_table = $wpdb->prefix . 'hope_seating_pricing_maps';
        $seat_pricing_table = $wpdb->prefix . 'hope_seating_seat_pricing';
        
        // Get all pricing maps
        $all_maps = $wpdb->get_results("SELECT * FROM $pricing_maps_table ORDER BY id");
        
        if (count($all_maps) > 1) {
            // Keep the first one with seats, delete the rest
            $keep_id = null;
            
            foreach ($all_maps as $map) {
                $seat_count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $seat_pricing_table WHERE pricing_map_id = %d",
                    $map->id
                ));
                
                if ($seat_count > 0 && !$keep_id) {
                    $keep_id = $map->id;
                    break;
                }
            }
            
            // If no map has seats, keep the first one
            if (!$keep_id) {
                $keep_id = $all_maps[0]->id;
            }
            
            // Delete seat pricing for maps we're removing
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $seat_pricing_table WHERE pricing_map_id != %d",
                $keep_id
            ));
            
            // Delete duplicate pricing maps
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM $pricing_maps_table WHERE id != %d",
                $keep_id
            ));
            
            if ($deleted > 0) {
                error_log("HOPE Seating: Cleaned up $deleted duplicate pricing maps, kept ID $keep_id");
            }
        }
        
        // Mark as cleaned
        update_option('hope_seating_duplicates_cleaned', true);
    }
    
    /**
     * Regenerate pricing assignments with corrected logic
     */
    private function regenerate_pricing_with_corrections() {
        // Only run once
        if (get_option('hope_seating_pricing_corrected', false)) {
            return;
        }
        
        if (!class_exists('HOPE_Pricing_Maps_Manager')) {
            return;
        }
        
        global $wpdb;
        $pricing_maps_table = $wpdb->prefix . 'hope_seating_pricing_maps';
        
        // Get the pricing map
        $pricing_map = $wpdb->get_row("SELECT * FROM $pricing_maps_table LIMIT 1");
        
        if ($pricing_map) {
            $pricing_manager = new HOPE_Pricing_Maps_Manager();
            $created_count = $pricing_manager->regenerate_pricing_assignments($pricing_map->id);
            
            if ($created_count > 0) {
                error_log("HOPE Seating: Corrected pricing assignments for $created_count seats");
                
                // Show admin notice about correction
                add_action('admin_notices', array($this, 'pricing_corrected_notice'));
            }
        }
        
        // Mark as corrected
        update_option('hope_seating_pricing_corrected', true);
    }
    
    /**
     * Show pricing correction notice
     */
    public function pricing_corrected_notice() {
        echo '<div class="notice notice-success is-dismissible">';
        echo '<p><strong>HOPE Theater Seating:</strong> Pricing assignments have been corrected to match your spreadsheet exactly!</p>';
        echo '</div>';
    }
}

// Helper function to create default venues
function hope_seating_create_default_venues() {
    global $wpdb;
    $venues_table = $wpdb->prefix . 'hope_seating_venues';
    
    // Check if table exists first
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$venues_table'") == $venues_table;
    
    if (!$table_exists) {
        error_log('HOPE Seating: Venues table does not exist during venue creation');
        return false;
    }
    
    // Check if venues already exist
    $existing = $wpdb->get_var("SELECT COUNT(*) FROM {$venues_table}");
    
    if ($existing > 0) {
        error_log('HOPE Seating: Venues already exist (' . $existing . ' found)');
        return; // Venues already exist
    }
    
    error_log('HOPE Seating: Creating default venues...');
    
    // Create HOPE Theater Main Stage
    $result1 = $wpdb->insert(
        $venues_table,
        array(
            'name' => 'HOPE Theater - Main Stage',
            'slug' => 'hope-theater-main-stage',
            'description' => '497-seat half-round theater with orchestra and balcony levels',
            'total_seats' => 497,
            'configuration' => json_encode(array(
                'type' => 'half-round',
                'levels' => array('orchestra', 'balcony'),
                'sections' => array('A', 'B', 'C', 'D', 'E', 'F', 'G', 'H')
            )),
            'status' => 'active'
        )
    );
    
    if ($result1 === false) {
        error_log('HOPE Seating: Failed to create Main Stage venue - ' . $wpdb->last_error);
    } else {
        error_log('HOPE Seating: Created Main Stage venue with ID ' . $wpdb->insert_id);
    }
    
    // Create Black Box Theater
    $result2 = $wpdb->insert(
        $venues_table,
        array(
            'name' => 'Black Box Theater',
            'slug' => 'black-box-theater',
            'description' => '110-seat flexible configuration theater',
            'total_seats' => 110,
            'configuration' => json_encode(array(
                'type' => 'flexible',
                'levels' => array('floor'),
                'sections' => array('General')
            )),
            'status' => 'active'
        )
    );
    
    if ($result2 === false) {
        error_log('HOPE Seating: Failed to create Black Box venue - ' . $wpdb->last_error);
    } else {
        error_log('HOPE Seating: Created Black Box venue with ID ' . $wpdb->insert_id);
    }
    
    // Final verification
    $final_count = $wpdb->get_var("SELECT COUNT(*) FROM {$venues_table}");
    error_log('HOPE Seating: Venue creation complete. Total venues: ' . $final_count);
}

// Activation hook to create database tables
register_activation_hook(__FILE__, 'hope_seating_activate_plugin');

function hope_seating_activate_plugin() {
    // Create database tables
    if (class_exists('HOPE_Seating_Database')) {
        HOPE_Seating_Database::create_tables();
        update_option('hope_seating_db_version', HOPE_SEATING_VERSION);
        error_log('HOPE Seating: Database tables created during plugin activation');
    }
    
    // OLD VENUE SYSTEM - DISABLED
    // hope_seating_create_default_venues(); // DEPRECATED: Using new pricing maps architecture
}

// Initialize plugin
HOPE_Theater_Seating::get_instance();