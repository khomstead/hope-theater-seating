<?php
/**
 * Session Manager for HOPE Theater Seating
 * Handles seat holds, releases, and session management
 */

if (!defined('ABSPATH')) {
    exit;
}

class HOPE_Session_Manager {

    private $holds_table;
    private $hold_duration;

    public function __construct() {
        global $wpdb;
        $this->holds_table = $wpdb->prefix . 'hope_seating_holds';

        // Get hold duration from admin settings
        $this->hold_duration = $this->get_hold_duration();
        
        // Add custom cron schedule FIRST
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        add_action('hope_seating_cleanup_holds', array($this, 'cleanup_expired_holds'));
        
        // Schedule cleanup cron AFTER adding the schedule
        if (!wp_next_scheduled('hope_seating_cleanup_holds')) {
            wp_schedule_event(time(), 'hope_seating_every_minute', 'hope_seating_cleanup_holds');
        }
    }
    
    /**
     * Add custom cron schedules
     */
    public function add_cron_schedules($schedules) {
        $schedules['hope_seating_every_minute'] = array(
            'interval' => 60,
            'display' => __('Every Minute', 'hope-seating')
        );
        return $schedules;
    }
    
    /**
     * Create a seat hold
     */
    public function create_hold($product_id, $seat_ids, $session_id, $user_email = null) {
        global $wpdb;

        // First, release any existing holds for this session
        $this->release_session_holds($session_id);

        // Check if any seats are already held by others
        $conflicts = $this->check_hold_conflicts($product_id, $seat_ids, $session_id);
        if (!empty($conflicts)) {
            return array(
                'success' => false,
                'message' => __('Some seats are no longer available', 'hope-seating'),
                'conflicts' => $conflicts
            );
        }

        // Create new holds
        // Use gmdate() to match MySQL UTC_TIMESTAMP()
        $expires_at = gmdate('Y-m-d H:i:s', time() + $this->hold_duration);
        $held_seats = array();

        foreach ($seat_ids as $seat_id) {
            $result = $wpdb->insert(
                $this->holds_table,
                array(
                    'session_id' => $session_id,
                    'seat_id' => $seat_id,
                    'product_id' => $product_id,
                    'user_email' => $user_email,
                    'expires_at' => $expires_at,
                    'created_at' => gmdate('Y-m-d H:i:s')
                ),
                array('%s', '%s', '%d', '%s', '%s', '%s')
            );
            
            if ($result) {
                $held_seats[] = $seat_id;
            }
        }
        
        return array(
            'success' => true,
            'held_seats' => $held_seats,
            'expires_at' => $expires_at,
            'expires_in' => $this->hold_duration
        );
    }
    
    /**
     * Check for hold conflicts
     */
    private function check_hold_conflicts($product_id, $seat_ids, $session_id) {
        global $wpdb;

        if (empty($seat_ids)) {
            return array();
        }

        // NEW: Check for blocked seats first
        $conflicts = $this->check_blocked_seats($product_id, $seat_ids);
        if (!empty($conflicts)) {
            return $conflicts; // Return blocked seats as conflicts
        }

        $placeholders = array_fill(0, count($seat_ids), '%s');
        $placeholders_str = implode(',', $placeholders);

        $query = $wpdb->prepare(
            "SELECT seat_id FROM {$this->holds_table}
            WHERE product_id = %d
            AND seat_id IN ($placeholders_str)
            AND session_id != %s
            AND expires_at > NOW()",
            array_merge(array($product_id), $seat_ids, array($session_id))
        );

        $conflicts = $wpdb->get_col($query);

        // Also check for booked seats
        $bookings_table = $wpdb->prefix . 'hope_seating_bookings';
        $booked_query = $wpdb->prepare(
            "SELECT seat_id FROM {$bookings_table}
            WHERE product_id = %d
            AND seat_id IN ($placeholders_str)",
            array_merge(array($product_id), $seat_ids)
        );

        $booked = $wpdb->get_col($booked_query);

        return array_merge($conflicts, $booked);
    }
    
