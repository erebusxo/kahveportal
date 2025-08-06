/**
 * KahvePortal - Notification System
 * assets/js/notifications.js
 */

window.NotificationSystem = {
    notifications: [],
    unreadCount: 0,
    isLoading: false,
    refreshInterval: null,
    
    // Initialize notification system
    init: function() {
        this.setupEventListeners();
        this.loadNotifications();
        this.startAutoRefresh();
        this.setupPermissions();
        console.log('Notification System initialized');
    },
    
    // Setup event listeners
    setupEventListeners: function() {
        // Notification dropdown toggle
        document.addEventListener('click', this.handleDropdownToggle.bind(this));
        
        // Mark as read buttons
        document.addEventListener('click', this.handleMarkAsRead.bind(this));
        
        // Mark all as read button
        document.addEventListener('click', this.handleMarkAllAsRead.bind(this));
        
        // Delete notification buttons
        document.addEventListener('click', this.handleDeleteNotification.bind(this));
        
        // Real-time notification clicks
        document.addEventListener('click', this.handleNotificationClick.bind(this));
        
        // Listen for new notifications via WebSocket or Server-Sent Events
        this.setupRealTimeListeners();
    },
    
    // Setup real-time listeners
    setupRealTimeListeners: function() {
        // Check for Server-Sent Events support
        if (typeof EventSource !== 'undefined') {
            this.setupSSE();
        } else {
            // Fallback to polling
            this.setupPolling();
        }
        
        // Listen for page visibility changes
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                this.loadNotifications();
            }
        });
    },
    
    // Setup Server-Sent Events
    setupSSE: function() {
        try {
            this.eventSource = new EventSource('/api/notifications/stream');
            
            this.eventSource.onmessage = (event) => {
                try {
                    const data = JSON.parse(event.data);
                    this.handleNewNotification(data);
                } catch (e) {
                    console.error('SSE message parse error:', e);
                }
            };
            
            this.eventSource.onerror = (error) => {
                console.error('SSE error:', error);
                this.eventSource.close();
                // Fallback to polling
                this.setupPolling();
            };
        } catch (e) {
            console.error('SSE setup error:', e);
            this.setupPolling();
        }
    },
    
    // Setup polling as fallback
    setupPolling: function() {
        this.pollingInterval = setInterval(() => {
            this.checkForNewNotifications();
        }, 30000); // Check every 30 seconds
    },
    
    // Check for new notifications
    checkForNewNotifications: function() {
        const lastCheck = localStorage.getItem('lastNotificationCheck');
        const url = lastCheck ? `/api/notifications?since=${lastCheck}` : '/api/notifications?limit=5';
        
        fetch(url)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.notifications.length > 0) {
                    data.notifications.forEach(notification => {
                        if (!notification.is_read) {
                            this.handleNewNotification(notification);
                        }
                    });
                }
                localStorage.setItem('lastNotificationCheck', new Date().toISOString());
            })
            .catch(error => console.error('Notification check error:', error));
    },
    
    // Handle new notification
    handleNewNotification: function(notification) {
        // Add to notifications array
        this.notifications.unshift(notification);
        this.unreadCount++;
        
        // Update UI
        this.updateNotificationBadge();
        this.updateNotificationDropdown();
        
        // Show desktop notification if permitted
        this.showDesktopNotification(notification);
        
        // Play notification sound
        this.playNotificationSound();
        
        // Show in-app notification
        this.showInAppNotification(notification);
    },
    
    // Setup notification permissions
    setupPermissions: function() {
        if ('Notification' in window && Notification.permission === 'default') {
            // Request permission on first user interaction
            document.addEventListener('click', () => {
                this.requestPermission();
            }, { once: true });
        }
    },
    
    // Request notification permission
    requestPermission: function() {
        if ('Notification' in window) {
            Notification.requestPermission().then(permission => {
                console.log('Notification permission:', permission);
            });
        }
    },
    
    // Load notifications from server
    loadNotifications: function(limit = 10) {
        if (this.isLoading) return;
        
        this.isLoading = true;
        
        fetch(`/api/notifications?limit=${limit}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.notifications = data.notifications;
                    this.unreadCount = data.unread_count;
                    this.updateNotificationBadge();
                    this.updateNotificationDropdown();
                }
            })
            .catch(error => {
                console.error('Notification load error:', error);
            })
            .finally(() => {
                this.isLoading = false;
            });
    },
    
    // Update notification badge
    updateNotificationBadge: function() {
        const badges = document.querySelectorAll('.notification-badge');
        
        badges.forEach(badge => {
            if (this.unreadCount > 0) {
                badge.textContent = this.unreadCount > 99 ? '99+' : this.unreadCount;
                badge.style.display = 'flex';
                badge.classList.add('animate-pulse');
                setTimeout(() => badge.classList.remove('animate-pulse'), 1000);
            } else {
                badge.style.display = 'none';
            }
        });
    },
    
    // Update notification dropdown
    updateNotificationDropdown: function() {
        const containers = document.querySelectorAll('.notification-dropdown-content');
        
        containers.forEach(container => {
            if (this.notifications.length === 0) {
                container.innerHTML = this.getEmptyNotificationsHTML();
            } else {
                container.innerHTML = this.notifications.slice(0, 5).map(notification => 
                    this.getNotificationHTML(notification)
                ).join('');
            }
        });
    },
    
    // Get notification HTML
    getNotificationHTML: function(notification) {
        const icon = this.getNotificationIcon(notification.type);
        const timeAgo = this.getTimeAgo(notification.created_at);
        const readClass = notification.is_read ? 'notification-read' : 'notification-unread';
        
        return `
            <div class="notification-item ${readClass}" data-notification-id="${notification.id}">
                <div class="notification-content d-flex">
                    <div class="notification-icon me-3">
                        <i class="${icon}"></i>
                    </div>
                    <div class="notification-body flex-grow-1">
                        <div class="notification-title fw-bold">${notification.title}</div>
                        <div class="notification-message text-muted small">${notification.message}</div>
                        <div class="notification-time text-muted small">${timeAgo}</div>
                    </div>
                    <div class="notification-actions">
                        ${!notification.is_read ? `
                            <button class="btn btn-sm btn-link text-primary" 
                                    data-mark-read="${notification.id}"
                                    title="Okundu olarak işaretle">
                                <i class="fas fa-check"></i>
                            </button>
                        ` : ''}
                        <button class="btn btn-sm btn-link text-danger" 
                                data-delete-notification="${notification.id}"
                                title="Sil">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
                ${notification.action_url ? `
                    <div class="notification-action mt-2">
                        <a href="${notification.action_url}" class="btn btn-sm btn-outline-primary">
                            Detayı Görüntüle
                        </a>
                    </div>
                ` : ''}
            </div>
        `;
    },
    
    // Get empty notifications HTML
    getEmptyNotificationsHTML: function() {
        return `
            <div class="text-center py-4">
                <i class="fas fa-bell-slash fa-3x text-muted mb-3"></i>
                <p class="text-muted">Yeni bildirim yok</p>
            </div>
        `;
    },
    
    // Get notification icon based on type
    getNotificationIcon: function(type) {
        const icons = {
            'info': 'fas fa-info-circle text-info',
            'success': 'fas fa-check-circle text-success',
            'warning': 'fas fa-exclamation-triangle text-warning',
            'error': 'fas fa-exclamation-circle text-danger',
            'order': 'fas fa-shopping-cart text-primary',
            'balance': 'fas fa-wallet text-warning',
            'system': 'fas fa-cog text-secondary'
        };
        
        return icons[type] || 'fas fa-bell text-secondary';
    },
    
    // Get time ago string
    getTimeAgo: function(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const diff = (now - date) / 1000; // seconds
        
        if (diff < 60) {
            return 'Az önce';
        } else if (diff < 3600) {
            const minutes = Math.floor(diff / 60);
            return `${minutes} dakika önce`;
        } else if (diff < 86400) {
            const hours = Math.floor(diff / 3600);
            return `${hours} saat önce`;
        } else if (diff < 604800) {
            const days = Math.floor(diff / 86400);
            return `${days} gün önce`;
        } else {
            return date.toLocaleDateString('tr-TR');
        }
    },
    
    // Handle dropdown toggle
    handleDropdownToggle: function(event) {
        const toggle = event.target.closest('[data-notification-toggle]');
        if (!toggle) return;
        
        // Load notifications when dropdown is opened
        this.loadNotifications();
    },
    
    // Handle mark as read
    handleMarkAsRead: function(event) {
        const button = event.target.closest('[data-mark-read]');
        if (!button) return;
        
        event.preventDefault();
        
        const notificationId = button.getAttribute('data-mark-read');
        this.markAsRead(notificationId);
    },
    
    // Handle mark all as read
    handleMarkAllAsRead: function(event) {
        const button = event.target.closest('[data-mark-all-read]');
        if (!button) return;
        
        event.preventDefault();
        this.markAllAsRead();
    },
    
    // Handle delete notification
    handleDeleteNotification: function(event) {
        const button = event.target.closest('[data-delete-notification]');
        if (!button) return;
        
        event.preventDefault();
        
        const notificationId = button.getAttribute('data-delete-notification');
        this.deleteNotification(notificationId);
    },
    
    // Handle notification click
    handleNotificationClick: function(event) {
        const notification = event.target.closest('.notification-item');
        if (!notification) return;
        
        const notificationId = notification.getAttribute('data-notification-id');
        const actionUrl = notification.querySelector('a')?.href;
        
        // Mark as read if not already read
        if (notification.classList.contains('notification-unread')) {
            this.markAsRead(notificationId);
        }
        
        // Navigate to action URL if exists
        if (actionUrl && !event.target.closest('.notification-actions')) {
            window.location.href = actionUrl;
        }
    },
    
    // Mark notification as read
    markAsRead: function(notificationId) {
        const data = {
            notification_id: notificationId,
            csrf_token: KahvePortal.config.csrfToken
        };
        
        fetch('/api/notifications', {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update local notification
                const notification = this.notifications.find(n => n.id == notificationId);
                if (notification && !notification.is_read) {
                    notification.is_read = true;
                    this.unreadCount--;
                    this.updateNotificationBadge();
                    this.updateNotificationDropdown();
                }
            }
        })
        .catch(error => {
            console.error('Mark as read error:', error);
        });
    },
    
    // Mark all notifications as read
    markAllAsRead: function() {
        const data = {
            mark_all: true,
            csrf_token: KahvePortal.config.csrfToken
        };
        
        fetch('/api/notifications', {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update local notifications
                this.notifications.forEach(notification => {
                    notification.is_read = true;
                });
                this.unreadCount = 0;
                this.updateNotificationBadge();
                this.updateNotificationDropdown();
                
                // Show success message
                this.showMessage('Tüm bildirimler okundu olarak işaretlendi', 'success');
            }
        })
        .catch(error => {
            console.error('Mark all as read error:', error);
            this.showMessage('Bildirimler güncellenirken hata oluştu', 'error');
        });
    },
    
    // Delete notification
    deleteNotification: function(notificationId) {
        if (window.Swal) {
            Swal.fire({
                title: 'Bildirimi Sil',
                text: 'Bu bildirimi silmek istediğinizden emin misiniz?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Evet, Sil',
                cancelButtonText: 'İptal'
            }).then((result) => {
                if (result.isConfirmed) {
                    this.performDeleteNotification(notificationId);
                }
            });
        } else {
            if (confirm('Bu bildirimi silmek istediğinizden emin misiniz?')) {
                this.performDeleteNotification(notificationId);
            }
        }
    },
    
    // Perform notification deletion
    performDeleteNotification: function(notificationId) {
        const data = {
            notification_id: notificationId,
            csrf_token: KahvePortal.config.csrfToken
        };
        
        fetch('/api/notifications', {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Remove from local notifications
                const index = this.notifications.findIndex(n => n.id == notificationId);
                if (index !== -1) {
                    const notification = this.notifications[index];
                    if (!notification.is_read) {
                        this.unreadCount--;
                    }
                    this.notifications.splice(index, 1);
                    this.updateNotificationBadge();
                    this.updateNotificationDropdown();
                }
                
                this.showMessage('Bildirim silindi', 'success');
            }
        })
        .catch(error => {
            console.error('Delete notification error:', error);
            this.showMessage('Bildirim silinirken hata oluştu', 'error');
        });
    },
    
    // Show desktop notification
    showDesktopNotification: function(notification) {
        if ('Notification' in window && Notification.permission === 'granted') {
            const options = {
                body: notification.message,
                icon: '/assets/images/logo.png',
                tag: notification.id,
                requireInteraction: notification.type === 'error' || notification.type === 'warning'
            };
            
            const n = new Notification(notification.title, options);
            
            n.onclick = function() {
                window.focus();
                if (notification.action_url) {
                    window.location.href = notification.action_url;
                }
                n.close();
            };
            
            // Auto close after 5 seconds
            setTimeout(() => n.close(), 5000);
        }
    },
    
    // Play notification sound
    playNotificationSound: function() {
        try {
            const audio = new Audio('/assets/sounds/notification.mp3');
            audio.volume = 0.3;
            audio.play().catch(error => {
                console.log('Notification sound play failed:', error);
            });
        } catch (error) {
            console.log('Notification sound not available:', error);
        }
    },
    
    // Show in-app notification toast
    showInAppNotification: function(notification) {
        const container = document.querySelector('.toast-container') || this.createToastContainer();
        
        const toast = document.createElement('div');
        toast.className = 'toast align-items-center text-white border-0';
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'assertive');
        toast.setAttribute('aria-atomic', 'true');
        toast.setAttribute('data-bs-delay', '5000');
        
        // Set background color based on type
        const bgClass = this.getToastBgClass(notification.type);
        toast.classList.add(bgClass);
        
        toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">
                    <strong>${notification.title}</strong><br>
                    ${notification.message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;
        
        container.appendChild(toast);
        
        // Initialize and show toast
        const bsToast = new bootstrap.Toast(toast);
        bsToast.show();
        
        // Remove from DOM after hide
        toast.addEventListener('hidden.bs.toast', () => {
            container.removeChild(toast);
        });
    },
    
    // Create toast container
    createToastContainer: function() {
        const container = document.createElement('div');
        container.className = 'toast-container position-fixed top-0 end-0 p-3';
        container.style.zIndex = '9999';
        document.body.appendChild(container);
        return container;
    },
    
    // Get toast background class
    getToastBgClass: function(type) {
        const classes = {
            'success': 'bg-success',
            'error': 'bg-danger',
            'warning': 'bg-warning',
            'info': 'bg-info',
            'order': 'bg-primary',
            'balance': 'bg-warning'
        };
        
        return classes[type] || 'bg-secondary';
    },
    
    // Show message
    showMessage: function(message, type = 'info') {
        if (window.KahvePortal && window.KahvePortal.showAlert) {
            KahvePortal.showAlert(message, type);
        }
    },
    
    // Start auto refresh
    startAutoRefresh: function() {
        this.refreshInterval = setInterval(() => {
            if (!document.hidden) {
                this.loadNotifications();
            }
        }, 60000); // Refresh every minute
    },
    
    // Stop auto refresh
    stopAutoRefresh: function() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
            this.refreshInterval = null;
        }
    },
    
    // Get unread count
    getUnreadCount: function() {
        return this.unreadCount;
    },
    
    // Get all notifications
    getAllNotifications: function() {
        return this.notifications;
    },
    
    // Clear all notifications
    clearAll: function() {
        this.notifications = [];
        this.unreadCount = 0;
        this.updateNotificationBadge();
        this.updateNotificationDropdown();
    },
    
    // Cleanup
    destroy: function() {
        this.stopAutoRefresh();
        
        if (this.eventSource) {
            this.eventSource.close();
        }
        
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
        }
    }
};

// Initialize notification system when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    if (window.KahvePortal && window.KahvePortal.config.csrfToken) {
        NotificationSystem.init();
    }
});

// Cleanup on page unload
window.addEventListener('beforeunload', function() {
    if (window.NotificationSystem) {
        NotificationSystem.destroy();
    }
});

// Export for modules if needed
if (typeof module !== 'undefined' && module.exports) {
    module.exports = NotificationSystem;
}