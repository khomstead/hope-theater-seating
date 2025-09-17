<?php
/**
 * HOPE Theater Seating - Refund Handler
 * Handles seat releases when WooCommerce orders are refunded or cancelled
 * 
 * @package HOPE_Theater_Seating
 * @version 2.4.4
 */

if (!defined('ABSPATH')) {
    exit;
}

class HOPE_Refund_Handler {
    
    /**
     * Constructor - Initialize refund handling hooks
     */
    public function __construct() {
        // Hook into WooCommerce refund processes
        add_action('woocommerce_order_status_refunded', array($this, 'handle_full_refund'), 10, 1);
        add_action('woocommerce_refund_created', array($this, 'handle_partial_refund'), 10, 2);
        add_action('woocommerce_order_status_cancelled', array($this, 'handle_cancelled_order'), 10, 1);
        
        // More comprehensive hooks for catching refunds
        add_action('woocommerce_order_refunded', array($this, 'handle_order_refunded'), 10, 2);
        add_action('woocommerce_create_refund', array($this, 'handle_create_refund'), 10, 2);
        
        // Backup hook for order status changes
        add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_change'), 10, 4);
        
        error_log('HOPE: Refund handler initialized with WooCommerce hooks');
    }
    
    /**
     * Handle full order refunds
     * Triggered when order status changes to "refunded"
     * 
     * @param int $order_id WooCommerce order ID
     */
    public function handle_full_refund($order_id) {
        error_log("HOPE REFUND: Processing full refund for order {$order_id}");
        $this->release_order_seats($order_id, 'refunded');
    }
    
    /**
     * Handle cancelled orders
     * Triggered when order status changes to "cancelled"
     * 
     * @param int $order_id WooCommerce order ID
     */
    public function handle_cancelled_order($order_id) {
        error_log("HOPE REFUND: Processing cancelled order {$order_id}");
        $this->release_order_seats($order_id, 'cancelled');
    }
    
    /**
     * Handle partial refunds (item-level)
     * Triggered when a refund is created in WooCommerce
     * 
     * @param int $refund_id WooCommerce refund ID
     * @param array $args Refund arguments
     */
    public function handle_partial_refund($refund_id, $args) {
        $refund = wc_get_order($refund_id);
        if (!$refund) {
            error_log("HOPE REFUND: Could not get refund object for ID {$refund_id}");
            return;
        }
        
        $order_id = $refund->get_parent_id();
        error_log("HOPE REFUND: Processing partial refund {$refund_id} for order {$order_id}");
        
        // Get refunded line items
        foreach ($refund->get_items() as $item_id => $item) {
            // In partial refunds, we need to find the original item being refunded
            $original_item_id = $item->get_meta('_refunded_item_id');
            if ($original_item_id) {
                $this->release_item_seats($order_id, $original_item_id, 'partially_refunded');
            } else {
                // Fallback: use the item ID directly if meta not set
                error_log("HOPE REFUND: No _refunded_item_id meta found, using item ID {$item_id}");
                $this->release_item_seats($order_id, $item_id, 'partially_refunded');
            }
        }
    }
    
    /**
     * Handle any order refund (more reliable hook)
     * Triggered when any refund is processed
     * 
     * @param int $order_id Order ID
     * @param int $refund_id Refund ID
     */
    public function handle_order_refunded($order_id, $refund_id) {
        error_log("HOPE REFUND: Order {$order_id} refunded (refund ID: {$refund_id})");
        $this->release_order_seats($order_id, 'refunded');
    }
    
    /**
     * Handle refund creation (alternative hook)
     * 
     * @param int $refund_id Refund ID
     * @param array $args Refund arguments
     */
    public function handle_create_refund($refund_id, $args) {
        if (isset($args['order_id'])) {
            $order_id = $args['order_id'];
            error_log("HOPE REFUND: Refund created for order {$order_id} (refund ID: {$refund_id})");
            $this->release_order_seats($order_id, 'refunded');
        }
    }
    
    /**
     * Handle general order status changes (backup method)
     * 
     * @param int $order_id Order ID
     * @param string $status_from Previous status
     * @param string $status_to New status
     * @param object $order Order object
     */
    public function handle_order_status_change($order_id, $status_from, $status_to, $order) {
        // Only handle if we haven't already processed this status change
        if (in_array($status_to, array('refunded', 'cancelled', 'failed'))) {
            error_log("HOPE REFUND: Order status changed from {$status_from} to {$status_to} for order {$order_id}");
            
            // Check if we already processed this (avoid double processing)
            $processed_meta = get_post_meta($order_id, '_hope_refund_processed', true);
            if ($processed_meta === $status_to) {
                error_log("HOPE REFUND: Already processed {$status_to} for order {$order_id}, skipping");
                return;
            }
            
            if ($status_to === 'failed') {
                $this->release_order_seats($order_id, 'failed');
            }
            
            // Mark as processed to prevent double processing
            update_post_meta($order_id, '_hope_refund_processed', $status_to);
        }
    }
    
