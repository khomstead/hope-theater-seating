<?php
/**
 * HOPE Theater Seating - Admin Interface Testing
 * Tests Phase 2 admin interface for selective refunds
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

echo "<h1>HOPE Selective Refunds - Admin Interface Testing</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 40px; line-height: 1.6; }
    .success { color: #0073aa; background: #f0f8ff; padding: 10px; border-left: 4px solid #0073aa; margin: 10px 0; }
    .error { color: #d63638; background: #fff0f0; padding: 10px; border-left: 4px solid #d63638; margin: 10px 0; }
    .info { color: #646970; background: #f6f7f7; padding: 10px; border-left: 4px solid #646970; margin: 10px 0; }
    .test-section { border: 1px solid #ddd; margin: 20px 0; padding: 15px; border-radius: 4px; }
    .feature-ready { background: #d4edda; border-color: #c3e6cb; }
    .test-order { background: #f8f9fa; border: 1px solid #dee2e6; padding: 10px; margin: 10px 0; border-radius: 4px; }
    .button { background: #0073aa; color: white; padding: 8px 15px; text-decoration: none; border-radius: 3px; display: inline-block; margin: 5px; }
</style>";

// Test 1: Check Phase 2 classes
echo "<div class='test-section'>";
echo "<h2>📦 Phase 2 Class Loading</h2>";

$phase2_classes = array(
    'HOPE_Admin_Selective_Refunds' => 'Admin interface for selective refunds',
    'HOPE_Selective_Refund_Handler' => 'Core selective refund processing (Phase 1)',
    'HOPE_Database_Selective_Refunds' => 'Database support (Phase 1)'
);

$all_loaded = true;
foreach ($phase2_classes as $class => $description) {
    $exists = class_exists($class);
    $status = $exists ? 'success' : 'error';
    $icon = $exists ? '✅' : '❌';
    echo "<div class='{$status}'>{$icon} {$class}: " . ($exists ? 'LOADED' : 'NOT LOADED') . " - {$description}</div>";
    if (!$exists) $all_loaded = false;
}

if ($all_loaded) {
    echo "<div class='success feature-ready'>🎉 <strong>Phase 2 Admin Interface: READY</strong></div>";
} else {
    echo "<div class='error'>❌ Some Phase 2 classes not loaded. Check file inclusion.</div>";
}
echo "</div>";

// Test 2: Find orders with theater seats
echo "<div class='test-section'>";
echo "<h2>🛍️ Orders with Theater Seats</h2>";

// Get recent orders
$orders = wc_get_orders(array(
    'limit' => 20,
    'status' => array('completed', 'processing', 'on-hold')
));

$theater_orders = array();
foreach ($orders as $order) {
    if (class_exists('HOPE_Database_Selective_Refunds')) {
        $refundable_seats = HOPE_Database_Selective_Refunds::get_refundable_seats($order->get_id());
        if (!empty($refundable_seats)) {
            $theater_orders[] = array(
                'order' => $order,
                'seats' => $refundable_seats
            );
        }
    }
}

if (!empty($theater_orders)) {
    echo "<div class='success'>✅ Found " . count($theater_orders) . " orders with theater seats for testing</div>";
    
    foreach ($theater_orders as $theater_order) {
        $order = $theater_order['order'];
        $seats = $theater_order['seats'];
        
        echo "<div class='test-order'>";
        echo "<strong>Order #{$order->get_order_number()}</strong> - {$order->get_status()}<br>";
        echo "Customer: {$order->get_billing_first_name()} {$order->get_billing_last_name()}<br>";
        echo "Total: $" . number_format($order->get_total(), 2) . "<br>";
        echo "Theater Seats: " . count($seats) . " (" . implode(', ', array_column($seats, 'seat_id')) . ")<br>";
        
        // Direct link to edit order
        $edit_url = admin_url('post.php?post=' . $order->get_id() . '&action=edit');
        echo "<a href='{$edit_url}' class='button' target='_blank'>🎭 Edit Order & Test Selective Refunds</a>";
        echo "</div>";
    }
    
} else {
    echo "<div class='info'>⏳ No orders found with theater seats. Create a test order with theater seating to test the admin interface.</div>";
}
echo "</div>";

// Test 3: Admin interface availability
echo "<div class='test-section'>";
echo "<h2>🎛️ Admin Interface Status</h2>";

if (class_exists('HOPE_Admin_Selective_Refunds')) {
    echo "<div class='success'>✅ Admin interface class loaded</div>";
    
    // Check if selective refunds are available
    if (method_exists('HOPE_Selective_Refund_Handler', 'is_available') && 
        HOPE_Selective_Refund_Handler::is_available()) {
        echo "<div class='success'>✅ Selective refund functionality is available</div>";
        echo "<div class='info'>📋 The admin interface will automatically appear on WooCommerce order edit pages that contain theater seats.</div>";
    } else {
        echo "<div class='error'>❌ Selective refund functionality not available</div>";
    }
} else {
    echo "<div class='error'>❌ Admin interface class not loaded</div>";
}
echo "</div>";

// Test 4: Integration points
echo "<div class='test-section'>";
echo "<h2>🔗 Integration Check</h2>";

$integrations = array(
    'WooCommerce Meta Box Hook' => has_action('add_meta_boxes'),
    'AJAX Handler Registration' => has_action('wp_ajax_hope_process_admin_selective_refund'),
    'Admin Assets Hook' => has_action('admin_enqueue_scripts'),
    'WooCommerce Active' => class_exists('WooCommerce')
);

foreach ($integrations as $integration => $status) {
    $icon = $status ? '✅' : '❌';
    $class = $status ? 'success' : 'error';
    echo "<div class='{$class}'>{$icon} {$integration}: " . ($status ? 'ACTIVE' : 'NOT ACTIVE') . "</div>";
}
echo "</div>";

// Test 5: Security and permissions
echo "<div class='test-section'>";
echo "<h2>🔒 Security Check</h2>";

$current_user = wp_get_current_user();
$can_manage_wc = current_user_can('manage_woocommerce');
$can_edit_orders = current_user_can('edit_shop_orders');

echo "<div class='info'>Current User: {$current_user->display_name} ({$current_user->user_login})</div>";
echo "<div class='" . ($can_manage_wc ? 'success' : 'error') . "'>" . ($can_manage_wc ? '✅' : '❌') . " Can Manage WooCommerce: " . ($can_manage_wc ? 'YES' : 'NO') . "</div>";
echo "<div class='" . ($can_edit_orders ? 'success' : 'error') . "'>" . ($can_edit_orders ? '✅' : '❌') . " Can Edit Orders: " . ($can_edit_orders ? 'YES' : 'NO') . "</div>";

if ($can_manage_wc && $can_edit_orders) {
    echo "<div class='success'>✅ <strong>User has sufficient permissions for selective refunds</strong></div>";
} else {
    echo "<div class='error'>❌ User needs WooCommerce management permissions</div>";
}
echo "</div>";

// Test 6: How to test
echo "<div class='test-section'>";
echo "<h2>🧪 How to Test the Admin Interface</h2>";

echo "<div class='info'><strong>Step-by-Step Testing Instructions:</strong></div>";
echo "<ol>";
echo "<li><strong>Find an order with theater seats</strong> (see list above)</li>";
echo "<li><strong>Click 'Edit Order'</strong> to open the WooCommerce order edit page</li>";
echo "<li><strong>Look for the '🎭 Theater Seat Refunds' meta box</strong> - it should appear below the order details</li>";
echo "<li><strong>Click on seats</strong> to select them for refund (they will turn red)</li>";
echo "<li><strong>Enter a refund reason</strong> in the text area</li>";
echo "<li><strong>Click 'Process Selective Refund'</strong> to test the functionality</li>";
echo "<li><strong>Verify the refund</strong> appears in WooCommerce → Orders → Refunds</li>";
echo "<li><strong>Check that refunded seats</strong> no longer appear as selectable</li>";
echo "</ol>";

if (!empty($theater_orders)) {
    echo "<div class='success'>🎯 <strong>Ready to test!</strong> Click any 'Edit Order' button above to start testing.</div>";
}
echo "</div>";

// Test 7: Troubleshooting
echo "<div class='test-section'>";
echo "<h2>🔧 Troubleshooting</h2>";

echo "<div class='info'><strong>If the meta box doesn't appear:</strong></div>";
echo "<ul>";
echo "<li>Make sure the order contains products with theater seating enabled</li>";
echo "<li>Verify that seats were booked for this order (check bookings table)</li>";
echo "<li>Ensure all Phase 1 and Phase 2 classes are loaded (see above)</li>";
echo "<li>Check WordPress admin error logs for any PHP errors</li>";
echo "</ul>";

echo "<div class='info'><strong>If refund processing fails:</strong></div>";
echo "<ul>";
echo "<li>Check browser console for JavaScript errors</li>";
echo "<li>Verify AJAX endpoint is responding (Network tab in dev tools)</li>";
echo "<li>Ensure WordPress nonce security is working</li>";
echo "<li>Check that WooCommerce refund API is accessible</li>";
echo "</ul>";
echo "</div>";

// Summary
echo "<div style='margin-top: 30px; padding: 15px; background: #f0f8ff; border: 1px solid #0073aa; border-radius: 4px;'>";
echo "<h3>🎯 Phase 2 Admin Interface Summary</h3>";
echo "<p><strong>Goal:</strong> Provide admin interface for selective seat refunds in WooCommerce orders</p>";
echo "<p><strong>Status:</strong> " . ($all_loaded ? 'Implementation Complete ✅' : 'Setup Required ⏳') . "</p>";
echo "<p><strong>Location:</strong> WooCommerce → Orders → Edit Order (with theater seats)</p>";
echo "<p><strong>Features:</strong> Visual seat selection, real-time refund calculation, WooCommerce integration</p>";
echo "</div>";

echo "<p><a href='?' class='button'>🔄 Refresh Test</a>";
echo "<a href='test-selective-refunds.php' class='button'>📋 Run Phase 1 Tests</a></p>";
?>