# HOPE Theater Seating - Data Structure Reference

## Overview
This document outlines the complete data architecture for the HOPE Theater Seating plugin, which uses a separated architecture where physical seat layout is independent of pricing configurations.

## Database Architecture

### Core Concept: Separation of Concerns
- **Physical Seats**: Fixed theater layout (seats, positions, accessibility) - never changes
- **Pricing Maps**: Variable pricing configurations that can be applied to physical seats
- **Seat Pricing**: Links physical seats to pricing tiers within a specific pricing map

## Database Tables

### 1. `wp_hope_seating_physical_seats`
**Purpose**: Stores the fixed physical layout of the theater
```sql
CREATE TABLE wp_hope_seating_physical_seats (
    id int(11) NOT NULL AUTO_INCREMENT,
    theater_id varchar(50) NOT NULL DEFAULT 'hope_main_theater',
    seat_id varchar(50) NOT NULL,           -- Format: "A1-1", "B2-3", etc.
    section varchar(10) NOT NULL,           -- A, B, C, D, E, F, G, H
    row_number int(11) NOT NULL,            -- 1, 2, 3, etc.
    seat_number int(11) NOT NULL,           -- 1, 2, 3, etc.
    level varchar(20) NOT NULL DEFAULT 'orchestra', -- 'orchestra' or 'balcony'
    x_coordinate decimal(10,2) NOT NULL,    -- For visual positioning
    y_coordinate decimal(10,2) NOT NULL,    -- For visual positioning
    seat_type varchar(20) NOT NULL DEFAULT 'standard', -- 'standard' or 'accessible'
    is_accessible boolean NOT NULL DEFAULT false,
    is_blocked boolean NOT NULL DEFAULT false,
    notes text,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY theater_seat (theater_id, seat_id)
);
```
**Total Records**: 497 seats for HOPE Main Theater

### 2. `wp_hope_seating_pricing_maps`
**Purpose**: Defines different pricing configurations (e.g., Standard, Holiday, Student)
```sql
CREATE TABLE wp_hope_seating_pricing_maps (
    id int(11) NOT NULL AUTO_INCREMENT,
    name varchar(100) NOT NULL,             -- "HOPE Theater - Standard Pricing"
    description text,
    theater_id varchar(50) NOT NULL DEFAULT 'hope_main_theater',
    is_default boolean NOT NULL DEFAULT false,
    status varchar(20) NOT NULL DEFAULT 'active',
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
);
```
**Current Records**: 1 pricing map ("HOPE Theater - Standard Pricing")

### 3. `wp_hope_seating_seat_pricing`
**Purpose**: Links physical seats to pricing tiers for a specific pricing map
```sql
CREATE TABLE wp_hope_seating_seat_pricing (
    id int(11) NOT NULL AUTO_INCREMENT,
    pricing_map_id int(11) NOT NULL,
    physical_seat_id int(11) NOT NULL,
    pricing_tier varchar(10) NOT NULL,      -- P1, P2, P3, AA
    price decimal(10,2) NOT NULL,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (pricing_map_id) REFERENCES wp_hope_seating_pricing_maps(id),
    FOREIGN KEY (physical_seat_id) REFERENCES wp_hope_seating_physical_seats(id),
    UNIQUE KEY unique_seat_pricing (pricing_map_id, physical_seat_id)
);
```
**Total Records**: 497 pricing assignments (one per physical seat)

## Pricing Tiers

### Standard Pricing Map Configuration
```php
$pricing_tiers = array(
    'P1' => array('name' => 'Premium', 'default_price' => 50, 'color' => '#9b59b6'),
    'P2' => array('name' => 'Standard', 'default_price' => 35, 'color' => '#3498db'),
    'P3' => array('name' => 'Value', 'default_price' => 25, 'color' => '#17a2b8'),
    'AA' => array('name' => 'Accessible', 'default_price' => 25, 'color' => '#e67e22')
);
```

### Target Distribution (Standard Pricing Map)
- **P1 (Premium)**: 108 seats @ $50.00
- **P2 (Standard)**: 292 seats @ $35.00  
- **P3 (Value)**: 88 seats @ $25.00
- **AA (Accessible)**: 9 seats @ $25.00
- **Total**: 497 seats

## Legacy Tables (Still Present)
These tables exist from the old system but are no longer actively used:
- `wp_hope_seating_venues` - Old venue definitions
- `wp_hope_seating_seat_maps` - Old seat/pricing combined data
- `wp_hope_seating_pricing_tiers` - Old pricing tier definitions
- `wp_hope_seating_event_seats` - Event booking data (still used)

## PHP Classes

