<?php
/**
 * HOPE Theater Seating - Admin Seat Blocking Interface
 * Provides admin interface for blocking seats across all events
 * 
 * @package HOPE_Theater_Seating
 * @version 2.4.7
 */

if (!defined('ABSPATH')) {
    exit;
}

class HOPE_Admin_Seat_Blocking {
    
    /**
     * Initialize admin seat blocking interface
     */
    public function __construct() {
        // Only initialize if seat blocking is available
        if (!$this->is_seat_blocking_available()) {
            return;
        }
        
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Handle AJAX requests
        add_action('wp_ajax_hope_admin_create_seat_block', array($this, 'ajax_create_seat_block'));
        add_action('wp_ajax_hope_admin_remove_seat_block', array($this, 'ajax_remove_seat_block'));
        add_action('wp_ajax_hope_admin_get_event_seats', array($this, 'ajax_get_event_seats'));
        
        // Enqueue admin assets
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        error_log('HOPE: Admin seat blocking interface initialized');
    }
    
    /**
     * Check if seat blocking functionality is available
     * @return bool
     */
    private function is_seat_blocking_available() {
        return class_exists('HOPE_Seat_Blocking_Handler') && 
               HOPE_Seat_Blocking_Handler::is_available();
    }
    
    /**
     * Add admin menu for seat blocking
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Theater Seat Blocking',
            'Seat Blocking',
            'manage_woocommerce',
            'hope-seat-blocking',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * Render the admin page for seat blocking
     */
    public function render_admin_page() {
        // Get all products with theater seating enabled
        $theater_products = $this->get_theater_products();
        
        echo '<div class="wrap">';
        echo '<h1>🎭 Theater Seat Blocking Management</h1>';
        
        // Include admin styles
        $this->render_admin_styles();
        
        if (empty($theater_products)) {
            echo '<div class="notice notice-info"><p>No theater seating products found. Enable theater seating on products to manage seat blocking.</p></div>';
            echo '</div>';
            return;
        }
        
        // Event selection
        echo '<div class="hope-admin-section">';
        echo '<h2>Select Event to Manage</h2>';
        echo '<select id="hope-event-selector" style="width: 100%; max-width: 400px; padding: 8px;">';
        echo '<option value="">-- Select an Event --</option>';
        foreach ($theater_products as $product) {
            echo '<option value="' . $product->get_id() . '">' . esc_html($product->get_name()) . ' (ID: ' . $product->get_id() . ')</option>';
        }
        echo '</select>';
        echo '</div>';
        
        // Seat blocking interface (initially hidden)
        echo '<div id="hope-blocking-interface" style="display: none;">';
        
        // Current blocks display
        echo '<div class="hope-admin-section">';
        echo '<h2>Current Seat Blocks</h2>';
        echo '<div id="hope-current-blocks">Select an event to view current blocks</div>';
        echo '</div>';
        
        // Seat selection and blocking controls
        echo '<div class="hope-admin-section">';
        echo '<h2>Block New Seats</h2>';
        echo '<div id="hope-seat-map-container">Select an event to load seat map</div>';
        
        echo '<div id="hope-block-controls" style="display: none;">';
        echo '<table class="form-table">';
        
        // Block type selection
        echo '<tr>';
        echo '<th scope="row"><label for="hope-block-type">Block Type</label></th>';
        echo '<td>';
        echo '<select id="hope-block-type" style="width: 200px;">';
        $block_types = HOPE_Seat_Blocking_Handler::get_block_types();
        foreach ($block_types as $type => $info) {
            echo '<option value="' . $type . '">' . esc_html($info['label']) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">Select the type of seat block to create.</p>';
        echo '</td>';
        echo '</tr>';
        
        // Block reason
        echo '<tr>';
        echo '<th scope="row"><label for="hope-block-reason">Reason</label></th>';
        echo '<td>';
        echo '<textarea id="hope-block-reason" rows="3" style="width: 100%; max-width: 500px;" placeholder="Enter reason for blocking these seats..."></textarea>';
        echo '</td>';
        echo '</tr>';
        
        // Time range (optional)
        echo '<tr>';
        echo '<th scope="row">Block Duration</th>';
        echo '<td>';
        echo '<label><input type="radio" name="block-duration" value="indefinite" checked> Indefinite (until manually removed)</label><br>';
        echo '<label><input type="radio" name="block-duration" value="custom"> Custom time range:</label>';
        echo '<div id="hope-custom-duration" style="margin-top: 10px; display: none;">';
        echo 'From: <input type="datetime-local" id="hope-valid-from" style="margin-right: 15px;">';
        echo 'Until: <input type="datetime-local" id="hope-valid-until">';
        echo '</div>';
        echo '</td>';
        echo '</tr>';
        
        echo '</table>';
        
        // Selected seats display
        echo '<div id="hope-selected-seats-display" style="display: none; margin: 20px 0; padding: 15px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px;">';
        echo '<h4>Selected Seats for Blocking</h4>';
        echo '<p><strong>Seats:</strong> <span id="hope-selected-seats-list">None selected</span></p>';
        echo '<p><strong>Count:</strong> <span id="hope-selected-seats-count">0</span></p>';
        echo '</div>';
        
        // Action buttons
        echo '<p>';
        echo '<button id="hope-create-block-btn" class="button-primary" disabled>Create Seat Block</button>';
        echo '<button id="hope-clear-selection-btn" class="button" style="margin-left: 10px;">Clear Selection</button>';
        echo '</p>';
        
        echo '</div>'; // End block controls
        echo '</div>'; // End admin section
        
        echo '</div>'; // End blocking interface
        
        // Instructions
        echo '<div class="hope-admin-section">';
        echo '<h2>How to Use Seat Blocking</h2>';
        echo '<ol>';
        echo '<li><strong>Select an event</strong> from the dropdown above</li>';
        echo '<li><strong>Review current blocks</strong> for that event</li>';
        echo '<li><strong>Click seats on the map</strong> to select them for blocking</li>';
        echo '<li><strong>Choose block type and reason</strong></li>';
        echo '<li><strong>Set duration</strong> (indefinite or custom time range)</li>';
        echo '<li><strong>Click "Create Seat Block"</strong> to apply the block</li>';
        echo '</ol>';
        
        echo '<div class="notice notice-info" style="margin-top: 20px;">';
        echo '<p><strong>Block Types:</strong></p>';
        echo '<ul>';
        foreach ($block_types as $type => $info) {
            echo '<li><strong>' . esc_html($info['label']) . ':</strong> ' . esc_html($info['description']) . '</li>';
        }
        echo '</ul>';
        echo '</div>';
        echo '</div>';
        
        // Add nonce for security
        wp_nonce_field('hope_seat_block_admin_action', 'hope_seat_block_admin_nonce');
        
        echo '</div>'; // End wrap
        
        // Add JavaScript for interactivity
        $this->render_admin_javascript();
    }
    
    /**
     * Get all products with theater seating enabled
     * @return array Array of WooCommerce product objects
     */
    private function get_theater_products() {
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_hope_seating_enabled',
                    'value' => 'yes',
                    'compare' => '='
                )
            )
        );
        
        $products = array();
        $posts = get_posts($args);
        
        foreach ($posts as $post) {
            $product = wc_get_product($post->ID);
            if ($product) {
                $products[] = $product;
            }
        }
        
        return $products;
    }
    
    /**
     * Render admin styles
     */
    private function render_admin_styles() {
        echo '<style>
            .hope-admin-section { background: white; margin: 20px 0; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
            .hope-admin-section h2 { margin-top: 0; }
            .hope-seat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(60px, 1fr)); gap: 6px; margin: 15px 0; max-width: 800px; }
            .hope-seat-item { border: 2px solid #ddd; border-radius: 4px; padding: 6px; text-align: center; cursor: pointer; transition: all 0.2s; font-size: 12px; }
            .hope-seat-item.available { background: #f0f8ff; border-color: #0073aa; }
            .hope-seat-item.available:hover { background: #e6f3ff; transform: translateY(-1px); }
            .hope-seat-item.selected { background: #ff6b6b; border-color: #ff5252; color: white; }
            .hope-seat-item.blocked { background: #6c757d; border-color: #495057; color: white; cursor: not-allowed; }
            .hope-seat-item.booked { background: #28a745; border-color: #1e7e34; color: white; cursor: not-allowed; }
            .hope-block-item { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; padding: 15px; margin: 10px 0; }
            .hope-block-type { display: inline-block; padding: 4px 8px; border-radius: 3px; color: white; font-size: 11px; font-weight: bold; }
            .hope-remove-block-btn { background: #dc3545; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer; }
            .hope-remove-block-btn:hover { background: #c82333; }
        </style>';
    }
    
    /**
     * Render JavaScript for admin interface
     */
    private function render_admin_javascript() {
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var selectedSeats = [];
            var currentEventId = null;
            var eventSeats = {};
            
            // Handle event selection
            $('#hope-event-selector').on('change', function() {
                currentEventId = $(this).val();
                if (currentEventId) {
                    loadEventData(currentEventId);
                    $('#hope-blocking-interface').show();
                } else {
                    $('#hope-blocking-interface').hide();
                }
            });
            
            // Handle block duration selection
            $('input[name="block-duration"]').on('change', function() {
                if ($(this).val() === 'custom') {
                    $('#hope-custom-duration').show();
                } else {
                    $('#hope-custom-duration').hide();
                }
            });
            
            // Load event data (current blocks and available seats)
            function loadEventData(eventId) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'hope_admin_get_event_seats',
                        event_id: eventId,
                        nonce: $('#hope_seat_block_admin_nonce').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            eventSeats = response.data;
                            renderCurrentBlocks(eventSeats.blocks);
                            renderSeatMap(eventSeats.seats, eventSeats.blocked_seats);
                        } else {
                            alert('Failed to load event data: ' + response.data.error);
                        }
                    },
                    error: function() {
                        alert('Network error loading event data');
                    }
                });
            }
            
            // Render current blocks
            function renderCurrentBlocks(blocks) {
                var html = '';
                if (blocks.length === 0) {
                    html = '<p>No active seat blocks for this event.</p>';
                } else {
                    blocks.forEach(function(block) {
                        var blockTypes = <?php echo json_encode(HOPE_Seat_Blocking_Handler::get_block_types()); ?>;
                        var typeInfo = blockTypes[block.block_type] || {label: block.block_type, color: '#6c757d'};
                        
                        html += '<div class="hope-block-item">';
                        html += '<div style="display: flex; justify-content: between; align-items: center;">';
                        html += '<div>';
                        html += '<span class="hope-block-type" style="background: ' + typeInfo.color + '">' + typeInfo.label + '</span>';
                        html += '<strong style="margin-left: 10px;">' + block.seat_ids.length + ' seats:</strong> ' + block.seat_ids.join(', ');
                        html += '</div>';
                        html += '<button class="hope-remove-block-btn" data-block-id="' + block.id + '">Remove Block</button>';
                        html += '</div>';
                        if (block.block_reason) {
                            html += '<p><strong>Reason:</strong> ' + block.block_reason + '</p>';
                        }
                        html += '<p><small>Created: ' + block.created_at + ' by User ID ' + block.blocked_by + '</small></p>';
                        html += '</div>';
                    });
                }
                $('#hope-current-blocks').html(html);
            }
            
            // Render seat map (simplified grid view)
            function renderSeatMap(allSeats, blockedSeats) {
                var html = '<div class="hope-seat-grid">';
                
                // For now, create a simple grid of seats (you can enhance this with actual venue layout)
                allSeats.forEach(function(seat) {
                    var classes = ['hope-seat-item'];
                    var clickable = true;
                    
                    if (blockedSeats.includes(seat.seat_id)) {
                        classes.push('blocked');
                        clickable = false;
                    } else if (seat.status === 'confirmed') {
                        classes.push('booked');
                        clickable = false;
                    } else {
                        classes.push('available');
                    }
                    
                    html += '<div class="' + classes.join(' ') + '" data-seat-id="' + seat.seat_id + '">';
                    html += seat.seat_id;
                    html += '</div>';
                });
                
                html += '</div>';
                $('#hope-seat-map-container').html(html);
                $('#hope-block-controls').show();
                
                // Clear selection when loading new event
                selectedSeats = [];
                updateSelectionDisplay();
            }
            
            // Handle seat selection
            $(document).on('click', '.hope-seat-item.available', function() {
                var seatId = $(this).data('seat-id');
                
                if ($(this).hasClass('selected')) {
                    // Deselect seat
                    $(this).removeClass('selected');
                    selectedSeats = selectedSeats.filter(function(seat) { return seat !== seatId; });
                } else {
                    // Select seat
                    $(this).addClass('selected');
                    selectedSeats.push(seatId);
                }
                
                updateSelectionDisplay();
            });
            
            // Update selection display
            function updateSelectionDisplay() {
                if (selectedSeats.length === 0) {
                    $('#hope-selected-seats-display').hide();
                    $('#hope-create-block-btn').prop('disabled', true);
                } else {
                    $('#hope-selected-seats-display').show();
                    $('#hope-selected-seats-list').text(selectedSeats.join(', '));
                    $('#hope-selected-seats-count').text(selectedSeats.length);
                    $('#hope-create-block-btn').prop('disabled', false);
                }
            }
            
            // Handle clear selection
            $('#hope-clear-selection-btn').on('click', function() {
                $('.hope-seat-item.selected').removeClass('selected');
                selectedSeats = [];
                updateSelectionDisplay();
            });
            
            // Handle create block
            $('#hope-create-block-btn').on('click', function() {
                if (selectedSeats.length === 0) {
                    alert('Please select at least one seat to block.');
                    return;
                }
                
                var blockType = $('#hope-block-type').val();
                var reason = $('#hope-block-reason').val();
                var duration = $('input[name="block-duration"]:checked').val();
                var validFrom = duration === 'custom' ? $('#hope-valid-from').val() : null;
                var validUntil = duration === 'custom' ? $('#hope-valid-until').val() : null;
                
                if (!confirm('Create ' + blockType + ' block for ' + selectedSeats.length + ' seat(s)?\\n\\nSeats: ' + selectedSeats.join(', '))) {
                    return;
                }
                
                var button = $(this);
                var originalText = button.text();
                button.prop('disabled', true).text('Creating Block...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'hope_admin_create_seat_block',
                        event_id: currentEventId,
                        seat_ids: selectedSeats,
                        block_type: blockType,
                        reason: reason,
                        valid_from: validFrom,
                        valid_until: validUntil,
                        nonce: $('#hope_seat_block_admin_nonce').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Seat block created successfully!');
                            
                            // Reload event data to refresh the interface
                            loadEventData(currentEventId);
                            
                            // Clear form
                            $('#hope-block-reason').val('');
                            selectedSeats = [];
                            updateSelectionDisplay();
                            
                        } else {
                            alert('Failed to create seat block: ' + response.data.error);
                        }
                    },
                    error: function() {
                        alert('Network error creating seat block');
                    },
                    complete: function() {
                        button.prop('disabled', false).text(originalText);
                    }
                });
            });
            
            // Handle remove block
            $(document).on('click', '.hope-remove-block-btn', function() {
                var blockId = $(this).data('block-id');
                
                if (!confirm('Are you sure you want to remove this seat block?')) {
                    return;
                }
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'hope_admin_remove_seat_block',
                        block_id: blockId,
                        nonce: $('#hope_seat_block_admin_nonce').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Seat block removed successfully!');
                            loadEventData(currentEventId);
                        } else {
                            alert('Failed to remove seat block: ' + response.data.error);
                        }
                    },
                    error: function() {
                        alert('Network error removing seat block');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * AJAX handler for creating seat blocks
     */
    public function ajax_create_seat_block() {
        // Security checks
        if (!current_user_can('manage_woocommerce') || !wp_verify_nonce($_POST['nonce'], 'hope_seat_block_admin_action')) {
            wp_send_json_error(array('error' => 'Access denied'));
        }
        
        $event_id = intval($_POST['event_id']);
        $seat_ids = array_map('sanitize_text_field', $_POST['seat_ids']);
        $block_type = sanitize_text_field($_POST['block_type']);
        $reason = sanitize_textarea_field($_POST['reason']);
        $valid_from = !empty($_POST['valid_from']) ? sanitize_text_field($_POST['valid_from']) : null;
        $valid_until = !empty($_POST['valid_until']) ? sanitize_text_field($_POST['valid_until']) : null;
        
        if (!class_exists('HOPE_Seat_Blocking_Handler')) {
            wp_send_json_error(array('error' => 'Seat blocking functionality not available'));
        }
        
        $handler = new HOPE_Seat_Blocking_Handler();
        $result = $handler->create_seat_block($event_id, $seat_ids, $block_type, $reason, $valid_from, $valid_until);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX handler for removing seat blocks
     */
    public function ajax_remove_seat_block() {
        // Security checks
        if (!current_user_can('manage_woocommerce') || !wp_verify_nonce($_POST['nonce'], 'hope_seat_block_admin_action')) {
            wp_send_json_error(array('error' => 'Access denied'));
        }
        
        $block_id = intval($_POST['block_id']);
        
        if (!class_exists('HOPE_Seat_Blocking_Handler')) {
            wp_send_json_error(array('error' => 'Seat blocking functionality not available'));
        }
        
        $handler = new HOPE_Seat_Blocking_Handler();
        $result = $handler->remove_seat_block($block_id);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX handler for getting event seat data
     */
    public function ajax_get_event_seats() {
        // Security checks
        if (!current_user_can('manage_woocommerce') || !wp_verify_nonce($_POST['nonce'], 'hope_seat_block_admin_action')) {
            wp_send_json_error(array('error' => 'Access denied'));
        }
        
        $event_id = intval($_POST['event_id']);
        
        // Get all seats for this event (from venue/seat map)
        $all_seats = $this->get_event_seats($event_id);
        
        // Get active seat blocks
        $blocks = HOPE_Database_Selective_Refunds::get_active_seat_blocks($event_id);
        $blocked_seats = HOPE_Database_Selective_Refunds::get_blocked_seat_ids($event_id);
        
        wp_send_json_success(array(
            'seats' => $all_seats,
            'blocks' => $blocks,
            'blocked_seats' => $blocked_seats
        ));
    }
    
    /**
     * Get seats for an event
     * @param int $event_id Event/Product ID
     * @return array Array of seat data
     */
    private function get_event_seats($event_id) {
        global $wpdb;
        
        // Try to get seats from various sources
        // First, try the seat maps table
        $seat_maps_table = $wpdb->prefix . 'hope_seating_seat_maps';
        $seats = $wpdb->get_results($wpdb->prepare(
            "SELECT seat_id, section, row_number, seat_number, pricing_tier, x_position, y_position
            FROM {$seat_maps_table}
            WHERE venue_id = 1
            ORDER BY seat_id",
            $event_id
        ), ARRAY_A);
        
        // If no seats found, create a simple list of seats for demo
        if (empty($seats)) {
            $seats = array();
            for ($i = 1; $i <= 50; $i++) {
                $seats[] = array(
                    'seat_id' => 'A-' . $i,
                    'section' => 'A',
                    'row_number' => ceil($i / 10),
                    'seat_number' => $i,
                    'pricing_tier' => 'P1',
                    'status' => 'available'
                );
            }
        }
        
        // Add booking status to each seat
        $bookings_table = $wpdb->prefix . 'hope_seating_bookings';
        foreach ($seats as &$seat) {
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT status FROM {$bookings_table}
                WHERE product_id = %d AND seat_id = %s
                AND status IN ('confirmed', 'pending')
                AND refund_id IS NULL",
                $event_id, $seat['seat_id']
            ));
            
            $seat['status'] = $booking ? $booking->status : 'available';
        }
        
        return $seats;
    }
    
    /**
     * Enqueue admin assets
     * @param string $hook Current admin page hook
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our admin page
        if ($hook !== 'woocommerce_page_hope-seat-blocking') {
            return;
        }
        
        // Styles and scripts are included inline for immediate rendering
    }
}