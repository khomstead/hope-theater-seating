-- Fix legacy seat metadata for 2 affected orders
-- This updates the display labels to match current format
-- The actual seat assignment (_fooevents_seats) remains unchanged

-- Step 1: Preview what will be updated
SELECT
    oi.order_item_id,
    oi.order_id,
    seats.meta_value as current_seat_id,
    row_name.meta_value as old_row_name,
    seat_num.meta_value as seat_number,
    -- Show what the new row name should be
    CONCAT('Section ',
           SUBSTRING_INDEX(seats.meta_value, '-', 1), -- Gets "C1" from "C1-2"
           ' Row ',
           SUBSTRING(SUBSTRING_INDEX(seats.meta_value, '-', 1), 2) -- Gets "1" from "C1"
    ) as new_row_name,
    summary.meta_value as old_summary,
    -- Show what the new summary should be
    CONCAT('Section ',
           SUBSTRING_INDEX(seats.meta_value, '-', 1), -- Gets "C1" from "C1-2"
           ' ',
           seats.meta_value -- Full seat ID
    ) as new_summary
FROM wp_woocommerce_order_items oi
INNER JOIN wp_woocommerce_order_itemmeta seats
    ON oi.order_item_id = seats.order_item_id AND seats.meta_key = '_fooevents_seats'
INNER JOIN wp_woocommerce_order_itemmeta row_name
    ON oi.order_item_id = row_name.order_item_id AND row_name.meta_key = '_fooevents_seat_row_name_0'
LEFT JOIN wp_woocommerce_order_itemmeta seat_num
    ON oi.order_item_id = seat_num.order_item_id AND seat_num.meta_key = '_fooevents_seat_number_0'
LEFT JOIN wp_woocommerce_order_itemmeta summary
    ON oi.order_item_id = summary.order_item_id AND summary.meta_key = '_hope_seat_summary'
WHERE seats.meta_value IS NOT NULL
    AND row_name.meta_value LIKE '%Orchestra Row%'
ORDER BY oi.order_id;

-- Step 2: Update _fooevents_seat_row_name_0 (UNCOMMENT TO EXECUTE)
-- UPDATE wp_woocommerce_order_itemmeta row_meta
-- INNER JOIN wp_woocommerce_order_itemmeta seats
--     ON row_meta.order_item_id = seats.order_item_id
--     AND seats.meta_key = '_fooevents_seats'
-- SET row_meta.meta_value = CONCAT(
--     'Section ',
--     SUBSTRING_INDEX(seats.meta_value, '-', 1), -- Section + Row (e.g., "C1")
--     ' Row ',
--     SUBSTRING(SUBSTRING_INDEX(seats.meta_value, '-', 1), 2) -- Just the row number
-- )
-- WHERE row_meta.meta_key = '_fooevents_seat_row_name_0'
--     AND row_meta.meta_value LIKE '%Orchestra Row%';

-- Step 3: Update _hope_seat_summary (UNCOMMENT TO EXECUTE)
-- UPDATE wp_woocommerce_order_itemmeta summary_meta
-- INNER JOIN wp_woocommerce_order_itemmeta seats
--     ON summary_meta.order_item_id = seats.order_item_id
--     AND seats.meta_key = '_fooevents_seats'
-- SET summary_meta.meta_value = CONCAT(
--     'Section ',
--     SUBSTRING_INDEX(seats.meta_value, '-', 1), -- Section + Row
--     ' ',
--     seats.meta_value -- Full seat ID
-- )
-- WHERE summary_meta.meta_key = '_hope_seat_summary'
--     AND summary_meta.order_item_id IN (
--         SELECT order_item_id
--         FROM wp_woocommerce_order_itemmeta
--         WHERE meta_key = '_fooevents_seat_row_name_0'
--             AND meta_value LIKE '%Orchestra Row%'
--     );

-- Step 4: Fix tier mismatches (align _hidden_seating-tier with _hope_tier)
-- UNCOMMENT TO EXECUTE
-- UPDATE wp_woocommerce_order_itemmeta hidden_tier
-- INNER JOIN wp_woocommerce_order_itemmeta hope_tier
--     ON hidden_tier.order_item_id = hope_tier.order_item_id
--     AND hope_tier.meta_key = '_hope_tier'
-- SET hidden_tier.meta_value = hope_tier.meta_value
-- WHERE hidden_tier.meta_key = '_hidden_seating-tier'
--     AND hidden_tier.meta_value != hope_tier.meta_value
--     AND hidden_tier.order_item_id IN (
--         SELECT order_item_id
--         FROM wp_woocommerce_order_itemmeta
--         WHERE meta_key = '_fooevents_seat_row_name_0'
--             AND meta_value LIKE '%Orchestra Row%'
--     );

-- Step 5: Verify the changes (RUN AFTER UPDATES)
-- SELECT
--     oi.order_item_id,
--     oi.order_id,
--     seats.meta_value as seat_id,
--     row_name.meta_value as row_name,
--     summary.meta_value as summary,
--     hope_tier.meta_value as hope_tier,
--     hidden_tier.meta_value as hidden_tier
-- FROM wp_woocommerce_order_items oi
-- INNER JOIN wp_woocommerce_order_itemmeta seats
--     ON oi.order_item_id = seats.order_item_id AND seats.meta_key = '_fooevents_seats'
-- INNER JOIN wp_woocommerce_order_itemmeta row_name
--     ON oi.order_item_id = row_name.order_item_id AND row_name.meta_key = '_fooevents_seat_row_name_0'
-- LEFT JOIN wp_woocommerce_order_itemmeta summary
--     ON oi.order_item_id = summary.order_item_id AND summary.meta_key = '_hope_seat_summary'
-- LEFT JOIN wp_woocommerce_order_itemmeta hope_tier
--     ON oi.order_item_id = hope_tier.order_item_id AND hope_tier.meta_key = '_hope_tier'
-- LEFT JOIN wp_woocommerce_order_itemmeta hidden_tier
--     ON oi.order_item_id = hidden_tier.order_item_id AND hidden_tier.meta_key = '_hidden_seating-tier'
-- WHERE oi.order_id IN (SELECT DISTINCT order_id FROM wp_woocommerce_order_items WHERE order_item_id IN (
--     SELECT order_item_id FROM wp_woocommerce_order_itemmeta WHERE meta_key = '_fooevents_seats'
-- ))
-- ORDER BY oi.order_id;
