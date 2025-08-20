/**
 * HOPE Theater Seat Map
 * Interactive seat selection with real-time availability
 */

class HOPESeatMap {
    constructor() {
        this.selectedSeats = new Set();
        this.heldSeats = new Set();
        this.currentFloor = 'orchestra';
        this.currentScale = 1.5; // Start at 150% zoom
        this.lastUnavailableSeats = new Set(); // Track previous unavailable seats for change detection
        this.isDragging = false;
        this.startX = 0;
        this.startY = 0;
        this.translateX = 0;
        this.translateY = 0;
        this.centerX = 600;
        this.centerY = 500;
        this.sessionId = hope_ajax.session_id;
        this.productId = hope_ajax.product_id;
        this.holdTimer = null;
        this.countdownInterval = null;
        this.availabilityRefreshInterval = null;
        this.variationPricing = null; // Will hold actual WooCommerce pricing
        this.isRestoringSeats = false; // Flag to prevent availability refresh from interfering
        
        this.theaterData = this.getTheaterConfiguration();
        this.pricing = this.getPricingTiers();
        
        this.init();
    }
    
    init() {
        // Load actual pricing from WooCommerce variations first
        this.loadVariationPricing().then(() => {
            // Wait for modal to be visible
            const checkModal = setInterval(() => {
                const modal = document.getElementById('hope-seat-modal');
                if (modal && modal.style.display !== 'none') {
                    clearInterval(checkModal);
                    this.initializeMap();
                }
            }, 100);
        });
    }
    
