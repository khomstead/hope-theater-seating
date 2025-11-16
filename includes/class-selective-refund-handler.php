<?php
/**
 * HOPE Theater Seating - Selective Refund Handler
 * Extends existing refund functionality with selective seat refund capabilities
 * BACKWARD COMPATIBLE: Does not modify existing refund behavior
 * 
 * @package HOPE_Theater_Seating
 * @version 2.4.7
 */

if (!defined('ABSPATH')) {
    exit;
}

class HOPE_Selective_Refund_Handler {
    
    /**
     * Initialize selective refund handler
     * SAFE: Only adds new functionality, existing hooks remain unchanged
     */
    public function __construct() {
        // Only initialize if database support is ready
        if (class_exists('HOPE_Database_Selective_Refunds') && 
            HOPE_Database_Selective_Refunds::is_selective_refund_ready()) {
            
            // Add new selective refund capabilities
            add_action('init', array($this, 'init_selective_features'));
            
            // Initialization successful - database ready
        } else {
            error_log('HOPE: Selective refund handler waiting for database setup');
        }
    }
    
    /**
     * Initialize selective refund features
     * SAFE: Only adds new hooks, doesn't interfere with existing ones
     */
    public function init_selective_features() {
        // AJAX endpoint for selective refund processing (new functionality only)
        add_action('wp_ajax_hope_process_selective_refund', array($this, 'ajax_process_selective_refund'));
        
        // Custom action hook for other plugins to listen to selective refunds
        // This allows future extensions without modifying core code
        add_action('hope_selective_refund_processed', array($this, 'log_selective_refund'), 10, 3);
    }
    
    /**
     * Process selective refund for specific seats
     * CORE FUNCTIONALITY: New selective refund capability
     *
     * @param int $order_id WooCommerce order ID
     * @param array $seat_ids Array of seat IDs to refund
     * @param string $reason Reason for refund
     * @param bool $notify_customer Whether to send customer notification
     * @param bool $keep_seats_held Whether to keep seats reserved (guest list/comp)
     * @return array Result with success status and details
     */
    public function process_selective_refund($order_id, $seat_ids, $reason = '', $notify_customer = true, $keep_seats_held = false) {
        // Validation
        if (empty($order_id) || empty($seat_ids) || !is_array($seat_ids)) {
            return array(
                'success' => false,
                'error' => 'Invalid parameters provided'
            );
        }
        
        // Check if selective refunds are available
        if (!HOPE_Database_Selective_Refunds::is_selective_refund_ready()) {
            return array(
                'success' => false,
                'error' => 'Selective refund functionality not available'
            );
        }
        
        // Get order and validate
        $order = wc_get_order($order_id);
        if (!$order) {
            return array(
                'success' => false,
                'error' => 'Order not found'
            );
        }
        
        // Get refundable seats for this order
        $refundable_seats = HOPE_Database_Selective_Refunds::get_refundable_seats($order_id);
        $refundable_seat_ids = array_column($refundable_seats, 'seat_id');
        
        // Validate that all requested seats can be refunded
        $invalid_seats = array_diff($seat_ids, $refundable_seat_ids);
        if (!empty($invalid_seats)) {
            return array(
                'success' => false,
                'error' => 'Some seats cannot be refunded: ' . implode(', ', $invalid_seats)
            );
        }
        
        // Calculate refund amount for selected seats
        $refund_calculation = $this->calculate_selective_refund_amount($order_id, $seat_ids);
        if (!$refund_calculation['success']) {
            return $refund_calculation;
        }
        
        // Set flag to prevent general refund handler from interfering
        update_post_meta($order_id, '_hope_selective_refund_in_progress', time());
        
        // Create WooCommerce refund
        $wc_refund_result = $this->create_woocommerce_refund($order, $refund_calculation['amount'], $reason);
        
        // Clear the flag regardless of success/failure
        delete_post_meta($order_id, '_hope_selective_refund_in_progress');
        
        if (!$wc_refund_result['success']) {
            return $wc_refund_result;
        }
        
        // Update individual seat records
        $seat_update_result = $this->update_seat_refund_records($order_id, $seat_ids, $wc_refund_result['refund_id'], $refund_calculation, $reason, $keep_seats_held);
        if (!$seat_update_result['success']) {
            // TODO: Consider rolling back WooCommerce refund if seat updates fail
            error_log("HOPE: Seat record updates failed after WC refund created. Manual intervention may be needed.");
        }
        
        // Create selective refund tracking record
        $tracking_record_id = HOPE_Database_Selective_Refunds::create_selective_refund_record(
            $order_id,
            $wc_refund_result['refund_id'],
            $seat_ids,
            $refund_calculation['amount'],
            $reason,
            get_current_user_id()
        );
        
        // Trigger action for extensibility
        do_action('hope_selective_refund_processed', $order_id, $seat_ids, $refund_calculation['amount']);

        // Add comprehensive order note for audit trail
        $refund_type_label = ($wc_refund_result['refund_type'] ?? 'unknown') === 'automatic' ? 'Automatic' : 'Manual';
        $seat_list = implode(', ', $seat_ids);
        $admin_name = wp_get_current_user()->display_name;

        if ($keep_seats_held) {
            $order->add_order_note(
                sprintf(
                    __('Selective Refund (Guest List): %d seat(s) refunded but kept held - %s | Amount: $%.2f (%s refund) | Reason: %s | Processed by: %s', 'hope-theater-seating'),
                    count($seat_ids),
                    $seat_list,
                    $refund_calculation['amount'],
                    $refund_type_label,
                    $reason ?: 'None provided',
                    $admin_name
                )
            );
        } else {
            $order->add_order_note(
                sprintf(
                    __('Selective Refund: %d seat(s) refunded and released - %s | Amount: $%.2f (%s refund) | Remaining seats: %d | Reason: %s | Processed by: %s', 'hope-theater-seating'),
                    count($seat_ids),
                    $seat_list,
                    $refund_calculation['amount'],
                    $refund_type_label,
                    count($this->get_remaining_seats($order_id)),
                    $reason ?: 'None provided',
                    $admin_name
                )
            );
        }

        // Enhanced message with refund type information
        $refund_type_info = isset($wc_refund_result['refund_type']) ?
            " ({$wc_refund_result['refund_type']} refund)" : '';

        // Add guest list info to message
        $guest_list_info = $keep_seats_held ? ' - Seats kept held (Guest List)' : '';

        $result = array(
            'success' => true,
            'refund_id' => $wc_refund_result['refund_id'],
            'tracking_id' => $tracking_record_id,
            'refunded_seats' => $seat_ids,
            'refund_amount' => $refund_calculation['amount'],
            'remaining_seats' => $this->get_remaining_seats($order_id),
            'refund_type' => $wc_refund_result['refund_type'] ?? 'unknown',
            'keep_seats_held' => $keep_seats_held,
            'message' => sprintf(
                'Successfully refunded %d seats (%s) for $%.2f%s%s',
                count($seat_ids),
                implode(', ', $seat_ids),
                $refund_calculation['amount'],
                $refund_type_info,
                $guest_list_info
            )
        );
        
        // Send customer notification if requested
        if ($notify_customer) {
            $this->send_selective_refund_notification($order, $result);
        }
        
        return $result;
    }
    
