<?php
/**
 * Debug seats status for specific orders
 */

// Include WordPress
$wp_root = dirname(dirname(dirname(dirname(__FILE__))));
require_once $wp_root . '/wp-config.php';
require_once $wp_root . '/wp-load.php';

echo "=== Order Seats Debug ===\n\n";

// Check if database class exists
if (!class_exists('HOPE_Database_Selective_Refunds')) {
    echo "❌ HOPE_Database_Selective_Refunds class not found\n";
    exit;
}

// Get order ID from command line or prompt
$order_id = $argv[1] ?? null;
if (!$order_id) {
    echo "Usage: php debug_order_seats.php [ORDER_ID]\n";
    echo "Please provide an order ID to debug\n";
    exit;
}

echo "Debugging order: {$order_id}\n\n";

// Check if order exists
$order = wc_get_order($order_id);
if (!$order) {
    echo "❌ Order {$order_id} not found\n";
    exit;
}

echo "Order found: {$order->get_order_number()} - {$order->get_status()}\n";
echo "Order total: $" . $order->get_total() . "\n\n";

// Get ALL seats for this order (not just refundable)
global $wpdb;
$bookings_table = $wpdb->prefix . 'hope_seating_bookings';

$all_seats = $wpdb->get_results($wpdb->prepare(
    "SELECT id, seat_id, product_id, order_item_id, status, created_at,
            refund_id, refunded_amount, refund_reason, refund_date
    FROM {$bookings_table}
    WHERE order_id = %d 
    ORDER BY seat_id",
    $order_id
), ARRAY_A);

echo "=== ALL SEATS FOR THIS ORDER ===\n";
if (empty($all_seats)) {
    echo "❌ NO SEATS FOUND - This explains why meta box doesn't show!\n";
} else {
    foreach ($all_seats as $seat) {
        echo "Seat: {$seat['seat_id']}\n";
        echo "  Status: {$seat['status']}\n";
        echo "  Refund ID: " . ($seat['refund_id'] ?: 'NULL') . "\n";
        echo "  Refunded Amount: " . ($seat['refunded_amount'] ?: '0.00') . "\n";
        echo "  Refund Reason: " . ($seat['refund_reason'] ?: 'NULL') . "\n";
        echo "  Refund Date: " . ($seat['refund_date'] ?: 'NULL') . "\n";
        echo "\n";
    }
}

// Get refundable seats
$refundable_seats = HOPE_Database_Selective_Refunds::get_refundable_seats($order_id);
echo "=== REFUNDABLE SEATS ===\n";
if (empty($refundable_seats)) {
    echo "❌ NO REFUNDABLE SEATS - This is why meta box doesn't show!\n";
    
    if (!empty($all_seats)) {
        echo "\nReasons seats might not be refundable:\n";
        foreach ($all_seats as $seat) {
            if ($seat['status'] === 'refunded') {
                echo "- Seat {$seat['seat_id']}: Status is 'refunded'\n";
            } elseif ($seat['refund_id'] && $seat['status'] === 'refunded') {
                echo "- Seat {$seat['seat_id']}: Has refund_id AND status is 'refunded'\n";
            } elseif (!in_array($seat['status'], ['confirmed', 'partially_refunded'])) {
                echo "- Seat {$seat['seat_id']}: Status '{$seat['status']}' not in allowed list\n";
            }
        }
    }
} else {
    echo "✅ Found " . count($refundable_seats) . " refundable seats:\n";
    foreach ($refundable_seats as $seat) {
        echo "- {$seat['seat_id']} (Status: {$seat['status']})\n";
    }
}

echo "\n=== META BOX VISIBILITY ===\n";
$has_theater_seats = !empty($refundable_seats);
echo "Meta box should show: " . ($has_theater_seats ? 'YES ✅' : 'NO ❌') . "\n";
?>