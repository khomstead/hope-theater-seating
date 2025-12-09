<?php
/**
 * Printable Seating Chart Admin Page
 *
 * @package hope-theater-seating
 * @since 2.3.1
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load the printable chart class
require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-printable-seating-chart.php';

// Check if this is a standalone view request
$standalone = isset($_GET['standalone']) && $_GET['standalone'] === '1';

// Get pricing map ID from URL or use default
$pricing_map_id = isset($_GET['map_id']) ? intval($_GET['map_id']) : null;

// If no map ID, show selection form
if (!$pricing_map_id) {
    // Use pricing maps manager to get maps
    if (!class_exists('HOPE_Pricing_Maps_Manager')) {
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-pricing-maps.php';
    }

    $pricing_manager = new HOPE_Pricing_Maps_Manager();
    $maps = $pricing_manager->get_pricing_maps();

    // Convert to format needed for dropdown
    $maps_for_display = array();
    foreach ($maps as $map) {
        $seats = $pricing_manager->get_seats_with_pricing($map->id);
        $maps_for_display[] = (object) array(
            'id' => $map->id,
            'name' => $map->name,
            'total_seats' => count($seats)
        );
    }
    $maps = $maps_for_display;
    ?>
    <div class="wrap">
        <h1>Generate Printable Seating Chart</h1>
        <p>Select a pricing map to generate a printable seating chart with seat labels.</p>

        <form method="get" action="" id="hope-chart-form">
            <input type="hidden" name="page" value="<?php echo esc_attr($_GET['page']); ?>" />
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="map_id">Pricing Map</label>
                    </th>
                    <td>
                        <select name="map_id" id="map_id" class="regular-text" required>
                            <option value="">-- Select a Pricing Map --</option>
                            <?php foreach ($maps as $map): ?>
                                <option value="<?php echo esc_attr($map->id); ?>">
                                    <?php echo esc_html($map->name); ?> (<?php echo esc_html($map->total_seats); ?> seats)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" class="button button-primary" value="Generate Printable Chart" />
            </p>
        </form>

        <script>
        document.getElementById('hope-chart-form').addEventListener('submit', function(e) {
            e.preventDefault();
            var mapId = document.getElementById('map_id').value;
            if (mapId) {
                var url = '<?php echo admin_url('admin.php'); ?>?page=<?php echo esc_js($_GET['page']); ?>&map_id=' + mapId + '&standalone=1';
                window.open(url, '_blank', 'width=1200,height=900');
            }
        });
        </script>
    </div>
    <?php
    return;
}

// Generate and display the chart
$generator = new HOPE_Printable_Seating_Chart();
$chart_html = $generator->generate_chart($pricing_map_id);

// If standalone mode, output only the chart and exit completely
if ($standalone) {
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Output chart with proper headers
    header('Content-Type: text/html; charset=utf-8');
    echo $chart_html;
    exit;
}

// Otherwise show in WordPress admin (shouldn't happen but fallback)
echo $chart_html;
