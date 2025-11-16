-- Fix Section G Row 1: Swap coordinates to reverse seat numbering
-- This version uses self-joins instead of temporary tables
-- Can be run as separate queries in phpMyAdmin

-- Step 1: Preview what will be swapped
SELECT
    s1.seat_id as seat1,
    s1.x_coordinate as x1_old,
    s1.y_coordinate as y1_old,
    s2.seat_id as seat2,
    s2.x_coordinate as x2_old,
    s2.y_coordinate as y2_old,
    s2.x_coordinate as x1_new,
    s2.y_coordinate as y1_new,
    s1.x_coordinate as x2_new,
    s1.y_coordinate as y2_new
FROM wp_hope_seating_physical_seats s1
INNER JOIN wp_hope_seating_physical_seats s2
    ON s1.section = s2.section
    AND s1.row_number = s2.row_number
    AND s1.seat_number + s2.seat_number = 25  -- Mirror pairs (1+24=25, 2+23=25, etc.)
    AND s1.seat_number < s2.seat_number  -- Only show each pair once
WHERE s1.section = 'G'
    AND s1.row_number = 1
ORDER BY s1.seat_number;

-- Step 2: Execute the swap
-- Create a simple temporary table (run all three statements together)
CREATE TEMPORARY TABLE temp_coords AS
SELECT seat_id, x_coordinate, y_coordinate
FROM wp_hope_seating_physical_seats
WHERE section = 'G' AND row_number = 1;

UPDATE wp_hope_seating_physical_seats s1
INNER JOIN temp_coords s2_coords
    ON CONCAT('G', s1.row_number, '-', (25 - s1.seat_number)) = s2_coords.seat_id
SET
    s1.x_coordinate = s2_coords.x_coordinate,
    s1.y_coordinate = s2_coords.y_coordinate
WHERE s1.section = 'G' AND s1.row_number = 1;

DROP TEMPORARY TABLE temp_coords;

-- Step 3: Verify the results
SELECT
    seat_id,
    seat_number,
    x_coordinate,
    y_coordinate
FROM wp_hope_seating_physical_seats
WHERE section = 'G' AND row_number = 1
ORDER BY seat_number;
