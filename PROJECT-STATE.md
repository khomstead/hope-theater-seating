# HOPE Theater Seating - Current Project State

**Last Updated:** 2026-01-22
**Current Version:** 2.8.23
**Primary Developer:** Kyle Homstead
**AI Assistant:** Read this file at the start of EVERY session

---

## Critical Knowledge (READ THIS FIRST)

### The Big Gotcha: "Venue" vs "Pricing Map"
**⚠️ IMPORTANT:** The meta key `_hope_seating_venue_id` actually stores the **PRICING MAP ID**, not a venue ID.

- When you see "venue_id" in code, it's usually the pricing map ID
- When working with products, `get_post_meta($product_id, '_hope_seating_venue_id', true)` returns the pricing map ID
- Don't let the naming fool you - verify by reading the actual data structure

### Data Architecture (3-Layer Separation + FooEvents Integration)

```
┌─────────────────────┐
│ Physical Seats      │ ← Fixed theater layout (seats, positions, accessibility)
│ Table: physical_seats│   497 seats total, never changes per booking
└─────────────────────┘
         ↓ linked by seat_id
┌─────────────────────┐
│ Pricing Maps        │ ← Define which seats cost what (P1, P2, P3, AA tiers)
│ Table: seat_pricing │   Multiple maps possible (Standard, VIP, Discount)
└─────────────────────┘
         ↓ referenced by product
┌─────────────────────┐
│ Bookings/Orders     │ ← Actual customer purchases
│ Table: bookings     │   Links seat_id to order_id and product_id
└─────────────────────┘
         ↓ creates
┌─────────────────────────────────────────────────────────────┐
│ FooEvents Tickets (CRITICAL - Added v2.7.0)                 │
│ Table: wp_posts (post_type='event_magic_tickets')          │
│ ⚠️ Has SEPARATE metadata from order items!                  │
│ Used for: Customer emails, Check-in app, PDF tickets       │
│                                                             │
│ MUST be synchronized when:                                 │
│ - Seats are reassigned (update ticket metadata)            │
│ - Orders are refunded (trash ticket posts)                 │
│ - Any seat changes (keep 3 sources in sync)                │
└─────────────────────────────────────────────────────────────┘
```

**NEVER query `wp_hope_seating_physical_seats` directly for available seats.**

**ALWAYS use:** `HOPE_Pricing_Maps_Manager::get_seats_with_pricing($pricing_map_id)`

### Why This Matters

❌ **Wrong approach:**
```php
$seats = $wpdb->get_results("SELECT * FROM wp_hope_seating_physical_seats");
```

✅ **Correct approach:**
```php
$pricing_map_id = get_post_meta($product_id, '_hope_seating_venue_id', true);
$pricing_manager = new HOPE_Pricing_Maps_Manager();
$seats = $pricing_manager->get_seats_with_pricing($pricing_map_id);
```

---

## Current Architecture Patterns

### Modal System

The plugin uses a shared `HOPESeatMap` JavaScript class that can work in different contexts:

**Frontend (Customer Booking):**
- Container: `#seat-map`
- Wrapper: `#seating-wrapper`
- Modal: `#hope-seat-modal`

**Admin (Seat Blocking):**
- Container: `#admin-seat-map`
- Wrapper: `#admin-seating-wrapper`
- Modal: `#hope-admin-seat-modal`

**Admin (Seat Reassignment):**
- Container: `#admin-seat-map` (reuses blocking modal structure)
- Wrapper: `#admin-seating-wrapper`
- Modal: `#hope-admin-seat-modal`

**Key Insight:** The `HOPESeatMap` class was originally hardcoded to use `#seat-map`. We modified it (v2.4.9) to use `this.containerId` so it can work in admin contexts. The class checks for this property and falls back to `'seat-map'` if not set.

### AJAX Configuration Pattern

When instantiating `HOPESeatMap` for admin use, set the `ajax` property:

```javascript
window.seatMapInstance = new HOPESeatMap();
window.seatMapInstance.containerId = 'admin-seat-map';
window.seatMapInstance.ajax = {
    ajax_url: ajaxurl,
    nonce: hope_ajax.nonce,
    venue_id: pricingMapId,    // ← Actually pricing map ID!
    product_id: eventId,
    event_id: eventId,
    session_id: 'admin_' + Date.now(),
    admin_mode: true
};
```

**Why:** The class uses `this.ajax || hope_ajax` to get config. Admin contexts need custom config since global `hope_ajax` is set for frontend.

---

## File Organization

### Core Classes (includes/)

