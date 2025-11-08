# HOPE Theater Seating - Current Project State

**Last Updated:** 2025-01-08
**Current Version:** 2.4.9
**Primary Developer:** Kyle Homstead
**AI Assistant:** Read this file at the start of EVERY session

---

## Critical Knowledge (READ THIS FIRST)

### The Big Gotcha: "Venue" vs "Pricing Map"
**⚠️ IMPORTANT:** The meta key `_hope_seating_venue_id` actually stores the **PRICING MAP ID**, not a venue ID.

- When you see "venue_id" in code, it's usually the pricing map ID
- When working with products, `get_post_meta($product_id, '_hope_seating_venue_id', true)` returns the pricing map ID
- Don't let the naming fool you - verify by reading the actual data structure

### Data Architecture (3-Layer Separation)

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

### ✅ Complete (v2.4.9)

**Seat Reassignment:**
- Location: WooCommerce order edit screen → "Theater Seat Management" meta box
- Function: Admin can reassign customer seats using visual seat map
- Implementation: `includes/class-admin-selective-refunds.php` lines 440-920
- Documentation: `docs/SEAT-REASSIGNMENT.md`
- Behavior: Single seat selection only, auto-deselects previous seat
- Ticket Handling: Automatically regenerates FooEvents tickets

**Seat Blocking:**
- Location: Admin menu → Seat Blocking
- Function: Admin can block seats to prevent customer booking
- Implementation: `includes/class-admin-seat-blocking.php`

**Selective Refunds:**
- Location: WooCommerce order edit screen
- Function: Refund individual seats instead of entire order
- Implementation: `includes/class-admin-selective-refunds.php` (earlier lines)

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
- `order_id` - Which WooCommerce order
- `product_id` - Which event/product
- `status` - active, confirmed, pending, refunded
- `refund_id` - If refunded (added in v2.4.7)

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

## Version History

**v2.4.9** (2025-01-08) - Seat reassignment feature
**v2.4.7** (Previous) - Selective refunds, seat blocking
**v2.3.0** (Previous) - Production deployment, schema fixes
**v2.2.32** (Previous) - Layout improvements

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
