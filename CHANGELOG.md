# Changelog

All notable changes to HOPE Theater Seating Plugin will be documented in this file.

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
