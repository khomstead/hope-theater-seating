<?php
/**
 * Admin page for repairing ticket seat assignments
 * Access via: WordPress Admin > Tools > Repair Tickets
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Add admin menu
add_action('admin_menu', function() {
    add_submenu_page(
        'tools.php',
        'Repair Ticket Seats',
        'Repair Ticket Seats', 
        'manage_options',
        'repair-ticket-seats',
        'hope_repair_tickets_page'
    );
});

function hope_repair_tickets_page() {
    global $wpdb;
    
    // Handle form submission
    if (isset($_POST['action']) && $_POST['action'] === 'repair_tickets') {
        $order_id = !empty($_POST['order_id']) ? intval($_POST['order_id']) : null;
        $dry_run = !isset($_POST['live_update']);
        
        echo '<div class="wrap">';
        echo '<h1>Repair Ticket Seat Assignments</h1>';
        
        hope_process_ticket_repair($order_id, $dry_run);
        
        echo '<p><a href="' . admin_url('tools.php?page=repair-ticket-seats') . '" class="button">Back to Repair Form</a></p>';
        echo '</div>';
        return;
    }
    
    // Show form
    ?>
    <div class="wrap">
        <h1>Repair Ticket Seat Assignments</h1>
        <p>This tool fixes existing tickets that show duplicate seat assignments in emails.</p>
        
        <form method="post" action="">
            <?php wp_nonce_field('repair_tickets', 'repair_nonce'); ?>
            <input type="hidden" name="action" value="repair_tickets">
            
            <table class="form-table">
                <tr>
                    <th scope="row">Order ID (optional)</th>
                    <td>
                        <input type="number" name="order_id" placeholder="Leave empty for all orders" class="regular-text">
                        <p class="description">Enter a specific order ID to repair only that order, or leave empty to repair all affected orders.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Mode</th>
                    <td>
                        <label>
                            <input type="radio" name="mode" value="dry_run" checked> 
                            <strong>Dry Run</strong> - Show what would be changed without making changes
                        </label><br>
                        <label>
                            <input type="radio" name="mode" value="live"> 
                            <strong>Live Update</strong> - Actually update the ticket data
                        </label>
                        <br><input type="checkbox" name="live_update" id="live_update" style="display:none;">
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" class="button-primary" value="Analyze Tickets" id="submit-btn">
            </p>
        </form>
        
        <script>
        document.querySelector('input[name="mode"][value="live"]').addEventListener('change', function() {
            document.getElementById('live_update').checked = this.checked;
            document.getElementById('submit-btn').value = 'Repair Tickets';
        });
        document.querySelector('input[name="mode"][value="dry_run"]').addEventListener('change', function() {
            document.getElementById('live_update').checked = false;
            document.getElementById('submit-btn').value = 'Analyze Tickets';
        });
        </script>
    </div>
    <?php
}

function hope_process_ticket_repair($order_id = null, $dry_run = true) {
    global $wpdb;
    
    echo '<h2>Repair Results</h2>';
    echo '<div style="background:#fff; border:1px solid #ccc; padding:20px; font-family:monospace; white-space:pre-wrap;">';
    
    $mode_text = $dry_run ? 'DRY RUN' : 'LIVE UPDATE';
    $order_text = $order_id ? "Order #{$order_id}" : 'All orders';
    
    echo "=== TICKET SEAT REPAIR ===\n";
    echo "Mode: {$mode_text}\n";
    echo "Order filter: {$order_text}\n\n";
    
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
        echo "No orders found with multiple tickets needing repair.\n\n";
        
        // Check for and repair duplicate seat assignments instead
        if (!$dry_run) {
            hope_repair_duplicate_seats();
            echo '</div>';
            return;
        }
        
        // Show diagnostic information
        echo "=== DIAGNOSTIC INFORMATION ===\n";
        
        // Check if we have any tickets at all
        $total_tickets = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'event_magic_tickets'");
        echo "Total tickets in system: {$total_tickets}\n";
        
        // Check if we have any booking records
        $total_bookings = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}hope_seating_bookings");
        echo "Total seat bookings: {$total_bookings}\n";
        
        // Show a sample of tickets and their seat assignments
        $sample_tickets = $wpdb->get_results("
            SELECT 
                p.ID,
                p.post_title,
                p.post_excerpt as order_item_id,
                pm1.meta_value as seat_row,
                pm2.meta_value as seat_number
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = 'fooevents_seat_row_name_0'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = 'fooevents_seat_number_0'
            WHERE p.post_type = 'event_magic_tickets'
            ORDER BY p.ID DESC
            LIMIT 10
        ");
        
        echo "\nSample of recent tickets:\n";
        foreach ($sample_tickets as $ticket) {
            $seat_display = $ticket->seat_row && $ticket->seat_number ? "{$ticket->seat_row}-{$ticket->seat_number}" : "No seat assigned";
            echo "  Ticket {$ticket->ID} (Item {$ticket->order_item_id}): {$seat_display}\n";
        }
        
        // Check for tickets with identical seat assignments from same order
        $duplicate_seats = $wpdb->get_results("
            SELECT 
                pm1.meta_value as seat_row,
                pm2.meta_value as seat_number,
                COUNT(*) as duplicate_count,
                GROUP_CONCAT(p.ID) as ticket_ids,
                GROUP_CONCAT(p.post_excerpt) as order_item_ids
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = 'fooevents_seat_row_name_0'
            INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = 'fooevents_seat_number_0'
            WHERE p.post_type = 'event_magic_tickets'
            AND pm1.meta_value != '' AND pm2.meta_value != ''
            GROUP BY pm1.meta_value, pm2.meta_value
            HAVING duplicate_count > 1
            ORDER BY duplicate_count DESC
        ");
        
        if (!empty($duplicate_seats)) {
            echo "\nFound tickets with duplicate seat assignments:\n";
            foreach ($duplicate_seats as $dup) {
                echo "  Seat {$dup->seat_row}-{$dup->seat_number}: {$dup->duplicate_count} tickets (IDs: {$dup->ticket_ids})\n";
            }
            
            echo "\n=== REPAIR OPTION AVAILABLE ===\n";
            echo "Would you like to fix these duplicate seat assignments?\n";
            echo "Click 'Live Update' mode to repair the duplicate tickets.\n";
            
        } else {
            echo "\nNo duplicate seat assignments found.\n";
        }
        
        echo '</div>';
        return;
    }
    
    echo "Found " . count($orders_with_multiple_tickets) . " orders with multiple tickets:\n\n";
    
    $repaired_count = 0;
    $total_tickets = 0;
    
    foreach ($orders_with_multiple_tickets as $order_group) {
        echo "Processing Order #{$order_group->order_id} - Product {$order_group->product_id} ({$order_group->ticket_count} tickets)\n";
        
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
            echo "  ⚠️  No seat bookings found in database - skipping\n";
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
        echo "Found {$total_tickets} tickets that could be processed.\n";
        echo "Run in Live Update mode to apply changes.\n";
    } else {
        echo "=== REPAIR COMPLETE ===\n";
        echo "Repaired {$repaired_count} out of {$total_tickets} tickets.\n";
        echo "You can now resend ticket emails with correct seat assignments.\n";
    }
    
    echo '</div>';
}

function hope_repair_duplicate_seats() {
    global $wpdb;
    
    echo "=== REPAIRING DUPLICATE SEAT ASSIGNMENTS ===\n";
    
    // Find tickets with duplicate seat assignments
    $duplicate_seats = $wpdb->get_results("
        SELECT 
            pm1.meta_value as seat_row,
            pm2.meta_value as seat_number,
            COUNT(*) as duplicate_count,
            GROUP_CONCAT(p.ID ORDER BY p.ID) as ticket_ids
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = 'fooevents_seat_row_name_0'
        INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = 'fooevents_seat_number_0'
        WHERE p.post_type = 'event_magic_tickets'
        AND pm1.meta_value != '' AND pm2.meta_value != ''
        GROUP BY pm1.meta_value, pm2.meta_value
        HAVING duplicate_count > 1
        ORDER BY duplicate_count DESC
    ");
    
    if (empty($duplicate_seats)) {
        echo "No duplicate seat assignments found to repair.\n";
        return;
    }
    
    $total_fixed = 0;
    
    foreach ($duplicate_seats as $dup_group) {
        $ticket_ids = explode(',', $dup_group->ticket_ids);
        echo "\nProcessing {$dup_group->duplicate_count} tickets assigned to seat {$dup_group->seat_row}-{$dup_group->seat_number}:\n";
        
        // Try to get correct seat assignments from booking data if available
        $correct_seats = array();
        
        foreach ($ticket_ids as $ticket_id) {
            // Get the post_excerpt (order_item_id) for this ticket
            $order_item_id = get_post_meta($ticket_id, '_order_item_id', true);
            if (!$order_item_id) {
                $order_item_id = $wpdb->get_var($wpdb->prepare("
                    SELECT post_excerpt FROM {$wpdb->posts} WHERE ID = %d
                ", $ticket_id));
            }
            
            if ($order_item_id) {
                // Look up the correct seat from bookings
                $correct_seat = $wpdb->get_row($wpdb->prepare("
                    SELECT s.row_name, s.seat_number 
                    FROM {$wpdb->prefix}hope_seating_bookings b
                    INNER JOIN {$wpdb->prefix}hope_seating_seats s ON b.seat_id = s.id
                    WHERE b.order_item_id = %s
                ", $order_item_id));
                
                if ($correct_seat) {
                    $correct_seats[$ticket_id] = $correct_seat;
                }
            }
        }
        
        // If we found correct seats from booking data, use them
        if (count($correct_seats) > 0) {
            foreach ($correct_seats as $ticket_id => $seat_data) {
                echo "  Ticket {$ticket_id}: Correcting to {$seat_data->row_name}-{$seat_data->seat_number}\n";
                
                // Update seat metadata
                update_post_meta($ticket_id, 'fooevents_seat_row_name_0', $seat_data->row_name);
                update_post_meta($ticket_id, 'fooevents_seat_number_0', $seat_data->seat_number);
                
                // Update seating fields array
                $seating_fields = array(
                    'fooevents_seat_row_name_0' => $seat_data->row_name,
                    'fooevents_seat_number_0' => $seat_data->seat_number
                );
                update_post_meta($ticket_id, 'WooCommerceEventsSeatingFields', $seating_fields);
                $total_fixed++;
            }
        } else {
            // Fallback: Assign sequential seats from the same row
            echo "  No booking data found. Assigning sequential seats in {$dup_group->seat_row}:\n";
            
            // Extract row and starting seat number
            $row_name = $dup_group->seat_row;
            $base_seat_num = intval($dup_group->seat_number);
            
            foreach ($ticket_ids as $index => $ticket_id) {
                $new_seat_num = $base_seat_num + $index;
                echo "    Ticket {$ticket_id}: Assigning {$row_name}-{$new_seat_num}\n";
                
                // Update seat metadata
                update_post_meta($ticket_id, 'fooevents_seat_row_name_0', $row_name);
                update_post_meta($ticket_id, 'fooevents_seat_number_0', $new_seat_num);
                
                // Update seating fields array
                $seating_fields = array(
                    'fooevents_seat_row_name_0' => $row_name,
                    'fooevents_seat_number_0' => $new_seat_num
                );
                update_post_meta($ticket_id, 'WooCommerceEventsSeatingFields', $seating_fields);
                $total_fixed++;
            }
        }
    }
    
    echo "\n=== REPAIR COMPLETE ===\n";
    echo "Fixed {$total_fixed} duplicate seat assignments.\n";
    echo "Ticket emails should now show unique seats for each ticket.\n";
}
?>