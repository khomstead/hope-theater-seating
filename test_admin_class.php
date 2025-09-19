<?php
/**
 * Test if admin class can be loaded
 */

// Include WordPress
$wp_root = dirname(dirname(dirname(dirname(__FILE__))));
require_once $wp_root . '/wp-config.php';
require_once $wp_root . '/wp-load.php';

echo "=== Admin Class Test ===\n\n";

// Check if admin class exists
$admin_class_exists = class_exists('HOPE_Admin_Selective_Refunds');
echo "Admin class exists: " . ($admin_class_exists ? 'YES' : 'NO') . "\n";

if (!$admin_class_exists) {
    echo "Trying to include admin class manually...\n";
    require_once dirname(__FILE__) . '/includes/class-admin-selective-refunds.php';
    $admin_class_exists = class_exists('HOPE_Admin_Selective_Refunds');
    echo "After manual include - Admin class exists: " . ($admin_class_exists ? 'YES' : 'NO') . "\n";
}

// Check dependencies
$refund_handler_exists = class_exists('HOPE_Selective_Refund_Handler');
$db_class_exists = class_exists('HOPE_Database_Selective_Refunds');
$woocommerce_exists = class_exists('WooCommerce');

echo "HOPE_Selective_Refund_Handler exists: " . ($refund_handler_exists ? 'YES' : 'NO') . "\n";
echo "HOPE_Database_Selective_Refunds exists: " . ($db_class_exists ? 'YES' : 'NO') . "\n";
echo "WooCommerce exists: " . ($woocommerce_exists ? 'YES' : 'NO') . "\n";

if ($refund_handler_exists) {
    $handler_available = HOPE_Selective_Refund_Handler::is_available();
    echo "Selective refund handler available: " . ($handler_available ? 'YES' : 'NO') . "\n";
}

if ($db_class_exists) {
    $db_ready = HOPE_Database_Selective_Refunds::is_selective_refund_ready();
    echo "Database ready: " . ($db_ready ? 'YES' : 'NO') . "\n";
}

// Try to instantiate admin class
if ($admin_class_exists) {
    echo "\nTrying to instantiate admin class...\n";
    try {
        $admin = new HOPE_Admin_Selective_Refunds();
        echo "✅ Admin class instantiated successfully\n";
    } catch (Exception $e) {
        echo "❌ Error instantiating admin class: " . $e->getMessage() . "\n";
    } catch (Error $e) {
        echo "❌ Fatal error instantiating admin class: " . $e->getMessage() . "\n";
    }
}
?>