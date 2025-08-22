<?php
/**
 * Seat map management for HOPE Theater Seating
 * File: /wp-content/plugins/hope-theater-seating/includes/class-seat-maps.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class HOPE_Seating_Seat_Maps {
    
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'hope_seating_seat_maps';
    }
    
    /**
     * Get all seats for a venue
     */
    public function get_venue_seats($venue_id) {
        global $wpdb;
        
        // Try new architecture first (venue_id is actually pricing_map_id)
        if (class_exists('HOPE_Pricing_Maps_Manager')) {
            $pricing_manager = new HOPE_Pricing_Maps_Manager();
            $seats_with_pricing = $pricing_manager->get_seats_with_pricing($venue_id);
            
            if (!empty($seats_with_pricing)) {
                // Convert to old format for compatibility
                $converted_seats = array();
                foreach ($seats_with_pricing as $seat) {
                    $converted_seats[] = (object) array(
                        'id' => $seat->id,
                        'venue_id' => $venue_id,
                        'seat_id' => $seat->seat_id,
                        'section' => $seat->section,
                        'row_number' => $seat->row_number,
                        'seat_number' => $seat->seat_number,
                        'level' => $seat->level,
                        'x_coordinate' => $seat->x_coordinate,
                        'y_coordinate' => $seat->y_coordinate,
                        'pricing_tier' => $seat->pricing_tier,
                        'seat_type' => $seat->seat_type,
                        'is_accessible' => $seat->is_accessible,
                        'is_blocked' => $seat->is_blocked,
                        'price' => $seat->price,
                        'tier_name' => $this->get_tier_name($seat->pricing_tier),
                        'color' => $this->get_tier_color($seat->pricing_tier)
                    );
                }
                return $converted_seats;
            }
        }
        
        // Fallback to old system
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE venue_id = %d 
             ORDER BY section, `row_number`, seat_number",
            $venue_id
        ));
    }
    
    /**
     * Get tier name from pricing tier code
     */
    private function get_tier_name($pricing_tier) {
        $tier_names = array(
            'P1' => 'Premium',
            'P2' => 'Standard', 
            'P3' => 'Value',
            'AA' => 'Accessible'
        );
        return isset($tier_names[$pricing_tier]) ? $tier_names[$pricing_tier] : 'Standard';
    }
    
    /**
     * Get tier color from pricing tier code
     */
    private function get_tier_color($pricing_tier) {
        $tier_colors = array(
            'P1' => '#9b59b6',
            'P2' => '#3498db',
            'P3' => '#17a2b8', 
            'AA' => '#e67e22'
        );
        return isset($tier_colors[$pricing_tier]) ? $tier_colors[$pricing_tier] : '#3498db';
    }
    
    /**
     * Get seats by section
     */
    public function get_section_seats($venue_id, $section) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE venue_id = %d AND section = %s 
             ORDER BY `row_number`, seat_number",
            $venue_id,
            $section
        ));
    }
    
    /**
     * Get a single seat
     */
    public function get_seat($seat_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $seat_id
        ));
    }
    
    /**
     * Get seat by venue and seat ID
     */
    public function get_seat_by_id($venue_id, $seat_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE venue_id = %d AND seat_id = %s",
            $venue_id,
            $seat_id
        ));
    }
    
    /**
     * Create a new seat
     */
    public function create_seat($data) {
        global $wpdb;
        
        $defaults = array(
            'venue_id' => 0,
            'seat_id' => '',
            'section' => '',
            'row_number' => 0,
            'seat_number' => 0,
            'level' => 'floor',
            'x_coordinate' => 0,
            'y_coordinate' => 0,
            'pricing_tier' => 'General',
            'seat_type' => 'standard',
            'is_accessible' => false,
            'is_blocked' => false,
            'notes' => ''
        );
        
        $data = wp_parse_args($data, $defaults);
        
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'venue_id' => $data['venue_id'],
                'seat_id' => $data['seat_id'],
                'section' => $data['section'],
                'row_number' => $data['row_number'],
                'seat_number' => $data['seat_number'],
                'level' => $data['level'],
                'x_coordinate' => $data['x_coordinate'],
                'y_coordinate' => $data['y_coordinate'],
                'pricing_tier' => $data['pricing_tier'],
                'seat_type' => $data['seat_type'],
                'is_accessible' => $data['is_accessible'],
                'is_blocked' => $data['is_blocked'],
                'notes' => $data['notes']
            ),
            array('%d', '%s', '%s', '%d', '%d', '%s', '%f', '%f', '%s', '%s', '%d', '%d', '%s')
        );
        
        if ($result === false) {
            return false;
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Update a seat
     */
    public function update_seat($seat_id, $data) {
        global $wpdb;
        
        $seat = $this->get_seat($seat_id);
        if (!$seat) {
            return false;
        }
        
        $update_data = array();
        $format = array();
        
        $fields = array(
            'x_coordinate' => '%f',
            'y_coordinate' => '%f',
            'pricing_tier' => '%s',
            'seat_type' => '%s',
            'is_accessible' => '%d',
            'is_blocked' => '%d',
            'notes' => '%s'
        );
        
        foreach ($fields as $field => $field_format) {
            if (isset($data[$field])) {
                $update_data[$field] = $data[$field];
                $format[] = $field_format;
            }
        }
        
        if (empty($update_data)) {
            return false;
        }
        
        return $wpdb->update(
            $this->table_name,
            $update_data,
            array('id' => $seat_id),
            $format,
            array('%d')
        );
    }
    
    /**
     * Delete a seat
     */
    public function delete_seat($seat_id) {
        global $wpdb;
        
        // Check if seat has bookings
        $event_seats_table = $wpdb->prefix . 'hope_seating_event_seats';
        $has_bookings = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $event_seats_table WHERE seat_map_id = %d",
            $seat_id
        ));
        
        if ($has_bookings > 0) {
            return new WP_Error('seat_has_bookings', 'Cannot delete seat with existing bookings');
        }
        
        return $wpdb->delete(
            $this->table_name,
            array('id' => $seat_id),
            array('%d')
        );
    }
    
    /**
     * Bulk create seats
     */
    public function bulk_create_seats($seats) {
        global $wpdb;
        
        $success_count = 0;
        $errors = array();
        
        foreach ($seats as $seat) {
            $result = $this->create_seat($seat);
            if ($result) {
                $success_count++;
            } else {
                $errors[] = "Failed to create seat: " . $seat['seat_id'];
            }
        }
        
        return array(
            'success' => $success_count,
            'errors' => $errors
        );
    }
    
    /**
     * Get seat availability for an event
     */
    public function get_seat_availability($event_id, $venue_id) {
        global $wpdb;
        
        $event_seats_table = $wpdb->prefix . 'hope_seating_event_seats';
        
        // Get all seats for the venue
        $all_seats = $this->get_venue_seats($venue_id);
        
        // Get booked/reserved seats for this event
        $booked_seats = $wpdb->get_results($wpdb->prepare(
            "SELECT seat_map_id, status, reserved_until 
             FROM $event_seats_table 
             WHERE event_id = %d AND venue_id = %d",
            $event_id,
            $venue_id
        ), OBJECT_K);
        
        // Combine data
        $availability = array();
        foreach ($all_seats as $seat) {
            $seat_status = 'available';
            
            if (isset($booked_seats[$seat->id])) {
                $booking = $booked_seats[$seat->id];
                
                if ($booking->status === 'booked') {
                    $seat_status = 'booked';
                } elseif ($booking->status === 'reserved' && 
                         strtotime($booking->reserved_until) > time()) {
                    $seat_status = 'reserved';
                }
            }
            
            if ($seat->is_blocked) {
                $seat_status = 'blocked';
            }
            
            $availability[$seat->seat_id] = array(
                'seat' => $seat,
                'status' => $seat_status
            );
        }
        
        return $availability;
    }
}
?>