**Data Management:**
- `class-database.php` - Main database schema and tables
- `class-database-selective-refunds.php` - Extends schema for refunds/blocking
- `class-physical-seats.php` - Physical seat layout (497 seats)
- `class-seat-maps.php` - Pricing maps management
- `class-pricing-maps-manager.php` - **USE THIS** for getting seats with pricing

**Frontend:**
- `class-frontend.php` - Customer-facing seating selection
- `class-woocommerce-integration.php` - Cart, checkout, order processing

**Admin:**
- `class-admin.php` - Admin menu and settings
- `class-admin-seat-blocking.php` - Block seats for events
- `class-admin-selective-refunds.php` - Partial refunds AND seat reassignment

**Integration:**
- `class-integration.php` - Main plugin orchestration
- `class-modal-handler.php` - Modal UI components

### JavaScript (assets/js/)

- `seat-map.js` - **Main seat selection library** (HOPESeatMap class)
- `modal-handler.js` - Modal open/close behavior
- `frontend.js` - Customer-side interactions

### Important: `seat-map.js` Changes (v2.4.9)

We modified this file to support admin contexts:
- Line 94: Uses `this.containerId || 'seat-map'`
- Line 119: Uses `this.ajax || hope_ajax`
- Line 441: Uses `this.containerId || 'seat-map'`
- Line 658: Uses `this.maxSeats || 10`
- Line 708: Uses `this.containerId || 'seat-map'`

**Do NOT revert these changes** - they enable admin functionality.

---

## Active Features

### ✅ Complete (v2.7.1 - CRITICAL FIXES)

**Seat Reassignment:**
- Location: WooCommerce order edit screen → "Theater Seat Management" meta box
- Function: Admin can reassign customer seats using visual seat map
- Implementation: `includes/class-admin-selective-refunds.php`
- Documentation: `docs/SEAT-REASSIGNMENT.md`, `docs/DATA_STRUCTURE.md` (FooEvents section)
- Behavior: Single seat selection only, auto-deselects previous seat
- **v2.6.0 Fix:** Triggers `woocommerce_order_item_meta_updated` action for ticket regeneration
- **v2.7.0 CRITICAL Fix:** Updates FooEvents ticket metadata (not just order metadata!)
  - Updates `fooevents_seat_number_0`, `fooevents_seat_row_name_0` on ticket post
  - Updates `WooCommerceEventsSeatingFields` serialized array
  - Ensures customer emails and check-in app show correct seat
- **v2.7.1 Enhancement:** Adds detailed order notes with admin name and timestamp

**Seat Blocking:**
- Location: Admin menu → Seat Blocking
- Function: Admin can block seats to prevent customer booking
- Implementation: `includes/class-admin-seat-blocking.php`

**Selective Refunds:**
- Location: WooCommerce order edit screen → "Refund Selected Seats" button
- Function: Refund individual seats instead of entire order
- Implementation: `includes/class-selective-refund-handler.php`, `includes/class-admin-selective-refunds.php`
- Features:
  - Partial refund support (refund some seats, keep others)
  - Guest list mode (refund but keep seats held for comps)
  - **v2.7.1 Enhancement:** Comprehensive order notes with amounts, admin name, refund type

**Full Order Refunds:**
- Triggered by: WooCommerce refund system
- Implementation: `includes/class-refund-handler.php`
- **v2.5.3-2.5.4 CRITICAL Fix:** Partial refunds (e.g., parking only) no longer release theater seats
  - Only releases seats when 100% of order is refunded
  - Prevented duplicate seat sales bug
- **v2.7.0 CRITICAL Fix:** Trashes FooEvents tickets on full refund
  - Changes ticket `post_status` to 'trash'
  - Prevents refunded customers from checking in
  - Tickets can be recovered if refund was mistaken
- **v2.7.1 Enhancement:** Order notes document seat release and ticket cleanup

### 🚧 Known Issues

1. **Page Reload Timing:** Inconsistent page reload behavior during seat reassignment
   - Impact: Low (cosmetic only, reassignment completes successfully)
   - Not critical for production

2. **Naming Confusion:** Many functions/variables use "venue" when they mean "pricing map"
   - Impact: High (confusing for developers)
   - Cleanup needed but risky to change everywhere

3. **Old Commented Code:** Several files have large blocks of commented code
   - Files affected: `class-woocommerce-integration.php`, `class-frontend.php`
   - Should be removed but needs careful review

---

## Misleading Code (Needs Cleanup Eventually)

### Variable Naming Issues

**File: `includes/class-admin-selective-refunds.php`**
- Line 736: `const venueId = venueResponse.data.venue_id;` ← Actually pricing map ID
- Line 1202+: Function `ajax_get_event_venue()` ← Returns pricing map ID, not venue

