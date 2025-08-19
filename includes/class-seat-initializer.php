<?php
/**
 * HOPE Theater Seat Data Initializer
 * 
 * Populates the database with accurate seat inventory based on
 * the architectural drawings and Excel spreadsheet data
 */

if (!defined('ABSPATH')) {
    exit;
}

class HOPE_Seating_Initializer {
    
    /**
     * Initialize HOPE Theater Main Stage with accurate seating
     * Based on the Excel data and architectural drawings
     */
    public static function initialize_hope_theater($venue_id) {
        $seat_maps = new HOPE_Seating_Seat_Maps();
        
        // Clear existing seats for this venue
        $seat_maps->delete_seats_by_venue($venue_id);
        
        // Orchestra Level Configuration
        $orchestra_config = array(
            // Section A (Far Left) - 8 seats per row
            'A' => array(
                'rows' => array(
                    1 => array('seats' => 8, 'pricing' => array_fill(1, 8, 'P1')),
                    2 => array('seats' => 8, 'pricing' => array_fill(1, 8, 'P2')),
                    3 => array('seats' => 8, 'pricing' => array_fill(1, 8, 'P2')),
                    4 => array('seats' => 8, 'pricing' => array_merge(array(1 => 'P3'), array_fill(2, 7, 'P2'))),
                    5 => array('seats' => 8, 'pricing' => array_merge(array_fill(1, 2, 'P3'), array_fill(3, 6, 'P2'))),
                    6 => array('seats' => 8, 'pricing' => array_merge(array_fill(1, 3, 'P3'), array_fill(4, 5, 'P2'))),
                    7 => array('seats' => 8, 'pricing' => array_merge(array_fill(1, 4, 'P3'), array_fill(5, 4, 'P2'))),
                    8 => array('seats' => 8, 'pricing' => array_merge(array_fill(1, 5, 'P3'), array_fill(6, 3, 'P2')))
                )
            ),
            
            // Section B (Left Wedge) - Expanding rows
            'B' => array(
                'rows' => array(
                    1 => array('seats' => 3, 'pricing' => array_fill(1, 3, 'P1')),
                    2 => array('seats' => 4, 'pricing' => array_fill(1, 4, 'P1')),
                    3 => array('seats' => 6, 'pricing' => array_fill(1, 6, 'P1')),
                    4 => array('seats' => 7, 'pricing' => array_fill(1, 7, 'P2')),
                    5 => array('seats' => 8, 'pricing' => array_fill(1, 8, 'P2')),
                    6 => array('seats' => 8, 'pricing' => array_fill(1, 8, 'P2')),
                    7 => array('seats' => 8, 'pricing' => array_fill(1, 8, 'P2')),
                    8 => array('seats' => 9, 'pricing' => array_fill(1, 9, 'P2')),
                    'AA' => array('seats' => 5, 'pricing' => array_fill(1, 5, 'AA'))
                )
            ),
            
            // Section C (Center) - Gradually expanding
            'C' => array(
                'rows' => array(
                    1 => array('seats' => 10, 'pricing' => array_fill(1, 10, 'P1')),
                    2 => array('seats' => 11, 'pricing' => array_fill(1, 11, 'P1')),
                    3 => array('seats' => 12, 'pricing' => array_fill(1, 12, 'P1')),
                    4 => array('seats' => 13, 'pricing' => array_fill(1, 13, 'P2')),
                    5 => array('seats' => 14, 'pricing' => array_fill(1, 14, 'P2')),
                    6 => array('seats' => 15, 'pricing' => array_fill(1, 15, 'P2')),
                    7 => array('seats' => 16, 'pricing' => array_fill(1, 16, 'P2')),
                    8 => array('seats' => 16, 'pricing' => array_fill(1, 16, 'P2')),
                    9 => array('seats' => 16, 'pricing' => array_fill(1, 16, 'P2'))
                )
            ),
            
            // Section D (Right Wedge) - Expanding rows
            'D' => array(
                'rows' => array(
                    1 => array('seats' => 2, 'pricing' => array_fill(1, 2, 'P1')),
                    2 => array('seats' => 3, 'pricing' => array_fill(1, 3, 'P1')),
                    3 => array('seats' => 4, 'pricing' => array_fill(1, 4, 'P1')),
                    4 => array('seats' => 6, 'pricing' => array_fill(1, 6, 'P2')),
                    5 => array('seats' => 7, 'pricing' => array_fill(1, 7, 'P2')),
                    6 => array('seats' => 8, 'pricing' => array_fill(1, 8, 'P2')),
                    7 => array('seats' => 8, 'pricing' => array_fill(1, 8, 'P2')),
                    8 => array('seats' => 8, 'pricing' => array_fill(1, 8, 'P2')),
                    9 => array('seats' => 4, 'pricing' => array_fill(1, 4, 'P3')),
                    'AA' => array('seats' => 2, 'pricing' => array_fill(1, 2, 'AA'))
                )
            ),
            
            // Section E (Far Right) - 7 seats per row
            'E' => array(
                'rows' => array(
                    1 => array('seats' => 7, 'pricing' => array_fill(1, 7, 'P1')),
                    2 => array('seats' => 7, 'pricing' => array_fill(1, 7, 'P2')),
                    3 => array('seats' => 7, 'pricing' => array_merge(array_fill(1, 6, 'P2'), array(7 => 'P3'))),
                    4 => array('seats' => 7, 'pricing' => array_merge(array_fill(1, 5, 'P2'), array_fill(6, 2, 'P3'))),
                    5 => array('seats' => 7, 'pricing' => array_merge(array_fill(1, 4, 'P2'), array_fill(5, 3, 'P3'))),
                    6 => array('seats' => 7, 'pricing' => array_merge(array_fill(1, 3, 'P2'), array_fill(4, 4, 'P3'))),
                    7 => array('seats' => 7, 'pricing' => array_merge(array_fill(1, 2, 'P2'), array_fill(3, 5, 'P3'))),
                    8 => array('seats' => 5, 'pricing' => array_merge(array_fill(1, 2, 'P2'), array_fill(3, 3, 'P3'))),
                    9 => array('seats' => 4, 'pricing' => array_fill(1, 4, 'P3')),
                    'AA' => array('seats' => 2, 'pricing' => array_fill(1, 2, 'AA'))
                )
            )
        );
        
        // Balcony Level Configuration
        $balcony_config = array(
            // Section F (Left Balcony)
            'F' => array(
                'rows' => array(
                    1 => array('seats' => 10, 'pricing' => array_merge(array_fill(1, 5, 'P2'), array_fill(6, 5, 'P1'))),
                    2 => array('seats' => 10, 'pricing' => array_fill(1, 10, 'P3')),
                    3 => array('seats' => 8, 'pricing' => array_fill(1, 8, 'P3'))
                )
            ),
            
            // Section G (Center Balcony)
            'G' => array(
                'rows' => array(
                    1 => array('seats' => 24, 'pricing' => array_fill(1, 24, 'P1')),
                    2 => array('seats' => 16, 'pricing' => array_fill(1, 16, 'P2')),
                    3 => array('seats' => 14, 'pricing' => array_fill(1, 14, 'P2')),
                    4 => array('seats' => 6, 'pricing' => array_fill(1, 6, 'P3'))
                )
            ),
            
            // Section H (Right Balcony)
            'H' => array(
                'rows' => array(
                    1 => array('seats' => 10, 'pricing' => array_merge(array_fill(1, 5, 'P1'), array_fill(6, 5, 'P2'))),
                    2 => array('seats' => 10, 'pricing' => array_merge(array_fill(1, 9, 'P2'), array(10 => 'P3'))),
                    3 => array('seats' => 8, 'pricing' => array_fill(1, 8, 'P3')),
                    4 => array('seats' => 6, 'pricing' => array_fill(1, 6, 'P3'))
                )
            )
        );
        
        // Create orchestra level seats
        self::create_seats_for_level($venue_id, 'orchestra', $orchestra_config, $seat_maps);
        
        // Create balcony level seats
        self::create_seats_for_level($venue_id, 'balcony', $balcony_config, $seat_maps);
        
        return true;
    }
    
