<?php
/**
 * Venue management for HOPE Theater Seating
 * File: /wp-content/plugins/hope-theater-seating/includes/class-venues.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class HOPE_Seating_Venues {
    
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'hope_seating_venues';
    }
    
    /**
     * Get all venues
     */
    public function get_all_venues($status = 'active') {
        global $wpdb;
        
        if ($status === 'all') {
            return $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY name");
        }
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE status = %s ORDER BY name",
            $status
        ));
    }
    
    /**
     * Get a single venue by ID
     */
    public function get_venue($venue_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $venue_id
        ));
    }
    
    /**
     * Get venue by slug
     */
    public function get_venue_by_slug($slug) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE slug = %s",
            $slug
        ));
    }
    
    /**
     * Create a new venue
     */
    public function create_venue($data) {
        global $wpdb;
        
        $defaults = array(
            'name' => '',
            'slug' => '',
            'description' => '',
            'total_seats' => 0,
            'configuration' => '',
            'svg_template' => '',
            'status' => 'active'
        );
        
        $data = wp_parse_args($data, $defaults);
        
        // Generate slug if not provided
        if (empty($data['slug'])) {
            $data['slug'] = sanitize_title($data['name']);
        }
        
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'],
                'total_seats' => $data['total_seats'],
                'configuration' => $data['configuration'],
                'svg_template' => $data['svg_template'],
                'status' => $data['status']
            ),
            array('%s', '%s', '%s', '%d', '%s', '%s', '%s')
        );
        
        if ($result === false) {
            return false;
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Update a venue
     */
    public function update_venue($venue_id, $data) {
        global $wpdb;
        
        $venue = $this->get_venue($venue_id);
        if (!$venue) {
            return false;
        }
        
        $update_data = array();
        $format = array();
        
        if (isset($data['name'])) {
            $update_data['name'] = $data['name'];
            $format[] = '%s';
        }
        
        if (isset($data['slug'])) {
            $update_data['slug'] = $data['slug'];
            $format[] = '%s';
        }
        
        if (isset($data['description'])) {
            $update_data['description'] = $data['description'];
            $format[] = '%s';
        }
        
        if (isset($data['total_seats'])) {
            $update_data['total_seats'] = $data['total_seats'];
            $format[] = '%d';
        }
        
        if (isset($data['configuration'])) {
            $update_data['configuration'] = $data['configuration'];
            $format[] = '%s';
        }
        
        if (isset($data['svg_template'])) {
            $update_data['svg_template'] = $data['svg_template'];
            $format[] = '%s';
        }
        
        if (isset($data['status'])) {
            $update_data['status'] = $data['status'];
            $format[] = '%s';
        }
        
        if (empty($update_data)) {
            return false;
        }
        
        return $wpdb->update(
            $this->table_name,
            $update_data,
            array('id' => $venue_id),
            $format,
            array('%d')
        );
    }
    
    /**
     * Delete a venue
     */
    public function delete_venue($venue_id) {
        global $wpdb;
        
        // Check if venue has associated seats or events
        $seat_maps_table = $wpdb->prefix . 'hope_seating_seat_maps';
        $has_seats = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $seat_maps_table WHERE venue_id = %d",
            $venue_id
        ));
        
        if ($has_seats > 0) {
            return new WP_Error('venue_has_seats', 'Cannot delete venue with existing seats');
        }
        
        return $wpdb->delete(
            $this->table_name,
            array('id' => $venue_id),
            array('%d')
        );
    }
    
    /**
     * Get venue statistics
     */
    public function get_venue_stats($venue_id) {
        global $wpdb;
        
        $venue = $this->get_venue($venue_id);
        if (!$venue) {
            return false;
        }
        
        $seat_maps_table = $wpdb->prefix . 'hope_seating_seat_maps';
        $event_seats_table = $wpdb->prefix . 'hope_seating_event_seats';
        
        // Get seat counts by section
        $sections = $wpdb->get_results($wpdb->prepare(
            "SELECT section, COUNT(*) as count 
             FROM $seat_maps_table 
             WHERE venue_id = %d 
             GROUP BY section",
            $venue_id
        ));
        
        // Get booked seats count
        $booked_seats = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) 
             FROM $event_seats_table 
             WHERE venue_id = %d AND status = 'booked'",
            $venue_id
        ));
        
        return array(
            'venue' => $venue,
            'sections' => $sections,
            'booked_seats' => $booked_seats,
            'available_seats' => $venue->total_seats - $booked_seats
        );
    }
}
?>