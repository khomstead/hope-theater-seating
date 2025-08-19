<?php
/**
 * Frontend functionality for HOPE Theater Seating
 * FINAL FIXED VERSION - Incorporates all fixes
 * Replace /wp-content/plugins/hope-theater-seating/includes/class-frontend.php with this
 */

if (!defined('ABSPATH')) {
    exit;
}

class HOPE_Seating_Frontend {
    
    public function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'), 5);
        add_shortcode('hope_seating_chart', array($this, 'seating_chart_shortcode'));
        add_shortcode('hope_seat_button', array($this, 'seat_button_shortcode'));
        
        // AJAX handlers
        add_action('wp_ajax_hope_get_venue_seats', array($this, 'ajax_get_venue_seats'));
        add_action('wp_ajax_nopriv_hope_get_venue_seats', array($this, 'ajax_get_venue_seats'));
        
        add_action('wp_ajax_hope_reserve_seats', array($this, 'ajax_reserve_seats'));
        add_action('wp_ajax_nopriv_hope_reserve_seats', array($this, 'ajax_reserve_seats'));
        
        // WooCommerce integration
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_seats_to_cart_item'), 10, 3);
        add_action('woocommerce_before_add_to_cart_button', array($this, 'add_hidden_seat_field'));
        
        // Schedule cleanup
        if (!wp_next_scheduled('hope_seating_cleanup')) {
            wp_schedule_event(time(), 'hourly', 'hope_seating_cleanup');
        }
        add_action('hope_seating_cleanup', array($this, 'cleanup_expired_reservations'));
    }
    
    public function enqueue_scripts() {
        global $post;
        
        $load_scripts = false;
        
        if (function_exists('is_product') && is_product()) {
            $load_scripts = true;
        }
        
        if ($post && is_object($post) && (has_shortcode($post->post_content, 'hope_seating_chart') || has_shortcode($post->post_content, 'hope_seat_button'))) {
            $load_scripts = true;
        }
        
        if (is_singular()) {
            $load_scripts = true;
        }
        
        if ($load_scripts) {
            wp_register_script(
                'hope-seating-frontend',
                HOPE_SEATING_PLUGIN_URL . 'assets/js/frontend.js',
                array('jquery'),
                HOPE_SEATING_VERSION,
                true
            );
            
            wp_localize_script('hope-seating-frontend', 'hopeSeating', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('hope_seating_nonce'),
                'pluginUrl' => HOPE_SEATING_PLUGIN_URL,
                'strings' => array(
                    'select_seats' => __('Please select your seats', 'hope-seating'),
                    'seats_selected' => __('seats selected', 'hope-seating'),
                    'seat_unavailable' => __('This seat is no longer available', 'hope-seating'),
                    'max_seats_reached' => __('Maximum number of seats selected', 'hope-seating'),
                    'loading' => __('Loading seating chart...', 'hope-seating'),
                    'error_loading' => __('Error loading seating chart', 'hope-seating')
                )
            ));
            
            wp_enqueue_script('hope-seating-frontend');
            
            wp_enqueue_style(
                'hope-seating-frontend',
                HOPE_SEATING_PLUGIN_URL . 'assets/css/frontend.css',
                array(),
                HOPE_SEATING_VERSION
            );
            
            // Add inline styles for overlay
            wp_add_inline_style('hope-seating-frontend', '
                .hope-seating-overlay {
                    display: none;
                    position: fixed;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background: rgba(0, 0, 0, 0.8);
                    z-index: 999999;
                    overflow: auto;
                }
                .hope-seating-overlay.active {
                    display: block;
                }
                .hope-seating-modal {
                    position: relative;
                    background: white;
                    max-width: 1400px;
                    width: 95%;
                    margin: 20px auto;
                    border-radius: 10px;
                    box-shadow: 0 0 40px rgba(0,0,0,0.5);
                }
                .hope-seating-modal-header {
                    padding: 20px;
                    border-bottom: 1px solid #ddd;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }
                .hope-seating-close {
                    background: none;
                    border: none;
                    font-size: 30px;
                    cursor: pointer;
                    color: #999;
                }
                .hope-seating-modal-body {
                    padding: 20px;
                    max-height: 80vh;
                    overflow: auto;
                }
            ');
        }
    }

    /**
 * Button shortcode for overlay seat selection
 * This is the fixed version that properly instantiates the seating chart
 */
public function seat_button_shortcode($atts) {
    $atts = shortcode_atts(array(
        'text' => 'Select Your Seats',
        'class' => 'button hope-seat-selector',
        'venue_id' => 1,
        'event_id' => get_the_ID()
    ), $atts);
    
    ob_start();
    ?>
    <button type="button" 
            class="<?php echo esc_attr($atts['class']); ?>" 
            onclick="openHopeSeatingOverlay(<?php echo esc_attr($atts['venue_id']); ?>, <?php echo esc_attr($atts['event_id']); ?>)">
        <?php echo esc_html($atts['text']); ?>
    </button>
    
    <!-- Overlay structure (hidden by default) -->
    <div id="hope-seating-overlay" class="hope-seating-overlay">
        <div class="hope-seating-modal">
            <div class="hope-seating-modal-header">
                <h2>Select Your Seats</h2>
                <button class="hope-seating-close" onclick="closeHopeSeatingOverlay()">&times;</button>
            </div>
            <div class="hope-seating-modal-body">
                <div id="hope-seating-content">
                    <!-- Seating chart will be loaded here -->
                </div>
            </div>
        </div>
    </div>
    
    <script>
    function openHopeSeatingOverlay(venueId, eventId) {
        document.getElementById('hope-seating-overlay').classList.add('active');
        // Load seating chart
        loadHopeSeatingChart(venueId, eventId);
    }
    
    function closeHopeSeatingOverlay() {
        document.getElementById('hope-seating-overlay').classList.remove('active');
    }
    
    function loadHopeSeatingChart(venueId, eventId) {
        var container = document.getElementById('hope-seating-content');
        container.innerHTML = '<div class="hope-seating-loading">Loading seating chart...</div>';
        
        // Make AJAX call to load the chart
        jQuery.ajax({
            url: hopeSeating.ajaxurl,
            type: 'POST',
            data: {
                action: 'hope_get_venue_seats',
                venue_id: venueId,
                event_id: eventId,
                nonce: hopeSeating.nonce
            },
            success: function(response) {
                if (response.success) {
                    // FIX: Instead of calling undefined renderHopeTheaterLayout,
                    // instantiate the HOPETheaterSeatingChart class
                    container.innerHTML = '<div id="hope-modal-seating-chart" class="hope-seating-chart"></div>';
                    
                    // Check if the HOPETheaterSeatingChart class is available
                    if (typeof HOPETheaterSeatingChart !== 'undefined') {
                        var seatingChart = new HOPETheaterSeatingChart('#hope-modal-seating-chart', {
                            venueId: venueId,
                            productId: eventId,
                            seatData: response.data.seats,
                            venueData: response.data.venue,
                            bookedSeats: response.data.booked_seats || [],
                            showPricing: true,
                            showLegend: true,
                            enableSelection: true
                        });
                    } else {
                        // Fallback: Display the raw seat data in a simple format
                        var html = '<div class="hope-seating-fallback">';
                        html += '<h3>' + (response.data.venue ? response.data.venue.name : 'Seating Chart') + '</h3>';
                        html += '<p>Seats available: ' + (response.data.seats ? response.data.seats.length : 0) + '</p>';
                        
                        if (response.data.seats && response.data.seats.length > 0) {
                            html += '<div class="seat-grid">';
                            response.data.seats.forEach(function(seat) {
                                var isBooked = response.data.booked_seats && response.data.booked_seats.includes(seat.seat_number);
                                html += '<span class="seat-item ' + (isBooked ? 'booked' : 'available') + '">';
                                html += seat.section + '-' + seat.row + '-' + seat.number;
                                html += '</span> ';
                            });
                            html += '</div>';
                        }
                        
                        html += '</div>';
                        container.innerHTML = html;
                    }
                } else {
                    container.innerHTML = '<div class="hope-seating-error">Error: ' + (response.data || 'Failed to load seating chart') + '</div>';
                }
            },
            error: function() {
                container.innerHTML = '<div class="hope-seating-error">Error loading seating chart. Please refresh and try again.</div>';
            }
        });
    }
    
    // Close on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeHopeSeatingOverlay();
        }
    });
    </script>
    <?php
    return ob_get_clean();
}
    
    /**
     * Original seating chart shortcode
     */
    public function seating_chart_shortcode($atts) {
        $atts = shortcode_atts(array(
            'venue_id' => get_post_meta(get_the_ID(), '_hope_seating_venue_id', true) ?: 1,
            'event_id' => get_the_ID(),
            'max_seats' => 8,
            'show_legend' => 'true',
            'height' => '600px'
        ), $atts);
        
        $venue_id = intval($atts['venue_id']);
        $event_id = intval($atts['event_id']);
        
        global $wpdb;
        $venues_table = $wpdb->prefix . 'hope_seating_venues';
        $venue = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $venues_table WHERE id = %d",
            $venue_id
        ));
        
        if (!$venue) {
            return '<p>Error: Venue not found (ID: ' . $venue_id . ').</p>';
        }
        
        ob_start();
        ?>
        <div class="hope-seating-widget" 
             data-venue-id="<?php echo esc_attr($venue_id); ?>"
             data-event-id="<?php echo esc_attr($event_id); ?>"
             data-max-seats="<?php echo esc_attr($atts['max_seats']); ?>"
             data-show-legend="<?php echo esc_attr($atts['show_legend']); ?>">
            
            <div class="hope-seating-chart-container" style="height: <?php echo esc_attr($atts['height']); ?>;">
                <div class="hope-seating-loading">
                    <p><?php _e('Loading seating chart...', 'hope-seating'); ?></p>
                </div>
                <div id="hope-seating-chart-<?php echo esc_attr($venue_id); ?>" class="hope-seating-chart"></div>
            </div>
            
            <?php if ($atts['show_legend'] === 'true'): ?>
            <div class="hope-seating-legend">
                <div class="legend-item">
                    <span class="legend-color available"></span>
                    <span class="legend-label"><?php _e('Available', 'hope-seating'); ?></span>
                </div>
                <div class="legend-item">
                    <span class="legend-color selected"></span>
                    <span class="legend-label"><?php _e('Selected', 'hope-seating'); ?></span>
                </div>
                <div class="legend-item">
                    <span class="legend-color unavailable"></span>
                    <span class="legend-label"><?php _e('Unavailable', 'hope-seating'); ?></span>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * AJAX handler to get venue seats - FIXED VERSION
     */
    public function ajax_get_venue_seats() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'hope_seating_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $venue_id = isset($_POST['venue_id']) ? intval($_POST['venue_id']) : 0;
        $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
        
        if (!$venue_id) {
            wp_send_json_error('Invalid venue ID');
            return;
        }
        
        global $wpdb;
        
        // Get venue data
        $venues_table = $wpdb->prefix . 'hope_seating_venues';
        $venue = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $venues_table WHERE id = %d",
            $venue_id
        ));
        
        if (!$venue) {
            wp_send_json_error('Venue not found');
            return;
        }
        
        // Get seats - FIXED: Don't use ORDER BY with row_number
        $seats_table = $wpdb->prefix . 'hope_seating_seat_maps';
        $seats = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $seats_table WHERE venue_id = %d",
            $venue_id
        ), ARRAY_A);
        
        // Sort in PHP to avoid MariaDB issues
        if (!empty($seats)) {
            usort($seats, function($a, $b) {
                $section_compare = strcmp($a['section'], $b['section']);
                if ($section_compare !== 0) return $section_compare;
                
                $row_compare = intval($a['row_number']) - intval($b['row_number']);
                if ($row_compare !== 0) return $row_compare;
                
                return intval($a['seat_number']) - intval($b['seat_number']);
            });
        }
        
        // Get booked seats
        $booked_seats = array();
        if ($event_id) {
            $bookings_table = $wpdb->prefix . 'hope_seating_bookings';
            if ($wpdb->get_var("SHOW TABLES LIKE '$bookings_table'") == $bookings_table) {
                $booked = $wpdb->get_results($wpdb->prepare(
                    "SELECT seat_id FROM $bookings_table 
                    WHERE event_id = %d AND status IN ('confirmed', 'reserved')",
                    $event_id
                ));
                
                foreach ($booked as $booking) {
                    $booked_seats[] = $booking->seat_id;
                }
            }
        }
        
        wp_send_json_success(array(
            'venue' => array(
                'id' => $venue->id,
                'name' => $venue->name,
                'total_seats' => $venue->total_seats,
                'config' => json_decode($venue->configuration, true)
            ),
            'seats' => $seats,
            'booked_seats' => $booked_seats,
            'pricing_tiers' => array(
                'General' => array('price' => '25.00', 'color' => '#4CAF50'),
                'Premium' => array('price' => '35.00', 'color' => '#2196F3'),
                'VIP' => array('price' => '50.00', 'color' => '#9C27B0')
            )
        ));
    }
    
    /**
     * Reserve seats temporarily
     */
    public function ajax_reserve_seats() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'hope_seating_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $seat_ids = isset($_POST['seat_ids']) ? json_decode(stripslashes($_POST['seat_ids']), true) : array();
        $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
        
        if (empty($seat_ids) || !$event_id) {
            wp_send_json_error('Invalid request');
            return;
        }
        
        global $wpdb;
        $bookings_table = $wpdb->prefix . 'hope_seating_bookings';
        
        // Reserve for 15 minutes
        $reserved_until = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        $session_id = session_id() ?: wp_generate_password(32, false);
        
        $success = true;
        foreach ($seat_ids as $seat_id) {
            $result = $wpdb->replace($bookings_table, array(
                'seat_id' => $seat_id,
                'event_id' => $event_id,
                'status' => 'reserved',
                'reserved_until' => $reserved_until,
                'session_id' => $session_id
            ));
            
            if (!$result) {
                $success = false;
                break;
            }
        }
        
        if ($success) {
            wp_send_json_success(array(
                'reserved_until' => $reserved_until,
                'message' => 'Seats reserved for 15 minutes'
            ));
        } else {
            wp_send_json_error('Failed to reserve seats');
        }
    }
    
    /**
     * Add hidden field for selected seats
     */
    public function add_hidden_seat_field() {
        echo '<input type="hidden" id="hope_selected_seats_cart" name="hope_selected_seats" value="" />';
    }
    
    /**
     * Add selected seats to cart item data
     */
    public function add_seats_to_cart_item($cart_item_data, $product_id, $variation_id) {
        if (isset($_POST['hope_selected_seats']) && !empty($_POST['hope_selected_seats'])) {
            $cart_item_data['hope_selected_seats'] = sanitize_text_field($_POST['hope_selected_seats']);
        }
        return $cart_item_data;
    }
    
    /**
     * Cleanup expired reservations
     */
    public function cleanup_expired_reservations() {
        global $wpdb;
        
        $bookings_table = $wpdb->prefix . 'hope_seating_bookings';
        if ($wpdb->get_var("SHOW TABLES LIKE '$bookings_table'") == $bookings_table) {
            $wpdb->query(
                "DELETE FROM $bookings_table 
                WHERE status = 'reserved' 
                AND reserved_until < NOW()"
            );
        }
    }
}