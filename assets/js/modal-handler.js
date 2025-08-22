/**
 * HOPE Theater Modal Handler
 * Manages modal opening, closing, and cart integration
 */

class HOPEModalHandler {
    constructor() {
        this.modal = null;
        this.isOpen = false;
        this.seatMap = null;
        this.lastClickTime = 0;
        this.lastFooterHeight = null; // Track previous footer height for change detection
        
        this.init();
    }
    
    init() {
        console.log('HOPEModalHandler initializing...');
        // Wait for DOM to be ready
        if (document.readyState === 'loading') {
            console.log('DOM still loading, waiting for DOMContentLoaded...');
            document.addEventListener('DOMContentLoaded', () => this.setupModal());
        } else {
            console.log('DOM ready, setting up modal immediately...');
            this.setupModal();
        }
    }
    
    setupModal() {
        console.log('setupModal called');
        this.modal = document.getElementById('hope-seat-modal');
        
        if (!this.modal) {
            console.error('Modal element #hope-seat-modal not found in DOM');
            return;
        }
        
        console.log('Modal element found, setting up event listeners...');
        
        // Setup event listeners
        this.setupEventListeners();
        
        // Setup legend toggle will be handled in setupEventListeners
        
        // Initialize seat map when modal opens
        this.modal.addEventListener('modal-opened', () => {
            if (!this.seatMap && window.hopeSeatMap) {
                this.seatMap = window.hopeSeatMap;
            }
        });
        
        console.log('Modal setup complete');
    }
    
    setupEventListeners() {
        // Use event delegation for dynamically loaded buttons - support multiple button IDs
        // Also check parent elements in case the click is on a child span
        document.addEventListener('click', (e) => {
            let target = e.target;
            
            // Check if the clicked element or its parent is a seat selection button
            while (target && target !== document) {
                if (target.matches && (
                    target.matches('#hope-select-seats, #hope-select-seats-main, .hope-select-seats-btn, .hope-change-seats-btn') ||
                    target.closest('#hope-select-seats, #hope-select-seats-main, .hope-select-seats-btn, .hope-change-seats-btn')
                )) {
                    e.preventDefault();
                    e.stopPropagation();
                    console.log('Seat selection button clicked (target:', target.tagName, target.className, '), opening modal...');
                    
                    // Prevent multiple rapid clicks
                    const now = Date.now();
                    if (this.isOpen || (now - this.lastClickTime < 500)) {
                        console.log('Modal already open or too soon after last click, ignoring');
                        return;
                    }
                    
                    this.lastClickTime = now;
                    this.openModal();
                    return;
                }
                target = target.parentElement;
            }
        }, { capture: true }); // Use capture phase to catch events early
        
        // X close button removed for cleaner UI
        
        // Cancel button
        const cancelBtn = this.modal.querySelector('.hope-cancel-btn');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', () => this.closeModal());
        }
        
        // Confirm seats button (was add to cart)
        const confirmSeatsBtn = this.modal.querySelector('.hope-confirm-seats-btn');
        if (confirmSeatsBtn) {
            confirmSeatsBtn.addEventListener('click', () => this.confirmSeats());
        }
        
        // Legend toggle button - use event delegation for reliability
        document.addEventListener('click', (e) => {
            if (e.target && (e.target.id === 'legend-toggle' || e.target.closest('#legend-toggle'))) {
                e.preventDefault();
                e.stopPropagation();
                console.log('Legend toggle clicked via event delegation');
                this.toggleLegend();
            }
        });
        
        // Seats toggle button
        const seatsToggle = this.modal.querySelector('#seats-toggle');
        const seatsPanel = this.modal.querySelector('#selected-seats-panel');
        if (seatsToggle && seatsPanel) {
            seatsToggle.addEventListener('click', () => this.toggleSeatsPanel());
        }
        
        // Overlay click
        const overlay = this.modal.querySelector('.hope-modal-overlay');
        if (overlay) {
            overlay.addEventListener('click', () => this.closeModal());
        }
        
        // Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.isOpen) {
                this.closeModal();
            }
        });
        
        // Mobile specific handlers
        if (hope_ajax.is_mobile) {
            this.setupMobileHandlers();
        }
        
        // Add resize listener to recalculate footer height
        window.addEventListener('resize', () => {
            if (this.isOpen) {
                setTimeout(() => this.updateFooterHeight(), 100);
            }
        });
        
        // Watch for footer content changes (like timer appearing)
        this.setupFooterObserver();
    }
    
    setupMobileHandlers() {
        const mobileBack = document.querySelector('.hope-mobile-back');
        const mobileDone = document.querySelector('.hope-mobile-done');
        
        if (mobileBack) {
            mobileBack.addEventListener('click', () => this.closeModal());
        }
        
        if (mobileDone) {
            mobileDone.addEventListener('click', () => {
                if (this.seatMap && this.seatMap.selectedSeats.size > 0) {
                    this.confirmSeats();
                } else {
                    this.closeModal();
                }
            });
        }
        
        // Prevent body scroll when modal is open on mobile
        this.modal.addEventListener('touchmove', (e) => {
            if (e.target === this.modal || e.target.classList.contains('hope-modal-overlay')) {
                e.preventDefault();
            }
        }, { passive: false });
    }
    
    openModal() {
        console.log('openModal called, isOpen:', this.isOpen, 'modal exists:', !!this.modal);
        
        if (!this.modal) {
            console.error('Modal element not found!');
            return;
        }
        
        if (this.isOpen) {
            console.log('Modal is already open, ignoring');
            return;
        }
        
        console.log('Opening modal...');
        
        // Show modal
        this.modal.style.display = 'block';
        this.modal.setAttribute('aria-hidden', 'false');
        this.isOpen = true;
        
        // Reset footer height tracker for fresh calculation
        this.lastFooterHeight = null;
        
        // Add body class to prevent scrolling
        document.body.classList.add('hope-modal-open');
        
        // Hide loading, show content
        setTimeout(() => {
            const loader = this.modal.querySelector('.hope-loading-indicator');
            const content = this.modal.querySelector('#hope-seat-map-container');
            
            if (loader) loader.style.display = 'none';
            if (content) {
                content.style.display = 'flex';
                content.style.flexDirection = 'column';
                content.style.height = '100%';
            }
            
            // Trigger custom event
            const event = new CustomEvent('modal-opened');
            this.modal.dispatchEvent(event);
            
            // Initialize or refresh seat map
            if (window.hopeSeatMap) {
                window.hopeSeatMap.initializeMap();
                // Clear any previous selections and refresh availability
                setTimeout(() => {
                    this.syncWithCartState();
                }, 800);
            } else {
                // Create new instance if needed
                if (typeof HOPESeatMap !== 'undefined' && typeof hope_ajax !== 'undefined') {
                    window.hopeSeatMap = new HOPESeatMap();
                    // Still need a bit more time for new instance
                    setTimeout(() => {
                        this.syncWithCartState();
                    }, 1200);
                }
            }
            
            // Show navigation hint
            this.showNavigationHint();
            
            // Refresh legend toggle event listeners now that content is visible
            this.refreshLegendToggle();
            
            // Calculate and set footer height for panel positioning
            this.updateFooterHeight();
            
            // Calculate and set container height dynamically
            this.updateContainerHeight();
        }, 500);
        
        // Focus management for accessibility
        this.previousFocus = document.activeElement;
        const closeBtn = this.modal.querySelector('.hope-modal-close');
        if (closeBtn) closeBtn.focus();
        
        // Trap focus within modal
        this.trapFocus();
        
        // Add resize listener to recalculate height on window resize
        this.resizeHandler = () => this.updateContainerHeight();
        window.addEventListener('resize', this.resizeHandler);
    }
    
    closeModal(skipConfirmation = false) {
        if (!this.modal || !this.isOpen) return;
        
        // Confirm if seats are selected (unless called from confirmSeats)
        if (!skipConfirmation && this.seatMap && this.seatMap.selectedSeats.size > 0) {
            if (!confirm('You have selected seats. Are you sure you want to close without adding them to cart?')) {
                return;
            }
            
            // Release held seats only if user really wants to close
            this.seatMap.releaseAllSeats();
        }
        
        // Hide modal
        this.modal.style.display = 'none';
        this.modal.setAttribute('aria-hidden', 'true');
        this.isOpen = false;
        
        // Remove body class
        document.body.classList.remove('hope-modal-open');
        
        // Restore focus
        if (this.previousFocus) {
            this.previousFocus.focus();
        }
        
        // Clean up
        if (this.seatMap) {
            this.seatMap.selectedSeats.clear();
            this.seatMap.updateSelectedDisplay();
            this.seatMap.stopHoldTimer();
            this.seatMap.stopAvailabilityRefresh();
        }
        
        // Cleanup footer observer
        this.cleanupFooterObserver();
        
        // Remove resize listener
        if (this.resizeHandler) {
            window.removeEventListener('resize', this.resizeHandler);
            this.resizeHandler = null;
        }
        
        // Emit modal closed event
        const modalClosedEvent = new CustomEvent('hope-modal-closed', {
            detail: { seats: this.seatMap ? Array.from(this.seatMap.selectedSeats) : [] }
        });
        document.dispatchEvent(modalClosedEvent);
    }
    
    confirmSeats() {
        console.log('confirmSeats called, selectedSeats size:', this.seatMap ? this.seatMap.selectedSeats.size : 'no seatMap');
        
        if (!this.seatMap || this.seatMap.selectedSeats.size === 0) {
            // If no seats selected, just close modal (user chose "Continue with No Seats")
            console.log('No seats selected, calling closeModal()');
            this.closeModal(true); // Skip confirmation since no seats are selected
            return;
        }
        
        const seats = Array.from(this.seatMap.selectedSeats);
        
        // Add seats to cart via AJAX to ensure persistence
        fetch(hope_ajax.ajax_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'hope_add_to_cart',
                nonce: hope_ajax.nonce,
                product_id: hope_ajax.product_id,
                seats: JSON.stringify(seats),
                session_id: hope_ajax.session_id
            })
        })
        .then(response => response.json())
        .then(data => {
            console.log('AJAX response from hope_add_to_cart:', data);
            if (data.success) {
                console.log('Seats successfully added to cart:', data);
                
                // Update button on product page
                const productBtn = document.getElementById('hope-select-seats') || 
                                  document.getElementById('hope-select-seats-main');
                if (productBtn) {
                    productBtn.innerHTML = `<span class="btn-text">${seats.length} Seats Selected</span> <span class="btn-icon">✓</span>`;
                    productBtn.classList.add('seats-selected');
                    console.log('Updated product page button');
                } else {
                    console.log('Product page button not found');
                }
                
                // Always manually update the seat display elements to ensure they show
                const prompt = document.querySelector('.hope-seat-selection-prompt');
                const display = document.querySelector('.hope-selected-seats-display');
                
                console.log('Looking for seat display elements - prompt:', !!prompt, 'display:', !!display);
                
                if (prompt && display) {
                    console.log('Found seat display elements, updating them');
                    prompt.style.display = 'none';
                    display.style.display = 'block';
                    
                    const seatsList = display.querySelector('.hope-seats-list');
                    const totalAmount = display.querySelector('.total-amount');
                    
                    console.log('Found seatsList:', !!seatsList, 'totalAmount:', !!totalAmount);
                    
                    if (seatsList) {
                        seatsList.innerHTML = '';
                        seats.forEach(seatId => {
                            const seatTag = document.createElement('span');
                            seatTag.className = 'hope-seat-tag';
                            seatTag.textContent = seatId;
                            seatTag.style.backgroundColor = '#7c3aed'; // Default purple
                            seatTag.style.color = 'white';
                            seatTag.style.padding = '6px 12px';
                            seatTag.style.margin = '0 8px 8px 0';
                            seatTag.style.borderRadius = '16px';
                            seatTag.style.fontSize = '14px';
                            seatTag.style.display = 'inline-block';
                            seatsList.appendChild(seatTag);
                        });
                        console.log('Added seat tags to list');
                    }
                    
                    if (totalAmount) {
                        const calculatedTotal = data.data?.total || (seats.length * 25); // fallback calculation
                        totalAmount.textContent = `$${calculatedTotal}`;
                        console.log('Updated total amount to:', calculatedTotal);
                    }
                    
                    console.log('Successfully updated seat display manually');
                } else {
                    console.error('Could not find seat display elements - prompt:', !!prompt, 'display:', !!display);
                    
                    // Log all elements with class names that might be relevant
                    const allElements = document.querySelectorAll('[class*="hope"], [class*="seat"]');
                    console.log('Found elements with hope/seat in class:', Array.from(allElements).map(el => el.className));
                }
                
                // Always ensure the checkout button is enabled after seat selection
                const checkoutButton = document.querySelector('.single_add_to_cart_button');
                if (checkoutButton) {
                    checkoutButton.disabled = false;
                    checkoutButton.classList.remove('disabled');
                    checkoutButton.style.opacity = '1';
                    checkoutButton.textContent = 'Checkout';
                    console.log('Enabled checkout button');
                } else {
                    console.error('Checkout button not found');
                }
                
                // Trigger seat change event for WooCommerce integration
                const seatChangeEvent = new CustomEvent('hope-seats-updated', {
                    detail: { seats: seats }
                });
                document.dispatchEvent(seatChangeEvent);
                console.log('Dispatched hope-seats-updated event with seats:', seats);
                
                // Force WooCommerce integration update if available
                if (window.hopeWooCommerceInstance) {
                    console.log('Found existing WooCommerce integration instance, updating directly');
                    window.hopeWooCommerceInstance.selectedSeats = new Set(seats);
                    if (seats.length > 0) {
                        const serverTotal = data.data?.total;
                        window.hopeWooCommerceInstance.updateSelectedSeatsDisplay(seats, serverTotal);
                        window.hopeWooCommerceInstance.getVariationForSeats(seats);
                        window.hopeWooCommerceInstance.enableAddToCart();
                    } else {
                        window.hopeWooCommerceInstance.clearSelectedSeatsDisplay();
                    }
                } else {
                    console.log('WooCommerce integration instance not found, dispatching event');
                    // Fallback to event system
                    const integrationEvent = new CustomEvent('hope-force-update', {
                        detail: { seats: seats }
                    });
                    document.dispatchEvent(integrationEvent);
                }
                
                // Trigger cart refresh for slide cart and mini cart
                if (typeof jQuery !== 'undefined' && jQuery(document.body).triggerHandler) {
                    console.log('Triggering WooCommerce cart refresh');
                    jQuery(document.body).trigger('wc_fragment_refresh');
                    jQuery(document.body).trigger('added_to_cart', [data.fragments, data.cart_hash, jQuery('<div>')]);
                }
                
                // Redirect directly to checkout instead of closing modal
                console.log('Seats successfully added to cart, redirecting to checkout');
                window.location.href = data.data?.cart_url || hope_ajax.checkout_url || '/checkout';
            } else {
                console.error('Failed to add seats to cart:', data);
                alert('Failed to add seats to cart: ' + (data.data?.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Error adding seats to cart:', error);
            alert('An error occurred while adding seats to cart. Please try again.');
        });
    }
    
    trapFocus() {
        const focusableElements = this.modal.querySelectorAll(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        );
        
        if (focusableElements.length === 0) return;
        
        const firstFocusable = focusableElements[0];
        const lastFocusable = focusableElements[focusableElements.length - 1];
        
        this.modal.addEventListener('keydown', (e) => {
            if (e.key !== 'Tab') return;
            
            if (e.shiftKey) {
                if (document.activeElement === firstFocusable) {
                    e.preventDefault();
                    lastFocusable.focus();
                }
            } else {
                if (document.activeElement === lastFocusable) {
                    e.preventDefault();
                    firstFocusable.focus();
                }
            }
        });
    }
    
    showSuccessMessage(message) {
        const msgEl = document.createElement('div');
        msgEl.className = 'hope-success-message';
        msgEl.innerHTML = `
            <div class="message-content">
                <span class="icon">✓</span>
                <span class="text">${message}</span>
            </div>
        `;
        msgEl.style.cssText = `
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: #28a745;
            color: white;
            padding: 20px 30px;
            border-radius: 10px;
            z-index: 100001;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            animation: fadeIn 0.3s ease;
        `;
        
        document.body.appendChild(msgEl);
        
        setTimeout(() => {
            msgEl.style.animation = 'fadeOut 0.3s ease';
            setTimeout(() => msgEl.remove(), 300);
        }, 3000);
    }
    
    showErrorMessage(message) {
        const msgEl = document.createElement('div');
        msgEl.className = 'hope-error-message';
        msgEl.innerHTML = `
            <div class="message-content">
                <span class="icon">✗</span>
                <span class="text">${message}</span>
            </div>
        `;
        msgEl.style.cssText = `
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: #dc3545;
            color: white;
            padding: 20px 30px;
            border-radius: 10px;
            z-index: 100001;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            animation: fadeIn 0.3s ease;
        `;
        
        document.body.appendChild(msgEl);
        
        setTimeout(() => {
            msgEl.style.animation = 'fadeOut 0.3s ease';
            setTimeout(() => msgEl.remove(), 300);
        }, 5000);
    }
    
    /**
     * Sync seat map with current cart state (handles both additions and removals)
     */
    syncWithCartState() {
        if (!window.hopeSeatMap) {
            console.log('Cannot sync with cart state - seat map not initialized');
            return;
        }
        
        console.log('syncWithCartState: Syncing seat map with current cart state');
        
        // First, clear any existing seat selections to start fresh
        if (window.hopeSeatMap.selectedSeats) {
            window.hopeSeatMap.selectedSeats.clear();
        }
        
        // Get current cart seats and restore only those
        this.getSeatsFromCart().then((cartSeats) => {
            if (cartSeats && cartSeats.length > 0) {
                console.log('Found seats in current cart:', cartSeats);
                this.attemptSeatRestore(cartSeats);
            } else {
                console.log('No seats found in current cart - map will show all seats as available');
                // Update display to show no seats selected
                if (window.hopeSeatMap.updateSelectedDisplay) {
                    window.hopeSeatMap.updateSelectedDisplay();
                }
            }
        }).catch((error) => {
            console.log('Error syncing with cart state:', error);
            // Fallback to DOM parsing for backward compatibility
            this.fallbackRestoreFromDOM();
        });
    }
    
    /**
     * Try to restore previously selected seats from page data (legacy method)
     */
    restoreSeatsFromCart() {
        if (!window.hopeSeatMap) {
            console.log('Cannot restore seats - seat map not initialized');
            return;
        }
        
        console.log('restoreSeatsFromCart: Starting seat restoration process');
        
        // First, try to get seats from WooCommerce cart via AJAX
        this.getSeatsFromCart().then((cartSeats) => {
            if (cartSeats && cartSeats.length > 0) {
                console.log('Found seats in cart via AJAX:', cartSeats);
                this.attemptSeatRestore(cartSeats);
            } else {
                // Fallback to DOM parsing
                console.log('No seats found in cart, trying DOM fallback');
                this.fallbackRestoreFromDOM();
            }
        }).catch((error) => {
            console.log('Error getting cart seats via AJAX, trying DOM fallback:', error);
            this.fallbackRestoreFromDOM();
        });
    }
    
    getSeatsFromCart() {
        return fetch(hope_ajax.ajax_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'hope_get_cart_seats',
                nonce: hope_ajax.nonce,
                product_id: hope_ajax.product_id
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                return data.data.seats || [];
            } else {
                throw new Error(data.data || 'Failed to get cart seats');
            }
        });
    }
    
    attemptSeatRestore(seatsToRestore) {
        // Wait for seat map to be fully rendered
        let retryCount = 0;
        const maxRetries = 10;
        
        const attemptRestore = () => {
            retryCount++;
            console.log(`Restore attempt ${retryCount}/${maxRetries}`);
            
            // Check if seats are rendered
            const allSeats = document.querySelectorAll('#seat-map .seat');
            if (allSeats.length === 0 && retryCount < maxRetries) {
                console.log('Seats not yet rendered, retrying in 100ms...');
                setTimeout(attemptRestore, 100);
                return;
            }
            
            console.log(`Found ${allSeats.length} rendered seats`);
            console.log('Seats to restore:', seatsToRestore);
            
            if (seatsToRestore.length > 0 && window.hopeSeatMap.selectSeats) {
                try {
                    // Verify each seat exists in the DOM before attempting to select
                    const validSeats = seatsToRestore.filter(seatId => {
                        const seatElement = document.querySelector(`[data-id="${seatId}"]`);
                        const exists = !!seatElement;
                        console.log(`Seat ${seatId} exists: ${exists}`);
                        return exists;
                    });
                    
                    if (validSeats.length > 0) {
                        console.log(`Restoring ${validSeats.length} valid seats:`, validSeats);
                        window.hopeSeatMap.selectSeats(validSeats);
                        console.log('Successfully restored seats from cart');
                    } else {
                        console.log('No valid seats found to restore');
                    }
                } catch (error) {
                    console.log('Error restoring seats:', error);
                }
            } else {
                console.log('No seats found to restore or selectSeats method unavailable');
            }
        };
        
        // Start the restoration process
        attemptRestore();
    }
    
    fallbackRestoreFromDOM() {
        console.log('Falling back to DOM parsing for seat restoration');
        
        let seatsToRestore = [];
        
        // Check if there's seat data in the summary displays
        const summaryElements = document.querySelectorAll('.hope-seats-list, .selected-seats-list, .hope-selected-seats-display');
        for (const element of summaryElements) {
            if (element.textContent && element.textContent.trim()) {
                const seatText = element.textContent.trim();
                if (seatText !== 'No seats selected' && seatText !== '' && !seatText.includes('$')) {
                    // Try to extract seat IDs from text
                    const seatMatches = seatText.match(/[A-Z]\d+-\d+/g);
                    if (seatMatches && seatMatches.length > 0) {
                        seatsToRestore = seatMatches;
                        console.log('Extracted seats from summary:', seatsToRestore);
                        break;
                    }
                }
            }
        }
        
        // Check hidden form fields that might have seat data
        if (seatsToRestore.length === 0) {
            const hiddenSeatFields = document.querySelectorAll('input[name*="seat"], input[id*="seat"]');
            for (const field of hiddenSeatFields) {
                if (field.value && field.value !== '') {
                    try {
                        const parsedSeats = JSON.parse(field.value);
                        if (Array.isArray(parsedSeats) && parsedSeats.length > 0) {
                            seatsToRestore = parsedSeats;
                            console.log('Found seats in hidden field:', seatsToRestore);
                            break;
                        }
                    } catch (e) {
                        // Not JSON, try as comma-separated
                        const seats = field.value.split(',').map(s => s.trim()).filter(s => s.match(/[A-Z]\d+-\d+/));
                        if (seats.length > 0) {
                            seatsToRestore = seats;
                            console.log('Found seats in hidden field (comma-separated):', seatsToRestore);
                            break;
                        }
                    }
                }
            }
        }
        
        if (seatsToRestore.length > 0) {
            this.attemptSeatRestore(seatsToRestore);
        } else {
            console.log('No seats found to restore via DOM fallback');
        }
    }
    
    /**
     * Toggle the pricing legend
     */
    toggleLegend() {
        console.log('toggleLegend method called');
        const legendToggle = this.modal.querySelector('#legend-toggle');
        const legend = this.modal.querySelector('#pricing-legend');
        
        console.log('Legend toggle elements - toggle:', !!legendToggle, 'legend:', !!legend);
        
        if (!legendToggle || !legend) {
            console.error('Legend elements not found');
            return;
        }
        
        const isVisible = legend.classList.contains('visible');
        console.log('Legend is currently visible:', isVisible);
        
        if (isVisible) {
            console.log('Hiding legend');
            legend.classList.remove('visible');
            legendToggle.classList.remove('active');
        } else {
            console.log('Showing legend');
            // Remove display:none from inline styles and add visible class
            legend.style.display = '';
            legend.classList.add('visible');
            legendToggle.classList.add('active');
        }
    }
    
    /**
     * Refresh legend toggle event listeners (called when content becomes visible)
     */
    refreshLegendToggle() {
        console.log('refreshLegendToggle called');
        const legendToggle = this.modal.querySelector('#legend-toggle');
        const legend = this.modal.querySelector('#pricing-legend');
        
        console.log('Refresh legend elements - toggle:', !!legendToggle, 'legend:', !!legend);
        
        if (legendToggle) {
            console.log('Legend toggle button found:', legendToggle.outerHTML);
            console.log('Button is visible:', legendToggle.offsetParent !== null);
            console.log('Button rect:', legendToggle.getBoundingClientRect());
        }
        
        if (legend) {
            console.log('Legend element found with display:', window.getComputedStyle(legend).display);
            console.log('Legend max-height:', window.getComputedStyle(legend).maxHeight);
        }
        
        // No need to add listeners here since we're using event delegation
        console.log('Using event delegation for legend toggle - no additional setup needed');
    }
    
    /**
     * Toggle the selected seats panel
     */
    toggleSeatsPanel() {
        const seatsToggle = this.modal.querySelector('#seats-toggle');
        const seatsPanel = this.modal.querySelector('#selected-seats-panel');
        
        if (!seatsToggle || !seatsPanel) return;
        
        const isVisible = seatsPanel.classList.contains('visible');
        
        if (isVisible) {
            seatsPanel.classList.remove('visible');
            seatsToggle.classList.remove('active');
            setTimeout(() => {
                seatsPanel.style.display = 'none';
            }, 300);
        } else {
            seatsPanel.style.display = 'block';
            setTimeout(() => {
                seatsPanel.classList.add('visible');
                seatsToggle.classList.add('active');
            }, 10);
        }
    }
    
    /**
     * Show navigation hint that fades on interaction
     */
    showNavigationHint() {
        const hint = this.modal.querySelector('#navigation-hint');
        if (!hint) return;
        
        // Show hint
        hint.style.display = 'block';
        
        // Auto-fade after 3 seconds
        const autoFadeTimeout = setTimeout(() => {
            this.fadeNavigationHint();
        }, 3000);
        
        // Fade on any interaction
        const fadeOnInteraction = () => {
            clearTimeout(autoFadeTimeout);
            this.fadeNavigationHint();
            
            // Remove event listeners
            this.modal.removeEventListener('click', fadeOnInteraction);
            this.modal.removeEventListener('touchstart', fadeOnInteraction);
            document.removeEventListener('keydown', fadeOnInteraction);
        };
        
        this.modal.addEventListener('click', fadeOnInteraction);
        this.modal.addEventListener('touchstart', fadeOnInteraction);
        document.addEventListener('keydown', fadeOnInteraction);
    }
    
    /**
     * Fade out the navigation hint
     */
    fadeNavigationHint() {
        const hint = this.modal.querySelector('#navigation-hint');
        if (!hint) return;
        
        hint.classList.add('fade-out');
        setTimeout(() => {
            hint.style.display = 'none';
            hint.classList.remove('fade-out');
        }, 500);
    }
    
    /**
     * Calculate footer height and update CSS custom property for panel positioning
     */
    updateFooterHeight() {
        const footer = this.modal.querySelector('.hope-modal-footer');
        if (!footer) {
            // Only log once if footer not found
            if (this.lastFooterHeight !== 'not-found') {
                console.log('HOPE: Footer not found for height calculation');
                this.lastFooterHeight = 'not-found';
            }
            return;
        }
        
        // Wait for footer to be fully rendered
        setTimeout(() => {
            // Get the actual computed height of the footer
            const footerRect = footer.getBoundingClientRect();
            const footerHeight = footerRect.height;
            const footerOffsetHeight = footer.offsetHeight;
            
            // Use the larger of the two measurements
            const actualHeight = Math.max(footerHeight, footerOffsetHeight);
            
            // Only log and update if height has actually changed
            if (actualHeight !== this.lastFooterHeight && actualHeight > 0) {
                console.log('HOPE: Footer height changed from', this.lastFooterHeight, 'to', actualHeight + 'px');
                
                // Set CSS custom property on the modal body (where panel is positioned)
                const modalBody = this.modal.querySelector('.hope-modal-body');
                if (modalBody) {
                    modalBody.style.setProperty('--footer-height', actualHeight + 'px');
                }
                
                // Also set on the modal itself
                this.modal.style.setProperty('--footer-height', actualHeight + 'px');
                
                // Set on document root for global access
                document.documentElement.style.setProperty('--hope-footer-height', actualHeight + 'px');
                
                // Special handling for mobile - ensure panel is visible
                if (hope_ajax.is_mobile || window.innerWidth <= 768) {
                    const panel = this.modal.querySelector('.selected-seats-panel');
                    if (panel) {
                        // Force the panel to be visible by setting inline styles as backup
                        panel.style.setProperty('--footer-height', actualHeight + 'px');
                    }
                }
                
                // Update stored height
                this.lastFooterHeight = actualHeight;
                console.log('HOPE: Footer height successfully updated to', actualHeight + 'px');
            } else if (actualHeight === 0) {
                // Only warn once about zero height
                if (this.lastFooterHeight !== 'zero-height') {
                    console.warn('HOPE: Footer height calculation returned 0, using fallback');
                    this.lastFooterHeight = 'zero-height';
                }
            }
            // If height hasn't changed, don't log anything
        }, 100);
    }
    
    /**
     * Setup MutationObserver to watch for footer changes (like timer appearing)
     */
    setupFooterObserver() {
        if (!this.modal) return;
        
        const footer = this.modal.querySelector('.hope-modal-footer');
        if (!footer) return;
        
        // Create observer to watch for changes in footer content
        this.footerObserver = new MutationObserver((mutations) => {
            let shouldUpdate = false;
            
            mutations.forEach((mutation) => {
                // Check for added/removed nodes or style changes
                if (mutation.type === 'childList' || 
                    mutation.type === 'attributes' && mutation.attributeName === 'style') {
                    shouldUpdate = true;
                }
                
                // Check for timer-related changes
                if (mutation.target.className && 
                    (mutation.target.className.includes('timer') || 
                     mutation.target.className.includes('session'))) {
                    shouldUpdate = true;
                    console.log('HOPE: Timer-related change detected in footer');
                }
            });
            
            if (shouldUpdate && this.isOpen) {
                // Only log if we haven't already detected this change recently
                if (!this.recentFooterChange) {
                    console.log('HOPE: Footer content changed, recalculating height');
                    this.recentFooterChange = true;
                    // Reset the flag after a short delay to allow for batched changes
                    setTimeout(() => {
                        this.recentFooterChange = false;
                    }, 1000);
                }
                // Delay to allow DOM to settle
                setTimeout(() => this.updateFooterHeight(), 200);
            }
        });
        
        // Observe footer and its children for changes
        this.footerObserver.observe(footer, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['style', 'class']
        });
        
        console.log('HOPE: Footer observer setup complete');
    }
    
    /**
     * Calculate and set container height dynamically based on modal size
     */
    updateContainerHeight() {
        console.log('HOPE: updateContainerHeight called - using flexbox layout');
        const modal = this.modal;
        if (!modal) {
            console.log('HOPE: No modal found');
            return;
        }
        
        const theaterContainer = modal.querySelector('.theater-container');
        const seatingContainer = modal.querySelector('.seating-container');
        
        if (!theaterContainer || !seatingContainer) {
            console.log('HOPE: Missing required elements for height calculation');
            return;
        }
        
        // Reset any inline styles to let CSS flexbox handle the layout
        theaterContainer.style.height = '';
        seatingContainer.style.height = '';
        
        // Apply mobile-specific adjustments if needed
        const isMobile = window.innerWidth <= 768;
        if (isMobile) {
            theaterContainer.style.height = '100vh';
        }
        
        console.log('HOPE: Container heights reset to use flexbox layout, mobile:', isMobile);
        
        // Trigger resize event for seat map to adjust
        if (window.hopeSeatMap && window.hopeSeatMap.handleResize) {
            setTimeout(() => {
                window.hopeSeatMap.handleResize();
            }, 100);
        }
    }
    
    /**
     * Cleanup observer when modal closes
     */
    cleanupFooterObserver() {
        if (this.footerObserver) {
            this.footerObserver.disconnect();
            this.footerObserver = null;
            console.log('HOPE: Footer observer cleaned up');
        }
    }
}

// Add CSS animations
const style = document.createElement('style');
style.textContent = `
    @keyframes fadeIn {
        from { opacity: 0; transform: translate(-50%, -50%) scale(0.9); }
        to { opacity: 1; transform: translate(-50%, -50%) scale(1); }
    }
    
    @keyframes fadeOut {
        from { opacity: 1; transform: translate(-50%, -50%) scale(1); }
        to { opacity: 0; transform: translate(-50%, -50%) scale(0.9); }
    }
    
    .hope-modal-open {
        overflow: hidden;
    }
    
    .spinner {
        display: inline-block;
        width: 16px;
        height: 16px;
        border: 2px solid rgba(255,255,255,0.3);
        border-top-color: white;
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
    }
    
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    
    .seats-selected {
        background: #28a745 !important;
        border-color: #28a745 !important;
    }
`;
document.head.appendChild(style);

// Initialize and store globally
window.hopeModalHandler = new HOPEModalHandler();