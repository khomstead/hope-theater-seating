<?php
/**
 * Printable Seating Chart Generator
 *
 * Generates a printable PDF/HTML seating chart with seat labels
 *
 * @package hope-theater-seating
 * @since 2.3.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class HOPE_Printable_Seating_Chart {

    /**
     * Pricing tier colors (matching frontend)
     */
    private $tier_colors = array(
        'P1' => '#c39bd3', // Premium - Lighter Purple for better text visibility
        'P2' => '#3498db', // Standard - Blue
        'P3' => '#17a2b8', // Value - Teal
        'AA' => '#e67e22'  // Accessible - Orange
    );

    /**
     * Generate printable seating chart for a pricing map
     */
    public function generate_chart($pricing_map_id) {
        // Load pricing maps manager
        if (!class_exists('HOPE_Pricing_Maps_Manager')) {
            require_once plugin_dir_path(__FILE__) . 'class-pricing-maps.php';
        }

        $pricing_manager = new HOPE_Pricing_Maps_Manager();

        // Get pricing map info
        $maps = $pricing_manager->get_pricing_maps();
        $map = null;
        foreach ($maps as $m) {
            if ($m->id == $pricing_map_id) {
                $map = $m;
                break;
            }
        }

        if (!$map) {
            return '<p>Pricing map not found.</p>';
        }

        // Get all physical seats with their pricing
        $seats = $pricing_manager->get_seats_with_pricing($pricing_map_id);

        if (empty($seats)) {
            return '<p>No seats found for this pricing map.</p>';
        }

        // Group seats by floor level
        $floors = array();
        foreach ($seats as $seat) {
            $level = $seat->level ?: 'orchestra';
            if (!isset($floors[$level])) {
                $floors[$level] = array();
            }
            $floors[$level][] = $seat;
        }

        // Generate HTML with embedded SVG
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title><?php echo esc_html($map->name); ?> - Seating Chart</title>
            <style>
                @media print {
                    @page {
                        size: landscape;
                        margin: 0.2in;
                    }
                    * {
                        margin: 0 !important;
                        padding: 0 !important;
                    }
                    .page-break {
                        display: block;
                        page-break-before: always;
                        height: 0;
                    }
                    .no-print {
                        display: none !important;
                    }
                    /* Hide all WordPress admin elements */
                    #wpadminbar,
                    #adminmenumain,
                    #adminmenuback,
                    #adminmenuwrap,
                    .wrap > *:not(.printable-chart-content),
                    #wpfooter,
                    .update-nag,
                    .notice,
                    .error {
                        display: none !important;
                    }
                    html, body {
                        background: white !important;
                        height: auto !important;
                    }
                    .printable-chart-content {
                        display: block !important;
                    }
                    .floor-section {
                        display: flex;
                        flex-direction: column;
                        align-items: center;
                        page-break-inside: avoid !important;
                        page-break-after: auto;
                    }
                    h1 {
                        font-size: 14px !important;
                        margin-bottom: 3px !important;
                        display: block;
                        text-align: center;
                    }
                    .legend {
                        margin-bottom: 3px !important;
                        gap: 12px;
                        display: flex !important;
                        justify-content: center;
                    }
                    .legend-item {
                        font-size: 10px;
                        gap: 4px;
                    }
                    .legend-box {
                        width: 12px;
                        height: 12px;
                        border-width: 1px;
                    }
                    svg {
                        background: white !important;
                        border: none !important;
                        display: block;
                    }
                    .seat-map-container {
                        display: block;
                    }
                }

                body {
                    font-family: Arial, sans-serif;
                    margin: 20px;
                }

                h1 {
                    text-align: center;
                    margin-bottom: 10px;
                    font-size: 24px;
                }

                .floor-title {
                    font-size: 20px;
                    font-weight: bold;
                    margin: 15px 0 10px 0;
                    text-transform: capitalize;
                }

                .legend {
                    display: flex;
                    justify-content: center;
                    gap: 20px;
                    margin: 10px 0;
                    flex-wrap: wrap;
                }

                .legend-item {
                    display: flex;
                    align-items: center;
                    gap: 6px;
                    font-size: 13px;
                }

                .legend-box {
                    width: 20px;
                    height: 20px;
                    border: 2px solid #333;
                    border-radius: 4px;
                }

                .seat-map-container {
                    width: 100%;
                    display: flex;
                    justify-content: center;
                    margin: 10px 0;
                }

                svg {
                    border: none;
                    background: white;
                }

                .seat {
                    stroke: #333;
                    stroke-width: 1.5;
                }

                .seat-label {
                    font-family: Arial, sans-serif;
                    font-size: 9px;
                    fill: #000;
                    pointer-events: none;
                    text-anchor: middle;
                    font-weight: bold;
                }

                .stage {
                    fill: #333;
                }

                .stage-text {
                    fill: #fff;
                    font-size: 24px;
                    font-weight: bold;
                    text-anchor: middle;
                    font-family: Arial, sans-serif;
                }

                .section-label {
                    font-family: Arial, sans-serif;
                    font-size: 16px;
                    fill: #333;
                    font-weight: bold;
                    text-anchor: middle;
                }

                .print-button {
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    padding: 12px 24px;
                    background: #0073aa;
                    color: white;
                    border: none;
                    border-radius: 4px;
                    cursor: pointer;
                    font-size: 16px;
                    z-index: 1000;
                }

                .print-button:hover {
                    background: #005177;
                }
            </style>
        </head>
        <body>
            <button class="print-button no-print" onclick="window.print()">Print Chart</button>

            <div class="printable-chart-content">
                <?php
                $floor_index = 0;
                foreach ($floors as $level => $level_seats):
                    // Add page break before each floor except the first
                    if ($floor_index > 0): ?>
                        <div class="page-break"></div>
                    <?php endif; ?>

                    <div class="floor-section">
                        <!-- Title and legend on each page -->
                        <h1><?php echo esc_html($map->name); ?> - <?php echo esc_html(ucfirst($level)); ?> Level</h1>

                        <div class="legend">
                            <?php foreach ($this->tier_colors as $tier => $color): ?>
                                <div class="legend-item">
                                    <div class="legend-box" style="background-color: <?php echo esc_attr($color); ?>;"></div>
                                    <span><?php echo $tier === 'AA' ? 'Accessible' : ($tier === 'P1' ? 'Premium' : ($tier === 'P2' ? 'Standard' : 'Value')); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="seat-map-container">
                            <?php echo $this->generate_svg($level_seats, $level); ?>
                        </div>
                    </div>
                <?php
                    $floor_index++;
                endforeach; ?>
            </div>

        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Generate SVG for a floor level
     */
    private function generate_svg($seats, $level) {
        // SVG dimensions - calculate based on actual seat positions
        $width = 1400;
        $seat_radius = 12;

        // Find the actual bounds of the seats
        $min_y = PHP_FLOAT_MAX;
        $max_y = PHP_FLOAT_MIN;
        foreach ($seats as $seat) {
            $y = floatval($seat->y_coordinate);
            $min_y = min($min_y, $y);
            $max_y = max($max_y, $y);
        }

        // Add padding for section labels and seat radius
        $top_padding = 50; // Space for section labels above
        $bottom_padding = 20; // Space below seats
        $viewBox_min_y = $min_y - $top_padding;
        $viewBox_height = ($max_y - $min_y) + $top_padding + $bottom_padding;

        // Set height - much smaller to fit on page with title/legend
        $height = 500; // Smaller to ensure everything fits

        $svg = '<svg width="' . $width . '" height="' . $height . '" viewBox="0 ' . $viewBox_min_y . ' ' . $width . ' ' . $viewBox_height . '" xmlns="http://www.w3.org/2000/svg">';

        // Calculate section boundaries for labels
        $sections = array();
        foreach ($seats as $seat) {
            $section = $seat->section;
            if (!isset($sections[$section])) {
                $sections[$section] = array(
                    'min_x' => floatval($seat->x_coordinate),
                    'max_x' => floatval($seat->x_coordinate),
                    'min_y' => floatval($seat->y_coordinate),
                    'max_y' => floatval($seat->y_coordinate)
                );
            } else {
                $sections[$section]['min_x'] = min($sections[$section]['min_x'], floatval($seat->x_coordinate));
                $sections[$section]['max_x'] = max($sections[$section]['max_x'], floatval($seat->x_coordinate));
                $sections[$section]['min_y'] = min($sections[$section]['min_y'], floatval($seat->y_coordinate));
                $sections[$section]['max_y'] = max($sections[$section]['max_y'], floatval($seat->y_coordinate));
            }
        }

        // Draw section labels above each section
        foreach ($sections as $section => $bounds) {
            $center_x = ($bounds['min_x'] + $bounds['max_x']) / 2;
            $label_y = $bounds['min_y'] - 35; // 35px above the topmost seat to avoid overlap

            // Use fixed coordinates for A and mirror for E
            // SVG center is at 700 (width 1400 / 2)
            // Section A is at x=1160, which is 460px right of center
            // Section E should mirror at x=240 (460px left of center)
            if ($section === 'A') {
                $center_x = 1160;
                $label_y = 600;
            } elseif ($section === 'E') {
                $center_x = 240; // Mirrored: 700 - (1160 - 700) = 240
                $label_y = 600;
            } elseif (in_array($section, array('B'))) {
                $center_x += 40; // Shift right
            } elseif (in_array($section, array('D'))) {
                $center_x -= 40; // Shift left
            }

            $svg .= sprintf(
                '<text class="section-label" x="%s" y="%s">SECTION %s</text>',
                $center_x,
                $label_y,
                esc_html($section)
            );
        }

        // Draw seats
        foreach ($seats as $seat) {
            $x = floatval($seat->x_coordinate);
            $y = floatval($seat->y_coordinate);
            $tier = strtoupper($seat->pricing_tier);
            $color = isset($this->tier_colors[$tier]) ? $this->tier_colors[$tier] : '#999';

            // Draw seat circle
            $svg .= sprintf(
                '<circle class="seat" cx="%s" cy="%s" r="%s" fill="%s" />',
                $x,
                $y,
                $seat_radius,
                $color
            );

            // Add seat label (row-seat format, e.g., "1-5")
            $label = $seat->row_number . '-' . $seat->seat_number;
            $svg .= sprintf(
                '<text class="seat-label" x="%s" y="%s">%s</text>',
                $x,
                $y + 3.5, // Offset to center text vertically
                esc_html($label)
            );
        }

        $svg .= '</svg>';

        return $svg;
    }
}
