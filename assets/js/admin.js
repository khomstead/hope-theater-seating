/**
 * HOPE Theater Seating - Admin JavaScript
 * Version: 1.0.1-fixed
 * Complete working version with syntax fixes
 */

(function($) {
    'use strict';
    
    // Admin functionality for HOPE Seating
    var HOPESeatingAdmin = {
        
        init: function() {
            this.cleanupOrphanedFields();
            this.bindEvents();
            this.initVenueSelector();
            this.initSeatEditor();
        },
        
        // Clean up orphaned form fields that cause validation errors
        cleanupOrphanedFields: function() {
            console.log('HOPE Admin: Cleaning up orphaned fields...');
            
            // Remove fields that no longer exist but cause validation errors
            var fieldsToRemove = [
                '_hope_seating_price_multiplier',
                '_hope_seating_max_seats',
                '_hope_seating_enabled'
            ];
            
            fieldsToRemove.forEach(function(fieldName) {
                // Remove input fields and their containers
                $('input[name="' + fieldName + '"]').each(function() {
                    $(this).removeAttr('required').removeAttr('min').removeAttr('max');
                    $(this).closest('.form-field, .options_group').remove();
                    console.log('Removed field: ' + fieldName);
                });
                
                // Remove select fields
                $('select[name="' + fieldName + '"]').closest('.form-field, .options_group').remove();
                
                // Remove textarea fields
                $('textarea[name="' + fieldName + '"]').closest('.form-field, .options_group').remove();
            });
            
            // Remove duplicate venue selection groups
            var venueGroups = $('.hope_seating_venue');
            if (venueGroups.length > 1) {
                venueGroups.not(':first').remove();
                console.log('Removed duplicate venue groups');
            }
            
            // Fix any hidden fields with required attribute
            $('input:hidden[required]').removeAttr('required');
        },
        
        bindEvents: function() {
            var self = this;
            
            // Venue management
            $(document).on('click', '.hope-add-venue', function(e) {
                e.preventDefault();
                self.addVenue();
            });
            
            $(document).on('click', '.hope-edit-venue', function(e) {
                e.preventDefault();
                self.editVenue($(this).data('venue-id'));
            });
            
            $(document).on('click', '.hope-delete-venue', function(e) {
                e.preventDefault();
                self.deleteVenue($(this).data('venue-id'));
            });
            
            // Seat map management
            $(document).on('click', '.hope-edit-seats', function(e) {
                e.preventDefault();
                self.openSeatEditor($(this).data('venue-id'));
            });
            
            $(document).on('click', '.hope-save-seats', function(e) {
                e.preventDefault();
                self.saveSeats();
            });
            
            // Import/Export
            $(document).on('click', '.hope-import-seats', function(e) {
                e.preventDefault();
                self.importSeats();
            });
            
            $(document).on('click', '.hope-export-seats', function(e) {
                e.preventDefault();
                self.exportSeats($(this).data('venue-id'));
            });
        },
        
        initVenueSelector: function() {
            // Initialize venue dropdown if it exists
            var $venueSelect = $('#_hope_venue_id');
            if ($venueSelect.length) {
                $venueSelect.on('change', function() {
                    var venueId = $(this).val();
                    if (venueId) {
                        HOPESeatingAdmin.loadVenueDetails(venueId);
                    }
                });
            }
        },
        
        initSeatEditor: function() {
            // Initialize seat editor if on seat map page
            if ($('#hope-seat-editor').length) {
                this.seatEditor = new SeatEditor('#hope-seat-editor');
            }
        },
        
        loadVenueDetails: function(venueId) {
            // Load venue configuration
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'hope_get_venue_details',
                    venue_id: venueId,
                    nonce: hope_seating_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        HOPESeatingAdmin.displayVenueInfo(response.data);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Failed to load venue details:', error);
                }
            });
        },
        
        displayVenueInfo: function(venue) {
            // Display venue information in admin
            var info = '<div class="venue-info">';
            info += '<p><strong>Total Seats:</strong> ' + venue.total_seats + '</p>';
            info += '<p><strong>Configuration:</strong> ' + venue.configuration.type + '</p>';
            info += '</div>';
            
            $('.hope-venue-info').html(info);
        },
        
        addVenue: function() {
            // Open venue creation modal
            this.openVenueModal();
        },
        
        editVenue: function(venueId) {
            // Open venue edit modal
            this.openVenueModal(venueId);
        },
        
        deleteVenue: function(venueId) {
            if (!confirm('Are you sure you want to delete this venue? This cannot be undone.')) {
                return;
            }
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'hope_delete_venue',
                    venue_id: venueId,
                    nonce: hope_seating_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error deleting venue: ' + response.data.message);
                    }
                },
                error: function(xhr, status, error) {
                    alert('Error deleting venue: ' + error);
                }
            });
        },
        
        openVenueModal: function(venueId) {
            // Implementation for venue modal
            console.log('Opening venue modal for:', venueId || 'new venue');
            // TODO: Implement modal functionality
        },
        
        openSeatEditor: function(venueId) {
            // Open seat editor interface
            if (this.seatEditor) {
                this.seatEditor.load(venueId);
            }
        },
        
        saveSeats: function() {
            if (!this.seatEditor) {
                return;
            }
            
            var seatData = this.seatEditor.getSeatData();
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'hope_save_seat_map',
                    seat_data: seatData,
                    nonce: hope_seating_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert('Seat map saved successfully!');
                    } else {
                        alert('Error saving seat map: ' + response.data.message);
                    }
                },
                error: function(xhr, status, error) {
                    alert('Error saving seat map: ' + error);
                }
            });
        },
        
        importSeats: function() {
            var $fileInput = $('#hope-import-file');
            if (!$fileInput.length || !$fileInput[0].files.length) {
                alert('Please select a file to import');
                return;
            }
            
            var file = $fileInput[0].files[0];
            var formData = new FormData();
            formData.append('action', 'hope_import_seats');
            formData.append('file', file);
            formData.append('nonce', hope_seating_admin.nonce);
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        alert('Seats imported successfully!');
                        location.reload();
                    } else {
                        alert('Error importing seats: ' + response.data.message);
                    }
                },
                error: function(xhr, status, error) {
                    alert('Error importing seats: ' + error);
                }
            });
        },
        
        exportSeats: function(venueId) {
            window.location.href = ajaxurl + '?action=hope_export_seats&venue_id=' + venueId + '&nonce=' + hope_seating_admin.nonce;
        }
    };
    
    /**
     * Seat Editor Class
     */
    function SeatEditor(container) {
        this.container = $(container);
        this.seats = [];
        this.selectedSeats = [];
        this.venueId = null;
        
        this.init();
    }
    
    SeatEditor.prototype.init = function() {
        this.setupCanvas();
        this.bindEvents();
    };
    
    SeatEditor.prototype.setupCanvas = function() {
        // Create SVG canvas for seat editing
        this.svg = $('<svg class="seat-editor-svg" viewBox="0 0 900 600"></svg>');
        this.container.append(this.svg);
    };
    
    SeatEditor.prototype.bindEvents = function() {
        var self = this;
        
        // Click to select/deselect seats
        this.container.on('click', '.seat', function() {
            self.toggleSeat($(this));
        });
        
        // Drag to select multiple
        this.container.on('mousedown', function(e) {
            self.startDrag(e);
        });
    };
    
    SeatEditor.prototype.load = function(venueId) {
        var self = this;
        this.venueId = venueId;
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'hope_get_seat_map',
                venue_id: venueId,
                nonce: hope_seating_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    self.renderSeats(response.data.seats);
                }
            },
            error: function(xhr, status, error) {
                console.error('Failed to load seat map:', error);
            }
        });
    };
    
    SeatEditor.prototype.renderSeats = function(seats) {
        this.seats = seats;
        this.svg.empty();
        
        // Render stage
        this.renderStage();
        
        // Render each seat
        var self = this;
        seats.forEach(function(seat) {
            self.renderSeat(seat);
        });
    };
    
    SeatEditor.prototype.renderStage = function() {
        // Add stage visualization
        var stage = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        stage.setAttribute('x', 350);
        stage.setAttribute('y', 50);
        stage.setAttribute('width', 200);
        stage.setAttribute('height', 40);
        stage.setAttribute('fill', '#333');
        stage.setAttribute('rx', 5);
        
        this.svg[0].appendChild(stage);
    };
    
    SeatEditor.prototype.renderSeat = function(seat) {
        var seatEl = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        seatEl.setAttribute('cx', seat.x_coordinate);
        seatEl.setAttribute('cy', seat.y_coordinate);
        seatEl.setAttribute('r', 8);
        seatEl.setAttribute('fill', this.getSeatColor(seat));
        seatEl.setAttribute('class', 'seat');
        seatEl.setAttribute('data-seat-id', seat.id);
        
        this.svg[0].appendChild(seatEl);
    };
    
    SeatEditor.prototype.getSeatColor = function(seat) {
        var colors = {
            'P1': '#9b59b6',
            'P2': '#3498db',
            'P3': '#27ae60',
            'AA': '#e67e22'
        };
        
        return colors[seat.pricing_tier] || '#95a5a6';
    };
    
    SeatEditor.prototype.toggleSeat = function($seat) {
        var seatId = $seat.data('seat-id');
        var index = this.selectedSeats.indexOf(seatId);
        
        if (index > -1) {
            this.selectedSeats.splice(index, 1);
            $seat.removeClass('selected');
        } else {
            this.selectedSeats.push(seatId);
            $seat.addClass('selected');
        }
    };
    
    SeatEditor.prototype.startDrag = function(e) {
        // Implement drag selection
        console.log('Drag selection started');
    };
    
    SeatEditor.prototype.getSeatData = function() {
        return {
            venue_id: this.venueId,
            seats: this.seats
        };
    };
    
    // Initialize on document ready
    $(document).ready(function() {
        // Initialize admin functionality
        HOPESeatingAdmin.init();
        
        // Additional cleanup on load
        setTimeout(function() {
            // Double-check cleanup after page fully loads
            HOPESeatingAdmin.cleanupOrphanedFields();
        }, 500);
    });
    
})(jQuery);