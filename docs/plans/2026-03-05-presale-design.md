# Pre-sale Feature Design

**Date:** 2026-03-05
**Status:** Approved
**Version target:** 2.8.27

## Overview

Add pre-sale capability where visitors can browse the product page but cannot purchase tickets unless they possess a pre-sale password. Supports multiple passwords per product (for different fan clubs, promotions, etc.) with usage tracking correlated to orders. Works with both seated and general admission products.

## Use Case

Artists and venues circulate pre-sale passwords to fan clubs before tickets go on sale to the general public. This feature gates ticket purchasing behind password validation during the pre-sale window, then automatically opens to general sale at a predetermined date/time.

## Customer-Facing States

### State 1: Announced (before pre-sale start)

Show when: `now < presale_start_date`

Displays:
- Admin-configured announcement message (textarea)
- "Pre-sale begins [date] at [time] [timezone]"
- "Tickets on sale to the general public [date] at [time] [timezone]"
- No password field, no purchase buttons

The standard WooCommerce add-to-cart button is hidden. If seating is enabled, the "Select Seats" button is also hidden.

### State 2: Pre-sale active (between pre-sale start and general sale)

Show when: `presale_start_date <= now < public_sale_date`

Displays:
- Admin-configured pre-sale message (textarea)
- "Tickets on sale to the general public [date] at [time] [timezone]"
- Password input field + Submit button

After successful password validation:
- Pre-sale messaging is replaced (no page reload) with normal purchase flow
- If seating enabled: "Select Seats" button appears
- If seating not enabled: standard WooCommerce "Add to Cart" appears

### State 3: General sale (after public sale date)

Show when: `now >= public_sale_date` OR pre-sale not enabled

Normal product page. No pre-sale messaging. Standard purchase flow.

## Admin Configuration

### Product Data Tab: "Pre-sale"

A new WooCommerce product data tab (always visible, not conditional on seating being enabled) with these fields:

**Controls:**
- Enable Pre-sale (checkbox)

**Dates (shown when enabled):**
- Pre-sale Start (date/time picker, WordPress timezone)
- General Sale Start (date/time picker, WordPress timezone)

**Passwords (shown when enabled):**
- Repeater field, each row has:
  - Password (text input)
  - Label (text input, e.g., "Fan Club", "Spotify VIP")
  - Remove button
  - Usage count display (read-only, auto-incremented)
- "+ Add Password" button

**Messaging (shown when enabled):**
- Announcement Message (textarea) - shown before pre-sale starts
- Pre-sale Message (textarea) - shown during active pre-sale

**Validation rules:**
- General sale date must be after pre-sale start date
- At least one password required when enabled
- Both dates required when enabled

### Storage (Product Meta)

Uses `get_post_meta()` / `update_post_meta()` to match existing plugin patterns. HPOS compatibility maintained via WooCommerce's backwards-compatibility layer.

```
_hope_presale_enabled              -> 'yes' / 'no'
_hope_presale_start                -> Unix timestamp
_hope_presale_public_start         -> Unix timestamp
_hope_presale_passwords            -> Serialized array:
                                      [
                                        ['password' => 'FANCLUB2026', 'label' => 'Fan Club', 'usage_count' => 0],
                                        ['password' => 'VIPACCESS', 'label' => 'Spotify VIP', 'usage_count' => 0],
                                      ]
_hope_presale_announcement_message -> Text
_hope_presale_message              -> Text
```

### Future-Proof Data Structure

Each password entry is stored as an associative array. Future fields can be added without migration:
- `pricing_map_id` - per-password pricing tier override
- `allowed_sections` - restrict which sections a password unlocks
- `tier_override` - different pricing for different passwords

## Password Validation Flow

### AJAX Endpoint: `hope_validate_presale_password`

1. Customer enters password, clicks Submit
2. AJAX POST with `product_id` and `password`
3. Server-side checks:
   a. Rate limit check (transient: `hope_presale_attempts_{ip_hash}`)
   b. Validate product has active pre-sale
   c. Compare password against stored passwords (case-insensitive)
4. On success:
   - Set cookie `hope_presale_{product_id}` with value = hash of matched password
   - Cookie expires at general sale date
   - Return success + rendered button HTML
5. On failure:
   - Increment attempt counter in transient
   - Return error message

### Rate Limiting

- 5 failed attempts per IP per 15-minute window
- Tracked via WordPress transient: `hope_presale_attempts_{md5(ip)}`
- Transient auto-expires after 15 minutes
- Lockout message: "Too many attempts. Please try again in 15 minutes."

### Session Persistence

- Cookie: `hope_presale_{product_id}`
- Value: `md5(matched_password)` (for correlation at checkout)
- Expiry: General sale date timestamp
- Path: `/` (site-wide, works on cart/checkout pages)
- On subsequent visits during pre-sale window, cookie detected server-side and normal purchase flow renders directly

