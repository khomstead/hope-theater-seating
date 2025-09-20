<?php
/**
 * Physical Seats Manager for HOPE Theater Seating
 * Handles the fixed physical layout (seats, positions, accessibility)
 * Separated from pricing which is handled in pricing maps
 */

if (!defined('ABSPATH')) {
    exit;
}

class HOPE_Physical_Seats_Manager {
    
    private $table_name;
    private $theater_id;
    
    /**
     * Physical seat layout based on architectural plans - 497 seats total
     * This defines ONLY the physical layout, NOT pricing
     * Fixed: Section arrangement from audience perspective (A=left, E=right)
     */
    private $physical_layout = array(
        'orchestra' => array(
            'A' => array(
                'rows' => array(1, 2, 3, 4, 5, 6, 7, 8, 9),
                'seats_per_row' => array(1 => 8, 2 => 8, 3 => 8, 4 => 8, 5 => 8, 6 => 8, 7 => 8, 8 => 8, 9 => 6),
                'angle_start' => 90,
                'angle_end' => 50
            ),
            'B' => array(
                'rows' => array(1, 2, 3, 4, 5, 6, 7, 8, 9, 10),
                'seats_per_row' => array(1 => 3, 2 => 4, 3 => 6, 4 => 7, 5 => 8, 6 => 8, 7 => 8, 8 => 9, 9 => 4, 10 => 5),
                'angle_start' => 50,
                'angle_end' => 25
            ),
            'C' => array(
                'rows' => array(1, 2, 3, 4, 5, 6, 7, 8, 9),
                'seats_per_row' => array(1 => 10, 2 => 11, 3 => 12, 4 => 13, 5 => 14, 6 => 15, 7 => 16, 8 => 16, 9 => 16),
                'angle_start' => 25,
                'angle_end' => -25
            ),
            'D' => array(
                'rows' => array(1, 2, 3, 4, 5, 6, 7, 8, 9),
                'seats_per_row' => array(1 => 2, 2 => 3, 3 => 4, 4 => 6, 5 => 7, 6 => 8, 7 => 7, 8 => 7, 9 => 7),
                'angle_start' => -25,
                'angle_end' => -50
            ),
            'E' => array(
                'rows' => array(1, 2, 3, 4, 5, 6, 7, 8, 9),
                'seats_per_row' => array(1 => 7, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7, 7 => 7, 8 => 4, 9 => 6),
                'angle_start' => -50,
                'angle_end' => -90
            )
        ),
        'balcony' => array(
            'F' => array(
                'rows' => array(1, 2, 3),
                'seats_per_row' => array(1 => 10, 2 => 10, 3 => 8),
                'angle_start' => 90,
                'angle_end' => 45,
                'justify_shorter_rows' => 'left'
            ),
            'G' => array(
                'rows' => array(1, 2, 3),
                'seats_per_row' => array(1 => 24, 2 => 16, 3 => 14),
                'angle_start' => 35,
                'angle_end' => -35,
                'justify_shorter_rows' => 'right'
            ),
            'H' => array(
                'rows' => array(1, 2, 3, 4),
                'seats_per_row' => array(1 => 14, 2 => 15, 3 => 15, 4 => 6),
                'angle_start' => -45,
                'angle_end' => -90,
                'justify_shorter_rows' => 'right'
            )
        )
    );
    
    public function __construct($theater_id = 'hope_main_theater') {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'hope_seating_physical_seats';
        $this->theater_id = $theater_id;
    }
    
