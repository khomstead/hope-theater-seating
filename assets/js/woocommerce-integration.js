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
        // Connect to the modal handler
        this.connectToModal();
        
        // Setup seat selection button
        this.setupSeatSelectionButton();
        
        // Override add to cart functionality
        this.overrideAddToCart();
        
        // Listen for seat selection changes
        this.listenForSeatChanges();
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
    
    updateSelectedSeatsDisplay(seats) {
        const prompt = document.querySelector('.hope-seat-selection-prompt');
        const display = document.querySelector('.hope-selected-seats-display');
        const seatsList = display.querySelector('.hope-seats-list');
        const totalAmount = display.querySelector('.total-amount');
        
        // Hide prompt, show display
        if (prompt) prompt.style.display = 'none';
        if (display) display.style.display = 'block';
        
        // Update seats list
        if (seatsList) {
            seatsList.innerHTML = '';
            let total = 0;
            
            seats.forEach(seatId => {
                const seatTag = document.createElement('span');
                seatTag.className = 'hope-seat-tag';
                seatTag.textContent = seatId;
                seatsList.appendChild(seatTag);
                
                // Calculate price (simplified - you may want to get actual prices)
                total += this.getSeatPrice(seatId);
            });
            
            this.totalPrice = total;
            if (totalAmount) {
                totalAmount.textContent = '$' + total.toFixed(2);
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
    
    getVariationForSeats(seats) {
        if (!hopeWooIntegration.is_variable || seats.length === 0) {
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
            if (this.selectedSeats.size === 0) {
                e.preventDefault();
                alert(hopeWooIntegration.messages.select_seats_first);
                return false;
            }
            
            // Add variation ID to form if it's a variable product
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
            
            // Set quantity based on seat count
            const qtyInput = form.querySelector('[name="quantity"]');
            if (qtyInput) {
                qtyInput.value = this.selectedSeats.size;
            }
        });
    }
    
    enableAddToCart() {
        const addToCartButton = document.querySelector('.single_add_to_cart_button');
        if (addToCartButton) {
            addToCartButton.disabled = false;
            addToCartButton.classList.remove('disabled');
            addToCartButton.style.opacity = '1';
            addToCartButton.textContent = addToCartButton.textContent.replace('Select seats first', 'Add to cart');
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
            this.syncSeatDisplay();
        });
        
        // Listen for modal close to sync display
        document.addEventListener('hope-modal-closed', () => {
            if (this.seatMap) {
                this.syncSeatDisplay();
            }
        });
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    if (typeof hopeWooIntegration !== 'undefined') {
        new HOPEWooCommerceIntegration();
    }
});

// Export for global access
window.HOPEWooCommerceIntegration = HOPEWooCommerceIntegration;