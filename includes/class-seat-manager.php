<?php
/**
 * Seat Manager for HOPE Theater Seating
 * Handles seat population and management for 485 seats
 */

if (!defined('ABSPATH')) {
    exit;
}

class HOPE_Seat_Manager {
    
    private $table_name;
    private $venue_id;
    
    /**
     * Seat distribution based on architectural plans - 497 seats total
     */
    private $seat_distribution = array(
        'orchestra' => array(
            'A' => array(
                'rows' => array('A', 'B', 'C', 'D', 'E', 'F', 'G'),
                'seats_per_row' => array('A' => 6, 'B' => 7, 'C' => 7, 'D' => 8, 'E' => 8, 'F' => 8, 'G' => 9),
                'pricing' => 'P2',
                'angle_start' => -60,
                'angle_end' => -30
            ),
            'B' => array(
                'rows' => array('A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'),
                'seats_per_row' => array('A' => 8, 'B' => 9, 'C' => 10, 'D' => 10, 'E' => 11, 'F' => 11, 'G' => 12, 'H' => 12),
                'pricing' => 'P1',
                'angle_start' => -30,
                'angle_end' => -10
            ),
            'C' => array(
                'rows' => array('A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'),
                'seats_per_row' => array('A' => 12, 'B' => 13, 'C' => 14, 'D' => 14, 'E' => 15, 'F' => 15, 'G' => 16, 'H' => 16, 'I' => 12),
                'pricing' => 'P1',
                'angle_start' => -10,
                'angle_end' => 10
            ),
            'D' => array(
                'rows' => array('A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'AA'),
                'seats_per_row' => array('A' => 8, 'B' => 9, 'C' => 10, 'D' => 10, 'E' => 11, 'F' => 11, 'G' => 12, 'H' => 12, 'AA' => 8),
                'pricing' => 'P1',
                'pricing_overrides' => array('AA' => 'AA'),
                'angle_start' => 10,
                'angle_end' => 30
            ),
            'E' => array(
                'rows' => array('A', 'B', 'C', 'D', 'E', 'F', 'G'),
                'seats_per_row' => array('A' => 6, 'B' => 7, 'C' => 7, 'D' => 8, 'E' => 8, 'F' => 8, 'G' => 9),
                'pricing' => 'P2',
                'angle_start' => 30,
                'angle_end' => 60
            )
        ),
        'balcony' => array(
            'F' => array(
                'rows' => array('J', 'K', 'L'),
                'seats_per_row' => array('J' => 12, 'K' => 13, 'L' => 14),
                'pricing' => 'P3',
                'angle_start' => -45,
                'angle_end' => -15
            ),
            'G' => array(
                'rows' => array('J', 'K', 'L'),
                'seats_per_row' => array('J' => 8, 'K' => 9, 'L' => 10),
                'pricing' => 'P3',
                'angle_start' => -15,
                'angle_end' => 5,
                'x_offset' => -50
            ),
            'H' => array(
                'rows' => array('J', 'K', 'L'),
                'seats_per_row' => array('J' => 7, 'K' => 8, 'L' => 9),
                'pricing' => 'P3',
                'angle_start' => 5,
                'angle_end' => 45
            )
        )
    );
    
    /**
     * Pricing tier configuration
     */
    private $pricing_tiers = array(
        'P1' => array('name' => 'VIP', 'price' => 50, 'color' => '#9b59b6'),
        'P2' => array('name' => 'Premium', 'price' => 35, 'color' => '#3498db'),
        'P3' => array('name' => 'General', 'price' => 25, 'color' => '#17a2b8'),
        'AA' => array('name' => 'Accessible', 'price' => 25, 'color' => '#e67e22')
    );
    
    public function __construct($venue_id = 1) {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'hope_seating_seat_maps';
        $this->venue_id = $venue_id;
    }
    
    /**
     * Populate all 497 seats for the venue
     */
    public function populate_seats() {
        global $wpdb;
        
        // Clear existing seats for this venue
        $wpdb->delete($this->table_name, array('venue_id' => $this->venue_id));
        
        $total_seats = 0;
        
        // Process orchestra sections
        foreach ($this->seat_distribution['orchestra'] as $section => $config) {
            $seats_created = $this->create_section_seats($section, $config, 'orchestra');
            $total_seats += $seats_created;
        }
        
        // Process balcony sections
        foreach ($this->seat_distribution['balcony'] as $section => $config) {
            $seats_created = $this->create_section_seats($section, $config, 'balcony');
            $total_seats += $seats_created;
        }
        
        // Update venue total
        $wpdb->update(
            $wpdb->prefix . 'hope_seating_venues',
            array('total_seats' => $total_seats),
            array('id' => $this->venue_id)
        );
        
        return $total_seats;
    }
    
    /**
     * Create seats for a specific section
     */
    private function create_section_seats($section, $config, $level) {
        global $wpdb;
        
        $seats_created = 0;
        $center_x = 600;
        $center_y = 800;
        
        // Adjust base radius for level
        $base_radius = ($level === 'orchestra') ? 250 : 350;
        $row_spacing = 35;
        
        foreach ($config['rows'] as $row_index => $row_letter) {
            $seats_in_row = $config['seats_per_row'][$row_letter];
            $radius = $base_radius + ($row_index * $row_spacing);
            
            // Calculate angular spacing for this row
            $angle_range = $config['angle_end'] - $config['angle_start'];
            $angle_step = $angle_range / ($seats_in_row + 1);
            
            // Determine seat numbering direction
            $reverse_numbering = in_array($section, array('B', 'D', 'F', 'G', 'H'));
            
            for ($seat_num = 1; $seat_num <= $seats_in_row; $seat_num++) {
                // Calculate angle for this seat
                $seat_index = $reverse_numbering ? ($seats_in_row - $seat_num + 1) : $seat_num;
                $angle = $config['angle_start'] + ($seat_index * $angle_step);
                $angle_rad = deg2rad($angle - 90); // Adjust for stage at top
                
                // Calculate coordinates
                $x = $center_x + ($radius * cos($angle_rad));
                $y = $center_y + ($radius * sin($angle_rad));
                
                // Apply section-specific offsets
                if (isset($config['x_offset'])) {
                    $x += $config['x_offset'];
                }
                
                // Determine pricing tier
                $pricing_tier = $config['pricing'];
                if (isset($config['pricing_overrides'][$row_letter])) {
                    $pricing_tier = $config['pricing_overrides'][$row_letter];
                }
                
                // Determine if accessible
                $is_accessible = ($row_letter === 'AA') ? 1 : 0;
                
                // Create seat ID
                $seat_id = $section . $row_letter . $seat_num;
                
                // Insert seat
                $wpdb->insert(
                    $this->table_name,
                    array(
                        'venue_id' => $this->venue_id,
                        'seat_id' => $seat_id,
                        'section' => $section,
                        'row_number' => ord($row_letter) - ord('A') + 1,
                        'seat_number' => $seat_num,
                        'level' => $level,
                        'x_coordinate' => round($x, 2),
                        'y_coordinate' => round($y, 2),
                        'pricing_tier' => $pricing_tier,
                        'seat_type' => $is_accessible ? 'accessible' : 'standard',
                        'is_accessible' => $is_accessible,
                        'is_blocked' => 0
                    ),
                    array('%d', '%s', '%s', '%d', '%d', '%s', '%f', '%f', '%s', '%s', '%d', '%d')
                );
                
                $seats_created++;
            }
        }
        
        // Update venue total_seats count in database to reflect actual populated seats
        $venues_table = $wpdb->prefix . 'hope_seating_venues';
        $wpdb->update(
            $venues_table,
            array('total_seats' => $seats_created),
            array('id' => $this->venue_id),
            array('%d'),
            array('%d')
        );
        
        return $seats_created;
    }
    
    /**
     * Get all seats for a venue
     */
    public function get_venue_seats($venue_id = null) {
        global $wpdb;
        
        if (!$venue_id) {
            $venue_id = $this->venue_id;
        }
        
        $seats = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
            WHERE venue_id = %d 
            ORDER BY section, row_number, seat_number",
            $venue_id
        ));
        
