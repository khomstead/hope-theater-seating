<?php
/**
 * HOPE Theater Seating - Unified Admin Interface
 * Adds WooCommerce order meta box for selective seat refunds AND seat blocking
 * 
 * @package HOPE_Theater_Seating
 * @version 2.4.7
 */

if (!defined('ABSPATH')) {
    exit;
}

class HOPE_Admin_Selective_Refunds {
    
    /**
     * Initialize admin interface
     */
    public function __construct() {
        // Only initialize if selective refunds are available
        if (!$this->is_selective_refunds_available()) {
            return;
        }
        
        // Add WooCommerce order meta box
        add_action('add_meta_boxes', array($this, 'add_order_meta_box'));
        
        // Handle AJAX requests for selective refunds
        add_action('wp_ajax_hope_process_admin_selective_refund', array($this, 'ajax_process_selective_refund'));
        
        // Handle AJAX requests for seat blocking
        add_action('wp_ajax_hope_process_admin_seat_block', array($this, 'ajax_process_seat_block'));
        
        // Enqueue admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // Add admin notices for refund results
        add_action('admin_notices', array($this, 'show_refund_notices'));
        
        error_log('HOPE: Admin selective refunds interface initialized');
    }
    
    /**
     * Check if selective refunds functionality is available
     * @return bool
     */
    private function is_selective_refunds_available() {
        return class_exists('HOPE_Selective_Refund_Handler') && 
               HOPE_Selective_Refund_Handler::is_available();
    }
    
    /**
     * Add meta box to WooCommerce order edit screen
     */
    public function add_order_meta_box() {
        error_log("HOPE DEBUG: add_order_meta_box called");
        $screen = get_current_screen();
        error_log("HOPE DEBUG: Current screen: " . ($screen ? $screen->id : 'unknown'));
        
        // Only add to WooCommerce order edit screens
        if (!$screen || !in_array($screen->id, array('shop_order', 'woocommerce_page_wc-orders'))) {
            return;
        }
        
        // Get order ID from different sources
        $order_id = $this->get_current_order_id();
        if (!$order_id) {
            return;
        }
        
        // Check if this order has theater seats
        if (!$this->order_has_theater_seats($order_id)) {
            return;
        }
        
        add_meta_box(
            'hope-selective-refunds',
            '🎭 Theater Seat Management',
            array($this, 'render_meta_box'),
            array('shop_order', 'woocommerce_page_wc-orders'),
            'normal',
            'high'
        );
    }
    
    /**
     * Get current order ID from various sources
     * @return int|null Order ID or null if not found
     */
    private function get_current_order_id() {
        global $post, $theorder;
        
        // Try different methods to get order ID
        if (isset($_GET['id']) && is_numeric($_GET['id'])) {
            return intval($_GET['id']);
        }
        
        if (isset($_GET['post']) && is_numeric($_GET['post'])) {
            return intval($_GET['post']);
        }
        
        if ($post && $post->post_type === 'shop_order') {
            return $post->ID;
        }
        
        if ($theorder && is_object($theorder)) {
            return $theorder->get_id();
        }
        
        return null;
    }
    
    /**
     * Check if order contains theater seating products
     * @param int $order_id Order ID
     * @return bool
     */
    private function order_has_theater_seats($order_id) {
        if (!class_exists('HOPE_Database_Selective_Refunds')) {
            return false;
        }
        
        $refundable_seats = HOPE_Database_Selective_Refunds::get_refundable_seats($order_id);
        return !empty($refundable_seats);
    }
    
