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
                    target.matches('#hope-select-seats, #hope-select-seats-main, .hope-select-seats-btn') ||
                    target.closest('#hope-select-seats, #hope-select-seats-main, .hope-select-seats-btn')
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
        
        // Close button
        const closeBtn = this.modal.querySelector('.hope-modal-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => this.closeModal());
        }
        
        // Cancel button
        const cancelBtn = this.modal.querySelector('.hope-cancel-btn');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', () => this.closeModal());
        }
        
        // Add to cart button
        const addToCartBtn = this.modal.querySelector('.hope-add-to-cart-btn');
        if (addToCartBtn) {
            addToCartBtn.addEventListener('click', () => this.addToCart());
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
                    this.addToCart();
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
        
        // Add body class to prevent scrolling
        document.body.classList.add('hope-modal-open');
        
        // Hide loading, show content
        setTimeout(() => {
            const loader = this.modal.querySelector('.hope-loading-indicator');
            const content = this.modal.querySelector('#hope-seat-map-container');
            
            if (loader) loader.style.display = 'none';
            if (content) content.style.display = 'block';
            
            // Trigger custom event
            const event = new CustomEvent('modal-opened');
            this.modal.dispatchEvent(event);
            
            // Initialize or refresh seat map
            if (window.hopeSeatMap) {
                window.hopeSeatMap.initializeMap();
            } else {
                // Create new instance if needed
                if (typeof HOPESeatMap !== 'undefined' && typeof hope_ajax !== 'undefined') {
                    window.hopeSeatMap = new HOPESeatMap();
                }
            }
        }, 500);
        
        // Focus management for accessibility
        this.previousFocus = document.activeElement;
        const closeBtn = this.modal.querySelector('.hope-modal-close');
        if (closeBtn) closeBtn.focus();
        
        // Trap focus within modal
        this.trapFocus();
    }
    
    closeModal() {
        if (!this.modal || !this.isOpen) return;
        
        // Confirm if seats are selected
        if (this.seatMap && this.seatMap.selectedSeats.size > 0) {
            if (!confirm('You have selected seats. Are you sure you want to close without adding them to cart?')) {
                return;
            }
            
            // Release held seats
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
        }
        
        // Emit modal closed event
        const modalClosedEvent = new CustomEvent('hope-modal-closed', {
            detail: { seats: this.seatMap ? Array.from(this.seatMap.selectedSeats) : [] }
        });
        document.dispatchEvent(modalClosedEvent);
    }
    
    addToCart() {
        if (!this.seatMap || this.seatMap.selectedSeats.size === 0) {
            return;
        }
        
        const addToCartBtn = this.modal.querySelector('.hope-add-to-cart-btn');
        if (addToCartBtn) {
            addToCartBtn.disabled = true;
            addToCartBtn.innerHTML = '<span class="spinner"></span> Adding to cart...';
        }
        
        const seats = Array.from(this.seatMap.selectedSeats);
        
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
            if (data.success) {
                // Update button on product page - support multiple button IDs
                const productBtn = document.getElementById('hope-select-seats') || 
                                  document.getElementById('hope-select-seats-main');
                if (productBtn) {
                    productBtn.innerHTML = `<span class="btn-text">${seats.length} Seats Selected</span> <span class="btn-icon">✓</span>`;
                    productBtn.classList.add('seats-selected');
                }
                
                // Show selected seats summary
                const summary = document.querySelector('.hope-selected-seats-summary') || 
                              document.querySelector('.hope-selected-seats-display');
                if (summary) {
                    summary.style.display = 'block';
                    const listEl = summary.querySelector('.selected-seats-list') ||
                                  summary.querySelector('.hope-seats-list');
                    if (listEl) {
                        listEl.innerHTML = seats.join(', ');
                    }
                }
                
                // Close modal
                this.isOpen = false; // Prevent confirmation
                this.modal.style.display = 'none';
                document.body.classList.remove('hope-modal-open');
                
                // Emit event for WooCommerce integration
                const seatsUpdatedEvent = new CustomEvent('hope-seats-updated', {
                    detail: { seats: Array.from(this.seatMap.selectedSeats) }
                });
                document.dispatchEvent(seatsUpdatedEvent);
                
                // Redirect to cart or show success message
                if (data.data.cart_url) {
                    window.location.href = data.data.cart_url;
                } else {
                    this.showSuccessMessage(data.data.message);
                }
            } else {
                this.showErrorMessage(data.data.message);
                
                // Re-enable button
                if (addToCartBtn) {
                    addToCartBtn.disabled = false;
                    addToCartBtn.innerHTML = 'Add to Cart <span class="seat-count-badge">' + seats.length + '</span>';
                }
            }
        })
        .catch(error => {
            console.error('Error adding to cart:', error);
            this.showErrorMessage('Failed to add seats to cart. Please try again.');
            
            // Re-enable button
            if (addToCartBtn) {
                addToCartBtn.disabled = false;
                addToCartBtn.innerHTML = 'Add to Cart <span class="seat-count-badge">' + seats.length + '</span>';
            }
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