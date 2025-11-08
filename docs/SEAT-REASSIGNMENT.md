# Seat Reassignment Feature

## Overview

The Seat Reassignment feature allows WordPress administrators to reassign customer seats from the WooCommerce order edit screen. This is useful when customers request seat changes, when seats need to be moved due to venue issues, or for any administrative seat management needs.

## Location

**WooCommerce Order Edit Screen** → **Theater Seat Management** meta box → **Reassign** button next to each seat

## How It Works

### User Flow

1. Admin navigates to WooCommerce → Orders
2. Opens an order that contains theater seats
3. Scrolls to the "Theater Seat Management" meta box
4. Clicks the "Reassign" button next to any seat
5. Visual seat map modal opens showing:
   - All seats in the venue
   - Available seats (colored by pricing tier)
   - Booked seats (grayed out)
   - Current seat highlighted
6. Admin clicks on an available seat to select it
7. Only one seat can be selected at a time (clicking another deselects the first)
8. Admin clicks "Confirm Reassignment"
9. Confirmation dialog appears
10. Upon confirmation:
    - Booking record is updated
    - Order metadata is updated
    - FooEvents tickets are automatically regenerated
    - Page reloads to show updated information

### Technical Flow

#### 1. Database Updates

**Bookings Table** (`wp_hope_seating_bookings`):
- Updates the `seat_id` field for the booking record
- Maintains all other booking data (order_id, product_id, status, etc.)

**Order Item Metadata**:
- `_fooevents_seats` - Updated with new seat ID
- `_hope_seat_summary` - Updated with new seat display text
- `_fooevents_seat_row_name_0` - Updated with new section/row
- `_fooevents_seat_number_0` - Updated with new seat number

#### 2. Ticket Regeneration

The system automatically calls the FooEvents ticket generation API:
- Deletes old ticket PDF for the reassigned seat
- Generates new ticket PDF with correct seat information
- Maintains all other ticket data (barcode, attendee info, etc.)

#### 3. Validation

Before allowing reassignment, the system checks:
- New seat must exist in the pricing map
- New seat must not be currently booked for this event
- New seat must not be blocked by admin
- User must have `manage_woocommerce` capability

## Files Modified

### Backend PHP

**`includes/class-admin-selective-refunds.php`** (lines 440-920):
- `render_theater_seat_management_meta_box()` - Added seat reassignment UI
- `ajax_get_event_venue()` - Gets venue/pricing map ID for an event
- `ajax_get_available_seats()` - Returns list of available seats
- `ajax_process_seat_reassignment()` - Processes the actual reassignment
- Modal HTML structure with seat map container
- JavaScript for modal interaction and AJAX calls

### Frontend JavaScript

**`assets/js/seat-map.js`**:
- Updated `loadRealSeatData()` to use instance-specific AJAX config (lines 117-133)
- Updated `showLoadingState()` to use `this.containerId` (lines 93-109)
- Updated `generateTheater()` to use `this.containerId` (lines 440-445)
- Updated `handleSeatHover()` to use `this.containerId` (lines 702-714)
- Updated `handleSeatClick()` to respect `this.maxSeats` property (lines 657-662)

### CSS

Uses existing admin styles from seat blocking feature:
- Modal overlay and structure
- Seat map SVG container
- Zoom controls
- Floor selector buttons
- Tooltip styling

## API Endpoints

### `hope_get_event_venue`

**Action**: `wp_ajax_hope_get_event_venue`
**Method**: POST
**Parameters**:
- `event_id` (int) - WooCommerce product ID
- `order_id` (int) - WooCommerce order ID
- `nonce` (string) - Security nonce

**Response**:
```json
{
  "success": true,
  "data": {
    "venue_id": "218"
  }
}
```

### `hope_get_available_seats`

**Action**: `wp_ajax_hope_get_available_seats`
**Method**: POST
**Parameters**:
- `event_id` (int) - WooCommerce product ID
- `order_id` (int) - Order ID
- `exclude_seat` (string) - Current seat ID to exclude from "booked" check
- `nonce` (string) - Security nonce

**Response**:
```json
{
  "success": true,
  "data": {
    "seats": [
      {
        "seat_id": "C1-5",
        "section": "C",
        "row_number": 1,
        "seat_number": 5
      }
    ]
  }
}
```

### `hope_process_seat_reassignment`

**Action**: `wp_ajax_hope_process_seat_reassignment`
**Method**: POST
**Parameters**:
- `order_id` (int) - WooCommerce order ID
- `old_seat_id` (string) - Current seat ID
- `new_seat_id` (string) - New seat ID
- `item_id` (int) - WooCommerce order item ID
- `nonce` (string) - Security nonce

**Response**:
```json
{
  "success": true,
  "data": {
    "message": "Seat reassigned successfully",
    "old_seat": "C1-5",
    "new_seat": "C2-8",
    "order_id": 2302
  }
}
```

## Security

