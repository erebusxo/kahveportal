/**
 * KahvePortal - Main JavaScript Application
 * assets/js/app.js
 */

// Global app object
window.KahvePortal = {
    config: {
        apiUrl: '/api',
        csrfToken: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        currency: '₺',
        dateFormat: 'dd.mm.yyyy',
        timeFormat: 'HH:mm'
    },
    
    // Initialize the application
    init: function() {
        this.setupEventListeners();
        this.initializeComponents();
        this.checkSession();
        this.setupAjaxHeaders();
        this.initializeTheme();
        console.log('KahvePortal App initialized');
    },
    
    // Set up global event listeners
    setupEventListeners: function() {
        // Handle form submissions with loading states
        document.addEventListener('submit', this.handleFormSubmit.bind(this));
        
        // Handle clicks with confirmation
        document.addEventListener('click', this.handleConfirmClick.bind(this));
        
        // Handle theme toggle
        document.addEventListener('click', this.handleThemeToggle.bind(this));
        
        // Handle auto-save forms
        document.addEventListener('input', this.handleAutoSave.bind(this));
        
        // Handle page visibility changes
        document.addEventListener('visibilitychange', this.handleVisibilityChange.bind(this));
        
        // Handle online/offline status
        window.addEventListener('online', this.handleOnlineStatus.bind(this));
        window.addEventListener('offline', this.handleOfflineStatus.bind(this));
        
        // Handle window resize
        window.addEventListener('resize', this.handleResize.bind(this));
        
        // Handle escape key
        document.addEventListener('keydown', this.handleEscapeKey.bind(this));
    },
    
    // Initialize components
    initializeComponents: function() {
        this.initializeTooltips();
        this.initializePopovers();
        this.initializeModals();
        this.initializeAlerts();
        this.initializeCounters();
        this.initializeLazyLoading();
        this.initializeInfiniteScroll();
    },
    
    // Initialize tooltips
    initializeTooltips: function() {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    },
    
    // Initialize popovers
    initializePopovers: function() {
        const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
        popoverTriggerList.map(function(popoverTriggerEl) {
            return new bootstrap.Popover(popoverTriggerEl);
        });
    },
    
    // Initialize modals
    initializeModals: function() {
        const modals = document.querySelectorAll('.modal');
        modals.forEach(modal => {
            modal.addEventListener('shown.bs.modal', function() {
                const firstInput = this.querySelector('input, textarea, select');
                if (firstInput) firstInput.focus();
            });
        });
    },
    
    // Initialize auto-dismiss alerts
    initializeAlerts: function() {
        const alerts = document.querySelectorAll('.alert[data-auto-dismiss]');
        alerts.forEach(alert => {
            const delay = parseInt(alert.getAttribute('data-auto-dismiss')) || 5000;
            setTimeout(() => {
                const bsAlert = new bootstrap.Alert(alert);
                if (bsAlert) bsAlert.close();
            }, delay);
        });
    },
    
    // Initialize counters with animation
    initializeCounters: function() {
        const counters = document.querySelectorAll('[data-counter]');
        const observerOptions = {
            threshold: 0.5,
            rootMargin: '0px 0px -100px 0px'
        };
        
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    this.animateCounter(entry.target);
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);
        
        counters.forEach(counter => observer.observe(counter));
    },
    
    // Animate counter
    animateCounter: function(element) {
        const target = parseInt(element.getAttribute('data-counter'));
        const duration = parseInt(element.getAttribute('data-duration')) || 2000;
        const step = target / (duration / 16);
        let current = 0;
        
        const updateCounter = () => {
            current += step;
            if (current < target) {
                element.textContent = Math.floor(current).toLocaleString('tr-TR');
                requestAnimationFrame(updateCounter);
            } else {
                element.textContent = target.toLocaleString('tr-TR');
            }
        };
        
        updateCounter();
    },
    
    // Initialize lazy loading
    initializeLazyLoading: function() {
        const images = document.querySelectorAll('img[data-src]');
        const imageObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    img.src = img.getAttribute('data-src');
                    img.removeAttribute('data-src');
                    img.classList.remove('lazy');
                    observer.unobserve(img);
                }
            });
        });
        
        images.forEach(img => imageObserver.observe(img));
    },
    
    // Initialize infinite scroll
    initializeInfiniteScroll: function() {
        const containers = document.querySelectorAll('[data-infinite-scroll]');
        containers.forEach(container => {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        this.loadMoreContent(container);
                    }
                });
            });
            
            const trigger = container.querySelector('.infinite-scroll-trigger');
            if (trigger) observer.observe(trigger);
        });
    },
    
    // Load more content for infinite scroll
    loadMoreContent: function(container) {
        const url = container.getAttribute('data-infinite-scroll');
        const page = parseInt(container.getAttribute('data-page')) || 1;
        
        fetch(`${url}?page=${page + 1}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.html) {
                    container.insertAdjacentHTML('beforeend', data.html);
                    container.setAttribute('data-page', page + 1);
                    
                    if (!data.hasMore) {
                        const trigger = container.querySelector('.infinite-scroll-trigger');
                        if (trigger) trigger.remove();
                    }
                }
            })
            .catch(error => console.error('Infinite scroll error:', error));
    },
    
    // Handle form submissions
    handleFormSubmit: function(event) {
        const form = event.target;
        if (!form.matches('form[data-ajax]')) return;
        
        event.preventDefault();
        this.submitFormAjax(form);
    },
    
    // Submit form via AJAX
    submitFormAjax: function(form) {
        const submitBtn = form.querySelector('[type="submit"]');
        const originalText = submitBtn?.textContent;
        
        // Show loading state
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Gönderiliyor...';
        }
        
        const formData = new FormData(form);
        const url = form.getAttribute('action') || window.location.href;
        
        fetch(url, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                this.showAlert(data.message || 'İşlem başarılı', 'success');
                
                // Handle redirect
                if (data.redirect) {
                    setTimeout(() => {
                        window.location.href = data.redirect;
                    }, 1000);
                }
                
                // Reset form if specified
                if (data.resetForm !== false) {
                    form.reset();
                }
                
                // Trigger custom event
                form.dispatchEvent(new CustomEvent('form:success', { detail: data }));
            } else {
                this.showAlert(data.message || 'Bir hata oluştu', 'danger');
                form.dispatchEvent(new CustomEvent('form:error', { detail: data }));
            }
        })
        .catch(error => {
            console.error('Form submission error:', error);
            this.showAlert('Bağlantı hatası oluştu', 'danger');
        })
        .finally(() => {
            // Restore button state
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        });
    },
    
    // Handle confirmation clicks
    handleConfirmClick: function(event) {
        const element = event.target.closest('[data-confirm]');
        if (!element) return;
        
        event.preventDefault();
        const message = element.getAttribute('data-confirm');
        
        if (window.Swal) {
            Swal.fire({
                title: 'Emin misiniz?',
                text: message,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Evet',
                cancelButtonText: 'İptal'
            }).then((result) => {
                if (result.isConfirmed) {
                    this.executeAction(element);
                }
            });
        } else {
            if (confirm(message)) {
                this.executeAction(element);
            }
        }
    },
    
    // Execute action after confirmation
    executeAction: function(element) {
        if (element.tagName === 'A') {
            window.location.href = element.href;
        } else if (element.tagName === 'BUTTON') {
            if (element.form) {
                element.form.submit();
            } else {
                element.click();
            }
        }
    },
    
    // Handle theme toggle
    handleThemeToggle: function(event) {
        if (!event.target.matches('[data-theme-toggle]')) return;
        
        event.preventDefault();
        this.toggleTheme();
    },
    
    // Toggle theme
    toggleTheme: function() {
        const currentTheme = document.documentElement.getAttribute('data-bs-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        
        document.documentElement.setAttribute('data-bs-theme', newTheme);
        localStorage.setItem('theme', newTheme);
        
        // Update theme icons
        this.updateThemeIcons(newTheme);
        
        // Save to server if user is logged in
        if (this.config.csrfToken) {
            this.saveThemePreference(newTheme);
        }
    },
    
    // Update theme icons
    updateThemeIcons: function(theme) {
        const icons = document.querySelectorAll('[data-theme-icon]');
        icons.forEach(icon => {
            if (theme === 'dark') {
                icon.className = icon.className.replace('fa-moon', 'fa-sun');
            } else {
                icon.className = icon.className.replace('fa-sun', 'fa-moon');
            }
        });
    },
    
    // Save theme preference to server
    saveThemePreference: function(theme) {
        fetch('/api/user-preferences', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': this.config.csrfToken
            },
            body: JSON.stringify({
                action: 'update_theme',
                theme: theme
            })
        }).catch(error => console.error('Theme save error:', error));
    },
    
    // Initialize theme
    initializeTheme: function() {
        const savedTheme = localStorage.getItem('theme');
        const systemTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        const theme = savedTheme || systemTheme;
        
        document.documentElement.setAttribute('data-bs-theme', theme);
        this.updateThemeIcons(theme);
        
        // Listen for system theme changes
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
            if (!localStorage.getItem('theme')) {
                const newTheme = e.matches ? 'dark' : 'light';
                document.documentElement.setAttribute('data-bs-theme', newTheme);
                this.updateThemeIcons(newTheme);
            }
        });
    },
    
    // Handle auto-save
    handleAutoSave: function(event) {
        const element = event.target;
        const form = element.closest('form[data-auto-save]');
        if (!form) return;
        
        clearTimeout(form.autoSaveTimeout);
        form.autoSaveTimeout = setTimeout(() => {
            this.autoSaveForm(form);
        }, 2000);
    },
    
    // Auto-save form
    autoSaveForm: function(form) {
        const formData = new FormData(form);
        const url = form.getAttribute('data-auto-save');
        
        fetch(url, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                this.showAutoSaveIndicator(form);
            }
        })
        .catch(error => console.error('Auto-save error:', error));
    },
    
    // Show auto-save indicator
    showAutoSaveIndicator: function(form) {
        let indicator = form.querySelector('.auto-save-indicator');
        if (!indicator) {
            indicator = document.createElement('div');
            indicator.className = 'auto-save-indicator';
            indicator.innerHTML = '<i class="fas fa-check text-success"></i> Kaydedildi';
            form.appendChild(indicator);
        }
        
        indicator.style.opacity = '1';
        setTimeout(() => {
            indicator.style.opacity = '0';
        }, 2000);
    },
    
    // Handle visibility change
    handleVisibilityChange: function() {
        if (document.hidden) {
            this.pauseRealTimeUpdates();
        } else {
            this.resumeRealTimeUpdates();
        }
    },
    
    // Handle online status
    handleOnlineStatus: function() {
        this.showAlert('İnternet bağlantısı geri geldi', 'success');
        this.resumeRealTimeUpdates();
    },
    
    // Handle offline status
    handleOfflineStatus: function() {
        this.showAlert('İnternet bağlantısı kesildi', 'warning');
        this.pauseRealTimeUpdates();
    },
    
    // Handle window resize
    handleResize: function() {
        // Update any responsive components
        this.updateResponsiveComponents();
    },
    
    // Handle escape key
    handleEscapeKey: function(event) {
        if (event.key === 'Escape') {
            // Close modals, dropdowns, etc.
            const openModal = document.querySelector('.modal.show');
            if (openModal) {
                const modal = bootstrap.Modal.getInstance(openModal);
                if (modal) modal.hide();
            }
        }
    },
    
    // Update responsive components
    updateResponsiveComponents: function() {
        // Update charts, tables, etc. on resize
        window.dispatchEvent(new Event('resize-update'));
    },
    
    // Setup AJAX headers
    setupAjaxHeaders: function() {
        // Set default headers for fetch requests
        const originalFetch = window.fetch;
        window.fetch = function(url, options = {}) {
            options.headers = options.headers || {};
            if (KahvePortal.config.csrfToken) {
                options.headers['X-CSRF-Token'] = KahvePortal.config.csrfToken;
            }
            options.headers['X-Requested-With'] = 'XMLHttpRequest';
            
            return originalFetch(url, options);
        };
    },
    
    // Check session status
    checkSession: function() {
        if (!this.config.csrfToken) return;
        
        setInterval(() => {
            fetch('/api/session-check')
                .then(response => response.json())
                .then(data => {
                    if (!data.valid) {
                        this.handleSessionExpired();
                    }
                })
                .catch(() => {
                    // Session check failed, possibly offline
                });
        }, 60000); // Check every minute
    },
    
    // Handle session expiration
    handleSessionExpired: function() {
        if (window.Swal) {
            Swal.fire({
                title: 'Oturum Süresi Doldu',
                text: 'Güvenliğiniz için oturumunuz sonlandırıldı. Lütfen tekrar giriş yapın.',
                icon: 'warning',
                allowOutsideClick: false,
                allowEscapeKey: false,
                confirmButtonText: 'Giriş Yap'
            }).then(() => {
                window.location.href = '/login';
            });
        } else {
            alert('Oturum süresi doldu. Tekrar giriş yapmanız gerekiyor.');
            window.location.href = '/login';
        }
    },
    
    // Pause real-time updates
    pauseRealTimeUpdates: function() {
        if (this.realTimeInterval) {
            clearInterval(this.realTimeInterval);
        }
    },
    
    // Resume real-time updates
    resumeRealTimeUpdates: function() {
        this.startRealTimeUpdates();
    },
    
    // Start real-time updates
    startRealTimeUpdates: function() {
        this.realTimeInterval = setInterval(() => {
            this.updateRealTimeData();
        }, 30000); // Update every 30 seconds
    },
    
    // Update real-time data
    updateRealTimeData: function() {
        // Update notifications, cart count, etc.
        this.updateNotificationCount();
        this.updateCartCount();
    },
    
    // Update notification count
    updateNotificationCount: function() {
        fetch('/api/notifications?unread_count=true')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const badges = document.querySelectorAll('.notification-badge');
                    badges.forEach(badge => {
                        if (data.unread_count > 0) {
                            badge.textContent = data.unread_count;
                            badge.style.display = 'flex';
                        } else {
                            badge.style.display = 'none';
                        }
                    });
                }
            })
            .catch(error => console.error('Notification update error:', error));
    },
    
    // Update cart count
    updateCartCount: function() {
        fetch('/api/cart')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const badges = document.querySelectorAll('.cart-badge');
                    badges.forEach(badge => {
                        if (data.summary.total_items > 0) {
                            badge.textContent = data.summary.total_items;
                            badge.style.display = 'flex';
                        } else {
                            badge.style.display = 'none';
                        }
                    });
                }
            })
            .catch(error => console.error('Cart update error:', error));
    },
    
    // Show alert
    showAlert: function(message, type = 'info', duration = 5000) {
        if (window.Swal) {
            const icon = type === 'danger' ? 'error' : type;
            Swal.fire({
                icon: icon,
                title: message,
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: duration
            });
        } else {
            // Fallback to browser alert
            alert(message);
        }
    },
    
    // Utility functions
    utils: {
        // Format currency
        formatCurrency: function(amount) {
            return new Intl.NumberFormat('tr-TR', {
                style: 'currency',
                currency: 'TRY'
            }).format(amount);
        },
        
        // Format date
        formatDate: function(date) {
            return new Intl.DateTimeFormat('tr-TR').format(new Date(date));
        },
        
        // Format time
        formatTime: function(date) {
            return new Intl.DateTimeFormat('tr-TR', {
                hour: '2-digit',
                minute: '2-digit'
            }).format(new Date(date));
        },
        
        // Debounce function
        debounce: function(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        },
        
        // Throttle function
        throttle: function(func, limit) {
            let inThrottle;
            return function() {
                const args = arguments;
                const context = this;
                if (!inThrottle) {
                    func.apply(context, args);
                    inThrottle = true;
                    setTimeout(() => inThrottle = false, limit);
                }
            };
        },
        
        // Generate random ID
        generateId: function() {
            return '_' + Math.random().toString(36).substr(2, 9);
        },
        
        // Validate email
        validateEmail: function(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        },
        
        // Validate phone
        validatePhone: function(phone) {
            const re = /^[0-9]{10,11}$/;
            return re.test(phone.replace(/\s/g, ''));
        }
    }
};

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    KahvePortal.init();
});

// Export for modules if needed
if (typeof module !== 'undefined' && module.exports) {
    module.exports = KahvePortal;
}