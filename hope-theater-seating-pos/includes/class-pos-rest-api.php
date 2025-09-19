<?php
/**
 * POS REST API Extensions
 * 
 * Extends FooEventsPOS REST API with seat selection endpoints
 *
 * @package hope-theater-seating-pos
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class HOPE_POS_REST_API {
    
    /**
     * API namespace
     */
    const NAMESPACE = 'fooeventspos/v1';
    
    /**
     * Initialize REST API extensions
     */
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    
    /**
     * Register seat selection REST routes
     */
    public function register_routes() {
        // Get seat map for an event
        register_rest_route(self::NAMESPACE, '/seat-maps/(?P<event_id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_seat_map'),
            'permission_callback' => array($this, 'check_pos_permissions'),
            'args' => array(
                'event_id' => array(
                    'validate_callback' => function($param, $request, $key) {
                        return is_numeric($param);
                    }
                ),
            ),
        ));
        
        // Get seat availability for an event
        register_rest_route(self::NAMESPACE, '/seat-availability/(?P<event_id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_seat_availability'),
            'permission_callback' => array($this, 'check_pos_permissions'),
            'args' => array(
                'event_id' => array(
                    'validate_callback' => function($param, $request, $key) {
                        return is_numeric($param);
                    }
                ),
            ),
        ));
        
        // Hold seats temporarily
        register_rest_route(self::NAMESPACE, '/seat-hold', array(
            'methods' => 'POST',
            'callback' => array($this, 'hold_seats'),
            'permission_callback' => array($this, 'check_pos_permissions'),
        ));
        
        // Release held seats
        register_rest_route(self::NAMESPACE, '/seat-release', array(
            'methods' => 'POST',
            'callback' => array($this, 'release_seats'),
            'permission_callback' => array($this, 'check_pos_permissions'),
        ));
    }
    
    /**
     * Check if user has POS permissions
     */
    public function check_pos_permissions() {
        // TODO: Implement proper POS permission checking
        // This should align with FooEventsPOS permission structure
        return current_user_can('manage_woocommerce');
    }
    
    /**
     * Get seat map for an event
     */
    public function get_seat_map($request) {
        $event_id = $request['event_id'];
        
        // TODO: Use core theater seating plugin to get seat map
        // This will adapt the existing AJAX handlers for REST consumption
        
        return rest_ensure_response(array(
            'event_id' => $event_id,
            'seat_map' => array(), // TODO: Implement
            'success' => true
        ));
    }
    
    /**
     * Get seat availability for an event
     */
    public function get_seat_availability($request) {
        $event_id = $request['event_id'];
        
        // TODO: Use core theater seating plugin to get availability
        
        return rest_ensure_response(array(
            'event_id' => $event_id,
            'available_seats' => array(), // TODO: Implement
            'held_seats' => array(), // TODO: Implement
            'sold_seats' => array(), // TODO: Implement
            'blocked_seats' => array(), // TODO: Implement
            'success' => true
        ));
    }
    
    /**
     * Hold seats temporarily for POS session
     */
    public function hold_seats($request) {
        // TODO: Implement seat holding logic
        // This should integrate with the core plugin's session manager
        
        return rest_ensure_response(array(
            'success' => true,
            'held_seats' => array(),
            'hold_expires' => time() + (15 * 60) // 15 minutes
        ));
    }
    
    /**
     * Release held seats
     */
    public function release_seats($request) {
        // TODO: Implement seat release logic
        
        return rest_ensure_response(array(
            'success' => true,
            'released_seats' => array()
        ));
    }
}