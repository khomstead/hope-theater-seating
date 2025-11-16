# Changelog

All notable changes to HOPE Theater Seating Plugin will be documented in this file.

## [2.8.6] - 2025-01-16

### Changed
- **Vomitorium Divider Hover Effects**: Improved visual feedback when hovering over divider lines
  - Lines now glow orange (#ff8c00) and brighten on hover, matching seat hover behavior
  - Stroke width increases from 8px to 10px on hover
  - Added drop shadow effect for visual prominence
  - Changed cursor from 'help' to 'pointer' to match seat interaction pattern

## [2.8.5] - 2025-01-16

### Fixed
- **Vomitorium Divider Tooltips**: Fixed tooltip functionality for vomitorium divider lines
  - Added `showDividerTooltip()` method for simple text tooltips
  - Dividers now display "Not an aisle" tooltip on hover
  - Original `showTooltip()` method was designed for seat elements with data attributes

## [2.8.4] - 2025-01-16

### Added
- **Vomitorium Divider Visual Indicators**: Added visual divider lines to distinguish vomitorium ramps from aisles
  - Solid gray lines (8px width, #666 color, 0.6 opacity) between sections A-B and D-E
  - Lines positioned to clearly indicate physical separation
  - Added tooltip support with "Not an aisle" message

## [2.8.3] - 2025-01-16

### Changed
- **Seat Map Layout Refinement**: Reduced angular gaps between orchestra sections
  - Sections A-B gap reduced from 4° to 0° (startAngle/endAngle: -54° → -50°)
  - Sections D-E gap reduced from 4° to 0° (startAngle/endAngle: 54° → 50°)
  - Improves visual clarity that vomitorium ramps are not aisles

## [2.8.2] - 2025-01-16

### Documentation
- **Overflow Seating Design Decision**: Added comprehensive documentation explaining why `is_overflow` is stored at the physical seat level rather than pricing tier level
  - Documents that overflow represents physical theater limitation (removable chairs)
  - Explains this applies to all pricing maps since it reflects actual theater configuration

## [2.8.1] - 2025-01-16

### Fixed
- **Overflow Seat Visibility**: Overflow seats that are sold or held now always display, even when "Hide Overflow" toggle is enabled
  - Prevents confusion for customers who have purchased overflow seats
  - Toggle now only affects available overflow seats

## [2.8.0] - 2025-01-16

### Added
- **Overflow/Removable Seating Control**: New admin and customer controls for handling overflow seating
  - Added "Hide Overflow" toggle on seat selection modal
  - Admin setting to enable/disable overflow toggle visibility
  - Database migration to add `is_overflow` column to physical_seats table
  - 19 specific seats in row 9 marked as removable (sections A, B, D, E)
  - Overflow seats visually distinguished with striped pattern

## [2.4.9] - 2025-01-08

### Added
- **Seat Reassignment Feature**: Administrators can now reassign customer seats from the WooCommerce order edit screen
  - Visual seat map modal for selecting new seats
  - Real-time availability checking
  - Single-seat selection enforcement (clicking second seat deselects first)
  - Automatic FooEvents ticket regeneration after reassignment
  - Comprehensive validation (seat availability, booking conflicts, admin blocks)
  - HPOS (High-Performance Order Storage) compatibility
  - Complete documentation in `docs/SEAT-REASSIGNMENT.md`

### Changed
- **HOPESeatMap Class Enhancements** (`assets/js/seat-map.js`):
  - `loadRealSeatData()` now uses instance-specific AJAX configuration via `this.ajax`
  - `showLoadingState()`, `generateTheater()`, and `handleSeatHover()` now use `this.containerId` for flexible container targeting
  - `handleSeatClick()` now respects `this.maxSeats` property for seat selection limits
  - Improved reusability for admin interfaces

### Fixed
- Seat map can now be instantiated multiple times with different container IDs
- Proper Set handling for `selectedSeats` (was incorrectly using array methods)
- Container ID flexibility allows same seat map library to work in both frontend and admin contexts

## [2.4.7] - Previous Release

### Added
- Selective refund support for individual seats
- Seat blocking functionality for administrators
- Enhanced database schema with refund tracking columns

### Changed
- Database architecture improvements for refund management
- Modal handler updates for admin seat blocking

## [2.3.0] - Previous Release

### Added
- Production deployment preparations
- Version bump for stability release

### Fixed
- Database schema bug causing connection errors during cart operations
- Theater seating layout and tooltip improvements

## [2.2.32] - Previous Release

### Changed
- Additional updates and improvements (staged automatically)

---

## Version Numbering

This project uses [Semantic Versioning](https://semver.org/):
- **MAJOR** version: Incompatible API changes
- **MINOR** version: New functionality (backwards compatible)
- **PATCH** version: Bug fixes (backwards compatible)

## Links
- [Documentation](docs/)
- [GitHub Repository](https://github.com/kylephillips/hope-theater-seating)
