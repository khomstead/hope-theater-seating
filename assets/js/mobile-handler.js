/**
 * HOPE Theater Mobile Handler
 * Manages touch gestures and mobile-specific interactions
 */

class HOPEMobileHandler {
    constructor() {
        this.touches = [];
        this.lastTouchDistance = 0;
        this.lastTouchTime = 0;
        this.doubleTapDelay = 300;
        this.pinchSensitivity = 0.01;
        
        this.init();
    }
    
    init() {
        if (!this.isMobile()) return;
        
        // Wait for seat map to be available
        const checkMap = setInterval(() => {
            const seatMap = document.getElementById('seat-map');
            if (seatMap && window.hopeSeatMap) {
                clearInterval(checkMap);
                this.setupTouchHandlers();
                this.setupMobileUI();
            }
        }, 100);
    }
    
    isMobile() {
        return /iPhone|iPad|iPod|Android/i.test(navigator.userAgent) || 
               ('ontouchstart' in window) ||
               (navigator.maxTouchPoints > 0);
    }
    
    setupTouchHandlers() {
        const wrapper = document.getElementById('seating-wrapper');
        if (!wrapper) return;
        
        // Prevent default touch behaviors
        wrapper.addEventListener('touchstart', (e) => this.handleTouchStart(e), { passive: false });
        wrapper.addEventListener('touchmove', (e) => this.handleTouchMove(e), { passive: false });
        wrapper.addEventListener('touchend', (e) => this.handleTouchEnd(e), { passive: false });
        
        // Handle seat taps
        document.addEventListener('touchstart', (e) => {
            if (e.target.classList && e.target.classList.contains('seat')) {
                e.preventDefault();
                this.handleSeatTap(e);
            }
        }, { passive: false });
    }
    
    setupMobileUI() {
        // Add mobile-specific classes
        document.body.classList.add('hope-mobile');
        
        // Enhance zoom controls for mobile
        const zoomControls = document.querySelector('.zoom-controls');
        if (zoomControls) {
            zoomControls.classList.add('mobile-enhanced');
        }
        
        // Make floor tabs larger on mobile
        const floorTabs = document.querySelectorAll('.floor-btn');
        floorTabs.forEach(tab => {
            tab.classList.add('mobile-enhanced');
        });
        
        // Add touch instructions
        this.addTouchInstructions();
        
        // Optimize viewport for mobile
        this.optimizeViewport();
    }
    
    handleTouchStart(e) {
        this.touches = Array.from(e.touches);
        
        if (this.touches.length === 2) {
            // Pinch zoom start
            e.preventDefault();
            this.lastTouchDistance = this.getTouchDistance(this.touches[0], this.touches[1]);
        } else if (this.touches.length === 1) {
            // Pan start or double tap
            const now = Date.now();
            const timeDiff = now - this.lastTouchTime;
            
            if (timeDiff < this.doubleTapDelay && timeDiff > 0) {
                // Double tap detected
                e.preventDefault();
                this.handleDoubleTap(e.touches[0]);
            }
            
            this.lastTouchTime = now;
            
            // Start pan
            if (window.hopeSeatMap) {
                window.hopeSeatMap.isDragging = true;
                window.hopeSeatMap.startX = e.touches[0].clientX - window.hopeSeatMap.translateX;
                window.hopeSeatMap.startY = e.touches[0].clientY - window.hopeSeatMap.translateY;
            }
        }
    }
    
    handleTouchMove(e) {
        if (this.touches.length === 2 && e.touches.length === 2) {
            // Pinch zoom
            e.preventDefault();
            
            const newDistance = this.getTouchDistance(e.touches[0], e.touches[1]);
            const scale = newDistance / this.lastTouchDistance;
            
            if (Math.abs(scale - 1) > this.pinchSensitivity) {
                this.handlePinchZoom(scale);
                this.lastTouchDistance = newDistance;
            }
        } else if (this.touches.length === 1 && e.touches.length === 1) {
            // Pan
            e.preventDefault();
            
            if (window.hopeSeatMap && window.hopeSeatMap.isDragging) {
                window.hopeSeatMap.translateX = e.touches[0].clientX - window.hopeSeatMap.startX;
                window.hopeSeatMap.translateY = e.touches[0].clientY - window.hopeSeatMap.startY;
                window.hopeSeatMap.updateTransform();
            }
        }
    }
    
    handleTouchEnd(e) {
        if (window.hopeSeatMap) {
            window.hopeSeatMap.isDragging = false;
        }
        
        // Reset touches
        this.touches = Array.from(e.touches);
    }
    
    handleSeatTap(e) {
        const seat = e.target;
        
        // Visual feedback
        seat.style.transform = 'scale(1.2)';
        setTimeout(() => {
            seat.style.transform = '';
        }, 200);
        
        // Trigger seat selection
        if (window.hopeSeatMap) {
            const event = new MouseEvent('click', {
                bubbles: true,
                cancelable: true,
                view: window
            });
            seat.dispatchEvent(event);
        }
        
        // Haptic feedback if available
        if (window.navigator.vibrate) {
            window.navigator.vibrate(50);
        }
    }
    
