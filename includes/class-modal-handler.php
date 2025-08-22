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
        
        // Use new pricing maps architecture
        $pricing_map_id = $venue_id; // Actually pricing map ID now
        
        if (!class_exists('HOPE_Pricing_Maps_Manager')) {
            return;
        }
        
        $pricing_manager = new HOPE_Pricing_Maps_Manager();
        $pricing_maps = $pricing_manager->get_pricing_maps();
        
        // Find the pricing map
        $pricing_map = null;
        foreach ($pricing_maps as $map) {
            if ($map->id == $pricing_map_id) {
                $pricing_map = $map;
                break;
            }
        }
        
        if (!$pricing_map) {
            return;
        }
        ?>
        <div id="hope-seat-modal" class="hope-modal" style="display: none;" aria-hidden="true" role="dialog">
            <div class="hope-modal-overlay"></div>
            <div class="hope-modal-content">
                
                <div class="hope-modal-body">
                    <!-- Loading indicator -->
                    <div class="hope-loading-indicator">
                        <div class="spinner"></div>
                        <p><?php _e('Loading seat map...', 'hope-theater-seating'); ?></p>
                    </div>
                    
                    <!-- Seat map container -->
                    <div id="hope-seat-map-container" style="display: none;">
                        <div class="theater-container">
                            <div class="header">
                                <div class="header-content">
                                    <button class="legend-toggle" id="legend-toggle" title="Show pricing legend">
                                        <span>?</span>
                                    </button>
                                    <div class="floor-selector">
                                        <button class="floor-btn active" data-floor="orchestra">Orchestra</button>
                                        <button class="floor-btn" data-floor="balcony">Balcony</button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="seating-container">
                                <div class="zoom-controls">
                                    <button class="zoom-btn" id="zoom-out">−</button>
                                    <span class="zoom-label">100%</span>
                                    <button class="zoom-btn" id="zoom-in">+</button>
                                </div>
                                
                                <div class="seating-wrapper" id="seating-wrapper">
                                    <svg id="seat-map" viewBox="-100 0 1400 1200" preserveAspectRatio="xMidYMid meet">
                                        <!-- Seats will be generated dynamically via JavaScript -->
                                    </svg>
                                </div>
                            </div>
                            
                            <div class="legend" id="pricing-legend" style="display: none;">
                                <div class="legend-content">
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
                            </div>
                            
                            <div class="selected-seats-panel" id="selected-seats-panel" style="display: none;">
                                <div class="seats-list" id="selected-seats-list">
                                    <span class="empty-message">No seats selected</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="tooltip" id="tooltip"></div>
                        
                        <!-- Navigation hint (desktop only) -->
                        <div class="navigation-hint desktop-only" id="navigation-hint">
                            <div class="hint-content">
                                <span class="hint-icon">👆</span>
                                <span class="hint-text">Click and drag to explore the theater</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="hope-modal-footer">
                    <div class="hope-modal-info">
                        <button class="seats-toggle" id="seats-toggle" title="Show selected seats">
                            <span class="seat-count-display">No seats selected</span>
                            <span class="toggle-icon">▲</span>
                        </button>
                        <span class="total-price-display">
                            <?php _e('Total: $0', 'hope-theater-seating'); ?>
                        </span>
                    </div>
                    
                    <!-- Session timer moved to footer -->
                    <div class="hope-session-timer" style="display: none;">
                        <span class="timer-icon">⏱️</span>
                        <span class="timer-text">
                            <?php _e('Seats held for:', 'hope-theater-seating'); ?>
                            <span class="timer-countdown">10:00</span>
                        </span>
                    </div>
                    
                    <div class="hope-modal-actions">
                        <button type="button" class="hope-cancel-btn button">
                            <?php _e('Cancel', 'hope-theater-seating'); ?>
                        </button>
                        <button type="button" class="hope-confirm-seats-btn button alt" disabled>
                            <?php _e('Confirm Seats', 'hope-theater-seating'); ?>
                            <span class="seat-count-badge" style="display: none;">0</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        </div>
        
        <!-- Mobile-specific overlay for better UX -->
        <?php 
        if (class_exists('HOPE_Mobile_Detector')) {
            $mobile_detector = HOPE_Mobile_Detector::get_instance();
            if ($mobile_detector->is_mobile()): 
        ?>
        <div class="hope-mobile-overlay" style="display: none;">
            <div class="hope-mobile-header">
                <button class="hope-mobile-back">← <?php _e('Back', 'hope-theater-seating'); ?></button>
                <h3><?php _e('Select Seats', 'hope-theater-seating'); ?></h3>
                <button class="hope-mobile-done"><?php _e('Done', 'hope-theater-seating'); ?></button>
            </div>
        </div>
        <?php 
            endif;
        }
        ?>
        <?php
    }
}