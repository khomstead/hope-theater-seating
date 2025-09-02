<?php
/**
 * Debug script to investigate pricing tier mapping issues
 */

if (!defined('ABSPATH')) {
    require_once(__DIR__ . '/../../../wp-config.php');
}

function debug_pricing_mapping($product_id = null) {
    global $wpdb;
    
    echo "=== PRICING TIER MAPPING DIAGNOSTIC ===\n\n";
    
    if (!$product_id) {
        // List available event products
        $products = $wpdb->get_results("
            SELECT p.ID, p.post_title, pm.meta_value as venue_id
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_hope_seating_venue_id'
            WHERE p.post_type = 'product' 
            AND p.post_status = 'publish'
            ORDER BY p.ID DESC
            LIMIT 10
        ");
        
        echo "Available event products:\n";
        foreach ($products as $product) {
            $venue_text = $product->venue_id ? "Venue/Map ID: {$product->venue_id}" : "No venue assigned";
            echo "  Product {$product->ID}: {$product->post_title} ({$venue_text})\n";
        }
        echo "\nRun with specific product ID to debug: debug_pricing_mapping(PRODUCT_ID)\n";
        return;
    }
    
    echo "Debugging Product ID: {$product_id}\n\n";
    
    // Get product details
    $product = wc_get_product($product_id);
    if (!$product) {
        echo "❌ Product not found\n";
        return;
    }
    
    echo "Product: {$product->get_name()}\n";
    echo "Type: {$product->get_type()}\n";
    
    // Get pricing map ID
    $pricing_map_id = get_post_meta($product_id, '_hope_seating_venue_id', true);
    echo "Pricing Map ID: " . ($pricing_map_id ?: 'Not assigned') . "\n\n";
    
    if (!$pricing_map_id) {
        echo "❌ No pricing map assigned to this product\n";
        return;
    }
    
    // Check if new architecture classes exist
    if (!class_exists('HOPE_Pricing_Maps_Manager')) {
        echo "❌ HOPE_Pricing_Maps_Manager class not found\n";
        return;
    }
    
    // Get seat pricing data
    $pricing_manager = new HOPE_Pricing_Maps_Manager();
    $seats_with_pricing = $pricing_manager->get_seats_with_pricing($pricing_map_id);
    
    echo "=== SEAT PRICING DATA ===\n";
    echo "Total seats with pricing: " . count($seats_with_pricing) . "\n\n";
    
    // Group by pricing tier
    $tier_counts = array();
    $sample_seats = array();
    
    foreach ($seats_with_pricing as $seat) {
        $tier = $seat->pricing_tier;
        if (!isset($tier_counts[$tier])) {
            $tier_counts[$tier] = 0;
            $sample_seats[$tier] = array();
        }
        $tier_counts[$tier]++;
        
        // Keep sample seats for each tier
        if (count($sample_seats[$tier]) < 3) {
            $sample_seats[$tier][] = $seat;
        }
    }
    
    echo "Pricing tier distribution:\n";
    foreach ($tier_counts as $tier => $count) {
        echo "  {$tier}: {$count} seats\n";
        
        // Show sample seats
        echo "    Sample seats: ";
        foreach ($sample_seats[$tier] as $sample) {
            echo "{$sample->seat_id}({$sample->section}-{$sample->row_number}-{$sample->seat_number}) ";
        }
        echo "\n";
    }
    
    echo "\n=== PRODUCT VARIATIONS ===\n";
    
    if ($product->is_type('variable')) {
        $variations = $product->get_available_variations();
        echo "Total variations: " . count($variations) . "\n\n";
        
        foreach ($variations as $variation) {
            $attributes = $variation['attributes'];
            $tier_attr = $attributes['attribute_seating-tier'] ?? 'Unknown';
            
            echo "Variation {$variation['variation_id']}: {$tier_attr}\n";
            echo "  Price: \${$variation['display_price']}\n";
            echo "  Attributes: " . print_r($attributes, true) . "\n";
        }
    } else {
        echo "❌ Product is not a variable product\n";
    }
    
    echo "\n=== POTENTIAL ISSUES ===\n";
    
    // Check if all pricing tiers have matching variations
    $variation_tiers = array();
    if ($product->is_type('variable')) {
        foreach ($product->get_available_variations() as $variation) {
            $tier = $variation['attributes']['attribute_seating-tier'] ?? null;
            if ($tier) {
                $variation_tiers[$tier] = $variation['variation_id'];
            }
        }
    }
    
    $missing_variations = array();
    foreach ($tier_counts as $tier => $count) {
        if (!isset($variation_tiers[$tier])) {
            $missing_variations[] = $tier;
        }
    }
    
    if (!empty($missing_variations)) {
        echo "❌ Missing variations for tiers: " . implode(', ', $missing_variations) . "\n";
        echo "   These seats will likely default to P3 pricing\n";
    }
    
    $extra_variations = array();
    foreach ($variation_tiers as $tier => $var_id) {
        if (!isset($tier_counts[$tier])) {
            $extra_variations[] = $tier;
        }
    }
    
    if (!empty($extra_variations)) {
        echo "⚠️  Extra variations for tiers: " . implode(', ', $extra_variations) . "\n";
        echo "   These variations exist but have no seats assigned\n";
    }
    
    if (empty($missing_variations) && empty($extra_variations)) {
        echo "✅ All pricing tiers have matching variations\n";
    }
    
    echo "\n=== ARRAY_KEYS ORDER TEST ===\n";
    echo "Current seat tier order (array_keys result): " . implode(', ', array_keys($tier_counts)) . "\n";
    echo "Primary tier selected by current logic: " . (array_keys($tier_counts)[0] ?? 'None') . "\n";
    echo "⚠️  This is why seats might default to wrong pricing!\n";
}

// Auto-run if executed directly - replace PRODUCT_ID with actual ID
if (!defined('ABSPATH')) {
    debug_pricing_mapping(); // Shows available products
}
?>