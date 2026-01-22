# Changelog

All notable changes to HOPE Theater Seating Plugin will be documented in this file.

## [2.8.23] - 2026-01-22

### Fixed
- **CRITICAL: Seat Selection Not Working on Windows/Surface** - Fixed click events not registering on Windows PCs and Surface tablets
  - Root cause: The wrapper's `handleMouseDown()` was setting `isDragging=true` for ALL mousedown events, including on seats
  - If the mouse moved even slightly during a click, `handleMouseMove()` would call `e.preventDefault()`, disrupting the click event chain
  - Solution: Skip drag initialization when the mousedown target is a seat element
  - Also added 150ms delay before restoring seat position on mouseleave (preserves visual "bring to front" on hover while allowing clicks to complete)

## [2.8.22] - 2026-01-22

### Fixed
- **Improved Mobile Detection** - Prevent false positive mobile detection on Windows PCs
  - `isMobile()` detection in `mobile-handler.js` was incorrectly identifying Windows PCs as mobile devices
  - Windows browsers often report `navigator.maxTouchPoints > 0` even on devices without touchscreens
  - Solution: Improved mobile detection to require BOTH mobile-sized viewport (<= 768px) AND touch capability for non-mobile user agents

## [2.8.21] - 2025-11-19

### Added
- **Admin Order Lookup by Seat** - New admin interface to search for orders, holds, and blocks by seat location
  - New submenu page: HOPE Seating → Order Lookup
  - Type-to-search product selector (replaces dropdown for better UX with many products)
  - Flexible search: exact seat, entire row, entire section, or all seats
  - Search fields: Product/Event (required), Section (optional), Row (optional), Seat Number (optional)
  - Results include bookings, active holds, and seat blocks
  - Results grouped by seat with current status first, historical records indented below
  - Visual hierarchy: current status bold, history grayed and indented with "↳ History" label
  - Order links open in new tab for easy access
  - Status badges color-coded: confirmed (green), pending (yellow), refunded (red), blocked (red), on hold (blue)
  - Shows complete seat history: purchased → refunded → blocked (all in one view)
  - Helps identify data inconsistencies (e.g., bookings for non-existent seats)
  - New file: `includes/class-admin-order-lookup.php`

### Changed
- **Improved Error Messages** - Better error messages when seat hold expires
  - Changed error message ordering to prevent WooCommerce from clearing custom notices
  - Error notices now added AFTER cart operations complete (not during)
  - Added missing `'partially_refunded'` status to cart validation query

### Fixed
- **Cart Validation** - Fixed error messages not displaying when holds expire
  - Root cause: `wc_add_notice()` was being called before `WC()->cart->remove_cart_item()`
  - `WC()->cart->calculate_totals()` was clearing notices added earlier
  - Solution: Collect error messages in array, remove items, THEN add notices
  - Now shows specific seat numbers and product names in error messages

## [2.8.20] - 2025-11-17

### Removed
- **Rollback: Checkout Countdown Timer** - Removed checkout countdown timer feature due to checkout page errors
  - Removed `display_checkout_countdown_timer()` method from WooCommerce integration
  - Removed `woocommerce_before_checkout_form` hook registration
  - Removed hold expiration query from add_to_cart AJAX handler
  - Removed `hope_hold_expires_at` from cart item metadata
  - Feature was causing "critical error" blank pages on checkout
  - Will revisit implementation approach in future version

## [2.8.19] - 2025-11-17

### Fixed
- **CRITICAL: Missing global $wpdb declaration** - Fixed fatal error preventing add to cart
  - Root cause: `add_to_cart()` method was using `$wpdb` without declaring it as global
  - Caused 500 Internal Server Error when adding seats to cart
  - Server returned HTML error page instead of JSON response
  - Added `global $wpdb;` at beginning of `add_to_cart()` method
  - Bug introduced in v2.8.17 when adding hold expiration query

## [2.8.18] - 2025-11-17

### Fixed
- **HOTFIX: Add to Cart Error** - Fixed undefined variable `$holds_table` in AJAX handler
  - Missing table name definition when querying hold expiration time
  - Caused "An error occurred while adding seats to cart" message
  - Added `$holds_table = $wpdb->prefix . 'hope_seating_holds';` before query

## [2.8.17] - 2025-11-17

### Added
- **Checkout Countdown Timer** - Displays seat hold expiration timer on checkout page
  - Prominent countdown displayed above checkout form
  - Shows exact time remaining (e.g., "9:45")
  - Turns red when under 2 minutes remaining
  - Auto-refreshes page when timer expires with helpful message
  - Carries hold expiration from seat selection modal through to checkout
  - Reduces customer confusion about reservation duration
  - Stored in cart metadata (`hope_hold_expires_at`) for persistence across sessions

## [2.8.16] - 2025-11-17

### Improved
- **Better Error Messages for Expired Holds** - Improved user experience when seat reservations expire
  - Shows specific seat numbers in error messages (e.g., "Seats F3-2, F3-3 for...")
  - Clearer instructions: "Please return to the event page and select your seats again"
  - Explains why hold expired: "has expired or was taken by another customer"
  - Forces cart totals recalculation to immediately reflect removed items
  - Replaces generic WooCommerce message with specific, actionable feedback

