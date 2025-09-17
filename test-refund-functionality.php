<?php
/**
 * Test script for HOPE Theater Seating Refund Functionality
 * 
 * This script helps test the refund handler without actually processing refunds
 * Use this on the staging site to verify the refund integration works correctly
 * 
 * Usage: Access via browser: /wp-content/plugins/hope-theater-seating/test-refund-functionality.php
 */

// Load WordPress
if (!defined('ABSPATH')) {
    require_once(__DIR__ . '/../../../wp-config.php');
}

// Security check - only allow on staging/development
if (!WP_DEBUG && !defined('WP_LOCAL_DEV')) {
    wp_die('This test script is only available on development/staging sites.');
}

function test_refund_functionality() {
    global $wpdb;
    
    echo "<h1>HOPE Theater Seating - Refund Functionality Test</h1>";
    echo "<style>body { font-family: Arial, sans-serif; margin: 40px; } .success { color: green; } .error { color: red; } .info { color: blue; } pre { background: #f5f5f5; padding: 15px; border-radius: 5px; }</style>";
    
    // Check if refund handler class exists
    if (!class_exists('HOPE_Refund_Handler')) {
        echo "<p class='error'>❌ HOPE_Refund_Handler class not found. Make sure the plugin is activated.</p>";
        return;
    }
    
    echo "<p class='success'>✅ HOPE_Refund_Handler class loaded successfully</p>";
    
    // Check database tables
    $bookings_table = $wpdb->prefix . 'hope_seating_bookings';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$bookings_table'") == $bookings_table;
    
    if (!$table_exists) {
        echo "<p class='error'>❌ Bookings table ({$bookings_table}) does not exist</p>";
        return;
    }
    
    echo "<p class='success'>✅ Bookings table exists</p>";
    
    // Check for recent orders with seat bookings
    $recent_bookings = $wpdb->get_results("
        SELECT b.*, o.post_status as order_status 
        FROM {$bookings_table} b
        LEFT JOIN {$wpdb->posts} o ON b.order_id = o.ID
        WHERE b.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY b.created_at DESC
        LIMIT 10
    ");
    
    echo "<h2>Recent Seat Bookings (Last 30 Days)</h2>";
    if (empty($recent_bookings)) {
        echo "<p class='info'>ℹ️ No recent bookings found. Create a test order with seats to test refund functionality.</p>";
    } else {
        echo "<table border='1' cellpadding='8' style='border-collapse: collapse;'>";
        echo "<tr><th>Booking ID</th><th>Seat ID</th><th>Order ID</th><th>Order Status</th><th>Booking Status</th><th>Created</th></tr>";
        
        foreach ($recent_bookings as $booking) {
            $status_class = ($booking->status === 'confirmed') ? 'success' : 'info';
            echo "<tr>";
            echo "<td>{$booking->id}</td>";
            echo "<td>{$booking->seat_id}</td>";
            echo "<td>{$booking->order_id}</td>";
            echo "<td>{$booking->order_status}</td>";
            echo "<td class='{$status_class}'>{$booking->status}</td>";
            echo "<td>{$booking->created_at}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Test refund handler methods (without actually processing refunds)
    echo "<h2>Refund Handler Method Tests</h2>";
    
    $refund_handler = new HOPE_Refund_Handler();
    
    // Test getting refund stats
    try {
        $stats = $refund_handler->get_refund_stats();
        echo "<p class='success'>✅ get_refund_stats() method works</p>";
        echo "<pre>";
        echo "Refund Statistics:\n";
        foreach ($stats as $status => $count) {
            echo "- {$status}: {$count} seats\n";
        }
        echo "</pre>";
    } catch (Exception $e) {
        echo "<p class='error'>❌ get_refund_stats() method failed: " . $e->getMessage() . "</p>";
    }
    
    // Check WooCommerce hooks
    echo "<h2>WooCommerce Hook Registration</h2>";
    
    $hooks_to_check = array(
        'woocommerce_order_status_refunded',
        'woocommerce_refund_created',
        'woocommerce_order_status_cancelled',
        'woocommerce_order_status_changed'
    );
    
    foreach ($hooks_to_check as $hook) {
        $priority = has_action($hook, array($refund_handler, 'handle_full_refund'));
        if ($priority === false) {
            $priority = has_action($hook, array($refund_handler, 'handle_partial_refund'));
        }
        if ($priority === false) {
            $priority = has_action($hook, array($refund_handler, 'handle_cancelled_order'));
        }
        if ($priority === false) {
            $priority = has_action($hook, array($refund_handler, 'handle_order_status_change'));
        }
        
        if ($priority !== false) {
            echo "<p class='success'>✅ Hook '{$hook}' is registered (priority: {$priority})</p>";
        } else {
            echo "<p class='error'>❌ Hook '{$hook}' is not registered</p>";
        }
    }
    
    // Show test instructions
    echo "<h2>Manual Testing Instructions</h2>";
    echo "<ol>";
    echo "<li><strong>Create a test order:</strong> Purchase tickets with seat selection</li>";
    echo "<li><strong>Verify booking:</strong> Check that seats appear as 'confirmed' in the table above</li>";
    echo "<li><strong>Process refund:</strong> Go to WooCommerce Orders and refund the test order</li>";
    echo "<li><strong>Check results:</strong> Refresh this page and verify booking status changed to 'refunded'</li>";
    echo "<li><strong>Test seat availability:</strong> Verify refunded seats are available for selection again</li>";
    echo "</ol>";
    
    echo "<p><a href='?' style='background: #0073aa; color: white; padding: 10px 15px; text-decoration: none; border-radius: 3px;'>Refresh Test Results</a></p>";
    
    // Show recent error logs related to refunds
    echo "<h2>Recent Error Log Entries (HOPE REFUND)</h2>";
    $log_file = WP_CONTENT_DIR . '/debug.log';
    if (file_exists($log_file)) {
        $log_content = file_get_contents($log_file);
        $log_lines = explode("\n", $log_content);
        $refund_logs = array_filter($log_lines, function($line) {
            return strpos($line, 'HOPE REFUND') !== false;
        });
        
        if (empty($refund_logs)) {
            echo "<p class='info'>ℹ️ No refund-related log entries found</p>";
        } else {
            echo "<pre style='max-height: 300px; overflow-y: scroll;'>";
            foreach (array_slice(array_reverse($refund_logs), 0, 10) as $log) {
                echo htmlspecialchars($log) . "\n";
            }
            echo "</pre>";
        }
    } else {
        echo "<p class='info'>ℹ️ Debug log file not found. Enable WP_DEBUG_LOG to see log entries.</p>";
    }
}

// Run the test
test_refund_functionality();
?>