        // Add pricing info to each seat
        foreach ($seats as &$seat) {
            if (isset($this->pricing_tiers[$seat->pricing_tier])) {
                $seat->price = $this->pricing_tiers[$seat->pricing_tier]['price'];
                $seat->tier_name = $this->pricing_tiers[$seat->pricing_tier]['name'];
                $seat->color = $this->pricing_tiers[$seat->pricing_tier]['color'];
            }
        }
        
        return $seats;
    }
    
    /**
     * Get seats by section
     */
    public function get_section_seats($venue_id, $section) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
            WHERE venue_id = %d AND section = %s 
            ORDER BY row_number, seat_number",
            $venue_id,
            $section
        ));
    }
    
    /**
     * Check seat availability
     */
    public function check_availability($venue_id, $event_id, $seat_ids) {
        global $wpdb;
        
        $placeholders = array_fill(0, count($seat_ids), '%s');
        $placeholders_str = implode(',', $placeholders);
        
        $query = $wpdb->prepare(
            "SELECT s.seat_id, 
                    CASE 
                        WHEN b.id IS NOT NULL THEN 'sold'
                        WHEN h.id IS NOT NULL AND h.expires_at > NOW() THEN 'held'
                        WHEN s.is_blocked = 1 THEN 'blocked'
                        ELSE 'available'
                    END as status
            FROM {$this->table_name} s
            LEFT JOIN {$wpdb->prefix}hope_seating_bookings b 
                ON s.seat_id = b.seat_id AND b.event_id = %d
            LEFT JOIN {$wpdb->prefix}hope_seating_holds h 
                ON s.seat_id = h.seat_id AND h.event_id = %d
            WHERE s.venue_id = %d AND s.seat_id IN ($placeholders_str)",
            array_merge(array($event_id, $event_id, $venue_id), $seat_ids)
        );
        
        return $wpdb->get_results($query, OBJECT_K);
    }
    
    /**
     * Get pricing summary for venue
     */
    public function get_pricing_summary($venue_id = null) {
        global $wpdb;
        
        if (!$venue_id) {
            $venue_id = $this->venue_id;
        }
        
        $summary = $wpdb->get_results($wpdb->prepare(
            "SELECT pricing_tier, COUNT(*) as count 
            FROM {$this->table_name} 
            WHERE venue_id = %d 
            GROUP BY pricing_tier",
            $venue_id
        ));
        
        $result = array();
        foreach ($summary as $tier) {
            if (isset($this->pricing_tiers[$tier->pricing_tier])) {
                $result[$tier->pricing_tier] = array(
                    'count' => $tier->count,
                    'name' => $this->pricing_tiers[$tier->pricing_tier]['name'],
                    'price' => $this->pricing_tiers[$tier->pricing_tier]['price'],
                    'color' => $this->pricing_tiers[$tier->pricing_tier]['color'],
                    'total_value' => $tier->count * $this->pricing_tiers[$tier->pricing_tier]['price']
                );
            }
        }
        
        return $result;
    }
}