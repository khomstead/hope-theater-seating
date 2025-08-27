<?php
/**
 * WP-CLI Command for repairing ticket seat assignments
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_CLI')) {
    return;
}

/**
 * Repair ticket seat assignments
 */
class Hope_Repair_Command extends WP_CLI_Command {
    
    /**
     * Repair ticket seat assignments that show duplicate seats
     *
     * ## OPTIONS
     *
     * [--order-id=<order-id>]
     * : Specific order ID to repair (optional)
     *
     * [--dry-run]
     * : Show what would be changed without making changes
     *
     * ## EXAMPLES
     *
     *     wp hope repair-tickets --dry-run
     *     wp hope repair-tickets --order-id=2323 --dry-run
     *     wp hope repair-tickets --order-id=2323
     *
     * @when after_wp_load
     */
    public function repair_tickets($args, $assoc_args) {
        global $wpdb;
        
        $order_id = isset($assoc_args['order-id']) ? intval($assoc_args['order-id']) : null;
        $dry_run = isset($assoc_args['dry-run']);
        
        WP_CLI::line('=== TICKET SEAT REPAIR COMMAND ===');
        WP_CLI::line('Mode: ' . ($dry_run ? 'DRY RUN' : 'LIVE UPDATE'));
        WP_CLI::line('Order filter: ' . ($order_id ? "Order #{$order_id}" : 'All orders'));
        WP_CLI::line('');
        
        // Get orders with multiple tickets for the same product
        $order_condition = $order_id ? $wpdb->prepare("AND o.order_id = %d", $order_id) : "";
        
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
            WP_CLI::success('No orders found with multiple tickets needing repair.');
            return;
        }
        
        WP_CLI::line('Found ' . count($orders_with_multiple_tickets) . ' orders with multiple tickets:');
        WP_CLI::line('');
        
        $repaired_count = 0;
        $total_tickets = 0;
        
        foreach ($orders_with_multiple_tickets as $order_group) {
            WP_CLI::line("Processing Order #{$order_group->order_id} - Product {$order_group->product_id} ({$order_group->ticket_count} tickets)");
            
            // Get individual seat assignments for this order/product combination
            $seat_bookings = $wpdb->get_results($wpdb->prepare("
                SELECT 
                    b.seat_id,
                    b.order_item_id,
                    s.row_name,
                    s.seat_number
                FROM {$wpdb->prefix}hope_seating_bookings b
                INNER JOIN {$wpdb->prefix}hope_seating_seats s ON b.seat_id = s.id
                WHERE b.order_id = %d AND b.product_id = %d
                ORDER BY b.order_item_id
            ", $order_group->order_id, $order_group->product_id));
            
            if (empty($seat_bookings)) {
                WP_CLI::warning("  No seat bookings found in database - skipping");
                continue;
            }
            
            // Map each order item to its correct seat
            foreach ($seat_bookings as $booking) {
                $total_tickets++;
                
                // Find the ticket post for this order item
                $ticket_post = $wpdb->get_row($wpdb->prepare("
                    SELECT ID, post_title 
                    FROM {$wpdb->posts} 
                    WHERE post_type = 'event_magic_tickets' 
                    AND post_excerpt = %s
                ", $booking->order_item_id));
                
                if (!$ticket_post) {
                    WP_CLI::warning("  No ticket post found for order item {$booking->order_item_id} - skipping");
                    continue;
                }
                
                // Check current seat assignment
                $current_row = get_post_meta($ticket_post->ID, 'fooevents_seat_row_name_0', true);
                $current_seat = get_post_meta($ticket_post->ID, 'fooevents_seat_number_0', true);
                
                $status_line = "  Ticket {$ticket_post->ID} (Item {$booking->order_item_id}): ";
                $status_line .= "Current: {$current_row}-{$current_seat} → Correct: {$booking->row_name}-{$booking->seat_number}";
                
                if ($current_row == $booking->row_name && $current_seat == $booking->seat_number) {
                    WP_CLI::line($status_line . ' ✅ Already correct');
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
                    
                    WP_CLI::line($status_line . ' ✅ UPDATED');
                    $repaired_count++;
                } else {
                    WP_CLI::line($status_line . ' 🔄 Would update');
                }
            }
            
            WP_CLI::line('');
        }
        
        if ($dry_run) {
            WP_CLI::line('=== DRY RUN COMPLETE ===');
            WP_CLI::line("Found {$total_tickets} tickets that could be processed.");
            WP_CLI::line('Run without --dry-run flag to apply changes.');
        } else {
            WP_CLI::success("=== REPAIR COMPLETE ===");
            WP_CLI::success("Repaired {$repaired_count} out of {$total_tickets} tickets.");
            WP_CLI::line('You can now resend ticket emails with correct seat assignments.');
        }
    }
}

WP_CLI::add_command('hope repair-tickets', 'Hope_Repair_Command::repair_tickets');
?>