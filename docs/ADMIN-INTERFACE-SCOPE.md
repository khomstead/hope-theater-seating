# HOPE Theater Seating - Admin Interface Development Scope

## Project Overview

**Goal**: Replace the problematic seat initializer system with a robust, user-friendly admin interface for managing seat pricing configurations.

**Background**: The current system relies on hardcoded seat configurations and dangerous regeneration buttons that have historically corrupted seating data. We need a visual, intuitive interface that eliminates the need for the initializer entirely.

## Current State

### ✅ Completed (Phase 1)
- Production seat map fixed with correct pricing tiers
- Seat initializer disabled with safety checks  
- Database backup of correct pricing assignments created
- Documentation of seating data pipeline completed
- Version 2.3.4 deployed to production

### 🎯 Target State (Phase 2)
- Visual seat map editor with click-to-change pricing
- Complete pricing map management system
- Bulk operations for efficient seat configuration
- Import/export capabilities for backup/restore
- No dependency on hardcoded initializer data

## Phase 2 Scope: Admin Interface Development

### 1. Core Admin Interface Structure

#### 1.1 Menu Organization
```
HOPE Seating (Main Menu)
├── Dashboard (overview, statistics)
├── Pricing Maps (create, manage, clone maps)
├── Seat Editor (visual seat map with pricing editor)
├── Import/Export (backup and restore configurations)
└── Diagnostics (current functionality preserved)
```

#### 1.2 User Experience Requirements
- **Intuitive**: Click-based seat editing (no SQL knowledge required)
- **Visual**: Real-time seat map with color-coded pricing tiers
- **Safe**: Built-in validation and confirmation dialogs
- **Efficient**: Bulk operations for mass changes
- **Reliable**: Undo functionality and backup integration

### 2. Feature Specifications

#### 2.1 Visual Seat Map Editor 🎨
**Priority**: HIGH - Core functionality

**Features:**
- Interactive SVG-based seat map identical to frontend display
- Click seat → change pricing tier (P1/P2/P3/AA)
- Real-time color updates with immediate visual feedback
- Hover tooltips showing seat ID and current tier
- Section-based view (filter by section A-H)
- Zoom and pan controls for detailed editing

**Technical Implementation:**
- Reuse existing seat positioning logic from frontend
- AJAX-powered seat updates with optimistic UI updates
- WebSocket or polling for multi-user editing safety
- CSS transitions for smooth color changes

#### 2.2 Pricing Map Management 📋
**Priority**: HIGH - Essential for multi-event support

**Features:**
- Create new pricing maps with custom names/descriptions
- Clone existing maps with modification capabilities
- Delete unused pricing maps with safety checks
- Set default pricing map for new products
- Archive old maps without losing historical data

**Use Cases:**
- "Summer 2024 Standard Pricing" 
- "Holiday Special Pricing" (+$10 all tiers)
- "Student Discount Pricing" (-$5 all tiers)
- "VIP Event Pricing" (premium rates)

#### 2.3 Bulk Operations ⚡
**Priority**: MEDIUM - Efficiency improvement

**Features:**
- Rectangle selection tool (drag to select seat areas)
- Section-based selection (select all of Section C)
- Row-based selection (select entire Row 5)
- Multi-select with Ctrl+click
- Bulk pricing tier changes for selected seats
- Pattern application (e.g., "front 3 rows = P1")

#### 2.4 Import/Export System 💾
**Priority**: MEDIUM - Data portability

**Features:**
- Export pricing map as CSV/JSON
- Import pricing configuration from file
- Full backup export (all maps + physical seats)
- Selective restore (choose specific maps to restore)
- Format validation with detailed error reporting

**File Formats:**
```csv
# CSV Format
section,row,seat,pricing_tier,price
A,1,1,P1,50.00
A,1,2,P1,50.00
```

```json
{
  "pricing_map": {
    "name": "Standard Pricing",
    "seats": [
      {"seat_id": "A1-1", "tier": "P1", "price": 50.00},
      {"seat_id": "A1-2", "tier": "P1", "price": 50.00}
    ]
  }
}
```

#### 2.5 Accessibility Features ♿
**Priority**: HIGH - Legal compliance and user experience

**Features:**
- AA seat confirmation popup with accessibility notice
- Clear explanation that AA seats are reserved for mobility assistance
- Option to proceed or select different seats
- ADA compliance messaging and disclaimers

**Popup Message:**
```
"Accessible Seating Notice

These seats are specifically reserved for patrons with mobility 
challenges, wheelchair users, and their companions. 

Are you selecting these seats because you or someone in your 
party requires accessible seating?

[Yes, we need accessible seating] [No, select different seats]"
```

#### 2.6 Advanced Features 🔧
**Priority**: LOW - Nice-to-have enhancements

