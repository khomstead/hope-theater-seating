# HOPE Theater Seating - Data Structure Documentation

## Overview
The HOPE Theater Seating plugin uses a **separated architecture** where physical seat layout is independent of pricing configurations. This design provides maximum flexibility for managing different pricing schemes (standard, holiday, student discounts) for the same physical theater.

## Core Principle: Separation of Concerns
- **Physical Seats**: Fixed theater layout (seats, positions, accessibility) - never changes
- **Pricing Maps**: Variable pricing configurations that can be applied to physical seats  
- **Seat Pricing**: Links physical seats to pricing tiers within a specific pricing map

## Database Tables

### 1. Physical Seats (`wp_hope_seating_physical_seats`)
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
- **Orchestra Level**: 365 seats (Sections A-E)
- **Balcony Level**: 132 seats (Sections F-H)

### 2. Pricing Maps (`wp_hope_seating_pricing_maps`)
**Purpose**: Defines different pricing configurations

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
**Future Usage**: Multiple pricing maps for different events/seasons

### 3. Seat Pricing (`wp_hope_seating_seat_pricing`)  
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

### Current Distribution (Standard Pricing Map)
- **P1 (Premium)**: 108 seats @ $50.00
- **P2 (Standard)**: 292 seats @ $35.00  
- **P3 (Value)**: 88 seats @ $25.00
- **AA (Accessible)**: 9 seats @ $25.00
- **Total**: 497 seats

### Section Breakdown
| Section | P1  | P2  | P3  | AA  | Total |
|---------|-----|-----|-----|-----|-------|
| A       | 8   | 41  | 21  | 0   | 70    |
| B       | 13  | 40  | 4   | 5   | 62    |
| C       | 33  | 90  | 0   | 0   | 123   |
| D       | 9   | 35  | 5   | 2   | 51    |
| E       | 7   | 28  | 22  | 2   | 59    |
| F       | 5   | 5   | 18  | 0   | 28    |
| G       | 24  | 30  | 0   | 0   | 54    |
| H       | 9   | 23  | 18  | 0   | 50    |

## PHP Architecture

### Class Structure

#### 1. `HOPE_Physical_Seats_Manager`
**Location**: `includes/class-physical-seats.php`
**Purpose**: Manages the fixed physical layout
**Key Methods**:
- `populate_physical_seats()` - Creates all 497 physical seats
- `get_all_seats()` - Retrieves physical seats
- `get_seat_by_id()` - Gets specific seat
- `is_accessible_seat()` - Determines accessibility

#### 2. `HOPE_Pricing_Maps_Manager`  
**Location**: `includes/class-pricing-maps.php`
**Purpose**: Manages pricing configurations and assignments
**Key Methods**:
- `create_pricing_map()` - Creates new pricing configuration
- `create_standard_pricing_map()` - Creates standard HOPE pricing
- `get_seats_with_pricing()` - Gets seats with pricing for a map
- `get_pricing_summary()` - Gets tier counts and statistics
- `get_seat_pricing_tier()` - Determines pricing tier for specific seat

#### 3. `HOPE_Seating_Admin`
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

## Seating Data Pipeline

### Overview
The seating data goes through a multi-stage initialization process that establishes both the physical layout and pricing configurations. Understanding this pipeline is critical for troubleshooting and managing seating data.

### Stage 1: Physical Seats Creation
**File**: `includes/class-seat-initializer.php`  
**Method**: `HOPE_Seating_Initializer::initialize_hope_theater($venue_id)`

**Purpose**: Creates the definitive physical seat layout with accurate coordinates and initial pricing tier assignments.

**Key Characteristics**:
- Based on architectural drawings and Excel spreadsheet data
- Contains the **authoritative** pricing tier configuration
- Creates 497 seats total for HOPE Main Theater
- Includes precise x/y coordinates for visual positioning
- Defines accessibility designations

**Example Configuration** (Section C):
```php
'C' => array(
    'rows' => array(
        1 => array('seats' => 10, 'pricing' => array_fill(1, 10, 'P1')),  // Rows 1-3: P1
        2 => array('seats' => 11, 'pricing' => array_fill(1, 11, 'P1')),
        3 => array('seats' => 12, 'pricing' => array_fill(1, 12, 'P1')),
        4 => array('seats' => 13, 'pricing' => array_fill(1, 13, 'P2')),  // Rows 4-9: P2
        5 => array('seats' => 14, 'pricing' => array_fill(1, 14, 'P2')),
        // ... continues
    )
),
```

### Stage 2: Pricing Maps Creation
**File**: `includes/class-pricing-maps.php`  
**Method**: `create_standard_pricing_map($theater_id)`

**Purpose**: Creates the pricing map records that will be used by products.

**Process**:
1. Creates pricing map record in `wp_hope_seating_pricing_maps`
2. Defines pricing tier values (P1=$1, P2=$2, P3=$3, AA=$5)
3. Links to the physical theater layout

**Current Active Map**:
- **ID**: 214
- **Name**: "HOPE Theater - Standard Pricing" 
- **Theater**: hope_main_theater
- **Status**: active

### Stage 3: Pricing Assignments Generation
**File**: `includes/class-pricing-maps.php`  
**Method**: `regenerate_pricing_assignments($pricing_map_id)`

**Purpose**: Links physical seats to specific pricing tiers within a pricing map.

**Critical Process**:
1. Deletes existing assignments for the pricing map
2. Iterates through all physical seats
3. Applies pricing tier logic to each seat
4. Creates records in `wp_hope_seating_seat_pricing`

**⚠️ Known Issues**:
- This stage has historically contained bugs that created incorrect assignments
- The regeneration logic may not match the authoritative initializer configuration
- Previous implementations created duplicate seats and wrong pricing tiers

