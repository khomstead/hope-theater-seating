<?php
/**
 * HOPE Theater Seating - Unified Admin Features Testing
 * Tests both selective refunds AND seat blocking functionality
 * 
 * SAFE TO RUN: Only displays information, does not modify data
 */

// Load WordPress
if (!defined('ABSPATH')) {
    require_once(__DIR__ . '/../../../wp-config.php');
}

// Check if user is admin
if (!current_user_can('manage_woocommerce')) {
    wp_die('Access denied. This page requires WooCommerce management permissions.');
}

echo "<h1>🎭 HOPE Theater Admin Features - Complete Testing Suite</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 40px; line-height: 1.6; }
    .success { color: #0073aa; background: #f0f8ff; padding: 10px; border-left: 4px solid #0073aa; margin: 10px 0; }
    .error { color: #d63638; background: #fff0f0; padding: 10px; border-left: 4px solid #d63638; margin: 10px 0; }
    .info { color: #646970; background: #f6f7f7; padding: 10px; border-left: 4px solid #646970; margin: 10px 0; }
    .warning { color: #b47f00; background: #fffaf0; padding: 10px; border-left: 4px solid #b47f00; margin: 10px 0; }
    .test-section { border: 1px solid #ddd; margin: 20px 0; padding: 15px; border-radius: 4px; }
    .feature-ready { background: #d4edda; border-color: #c3e6cb; }
    .feature-pending { background: #fff3cd; border-color: #ffeaa7; }
    .test-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px 0; }
    .button { background: #0073aa; color: white; padding: 8px 15px; text-decoration: none; border-radius: 3px; display: inline-block; margin: 5px; }
    .button-secondary { background: #6c757d; }
    .button-success { background: #198754; }
    .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin: 15px 0; }
    .stat-box { text-align: center; padding: 15px; background: #f8f9fa; border-radius: 4px; border: 1px solid #dee2e6; }
    .stat-number { font-size: 24px; font-weight: bold; color: #0073aa; }
    .stat-label { font-size: 12px; color: #666; text-transform: uppercase; }
</style>";

// Test 1: Overall system status
echo "<div class='test-section'>";
echo "<h2>🚀 System Status Overview</h2>";

$all_classes = array(
    'HOPE_Database_Selective_Refunds' => 'Database support for advanced features',
    'HOPE_Selective_Refund_Handler' => 'Selective refund processing',
    'HOPE_Admin_Selective_Refunds' => 'Order-based refund admin interface',
    'HOPE_Seat_Blocking_Handler' => 'Seat blocking functionality',
    'HOPE_Admin_Seat_Blocking' => 'Event-based blocking admin interface'
);

$all_loaded = true;
$selective_refunds_ready = false;
$seat_blocking_ready = false;

foreach ($all_classes as $class => $description) {
    $exists = class_exists($class);
    $status = $exists ? 'success' : 'error';
    $icon = $exists ? '✅' : '❌';
    echo "<div class='{$status}'>{$icon} {$class}: " . ($exists ? 'LOADED' : 'NOT LOADED') . " - {$description}</div>";
    if (!$exists) $all_loaded = false;
}

if ($all_loaded) {
    $selective_refunds_ready = HOPE_Selective_Refund_Handler::is_available();
    $seat_blocking_ready = HOPE_Seat_Blocking_Handler::is_available();
    
    echo "<div class='stats-grid'>";
    echo "<div class='stat-box'><div class='stat-number'>✅</div><div class='stat-label'>Classes Loaded</div></div>";
    echo "<div class='stat-box'><div class='stat-number'>" . ($selective_refunds_ready ? '✅' : '⏳') . "</div><div class='stat-label'>Selective Refunds</div></div>";
    echo "<div class='stat-box'><div class='stat-number'>" . ($seat_blocking_ready ? '✅' : '⏳') . "</div><div class='stat-label'>Seat Blocking</div></div>";
    echo "<div class='stat-box'><div class='stat-number'>2.4.8</div><div class='stat-label'>Plugin Version</div></div>";
    echo "</div>";
}

echo "</div>";

// Test 2: Split interface testing
echo "<div class='test-grid'>";

// Left column: Selective Refunds
echo "<div class='test-section'>";
echo "<h2>💰 Selective Refunds Testing</h2>";

if ($selective_refunds_ready) {
    echo "<div class='success feature-ready'>🎯 <strong>Selective Refunds: READY</strong></div>";
    
    // Find orders with theater seats for refund testing
    $orders = wc_get_orders(array(
        'limit' => 10,
        'status' => array('completed', 'processing', 'on-hold')
    ));
    
    $refund_orders = array();
    foreach ($orders as $order) {
        $refundable_seats = HOPE_Database_Selective_Refunds::get_refundable_seats($order->get_id());
        if (!empty($refundable_seats)) {
            $refund_orders[] = array(
                'order' => $order,
                'seats' => $refundable_seats
            );
        }
    }
    
    if (!empty($refund_orders)) {
        echo "<div class='info'>📋 Found " . count($refund_orders) . " orders available for refund testing</div>";
        
        foreach (array_slice($refund_orders, 0, 3) as $refund_order) {
            $order = $refund_order['order'];
            $seats = $refund_order['seats'];
            
            echo "<div style='background: #f8f9fa; padding: 10px; margin: 10px 0; border-radius: 4px;'>";
            echo "<strong>Order #{$order->get_order_number()}</strong><br>";
            echo "Seats: " . count($seats) . " (" . implode(', ', array_slice(array_column($seats, 'seat_id'), 0, 5)) . ")<br>";
            
            $edit_url = admin_url('post.php?post=' . $order->get_id() . '&action=edit');
            echo "<a href='{$edit_url}' class='button' target='_blank'>🎭 Test Selective Refunds</a>";
            echo "</div>";
        }
    } else {
        echo "<div class='warning'>⚠️ No orders with refundable theater seats found</div>";
    }
    
    echo "<div class='info'><strong>How to test selective refunds:</strong></div>";
    echo "<ol>";
    echo "<li>Click 'Test Selective Refunds' on any order above</li>";
    echo "<li>Look for '🎭 Theater Seat Management' meta box</li>";
    echo "<li>Select specific seats to refund</li>";
    echo "<li>Process partial refund through WooCommerce</li>";
    echo "</ol>";
    
} else {
    echo "<div class='error'>❌ Selective refunds not available - database setup needed</div>";
}

echo "</div>";

// Right column: Seat Blocking
echo "<div class='test-section'>";
echo "<h2>🚫 Seat Blocking Testing</h2>";

if ($seat_blocking_ready) {
    echo "<div class='success feature-ready'>🎯 <strong>Seat Blocking: READY</strong></div>";
    
    // Find theater products for blocking testing
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => 5,
        'meta_query' => array(
            array(
                'key' => '_hope_seating_enabled',
                'value' => 'yes',
                'compare' => '='
            )
        )
    );
    
    $theater_products = get_posts($args);
    
    if (!empty($theater_products)) {
        echo "<div class='info'>🎪 Found " . count($theater_products) . " theater events available for blocking</div>";
        
        foreach (array_slice($theater_products, 0, 3) as $post) {
            $product = wc_get_product($post->ID);
            
            echo "<div style='background: #f8f9fa; padding: 10px; margin: 10px 0; border-radius: 4px;'>";
            echo "<strong>{$product->get_name()}</strong><br>";
            echo "Event ID: {$product->get_id()}<br>";
            echo "</div>";
        }
        
        $blocking_url = admin_url('admin.php?page=hope-seat-blocking');
        echo "<a href='{$blocking_url}' class='button button-secondary' target='_blank'>🎛️ Open Seat Blocking Admin</a>";
    } else {
        echo "<div class='warning'>⚠️ No theater events found for blocking tests</div>";
    }
    
    echo "<div class='info'><strong>How to test seat blocking:</strong></div>";
    echo "<ol>";
    echo "<li>Click 'Open Seat Blocking Admin' above</li>";
    echo "<li>Navigate to WooCommerce → Seat Blocking</li>";
    echo "<li>Select an event from the dropdown</li>";
    echo "<li>Click seats to select them for blocking</li>";
    echo "<li>Choose block type and create the block</li>";
    echo "</ol>";
    
} else {
    echo "<div class='error'>❌ Seat blocking not available - database setup needed</div>";
}

echo "</div>";
echo "</div>"; // End test grid

// Test 3: Database status
echo "<div class='test-section'>";
echo "<h2>🗄️ Database Status</h2>";

global $wpdb;

$tables_to_check = array(
    'hope_seating_bookings' => 'Main bookings table',
    'hope_seating_selective_refunds' => 'Selective refunds tracking',
    'hope_seating_seat_blocks' => 'Seat blocking records'
);

foreach ($tables_to_check as $table_suffix => $description) {
    $table_name = $wpdb->prefix . $table_suffix;
    $exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    
    $status = $exists ? 'success' : 'warning';
    $icon = $exists ? '✅' : '⏳';
    echo "<div class='{$status}'>{$icon} {$table_name}: " . ($exists ? 'EXISTS' : 'PENDING CREATION') . " - {$description}</div>";
    
    if ($exists) {
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        echo "<div class='info'>📊 Records in table: {$count}</div>";
    }
}

echo "</div>";

// Test 4: Integration status
echo "<div class='test-section'>";
echo "<h2>🔗 Integration Status</h2>";

$integrations = array(
    'WooCommerce Hooks' => has_action('add_meta_boxes'),
    'Selective Refund AJAX' => has_action('wp_ajax_hope_process_admin_selective_refund'),
    'Seat Blocking AJAX' => has_action('wp_ajax_hope_admin_create_seat_block'),
    'Session Manager Integration' => method_exists('HOPE_Session_Manager', '__construct'),
    'Admin Menu Registration' => has_action('admin_menu')
);

foreach ($integrations as $integration => $status) {
    $icon = $status ? '✅' : '❌';
    $class = $status ? 'success' : 'error';
    echo "<div class='{$class}'>{$icon} {$integration}: " . ($status ? 'ACTIVE' : 'NOT ACTIVE') . "</div>";
}

echo "</div>";

// Test 5: Quick actions
echo "<div class='test-section'>";
echo "<h2>⚡ Quick Actions</h2>";

echo "<div class='test-grid'>";

echo "<div>";
echo "<h4>Selective Refunds</h4>";
if ($selective_refunds_ready) {
    echo "<a href='" . admin_url('edit.php?post_type=shop_order') . "' class='button' target='_blank'>📋 View Orders</a>";
    echo "<a href='test-admin-interface.php' class='button button-secondary' target='_blank'>🧪 Run Refund Tests</a>";
} else {
    echo "<div class='info'>⏳ Setup database to enable quick actions</div>";
}
echo "</div>";

echo "<div>";
echo "<h4>Seat Blocking</h4>";
if ($seat_blocking_ready) {
    echo "<a href='" . admin_url('admin.php?page=hope-seat-blocking') . "' class='button button-success' target='_blank'>🎛️ Seat Blocking Admin</a>";
    echo "<a href='" . admin_url('edit.php?post_type=product') . "' class='button button-secondary' target='_blank'>🎪 View Products</a>";
} else {
    echo "<div class='info'>⏳ Setup database to enable quick actions</div>";
}
echo "</div>";

echo "</div>";
echo "</div>";

// Test 6: Setup instructions
if (!$all_loaded || !$selective_refunds_ready || !$seat_blocking_ready) {
    echo "<div class='test-section'>";
    echo "<h2>⚙️ Setup Instructions</h2>";
    
    if (!$all_loaded) {
        echo "<div class='error'><strong>Missing Classes:</strong> Some plugin files may not be loaded correctly.</div>";
        echo "<div class='info'>1. Ensure all plugin files are uploaded correctly</div>";
        echo "<div class='info'>2. Check for PHP errors in WordPress error logs</div>";
    }
    
    if (!$selective_refunds_ready || !$seat_blocking_ready) {
        echo "<div class='warning'><strong>Database Setup Needed:</strong> Advanced features require database updates.</div>";
        echo "<div class='info'>1. Deactivate the HOPE Theater Seating plugin</div>";
        echo "<div class='info'>2. Reactivate the plugin to run database updates</div>";
        echo "<div class='info'>3. Refresh this page to verify setup</div>";
    }
    
    echo "</div>";
}

// Summary
echo "<div style='margin-top: 30px; padding: 20px; background: #f0f8ff; border: 1px solid #0073aa; border-radius: 4px;'>";
echo "<h3>🎯 Complete Feature Summary</h3>";
echo "<div class='test-grid'>";

echo "<div>";
echo "<h4>✅ Selective Refunds</h4>";
echo "<ul>";
echo "<li><strong>Purpose:</strong> Allow partial refunds of specific theater seats</li>";
echo "<li><strong>Location:</strong> WooCommerce order edit pages</li>";
echo "<li><strong>Features:</strong> Visual seat selection, proportional refund calculation</li>";
echo "<li><strong>Integration:</strong> Native WooCommerce refund API</li>";
echo "</ul>";
echo "</div>";

echo "<div>";
echo "<h4>🚫 Seat Blocking</h4>";
echo "<ul>";
echo "<li><strong>Purpose:</strong> Block seats for equipment, VIP, maintenance</li>";
echo "<li><strong>Location:</strong> WooCommerce → Seat Blocking admin page</li>";
echo "<li><strong>Features:</strong> Multiple block types, time-based blocking</li>";
echo "<li><strong>Integration:</strong> Automatic availability filtering</li>";
echo "</ul>";
echo "</div>";

echo "</div>";

$status = ($selective_refunds_ready && $seat_blocking_ready) ? 'FULLY OPERATIONAL' : 'SETUP REQUIRED';
$icon = ($selective_refunds_ready && $seat_blocking_ready) ? '🚀' : '⚙️';
echo "<p><strong>Overall Status:</strong> {$icon} {$status}</p>";
echo "</div>";

echo "<p style='text-align: center; margin-top: 30px;'>";
echo "<a href='?' class='button'>🔄 Refresh All Tests</a>";
echo "<a href='test-selective-refunds.php' class='button button-secondary'>📋 Basic Tests</a>";
echo "<a href='test-admin-interface.php' class='button button-secondary'>🎭 Refund Tests</a>";
echo "</p>";
?>