**File: `includes/class-frontend.php`**
- Line 337: `$pricing_map_id = isset($_POST['venue_id'])` ← POST param named venue_id
- Line 662: `$venue_id = get_post_meta($product_id, '_hope_seating_venue_id', true);` ← Gets pricing map

### Why We Haven't Fixed It

Changing these would require updating:
- JavaScript AJAX calls
- PHP AJAX handlers
- Database meta key names (would break existing sites)
- All documentation

**Decision:** Live with the naming inconsistency, document it clearly instead.

### Commented Code to Remove (When Safe)

**File: `includes/class-woocommerce-integration.php`**
- Lines ~500-600: Old seat metadata generation (replaced in v2.3.0)
- Keep until fully confident new system works in production

**File: `includes/class-frontend.php`**
- Lines ~650-685: Debug logging from seat selection fixes
- Can remove debug logs after v2.4.9 proves stable

---

## Development Workflow Lessons Learned

### What Works

1. **Research before coding:** Use Grep/Read to find existing patterns
2. **Explain approach first:** Get approval before implementing
3. **Small commits:** One feature at a time with clear commit messages
4. **Comprehensive docs:** Write documentation as you code

### What Doesn't Work

1. **Assuming variable names are accurate:** Always verify what data a variable actually holds
2. **Quick fixes without understanding:** Leads to compatibility issues later
3. **Implementing first, asking later:** Wastes time troubleshooting preventable issues
4. **Trusting AI memory:** AI has no memory between sessions, must re-learn every time

### Common Pitfalls

**"Let me quickly add..."**
- Stop. There are no quick adds in an unfamiliar codebase.
- Read related code first, understand the pattern, then implement.

**"I'll create a new function for..."**
- Stop. Search if that function already exists under a different name.
- Check all related files, not just the obvious one.

**"That variable name suggests..."**
- Stop. Don't trust variable names in this project.
- Read the actual code to see what data it holds.

---

## Database Schema Quick Reference

### Tables

**Physical Seats** (`wp_hope_seating_physical_seats`)
- `seat_id` (e.g., "C1-5") - Primary identifier
- `section`, `row_number`, `seat_number` - Components of seat_id
- `x_coordinate`, `y_coordinate` - Position on visual map
- `is_accessible` - Whether it's an AA (accessible) seat
- Total: 497 seats (never changes)

**Seat Pricing** (`wp_hope_seating_seat_pricing`)
- `pricing_map_id` - Links to pricing maps
- `seat_id` - Links to physical seats
- `pricing_tier` - P1, P2, P3, or AA
- `price` - Override price (NULL = use tier default)

**Pricing Maps** (`wp_hope_seating_pricing_maps`)
- `id` - This is what `_hope_seating_venue_id` stores!
- `name` - e.g., "Standard Pricing", "VIP Pricing"
- `total_seats` - Should match physical seats count

**Bookings** (`wp_hope_seating_bookings`)
- `seat_id` - Which seat (links to physical_seats)
- `order_id` - Which WooCommerce order (references HPOS `wp_wc_orders.id`, NOT `wp_posts.ID`)
- `product_id` - Which event/product
- `status` - active, confirmed, pending, refunded
- `refund_id` - If refunded (added in v2.4.7)

**⚠️ CRITICAL - HPOS (High-Performance Order Storage)**
- Site uses WooCommerce HPOS (enabled)
- Order data is in `wp_wc_orders` table, NOT `wp_posts`
- `wp_posts` only contains placeholder/draft records for HPOS orders
- **Always join bookings to `wp_wc_orders`** to get real order status
- Order statuses: `wc-processing`, `wc-completed`, `wc-on-hold`, `wc-refunded`, etc.
- **Never use** `wp_posts.post_status` for order status checks - it will always show 'draft'

### Meta Keys (WooCommerce Products)

- `_hope_seating_enabled` - "yes" or "no"
- `_hope_seating_venue_id` - **ACTUALLY STORES PRICING MAP ID**

### Meta Keys (WooCommerce Order Items)

- `_fooevents_seats` - Comma-separated seat IDs (e.g., "C1-5,C1-6")
- `_hope_seat_summary` - Display text (e.g., "Orchestra C1-5, Orchestra C1-6")
- `_fooevents_seat_row_name_0` - First seat section+row (e.g., "Orchestra C1")
- `_fooevents_seat_number_0` - First seat number (e.g., "5")
- `_hope_tier` - Pricing tier for order item

---

## Testing Requirements