    /**
     * Load actual pricing from WooCommerce variations
     */
    loadVariationPricing() {
        return fetch(hope_ajax.ajax_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'hope_get_variation_pricing',
                nonce: hope_ajax.nonce,
                product_id: this.productId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data.pricing) {
                this.variationPricing = data.data.pricing;
                console.log('HOPE: Loaded variation pricing:', this.variationPricing);
                // Update the pricing object with real prices
                this.updatePricingWithVariations();
            }
        })
        .catch(error => {
            console.error('HOPE: Error loading variation pricing:', error);
            // Fallback to hardcoded prices if AJAX fails
        });
    }
    
    /**
     * Update pricing object with actual variation prices
     */
    updatePricingWithVariations() {
        if (this.variationPricing) {
            for (const [tier, data] of Object.entries(this.variationPricing)) {
                if (this.pricing[tier]) {
                    this.pricing[tier].price = parseFloat(data.price);
                }
            }
        }
    }
    
    initializeMap() {
        // Generate initial theater
        this.generateTheater(this.currentFloor);
        
        // Set initial zoom
        document.querySelector('.zoom-label').textContent = '150%';
        this.updateTransform();
        
        // Setup event handlers
        this.setupEventHandlers();
        
        // Load existing seat availability
        this.loadSeatAvailability();
        
        // Start periodic availability refresh (every 15 seconds)
        this.startAvailabilityRefresh();
        
        // Start hold timer if seats are already selected
        if (this.selectedSeats.size > 0) {
            this.startHoldTimer();
        }
    }
    
    getTheaterConfiguration() {
        return {
            orchestra: {
                A: {
                    startAngle: -75,
                    endAngle: -54,
                    reverseNumbering: false,
                    rows: [
                        { radius: 180, seats: 5, tier: 'p1' },
                        { radius: 215, seats: 5, tier: 'p1' },
                        { radius: 250, seats: 6, tier: 'p2' },
                        { radius: 285, seats: 6, tier: 'p2' },
                        { radius: 320, seats: 7, tier: 'p2' },
                        { radius: 355, seats: 7, tier: 'p2' },
                        { radius: 390, seats: 8, tier: 'p2' },
                        { radius: 425, seats: 8, tier: 'p3' },
                        { radius: 460, seats: 4, tier: 'p3' },
                        { radius: 495, seats: 2, tier: 'aa' }
                    ]
                },
                B: {
                    startAngle: -54,
                    endAngle: -30,
                    reverseNumbering: true,
                    rows: [
                        { radius: 180, seats: 2, tier: 'p1' },
                        { radius: 215, seats: 3, tier: 'p1' },
                        { radius: 250, seats: 4, tier: 'p1' },
                        { radius: 285, seats: 6, tier: 'p2' },
                        { radius: 320, seats: 7, tier: 'p2' },
                        { radius: 355, seats: 8, tier: 'p2' },
                        { radius: 390, seats: 8, tier: 'p2' },
                        { radius: 425, seats: 8, tier: 'p2' },
                        { radius: 460, seats: 9, tier: 'p2' }
                    ]
                },
                C: {
                    startAngle: -30,
                    endAngle: 30,
                    reverseNumbering: true,
                    rows: [
                        { radius: 180, seats: 10, tier: 'p1' },
                        { radius: 215, seats: 11, tier: 'p1' },
                        { radius: 250, seats: 12, tier: 'p1' },
                        { radius: 285, seats: 13, tier: 'p2' },
                        { radius: 320, seats: 14, tier: 'p2' },
                        { radius: 355, seats: 15, tier: 'p2' },
                        { radius: 390, seats: 16, tier: 'p2' },
                        { radius: 425, seats: 16, tier: 'p2' },
                        { radius: 460, seats: 16, tier: 'p2' },
                        { radius: 495, seats: 10, tier: 'p3' }
                    ]
                },
                D: {
                    startAngle: 30,
                    endAngle: 54,
                    reverseNumbering: false,
                    rows: [
                        { radius: 180, seats: 2, tier: 'p1' },
                        { radius: 215, seats: 3, tier: 'p1' },
                        { radius: 250, seats: 4, tier: 'p1' },
                        { radius: 285, seats: 6, tier: 'p2' },
                        { radius: 320, seats: 7, tier: 'p2' },
                        { radius: 355, seats: 8, tier: 'p2' },
                        { radius: 390, seats: 8, tier: 'p2' },
                        { radius: 425, seats: 8, tier: 'p2' },
                        { radius: 460, seats: 9, tier: 'p2' },
                        { radius: 530, seats: 5, tier: 'aa' }
                    ]
                },
                E: {
                    startAngle: 54,
                    endAngle: 75,
                    reverseNumbering: false,
                    rows: [
                        { radius: 180, seats: 5, tier: 'p1' },
                        { radius: 215, seats: 5, tier: 'p1' },
                        { radius: 250, seats: 6, tier: 'p2' },
                        { radius: 285, seats: 6, tier: 'p2' },
                        { radius: 320, seats: 7, tier: 'p2' },
                        { radius: 355, seats: 7, tier: 'p2' },
                        { radius: 390, seats: 8, tier: 'p2' },
                        { radius: 425, seats: 8, tier: 'p3' },
                        { radius: 460, seats: 4, tier: 'p3' },
                        { radius: 495, seats: 2, tier: 'aa' }
                    ]
                }
            },
            balcony: {
                F: {
                    startAngle: -65,
                    endAngle: -35,
                    reverseNumbering: false,
                    rows: [
                        { radius: 340, seats: 14, tier: 'p1' },
                        { radius: 375, seats: 16, tier: 'p2' },
                        { radius: 410, seats: 14, tier: 'p2' },
                        { radius: 445, seats: 6, tier: 'p3' }
                    ]
                },
                G: {
                    rows: [
                        {
                            radius: 340,
                            seats: 24,
                            tier: 'p1',
                            startAngle: -35,
                            endAngle: 35,
                            reverseNumbering: true
                        },
                        {
                            radius: 375,
                            seats: 16,
                            tier: 'p2',
                            startAngle: -35,
                            endAngle: 10,
                            reverseNumbering: true
                        },
                        {
                            radius: 410,
                            seats: 14,
                            tier: 'p2',
                            startAngle: -35,
                            endAngle: 5,
                            reverseNumbering: true
                        },
                        {
                            radius: 445,
                            seats: 6,
                            tier: 'p3',
                            startAngle: -35,
                            endAngle: -10,
                            reverseNumbering: true
                        }
                    ]
                },
                H: {
                    startAngle: 35,
                    endAngle: 65,
                    reverseNumbering: false,
                    rows: [
                        { radius: 340, seats: 14, tier: 'p1' },
                        { radius: 375, seats: 16, tier: 'p2' },
                        { radius: 410, seats: 14, tier: 'p2' },
                        { radius: 445, seats: 6, tier: 'p3' }
                    ]
                }
            }
        };
    }
    
    getPricingTiers() {
        // These are fallback prices - real prices will be loaded from WooCommerce variations
        return {
            p1: { price: 50, name: 'Premium', color: '#9b59b6' },
            p2: { price: 35, name: 'Standard', color: '#3498db' },
            p3: { price: 25, name: 'Value', color: '#17a2b8' },
            aa: { price: 25, name: 'Accessible', color: '#e67e22' }
        };
    }
    
    generateTheater(floor) {
        const svg = document.getElementById('seat-map');
        if (!svg) return;
        
        svg.innerHTML = '';
        
        this.createStage(svg);
        
        const floorData = this.theaterData[floor];
        Object.entries(floorData).forEach(([sectionName, sectionData]) => {
            this.createSection(svg, sectionName, sectionData);
        });
    }
    
    createStage(svg) {
        const stageText = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        stageText.setAttribute('x', '600');
        stageText.setAttribute('y', '400');
        stageText.setAttribute('text-anchor', 'middle');
        stageText.setAttribute('class', 'stage-text');
        stageText.textContent = 'STAGE';
        svg.appendChild(stageText);
    }
    
    createSection(svg, sectionName, sectionData) {
        const sectionGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
        sectionGroup.setAttribute('id', `section-${sectionName}`);
        
        if (sectionName === 'G' && sectionData.rows) {
            // Special handling for asymmetrical Section G
            sectionData.rows.forEach((rowData, rowIndex) => {
                const angleRange = rowData.endAngle - rowData.startAngle;
                const angleStep = angleRange / (rowData.seats + 1);
                
                for (let seatIndex = 0; seatIndex < rowData.seats; seatIndex++) {
                    const actualSeatNumber = rowData.reverseNumbering
                        ? rowData.seats - seatIndex
                        : seatIndex + 1;
                    
                    const angle = rowData.startAngle + angleStep * (seatIndex + 1);
                    const angleRad = (angle * Math.PI) / 180;
                    
                    const x = this.centerX + rowData.radius * Math.sin(angleRad);
                    const y = this.centerY - rowData.radius * Math.cos(angleRad);
                    
                    this.createSeat(sectionGroup, x, y, sectionName, rowIndex + 1, actualSeatNumber, rowData.tier);
                }
            });
        } else {
            // Normal section handling
            sectionData.rows.forEach((rowData, rowIndex) => {
                const angleRange = sectionData.endAngle - sectionData.startAngle;
                const angleStep = angleRange / (rowData.seats + 1);
                
                for (let seatIndex = 0; seatIndex < rowData.seats; seatIndex++) {
                    const actualSeatNumber = sectionData.reverseNumbering
                        ? rowData.seats - seatIndex
                        : seatIndex + 1;
                    
                    const angle = sectionData.startAngle + angleStep * (seatIndex + 1);
                    const angleRad = (angle * Math.PI) / 180;
                    
                    const x = this.centerX + rowData.radius * Math.sin(angleRad);
                    const y = this.centerY - rowData.radius * Math.cos(angleRad);
                    
                    this.createSeat(sectionGroup, x, y, sectionName, rowIndex + 1, actualSeatNumber, rowData.tier);
                }
            });
        }
        
        svg.appendChild(sectionGroup);
    }
    
    createSeat(parentGroup, x, y, sectionName, row, seatNumber, tier) {
        const seat = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        const seatId = `${sectionName}${row}-${seatNumber}`;
        
        seat.setAttribute('x', x - 11);
        seat.setAttribute('y', y - 11);
        seat.setAttribute('width', '22');
        seat.setAttribute('height', '22');
        seat.setAttribute('rx', '4');
        seat.setAttribute('class', `seat ${tier}`);
        seat.setAttribute('data-section', sectionName);
        seat.setAttribute('data-row', row);
        seat.setAttribute('data-seat', seatNumber);
        seat.setAttribute('data-id', seatId);
        seat.setAttribute('data-tier', tier);
        seat.setAttribute('fill', this.pricing[tier].color);
        seat.setAttribute('stroke', 'white');
        seat.setAttribute('stroke-width', '1.5');
        seat.setAttribute('stroke-dasharray', '88');
        seat.setAttribute('stroke-dashoffset', '0');
        
        // Event handlers
        seat.addEventListener('click', (e) => this.handleSeatClick(e));
        seat.addEventListener('mouseenter', (e) => this.handleSeatHover(e));
        seat.addEventListener('mouseleave', (e) => this.handleSeatLeave(e));
        
        parentGroup.appendChild(seat);
    }
    
    handleSeatClick(e) {
        e.stopPropagation();
        const seat = e.currentTarget;
        const seatId = seat.getAttribute('data-id');
        
        // Allow deselection of currently selected seats, even if they appear unavailable
        if (this.selectedSeats.has(seatId)) {
            console.log(`Deselecting seat ${seatId} (was selected by current session)`);
            this.deselectSeat(seatId);
            return;
        }
        
        // If seat is unavailable and not selected by current session, check status
        if (seat.classList.contains('unavailable')) {
            console.log(`Seat ${seatId} is unavailable and not selected by current session`);
            this.checkSeatStatus(seatId);
            return;
        }
        
        // Select new seat
        if (this.selectedSeats.size >= 10) {
            this.showMessage(hope_ajax.messages.max_seats, 'warning');
            return;
        }
        
        console.log(`Selecting new seat ${seatId}`);
        this.selectSeat(seatId);
    }
    
    selectSeat(seatId) {
        this.selectedSeats.add(seatId);
        const seat = document.querySelector(`[data-id="${seatId}"]`);
        if (seat) {
            seat.classList.add('selected');
            seat.setAttribute('fill', '#28a745');
        }
        this.updateSelectedDisplay();
        this.holdSeats();
    }
    
    deselectSeat(seatId) {
        this.selectedSeats.delete(seatId);
        const seat = document.querySelector(`[data-id="${seatId}"]`);
        if (seat) {
            seat.classList.remove('selected');
            const tier = seat.getAttribute('data-tier');
            seat.setAttribute('fill', this.pricing[tier].color);
        }
        this.updateSelectedDisplay();
        
        if (this.selectedSeats.size === 0) {
            this.releaseAllSeats();
        } else {
            this.holdSeats();
        }
    }
    
    handleSeatHover(e) {
        const seat = e.currentTarget;
        
        // Move to top layer
        seat._originalParent = seat.parentNode;
        seat._originalNextSibling = seat.nextSibling;
        const svg = document.getElementById('seat-map');
        svg.appendChild(seat);
        
        // Show tooltip
        this.showTooltip(seat);
    }
    
    handleSeatLeave(e) {
        const seat = e.currentTarget;
        
        // Restore original position
        if (seat._originalParent && !seat.classList.contains('selected')) {
            if (seat._originalNextSibling) {
                seat._originalParent.insertBefore(seat, seat._originalNextSibling);
            } else {
                seat._originalParent.appendChild(seat);
            }
            seat._originalParent = null;
            seat._originalNextSibling = null;
        }
        
        this.hideTooltip();
    }
    
    showTooltip(seat) {
        const section = seat.getAttribute('data-section');
        const row = seat.getAttribute('data-row');
        const seatNum = seat.getAttribute('data-seat');
        const tier = seat.getAttribute('data-tier');
        const isUnavailable = seat.classList.contains('unavailable');
        
        const tooltip = document.getElementById('tooltip');
        if (!tooltip) return;
        
        let content = `Section ${section}, Row ${row}, Seat ${seatNum}<br>`;
        
        if (isUnavailable) {
            content += '<strong style="color: #fbbf24;">Unavailable</strong>';
        } else if (tier && this.pricing[tier]) {
            // Use the updated pricing (which includes real variation prices)
            content += `<strong>${this.pricing[tier].name} - $${this.pricing[tier].price}</strong>`;
        }
        
        tooltip.innerHTML = content;
        
        const rect = seat.getBoundingClientRect();
        tooltip.style.left = rect.left + rect.width / 2 + 'px';
        tooltip.style.top = rect.top - 50 + 'px'; // Moved farther up to avoid overlap
        tooltip.classList.add('show');
    }
    
    hideTooltip() {
        const tooltip = document.getElementById('tooltip');
        if (tooltip) {
            tooltip.classList.remove('show');
        }
    }
    
    updateSelectedDisplay() {
        const listEl = document.getElementById('selected-seats-list');
        const totalEl = document.querySelector('.total-price');
        const modalCount = document.querySelector('.seat-count-display');
        const modalTotal = document.querySelector('.total-price-display');
        const confirmSeatsBtn = document.querySelector('.hope-confirm-seats-btn');
        
        if (this.selectedSeats.size === 0) {
            if (listEl) listEl.innerHTML = '<span class="empty-message">No seats selected</span>';
            if (totalEl) totalEl.textContent = 'Total: $0';
            if (modalCount) modalCount.textContent = 'No seats selected';
            if (modalTotal) modalTotal.textContent = 'Total: $0';
            if (confirmSeatsBtn) {
                confirmSeatsBtn.disabled = true;
                confirmSeatsBtn.querySelector('.seat-count-badge').style.display = 'none';
            }
            return;
        }
        
        if (listEl) listEl.innerHTML = '';
        let total = 0;
        
        this.selectedSeats.forEach(seatId => {
            const seat = document.querySelector(`[data-id="${seatId}"]`);
            if (!seat) return;
            
            const tier = seat.getAttribute('data-tier');
            // Use the updated pricing (which includes real variation prices)
            const price = this.pricing[tier].price;
            total += price;
            
            if (listEl) {
                const tag = document.createElement('div');
                tag.className = 'seat-tag';
                
                // Add tier-based color coding
                const tierColor = this.pricing[tier] ? this.pricing[tier].color : '#7c3aed';
                tag.style.backgroundColor = tierColor;
                tag.setAttribute('data-tier', tier);
                
                tag.innerHTML = `
                    ${seatId}
                    <span class="remove" data-seat="${seatId}">×</span>
                `;
                tag.querySelector('.remove').addEventListener('click', () => {
                    this.deselectSeat(seatId);
                });
                listEl.appendChild(tag);
            }
        });
        
        if (totalEl) totalEl.textContent = `Total: $${total}`;
        if (modalCount) modalCount.textContent = `${this.selectedSeats.size} seat${this.selectedSeats.size > 1 ? 's' : ''} selected`;
        if (modalTotal) modalTotal.textContent = `Total: $${total}`;
        
        if (confirmSeatsBtn) {
            confirmSeatsBtn.disabled = false;
            const badge = confirmSeatsBtn.querySelector('.seat-count-badge');
            if (badge) {
                badge.textContent = this.selectedSeats.size;
                badge.style.display = 'inline-block';
            }
        }
    }
    
    setupEventHandlers() {
        // Floor switching
        document.querySelectorAll('.floor-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.floor-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                this.currentFloor = btn.dataset.floor;
                this.generateTheater(this.currentFloor);
                this.loadSeatAvailability();
            });
        });
        
        // Zoom controls
        const zoomIn = document.getElementById('zoom-in');
        const zoomOut = document.getElementById('zoom-out');
        
        if (zoomIn) zoomIn.addEventListener('click', () => this.handleZoomIn());
        if (zoomOut) zoomOut.addEventListener('click', () => this.handleZoomOut());
        
        // Pan controls
        const wrapper = document.getElementById('seating-wrapper');
        if (wrapper) {
            wrapper.addEventListener('mousedown', (e) => this.handleMouseDown(e));
            document.addEventListener('mousemove', (e) => this.handleMouseMove(e));
            document.addEventListener('mouseup', () => this.handleMouseUp());
            wrapper.addEventListener('wheel', (e) => this.handleWheel(e));
        }
    }
    
    handleZoomIn() {
        this.currentScale = Math.min(this.currentScale * 1.2, 3);
        document.querySelector('.zoom-label').textContent = Math.round(this.currentScale * 100) + '%';
        this.updateTransform();
    }
    
    handleZoomOut() {
        this.currentScale = Math.max(this.currentScale / 1.2, 0.5);
        document.querySelector('.zoom-label').textContent = Math.round(this.currentScale * 100) + '%';
        this.updateTransform();
    }
    
    handleMouseDown(e) {
        this.isDragging = true;
        this.startX = e.clientX - this.translateX;
        this.startY = e.clientY - this.translateY;
        document.getElementById('seating-wrapper').classList.add('dragging');
    }
    
    handleMouseMove(e) {
        if (!this.isDragging) return;
        e.preventDefault();
        this.translateX = e.clientX - this.startX;
        this.translateY = e.clientY - this.startY;
        this.updateTransform();
    }
    
    handleMouseUp() {
        this.isDragging = false;
        const wrapper = document.getElementById('seating-wrapper');
        if (wrapper) wrapper.classList.remove('dragging');
    }
    
    handleWheel(e) {
        if (!e.shiftKey) return;
        e.preventDefault();
        
        if (e.deltaY < 0) {
            this.handleZoomIn();
        } else {
            this.handleZoomOut();
        }
    }
    
    updateTransform() {
        const wrapper = document.getElementById('seating-wrapper');
        if (wrapper) {
            wrapper.style.transform = `translate(${this.translateX}px, ${this.translateY}px) scale(${this.currentScale})`;
        }
    }
    
    // AJAX Methods
    loadSeatAvailability() {
        fetch(hope_ajax.ajax_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'hope_check_availability',
                nonce: hope_ajax.nonce,
                product_id: this.productId,
                venue_id: hope_ajax.venue_id,
                seats: JSON.stringify([]),
                session_id: this.sessionId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data.unavailable_seats) {
                // Don't interfere with seat restoration
                if (this.isRestoringSeats) {
                    console.log('HOPE: Skipping availability update during seat restoration');
                    return;
                }
                
                // First, clear all existing unavailable markings
                document.querySelectorAll('.seat.unavailable').forEach(seat => {
                    seat.classList.remove('unavailable');
                    // Only restore original color if seat is not selected
                    if (!seat.classList.contains('selected')) {
                        const tier = seat.getAttribute('data-tier');
                        if (tier && this.pricing[tier]) {
                            seat.setAttribute('fill', this.pricing[tier].color);
                        }
                    }
                });
                
                // Check if unavailable seats have changed
                const currentUnavailable = new Set(data.data.unavailable_seats);
                const hasChanges = this.lastUnavailableSeats.size !== currentUnavailable.size ||
                    [...this.lastUnavailableSeats].some(seat => !currentUnavailable.has(seat)) ||
                    [...currentUnavailable].some(seat => !this.lastUnavailableSeats.has(seat));
                
                // Then mark the currently unavailable seats
                data.data.unavailable_seats.forEach(seatId => {
                    const seat = document.querySelector(`[data-id="${seatId}"]`);
                    if (seat && !this.selectedSeats.has(seatId)) {
                        seat.classList.add('unavailable');
                        seat.setAttribute('fill', '#6c757d');
                    }
                });
                
                // Only log if there are changes
                if (hasChanges) {
                    console.log(`HOPE: Seat availability changed - ${data.data.unavailable_seats.length} seats now unavailable`);
                    this.lastUnavailableSeats = currentUnavailable;
                }
            }
        })
        .catch(error => {
            console.error('HOPE: Error loading seat availability:', error);
        });
    }
    
    holdSeats() {
        if (this.selectedSeats.size === 0) return;
        
        const seats = Array.from(this.selectedSeats);
        
        fetch(hope_ajax.ajax_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'hope_hold_seats',
                nonce: hope_ajax.nonce,
                product_id: this.productId,
                seats: JSON.stringify(seats),
                session_id: this.sessionId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                this.heldSeats = new Set(data.data.held_seats);
                this.startHoldTimer();
            } else {
                this.showMessage(data.data.message, 'error');
            }
        })
        .catch(() => {
            this.showMessage(hope_ajax.messages.connection_error, 'error');
        });
    }
    
    releaseAllSeats() {
        fetch(hope_ajax.ajax_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'hope_release_seats',
                nonce: hope_ajax.nonce,
                product_id: this.productId,
                session_id: this.sessionId,
                seats: JSON.stringify([])
            })
        });
        
        this.stopHoldTimer();
    }
    
    startHoldTimer() {
        this.stopHoldTimer();
        
        const timerEl = document.querySelector('.hope-session-timer');
        const countdownEl = document.querySelector('.timer-countdown');
        
        if (timerEl) timerEl.style.display = 'flex';
        
        let remaining = hope_ajax.hold_duration;
        
        this.countdownInterval = setInterval(() => {
            remaining--;
            
            if (remaining <= 0) {
                this.stopHoldTimer();
                this.showMessage(hope_ajax.messages.session_expired, 'warning');
                this.selectedSeats.clear();
                this.updateSelectedDisplay();
                return;
            }
            
            const minutes = Math.floor(remaining / 60);
            const seconds = remaining % 60;
            
            if (countdownEl) {
                countdownEl.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
            }
        }, 1000);
    }
    
    stopHoldTimer() {
        if (this.countdownInterval) {
            clearInterval(this.countdownInterval);
            this.countdownInterval = null;
        }
        
        const timerEl = document.querySelector('.hope-session-timer');
        if (timerEl) timerEl.style.display = 'none';
    }
    
    showMessage(message, type = 'info') {
        // Simple message display - can be enhanced with a toast library
        console.log(`${type}: ${message}`);
        
        // Create temporary message element
        const msgEl = document.createElement('div');
        msgEl.className = `hope-message hope-message-${type}`;
        msgEl.textContent = message;
        msgEl.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            background: ${type === 'error' ? '#dc3545' : type === 'warning' ? '#ffc107' : '#28a745'};
            color: white;
            border-radius: 5px;
            z-index: 100000;
            animation: slideIn 0.3s ease;
        `;
        
        document.body.appendChild(msgEl);
        
        setTimeout(() => {
            msgEl.remove();
        }, 3000);
    }
    
    startAvailabilityRefresh() {
        // Stop any existing refresh to prevent duplicates
        this.stopAvailabilityRefresh();
        
        // Refresh availability every 10 seconds (more frequent for better UX)
        this.availabilityRefreshInterval = setInterval(() => {
            this.loadSeatAvailability();
        }, 10000);
    }
    
    stopAvailabilityRefresh() {
        if (this.availabilityRefreshInterval) {
            clearInterval(this.availabilityRefreshInterval);
            this.availabilityRefreshInterval = null;
        }
    }
    
    checkSeatStatus(seatId) {
        // Check the specific status of a seat to provide better feedback
        fetch(hope_ajax.ajax_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'hope_check_availability',
                nonce: hope_ajax.nonce,
                product_id: this.productId,
                venue_id: hope_ajax.venue_id,
                seats: JSON.stringify([seatId]),
                session_id: this.sessionId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.data.unavailable_seats && data.data.unavailable_seats.includes(seatId)) {
                    this.showMessage(`Seat ${seatId} is currently held by another user or booked. Please select a different seat.`, 'warning');
                } else {
                    // Seat might have become available - refresh the display
                    this.loadSeatAvailability();
                    this.showMessage(`Seat ${seatId} status updated. Please try again.`, 'info');
                }
            }
        })
        .catch(error => {
            this.showMessage('Unable to check seat status. Please try again.', 'error');
        });
    }
    
    // Method to select multiple seats (used by modal restoration)
    selectSeats(seatIds) {
        if (!Array.isArray(seatIds)) return;
        
        // Set flag to prevent availability refresh from interfering
        this.isRestoringSeats = true;
        
        seatIds.forEach(seatId => {
            if (!this.selectedSeats.has(seatId)) {
                this.selectSeat(seatId);
            }
        });
        
        // Clear flag after a short delay to allow restoration to complete
        setTimeout(() => {
            this.isRestoringSeats = false;
        }, 1000);
    }
}

// Initialize when document is ready
document.addEventListener('DOMContentLoaded', () => {
    // Only initialize if we have the required data and are in the right context
    if (typeof hope_ajax !== 'undefined' && 
        hope_ajax.product_id && 
        hope_ajax.product_id !== '0' && 
        hope_ajax.venue_id && 
        hope_ajax.venue_id !== '0') {
        window.hopeSeatMap = new HOPESeatMap();
    } else {
        console.log('HOPE: Seat map not initialized - missing required product/venue data or not on product page');
    }
});