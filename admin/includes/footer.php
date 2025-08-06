        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../assets/js/app.js"></script>
    <script src="../assets/js/admin.js"></script>
    
    <script>
        // Sidebar Toggle
        document.getElementById('sidebar-toggle').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('main-content');
            
            if (window.innerWidth > 768) {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');
            } else {
                sidebar.classList.toggle('show');
            }
        });

        // Close sidebar on mobile when clicking outside
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 768) {
                const sidebar = document.getElementById('sidebar');
                const sidebarToggle = document.getElementById('sidebar-toggle');
                
                if (!sidebar.contains(e.target) && !sidebarToggle.contains(e.target)) {
                    sidebar.classList.remove('show');
                }
            }
        });

        // Theme Toggle
        function toggleTheme() {
            const currentTheme = document.documentElement.getAttribute('data-bs-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            
            document.documentElement.setAttribute('data-bs-theme', newTheme);
            
            // Save theme preference
            fetch('../api/user-preferences.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'update_theme',
                    theme: newTheme,
                    csrf_token: '<?php echo $_SESSION[CSRF_TOKEN_NAME] ?? ''; ?>'
                })
            });
            
            // Reload page to apply theme styles
            setTimeout(() => {
                window.location.reload();
            }, 200);
        }

        // Load notifications
        function loadNotifications() {
            fetch('../api/notifications.php?limit=5')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const notificationList = document.getElementById('notification-list');
                        
                        if (data.notifications.length === 0) {
                            notificationList.innerHTML = '<div class="text-center text-muted">Yeni bildirim yok</div>';
                        } else {
                            let html = '';
                            data.notifications.forEach(notification => {
                                const isRead = notification.is_read ? 'text-muted' : '';
                                const icon = getNotificationIcon(notification.type);
                                
                                html += `
                                    <div class="notification-item mb-2 p-2 rounded ${isRead}" style="font-size: 0.85rem;">
                                        <div class="d-flex">
                                            <div class="me-2">
                                                <i class="${icon}"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <div class="fw-bold">${notification.title}</div>
                                                <div class="small">${notification.message}</div>
                                                <div class="small text-muted">${formatDate(notification.created_at)}</div>
                                            </div>
                                        </div>
                                    </div>
                                `;
                            });
                            notificationList.innerHTML = html;
                        }
                    }
                })
                .catch(error => {
                    console.error('Bildirimler yüklenirken hata:', error);
                    document.getElementById('notification-list').innerHTML = '<div class="text-center text-danger">Bildirimler yüklenemedi</div>';
                });
        }

        function getNotificationIcon(type) {
            const icons = {
                'info': 'fas fa-info-circle text-info',
                'success': 'fas fa-check-circle text-success',
                'warning': 'fas fa-exclamation-triangle text-warning',
                'error': 'fas fa-exclamation-circle text-danger',
                'order': 'fas fa-shopping-cart text-primary',
                'balance': 'fas fa-wallet text-warning'
            };
            return icons[type] || 'fas fa-bell text-secondary';
        }

        function formatDate(dateString) {
            const date = new Date(dateString);
            const now = new Date();
            const diff = (now - date) / 1000; // seconds

            if (diff < 60) {
                return 'Az önce';
            } else if (diff < 3600) {
                return Math.floor(diff / 60) + ' dakika önce';
            } else if (diff < 86400) {
                return Math.floor(diff / 3600) + ' saat önce';
            } else {
                return date.toLocaleDateString('tr-TR');
            }
        }

        // Load notifications when dropdown is opened
        document.addEventListener('DOMContentLoaded', function() {
            const notificationDropdown = document.querySelector('[data-bs-toggle="dropdown"]');
            if (notificationDropdown) {
                notificationDropdown.addEventListener('shown.bs.dropdown', loadNotifications);
            }
        });

        // Auto-refresh notifications every 30 seconds
        setInterval(function() {
            // Update notification count in badge
            fetch('../api/notifications.php?unread_count=true')
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
                });
        }, 30000);

        // Session timeout warning
        let sessionWarningShown = false;
        setInterval(function() {
            const sessionStart = <?php echo $_SESSION['last_activity'] ?? time(); ?>;
            const sessionLifetime = <?php echo SESSION_LIFETIME; ?>;
            const currentTime = Math.floor(Date.now() / 1000);
            const timeLeft = sessionLifetime - (currentTime - sessionStart);
            
            // Warn 5 minutes before timeout
            if (timeLeft <= 300 && !sessionWarningShown) {
                sessionWarningShown = true;
                Swal.fire({
                    title: 'Oturum Uyarısı',
                    text: 'Oturumunuz yakında sona erecek. Devam etmek istiyor musunuz?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Devam Et',
                    cancelButtonText: 'Çıkış Yap'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Extend session
                        fetch('../api/extend-session.php', {method: 'POST'});
                        sessionWarningShown = false;
                    } else {
                        window.location.href = '../auth.php?action=logout';
                    }
                });
            }
        }, 60000); // Check every minute

        // Global error handler
        window.addEventListener('error', function(e) {
            console.error('JavaScript Error:', e.error);
        });

        // Confirm dialogs for dangerous actions
        document.addEventListener('click', function(e) {
            if (e.target.matches('[data-confirm]')) {
                e.preventDefault();
                const message = e.target.getAttribute('data-confirm');
                
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
                        if (e.target.tagName === 'A') {
                            window.location.href = e.target.href;
                        } else if (e.target.tagName === 'BUTTON') {
                            e.target.form.submit();
                        }
                    }
                });
            }
        });

        // Auto-save forms
        function setupAutoSave(formId, saveUrl) {
            const form = document.getElementById(formId);
            if (!form) return;
            
            let saveTimeout;
            const inputs = form.querySelectorAll('input, textarea, select');
            
            inputs.forEach(input => {
                input.addEventListener('input', function() {
                    clearTimeout(saveTimeout);
                    saveTimeout = setTimeout(() => {
                        const formData = new FormData(form);
                        fetch(saveUrl, {
                            method: 'POST',
                            body: formData
                        });
                    }, 2000);
                });
            });
        }

        // Page loading indicator
        function showLoading() {
            document.body.style.cursor = 'wait';
        }

        function hideLoading() {
            document.body.style.cursor = 'default';
        }

        // Show loading on form submissions and navigation
        document.addEventListener('DOMContentLoaded', function() {
            // Form submissions
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', showLoading);
            });
            
            // Navigation links
            document.querySelectorAll('a:not([href^="#"]):not([target="_blank"])').forEach(link => {
                link.addEventListener('click', showLoading);
            });
        });

        // Hide loading when page is fully loaded
        window.addEventListener('load', hideLoading);
    </script>
    
    <?php if (isset($custom_js)): ?>
        <!-- Custom JavaScript -->
        <?php echo $custom_js; ?>
    <?php endif; ?>
</body>
</html>