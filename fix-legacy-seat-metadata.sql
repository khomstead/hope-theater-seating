-- Diagnostic: Find orders with conflicting seat metadata
-- Run this first to see the scope of the issue

-- Find all order items with _fooevents_seats metadata (the correct source)
SELECT
    oi.order_item_id,
    oi.order_id,
    seats.meta_value as fooevents_seats,
    row_name.meta_value as legacy_row_name,
    seat_num.meta_value as legacy_seat_number,
    hidden_tier.meta_value as hidden_tier,
    hope_tier.meta_value as hope_tier,
    summary.meta_value as seat_summary
FROM wp_woocommerce_order_items oi
LEFT JOIN wp_woocommerce_order_itemmeta seats
    ON oi.order_item_id = seats.order_item_id AND seats.meta_key = '_fooevents_seats'
LEFT JOIN wp_woocommerce_order_itemmeta row_name
    ON oi.order_item_id = row_name.order_item_id AND row_name.meta_key = '_fooevents_seat_row_name_0'
LEFT JOIN wp_woocommerce_order_itemmeta seat_num
    ON oi.order_item_id = seat_num.order_item_id AND seat_num.meta_key = '_fooevents_seat_number_0'
LEFT JOIN wp_woocommerce_order_itemmeta hidden_tier
    ON oi.order_item_id = hidden_tier.order_item_id AND hidden_tier.meta_key = '_hidden_seating-tier'
LEFT JOIN wp_woocommerce_order_itemmeta hope_tier
    ON oi.order_item_id = hope_tier.order_item_id AND hope_tier.meta_key = '_hope_tier'
LEFT JOIN wp_woocommerce_order_itemmeta summary
    ON oi.order_item_id = summary.order_item_id AND summary.meta_key = '_hope_seat_summary'
WHERE seats.meta_value IS NOT NULL
    AND row_name.meta_value LIKE '%Orchestra Row%'
ORDER BY oi.order_id DESC
LIMIT 50;

-- Check for tier mismatches
SELECT
    oi.order_item_id,
    oi.order_id,
    seats.meta_value as seat_id,
    hope_tier.meta_value as hope_tier,
    hidden_tier.meta_value as hidden_tier,
    pricing_tier.meta_value as pricing_tier
FROM wp_woocommerce_order_items oi
INNER JOIN wp_woocommerce_order_itemmeta seats
    ON oi.order_item_id = seats.order_item_id AND seats.meta_key = '_fooevents_seats'
LEFT JOIN wp_woocommerce_order_itemmeta hope_tier
    ON oi.order_item_id = hope_tier.order_item_id AND hope_tier.meta_key = '_hope_tier'
LEFT JOIN wp_woocommerce_order_itemmeta hidden_tier
    ON oi.order_item_id = hidden_tier.order_item_id AND hidden_tier.meta_key = '_hidden_seating-tier'
LEFT JOIN wp_woocommerce_order_itemmeta pricing_tier
    ON oi.order_item_id = pricing_tier.order_item_id AND pricing_tier.meta_key = '_hope_pricing_tier'
WHERE seats.meta_value IS NOT NULL
    AND (
        (hope_tier.meta_value != hidden_tier.meta_value AND hidden_tier.meta_value IS NOT NULL)
        OR (LOWER(hope_tier.meta_value) != pricing_tier.meta_value AND pricing_tier.meta_value IS NOT NULL)
    )
ORDER BY oi.order_id DESC;