- **Capability Check**: Only users with `manage_woocommerce` capability can reassign seats
- **Nonce Verification**: All AJAX requests are protected with WordPress nonces
- **Input Validation**: All inputs are sanitized and validated
- **Database Prepared Statements**: All queries use `$wpdb->prepare()` to prevent SQL injection
- **HPOS Compatible**: Works with both classic orders and High-Performance Order Storage

## Integration Points

### FooEvents Integration

The reassignment automatically triggers FooEvents ticket regeneration via:
```php
do_action('woocommerce_order_item_meta_updated', $item_id, $order);
```

This ensures:
- Old tickets are invalidated
- New tickets show correct seat information
- Barcodes remain valid
- Attendee information is preserved

### Seat Blocking Integration

The reassignment respects admin seat blocks:
- Blocked seats are excluded from available seats list
- Uses `HOPE_Database_Selective_Refunds::get_blocked_seat_ids()`
- Visual seat map shows blocked seats as unavailable

### Pricing Maps Integration

Uses the pricing map architecture:
- `HOPE_Pricing_Maps_Manager::get_seats_with_pricing()` to get all seats
- Respects pricing tiers (P1, P2, P3, AA)
- Maintains pricing information in visual display

## Limitations

1. **Single Seat Only**: Can only reassign one seat at a time
2. **Same Event Only**: Cannot reassign to a different event/product
3. **No Price Adjustment**: Does not automatically adjust order total if new seat has different price
4. **Manual Email**: Does not automatically email customer (admin should notify customer separately)
5. **No Refund Integration**: Does not create refunds if moving to lower-priced seat

## Future Enhancements

Potential improvements for future versions:

1. **Price Adjustment**: Automatically adjust order total when moving between pricing tiers
2. **Customer Notification**: Send email to customer with updated ticket
3. **Bulk Reassignment**: Allow reassigning multiple seats at once
4. **Seat Swap**: Allow swapping seats between two orders
5. **Audit Trail**: Log all seat reassignments with timestamp and admin user
6. **Seat Preference**: Remember customer seat preferences for future bookings

## Troubleshooting

### Seat Map Not Loading

**Symptom**: Modal opens but seat map is blank
**Solution**:
- Check browser console for JavaScript errors
- Verify venue/pricing map is configured for the event
- Check that `_hope_seating_venue_id` meta exists on product
- Verify seat data exists in `wp_hope_seating_seat_pricing` table

### Cannot Select Seats

**Symptom**: Clicking seats does nothing
**Solution**:
- Check console for `this.selectedSeats.has is not a function` error
- Verify `HOPESeatMap` class is loaded
- Check that `seat-map.js` is enqueued properly

### Reassignment Fails

**Symptom**: Error message after clicking confirm
**Solution**:
- Check if new seat is already booked
- Verify order item ID is valid
- Check WordPress error logs for database errors
- Ensure FooEvents plugin is active and updated

### Tickets Not Regenerating

**Symptom**: Seat changes but ticket still shows old seat
**Solution**:
- Verify FooEvents plugin is active
- Check that `woocommerce_order_item_meta_updated` action is firing
- Check FooEvents settings for ticket generation
- Manually trigger ticket regeneration from FooEvents settings

## Developer Notes

### Adding Custom Validation

To add custom validation before reassignment:

```php
add_filter('hope_before_seat_reassignment', function($allowed, $old_seat_id, $new_seat_id, $order_id) {
    // Custom validation logic
    if (my_custom_check($new_seat_id)) {
        return false; // Prevent reassignment
    }
    return $allowed;
}, 10, 4);
```

### Logging Reassignments

To log reassignments to custom table:

```php
add_action('hope_after_seat_reassignment', function($old_seat_id, $new_seat_id, $order_id, $item_id) {
    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'seat_reassignment_log', array(
        'order_id' => $order_id,
        'old_seat' => $old_seat_id,
        'new_seat' => $new_seat_id,
        'admin_user' => get_current_user_id(),
        'timestamp' => current_time('mysql')
    ));
}, 10, 4);
```

### Customizing Modal Appearance

The modal uses the same structure as seat blocking feature. To customize:

1. Add custom CSS to admin styles
2. Target `.hope-modal` and child elements
3. Use `!important` sparingly to override defaults

## Testing Checklist

- [ ] Can open seat map modal from order edit screen
- [ ] Seat map displays all seats correctly
- [ ] Available seats are selectable
- [ ] Booked seats are grayed out and not selectable
- [ ] Can only select one seat at a time
- [ ] Clicking second seat deselects first
- [ ] Confirmation dialog appears
- [ ] Database updates correctly after confirmation
- [ ] Order metadata updates correctly
- [ ] FooEvents tickets regenerate automatically
- [ ] Page reloads showing new seat
- [ ] Works with HPOS orders
- [ ] Works with classic orders
- [ ] Respects admin seat blocks
- [ ] Validates seat availability correctly
- [ ] Shows appropriate error messages

## Version History

**v2.4.9** (2025-01-08)
- Initial implementation of seat reassignment feature
- Visual seat map modal integration
- Single-seat selection enforcement
- Automatic ticket regeneration
- HPOS compatibility
