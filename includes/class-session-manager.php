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
    private $hold_duration = 600; // 10 minutes in seconds
    
    public function __construct() {
        global $wpdb;
        $this->holds_table = $wpdb->prefix . 'hope_seating_holds';
        
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
    public function create_hold($event_id, $seat_ids, $session_id, $user_email = null) {
        global $wpdb;
        
        // First, release any existing holds for this session
        $this->release_session_holds($session_id);
        
        // Check if any seats are already held by others
        $conflicts = $this->check_hold_conflicts($event_id, $seat_ids, $session_id);
        if (!empty($conflicts)) {
            return array(
                'success' => false,
                'message' => __('Some seats are no longer available', 'hope-seating'),
                'conflicts' => $conflicts
            );
        }
        
        // Create new holds
        $expires_at = date('Y-m-d H:i:s', time() + $this->hold_duration);
        $held_seats = array();
        
        foreach ($seat_ids as $seat_id) {
            $result = $wpdb->insert(
                $this->holds_table,
                array(
                    'session_id' => $session_id,
                    'seat_id' => $seat_id,
                    'event_id' => $event_id,
                    'user_email' => $user_email,
                    'expires_at' => $expires_at,
                    'created_at' => current_time('mysql')
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
    private function check_hold_conflicts($event_id, $seat_ids, $session_id) {
        global $wpdb;
        
        if (empty($seat_ids)) {
            return array();
        }
        
        $placeholders = array_fill(0, count($seat_ids), '%s');
        $placeholders_str = implode(',', $placeholders);
        
        $query = $wpdb->prepare(
            "SELECT seat_id FROM {$this->holds_table}
            WHERE event_id = %d 
            AND seat_id IN ($placeholders_str)
            AND session_id != %s
            AND expires_at > NOW()",
            array_merge(array($event_id), $seat_ids, array($session_id))
        );
        
        $conflicts = $wpdb->get_col($query);
        
        // Also check for booked seats
        $bookings_table = $wpdb->prefix . 'hope_seating_bookings';
        $booked_query = $wpdb->prepare(
            "SELECT seat_id FROM {$bookings_table}
            WHERE event_id = %d 
            AND seat_id IN ($placeholders_str)",
            array_merge(array($event_id), $seat_ids)
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
    public function release_seat_holds($event_id, $seat_ids, $session_id) {
        global $wpdb;
        
        if (empty($seat_ids)) {
            return false;
        }
        
        $placeholders = array_fill(0, count($seat_ids), '%s');
        $placeholders_str = implode(',', $placeholders);
        
        $query = $wpdb->prepare(
            "DELETE FROM {$this->holds_table}
            WHERE event_id = %d 
            AND seat_id IN ($placeholders_str)
            AND session_id = %s",
            array_merge(array($event_id), $seat_ids, array($session_id))
        );
        
        return $wpdb->query($query);
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
     * Get all active holds for an event
     */
    public function get_event_holds($event_id) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->holds_table}
            WHERE event_id = %d 
            AND expires_at > NOW()
            ORDER BY seat_id",
            $event_id
        ));
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
                WHERE event_id = %d AND seat_id = %s",
                $hold->event_id,
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
                    'event_id' => $hold->event_id,
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
        
        // Check if table exists before attempting cleanup
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->holds_table}'") == $this->holds_table;
        
        if (!$table_exists) {
            // Table doesn't exist, try to create it
            if (class_exists('HOPE_Seating_Database')) {
                HOPE_Seating_Database::create_tables();
            }
            return 0;
        }
        
        $deleted = $wpdb->query(
            "DELETE FROM {$this->holds_table}
            WHERE expires_at <= NOW()"
        );
        
        if ($deleted === false) {
            error_log('HOPE Seating: Failed to cleanup expired holds - ' . $wpdb->last_error);
            return 0;
        }
        
        if ($deleted > 0) {
            do_action('hope_seating_holds_cleaned', $deleted);
        }
        
        return $deleted;
    }
    
    /**
     * Get hold statistics
     */
    public function get_hold_stats($event_id = null) {
        global $wpdb;
        
        $where = $event_id ? $wpdb->prepare(" WHERE event_id = %d", $event_id) : "";
        
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
            
            // Regenerate session ID to prevent fixation attacks
            if (!isset($_SESSION['hope_seating_initialized'])) {
                session_regenerate_id(true);
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
}