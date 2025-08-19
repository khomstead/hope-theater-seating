<?php
/**
 * Modal Handler Class
 * Manages the seat selection modal interface
 */

if (!defined('ABSPATH')) {
    exit;
}

class HOPE_Modal_Handler {
    
    public function __construct() {
        add_action('wp_footer', array($this, 'render_modal_wrapper'));
        // Seat selection button now handled by WooCommerce integration
    }
    
    /**
     * Render the seat selection button on product page
     */
    public function render_seat_selection_button() {
        global $product;
        
        if (!$product) return;
        
        $seating_enabled = get_post_meta($product->get_id(), '_hope_seating_enabled', true);
        $venue_id = get_post_meta($product->get_id(), '_hope_seating_venue_id', true);
        
        if ($seating_enabled !== 'yes' || !$venue_id) return;
        
        $product_id = $product->get_id();
        $event_date = get_post_meta($product_id, '_event_date', true);
        
        if (!$venue_id) {
            return;
        }
        ?>
        <div class="hope-seat-selection-wrapper">
            <button type="button" 
                    class="hope-select-seats-btn button alt" 
                    id="hope-select-seats"
                    data-product-id="<?php echo esc_attr($product_id); ?>"
                    data-venue-id="<?php echo esc_attr($venue_id); ?>"
                    data-event-date="<?php echo esc_attr($event_date); ?>">
                <span class="btn-text"><?php _e('Select Seats', 'hope-theater-seating'); ?></span>
                <span class="btn-icon">🎭</span>
            </button>
            <div class="hope-selected-seats-summary" style="display: none;">
                <h4><?php _e('Selected Seats:', 'hope-theater-seating'); ?></h4>
                <div class="selected-seats-list"></div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render the modal wrapper that will contain the seat map
     */
    public function render_modal_wrapper() {
        global $product;
        
        if (!$product || !is_product()) return;
        
        $seating_enabled = get_post_meta($product->get_id(), '_hope_seating_enabled', true);
        $venue_id = get_post_meta($product->get_id(), '_hope_seating_venue_id', true);
        
        if ($seating_enabled !== 'yes' || !$venue_id) return;
        
        // Get venue details
        global $wpdb;
        $venue = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hope_seating_venues WHERE id = %d",
            $venue_id
        ));
        
        if (!$venue) {
            return;
        }
        ?>
        <div id="hope-seat-modal" class="hope-modal" style="display: none;" aria-hidden="true" role="dialog">
            <div class="hope-modal-overlay"></div>
            <div class="hope-modal-content">
                <div class="hope-modal-header">
                    <h2><?php echo esc_html($venue->name); ?></h2>
                    <button type="button" class="hope-modal-close" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                
                <div class="hope-modal-body">
                    <!-- Loading indicator -->
                    <div class="hope-loading-indicator">
                        <div class="spinner"></div>
                        <p><?php _e('Loading seat map...', 'hope-theater-seating'); ?></p>
                    </div>
                    
                    <!-- Seat map container -->
                    <div id="hope-seat-map-container" style="display: none;">
                        <!-- The actual seat map from our mockup will be inserted here -->
                        <?php $this->render_seat_map_html(); ?>
                    </div>
                </div>
                
                <div class="hope-modal-footer">
                    <div class="hope-modal-info">
                        <span class="seat-count-display">
                            <?php _e('No seats selected', 'hope-theater-seating'); ?>
                        </span>
                        <span class="total-price-display">
                            <?php _e('Total: $0', 'hope-theater-seating'); ?>
                        </span>
                    </div>
                    <div class="hope-modal-actions">
                        <button type="button" class="hope-cancel-btn button">
                            <?php _e('Cancel', 'hope-theater-seating'); ?>
                        </button>
                        <button type="button" class="hope-add-to-cart-btn button alt" disabled>
                            <?php _e('Add to Cart', 'hope-theater-seating'); ?>
                            <span class="seat-count-badge" style="display: none;">0</span>
                        </button>
                    </div>
                </div>
                
                <!-- Session timer -->
                <div class="hope-session-timer" style="display: none;">
                    <span class="timer-icon">⏱️</span>
                    <span class="timer-text">
                        <?php _e('Seats held for:', 'hope-theater-seating'); ?>
                        <span class="timer-countdown">10:00</span>
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Mobile-specific overlay for better UX -->
        <?php 
        $mobile_detector = new HOPE_Mobile_Detector();
        if ($mobile_detector->is_mobile()): 
        ?>
        <div class="hope-mobile-overlay" style="display: none;">
            <div class="hope-mobile-header">
                <button class="hope-mobile-back">← <?php _e('Back', 'hope-theater-seating'); ?></button>
                <h3><?php _e('Select Seats', 'hope-theater-seating'); ?></h3>
                <button class="hope-mobile-done"><?php _e('Done', 'hope-theater-seating'); ?></button>
            </div>
        </div>
        <?php endif; ?>
        <?php
    }
    
    /**
     * Render the seat map HTML structure
     */
    private function render_seat_map_html() {
        ?>
        <div class="theater-container">
            <div class="header">
                <h1>HOPE Theater - Main Stage</h1>
                <div class="floor-selector">
                    <button class="floor-btn active" data-floor="orchestra">Orchestra</button>
                    <button class="floor-btn" data-floor="balcony">Balcony</button>
                </div>
            </div>
            
            <div class="seating-container">
                <div class="zoom-controls">
                    <button class="zoom-btn" id="zoom-out">−</button>
                    <span class="zoom-label">100%</span>
                    <button class="zoom-btn" id="zoom-in">+</button>
                </div>
                
                <div class="seating-wrapper" id="seating-wrapper">
                    <svg id="seat-map" viewBox="0 50 1200 600" preserveAspectRatio="xMidYMid meet">
                        <!-- Seats will be generated dynamically via JavaScript -->
                    </svg>
                </div>
            </div>
            
            <div class="legend">
                <div class="legend-item">
                    <div class="legend-color" style="background: #9b59b6;"></div>
                    <span>P1 - Premium ($150)</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #3498db;"></div>
                    <span>P2 - Standard ($120)</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #17a2b8;"></div>
                    <span>P3 - Value ($90)</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #e67e22;"></div>
                    <span>AA - Accessible ($120)</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #6c757d;"></div>
                    <span>Unavailable</span>
                </div>
            </div>
            
            <div class="selected-seats-panel">
                <h3 class="selected-seats-title">Selected Seats</h3>
                <div class="seats-list" id="selected-seats-list">
                    <span class="empty-message">No seats selected</span>
                </div>
                <div class="total-price">Total: $0</div>
            </div>
        </div>
        
        <div class="tooltip" id="tooltip"></div>
        <?php
    }
}