## [2.8.15] - 2025-11-17

### Changed
- **Code Cleanup** - Removed verbose debug logging from v2.8.13-2.8.14
  - Removed session management debug logs (10 log statements)
  - Removed cart validation verbose logs (6 log statements)
  - Kept only critical error logging (session missing, checkout blocked)
  - Improves performance and reduces log file size

## [2.8.14] - 2025-11-17

### Fixed
- **CRITICAL: Incognito Mode Session Cookie Issue** - Fixed holds expiring in incognito/privacy browsing mode
  - Root cause: PHP session cookies not persisting between AJAX calls and page redirects in incognito mode
  - Session ID stored in cart item (`hope_session_id`) but validation was using current PHP session ID (different)
  - Solution: Retrieve session ID from cart item data instead of relying on PHP session persistence
  - Updated `validate_cart_seat_holds()` to collect all session IDs from cart items and extend holds for all
  - Updated `validate_checkout_seat_holds()` to use cart item's session ID for hold lookups
  - Now works correctly in incognito mode, privacy browsing, and when cookies are blocked
  - Fixes "items expiring immediately" issue reported in production

## [2.8.13] - 2025-11-17

### Fixed
- **CRITICAL: Session Data Loss During Checkout** - Fixed session regeneration destroying hold data
  - Root cause: `session_regenerate_id(true)` was destroying session file when reinitializing
  - When checkout page loaded, if PHP session wasn't active, would start session and immediately destroy it
  - This created NEW `hope_seating_session_id`, making hold lookups fail
  - Solution: Changed to `session_regenerate_id(false)` when existing session data detected
  - Preserves session file while still regenerating ID for security against fixation attacks
  - Added extensive debug logging to track session ID through entire checkout flow
  - Logs now show: session creation, PHP session_id, hope_seating_session_id, regeneration events
  - This was the root cause of "items expiring immediately" issue

## [2.8.12] - 2025-01-17

### Fixed
- **Seat Blocking Modal DOM Layering** - Fixed SVG canvas dragging over controls when panning/zooming
  - Added `overflow: hidden` to `.seating-container` to clip SVG bounds
  - Set `z-index: 1` on `.seating-wrapper` (SVG layer)
  - Set `z-index: 5` on `.header` (floor selector/controls)
  - Set `z-index: 10` on `.zoom-controls` (already present)
  - Now matches frontend modal behavior where controls stay above seating canvas

## [2.8.11] - 2025-01-17

### Fixed
- **CRITICAL: Seat Blocking Broken Since v2.7.8** - Fixed AJAX action name conflict preventing seat blocking from working
  - Root cause: Both `class-admin-seat-blocking.php` and `class-admin-selective-refunds.php` registered `wp_ajax_hope_get_event_venue`
  - Last registered handler (seat reassignment) overrode seat blocking handler
  - Result: Seat blocking AJAX calls were routed to wrong handler, failed with "No venue configured"
  - Solution: Renamed seat blocking action to `hope_get_event_venue_blocking` (unique name)
  - Updated all 4 JavaScript AJAX calls in seat blocking to use new action name
  - Added extensive debug logging to troubleshoot AJAX flow
  - Bug introduced in v2.7.8 when seat reassignment was updated to find correct seating product

## [2.8.10] - 2025-01-16

### Improved
- **Admin Seat Blocking Error Messages** - Better error messaging when product doesn't have seat map configured
  - Changed generic "No venue configured" to specific product name
  - Added instructions on how to fix: Edit product → HOPE Theater Seating tab → Select seat map
  - Error now shows which product is missing configuration
  - Helps admins quickly identify and fix configuration issues

## [2.8.9] - 2025-01-16

### Fixed
- **CRITICAL: Hold Expiration During Checkout** - Fixed hold expiration issue causing no sales since v2.8.7
  - Root cause: Holds were only extended when adding to cart, not while on checkout page
  - Holds would expire if customer spent more than hold_duration on checkout page
  - Solution: Auto-extend all session holds on every cart/checkout page load
  - Modified `validate_cart_seat_holds()` to refresh holds BEFORE validation
  - Holds now stay active as long as customer is engaged with checkout
  - Extends by full hold_duration (15 min default) on each page view
  - Error log: "HOPE CART: Extended X seat holds" confirms active extension

## [2.8.8] - 2025-01-16

### Fixed
- **Admin Seat Blocking Modal** - Fixed JavaScript error preventing admin seat blocking modal from closing
  - Made `closeAdminSeatModal()` function global to support inline onclick handlers
  - Error: "ReferenceError: Can't find variable: closeAdminSeatModal" resolved
  - Function was previously scoped inside jQuery.ready(), inaccessible to onclick attributes

## [2.8.7] - 2025-01-16

