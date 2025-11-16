-- Fix Section G Row 1 seat numbering
-- This swaps coordinates so seats number left-to-right (1-24) instead of right-to-left (24-1)
-- IMPORTANT: This only affects the visual map, NOT existing bookings
-- Existing bookings keep their seat IDs but visual location will change

-- Step 1: Create a temporary table to store the coordinate mapping
CREATE TEMPORARY TABLE IF NOT EXISTS temp_g1_coords AS
SELECT
    s1.id as seat_id,
    s1.seat_number as seat_num,
    s2.x_coordinate as new_x,
    s2.y_coordinate as new_y
FROM wp_hope_seating_physical_seats s1
JOIN wp_hope_seating_physical_seats s2
    ON s1.section = s2.section
    AND s1.row_number = s2.row_number
    AND s1.seat_number + s2.seat_number = 25  -- Mirror pairs: 1+24=25, 2+23=25, etc.
WHERE s1.section = 'G'
    AND s1.row_number = 1;

-- Step 2: Show what will change (preview)
SELECT
    CONCAT('G1-', t.seat_num) as seat_id,
    t.seat_num as seat_number,
    s.x_coordinate as old_x,
    s.y_coordinate as old_y,
    t.new_x,
    t.new_y,
    ROUND(t.new_x - s.x_coordinate, 2) as x_diff,
    ROUND(t.new_y - s.y_coordinate, 2) as y_diff
FROM temp_g1_coords t
JOIN wp_hope_seating_physical_seats s ON t.seat_id = s.id
ORDER BY t.seat_num;

-- Step 3: Apply the changes (UNCOMMENT TO EXECUTE)
-- UPDATE wp_hope_seating_physical_seats s
-- JOIN temp_g1_coords t ON s.id = t.seat_id
-- SET
--     s.x_coordinate = t.new_x,
--     s.y_coordinate = t.new_y,
--     s.updated_at = NOW();

-- Step 4: Verify the changes (run after uncommenting Step 3)
-- SELECT
--     seat_id,
--     seat_number,
--     x_coordinate,
--     y_coordinate
-- FROM wp_hope_seating_physical_seats
-- WHERE section = 'G' AND row_number = 1
-- ORDER BY seat_number;

-- Step 5: Cleanup
DROP TEMPORARY TABLE IF EXISTS temp_g1_coords;
