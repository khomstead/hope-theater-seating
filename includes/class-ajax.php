<?php
/**
 * AJAX handlers for HOPE Theater Seating
 * File: /wp-content/plugins/hope-theater-seating/includes/class-ajax.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class HOPE_Seating_Ajax {
    
    public function __construct() {
        // Public AJAX actions
        add_action('wp_ajax_hope_get_seating_chart', array($this, 'get_seating_chart'));
        add_action('wp_ajax_nopriv_hope_get_seating_chart', array($this, 'get_seating_chart'));
        
        add_action('wp_ajax_hope_check_seat_availability', array($this, 'check_seat_availability'));
        add_action('wp_ajax_nopriv_hope_check_seat_availability', array($this, 'check_seat_availability'));
        
        add_action('wp_ajax_hope_reserve_seats', array($this, 'reserve_seats'));
        add_action('wp_ajax_nopriv_hope_reserve_seats', array($this, 'reserve_seats'));
        
        // Admin AJAX actions
        add_action('wp_ajax_hope_admin_get_venue_details', array($this, 'get_venue_details'));
        add_action('wp_ajax_hope_admin_update_seat', array($this, 'update_seat'));
        add_action('wp_ajax_hope_admin_bulk_update_seats', array($this, 'bulk_update_seats'));
    }
    
    /**
     * Get seating chart data
     */
    public function get_seating_chart() {
        check_ajax_referer('hope_seating_nonce', 'nonce');
        
        $venue_id = intval($_POST['venue_id']);
        $event_id = intval($_POST['event_id']);
        
        if (!$venue_id || !$event_id) {
            wp_send_json_error('Invalid parameters');
        }
        
        // Get venue data
        $venues = new HOPE_Seating_Venues();
        $venue = $venues->get_venue($venue_id);
        
        if (!$venue) {
            wp_send_json_error('Venue not found');
        }
        
        // Get seats
        $seat_maps = new HOPE_Seating_Seat_Maps();
        $seats = $seat_maps->get_venue_seats($venue_id);
        
        // Get seat availability
        $availability = $seat_maps->get_seat_availability($event_id, $venue_id);
        
        // Get pricing tiers
        global $wpdb;
        $pricing_table = $wpdb->prefix . 'hope_seating_pricing_tiers';
        $pricing_tiers = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $pricing_table WHERE venue_id = %d ORDER BY sort_order",
            $venue_id
        ), OBJECT_K);
        
        // Format seat data for frontend
        $seat_data = array();
        foreach ($seats as $seat) {
            $status = isset($availability[$seat->seat_id]) ? 
                     $availability[$seat->seat_id]['status'] : 'available';
            
            $tier = isset($pricing_tiers[$seat->pricing_tier]) ? 
                   $pricing_tiers[$seat->pricing_tier] : null;
            
            $seat_data[] = array(
                'id' => $seat->id,
                'seat_id' => $seat->seat_id,
                'section' => $seat->section,
                'row' => $seat->row_number,
                'seat' => $seat->seat_number,
                'x' => floatval($seat->x_coordinate),
                'y' => floatval($seat->y_coordinate),
                'status' => $status,
                'pricing_tier' => $seat->pricing_tier,
                'price' => $tier ? floatval($tier->base_price) : 0,
                'color' => $tier ? $tier->color_code : '#549e39',
                'is_accessible' => $seat->is_accessible,
                'level' => $seat->level
            );
        }
        
        // Get venue configuration
        $config = json_decode($venue->configuration, true);
        
        wp_send_json_success(array(
            'venue' => array(
                'id' => $venue->id,
                'name' => $venue->name,
                'configuration' => $config
            ),
            'seats' => $seat_data,
            'pricing_tiers' => array_values($pricing_tiers)
        ));
    }
    
    /**
     * Check seat availability
     */
    public function check_seat_availability() {
        check_ajax_referer('hope_seating_nonce', 'nonce');
        
        $event_id = intval($_POST['event_id']);
        $seat_ids = isset($_POST['seat_ids']) ? array_map('intval', $_POST['seat_ids']) : array();
        
        if (!$event_id || empty($seat_ids)) {
            wp_send_json_error('Invalid parameters');
        }
        
        global $wpdb;
        $event_seats_table = $wpdb->prefix . 'hope_seating_event_seats';
        
        // Check if any seats are already booked
        $placeholders = array_fill(0, count($seat_ids), '%d');
        $query = $wpdb->prepare(
            "SELECT seat_map_id FROM $event_seats_table 
             WHERE event_id = %d 
             AND seat_map_id IN (" . implode(',', $placeholders) . ")
             AND (status = 'booked' OR (status = 'reserved' AND reserved_until > NOW()))",
            array_merge(array($event_id), $seat_ids)
        );
        
        $unavailable = $wpdb->get_col($query);
        
        wp_send_json_success(array(
            'available' => array_diff($seat_ids, $unavailable),
            'unavailable' => $unavailable
        ));
    }
    
    /**
     * Reserve seats temporarily
     */
    public function reserve_seats() {
        check_ajax_referer('hope_seating_nonce', 'nonce');
        
        $event_id = intval($_POST['event_id']);
        $venue_id = intval($_POST['venue_id']);
        $seat_ids = isset($_POST['seat_ids']) ? array_map('intval', $_POST['seat_ids']) : array();
        
        if (!$event_id || !$venue_id || empty($seat_ids)) {
            wp_send_json_error('Invalid parameters');
        }
        
        global $wpdb;
        $event_seats_table = $wpdb->prefix . 'hope_seating_event_seats';
        
        // Get reservation time from settings
        $options = get_option('hope_seating_options', array());
        $reservation_time = isset($options['reservation_time']) ? intval($options['reservation_time']) : 15;
        $reserved_until = date('Y-m-d H:i:s', strtotime("+{$reservation_time} minutes"));
        
        $customer_id = get_current_user_id();
        $session_id = session_id() ?: wp_generate_password(32, false);
        
        $success = true;
        $reserved = array();
        
        foreach ($seat_ids as $seat_id) {
            // Check if seat is available
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $event_seats_table 
                 WHERE event_id = %d AND seat_map_id = %d",
                $event_id,
                $seat_id
            ));
            
            if ($existing && $existing->status === 'booked') {
                continue; // Skip booked seats
            }
            
            if ($existing) {
                // Update existing reservation
                $result = $wpdb->update(
                    $event_seats_table,
                    array(
                        'status' => 'reserved',
                        'reserved_until' => $reserved_until,
                        'customer_id' => $customer_id,
                        'booking_reference' => $session_id
                    ),
                    array(
                        'event_id' => $event_id,
                        'seat_map_id' => $seat_id
                    ),
                    array('%s', '%s', '%d', '%s'),
                    array('%d', '%d')
                );
            } else {
                // Create new reservation
                $result = $wpdb->insert(
                    $event_seats_table,
                    array(
                        'event_id' => $event_id,
                        'venue_id' => $venue_id,
                        'seat_map_id' => $seat_id,
                        'customer_id' => $customer_id,
                        'status' => 'reserved',
                        'reserved_until' => $reserved_until,
                        'booking_reference' => $session_id
                    ),
                    array('%d', '%d', '%d', '%d', '%s', '%s', '%s')
                );
            }
            
            if ($result !== false) {
                $reserved[] = $seat_id;
            }
        }
        
        wp_send_json_success(array(
            'reserved' => $reserved,
            'reserved_until' => $reserved_until,
            'session_id' => $session_id
        ));
    }
    
    /**
     * Get venue details (admin)
     */
    public function get_venue_details() {
        check_ajax_referer('hope_seating_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $venue_id = intval($_POST['venue_id']);
        
        if (!$venue_id) {
            wp_send_json_error('Invalid venue ID');
        }
        
        $venues = new HOPE_Seating_Venues();
        $stats = $venues->get_venue_stats($venue_id);
        
        if (!$stats) {
            wp_send_json_error('Venue not found');
        }
        
        wp_send_json_success($stats);
    }
    
    /**
     * Update seat (admin)
     */
    public function update_seat() {
        check_ajax_referer('hope_seating_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $seat_id = intval($_POST['seat_id']);
        $data = isset($_POST['data']) ? $_POST['data'] : array();
        
        if (!$seat_id || empty($data)) {
            wp_send_json_error('Invalid parameters');
        }
        
        $seat_maps = new HOPE_Seating_Seat_Maps();
        $result = $seat_maps->update_seat($seat_id, $data);
        
        if ($result === false) {
            wp_send_json_error('Failed to update seat');
        }
        
        wp_send_json_success('Seat updated successfully');
    }
    
    /**
     * Bulk update seats (admin)
     */
    public function bulk_update_seats() {
        check_ajax_referer('hope_seating_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $seat_ids = isset($_POST['seat_ids']) ? array_map('intval', $_POST['seat_ids']) : array();
        $data = isset($_POST['data']) ? $_POST['data'] : array();
        
        if (empty($seat_ids) || empty($data)) {
            wp_send_json_error('Invalid parameters');
        }
        
        $seat_maps = new HOPE_Seating_Seat_Maps();
        $success = 0;
        $failed = 0;
        
        foreach ($seat_ids as $seat_id) {
            $result = $seat_maps->update_seat($seat_id, $data);
            if ($result !== false) {
                $success++;
            } else {
                $failed++;
            }
        }
        
        wp_send_json_success(array(
            'updated' => $success,
            'failed' => $failed
        ));
    }
}
?>