<?php
/**
 * Pricing Maps Manager for HOPE Theater Seating
 * Handles different pricing configurations that can be applied to physical seats
 */

if (!defined('ABSPATH')) {
    exit;
}

class HOPE_Pricing_Maps_Manager {
    
    private $pricing_maps_table;
    private $seat_pricing_table;
    private $physical_seats_table;
    
    /**
     * Pricing tier definitions
     */
    private $pricing_tiers = array(
        'P1' => array('name' => 'Premium', 'default_price' => 50, 'color' => '#9b59b6'),
        'P2' => array('name' => 'Standard', 'default_price' => 35, 'color' => '#3498db'),
        'P3' => array('name' => 'Value', 'default_price' => 25, 'color' => '#17a2b8'),
        'AA' => array('name' => 'Accessible', 'default_price' => 25, 'color' => '#e67e22')
    );
    
    public function __construct() {
        global $wpdb;
        $this->pricing_maps_table = $wpdb->prefix . 'hope_seating_pricing_maps';
        $this->seat_pricing_table = $wpdb->prefix . 'hope_seating_seat_pricing';
        $this->physical_seats_table = $wpdb->prefix . 'hope_seating_physical_seats';
    }
    
    /**
     * Create a new pricing map
     */
    public function create_pricing_map($name, $description = '', $theater_id = 'hope_main_theater', $is_default = false) {
        global $wpdb;
        
        $result = $wpdb->insert(
            $this->pricing_maps_table,
            array(
                'name' => $name,
                'description' => $description,
                'theater_id' => $theater_id,
                'is_default' => $is_default,
                'status' => 'active'
            ),
            array('%s', '%s', '%s', '%d', '%s')
        );
        
        if ($result) {
            return $wpdb->insert_id;
        }
        
        return false;
    }
    
