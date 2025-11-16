# Section G Row 1 Numbering Fix

## Problem
Section G Row 1 seats were numbered right-to-left (24→1) but should be left-to-right (1→24).

## Solution
This fix includes TWO parts:

### 1. Code Update ✅ (Already Applied)
`includes/class-physical-seats.php` - Removed 'G' from reverse numbering array so future seat generation is correct.

### 2. Database Update ⏳ (Needs to be run)
`fix-section-g-row1.sql` - Swaps coordinates for existing Section G Row 1 seats in database.

## How to Run the SQL Fix

### Option A: Using TablePlus / Sequel Pro / phpMyAdmin
1. Open your database client and connect to the Local site database
2. Open `fix-section-g-row1.sql`
3. Run the script **in PREVIEW mode first** (Steps 1-2 only)
4. Review the output to see what will change
5. Uncomment Step 3 to apply changes
6. Run Step 4 to verify

### Option B: Using WP-CLI (if available)
```bash
cd "/Users/kyle/Local Sites/hope-center-for-the-arts/app/public"
wp db query < wp-content/plugins/hope-theater-seating/fix-section-g-row1.sql
```

### Option C: Using MySQL command line
```bash
mysql -h 127.0.0.1 -u root -proot local < fix-section-g-row1.sql
```

## What This Does
- **Swaps coordinates** for the 24 seats in Section G Row 1
- Seat 1 gets the coordinates that were for Seat 24
- Seat 2 gets the coordinates that were for Seat 23
- And so on...

## Important Notes
⚠️ **Existing Bookings:**
- People who already bought seats (e.g., G1-19) keep their seat ID
- The visual location on the map will change
- The seat number doesn't change, only the coordinates

✅ **Future Bookings:**
- New customers will see the corrected map
- Seats will be numbered left-to-right as expected

## After Running
1. Test the seat map on the frontend - Section G Row 1 should now show seats 1-24 from left to right
2. Verify existing bookings still display correctly (they'll be in different visual positions but that's expected)
3. Delete this SQL file and README after confirming the fix works

## Files Modified
- `includes/class-physical-seats.php` - Code change for future seat generation
- Database: `wp_hope_seating_physical_seats` - Coordinate updates for existing seats
