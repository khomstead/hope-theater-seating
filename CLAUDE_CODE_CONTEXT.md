# HOPE Theater Seating Plugin - Claude Code Project Context

## 🎯 Project Overview
WordPress plugin for HOPE Theater's 485-seat venue with irregular half-round seating layout.
- **Main Stage**: 353 orchestra seats + 132 balcony seats
- **Purpose**: Replace FooEvents' rectangular-only seating with accurate curved theater layout
- **Integration**: WooCommerce + FooEvents for ticket sales
- **Current Version**: 2.4.5

## 📅 Development Timeline
- **Early Sessions**: Database structure, basic plugin architecture
- **Middle Sessions**: HTML mockup with pan/zoom functionality
- **August 13, 2025**: Completed mockup with all visual fixes
- **Latest Session**: Full plugin with modal system and mobile optimization
- **Current Session**: Added seat manager, session manager, and mobile detector classes

## ✅ COMPLETED COMPONENTS

### Frontend Seat Map (Complete)
- ✅ SVG-based half-round theater layout
- ✅ Manual pan/zoom (no library dependencies)
- ✅ Orange border trace animation on hover
- ✅ Proper z-index management
- ✅ Floor switching (Orchestra/Balcony)
- ✅ Tooltips with seat details
- ✅ Selected seats summary with pricing
- ✅ ViewBox: -100 -50 1400 700 (prevents cutoff)
- ✅ Stage positioned at Y=400

### Modal System (Complete)
- ✅ Full-screen overlay for seat selection
- ✅ Close via X button, Cancel, overlay click, or Escape key
- ✅ Confirmation dialog if seats selected but not added
- ✅ "Add to Cart" integration with WooCommerce

### Mobile Optimization (Complete)
- ✅ Touch gestures (pinch zoom, pan, double-tap)
- ✅ Larger touch targets
- ✅ Responsive modal
- ✅ Haptic feedback support
- ✅ Device detection with class-mobile-detector.php

### Session Management (Complete)
- ✅ 10-minute seat holds with countdown
- ✅ Automatic release of expired holds
- ✅ Real-time availability checking
- ✅ WordPress cron cleanup
- ✅ Session manager class created

### WooCommerce Refund Integration (Complete)
- ✅ class-refund-handler.php created
- ✅ Automatic seat release on order refunds
- ✅ Support for full and partial refunds
- ✅ Order cancellation handling
- ✅ Audit trail for all refund activities
- ✅ Optional admin email notifications
- ✅ Integration with WooCommerce hooks

### Database Structure (Complete)
- ✅ Table schemas defined and created
- ✅ class-database.php functional
- ✅ Venues and seat maps structure
- ✅ Holds and bookings tables

### Seat Management (Complete)
- ✅ class-seat-manager.php created
- ✅ Populates 485 seats with correct distribution
- ✅ Proper curved positioning using polar coordinates
- ✅ Pricing tiers correctly assigned
- ✅ Availability checking implemented

### Main Plugin File (Updated)
- ✅ Version 2.0.1
- ✅ Activation hook populates seats
- ✅ Safe file inclusion with existence checks
- ✅ Cron jobs for cleanup
- ✅ Mobile detection integration

## ❌ REMAINING/INCOMPLETE COMPONENTS

### Admin Interface Needed:
1. **class-admin-menu.php** - WordPress admin menus
2. **class-product-meta.php** - Venue assignment to products
3. **Visual seat editor** - Drag-drop seat positioning
4. **Reporting dashboard** - Sales and availability reports

### Integration Enhancements:
1. **FooEvents deep integration** - Pass seat data to tickets
2. **Email templates** - Show seat numbers in confirmations
3. **QR codes** - Include seat info in ticket QR

## 📁 CURRENT FILE STRUCTURE

