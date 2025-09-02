<?php
/**
 * Debug script specifically for product 2154 pricing issue
 */

if (!defined('ABSPATH')) {
    require_once(__DIR__ . '/../../../wp-config.php');
}

function debug_product_2154() {
    global $wpdb;
    
    $product_id = 2154;
    echo "=== PRODUCT 2154 SPECIFIC DIAGNOSTIC ===\n\n";
    
    // Test the exact method that's failing
    if (class_exists('HOPE_WooCommerce_Integration')) {
        $woo_integration = new HOPE_WooCommerce_Integration();
        
        // Simulate seat selection from different tiers
        echo "=== TESTING SEAT SELECTION SCENARIOS ===\n";
        
        // Get some actual seat IDs from different tiers for testing
        $test_seats_by_tier = $wpdb->get_results($wpdb->prepare("
            SELECT sp.pricing_tier, ps.id as seat_id, ps.section, ps.row_number, ps.seat_number
            FROM wp_hope_seating_seat_pricing sp
            INNER JOIN wp_hope_seating_physical_seats ps ON sp.physical_seat_id = ps.id
            WHERE sp.pricing_map_id = %d
            AND sp.pricing_tier IN ('P1', 'P2', 'P3')
            GROUP BY sp.pricing_tier
            LIMIT 3
        ", 8));
        
        if (!empty($test_seats_by_tier)) {
            $test_seat_ids = array();
            echo "Test seats selected:\n";
            foreach ($test_seats_by_tier as $seat) {
                $test_seat_ids[] = $seat->seat_id;
                echo "  Seat {$seat->seat_id}: {$seat->section}-{$seat->row_number}-{$seat->seat_number} (Tier: {$seat->pricing_tier})\n";
            }
            
            echo "\nTesting get_variation_for_seats method...\n";
            
            // Test the actual method (we'll capture output)
            ob_start();
            
            try {
                // Simulate the AJAX call
                $_POST = array(
                    'nonce' => wp_create_nonce('hope_seating_nonce'),
                    'product_id' => $product_id,
                    'selected_seats' => $test_seat_ids
                );
                
                $woo_integration->get_variation_for_seats();
                
            } catch (Exception $e) {
                echo "Method execution error: " . $e->getMessage() . "\n";
            }
            
            $output = ob_get_clean();
            echo "Method output: $output\n";
            
        } else {
            echo "❌ Could not find test seats\n";
        }
    } else {
        echo "❌ HOPE_WooCommerce_Integration class not found\n";
    }
    
    // Check JavaScript loading for this product
    echo "\n=== JAVASCRIPT DATA LOADING ===\n";
    echo "Check browser console for these messages when loading product 2154:\n";
    echo "- 'HOPE: Loaded variation pricing: [object]'\n";
    echo "- 'HOPE: ✅ Real seat data loaded'\n";
    echo "- Any JavaScript errors related to pricing\n";
    
    echo "\n=== BROWSER CACHE TEST ===\n";
    echo "1. Open product 2154 in an incognito/private browser window\n";
    echo "2. Test seat selection from different tiers\n";
    echo "3. Check if the issue persists without cache\n";
    
    // Check for any product-specific overrides
    echo "\n=== PRODUCT-SPECIFIC SETTINGS ===\n";
    
    $product_meta = $wpdb->get_results($wpdb->prepare("
        SELECT meta_key, meta_value 
        FROM wp_postmeta 
        WHERE post_id = %d 
        AND meta_key LIKE '%hope%'
        ORDER BY meta_key
    ", $product_id));
    
    if (!empty($product_meta)) {
        echo "Product 2154 HOPE-related metadata:\n";
        foreach ($product_meta as $meta) {
            echo "  {$meta->meta_key}: {$meta->meta_value}\n";
        }
    } else {
        echo "No HOPE-specific metadata found for product 2154\n";
    }
}

// Test the server-side tier selection logic
function test_tier_selection_for_2154() {
    $product_id = 2154;
    $pricing_map_id = get_post_meta($product_id, '_hope_seating_venue_id', true);
    echo "Product 2154 pricing map: {$pricing_map_id}\n\n";
    
    if (class_exists('HOPE_Pricing_Maps_Manager')) {
        $pricing_manager = new HOPE_Pricing_Maps_Manager();
        $seats = $pricing_manager->get_seats_with_pricing($pricing_map_id);
        
        $tier_counts = array();
        foreach ($seats as $seat) {
            $tier_counts[$seat->pricing_tier] = ($tier_counts[$seat->pricing_tier] ?? 0) + 1;
        }
        
        echo "Tier distribution: " . print_r($tier_counts, true) . "\n";
        echo "Array keys order: " . implode(', ', array_keys($tier_counts)) . "\n";
        echo "Primary tier selected by buggy logic: " . (array_keys($tier_counts)[0] ?? 'None') . "\n\n";
        
        // This is the EXACT logic from the buggy code
        $primary_tier = array_keys($tier_counts)[0] ?? 'P1';
        echo "⚠️ BUG RESULT: All seats will use tier '{$primary_tier}' regardless of actual tier\n";
        
    } else {
        echo "❌ HOPE_Pricing_Maps_Manager not found\n";
    }
}

// Auto-run if executed directly
if (!defined('ABSPATH')) {
    debug_product_2154();
    echo "\n" . str_repeat("=", 50) . "\n";
    test_tier_selection_for_2154();
}
?>