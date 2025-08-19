<?php
/**
 * Mobile Detector for HOPE Theater Seating
 * Handles device detection and responsive adjustments
 */

if (!defined('ABSPATH')) {
    exit;
}

class HOPE_Mobile_Detector {
    
    private static $instance = null;
    private $is_mobile = null;
    private $is_tablet = null;
    private $device_type = null;
    
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
        $this->detect_device();
    }
    
    /**
     * Detect device type
     */
    private function detect_device() {
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        
        // Check for mobile devices
        $mobile_patterns = array(
            '/Mobile/i',
            '/Android/i',
            '/iPhone/i',
            '/iPod/i',
            '/BlackBerry/i',
            '/Windows Phone/i',
            '/Opera Mini/i',
            '/IEMobile/i'
        );
        
        foreach ($mobile_patterns as $pattern) {
            if (preg_match($pattern, $user_agent)) {
                $this->is_mobile = true;
                break;
            }
        }
        
        // Check for tablets
        $tablet_patterns = array(
            '/iPad/i',
            '/Tablet/i',
            '/Kindle/i',
            '/Playbook/i',
            '/Nexus 7/i',
            '/Nexus 10/i',
            '/Samsung.*Tab/i'
        );
        
        foreach ($tablet_patterns as $pattern) {
            if (preg_match($pattern, $user_agent)) {
                $this->is_tablet = true;
                $this->is_mobile = false; // Tablets are not mobile phones
                break;
            }
        }
        
        // Set device type
        if ($this->is_mobile) {
            $this->device_type = 'mobile';
        } elseif ($this->is_tablet) {
            $this->device_type = 'tablet';
        } else {
            $this->device_type = 'desktop';
        }
    }
    
    /**
     * Check if mobile device
     */
    public function is_mobile() {
        return $this->is_mobile === true;
    }
    
    /**
     * Check if tablet
     */
    public function is_tablet() {
        return $this->is_tablet === true;
    }
    
    /**
     * Check if desktop
     */
    public function is_desktop() {
        return !$this->is_mobile && !$this->is_tablet;
    }
    
    /**
     * Check if touch device (mobile or tablet)
     */
    public function is_touch_device() {
        return $this->is_mobile || $this->is_tablet;
    }
    
    /**
     * Get device type
     */
    public function get_device_type() {
        return $this->device_type;
    }
    
    /**
     * Get device-specific CSS classes
     */
    public function get_device_classes() {
        $classes = array('hope-device-' . $this->device_type);
        
        if ($this->is_touch_device()) {
            $classes[] = 'hope-touch-enabled';
        } else {
            $classes[] = 'hope-no-touch';
        }
        
        if ($this->is_mobile) {
            $classes[] = 'hope-mobile';
        }
        
        if ($this->is_tablet) {
            $classes[] = 'hope-tablet';
        }
        
        return implode(' ', $classes);
    }
    
    /**
     * Get viewport configuration
     */
    public function get_viewport_config() {
        $config = array(
            'initial_zoom' => 1.0,
            'max_seats' => 10,
            'seat_size' => 12,
            'enable_pan' => true,
            'enable_zoom' => true
        );
        
        if ($this->is_mobile) {
            $config['initial_zoom'] = 0.8;
            $config['max_seats'] = 6;
            $config['seat_size'] = 16; // Larger touch targets
            $config['enable_haptic'] = true;
        } elseif ($this->is_tablet) {
            $config['initial_zoom'] = 1.2;
            $config['max_seats'] = 8;
            $config['seat_size'] = 14;
            $config['enable_haptic'] = true;
        } else {
            $config['initial_zoom'] = 1.5; // Desktop starts zoomed in
        }
        
        return $config;
    }
    
    /**
     * Should use simplified interface
     */
    public function use_simplified_interface() {
        // Use simplified interface for small mobile devices
        if ($this->is_mobile) {
            $screen_width = $this->get_screen_width();
            return $screen_width < 375; // iPhone SE and smaller
        }
        return false;
    }
    
    /**
     * Get estimated screen width
     */
    private function get_screen_width() {
        // Try to detect common device widths from user agent
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        
        // iPhone detection
        if (preg_match('/iPhone/', $user_agent)) {
            if (preg_match('/iPhone\s+(SE|5|4)/', $user_agent)) {
                return 320;
            } elseif (preg_match('/iPhone\s+(6|7|8)(?!\s*Plus)/', $user_agent)) {
                return 375;
            } elseif (preg_match('/iPhone\s+(6|7|8)\s+Plus/', $user_agent)) {
                return 414;
            } else {
                return 390; // Default modern iPhone
            }
        }
        
        // Android detection (approximate)
        if (preg_match('/Android/', $user_agent)) {
            if (preg_match('/Mobile/', $user_agent)) {
                return 360; // Common Android phone width
            }
        }
        
        // Default mobile width
        return 375;
    }
    
    /**
     * Get touch event names
     */
    public function get_touch_events() {
        if ($this->is_touch_device()) {
            return array(
                'start' => 'touchstart',
                'move' => 'touchmove',
                'end' => 'touchend',
                'cancel' => 'touchcancel'
            );
        } else {
            return array(
                'start' => 'mousedown',
                'move' => 'mousemove',
                'end' => 'mouseup',
                'cancel' => 'mouseleave'
            );
        }
    }
    
    /**
     * Should preload assets
     */
    public function should_preload_assets() {
        // Don't preload on slow mobile connections
        if ($this->is_mobile) {
            return $this->is_fast_connection();
        }
        return true;
    }
    
    /**
     * Check if fast connection (basic detection)
     */
    private function is_fast_connection() {
        // Check for slow connection hints
        if (isset($_SERVER['HTTP_SAVE_DATA']) && $_SERVER['HTTP_SAVE_DATA'] === 'on') {
            return false;
        }
        
        // Check connection type if available
        if (isset($_SERVER['HTTP_CONNECTION'])) {
            $connection = strtolower($_SERVER['HTTP_CONNECTION']);
            if (strpos($connection, '2g') !== false || strpos($connection, 'slow') !== false) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get performance settings
     */
    public function get_performance_settings() {
        $settings = array(
            'enable_animations' => true,
            'animation_duration' => 300,
            'debounce_delay' => 100,
            'lazy_load' => false
        );
        
        if ($this->is_mobile && !$this->is_fast_connection()) {
            $settings['enable_animations'] = false;
            $settings['animation_duration'] = 0;
            $settings['debounce_delay'] = 200;
            $settings['lazy_load'] = true;
        } elseif ($this->is_mobile) {
            $settings['animation_duration'] = 200;
            $settings['debounce_delay'] = 150;
        }
        
        return $settings;
    }
    
    /**
     * Add device-specific body classes
     */
    public function add_body_classes($classes) {
        $device_classes = $this->get_device_classes();
        $classes_array = explode(' ', $device_classes);
        
        foreach ($classes_array as $class) {
            $classes[] = $class;
        }
        
        return $classes;
    }
}