    /**
     * Release holds for a session
     */
    public function release_session_holds($session_id) {
        global $wpdb;
        
        return $wpdb->delete(
            $this->holds_table,
            array('session_id' => $session_id),
            array('%s')
        );
    }
    
    /**
     * Release specific seat holds
     */
    public function release_seat_holds($product_id, $seat_ids, $session_id) {
        global $wpdb;

        if (empty($seat_ids)) {
            return false;
        }

        $placeholders = array_fill(0, count($seat_ids), '%s');
        $placeholders_str = implode(',', $placeholders);

        $query = $wpdb->prepare(
            "DELETE FROM {$this->holds_table}
            WHERE product_id = %d
            AND seat_id IN ($placeholders_str)
            AND session_id = %s",
            array_merge(array($product_id), $seat_ids, array($session_id))
        );

        return $wpdb->query($query);
    }

    /**
     * Release hold for a single seat
     */
    public function release_seat_hold($product_id, $seat_id, $session_id) {
        return $this->release_seat_holds($product_id, array($seat_id), $session_id);
    }
    
    /**
     * Extend hold duration
     */
    public function extend_hold($session_id, $additional_time = null) {
        global $wpdb;
        
        if (!$additional_time) {
            $additional_time = $this->hold_duration;
        }
        
        $new_expiry = date('Y-m-d H:i:s', time() + $additional_time);
        
        return $wpdb->update(
            $this->holds_table,
            array('expires_at' => $new_expiry),
            array('session_id' => $session_id),
            array('%s'),
            array('%s')
        );
    }
    
