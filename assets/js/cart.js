/**
 * KahvePortal - Cart Functionality
 * assets/js/cart.js
 */

window.Cart = {
    items: [],
    total: 0,
    isLoading: false,
    
    // Initialize cart
    init: function() {
        this.loadCart();
        this.setupEventListeners();
        this.updateCartDisplay();
        console.log('Cart initialized');
    },
    
    // Setup event listeners
    setupEventListeners: function() {
        // Add to cart buttons
        document.addEventListener('click', this.handleAddToCart.bind(this));
        
        // Update quantity buttons
        document.addEventListener('click', this.handleQuantityUpdate.bind(this));
        
        // Remove item buttons
        document.addEventListener('click', this.handleRemoveItem.bind(this));
        
        // Clear cart button
        document.addEventListener('click', this.handleClearCart.bind(this));
        
        // Checkout button
        document.addEventListener('click', this.handleCheckout.bind(this));
        
        // Cart dropdown toggle
        document.addEventListener('click', this.handleCartToggle.bind(this));
    },
    
    // Load cart from server
    loadCart: function() {
        if (this.isLoading) return;
        
        this.isLoading = true;
        this.showLoading();
        
        fetch('/api/cart')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.items = data.cart;
                    this.total = data.summary.total_amount;
                    this.updateCartDisplay();
                    this.updateCartBadge(data.summary.total_items);
                } else {
                    this.showError(data.message || 'Sepet yüklenemedi');
                }
            })
            .catch(error => {
                console.error('Cart load error:', error);
                this.showError('Sepet yüklenirken hata oluştu');
            })
            .finally(() => {
                this.isLoading = false;
                this.hideLoading();
            });
    },
    
    // Add item to cart
    addToCart: function(productId, quantity = 1, options = {}) {
        if (this.isLoading) return Promise.reject('İşlem devam ediyor');
        
        this.isLoading = true;
        this.showLoading();
        
        const data = {
            product_id: productId,
            quantity: quantity,
            options: options,
            csrf_token: KahvePortal.config.csrfToken
        };
        
        return fetch('/api/cart', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                this.showSuccess(data.message || 'Ürün sepete eklendi');
                this.updateCartBadge(data.cart_summary.total_quantity);
                this.loadCart(); // Reload cart to get updated data
                return data;
            } else {
                this.showError(data.message || 'Ürün sepete eklenemedi');
                throw new Error(data.message);
            }
        })
        .catch(error => {
            console.error('Add to cart error:', error);
            this.showError('Ürün sepete eklenirken hata oluştu');
            throw error;
        })
        .finally(() => {
            this.isLoading = false;
            this.hideLoading();
        });
    },
    
    // Update item quantity
    updateQuantity: function(cartItemId, quantity) {
        if (this.isLoading) return Promise.reject('İşlem devam ediyor');
        
        if (quantity < 1) {
            return this.removeItem(cartItemId);
        }
        
        this.isLoading = true;
        this.showLoading();
        
        const data = {
            cart_item_id: cartItemId,
            quantity: quantity,
            csrf_token: KahvePortal.config.csrfToken
        };
        
        return fetch('/api/cart', {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                this.loadCart(); // Reload cart to get updated data
                return data;
            } else {
                this.showError(data.message || 'Miktar güncellenemedi');
                throw new Error(data.message);
            }
        })
        .catch(error => {
            console.error('Update quantity error:', error);
            this.showError('Miktar güncellenirken hata oluştu');
            throw error;
        })
        .finally(() => {
            this.isLoading = false;
            this.hideLoading();
        });
    },
    
    // Remove item from cart
    removeItem: function(cartItemId) {
        if (this.isLoading) return Promise.reject('İşlem devam ediyor');
        
        this.isLoading = true;
        this.showLoading();
        
        const data = {
            cart_item_id: cartItemId,
            csrf_token: KahvePortal.config.csrfToken
        };
        
        return fetch('/api/cart', {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                this.showSuccess(data.message || 'Ürün sepetten kaldırıldı');
                this.loadCart(); // Reload cart to get updated data
                return data;
            } else {
                this.showError(data.message || 'Ürün sepetten kaldırılamadı');
                throw new Error(data.message);
            }
        })
        .catch(error => {
            console.error('Remove item error:', error);
            this.showError('Ürün sepetten kaldırılırken hata oluştu');
            throw error;
        })
        .finally(() => {
            this.isLoading = false;
            this.hideLoading();
        });
    },
    
    // Clear entire cart
    clearCart: function() {
        if (this.isLoading) return Promise.reject('İşlem devam ediyor');
        
        if (window.Swal) {
            return Swal.fire({
                title: 'Sepeti Temizle',
                text: 'Sepetteki tüm ürünler kaldırılacak. Emin misiniz?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Evet, Temizle',
                cancelButtonText: 'İptal'
            }).then((result) => {
                if (result.isConfirmed) {
                    return this.performClearCart();
                }
            });
        } else {
            if (confirm('Sepetteki tüm ürünler kaldırılacak. Emin misiniz?')) {
                return this.performClearCart();
            }
        }
    },
    
    // Perform cart clearing
    performClearCart: function() {
        this.isLoading = true;
        this.showLoading();
        
        return fetch('/api/cart?action=clear', {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                csrf_token: KahvePortal.config.csrfToken
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                this.items = [];
                this.total = 0;
                this.updateCartDisplay();
                this.updateCartBadge(0);
                this.showSuccess(data.message || 'Sepet temizlendi');
                return data;
            } else {
                this.showError(data.message || 'Sepet temizlenemedi');
                throw new Error(data.message);
            }
        })
        .catch(error => {
            console.error('Clear cart error:', error);
            this.showError('Sepet temizlenirken hata oluştu');
            throw error;
        })
        .finally(() => {
            this.isLoading = false;
            this.hideLoading();
        });
    },
    
    // Handle add to cart button clicks
    handleAddToCart: function(event) {
        const button = event.target.closest('[data-add-to-cart]');
        if (!button) return;
        
        event.preventDefault();
        
        const productId = button.getAttribute('data-product-id');
        const quantity = parseInt(button.getAttribute('data-quantity')) || 1;
        
        // Get options from form if exists
        const form = button.closest('form');
        let options = {};
        
        if (form) {
            const formData = new FormData(form);
            options = this.extractOptionsFromForm(formData);
        }
        
        // Disable button temporarily
        button.disabled = true;
        const originalText = button.innerHTML;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Ekleniyor...';
        
        this.addToCart(productId, quantity, options)
            .then(() => {
                // Add success animation
                button.innerHTML = '<i class="fas fa-check"></i> Eklendi!';
                setTimeout(() => {
                    button.innerHTML = originalText;
                    button.disabled = false;
                }, 1500);
            })
            .catch(() => {
                button.innerHTML = originalText;
                button.disabled = false;
            });
    },
    
    // Handle quantity update buttons
    handleQuantityUpdate: function(event) {
        const button = event.target.closest('[data-quantity-action]');
        if (!button) return;
        
        event.preventDefault();
        
        const action = button.getAttribute('data-quantity-action');
        const cartItemId = button.getAttribute('data-cart-item-id');
        const quantityInput = button.parentElement.querySelector('input[type="number"]');
        
        if (!quantityInput) return;
        
        let newQuantity = parseInt(quantityInput.value);
        
        if (action === 'increase') {
            newQuantity++;
        } else if (action === 'decrease') {
            newQuantity--;
        }
        
        if (newQuantity < 1) {
            this.removeItem(cartItemId);
        } else {
            quantityInput.value = newQuantity;
            this.updateQuantity(cartItemId, newQuantity);
        }
    },
    
    // Handle remove item buttons
    handleRemoveItem: function(event) {
        const button = event.target.closest('[data-remove-item]');
        if (!button) return;
        
        event.preventDefault();
        
        const cartItemId = button.getAttribute('data-cart-item-id');
        this.removeItem(cartItemId);
    },
    
    // Handle clear cart button
    handleClearCart: function(event) {
        const button = event.target.closest('[data-clear-cart]');
        if (!button) return;
        
        event.preventDefault();
        this.clearCart();
    },
    
    // Handle checkout button
    handleCheckout: function(event) {
        const button = event.target.closest('[data-checkout]');
        if (!button) return;
        
        event.preventDefault();
        
        if (this.items.length === 0) {
            this.showError('Sepetiniz boş');
            return;
        }
        
        // Redirect to checkout page or show checkout modal
        window.location.href = '/checkout';
    },
    
    // Handle cart dropdown toggle
    handleCartToggle: function(event) {
        const toggle = event.target.closest('[data-cart-toggle]');
        if (!toggle) return;
        
        // Load cart when dropdown is opened
        this.loadCart();
    },
    
    // Extract options from form
    extractOptionsFromForm: function(formData) {
        const options = {};
        
        for (const [key, value] of formData.entries()) {
            if (key.startsWith('option_')) {
                const optionName = key.replace('option_', '');
                options[optionName] = value;
            }
        }
        
        return options;
    },
    
    // Update cart display
    updateCartDisplay: function() {
        this.updateCartItems();
        this.updateCartTotal();
        this.updateCartEmpty();
    },
    
    // Update cart items display
    updateCartItems: function() {
        const containers = document.querySelectorAll('[data-cart-items]');
        
        containers.forEach(container => {
            if (this.items.length === 0) {
                container.innerHTML = this.getEmptyCartHTML();
            } else {
                container.innerHTML = this.items.map(item => this.getCartItemHTML(item)).join('');
            }
        });
    },
    
    // Update cart total display
    updateCartTotal: function() {
        const totalElements = document.querySelectorAll('[data-cart-total]');
        const formattedTotal = KahvePortal.utils.formatCurrency(this.total);
        
        totalElements.forEach(element => {
            element.textContent = formattedTotal;
        });
    },
    
    // Update cart empty state
    updateCartEmpty: function() {
        const emptyElements = document.querySelectorAll('[data-cart-empty]');
        const itemElements = document.querySelectorAll('[data-cart-has-items]');
        
        if (this.items.length === 0) {
            emptyElements.forEach(el => el.style.display = 'block');
            itemElements.forEach(el => el.style.display = 'none');
        } else {
            emptyElements.forEach(el => el.style.display = 'none');
            itemElements.forEach(el => el.style.display = 'block');
        }
    },
    
    // Update cart badge
    updateCartBadge: function(count) {
        const badges = document.querySelectorAll('.cart-badge');
        
        badges.forEach(badge => {
            if (count > 0) {
                badge.textContent = count;
                badge.style.display = 'flex';
                badge.classList.add('animate-pulse');
                setTimeout(() => badge.classList.remove('animate-pulse'), 1000);
            } else {
                badge.style.display = 'none';
            }
        });
    },
    
    // Get cart item HTML
    getCartItemHTML: function(item) {
        const optionsHTML = item.options && item.options.length > 0 
            ? `<div class="small text-muted">${item.options.map(opt => opt.name).join(', ')}</div>`
            : '';
        
        return `
            <div class="cart-item d-flex align-items-center py-2" data-cart-item-id="${item.id}">
                <div class="cart-item-image me-3">
                    <img src="${item.image || '/assets/images/default-coffee.png'}" 
                         alt="${item.name}" 
                         class="rounded" 
                         style="width: 50px; height: 50px; object-fit: cover;">
                </div>
                <div class="cart-item-details flex-grow-1">
                    <div class="cart-item-name fw-bold">${item.name}</div>
                    ${optionsHTML}
                    <div class="cart-item-price text-primary">${KahvePortal.utils.formatCurrency(item.item_total)}</div>
                </div>
                <div class="cart-item-quantity d-flex align-items-center">
                    <button class="btn btn-sm btn-outline-secondary" 
                            data-quantity-action="decrease" 
                            data-cart-item-id="${item.id}">
                        <i class="fas fa-minus"></i>
                    </button>
                    <input type="number" 
                           class="form-control text-center mx-2" 
                           style="width: 60px;" 
                           value="${item.quantity}" 
                           min="1" 
                           readonly>
                    <button class="btn btn-sm btn-outline-secondary" 
                            data-quantity-action="increase" 
                            data-cart-item-id="${item.id}">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
                <div class="cart-item-remove ms-2">
                    <button class="btn btn-sm btn-outline-danger" 
                            data-remove-item 
                            data-cart-item-id="${item.id}">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        `;
    },
    
    // Get empty cart HTML
    getEmptyCartHTML: function() {
        return `
            <div class="text-center py-4">
                <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
                <p class="text-muted">Sepetiniz boş</p>
                <a href="/menu" class="btn btn-primary">
                    <i class="fas fa-coffee me-2"></i>Ürünlere Göz At
                </a>
            </div>
        `;
    },
    
    // Show loading state
    showLoading: function() {
        const loadingElements = document.querySelectorAll('[data-cart-loading]');
        loadingElements.forEach(el => el.style.display = 'block');
    },
    
    // Hide loading state
    hideLoading: function() {
        const loadingElements = document.querySelectorAll('[data-cart-loading]');
        loadingElements.forEach(el => el.style.display = 'none');
    },
    
    // Show success message
    showSuccess: function(message) {
        if (window.KahvePortal && window.KahvePortal.showAlert) {
            KahvePortal.showAlert(message, 'success');
        } else {
            console.log('Success:', message);
        }
    },
    
    // Show error message
    showError: function(message) {
        if (window.KahvePortal && window.KahvePortal.showAlert) {
            KahvePortal.showAlert(message, 'danger');
        } else {
            console.error('Error:', message);
        }
    },
    
    // Get cart count
    getCartCount: function() {
        return this.items.reduce((total, item) => total + item.quantity, 0);
    },
    
    // Get cart total
    getCartTotal: function() {
        return this.total;
    },
    
    // Check if cart is empty
    isEmpty: function() {
        return this.items.length === 0;
    },
    
    // Find item by ID
    findItem: function(cartItemId) {
        return this.items.find(item => item.id == cartItemId);
    },
    
    // Check if product is in cart
    hasProduct: function(productId) {
        return this.items.some(item => item.product_id == productId);
    }
};

// Initialize cart when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    if (typeof window !== 'undefined') {
        Cart.init();
    }
});

// Export for modules if needed
if (typeof module !== 'undefined' && module.exports) {
    module.exports = Cart;
}