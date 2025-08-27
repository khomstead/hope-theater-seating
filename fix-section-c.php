<?php
/**
 * Fix Section C pricing tiers
 */

// Load WordPress
require_once('../../../../wp-config.php');

global $wpdb;

echo "=== SECTION C PRICING TIER FIX ===\n";

// Check current Section C assignments
echo "Current Section C assignments:\n";
$seats = $wpdb->get_results("
    SELECT ps.seat_id, ps.section, ps.row_number, ps.seat_number, sp.pricing_tier 
    FROM {$wpdb->prefix}hope_seating_physical_seats ps
    JOIN {$wpdb->prefix}hope_seating_seat_pricing sp ON ps.id = sp.physical_seat_id
    WHERE sp.pricing_map_id = 1 AND ps.section = 'C'
    ORDER BY CAST(ps.row_number AS UNSIGNED), CAST(ps.seat_number AS UNSIGNED)
");

$tier_counts = array();
foreach ($seats as $seat) {
    $tier = $seat->pricing_tier;
    $tier_counts[$tier] = isset($tier_counts[$tier]) ? $tier_counts[$tier] + 1 : 1;
    
    // Show first few seats of each row
    if ($seat->seat_number <= 3) {
        echo "Row {$seat->row_number}, Seat {$seat->seat_number}: {$seat->pricing_tier}\n";
    }
}

echo "Section C tier counts: " . json_encode($tier_counts) . "\n";

// Fix Section C: Rows 1-3 should be P1, Rows 4+ should be P2
echo "\n=== FIXING SECTION C ===\n";

// Update rows 4+ to P2
$result = $wpdb->query($wpdb->prepare("
    UPDATE {$wpdb->prefix}hope_seating_seat_pricing sp
    JOIN {$wpdb->prefix}hope_seating_physical_seats ps ON sp.physical_seat_id = ps.id
    SET sp.pricing_tier = 'P2'
    WHERE sp.pricing_map_id = %d 
    AND ps.section = 'C' 
    AND CAST(ps.row_number AS UNSIGNED) >= 4
", 1));

echo "Updated {$result} seats in Section C rows 4+ to P2\n";

// Verify the fix
echo "\nAfter fix - Section C assignments:\n";
$seats_after = $wpdb->get_results("
    SELECT ps.seat_id, ps.section, ps.row_number, ps.seat_number, sp.pricing_tier 
    FROM {$wpdb->prefix}hope_seating_physical_seats ps
    JOIN {$wpdb->prefix}hope_seating_seat_pricing sp ON ps.id = sp.physical_seat_id
    WHERE sp.pricing_map_id = 1 AND ps.section = 'C'
    ORDER BY CAST(ps.row_number AS UNSIGNED), CAST(ps.seat_number AS UNSIGNED)
");

$tier_counts_after = array();
foreach ($seats_after as $seat) {
    $tier = $seat->pricing_tier;
    $tier_counts_after[$tier] = isset($tier_counts_after[$tier]) ? $tier_counts_after[$tier] + 1 : 1;
}

echo "Section C tier counts after fix: " . json_encode($tier_counts_after) . "\n";

echo "\n=== TOTAL DATABASE TIER COUNTS ===\n";
$total_counts = $wpdb->get_results("
    SELECT sp.pricing_tier, COUNT(*) as count
    FROM {$wpdb->prefix}hope_seating_seat_pricing sp
    WHERE sp.pricing_map_id = 1
    GROUP BY sp.pricing_tier
    ORDER BY sp.pricing_tier
");

foreach ($total_counts as $count) {
    echo "{$count->pricing_tier}: {$count->count}\n";
}

echo "\nSection C fix completed!\n";
?>