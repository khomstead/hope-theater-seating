<?php
/**
 * Debug current product seating configuration
 */

// Include WordPress
$wp_root = dirname(dirname(dirname(dirname(__FILE__))));
require_once $wp_root . '/wp-config.php';
require_once $wp_root . '/wp-load.php';

echo "=== Product Seating Configuration Debug ===\n\n";

// Get all products
$products = get_posts(array(
    'post_type' => 'product',
    'posts_per_page' => -1,
    'post_status' => 'publish'
));

foreach ($products as $product) {
    echo "Product: {$product->post_title} (ID: {$product->ID})\n";
    
    // Check all the meta fields that affect seating
    $seating_enabled = get_post_meta($product->ID, '_hope_seating_enabled', true);
    $venue_id = get_post_meta($product->ID, '_hope_seating_venue_id', true);
    
    echo "  _hope_seating_enabled: " . ($seating_enabled ?: 'NOT SET') . "\n";
    echo "  _hope_seating_venue_id: " . ($venue_id ?: 'NOT SET') . "\n";
    
    // Check if both conditions are met for seat selection to show
    if ($seating_enabled === 'yes' && !empty($venue_id)) {
        echo "  ✅ SEAT SELECTION SHOULD SHOW\n";
    } else {
        echo "  ❌ SEAT SELECTION WILL NOT SHOW\n";
        if ($seating_enabled !== 'yes') {
            echo "     - Need to check 'Enable Seat Selection' checkbox\n";
        }
        if (empty($venue_id)) {
            echo "     - Need to select a seat map\n";
        }
    }
    
    echo "\n";
}

// Also check if scripts are being loaded
echo "=== Frontend Script Loading Conditions ===\n";
echo "The frontend class loads scripts when:\n";
echo "1. is_product() is true\n";
echo "2. Product has _hope_seating_enabled = 'yes'\n";
echo "3. Either shortcode is present OR admin area\n\n";

echo "The seat selection button shows when:\n";
echo "1. _hope_seating_enabled = 'yes'\n";
echo "2. _hope_seating_venue_id is set (not empty)\n\n";

echo "If you're still seeing 'Add to Cart' instead of seat selection:\n";
echo "1. Make sure 'Enable Seat Selection' checkbox is checked in product\n";
echo "2. Make sure a seat map is selected in dropdown\n";
echo "3. Save the product\n";
echo "4. Clear any caching (browser, WordPress cache, etc.)\n";
?>