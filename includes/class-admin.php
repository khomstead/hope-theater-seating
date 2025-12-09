<?php
/**
 * Complete Admin interface for HOPE Theater Seating
 * Save this as: /wp-content/plugins/hope-theater-seating/includes/class-admin.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class HOPE_Seating_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'init_settings'));
        
        // Add venue selection to WooCommerce product edit page
        add_action('woocommerce_product_data_tabs', array($this, 'add_product_venue_tab'));
        add_action('woocommerce_product_data_panels', array($this, 'add_product_venue_fields'));
        add_action('woocommerce_process_product_meta', array($this, 'save_product_venue_fields'));
        
        // Add columns to products list
        add_filter('manage_edit-product_columns', array($this, 'add_product_columns'));
        add_action('manage_product_posts_custom_column', array($this, 'show_product_column_content'), 10, 2);
        
        // AJAX handler for creating venues
        add_action('wp_ajax_hope_create_default_venues', array($this, 'ajax_create_default_venues'));
    }
    
    public function add_admin_menu() {
        // Main menu
        add_menu_page(
            'HOPE Seating',
            'HOPE Seating',
            'manage_options',
            'hope-seating',
            array($this, 'main_page'),
            'dashicons-tickets-alt',
            30
        );
        
        // Submenu pages
        add_submenu_page(
            'hope-seating',
            'Venues',
            'Venues',
            'manage_options',
            'hope-seating-venues',
            array($this, 'venues_page')
        );
        
        add_submenu_page(
            'hope-seating',
            'Seat Maps',
            'Seat Maps',
            'manage_options', 
            'hope-seating-seats',
            array($this, 'seats_page')
        );
        
        add_submenu_page(
            'hope-seating',
            'Settings',
            'Settings',
            'manage_options',
            'hope-seating-settings',
            array($this, 'settings_page')
        );
        
        add_submenu_page(
            'hope-seating',
            'Order Lookup',
            'Order Lookup',
            'manage_options',
            'hope-seating-order-lookup',
            array($this, 'order_lookup_page')
        );

        add_submenu_page(
            'hope-seating',
            'Diagnostics',
            'Diagnostics',
            'manage_options',
            'hope-seating-diagnostics',
            array($this, 'diagnostics_page')
        );

        add_submenu_page(
            'hope-seating',
            'Printable Chart',
            'Printable Chart',
            'manage_options',
            'hope-seating-printable-chart',
            array($this, 'printable_chart_page')
        );
    }
    
    public function init_settings() {
        register_setting('hope_seating_settings', 'hope_seating_options');
    }
    
    // Main admin page
    public function main_page() {
        global $wpdb;
        
        // Get statistics from new architecture (with fallback to old system)
        $pricing_maps_table = $wpdb->prefix . 'hope_seating_pricing_maps';
        $physical_seats_table = $wpdb->prefix . 'hope_seating_physical_seats';
        $events_table = $wpdb->prefix . 'hope_seating_event_seats';
        
        // Try new architecture first
        $total_venues = 0;
        $total_seats = 0;
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$pricing_maps_table'") == $pricing_maps_table) {
            $total_venues = $wpdb->get_var("SELECT COUNT(*) FROM $pricing_maps_table WHERE status = 'active'");
        }
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$physical_seats_table'") == $physical_seats_table) {
            $total_seats = $wpdb->get_var("SELECT COUNT(*) FROM $physical_seats_table");
        }
        
        // Fallback to old system if new tables are empty
        if ($total_venues == 0 || $total_seats == 0) {
            $venues_table = $wpdb->prefix . 'hope_seating_venues';
            $seats_table = $wpdb->prefix . 'hope_seating_seat_maps';
            
            if ($total_venues == 0) {
                $total_venues = $wpdb->get_var("SELECT COUNT(*) FROM $venues_table");
            }
            if ($total_seats == 0) {
                $total_seats = $wpdb->get_var("SELECT COUNT(*) FROM $seats_table");
            }
        }
        
        $booked_seats = $wpdb->get_var("SELECT COUNT(*) FROM $events_table WHERE status = 'booked'");
        
        ?>
        <div class="wrap">
            <h1><?php _e('HOPE Theater Seating Dashboard', 'hope-seating'); ?></h1>
            
            <div class="hope-seating-dashboard">
                <div class="hope-stats-grid">
                    <div class="hope-stat-box">
                        <h3><?php _e('Seat Maps', 'hope-seating'); ?></h3>
                        <p class="hope-stat-number"><?php echo esc_html($total_venues); ?></p>
                    </div>
                    <div class="hope-stat-box">
                        <h3><?php _e('Total Seats', 'hope-seating'); ?></h3>
                        <p class="hope-stat-number"><?php echo esc_html($total_seats); ?></p>
                        <?php if (class_exists('HOPE_Pricing_Maps_Manager')): ?>
                            <p class="hope-stat-detail">Using new architecture</p>
                        <?php else: ?>
                            <p class="hope-stat-detail">Using legacy system</p>
                        <?php endif; ?>
                    </div>
                    <div class="hope-stat-box">
                        <h3><?php _e('Booked Seats', 'hope-seating'); ?></h3>
                        <p class="hope-stat-number"><?php echo esc_html($booked_seats); ?></p>
                    </div>
                </div>
                
                <?php if (class_exists('HOPE_Pricing_Maps_Manager')): ?>
                    <?php
                    // Show pricing breakdown from new architecture
                    $pricing_manager = new HOPE_Pricing_Maps_Manager();
                    $pricing_maps = $pricing_manager->get_pricing_maps();
                    
                    if (!empty($pricing_maps)) {
                        $default_map = $pricing_maps[0]; // First map should be default
                        $pricing_summary = $pricing_manager->get_pricing_summary($default_map->id);
                        if (!empty($pricing_summary)): ?>
                            <div class="hope-pricing-summary" style="margin: 20px 0; padding: 20px; background: #f0f8ff; border-radius: 5px; border-left: 4px solid #2271b1;">
                                <h3>Current Pricing Breakdown (<?php echo esc_html($default_map->name); ?>)</h3>
                                <div class="hope-pricing-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 15px 0;">
                                    <?php foreach ($pricing_summary as $tier_code => $tier_data): ?>
                                        <div class="hope-pricing-tier" style="padding: 15px; background: white; border-radius: 5px; border-left: 3px solid <?php echo esc_attr($tier_data['color']); ?>;">
                                            <h4 style="margin: 0 0 5px 0; color: <?php echo esc_attr($tier_data['color']); ?>;">
                                                <?php echo esc_html($tier_data['name']); ?> (<?php echo esc_html($tier_code); ?>)
                                            </h4>
                                            <p style="margin: 0; font-size: 18px; font-weight: bold;"><?php echo esc_html($tier_data['count']); ?> seats</p>
                                            <p style="margin: 5px 0 0 0; color: #666; font-size: 14px;">$<?php echo number_format($tier_data['avg_price'], 2); ?> avg</p>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif;
                    }
                    ?>
                <?php endif; ?>
                
                <div class="hope-actions">
                    <h2><?php _e('Quick Actions', 'hope-seating'); ?></h2>
                    <a href="<?php echo admin_url('admin.php?page=hope-seating-venues'); ?>" class="button button-primary">
                        <?php _e('Manage Seat Maps', 'hope-seating'); ?>
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=hope-seating-seats'); ?>" class="button">
                        <?php _e('Edit Seat Maps', 'hope-seating'); ?>
                    </a>
                    <a href="<?php echo admin_url('edit.php?post_type=product'); ?>" class="button">
                        <?php _e('View Events', 'hope-seating'); ?>
                    </a>
                </div>
            </div>
        </div>
        
        <style>
        .hope-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .hope-stat-box {
            background: white;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            text-align: center;
        }
        .hope-stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #2271b1;
            margin: 10px 0;
        }
        .hope-stat-detail {
            margin: 5px 0 0 0;
            font-size: 12px;
            color: #666;
            font-style: italic;
        }
        .hope-actions {
            margin-top: 30px;
        }
        .hope-actions .button {
            margin-right: 10px;
        }
        </style>
        <?php
    }
    
    // Seat Maps management page  
    public function venues_page() {
        global $wpdb;
        
        // Try new architecture first
        $pricing_maps = array();
        if (class_exists('HOPE_Pricing_Maps_Manager')) {
            $pricing_manager = new HOPE_Pricing_Maps_Manager();
            $pricing_maps = $pricing_manager->get_pricing_maps();
        }
        
        // Fallback to old system if no pricing maps
        $venues = array();
        if (empty($pricing_maps)) {
            $venues_table = $wpdb->prefix . 'hope_seating_venues';
            $venues = $wpdb->get_results("SELECT * FROM $venues_table ORDER BY name");
        }
        
        ?>
        <div class="wrap">
            <h1><?php _e('Seat Map Management', 'hope-seating'); ?></h1>
            
            <?php if (!empty($pricing_maps)): ?>
                <div class="hope-new-architecture" style="margin: 20px 0; padding: 15px; background: #d1ecf1; border-left: 4px solid #bee5eb; border-radius: 5px;">
                    <h3 style="margin-top: 0;">✅ Using New Separated Architecture</h3>
                    <p>Physical seats and pricing configurations are now managed separately for better flexibility.</p>
                </div>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('ID', 'hope-seating'); ?></th>
                            <th><?php _e('Seat Map Name', 'hope-seating'); ?></th>
                            <th><?php _e('Description', 'hope-seating'); ?></th>
                            <th><?php _e('Total Seats', 'hope-seating'); ?></th>
                            <th><?php _e('Pricing Breakdown', 'hope-seating'); ?></th>
                            <th><?php _e('Status', 'hope-seating'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pricing_maps as $map): 
                            $seats_with_pricing = $pricing_manager->get_seats_with_pricing($map->id);
                            $total_seats = count($seats_with_pricing);
                            $pricing_summary = $pricing_manager->get_pricing_summary($map->id);
                        ?>
                            <tr>
                                <td><?php echo esc_html($map->id); ?></td>
                                <td><strong><?php echo esc_html($map->name); ?></strong>
                                    <?php if ($map->is_default): ?>
                                        <span style="background: #2271b1; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px; margin-left: 5px;">DEFAULT</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($map->description); ?></td>
                                <td><strong><?php echo esc_html($total_seats); ?></strong></td>
                                <td>
                                    <?php if (!empty($pricing_summary)): ?>
                                        <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                            <?php foreach ($pricing_summary as $tier_code => $tier_data): ?>
                                                <span style="background: <?php echo esc_attr($tier_data['color']); ?>; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px;">
                                                    <?php echo esc_html($tier_code); ?>: <?php echo esc_html($tier_data['count']); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #666;">No pricing data</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-<?php echo esc_attr($map->status); ?>">
                                        <?php echo esc_html($map->status); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
            <?php elseif (!empty($venues)): ?>
                <div class="hope-legacy-warning" style="margin: 20px 0; padding: 15px; background: #fff3cd; border-left: 4px solid #ffeaa7; border-radius: 5px;">
                    <h3 style="margin-top: 0;">⚠️ Using Legacy Venue System</h3>
                    <p>Consider migrating to the new separated architecture for better flexibility.</p>
                </div>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('ID', 'hope-seating'); ?></th>
                            <th><?php _e('Name', 'hope-seating'); ?></th>
                            <th><?php _e('Slug', 'hope-seating'); ?></th>
                            <th><?php _e('Total Seats', 'hope-seating'); ?></th>
                            <th><?php _e('Status', 'hope-seating'); ?></th>
                            <th><?php _e('Actions', 'hope-seating'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($venues as $venue): ?>
                            <tr>
                                <td><?php echo esc_html($venue->id); ?></td>
                                <td><strong><?php echo esc_html($venue->name); ?></strong></td>
                                <td><?php echo esc_html($venue->slug); ?></td>
                                <td><?php echo esc_html($venue->total_seats); ?></td>
                                <td>
                                    <span class="status-<?php echo esc_attr($venue->status); ?>">
                                        <?php echo esc_html($venue->status); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=hope-seating-seats&venue_id=' . $venue->id); ?>" 
                                       class="button button-small">
                                        <?php _e('Edit Seats', 'hope-seating'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
            <?php else: ?>
                <div class="hope-no-data" style="margin: 20px 0; padding: 15px; background: #f8d7da; border-left: 4px solid #f5c6cb; border-radius: 5px;">
                    <h3 style="margin-top: 0;">❌ No Seat Maps Found</h3>
                    <p>No seat maps or venues found. The plugin may need to be reinitialized.</p>
                </div>
            <?php endif; ?>
            
        </div>
        <?php
    }
    
    // Seats management page
    public function seats_page() {
        // Handle delete request
        if (isset($_GET['delete_map']) && isset($_GET['map_id']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_map_' . $_GET['map_id'])) {
            $this->delete_pricing_map($_GET['map_id']);
            echo '<div class="notice notice-success is-dismissible"><p><strong>Success!</strong> Pricing map deleted successfully.</p></div>';
        }
        
        ?>
        <div class="wrap">
            <h1><?php _e('Seat Map Management', 'hope-seating'); ?></h1>
            
            <?php $this->display_pricing_maps_table(); ?>
            
        </div>
        <?php
    }
    
    /**
     * Display pricing maps management table
     */
    private function display_pricing_maps_table() {
        if (!class_exists('HOPE_Pricing_Maps_Manager')) {
            echo '<p>Pricing maps system not available.</p>';
            return;
        }
        
        $pricing_manager = new HOPE_Pricing_Maps_Manager();
        $pricing_maps = $pricing_manager->get_pricing_maps();
        
        ?>
        <div class="pricing-maps-management">
            <h2>Pricing Maps</h2>
            
            <?php if (empty($pricing_maps)): ?>
                <p>No pricing maps found.</p>
            <?php else: ?>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Total Seats</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pricing_maps as $map): ?>
                            <?php
                            // Get seat count for this map
                            $seat_count = $this->get_pricing_map_seat_count($map->id);
                            $delete_url = wp_nonce_url(
                                admin_url('admin.php?page=hope-seating-seats&delete_map=1&map_id=' . $map->id),
                                'delete_map_' . $map->id
                            );
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($map->id); ?></strong></td>
                                <td><?php echo esc_html($map->name); ?></td>
                                <td>
                                    <?php echo esc_html($seat_count); ?> seats
                                    <?php if ($seat_count == 0): ?>
                                        <span style="color: #d63638; font-weight: bold;">(EMPTY)</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html(date('M j, Y', strtotime($map->created_at))); ?></td>
                                <td>
                                    <?php if ($seat_count == 0): ?>
                                        <a href="<?php echo esc_url($delete_url); ?>" 
                                           class="button button-secondary"
                                           style="background: #d63638; border-color: #d63638; color: white;"
                                           onclick="return confirm('Are you sure you want to delete this empty pricing map? This action cannot be undone.');">
                                            🗑️ Delete Empty Map
                                        </a>
                                    <?php else: ?>
                                        <span style="color: #50575e;">Contains <?php echo $seat_count; ?> seats - Cannot delete</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            
            <div style="margin-top: 20px; padding: 15px; background: #e7f3ff; border-left: 4px solid #0073aa;">
                <h3>Seat Map Management</h3>
                <p><strong>Safe Operations:</strong></p>
                <ul>
                    <li>✅ <strong>Delete empty maps</strong> - Remove pricing maps with 0 seats (safe cleanup)</li>
                    <li>⚠️ <strong>Maps with seats</strong> - Cannot be deleted to prevent data loss</li>
                </ul>
                <p><strong>Note:</strong> For seat count or pricing adjustments, use manual SQL queries or contact the developer.</p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get seat count for a pricing map
     */
    private function get_pricing_map_seat_count($map_id) {
        if (!class_exists('HOPE_Pricing_Maps_Manager')) {
            return 0;
        }
        
        $pricing_manager = new HOPE_Pricing_Maps_Manager();
        $seats = $pricing_manager->get_seats_with_pricing($map_id);
        return count($seats);
    }
    
    /**
     * Delete a pricing map (only if empty)
     */
    private function delete_pricing_map($map_id) {
        global $wpdb;
        
        $map_id = intval($map_id);
        $seat_count = $this->get_pricing_map_seat_count($map_id);
        
        if ($seat_count > 0) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Cannot delete pricing map with seats. It contains ' . $seat_count . ' seats.</p></div>';
            return false;
        }
        
        // Safe to delete - no seats associated
        $pricing_maps_table = $wpdb->prefix . 'hope_seating_pricing_maps';
        $result = $wpdb->delete(
            $pricing_maps_table,
            array('id' => $map_id),
            array('%d')
        );
        
        if ($result === false) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Failed to delete pricing map.</p></div>';
            return false;
        }
        
        return true;
    }
    
    // Settings page
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('HOPE Seating Settings', 'hope-seating'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('hope_seating_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Reservation Time', 'hope-seating'); ?></th>
                        <td>
                            <?php
                            $options = get_option('hope_seating_options', array());
                            $reservation_time = isset($options['reservation_time']) ? $options['reservation_time'] : 15;
                            ?>
                            <input type="number" name="hope_seating_options[reservation_time]" value="<?php echo esc_attr($reservation_time); ?>" min="5" max="60" />
                            <p class="description"><?php _e('Minutes to hold seats in cart before releasing', 'hope-seating'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    // Order Lookup page
    public function order_lookup_page() {
        // Instantiate order lookup class and render page
        if (!class_exists('HOPE_Admin_Order_Lookup')) {
            require_once HOPE_SEATING_PLUGIN_DIR . 'includes/class-admin-order-lookup.php';
        }
        $order_lookup = new HOPE_Admin_Order_Lookup();
        $order_lookup->render_page();
    }

    // Printable seating chart page
    public function printable_chart_page() {
        include plugin_dir_path(dirname(__FILE__)) . 'admin/printable-chart.php';
    }

    // Diagnostics page for debugging seat assignments
    public function diagnostics_page() {
        global $wpdb;
        
        $physical_seats_table = $wpdb->prefix . 'hope_seating_physical_seats';
        $seat_pricing_table = $wpdb->prefix . 'hope_seating_seat_pricing';
        $pricing_maps_table = $wpdb->prefix . 'hope_seating_pricing_maps';
        
        // Handle manual fix request - DISABLED DUE TO BUGS
        if (isset($_GET['fix_assignments']) && $_GET['fix_assignments'] == '1') {
            echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> This function has been disabled due to bugs that multiply seats. Please contact developer for manual fixes.</p></div>';
            return; // Exit early to prevent execution
        }
        
        
        // Handle complete fix for product 2272
        if (isset($_GET['fix_map_214']) && $_GET['fix_map_214'] == '1') {
            try {
                // Step 1: Create physical seats
                if (class_exists('HOPE_Physical_Seats_Manager')) {
                    $physical_manager = new HOPE_Physical_Seats_Manager();
                    $seats_created = $physical_manager->populate_physical_seats();
                    error_log("HOPE: Created $seats_created physical seats");
                }
                
                // Step 2: Create pricing assignments for map 214
                if (class_exists('HOPE_Pricing_Maps_Manager')) {
                    $pricing_manager = new HOPE_Pricing_Maps_Manager();
                    $assignment_count = $pricing_manager->regenerate_pricing_assignments(214);
                    error_log("HOPE: Created $assignment_count pricing assignments for map 214");
                }
                
                echo '<div class="notice notice-success is-dismissible"><p><strong>Success!</strong> Created ' . $seats_created . ' physical seats and ' . $assignment_count . ' pricing assignments for map 214. Product 2272 should now work!</p></div>';
                
            } catch (Exception $e) {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> ' . esc_html($e->getMessage()) . '</p></div>';
            }
        }

        // Handle physical seats regeneration request - DISABLED DUE TO BUGS
        if (isset($_GET['regenerate_seats']) && $_GET['regenerate_seats'] == '1') {
            echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Regenerate Seats function has been disabled due to bugs that create thousands of duplicate seats. Please contact developer for manual fixes.</p></div>';
            return; // Exit early to prevent execution
        }
        
        // Original regenerate seats code (disabled)
        if (false && isset($_GET['regenerate_seats']) && $_GET['regenerate_seats'] == '1') {
            // Ensure classes are loaded
            if (!class_exists('HOPE_Physical_Seats_Manager')) {
                require_once HOPE_SEATING_PLUGIN_DIR . 'includes/class-physical-seats.php';
            }
            if (!class_exists('HOPE_Pricing_Maps_Manager')) {
                require_once HOPE_SEATING_PLUGIN_DIR . 'includes/class-pricing-maps.php';
            }
            
            try {
                if (class_exists('HOPE_Physical_Seats_Manager')) {
                    $physical_manager = new HOPE_Physical_Seats_Manager();
                    $seats_created = $physical_manager->populate_physical_seats();
                    
                    // Also fix pricing assignments after regenerating physical seats
                    if (class_exists('HOPE_Pricing_Maps_Manager')) {
                        $pricing_manager = new HOPE_Pricing_Maps_Manager();
                        // Get ALL pricing maps and regenerate for each
                        $pricing_maps = $pricing_manager->get_pricing_maps();
                        if (!empty($pricing_maps)) {
                            foreach ($pricing_maps as $map) {
                                $assignment_count = $pricing_manager->regenerate_pricing_assignments($map->id);
                                error_log("HOPE: Regenerated $assignment_count pricing assignments for map {$map->id} ({$map->name})");
                            }
                        } else {
                            error_log("HOPE: No pricing maps found for regeneration");
                        }
                    }
                    
                    echo '<div class="notice notice-success is-dismissible"><p><strong>Success!</strong> Regenerated ' . $seats_created . ' physical seats with corrected section positioning. Section A is now leftmost, Section E is rightmost from audience perspective.</p></div>';
                } else {
                    echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> Physical seats manager class could not be loaded.</p></div>';
                }
            } catch (Exception $e) {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> ' . esc_html($e->getMessage()) . '</p></div>';
            }
        }
        
        ?>
        <div class="wrap">
            <h1><?php _e('HOPE Seating Diagnostics', 'hope-seating'); ?></h1>
            
            <?php
            // Get current state
            $current_counts = $wpdb->get_results("
                SELECT sp.pricing_tier, COUNT(*) as count 
                FROM $seat_pricing_table sp
                GROUP BY sp.pricing_tier
                ORDER BY sp.pricing_tier
            ");
            ?>
            
            <h2>Current vs Target Seat Counts</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Tier</th>
                        <th>Current Count</th>
                        <th>Target Count</th>
                        <th>Difference</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $targets = array('P1' => 108, 'P2' => 292, 'P3' => 88, 'AA' => 9);
                    $currents = array();
                    
                    foreach ($current_counts as $tier) {
                        $currents[$tier->pricing_tier] = $tier->count;
                    }
                    
                    $total_current = 0;
                    foreach ($targets as $tier => $target) {
                        $current = isset($currents[$tier]) ? $currents[$tier] : 0;
                        $diff = $target - $current;
                        $total_current += $current;
                        
                        echo "<tr>";
                        echo "<td><strong>$tier</strong></td>";
                        echo "<td>$current</td>";
                        echo "<td>$target</td>";
                        echo "<td style='color: " . ($diff == 0 ? 'green' : 'red') . "'>$diff</td>";
                        echo "</tr>";
                    }
                    ?>
                </tbody>
            </table>
            <p><strong>Total Current: <?php echo $total_current; ?> seats | Total Target: 497 seats</strong></p>
            
            <?php
            // Section breakdown
            $section_breakdown = $wpdb->get_results("
                SELECT ps.section, sp.pricing_tier, COUNT(*) as count
                FROM $physical_seats_table ps
                JOIN $seat_pricing_table sp ON ps.id = sp.physical_seat_id
                GROUP BY ps.section, sp.pricing_tier
                ORDER BY ps.section, sp.pricing_tier
            ");
            ?>
            
            <h2>Current Section Breakdown</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Section</th>
                        <th>P1</th>
                        <th>P2</th>
                        <th>P3</th>
                        <th>AA</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sections = array();
                    foreach ($section_breakdown as $row) {
                        if (!isset($sections[$row->section])) {
                            $sections[$row->section] = array('P1' => 0, 'P2' => 0, 'P3' => 0, 'AA' => 0);
                        }
                        $sections[$row->section][$row->pricing_tier] = $row->count;
                    }
                    
                    foreach ($sections as $section => $counts) {
                        $total = array_sum($counts);
                        echo "<tr>";
                        echo "<td><strong>Section $section</strong></td>";
                        echo "<td>{$counts['P1']}</td>";
                        echo "<td>{$counts['P2']}</td>";
                        echo "<td>{$counts['P3']}</td>";
                        echo "<td>{$counts['AA']}</td>";
                        echo "<td><strong>$total</strong></td>";
                        echo "</tr>";
                    }
                    ?>
                </tbody>
            </table>
            
            <h2>Manual Assignment Fix</h2>
            <p>This will reassign seats to match your exact spreadsheet targets:</p>
            <ul>
                <li><strong>P1:</strong> 108 seats (Premium)</li>
                <li><strong>P2:</strong> 292 seats (Standard)</li>
                <li><strong>P3:</strong> 88 seats (Value)</li>
                <li><strong>AA:</strong> 9 seats (Accessible)</li>
            </ul>
            
            <?php $fix_url = admin_url('admin.php?page=hope-seating-diagnostics&fix_assignments=1'); ?>
            <?php $regenerate_url = admin_url('admin.php?page=hope-seating-diagnostics&regenerate_seats=1'); ?>
            <?php $fix_214_url = admin_url('admin.php?page=hope-seating-diagnostics&fix_map_214=1'); ?>
            <div style="padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107; margin-bottom: 20px;">
                <h4 style="color: #856404; margin-top: 0;">⚠️ Diagnostic Tools Disabled</h4>
                <p><strong>These buttons have been temporarily disabled due to bugs that multiply seats instead of fixing them.</strong></p>
                <p>For seat count or pricing tier issues, please contact the developer for manual SQL fixes.</p>
                <p style="margin-bottom: 0;">
                    <button class="button button-primary" disabled style="opacity: 0.5;">❌ Fix Seat Assignments Now (DISABLED)</button>
                    <button class="button button-secondary" disabled style="opacity: 0.5; margin-left: 10px;">❌ Regenerate Seats (DISABLED)</button>
                    <button class="button button-secondary" disabled style="opacity: 0.5; margin-left: 10px;">❌ Fix Map 214 (DISABLED)</button>
                </p>
            </div>
            
            
            <h3>Section Layout Fix</h3>
            <p>If sections appear in the wrong order on the visual map (Section A should be leftmost, Section E rightmost from audience perspective), use the "Regenerate Seats" button above. This will:</p>
            <ul>
                <li>Recalculate all seat coordinates with corrected section positioning</li>
                <li>Place Section A on the left side of the stage</li>
                <li>Place Section E on the right side of the stage</li>
                <li>Maintain all pricing assignments</li>
            </ul>
        </div>
        <?php
    }
    
    // Manual fix for seat assignments
    private function manual_fix_seat_assignments() {
        global $wpdb;
        
        $physical_seats_table = $wpdb->prefix . 'hope_seating_physical_seats';
        $seat_pricing_table = $wpdb->prefix . 'hope_seating_seat_pricing';
        $pricing_maps_table = $wpdb->prefix . 'hope_seating_pricing_maps';
        
        // Get the pricing map
        $pricing_map = $wpdb->get_row("SELECT * FROM $pricing_maps_table LIMIT 1");
        if (!$pricing_map) {
            return false;
        }
        
        // Get all physical seats
        $all_seats = $wpdb->get_results("
            SELECT * FROM $physical_seats_table 
            ORDER BY section, `row_number`, seat_number
        ");
        
        // Clear existing assignments
        $deleted_count = $wpdb->query($wpdb->prepare(
            "DELETE FROM $seat_pricing_table WHERE pricing_map_id = %d",
            $pricing_map->id
        ));
        error_log("HOPE Seating: Deleted $deleted_count existing assignments");
        
        // Direct assignment to hit exact targets: P1=108, P2=292, P3=88, AA=9
        $assignments = array();
        $counts = array('P1' => 0, 'P2' => 0, 'P3' => 0, 'AA' => 0);
        $targets = array('P1' => 108, 'P2' => 292, 'P3' => 88, 'AA' => 9);
        
        $debug_log = array();
        
        foreach ($all_seats as $seat) {
            // Use the EXACT spreadsheet mapping - no arbitrary logic
            $tier = $this->get_exact_spreadsheet_tier($seat->section, $seat->row_number, $seat->seat_number, $seat->is_accessible);
            
            // Debug logging for first few seats
            if (count($debug_log) < 10) {
                $debug_log[] = "Seat {$seat->seat_id} (Section {$seat->section} Row {$seat->row_number} Seat {$seat->seat_number}) -> {$tier}";
            }
            
            $assignments[] = array(
                'seat_id' => $seat->id,
                'tier' => $tier
            );
            $counts[$tier]++;
        }
        
        // Log the debug info
        error_log("HOPE Seating Manual Assignment Debug:");
        foreach ($debug_log as $log_entry) {
            error_log("  " . $log_entry);
        }
        error_log("Predicted final counts: P1={$counts['P1']}, P2={$counts['P2']}, P3={$counts['P3']}, AA={$counts['AA']}");
        
        // Insert new assignments
        $inserted_count = 0;
        foreach ($assignments as $assignment) {
            $price = ($assignment['tier'] == 'P1' ? 50 : 
                     ($assignment['tier'] == 'P2' ? 35 : 25));
            
            $result = $wpdb->insert(
                $seat_pricing_table,
                array(
                    'pricing_map_id' => $pricing_map->id,
                    'physical_seat_id' => $assignment['seat_id'],
                    'pricing_tier' => $assignment['tier'],
                    'price' => $price
                ),
                array('%d', '%d', '%s', '%f')
            );
            
            if ($result !== false) {
                $inserted_count++;
            } else {
                error_log("HOPE Seating: Failed to insert assignment for seat {$assignment['seat_id']}: " . $wpdb->last_error);
            }
        }
        
        error_log("HOPE Seating: Inserted $inserted_count new assignments");
        
        error_log("HOPE Seating: Manual assignment complete. Final counts: P1={$counts['P1']}, P2={$counts['P2']}, P3={$counts['P3']}, AA={$counts['AA']}");
        
        return true;
    }
    
    // Get exact pricing tier from the original spreadsheet mapping
    private function get_exact_spreadsheet_tier($section, $row_number, $seat_number, $is_accessible) {
        // If accessible, always AA
        if ($is_accessible) {
            return 'AA';
        }
        
        // Log first few calls to debug
        static $debug_count = 0;
        if ($debug_count < 3) {
            error_log("DEBUG get_exact_spreadsheet_tier: Section='$section', Row='$row_number', Seat='$seat_number', Accessible='$is_accessible'");
            $debug_count++;
        }
        
        
        // EXACT SPREADSHEET MAPPING - Using your original data
        
        
        // Orchestra Level
        if ($section === 'A') {
            if ($row_number == 1) return 'P1';
            if (in_array($row_number, [2, 3])) return 'P2';
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
            if ($row_number == 9) return 'P3';
        }
        
        if ($section === 'B') {
            if (in_array($row_number, [1, 2, 3])) return 'P1';
            if (in_array($row_number, [4, 5, 6, 7, 8])) return 'P2';
            if ($row_number == 9) return 'P3';
            if ($row_number == 10) return 'AA'; // Already handled by is_accessible
        }
        
        if ($section === 'C') {
            if (in_array($row_number, [1, 2, 3])) return 'P1';
            if (in_array($row_number, [4, 5, 6, 7, 8, 9])) return 'P2';
        }
        
        if ($section === 'D') {
            if (in_array($row_number, [1, 2, 3])) return 'P1';
            if (in_array($row_number, [4, 5, 6, 7, 8])) return 'P2';
            if ($row_number == 9) {
                return (in_array($seat_number, [1, 2, 3, 4, 5])) ? 'P3' : 'AA';
            }
        }
        
        if ($section === 'E') {
            if ($row_number == 1) return 'P1';
            if ($row_number == 2) return 'P2';
            if ($row_number == 3) {
                return ($seat_number == 7) ? 'P3' : 'P2';
            }
            if ($row_number == 4) {
                return (in_array($seat_number, [6, 7])) ? 'P3' : 'P2';
            }
            if ($row_number == 5) {
                return (in_array($seat_number, [5, 6, 7])) ? 'P3' : 'P2';
            }
            if ($row_number == 6) {
                return (in_array($seat_number, [4, 5, 6, 7])) ? 'P3' : 'P2';
            }
            if ($row_number == 7) {
                return (in_array($seat_number, [3, 4, 5, 6, 7])) ? 'P3' : 'P2';
            }
            if ($row_number == 8) {
                return (in_array($seat_number, [2, 3, 4])) ? 'P3' : 'P2';
            }
            if ($row_number == 9) {
                return (in_array($seat_number, [1, 2])) ? 'AA' : 'P3';
            }
        }
        
        // Balcony Level
        if ($section === 'F') {
            if ($row_number == 1) {
                return (in_array($seat_number, [6, 7, 8, 9, 10])) ? 'P1' : 'P2';
            }
            if (in_array($row_number, [2, 3])) return 'P3';
        }
        
        if ($section === 'G') {
            if ($row_number == 1) return 'P1';  // Row 1: 24 seats P1
            if (in_array($row_number, [2, 3])) return 'P2';  // Rows 2-3: P2
        }
        
        if ($section === 'H') {
            if ($row_number == 1) {
                return (in_array($seat_number, [1, 2, 3, 4, 5, 6, 7, 8, 9])) ? 'P1' : 'P2';
            }
            if (in_array($row_number, [2, 3])) {
                return (in_array($seat_number, [1, 2, 3, 4, 5, 6, 7, 8, 9])) ? 'P2' : 'P3';
            }
            if ($row_number == 4) return 'P3';
        }
        
        // Default fallback
        return 'P2';
    }
    
    // WooCommerce Product Integration
    public function add_product_venue_tab($tabs) {
        $tabs['hope_seating'] = array(
            'label' => __('Venue & Seating', 'hope-seating'),
            'target' => 'hope_seating_venue_options',
            'class' => array('show_if_simple', 'show_if_variable'),
            'priority' => 21
        );
        return $tabs;
    }
    
    // Enhanced venue selection fields
    public function add_product_venue_fields() {
        global $post, $wpdb;
        
        // Get pricing maps from new architecture
        $pricing_maps = array();
        $venues = array(); // For backward compatibility
        
        if (class_exists('HOPE_Pricing_Maps_Manager')) {
            $pricing_manager = new HOPE_Pricing_Maps_Manager();
            $pricing_maps = $pricing_manager->get_pricing_maps();
            
            // Convert pricing maps to venue format for compatibility
            foreach ($pricing_maps as $map) {
                $seats_with_pricing = $pricing_manager->get_seats_with_pricing($map->id);
                $total_seats = count($seats_with_pricing);
                
                $venues[] = (object) array(
                    'id' => $map->id,
                    'name' => $map->name,
                    'total_seats' => $total_seats,
                    'status' => $map->status
                );
            }
        } else {
            // Fallback to old system
            $venues_table = $wpdb->prefix . 'hope_seating_venues';
            
            // Check if table exists first
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$venues_table'") == $venues_table;
            
            if ($table_exists) {
                $venues = $wpdb->get_results("SELECT * FROM $venues_table WHERE status = 'active' ORDER BY name");
                
                // If no venues found, try to create default ones
                if (empty($venues)) {
                    if (function_exists('hope_seating_create_default_venues')) {
                        hope_seating_create_default_venues();
                        // Try to get venues again
                        $venues = $wpdb->get_results("SELECT * FROM $venues_table WHERE status = 'active' ORDER BY name");
                    }
                }
            } else {
                $venues = array();
                // Create tables if they don't exist
                if (class_exists('HOPE_Seating_Database')) {
                    HOPE_Seating_Database::create_tables();
                    hope_seating_create_default_venues();
                    $venues = $wpdb->get_results("SELECT * FROM $venues_table WHERE status = 'active' ORDER BY name");
                }
            }
        }
        
        // Get saved venue for this product
        $selected_venue = get_post_meta($post->ID, '_hope_seating_venue_id', true);
        $enable_seating = get_post_meta($post->ID, '_hope_seating_enabled', true);
        
        ?>
        <div id="hope_seating_venue_options" class="panel woocommerce_options_panel">
            <div class="options_group">
                <h3 style="padding-left: 10px;">🎭 HOPE Theater Seating Configuration</h3>
                
                <?php
                // Enable seating checkbox
                woocommerce_wp_checkbox(array(
                    'id' => '_hope_seating_enabled',
                    'label' => __('Enable Seat Selection', 'hope-seating'),
                    'description' => __('Allow customers to select specific seats for this event', 'hope-seating'),
                    'value' => $enable_seating ? $enable_seating : 'no',
                    'desc_tip' => true
                ));
                ?>
                
                <div class="hope-seating-venue-options" style="<?php echo $enable_seating !== 'yes' ? 'display:none;' : ''; ?>">
                    
                    <p class="form-field">
                        <label for="_hope_seating_venue_id"><?php _e('Select Seat Map', 'hope-seating'); ?></label>
                        <select id="_hope_seating_venue_id" name="_hope_seating_venue_id" class="select short">
                            <option value=""><?php _e('— Select a seat map —', 'hope-seating'); ?></option>
                            <?php if (!empty($venues)): ?>
                                <?php foreach ($venues as $venue): ?>
                                    <option value="<?php echo esc_attr($venue->id); ?>" 
                                            <?php selected($selected_venue, $venue->id); ?>
                                            data-total-seats="<?php echo esc_attr($venue->total_seats); ?>">
                                        <?php echo esc_html($venue->name); ?> 
                                        (<?php echo esc_html($venue->total_seats); ?> seats)
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>No venues found - please activate plugin or check database</option>
                            <?php endif; ?>
                        </select>
                    </p>
                    
                    <?php if (empty($venues)): ?>
                        <p class="form-field">
                            <em style="color: #d63638;">
                                ⚠️ No venues found. 
                            </em>
                            <br>
                            <button type="button" id="hope-create-venues" class="button button-secondary" style="margin-top: 5px;">
                                Create Default Venues Now
                            </button>
                            <span id="hope-venue-creation-status" style="margin-left: 10px;"></span>
                        </p>
                        
                        <script type="text/javascript">
                        jQuery(document).ready(function($) {
                            $('#hope-create-venues').on('click', function(e) {
                                e.preventDefault();
                                var $button = $(this);
                                var $status = $('#hope-venue-creation-status');
                                
                                $button.prop('disabled', true).text('Creating...');
                                $status.html('<span style="color: #666;">Creating venues...</span>');
                                
                                $.ajax({
                                    url: ajaxurl,
                                    type: 'POST',
                                    data: {
                                        action: 'hope_create_default_venues',
                                        nonce: '<?php echo wp_create_nonce('hope_admin_nonce'); ?>'
                                    },
                                    success: function(response) {
                                        if (response.success) {
                                            $status.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                                            setTimeout(function() {
                                                location.reload();
                                            }, 2000);
                                        } else {
                                            $status.html('<span style="color: red;">✗ ' + response.data.message + '</span>');
                                            $button.prop('disabled', false).text('Create Default Venues Now');
                                        }
                                    },
                                    error: function() {
                                        $status.html('<span style="color: red;">✗ Server error occurred</span>');
                                        $button.prop('disabled', false).text('Create Default Venues Now');
                                    }
                                });
                            });
                        });
                        </script>
                    <?php endif; ?>
                    
                    <?php if ($selected_venue): ?>
                        <?php
                        // Get venue details from new architecture
                        $venue = null;
                        $config = array();
                        
                        if (class_exists('HOPE_Pricing_Maps_Manager')) {
                            $pricing_manager = new HOPE_Pricing_Maps_Manager();
                            $pricing_maps = $pricing_manager->get_pricing_maps();
                            
                            // Find the selected pricing map
                            foreach ($pricing_maps as $map) {
                                if ($map->id == $selected_venue) {
                                    $venue = $map;
                                    break;
                                }
                            }
                            
                            // Create config from new architecture
                            if ($venue) {
                                $config = array(
                                    'type' => 'theater',
                                    'levels' => array('orchestra', 'balcony'),
                                    'sections' => array('A', 'B', 'C', 'D', 'E', 'F', 'G', 'H')
                                );
                            }
                        } else {
                            // Fallback to old system
                            $venues_table = $wpdb->prefix . 'hope_seating_venues';
                            $venue = $wpdb->get_row($wpdb->prepare(
                                "SELECT * FROM $venues_table WHERE id = %d",
                                $selected_venue
                            ));
                            
                            if ($venue) {
                                $config = json_decode($venue->configuration, true);
                            }
                        }
                        
                        if ($venue) {
                            // Get seat statistics from new architecture
                            $seat_stats = array();
                            $pricing_tiers = array();
                            
                            if (class_exists('HOPE_Pricing_Maps_Manager')) {
                                $pricing_manager = new HOPE_Pricing_Maps_Manager();
                                $seats_with_pricing = $pricing_manager->get_seats_with_pricing($selected_venue);
                                
                                // Group by section for stats
                                $sections = array();
                                foreach ($seats_with_pricing as $seat) {
                                    if (!isset($sections[$seat->section])) {
                                        $sections[$seat->section] = 0;
                                    }
                                    $sections[$seat->section]++;
                                }
                                
                                foreach ($sections as $section => $count) {
                                    $seat_stats[] = (object) array('section' => $section, 'count' => $count);
                                }
                                
                                // Get pricing tiers from the manager
                                $pricing_tier_config = $pricing_manager->get_pricing_tiers();
                                foreach ($pricing_tier_config as $tier_code => $tier_info) {
                                    $pricing_tiers[] = (object) array(
                                        'tier_name' => $tier_code,
                                        'tier_label' => $tier_info['name'],
                                        'base_price' => $tier_info['default_price'],
                                        'color_code' => $tier_info['color']
                                    );
                                }
                            } else {
                                // Fallback to old system
                                $seat_maps_table = $wpdb->prefix . 'hope_seating_seat_maps';
                                $seat_stats = $wpdb->get_results($wpdb->prepare(
                                    "SELECT section, COUNT(*) as count 
                                     FROM $seat_maps_table 
                                     WHERE venue_id = %d 
                                     GROUP BY section",
                                    $selected_venue
                                ));
                                
                                // Get pricing tiers
                                $pricing_table = $wpdb->prefix . 'hope_seating_pricing_tiers';
                                $pricing_tiers = $wpdb->get_results($wpdb->prepare(
                                    "SELECT * FROM $pricing_table 
                                     WHERE venue_id = %d 
                                     ORDER BY sort_order",
                                    $selected_venue
                                ));
                            }
                            ?>
                            
                            <div class="hope-venue-info" style="background: #f7f7f7; padding: 15px; margin: 10px; border-radius: 5px;">
                                <h4><?php echo esc_html($venue->name); ?> Details</h4>
                                <?php if (isset($venue->description) && $venue->description): ?>
                                    <p><strong>Description:</strong> <?php echo esc_html($venue->description); ?></p>
                                <?php endif; ?>
                                <p><strong>Configuration:</strong> <?php echo esc_html($config['type']); ?></p>
                                <p><strong>Levels:</strong> <?php echo esc_html(implode(', ', $config['levels'])); ?></p>
                                <p><strong>Sections:</strong> <?php echo esc_html(implode(', ', $config['sections'])); ?></p>
                                <?php if (class_exists('HOPE_Pricing_Maps_Manager')): ?>
                                    <p><strong>Architecture:</strong> <span style="color: #2271b1;">✓ New Separated System</span></p>
                                <?php endif; ?>
                                
                                <?php if ($seat_stats): ?>
                                    <h5>Seat Distribution:</h5>
                                    <ul>
                                        <?php foreach ($seat_stats as $stat): ?>
                                            <li>Section <?php echo esc_html($stat->section); ?>: 
                                                <?php echo esc_html($stat->count); ?> seats</li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                                
                                <?php if ($pricing_tiers): ?>
                                    <h5>Pricing Tiers:</h5>
                                    <table class="widefat" style="margin-top: 10px;">
                                        <thead>
                                            <tr>
                                                <th>Tier</th>
                                                <th>Label</th>
                                                <th>Base Price</th>
                                                <th>Color</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($pricing_tiers as $tier): ?>
                                                <tr>
                                                    <td><?php echo esc_html($tier->tier_name); ?></td>
                                                    <td><?php echo esc_html($tier->tier_label); ?></td>
                                                    <td>$<?php echo number_format($tier->base_price, 2); ?></td>
                                                    <td>
                                                        <span style="display: inline-block; width: 20px; height: 20px; 
                                                                    background: <?php echo esc_attr($tier->color_code); ?>; 
                                                                    border: 1px solid #ccc; vertical-align: middle;">
                                                        </span>
                                                        <?php echo esc_html($tier->color_code); ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Seating Tier Summary -->
                            <div class="hope-seating-summary" style="margin: 15px 0; padding: 15px; background: #f0f8ff; border-radius: 5px; border-left: 4px solid #7c3aed;">
                                <h4>Seating Breakdown</h4>
                                <?php 
                                // Get seat counts by pricing tier from new architecture
                                if (class_exists('HOPE_Pricing_Maps_Manager')) {
                                    $pricing_manager = new HOPE_Pricing_Maps_Manager();
                                    $pricing_summary = $pricing_manager->get_pricing_summary($selected_venue);
                                    
                                    if (!empty($pricing_summary)): ?>
                                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin: 10px 0;">
                                            <?php foreach ($pricing_summary as $tier_code => $tier_data): ?>
                                                <div style="padding: 8px; background: white; border-radius: 4px; border-left: 3px solid <?php echo esc_attr($tier_data['color']); ?>;">
                                                    <strong><?php echo esc_html($tier_data['name']); ?> (<?php echo esc_html($tier_code); ?>)</strong><br>
                                                    <span style="color: #666;"><?php echo esc_html($tier_data['count']); ?> seats</span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p style="color: #e67e22; margin: 10px 0;">
                                            ⚠️ No pricing data found for this seat map.
                                            <br><small>Debug: Seat Map ID = <?php echo esc_html($selected_venue); ?></small>
                                        </p>
                                    <?php endif;
                                } else {
                                    // Fallback to old system
                                    if (class_exists('HOPE_Seat_Manager')) {
                                        $seat_manager = new HOPE_Seat_Manager($selected_venue);
                                        $pricing_tiers_old = $seat_manager->get_pricing_tiers();
                                        
                                        // Get actual seat counts from database
                                        $seat_maps_table = $wpdb->prefix . 'hope_seating_seat_maps';
                                        $seat_counts = $wpdb->get_results($wpdb->prepare(
                                            "SELECT pricing_tier, COUNT(*) as count 
                                             FROM $seat_maps_table 
                                             WHERE venue_id = %d 
                                             GROUP BY pricing_tier
                                             ORDER BY pricing_tier",
                                            $selected_venue
                                        ));
                                        
                                        if (!empty($seat_counts)): ?>
                                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin: 10px 0;">
                                                <?php foreach ($seat_counts as $tier_count): 
                                                    $tier_info = isset($pricing_tiers_old[$tier_count->pricing_tier]) ? $pricing_tiers_old[$tier_count->pricing_tier] : array('name' => $tier_count->pricing_tier, 'color' => '#666');
                                                    ?>
                                                    <div style="padding: 8px; background: white; border-radius: 4px; border-left: 3px solid <?php echo esc_attr($tier_info['color']); ?>;">
                                                        <strong><?php echo esc_html($tier_info['name']); ?> (<?php echo esc_html($tier_count->pricing_tier); ?>)</strong><br>
                                                        <span style="color: #666;"><?php echo esc_html($tier_count->count); ?> seats</span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <p style="color: #e67e22; margin: 10px 0;">
                                                ⚠️ No seats found for this venue (legacy system).
                                            </p>
                                        <?php endif;
                                    } else {
                                        echo '<p style="color: red;">No seat management system found</p>';
                                    }
                                } ?>
                            </div>
                            
                            <!-- Setup Variations Button -->
                            <div class="hope-variation-setup" style="margin: 15px 0; padding: 15px; background: #f9f9f9; border-radius: 5px;">
                                <h4>Product Variations Setup</h4>
                                <p class="description">
                                    For seating events, the product should be set to <strong>Variable Product</strong> type. 
                                    Each seating tier will become a product variation that customers can select.
                                </p>
                                
                                <?php $product = wc_get_product($post->ID); ?>
                                <?php if ($product && $product->is_type('variable')): ?>
                                    <button type="button" class="button button-primary" id="hope_setup_variations" 
                                            data-venue-id="<?php echo esc_attr($selected_venue); ?>" 
                                            data-product-id="<?php echo esc_attr($post->ID); ?>">
                                        Setup Pricing Variations
                                    </button>
                                    <span class="spinner" style="float: none; margin-left: 10px;"></span>
                                    <span id="hope-setup-message" style="margin-left: 10px; display: none;"></span>
                                    
                                    <div class="hope-variation-help" style="margin-top: 10px; font-size: 12px; color: #666;">
                                        This will create variations for: VIP, Premium, General, and Accessible seating tiers.
                                        You can adjust pricing in the Variations tab after creation.
                                    </div>
                                <?php else: ?>
                                    <p style="color: #d63638; font-weight: bold;">
                                        ⚠️ Please change the product type to "Variable product" to enable seating variations.
                                    </p>
                                    <p class="description">
                                        Go to Product Data → Product Type dropdown → Select "Variable product" → Save
                                    </p>
                                <?php endif; ?>
                            </div>
                            
                        <?php } ?>
                    <?php endif; ?>
                    
                    <?php
                    // Overflow seating toggle
                    $overflow_enabled = get_post_meta($post->ID, '_hope_overflow_enabled', true);
                    woocommerce_wp_checkbox(array(
                        'id' => '_hope_overflow_enabled',
                        'label' => __('Enable Overflow Seating', 'hope-seating'),
                        'description' => __('Show removable seats in row 9 (19 additional seats: A9 1-6, B9 1-4, D9 1-5, E9 1-4)', 'hope-seating'),
                        'value' => $overflow_enabled ? $overflow_enabled : 'no',
                        'desc_tip' => true
                    ));
                    ?>
                </div>
            </div>

            <div class="options_group">
                <p class="form-field" style="padding-left: 10px;">
                    <em>💡 Tip: Make sure to set the product as "Virtual" if this is an event ticket.</em>
                </p>
            </div>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Toggle venue options based on checkbox
            $('#_hope_seating_enabled').change(function() {
                if ($(this).is(':checked')) {
                    $('.hope-seating-venue-options').slideDown();
                } else {
                    $('.hope-seating-venue-options').slideUp();
                }
            });
            
            // Update venue info when selection changes
            $('#_hope_seating_venue_id').change(function() {
                if ($(this).val()) {
                    var seats = $(this).find('option:selected').data('total-seats');
                    console.log('Selected venue has ' + seats + ' seats');
                }
            });
            
            // Setup variations button click handler
            $('#hope_setup_variations').on('click', function(e) {
                e.preventDefault();
                
                var $button = $(this);
                var $spinner = $button.next('.spinner');
                var $message = $('#hope-setup-message');
                var venueId = $button.data('venue-id');
                var productId = $button.data('product-id');
                
                if (!venueId || !productId) {
                    alert('Please save the product first with a venue selected.');
                    return;
                }
                
                if (!confirm('This will create/update product variations based on the venue pricing tiers. Continue?')) {
                    return;
                }
                
                $button.prop('disabled', true);
                $spinner.addClass('is-active');
                $message.hide();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'hope_setup_venue_variations',
                        product_id: productId,
                        venue_id: venueId,
                        nonce: '<?php echo wp_create_nonce('hope_seating_admin'); ?>'
                    },
                    success: function(response) {
                        $spinner.removeClass('is-active');
                        
                        if (response.success) {
                            $message.text(response.data.message).css('color', 'green').show();
                            // Reload page after 2 seconds to show new variations
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            $message.text('Error: ' + response.data.message).css('color', 'red').show();
                            $button.prop('disabled', false);
                        }
                    },
                    error: function(xhr, status, error) {
                        $spinner.removeClass('is-active');
                        $button.prop('disabled', false);
                        
                        console.error('AJAX Error:', error);
                        console.error('Response:', xhr.responseText);
                        
                        $message.text('Server error. Check console for details.').css('color', 'red').show();
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    // Save venue selection
    public function save_product_venue_fields($post_id) {
        // Check if nonce is set
        if (!isset($_POST['woocommerce_meta_nonce'])) {
            return;
        }
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['woocommerce_meta_nonce'], 'woocommerce_save_data')) {
            return;
        }
        
        // Check autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Save enable seating checkbox
        $enable_seating = isset($_POST['_hope_seating_enabled']) ? 'yes' : 'no';
        update_post_meta($post_id, '_hope_seating_enabled', $enable_seating);

        // Save venue selection
        if (isset($_POST['_hope_seating_venue_id'])) {
            update_post_meta($post_id, '_hope_seating_venue_id', sanitize_text_field($_POST['_hope_seating_venue_id']));
        }

        // Save overflow seating toggle
        $overflow_enabled = isset($_POST['_hope_overflow_enabled']) ? 'yes' : 'no';
        update_post_meta($post_id, '_hope_overflow_enabled', $overflow_enabled);
    }
    
    // Add venue column to products list
    public function add_product_columns($columns) {
        $new_columns = array();
        
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'name') {
                $new_columns['hope_venue'] = __('Venue', 'hope-seating');
            }
        }
        
        return $new_columns;
    }
    
    // Show venue in product column
    public function show_product_column_content($column, $post_id) {
        if ($column === 'hope_venue') {
            $enabled = get_post_meta($post_id, '_hope_seating_enabled', true);
            $venue_id = get_post_meta($post_id, '_hope_seating_venue_id', true);
            
            if ($enabled === 'yes' && $venue_id) {
                global $wpdb;
                $venues_table = $wpdb->prefix . 'hope_seating_venues';
                $venue = $wpdb->get_row($wpdb->prepare(
                    "SELECT name, total_seats FROM $venues_table WHERE id = %d",
                    $venue_id
                ));
                
                if ($venue) {
                    echo '<strong>' . esc_html($venue->name) . '</strong><br>';
                    echo '<small>' . esc_html($venue->total_seats) . ' seats</small>';
                } else {
                    echo '<span style="color: #999;">—</span>';
                }
            } else {
                echo '<span style="color: #999;">—</span>';
            }
        }
    }
    
    /**
     * AJAX handler to create default venues
     */
    public function ajax_create_default_venues() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'hope_admin_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        // Create database tables first if they don't exist
        if (class_exists('HOPE_Seating_Database')) {
            HOPE_Seating_Database::create_tables();
        }
        
        // Create default venues
        if (function_exists('hope_seating_create_default_venues')) {
            hope_seating_create_default_venues();
            
            // Check if venues were created
            global $wpdb;
            $venues_table = $wpdb->prefix . 'hope_seating_venues';
            $count = $wpdb->get_var("SELECT COUNT(*) FROM $venues_table");
            
            if ($count > 0) {
                wp_send_json_success(array('message' => "Successfully created $count default venues"));
            } else {
                wp_send_json_error(array('message' => 'Failed to create venues - check error logs'));
            }
        } else {
            wp_send_json_error(array('message' => 'Venue creation function not available'));
        }
    }
}
?>