**Features:**
- Pricing tier templates (save common patterns)
- Price adjustment tools (increase all P1 by 10%)
- Seat availability overlay (show booked seats)
- Change history log with rollback capability
- Multi-theater support (if expanded beyond HOPE)

### 3. Technical Architecture

#### 3.1 Database Schema (No Changes Required)
- Existing separated architecture is perfect
- `wp_hope_seating_physical_seats` (physical layout)
- `wp_hope_seating_pricing_maps` (pricing configurations) 
- `wp_hope_seating_seat_pricing` (seat → tier assignments)

#### 3.2 New PHP Classes Required

```php
// Core admin interface
class HOPE_Seating_Admin_Interface {
    // Visual seat map editor
    // Pricing map CRUD operations
    // Bulk operations handler
}

// Import/export functionality  
class HOPE_Seating_Import_Export {
    // CSV/JSON import/export
    // Validation and error handling
    // Backup/restore operations
}

// AJAX handlers for real-time updates
class HOPE_Seating_Admin_Ajax {
    // Seat pricing updates
    // Bulk operations
    // Map management
}
```

#### 3.3 Frontend Technologies
- **JavaScript**: ES6+ with async/await for AJAX
- **CSS**: Modern flexbox/grid for responsive layout
- **SVG**: Interactive seat map with click handlers
- **AJAX**: Real-time updates without page refreshes

### 4. Development Phases

#### Phase 2A: Foundation (Week 1)
- [ ] Create new admin interface structure
- [ ] Implement basic pricing map CRUD operations
- [ ] Set up AJAX handlers for seat updates
- [ ] Create basic visual seat map editor

#### Phase 2B: Core Features (Week 2)  
- [ ] Complete click-to-edit seat functionality
- [ ] Add bulk selection and operations
- [ ] Implement real-time visual feedback
- [ ] Add validation and safety checks

#### Phase 2C: Advanced Features (Week 3)
- [ ] Build import/export system
- [ ] Add undo/redo functionality
- [ ] Create pricing tier templates
- [ ] Implement change history logging

#### Phase 2D: Polish & Testing (Week 4)
- [ ] User experience testing and refinements
- [ ] Performance optimization
- [ ] Documentation and training materials
- [ ] Production deployment

### 5. Success Criteria

#### 5.1 Functional Requirements
✅ **Complete Independence**: No reliance on seat initializer
✅ **Visual Management**: All seat pricing editable via GUI
✅ **Data Safety**: No risk of seat duplication or corruption  
✅ **Multi-Map Support**: Multiple pricing configurations per theater
✅ **Bulk Efficiency**: Fast editing of large seat selections

#### 5.2 User Experience Requirements
✅ **Intuitive Interface**: Non-technical staff can manage pricing
✅ **Visual Feedback**: Immediate confirmation of changes
✅ **Error Prevention**: Clear warnings before destructive actions
✅ **Backup Integration**: Easy export/import for safety

#### 5.3 Technical Requirements  
✅ **Performance**: Sub-second response times for seat updates
✅ **Reliability**: 99.9% uptime for admin interface
✅ **Compatibility**: Works with existing WooCommerce integration
✅ **Maintainability**: Clean, documented code for future updates

### 6. Risk Assessment & Mitigation

#### 6.1 Technical Risks
**Risk**: Database corruption during bulk operations
**Mitigation**: Transaction rollback, automatic backups before changes

**Risk**: Concurrent editing conflicts (multiple users)  
**Mitigation**: Optimistic locking, change conflict detection

**Risk**: Performance issues with 497 seats
**Mitigation**: Efficient SQL queries, caching, progressive loading

#### 6.2 User Experience Risks
**Risk**: Complex interface overwhelming non-technical users
**Mitigation**: Progressive disclosure, guided tutorials, sensible defaults

**Risk**: Accidental bulk changes destroying configuration
**Mitigation**: Confirmation dialogs, undo functionality, automatic backups

### 7. Post-Implementation

#### 7.1 Cleanup Tasks
- Remove disabled initializer code completely
- Archive old troubleshooting documentation  
- Update user documentation and training materials
- Remove hardcoded pricing configurations

#### 7.2 Future Enhancements
- Multi-theater support for venue expansion
- API endpoints for external system integration
- Advanced reporting and analytics
- Mobile-responsive interface for tablet management

---

## Estimated Timeline: 3-4 weeks
## Estimated Effort: 80-100 hours
## Primary Developer: Claude Code + Kyle (review/testing)

**Next Steps:**
1. Review and approve scope document
2. Begin Phase 2A development
3. Set up testing environment for admin interface
4. Create user acceptance criteria for each feature

---

*This scope document serves as the roadmap for eliminating the problematic seat initializer and creating a production-ready admin interface for seat pricing management.*