Before committing any changes that affect core functionality:

### Seat Selection (Frontend)
- [ ] Customer can select seats on product page
- [ ] Booked seats show as unavailable
- [ ] Selected seats persist through cart/checkout
- [ ] Pricing tier reflected in cart total

### Seat Reassignment (Admin)
- [ ] Modal opens with correct pricing map seats
- [ ] Available vs booked seats display correctly
- [ ] Can only select one seat at a time
- [ ] Reassignment updates database correctly
- [ ] FooEvents tickets regenerate automatically
- [ ] Works with HPOS orders

### Seat Blocking (Admin)
- [ ] Can block individual seats
- [ ] Blocked seats unavailable to customers
- [ ] Blocked seats visible in admin interfaces
- [ ] Can unblock seats

---

## Next Priorities

1. **Production Testing** - Monitor v2.4.9 seat reassignment in real use
2. **Performance** - Profile seat availability queries under load
3. **Cleanup** - Remove commented code after stability confirmed
4. **Documentation** - Video walkthrough for client training

---

## Questions to Ask Before Changing Code

1. **Does this pattern already exist?** (Search first)
2. **Am I assuming what a variable contains?** (Verify by reading)
3. **Will this break HPOS compatibility?** (Test both order types)
4. **Does this affect FooEvents integration?** (Check ticket generation)
5. **Am I creating technical debt?** (Document it if yes)
6. **Have I updated relevant documentation?** (Do it now, not later)

---

## Emergency Contacts

**If something breaks in production:**
1. Check WordPress debug.log
2. Check browser console for JavaScript errors
3. Check recent git commits for what changed
4. Rollback to previous version if critical

**Common Debug Flags:**
```php
// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

**Database Backup Before Schema Changes:**
```bash
wp db export backup-$(date +%Y%m%d-%H%M%S).sql
```

---

## Critical: FooEvents Ticket Integration (Added v2.7.0)

**THE PROBLEM WE DISCOVERED:**

FooEvents creates separate ticket posts (`wp_posts.post_type = 'event_magic_tickets'`) with their OWN metadata, completely independent of WooCommerce order item metadata.

**Three Separate Data Sources (Must Stay Synchronized):**

1. **HOPE Booking Table** (`wp_hope_seating_bookings`)
   - `seat_id` column - source of truth for reservations

2. **WooCommerce Order Metadata** (`wp_woocommerce_order_itemmeta`)
   - `_fooevents_seats`, `_fooevents_seat_number_0`, etc.
   - Used during checkout/order processing

3. **FooEvents Ticket Metadata** (`wp_postmeta` for ticket posts) **← CRITICAL!**
   - `fooevents_seat_number_0` - seat number
   - `fooevents_seat_row_name_0` - row display name
   - `WooCommerceEventsSeatingFields` - serialized seat data
   - **THIS** is what customer emails display
   - **THIS** is what check-in app reads
   - **THIS** is what PDF tickets show

**Why This Matters:**

Before v2.7.0:
- ❌ Seat reassignment updated booking table + order metadata only
- ❌ Ticket metadata remained stale
- ❌ Customers received emails with WRONG seat info
- ❌ Check-in app showed WRONG seats
- ❌ Refunded tickets remained published and could still check in

After v2.7.0+:
- ✅ Seat reassignment updates ALL THREE sources
- ✅ `update_fooevents_ticket_metadata()` method synchronizes ticket posts
- ✅ Full refunds trash ticket posts (prevents check-in)
- ✅ All actions create order notes for audit trail

**Implementation Files:**
- `class-admin-selective-refunds.php::update_fooevents_ticket_metadata()` - Seat reassignment ticket sync
- `class-refund-handler.php::trash_fooevents_tickets()` - Refund ticket cleanup

**Documentation:**
- See `docs/DATA_STRUCTURE.md` → "FooEvents Ticket Integration" section
- See `/Users/kyle/Desktop/CRITICAL-TICKET-METADATA-RELATIONSHIP.md`

## Session Management & Hold System (Added v2.8.13-2.8.14)

### Critical Understanding: Holds vs. Bookings

**Holds** (`wp_hope_seating_holds`)
- Temporary seat reservations during shopping/checkout
- Expire after configured duration (default: 10 minutes)
- Linked by `session_id` (NOT user_id - supports guests)
- Prevent double-booking during checkout process

**Bookings** (`wp_hope_seating_bookings`)
- Permanent seat assignments after order completion
- Linked by `order_id` and `product_id`
- Created when order is processed/completed
- Never expire (until refunded)

### Session ID Storage Strategy

**The Problem:** PHP session cookies don't persist in incognito/privacy mode between AJAX calls and page redirects.

**The Solution (v2.8.14):** Store session ID in cart item metadata, not just PHP session:

```php
// When creating holds (class-ajax-handler.php)
$cart_item_data = [
    'hope_session_id' => $session_id,  // ← Stored in cart
    'hope_theater_seats' => $seats,
    // ...
];
```

**Validation Pattern:**
```php
// WRONG (v2.8.12 and earlier):
$session_id = HOPE_Session_Manager::get_current_session_id(); // New ID in incognito!