    handleDoubleTap(touch) {
        // Zoom in on double tap
        if (!window.hopeSeatMap) return;
        
        const rect = document.getElementById('seating-wrapper').getBoundingClientRect();
        const x = touch.clientX - rect.left;
        const y = touch.clientY - rect.top;
        
        // Zoom in centered on tap location
        if (window.hopeSeatMap.currentScale < 2) {
            window.hopeSeatMap.currentScale = 2;
        } else {
            window.hopeSeatMap.currentScale = 1;
        }
        
        document.querySelector('.zoom-label').textContent = 
            Math.round(window.hopeSeatMap.currentScale * 100) + '%';
        
        window.hopeSeatMap.updateTransform();
    }
    
    handlePinchZoom(scale) {
        if (!window.hopeSeatMap) return;
        
        const newScale = window.hopeSeatMap.currentScale * scale;
        
        // Limit zoom range
        window.hopeSeatMap.currentScale = Math.max(0.5, Math.min(3, newScale));
        
        document.querySelector('.zoom-label').textContent = 
            Math.round(window.hopeSeatMap.currentScale * 100) + '%';
        
        window.hopeSeatMap.updateTransform();
    }
    
    getTouchDistance(touch1, touch2) {
        const dx = touch1.clientX - touch2.clientX;
        const dy = touch1.clientY - touch2.clientY;
        return Math.sqrt(dx * dx + dy * dy);
    }
    
    addTouchInstructions() {
        const instructions = document.createElement('div');
        instructions.className = 'hope-touch-instructions';
        instructions.innerHTML = `
            <div class="instruction-content">
                <span class="gesture-icon">👆</span> Tap to select
                <span class="gesture-icon">✌️</span> Pinch to zoom
                <span class="gesture-icon">👆👆</span> Double tap to zoom
            </div>
        `;
        instructions.style.cssText = `
            position: absolute;
            bottom: 10px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 12px;
            z-index: 100;
            display: flex;
            align-items: center;
            gap: 15px;
        `;
        
        const container = document.querySelector('.seating-container');
        if (container) {
            container.appendChild(instructions);
            
            // Hide after 5 seconds
            setTimeout(() => {
                instructions.style.opacity = '0';
                instructions.style.transition = 'opacity 0.5s';
                setTimeout(() => instructions.remove(), 500);
            }, 5000);
        }
    }
    
    optimizeViewport() {
        // Set initial scale for mobile
        if (window.hopeSeatMap) {
            // Start zoomed out on mobile to see full theater
            window.hopeSeatMap.currentScale = 0.8;
            window.hopeSeatMap.updateTransform();
            document.querySelector('.zoom-label').textContent = '80%';
        }
        
        // Ensure viewport meta tag is set correctly
        let viewport = document.querySelector('meta[name="viewport"]');
        if (!viewport) {
            viewport = document.createElement('meta');
            viewport.name = 'viewport';
            document.head.appendChild(viewport);
        }
        viewport.content = 'width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes';
    }
}

// Mobile-specific styles
const mobileStyles = document.createElement('style');
mobileStyles.textContent = `
    .hope-mobile .zoom-controls.mobile-enhanced {
        bottom: 20px;
        right: 20px;
        top: auto;
    }
    
    .hope-mobile .zoom-btn {
        width: 50px;
        height: 50px;
        font-size: 24px;
    }
    
    .hope-mobile .floor-btn.mobile-enhanced {
        padding: 12px 24px;
        font-size: 16px;
    }
    
    .hope-mobile .seat {
        /* Slightly larger touch targets on mobile */
        transform: scale(1.2);
        transform-origin: center;
    }
    
    .hope-mobile .tooltip {
        /* Position tooltip higher on mobile to avoid finger */
        margin-top: -20px;
    }
    
    .hope-mobile .selected-seats-panel {
        /* Sticky bottom panel on mobile */
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        background: white;
        box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
        z-index: 50;
        max-height: 200px;
        overflow-y: auto;
    }
    
    .hope-mobile .hope-modal-content {
        width: 100%;
        height: 100%;
        max-width: none;
        border-radius: 0;
    }
    
    .gesture-icon {
        font-size: 16px;
        margin: 0 5px;
    }
    
    @media (max-width: 768px) {
        .theater-container {
            border-radius: 0;
        }
        
        .seating-container {
            padding: 10px;
        }
        
        .header h1 {
            font-size: 1.5em;
        }
        
        .legend {
            font-size: 12px;
            padding: 10px;
        }
        
        .legend-item {
            gap: 5px;
        }
        
        .legend-color {
            width: 16px;
            height: 16px;
        }
    }
`;
document.head.appendChild(mobileStyles);

// Initialize mobile handler
if (typeof hope_ajax !== 'undefined') {
    new HOPEMobileHandler();
}