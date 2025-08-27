<?php
/**
 * Repair Script: Fix duplicate seat display in existing tickets
 * 
 * This script identifies tickets with duplicate seat assignments and corrects them
 * using the actual seat data from the bookings table.
 * 
 * Usage: Run this script once from WordPress admin or via WP-CLI
 */

if (!defined('ABSPATH')) {
    // If running standalone, load WordPress
    require_once(__DIR__ . '/../../../wp-config.php');
}

function repair_ticket_seat_assignments($order_id = null, $dry_run = true) {
    global $wpdb;
    
    echo "=== TICKET SEAT REPAIR SCRIPT ===\n";
    echo "Mode: " . ($dry_run ? "DRY RUN" : "LIVE UPDATE") . "\n";
    echo "Order filter: " . ($order_id ? "Order #{$order_id}" : "All orders") . "\n\n";
    
    // Get orders with multiple tickets for the same product
    $order_condition = $order_id ? "AND o.order_id = {$order_id}" : "";
    
    $orders_with_multiple_tickets = $wpdb->get_results("
        SELECT 
            o.order_id,
            o.product_id,
            COUNT(*) as ticket_count,
            GROUP_CONCAT(DISTINCT p.ID) as ticket_post_ids,
            GROUP_CONCAT(DISTINCT o.order_item_id) as order_item_ids
        FROM {$wpdb->prefix}hope_seating_bookings o
        INNER JOIN {$wpdb->posts} p ON p.post_excerpt = o.order_item_id 
        WHERE p.post_type = 'event_magic_tickets'
        {$order_condition}
        GROUP BY o.order_id, o.product_id
        HAVING ticket_count > 1
        ORDER BY o.order_id DESC
    ");
    
    if (empty($orders_with_multiple_tickets)) {
        echo "No orders found with multiple tickets needing repair.\n";
        return;
    }
    
    echo "Found " . count($orders_with_multiple_tickets) . " orders with multiple tickets:\n\n";
    
    $repaired_count = 0;
    
    foreach ($orders_with_multiple_tickets as $order_group) {
        echo "Processing Order #{$order_group->order_id} - Product {$order_group->product_id} ({$order_group->ticket_count} tickets)\n";
        
        // Get individual seat assignments for this order/product combination
        $seat_bookings = $wpdb->get_results($wpdb->prepare("
            SELECT 
                seat_id,
                order_item_id,
                row_name,
                seat_number
            FROM {$wpdb->prefix}hope_seating_bookings b
            INNER JOIN {$wpdb->prefix}hope_seating_seats s ON b.seat_id = s.id
            WHERE b.order_id = %d AND b.product_id = %d
            ORDER BY b.order_item_id
        ", $order_group->order_id, $order_group->product_id));
        
        if (empty($seat_bookings)) {
            echo "  ⚠️  No seat bookings found in database - skipping\n";
            continue;
        }
        
        // Map each order item to its correct seat
        foreach ($seat_bookings as $booking) {
            // Find the ticket post for this order item
            $ticket_post = $wpdb->get_row($wpdb->prepare("
                SELECT ID, post_title 
                FROM {$wpdb->posts} 
                WHERE post_type = 'event_magic_tickets' 
                AND post_excerpt = %s
            ", $booking->order_item_id));
            
            if (!$ticket_post) {
                echo "  ⚠️  No ticket post found for order item {$booking->order_item_id} - skipping\n";
                continue;
            }
            
            // Check current seat assignment
            $current_row = get_post_meta($ticket_post->ID, 'fooevents_seat_row_name_0', true);
            $current_seat = get_post_meta($ticket_post->ID, 'fooevents_seat_number_0', true);
            
            echo "  Ticket {$ticket_post->ID} (Item {$booking->order_item_id}): ";
            echo "Current: {$current_row}-{$current_seat} → Correct: {$booking->row_name}-{$booking->seat_number}";
            
            if ($current_row == $booking->row_name && $current_seat == $booking->seat_number) {
                echo " ✅ Already correct\n";
                continue;
            }
            
            if (!$dry_run) {
                // Update the seat metadata
                update_post_meta($ticket_post->ID, 'fooevents_seat_row_name_0', $booking->row_name);
                update_post_meta($ticket_post->ID, 'fooevents_seat_number_0', $booking->seat_number);
                
                // Update the seating fields array
                $seating_fields = array(
                    'fooevents_seat_row_name_0' => $booking->row_name,
                    'fooevents_seat_number_0' => $booking->seat_number
                );
                update_post_meta($ticket_post->ID, 'WooCommerceEventsSeatingFields', $seating_fields);
                
                echo " ✅ UPDATED";
                $repaired_count++;
            } else {
                echo " 🔄 Would update";
            }
            echo "\n";
        }
        
        echo "\n";
    }
    
    if ($dry_run) {
        echo "=== DRY RUN COMPLETE ===\n";
        echo "Run with dry_run=false to apply changes.\n";
    } else {
        echo "=== REPAIR COMPLETE ===\n";
        echo "Repaired {$repaired_count} tickets.\n";
        echo "You can now resend ticket emails with correct seat assignments.\n";
    }
}

// Usage examples:
// repair_ticket_seat_assignments(null, true);  // Dry run all orders
// repair_ticket_seat_assignments(2323, true);  // Dry run specific order
// repair_ticket_seat_assignments(null, false); // Live update all orders

// Auto-run if executed directly (dry run mode for safety)
if (!defined('ABSPATH') || (defined('WP_CLI') && WP_CLI)) {
    repair_ticket_seat_assignments(null, true);
}
?>