    /**
     * Create seats for a specific level
     */
    private static function create_seats_for_level($venue_id, $level, $config, $seat_maps) {
        foreach ($config as $section => $section_data) {
            foreach ($section_data['rows'] as $row => $row_data) {
                $seats_count = $row_data['seats'];
                $pricing = $row_data['pricing'];
                
                for ($seat_num = 1; $seat_num <= $seats_count; $seat_num++) {
                    $seat_id = $section . $row . '-' . $seat_num;
                    $pricing_tier = isset($pricing[$seat_num]) ? $pricing[$seat_num] : 'P3';
                    
                    // Calculate position based on section layout
                    $position = self::calculate_seat_position($section, $row, $seat_num, $seats_count, $level);
                    
                    $seat_data = array(
                        'venue_id' => $venue_id,
                        'seat_id' => $seat_id,
                        'section' => $section,
                        'row_number' => $row,
                        'seat_number' => $seat_num,
                        'level' => $level,
                        'x_coordinate' => $position['x'],
                        'y_coordinate' => $position['y'],
                        'pricing_tier' => $pricing_tier,
                        'seat_type' => ($pricing_tier === 'AA') ? 'accessible' : 'standard',
                        'is_accessible' => ($pricing_tier === 'AA') ? 1 : 0,
                        'is_blocked' => 0,
                        'notes' => ''
                    );
                    
                    $seat_maps->create_seat($seat_data);
                }
            }
        }
    }
    
    /**
     * Calculate seat position based on half-round theater layout
     */
    private static function calculate_seat_position($section, $row, $seat_num, $total_seats, $level) {
        // Base center point for the theater
        $center_x = 450;
        $center_y = 140;
        
        // Section angular positions (degrees)
        $section_angles = array(
            'orchestra' => array(
                'A' => array('start' => -75, 'end' => -55),
                'B' => array('start' => -50, 'end' => -25),
                'C' => array('start' => -20, 'end' => 20),
                'D' => array('start' => 25, 'end' => 50),
                'E' => array('start' => 55, 'end' => 75)
            ),
            'balcony' => array(
                'F' => array('start' => -60, 'end' => -25),
                'G' => array('start' => -20, 'end' => 20),
                'H' => array('start' => 25, 'end' => 60)
            )
        );
        
        // Base radius for each level
        $base_radius = ($level === 'orchestra') ? 180 : 320;
        
        // Get section angles
        $angles = $section_angles[$level][$section];
        $angle_range = $angles['end'] - $angles['start'];
        
        // Calculate row radius (increases as we go back)
        $row_number = is_numeric($row) ? intval($row) : 9; // AA rows are at the back
        $row_radius = $base_radius + ($row_number * 35);
        
        // Calculate angle for this seat
        $angle_step = $angle_range / ($total_seats + 1);
        $seat_angle = $angles['start'] + ($angle_step * $seat_num);
        
        // Convert to radians
        $angle_rad = deg2rad($seat_angle);
        
        // Calculate x,y coordinates
        $x = $center_x + ($row_radius * sin($angle_rad));
        $y = $center_y + ($row_radius * cos($angle_rad));
        
        return array('x' => round($x), 'y' => round($y));
    }
}