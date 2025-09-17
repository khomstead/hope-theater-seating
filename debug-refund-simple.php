<?php
/**
 * Simple debug script for refund handler
 * Minimal version to identify what's causing the critical error
 */

// Try to load WordPress
try {
    if (!defined('ABSPATH')) {
        echo "Loading WordPress...\n";
        require_once(__DIR__ . '/../../../wp-config.php');
        echo "WordPress loaded successfully.\n";
    }
} catch (Exception $e) {
    echo "Error loading WordPress: " . $e->getMessage() . "\n";
    exit;
}

echo "<h1>Simple Refund Handler Debug</h1>";
echo "<style>body { font-family: Arial, sans-serif; margin: 40px; }</style>";

// Check if we're on the right environment
echo "<p><strong>WordPress loaded:</strong> " . (defined('ABSPATH') ? 'YES' : 'NO') . "</p>";
echo "<p><strong>WP_DEBUG:</strong> " . (defined('WP_DEBUG') && WP_DEBUG ? 'ON' : 'OFF') . "</p>";

// Check if WooCommerce exists
echo "<p><strong>WooCommerce available:</strong> " . (class_exists('WooCommerce') ? 'YES' : 'NO') . "</p>";

// Check if our plugin classes exist
$classes_to_check = [
    'HOPE_Refund_Handler',
    'HOPE_Session_Manager', 
    'HOPE_Seating_Database',
    'HOPE_Ajax_Handler'
];

echo "<h2>Plugin Classes Status</h2>";
foreach ($classes_to_check as $class) {
    $exists = class_exists($class);
    echo "<p><strong>{$class}:</strong> " . ($exists ? 'LOADED' : 'NOT FOUND') . "</p>";
}

// Try to instantiate refund handler
echo "<h2>Refund Handler Test</h2>";
try {
    if (class_exists('HOPE_Refund_Handler')) {
        echo "<p>Attempting to create HOPE_Refund_Handler instance...</p>";
        $handler = new HOPE_Refund_Handler();
        echo "<p style='color: green;'>✅ HOPE_Refund_Handler created successfully!</p>";
        
        // Test a simple method
        try {
            $stats = $handler->get_refund_stats();
            echo "<p style='color: green;'>✅ get_refund_stats() works: " . json_encode($stats) . "</p>";
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ get_refund_stats() failed: " . $e->getMessage() . "</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ HOPE_Refund_Handler class not found</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error creating refund handler: " . $e->getMessage() . "</p>";
    echo "<p><strong>Error details:</strong> " . $e->getFile() . " line " . $e->getLine() . "</p>";
}

// Check database connection
echo "<h2>Database Test</h2>";
try {
    global $wpdb;
    $bookings_table = $wpdb->prefix . 'hope_seating_bookings';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$bookings_table'") == $bookings_table;
    echo "<p><strong>Bookings table exists:</strong> " . ($table_exists ? 'YES' : 'NO') . "</p>";
    
    if ($table_exists) {
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $bookings_table");
        echo "<p><strong>Booking records:</strong> {$count}</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Database error: " . $e->getMessage() . "</p>";
}

// Check if hooks are registered  
echo "<h2>Hook Registration Check</h2>";
try {
    $hook_status = has_action('woocommerce_order_status_refunded');
    echo "<p><strong>woocommerce_order_status_refunded:</strong> " . ($hook_status ? "REGISTERED" : "NOT REGISTERED") . "</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>Error checking hooks: " . $e->getMessage() . "</p>";
}

echo "<p><a href='?' style='background: #0073aa; color: white; padding: 10px 15px; text-decoration: none; border-radius: 3px;'>Refresh</a></p>";
?>