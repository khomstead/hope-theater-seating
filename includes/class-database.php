<?php
/**
 * Database management for HOPE Theater Seating
 * File: /wp-content/plugins/hope-theater-seating/includes/class-database.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class HOPE_Seating_Database {
    
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Venues table
        $venues_table = $wpdb->prefix . 'hope_seating_venues';
        $venues_sql = "CREATE TABLE IF NOT EXISTS $venues_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            slug varchar(100) NOT NULL UNIQUE,
            description text,
            total_seats int(11) NOT NULL DEFAULT 0,
            configuration longtext,
            svg_template longtext,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status)
        ) $charset_collate;";
        
        dbDelta($venues_sql);
        
        // Seat maps table
        $seat_maps_table = $wpdb->prefix . 'hope_seating_seat_maps';
        $seat_maps_sql = "CREATE TABLE IF NOT EXISTS $seat_maps_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            venue_id int(11) NOT NULL,
            seat_id varchar(50) NOT NULL,
            section varchar(10) NOT NULL,
            `row_number` int(11) NOT NULL,
            seat_number int(11) NOT NULL,
            level varchar(20) NOT NULL DEFAULT 'floor',
            x_coordinate decimal(10,2) NOT NULL,
            y_coordinate decimal(10,2) NOT NULL,
            pricing_tier varchar(10) NOT NULL DEFAULT 'General',
            seat_type varchar(20) NOT NULL DEFAULT 'standard',
            is_accessible boolean NOT NULL DEFAULT false,
            is_blocked boolean NOT NULL DEFAULT false,
            notes text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY venue_seat (venue_id, seat_id),
            KEY venue_id (venue_id),
            KEY section (section),
            KEY pricing_tier (pricing_tier)
        ) $charset_collate;";
        
        dbDelta($seat_maps_sql);
        
        // Event seats table
        $event_seats_table = $wpdb->prefix . 'hope_seating_event_seats';
        $event_seats_sql = "CREATE TABLE IF NOT EXISTS $event_seats_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            event_id int(11) NOT NULL,
            venue_id int(11) NOT NULL,
            seat_map_id int(11) NOT NULL,
            order_id int(11) NULL,
            order_item_id int(11) NULL,
            customer_id int(11) NULL,
            status varchar(20) NOT NULL DEFAULT 'available',
            reserved_until datetime NULL,
            booking_reference varchar(50) NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY event_seat_unique (event_id, seat_map_id),
            KEY event_id (event_id),
            KEY venue_id (venue_id),
            KEY seat_map_id (seat_map_id),
            KEY order_id (order_id),
            KEY customer_id (customer_id),
            KEY status (status),
            KEY reserved_until (reserved_until)
        ) $charset_collate;";
        
        dbDelta($event_seats_sql);
        
        // Pricing tiers table
        $pricing_table = $wpdb->prefix . 'hope_seating_pricing_tiers';
        $pricing_sql = "CREATE TABLE IF NOT EXISTS $pricing_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            venue_id int(11) NOT NULL,
            tier_name varchar(50) NOT NULL,
            tier_label varchar(100) NOT NULL,
            base_price decimal(10,2) NOT NULL DEFAULT 0.00,
            description text,
            color_code varchar(7) NOT NULL DEFAULT '#549e39',
            sort_order int(11) NOT NULL DEFAULT 0,
            is_active boolean NOT NULL DEFAULT true,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY venue_tier (venue_id, tier_name),
            KEY venue_id (venue_id),
            KEY is_active (is_active),
            KEY sort_order (sort_order)
        ) $charset_collate;";
        
        dbDelta($pricing_sql);
        
        // Holds table (for temporary seat reservations)
        $holds_table = $wpdb->prefix . 'hope_seating_holds';
        $holds_sql = "CREATE TABLE IF NOT EXISTS $holds_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            seat_id varchar(50) NOT NULL,
            product_id int(11) NOT NULL,
            session_id varchar(100) NOT NULL,
            user_email varchar(255) NULL,
            expires_at datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY seat_id (seat_id),
            KEY product_id (product_id),
            KEY session_id (session_id),
            KEY expires_at (expires_at)
        ) $charset_collate;";
        
        dbDelta($holds_sql);
        
        // Bookings table (for confirmed reservations)
        $bookings_table = $wpdb->prefix . 'hope_seating_bookings';
        $bookings_sql = "CREATE TABLE IF NOT EXISTS $bookings_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            seat_id varchar(50) NOT NULL,
            product_id int(11) NOT NULL,
            order_id int(11) NULL,
            order_item_id int(11) NULL,
            customer_id int(11) NULL,
            customer_email varchar(255) NULL,
            session_id varchar(100) NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            reserved_until datetime NULL,
            booking_reference varchar(50) NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY seat_id (seat_id),
            KEY product_id (product_id),
            KEY order_id (order_id),
            KEY customer_id (customer_id),
            KEY session_id (session_id),
            KEY status (status),
            KEY reserved_until (reserved_until)
        ) $charset_collate;";
        
        dbDelta($bookings_sql);
    }
    
    public static function get_table_name($table) {
        global $wpdb;
        
        $tables = array(
            'venues' => $wpdb->prefix . 'hope_seating_venues',
            'seat_maps' => $wpdb->prefix . 'hope_seating_seat_maps',
            'event_seats' => $wpdb->prefix . 'hope_seating_event_seats',
            'pricing_tiers' => $wpdb->prefix . 'hope_seating_pricing_tiers'
        );
        
        return isset($tables[$table]) ? $tables[$table] : false;
    }
    
    public static function drop_tables() {
        global $wpdb;
        
        $tables = array(
            $wpdb->prefix . 'hope_seating_event_seats',
            $wpdb->prefix . 'hope_seating_pricing_tiers',
            $wpdb->prefix . 'hope_seating_seat_maps',
            $wpdb->prefix . 'hope_seating_venues'
        );
        
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
    }
}
?>