// CORRECT (v2.8.14+):
$cart_item_session_id = $cart_item['hope_session_id']; // From cart metadata
```

**Files That Use This Pattern:**
- `class-woocommerce-integration.php::validate_cart_seat_holds()` - Extends holds using cart item session IDs
- `class-woocommerce-integration.php::validate_checkout_seat_holds()` - Validates using cart item session IDs
- `class-ajax-handler.php::ajax_add_to_cart()` - Stores session ID in cart metadata

**Why This Matters:**
- ✅ Works in incognito/private browsing
- ✅ Works when cookies are blocked
- ✅ Works across page redirects
- ✅ Supports multiple sessions (if user has multiple tabs)

### Session ID Regeneration Security (v2.8.13)

Changed `session_regenerate_id(true)` to `session_regenerate_id(false)` when existing session data detected:

```php
// class-session-manager.php::get_current_session_id()
if ($has_existing_session) {
    session_regenerate_id(false); // Don't destroy old session file
} else {
    session_regenerate_id(true);  // New session, safe to destroy
}
```

**Why:** The `true` parameter destroys the session file, which breaks hold lookups if session isn't fully connected yet.

## Version History

**v2.8.23** (2026-01-22) - **CRITICAL:** Fix seat selection - delay DOM restoration on hover
**v2.8.22** (2026-01-22) - Improved mobile detection for Windows PCs
**v2.8.14** (2025-11-17) - **CRITICAL:** Fix incognito mode session cookie issue
**v2.8.13** (2025-11-17) - **CRITICAL:** Fix session regeneration destroying hold data
**v2.8.12** (2025-01-17) - Fix seat blocking modal DOM layering
**v2.8.11** (2025-01-17) - **CRITICAL:** Fix seat blocking AJAX conflict (broken since v2.7.8)
**v2.8.10** (2025-01-16) - Improve seat blocking error messages
**v2.8.9** (2025-01-16) - **CRITICAL:** Auto-extend holds during checkout
**v2.8.8** (2025-01-16) - Fix admin seat blocking modal close button
**v2.8.7** (2025-01-16) - **CRITICAL:** Prevent seats removed during checkout redirect
**v2.8.6** (2025-01-16) - Vomitorium divider hover effects
**v2.8.5** (2025-01-16) - Fix vomitorium divider tooltips
**v2.8.4** (2025-01-16) - Add vomitorium divider visual indicators
**v2.8.3** (2025-01-16) - Reduce angular gaps between orchestra sections
**v2.8.2** (2025-01-16) - Document overflow seating design decision
**v2.8.1** (2025-01-16) - Fix overflow seat visibility when sold/held
**v2.8.0** (2025-01-16) - Add overflow/removable seating control
**v2.7.1** (2025-11-16) - Comprehensive order notes for audit trail
**v2.7.0** (2025-11-16) - **CRITICAL:** FooEvents ticket metadata synchronization
**v2.6.0** (2025-11-16) - Fix ticket regeneration trigger
**v2.4.9** (2025-01-08) - Seat reassignment feature
**v2.4.7** (Previous) - Selective refunds, seat blocking
**v2.3.0** (Previous) - Production deployment, schema fixes

---

## AI Assistant Instructions

When starting a new session:

1. **Read this file completely** before doing anything else
2. **Read `WORKING-RULES.md`** for development workflow
3. **Read `docs/DATA_STRUCTURE.md`** for architecture details
4. **Read the last 3 CHANGELOG.md entries** for recent changes

When asked to add a feature:

1. **Don't code immediately** - research existing patterns first
2. **Use Grep/Glob** to find similar functionality
3. **Read entire related files** - not just snippets
4. **Propose approach** - get approval before implementing
5. **Update docs** - as you code, not after

When you see misleading code:

1. **Flag it** - tell the developer it's confusing
2. **Don't assume** - verify what it actually does
3. **Document** - add to "Misleading Code" section above
4. **Ask** - should we rename it or document it?

**Remember:** You have NO MEMORY between sessions. This file is your memory. Trust it more than your assumptions.