    /**
     * Release seats for entire order
     * 
     * @param int $order_id WooCommerce order ID
     * @param string $reason Reason for release (refunded, cancelled, failed)
     */
    private function release_order_seats($order_id, $reason = 'refunded') {
        global $wpdb;
        
        $order = wc_get_order($order_id);
        if (!$order) {
            error_log("HOPE REFUND: Could not get order object for {$order_id}");
            return;
        }
        
        $bookings_table = $wpdb->prefix . 'hope_seating_bookings';
        
        // Ensure bookings table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$bookings_table'") == $bookings_table;
        if (!$table_exists) {
            error_log("HOPE REFUND: Bookings table doesn't exist, cannot release seats");
            return;
        }
        
        // Get all confirmed bookings for this order
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$bookings_table} WHERE order_id = %d AND status = 'confirmed'",
            $order_id
        ));
        
        if (empty($bookings)) {
            error_log("HOPE REFUND: No confirmed bookings found for order {$order_id}");
            return;
        }
        
        $released_count = 0;
        foreach ($bookings as $booking) {
            // Update booking status to indicate refund/cancellation
            $result = $wpdb->update(
                $bookings_table,
                array(
                    'status' => $reason,
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $booking->id),
                array('%s', '%s'),
                array('%d')
            );
            
            if ($result !== false) {
                $released_count++;
                error_log("HOPE REFUND: Released seat {$booking->seat_id} due to {$reason} - Order {$order_id}");
            } else {
                error_log("HOPE REFUND: Failed to release seat {$booking->seat_id} - Database error: " . $wpdb->last_error);
            }
        }
        
        // Log the action and send notifications
        $this->log_seat_release($order_id, $bookings, $reason);
        
        error_log("HOPE REFUND: Successfully released {$released_count} of " . count($bookings) . " seats for order {$order_id}");
    }
    
    /**
     * Release seats for specific order item (partial refunds)
     * 
     * @param int $order_id WooCommerce order ID
     * @param int $item_id WooCommerce order item ID
     * @param string $reason Reason for release
     */
    private function release_item_seats($order_id, $item_id, $reason = 'refunded') {
        global $wpdb;
        
        $bookings_table = $wpdb->prefix . 'hope_seating_bookings';
        
        // Get confirmed bookings for this specific item
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$bookings_table} WHERE order_id = %d AND order_item_id = %d AND status = 'confirmed'",
            $order_id, $item_id
        ));
        
        if (empty($bookings)) {
            error_log("HOPE REFUND: No confirmed bookings found for order {$order_id}, item {$item_id}");
            return;
        }
        
        $released_count = 0;
        foreach ($bookings as $booking) {
            $result = $wpdb->update(
                $bookings_table,
                array(
                    'status' => $reason,
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $booking->id),
                array('%s', '%s'),
                array('%d')
            );
            
            if ($result !== false) {
                $released_count++;
                error_log("HOPE REFUND: Released seat {$booking->seat_id} for item {$item_id} due to {$reason}");
            }
        }
        
        error_log("HOPE REFUND: Released {$released_count} seats for order {$order_id}, item {$item_id}");
    }
    
    /**
     * Log seat releases for audit trail and send notifications
     * 
     * @param int $order_id Order ID
     * @param array $bookings Array of booking objects
     * @param string $reason Reason for release
     */
    private function log_seat_release($order_id, $bookings, $reason) {
        if (empty($bookings)) return;
        
        $seat_ids = array_map(function($booking) { return $booking->seat_id; }, $bookings);
        $seat_list = implode(', ', $seat_ids);
        
        // Create audit log entry
        error_log("HOPE REFUND AUDIT: Order {$order_id} - Released " . count($bookings) . " seats ({$seat_list}) - Reason: {$reason}");
        
        // Store refund activity in order meta for admin reference
        $refund_log = get_post_meta($order_id, '_hope_refund_log', true);
        if (!is_array($refund_log)) {
            $refund_log = array();
        }
        
        $refund_log[] = array(
            'timestamp' => current_time('mysql'),
            'reason' => $reason,
            'seats_released' => $seat_ids,
            'count' => count($bookings)
        );
        
        update_post_meta($order_id, '_hope_refund_log', $refund_log);
        
        // Optional: Send notification email to admin (can be enabled later)
        $this->maybe_notify_admin($order_id, $bookings, $reason);
    }
    
    /**
     * Send admin notification about seat releases (optional)
     * 
     * @param int $order_id Order ID
     * @param array $bookings Booking records
     * @param string $reason Reason for release
     */
    private function maybe_notify_admin($order_id, $bookings, $reason) {
        // Check if admin notifications are enabled
        $notify_admin = get_option('hope_seating_notify_refunds', false);
        if (!$notify_admin) {
            return;
        }
        
        $admin_email = get_option('admin_email');
        if (!$admin_email) {
            return;
        }
        
        $order = wc_get_order($order_id);
        $seat_count = count($bookings);
        $seat_list = implode(', ', array_map(function($b) { return $b->seat_id; }, $bookings));
        
        $subject = "HOPE Theater: {$seat_count} seats released due to {$reason}";
        $message = "Order #{$order_id} - {$reason}\n\n";
        $message .= "Customer: " . $order->get_billing_first_name() . " " . $order->get_billing_last_name() . "\n";
        $message .= "Email: " . $order->get_billing_email() . "\n";
        $message .= "Released seats: {$seat_list}\n";
        $message .= "Timestamp: " . current_time('mysql') . "\n\n";
        $message .= "These seats are now available for purchase again.";
        
        wp_mail($admin_email, $subject, $message);
        error_log("HOPE REFUND: Admin notification sent for order {$order_id}");
    }
    
    /**
     * Get refund statistics for admin dashboard
     * 
     * @return array Refund statistics
     */
    public function get_refund_stats() {
        global $wpdb;
        
        $bookings_table = $wpdb->prefix . 'hope_seating_bookings';
        
        $stats = array();
        
        // Count refunded seats by reason
        $reasons = array('refunded', 'cancelled', 'failed', 'partially_refunded');
        foreach ($reasons as $reason) {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$bookings_table} WHERE status = %s",
                $reason
            ));
            $stats[$reason] = (int)$count;
        }
        
        // Recent refunds (last 30 days)
        $recent_count = $wpdb->get_var("
            SELECT COUNT(*) FROM {$bookings_table} 
            WHERE status IN ('refunded', 'cancelled', 'failed') 
            AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stats['recent_refunds'] = (int)$recent_count;
        
        return $stats;
    }
}