    /**
     * Populate all physical seats for the theater
     */
    public function populate_physical_seats() {
        global $wpdb;

        // SAFETY CHECK: Don't recreate seats if they already exist and have bookings
        $existing_seats = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE theater_id = %s",
            $this->theater_id
        ));

        if ($existing_seats > 0) {
            // Check if there are any bookings that reference these seats
            $bookings_table = $wpdb->prefix . 'hope_seating_bookings';
            $existing_bookings = $wpdb->get_var("SELECT COUNT(*) FROM {$bookings_table}");

            if ($existing_bookings > 0) {
                error_log("HOPE: Skipping seat recreation - {$existing_seats} seats exist with {$existing_bookings} bookings. Preserving data integrity.");
                return $existing_seats;
            }

            // Also check for holds
            $holds_table = $wpdb->prefix . 'hope_seating_holds';
            $existing_holds = $wpdb->get_var("SELECT COUNT(*) FROM {$holds_table}");

            if ($existing_holds > 0) {
                error_log("HOPE: Skipping seat recreation - {$existing_seats} seats exist with {$existing_holds} active holds. Preserving data integrity.");
                return $existing_seats;
            }

            error_log("HOPE: {$existing_seats} seats exist but no bookings/holds found. Safe to recreate seats.");
        }

        // Increase memory limit and execution time for this operation
        ini_set('memory_limit', '512M');
        set_time_limit(300);

        // Clear existing seats for this theater (only if safe to do so)
        if ($existing_seats > 0) {
            error_log("HOPE: Clearing {$existing_seats} existing seats for recreation");
            $wpdb->delete($this->table_name, array('theater_id' => $this->theater_id));
        }

        $total_seats = 0;
        
        // Process orchestra sections
        foreach ($this->physical_layout['orchestra'] as $section => $config) {
            $seats_created = $this->create_section_seats($section, $config, 'orchestra');
            $total_seats += $seats_created;
            
            // Clear memory between sections
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
        
        // Process balcony sections
        foreach ($this->physical_layout['balcony'] as $section => $config) {
            $seats_created = $this->create_section_seats($section, $config, 'balcony');
            $total_seats += $seats_created;
            
            // Clear memory between sections
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
        
        return $total_seats;
    }
    
    /**
     * Create physical seats for a specific section
     */
    private function create_section_seats($section, $config, $level) {
        global $wpdb;
        
        $seats_created = 0;
        $center_x = 600;
        $center_y = 800;
        
        // Adjust base radius for level
        $base_radius = ($level === 'orchestra') ? 250 : 350;
        $row_spacing = 35;
        
        // Prepare batch insert data
        $batch_data = array();
        
        foreach ($config['rows'] as $row_index => $row_number) {
            $seats_in_row = $config['seats_per_row'][$row_number];
            $radius = $base_radius + ($row_index * $row_spacing);
            
            // Calculate angular spacing for this row
            $angle_range = abs($config['angle_end'] - $config['angle_start']);
            
            // Handle justification for shorter rows in sections with asymmetrical arrangements
            if (isset($config['justify_shorter_rows'])) {
                // Determine max seats in section for baseline spacing
                $max_seats_in_section = max($config['seats_per_row']);
                
                if ($config['justify_shorter_rows'] === 'right') {
                    // For right-justification (G, H), treat longest and shorter rows differently
                    if ($seats_in_row >= $max_seats_in_section) {
                        // Longest rows: center them by dividing range into equal segments
                        $angle_step = $angle_range / ($seats_in_row - 1);
                        $seat_offset = 0;
                        $use_centered_spacing = true;
                    } else {
                        // Shorter rows use baseline spacing and are right-justified
                        $angle_step = $angle_range / ($max_seats_in_section + 1);
                        $seat_offset = $max_seats_in_section - $seats_in_row;
                        $use_centered_spacing = false;
                    }
                } elseif ($config['justify_shorter_rows'] === 'left') {
                    // For left-justification (F), use baseline spacing, no offset
                    $angle_step = $angle_range / ($max_seats_in_section + 1);
                    $seat_offset = 0;
                }
            } else {
                $angle_step = $angle_range / ($seats_in_row + 1);
                $seat_offset = 0;
                $use_centered_spacing = false;
            }
            
            // Determine if we're going from high to low angles
            $descending_angles = $config['angle_start'] > $config['angle_end'];
            
            // Determine seat numbering direction based on section (from audience perspective)
            // Left-to-Right: A, D (left side sections) + balcony F, H
            // Right-to-Left: B, C, E (center/right sections) + balcony G
            $reverse_numbering = in_array($section, array('B', 'C', 'E', 'G'));
            
            for ($seat_num = 1; $seat_num <= $seats_in_row; $seat_num++) {
                // Determine position index for seat placement
                if ($use_centered_spacing) {
                    // For centered rows, ALWAYS use sequential positioning for true centering
                    $position_index = $seat_num;
                } else {
                    // For justified rows, apply numbering direction to positioning
                    $position_index = $reverse_numbering ? ($seats_in_row - $seat_num + 1) : $seat_num;
                }
                
                if ($use_centered_spacing) {
                    // For centered rows, distribute seats evenly across the full range
                    if ($descending_angles) {
                        $angle = $config['angle_start'] - (($position_index - 1) * $angle_step);
                    } else {
                        $angle = $config['angle_start'] + (($position_index - 1) * $angle_step);
                    }
                } else {
                    // For justified rows, use the standard spacing with offset
                    if ($descending_angles) {
                        $angle = $config['angle_start'] - (($position_index + $seat_offset) * $angle_step);
                    } else {
                        $angle = $config['angle_start'] + (($position_index + $seat_offset) * $angle_step);
                    }
                }
                $angle_rad = deg2rad($angle - 90); // Adjust for stage at bottom
                
                // Calculate coordinates
                $x = $center_x + ($radius * cos($angle_rad));
                $y = $center_y + ($radius * sin($angle_rad));
                
                // Apply section-specific offsets
                if (isset($config['x_offset'])) {
                    $x += $config['x_offset'];
                }
                
                // Create seat ID matching spreadsheet pattern
                $seat_id = $section . $row_number . '-' . $seat_num;
                
                // Determine accessibility based on special cases
                $is_accessible = $this->is_accessible_seat($section, $row_number, $seat_num);
                
                // Add to batch data
                $batch_data[] = array(
                    'theater_id' => $this->theater_id,
                    'seat_id' => $seat_id,
                    'section' => $section,
                    'row_number' => $row_number,
                    'seat_number' => $seat_num,
                    'level' => $level,
                    'x_coordinate' => round($x, 2),
                    'y_coordinate' => round($y, 2),
                    'seat_type' => $is_accessible ? 'accessible' : 'standard',
                    'is_accessible' => $is_accessible ? 1 : 0,
                    'is_blocked' => 0
                );
                
                $seats_created++;
            }
        }
        
        // Perform batch insert
        if (!empty($batch_data)) {
            $this->batch_insert_seats($batch_data);
        }
        
        return $seats_created;
    }
    
    /**
     * Perform batch insert of seats to improve performance
     */
    private function batch_insert_seats($batch_data) {
        global $wpdb;
        
        if (empty($batch_data)) {
            return;
        }
        
        $values = array();
        $placeholders = array();
        
        foreach ($batch_data as $seat) {
            $placeholders[] = "(%s, %s, %s, %d, %d, %s, %f, %f, %s, %d, %d)";
            $values[] = $seat['theater_id'];
            $values[] = $seat['seat_id'];
            $values[] = $seat['section'];
            $values[] = $seat['row_number'];
            $values[] = $seat['seat_number'];
            $values[] = $seat['level'];
            $values[] = $seat['x_coordinate'];
            $values[] = $seat['y_coordinate'];
            $values[] = $seat['seat_type'];
            $values[] = $seat['is_accessible'];
            $values[] = $seat['is_blocked'];
        }
        
        $sql = "INSERT INTO {$this->table_name} 
                (theater_id, seat_id, section, `row_number`, seat_number, level, x_coordinate, y_coordinate, seat_type, is_accessible, is_blocked) 
                VALUES " . implode(', ', $placeholders);
        
        $wpdb->query($wpdb->prepare($sql, $values));
    }
    
    /**
     * Determine if a seat is accessible based on spreadsheet data
     */
    private function is_accessible_seat($section, $row_number, $seat_number) {
        // Based on spreadsheet: AA seats are in specific locations
        
        // Section B Row 10: all 5 seats are AA
        if ($section === 'B' && $row_number === 10) {
            return true;
        }
        
        // Section D Row 9: seats 6,7 are AA
        if ($section === 'D' && $row_number === 9 && in_array($seat_number, [6, 7])) {
            return true;
        }
        
        // Section E Row 9: seats 5,6 are AA
        if ($section === 'E' && $row_number === 9 && in_array($seat_number, [5, 6])) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get all physical seats for theater
     */
    public function get_all_seats($theater_id = null) {
        global $wpdb;
        
        if (!$theater_id) {
            $theater_id = $this->theater_id;
        }
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
            WHERE theater_id = %s 
            ORDER BY level, section, `row_number`, seat_number",
            $theater_id
        ));
    }
    
    /**
     * Get seat by ID
     */
    public function get_seat_by_id($seat_id, $theater_id = null) {
        global $wpdb;
        
        if (!$theater_id) {
            $theater_id = $this->theater_id;
        }
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
            WHERE theater_id = %s AND seat_id = %s",
            $theater_id, $seat_id
        ));
    }
    
    /**
     * Get seats by section
     */
    public function get_seats_by_section($section, $theater_id = null) {
        global $wpdb;
        
        if (!$theater_id) {
            $theater_id = $this->theater_id;
        }
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
            WHERE theater_id = %s AND section = %s 
            ORDER BY `row_number`, seat_number",
            $theater_id, $section
        ));
    }
}