### 1. `HOPE_Physical_Seats_Manager`
**Location**: `includes/class-physical-seats.php`
**Purpose**: Manages the fixed physical layout
**Key Methods**:
- `populate_physical_seats()` - Creates all 497 physical seats
- `get_all_seats()` - Retrieves physical seats
- `get_seat_by_id()` - Gets specific seat
- `is_accessible_seat()` - Determines accessibility

### 2. `HOPE_Pricing_Maps_Manager`  
**Location**: `includes/class-pricing-maps.php`
**Purpose**: Manages pricing configurations and assignments
**Key Methods**:
- `create_pricing_map()` - Creates new pricing configuration
- `create_standard_pricing_map()` - Creates standard HOPE pricing
- `get_seats_with_pricing()` - Gets seats with pricing for a map
- `get_pricing_summary()` - Gets tier counts and statistics
- `get_seat_pricing_tier()` - Determines pricing tier for specific seat

### 3. `HOPE_Seating_Admin`
**Location**: `includes/class-admin.php`  
**Purpose**: Admin interface and diagnostics
**Key Methods**:
- `diagnostics_page()` - Shows current vs target counts
- `manual_fix_seat_assignments()` - Direct assignment tool
- `get_exact_spreadsheet_tier()` - Implements exact spreadsheet logic

## Data Flow

### 1. System Initialization
1. Physical seats are created (497 seats) via `HOPE_Physical_Seats_Manager`
2. Standard pricing map is created via `HOPE_Pricing_Maps_Manager`
3. Each physical seat gets a pricing assignment via spreadsheet logic

### 2. Admin Interface Display
1. Admin calls `get_pricing_summary($pricing_map_id)`
2. This runs: `SELECT pricing_tier, COUNT(*) FROM seat_pricing WHERE pricing_map_id = ?`
3. Results are formatted and displayed as "Seating Breakdown"

### 3. Product Configuration
1. Products select a pricing map (not a venue)
2. Frontend loads seats via `get_seats_with_pricing($pricing_map_id)`
3. This joins physical seats with their pricing assignments

## Troubleshooting Guide

> **📋 For comprehensive troubleshooting, see [TROUBLESHOOTING-SEATING-DATA.md](./TROUBLESHOOTING-SEATING-DATA.md)**

### Common Issues

#### Wrong Seat Counts
**Symptom**: Admin shows incorrect P1/P2/P3/AA counts
**Diagnosis**: Check `wp_hope_seating_seat_pricing` table
```sql
SELECT pricing_tier, COUNT(*) as count 
FROM wp_hope_seating_seat_pricing 
GROUP BY pricing_tier;
```
**Solution**: Use Diagnostics page "Fix Seat Assignments Now"

#### Missing Seats
**Symptom**: Total seats ≠ 497
**Diagnosis**: Check physical seats table
```sql
SELECT COUNT(*) FROM wp_hope_seating_physical_seats;
```
**Solution**: Reinitialize via `populate_physical_seats()`

#### Wrong Pricing Logic
**Symptom**: Individual seats have wrong pricing tier
**Diagnosis**: Compare `get_exact_spreadsheet_tier()` logic with actual spreadsheet
**Solution**: Update the seat-by-seat mapping logic

### Key Debugging Queries

```sql
-- Check total counts by tier
SELECT sp.pricing_tier, COUNT(*) as count, AVG(sp.price) as avg_price
FROM wp_hope_seating_seat_pricing sp
WHERE sp.pricing_map_id = 1
GROUP BY sp.pricing_tier;

-- Check specific section assignments  
SELECT ps.section, ps.row_number, sp.pricing_tier, COUNT(*) as count
FROM wp_hope_seating_physical_seats ps
JOIN wp_hope_seating_seat_pricing sp ON ps.id = sp.physical_seat_id
WHERE sp.pricing_map_id = 1
GROUP BY ps.section, ps.row_number, sp.pricing_tier
ORDER BY ps.section, ps.row_number;

-- Find specific problematic seats
SELECT ps.seat_id, ps.section, ps.row_number, ps.seat_number, sp.pricing_tier
FROM wp_hope_seating_physical_seats ps
JOIN wp_hope_seating_seat_pricing sp ON ps.id = sp.physical_seat_id
WHERE sp.pricing_map_id = 1 AND ps.section = 'A' AND ps.row_number = 1;
```

## Integration Points

### WooCommerce Integration
- Products store `_hope_seating_venue_id` meta (actually pricing map ID)
- Product variations are created based on pricing tiers
- Cart integration uses seat IDs and pricing map context

### Frontend Display
- Seat map loads via AJAX with pricing map ID
- Visual positioning uses x_coordinate, y_coordinate from physical seats
- Colors/pricing come from pricing tier configuration

## File References
- Physical layout: `docs/physical-seats-reference.csv`
- Pricing assignments: `docs/standard-pricing-map.csv`
- This documentation: `docs/data-structure-reference.md`