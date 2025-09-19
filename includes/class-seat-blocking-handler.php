<?php
/**
 * HOPE Theater Seating - Seat Blocking Handler
 * Handles administrative seat blocking for equipment, VIP, maintenance, etc.
 * 
 * @package HOPE_Theater_Seating
 * @version 2.4.7
 */

if (!defined('ABSPATH')) {
    exit;
}

class HOPE_Seat_Blocking_Handler {
    
    /**
     * Initialize seat blocking handler
     */
    public function __construct() {
        // Only initialize if database support is ready
        if (class_exists('HOPE_Database_Selective_Refunds') && 
            HOPE_Database_Selective_Refunds::is_seat_blocking_ready()) {
            
            // Hook into seat availability checking
            add_filter('hope_seat_availability_check', array($this, 'filter_blocked_seats'), 10, 3);
            
            // Add AJAX endpoints for blocking management
            add_action('wp_ajax_hope_create_seat_block', array($this, 'ajax_create_seat_block'));
            add_action('wp_ajax_hope_remove_seat_block', array($this, 'ajax_remove_seat_block'));
            add_action('wp_ajax_hope_get_seat_blocks', array($this, 'ajax_get_seat_blocks'));
            
            error_log('HOPE: Seat blocking handler initialized');
        } else {
            error_log('HOPE: Seat blocking handler waiting for database setup');
        }
    }
    
    /**
     * Create a new seat block
     * @param int $event_id Event/Product ID
     * @param array $seat_ids Array of seat IDs to block
     * @param string $block_type Type of block (manual, equipment, vip, maintenance)
     * @param string $reason Block reason
     * @param string $valid_from Start time (Y-m-d H:i:s format or null for immediate)
     * @param string $valid_until End time (Y-m-d H:i:s format or null for indefinite)
     * @return array Result with success status and details
     */
    public function create_seat_block($event_id, $seat_ids, $block_type = 'manual', $reason = '', $valid_from = null, $valid_until = null) {
        // Validation
        if (empty($event_id) || empty($seat_ids) || !is_array($seat_ids)) {
            return array(
                'success' => false,
                'error' => 'Invalid parameters provided'
            );
        }
        
        // Check if seat blocking is available
        if (!HOPE_Database_Selective_Refunds::is_seat_blocking_ready()) {
            return array(
                'success' => false,
                'error' => 'Seat blocking functionality not available'
            );
        }
        
        // Validate block type
        $valid_types = array('manual', 'equipment', 'vip', 'maintenance', 'guest-list', 'accessibility');
        if (!in_array($block_type, $valid_types)) {
            $block_type = 'manual';
        }
        
        // Check if any seats are already blocked or booked
        $conflicts = $this->check_seat_conflicts($event_id, $seat_ids);
        if (!empty($conflicts['blocked'])) {
            return array(
                'success' => false,
                'error' => 'Some seats are already blocked: ' . implode(', ', $conflicts['blocked'])
            );
        }
        
        if (!empty($conflicts['booked'])) {
            return array(
                'success' => false,
                'error' => 'Some seats are already booked by customers: ' . implode(', ', $conflicts['booked'])
            );
        }
        
        // Create the block
        $block_id = HOPE_Database_Selective_Refunds::create_seat_block(
            $event_id, $seat_ids, $block_type, $reason, $valid_from, $valid_until
        );
        
        if ($block_id === false) {
            return array(
                'success' => false,
                'error' => 'Failed to create seat block in database'
            );
        }
        
        // Log the action
        error_log(sprintf(
            "HOPE SEAT BLOCK: Created %s block for event %d - %d seats (%s) - Block ID: %d",
            $block_type,
            $event_id,
            count($seat_ids),
            implode(', ', $seat_ids),
            $block_id
        ));
        
        return array(
            'success' => true,
            'block_id' => $block_id,
            'blocked_seats' => $seat_ids,
            'block_type' => $block_type,
            'event_id' => $event_id,
            'message' => sprintf(
                'Successfully blocked %d seats (%s) for %s',
                count($seat_ids),
                implode(', ', $seat_ids),
                $block_type
            )
        );
    }
    
    /**
     * Remove a seat block
     * @param int $block_id Block ID to remove
     * @return array Result with success status
     */
    public function remove_seat_block($block_id) {
        if (empty($block_id)) {
            return array(
                'success' => false,
                'error' => 'Invalid block ID'
            );
        }
        
        // Get block info before removing
        $block_info = $this->get_seat_block($block_id);
        if (!$block_info) {
            return array(
                'success' => false,
                'error' => 'Block not found'
            );
        }
        
        $result = HOPE_Database_Selective_Refunds::remove_seat_block($block_id);
        
        if ($result) {
            error_log("HOPE SEAT BLOCK: Removed block ID {$block_id} - " . count($block_info['seat_ids']) . " seats released");
            
            return array(
                'success' => true,
                'block_id' => $block_id,
                'released_seats' => $block_info['seat_ids'],
                'message' => 'Seat block removed successfully'
            );
        } else {
            return array(
                'success' => false,
                'error' => 'Failed to remove seat block'
            );
        }
    }
    
