<?php
/**
 * Plugin Name: HOPE Theater Seating
 * Plugin URI: https://hopecenterforthearts.org
 * GitHub Plugin URI: khomstead/hope-theater-seating
 * GitHub Branch: main
 * Primary Branch: main
 * Release Asset: true
 * Description: Custom seating chart system for HOPE Theater venues with WooCommerce/FooEvents integration
 * Version: 2.2.20
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
define('HOPE_SEATING_VERSION', '2.2.20');
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
    require_once HOPE_SEATING_PLUGIN_DIR . 'includes/class-seat-manager.php';
    
    // Create database tables
    $database = new HOPE_Seating_Database();
    $database->create_tables();
    
    // Create default venues if they don't exist
    hope_seating_create_default_venues();
    
    // NEW: Populate seats for main venue
    $seat_manager = new HOPE_Seat_Manager(1); // Venue ID 1 is Main Stage
    $total_seats = $seat_manager->populate_seats();
    
    // Log activation
    error_log('HOPE Theater Seating v2.0.1 activated. ' . $total_seats . ' seats populated.');
    
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
        
        // Check if files exist before requiring
        $files_to_include = array(
            'includes/class-venues.php',
            'includes/class-seat-maps.php',
            'includes/class-seat-manager.php',     // NEW
            'includes/class-session-manager.php',   // NEW
            'includes/class-mobile-detector.php',   // NEW
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
        
        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // Add body classes for device detection
        if (class_exists('HOPE_Mobile_Detector')) {
            add_filter('body_class', array(HOPE_Mobile_Detector::get_instance(), 'add_body_classes'));
        }
    }
    
    public function enqueue_frontend_assets() {
        // Get mobile detector instance if available
        $viewport_config = array();
        if (class_exists('HOPE_Mobile_Detector')) {
            $mobile_detector = HOPE_Mobile_Detector::get_instance();
            $viewport_config = $mobile_detector->get_viewport_config();
        }
        
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
        
        // Get session ID
        $session_id = '';
        if (class_exists('HOPE_Session_Manager')) {
            $session_id = HOPE_Session_Manager::get_current_session_id();
        }
        
        // Get current product data for seat map
        global $product;
        $product_id = $product ? $product->get_id() : 0;
        $venue_id = $product ? get_post_meta($product->get_id(), '_hope_seating_venue_id', true) : 0;
        
        // Localize script with configuration
        wp_localize_script('hope-seating-seat-map', 'hope_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hope_seating_nonce'),
            'session_id' => $session_id,
            'product_id' => $product_id,
            'venue_id' => $venue_id,
            'hold_duration' => 600, // 10 minutes
            'device_type' => isset($mobile_detector) ? $mobile_detector->get_device_type() : 'desktop',
            'is_mobile' => isset($mobile_detector) ? $mobile_detector->is_mobile() : false,
            'viewport_config' => $viewport_config,
            'messages' => array(
                'seat_unavailable' => __('This seat is no longer available', 'hope-seating'),
                'max_seats' => __('Maximum number of seats selected', 'hope-seating'),
                'connection_error' => __('Connection error. Please try again.', 'hope-seating'),
                'session_expired' => __('Your session has expired. Please refresh the page.', 'hope-seating')
            )
        ));
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
        
        // Only update if version has changed and is 2.2.17 or later
        if (version_compare($current_version, '2.2.17', '<')) {
            if (class_exists('HOPE_Seating_Database')) {
                HOPE_Seating_Database::update_database_schema();
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

// Initialize plugin
HOPE_Theater_Seating::get_instance();