    /**
     * Create the standard pricing map based on spreadsheet data
     */
    public function create_standard_pricing_map($theater_id = 'hope_main_theater') {
        global $wpdb;
        
        // Create the pricing map
        $pricing_map_id = $this->create_pricing_map(
            'HOPE Theater - Standard Pricing',
            'Standard pricing configuration based on architectural spreadsheet layout',
            $theater_id,
            true
        );
        
        if (!$pricing_map_id) {
            return false;
        }
        
        // Get all physical seats
        $physical_seats = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->physical_seats_table} WHERE theater_id = %s ORDER BY section, `row_number`, seat_number",
            $theater_id
        ));
        
        if (empty($physical_seats)) {
            return false;
        }
        
        // Apply pricing based on exact spreadsheet layout
        foreach ($physical_seats as $seat) {
            $pricing_tier = $this->get_seat_pricing_tier($seat->section, $seat->row_number, $seat->seat_number, $seat->is_accessible);
            $price = $this->pricing_tiers[$pricing_tier]['default_price'];
            
            $wpdb->insert(
                $this->seat_pricing_table,
                array(
                    'pricing_map_id' => $pricing_map_id,
                    'physical_seat_id' => $seat->id,
                    'pricing_tier' => $pricing_tier,
                    'price' => $price
                ),
                array('%d', '%d', '%s', '%f')
            );
        }
        
        return $pricing_map_id;
    }
    
    /**
     * Determine pricing tier for a specific seat based on EXACT spreadsheet data
     * REWRITTEN to match the exact target numbers: P1=108, P2=292, P3=88, AA=9
     */
    private function get_seat_pricing_tier($section, $row_number, $seat_number, $is_accessible) {
        // If accessible, always AA
        if ($is_accessible) {
            return 'AA';
        }
        
        // Orchestra Level Sections (A-E)
        
        // Section A: 70 seats total
        if ($section === 'A') {
            if ($row_number == 1) return 'P1';  // Row 1: all P1
            if (in_array($row_number, [2, 3])) return 'P2';  // Rows 2-3: all P2
            // Rows 4-8: Mixed P2/P3 (more P3 on edges)
            if ($row_number == 4) {
                return ($seat_number == 1) ? 'P3' : 'P2';
            }
            if ($row_number == 5) {
                return (in_array($seat_number, [1, 2])) ? 'P3' : 'P2';
            }
            if ($row_number == 6) {
                return (in_array($seat_number, [1, 2, 3])) ? 'P3' : 'P2';
            }
            if ($row_number == 7) {
                return (in_array($seat_number, [1, 2, 3, 4])) ? 'P3' : 'P2';
            }
            if ($row_number == 8) {
                return (in_array($seat_number, [1, 2, 3, 4, 5])) ? 'P3' : 'P2';
            }
            if ($row_number == 9) return 'P3';  // Row 9: all P3
        }
        
        // Section B: 62 seats total  
        if ($section === 'B') {
            if (in_array($row_number, [1, 2, 3])) return 'P1';  // Rows 1-3: P1
            if (in_array($row_number, [4, 5, 6, 7, 8])) return 'P2';  // Rows 4-8: P2
            if ($row_number == 9) return 'P3';  // Row 9: P3
            if ($row_number == 10) return 'AA';  // Row 10: Accessible (already handled above)
        }
        
        // Section C: 123 seats total - MOST SEATS ARE P1 HERE
        if ($section === 'C') {
            if (in_array($row_number, [1, 2, 3, 4, 5, 6, 7])) return 'P1';  // Rows 1-7: P1 (CHANGED: more P1)
            if (in_array($row_number, [8, 9])) return 'P2';  // Rows 8-9: P2
        }
        
        // Section D: 51 seats total
        if ($section === 'D') {
            if (in_array($row_number, [1, 2, 3, 4, 5, 6])) return 'P1';  // Rows 1-6: P1 (CHANGED: more P1)
            if (in_array($row_number, [7, 8])) return 'P2';  // Rows 7-8: P2
            if ($row_number == 9) {
                return (in_array($seat_number, [1, 2, 3, 4, 5])) ? 'P3' : 'AA';  // Row 9: mixed
            }
        }
        
        // Section E: 59 seats total  
        if ($section === 'E') {
            if (in_array($row_number, [1, 2])) return 'P1';  // Rows 1-2: P1 (CHANGED: more P1)
            if (in_array($row_number, [3, 4])) return 'P2';  // Rows 3-4: P2
            if (in_array($row_number, [5, 6, 7, 8])) return 'P3';  // Rows 5-8: P3 (CHANGED: more P3)
            if ($row_number == 9) {
                return (in_array($seat_number, [5, 6])) ? 'AA' : 'P3';  // Row 9: P3 seats 1-4, AA seats 5-6
            }
        }
        
        // Balcony Level Sections (F-H)
        
        // Section F: 28 seats total
        if ($section === 'F') {
            if ($row_number == 1) {
                return (in_array($seat_number, [6, 7, 8, 9, 10])) ? 'P1' : 'P2';  // Row 1: seats 6-10 are P1
            }
            if (in_array($row_number, [2, 3])) return 'P3';  // Rows 2-3: P3
        }
        
        // Section G: 54 seats total
        if ($section === 'G') {
            if ($row_number == 1) return 'P1';  // Row 1: P1 (24 seats)
            if (in_array($row_number, [2, 3])) return 'P2';  // Rows 2-3: P2 (30 seats)
        }
        
        // Section H: 50 seats total
        if ($section === 'H') {
            if ($row_number == 1) {
                return (in_array($seat_number, [1, 2, 3, 4, 5, 6, 7, 8, 9])) ? 'P1' : 'P2';  // Row 1: 1-9 P1, 10-14 P2
            }
            if (in_array($row_number, [2, 3])) {
                return (in_array($seat_number, [1, 2, 3, 4, 5, 6, 7, 8, 9])) ? 'P2' : 'P3';  // Rows 2-3: 1-9 P2, 10-15 P3
            }
            if ($row_number == 4) return 'P3';  // Row 4: all P3
        }
        
        // Default fallback
        return 'P2';
    }
    
    /**
     * Get all pricing maps for theater
     */
    public function get_pricing_maps($theater_id = 'hope_main_theater') {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->pricing_maps_table} 
            WHERE theater_id = %s AND status = 'active' 
            ORDER BY is_default DESC, name",
            $theater_id
        ));
    }
    
    /**
     * Get seats with pricing for a specific pricing map
     */
    public function get_seats_with_pricing($pricing_map_id) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT ps.*, sp.pricing_tier, sp.price 
            FROM {$this->physical_seats_table} ps
            JOIN {$this->seat_pricing_table} sp ON ps.id = sp.physical_seat_id
            WHERE sp.pricing_map_id = %d
            ORDER BY ps.level, ps.section, ps.`row_number`, ps.seat_number",
            $pricing_map_id
        ));
    }
    
    /**
     * Get pricing summary for a pricing map
     */
    public function get_pricing_summary($pricing_map_id) {
        global $wpdb;
        
        $summary = $wpdb->get_results($wpdb->prepare(
            "SELECT sp.pricing_tier, COUNT(*) as count, AVG(sp.price) as avg_price
            FROM {$this->seat_pricing_table} sp
            WHERE sp.pricing_map_id = %d
            GROUP BY sp.pricing_tier",
            $pricing_map_id
        ));
        
        $result = array();
        foreach ($summary as $tier) {
            if (isset($this->pricing_tiers[$tier->pricing_tier])) {
                $result[$tier->pricing_tier] = array(
                    'count' => $tier->count,
                    'name' => $this->pricing_tiers[$tier->pricing_tier]['name'],
                    'avg_price' => $tier->avg_price,
                    'color' => $this->pricing_tiers[$tier->pricing_tier]['color'],
                    'total_value' => $tier->count * $tier->avg_price
                );
            }
        }
        
        return $result;
    }
    
    /**
     * Get pricing tiers configuration
     */
    public function get_pricing_tiers() {
        return $this->pricing_tiers;
    }
    
    /**
     * Regenerate pricing assignments for existing physical seats
     */
    public function regenerate_pricing_assignments($pricing_map_id) {
        global $wpdb;
        
        // Delete existing seat pricing assignments
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->seat_pricing_table} WHERE pricing_map_id = %d",
            $pricing_map_id
        ));
        
        // Get all physical seats
        $physical_seats = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->physical_seats_table} WHERE theater_id = %s ORDER BY section, `row_number`, seat_number",
            'hope_main_theater'
        ));
        
        if (empty($physical_seats)) {
            return false;
        }
        
        $created_count = 0;
        
        // Apply pricing based on exact spreadsheet layout with corrected logic
        foreach ($physical_seats as $seat) {
            $pricing_tier = $this->get_seat_pricing_tier($seat->section, $seat->row_number, $seat->seat_number, $seat->is_accessible);
            $price = $this->pricing_tiers[$pricing_tier]['default_price'];
            
            $result = $wpdb->insert(
                $this->seat_pricing_table,
                array(
                    'pricing_map_id' => $pricing_map_id,
                    'physical_seat_id' => $seat->id,
                    'pricing_tier' => $pricing_tier,
                    'price' => $price
                ),
                array('%d', '%d', '%s', '%f')
            );
            
            if ($result) {
                $created_count++;
            }
        }
        
        error_log("HOPE Seating: Regenerated $created_count seat pricing assignments (deleted $deleted old ones)");
        return $created_count;
    }
}