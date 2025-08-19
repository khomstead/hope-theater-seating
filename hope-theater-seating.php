<?php
/**
 * Plugin Name: HOPE Theater Seating
 * Plugin URI: https://hopecenterforthearts.org
 * Description: Custom seating chart system for HOPE Theater with accurate half-round layout
 * Version: 2.0.0
 * Author: HOPE Center Development Team
 * License: GPL v2 or later
 * Text Domain: hope-theater-seating
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('HOPE_SEATING_VERSION', '2.0.0');
define('HOPE_SEATING_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HOPE_SEATING_PLUGIN_URL', plugin_dir_url(__FILE__));
define('HOPE_SEATING_PLUGIN_FILE', __FILE__);

/**
 * Main plugin class
 */
class HOPE_Theater_Seating {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }
    
    private function load_dependencies() {
        // Core classes
        require_once HOPE_SEATING_PLUGIN_DIR . 'includes/class-database.php';
        require_once HOPE_SEATING_PLUGIN_DIR . 'includes/class-modal-handler.php';
        require_once HOPE_SEATING_PLUGIN_DIR . 'includes/class-ajax-handler.php';
        require_once HOPE_SEATING_PLUGIN_DIR . 'includes/class-seat-manager.php';
        require_once HOPE_SEATING_PLUGIN_DIR . 'includes/class-mobile-detector.php';
        require_once HOPE_SEATING_PLUGIN_DIR . 'includes/class-session-manager.php';
        
        // Admin classes
        if (is_admin()) {
            require_once HOPE_SEATING_PLUGIN_DIR . 'includes/admin/class-admin-menu.php';
            require_once HOPE_SEATING_PLUGIN_DIR . 'includes/admin/class-product-meta.php';
        }
    }
    
    private function init_hooks() {
        // Activation/Deactivation hooks
        register_activation_hook(HOPE_SEATING_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(HOPE_SEATING_PLUGIN_FILE, [$this, 'deactivate']);
        
        // Initialize components
        add_action('init', [$this, 'init']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_scripts']);
        
        // WooCommerce integration
        add_action('woocommerce_before_add_to_cart_button', [$this, 'add_seat_selection_button']);
        add_action('wp_footer', [$this, 'render_modal']);
        
        // AJAX handlers
        $ajax = new HOPE_Ajax_Handler();
        add_action('wp_ajax_hope_check_availability', [$ajax, 'check_availability']);
        add_action('wp_ajax_nopriv_hope_check_availability', [$ajax, 'check_availability']);
        add_action('wp_ajax_hope_hold_seats', [$ajax, 'hold_seats']);
        add_action('wp_ajax_nopriv_hope_hold_seats', [$ajax, 'hold_seats']);
        add_action('wp_ajax_hope_add_to_cart', [$ajax, 'add_to_cart']);
        add_action('wp_ajax_nopriv_hope_add_to_cart', [$ajax, 'add_to_cart']);
        add_action('wp_ajax_hope_release_seats', [$ajax, 'release_seats']);
        add_action('wp_ajax_nopriv_hope_release_seats', [$ajax, 'release_seats']);
        
        // Session cleanup
        add_action('hope_cleanup_expired_holds', [$this, 'cleanup_expired_holds']);
        
        // Schedule cleanup if not already scheduled
        if (!wp_next_scheduled('hope_cleanup_expired_holds')) {
            wp_schedule_event(time(), 'every_five_minutes', 'hope_cleanup_expired_holds');
        }
        
        // Admin initialization
        if (is_admin()) {
            new HOPE_Admin_Menu();
            new HOPE_Product_Meta();
        }
    }
    
    public function init() {
        // Add custom cron schedule
        add_filter('cron_schedules', function($schedules) {
            $schedules['every_five_minutes'] = [
                'interval' => 300,
                'display' => __('Every 5 Minutes', 'hope-theater-seating')
            ];
            return $schedules;
        });
    }
    
    public function enqueue_scripts() {
        if (!is_product()) {
            return;
        }
        
        global $product;
        if (!$product || !get_post_meta($product->get_id(), '_hope_has_seating', true)) {
            return;
        }
        
        // Core styles
        wp_enqueue_style(
            'hope-seating-styles',
            HOPE_SEATING_PLUGIN_URL . 'assets/css/seat-map.css',
            [],
            HOPE_SEATING_VERSION
        );
        
        wp_enqueue_style(
            'hope-modal-styles',
            HOPE_SEATING_PLUGIN_URL . 'assets/css/modal.css',
            [],
            HOPE_SEATING_VERSION
        );
        
        // Core scripts
        wp_enqueue_script(
            'hope-seating-map',
            HOPE_SEATING_PLUGIN_URL . 'assets/js/seat-map.js',
            [],
            HOPE_SEATING_VERSION,
            true
        );
        
        wp_enqueue_script(
            'hope-modal-handler',
            HOPE_SEATING_PLUGIN_URL . 'assets/js/modal-handler.js',
            ['hope-seating-map'],
            HOPE_SEATING_VERSION,
            true
        );
        
        // Mobile handler for touch devices
        $mobile_detector = new HOPE_Mobile_Detector();
        if ($mobile_detector->is_mobile()) {
            wp_enqueue_script(
                'hope-mobile-handler',
                HOPE_SEATING_PLUGIN_URL . 'assets/js/mobile-handler.js',
                ['hope-seating-map'],
                HOPE_SEATING_VERSION,
                true
            );
        }
        
        // Localize script with AJAX data
        wp_localize_script('hope-seating-map', 'hope_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hope_seating_nonce'),
            'product_id' => $product->get_id(),
            'venue_id' => get_post_meta($product->get_id(), '_hope_venue_id', true),
            'is_mobile' => $mobile_detector->is_mobile(),
            'session_id' => HOPE_Session_Manager::get_session_id(),
            'hold_duration' => 600, // 10 minutes in seconds
            'messages' => [
                'seat_unavailable' => __('This seat is no longer available', 'hope-theater-seating'),
                'connection_error' => __('Connection error. Please try again.', 'hope-theater-seating'),
                'session_expired' => __('Your session has expired. Please refresh the page.', 'hope-theater-seating'),
                'max_seats' => __('Maximum 10 seats per order', 'hope-theater-seating')
            ]
        ]);
    }
    
    public function admin_enqueue_scripts($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php', 'toplevel_page_hope-seating'])) {
            return;
        }
        
        wp_enqueue_style(
            'hope-admin-styles',
            HOPE_SEATING_PLUGIN_URL . 'assets/css/admin.css',
            [],
            HOPE_SEATING_VERSION
        );
        
        wp_enqueue_script(
            'hope-admin-scripts',
            HOPE_SEATING_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            HOPE_SEATING_VERSION,
            true
        );
    }
    
    public function add_seat_selection_button() {
        global $product;
        
        if (!$product || !get_post_meta($product->get_id(), '_hope_has_seating', true)) {
            return;
        }
        
        $modal_handler = new HOPE_Modal_Handler();
        $modal_handler->render_seat_selection_button($product->get_id());
    }
    
    public function render_modal() {
        if (!is_product()) {
            return;
        }
        
        global $product;
        if (!$product || !get_post_meta($product->get_id(), '_hope_has_seating', true)) {
            return;
        }
        
        $modal_handler = new HOPE_Modal_Handler();
        $modal_handler->render_modal_wrapper();
    }
    
    public function cleanup_expired_holds() {
        $session_manager = new HOPE_Session_Manager();
        $session_manager->cleanup_expired_holds();
    }
    
    public function activate() {
        // Create database tables
        $database = new HOPE_Database();
        $database->create_tables();
        
        // Initialize default data
        $database->initialize_venue_data();
        
        // Schedule cleanup cron
        if (!wp_next_scheduled('hope_cleanup_expired_holds')) {
            wp_schedule_event(time(), 'every_five_minutes', 'hope_cleanup_expired_holds');
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('hope_cleanup_expired_holds');
        
        // Clean up temporary holds
        $session_manager = new HOPE_Session_Manager();
        $session_manager->cleanup_all_holds();
    }
}

// Initialize plugin
add_action('plugins_loaded', function() {
    HOPE_Theater_Seating::get_instance();
});