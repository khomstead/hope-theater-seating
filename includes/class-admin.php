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
    }
    
    public function init_settings() {
        register_setting('hope_seating_settings', 'hope_seating_options');
    }
    
    // Main admin page
    public function main_page() {
        global $wpdb;
        
        // Get statistics
        $venues_table = $wpdb->prefix . 'hope_seating_venues';
        $seats_table = $wpdb->prefix . 'hope_seating_seat_maps';
        $events_table = $wpdb->prefix . 'hope_seating_event_seats';
        
        $total_venues = $wpdb->get_var("SELECT COUNT(*) FROM $venues_table");
        $total_seats = $wpdb->get_var("SELECT COUNT(*) FROM $seats_table");
        $booked_seats = $wpdb->get_var("SELECT COUNT(*) FROM $events_table WHERE status = 'booked'");
        
        ?>
        <div class="wrap">
            <h1><?php _e('HOPE Theater Seating Dashboard', 'hope-seating'); ?></h1>
            
            <div class="hope-seating-dashboard">
                <div class="hope-stats-grid">
                    <div class="hope-stat-box">
                        <h3><?php _e('Venues', 'hope-seating'); ?></h3>
                        <p class="hope-stat-number"><?php echo esc_html($total_venues); ?></p>
                    </div>
                    <div class="hope-stat-box">
                        <h3><?php _e('Total Seats', 'hope-seating'); ?></h3>
                        <p class="hope-stat-number"><?php echo esc_html($total_seats); ?></p>
                    </div>
                    <div class="hope-stat-box">
                        <h3><?php _e('Booked Seats', 'hope-seating'); ?></h3>
                        <p class="hope-stat-number"><?php echo esc_html($booked_seats); ?></p>
                    </div>
                </div>
                
                <div class="hope-actions">
                    <h2><?php _e('Quick Actions', 'hope-seating'); ?></h2>
                    <a href="<?php echo admin_url('admin.php?page=hope-seating-venues'); ?>" class="button button-primary">
                        <?php _e('Manage Venues', 'hope-seating'); ?>
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
        .hope-actions {
            margin-top: 30px;
        }
        .hope-actions .button {
            margin-right: 10px;
        }
        </style>
        <?php
    }
    
    // Venues management page  
    public function venues_page() {
        global $wpdb;
        $venues_table = $wpdb->prefix . 'hope_seating_venues';
        $venues = $wpdb->get_results("SELECT * FROM $venues_table ORDER BY name");
        
        ?>
        <div class="wrap">
            <h1><?php _e('Venue Management', 'hope-seating'); ?></h1>
            
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
        </div>
        <?php
    }
    
    // Seats management page
    public function seats_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Seat Map Editor', 'hope-seating'); ?></h1>
            <p><?php _e('Visual seat editor coming soon. Currently, seats are managed through the database.', 'hope-seating'); ?></p>
        </div>
        <?php
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
                            <input type="number" name="hope_seating_options[reservation_time]" value="15" min="5" max="60" />
                            <p class="description"><?php _e('Minutes to hold seats in cart before releasing', 'hope-seating'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    // WooCommerce Product Integration
    public function add_product_venue_tab($tabs) {
        $tabs['hope_seating'] = array(
            'label' => __('Venue & Seating', 'hope-seating'),
            'target' => 'hope_seating_venue_options',
            'class' => array('show_if_simple'),
            'priority' => 21
        );
        return $tabs;
    }
    
    // Enhanced venue selection fields
    public function add_product_venue_fields() {
        global $post, $wpdb;
        
        // Get all active venues
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
                        <label for="_hope_seating_venue_id"><?php _e('Select Venue', 'hope-seating'); ?></label>
                        <select id="_hope_seating_venue_id" name="_hope_seating_venue_id" class="select short">
                            <option value=""><?php _e('— Select a venue —', 'hope-seating'); ?></option>
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
                                ⚠️ No venues found. Please deactivate and reactivate the HOPE Theater Seating plugin to create default venues.
                            </em>
                        </p>
                    <?php endif; ?>
                    
                    <?php if ($selected_venue): ?>
                        <?php
                        // Get venue details
                        $venue = $wpdb->get_row($wpdb->prepare(
                            "SELECT * FROM $venues_table WHERE id = %d",
                            $selected_venue
                        ));
                        
                        if ($venue) {
                            $config = json_decode($venue->configuration, true);
                            
                            // Get seat statistics
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
                            ?>
                            
                            <div class="hope-venue-info" style="background: #f7f7f7; padding: 15px; margin: 10px; border-radius: 5px;">
                                <h4><?php echo esc_html($venue->name); ?> Details</h4>
                                <p><strong>Configuration:</strong> <?php echo esc_html($config['type']); ?></p>
                                <p><strong>Levels:</strong> <?php echo esc_html(implode(', ', $config['levels'])); ?></p>
                                <p><strong>Sections:</strong> <?php echo esc_html(implode(', ', $config['sections'])); ?></p>
                                
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
                            
                        <?php } ?>
                    <?php endif; ?>
                    
                    <?php
                    // Future event-specific settings can go here if needed
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
}
?>