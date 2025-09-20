<?php
/**
 * HOPE Theater Seating - Selective Refunds Database Extension
 * Adds selective partial refund capabilities without disrupting existing structure
 * 
 * @package HOPE_Theater_Seating
 * @version 2.4.7
 */

if (!defined('ABSPATH')) {
    exit;
}

class HOPE_Database_Selective_Refunds {
    
    /**
     * Add selective refund columns to existing bookings table
     * NON-DISRUPTIVE: Only adds new optional columns
     */
    public static function add_selective_refund_support() {
        global $wpdb;
        
        $bookings_table = $wpdb->prefix . 'hope_seating_bookings';
        
        // Check if selective refund columns already exist
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$bookings_table}");
        $existing_columns = array_column($columns, 'Field');
        
        $new_columns = array();
        
        // Add refund tracking columns if they don't exist
        if (!in_array('refund_id', $existing_columns)) {
            $new_columns[] = "ADD COLUMN refund_id INT(11) NULL COMMENT 'WooCommerce refund ID if this seat was refunded'";
        }
        
        if (!in_array('refunded_amount', $existing_columns)) {
            $new_columns[] = "ADD COLUMN refunded_amount DECIMAL(10,2) NULL COMMENT 'Amount refunded for this specific seat'";
        }
        
        if (!in_array('refund_reason', $existing_columns)) {
            $new_columns[] = "ADD COLUMN refund_reason TEXT NULL COMMENT 'Reason for individual seat refund'";
        }
        
        if (!in_array('refund_date', $existing_columns)) {
            $new_columns[] = "ADD COLUMN refund_date DATETIME NULL COMMENT 'When this seat was refunded'";
        }
        
        // Execute column additions
        if (!empty($new_columns)) {
            $alter_sql = "ALTER TABLE {$bookings_table} " . implode(', ', $new_columns);
            $result = $wpdb->query($alter_sql);
            
            if ($result !== false) {
                error_log("HOPE: Added selective refund columns to bookings table");
            } else {
                error_log("HOPE: Error adding selective refund columns: " . $wpdb->last_error);
            }
        }
        
        // Create selective refunds tracking table (completely new, no conflicts)
        self::create_selective_refunds_table();
        
