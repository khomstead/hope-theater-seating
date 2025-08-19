<?php
/**
 * HOPE Theater Seating - WooCommerce/FooEvents Integration (FIXED)
 * Fixes the 500 error on Setup Pricing Variations button
 */

if (!defined('ABSPATH')) {
    exit;
}

class HOPE_Seating_Integration {
    
    private static $instance = null;
    
    private $pricing_tiers = array(
        'P1' => array(
            'label' => 'VIP',
            'default_price' => 50,
            'color' => '#9b59b6'
        ),
        'P2' => array(
            'label' => 'Premium', 
            'default_price' => 35,
            'color' => '#3498db'
        ),
        'P3' => array(
            'label' => 'General',
            'default_price' => 25, 
            'color' => '#27ae60'
        ),
        'AA' => array(
            'label' => 'Accessible',
            'default_price' => 25,
            'color' => '#e67e22'
        )
    );
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        // WooCommerce product hooks
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_venue_selection'), 15);
        add_action('woocommerce_process_product_meta', array($this, 'save_venue_selection'), 10);
        
        // AJAX handlers - MUST be registered for admin
        add_action('wp_ajax_hope_setup_venue_variations', array($this, 'ajax_setup_venue_variations'));
    }
    
    /**
     * Add venue selection to product general tab
     */
    public function add_venue_selection() {
        global $post;
        
        // Get venues from database
        global $wpdb;
        $venues = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}hope_seating_venues ORDER BY name");
        
        $selected_venue = get_post_meta($post->ID, '_hope_venue_id', true);
        $product_type = get_post_meta($post->ID, 'product-type', true);
        
        echo '<div class="options_group hope_seating_venue">';
        
        // Venue selection dropdown
        $venue_options = array('' => __('Select a venue', 'hope-seating'));
        foreach ($venues as $venue) {
            $venue_options[$venue->id] = $venue->name . ' (' . $venue->total_seats . ' seats)';
        }
        
        woocommerce_wp_select(array(
            'id' => '_hope_venue_id',
            'label' => __('Theater Venue', 'hope-seating'),
            'options' => $venue_options,
            'desc_tip' => true,
            'description' => __('Select the theater venue for this event', 'hope-seating'),
            'value' => $selected_venue
        ));
        
        // Setup variations button - only show for variable products
        if ($selected_venue) {
            $product = wc_get_product($post->ID);
            
            echo '<p class="form-field">';
            
            if ($product && $product->is_type('variable')) {
                echo '<button type="button" class="button button-primary" id="hope_setup_variations" ';
                echo 'data-venue-id="' . esc_attr($selected_venue) . '" ';
                echo 'data-product-id="' . esc_attr($post->ID) . '">';
                echo __('Setup Pricing Variations', 'hope-seating');
                echo '</button>';
                echo '<span class="spinner" style="float: none;"></span>';
                echo '<span id="hope-setup-message" style="margin-left: 10px; color: green; display: none;"></span>';
            } else {
                echo '<strong>Note:</strong> Change product type to "Variable product" to enable seating variations.';
            }
            
            echo '</p>';
        }
        
        echo '</div>';
        
        // Add JavaScript for the button
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
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
    
    /**
     * Save venue selection
     */
    public function save_venue_selection($post_id) {
        if (isset($_POST['_hope_venue_id'])) {
            update_post_meta($post_id, '_hope_venue_id', sanitize_text_field($_POST['_hope_venue_id']));
        }
    }
    
    /**
     * AJAX handler to setup venue variations - FIXED VERSION
     */
    public function ajax_setup_venue_variations() {
        // Check nonce for security
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'hope_seating_admin')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check permissions
        if (!current_user_can('edit_products')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $venue_id = isset($_POST['venue_id']) ? intval($_POST['venue_id']) : 0;
        
        if (!$product_id || !$venue_id) {
            wp_send_json_error(array('message' => 'Invalid product or venue'));
            return;
        }
        
        // Get product and ensure it's variable type
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(array('message' => 'Product not found'));
            return;
        }
        
        if (!$product->is_type('variable')) {
            wp_send_json_error(array('message' => 'Product must be Variable type. Please change the product type to Variable and save.'));
            return;
        }
        
        // Get venue seat data from database
        global $wpdb;
        $seats = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hope_seating_seat_maps WHERE venue_id = %d",
            $venue_id
        ));
        
        if (empty($seats)) {
            wp_send_json_error(array('message' => 'No seats found for this venue. Please configure venue seats first.'));
            return;
        }
        
        // Count seats by pricing tier
        $tier_counts = array();
        foreach ($seats as $seat) {
            $tier = $seat->pricing_tier;
            if (!isset($tier_counts[$tier])) {
                $tier_counts[$tier] = 0;
            }
            $tier_counts[$tier]++;
        }
        
        // Create the attribute for variations
        $attribute_name = 'Seating Tier';
        $attribute_slug = 'seating-tier';
        
        // Get existing attributes
        $attributes = $product->get_attributes();
        
        // Create or update the seating tier attribute
        $attribute = new WC_Product_Attribute();
        $attribute->set_name($attribute_name);
        $attribute->set_options(array_keys($this->pricing_tiers));
        $attribute->set_position(0);
        $attribute->set_visible(true);
        $attribute->set_variation(true);
        
        $attributes[$attribute_slug] = $attribute;
        $product->set_attributes($attributes);
        $product->save();
        
        // Create variations for each pricing tier
        $created_count = 0;
        $data_store = $product->get_data_store();
        
        foreach ($tier_counts as $tier => $count) {
            if ($count > 0 && isset($this->pricing_tiers[$tier])) {
                // Check if variation already exists
                $variation_id = $this->find_matching_variation($product_id, $tier);
                
                if (!$variation_id) {
                    // Create new variation
                    $variation = new WC_Product_Variation();
                    $variation->set_parent_id($product_id);
                    $variation->set_attributes(array('seating-tier' => $tier));
                    
                    // Set price
                    $price = $this->pricing_tiers[$tier]['default_price'];
                    $variation->set_regular_price($price);
                    $variation->set_price($price);
                    
                    // Set stock
                    $variation->set_manage_stock(true);
                    $variation->set_stock_quantity($count);
                    $variation->set_stock_status('instock');
                    
                    // Set name
                    $tier_label = $this->pricing_tiers[$tier]['label'];
                    $variation->set_name($product->get_name() . ' - ' . $tier_label);
                    
                    // Save variation
                    $variation->save();
                    $created_count++;
                } else {
                    // Update existing variation stock
                    $variation = wc_get_product($variation_id);
                    if ($variation) {
                        $variation->set_stock_quantity($count);
                        $variation->save();
                    }
                }
            }
        }
        
        // Clear transients
        WC_Product_Variable::sync($product_id);
        wc_delete_product_transients($product_id);
        
        $message = $created_count > 0 
            ? sprintf('Successfully created %d variations with seat inventory.', $created_count)
            : 'Variations updated with current seat inventory.';
            
        wp_send_json_success(array('message' => $message));
    }
    
    /**
     * Find existing variation for a pricing tier
     */
    private function find_matching_variation($product_id, $tier) {
        $product = wc_get_product($product_id);
        if (!$product || !$product->is_type('variable')) {
            return false;
        }
        
        $variations = $product->get_available_variations();
        
        foreach ($variations as $variation) {
            $attributes = $variation['attributes'];
            if (isset($attributes['attribute_seating-tier']) && $attributes['attribute_seating-tier'] === $tier) {
                return $variation['variation_id'];
            }
        }
        
        return false;
    }
}

// Initialize the integration class
add_action('init', function() {
    if (class_exists('WooCommerce')) {
        HOPE_Seating_Integration::get_instance();
    }
});