## Order Tracking

### At Checkout

When an order is placed for a pre-sale product:
1. Check for pre-sale cookie on the request
2. Look up which password entry matches the cookie hash
3. Save order item meta:
   - `_hope_presale_password` -> the password text
   - `_hope_presale_label` -> the label (e.g., "Fan Club")
4. Increment `usage_count` on the matching password entry in product meta

### Reporting

- Per-order: visible in order details ("Pre-sale: Fan Club")
- Per-password: usage_count displayed in admin product tab
- Queryable: can filter orders by `_hope_presale_label` for reporting

## Technical Implementation

### New Files

- `includes/class-presale.php` - Core pre-sale logic:
  - `get_presale_state($product_id)` - returns 'announced' / 'presale' / 'general_sale' / 'disabled'
  - `validate_password($product_id, $password)` - AJAX handler
  - `check_rate_limit($ip)` - rate limiting
  - `has_valid_presale_cookie($product_id)` - cookie check
  - `set_presale_cookie($product_id, $password_hash, $expiry)` - cookie management
  - `track_presale_usage($order_id, $product_id)` - order meta + usage count
  - `render_presale_gate($product_id)` - output the appropriate state HTML

### Modified Files

- `includes/class-admin.php`
  - Add "Pre-sale" product data tab (new tab, not inside "Venue & Seating")
  - Add fields rendering method
  - Add save handler for pre-sale meta
  - Password repeater with JavaScript for add/remove rows

- `includes/class-woocommerce-integration.php`
  - Modify `add_seat_selection_interface()` to check pre-sale state first
  - Add hook at `woocommerce_before_add_to_cart_form` (higher level) for non-seated products
  - Hide add-to-cart form during announced/locked pre-sale states
  - Add checkout hook for order meta tracking

- `includes/class-modal-handler.php`
  - Check pre-sale state before rendering modal HTML (skip if customer can't access)

- `includes/class-frontend.php`
  - Register AJAX endpoint `hope_validate_presale_password` (nopriv + priv)
  - Skip enqueuing seat map scripts during announced/pre-sale-locked states

- `includes/class-integration.php`
  - Include and instantiate `HOPE_Presale` class

- `hope-theater-seating.php`
  - Include `class-presale.php`

### Hook Priority

Pre-sale check runs at a higher priority than the seating interface to ensure it evaluates first:
- Pre-sale: hooks into `woocommerce_before_add_to_cart_form` at priority 5
- Seating: hooks into `woocommerce_before_add_to_cart_button` at default priority 10

When pre-sale is gating access, it hides the entire add-to-cart form area and renders its own messaging. The seating hooks never fire because the form area is suppressed.

### No New Database Tables

All storage uses existing WordPress/WooCommerce mechanisms:
- Product meta for configuration
- Order item meta for tracking
- Transients for rate limiting
- Cookies for session persistence

## Edge Cases

1. **Admin disables pre-sale mid-stream** - Immediately reverts to normal flow. Existing cookies harmless.
2. **Admin changes general sale date earlier** - Pre-sale ends at new date. Existing cookies may outlive window but no harm (general sale = everyone has access).
3. **Customer validates, checks out after general sale begins** - Works fine. Order still records pre-sale password from cookie.
4. **Cookie for different product** - Cookies are per-product (`hope_presale_{product_id}`), no cross-product leakage.
5. **Seating disabled but pre-sale enabled** - Pre-sale gates the standard WooCommerce add-to-cart button instead.
6. **All dates in the past** - General sale date passed = normal product page, no pre-sale messaging.
7. **Missing general sale date** - Validation prevents saving when pre-sale is enabled without both dates.

## Timezone Handling

All date/time inputs use the WordPress timezone setting (Settings > General). Dates stored as Unix timestamps. Display to customers uses `wp_date()` with the site's configured timezone.

## Decisions Log

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Separate plugin vs integrated | Integrated | Pre-sale gates sit in same rendering flow as existing seating checks |
| Password input UX | Inline on product page | Simplest, no extra modals or pages |
| Session persistence | Cookie until general sale | No re-entry needed during pre-sale window |
| Password storage | Repeater with label | Future-proof for per-password pricing/sections |
| Order correlation | Order item meta + usage counter | Mirrors WooCommerce coupon tracking pattern |
| Rate limiting | 5 attempts / 15 min / IP | Prevents brute force without blocking legitimate users |
| Product meta API | get_post_meta() | Matches existing plugin patterns; HPOS compat layer handles it |
| Pre-sale tab visibility | Always visible | Supports non-seated general admission products |
| Hook priority | Pre-sale at 5, seating at 10 | Pre-sale evaluates first, gates entire purchase flow |
| Timezone | WordPress setting | Consistent with WooCommerce/FooEvents behavior |