### Fixed
- **CRITICAL: Seats Removed During Checkout** - Fixed critical bug where seats were being removed from cart before checkout page loaded
  - Root cause: Seat holds were expiring during redirect from product page to checkout
  - Solution: Extended hold expiration by full hold duration (15 minutes) when adding seats to cart
  - Added `extend_hold_expiration()` method in AJAX handler to refresh hold timers before checkout redirect
  - Prevents validation failures in `validate_checkout_seat_holds()` that were removing cart items
  - Error log: "HOPE CHECKOUT BLOCKED: No valid hold for seat..." should no longer occur during normal checkout flow

## [2.8.6] - 2025-01-16

### Changed
- **Vomitorium Divider Hover Effects**: Improved visual feedback when hovering over divider lines
  - Lines now glow orange (#ff8c00) and brighten on hover, matching seat hover behavior
  - Stroke width increases from 8px to 10px on hover
  - Added drop shadow effect for visual prominence
  - Changed cursor from 'help' to 'pointer' to match seat interaction pattern

## [2.8.5] - 2025-01-16

### Fixed
- **Vomitorium Divider Tooltips**: Fixed tooltip functionality for vomitorium divider lines
  - Added `showDividerTooltip()` method for simple text tooltips
  - Dividers now display "Not an aisle" tooltip on hover
  - Original `showTooltip()` method was designed for seat elements with data attributes

## [2.8.4] - 2025-01-16

### Added
- **Vomitorium Divider Visual Indicators**: Added visual divider lines to distinguish vomitorium ramps from aisles
  - Solid gray lines (8px width, #666 color, 0.6 opacity) between sections A-B and D-E
  - Lines positioned to clearly indicate physical separation
  - Added tooltip support with "Not an aisle" message

## [2.8.3] - 2025-01-16

### Changed
- **Seat Map Layout Refinement**: Reduced angular gaps between orchestra sections
  - Sections A-B gap reduced from 4° to 0° (startAngle/endAngle: -54° → -50°)
  - Sections D-E gap reduced from 4° to 0° (startAngle/endAngle: 54° → 50°)
  - Improves visual clarity that vomitorium ramps are not aisles

## [2.8.2] - 2025-01-16

### Documentation
- **Overflow Seating Design Decision**: Added comprehensive documentation explaining why `is_overflow` is stored at the physical seat level rather than pricing tier level
  - Documents that overflow represents physical theater limitation (removable chairs)
  - Explains this applies to all pricing maps since it reflects actual theater configuration

## [2.8.1] - 2025-01-16

### Fixed
- **Overflow Seat Visibility**: Overflow seats that are sold or held now always display, even when "Hide Overflow" toggle is enabled
  - Prevents confusion for customers who have purchased overflow seats
  - Toggle now only affects available overflow seats

## [2.8.0] - 2025-01-16

### Added
- **Overflow/Removable Seating Control**: New admin and customer controls for handling overflow seating
  - Added "Hide Overflow" toggle on seat selection modal
  - Admin setting to enable/disable overflow toggle visibility
  - Database migration to add `is_overflow` column to physical_seats table
  - 19 specific seats in row 9 marked as removable (sections A, B, D, E)
  - Overflow seats visually distinguished with striped pattern

## [2.4.9] - 2025-01-08

### Added
- **Seat Reassignment Feature**: Administrators can now reassign customer seats from the WooCommerce order edit screen
  - Visual seat map modal for selecting new seats
  - Real-time availability checking
  - Single-seat selection enforcement (clicking second seat deselects first)
  - Automatic FooEvents ticket regeneration after reassignment
  - Comprehensive validation (seat availability, booking conflicts, admin blocks)
  - HPOS (High-Performance Order Storage) compatibility
  - Complete documentation in `docs/SEAT-REASSIGNMENT.md`

### Changed
- **HOPESeatMap Class Enhancements** (`assets/js/seat-map.js`):
  - `loadRealSeatData()` now uses instance-specific AJAX configuration via `this.ajax`
  - `showLoadingState()`, `generateTheater()`, and `handleSeatHover()` now use `this.containerId` for flexible container targeting
  - `handleSeatClick()` now respects `this.maxSeats` property for seat selection limits
  - Improved reusability for admin interfaces

### Fixed
- Seat map can now be instantiated multiple times with different container IDs
- Proper Set handling for `selectedSeats` (was incorrectly using array methods)
- Container ID flexibility allows same seat map library to work in both frontend and admin contexts

## [2.4.7] - Previous Release

### Added
- Selective refund support for individual seats
- Seat blocking functionality for administrators
- Enhanced database schema with refund tracking columns

### Changed
- Database architecture improvements for refund management
- Modal handler updates for admin seat blocking

## [2.3.0] - Previous Release

### Added
- Production deployment preparations
- Version bump for stability release

### Fixed
- Database schema bug causing connection errors during cart operations
- Theater seating layout and tooltip improvements

## [2.2.32] - Previous Release

### Changed
- Additional updates and improvements (staged automatically)

---

## Version Numbering

This project uses [Semantic Versioning](https://semver.org/):
- **MAJOR** version: Incompatible API changes
- **MINOR** version: New functionality (backwards compatible)
- **PATCH** version: Bug fixes (backwards compatible)

## Links
- [Documentation](docs/)
- [GitHub Repository](https://github.com/kylephillips/hope-theater-seating)