    /**
     * Calculate refund amount for selected seats
     * @param int $order_id Order ID
     * @param array $seat_ids Seat IDs to refund
     * @return array Calculation result
     */
    private function calculate_selective_refund_amount($order_id, $seat_ids) {
        // For now, use simple calculation based on order total divided by seat count
        // This can be enhanced later with actual seat pricing
        
        $order = wc_get_order($order_id);
        $total_seats = HOPE_Database_Selective_Refunds::get_refundable_seats($order_id);
        
        if (empty($total_seats)) {
            return array(
                'success' => false,
                'error' => 'No refundable seats found for this order'
            );
        }
        
        $order_total = $order->get_total();
        $total_seat_count = count($total_seats);
        $selected_seat_count = count($seat_ids);
        
        // Calculate proportional refund amount
        $refund_amount = ($order_total / $total_seat_count) * $selected_seat_count;
        
        return array(
            'success' => true,
            'amount' => round($refund_amount, 2),
            'per_seat_amount' => round($refund_amount / $selected_seat_count, 2),
            'calculation_method' => 'proportional'
        );
    }
    
    /**
     * Create WooCommerce refund
     * @param WC_Order $order Order object
     * @param float $amount Refund amount
     * @param string $reason Refund reason
     * @return array Result with refund ID or error
     */
    private function create_woocommerce_refund($order, $amount, $reason) {
        try {
            // Check if this is a $0 order or if payment gateway is unavailable
            $order_total = floatval($order->get_total());
            $payment_method = $order->get_payment_method();
            $gateway_available = !empty($payment_method) && $order_total > 0;
            
            // For $0 orders or when gateway is unavailable, create manual refund
            $refund_payment = $gateway_available && $amount > 0;
            
            error_log("HOPE Refund: Order total: {$order_total}, Payment method: {$payment_method}, Gateway available: " . ($gateway_available ? 'yes' : 'no') . ", Refund amount: {$amount}, Will process payment: " . ($refund_payment ? 'yes' : 'no'));
            
            $refund = wc_create_refund(array(
                'amount' => $amount,
                'reason' => $reason ?: 'Selective seat refund',
                'order_id' => $order->get_id(),
                'line_items' => array(), // TODO: Can be enhanced to specify exact line items
                'refund_payment' => $refund_payment // Only process payment refund if gateway is available
            ));
            
            if (is_wp_error($refund)) {
                return array(
                    'success' => false,
                    'error' => 'WooCommerce refund failed: ' . $refund->get_error_message()
                );
            }
            
            $refund_type = $refund_payment ? 'automatic' : 'manual';
            return array(
                'success' => true,
                'refund_id' => $refund->get_id(),
                'refund_object' => $refund,
                'refund_type' => $refund_type,
                'message' => $refund_payment 
                    ? 'Refund processed automatically through payment gateway' 
                    : 'Manual refund created - no payment to process'
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => 'Refund creation failed: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Update individual seat booking records with refund information
     * @param int $order_id Order ID
     * @param array $seat_ids Seat IDs being refunded
     * @param int $refund_id WooCommerce refund ID
     * @param array $refund_calculation Refund calculation details
     * @param string $reason Refund reason
     * @param bool $keep_seats_held Whether to keep seats held (guest list/comp)
     * @return array Update result
     */
    private function update_seat_refund_records($order_id, $seat_ids, $refund_id, $refund_calculation, $reason, $keep_seats_held = false) {
        global $wpdb;

        $bookings_table = $wpdb->prefix . 'hope_seating_bookings';
        $per_seat_amount = $refund_calculation['per_seat_amount'];
        $updated_count = 0;

        // Determine the status based on whether seats should be kept held
        $new_status = $keep_seats_held ? 'guest_list' : 'partially_refunded';

        foreach ($seat_ids as $seat_id) {
            $result = $wpdb->update(
                $bookings_table,
                array(
                    'status' => $new_status,
                    'refund_id' => $refund_id,
                    'refunded_amount' => $per_seat_amount,
                    'refund_reason' => $reason,
                    'refund_date' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ),
                array(
                    'order_id' => $order_id,
                    'seat_id' => $seat_id
                ),
                array('%s', '%d', '%f', '%s', '%s', '%s'),
                array('%d', '%s')
            );
            
            if ($result !== false) {
                $updated_count++;
                error_log("HOPE: Updated refund info for seat {$seat_id} in order {$order_id}");
            } else {
                error_log("HOPE: Failed to update seat {$seat_id} refund info: " . $wpdb->last_error);
            }
        }
        
        return array(
            'success' => $updated_count === count($seat_ids),
            'updated_count' => $updated_count,
            'total_requested' => count($seat_ids)
        );
    }
    
    /**
     * Get remaining (non-refunded) seats for an order
     * @param int $order_id Order ID
     * @return array Array of remaining seat IDs
     */
    private function get_remaining_seats($order_id) {
        global $wpdb;
        
        $bookings_table = $wpdb->prefix . 'hope_seating_bookings';
        
        $remaining_seats = $wpdb->get_col($wpdb->prepare(
            "SELECT seat_id FROM {$bookings_table}
            WHERE order_id = %d 
            AND status = 'confirmed'
            AND refund_id IS NULL
            ORDER BY seat_id",
            $order_id
        ));
        
        return $remaining_seats ?: array();
    }
    
    /**
     * Send customer notification about selective refund
     * @param WC_Order $order Order object
     * @param array $refund_result Refund processing result
     */
    private function send_selective_refund_notification($order, $refund_result) {
        $customer_email = $order->get_billing_email();
        if (!$customer_email) {
            return;
        }
        
        $subject = sprintf('Partial Refund Processed - Order #%s', $order->get_order_number());
        
        $message = sprintf(
            "Dear %s,\n\n" .
            "Your partial refund has been processed:\n\n" .
            "Order: #%s\n" .
            "Refunded Seats: %s\n" .
            "Refund Amount: $%.2f\n" .
            "Remaining Seats: %s\n\n" .
            "The refund will appear on your original payment method within 3-5 business days.\n\n" .
            "Thank you,\nHOPE Theater",
            $order->get_billing_first_name(),
            $order->get_order_number(),
            implode(', ', $refund_result['refunded_seats']),
            $refund_result['refund_amount'],
            implode(', ', $refund_result['remaining_seats'])
        );
        
        wp_mail($customer_email, $subject, $message);
        error_log("HOPE: Selective refund notification sent to {$customer_email}");
    }
    
    /**
     * AJAX handler for selective refund processing
     * For future admin interface integration
     */
    public function ajax_process_selective_refund() {
        // Security checks
        if (!current_user_can('manage_woocommerce') || !wp_verify_nonce($_POST['nonce'], 'hope_selective_refund')) {
            wp_die('Access denied');
        }
        
        $order_id = intval($_POST['order_id']);
        $seat_ids = array_map('sanitize_text_field', $_POST['seat_ids']);
        $reason = sanitize_text_field($_POST['reason']);
        
        $result = $this->process_selective_refund($order_id, $seat_ids, $reason);
        
        wp_send_json($result);
    }
    
    /**
     * Log selective refund for audit trail
     * @param int $order_id Order ID
     * @param array $seat_ids Refunded seat IDs
     * @param float $amount Refund amount
     */
    public function log_selective_refund($order_id, $seat_ids, $amount) {
        error_log(sprintf(
            "HOPE SELECTIVE REFUND: Order %d - Refunded %d seats (%s) for $%.2f",
            $order_id,
            count($seat_ids),
            implode(', ', $seat_ids),
            $amount
        ));
    }
    
    /**
     * Check if selective refund functionality is available
     * @return bool Whether selective refunds can be processed
     */
    public static function is_available() {
        return class_exists('HOPE_Database_Selective_Refunds') && 
               HOPE_Database_Selective_Refunds::is_selective_refund_ready();
    }
}