    /**
     * Get seat block information
     * @param int $block_id Block ID
     * @return array|null Block information or null if not found
     */
    public function get_seat_block($block_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hope_seating_seat_blocks';
        
        $block = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $block_id
        ), ARRAY_A);
        
        if ($block) {
            $block['seat_ids'] = json_decode($block['seat_ids'], true) ?: array();
        }
        
        return $block;
    }
    
    /**
     * Check for seat conflicts (already blocked or booked)
     * @param int $event_id Event/Product ID
     * @param array $seat_ids Seat IDs to check
     * @return array Array with 'blocked' and 'booked' conflicts
     */
    private function check_seat_conflicts($event_id, $seat_ids) {
        $conflicts = array(
            'blocked' => array(),
            'booked' => array()
        );
        
        // Check for existing blocks
        $blocked_seats = HOPE_Database_Selective_Refunds::get_blocked_seat_ids($event_id);
        $conflicts['blocked'] = array_intersect($seat_ids, $blocked_seats);
        
        // Check for existing bookings
        $booked_seats = $this->get_booked_seats($event_id);
        $conflicts['booked'] = array_intersect($seat_ids, $booked_seats);
        
        return $conflicts;
    }
    
    /**
     * Get booked seats for an event
     * @param int $event_id Event/Product ID
     * @return array Array of booked seat IDs
     */
    private function get_booked_seats($event_id) {
        global $wpdb;
        
        $bookings_table = $wpdb->prefix . 'hope_seating_bookings';
        
        $booked_seats = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT seat_id FROM {$bookings_table}
            WHERE product_id = %d 
            AND status IN ('confirmed', 'pending')
            AND refund_id IS NULL",
            $event_id
        ));
        
        return $booked_seats ?: array();
    }
    
    /**
     * Filter seat availability to exclude blocked seats
     * Hook into existing availability checking system
     * @param array $available_seats Currently available seats
     * @param int $event_id Event/Product ID
     * @param array $context Additional context
     * @return array Filtered available seats
     */
    public function filter_blocked_seats($available_seats, $event_id, $context = array()) {
        if (empty($available_seats) || empty($event_id)) {
            return $available_seats;
        }
        
        // Get blocked seat IDs for this event
        $blocked_seats = HOPE_Database_Selective_Refunds::get_blocked_seat_ids($event_id);
        
        if (empty($blocked_seats)) {
            return $available_seats; // No blocks, return as-is
        }
        
        // Filter out blocked seats
        if (is_array($available_seats)) {
            // If seats are in array format
            $filtered_seats = array_diff($available_seats, $blocked_seats);
        } else {
            // Handle other formats if needed
            $filtered_seats = $available_seats;
        }
        
        error_log("HOPE SEAT BLOCKING: Filtered " . count($blocked_seats) . " blocked seats from availability for event {$event_id}");
        
        return $filtered_seats;
    }
    
    /**
     * AJAX handler for creating seat blocks
     */
    public function ajax_create_seat_block() {
        // Security checks
        if (!current_user_can('manage_woocommerce') || !wp_verify_nonce($_POST['nonce'], 'hope_seat_block_action')) {
            wp_send_json_error(array('error' => 'Access denied'));
        }
        
        $event_id = intval($_POST['event_id']);
        $seat_ids = array_map('sanitize_text_field', $_POST['seat_ids']);
        $block_type = sanitize_text_field($_POST['block_type']);
        $reason = sanitize_textarea_field($_POST['reason']);
        $valid_from = !empty($_POST['valid_from']) ? sanitize_text_field($_POST['valid_from']) : null;
        $valid_until = !empty($_POST['valid_until']) ? sanitize_text_field($_POST['valid_until']) : null;
        
        $result = $this->create_seat_block($event_id, $seat_ids, $block_type, $reason, $valid_from, $valid_until);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX handler for removing seat blocks
     */
    public function ajax_remove_seat_block() {
        // Security checks
        if (!current_user_can('manage_woocommerce') || !wp_verify_nonce($_POST['nonce'], 'hope_seat_block_action')) {
            wp_send_json_error(array('error' => 'Access denied'));
        }
        
        $block_id = intval($_POST['block_id']);
        
        $result = $this->remove_seat_block($block_id);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX handler for getting seat blocks for an event
     */
    public function ajax_get_seat_blocks() {
        // Security checks
        if (!current_user_can('manage_woocommerce') || !wp_verify_nonce($_POST['nonce'], 'hope_seat_block_action')) {
            wp_send_json_error(array('error' => 'Access denied'));
        }
        
        $event_id = intval($_POST['event_id']);
        
        $blocks = HOPE_Database_Selective_Refunds::get_active_seat_blocks($event_id);
        
        wp_send_json_success(array(
            'blocks' => $blocks,
            'blocked_seats' => HOPE_Database_Selective_Refunds::get_blocked_seat_ids($event_id)
        ));
    }
    
    /**
     * Get block type descriptions
     * @return array Block type labels and descriptions
     */
    public static function get_block_types() {
        return array(
            'manual' => array(
                'label' => 'Manual Block',
                'description' => 'General administrative block',
                'color' => '#6c757d'
            ),
            'equipment' => array(
                'label' => 'Equipment',
                'description' => 'Blocked for technical equipment',
                'color' => '#fd7e14'
            ),
            'vip' => array(
                'label' => 'VIP Reserved',
                'description' => 'Reserved for VIP guests',
                'color' => '#6f42c1'
            ),
            'maintenance' => array(
                'label' => 'Maintenance',
                'description' => 'Seat maintenance or repair',
                'color' => '#dc3545'
            ),
            'guest-list' => array(
                'label' => 'Guest List',
                'description' => 'Reserved for guest list',
                'color' => '#198754'
            ),
            'accessibility' => array(
                'label' => 'Accessibility',
                'description' => 'Accessibility accommodation',
                'color' => '#0dcaf0'
            )
        );
    }
    
    /**
     * Check if seat blocking functionality is available
     * @return bool Whether seat blocking can be used
     */
    public static function is_available() {
        return class_exists('HOPE_Database_Selective_Refunds') && 
               HOPE_Database_Selective_Refunds::is_seat_blocking_ready();
    }
}