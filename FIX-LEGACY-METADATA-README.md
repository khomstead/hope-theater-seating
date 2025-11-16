# Fix Legacy Seat Metadata

## Problem
Early orders have conflicting seat metadata:
- **Correct:** `_fooevents_seats: C1-2`, `_hope_seat_summary: Orchestra C1-2`
- **Incorrect:** `_fooevents_seat_row_name_0: Orchestra Row 1`, `_fooevents_seat_number_0: 2`
- **Tier mismatch:** `_hope_tier: P1` vs `_hidden_seating-tier: P2`

The code is also **still generating** the old format for new orders.

## Solution

### Step 1: Run Diagnostic Query
Run `fix-legacy-seat-metadata.sql` to see affected orders.

### Step 2: Fix Code (Prevent Future Issues)
Update `includes/class-woocommerce-integration.php` lines 884-885 and 897-898:
- Change from: `"Orchestra Row 1"` format
- Change to: `"Orchestra C1"` format (section-based)

### Step 3: Fix Existing Orders
Run the correction queries below.

## Correction Strategy

**Source of Truth:** `_fooevents_seats` (e.g., "C1-2")

Parse this to get:
- Section: C
- Row: 1
- Seat: 2

Then update:
- `_fooevents_seat_row_name_0` → "Orchestra C1" (not "Orchestra Row 1")
- `_fooevents_seat_number_0` → "2" (keep as-is)
- Sync tier metadata

## IMPORTANT
- Customer has the **correct seat** (`_fooevents_seats`)
- We're just fixing the **display labels** and **tier metadata**
- Physical seat assignment never changes
