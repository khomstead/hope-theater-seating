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
        add_action('wp_ajax_hope_get_event_venue_blocking', array($this, 'ajax_get_event_venue'));
        
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
            'hope-seating',
            'Theater Seat Blocking',
            'Seat Blocking',
            'manage_options',
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
        
        // Event selection with search/combo box
        echo '<div class="hope-admin-section">';
        echo '<h2>Select Event to Manage</h2>';
        echo '<div class="hope-event-search-container">';
        echo '<input type="text" id="hope-event-search" placeholder="Search for an event..." style="width: 100%; max-width: 400px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">';
        echo '<div id="hope-event-dropdown" class="hope-event-dropdown" style="display: none;">';
        foreach ($theater_products as $product) {
            echo '<div class="hope-event-option" data-event-id="' . $product->get_id() . '">';
            echo '<strong>' . esc_html($product->get_name()) . '</strong>';
            echo '<small style="color: #666; display: block;">ID: ' . $product->get_id() . '</small>';
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';
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
        echo '<div id="hope-seat-map-container" style="border: 1px solid #ddd; border-radius: 4px; background: #f9f9f9; min-height: 400px;">';
        echo '<div id="hope-seat-map-loading" style="text-align: center; padding: 40px; color: #666;">Select an event to load seat map</div>';
        echo '<button id="hope-open-seat-map-btn" class="button-primary" style="display: none; margin: 20px auto; display: block;">Open Seat Map</button>';
        echo '</div>';
        
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

        // Add the admin seat selection modal
        $this->render_admin_seat_modal();

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
     * Render the admin seat selection modal (based on frontend modal)
     */
    private function render_admin_seat_modal() {
        ?>
        <div id="hope-admin-seat-modal" class="hope-modal" style="display: none;" aria-hidden="true" role="dialog">
            <div class="hope-modal-overlay"></div>
            <div class="hope-modal-content">

                <div class="hope-modal-body">
                    <!-- Loading indicator -->
                    <div class="hope-loading-indicator">
                        <div class="spinner"></div>
                        <p><?php _e('Loading seat map...', 'hope-theater-seating'); ?></p>
                    </div>

                    <!-- Seat map container -->
                    <div id="hope-admin-seat-map-container" style="display: none;">
                        <div class="theater-container">
                            <div class="header">
                                <div class="header-content">
                                    <h3>Select Seats to Block</h3>
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
                                        <!-- Seats will be generated dynamically via JavaScript -->
                                    </svg>
                                </div>
                            </div>

                            <div class="selected-seats-panel" id="admin-selected-seats-panel" style="display: none;">
                                <div class="seats-list" id="admin-selected-seats-list">
                                    <span class="empty-message">No seats selected for blocking</span>
                                </div>
                            </div>
                        </div>

                        <div class="tooltip" id="admin-tooltip"></div>
                    </div>
                </div>

                <div class="hope-modal-footer">
                    <div class="hope-modal-info">
                        <button class="seats-toggle" id="admin-seats-toggle" title="Show selected seats">
                            <span class="seat-count-display">No seats selected</span>
                            <span class="toggle-icon">▲</span>
                        </button>
                    </div>

                    <div class="hope-modal-actions">
                        <button type="button" class="hope-cancel-btn button">
                            <?php _e('Cancel', 'hope-theater-seating'); ?>
                        </button>
                        <button type="button" class="hope-confirm-seats-btn button-primary" disabled>
                            <?php _e('Use Selected Seats', 'hope-theater-seating'); ?>
                            <span class="seat-count-badge" style="display: none;">0</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render admin styles
     */
    private function render_admin_styles() {
        echo '<style>
            .hope-admin-section { background: white; margin: 20px 0; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
            .hope-admin-section h2 { margin-top: 0; }
            .hope-event-search-container { position: relative; }
            .hope-event-dropdown { position: absolute; top: 100%; left: 0; right: 0; max-width: 400px; background: white; border: 1px solid #ddd; border-top: none; border-radius: 0 0 4px 4px; max-height: 300px; overflow-y: auto; z-index: 1000; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
            .hope-event-option { padding: 10px; cursor: pointer; border-bottom: 1px solid #eee; }
            .hope-event-option:hover { background: #f8f9fa; }
            .hope-event-option:last-child { border-bottom: none; }
            .hope-block-item { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; padding: 15px; margin: 10px 0; display: flex; justify-content: space-between; align-items: flex-start; }
            .hope-block-content { flex: 1; }
            .hope-block-type { display: inline-block; padding: 4px 8px; border-radius: 3px; color: white; font-size: 11px; font-weight: bold; }
            .hope-remove-block-btn { background: #dc3545; color: white; border: none; padding: 8px 12px; border-radius: 3px; cursor: pointer; margin-left: 15px; flex-shrink: 0; }
            .hope-remove-block-btn:hover { background: #c82333; }
            #hope-admin-seat-map-container { position: relative; }
            #hope-admin-seat-map-container svg { width: 100%; height: auto; max-height: 600px; }
            .hope-admin-seat { cursor: pointer; transition: all 0.2s; }
            .hope-admin-seat.available { fill: #27ae60; }
            .hope-admin-seat.available:hover { fill: #2ecc71; stroke: #fff; stroke-width: 2; }
            .hope-admin-seat.selected { fill: #e74c3c; stroke: #fff; stroke-width: 2; }
            .hope-admin-seat.blocked { fill: #95a5a6; opacity: 0.7; cursor: not-allowed; }
            .hope-admin-seat.booked { fill: #6c757d; opacity: 0.8; cursor: not-allowed; }
            .hope-admin-stage { fill: #2c3e50; }
            .hope-admin-seat-label { font-size: 8px; fill: white; pointer-events: none; text-anchor: middle; }
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
            
            // Handle event search and selection
            $('#hope-event-search').on('input', function() {
                const searchTerm = $(this).val().toLowerCase();
                const dropdown = $('#hope-event-dropdown');

                if (searchTerm.length > 0) {
                    $('.hope-event-option').each(function() {
                        const eventName = $(this).text().toLowerCase();
                        if (eventName.includes(searchTerm)) {
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

            // Handle clicking outside to close dropdown
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.hope-event-search-container').length) {
                    $('#hope-event-dropdown').hide();
                }
            });

            // Handle event option selection
            $('.hope-event-option').on('click', function() {
                const eventId = $(this).data('event-id');
                const eventName = $(this).find('strong').text();

                $('#hope-event-search').val(eventName);
                $('#hope-event-dropdown').hide();

                currentEventId = eventId;
                loadEventData(eventId);
                $('#hope-blocking-interface').show();
            });
            
            // Handle block duration selection
            $('input[name="block-duration"]').on('change', function() {
                if ($(this).val() === 'custom') {
                    $('#hope-custom-duration').show();
                } else {
                    $('#hope-custom-duration').hide();
                }
            });
            
            // Load event data (current blocks and enable seat map)
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
                            enableSeatMapButton(eventId);
                        } else {
                            alert('Failed to load event data: ' + response.data.error);
                        }
                    },
                    error: function() {
                        alert('Network error loading event data');
                    }
                });
            }

            // Enable the seat map button for the selected event
            function enableSeatMapButton(eventId) {
                $('#hope-seat-map-loading').text('Ready to block seats for this event');
                $('#hope-open-seat-map-btn').show();
                $('#hope-block-controls').show();

                // Set up the button click handler
                $('#hope-open-seat-map-btn').off('click').on('click', function() {
                    openSeatMapForBlocking(eventId);
                });

                // Clear any previous selection
                selectedSeats = [];
                updateSelectionDisplay();
            }

            // Open seat map for blocking - use actual modal
            function openSeatMapForBlocking(eventId) {
                console.log('Opening seat map for event:', eventId);

                // Find the modal
                const modal = $('#hope-admin-seat-modal');
                console.log('Modal found:', modal.length > 0);

                if (modal.length === 0) {
                    alert('Error: Admin seat modal not found in page. Please refresh and try again.');
                    return;
                }

                // Show the modal
                modal.addClass('show').attr('aria-hidden', 'false');

                // Add body class to prevent scrolling
                $('body').addClass('hope-modal-open');

                // Show loading, hide content initially
                const loader = modal.find('.hope-loading-indicator');
                const content = modal.find('#hope-admin-seat-map-container');

                console.log('Modal elements found - loader:', loader.length, 'content:', content.length);

                loader.show();
                content.hide();

                // Initialize the real seat map with proper data loading flags
                setTimeout(() => {
                    initializeRealAdminSeatMap(eventId);
                }, 500);
            }

            // Initialize real admin seat map with proper data loading fix
            function initializeRealAdminSeatMap(eventId) {
                console.log('Initializing REAL admin seat map for event:', eventId);

                // CRITICAL: Clean up any previous seat map state first
                if (window.adminSeatMap) {
                    console.log('Cleaning up previous admin seat map instance');
                    if (window.adminSeatMap.selectedSeats) {
                        window.adminSeatMap.selectedSeats.clear();
                    }
                    if (window.adminSeatMap.availabilityInterval) {
                        clearInterval(window.adminSeatMap.availabilityInterval);
                    }
                    window.adminSeatMap = null;
                }

                // Clear simple selected seats
                if (window.simpleSelectedSeats) {
                    window.simpleSelectedSeats.clear();
                }

                // Clear SVG content
                const svg = document.getElementById('admin-seat-map');
                if (svg) {
                    svg.innerHTML = '';
                }

                const modal = $('#hope-admin-seat-modal');
                const loader = modal.find('.hope-loading-indicator');
                const content = modal.find('#hope-admin-seat-map-container');

                // Get venue ID first
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'hope_get_event_venue_blocking',
                        event_id: eventId,
                        nonce: $('#hope_seat_block_admin_nonce').val()
                    },
                    success: function(venueResponse) {
                        if (!venueResponse.success) {
                            const errorMsg = venueResponse.data?.error || 'This event does not have a venue/pricing map configured.';
                            console.error('No venue configured for event:', eventId, '-', errorMsg);
                            loader.hide();
                            content.show().html(`
                                <div style="padding: 40px; text-align: center;">
                                    <h3>⚠️ Seat Map Not Configured</h3>
                                    <p style="margin: 20px 0;">${errorMsg}</p>
                                    <p style="color: #666; font-size: 14px;">
                                        To fix this: Edit the product → Go to "HOPE Theater Seating" tab →
                                        Check "Enable Seat Selection" → Select a seat map
                                    </p>
                                    <button type="button" class="button" onclick="closeAdminSeatModal()">Close</button>
                                </div>
                            `);
                            return;
                        }

                        const venueId = venueResponse.data.venue_id;
                        console.log('Found venue ID:', venueId, 'for event:', eventId);

                        // Hide loading, show content
                        loader.hide();
                        content.show();

                        // Create proper AJAX config
                        const adminAjax = {
                            ajax_url: hope_ajax.ajax_url,
                            nonce: hope_ajax.nonce,
                            product_id: eventId,
                            event_id: eventId,
                            venue_id: venueId,
                            session_id: 'admin_' + Date.now(),
                            admin_mode: true,
                            is_mobile: false
                        };

                        // Set global hope_ajax for seat map to use
                        window.hope_ajax = adminAjax;

                        console.log('Creating real admin seat map with config:', adminAjax);

                        try {
                            // Create new seat map instance
                            window.adminSeatMap = new HOPESeatMap();

                            // Set admin-specific properties
                            window.adminSeatMap.ajax = adminAjax;
                            window.adminSeatMap.containerId = 'admin-seat-map';
                            window.adminSeatMap.wrapperId = 'admin-seating-wrapper';
                            window.adminSeatMap.tooltipId = 'admin-tooltip';
                            window.adminSeatMap.isAdminMode = true;
                            window.adminSeatMap.maxSeats = 999; // Allow selecting many seats
                            window.adminSeatMap.selectedSeats = new Set();

                            // Override availability loading for admin mode to show seat status
                            window.adminSeatMap.loadSeatAvailability = function() {
                                console.log('Admin mode: loading seat availability for status display');
                                return loadAdminSeatAvailability(eventId);
                            };

                            // Override hover handlers to prevent errors in admin mode
                            window.adminSeatMap.handleSeatHover = function(seat, isEntering) {
                                // Skip hover handling in admin mode to prevent SVG errors
                                return;
                            };
                            window.adminSeatMap.startAvailabilityRefresh = function() {
                                console.log('Admin mode: skipping periodic refresh');
                                // Don't start periodic refresh in admin mode
                            };

                            // CRITICAL: Initialize data loading status to prevent waiting
                            window.adminSeatMap.dataLoadingStatus = {
                                variationPricing: false, // Start as false, we'll set to true after init
                                realSeatData: false      // Start as false, will be set by loadRealSeatData
                            };

                            console.log('Calling initializeMap...');
                            window.adminSeatMap.initializeMap();

                            // Force both data loading flags to true after a short delay
                            setTimeout(() => {
                                console.log('Forcing data loading status to complete...');
                                if (window.adminSeatMap.dataLoadingStatus) {
                                    window.adminSeatMap.dataLoadingStatus.variationPricing = true;
                                    window.adminSeatMap.dataLoadingStatus.realSeatData = true;

                                    // Re-call initializeMap now that both flags are true
                                    console.log('Re-calling initializeMap with both data types ready...');
                                    window.adminSeatMap.initializeMap();

                                    // Check if seats rendered after a delay
                                    setTimeout(() => {
                                        const seatCount = document.querySelectorAll('#admin-seat-map .seat').length;
                                        console.log('Seat elements rendered:', seatCount);
                                        if (seatCount === 0) {
                                            console.log('No seats rendered, trying generateTheater directly...');

                                            // Debug the seat map data and state
                                            console.log('Real seat data available:', !!window.adminSeatMap.realSeatData);
                                            console.log('Real seat data length:', window.adminSeatMap.realSeatData?.length || 0);
                                            console.log('Processed seat data:', window.adminSeatMap.processedSeatData);
                                            console.log('Current floor:', window.adminSeatMap.currentFloor);

                                            // Debug the specific floor data that's causing the error
                                            if (window.adminSeatMap.processedSeatData) {
                                                console.log('Orchestra data:', window.adminSeatMap.processedSeatData.orchestra);
                                                console.log('Balcony data:', window.adminSeatMap.processedSeatData.balcony);
                                                console.log('Available floors:', Object.keys(window.adminSeatMap.processedSeatData));
                                            }

                                            // Try to manually trigger data processing if needed
                                            if (window.adminSeatMap.realSeatData && window.adminSeatMap.realSeatData.length > 0) {
                                                console.log('Manually calling processRealSeatData...');
                                                try {
                                                    window.adminSeatMap.processRealSeatData();
                                                    console.log('After manual processing:', window.adminSeatMap.processedSeatData);
                                                } catch (e) {
                                                    console.error('Error in processRealSeatData:', e);
                                                }
                                            }

                                            // Try to call various rendering methods
                                            if (window.adminSeatMap.generateTheater) {
                                                console.log('Calling generateTheater(orchestra)...');
                                                try {
                                                    window.adminSeatMap.generateTheater('orchestra');
                                                } catch (e) {
                                                    console.error('Error in generateTheater:', e);
                                                }
                                            }

                                            // Also try createRealSeats if available, but fix the null data issue first
                                            if (window.adminSeatMap.createRealSeats) {
                                                console.log('Calling createRealSeats...');
                                                try {
                                                    // Check if processedSeatData has the right structure
                                                    if (!window.adminSeatMap.processedSeatData?.orchestra) {
                                                        console.log('Orchestra data is missing, trying to fix...');

                                                        // Ensure processedSeatData has the right structure
                                                        if (!window.adminSeatMap.processedSeatData) {
                                                            window.adminSeatMap.processedSeatData = { orchestra: {}, balcony: {} };
                                                        }
                                                        if (!window.adminSeatMap.processedSeatData.orchestra) {
                                                            window.adminSeatMap.processedSeatData.orchestra = {};
                                                        }

                                                        // Try to process real seat data again
                                                        if (window.adminSeatMap.realSeatData && window.adminSeatMap.realSeatData.length > 0) {
                                                            window.adminSeatMap.processRealSeatData();
                                                        }
                                                    }

                                                    // Get the SVG element and current floor
                                                    const svg = document.getElementById('admin-seat-map');
                                                    const currentFloor = window.adminSeatMap.currentFloor || 'orchestra';

                                                    console.log('Calling createRealSeats with floor:', currentFloor);
                                                    window.adminSeatMap.createRealSeats(svg, currentFloor);

                                                    // Load availability status after creating seats
                                                    setTimeout(() => {
                                                        console.log('Loading availability after createRealSeats...');
                                                        loadAdminSeatAvailability(eventId);
                                                    }, 500);
                                                } catch (e) {
                                                    console.error('Error in createRealSeats:', e);
                                                }
                                            }

                                            // Check again after these calls
                                            setTimeout(() => {
                                                const newSeatCount = document.querySelectorAll('#admin-seat-map .seat').length;
                                                console.log('Seat count after manual rendering attempts:', newSeatCount);

                                                if (newSeatCount === 0) {
                                                    // Try one more approach - manually create a few test seats
                                                    console.log('Still no seats, creating test seats manually...');
                                                    createTestSeats();
                                                } else {
                                                    // Seats are rendered, now load availability status
                                                    console.log('Seats rendered successfully, loading availability status...');
                                                    loadAdminSeatAvailability(eventId);
                                                }
                                            }, 1000);
                                        }
                                    }, 1000);
                                }
                            }, 2000);

                            // Set up admin-specific event handlers
                            setupAdminSeatMapHandlers();

                            // Set up zoom, drag and floor toggle functionality
                            setupAdminSeatMapInteractivity();

                        } catch (error) {
                            console.error('Error creating real admin seat map:', error);
                            content.html(`
                                <div style="padding: 40px; text-align: center;">
                                    <h3>❌ Error</h3>
                                    <p>Error initializing seat map: ${error.message}</p>
                                    <button type="button" class="button" onclick="closeAdminSeatModal()">Close</button>
                                </div>
                            `);
                        }
                    },
                    error: function() {
                        console.error('Error getting venue for event');
                        loader.hide();
                        content.show().html(`
                            <div style="padding: 40px; text-align: center;">
                                <h3>❌ Error Loading Venue</h3>
                                <p>Could not load venue information for this event.</p>
                                <button type="button" class="button" onclick="closeAdminSeatModal()">Close</button>
                            </div>
                        `);
                    }
                });
            }

            // Create test seats manually as last resort
            function createTestSeats() {
                console.log('Creating manual test seats...');
                const svg = document.getElementById('admin-seat-map');
                if (!svg) {
                    console.error('SVG container not found');
                    return;
                }

                // Create a test section group
                const sectionGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                sectionGroup.id = 'section-TEST';
                sectionGroup.setAttribute('class', 'section-group');

                // Create a few test seats
                for (let i = 1; i <= 10; i++) {
                    const seat = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                    const seatId = `TEST${1}-${i}`;

                    seat.setAttribute('class', 'seat available');
                    seat.setAttribute('data-seat-id', seatId);
                    seat.setAttribute('data-section', 'TEST');
                    seat.setAttribute('data-row', '1');
                    seat.setAttribute('data-seat', i.toString());
                    seat.setAttribute('data-tier', 'P1');
                    seat.setAttribute('x', (200 + i * 40).toString());
                    seat.setAttribute('y', '400');
                    seat.setAttribute('width', '30');
                    seat.setAttribute('height', '30');
                    seat.setAttribute('rx', '5');
                    seat.setAttribute('fill', '#28a745');
                    seat.setAttribute('stroke', '#fff');
                    seat.setAttribute('stroke-width', '2');
                    seat.style.cursor = 'pointer';

                    // Add click handler
                    seat.addEventListener('click', function() {
                        toggleTestSeat(seatId, seat);
                    });

                    sectionGroup.appendChild(seat);

                    // Add seat number text
                    const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                    text.setAttribute('x', (215 + i * 40).toString());
                    text.setAttribute('y', '420');
                    text.setAttribute('text-anchor', 'middle');
                    text.setAttribute('font-size', '12');
                    text.setAttribute('fill', 'white');
                    text.setAttribute('pointer-events', 'none');
                    text.textContent = i.toString();

                    sectionGroup.appendChild(text);
                }

                svg.appendChild(sectionGroup);

                // Add a label
                const label = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                label.setAttribute('x', '400');
                label.setAttribute('y', '380');
                label.setAttribute('text-anchor', 'middle');
                label.setAttribute('font-size', '14');
                label.setAttribute('fill', '#333');
                label.textContent = 'Test Section (Click seats to select)';
                svg.appendChild(label);

                console.log('Manual test seats created');
            }

            // Toggle test seat selection
            function toggleTestSeat(seatId, seatElement) {
                window.adminTestSelectedSeats = window.adminTestSelectedSeats || new Set();

                if (window.adminTestSelectedSeats.has(seatId)) {
                    // Deselect
                    window.adminTestSelectedSeats.delete(seatId);
                    seatElement.setAttribute('fill', '#28a745');
                    seatElement.classList.remove('selected');
                } else {
                    // Select
                    window.adminTestSelectedSeats.add(seatId);
                    seatElement.setAttribute('fill', '#007bff');
                    seatElement.classList.add('selected');
                }

                // Update the global selectedSeats for the modal
                if (window.adminSeatMap) {
                    window.adminSeatMap.selectedSeats = window.adminTestSelectedSeats;
                }

                // Update modal footer
                updateAdminSeatDisplay(Array.from(window.adminTestSelectedSeats));

                console.log('Test seat toggled:', seatId, 'Total selected:', window.adminTestSelectedSeats.size);
            }

            // Create simple admin seat display that actually works
            function createSimpleAdminSeatDisplay(eventId) {
                console.log('Creating simple admin seat display for event:', eventId);

                const modal = $('#hope-admin-seat-modal');
                const loader = modal.find('.hope-loading-indicator');
                const content = modal.find('#hope-admin-seat-map-container');

                // First get the venue ID for this event
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'hope_get_event_venue_blocking',
                        event_id: eventId,
                        nonce: $('#hope_seat_block_admin_nonce').val()
                    },
                    success: function(venueResponse) {
                        if (!venueResponse.success) {
                            console.error('No venue configured for event:', eventId);
                            loader.hide();
                            content.show().html(`
                                <div style="padding: 40px; text-align: center;">
                                    <h3>⚠️ Venue Not Configured</h3>
                                    <p>This event does not have a venue/pricing map configured.</p>
                                    <p>Please configure the venue in the product settings first.</p>
                                    <button type="button" class="button" onclick="closeAdminSeatModal()">Close</button>
                                </div>
                            `);
                            return;
                        }

                        const venueId = venueResponse.data.venue_id;
                        console.log('Found venue ID:', venueId, 'for event:', eventId);

                        // Now get the seat data via AJAX
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'hope_get_venue_seats',
                                venue_id: venueId,
                                event_id: eventId,
                                nonce: hope_ajax.nonce
                            },
                            success: function(response) {
                                console.log('Seat data response:', response);

                                loader.hide();
                                content.show();

                                if (response.success && response.data.seats) {
                                    const seats = response.data.seats;
                                    console.log('Building simple seat grid with', seats.length, 'seats');

                                    buildSimpleSeatGrid(seats, content);
                                } else {
                                    content.html(`
                                        <div style="padding: 40px; text-align: center;">
                                            <h3>⚠️ No Seat Data</h3>
                                            <p>Could not load seat data: ${response.data || 'Unknown error'}</p>
                                            <button type="button" class="button" onclick="closeAdminSeatModal()">Close</button>
                                        </div>
                                    `);
                                }
                            },
                            error: function(xhr, status, error) {
                                console.error('Error loading seat data:', error);
                                loader.hide();
                                content.show().html(`
                                    <div style="padding: 40px; text-align: center;">
                                        <h3>❌ Error Loading Seats</h3>
                                        <p>Could not load seat data from server.</p>
                                        <button type="button" class="button" onclick="closeAdminSeatModal()">Close</button>
                                    </div>
                                `);
                            }
                        });
                    },
                    error: function() {
                        console.error('Error getting venue for event');
                        loader.hide();
                        content.show().html(`
                            <div style="padding: 40px; text-align: center;">
                                <h3>❌ Error Loading Venue</h3>
                                <p>Could not load venue information for this event.</p>
                                <button type="button" class="button" onclick="closeAdminSeatModal()">Close</button>
                            </div>
                        `);
                    }
                });
            }

            // Build simple seat grid
            function buildSimpleSeatGrid(seats, container) {
                console.log('Building seat grid with', seats.length, 'seats');

                // Group seats by section for easier display
                const seatsBySection = {};
                seats.forEach(seat => {
                    const section = seat.section || 'Unknown';
                    if (!seatsBySection[section]) {
                        seatsBySection[section] = [];
                    }
                    seatsBySection[section].push(seat);
                });

                console.log('Seat sections:', Object.keys(seatsBySection));

                let html = `
                    <div style="padding: 20px;">
                        <h3>Select Seats to Block</h3>
                        <p>Click seats to select/deselect them for blocking. Selected seats will be highlighted.</p>
                        <div style="margin-bottom: 20px;">
                            <span class="seat-legend">
                                <span class="legend-item"><span class="color-box" style="background: #28a745;"></span> Available</span>
                                <span class="legend-item"><span class="color-box" style="background: #dc3545;"></span> Booked</span>
                                <span class="legend-item"><span class="color-box" style="background: #6c757d;"></span> Blocked</span>
                                <span class="legend-item"><span class="color-box" style="background: #007bff;"></span> Selected</span>
                            </span>
                        </div>
                `;

                // Display each section
                Object.keys(seatsBySection).sort().forEach(sectionName => {
                    const sectionSeats = seatsBySection[sectionName];

                    html += `<div class="seat-section" style="margin-bottom: 30px;">`;
                    html += `<h4>Section ${sectionName}</h4>`;
                    html += `<div class="seat-grid" style="display: flex; flex-wrap: wrap; gap: 5px; max-width: 800px;">`;

                    sectionSeats.forEach(seat => {
                        const seatId = seat.seat_id || `${seat.section}${seat.row}-${seat.seat_number}`;
                        const isBooked = seat.status === 'booked' || seat.status === 'confirmed';
                        const isBlocked = seat.status === 'blocked';

                        let seatClass = 'available';
                        let backgroundColor = '#28a745';
                        let cursor = 'pointer';

                        if (isBooked) {
                            seatClass = 'booked';
                            backgroundColor = '#dc3545';
                            cursor = 'not-allowed';
                        } else if (isBlocked) {
                            seatClass = 'blocked';
                            backgroundColor = '#6c757d';
                            cursor = 'not-allowed';
                        }

                        html += `
                            <div class="simple-seat ${seatClass}"
                                 data-seat-id="${seatId}"
                                 data-section="${seat.section}"
                                 data-row="${seat.row}"
                                 data-seat="${seat.seat_number}"
                                 onclick="toggleSimpleSeat('${seatId}')"
                                 style="
                                     width: 30px;
                                     height: 30px;
                                     background: ${backgroundColor};
                                     border: 2px solid #fff;
                                     border-radius: 4px;
                                     display: flex;
                                     align-items: center;
                                     justify-content: center;
                                     font-size: 10px;
                                     color: white;
                                     cursor: ${cursor};
                                     user-select: none;
                                 "
                                 title="${seatId} - ${seatClass}">
                                ${seat.seat_number}
                            </div>
                        `;
                    });

                    html += `</div></div>`;
                });

                html += `
                        <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                            <strong>Selected Seats: </strong>
                            <span id="simple-selected-seats">None</span>
                        </div>
                    </div>
                `;

                container.html(html);

                // Set up modal handlers
                setupBasicModalHandlers();

                console.log('Simple seat grid created successfully');
            }

            // Toggle seat selection in simple grid - make it globally accessible
            window.toggleSimpleSeat = function(seatId) {
                const seatElement = $(`.simple-seat[data-seat-id="${seatId}"]`);

                if (seatElement.hasClass('booked') || seatElement.hasClass('blocked')) {
                    return; // Can't select booked or blocked seats
                }

                if (seatElement.hasClass('selected')) {
                    // Deselect
                    seatElement.removeClass('selected').css('background', '#28a745');
                    window.simpleSelectedSeats = window.simpleSelectedSeats || new Set();
                    window.simpleSelectedSeats.delete(seatId);
                } else {
                    // Select
                    seatElement.addClass('selected').css('background', '#007bff');
                    window.simpleSelectedSeats = window.simpleSelectedSeats || new Set();
                    window.simpleSelectedSeats.add(seatId);
                }

                window.updateSimpleSelection();
            }

            // Update selection display - make it globally accessible
            window.updateSimpleSelection = function() {
                window.simpleSelectedSeats = window.simpleSelectedSeats || new Set();
                const count = window.simpleSelectedSeats.size;
                const seats = Array.from(window.simpleSelectedSeats);

                $('#simple-selected-seats').text(count > 0 ? `${count} seats: ${seats.join(', ')}` : 'None');

                // Update modal footer
                const modal = $('#hope-admin-seat-modal');
                const countDisplay = modal.find('.seat-count-display');
                const confirmBtn = modal.find('.hope-confirm-seats-btn');

                if (count > 0) {
                    countDisplay.text(`${count} seats selected`);
                    confirmBtn.prop('disabled', false);
                } else {
                    countDisplay.text('No seats selected');
                    confirmBtn.prop('disabled', true);
                }
            }

            // Initialize admin seat map
            function initializeAdminSeatMap(eventId) {
                console.log('Initializing admin seat map for event:', eventId);

                const modal = $('#hope-admin-seat-modal');
                const loader = modal.find('.hope-loading-indicator');
                const content = modal.find('#hope-admin-seat-map-container');

                // Get the venue/pricing map ID from the product first
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'hope_get_event_venue_blocking',
                        event_id: eventId,
                        nonce: $('#hope_seat_block_admin_nonce').val()
                    },
                    success: function(response) {
                        if (!response.success) {
                            console.error('No venue configured for event:', eventId);
                            loader.hide();
                            content.show().html(`
                                <div style="padding: 40px; text-align: center;">
                                    <h3>⚠️ Venue Not Configured</h3>
                                    <p>This event does not have a venue/pricing map configured.</p>
                                    <p>Please configure the venue in the product settings first.</p>
                                    <button type="button" class="button" onclick="closeAdminSeatModal()">Close</button>
                                </div>
                            `);
                            return;
                        }

                        const venueId = response.data.venue_id;
                        console.log('Found venue ID:', venueId, 'for event:', eventId);

                        // Now initialize the seat map properly
                        initializeSeatMapWithVenue(eventId, venueId, modal, loader, content);
                    },
                    error: function() {
                        console.error('Error getting venue for event');
                        loader.hide();
                        content.show().html(`
                            <div style="padding: 40px; text-align: center;">
                                <h3>❌ Error Loading Venue</h3>
                                <p>Could not load venue information for this event.</p>
                                <button type="button" class="button" onclick="closeAdminSeatModal()">Close</button>
                            </div>
                        `);
                    }
                });
            }

            // Initialize seat map with venue data
            function initializeSeatMapWithVenue(eventId, venueId, modal, loader, content) {
                console.log('Initializing seat map with venue:', venueId);

                // Check if required classes are available
                if (typeof hope_ajax === 'undefined') {
                    console.error('hope_ajax not defined');
                    showSeatMapError('AJAX configuration not loaded', content, loader);
                    return;
                }

                if (typeof HOPESeatMap === 'undefined') {
                    console.error('HOPESeatMap class not available');
                    showSeatMapError('Seat map system not loaded', content, loader);
                    return;
                }

                // Hide loading, show content
                loader.hide();
                content.show();

                try {
                    // Create admin-specific AJAX configuration
                    const adminAjax = {
                        ajax_url: hope_ajax.ajax_url,
                        nonce: hope_ajax.nonce,
                        product_id: eventId,
                        event_id: eventId, // Also send as event_id for compatibility
                        venue_id: venueId,
                        session_id: 'admin_' + Date.now(),
                        admin_mode: true,
                        is_mobile: false
                    };

                    console.log('Creating admin seat map with config:', adminAjax);

                    // Set the global hope_ajax for the seat map to use
                    window.hope_ajax = adminAjax;

                    // Create new seat map instance
                    window.adminSeatMap = new HOPESeatMap();

                    // Override the AJAX config for admin use
                    window.adminSeatMap.ajax = adminAjax;

                    // Set admin-specific container IDs
                    window.adminSeatMap.containerId = 'admin-seat-map';
                    window.adminSeatMap.wrapperId = 'admin-seating-wrapper';
                    window.adminSeatMap.tooltipId = 'admin-tooltip';
                    window.adminSeatMap.isAdminMode = true;

                    // Override seat selection behavior for admin
                    window.adminSeatMap.maxSeats = 999; // Allow selecting many seats
                    window.adminSeatMap.selectedSeats = new Set();

                    // Initialize the map
                    console.log('Calling initializeMap on admin seat map...');
                    window.adminSeatMap.initializeMap();

                    // Add a delay and check if seat data is loading
                    setTimeout(() => {
                        console.log('Checking seat map state after 2 seconds...');
                        console.log('Seat map container:', document.getElementById('admin-seat-map'));
                        console.log('Seat map SVG content:', document.querySelector('#admin-seat-map')?.innerHTML?.length || 0, 'characters');
                        console.log('Admin seat map selectedSeats:', window.adminSeatMap?.selectedSeats?.size || 'not available');

                        // Check if the seat map has the data loading methods
                        console.log('Admin seat map loadRealSeatData method:', typeof window.adminSeatMap?.loadRealSeatData);
                        console.log('Admin seat map realSeatData:', window.adminSeatMap?.realSeatData);

                        // Try to manually trigger seat data loading
                        if (window.adminSeatMap && window.adminSeatMap.loadRealSeatData) {
                            console.log('Manually triggering seat data loading...');
                            window.adminSeatMap.loadRealSeatData()
                                .then(() => {
                                    console.log('Manual seat data loading completed');

                                    // Try to manually trigger rendering
                                    console.log('Checking rendering methods...');
                                    console.log('processRealSeatData method:', typeof window.adminSeatMap?.processRealSeatData);
                                    console.log('renderSeats method:', typeof window.adminSeatMap?.renderSeats);
                                    console.log('createSeatElements method:', typeof window.adminSeatMap?.createSeatElements);

                                    // List all available methods on the seat map object
                                    console.log('Available methods on adminSeatMap:');
                                    const methods = Object.getOwnPropertyNames(Object.getPrototypeOf(window.adminSeatMap))
                                        .filter(name => typeof window.adminSeatMap[name] === 'function');
                                    console.log('Methods:', methods);

                                    // Try to manually trigger seat rendering
                                    if (window.adminSeatMap.processRealSeatData) {
                                        console.log('Manually triggering processRealSeatData...');
                                        window.adminSeatMap.processRealSeatData();
                                    }

                                    // Look for rendering-related methods from the available methods
                                    const renderingMethods = methods.filter(method =>
                                        method.toLowerCase().includes('render') ||
                                        method.toLowerCase().includes('draw') ||
                                        method.toLowerCase().includes('create') ||
                                        method.toLowerCase().includes('build') ||
                                        method.toLowerCase().includes('generate') ||
                                        method.toLowerCase().includes('seat')
                                    );
                                    console.log('Potential rendering methods:', renderingMethods);

                                    // Try calling some key methods that might trigger rendering
                                    const methodsToTry = [
                                        'generateSeatLayout',
                                        'renderLayout',
                                        'createSeatMap',
                                        'buildSeatMap',
                                        'initializeSeats',
                                        'renderSeatMap',
                                        'drawSeatMap',
                                        'createSeats',
                                        'populateSeats'
                                    ];

                                    methodsToTry.forEach(methodName => {
                                        if (window.adminSeatMap[methodName]) {
                                            console.log(`Found ${methodName} method, calling it...`);
                                            try {
                                                window.adminSeatMap[methodName]();
                                            } catch (e) {
                                                console.error(`Error calling ${methodName}:`, e);
                                            }
                                        }
                                    });

                                    // Check data loading status - this is the key issue!
                                    console.log('Data loading status:', window.adminSeatMap.dataLoadingStatus);
                                    console.log('Variation pricing loaded:', window.adminSeatMap.dataLoadingStatus?.variationPricing);
                                    console.log('Real seat data loaded:', window.adminSeatMap.dataLoadingStatus?.realSeatData);

                                    // The issue is that initializeMap waits for BOTH data types
                                    // Let's force the variation pricing to be marked as loaded
                                    if (window.adminSeatMap.dataLoadingStatus) {
                                        console.log('Forcing variation pricing status to true...');
                                        window.adminSeatMap.dataLoadingStatus.variationPricing = true;

                                        // Now try calling initializeMap again
                                        console.log('Calling initializeMap again with both data types ready...');
                                        window.adminSeatMap.initializeMap();
                                    }

                                    // Also directly try generateTheater
                                    if (window.adminSeatMap.generateTheater) {
                                        console.log('Directly calling generateTheater...');
                                        try {
                                            window.adminSeatMap.generateTheater('orchestra');
                                        } catch (e) {
                                            console.error('Error calling generateTheater:', e);
                                        }
                                    }

                                    // Try other creation methods
                                    if (window.adminSeatMap.createRealSeats) {
                                        console.log('Directly calling createRealSeats...');
                                        try {
                                            window.adminSeatMap.createRealSeats();
                                        } catch (e) {
                                            console.error('Error calling createRealSeats:', e);
                                        }
                                    }

                                    // Check what's in the processedSeatData
                                    console.log('Processed seat data:', window.adminSeatMap.processedSeatData);

                                    // Check the SVG container and current floor
                                    console.log('Current floor:', window.adminSeatMap.currentFloor);
                                    console.log('SVG container exists:', !!document.getElementById('admin-seat-map'));

                                    // Try to create a single seat manually to test if seat creation works
                                    if (window.adminSeatMap.createSeat) {
                                        console.log('Testing manual seat creation...');
                                        try {
                                            const svg = document.getElementById('admin-seat-map');
                                            if (svg) {
                                                const testGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                                                testGroup.id = 'test-section';
                                                svg.appendChild(testGroup);
                                                window.adminSeatMap.createSeat(testGroup, 500, 500, 'TEST', 1, 1, 'P1');
                                                console.log('Manual seat creation attempted');
                                            }
                                        } catch (e) {
                                            console.error('Error creating manual seat:', e);
                                        }
                                    }

                                    setTimeout(() => {
                                        console.log('SVG content after manual render:', document.querySelector('#admin-seat-map')?.innerHTML?.length || 0, 'characters');

                                        // Check if any seat elements exist
                                        const seatElements = document.querySelectorAll('#admin-seat-map .seat');
                                        console.log('Number of seat elements in DOM:', seatElements.length);

                                        // Log a sample of the SVG content to see what's there
                                        const svgContent = document.querySelector('#admin-seat-map')?.innerHTML || '';
                                        console.log('SVG content sample:', svgContent.substring(0, 200) + '...');
                                    }, 1000);
                                })
                                .catch(err => {
                                    console.error('Manual seat data loading failed:', err);
                                });
                        } else {
                            console.log('loadRealSeatData method not available');
                        }
                    }, 2000);

                    // Set up admin-specific event handlers
                    setupAdminSeatMapHandlers();

                    // Monitor for seat selections
                    monitorAdminSeatSelections();

                } catch (error) {
                    console.error('Error creating admin seat map:', error);
                    showSeatMapError('Error initializing seat map: ' + error.message, content, loader);
                }
            }

            // Show error message in seat map content
            function showSeatMapError(message, content, loader) {
                loader.hide();
                content.show().html(`
                    <div style="padding: 40px; text-align: center;">
                        <h3>❌ Error</h3>
                        <p>${message}</p>
                        <p>Please refresh the page and try again.</p>
                        <button type="button" class="button" onclick="closeAdminSeatModal()">Close</button>
                    </div>
                `);
            }

            // Monitor admin seat selections
            function monitorAdminSeatSelections() {
                // Update the seat count in modal footer when selections change
                const updateInterval = setInterval(() => {
                    if (!window.adminSeatMap || !$('#hope-admin-seat-modal').hasClass('show')) {
                        clearInterval(updateInterval);
                        return;
                    }

                    const selectedCount = window.adminSeatMap.selectedSeats ? window.adminSeatMap.selectedSeats.size : 0;
                    updateAdminSeatDisplay(Array.from(window.adminSeatMap.selectedSeats || []));
                }, 500);
            }

            // Get venue ID for a specific event
            function getVenueIdForEvent(eventId) {
                // This should get the venue ID from the event data
                // For now, we'll make an AJAX call to get this information
                let venueId = null;

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    async: false, // Synchronous for simplicity
                    data: {
                        action: 'hope_get_event_venue_blocking',
                        event_id: eventId,
                        nonce: $('#hope_seat_block_admin_nonce').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            venueId = response.data.venue_id;
                        }
                    }
                });

                return venueId;
            }

            // Set up admin seat map interactivity (zoom, drag, floor toggle)
            function setupAdminSeatMapInteractivity() {
                console.log('Setting up admin seat map interactivity...');

                const modal = $('#hope-admin-seat-modal');

                // Set up zoom controls
                setupZoomControls();

                // Set up floor toggle
                setupFloorToggle();

                // Set up drag functionality
                setupDragFunctionality();

                console.log('Admin seat map interactivity set up');
            }

            // Set up zoom controls
            function setupZoomControls() {
                const modal = $('#hope-admin-seat-modal');

                // Find zoom controls
                const zoomInBtn = modal.find('#admin-zoom-in');
                const zoomOutBtn = modal.find('#admin-zoom-out');
                const zoomLabel = modal.find('.zoom-label');

                if (zoomInBtn.length === 0 || zoomOutBtn.length === 0) {
                    console.log('Zoom controls not found, skipping zoom setup');
                    return;
                }

                let currentZoom = 150; // Default zoom level

                zoomInBtn.off('click.adminZoom').on('click.adminZoom', function() {
                    if (currentZoom < 300) {
                        currentZoom += 25;
                        applyZoom(currentZoom);
                        zoomLabel.text(currentZoom + '%');
                    }
                });

                zoomOutBtn.off('click.adminZoom').on('click.adminZoom', function() {
                    if (currentZoom > 50) {
                        currentZoom -= 25;
                        applyZoom(currentZoom);
                        zoomLabel.text(currentZoom + '%');
                    }
                });

                // Set initial zoom
                applyZoom(currentZoom);
                zoomLabel.text(currentZoom + '%');

                console.log('Zoom controls set up');
            }

            // Apply zoom transformation - only to the SVG, not the wrapper
            function applyZoom(zoomLevel) {
                const svg = document.getElementById('admin-seat-map');
                if (svg) {
                    const scale = zoomLevel / 100;
                    svg.style.transform = `scale(${scale})`;
                    svg.style.transformOrigin = 'center center';
                }
            }

            // Set up floor toggle functionality
            function setupFloorToggle() {
                const modal = $('#hope-admin-seat-modal');
                const floorButtons = modal.find('.floor-btn');

                if (floorButtons.length === 0) {
                    console.log('Floor buttons not found, skipping floor toggle setup');
                    return;
                }

                floorButtons.off('click.adminFloor').on('click.adminFloor', function() {
                    const floor = $(this).data('floor');
                    console.log('Switching to floor:', floor);

                    // Update button states
                    floorButtons.removeClass('active');
                    $(this).addClass('active');

                    // Switch floor in seat map
                    if (window.adminSeatMap && window.adminSeatMap.generateTheater) {
                        window.adminSeatMap.currentFloor = floor;

                        // Clear existing seats
                        const svg = document.getElementById('admin-seat-map');
                        if (svg) {
                            // Remove existing section groups
                            const sections = svg.querySelectorAll('g[id^="section-"]');
                            sections.forEach(section => section.remove());

                            // Generate new floor
                            const floorData = window.adminSeatMap.processedSeatData[floor];
                            if (floorData && Object.keys(floorData).length > 0) {
                                window.adminSeatMap.createRealSeats(svg, floor);

                                // Load availability status for the new floor
                                setTimeout(() => {
                                    console.log('Loading availability after floor switch to:', floor);
                                    loadAdminSeatAvailability(window.hope_ajax.product_id);
                                }, 300);
                            } else {
                                console.log('No data for floor:', floor);
                            }
                        }
                    }
                });

                console.log('Floor toggle set up');
            }

            // Set up drag functionality - work with the seating wrapper but maintain SVG-only zoom
            function setupDragFunctionality() {
                const seatingWrapper = document.getElementById('admin-seating-wrapper');
                if (!seatingWrapper) {
                    console.log('Seating wrapper not found, skipping drag setup');
                    return;
                }

                let isDragging = false;
                let startX, startY;
                let currentX = 0, currentY = 0;

                seatingWrapper.addEventListener('mousedown', function(e) {
                    // Don't start dragging if clicking on a seat
                    if (e.target.classList.contains('seat') || e.target.closest('.seat')) {
                        return;
                    }

                    isDragging = true;
                    startX = e.clientX - currentX;
                    startY = e.clientY - currentY;
                    seatingWrapper.style.cursor = 'grabbing';
                    e.preventDefault();
                });

                document.addEventListener('mousemove', function(e) {
                    if (!isDragging) return;

                    currentX = e.clientX - startX;
                    currentY = e.clientY - startY;

                    // Apply translation to the wrapper, but keep zoom on SVG
                    seatingWrapper.style.transform = `translate(${currentX}px, ${currentY}px)`;
                });

                document.addEventListener('mouseup', function() {
                    if (isDragging) {
                        isDragging = false;
                        seatingWrapper.style.cursor = 'grab';
                    }
                });

                // Set initial cursor
                seatingWrapper.style.cursor = 'grab';

                console.log('Drag functionality set up');
            }

            // Set up admin-specific seat map event handlers
            function setupAdminSeatMapHandlers() {
                const modal = $('#hope-admin-seat-modal');

                // Handle modal close
                modal.find('.hope-cancel-btn').off('click.adminModal').on('click.adminModal', function() {
                    closeAdminSeatModal();
                });

                // Handle seat confirmation
                modal.find('.hope-confirm-seats-btn').off('click.adminModal').on('click.adminModal', function() {
                    confirmAdminSeatSelection();
                });

                // Handle overlay click
                modal.find('.hope-modal-overlay').off('click.adminModal').on('click.adminModal', function() {
                    closeAdminSeatModal();
                });

                // Handle escape key
                $(document).off('keydown.adminModal').on('keydown.adminModal', function(e) {
                    if (e.key === 'Escape' && modal.hasClass('show')) {
                        closeAdminSeatModal();
                    }
                });

                // Handle zoom controls if they exist
                modal.find('#admin-zoom-in').off('click.adminModal').on('click.adminModal', function() {
                    if (window.adminSeatMap && window.adminSeatMap.zoomIn) {
                        window.adminSeatMap.zoomIn();
                    }
                });

                modal.find('#admin-zoom-out').off('click.adminModal').on('click.adminModal', function() {
                    if (window.adminSeatMap && window.adminSeatMap.zoomOut) {
                        window.adminSeatMap.zoomOut();
                    }
                });

                // Handle seats toggle
                modal.find('#admin-seats-toggle').off('click.adminModal').on('click.adminModal', function() {
                    toggleAdminSeatsPanel();
                });

                console.log('Admin seat map handlers set up');
            }

            // Toggle the admin seats panel
            function toggleAdminSeatsPanel() {
                const panel = $('#admin-selected-seats-panel');
                const toggle = $('#admin-seats-toggle');

                if (panel.is(':visible')) {
                    panel.hide();
                    toggle.removeClass('active');
                } else {
                    panel.show();
                    toggle.addClass('active');
                }
            }

            // Update admin seat count display
            function updateAdminSeatDisplay(seats) {
                const modal = $('#hope-admin-seat-modal');
                const countDisplay = modal.find('.seat-count-display');
                const confirmBtn = modal.find('.hope-confirm-seats-btn');
                const countBadge = modal.find('.seat-count-badge');

                if (seats && seats.length > 0) {
                    countDisplay.text(seats.length + ' seats selected');
                    countBadge.text(seats.length).show();
                    confirmBtn.prop('disabled', false);
                } else {
                    countDisplay.text('No seats selected');
                    countBadge.hide();
                    confirmBtn.prop('disabled', true);
                }
            }

            // Set up basic modal handlers
            function setupBasicModalHandlers() {
                const modal = $('#hope-admin-seat-modal');

                // Handle overlay click
                modal.find('.hope-modal-overlay').off('click.adminModal').on('click.adminModal', function() {
                    closeAdminSeatModal();
                });

                // Handle escape key
                $(document).off('keydown.adminModal').on('keydown.adminModal', function(e) {
                    if (e.key === 'Escape' && modal.is(':visible')) {
                        closeAdminSeatModal();
                    }
                });

                // Handle cancel button
                modal.find('.hope-cancel-btn').off('click.adminModal').on('click.adminModal', function() {
                    closeAdminSeatModal();
                });
            }


            // Load admin seat availability for status display
            function loadAdminSeatAvailability(eventId) {
                console.log('Loading admin seat availability for event:', eventId);

                return new Promise((resolve, reject) => {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'hope_check_availability',
                            product_id: eventId,
                            session_id: 'admin_' + eventId + '_' + Date.now(),
                            nonce: hope_ajax.nonce
                        },
                        success: function(response) {
                            console.log('Admin availability response:', response);

                            if (response.success && response.data) {
                                // Store availability data for seat map to use
                                window.adminSeatMap.availabilityData = response.data;

                                // Apply seat status to existing seats
                                applyAdminSeatStatus(response.data);

                                resolve(response.data);
                            } else {
                                console.warn('Admin availability check failed:', response);
                                resolve({}); // Don't reject, just use empty data
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Admin availability AJAX error:', error);
                            resolve({}); // Don't reject, just use empty data
                        }
                    });
                });
            }

            // Apply seat status to existing seats in admin mode
            function applyAdminSeatStatus(availabilityData) {
                console.log('Applying admin seat status:', availabilityData);

                if (!availabilityData || typeof availabilityData !== 'object') {
                    console.log('No availability data to apply');
                    return;
                }

                // Update seats in the SVG based on availability data
                const svg = document.getElementById('admin-seat-map');
                if (!svg) {
                    console.log('Admin seat map SVG not found');
                    return;
                }

                // Extract seat arrays from availability data
                const bookedSeats = availabilityData.booked_seats || [];
                const blockedSeats = availabilityData.blocked_seats || [];
                const unavailableSeats = availabilityData.unavailable_seats || {};

                console.log('Booked seats:', bookedSeats);
                console.log('Blocked seats:', blockedSeats);
                console.log('Unavailable seats:', unavailableSeats);

                // Convert unavailable_seats object to array if needed
                const unavailableArray = Array.isArray(unavailableSeats) ? unavailableSeats : Object.values(unavailableSeats);

                // Debug: Show sample seat IDs from data vs DOM
                console.log('Sample booked seats from data:', bookedSeats.slice(0, 5));
                console.log('Sample blocked seats from data:', blockedSeats.slice(0, 5));
                console.log('Sample unavailable seats:', unavailableArray.slice(0, 5));

                // Get all seat elements
                const seatElements = svg.querySelectorAll('.seat');
                console.log('Found', seatElements.length, 'seat elements to update');

                // Debug: Check what attributes the seats actually have
                if (seatElements.length > 0) {
                    const firstSeat = seatElements[0];
                    console.log('First seat element:', firstSeat);
                    console.log('First seat attributes:', {
                        'data-seat-id': firstSeat.getAttribute('data-seat-id'),
                        'data-id': firstSeat.getAttribute('data-id'),
                        'id': firstSeat.getAttribute('id'),
                        'class': firstSeat.getAttribute('class'),
                        'all attributes': Array.from(firstSeat.attributes).map(attr => `${attr.name}="${attr.value}"`).join(', ')
                    });

                    // Show first few seat IDs from DOM
                    const sampleDomSeatIds = Array.from(seatElements).slice(0, 10).map(seat =>
                        seat.getAttribute('data-seat-id') || seat.getAttribute('data-id')
                    ).filter(id => id);
                    console.log('Sample seat IDs from DOM:', sampleDomSeatIds);
                }

                let bookedCount = 0;
                let blockedCount = 0;
                let availableCount = 0;
                let noIdCount = 0;

                seatElements.forEach(seatElement => {
                    // Try both data-seat-id and data-id attributes
                    const seatId = seatElement.getAttribute('data-seat-id') || seatElement.getAttribute('data-id');
                    if (!seatId) {
                        noIdCount++;
                        return;
                    }

                    // Remove existing status classes
                    seatElement.classList.remove('available', 'booked', 'blocked', 'unavailable');

                    // Check seat status based on arrays
                    const isBooked = bookedSeats.includes(seatId) || unavailableArray.includes(seatId);
                    const isBlocked = blockedSeats.includes(seatId);

                    // Debug first few matches
                    if ((bookedCount + blockedCount + availableCount) < 5) {
                        console.log(`Checking seat ${seatId}: booked=${isBooked}, blocked=${isBlocked}`);
                    }

                    if (isBooked) {
                        // Seat is booked/unavailable
                        seatElement.classList.add('booked');
                        seatElement.style.fill = '#dc3545'; // Red for booked
                        seatElement.style.cursor = 'not-allowed';
                        seatElement.style.opacity = '0.8';
                        seatElement.setAttribute('title', `${seatId} - Booked`);

                        // Disable pointer events for booked seats
                        seatElement.style.pointerEvents = 'none';
                        bookedCount++;
                    } else if (isBlocked) {
                        // Seat is blocked
                        seatElement.classList.add('blocked');
                        seatElement.style.fill = '#6c757d'; // Gray for blocked
                        seatElement.style.cursor = 'not-allowed';
                        seatElement.style.opacity = '0.7';
                        seatElement.setAttribute('title', `${seatId} - Blocked`);

                        // Disable pointer events for blocked seats
                        seatElement.style.pointerEvents = 'none';
                        blockedCount++;
                    } else {
                        // Seat is available - preserve original tier color
                        seatElement.classList.add('available');

                        // Get the original fill color (tier color) and preserve it
                        const originalFill = seatElement.getAttribute('fill') || seatElement.style.fill;
                        seatElement.style.cursor = 'pointer';
                        seatElement.style.opacity = '1';
                        seatElement.style.pointerEvents = 'auto';

                        // Get tier info for tooltip
                        const tier = seatElement.getAttribute('data-tier') || 'Standard';
                        seatElement.setAttribute('title', `${seatId} - Available (${tier})`);

                        // Store original color for hover restoration
                        seatElement.setAttribute('data-original-fill', originalFill);

                        // Ensure hover effects work for available seats - brighten the existing color
                        seatElement.addEventListener('mouseenter', function() {
                            if (this.classList.contains('available') && !this.classList.contains('selected')) {
                                // Create a lighter version of the original color for hover
                                const originalColor = this.getAttribute('data-original-fill');
                                this.style.filter = 'brightness(1.2)'; // Brighten the existing color
                                this.style.stroke = '#fff';
                                this.style.strokeWidth = '2';
                            }
                        });

                        seatElement.addEventListener('mouseleave', function() {
                            if (this.classList.contains('available') && !this.classList.contains('selected')) {
                                // Restore original color
                                this.style.filter = '';
                                this.style.stroke = '';
                                this.style.strokeWidth = '';
                            }
                        });
                        availableCount++;
                    }
                });

                console.log(`Seat status applied: ${bookedCount} booked, ${blockedCount} blocked, ${availableCount} available, ${noIdCount} seats without IDs`);

                // Also get blocked seats from our blocking system
                loadBlockedSeatsForAdmin(window.hope_ajax.product_id);
            }

            // Load blocked seats from admin blocking system
            function loadBlockedSeatsForAdmin(eventId) {
                console.log('Loading blocked seats for admin event:', eventId);

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'hope_admin_get_event_seats',
                        event_id: eventId,
                        nonce: $('#hope_seat_block_admin_nonce').val()
                    },
                    success: function(response) {
                        if (response.success && response.data.blocked_seats) {
                            console.log('Admin blocked seats:', response.data.blocked_seats);

                            // Apply blocked status to these seats
                            const svg = document.getElementById('admin-seat-map');
                            if (svg) {
                                response.data.blocked_seats.forEach(seatId => {
                                    // Try both data-seat-id and data-id selectors
                                    const seatElement = svg.querySelector(`.seat[data-seat-id="${seatId}"]`) ||
                                                       svg.querySelector(`.seat[data-id="${seatId}"]`);
                                    if (seatElement) {
                                        seatElement.classList.remove('available', 'booked');
                                        seatElement.classList.add('blocked');
                                        seatElement.style.fill = '#6c757d'; // Gray for blocked
                                        seatElement.style.cursor = 'not-allowed';
                                        seatElement.style.opacity = '0.7';
                                        seatElement.style.pointerEvents = 'none';
                                        seatElement.setAttribute('title', `${seatId} - Blocked (Admin)`);

                                        // Remove any event listeners
                                        seatElement.removeEventListener('mouseenter', null);
                                        seatElement.removeEventListener('mouseleave', null);
                                    }
                                });
                            }
                        }
                    },
                    error: function() {
                        console.log('Error loading admin blocked seats');
                    }
                });
            }

            // Close the admin seat modal - MUST be global for inline onclick handlers
            window.closeAdminSeatModal = function() {
                console.log('Closing admin seat modal');
                const modal = jQuery('#hope-admin-seat-modal');

                if (modal.length === 0) {
                    console.log('Modal not found for closing');
                    return;
                }

                modal.removeClass('show').attr('aria-hidden', 'true');
                jQuery('body').removeClass('hope-modal-open');

                // Clean up event handlers
                jQuery(document).off('keydown.adminModal');
                modal.find('.hope-modal-overlay').off('click.adminModal');
                modal.find('.hope-cancel-btn').off('click.adminModal');

                // CRITICAL: Clean up seat map state to prevent stale data on next open
                if (window.adminSeatMap) {
                    // Clear selected seats
                    if (window.adminSeatMap.selectedSeats) {
                        window.adminSeatMap.selectedSeats.clear();
                    }
                    // Clear any intervals
                    if (window.adminSeatMap.availabilityInterval) {
                        clearInterval(window.adminSeatMap.availabilityInterval);
                    }
                    // Destroy the instance
                    window.adminSeatMap = null;
                }

                // Clear simple selected seats too
                if (window.simpleSelectedSeats) {
                    window.simpleSelectedSeats.clear();
                }

                // Clear SVG content to ensure fresh render on next open
                const svg = document.getElementById('admin-seat-map');
                if (svg) {
                    svg.innerHTML = '';
                }

                console.log('Admin seat modal closed and state cleaned up');
            };

            // Confirm admin seat selection and transfer to blocking form
            function confirmAdminSeatSelection() {
                // Check for simple seat selection first
                window.simpleSelectedSeats = window.simpleSelectedSeats || new Set();

                if (window.simpleSelectedSeats.size === 0) {
                    // Fallback to complex seat map if available
                    if (!window.adminSeatMap || !window.adminSeatMap.selectedSeats || window.adminSeatMap.selectedSeats.size === 0) {
                        alert('Please select at least one seat to block.');
                        return;
                    }
                    // Use complex seat map selection
                    const seats = Array.from(window.adminSeatMap.selectedSeats);
                    console.log('Admin confirmed complex seat selection:', seats);

                    selectedSeats = seats;
                } else {
                    // Use simple seat selection
                    const seats = Array.from(window.simpleSelectedSeats);
                    console.log('Admin confirmed simple seat selection:', seats);

                    selectedSeats = seats;
                }

                // Update the main form
                updateSelectionDisplay();

                // Close the modal
                closeAdminSeatModal();

                // Show success message
                const message = `Selected ${selectedSeats.length} seat(s) for blocking:\n${selectedSeats.join(', ')}`;
                alert(message);
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
                        html += '<div class="hope-block-content">';
                        html += '<span class="hope-block-type" style="background: ' + typeInfo.color + '">' + typeInfo.label + '</span>';
                        html += '<strong style="margin-left: 10px;">' + block.seat_ids.length + ' seats:</strong> ' + block.seat_ids.join(', ');
                        if (block.block_reason) {
                            html += '<p style="margin: 10px 0 5px 0;"><strong>Reason:</strong> ' + block.block_reason + '</p>';
                        }
                        // Get username for display
                        var createdBy = block.blocked_by_username || 'User ID ' + block.blocked_by;
                        html += '<p style="margin: 5px 0 0 0;"><small>Created: ' + block.created_at + ' by ' + createdBy + '</small></p>';
                        html += '</div>';
                        html += '<button class="hope-remove-block-btn" data-block-id="' + block.id + '">Remove Block</button>';
                        html += '</div>';
                    });
                }
                $('#hope-current-blocks').html(html);
            }
            

            // Handle seat selection toggle
            function toggleSeatSelection(seatId, seatElement) {
                var index = selectedSeats.indexOf(seatId);
                if (index > -1) {
                    // Deselect
                    selectedSeats.splice(index, 1);
                    seatElement.setAttribute('class', 'hope-admin-seat available');
                } else {
                    // Select
                    selectedSeats.push(seatId);
                    seatElement.setAttribute('class', 'hope-admin-seat selected');
                }
                updateSelectionDisplay();
            }
            
            
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
     * AJAX handler for getting event venue ID
     */
    public function ajax_get_event_venue() {
        error_log('HOPE BLOCKING: ajax_get_event_venue called');

        // Security checks
        if (!current_user_can('manage_woocommerce')) {
            error_log('HOPE BLOCKING: User does not have manage_woocommerce capability');
            wp_send_json_error(array('error' => 'Access denied - insufficient permissions'));
        }

        if (!wp_verify_nonce($_POST['nonce'], 'hope_seat_block_admin_action')) {
            error_log('HOPE BLOCKING: Nonce verification failed');
            wp_send_json_error(array('error' => 'Access denied - nonce failed'));
        }

        $event_id = intval($_POST['event_id']);
        error_log('HOPE BLOCKING: event_id = ' . $event_id);

        // Get the venue/pricing map ID for this product
        $venue_id = get_post_meta($event_id, '_hope_seating_venue_id', true);
        error_log('HOPE BLOCKING: venue_id from meta = ' . var_export($venue_id, true));

        if (!$venue_id) {
            $product = wc_get_product($event_id);
            $product_name = $product ? $product->get_name() : "Product #{$event_id}";
            error_log('HOPE BLOCKING: No venue_id found for product ' . $product_name);
            wp_send_json_error(array(
                'error' => "No seat map configured for \"{$product_name}\". Please edit the product and select a seat map under the HOPE Theater Seating tab."
            ));
        }

        error_log('HOPE BLOCKING: Success - venue_id = ' . $venue_id);

        wp_send_json_success(array(
            'venue_id' => $venue_id,
            'event_id' => $event_id
        ));
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
        
        // Get the pricing map ID for this event/product
        $pricing_map_id = get_post_meta($event_id, '_hope_seating_venue_id', true);
        if (!$pricing_map_id) {
            return array(); // No seating configured for this event
        }
        
        // Use the same system as the frontend to get seats with pricing
        if (class_exists('HOPE_Pricing_Maps_Manager')) {
            $pricing_manager = new HOPE_Pricing_Maps_Manager();
            $seats_with_pricing = $pricing_manager->get_seats_with_pricing($pricing_map_id);
            
            if (!empty($seats_with_pricing)) {
                // Convert to the format expected by the blocking interface
                $seats = array();
                foreach ($seats_with_pricing as $seat) {
                    // $seat is an object with properties seat_id, section, row_number, seat_number, pricing_tier
                    $seats[] = array(
                        'seat_id' => $seat->seat_id,
                        'section' => $seat->section,
                        'row_number' => $seat->row_number,
                        'seat_number' => $seat->seat_number,
                        'pricing_tier' => $seat->pricing_tier,
                        'status' => 'available' // Will be updated below
                    );
                }
            } else {
                return array(); // No seats found
            }
        } else {
            return array(); // Pricing manager not available
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
        // Only load on our admin page - now under Hope Seating menu
        if ($hook !== 'hope-seating_page_hope-seat-blocking') {
            return;
        }

        // Enqueue frontend scripts for seat map functionality
        $plugin_url = plugin_dir_url(dirname(__FILE__));

        // Enqueue the seat map script
        if (file_exists(dirname(__FILE__) . '/../assets/js/seat-map.js')) {
            wp_enqueue_script('hope-seat-map', $plugin_url . 'assets/js/seat-map.js', array('jquery'), HOPE_SEATING_VERSION, true);
        }

        // Enqueue frontend styles for modal appearance
        if (file_exists(dirname(__FILE__) . '/../assets/css/frontend.css')) {
            wp_enqueue_style('hope-frontend-style', $plugin_url . 'assets/css/frontend.css', array(), HOPE_SEATING_VERSION);
        }

        // Create proper localization for admin seat map
        $current_user = wp_get_current_user();
        wp_localize_script('hope-seat-map', 'hope_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hope_seating_nonce'),
            'session_id' => 'admin_' . $current_user->ID . '_' . time(),
            'admin_mode' => true,
            'is_mobile' => false, // Admin interface is desktop only
            'messages' => array(
                'max_seats' => 'Maximum seats selection reached',
                'add_to_cart_error' => 'Error selecting seats',
                'session_expired' => 'Session expired, please refresh'
            )
        ));

        // Add admin-specific inline styles for modal
        wp_add_inline_style('hope-frontend-style', '
            /* Admin modal base styling */
            #hope-admin-seat-modal {
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                width: 100% !important;
                height: 100% !important;
                z-index: 200000 !important;
                background: rgba(0, 0, 0, 0.8) !important;
                display: none;
            }

            /* Ensure seat map container is properly sized */
            #hope-admin-seat-modal #admin-seat-map {
                width: 100% !important;
                height: 500px !important;
                border: 2px solid #ccc !important;
                background: #f9f9f9 !important;
            }

            #hope-admin-seat-modal .theater-container {
                width: 100% !important;
                height: 100% !important;
            }

            #hope-admin-seat-modal .seating-container {
                width: 100% !important;
                height: 400px !important;
                position: relative !important;
                overflow: hidden !important; /* CRITICAL: Clips SVG to prevent dragging over controls */
            }

            #hope-admin-seat-modal .seating-wrapper {
                width: 100% !important;
                height: 100% !important;
                position: relative !important;
                z-index: 1 !important; /* Below header/controls */
            }

            /* Admin modal controls styling */
            #hope-admin-seat-modal .header {
                background: #f8f9fa;
                border-bottom: 1px solid #dee2e6;
                padding: 15px 20px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                position: relative;
                z-index: 5; /* Above seating wrapper */
            }

            #hope-admin-seat-modal .floor-selector {
                display: flex;
                gap: 10px;
            }

            #hope-admin-seat-modal .floor-btn {
                padding: 8px 16px;
                border: 1px solid #ccc;
                background: white;
                border-radius: 4px;
                cursor: pointer;
                transition: all 0.2s;
            }

            #hope-admin-seat-modal .floor-btn:hover {
                background: #e9ecef;
            }

            #hope-admin-seat-modal .floor-btn.active {
                background: #007bff;
                color: white;
                border-color: #007bff;
            }

            #hope-admin-seat-modal .zoom-controls {
                position: absolute;
                top: 10px;
                right: 10px;
                background: rgba(255, 255, 255, 0.9);
                border-radius: 6px;
                padding: 8px;
                display: flex;
                align-items: center;
                gap: 8px;
                z-index: 10;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }

            #hope-admin-seat-modal .zoom-btn {
                width: 32px;
                height: 32px;
                border: 1px solid #ccc;
                background: white;
                border-radius: 4px;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 18px;
                font-weight: bold;
                transition: all 0.2s;
            }

            #hope-admin-seat-modal .zoom-btn:hover {
                background: #e9ecef;
                border-color: #007bff;
            }

            #hope-admin-seat-modal .zoom-label {
                font-size: 14px;
                font-weight: 500;
                min-width: 40px;
                text-align: center;
            }

            #hope-admin-seat-modal.show {
                display: block !important;
            }

            #hope-admin-seat-modal .hope-modal-overlay {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.8);
            }

            #hope-admin-seat-modal .hope-modal-content {
                position: relative;
                background: white;
                max-width: 1200px;
                width: 95%;
                max-height: 90vh;
                margin: 20px auto;
                border-radius: 8px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.3);
                overflow: hidden;
                z-index: 200001;
            }

            #hope-admin-seat-modal .hope-modal-body {
                padding: 0;
                max-height: calc(90vh - 140px);
                overflow-y: auto;
            }

            #hope-admin-seat-modal .hope-modal-footer {
                background: #f8f9fa;
                border-top: 1px solid #dee2e6;
                padding: 15px 20px;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            #hope-admin-seat-modal .hope-confirm-seats-btn:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }

            /* Loading indicator */
            #hope-admin-seat-modal .hope-loading-indicator {
                text-align: center;
                padding: 40px;
            }

            #hope-admin-seat-modal .spinner {
                display: inline-block;
                width: 20px;
                height: 20px;
                border: 2px solid #f3f3f3;
                border-top: 2px solid #0073aa;
                border-radius: 50%;
                animation: spin 1s linear infinite;
            }

            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }

            /* Admin seat styling */
            #hope-admin-seat-modal .seat.blocked {
                fill: #6c757d !important;
                opacity: 0.7;
                cursor: not-allowed;
            }

            #hope-admin-seat-modal .seat.booked {
                fill: #dc3545 !important;
                opacity: 0.8;
                cursor: not-allowed;
            }

            #hope-admin-seat-modal .seat.available {
                cursor: pointer;
            }

            #hope-admin-seat-modal .seat.selected {
                fill: #28a745 !important;
                stroke: #fff;
                stroke-width: 2;
            }

            /* Prevent body scroll when modal is open */
            body.hope-modal-open {
                overflow: hidden;
            }
        ');
    }

}