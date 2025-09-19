<?php
/**
 * HOPE Theater Seating POS Integration
 *
 * @link              https://hopecenterforthearts.com
 * @since             1.0.0
 * @package           hope-theater-seating-pos
 *
 * @wordpress-plugin
 * Plugin Name:       HOPE Theater Seating POS Integration
 * Plugin URI:        https://hopecenterforthearts.com
 * Description:       Integrates HOPE Theater Seating with FooEventsPOS for visual seat selection in Point of Sale interface.
 * Version:           1.0.0
 * Author:            HOPE Center for the Arts
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       hope-theater-seating-pos
 * Domain Path:       /languages
 * Requires at least: 5.0
 * Tested up to:      6.3
 * Requires PHP:      7.4
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Currently plugin version.
 */
define('HOPE_THEATER_SEATING_POS_VERSION', '1.0.0');
define('HOPE_THEATER_SEATING_POS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HOPE_THEATER_SEATING_POS_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Check for required dependencies on plugin activation
 */
register_activation_hook(__FILE__, 'hope_theater_seating_pos_activation_check');

function hope_theater_seating_pos_activation_check() {
    // Check for HOPE Theater Seating plugin
    if (!class_exists('HOPE_Theater_Seating')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            __('HOPE Theater Seating POS Integration requires the HOPE Theater Seating plugin to be installed and activated.', 'hope-theater-seating-pos'),
            __('Plugin Activation Error', 'hope-theater-seating-pos'),
            array('back_link' => true)
        );
    }

    // Check for FooEvents POS plugin
    if (!class_exists('FooEvents_POS')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            __('HOPE Theater Seating POS Integration requires the FooEvents POS plugin to be installed and activated.', 'hope-theater-seating-pos'),
            __('Plugin Activation Error', 'hope-theater-seating-pos'),
            array('back_link' => true)
        );
    }
}

/**
 * Check dependencies on every page load and show admin notices if missing
 */
add_action('admin_notices', 'hope_theater_seating_pos_dependency_notices');

function hope_theater_seating_pos_dependency_notices() {
    if (!class_exists('HOPE_Theater_Seating')) {
        echo '<div class="notice notice-error"><p>';
        echo __('HOPE Theater Seating POS Integration requires the HOPE Theater Seating plugin to be installed and activated.', 'hope-theater-seating-pos');
        echo '</p></div>';
        return;
    }

    if (!class_exists('FooEvents_POS')) {
        echo '<div class="notice notice-error"><p>';
        echo __('HOPE Theater Seating POS Integration requires the FooEvents POS plugin to be installed and activated.', 'hope-theater-seating-pos');
        echo '</p></div>';
        return;
    }

    // Check for minimum versions if needed
    // TODO: Add version checks when implementing
}

/**
 * Initialize the plugin only if dependencies are met
 */
add_action('plugins_loaded', 'hope_theater_seating_pos_init');

function hope_theater_seating_pos_init() {
    // Early return if dependencies not met
    if (!class_exists('HOPE_Theater_Seating') || !class_exists('FooEvents_POS')) {
        return;
    }

    // Load plugin classes
    require_once HOPE_THEATER_SEATING_POS_PLUGIN_DIR . 'includes/class-pos-integration.php';
    require_once HOPE_THEATER_SEATING_POS_PLUGIN_DIR . 'includes/class-pos-rest-api.php';
    require_once HOPE_THEATER_SEATING_POS_PLUGIN_DIR . 'includes/class-seat-selection-handler.php';

    // Initialize the plugin
    new HOPE_POS_Integration();
}

/**
 * Load plugin textdomain for translations
 */
add_action('init', 'hope_theater_seating_pos_load_textdomain');

function hope_theater_seating_pos_load_textdomain() {
    load_plugin_textdomain(
        'hope-theater-seating-pos',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages/'
    );
}