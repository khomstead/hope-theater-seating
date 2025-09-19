<?php
/**
 * Repair corrupted seat data where status='refunded' but no actual refund occurred
 * Access via: /wp-content/plugins/hope-theater-seating/repair_corrupted_seats.php?order_id=2314
 */

// Include WordPress
$wp_root = dirname(dirname(dirname(dirname(__FILE__))));
require_once $wp_root . '/wp-config.php';
require_once $wp_root . '/wp-load.php';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Repair Corrupted Seats</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f1f1f1; }
        .container { background: white; padding: 20px; border-radius: 5px; max-width: 800px; }
        .error { color: #d63638; }
        .success { color: #00a32a; }
        .warning { color: #dba617; }
    </style>
</head>
<body>
<div class="container">
<h1>🔧 Repair Corrupted Seat Data</h1>

<?php
$order_id = $_GET['order_id'] ?? null;
$confirm = $_GET['confirm'] ?? false;

if (!$order_id) {
    echo '<p>Please provide an order ID: <code>?order_id=123</code></p>';
    exit;
}

echo "<h2>Order: {$order_id}</h2>";

global $wpdb;
$bookings_table = $wpdb->prefix . 'hope_seating_bookings';

// Find corrupted seats (status='refunded' but refund_id=NULL)
$corrupted_seats = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$bookings_table} 
    WHERE order_id = %d 
    AND status = 'refunded' 
    AND refund_id IS NULL",
    $order_id
), ARRAY_A);

if (empty($corrupted_seats)) {
    echo '<p class="success">✅ No corrupted seats found for this order</p>';
    exit;
}

echo '<p class="warning">⚠️ Found ' . count($corrupted_seats) . ' corrupted seats:</p>';
echo '<ul>';
foreach ($corrupted_seats as $seat) {
    echo '<li>Seat ' . $seat['seat_id'] . ' - Status: refunded, but refund_id: NULL</li>';
}
echo '</ul>';

if (!$confirm) {
    echo '<p><strong>These seats will be restored to "confirmed" status.</strong></p>';
    echo '<p><a href="?order_id=' . $order_id . '&confirm=1" style="background: #007cba; color: white; padding: 10px 15px; text-decoration: none; border-radius: 3px;">✅ Confirm Repair</a></p>';
    echo '<p><em>This will make the seats refundable again and restore the meta box.</em></p>';
} else {
    // Perform the repair
    $updated = $wpdb->update(
        $bookings_table,
        array(
            'status' => 'confirmed',
            'refunded_amount' => null,
            'refund_reason' => null,
            'refund_date' => null
        ),
        array(
            'order_id' => $order_id,
            'status' => 'refunded',
            'refund_id' => null
        ),
        array('%s', '%s', '%s', '%s'),
        array('%d', '%s', '%s')
    );
    
    if ($updated) {
        echo '<p class="success">✅ Successfully repaired ' . $updated . ' seat records</p>';
        echo '<p>The meta box should now appear on the order page again.</p>';
        echo '<p><a href="?order_id=' . $order_id . '">Check Status</a></p>';
    } else {
        echo '<p class="error">❌ Failed to update seat records</p>';
        echo '<p>Error: ' . $wpdb->last_error . '</p>';
    }
}
?>

</div>
</body>
</html>