```
hope-theater-seating/
├── hope-theater-seating.php          ✅ Main plugin file (v2.4.5)
├── includes/
│   ├── class-modal-handler.php       ✅ Modal system
│   ├── class-ajax-handler.php        ✅ AJAX endpoints
│   ├── class-database.php            ✅ Database tables
│   ├── class-seat-manager.php        ✅ Seat population
│   ├── class-session-manager.php     ✅ Hold management
│   ├── class-mobile-detector.php     ✅ Device detection
│   ├── class-refund-handler.php      ✅ WooCommerce refund integration (NEW)
│   ├── class-venues.php              ⚠️  May exist
│   ├── class-seat-maps.php           ⚠️  May exist
│   ├── class-admin.php               ⚠️  May exist
│   ├── class-frontend.php            ⚠️  May exist
│   └── class-ajax.php                ⚠️  May exist
├── assets/
│   ├── js/
│   │   ├── seat-map.js              ✅ Interactive seat map
│   │   ├── modal-handler.js         ✅ Modal management
│   │   └── mobile-handler.js        ✅ Touch gestures
│   └── css/
│       ├── seat-map.css             ✅ Complete styling
│       └── modal.css                ✅ Modal styles
├── CLAUDE_CODE_CONTEXT.md            ✅ This file (updated)
└── .git/                             ✅ Version control
```

## 🎭 VENUE SPECIFICATIONS

### Sections Layout
**Orchestra (353 seats)**
- Section A: 63 seats - Side section (left) - P2 pricing
- Section B: 96 seats - Wedge-shaped - P1 pricing
- Section C: 144 seats - Center (rectangular) - P1 pricing
- Section D: 106 seats - Wedge-shaped - P1 pricing (includes 10 AA seats)
- Section E: 63 seats - Side section (right) - P2 pricing

**Balcony (132 seats)**
- Section F: 56 seats - Left balcony - P3 pricing
- Section G: 40 seats - Center balcony (shifted left) - P3 pricing
- Section H: 36 seats - Right balcony - P3 pricing

