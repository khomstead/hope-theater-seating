# HOPE Theater Seating - Troubleshooting Guide

## Quick Diagnostic Checklist

### 1. Check Seat Count
```sql
-- Expected: 497 seats for HOPE Main Theater
SELECT COUNT(*) FROM wp_hope_seating_physical_seats WHERE theater_id = 'hope_main_theater';
```

### 2. Check Active Pricing Map
```sql
-- Should show Map ID 214: "HOPE Theater - Standard Pricing"
SELECT * FROM wp_hope_seating_pricing_maps WHERE status = 'active';
```

### 3. Check Pricing Distribution
```sql
-- Expected: P1(108), P2(292), P3(88), AA(9) = 497 total
SELECT sp.pricing_tier, COUNT(*) as count
FROM wp_hope_seating_seat_pricing sp 
WHERE sp.pricing_map_id = 214
GROUP BY sp.pricing_tier
ORDER BY sp.pricing_tier;
```

## Common Issues & Solutions

### Problem: Wrong Colors on Seat Map

**Symptoms**: 
- Section C shows mostly purple (P1) instead of mixed P1/P2
- Visual display doesn't match expected pricing structure

**Diagnosis**:
```sql
-- Check Section C current assignments
SELECT sp.pricing_tier, COUNT(*) as count
FROM wp_hope_seating_seat_pricing sp 
JOIN wp_hope_seating_physical_seats ps ON sp.physical_seat_id = ps.id 
WHERE ps.section = 'C' AND sp.pricing_map_id = 214
GROUP BY sp.pricing_tier;
```

**Expected Section C**: P1(36 seats rows 1-3), P2(58+ seats rows 4-9)

**Solution**:
```sql
-- Fix Section C rows 4+ to P2
UPDATE wp_hope_seating_seat_pricing sp
JOIN wp_hope_seating_physical_seats ps ON sp.physical_seat_id = ps.id
SET sp.pricing_tier = 'P2'
WHERE sp.pricing_map_id = 214 
AND ps.section = 'C' 
AND CAST(ps.row_number AS UNSIGNED) >= 4;
```

### Problem: Duplicate Seats

**Symptoms**: 
- More than 497 seats total
- Multiple seat IDs like "A1-1", "A1-1", "A1-1"

**Diagnosis**:
```sql
-- Check for duplicates
SELECT seat_id, COUNT(*) 
FROM wp_hope_seating_physical_seats 
WHERE theater_id = 'hope_main_theater'
GROUP BY seat_id 
HAVING COUNT(*) > 1;
```

**Solution**: Contact developer - requires careful manual cleanup

### Problem: Missing Seats

**Symptoms**: 
- Fewer than 497 seats
- Sections missing from seat map

**Diagnosis**:
```sql
-- Check seats by section
SELECT section, COUNT(*) as count
FROM wp_hope_seating_physical_seats 
WHERE theater_id = 'hope_main_theater'
GROUP BY section
ORDER BY section;
```

**Expected Counts by Section**:
- A: 70 seats
- B: 62 seats  
- C: 123 seats
- D: 51 seats
- E: 59 seats
- F: 28 seats
- G: 54 seats
- H: 50 seats

**Solution**: Physical seats need to be regenerated (contact developer)

### Problem: No Seats Display

**Symptoms**: 
- Seat modal opens but shows empty
- JavaScript console shows "Failed to load real seat data"

**Diagnosis**:
1. Check if product has pricing map assigned:
```sql
SELECT post_id, meta_value 
FROM wp_postmeta 
WHERE meta_key = '_hope_pricing_map_id';
```

2. Check if pricing assignments exist:
```sql
SELECT COUNT(*) 
FROM wp_hope_seating_seat_pricing 
WHERE pricing_map_id = 214;
```

**Solution**: 
- Ensure product has `_hope_pricing_map_id = 214`
- Verify pricing assignments exist for map 214

## Prevention Guidelines

### Before Making Changes
1. **Backup database** before any bulk operations
2. **Test on staging** before production changes
3. **Verify seat counts** after any regeneration

### Safe Practices
1. **Use SQL updates** for pricing changes instead of regeneration buttons
2. **Always reference the authoritative configuration** in `class-seat-initializer.php`
3. **Monitor error logs** during seating operations
4. **Keep diagnostic queries handy** for quick checks

### Red Flags to Watch For
- **Seat counts ≠ 497**: Something went wrong
- **Pricing distribution doesn't match expected**: Check assignments
- **Duplicate seat IDs**: Stop and investigate immediately
- **Empty seat maps**: Check product configuration and pricing assignments

## Emergency Recovery

If seating data becomes completely corrupted:

1. **Stop all seating operations** immediately
2. **Restore from backup** if available
3. **Contact developer** for manual reconstruction
4. **Document what caused the issue** for future prevention

## Reference Files

- **Authoritative Configuration**: `includes/class-seat-initializer.php` (lines 25-134)
- **Pricing Maps Manager**: `includes/class-pricing-maps.php`
- **Admin Interface**: `includes/class-admin.php`
- **Frontend Display**: `includes/class-frontend.php`

## Contact Information

For complex seating data issues, contact the plugin developer with:
- Current seat counts by section
- Expected vs actual pricing distribution
- Recent operations performed (regeneration, etc.)
- Error messages from logs