    /**
     * Get holds for a session
     */
    public function get_session_holds($session_id) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->holds_table}
            WHERE session_id = %s 
            AND expires_at > NOW()
            ORDER BY seat_id",
            $session_id
        ));
    }
    
    /**
     * Get all active holds for a product/event
     */
    public function get_product_holds($product_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->holds_table}
            WHERE product_id = %d
            AND expires_at > NOW()
            ORDER BY seat_id",
            $product_id
        ));
    }

    // Backwards compatibility alias
    public function get_event_holds($event_id) {
        return $this->get_product_holds($event_id);
    }
    
    /**
     * Convert holds to bookings
     */
    public function convert_holds_to_bookings($session_id, $order_id, $customer_email) {
        global $wpdb;
        
        // Get current holds
        $holds = $this->get_session_holds($session_id);
        
        if (empty($holds)) {
            return array(
                'success' => false,
                'message' => __('No seats to book', 'hope-seating')
            );
        }
        
        $bookings_table = $wpdb->prefix . 'hope_seating_bookings';
        $booked_seats = array();
        
        foreach ($holds as $hold) {
            // Check if seat is still available
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$bookings_table}
                WHERE product_id = %d AND seat_id = %s",
                $hold->product_id,
                $hold->seat_id
            ));

            if ($existing) {
                continue; // Skip if already booked
            }

            // Create booking
            $result = $wpdb->insert(
                $bookings_table,
                array(
                    'order_id' => $order_id,
                    'seat_id' => $hold->seat_id,
                    'product_id' => $hold->product_id,
                    'customer_email' => $customer_email,
                    'booking_date' => current_time('mysql'),
                    'status' => 'confirmed'
                ),
                array('%d', '%s', '%d', '%s', '%s', '%s')
            );
            
            if ($result) {
                $booked_seats[] = $hold->seat_id;
            }
        }
        
        // Release the holds
        $this->release_session_holds($session_id);
        
        return array(
            'success' => true,
            'booked_seats' => $booked_seats,
            'order_id' => $order_id
        );
    }
    
    /**
     * Cleanup expired holds
     */
    public function cleanup_expired_holds() {
        global $wpdb;
        
        error_log('HOPE Cleanup: Starting expired holds cleanup...');
        
        // Check if table exists before attempting cleanup
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->holds_table}'") == $this->holds_table;
        
        if (!$table_exists) {
            error_log('HOPE Cleanup: Holds table does not exist, attempting to create...');
            // Table doesn't exist, try to create it
            if (class_exists('HOPE_Seating_Database')) {
                HOPE_Seating_Database::create_tables();
            }
            return 0;
        }
        
        // First, check how many expired holds exist
        $expired_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->holds_table} WHERE expires_at <= NOW()"
        );
        
        error_log("HOPE Cleanup: Found {$expired_count} expired holds to clean");
        
        if ($expired_count > 0) {
            // Log the expired holds before deleting
            $expired_holds = $wpdb->get_results(
                "SELECT session_id, seat_id, expires_at, TIMESTAMPDIFF(MINUTE, expires_at, NOW()) as minutes_expired 
                FROM {$this->holds_table} WHERE expires_at <= NOW()"
            );
            
            foreach ($expired_holds as $hold) {
                error_log("HOPE Cleanup: Removing expired hold - Session: {$hold->session_id}, Seat: {$hold->seat_id}, Expired {$hold->minutes_expired} minutes ago");
            }
        }
        
        $deleted = $wpdb->query(
            "DELETE FROM {$this->holds_table}
            WHERE expires_at <= NOW()"
        );
        
        if ($deleted === false) {
            error_log('HOPE Cleanup: Failed to cleanup expired holds - ' . $wpdb->last_error);
            return 0;
        }
        
        error_log("HOPE Cleanup: Successfully deleted {$deleted} expired holds");
        
        if ($deleted > 0) {
            do_action('hope_seating_holds_cleaned', $deleted);
        }
        
        // Also cleanup abandoned pending bookings (older than 30 minutes with no order)
        $this->cleanup_abandoned_pending_bookings();
        
        // Also log current active holds
        $active_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->holds_table} WHERE expires_at > NOW()"
        );
        error_log("HOPE Cleanup: {$active_count} active holds remaining");
        
        return $deleted;
    }
    
    /**
     * Force cleanup of all expired holds (for debugging)
     */
    public function force_cleanup_all_expired() {
        error_log('HOPE Cleanup: FORCED cleanup of all expired holds');
        return $this->cleanup_expired_holds();
    }
    
    /**
     * Clear ALL holds (for debugging - use with caution)
     */
    public function clear_all_holds() {
        global $wpdb;
        
        error_log('HOPE Cleanup: CLEARING ALL HOLDS (DEBUG MODE)');
        
        $deleted = $wpdb->query("DELETE FROM {$this->holds_table}");
        
        error_log("HOPE Cleanup: Deleted {$deleted} total holds");
        
        return $deleted;
    }
    
    /**
     * Clean up abandoned pending bookings
     */
    public function cleanup_abandoned_pending_bookings() {
        global $wpdb;
        $bookings_table = $wpdb->prefix . 'hope_seating_bookings';
        
        error_log('HOPE Cleanup: Starting abandoned pending bookings cleanup...');
        
        // Find pending bookings older than 30 minutes with no order_id
        $abandoned_bookings = $wpdb->get_results(
            "SELECT id, seat_id, created_at, TIMESTAMPDIFF(MINUTE, created_at, NOW()) as minutes_old
            FROM {$bookings_table} 
            WHERE status = 'pending' 
            AND (order_id = 0 OR order_id IS NULL)
            AND created_at <= DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
        );
        
        if (count($abandoned_bookings) > 0) {
            error_log("HOPE Cleanup: Found " . count($abandoned_bookings) . " abandoned pending bookings");
            
            foreach ($abandoned_bookings as $booking) {
                error_log("HOPE Cleanup: Removing abandoned booking - ID: {$booking->id}, Seat: {$booking->seat_id}, Age: {$booking->minutes_old} minutes");
            }
            
            $deleted_bookings = $wpdb->query(
                "DELETE FROM {$bookings_table}
                WHERE status = 'pending' 
                AND (order_id = 0 OR order_id IS NULL)
                AND created_at <= DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
            );
            
            if ($deleted_bookings > 0) {
                error_log("HOPE Cleanup: Successfully deleted {$deleted_bookings} abandoned pending bookings");
            }
        } else {
            error_log('HOPE Cleanup: No abandoned pending bookings found');
        }
    }

    /**
     * Get hold duration from admin settings (in seconds)
     * @return int Hold duration in seconds
     */
    private function get_hold_duration() {
        if (class_exists('HOPE_Theater_Seating')) {
            return HOPE_Theater_Seating::get_hold_duration();
        }
        // Fallback to 15 minutes if main class not available
        return 900;
    }

    /**
     * Get hold statistics
     */
    public function get_hold_stats($product_id = null) {
        global $wpdb;

        $where = $product_id ? $wpdb->prepare(" WHERE product_id = %d", $product_id) : "";

        $stats = array(
            'total_holds' => $wpdb->get_var(
                "SELECT COUNT(*) FROM {$this->holds_table}{$where}"
            ),
            'active_holds' => $wpdb->get_var(
                "SELECT COUNT(*) FROM {$this->holds_table}{$where}
                " . ($where ? " AND" : " WHERE") . " expires_at > NOW()"
            ),
            'expired_holds' => $wpdb->get_var(
                "SELECT COUNT(*) FROM {$this->holds_table}{$where}
                " . ($where ? " AND" : " WHERE") . " expires_at <= NOW()"
            )
        );

        return $stats;
    }
    
    /**
     * Generate unique session ID
     */
    public static function generate_session_id() {
        return wp_generate_password(32, false);
    }
    
    /**
     * Get or create session ID for current user
     */
    public static function get_current_session_id() {
        // Enhanced session security
        if (!session_id()) {
            // Set secure session parameters
            ini_set('session.cookie_httponly', 1);
            ini_set('session.cookie_secure', is_ssl() ? 1 : 0);
            ini_set('session.cookie_samesite', 'Strict');
            ini_set('session.use_strict_mode', 1);

            session_start();

            // CRITICAL: Check if we have existing session data BEFORE regenerating ID
            $has_existing_session = isset($_SESSION['hope_seating_session_id']);

            // Regenerate session ID to prevent fixation attacks
            // But DON'T destroy session if we already have a seating session
            if (!isset($_SESSION['hope_seating_initialized'])) {
                if ($has_existing_session) {
                    session_regenerate_id(false); // FALSE = don't delete old session
                } else {
                    session_regenerate_id(true);
                }
                $_SESSION['hope_seating_initialized'] = true;
                $_SESSION['hope_seating_created'] = time();
            }
        }

        // Check for session timeout (2 hours)
        if (isset($_SESSION['hope_seating_created']) &&
            (time() - $_SESSION['hope_seating_created']) > 7200) {
            session_destroy();
            session_start();
            $_SESSION['hope_seating_initialized'] = true;
            $_SESSION['hope_seating_created'] = time();
        }

        if (!isset($_SESSION['hope_seating_session_id'])) {
            $_SESSION['hope_seating_session_id'] = self::generate_session_id();
        }

        return $_SESSION['hope_seating_session_id'];
    }
    
    /**
     * Check for blocked seats that conflict with requested seats
     * NEW: Integration with seat blocking system
     * @param int $product_id Event/Product ID
     * @param array $seat_ids Seat IDs to check
     * @return array Array of blocked seat IDs that conflict
     */
    private function check_blocked_seats($product_id, $seat_ids) {
        if (!class_exists('HOPE_Database_Selective_Refunds') ||
            !HOPE_Database_Selective_Refunds::is_seat_blocking_ready()) {
            return array(); // Seat blocking not available, no conflicts
        }

        // Get all blocked seat IDs for this product
        $blocked_seats = HOPE_Database_Selective_Refunds::get_blocked_seat_ids($product_id);

        if (empty($blocked_seats)) {
            return array(); // No blocked seats, no conflicts
        }

        // Find intersection of requested seats and blocked seats
        $conflicts = array_intersect($seat_ids, $blocked_seats);

        if (!empty($conflicts)) {
            error_log("HOPE SEAT BLOCKING: Blocked seat conflicts detected for product {$product_id} - " . implode(', ', $conflicts));
        }

        return array_values($conflicts);
    }
}