    /**
     * Render the selective refunds meta box
     * @param WP_Post|WC_Order $post_or_order Post object or WC Order
     */
    public function render_meta_box($post_or_order) {
        // Get order object
        if (is_a($post_or_order, 'WC_Order')) {
            $order = $post_or_order;
            $order_id = $order->get_id();
        } else {
            $order_id = $post_or_order->ID;
            $order = wc_get_order($order_id);
        }
        
        if (!$order) {
            echo '<p>Unable to load order data.</p>';
            return;
        }
        
        // Get refundable seats
        $refundable_seats = HOPE_Database_Selective_Refunds::get_refundable_seats($order_id);
        $refunded_seats = $this->get_refunded_seats($order_id);
        
        // Calculate totals
        $total_seats = count($refundable_seats) + count($refunded_seats);
        $remaining_seats = count($refundable_seats);
        $order_total = $order->get_total();
        
        // Include styles inline for immediate rendering
        echo '<style>
            .hope-seat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); gap: 8px; margin: 15px 0; }
            .hope-seat-item { border: 2px solid #ddd; border-radius: 4px; padding: 8px; text-align: center; cursor: pointer; transition: all 0.2s; }
            .hope-seat-item.available { background: #f0f8ff; border-color: #0073aa; }
            .hope-seat-item.available:hover { background: #e6f3ff; transform: translateY(-1px); }
            .hope-seat-item.selected { background: #ff6b6b; border-color: #ff5252; color: white; }
            .hope-seat-item.refunded { background: #f5f5f5; border-color: #999; color: #666; cursor: not-allowed; }
            .hope-refund-summary { background: #f9f9f9; border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 4px; }
            .hope-refund-controls { margin: 15px 0; }
            .hope-refund-btn { background: #ff6b6b; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-size: 14px; }
            .hope-refund-btn:hover { background: #ff5252; }
            .hope-refund-btn:disabled { background: #ccc; cursor: not-allowed; }
            .hope-section { margin: 20px 0; }
            .hope-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin: 15px 0; }
            .hope-stat { text-align: center; padding: 10px; background: #f8f9fa; border-radius: 4px; }
            .hope-stat-number { font-size: 24px; font-weight: bold; color: #0073aa; }
            .hope-stat-label { font-size: 12px; color: #666; text-transform: uppercase; }
        </style>';
        
        // Add nonce for security
        wp_nonce_field('hope_selective_refund_action', 'hope_selective_refund_nonce');
        
        echo '<div id="hope-selective-refunds-container">';
        
        // Order statistics
        echo '<div class="hope-stats">';
        echo '<div class="hope-stat"><div class="hope-stat-number">' . $total_seats . '</div><div class="hope-stat-label">Total Seats</div></div>';
        echo '<div class="hope-stat"><div class="hope-stat-number">' . $remaining_seats . '</div><div class="hope-stat-label">Available</div></div>';
        echo '<div class="hope-stat"><div class="hope-stat-number">$' . number_format($order_total, 2) . '</div><div class="hope-stat-label">Order Total</div></div>';
        echo '</div>';
        
        if (empty($refundable_seats)) {
            echo '<div class="notice notice-info"><p><strong>No refundable seats found.</strong> All seats may have already been refunded.</p></div>';
            echo '</div>';
            return;
        }
        
        // Seat selection interface
        echo '<div class="hope-section">';
        echo '<h4>🎟️ Select Seats to Refund</h4>';
        echo '<p>Click on seats below to select them for refund. Selected seats will be highlighted in red.</p>';
        
        echo '<div class="hope-seat-grid">';
        
        // Show available seats (clickable)
        foreach ($refundable_seats as $seat) {
            echo '<div class="hope-seat-item available" data-seat-id="' . esc_attr($seat['seat_id']) . '" data-order-id="' . $order_id . '">';
            echo '<strong>' . esc_html($seat['seat_id']) . '</strong>';
            echo '</div>';
        }
        
        // Show already refunded seats (non-clickable)
        foreach ($refunded_seats as $seat) {
            echo '<div class="hope-seat-item refunded" title="Already refunded on ' . esc_attr($seat['refund_date']) . '">';
            echo '<strong>' . esc_html($seat['seat_id']) . '</strong>';
            echo '<br><small>Refunded</small>';
            echo '</div>';
        }
        
        echo '</div>';
        echo '</div>';
        
        // Refund summary and controls
        echo '<div class="hope-refund-summary" id="hope-refund-summary" style="display: none;">';
        echo '<h4>📋 Refund Summary</h4>';
        echo '<p><strong>Selected Seats:</strong> <span id="hope-selected-seats">None</span></p>';
        echo '<p><strong>Estimated Refund Amount:</strong> $<span id="hope-refund-amount">0.00</span></p>';
        echo '<p><small>Amount calculated proportionally based on order total and seat count.</small></p>';
        echo '</div>';
        
        echo '<div class="hope-refund-controls">';
        echo '<label for="hope-refund-reason"><strong>Refund Reason:</strong></label><br>';
        echo '<textarea id="hope-refund-reason" rows="3" style="width: 100%; margin: 5px 0;" placeholder="Enter reason for refund (optional)"></textarea>';
        echo '<br>';
        echo '<button id="hope-process-refund-btn" class="hope-refund-btn" disabled>Process Selective Refund</button>';
        echo '<span id="hope-refund-status" style="margin-left: 15px;"></span>';
        echo '</div>';
        
        echo '<div class="hope-section">';
        echo '<h4>ℹ️ How It Works</h4>';
        echo '<ul>';
        echo '<li><strong>Select seats</strong> by clicking on them above</li>';
        echo '<li><strong>Refund amount</strong> is calculated proportionally based on order total</li>';
        echo '<li><strong>WooCommerce refund</strong> is created automatically</li>';
        echo '<li><strong>Customer notification</strong> is sent with refund details</li>';
        echo '<li><strong>Remaining seats</strong> stay active for the customer</li>';
        echo '</ul>';
        echo '</div>';
        
        echo '</div>'; // End container
        
        // Add JavaScript for interactivity
        $this->render_admin_javascript($order_id, $order_total, $total_seats);
    }
    
    /**
     * Get already refunded seats for an order
     * @param int $order_id Order ID
     * @return array
     */
    private function get_refunded_seats($order_id) {
        global $wpdb;
        
        $bookings_table = $wpdb->prefix . 'hope_seating_bookings';
        
        $refunded_seats = $wpdb->get_results($wpdb->prepare(
            "SELECT seat_id, refunded_amount, refund_reason, refund_date, status
            FROM {$bookings_table}
            WHERE order_id = %d 
            AND refund_id IS NOT NULL
            ORDER BY seat_id",
            $order_id
        ), ARRAY_A);
        
        return $refunded_seats ?: array();
    }
    
    /**
     * Render JavaScript for admin interface interactivity
     * @param int $order_id Order ID
     * @param float $order_total Order total
     * @param int $total_seats Total seat count
     */
    private function render_admin_javascript($order_id, $order_total, $total_seats) {
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var selectedSeats = [];
            var orderTotal = <?php echo $order_total; ?>;
            var totalSeats = <?php echo $total_seats; ?>;
            var orderId = <?php echo $order_id; ?>;
            
            // Handle seat selection
            $('.hope-seat-item.available').on('click', function() {
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
                
                updateRefundSummary();
            });
            
            // Update refund summary
            function updateRefundSummary() {
                if (selectedSeats.length === 0) {
                    $('#hope-refund-summary').hide();
                    $('#hope-process-refund-btn').prop('disabled', true);
                    return;
                }
                
                $('#hope-refund-summary').show();
                $('#hope-selected-seats').text(selectedSeats.join(', '));
                
                // Calculate proportional refund amount
                var refundAmount = (orderTotal / totalSeats) * selectedSeats.length;
                $('#hope-refund-amount').text(refundAmount.toFixed(2));
                
                $('#hope-process-refund-btn').prop('disabled', false);
            }
            
            // Handle refund processing
            $('#hope-process-refund-btn').on('click', function() {
                if (selectedSeats.length === 0) {
                    alert('Please select at least one seat to refund.');
                    return;
                }
                
                if (!confirm('Are you sure you want to process a refund for ' + selectedSeats.length + ' seat(s)?\n\nSeats: ' + selectedSeats.join(', ') + '\nAmount: $' + $('#hope-refund-amount').text())) {
                    return;
                }
                
                var button = $(this);
                var originalText = button.text();
                button.prop('disabled', true).text('Processing...');
                $('#hope-refund-status').html('<span style="color: #0073aa;">Processing refund...</span>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'hope_process_admin_selective_refund',
                        order_id: orderId,
                        seat_ids: selectedSeats,
                        reason: $('#hope-refund-reason').val(),
                        nonce: $('#hope_selective_refund_nonce').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#hope-refund-status').html('<span style="color: #46b450;">✅ Refund processed successfully!</span>');
                            
                            // Mark refunded seats as non-selectable
                            selectedSeats.forEach(function(seatId) {
                                $('.hope-seat-item[data-seat-id="' + seatId + '"]')
                                    .removeClass('available selected')
                                    .addClass('refunded')
                                    .html('<strong>' + seatId + '</strong><br><small>Refunded</small>')
                                    .off('click');
                            });
                            
                            // Reset selection
                            selectedSeats = [];
                            updateRefundSummary();
                            
                            // Show success message
                            alert('Refund processed successfully!\n\n' + response.data.message);
                            
                        } else {
                            $('#hope-refund-status').html('<span style="color: #dc3232;">❌ Error: ' + response.data.error + '</span>');
                            alert('Refund failed: ' + response.data.error);
                        }
                    },
                    error: function() {
                        $('#hope-refund-status').html('<span style="color: #dc3232;">❌ Network error occurred</span>');
                        alert('Network error. Please try again.');
                    },
                    complete: function() {
                        button.prop('disabled', false).text(originalText);
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Handle AJAX request for processing selective refunds
     */
    public function ajax_process_selective_refund() {
        // Security checks
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('error' => 'Access denied'));
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'hope_selective_refund_action')) {
            wp_send_json_error(array('error' => 'Security check failed'));
        }
        
        // Get and validate input
        $order_id = intval($_POST['order_id']);
        $seat_ids = array_map('sanitize_text_field', $_POST['seat_ids']);
        $reason = sanitize_textarea_field($_POST['reason']);
        
        if (!$order_id || empty($seat_ids)) {
            wp_send_json_error(array('error' => 'Invalid parameters'));
        }
        
        // Process selective refund
        if (!class_exists('HOPE_Selective_Refund_Handler')) {
            wp_send_json_error(array('error' => 'Selective refund functionality not available'));
        }
        
        $handler = new HOPE_Selective_Refund_Handler();
        $result = $handler->process_selective_refund($order_id, $seat_ids, $reason, true);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * Enqueue admin scripts and styles
     * @param string $hook Current admin page hook
     */
    public function enqueue_admin_assets($hook) {
        // Only load on order edit pages
        if (!in_array($hook, array('post.php', 'post-new.php', 'woocommerce_page_wc-orders'))) {
            return;
        }
        
        // Check if this is a shop_order
        global $post;
        if (!$post || $post->post_type !== 'shop_order') {
            return;
        }
        
        // Styles are included inline in the meta box for immediate rendering
        // Additional JavaScript dependencies handled by WordPress/WooCommerce
    }
    
    /**
     * Show admin notices for refund results
     */
    public function show_refund_notices() {
        // This could be enhanced to show persistent notices after redirects
        // For now, AJAX handles immediate feedback
    }
}