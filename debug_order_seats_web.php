<?php
/**
 * Debug seats status for specific orders - Web version
 * Access via: /wp-content/plugins/hope-theater-seating/debug_order_seats_web.php?order_id=123
 */

// Include WordPress
$wp_root = dirname(dirname(dirname(dirname(__FILE__))));
require_once $wp_root . '/wp-config.php';
require_once $wp_root . '/wp-load.php';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Order Seats Debug</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f1f1f1; }
        .container { background: white; padding: 20px; border-radius: 5px; max-width: 800px; }
        .error { color: #d63638; }
        .success { color: #00a32a; }
        .warning { color: #dba617; }
        pre { background: #f6f7f7; padding: 10px; border-radius: 3px; }
    </style>
</head>
<body>
<div class="container">
<h1>🎭 Order Seats Debug</h1>

<?php
$order_id = $_GET['order_id'] ?? null;

if (!$order_id) {
    echo '<p>Please provide an order ID: <code>?order_id=123</code></p>';
    echo '<form method="get">';
    echo '<label>Order ID: <input type="number" name="order_id" placeholder="Enter order ID" /></label>';
    echo '<button type="submit">Debug Order</button>';
    echo '</form>';
    exit;
}

echo "<h2>Debugging Order: {$order_id}</h2>";

// Check if database class exists
if (!class_exists('HOPE_Database_Selective_Refunds')) {
    echo '<p class="error">❌ HOPE_Database_Selective_Refunds class not found</p>';
    exit;
}

// Check if order exists
$order = wc_get_order($order_id);
if (!$order) {
    echo '<p class="error">❌ Order ' . $order_id . ' not found</p>';
    exit;
}

echo '<p><strong>Order found:</strong> ' . $order->get_order_number() . ' - ' . $order->get_status() . '</p>';
echo '<p><strong>Order total:</strong> $' . $order->get_total() . '</p>';

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

echo "<h3>All Seats for This Order</h3>";
if (empty($all_seats)) {
    echo '<p class="error">❌ NO SEATS FOUND - This explains why meta box doesn\'t show!</p>';
} else {
    echo '<table border="1" cellpadding="5" style="border-collapse: collapse; width: 100%;">';
    echo '<tr><th>Seat ID</th><th>Status</th><th>Refund ID</th><th>Refunded Amount</th><th>Refund Reason</th><th>Refund Date</th></tr>';
    foreach ($all_seats as $seat) {
        echo '<tr>';
        echo '<td>' . $seat['seat_id'] . '</td>';
        echo '<td>' . $seat['status'] . '</td>';
        echo '<td>' . ($seat['refund_id'] ?: 'NULL') . '</td>';
        echo '<td>' . ($seat['refunded_amount'] ?: '0.00') . '</td>';
        echo '<td>' . ($seat['refund_reason'] ?: 'NULL') . '</td>';
        echo '<td>' . ($seat['refund_date'] ?: 'NULL') . '</td>';
        echo '</tr>';
    }
    echo '</table>';
}

// Get refundable seats
$refundable_seats = HOPE_Database_Selective_Refunds::get_refundable_seats($order_id);
echo "<h3>Refundable Seats</h3>";
if (empty($refundable_seats)) {
    echo '<p class="error">❌ NO REFUNDABLE SEATS - This is why meta box doesn\'t show!</p>';
    
    if (!empty($all_seats)) {
        echo "<h4>Why seats are not refundable:</h4>";
        echo "<ul>";
        foreach ($all_seats as $seat) {
            if ($seat['status'] === 'refunded') {
                echo "<li>Seat {$seat['seat_id']}: Status is 'refunded'</li>";
            } elseif ($seat['refund_id'] && $seat['status'] === 'refunded') {
                echo "<li>Seat {$seat['seat_id']}: Has refund_id AND status is 'refunded'</li>";
            } elseif (!in_array($seat['status'], ['confirmed', 'partially_refunded'])) {
                echo "<li>Seat {$seat['seat_id']}: Status '{$seat['status']}' not in allowed list (confirmed, partially_refunded)</li>";
            }
        }
        echo "</ul>";
    }
} else {
    echo '<p class="success">✅ Found ' . count($refundable_seats) . ' refundable seats:</p>';
    echo '<ul>';
    foreach ($refundable_seats as $seat) {
        echo '<li>' . $seat['seat_id'] . ' (Status: ' . $seat['status'] . ')</li>';
    }
    echo '</ul>';
}

echo "<h3>Meta Box Visibility</h3>";
$has_theater_seats = !empty($refundable_seats);
if ($has_theater_seats) {
    echo '<p class="success">Meta box should show: YES ✅</p>';
} else {
    echo '<p class="error">Meta box should show: NO ❌</p>';
}
?>

<hr>
<p><a href="?order_id=<?php echo $order_id; ?>">Refresh</a> | <a href="?">Debug Another Order</a></p>
</div>
</body>
</html>