### Stage 4: Product Integration
**Process**: Products are configured to use specific pricing maps via WooCommerce meta fields.

**Meta Field**: `_hope_pricing_map_id` (stores the pricing map ID, e.g., 214)

### Data Flow Summary
```
Architectural Plans & Excel Data
         ↓
    Seat Initializer (AUTHORITATIVE)
         ↓
    Physical Seats Table (497 seats)
         ↓
    Pricing Maps Creation (Map ID 214)
         ↓
    Pricing Assignments Generation (POTENTIAL BUGS HERE)
         ↓
    Seat Pricing Table (Links seats → tiers)
         ↓
    Product Configuration (Uses Map ID)
         ↓
    Frontend Display (AJAX loads seat data)
```

### Troubleshooting Guidelines

#### 1. Visual Display Issues
**Symptom**: Seat map shows wrong colors/pricing
**Root Cause**: Usually incorrect pricing assignments in Stage 3
**Solution**: Compare database assignments with authoritative initializer configuration

#### 2. Missing Seats
**Symptom**: Fewer seats than expected (497 for HOPE)
**Root Cause**: Physical seats creation failed or partial deletion occurred
**Solution**: Check physical seats count, regenerate if necessary

#### 3. Duplicate Seats
**Symptom**: More seats than expected (e.g., 994, 2485)
**Root Cause**: Multiple runs of initialization without proper cleanup
**Solution**: Manual database cleanup, disable problematic regeneration buttons

#### 4. Wrong Pricing Distribution
**Symptom**: Incorrect tier counts (e.g., P1 too high, P2 too low)
**Root Cause**: Bug in pricing assignments generation logic
**Solution**: Direct SQL updates to match authoritative configuration

**Expected Distribution** (Map ID 214):
- **P1**: 108 seats (front rows, premium locations)
- **P2**: 292 seats (middle sections, standard pricing)  
- **P3**: 88 seats (back rows, budget pricing)
- **AA**: 9 seats (accessible seating)
- **Total**: 497 seats

### Maintenance Recommendations

1. **Always verify against authoritative source** (`class-seat-initializer.php`)
2. **Test regeneration on staging before production**
3. **Monitor seat counts after any regeneration operations**
4. **Use the admin interface for pricing changes instead of regeneration buttons**
5. **Keep backups before bulk operations**

## Integration Points

### WooCommerce Integration
- Products store `_hope_seating_venue_id` meta (actually pricing map ID)
- Product variations are created based on pricing tiers
- Cart integration uses seat IDs and pricing map context

### Frontend Display
- Seat map loads via AJAX with pricing map ID
- Visual positioning uses x_coordinate, y_coordinate from physical seats
- Colors/pricing come from pricing tier configuration

## Future Expansion Capabilities

### 1. **Physical Layout Management**
- Add/remove seats: INSERT/DELETE from `physical_seats` table
- Modify positions: UPDATE coordinates
- Change accessibility: UPDATE `is_accessible`
- **Admin Interface Needed**: Visual drag-and-drop seat editor

### 2. **Pricing Map Management**
- Create seasonal pricing: INSERT new pricing map
- Clone existing maps: Copy pricing assignments
- Bulk pricing updates: UPDATE pricing assignments
- **Admin Interface Needed**: Visual pricing assignment tool

### 3. **Event-Specific Configurations**
- Holiday pricing: New pricing map with premium rates
- Student discounts: New pricing map with reduced rates
- VIP events: Custom pricing map with exclusive seating

### 4. **Import/Export Capabilities**
- Export pricing maps: CSV/JSON export
- Import seat layouts: CSV import for renovations
- Backup/restore: Full database export/import

## Legacy Tables (Deprecated)
These tables exist from the old system but are no longer actively used:
- `wp_hope_seating_venues` - Old venue definitions
- `wp_hope_seating_seat_maps` - Old seat/pricing combined data
- `wp_hope_seating_pricing_tiers` - Old pricing tier definitions
- `wp_hope_seating_event_seats` - Event booking data (still used for bookings)

## Key Design Decisions

### 1. **Separated Architecture Benefits**
- **Flexibility**: Same physical theater, multiple pricing schemes
- **Scalability**: Easy to add new pricing maps without rebuilding seats
- **Maintainability**: Clear separation of concerns
- **Future-proof**: Ready for complex pricing scenarios

### 2. **Type Safety Lessons Learned**
- Database row numbers come as strings ('1', '2') not integers (1, 2)
- Use loose comparison (`==`) not strict (`===`) for row/seat number matching
- Critical for spreadsheet logic implementation

### 3. **Data Integrity**
- Foreign key constraints ensure referential integrity
- Unique constraints prevent duplicate seat assignments
- Default values provide safe fallbacks

## Troubleshooting

### Common Issues
- **Wrong seat counts**: Check `get_exact_spreadsheet_tier()` logic
- **Type comparison errors**: Ensure loose comparison for row numbers
- **Missing seats**: Verify physical seats table population

### Diagnostic Queries
```sql
-- Check total counts by tier
SELECT sp.pricing_tier, COUNT(*) as count, AVG(sp.price) as avg_price
FROM wp_hope_seating_seat_pricing sp
WHERE sp.pricing_map_id = 1
GROUP BY sp.pricing_tier;

-- Check section breakdown
SELECT ps.section, sp.pricing_tier, COUNT(*) as count
FROM wp_hope_seating_physical_seats ps
JOIN wp_hope_seating_seat_pricing sp ON ps.id = sp.physical_seat_id
WHERE sp.pricing_map_id = 1
GROUP BY ps.section, sp.pricing_tier
ORDER BY ps.section, sp.pricing_tier;
```

---

*This document serves as the definitive reference for understanding and extending the HOPE Theater Seating plugin architecture.*