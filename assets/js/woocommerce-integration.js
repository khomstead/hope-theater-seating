/**
 * WooCommerce Integration for HOPE Theater Seating
 * Handles seat selection integration with WooCommerce variations
 */

class HOPEWooCommerceIntegration {
    constructor() {
        this.selectedSeats = new Set();
        this.totalPrice = 0;
        this.variationId = 0;
        
        this.init();
    }
    
    init() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.setupIntegration());
        } else {
            this.setupIntegration();
        }
    }
    
    setupIntegration() {
        console.log('WooCommerce integration setup starting');
        
        // Check if required elements exist
        const seatInterface = document.querySelector('.hope-seat-selection-interface');
        const addToCartForm = document.querySelector('.cart');
        
        console.log('Found seat interface:', !!seatInterface);
        console.log('Found add to cart form:', !!addToCartForm);
        
        if (!seatInterface) {
            console.warn('Seat selection interface not found - skipping WooCommerce integration');
            return;
        }
        
        // Connect to the modal handler
        this.connectToModal();
        
        // Setup seat selection button
        this.setupSeatSelectionButton();
        
        // Override add to cart functionality
        this.overrideAddToCart();
        
        // Listen for seat selection changes
        this.listenForSeatChanges();
        
        // Check for existing cart seats and restore display
        this.restoreProductPageDisplay();
        
        // Listen for browser back/forward navigation
        window.addEventListener('pageshow', (event) => {
            // Only restore if coming from cache (back button)
            if (event.persisted) {
                console.log('Page restored from cache, checking for cart seats...');
                setTimeout(() => this.restoreProductPageDisplay(), 500);
            }
        });
        
        console.log('WooCommerce integration setup completed');
    }
    
    connectToModal() {
        // Wait for modal to be available
        const checkModal = setInterval(() => {
            if (window.hopeSeatMap) {
                clearInterval(checkModal);
                this.seatMap = window.hopeSeatMap;
                
                // Override seat map's update method to sync with our interface
                const originalUpdate = this.seatMap.updateSelectedDisplay;
                this.seatMap.updateSelectedDisplay = () => {
                    originalUpdate.call(this.seatMap);
                    this.syncSeatDisplay();
                };
            }
        }, 100);
    }
    
    setupSeatSelectionButton() {
        // Remove duplicate event handler - modal-handler.js handles all button clicks
        // Just ensure we have the right data attributes for the modal
        const button = document.getElementById('hope-select-seats-main');
        if (button && !button.hasAttribute('data-venue-id')) {
            button.setAttribute('data-venue-id', '1'); // Default venue
        }
    }
    
    syncSeatDisplay() {
        if (!this.seatMap) return;
        
        const seats = Array.from(this.seatMap.selectedSeats);
        this.selectedSeats = new Set(seats);
        
        if (seats.length > 0) {
            this.updateSelectedSeatsDisplay(seats);
            this.getVariationForSeats(seats);
        } else {
            this.clearSelectedSeatsDisplay();
        }
    }
    
    updateSelectedSeatsDisplay(seats, serverTotal = null) {
        console.log('updateSelectedSeatsDisplay called with seats:', seats, 'serverTotal:', serverTotal);
        
        const prompt = document.querySelector('.hope-seat-selection-prompt');
        const display = document.querySelector('.hope-selected-seats-display');
        
        console.log('Found prompt element:', !!prompt);
        console.log('Found display element:', !!display);
        
        if (!display) {
            console.error('Could not find .hope-selected-seats-display element');
            // Still continue to enable the checkout button
            this.enableAddToCart();
            return;
        }
        
        const seatsList = display.querySelector('.hope-seats-list');
        const totalAmount = display.querySelector('.total-amount');
        
        console.log('Found seatsList:', !!seatsList);
        console.log('Found totalAmount:', !!totalAmount);
        
        // Hide prompt, show display
        if (prompt) {
            prompt.style.display = 'none';
            console.log('Hid prompt');
        }
        if (display) {
            display.style.display = 'block';
            console.log('Showed display');
        }
        
        // Update seats list
        if (seatsList) {
            seatsList.innerHTML = '';
            let calculatedTotal = 0;
            
            seats.forEach(seatId => {
                const seatTag = document.createElement('span');
                seatTag.className = 'hope-seat-tag';
                seatTag.textContent = seatId;
                
                // Get tier information for color-coding
                const tier = this.getSeatTier(seatId);
                const tierColor = this.getTierColor(tier);
                
                // Apply tier-based styling
                seatTag.style.backgroundColor = tierColor;
                seatTag.setAttribute('data-tier', tier);
                seatTag.setAttribute('title', this.getTierName(tier) + ' - $' + this.getSeatPrice(seatId).toFixed(2));
                
                seatsList.appendChild(seatTag);
                
                // Calculate price (for fallback)
                calculatedTotal += this.getSeatPrice(seatId);
            });
            
            // Use server total if available, otherwise use calculated total
            const finalTotal = serverTotal !== null ? serverTotal : calculatedTotal;
            this.totalPrice = finalTotal;
            
            if (totalAmount) {
                totalAmount.textContent = '$' + parseFloat(finalTotal).toFixed(2);
                console.log('Updated total to server value:', finalTotal);
            }
        }
        
        // Update hidden form fields
        this.updateHiddenFields(seats);
        
        // Enable add to cart button
        this.enableAddToCart();
    }
    
    clearSelectedSeatsDisplay() {
        const prompt = document.querySelector('.hope-seat-selection-prompt');
        const display = document.querySelector('.hope-selected-seats-display');
        
        if (prompt) prompt.style.display = 'block';
        if (display) display.style.display = 'none';
        
        this.selectedSeats.clear();
        this.totalPrice = 0;
        this.variationId = 0;
        
        // Clear hidden fields
        this.updateHiddenFields([]);
        
        // Disable add to cart
        this.disableAddToCart();
    }
    
    getSeatPrice(seatId) {
        // Get price from seat map if available
        if (this.seatMap && this.seatMap.pricing) {
            const seatElement = document.querySelector(`[data-id="${seatId}"]`);
            if (seatElement) {
                const tier = seatElement.getAttribute('data-tier');
                if (this.seatMap.pricing[tier]) {
                    return this.seatMap.pricing[tier].price;
                }
            }
        }
        
        // Fallback price
        return 25;
    }
    
    getSeatTier(seatId) {
        // Try to get tier from seat element first
        const seatElement = document.querySelector(`[data-id="${seatId}"]`);
        if (seatElement) {
            return seatElement.getAttribute('data-tier') || 'p2';
        }
        
        // Fallback: extract tier using same logic as PHP
        return this.extractTierFromSeatId(seatId);
    }
    
    extractTierFromSeatId(seatId) {
        // Parse seat ID to determine pricing tier (matches PHP logic)
        const match = seatId.match(/^([A-Z])(\d+)-(\d+)$/);
        if (!match) return 'p2';
        
        const section = match[1];
        const row = parseInt(match[2]);
        
        // Theater pricing logic (matches the PHP code exactly)
        if (section === 'A') {
            if (row <= 2) return 'p1';
            else if (row <= 9) return 'p2';
            else return 'aa';
        } else if (section === 'B') {
            if (row <= 3) return 'p1';
            else return 'p2';
        } else if (section === 'C') {
            if (row <= 3) return 'p1';
            else if (row <= 9) return 'p2';
            else return 'p3';
        } else if (section === 'D') {
            if (row <= 3) return 'p1';
            else if (row <= 9) return 'p2';
            else return 'aa';
        } else if (section === 'E') {
            if (row <= 2) return 'p1';
            else if (row <= 7) return 'p2';
            else if (row <= 9) return 'p3';
            else return 'aa';
        } else if (section === 'F') {
            if (row <= 1) return 'p1';
            else if (row <= 3) return 'p2';
            else return 'p3';
        } else if (section === 'G') {
            if (row <= 1) return 'p1';
            else if (row <= 3) return 'p2';
            else return 'p3';
        } else if (section === 'H') {
            if (row <= 1) return 'p1';
            else if (row <= 3) return 'p2';
            else return 'p3';
        }
        
        return 'p2'; // Default fallback
    }
    
    getTierColor(tier) {
        // Color mapping that matches seat-map.js pricing colors
        const tierColors = {
            'p1': '#9b59b6', // Premium - Purple
            'p2': '#3498db', // Standard - Blue  
            'p3': '#17a2b8', // Value - Teal
            'aa': '#e67e22'  // Accessible - Orange
        };
        
        return tierColors[tier] || tierColors['p2'];
    }
    
    getTierName(tier) {
        const tierNames = {
            'p1': 'Premium',
            'p2': 'Standard', 
            'p3': 'Value',
            'aa': 'Accessible'
        };
        
        return tierNames[tier] || 'Standard';
    }
    
    getVariationForSeats(seats) {
        if (!hopeWooIntegration.is_variable || seats.length === 0) {
            return;
        }
        
        // Additional safety check for required data
        if (!hopeWooIntegration.product_id || hopeWooIntegration.product_id === '0') {
            console.log('HOPE: Skipping variation request - invalid product ID');
            return;
        }
        
        fetch(hopeWooIntegration.ajax_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'hope_get_variation_for_seats',
                nonce: hopeWooIntegration.nonce,
                product_id: hopeWooIntegration.product_id,
                selected_seats: JSON.stringify(seats)
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data.variation_id) {
                this.variationId = data.data.variation_id;
                this.updateVariationDisplay(data.data);
            }
        })
        .catch(error => {
            console.error('Error getting variation:', error);
        });
    }
    
    updateVariationDisplay(variationData) {
        // Update any price displays
        const priceElements = document.querySelectorAll('.price');
        if (variationData.price_html && priceElements.length > 0) {
            // Update the first price element found
            priceElements[0].innerHTML = variationData.price_html;
        }
        
        // Store variation ID for form submission
        const variationField = document.getElementById('hope_selected_variation');
        if (variationField) {
            variationField.value = this.variationId;
        }
    }
    
    updateHiddenFields(seats) {
        const seatsField = document.getElementById('hope_selected_seats');
        const transField = document.getElementById('fooevents_seats__trans');
        
        if (seatsField) {
            seatsField.value = JSON.stringify(seats);
        }
        
        // FooEvents compatibility
        if (transField) {
            transField.value = seats.join(',');
        }
    }
    
    overrideAddToCart() {
        const form = document.querySelector('.cart');
        const addToCartButton = form ? form.querySelector('.single_add_to_cart_button') : null;
        
        if (!addToCartButton) return;
        
        // Initially disable the button
        this.disableAddToCart();
        
        // Override form submission
        form.addEventListener('submit', (e) => {
            console.log('Form submission intercepted, selectedSeats size:', this.selectedSeats.size);
            
            const addToCartButton = form.querySelector('.single_add_to_cart_button');
            
            // Check if Add to Cart button is disabled (no seats selected)
            if (addToCartButton && addToCartButton.disabled) {
                console.log('Checkout button is disabled, preventing form submission');
                e.preventDefault();
                alert(hopeWooIntegration.messages.select_seats_first);
                return false;
            }
            
            // If button text is "Checkout", it means seats are already in cart - redirect to checkout
            if (addToCartButton && addToCartButton.textContent === 'Checkout') {
                console.log('Checkout button clicked - seats already in cart, redirecting to checkout');
                e.preventDefault();
                
                // Redirect to checkout using the URL from our localized script
                window.location.href = hope_ajax?.checkout_url || '/checkout';
                return false;
            }
            
            // If we reach here, allow normal WooCommerce form submission (fallback case)
            console.log('Allowing normal WooCommerce form submission');
            
            // Set quantity to 1 since each seat is a separate cart item
            const qtyInput = form.querySelector('[name="quantity"]');
            if (qtyInput) {
                qtyInput.value = 1;
            }
            
            // Add variation ID if needed (for variable products)
            if (hopeWooIntegration.is_variable && this.variationId) {
                const variationInput = form.querySelector('[name="variation_id"]');
                if (!variationInput) {
                    const hiddenVariation = document.createElement('input');
                    hiddenVariation.type = 'hidden';
                    hiddenVariation.name = 'variation_id';
                    hiddenVariation.value = this.variationId;
                    form.appendChild(hiddenVariation);
                } else {
                    variationInput.value = this.variationId;
                }
            }
            
            // Allow form to submit normally - WooCommerce will handle the redirect
        });
    }
    
    enableAddToCart() {
        const addToCartButton = document.querySelector('.single_add_to_cart_button');
        if (addToCartButton) {
            addToCartButton.disabled = false;
            addToCartButton.classList.remove('disabled');
            addToCartButton.style.opacity = '1';
            addToCartButton.textContent = 'Checkout';
        }
    }
    
    disableAddToCart() {
        const addToCartButton = document.querySelector('.single_add_to_cart_button');
        if (addToCartButton) {
            addToCartButton.disabled = true;
            addToCartButton.classList.add('disabled');
            addToCartButton.style.opacity = '0.6';
            addToCartButton.textContent = 'Select seats first';
        }
    }
    
    listenForSeatChanges() {
        // Listen for custom events from the seat map
        document.addEventListener('hope-seats-updated', (e) => {
            const seats = e.detail.seats || [];
            console.log('hope-seats-updated event received with seats:', seats);
            
            // Update our local selectedSeats and display
            this.selectedSeats = new Set(seats);
            
            if (seats.length > 0) {
                this.updateSelectedSeatsDisplay(seats);
                this.getVariationForSeats(seats);
                this.enableAddToCart();
            } else {
                this.clearSelectedSeatsDisplay();
            }
        });
        
        // Listen for modal close to sync display
        document.addEventListener('hope-modal-closed', () => {
            if (this.seatMap) {
                this.syncSeatDisplay();
            }
        });
        
        // Listen for forced updates from modal
        document.addEventListener('hope-force-update', (e) => {
            const seats = e.detail.seats || [];
            console.log('hope-force-update event received with seats:', seats);
            
            // Update our local selectedSeats and display
            this.selectedSeats = new Set(seats);
            
            if (seats.length > 0) {
                this.updateSelectedSeatsDisplay(seats);
                this.getVariationForSeats(seats);
                this.enableAddToCart();
            } else {
                this.clearSelectedSeatsDisplay();
            }
        });
    }
    
    restoreProductPageDisplay() {
        // Check if there are seats in the cart for this product
        if (typeof hope_ajax === 'undefined' || !hope_ajax.product_id || hope_ajax.product_id === '0') {
            console.log('HOPE: Skipping product page display restore - missing product data');
            return;
        }
        
        fetch(hope_ajax.ajax_url, {
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
            if (data.success && data.data.seats && data.data.seats.length > 0) {
                console.log('Found seats in cart for product page display:', data.data.seats);
                console.log('Cart total from server:', data.data.total);
                
                this.selectedSeats = new Set(data.data.seats);
                this.updateSelectedSeatsDisplay(data.data.seats, data.data.total);
                this.getVariationForSeats(data.data.seats);
                
                // Update the select seats button
                const selectButton = document.querySelector('#hope-select-seats-main, #hope-select-seats');
                if (selectButton) {
                    selectButton.innerHTML = `<span class="btn-text">${data.data.seats.length} Seats Selected</span> <span class="btn-icon">✓</span>`;
                    selectButton.classList.add('seats-selected');
                }
                
                // CRITICAL: Enable the Add to Cart button since we have seats in cart
                this.enableAddToCart();
            } else {
                console.log('No seats found in cart for this product');
                // Keep Add to Cart disabled if no seats
                this.disableAddToCart();
            }
        })
        .catch(error => {
            console.error('Error checking cart seats for product page:', error);
        });
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    // Only initialize if we have the required data and are on a product page
    if (typeof hopeWooIntegration !== 'undefined' && 
        hopeWooIntegration.product_id && 
        hopeWooIntegration.product_id !== '0') {
        window.hopeWooCommerceInstance = new HOPEWooCommerceIntegration();
        console.log('WooCommerce integration instance created and stored globally');
    } else {
        console.log('HOPE: WooCommerce integration not initialized - missing required data or not on product page');
    }
});

// Export for global access
window.HOPEWooCommerceIntegration = HOPEWooCommerceIntegration;