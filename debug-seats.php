<?php
/**
 * Debug script to test seat pricing queries
 */

// Load WordPress
require_once('../../../../wp-config.php');

if (!class_exists('HOPE_Pricing_Maps_Manager')) {
    echo "HOPE_Pricing_Maps_Manager not found\n";
    exit;
}

$pricing_manager = new HOPE_Pricing_Maps_Manager();

// Get all pricing maps
echo "=== PRICING MAPS ===\n";
$pricing_maps = $pricing_manager->get_pricing_maps();
foreach ($pricing_maps as $map) {
    echo "Map ID {$map->id}: {$map->name}\n";
    
    $seats = $pricing_manager->get_seats_with_pricing($map->id);
    echo "Total seats: " . count($seats) . "\n";
    
    // Count by tier
    $tier_counts = array();
    foreach ($seats as $seat) {
        $tier = $seat->pricing_tier;
        $tier_counts[$tier] = isset($tier_counts[$tier]) ? $tier_counts[$tier] + 1 : 1;
    }
    echo "Tier counts: " . json_encode($tier_counts) . "\n";
    
    // Show first few Section C seats
    echo "First 5 Section C seats:\n";
    $c_seats = 0;
    foreach ($seats as $seat) {
        if ($seat->section === 'C' && $c_seats < 5) {
            echo "  {$seat->seat_id}: Row {$seat->row_number}, Seat {$seat->seat_number}, Tier {$seat->pricing_tier}\n";
            $c_seats++;
        }
    }
    echo "\n";
}

// Check if products are using pricing maps
echo "=== PRODUCT PRICING MAP ASSIGNMENTS ===\n";
global $wpdb;
$products = $wpdb->get_results("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_hope_pricing_map_id'");
foreach ($products as $product) {
    $post_title = get_the_title($product->post_id);
    echo "Product {$product->post_id} ({$post_title}): Uses Pricing Map {$product->meta_value}\n";
}
?>