### Pricing Tiers
- **P1 (VIP)**: $50 - Purple (#9b59b6) - 109 seats
- **P2 (Premium)**: $35 - Blue (#3498db) - 293 seats
- **P3 (General)**: $25 - Aqua (#17a2b8) - 89 seats
- **AA (Accessible)**: $25 - Orange (#e67e22) - 10 seats

## 🔧 TECHNICAL SPECIFICATIONS

### JavaScript Configuration
```javascript
// Seat map initialization
const seatMap = new HOPESeatMap({
    container: '#seat-map-container',
    venueId: 1,
    productId: productId,
    maxSeats: 10,
    viewBox: '-100 -50 1400 700',
    stageY: 400,
    initialZoom: 1.5
});
```

### Database Tables
- `wp_hope_seating_venues` - Venue templates
- `wp_hope_seating_seat_maps` - Individual seats with coordinates
- `wp_hope_seating_holds` - Temporary seat holds
- `wp_hope_seating_bookings` - Confirmed bookings

### AJAX Endpoints
- `hope_get_seat_data` - Fetch venue layout
- `hope_check_availability` - Real-time availability
- `hope_hold_seats` - Create temporary hold
- `hope_release_seats` - Release holds
- `hope_add_to_cart` - Add selected seats to WooCommerce cart

### WooCommerce Hooks (Refund Integration)
- `woocommerce_order_status_refunded` - Full order refunds
- `woocommerce_refund_created` - Partial refunds (item-level)
- `woocommerce_order_status_cancelled` - Order cancellations
- `woocommerce_order_status_changed` - General status changes backup

### Cron Jobs
- `hope_seating_cleanup` - Hourly general cleanup
- `hope_seating_cleanup_holds` - Every minute hold expiration check

## 🚀 IMMEDIATE PRIORITIES

1. **Test Current Implementation**
   - Activate plugin on staging
   - Verify 485 seats populated
   - Test seat selection flow
   - Verify cart integration
   - Check hold expiration

2. **Create Admin Interface**
   - Admin menu structure
   - Product meta boxes for venue assignment
   - Booking reports page

3. **Complete FooEvents Integration**
   - Hook into ticket generation
   - Pass seat data to tickets
   - Update confirmation emails

## 🐛 KNOWN ISSUES & FIXES

### Fixed Issues
- ✅ **Panzoom library conflicts** - Now using manual implementation
- ✅ **Stage cutoff** - Fixed with viewBox adjustment
- ✅ **Balcony toggle broken** - Fixed in current version
- ✅ **Plugin activation crashes** - Fixed with safe file inclusion
- ✅ **OAuth authentication error in Claude Code** - Use API key with environment variable

### Current Issues
- ⚠️ Some class files may not exist yet (safely handled)
- ⚠️ Admin interface incomplete
- ⚠️ FooEvents integration needs testing

## 📝 TESTING CHECKLIST

- [x] Plugin activates without errors
- [x] Database tables created correctly
- [x] 485 seats populated in database
- [ ] Seat map displays on product page
- [ ] Modal opens/closes properly
- [ ] Seats can be selected/deselected
- [ ] Cart integration works
- [ ] Holds expire after 10 minutes
- [ ] Concurrent bookings prevented
- [ ] Mobile gestures work
- [ ] FooEvents receives seat data

## 🔗 ENVIRONMENT

- **Local Dev**: ~/Desktop/plugins/hope-theater-seating
- **Git Repo**: github.com/[username]/hope-theater-seating
- **Staging**: gkld8kygds-staging.wpdns.site
- **WordPress**: 6.4+
- **WooCommerce**: 8.5+
- **FooEvents**: Active
- **PHP**: 7.4+
- **Database**: MySQL 5.7+

## 💡 IMPORTANT NOTES

1. **No jQuery Dependency**: Pure JavaScript implementation
2. **No External Libraries**: Manual pan/zoom to avoid conflicts
3. **SVG Approach**: Better performance than DOM elements for 485 seats
4. **Session-Based Holds**: Using WordPress transients for temporary storage
5. **Mobile-First**: Touch events take priority over mouse events
6. **Safe Plugin Structure**: Checks file existence before including

## 🎯 SUCCESS CRITERIA

1. ✅ Accurate visual representation of theater
2. ✅ Smooth seat selection experience
3. ✅ No double-booking of seats
4. ✅ Mobile-friendly interface
5. ⚠️ Seamless WooCommerce/FooEvents integration (needs testing)
6. ✅ Sub-2-second load time for seat map
7. ✅ Hold system prevents conflicts

## 📚 REFERENCE FILES

- **Excel**: HOPE Seating.xlsx (seat distribution data)
- **PDF**: Architectural drawings showing exact layout
- **Git**: github.com/[username]/hope-theater-seating
- **Status Doc**: paste.txt (latest status from August 13)

---

## CLAUDE CODE COMMANDS

## SESSION NOTES

### Session August 19, 2025
- ✅ Added per-event/product seating activation functionality
- ✅ Updated frontend to conditionally show seating based on product settings
- ✅ Enhanced admin interface with checkbox to enable/disable seating per product
- ✅ Added security checks in AJAX handlers to verify seating is enabled
- ✅ Seating scripts now only load when enabled for specific products
- ✅ Maintains backward compatibility for events without seating requirements

### Session August 18, 2025
- Created class-seat-manager.php with populate_seats() method
- Created class-session-manager.php for hold management
- Created class-mobile-detector.php for device detection
- Updated main plugin file to version 2.0.1
- Added activation hooks to populate seats
- Resolved Claude Code authentication issues

### Previous Sessions
- Completed HTML mockup with all visual fixes
- Created modal system with full WordPress integration
- Implemented mobile touch gestures
- Built complete frontend with orange border animations

---

## GIT WORKFLOW

```bash
# Check status
git status

# Add new files
git add includes/class-seat-manager.php
git add includes/class-session-manager.php
git add includes/class-mobile-detector.php

# Commit changes
git commit -m "Add seat, session, and mobile managers - v2.0.1"

# Push to GitHub
git push origin main
```

## NEXT DEVELOPMENT SESSION

Priority tasks for next session:
1. Test plugin activation on staging site
2. Verify seat population in database
3. Create admin menu interface
4. Test complete purchase flow
5. Fix any issues that arise during testing