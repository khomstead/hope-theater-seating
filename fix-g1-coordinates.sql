-- Fix Section G Row 1: Swap coordinates to reverse seat numbering
-- INSTRUCTIONS: Run each step separately in phpMyAdmin

-- Step 1: Preview what will be swapped
SELECT
    s1.seat_id as seat1,
    s1.x_coordinate as x1_old,
    s1.y_coordinate as y1_old,
    s2.seat_id as seat2,
    s2.x_coordinate as x2_old,
    s2.y_coordinate as y2_old
FROM wp_hope_seating_physical_seats s1
INNER JOIN wp_hope_seating_physical_seats s2
    ON s1.section = s2.section
    AND s1.row_number = s2.row_number
    AND s1.seat_number + s2.seat_number = 25
    AND s1.seat_number < s2.seat_number
WHERE s1.section = 'G' AND s1.row_number = 1
ORDER BY s1.seat_number;

-- Step 2a: Create backup of coordinates
CREATE TABLE IF NOT EXISTS backup_g1_coords AS
SELECT * FROM wp_hope_seating_physical_seats
WHERE section = 'G' AND row_number = 1;

-- Step 2b: Execute the swap (24 individual updates)
UPDATE wp_hope_seating_physical_seats SET x_coordinate = (SELECT x_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-24'), y_coordinate = (SELECT y_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-24') WHERE seat_id = 'G1-1';
UPDATE wp_hope_seating_physical_seats SET x_coordinate = (SELECT x_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-23'), y_coordinate = (SELECT y_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-23') WHERE seat_id = 'G1-2';
UPDATE wp_hope_seating_physical_seats SET x_coordinate = (SELECT x_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-22'), y_coordinate = (SELECT y_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-22') WHERE seat_id = 'G1-3';
UPDATE wp_hope_seating_physical_seats SET x_coordinate = (SELECT x_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-21'), y_coordinate = (SELECT y_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-21') WHERE seat_id = 'G1-4';
UPDATE wp_hope_seating_physical_seats SET x_coordinate = (SELECT x_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-20'), y_coordinate = (SELECT y_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-20') WHERE seat_id = 'G1-5';
UPDATE wp_hope_seating_physical_seats SET x_coordinate = (SELECT x_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-19'), y_coordinate = (SELECT y_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-19') WHERE seat_id = 'G1-6';
UPDATE wp_hope_seating_physical_seats SET x_coordinate = (SELECT x_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-18'), y_coordinate = (SELECT y_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-18') WHERE seat_id = 'G1-7';
UPDATE wp_hope_seating_physical_seats SET x_coordinate = (SELECT x_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-17'), y_coordinate = (SELECT y_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-17') WHERE seat_id = 'G1-8';
UPDATE wp_hope_seating_physical_seats SET x_coordinate = (SELECT x_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-16'), y_coordinate = (SELECT y_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-16') WHERE seat_id = 'G1-9';
UPDATE wp_hope_seating_physical_seats SET x_coordinate = (SELECT x_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-15'), y_coordinate = (SELECT y_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-15') WHERE seat_id = 'G1-10';
UPDATE wp_hope_seating_physical_seats SET x_coordinate = (SELECT x_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-14'), y_coordinate = (SELECT y_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-14') WHERE seat_id = 'G1-11';
UPDATE wp_hope_seating_physical_seats SET x_coordinate = (SELECT x_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-13'), y_coordinate = (SELECT y_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-13') WHERE seat_id = 'G1-12';
UPDATE wp_hope_seating_physical_seats SET x_coordinate = (SELECT x_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-12'), y_coordinate = (SELECT y_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-12') WHERE seat_id = 'G1-13';
UPDATE wp_hope_seating_physical_seats SET x_coordinate = (SELECT x_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-11'), y_coordinate = (SELECT y_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-11') WHERE seat_id = 'G1-14';
UPDATE wp_hope_seating_physical_seats SET x_coordinate = (SELECT x_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-10'), y_coordinate = (SELECT y_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-10') WHERE seat_id = 'G1-15';
UPDATE wp_hope_seating_physical_seats SET x_coordinate = (SELECT x_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-9'), y_coordinate = (SELECT y_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-9') WHERE seat_id = 'G1-16';
UPDATE wp_hope_seating_physical_seats SET x_coordinate = (SELECT x_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-8'), y_coordinate = (SELECT y_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-8') WHERE seat_id = 'G1-17';
UPDATE wp_hope_seating_physical_seats SET x_coordinate = (SELECT x_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-7'), y_coordinate = (SELECT y_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-7') WHERE seat_id = 'G1-18';
UPDATE wp_hope_seating_physical_seats SET x_coordinate = (SELECT x_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-6'), y_coordinate = (SELECT y_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-6') WHERE seat_id = 'G1-19';
UPDATE wp_hope_seating_physical_seats SET x_coordinate = (SELECT x_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-5'), y_coordinate = (SELECT y_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-5') WHERE seat_id = 'G1-20';
UPDATE wp_hope_seating_physical_seats SET x_coordinate = (SELECT x_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-4'), y_coordinate = (SELECT y_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-4') WHERE seat_id = 'G1-21';
UPDATE wp_hope_seating_physical_seats SET x_coordinate = (SELECT x_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-3'), y_coordinate = (SELECT y_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-3') WHERE seat_id = 'G1-22';
UPDATE wp_hope_seating_physical_seats SET x_coordinate = (SELECT x_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-2'), y_coordinate = (SELECT y_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-2') WHERE seat_id = 'G1-23';
UPDATE wp_hope_seating_physical_seats SET x_coordinate = (SELECT x_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-1'), y_coordinate = (SELECT y_coordinate FROM backup_g1_coords WHERE seat_id = 'G1-1') WHERE seat_id = 'G1-24';

-- Step 3: Verify the results
SELECT seat_id, seat_number, x_coordinate, y_coordinate
FROM wp_hope_seating_physical_seats
WHERE section = 'G' AND row_number = 1
ORDER BY seat_number;

-- Step 4: Cleanup (run after verifying results are correct)
DROP TABLE backup_g1_coords;
