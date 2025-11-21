<?php
/**
 * Admin Order Lookup by Seat
 * Allows administrators to search for orders by seat location
 */

if (!defined('ABSPATH')) {
    exit;
}

class HOPE_Admin_Order_Lookup {

    public function __construct() {
        // Register AJAX handlers
        add_action('wp_ajax_hope_search_orders_by_seat', array($this, 'ajax_search_orders_by_seat'));
    }

    /**
     * Render the order lookup admin page
     */
    public function render_page() {
        global $wpdb;

        // Get all products that have seating enabled
        $products = $this->get_seating_products();

        ?>
        <div class="wrap">
            <h1>Order Lookup by Seat</h1>
            <p>Search for orders by seat location. Select a product and enter seat details to find matching orders.</p>

            <div class="hope-order-lookup-container">
                <div class="hope-search-form">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="product-search">Product/Event *</label></th>
                            <td>
                                <div class="hope-product-search-container" style="position: relative;">
                                    <input
                                        type="text"
                                        id="product-search"
                                        placeholder="Type to search for a product..."
                                        autocomplete="off"
                                        style="width: 100%; max-width: 400px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                    <input type="hidden" id="product-id" name="product_id">
                                    <div id="product-dropdown" class="hope-product-dropdown" style="display: none;">
                                        <?php foreach ($products as $product): ?>
                                            <div class="hope-product-option" data-product-id="<?php echo esc_attr($product->ID); ?>">
                                                <strong><?php echo esc_html($product->post_title); ?></strong>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <p class="description">Start typing to search for products</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="section-input">Section</label></th>
                            <td>
                                <input type="text" id="section-input" name="section" placeholder="e.g., A, B, H" style="width: 100px;" maxlength="2">
                                <p class="description">Optional. Leave blank to search all sections.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="row-input">Row Number</label></th>
                            <td>
                                <input type="number" id="row-input" name="row_number" placeholder="e.g., 1, 2, 5" style="width: 100px;" min="1">
                                <p class="description">Optional. Leave blank to search all rows.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="seat-input">Seat Number</label></th>
                            <td>
                                <input type="number" id="seat-input" name="seat_number" placeholder="e.g., 1, 5, 12" style="width: 100px;" min="1">
                                <p class="description">Optional. Leave blank to search all seats.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"></th>
                            <td>
                                <button type="button" id="search-button" class="button button-primary">Search Orders</button>
                                <button type="button" id="clear-button" class="button">Clear</button>
                                <span id="search-loading" style="display: none; margin-left: 10px;">
                                    <span class="spinner is-active" style="float: none; margin: 0;"></span>
                                    Searching...
                                </span>
                            </td>
                        </tr>
                    </table>
                </div>

                <div id="search-results" style="margin-top: 30px;"></div>
            </div>
        </div>

        <style>
            .hope-order-lookup-container {
                background: #fff;
                padding: 20px;
                margin-top: 20px;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }

            .hope-search-form {
                max-width: 800px;
            }

            #search-results table {
                width: 100%;
                border-collapse: collapse;
            }

