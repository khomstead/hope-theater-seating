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
        error_log('HOPE DEBUG: Admin selective refunds constructor called');

        // Only initialize if selective refunds are available
        $is_available = $this->is_selective_refunds_available();
        error_log('HOPE DEBUG: Selective refunds available: ' . ($is_available ? 'YES' : 'NO'));

        if (!$is_available) {
            error_log('HOPE DEBUG: Selective refunds not available, exiting constructor');
            return;
        }

        error_log('HOPE DEBUG: Registering add_meta_boxes hook');
        // Add WooCommerce order meta box
        add_action('add_meta_boxes', array($this, 'add_order_meta_box'));

        // Process reassignment forms early (before page render)
        add_action('admin_init', array($this, 'process_reassignment_form'));
        
        // Handle AJAX requests for selective refunds
        add_action('wp_ajax_hope_process_admin_selective_refund', array($this, 'ajax_process_selective_refund'));
        
        // Handle AJAX requests for seat blocking
        add_action('wp_ajax_hope_process_admin_seat_block', array($this, 'ajax_process_seat_block'));

        // Handle AJAX requests for seat reassignment
        add_action('wp_ajax_hope_process_seat_reassignment', array($this, 'ajax_process_seat_reassignment'));
        add_action('wp_ajax_hope_get_order_product_id', array($this, 'ajax_get_order_product_id'));
        add_action('wp_ajax_hope_get_venue_layout', array($this, 'ajax_get_venue_layout'));
        add_action('wp_ajax_hope_get_event_venue', array($this, 'ajax_get_event_venue'));
        add_action('wp_ajax_hope_get_available_seats', array($this, 'ajax_get_available_seats'));

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
            error_log("HOPE DEBUG: Not a WooCommerce order screen, exiting");
            return;
        }

        // Get order ID from different sources
        $order_id = $this->get_current_order_id();
        error_log("HOPE DEBUG: Order ID found: " . ($order_id ? $order_id : 'NULL'));
        if (!$order_id) {
            error_log("HOPE DEBUG: No order ID, exiting");
            return;
        }

        // Check if this order has theater seats
        $has_seats = $this->order_has_theater_seats($order_id);
        error_log("HOPE DEBUG: Order {$order_id} has theater seats: " . ($has_seats ? 'YES' : 'NO'));
        if (!$has_seats) {
            error_log("HOPE DEBUG: No theater seats, exiting");
            return;
        }

        error_log("HOPE DEBUG: Adding meta box for order {$order_id}");
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
            error_log("HOPE DEBUG: HOPE_Database_Selective_Refunds class not found");
            return false;
        }

        $refundable_seats = HOPE_Database_Selective_Refunds::get_refundable_seats($order_id);
        error_log("HOPE DEBUG: Refundable seats for order {$order_id}: " . print_r($refundable_seats, true));

        // Also check order metadata as a fallback
        $order = wc_get_order($order_id);
        if ($order) {
            $items = $order->get_items();
            foreach ($items as $item) {
                $seat_data = wc_get_order_item_meta($item->get_id(), '_fooevents_seats');
                error_log("HOPE DEBUG: Order item {$item->get_id()} has seat data: " . ($seat_data ? $seat_data : 'none'));
                if ($seat_data) {
                    error_log("HOPE DEBUG: Found seats in order metadata, showing meta box");
                    return true;
                }
            }
        }

        return !empty($refundable_seats);
    }
    
    /**
     * Process form-based seat reassignment
     */
    public function process_reassignment_form() {
        // Check if this is a reassignment submission
        if (!isset($_POST['hope_reassign_action'])) {
            return;
        }

        error_log('HOPE: Reassignment form submitted');

        $order_id = intval($_POST['hope_reassign_order_id']);
        error_log('HOPE: Order ID: ' . $order_id);

        // Verify nonce
        if (!wp_verify_nonce($_POST['hope_reassign_nonce'], 'hope_reassign_seat_' . $order_id)) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            });
            return;
        }

        // Verify permissions
        if (!current_user_can('edit_shop_orders')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>Insufficient permissions.</p></div>';
            });
            return;
        }

        $old_seat_id = sanitize_text_field($_POST['hope_reassign_old_seat']);
        $new_seat_id = sanitize_text_field($_POST['hope_reassign_new_seat']);
        $item_id = intval($_POST['hope_reassign_item_id']);

        error_log("HOPE: Reassigning seat {$old_seat_id} to {$new_seat_id} for item {$item_id}");

        // Process the reassignment
        global $wpdb;
        $bookings_table = $wpdb->prefix . 'hope_seating_bookings';

        try {
            error_log('HOPE: Starting reassignment process');
            // Check if this is a legacy order (no booking in database)
            $has_booking = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$bookings_table} WHERE order_id = %d AND seat_id = %s",
                $order_id, $old_seat_id
            ));

            if ($has_booking) {
                // Modern order - update booking table
                $wpdb->query('START TRANSACTION');

                // Check if new seat is available
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$bookings_table}
                    WHERE seat_id = %s AND status IN ('active', 'confirmed') AND order_id != %d",
                    $new_seat_id, $order_id
                ));

                if ($existing > 0) {
                    throw new Exception('Seat ' . $new_seat_id . ' is already booked');
                }

                // Update the booking
                $updated = $wpdb->update(
                    $bookings_table,
                    array('seat_id' => $new_seat_id),
                    array(
                        'order_id' => $order_id,
                        'seat_id' => $old_seat_id
                    ),
                    array('%s'),
                    array('%d', '%s')
                );

                if ($updated === false) {
                    throw new Exception('Failed to update booking');
                }

                $wpdb->query('COMMIT');
            }

            // Always update order item metadata (for both legacy and modern orders)
            error_log('HOPE: Updating order item metadata for item ' . $item_id);
            $this->update_order_item_metadata($item_id, $old_seat_id, $new_seat_id);

            // Resend tickets
            $order = wc_get_order($order_id);
            $this->resend_tickets($order);

            error_log("HOPE: Reassignment successful: {$old_seat_id} -> {$new_seat_id}");

            add_action('admin_notices', function() use ($old_seat_id, $new_seat_id) {
                echo '<div class="notice notice-success"><p>Seat successfully reassigned from ' . esc_html($old_seat_id) . ' to ' . esc_html($new_seat_id) . '!</p></div>';
            });

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('HOPE: Reassignment error: ' . $e->getMessage());
            add_action('admin_notices', function() use ($e) {
                echo '<div class="notice notice-error"><p>Reassignment failed: ' . esc_html($e->getMessage()) . '</p></div>';
            });
        }
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

        // Fallback: If no seats in database, try to get from order metadata
        if (empty($refundable_seats)) {
            $refundable_seats = $this->get_seats_from_order_metadata($order_id);
        }

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
        echo '<label style="display: block; margin: 10px 0; padding: 8px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px;">';
        echo '<input type="checkbox" id="hope-keep-seats-held" style="margin-right: 8px;">';
        echo '<strong>Keep seats held (Guest List / Comp)</strong><br>';
        echo '<small style="color: #666;">Check this to refund the customer but keep the seats reserved (for comps, guest list, etc.). Seats will not become available to the public.</small>';
        echo '</label>';
        echo '<br>';
        echo '<button id="hope-process-refund-btn" class="hope-refund-btn" disabled>Process Selective Refund</button>';
        echo '<span id="hope-refund-status" style="margin-left: 15px;"></span>';
        echo '</div>';
        
        // Seat Reassignment Section
        echo '<div class="hope-section" style="margin-top: 30px; border-top: 2px solid #e0e0e0; padding-top: 20px;">';
        echo '<h4>🔄 Reassign Seats</h4>';
        echo '<p>Click "Reassign" to change a customer\'s seat to a different available seat.</p>';

        echo '<div class="hope-reassignment-list" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; margin: 15px 0;">';

        foreach ($refundable_seats as $seat) {
            $item_id = isset($seat['order_item_id']) ? $seat['order_item_id'] : 0;
            echo '<div class="hope-reassignment-item" style="border: 1px solid #ddd; padding: 12px; border-radius: 4px; background: #f8f9fa;">';
            echo '<strong style="font-size: 16px; color: #0073aa;">' . esc_html($seat['seat_id']) . '</strong><br>';
            echo '<button class="button button-small hope-reassign-btn"
                    data-seat-id="' . esc_attr($seat['seat_id']) . '"
                    data-order-id="' . esc_attr($order_id) . '"
                    data-item-id="' . esc_attr($item_id) . '"
                    style="margin-top: 8px; width: 100%;">
                    ↔️ Reassign
                  </button>';
            echo '</div>';
        }

        echo '</div>';
        echo '</div>';

        echo '<div class="hope-section">';
        echo '<h4>ℹ️ How It Works</h4>';
        echo '<ul>';
        echo '<li><strong>Select seats</strong> by clicking on them above</li>';
        echo '<li><strong>Refund amount</strong> is calculated proportionally based on order total</li>';
        echo '<li><strong>WooCommerce refund</strong> is created automatically</li>';
        echo '<li><strong>Customer notification</strong> is sent with refund details</li>';
        echo '<li><strong>Remaining seats</strong> stay active for the customer</li>';
        echo '<li><strong>Reassign seats</strong> to move customers to different seats (tickets are automatically resent)</li>';
        echo '</ul>';
        echo '</div>';
        
        echo '</div>'; // End container

        // Add the admin seat selection modal for reassignment
        $this->render_admin_seat_modal();

        // Get product ID for reassignment (to pass to JavaScript)
        // CRITICAL: Find the product that has seating enabled, not just the first item
        // Orders may contain multiple products (e.g., event ticket + parking)
        $product_id = 0;
        $items = $order->get_items();
        if (!empty($items)) {
            // Loop through items to find the one with seating enabled
            foreach ($items as $item) {
                $item_product_id = $item->get_product_id();
                $seating_enabled = get_post_meta($item_product_id, '_hope_seating_enabled', true);
                $venue_id = get_post_meta($item_product_id, '_hope_seating_venue_id', true);

                if ($seating_enabled === 'yes' && $venue_id) {
                    $product_id = $item_product_id;
                    error_log('HOPE: Order ' . $order_id . ' - Found seating product ID: ' . $product_id);
                    break;
                }
            }

            // Fallback: if no seating-enabled product found, use first item (legacy behavior)
            if (!$product_id) {
                $first_item = reset($items);
                $product_id = $first_item->get_product_id();
                error_log('HOPE: Order ' . $order_id . ' - Using first item as fallback, product ID: ' . $product_id);
            }

            // Check if this product has a pricing map configured
            $pricing_map = get_post_meta($product_id, '_fooevents_pricing_map', true);
            error_log('HOPE: Product ' . $product_id . ' pricing map: ' . var_export($pricing_map, true));
        }

        // Add JavaScript for interactivity
        $this->render_admin_javascript($order_id, $order_total, $total_seats, $product_id);
    }
    
    /**
     * Render the admin seat selection modal (reuses blocking modal)
     */
    private function render_admin_seat_modal() {
        // Check if seat blocking class exists and has the modal
        if (class_exists('HOPE_Admin_Seat_Blocking')) {
            // Render a simplified version of the modal for reassignment
            ?>
            <div id="hope-admin-seat-modal" class="hope-modal" style="display: none;" aria-hidden="true" role="dialog">
                <div class="hope-modal-overlay"></div>
                <div class="hope-modal-content">
                    <div class="hope-modal-body">
                        <div class="hope-loading-indicator" style="text-align: center; padding: 40px;">
                            <div class="spinner is-active" style="float: none;"></div>
                            <p>Loading seat map...</p>
                        </div>
                        <div id="hope-admin-seat-map-container" style="display: none;">
                            <div class="theater-container">
                                <div class="header">
                                    <div class="header-content">
                                        <h3>Select New Seat</h3>
                                        <div class="floor-selector">
                                            <button class="floor-btn active" data-floor="orchestra">Orchestra</button>
                                            <button class="floor-btn" data-floor="balcony">Balcony</button>
                                        </div>
                                    </div>
                                </div>

                                <div class="seating-container">
                                    <div class="zoom-controls">
                                        <button class="zoom-btn" id="admin-zoom-out">−</button>
                                        <span class="zoom-label">150%</span>
                                        <button class="zoom-btn" id="admin-zoom-in">+</button>
                                    </div>

                                    <div class="seating-wrapper" id="admin-seating-wrapper">
                                        <svg id="admin-seat-map" viewBox="-100 0 1400 1200" preserveAspectRatio="xMidYMid meet">
                                            <!-- Seats will be generated dynamically -->
                                        </svg>
                                    </div>
                                </div>

                                <div class="selected-seats-panel" id="admin-selected-seats-panel" style="display: none;">
                                    <div class="seats-list" id="admin-selected-seats-list">
                                        <span class="empty-message">No seat selected</span>
                                    </div>
                                </div>
                            </div>

                            <div class="tooltip" id="admin-tooltip"></div>
                        </div>
                    </div>
                    <div class="hope-modal-footer">
                        <div class="hope-modal-info">
                            <button class="seats-toggle" id="admin-seats-toggle" title="Show selected seat">
                                <span class="seat-count-display">No seat selected</span>
                                <span class="toggle-icon">▲</span>
                            </button>
                        </div>
                        <div class="hope-modal-actions">
                            <button type="button" class="hope-cancel-btn button">Cancel</button>
                            <button type="button" class="hope-confirm-seats-btn button-primary" disabled>Confirm Reassignment</button>
                        </div>
                    </div>
                </div>
            </div>
            <script>
                function closeAdminSeatModal() {
                    jQuery('#hope-admin-seat-modal').removeClass('show').attr('aria-hidden', 'true');
                    if (window.adminSeatMap) {
                        window.adminSeatMap.destroy();
                        window.adminSeatMap = null;
                    }
                }
            </script>
            <style>
                #hope-admin-seat-modal { position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 999999; display: none; }
                #hope-admin-seat-modal.show { display: flex !important; align-items: center; justify-content: center; }
                #hope-admin-seat-modal .hope-modal-overlay { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); }
                #hope-admin-seat-modal .hope-modal-content { position: relative; background: white; width: 90%; max-width: 1200px; max-height: 90vh; display: flex; flex-direction: column; border-radius: 8px; }
                #hope-admin-seat-modal .hope-modal-body { flex: 1; overflow: auto; padding: 20px; min-height: 400px; }
                #hope-admin-seat-modal .hope-modal-footer { padding: 15px 20px; border-top: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; }
                #hope-admin-seat-modal #admin-seat-map { width: 100%; min-height: 500px; }
            </style>
            <?php
        }
    }

    /**
     * Get seats from order item metadata (fallback for legacy orders)
     * @param int $order_id Order ID
     * @return array
     */
    private function get_seats_from_order_metadata($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return array();
        }

        $seats = array();
        $items = $order->get_items();

        foreach ($items as $item_id => $item) {
            $seat_data = wc_get_order_item_meta($item_id, '_fooevents_seats');
            if ($seat_data) {
                // Parse seat data - could be single seat or multiple
                $seat_ids = is_array($seat_data) ? $seat_data : array($seat_data);

                foreach ($seat_ids as $seat_id) {
                    if (!empty($seat_id)) {
                        $seats[] = array(
                            'id' => null,
                            'seat_id' => $seat_id,
                            'product_id' => $item->get_product_id(),
                            'order_item_id' => $item_id,
                            'status' => 'active',
                            'created_at' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : null,
                            'refund_id' => null,
                            'refunded_amount' => null,
                            'refund_reason' => null,
                            'refund_date' => null
                        );
                    }
                }
            }
        }

        return $seats;
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
     * @param int $product_id Product ID for reassignment
     */
    private function render_admin_javascript($order_id, $order_total, $total_seats, $product_id = 0) {
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Ensure ajaxurl is defined (it should be in admin, but just in case)
            if (typeof ajaxurl === 'undefined') {
                var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
            }

            var selectedSeats = [];
            var orderTotal = <?php echo $order_total; ?>;
            var totalSeats = <?php echo $total_seats; ?>;
            var orderId = <?php echo $order_id; ?>;
            var productId = <?php echo $product_id; ?>;
            
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
                        keep_seats_held: $('#hope-keep-seats-held').is(':checked'),
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

            // Handle seat reassignment
            $('.hope-reassign-btn').on('click', function(e) {
                e.preventDefault(); // Prevent default button behavior
                console.log('Reassign button clicked');
                var button = $(this);
                var seatId = button.data('seat-id');
                var orderId = button.data('order-id');
                var itemId = button.data('item-id');

                console.log('Seat ID:', seatId, 'Order ID:', orderId, 'Item ID:', itemId);
                console.log('Product ID:', productId);

                // Use the product ID that was passed from PHP (avoids AJAX call)
                if (productId) {
                    openSeatMapForReassignment(seatId, orderId, itemId, productId);
                } else {
                    alert('Error: Could not determine product ID for this order');
                }

                return false; // Extra safety to prevent form submission
            });

            // Open seat map modal for reassignment - Visual modal approach
            function openSeatMapForReassignment(oldSeatId, orderId, itemId, eventId) {
                console.log('Opening visual seat map for reassignment:', {oldSeatId, orderId, itemId, eventId});

                const modal = $('#hope-admin-seat-modal');

                if (modal.length === 0) {
                    alert('Error: Seat selection modal not found. Please ensure the seat blocking feature is active.');
                    return;
                }

                // Show the modal
                modal.addClass('show').attr('aria-hidden', 'false');
                $('body').addClass('hope-modal-open');

                // Update modal header
                modal.find('.header-content h3').text('Select New Seat for ' + oldSeatId);

                // Show loading
                const loader = modal.find('.hope-loading-indicator');
                const content = modal.find('#hope-admin-seat-map-container');

                loader.show();
                content.hide();

                // Get venue ID for this event
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'hope_get_event_venue',
                        event_id: eventId,
                        order_id: orderId,
                        nonce: $('#hope_selective_refund_nonce').val()
                    },
                    success: function(venueResponse) {
                        if (!venueResponse.success) {
                            loader.hide();
                            content.show().html('<div style="padding: 40px; text-align: center;"><h3>⚠️ Venue Not Configured</h3><p>This event does not have a venue/pricing map configured.</p><button type="button" class="button" onclick="jQuery(\'#hope-admin-seat-modal\').removeClass(\'show\'); jQuery(\'body\').removeClass(\'hope-modal-open\');">Close</button></div>');
                            return;
                        }

                        const venueId = venueResponse.data.venue_id;

                        // Hide loading, show content
                        loader.hide();
                        const container = $('#hope-admin-seat-map-container');
                        container.show();

                        // Update the global hope_ajax with venue_id and product_id
                        if (typeof hope_ajax !== 'undefined') {
                            hope_ajax.venue_id = venueId;
                            hope_ajax.product_id = eventId;
                            hope_ajax.event_id = eventId;
                        }

                        // Initialize the seat map
                        if (typeof HOPESeatMap !== 'undefined') {
                            window.reassignmentSeatMap = new HOPESeatMap();
                            window.reassignmentSeatMap.containerId = 'admin-seat-map';
                            window.reassignmentSeatMap.wrapperId = 'admin-seating-wrapper';
                            window.reassignmentSeatMap.tooltipId = 'admin-tooltip';
                            window.reassignmentSeatMap.isAdminMode = true;
                            window.reassignmentSeatMap.maxSeats = 1;

                            // Set the AJAX config directly
                            window.reassignmentSeatMap.ajax = {
                                ajax_url: ajaxurl,
                                nonce: hope_ajax.nonce,
                                venue_id: venueId,
                                product_id: eventId,
                                event_id: eventId,
                                session_id: 'reassign_' + Date.now(),
                                admin_mode: true,
                                is_mobile: false
                            };

                            // Override to ensure only one seat can be selected at a time
                            const originalSelectSeat = window.reassignmentSeatMap.selectSeat.bind(window.reassignmentSeatMap);
                            window.reassignmentSeatMap.selectSeat = function(seatId) {
                                // If a seat is already selected, deselect it first
                                if (this.selectedSeats.size > 0) {
                                    const previousSeat = Array.from(this.selectedSeats)[0];
                                    this.deselectSeat(previousSeat);
                                }
                                // Now select the new seat
                                originalSelectSeat(seatId);
                            };

                            // Don't call init() - it waits for wrong modal ID
                            // Instead, load data and manually trigger initialization
                            window.reassignmentSeatMap.dataLoadingStatus = {
                                variationPricing: false,
                                realSeatData: false
                            };

                            // Load variation pricing
                            window.reassignmentSeatMap.loadVariationPricing().then(() => {
                                window.reassignmentSeatMap.dataLoadingStatus.variationPricing = true;
                                if (window.reassignmentSeatMap.dataLoadingStatus.realSeatData) {
                                    window.reassignmentSeatMap.initializeMap();
                                }
                            });

                            // Load real seat data
                            window.reassignmentSeatMap.loadRealSeatData().then(() => {
                                window.reassignmentSeatMap.dataLoadingStatus.realSeatData = true;
                                if (window.reassignmentSeatMap.dataLoadingStatus.variationPricing) {
                                    window.reassignmentSeatMap.initializeMap();
                                }
                            });

                            // Update confirm button
                            modal.find('.hope-confirm-seats-btn').off('click').on('click', function() {
                                const selectedSeats = window.reassignmentSeatMap.selectedSeats;
                                if (selectedSeats.size !== 1) {
                                    alert('Please select exactly one seat');
                                    return;
                                }

                                const newSeatId = Array.from(selectedSeats)[0];
                                if (!confirm(`Confirm reassignment from ${oldSeatId} to ${newSeatId}?`)) {
                                    return;
                                }

                                modal.removeClass('show').attr('aria-hidden', 'true');
                                $('body').removeClass('hope-modal-open');

                                performSeatReassignment(orderId, oldSeatId, newSeatId, itemId);
                            });

                            // Cancel button
                            modal.find('.hope-cancel-btn').off('click').on('click', function() {
                                modal.removeClass('show').attr('aria-hidden', 'true');
                                $('body').removeClass('hope-modal-open');
                            });
                        } else {
                            alert('Error: Seat map library not loaded');
                        }
                    },
                    error: function() {
                        loader.hide();
                        content.show().html('<div style="padding: 40px; text-align: center;"><h3>⚠️ Error</h3><p>Could not load venue information.</p><button type="button" class="button" onclick="jQuery(\'#hope-admin-seat-modal\').removeClass(\'show\'); jQuery(\'body\').removeClass(\'hope-modal-open\');">Close</button></div>');
                    }
                });
            }

            // Perform the actual seat reassignment via AJAX
            function performSeatReassignment(orderId, oldSeatId, newSeatId, itemId) {
                console.log('Performing seat reassignment:', {orderId, oldSeatId, newSeatId, itemId});

                var formData = new FormData();
                formData.append('action', 'hope_process_seat_reassignment');
                formData.append('order_id', orderId);
                formData.append('old_seat_id', oldSeatId);
                formData.append('new_seat_id', newSeatId);
                formData.append('item_id', itemId);
                formData.append('nonce', $('#hope_selective_refund_nonce').val());

                fetch(ajaxurl, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(function(response) {
                    console.log('Fetch response:', response);
                    return response.json();
                })
                .then(function(data) {
                    console.log('Response data:', data);
                    if (data.success) {
                        alert('✓ Seat reassigned successfully!\n\n' + oldSeatId + ' → ' + newSeatId);
                        location.reload();
                    } else {
                        alert('Reassignment failed: ' + (data.data ? data.data.message : 'Unknown error'));
                    }
                })
                .catch(function(error) {
                    console.error('Fetch error:', error);
                    alert('Network error: ' + error.message);
                });
            }
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
        $keep_seats_held = isset($_POST['keep_seats_held']) && $_POST['keep_seats_held'] === 'true';

        if (!$order_id || empty($seat_ids)) {
            wp_send_json_error(array('error' => 'Invalid parameters'));
        }

        // Process selective refund
        if (!class_exists('HOPE_Selective_Refund_Handler')) {
            wp_send_json_error(array('error' => 'Selective refund functionality not available'));
        }

        $handler = new HOPE_Selective_Refund_Handler();
        $result = $handler->process_selective_refund($order_id, $seat_ids, $reason, true, $keep_seats_held);
        
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
        // Only load on order edit pages (both old and HPOS)
        if (!in_array($hook, array('post.php', 'post-new.php', 'woocommerce_page_wc-orders'))) {
            return;
        }

        // For HPOS, the hook is 'woocommerce_page_wc-orders', so skip the post check
        if ($hook !== 'woocommerce_page_wc-orders') {
            // Check if this is a shop_order (for classic orders)
            global $post;
            if (!$post || $post->post_type !== 'shop_order') {
                return;
            }
        }

        // Enqueue seat map scripts for reassignment modal (same as seat blocking)
        $plugin_url = plugin_dir_url(dirname(__FILE__));

        // Enqueue the seat map script
        if (file_exists(dirname(__FILE__) . '/../assets/js/seat-map.js')) {
            wp_enqueue_script('hope-seat-map', $plugin_url . 'assets/js/seat-map.js', array('jquery'), HOPE_SEATING_VERSION, true);
        }

        // Enqueue frontend styles for modal appearance
        if (file_exists(dirname(__FILE__) . '/../assets/css/frontend.css')) {
            wp_enqueue_style('hope-frontend-style', $plugin_url . 'assets/css/frontend.css', array(), HOPE_SEATING_VERSION);
        }

        // Localize script with AJAX settings
        $current_user = wp_get_current_user();
        wp_localize_script('hope-seat-map', 'hope_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hope_seating_nonce'),
            'session_id' => 'admin_reassign_' . $current_user->ID . '_' . time(),
            'admin_mode' => true,
            'is_mobile' => false
        ));
    }
    
    /**
     * Show admin notices for refund results
     */
    public function show_refund_notices() {
        // This could be enhanced to show persistent notices after redirects
        // For now, AJAX handles immediate feedback
    }

    /**
     * Handle AJAX request for seat reassignment
     */
    public function ajax_process_seat_reassignment() {
        error_log('HOPE: ajax_process_seat_reassignment handler called');
        error_log('HOPE: POST data: ' . print_r($_POST, true));

        // Add CORS headers for Local/staging environments
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST');
        header('Access-Control-Allow-Headers: Content-Type');

        // Verify nonce (using same nonce as other functions on this page)
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'hope_selective_refund_action')) {
            error_log('HOPE: Nonce verification failed');
            wp_send_json_error(array('message' => 'Security check failed'));
        }

        // Verify user capabilities
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        $order_id = intval($_POST['order_id']);
        $old_seat_id = sanitize_text_field($_POST['old_seat_id']);
        $new_seat_id = sanitize_text_field($_POST['new_seat_id']);
        $order_item_id = intval($_POST['item_id']);

        error_log("HOPE Reassignment: Order {$order_id}, Item {$order_item_id}, {$old_seat_id} -> {$new_seat_id}");

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => 'Order not found'));
        }

        // Get the product/event ID for this order
        $items = $order->get_items();
        $product_id = 0;
        if (!empty($items)) {
            $first_item = reset($items);
            $product_id = $first_item->get_product_id();
        }

        global $wpdb;
        $bookings_table = $wpdb->prefix . 'hope_seating_bookings';

        // Start transaction
        $wpdb->query('START TRANSACTION');

        try {
            // Check if new seat is available for THIS event/product only
            $existing_booking = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$bookings_table}
                WHERE seat_id = %s
                AND event_id = %d
                AND order_id != %d
                AND status IN ('active', 'confirmed')",
                $new_seat_id,
                $product_id,
                $order_id
            ));

            error_log('HOPE: Checking if seat ' . $new_seat_id . ' is available for event ' . $product_id . '. Existing booking: ' . print_r($existing_booking, true));

            if ($existing_booking) {
                throw new Exception('Seat ' . $new_seat_id . ' is already booked by order #' . $existing_booking->order_id . ' for this event');
            }

            // Update the booking
            error_log('HOPE: About to update booking. Order: ' . $order_id . ', Old: ' . $old_seat_id . ', New: ' . $new_seat_id);

            $updated = $wpdb->update(
                $bookings_table,
                array('seat_id' => $new_seat_id),
                array(
                    'order_id' => $order_id,
                    'seat_id' => $old_seat_id
                ),
                array('%s'),
                array('%d', '%s')
            );

            error_log('HOPE: Update result: ' . var_export($updated, true) . ' (false=error, 0=no rows matched, >0=rows updated)');
            error_log('HOPE: wpdb->last_error: ' . $wpdb->last_error);
            error_log('HOPE: wpdb->last_query: ' . $wpdb->last_query);

            if ($updated === false) {
                throw new Exception('Failed to update booking: ' . $wpdb->last_error);
            }

            if ($updated === 0) {
                error_log('HOPE: WARNING - No rows were updated. Checking if booking exists...');
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$bookings_table} WHERE order_id = %d AND seat_id = %s",
                    $order_id,
                    $old_seat_id
                ));
                error_log('HOPE: Existing booking check: ' . print_r($existing, true));
            }

            // Update order item metadata
            error_log('HOPE: About to update order item metadata. Item ID: ' . $order_item_id);
            $this->update_order_item_metadata($order_item_id, $old_seat_id, $new_seat_id);
            error_log('HOPE: Finished updating order item metadata');

            // Commit transaction
            $wpdb->query('COMMIT');

            // Resend tickets
            $this->resend_tickets($order);

            wp_send_json_success(array(
                'message' => "Seat successfully reassigned from {$old_seat_id} to {$new_seat_id}",
                'old_seat' => $old_seat_id,
                'new_seat' => $new_seat_id
            ));

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('HOPE Reassignment Error: ' . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * Update order item metadata after seat reassignment
     */
    private function update_order_item_metadata($order_item_id, $old_seat_id, $new_seat_id) {
        error_log('HOPE: update_order_item_metadata called for item ' . $order_item_id);

        // Update _fooevents_seats
        $current_seats = wc_get_order_item_meta($order_item_id, '_fooevents_seats');
        error_log('HOPE: Current _fooevents_seats: ' . var_export($current_seats, true));

        if ($current_seats === $old_seat_id) {
            $result = wc_update_order_item_meta($order_item_id, '_fooevents_seats', $new_seat_id);
            error_log('HOPE: Updated _fooevents_seats from ' . $old_seat_id . ' to ' . $new_seat_id . ', result: ' . var_export($result, true));
        } else {
            error_log('HOPE: WARNING - Current seats mismatch. Expected: ' . $old_seat_id . ', Got: ' . var_export($current_seats, true));
        }

        // Update _hope_seat_summary
        $summary = wc_get_order_item_meta($order_item_id, '_hope_seat_summary');
        error_log('HOPE: Current _hope_seat_summary: ' . var_export($summary, true));
        if ($summary) {
            $new_summary = str_replace($old_seat_id, $new_seat_id, $summary);
            $result = wc_update_order_item_meta($order_item_id, '_hope_seat_summary', $new_summary);
            error_log('HOPE: Updated _hope_seat_summary. Old: ' . $summary . ', New: ' . $new_summary . ', result: ' . var_export($result, true));
        }

        // Update _fooevents_seat_row_name_0
        $row_name = wc_get_order_item_meta($order_item_id, '_fooevents_seat_row_name_0');
        error_log('HOPE: Current _fooevents_seat_row_name_0: ' . var_export($row_name, true));
        if ($row_name) {
            // Extract section and row from new seat ID (e.g., "A1-5" -> "Section A Row 1")
            if (preg_match('/^([A-Z])(\d+)-(\d+)$/', $new_seat_id, $matches)) {
                $section = $matches[1];
                $row = $matches[2];
                $new_row_name = "Section {$section} Row {$row}";
                $result = wc_update_order_item_meta($order_item_id, '_fooevents_seat_row_name_0', $new_row_name);
                error_log('HOPE: Updated _fooevents_seat_row_name_0 to ' . $new_row_name . ', result: ' . var_export($result, true));
            }
        }

        // Update _fooevents_seat_number_0
        if (preg_match('/-(\d+)$/', $new_seat_id, $matches)) {
            $seat_number = $matches[1];
            $result = wc_update_order_item_meta($order_item_id, '_fooevents_seat_number_0', $seat_number);
            error_log('HOPE: Updated _fooevents_seat_number_0 to ' . $seat_number . ', result: ' . var_export($result, true));
        }

        // CRITICAL: Update FooEvents ticket metadata (not just order item metadata)
        $this->update_fooevents_ticket_metadata($order_item_id, $old_seat_id, $new_seat_id);

        // Add note to order
        $order = wc_get_order(wc_get_order_id_by_order_item_id($order_item_id));
        if ($order) {
            $order->add_order_note(
                sprintf(
                    __('Seat reassigned: %s → %s (Admin: %s)', 'hope-theater-seating'),
                    $old_seat_id,
                    $new_seat_id,
                    wp_get_current_user()->display_name
                )
            );
            error_log('HOPE: Added order note to order ' . $order->get_id());

            // Trigger WordPress action that FooEvents listens to for ticket regeneration
            do_action('woocommerce_order_item_meta_updated', $order_item_id, $order);
            error_log('HOPE: Triggered woocommerce_order_item_meta_updated action for ticket regeneration');
        } else {
            error_log('HOPE: WARNING - Could not get order from item_id ' . $order_item_id);
        }
    }

    /**
     * Update FooEvents ticket post metadata when seat is reassigned
     * CRITICAL: Tickets have their own metadata separate from order item metadata
     *
     * @param int $order_item_id WooCommerce order item ID
     * @param string $old_seat_id Old seat ID (e.g., "E8-4")
     * @param string $new_seat_id New seat ID (e.g., "E8-1")
     */
    private function update_fooevents_ticket_metadata($order_item_id, $old_seat_id, $new_seat_id) {
        error_log('HOPE: update_fooevents_ticket_metadata called');

        // Get order ID from order item
        $order_id = wc_get_order_id_by_order_item_id($order_item_id);
        if (!$order_id) {
            error_log('HOPE: Could not get order ID from item ' . $order_item_id);
            return;
        }

        // Find FooEvents ticket(s) for this order
        // Note: We search by order_id because there's no direct order_item_id link
        $tickets = get_posts(array(
            'post_type' => 'event_magic_tickets',
            'post_status' => 'any',
            'meta_query' => array(
                array(
                    'key' => 'WooCommerceEventsOrderID',
                    'value' => $order_id
                )
            ),
            'posts_per_page' => -1
        ));

        if (empty($tickets)) {
            error_log('HOPE: No FooEvents tickets found for order ' . $order_id);
            return;
        }

        error_log('HOPE: Found ' . count($tickets) . ' ticket(s) for order ' . $order_id);

        // For single-ticket orders, update the ticket
        // For multi-ticket orders, find the right ticket by matching old seat data
        foreach ($tickets as $ticket) {
            $ticket_seat_number = get_post_meta($ticket->ID, 'fooevents_seat_number_0', true);

            // Extract seat number from old seat ID (e.g., "E8-4" -> "4")
            if (preg_match('/-(\d+)$/', $old_seat_id, $old_matches)) {
                $old_seat_number = $old_matches[1];

                // If this ticket matches the old seat, update it
                if ($ticket_seat_number == $old_seat_number || count($tickets) == 1) {
                    error_log('HOPE: Updating ticket ' . $ticket->ID . ' from seat ' . $old_seat_id . ' to ' . $new_seat_id);

                    // Extract new seat components
                    if (preg_match('/^([A-Z])(\d+)-(\d+)$/', $new_seat_id, $new_matches)) {
                        $section = $new_matches[1];
                        $row = $new_matches[2];
                        $new_seat_number = $new_matches[3];
                        $new_row_name = "Section {$section} Row {$row}";

                        // Update individual ticket meta fields
                        update_post_meta($ticket->ID, 'fooevents_seat_number_0', $new_seat_number);
                        update_post_meta($ticket->ID, 'fooevents_seat_row_name_0', $new_row_name);

                        // Update serialized seating fields array
                        $seating_fields = array(
                            'fooevents_seat_row_name_0' => $new_row_name,
                            'fooevents_seat_number_0' => $new_seat_number
                        );
                        update_post_meta($ticket->ID, 'WooCommerceEventsSeatingFields', $seating_fields);

                        error_log('HOPE: Successfully updated ticket ' . $ticket->ID . ' metadata');
                        error_log('HOPE: - Seat number: ' . $new_seat_number);
                        error_log('HOPE: - Row name: ' . $new_row_name);

                        // Add detailed order note about ticket update
                        $order = wc_get_order($order_id);
                        if ($order) {
                            $order->add_order_note(
                                sprintf(
                                    __('FooEvents ticket #%d updated: Seat %s → Row "%s", Seat #%s (Ticket metadata synchronized)', 'hope-theater-seating'),
                                    $ticket->ID,
                                    $new_seat_id,
                                    $new_row_name,
                                    $new_seat_number
                                )
                            );
                        }

                        break; // Found and updated the right ticket
                    } else {
                        error_log('HOPE: Could not parse new seat ID: ' . $new_seat_id);
                    }
                }
            }
        }
    }

    /**
     * Resend tickets after seat reassignment
     */
    private function resend_tickets($order) {
        // Trigger FooEvents ticket regeneration
        if (class_exists('FooEvents') && function_exists('fooevents_resend_tickets')) {
            fooevents_resend_tickets($order->get_id());
            error_log('HOPE: Tickets resent for order ' . $order->get_id());
        } else {
            // Alternative: Send email notification
            $order->add_order_note(__('Seats reassigned. Please resend tickets manually.', 'hope-theater-seating'));
        }
    }

    /**
     * AJAX handler to get product ID from order
     */
    public function ajax_get_order_product_id() {
        // Add CORS headers for Local/staging environments
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST');
        header('Access-Control-Allow-Headers: Content-Type');

        error_log('HOPE DEBUG: ajax_get_order_product_id called');

        // Security checks
        if (!current_user_can('manage_woocommerce')) {
            error_log('HOPE DEBUG: Access denied - user cannot manage_woocommerce');
            wp_send_json_error(array('error' => 'Access denied'));
        }

        error_log('HOPE DEBUG: Nonce check - received: ' . (isset($_POST['nonce']) ? $_POST['nonce'] : 'NONE'));
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'hope_selective_refund_action')) {
            error_log('HOPE DEBUG: Nonce verification failed');
            wp_send_json_error(array('error' => 'Security check failed'));
        }

        $order_id = intval($_POST['order_id']);
        error_log('HOPE DEBUG: Order ID: ' . $order_id);
        if (!$order_id) {
            error_log('HOPE DEBUG: Invalid order ID');
            wp_send_json_error(array('error' => 'Invalid order ID'));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            error_log('HOPE DEBUG: Order not found');
            wp_send_json_error(array('error' => 'Order not found'));
        }

        // Get the first product ID from the order (theater events should only have one product)
        $items = $order->get_items();
        if (empty($items)) {
            error_log('HOPE DEBUG: No items in order');
            wp_send_json_error(array('error' => 'No items in order'));
        }

        $item = reset($items);
        $product_id = $item->get_product_id();

        error_log('HOPE DEBUG: Product ID found: ' . $product_id);
        wp_send_json_success(array('product_id' => $product_id));
    }

    /**
     * AJAX handler to get event venue ID
     */
    public function ajax_get_event_venue() {
        // Security checks
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Access denied'));
        }

        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'hope_selective_refund_action')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }

        $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

        error_log('HOPE: ajax_get_event_venue called with event_id=' . $event_id . ', order_id=' . $order_id);

        if (!$event_id) {
            wp_send_json_error(array('message' => 'Invalid event ID'));
        }

        // First try to get the pricing map from the product meta
        // According to docs: Products store _hope_seating_venue_id meta (which is actually the pricing map ID)
        $pricing_map_id = get_post_meta($event_id, '_hope_seating_venue_id', true);

        if (!$pricing_map_id) {
            // Fallback: also try _fooevents_pricing_map for legacy compatibility
            $pricing_map_id = get_post_meta($event_id, '_fooevents_pricing_map', true);
        }

        // If not found on product, try to get it from the database bookings
        if (!$pricing_map_id && $order_id) {
            global $wpdb;
            $bookings_table = $wpdb->prefix . 'hope_seating_bookings';

            // Get a booking for this order to find the pricing map
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT product_id, event_id FROM {$bookings_table} WHERE order_id = %d LIMIT 1",
                $order_id
            ));

            error_log('HOPE: Booking query result: ' . print_r($booking, true));

            if ($booking) {
                error_log('HOPE: Found booking with product_id=' . $booking->product_id . ', event_id=' . $booking->event_id);

                // The product_id in bookings table is actually the pricing map ID
                $pricing_map_id = $booking->product_id;
                error_log('HOPE: Using booking product_id as pricing_map_id: ' . $pricing_map_id);
            } else {
                error_log('HOPE: No booking found in database for order_id ' . $order_id);
            }
        }

        // Last resort: try order item metadata
        if (!$pricing_map_id && $order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $items = $order->get_items();
                foreach ($items as $item) {
                    if ($item->get_product_id() == $event_id) {
                        $pricing_map_id = wc_get_order_item_meta($item->get_id(), '_fooevents_pricing_map', true);
                        if ($pricing_map_id) {
                            error_log('HOPE: Found pricing map ' . $pricing_map_id . ' in order item metadata');
                            break;
                        }
                    }
                }
            }
        }

        if (!$pricing_map_id) {
            error_log('HOPE: No pricing map found for event ' . $event_id . ' (checked product meta and order item meta)');
            wp_send_json_error(array('message' => 'No venue/pricing map configured for this event'));
        }

        error_log('HOPE: Found venue/pricing map ID ' . $pricing_map_id . ' for event ' . $event_id);
        wp_send_json_success(array('venue_id' => intval($pricing_map_id)));
    }

    /**
     * AJAX handler to get available seats for an event
     */
    public function ajax_get_available_seats() {
        // Security checks
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Access denied'));
        }

        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'hope_selective_refund_action')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }

        $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $exclude_seat = isset($_POST['exclude_seat']) ? sanitize_text_field($_POST['exclude_seat']) : '';

        if (!$event_id) {
            wp_send_json_error(array('message' => 'Invalid event ID'));
        }

        global $wpdb;
        $bookings_table = $wpdb->prefix . 'hope_seating_bookings';

        // Get the pricing map ID for this event/product (same as seat blocking does)
        $pricing_map_id = get_post_meta($event_id, '_hope_seating_venue_id', true);

        if (!$pricing_map_id) {
            wp_send_json_error(array('message' => 'No seating configuration found for this event'));
        }

        // Get all seats using the pricing manager (same as seat blocking and frontend)
        $all_seats = array();
        if (class_exists('HOPE_Pricing_Maps_Manager')) {
            $pricing_manager = new HOPE_Pricing_Maps_Manager();
            $seats_with_pricing = $pricing_manager->get_seats_with_pricing($pricing_map_id);

            if (!empty($seats_with_pricing)) {
                foreach ($seats_with_pricing as $seat) {
                    $all_seats[] = array(
                        'seat_id' => $seat->seat_id,
                        'section' => $seat->section,
                        'row_number' => $seat->row_number,
                        'seat_number' => $seat->seat_number
                    );
                }
            }
        }

        // Get all booked seats for this event (excluding the seat we're reassigning from)
        // Note: bookings table uses product_id field (which is the WooCommerce product/event ID)
        $booked_seats = $wpdb->get_col($wpdb->prepare(
            "SELECT seat_id FROM {$bookings_table}
            WHERE product_id = %d
            AND status IN ('active', 'confirmed', 'pending')
            AND refund_id IS NULL
            AND seat_id != %s",
            $event_id,
            $exclude_seat
        ));

        // Filter to only available seats
        $available_seats = array_filter($all_seats, function($seat) use ($booked_seats) {
            return !in_array($seat['seat_id'], $booked_seats);
        });

        // Reset array keys
        $available_seats = array_values($available_seats);

        wp_send_json_success(array('seats' => $available_seats));
    }

    /**
     * AJAX handler to get venue layout data
     */
    public function ajax_get_venue_layout() {
        // Add CORS headers for Local/staging environments
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST');
        header('Access-Control-Allow-Headers: Content-Type');

        // Security checks
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('error' => 'Access denied'));
        }

        if (!wp_verify_nonce($_POST['nonce'], 'hope_selective_refund_action')) {
            wp_send_json_error(array('error' => 'Security check failed'));
        }

        $venue_id = intval($_POST['venue_id']);
        $event_id = intval($_POST['event_id']);

        if (!$venue_id || !$event_id) {
            wp_send_json_error(array('error' => 'Invalid parameters'));
        }

        // Get venue layout from database
        global $wpdb;
        $venues_table = $wpdb->prefix . 'hope_seating_venues';

        $venue_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$venues_table} WHERE id = %d",
            $venue_id
        ), ARRAY_A);

        if (!$venue_data) {
            wp_send_json_error(array('error' => 'Venue not found'));
        }

        // Parse the layout JSON
        $layout = json_decode($venue_data['layout'], true);
        if (!$layout) {
            wp_send_json_error(array('error' => 'Invalid venue layout'));
        }

        // Get seat availability for this event
        $bookings_table = $wpdb->prefix . 'hope_seating_bookings';
        $blocks_table = $wpdb->prefix . 'hope_seating_blocks';

        // Get booked seats
        $booked_seats = $wpdb->get_col($wpdb->prepare(
            "SELECT seat_id FROM {$bookings_table} WHERE event_id = %d AND status = 'active'",
            $event_id
        ));

        // Get blocked seats
        $blocked_seats = $wpdb->get_col($wpdb->prepare(
            "SELECT seat_id FROM {$blocks_table}
            WHERE event_id = %d
            AND (valid_until IS NULL OR valid_until > NOW())
            AND (valid_from IS NULL OR valid_from <= NOW())",
            $event_id
        ));

        // Add availability info to response
        wp_send_json_success(array(
            'venue' => $venue_data,
            'layout' => $layout,
            'booked_seats' => $booked_seats,
            'blocked_seats' => $blocked_seats
        ));
    }
}