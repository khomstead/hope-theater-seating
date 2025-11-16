<?php
/**
 * FooEvents Seating Compatibility Layer
 * 
 * This class emulates the FooEvents Seating plugin interface
 * so that FooEvents can properly handle our custom seating data.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Compatibility class that emulates Fooevents_Seating
 */
class Fooevents_Seating {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Compatibility layer is ready
    }
    
    /**
     * Capture seating options during checkout (called by FooEvents)
     * This is where we provide our HOPE seat data in FooEvents format
     */
    public function capture_seating_options($product_id, $x, $y, $ticket_data) {
        error_log("HOPE: *** Fooevents_Seating::capture_seating_options called ***");
        error_log("HOPE: Parameters - Product: {$product_id}, X: {$x}, Y: {$y}");
        error_log("HOPE: Ticket data: " . print_r($ticket_data, true));
        
        global $hope_woo_integration_instance;
        
        if (!$hope_woo_integration_instance) {
            error_log("HOPE: No integration instance available for capture_seating_options");
            return array();
        }
        
        $result = $hope_woo_integration_instance->capture_hope_seating_options($product_id, $x, $y, $ticket_data);
        error_log("HOPE: Returning seating options: " . print_r($result, true));
        
        return $result;
    }
    
    /**
     * Process captured seating options (called when ticket posts are created)
     */
    public function process_capture_seating_options($post_id, $seating_fields) {
        error_log("HOPE: *** process_capture_seating_options called for ticket {$post_id} ***");
        error_log("HOPE: Seating fields to process: " . print_r($seating_fields, true));
        
        if (empty($seating_fields)) {
            error_log("HOPE: No seating fields to process for ticket {$post_id}");
            return;
        }
        
        // Store seating fields on the ticket post
        foreach ($seating_fields as $key => $value) {
            if (strpos($key, 'fooevents_seat_') === 0) {
                update_post_meta($post_id, $key, $value);
                error_log("HOPE: Stored meta {$key} = {$value} on ticket {$post_id}");
            }
        }
        
        update_post_meta($post_id, 'WooCommerceEventsSeatingFields', $seating_fields);
        
        error_log("HOPE: Successfully stored seating fields on ticket {$post_id}: " . print_r($seating_fields, true));
    }
}

// Instantiate the compatibility class so FooEvents can find it
global $fooevents_seating;
$fooevents_seating = new Fooevents_Seating();