            #search-results th {
                text-align: left;
                padding: 10px;
                background: #f0f0f1;
                border-bottom: 2px solid #ccd0d4;
                font-weight: 600;
            }

            #search-results td {
                padding: 10px;
                border-bottom: 1px solid #e0e0e0;
            }

            #search-results tr:hover {
                background: #f6f7f7;
            }

            #search-results tr.current-status {
                font-weight: 500;
            }

            #search-results tr.history-status {
                background: #fafafa;
                font-size: 0.95em;
                color: #666;
            }

            #search-results tr.history-status:hover {
                background: #f0f0f1;
            }

            .order-link {
                font-weight: 600;
                text-decoration: none;
            }

            .status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
            }

            .status-confirmed {
                background: #c6e1c6;
                color: #2e4b2e;
            }

            .status-pending {
                background: #fff8e5;
                color: #94660c;
            }

            .status-refunded {
                background: #f0d0d0;
                color: #8b0000;
            }

            .status-partially_refunded {
                background: #f0e0d0;
                color: #8b4000;
            }

            .status-on_hold, .status-on-hold {
                background: #e5f3ff;
                color: #0056b3;
            }

            .status-blocked {
                background: #ffebee;
                color: #c62828;
            }

            .hope-product-search-container {
                position: relative;
            }

            .hope-product-dropdown {
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                max-width: 400px;
                background: white;
                border: 1px solid #ddd;
                border-top: none;
                border-radius: 0 0 4px 4px;
                max-height: 300px;
                overflow-y: auto;
                z-index: 1000;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }

            .hope-product-option {
                padding: 10px;
                cursor: pointer;
                border-bottom: 1px solid #eee;
            }

            .hope-product-option:hover {
                background: #f0f0f1;
            }

            .hope-product-option:last-child {
                border-bottom: none;
            }

            .no-results {
                padding: 20px;
                text-align: center;
                color: #666;
                font-style: italic;
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            // Product search autocomplete
            $('#product-search').on('input', function() {
                const searchTerm = $(this).val().toLowerCase();
                const dropdown = $('#product-dropdown');

                if (searchTerm.length > 0) {
                    $('.hope-product-option').each(function() {
                        const productName = $(this).text().toLowerCase();
                        if (productName.includes(searchTerm)) {
                            $(this).show();
                        } else {
                            $(this).hide();
                        }
                    });
                    dropdown.show();
                } else {
                    dropdown.hide();
                }
            });

            // Handle product selection
            $('.hope-product-option').on('click', function() {
                const productId = $(this).data('product-id');
                const productName = $(this).find('strong').text();

                $('#product-search').val(productName);
                $('#product-id').val(productId);
                $('#product-dropdown').hide();
            });

            // Handle clicking outside to close dropdown
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.hope-product-search-container').length) {
                    $('#product-dropdown').hide();
                }
            });

            $('#search-button').on('click', function() {
                performSearch();
            });

            $('#clear-button').on('click', function() {
                $('#product-search').val('');
                $('#product-id').val('');
                $('#section-input').val('');
                $('#row-input').val('');
                $('#seat-input').val('');
                $('#search-results').html('');
            });

            // Allow Enter key to trigger search
            $('#section-input, #row-input, #seat-input').on('keypress', function(e) {
                if (e.which === 13) {
                    performSearch();
                }
            });

            function performSearch() {
                var productId = $('#product-id').val();
                var section = $('#section-input').val().trim().toUpperCase();
                var rowNumber = $('#row-input').val().trim();
                var seatNumber = $('#seat-input').val().trim();

                if (!productId) {
                    alert('Please select a product/event.');
                    return;
                }

                // Show loading
                $('#search-loading').show();
                $('#search-button').prop('disabled', true);
                $('#search-results').html('');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'hope_search_orders_by_seat',
                        product_id: productId,
                        section: section,
                        row_number: rowNumber,
                        seat_number: seatNumber,
                        nonce: '<?php echo wp_create_nonce('hope_order_lookup_nonce'); ?>'
                    },
                    success: function(response) {
                        $('#search-loading').hide();
                        $('#search-button').prop('disabled', false);

                        if (response.success) {
                            displayResults(response.data);
                        } else {
                            $('#search-results').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                        }
                    },
                    error: function() {
                        $('#search-loading').hide();
                        $('#search-button').prop('disabled', false);
                        $('#search-results').html('<div class="notice notice-error"><p>An error occurred while searching. Please try again.</p></div>');
                    }
                });
            }

            function displayResults(data) {
                if (data.results.length === 0) {
                    $('#search-results').html('<div class="no-results">No orders found matching your search criteria.</div>');
                    return;
                }

                var html = '<h2>Search Results (' + data.results.length + ' found)</h2>';
                html += '<table class="wp-list-table widefat fixed striped">';
                html += '<thead><tr>';
                html += '<th>Seat ID</th>';
                html += '<th>Order</th>';
                html += '<th>Customer</th>';
                html += '<th>Email</th>';
                html += '<th>Booking Status</th>';
                html += '<th>Order Status</th>';
                html += '<th>Order Date</th>';
                html += '</tr></thead>';
                html += '<tbody>';

                data.results.forEach(function(result) {
                    var rowClass = result.is_current ? 'current-status' : 'history-status';
                    html += '<tr class="' + rowClass + '">';

                    // Seat ID - show only for current status, indent history
                    if (result.is_current) {
                        html += '<td><strong>' + escapeHtml(result.seat_id) + '</strong></td>';
                    } else {
                        html += '<td style="padding-left: 30px; color: #666;"><em>↳ History</em></td>';
                    }

                    // Handle holds vs bookings
                    if (result.order_id) {
                        html += '<td><a href="' + result.order_edit_url + '" target="_blank" class="order-link">#' + result.order_id + '</a></td>';
                    } else {
                        html += '<td><em>-</em></td>';
                    }

                    html += '<td>' + escapeHtml(result.customer_name) + '</td>';
                    html += '<td>' + escapeHtml(result.customer_email) + '</td>';
                    html += '<td><span class="status-badge status-' + result.booking_status + '">' + escapeHtml(result.booking_status.replace('_', ' ')) + '</span></td>';
                    html += '<td><span class="status-badge status-' + result.order_status.toLowerCase().replace(' ', '-') + '">' + escapeHtml(result.order_status) + '</span></td>';
                    html += '<td>' + escapeHtml(result.order_date) + '</td>';
                    html += '</tr>';
                });

                html += '</tbody></table>';
                $('#search-results').html(html);
            }

            function escapeHtml(text) {
                if (!text) return '';
                var map = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                };
                return text.toString().replace(/[&<>"']/g, function(m) { return map[m]; });
            }
        });
        </script>
        <?php
    }

    /**
     * Get all products that have seating enabled
     */
    private function get_seating_products() {
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => '_hope_seating_enabled',
                    'value' => 'yes',
                    'compare' => '='
                )
            ),
            'orderby' => 'title',
            'order' => 'ASC'
        );

        return get_posts($args);
    }

    /**
     * AJAX handler for searching orders by seat
     */
    public function ajax_search_orders_by_seat() {
        // Verify nonce
        if (!check_ajax_referer('hope_order_lookup_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        $product_id = intval($_POST['product_id']);
        $section = isset($_POST['section']) ? sanitize_text_field($_POST['section']) : '';
        $row_number = isset($_POST['row_number']) ? intval($_POST['row_number']) : 0;
        $seat_number = isset($_POST['seat_number']) ? intval($_POST['seat_number']) : 0;

        if (!$product_id) {
            wp_send_json_error(array('message' => 'Product ID is required'));
        }

        global $wpdb;
        $bookings_table = $wpdb->prefix . 'hope_seating_bookings';
        $blocks_table = $wpdb->prefix . 'hope_seating_seat_blocks';

        // Build seat ID pattern for LIKE query
        $seat_pattern = '';

        if ($section && $row_number && $seat_number) {
            // Exact seat: e.g., "A1-5"
            $seat_pattern = $section . $row_number . '-' . $seat_number;
        } elseif ($section && $row_number) {
            // Entire row: e.g., "A1-%"
            $seat_pattern = $section . $row_number . '-%';
        } elseif ($section) {
            // Entire section: e.g., "A%"
            $seat_pattern = $section . '%';
        } else {
            // All seats for this product
            $seat_pattern = '%';
        }

        // Query bookings - get confirmed purchases
        $bookings_query = $wpdb->prepare(
            "SELECT
                b.seat_id,
                b.order_id,
                b.customer_email,
                b.status as booking_status,
                b.created_at,
                'booking' as record_type
            FROM {$bookings_table} b
            WHERE b.product_id = %d
            AND b.seat_id LIKE %s
            AND b.status IN ('confirmed', 'active', 'pending', 'refunded', 'partially_refunded')
            ORDER BY b.seat_id ASC, b.created_at DESC",
            $product_id,
            $seat_pattern
        );

        $bookings = $wpdb->get_results($bookings_query);

        // Query seat blocks - get active blocks matching the pattern
        $blocks_query = $wpdb->prepare(
            "SELECT
                id,
                event_id,
                seat_ids,
                block_type,
                block_reason,
                blocked_by,
                created_at
            FROM {$blocks_table}
            WHERE event_id = %d
            AND is_active = 1",
            $product_id
        );

        $blocks = $wpdb->get_results($blocks_query);

        // Process blocks to extract individual seats matching the pattern
        $block_results = array();

        foreach ($blocks as $block) {
            $seat_ids = json_decode($block->seat_ids, true);
            if (!is_array($seat_ids)) {
                continue;
            }

            foreach ($seat_ids as $seat_id) {
                // Check if this seat matches the search pattern using SQL LIKE-style matching
                $matches = false;

                if ($seat_pattern === '%') {
                    // Match all seats
                    $matches = true;
                } elseif (strpos($seat_pattern, '%') !== false) {
                    // Pattern has wildcards - convert SQL LIKE pattern to regex
                    $regex_pattern = '/^' . str_replace('%', '.*', preg_quote($seat_pattern, '/')) . '$/';
                    $matches = preg_match($regex_pattern, $seat_id);
                } else {
                    // Exact match
                    $matches = ($seat_pattern === $seat_id);
                }

                if ($matches) {
                    $block_results[] = (object) array(
                        'seat_id' => $seat_id,
                        'order_id' => null,
                        'customer_email' => null,
                        'booking_status' => 'blocked',
                        'created_at' => $block->created_at,
                        'record_type' => 'block',
                        'block_type' => $block->block_type,
                        'block_reason' => $block->block_reason,
                        'blocked_by' => $block->blocked_by
                    );
                }
            }
        }

        // Combine bookings and blocks (holds excluded - too temporary/noisy)
        $all_results = array_merge($bookings, $block_results);

        // Debug: Check for duplicates before grouping
        error_log("HOPE Order Lookup: Total results before grouping: " . count($all_results));
        $seat_count = array();
        foreach ($all_results as $result) {
            $key = $result->seat_id . '-' . $result->record_type;
            if (!isset($seat_count[$key])) {
                $seat_count[$key] = 0;
            }
            $seat_count[$key]++;
        }
        foreach ($seat_count as $key => $count) {
            if ($count > 1) {
                error_log("HOPE Order Lookup: DUPLICATE FOUND - {$key} appears {$count} times");
            }
        }

        // Group results by seat_id
        $grouped_results = array();
        foreach ($all_results as $record) {
            if (!isset($grouped_results[$record->seat_id])) {
                $grouped_results[$record->seat_id] = array();
            }
            $grouped_results[$record->seat_id][] = $record;
        }

        // Sort seats using natural sort (handles A1-2, A1-10 correctly)
        uksort($grouped_results, 'strnatcmp');

        // Within each seat, sort by priority, then by date
        // Priority order: confirmed/active bookings > blocks > refunded
        foreach ($grouped_results as $seat_id => &$records) {
            usort($records, function($a, $b) {
                // Define priority for each status
                $priority = function($record) {
                    if ($record->record_type === 'booking') {
                        if (in_array($record->booking_status, array('confirmed', 'active', 'pending'))) {
                            return 1; // Highest priority - active bookings
                        } else {
                            return 3; // Lowest priority - refunded bookings
                        }
                    } else { // block
                        return 2; // Second priority - blocks
                    }
                };

                $priority_a = $priority($a);
                $priority_b = $priority($b);

                // First sort by priority (lower number = higher priority)
                if ($priority_a !== $priority_b) {
                    return $priority_a - $priority_b;
                }

                // If same priority, sort by date (most recent first)
                return strtotime($b->created_at) - strtotime($a->created_at);
            });
        }

        // Format results - flatten grouped results with current status first, then history
        $results = array();
        foreach ($grouped_results as $seat_id => $records) {
            $is_first = true; // First record is current status
            foreach ($records as $record) {
                $is_current = $is_first;
                $is_first = false;

                if ($record->record_type === 'block') {
                    // Process block record
                    $block_type_label = ucfirst($record->block_type);
                    $blocked_by_user = get_userdata($record->blocked_by);
                    $blocked_by_name = $blocked_by_user ? $blocked_by_user->display_name : 'Unknown';

                    $results[] = array(
                        'seat_id' => $record->seat_id,
                        'order_id' => null,
                        'order_edit_url' => null,
                        'customer_name' => 'Blocked (' . $block_type_label . ')',
                        'customer_email' => $record->block_reason ? $record->block_reason : 'N/A',
                        'booking_status' => 'blocked',
                        'order_status' => 'Blocked by ' . $blocked_by_name,
                        'order_date' => mysql2date('M j, Y g:i A', $record->created_at),
                        'is_current' => $is_current
                    );
                } else {
                    // Process booking record
                    $order = wc_get_order($record->order_id);

                    if (!$order) {
                        continue;
                    }

                    $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
                    if (trim($customer_name) === '') {
                        $customer_name = 'Guest';
                    }

                    $results[] = array(
                        'seat_id' => $record->seat_id,
                        'order_id' => $record->order_id,
                        'order_edit_url' => admin_url('post.php?post=' . $record->order_id . '&action=edit'),
                        'customer_name' => $customer_name,
                        'customer_email' => $record->customer_email ? $record->customer_email : $order->get_billing_email(),
                        'booking_status' => $record->booking_status,
                        'order_status' => $order->get_status(),
                        'order_date' => $order->get_date_created()->date_i18n('M j, Y g:i A'),
                        'is_current' => $is_current
                    );
                }
            }
        }

        wp_send_json_success(array(
            'results' => $results,
            'search_criteria' => array(
                'product_id' => $product_id,
                'section' => $section,
                'row_number' => $row_number,
                'seat_number' => $seat_number,
                'seat_pattern' => $seat_pattern
            )
        ));
    }
}
