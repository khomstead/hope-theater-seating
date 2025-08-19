/**
 * HOPE Theater Seating Plugin - Frontend JavaScript
 * Accurate half-round theater layout based on architectural drawings
 * Version: 3.0.0 - ACCURATE LAYOUT
 */

(function($) {
    'use strict';
    
    class HOPETheaterSeatingChart {
        constructor(container, options = {}) {
            this.container = typeof container === 'string' ? 
                document.querySelector(container) : container;
            
            if (!this.container) {
                console.error('HOPE Seating: Container element not found');
                return;
            }
            
            // Default options with accurate theater dimensions
            this.options = {
                venueId: null,
                productId: null,
                eventId: null,
                showPricing: true,
                showLegend: true,
                showSectionLabels: true,
                enableSelection: true,
                maxSelections: 10,
                viewBox: '0 0 1200 900', // Wider for half-round layout
                ...options
            };
            
            // Handle preloaded data
            if (options.seatData && options.venueData) {
                this.preloadedData = {
                    seats: options.seatData,
                    venue: options.venueData,
                    booked_seats: options.bookedSeats || []
                };
            }
            
            // Accurate color scheme from project specs
            this.colors = {
                'P1': '#9b59b6',  // VIP Purple
                'P2': '#3498db',  // Premium Blue  
                'P3': '#27ae60',  // General Green
                'AA': '#e67e22',  // Accessible Orange
                'selected': '#f39c12',
                'unavailable': '#e74c3c',
                'blocked': '#95a5a6',
                'hover': '#34495e'
            };
            
            // Theater layout configuration
            this.theaterConfig = {
                orchestra: {
                    centerX: 600,
                    startY: 300,
                    rowSpacing: 35,
                    seatSize: 12,
                    sections: {
                        'A': { startAngle: -75, endAngle: -45, startRadius: 150 },
                        'B': { startAngle: -45, endAngle: -15, startRadius: 150 },
                        'C': { startAngle: -15, endAngle: 15, startRadius: 150 },
                        'D': { startAngle: 15, endAngle: 45, startRadius: 150 },
                        'E': { startAngle: 45, endAngle: 75, startRadius: 150 }
                    }
                },
                balcony: {
                    centerX: 600,
                    startY: 600,
                    rowSpacing: 35,
                    seatSize: 10,
                    sections: {
                        'F': { startAngle: -60, endAngle: -20, startRadius: 100 },
                        'G': { startAngle: -20, endAngle: 20, startRadius: 100 },
                        'H': { startAngle: 20, endAngle: 60, startRadius: 100 }
                    }
                }
            };
            
            this.selectedSeats = new Set();
            this.seatElements = new Map();
            this.bookedSeats = new Set();
            
            this.init();
        }
        
        async init() {
            this.showLoading();
            
            try {
                const data = this.preloadedData || await this.fetchSeatData();
                this.seatData = data.seats || [];
                this.venueData = data.venue || {};
                this.bookedSeats = new Set(data.booked_seats || []);
                
                this.render();
                this.bindEvents();
                
            } catch (error) {
                console.error('Failed to initialize seating chart:', error);
                this.showError('Failed to load seating chart. Please refresh the page.');
            }
        }
        
        async fetchSeatData() {
            if (this.preloadedData) {
                return this.preloadedData;
            }
            
            const ajaxUrl = window.hopeSeating?.ajaxurl || '/wp-admin/admin-ajax.php';
            const nonce = window.hopeSeating?.nonce || '';
            
            const response = await fetch(ajaxUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'hope_get_venue_seats',
                    venue_id: this.options.venueId,
                    event_id: this.options.eventId || this.options.productId || '',
                    nonce: nonce
                })
            });
            
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            
            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.data || 'Failed to load seat data');
            }
            
            return data.data;
        }
        
        showLoading() {
            while (this.container.firstChild) {
                this.container.removeChild(this.container.firstChild);
            }
            
            const loadingDiv = document.createElement('div');
            loadingDiv.setAttribute('class', 'hope-seating-loading');
            
            const spinner = document.createElement('div');
            spinner.setAttribute('class', 'spinner');
            
            const loadingText = document.createElement('p');
            loadingText.textContent = 'Loading seating chart...';
            
            loadingDiv.appendChild(spinner);
            loadingDiv.appendChild(loadingText);
            this.container.appendChild(loadingDiv);
        }
        
        showError(message) {
            while (this.container.firstChild) {
                this.container.removeChild(this.container.firstChild);
            }
            
            const errorDiv = document.createElement('div');
            errorDiv.setAttribute('class', 'hope-seating-error');
            
            const errorText = document.createElement('p');
            errorText.textContent = message;
            
            errorDiv.appendChild(errorText);
            this.container.appendChild(errorDiv);
        }
        
        render() {
            const newContainer = document.createElement('div');
            newContainer.setAttribute('class', 'hope-seating-container');
            
            const wrapper = document.createElement('div');
            wrapper.setAttribute('class', 'hope-seating-wrapper');
            
            // Add header
            const header = document.createElement('div');
            header.setAttribute('class', 'hope-seating-header');
            const headerTitle = document.createElement('h2');
            headerTitle.textContent = 'Select Your Seats';
            header.appendChild(headerTitle);
            wrapper.appendChild(header);
            
            // Create SVG container
            const svgContainer = document.createElement('div');
            svgContainer.setAttribute('class', 'hope-seating-svg-container');
            
            // Create SVG element
            this.svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            this.svg.setAttribute('viewBox', this.options.viewBox);
            this.svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
            this.svg.setAttribute('class', 'hope-seating-svg');
            
            // Draw the theater layout
            this.drawStage();
            this.drawSeats();
            if (this.options.showSectionLabels) {
                this.drawSectionLabels();
            }
            
            svgContainer.appendChild(this.svg);
            wrapper.appendChild(svgContainer);
            
            // Add legend if enabled
            if (this.options.showLegend) {
                wrapper.appendChild(this.createLegend());
            }
            
            // Add selected seats display
            const selectedDisplay = document.createElement('div');
            selectedDisplay.setAttribute('class', 'hope-selected-seats-display');
            
            const selectedTitle = document.createElement('h3');
            selectedTitle.textContent = 'Selected Seats: ';
            const selectedCount = document.createElement('span');
            selectedCount.setAttribute('id', 'selected-count');
            selectedCount.textContent = '0';
            selectedTitle.appendChild(selectedCount);
            
            const selectedList = document.createElement('div');
            selectedList.setAttribute('id', 'selected-list');
            
            selectedDisplay.appendChild(selectedTitle);
            selectedDisplay.appendChild(selectedList);
            wrapper.appendChild(selectedDisplay);
            
            newContainer.appendChild(wrapper);
            
            // Clear the original container and append our new container
            while (this.container.firstChild) {
                this.container.removeChild(this.container.firstChild);
            }
            this.container.appendChild(newContainer);
        }
        
        drawStage() {
            // Draw curved stage at the top
            const stage = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            const cx = 600;
            const cy = 200;
            const width = 400;
            const height = 80;
            
            // Create a curved stage path
            const pathData = `
                M ${cx - width/2} ${cy}
                Q ${cx - width/2} ${cy - height}, ${cx} ${cy - height}
                T ${cx + width/2} ${cy}
                L ${cx + width/2} ${cy + 20}
                Q ${cx + width/2} ${cy - height + 20}, ${cx} ${cy - height + 20}
                T ${cx - width/2} ${cy + 20}
                Z
            `;
            
            stage.setAttribute('d', pathData);
            stage.setAttribute('fill', '#2c3e50');
            stage.setAttribute('stroke', '#34495e');
            stage.setAttribute('stroke-width', '2');
            stage.setAttribute('class', 'hope-stage');
            
            // Add stage label
            const stageLabel = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            stageLabel.setAttribute('x', cx);
            stageLabel.setAttribute('y', cy - 30);
            stageLabel.setAttribute('text-anchor', 'middle');
            stageLabel.setAttribute('class', 'stage-label');
            stageLabel.setAttribute('fill', '#2c3e50');
            stageLabel.setAttribute('font-size', '24');
            stageLabel.setAttribute('font-weight', 'bold');
            stageLabel.textContent = 'STAGE';
            
            this.svg.appendChild(stage);
            this.svg.appendChild(stageLabel);
        }
        
        drawSeats() {
            // Group seats by section for better organization
            const sections = this.groupSeatsBySection();
            
            Object.entries(sections).forEach(([sectionName, seats]) => {
                const sectionGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                sectionGroup.setAttribute('class', `section-${sectionName}`);
                sectionGroup.setAttribute('data-section', sectionName);
                
                seats.forEach(seat => {
                    const seatElement = this.createSeatElement(seat);
                    sectionGroup.appendChild(seatElement);
                    this.seatElements.set(
                        seat.id || `${seat.section}-${seat.row_number}-${seat.seat_number}`,
                        seatElement
                    );
                });
                
                this.svg.appendChild(sectionGroup);
            });
        }
        
        drawSectionLabels() {
            // Orchestra section labels
            const orchestraSections = [
                { name: 'A', x: 250, y: 450 },
                { name: 'B', x: 400, y: 400 },
                { name: 'C', x: 600, y: 380 },
                { name: 'D', x: 800, y: 400 },
                { name: 'E', x: 950, y: 450 }
            ];
            
            // Balcony section labels
            const balconySections = [
                { name: 'F', x: 350, y: 650 },
                { name: 'G', x: 600, y: 630 },
                { name: 'H', x: 850, y: 650 }
            ];
            
            [...orchestraSections, ...balconySections].forEach(section => {
                const label = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                label.setAttribute('x', section.x);
                label.setAttribute('y', section.y);
                label.setAttribute('text-anchor', 'middle');
                label.setAttribute('class', 'section-label');
                label.setAttribute('fill', '#7f8c8d');
                label.setAttribute('font-size', '18');
                label.setAttribute('font-weight', 'bold');
                label.setAttribute('opacity', '0.6');
                label.textContent = `Section ${section.name}`;
                this.svg.appendChild(label);
            });
        }
        
        groupSeatsBySection() {
            const sections = {};
            this.seatData.forEach(seat => {
                const section = seat.section || 'default';
                if (!sections[section]) {
                    sections[section] = [];
                }
                sections[section].push(seat);
            });
            return sections;
        }
        
        createSeatElement(seat) {
            const seatGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            seatGroup.setAttribute('class', 'seat-group');
            seatGroup.setAttribute('data-seat-id', seat.id || `${seat.section}-${seat.row_number}-${seat.seat_number}`);
            
            // Calculate position using half-round layout
            const position = this.calculateAccurateSeatPosition(seat);
            
            // Determine seat size based on level
            const isBalcony = ['F', 'G', 'H'].includes(seat.section);
            const seatSize = isBalcony ? 
                this.theaterConfig.balcony.seatSize : 
                this.theaterConfig.orchestra.seatSize;
            
            // Create seat rectangle (theater seats are typically rectangular)
            const seatRect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
            seatRect.setAttribute('x', position.x - seatSize/2);
            seatRect.setAttribute('y', position.y - seatSize/2);
            seatRect.setAttribute('width', seatSize);
            seatRect.setAttribute('height', seatSize);
            seatRect.setAttribute('rx', '2'); // Rounded corners
            seatRect.setAttribute('class', 'seat');
            
            // Rotate seat to face the stage
            if (position.rotation) {
                seatRect.setAttribute('transform', 
                    `rotate(${position.rotation} ${position.x} ${position.y})`
                );
            }
            
            // Set seat color based on status and pricing tier
            const isBooked = this.bookedSeats.has(seat.id) || 
                            this.bookedSeats.has(seat.seat_number) ||
                            this.bookedSeats.has(`${seat.section}${seat.row_number}-${seat.seat_number}`);
            const pricingTier = seat.pricing_tier || 'P3';
            
            if (isBooked) {
                seatRect.setAttribute('fill', this.colors.unavailable);
                seatRect.setAttribute('class', 'seat unavailable');
            } else if (seat.status === 'blocked') {
                seatRect.setAttribute('fill', this.colors.blocked);
                seatRect.setAttribute('class', 'seat blocked');
            } else {
                seatRect.setAttribute('fill', this.colors[pricingTier] || this.colors.P3);
                seatRect.setAttribute('class', 'seat available');
            }
            
            // Add seat label (only for larger seats)
            if (seatSize >= 12) {
                const seatLabel = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                seatLabel.setAttribute('x', position.x);
                seatLabel.setAttribute('y', position.y + 3);
                seatLabel.setAttribute('text-anchor', 'middle');
                seatLabel.setAttribute('class', 'seat-label');
                seatLabel.setAttribute('pointer-events', 'none');
                seatLabel.setAttribute('fill', 'white');
                seatLabel.setAttribute('font-size', '8');
                seatLabel.textContent = seat.seat_number || seat.number || '';
                seatGroup.appendChild(seatLabel);
            }
            
            seatGroup.appendChild(seatRect);
            
            // Store seat data
            seatGroup.seatData = seat;
            
            return seatGroup;
        }
        
        calculateAccurateSeatPosition(seat) {
            const section = seat.section;
            const row = parseInt(seat.row_number) || 1;
            const seatNum = parseInt(seat.seat_number) || 1;
            
            // Check if this is a balcony seat
            const isBalcony = ['F', 'G', 'H'].includes(section);
            const config = isBalcony ? this.theaterConfig.balcony : this.theaterConfig.orchestra;
            const sectionConfig = config.sections[section];
            
            if (!sectionConfig) {
                // Fallback for unknown sections
                return {
                    x: 600 + (seatNum * 15),
                    y: 400 + (row * 30),
                    rotation: 0
                };
            }
            
            // Get total seats in this row from the data
            const rowSeats = this.seatData.filter(s => 
                s.section === section && s.row_number == row
            ).length || 10;
            
            // Calculate radius for this row (increases as you go back)
            const radius = sectionConfig.startRadius + (row * config.rowSpacing);
            
            // Calculate angle for this seat
            const angleRange = sectionConfig.endAngle - sectionConfig.startAngle;
            const angleStep = angleRange / (rowSeats + 1);
            const seatAngle = sectionConfig.startAngle + (angleStep * seatNum);
            
            // Convert to radians
            const angleRad = (seatAngle * Math.PI) / 180;
            
            // Calculate position
            const x = config.centerX + (radius * Math.sin(angleRad));
            const y = config.startY + (radius * Math.cos(angleRad));
            
            // Calculate rotation so seat faces the stage
            const rotation = -seatAngle;
            
            return { x, y, rotation };
        }
        
        createLegend() {
            const legend = document.createElement('div');
            legend.setAttribute('class', 'hope-seating-legend');
            
            const title = document.createElement('h3');
            title.textContent = 'Pricing Tiers';
            legend.appendChild(title);
            
            const itemsContainer = document.createElement('div');
            itemsContainer.setAttribute('class', 'legend-items');
            
            const tiers = [
                { color: this.colors.P1, label: 'P1 - VIP ($50)' },
                { color: this.colors.P2, label: 'P2 - Premium ($35)' },
                { color: this.colors.P3, label: 'P3 - General ($25)' },
                { color: this.colors.AA, label: 'AA - Accessible ($25)' },
                { color: this.colors.unavailable, label: 'Unavailable' },
                { color: this.colors.selected, label: 'Selected' }
            ];
            
            tiers.forEach(tier => {
                const item = document.createElement('div');
                item.setAttribute('class', 'legend-item');
                
                const colorSpan = document.createElement('span');
                colorSpan.setAttribute('class', 'legend-color');
                colorSpan.style.setProperty('background-color', tier.color);
                colorSpan.style.setProperty('display', 'inline-block');
                colorSpan.style.setProperty('width', '20px');
                colorSpan.style.setProperty('height', '20px');
                colorSpan.style.setProperty('margin-right', '8px');
                colorSpan.style.setProperty('border', '1px solid #ddd');
                colorSpan.style.setProperty('border-radius', '3px');
                
                const labelSpan = document.createElement('span');
                labelSpan.textContent = tier.label;
                
                item.appendChild(colorSpan);
                item.appendChild(labelSpan);
                item.style.setProperty('margin-bottom', '5px');
                itemsContainer.appendChild(item);
            });
            
            legend.appendChild(itemsContainer);
            return legend;
        }
        
        bindEvents() {
            if (!this.options.enableSelection) return;
            
            this.seatElements.forEach((element, seatId) => {
                const seatRect = element.querySelector('.seat');
                
                if (!seatRect.classList.contains('unavailable') && !seatRect.classList.contains('blocked')) {
                    element.addEventListener('click', () => this.handleSeatClick(seatId, element));
                    element.addEventListener('mouseenter', () => this.handleSeatHover(element, true));
                    element.addEventListener('mouseleave', () => this.handleSeatHover(element, false));
                    element.style.setProperty('cursor', 'pointer');
                }
            });
        }
        
        handleSeatClick(seatId, element) {
            const seatRect = element.querySelector('.seat');
            
            if (this.selectedSeats.has(seatId)) {
                // Deselect seat
                this.selectedSeats.delete(seatId);
                seatRect.setAttribute('fill', this.getSeatOriginalColor(element));
                seatRect.classList.remove('selected');
            } else if (this.selectedSeats.size < this.options.maxSelections) {
                // Select seat
                this.selectedSeats.add(seatId);
                seatRect.setAttribute('fill', this.colors.selected);
                seatRect.classList.add('selected');
            } else {
                alert(`Maximum ${this.options.maxSelections} seats can be selected`);
            }
            
            this.updateSelectedDisplay();
        }
        
        handleSeatHover(element, isHovering) {
            const seatRect = element.querySelector('.seat');
            if (seatRect.classList.contains('selected')) return;
            
            if (isHovering) {
                seatRect.style.setProperty('opacity', '0.8');
                seatRect.style.setProperty('stroke', this.colors.hover);
                seatRect.style.setProperty('stroke-width', '2');
                this.showSeatTooltip(element);
            } else {
                seatRect.style.setProperty('opacity', '1');
                seatRect.style.setProperty('stroke', 'none');
                this.hideSeatTooltip();
            }
        }
        
        showSeatTooltip(element) {
            const seat = element.seatData;
            const tooltip = document.createElement('div');
            tooltip.setAttribute('class', 'hope-seat-tooltip');
            tooltip.style.setProperty('position', 'fixed');
            tooltip.style.setProperty('background', 'rgba(0,0,0,0.9)');
            tooltip.style.setProperty('color', 'white');
            tooltip.style.setProperty('padding', '8px 12px');
            tooltip.style.setProperty('border-radius', '4px');
            tooltip.style.setProperty('font-size', '12px');
            tooltip.style.setProperty('z-index', '1000');
            tooltip.style.setProperty('pointer-events', 'none');
            
            const lines = [
                `Section: ${seat.section}`,
                `Row: ${seat.row_number || seat.row}`,
                `Seat: ${seat.seat_number || seat.number}`,
                `Price: ${this.getPriceForTier(seat.pricing_tier)}`
            ];
            
            lines.forEach((line, index) => {
                const text = document.createTextNode(line);
                tooltip.appendChild(text);
                if (index < lines.length - 1) {
                    tooltip.appendChild(document.createElement('br'));
                }
            });
            
            const rect = element.getBoundingClientRect();
            tooltip.style.setProperty('left', rect.left + 'px');
            tooltip.style.setProperty('top', (rect.top - 80) + 'px');
            
            document.body.appendChild(tooltip);
            this.currentTooltip = tooltip;
        }
        
        hideSeatTooltip() {
            if (this.currentTooltip) {
                this.currentTooltip.remove();
                this.currentTooltip = null;
            }
        }
        
        getSeatOriginalColor(element) {
            const seat = element.seatData;
            const pricingTier = seat.pricing_tier || 'P3';
            return this.colors[pricingTier] || this.colors.P3;
        }
        
        getPriceForTier(tier) {
            const prices = {
                'P1': '$50',
                'P2': '$35',
                'P3': '$25',
                'AA': '$25'
            };
            return prices[tier] || '$25';
        }
        
        updateSelectedDisplay() {
            const countElement = document.getElementById('selected-count');
            const listElement = document.getElementById('selected-list');
            
            if (countElement) {
                countElement.textContent = this.selectedSeats.size.toString();
            }
            
            if (listElement) {
                while (listElement.firstChild) {
                    listElement.removeChild(listElement.firstChild);
                }
                
                const seatList = Array.from(this.selectedSeats).map(seatId => {
                    const element = this.seatElements.get(seatId);
                    const seat = element?.seatData;
                    return seat ? `${seat.section}${seat.row_number || seat.row}-${seat.seat_number || seat.number}` : seatId;
                });
                
                const textNode = document.createTextNode(seatList.join(', '));
                listElement.appendChild(textNode);
            }
        }
    }
    
    // Alternative simple class for basic functionality
    class HOPESeatingChart {
        constructor(container, options = {}) {
            this.container = container;
            this.options = options;
            
            // Use HOPETheaterSeatingChart if available
            return new HOPETheaterSeatingChart(container, options);
        }
    }
    
    // Make classes globally available
    window.HOPETheaterSeatingChart = HOPETheaterSeatingChart;
    window.HOPESeatingChart = HOPESeatingChart;
    
    // Auto-initialize on DOM ready
    $(document).ready(function() {
        // Initialize any seating charts with data attributes
        $('[data-venue-id]').each(function() {
            const $container = $(this);
            if ($container.hasClass('hope-seating-initialized')) return;
            
            const venueId = $container.data('venue-id');
            const eventId = $container.data('event-id');
            const $chartDiv = $container.find('.hope-seating-chart');
            
            if ($chartDiv.length && venueId) {
                new HOPESeatingChart($chartDiv[0], {
                    venueId: venueId,
                    eventId: eventId,
                    maxSelections: $container.data('max-seats') || 8,
                    showLegend: $container.data('show-legend') !== false
                });
                
                $container.addClass('hope-seating-initialized');
            }
        });
    });
    
})(jQuery);