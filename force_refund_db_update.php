<?php
/**
 * Force database update for selective refunds
 * Run this if the refund meta box is missing
 */

// Include WordPress
$wp_root = dirname(dirname(dirname(dirname(__FILE__))));
require_once $wp_root . '/wp-config.php';
require_once $wp_root . '/wp-load.php';

if (!defined('ABSPATH')) {
    die("Error: WordPress not loaded\n");
}

echo "=== HOPE Selective Refunds Database Update ===\n\n";

// Include the database class
require_once dirname(__FILE__) . '/includes/class-database-selective-refunds.php';

// Force the database update
echo "Forcing selective refund database support...\n";
HOPE_Database_Selective_Refunds::add_selective_refund_support();

// Check status
echo "\nChecking refund readiness...\n";
$ready = HOPE_Database_Selective_Refunds::is_selective_refund_ready();
echo "Selective refunds ready: " . ($ready ? 'YES' : 'NO') . "\n";

if ($ready) {
    echo "\n✅ SUCCESS: Refund meta box should now appear on order pages!\n";
} else {
    echo "\n❌ ISSUE: Check the debug log for details about what's missing.\n";
}

echo "\nYou can now delete this file and reload the order page.\n";
?>