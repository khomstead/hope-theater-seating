-- EXECUTE: Fix legacy seat metadata for 2 affected orders
-- Backup should be created before running this

-- Step 1: Update _fooevents_seat_row_name_0
UPDATE wp_woocommerce_order_itemmeta row_meta
INNER JOIN wp_woocommerce_order_itemmeta seats
    ON row_meta.order_item_id = seats.order_item_id
    AND seats.meta_key = '_fooevents_seats'
SET row_meta.meta_value = CONCAT(
    'Section ',
    SUBSTRING_INDEX(seats.meta_value, '-', 1),
    ' Row ',
    SUBSTRING(SUBSTRING_INDEX(seats.meta_value, '-', 1), 2)
)
WHERE row_meta.meta_key = '_fooevents_seat_row_name_0'
    AND row_meta.meta_value LIKE '%Orchestra Row%';

-- Step 2: Update _hope_seat_summary
UPDATE wp_woocommerce_order_itemmeta summary_meta
INNER JOIN wp_woocommerce_order_itemmeta seats
    ON summary_meta.order_item_id = seats.order_item_id
    AND seats.meta_key = '_fooevents_seats'
INNER JOIN (
    SELECT DISTINCT order_item_id
    FROM wp_woocommerce_order_itemmeta
    WHERE meta_key = '_fooevents_seat_row_name_0'
        AND meta_value LIKE '%Orchestra Row%'
) affected_items ON summary_meta.order_item_id = affected_items.order_item_id
SET summary_meta.meta_value = CONCAT(
    'Section ',
    SUBSTRING_INDEX(seats.meta_value, '-', 1),
    ' ',
    seats.meta_value
)
WHERE summary_meta.meta_key = '_hope_seat_summary';

-- Step 3: Fix tier mismatches (align _hidden_seating-tier with _hope_tier)
UPDATE wp_woocommerce_order_itemmeta hidden_tier
INNER JOIN wp_woocommerce_order_itemmeta hope_tier
    ON hidden_tier.order_item_id = hope_tier.order_item_id
    AND hope_tier.meta_key = '_hope_tier'
INNER JOIN (
    SELECT DISTINCT order_item_id
    FROM wp_woocommerce_order_itemmeta
    WHERE meta_key = '_fooevents_seats'
        AND order_item_id IN (
            SELECT order_item_id
            FROM wp_woocommerce_order_itemmeta
            WHERE meta_key = '_fooevents_seat_row_name_0'
        )
) affected_items ON hidden_tier.order_item_id = affected_items.order_item_id
SET hidden_tier.meta_value = hope_tier.meta_value
WHERE hidden_tier.meta_key = '_hidden_seating-tier'
    AND hidden_tier.meta_value != hope_tier.meta_value;

-- Step 4: Verify the results
SELECT
    oi.order_item_id,
    oi.order_id,
    seats.meta_value as seat_id,
    row_name.meta_value as row_name,
    summary.meta_value as summary,
    hope_tier.meta_value as hope_tier,
    hidden_tier.meta_value as hidden_tier,
    pricing_tier.meta_value as pricing_tier
FROM wp_woocommerce_order_items oi
INNER JOIN wp_woocommerce_order_itemmeta seats
    ON oi.order_item_id = seats.order_item_id AND seats.meta_key = '_fooevents_seats'
LEFT JOIN wp_woocommerce_order_itemmeta row_name
    ON oi.order_item_id = row_name.order_item_id AND row_name.meta_key = '_fooevents_seat_row_name_0'
LEFT JOIN wp_woocommerce_order_itemmeta summary
    ON oi.order_item_id = summary.order_item_id AND summary.meta_key = '_hope_seat_summary'
LEFT JOIN wp_woocommerce_order_itemmeta hope_tier
    ON oi.order_item_id = hope_tier.order_item_id AND hope_tier.meta_key = '_hope_tier'
LEFT JOIN wp_woocommerce_order_itemmeta hidden_tier
    ON oi.order_item_id = hidden_tier.order_item_id AND hidden_tier.meta_key = '_hidden_seating-tier'
LEFT JOIN wp_woocommerce_order_itemmeta pricing_tier
    ON oi.order_item_id = pricing_tier.order_item_id AND pricing_tier.meta_key = '_hope_pricing_tier'
WHERE row_name.meta_value LIKE 'Section%'
ORDER BY oi.order_id;