        // Create seat blocking table (completely new, no conflicts)
        self::create_seat_blocking_table();
    }
    
    /**
     * Create new table for tracking selective refund operations
     * SAFE: Completely new table, no conflicts with existing structure
     */
    private static function create_selective_refunds_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hope_seating_selective_refunds';
        
        // Check if table already exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if ($table_exists) {
            return; // Table already exists, skip creation
        }
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id INT(11) NOT NULL AUTO_INCREMENT,
            order_id INT(11) NOT NULL COMMENT 'WooCommerce order ID',
            wc_refund_id INT(11) NOT NULL COMMENT 'WooCommerce refund ID',
            seat_ids TEXT NOT NULL COMMENT 'JSON array of refunded seat IDs',
            total_refund_amount DECIMAL(10,2) NOT NULL COMMENT 'Total amount refunded for these seats',
            seat_count INT(11) NOT NULL COMMENT 'Number of seats refunded',
            refund_method VARCHAR(50) NOT NULL DEFAULT 'selective' COMMENT 'Method used (selective, full, auto)',
            refund_reason TEXT NULL COMMENT 'Admin-provided reason for refund',
            processed_by INT(11) NOT NULL COMMENT 'WordPress user ID who processed refund',
            customer_notified BOOLEAN DEFAULT FALSE COMMENT 'Whether customer was notified',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY wc_refund_id (wc_refund_id),
            KEY processed_by (processed_by),
            KEY created_at (created_at)
        ) $charset_collate COMMENT='Tracks selective seat refund operations';";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            error_log("HOPE: Created selective refunds tracking table");
        } else {
            error_log("HOPE: Error creating selective refunds table: " . $wpdb->last_error);
        }
    }
    
    /**
     * Create new table for tracking seat blocking operations
     * SAFE: Completely new table, no conflicts with existing structure
     */
    private static function create_seat_blocking_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hope_seating_seat_blocks';
        
        // Check if table already exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        if ($table_exists) {
            return; // Table already exists, skip creation
        }
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id INT(11) NOT NULL AUTO_INCREMENT,
            event_id INT(11) NOT NULL COMMENT 'Event/Product ID this block applies to',
            venue_id INT(11) NULL COMMENT 'Venue ID (optional)',
            seat_ids TEXT NOT NULL COMMENT 'JSON array of blocked seat IDs',
            block_type VARCHAR(50) NOT NULL DEFAULT 'manual' COMMENT 'Type: manual, equipment, vip, maintenance',
            block_reason TEXT NULL COMMENT 'Admin-provided reason for blocking',
            blocked_by INT(11) NOT NULL COMMENT 'WordPress user ID who created block',
            is_active BOOLEAN DEFAULT TRUE COMMENT 'Whether block is currently active',
            valid_from DATETIME NULL COMMENT 'Block start time (NULL = immediate)',
            valid_until DATETIME NULL COMMENT 'Block end time (NULL = indefinite)',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_id (event_id),
            KEY venue_id (venue_id),
            KEY blocked_by (blocked_by),
            KEY is_active (is_active),
            KEY valid_from (valid_from),
            KEY valid_until (valid_until),
            KEY block_type (block_type)
        ) $charset_collate COMMENT='Tracks administrative seat blocking for events';";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            error_log("HOPE: Created seat blocking tracking table");
        } else {
            error_log("HOPE: Error creating seat blocking table: " . $wpdb->last_error);
        }
    }
    
    /**
     * Safely check if selective refund features are available
     * @return bool Whether selective refund database support is ready
     */
    public static function is_selective_refund_ready() {
        global $wpdb;
        
        $bookings_table = $wpdb->prefix . 'hope_seating_bookings';
        $selective_table = $wpdb->prefix . 'hope_seating_selective_refunds';
        
        // Check if bookings table has refund columns
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$bookings_table}");
        $existing_columns = array_column($columns, 'Field');
        
        $required_columns = array('refund_id', 'refunded_amount', 'refund_reason', 'refund_date');
        $has_refund_columns = count(array_intersect($required_columns, $existing_columns)) === count($required_columns);
        
        // Check if selective refunds table exists
        $selective_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$selective_table'") == $selective_table;
        
        return $has_refund_columns && $selective_table_exists;
    }
    
    /**
     * Get refund information for a specific seat
     * @param string $seat_id Seat identifier
     * @param int $order_id Order ID
     * @return array|null Refund info or null if not refunded
     */
    public static function get_seat_refund_info($seat_id, $order_id) {
        global $wpdb;
        
        if (!self::is_selective_refund_ready()) {
            return null;
        }
        
        $bookings_table = $wpdb->prefix . 'hope_seating_bookings';
        
        $refund_info = $wpdb->get_row($wpdb->prepare(
            "SELECT refund_id, refunded_amount, refund_reason, refund_date, status
            FROM {$bookings_table}
            WHERE seat_id = %s AND order_id = %d AND refund_id IS NOT NULL",
            $seat_id, $order_id
        ), ARRAY_A);
        
        return $refund_info;
    }
    
    /**
     * Get all refundable seats for an order
     * @param int $order_id Order ID
     * @return array Array of seat information that can be refunded
     */
    public static function get_refundable_seats($order_id) {
        global $wpdb;
        
        $bookings_table = $wpdb->prefix . 'hope_seating_bookings';
        
        // Get all confirmed seats that haven't been refunded yet
        $seats = $wpdb->get_results($wpdb->prepare(
            "SELECT id, seat_id, product_id, order_item_id, status, created_at,
                    refund_id, refunded_amount, refund_reason, refund_date
            FROM {$bookings_table}
            WHERE order_id = %d 
            AND status IN ('confirmed', 'partially_refunded')
            AND (refund_id IS NULL OR status != 'refunded')
            ORDER BY seat_id",
            $order_id
        ), ARRAY_A);
        
        return $seats ?: array();
    }
    
    /**
     * Create selective refund record
     * @param int $order_id Order ID
     * @param int $wc_refund_id WooCommerce refund ID
     * @param array $seat_ids Array of seat IDs being refunded
     * @param float $total_amount Total refund amount
     * @param string $reason Refund reason
     * @param int $processed_by User ID who processed the refund
     * @return int|false Selective refund record ID or false on failure
     */
    public static function create_selective_refund_record($order_id, $wc_refund_id, $seat_ids, $total_amount, $reason = '', $processed_by = 0) {
        global $wpdb;
        
        if (!self::is_selective_refund_ready()) {
            return false;
        }
        
        $table_name = $wpdb->prefix . 'hope_seating_selective_refunds';
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'order_id' => $order_id,
                'wc_refund_id' => $wc_refund_id,
                'seat_ids' => json_encode($seat_ids),
                'total_refund_amount' => $total_amount,
                'seat_count' => count($seat_ids),
                'refund_method' => 'selective',
                'refund_reason' => $reason,
                'processed_by' => $processed_by ?: get_current_user_id(),
                'customer_notified' => false
            ),
            array('%d', '%d', '%s', '%f', '%d', '%s', '%s', '%d', '%d')
        );
        
        if ($result !== false) {
            return $wpdb->insert_id;
        }
        
        error_log("HOPE: Error creating selective refund record: " . $wpdb->last_error);
        return false;
    }
    
    /**
     * Create seat blocking record
     * @param int $event_id Event/Product ID
     * @param array $seat_ids Array of seat IDs to block
     * @param string $block_type Type of block (manual, equipment, vip, maintenance)
     * @param string $reason Block reason
     * @param string $valid_from Start time (Y-m-d H:i:s format or null for immediate)
     * @param string $valid_until End time (Y-m-d H:i:s format or null for indefinite)
     * @return int|false Block record ID or false on failure
     */
    public static function create_seat_block($event_id, $seat_ids, $block_type = 'manual', $reason = '', $valid_from = null, $valid_until = null) {
        global $wpdb;
        
        if (!self::is_selective_refund_ready()) {
            return false;
        }
        
        $table_name = $wpdb->prefix . 'hope_seating_seat_blocks';
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'event_id' => $event_id,
                'seat_ids' => json_encode($seat_ids),
                'block_type' => $block_type,
                'block_reason' => $reason,
                'blocked_by' => get_current_user_id(),
                'is_active' => true,
                'valid_from' => $valid_from,
                'valid_until' => $valid_until
            ),
            array('%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s')
        );
        
        if ($result !== false) {
            error_log("HOPE: Created seat block for event {$event_id} - " . count($seat_ids) . " seats");
            return $wpdb->insert_id;
        }
        
        error_log("HOPE: Error creating seat block: " . $wpdb->last_error);
        return false;
    }
    
    /**
     * Get active seat blocks for an event
     * @param int $event_id Event/Product ID
     * @return array Array of active blocks
     */
    public static function get_active_seat_blocks($event_id) {
        global $wpdb;
        
        if (!self::is_seat_blocking_ready()) {
            return array();
        }
        
        $table_name = $wpdb->prefix . 'hope_seating_seat_blocks';
        
        $blocks = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} 
            WHERE event_id = %d 
            AND is_active = 1
            AND (valid_from IS NULL OR valid_from <= NOW())
            AND (valid_until IS NULL OR valid_until >= NOW())
            ORDER BY created_at DESC",
            $event_id
        ), ARRAY_A);
        
        // Decode seat_ids JSON for each block
        foreach ($blocks as &$block) {
            $block['seat_ids'] = json_decode($block['seat_ids'], true) ?: array();
        }
        
        return $blocks ?: array();
    }
    
    /**
     * Get all blocked seat IDs for an event
     * @param int $event_id Event/Product ID
     * @return array Array of seat IDs that are currently blocked
     */
    public static function get_blocked_seat_ids($event_id) {
        $active_blocks = self::get_active_seat_blocks($event_id);
        $blocked_seats = array();
        
        foreach ($active_blocks as $block) {
            $blocked_seats = array_merge($blocked_seats, $block['seat_ids']);
        }
        
        return array_unique($blocked_seats);
    }
    
    /**
     * Remove/deactivate seat block
     * @param int $block_id Block ID to remove
     * @return bool Success status
     */
    public static function remove_seat_block($block_id) {
        global $wpdb;
        
        if (!self::is_seat_blocking_ready()) {
            return false;
        }
        
        $table_name = $wpdb->prefix . 'hope_seating_seat_blocks';
        
        $result = $wpdb->update(
            $table_name,
            array(
                'is_active' => false,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $block_id),
            array('%d', '%s'),
            array('%d')
        );
        
        if ($result !== false) {
            error_log("HOPE: Deactivated seat block ID {$block_id}");
            return true;
        }
        
        error_log("HOPE: Error deactivating seat block: " . $wpdb->last_error);
        return false;
    }
    
    /**
     * Check if seat blocking features are available
     * @return bool Whether seat blocking database support is ready
     */
    public static function is_seat_blocking_ready() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hope_seating_seat_blocks';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        
        return $table_exists;
    }
}