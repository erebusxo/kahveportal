/**
 * KahvePortal - Admin JavaScript Functions
 * assets/js/admin.js
 */

window.AdminPanel = {
    // Initialize admin panel
    init: function() {
        this.setupSidebar();
        this.setupDataTables();
        this.setupCharts();
        this.setupFormValidation();
        this.setupFileUploads();
        this.setupDatePickers();
        this.setupRealTimeUpdates();
        this.setupKeyboardShortcuts();
        console.log('Admin Panel initialized');
    },
    
    // Setup sidebar functionality
    setupSidebar: function() {
        const sidebarToggle = document.getElementById('sidebar-toggle');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('main-content');
        
        if (sidebarToggle && sidebar && mainContent) {
            sidebarToggle.addEventListener('click', function() {
                if (window.innerWidth > 768) {
                    sidebar.classList.toggle('collapsed');
                    mainContent.classList.toggle('expanded');
                    
                    // Save state to localStorage
                    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
                } else {
                    sidebar.classList.toggle('show');
                }
            });
            
            // Restore sidebar state
            const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
            if (isCollapsed && window.innerWidth > 768) {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
            }
        }
        
        // Auto-hide sidebar on mobile when clicking outside
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 768 && sidebar && !sidebar.contains(e.target) && !sidebarToggle.contains(e.target)) {
                sidebar.classList.remove('show');
            }
        });
        
        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768 && sidebar) {
                sidebar.classList.remove('show');
            }
        });
    },
    
    // Setup data tables
    setupDataTables: function() {
        const tables = document.querySelectorAll('[data-table]');
        
        tables.forEach(table => {
            this.initializeDataTable(table);
        });
    },
    
    // Initialize individual data table
    initializeDataTable: function(table) {
        const options = {
            responsive: true,
            ordering: true,
            searching: true,
            paging: true,
            info: true,
            autoWidth: false,
            language: {
                url: '/assets/js/datatables-turkish.json'
            }
        };
        
        // Get custom options from data attributes
        const customOptions = table.getAttribute('data-table-options');
        if (customOptions) {
            try {
                Object.assign(options, JSON.parse(customOptions));
            } catch (e) {
                console.error('Invalid table options:', e);
            }
        }
        
        // Initialize DataTable if available
        if (typeof $.fn.DataTable !== 'undefined') {
            $(table).DataTable(options);
        } else {
            // Fallback: basic table enhancements
            this.enhanceTable(table);
        }
    },
    
    // Enhance table without DataTables
    enhanceTable: function(table) {
        // Add search functionality
        this.addTableSearch(table);
        
        // Add sorting functionality
        this.addTableSorting(table);
        
        // Add row selection
        this.addRowSelection(table);
    },
    
    // Add table search
    addTableSearch: function(table) {
        const searchContainer = table.parentElement.querySelector('.table-search');
        if (!searchContainer) return;
        
        const searchInput = searchContainer.querySelector('input');
        if (!searchInput) return;
        
        searchInput.addEventListener('input', KahvePortal.utils.debounce(function() {
            const searchTerm = this.value.toLowerCase();
            const rows = table.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        }, 300));
    },
    
    // Add table sorting
    addTableSorting: function(table) {
        const headers = table.querySelectorAll('thead th[data-sortable]');
        
        headers.forEach(header => {
            header.style.cursor = 'pointer';
            header.innerHTML += ' <i class="fas fa-sort text-muted"></i>';
            
            header.addEventListener('click', () => {
                const column = Array.from(header.parentElement.children).indexOf(header);
                const direction = header.getAttribute('data-sort-direction') === 'asc' ? 'desc' : 'asc';
                
                this.sortTable(table, column, direction);
                
                // Update header icons
                headers.forEach(h => {
                    const icon = h.querySelector('i');
                    icon.className = 'fas fa-sort text-muted';
                });
                
                const icon = header.querySelector('i');
                icon.className = direction === 'asc' ? 'fas fa-sort-up text-primary' : 'fas fa-sort-down text-primary';
                header.setAttribute('data-sort-direction', direction);
            });
        });
    },
    
    // Sort table
    sortTable: function(table, column, direction) {
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        
        rows.sort((a, b) => {
            const aVal = a.children[column].textContent.trim();
            const bVal = b.children[column].textContent.trim();
            
            // Try to parse as numbers
            const aNum = parseFloat(aVal.replace(/[^\d.-]/g, ''));
            const bNum = parseFloat(bVal.replace(/[^\d.-]/g, ''));
            
            if (!isNaN(aNum) && !isNaN(bNum)) {
                return direction === 'asc' ? aNum - bNum : bNum - aNum;
            }
            
            // String comparison
            return direction === 'asc' ? aVal.localeCompare(bVal) : bVal.localeCompare(aVal);
        });
        
        rows.forEach(row => tbody.appendChild(row));
    },
    
    // Add row selection
    addRowSelection: function(table) {
        const rows = table.querySelectorAll('tbody tr');
        
        rows.forEach(row => {
            row.addEventListener('click', function(e) {
                if (e.target.tagName === 'INPUT' || e.target.tagName === 'BUTTON' || e.target.tagName === 'A') {
                    return;
                }
                
                row.classList.toggle('table-active');
                
                // Trigger custom event
                row.dispatchEvent(new CustomEvent('row:selected', {
                    detail: { selected: row.classList.contains('table-active') }
                }));
            });
        });
    },
    
    // Setup charts
    setupCharts: function() {
        // Only setup if Chart.js is available
        if (typeof Chart === 'undefined') return;
        
        const chartElements = document.querySelectorAll('[data-chart]');
        
        chartElements.forEach(element => {
            this.initializeChart(element);
        });
    },
    
    // Initialize individual chart
    initializeChart: function(element) {
        const type = element.getAttribute('data-chart-type') || 'line';
        const dataUrl = element.getAttribute('data-chart-url');
        const options = element.getAttribute('data-chart-options');
        
        if (dataUrl) {
            // Load data from URL
            fetch(dataUrl)
                .then(response => response.json())
                .then(data => {
                    this.createChart(element, type, data, options);
                })
                .catch(error => {
                    console.error('Chart data load error:', error);
                });
        } else {
            // Use inline data
            const data = element.getAttribute('data-chart-data');
            if (data) {
                try {
                    this.createChart(element, type, JSON.parse(data), options);
                } catch (e) {
                    console.error('Invalid chart data:', e);
                }
            }
        }
    },
    
    // Create chart
    createChart: function(element, type, data, options) {
        const defaultOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            },
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        };
        
        // Merge custom options
        if (options) {
            try {
                Object.assign(defaultOptions, JSON.parse(options));
            } catch (e) {
                console.error('Invalid chart options:', e);
            }
        }
        
        const ctx = element.getContext('2d');
        new Chart(ctx, {
            type: type,
            data: data,
            options: defaultOptions
        });
    },
    
    // Setup form validation
    setupFormValidation: function() {
        const forms = document.querySelectorAll('.needs-validation');
        
        forms.forEach(form => {
            form.addEventListener('submit', function(event) {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                    
                    // Focus first invalid field
                    const firstInvalid = form.querySelector(':invalid');
                    if (firstInvalid) {
                        firstInvalid.focus();
                        firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
                
                form.classList.add('was-validated');
            });
            
            // Real-time validation
            const inputs = form.querySelectorAll('input, textarea, select');
            inputs.forEach(input => {
                input.addEventListener('blur', function() {
                    if (input.checkValidity()) {
                        input.classList.remove('is-invalid');
                        input.classList.add('is-valid');
                    } else {
                        input.classList.remove('is-valid');
                        input.classList.add('is-invalid');
                    }
                });
            });
        });
        
        // Password confirmation validation
        const passwordConfirms = document.querySelectorAll('input[data-confirm-password]');
        passwordConfirms.forEach(input => {
            const targetId = input.getAttribute('data-confirm-password');
            const targetInput = document.getElementById(targetId);
            
            if (targetInput) {
                const validatePasswords = () => {
                    if (input.value !== targetInput.value) {
                        input.setCustomValidity('Şifreler eşleşmiyor');
                    } else {
                        input.setCustomValidity('');
                    }
                };
                
                input.addEventListener('input', validatePasswords);
                targetInput.addEventListener('input', validatePasswords);
            }
        });
    },
    
    // Setup file uploads
    setupFileUploads: function() {
        const fileInputs = document.querySelectorAll('input[type="file"][data-preview]');
        
        fileInputs.forEach(input => {
            input.addEventListener('change', function() {
                const file = this.files[0];
                const previewId = this.getAttribute('data-preview');
                const preview = document.getElementById(previewId);
                
                if (file && preview) {
                    if (file.type.startsWith('image/')) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            preview.src = e.target.result;
                            preview.style.display = 'block';
                        };
                        reader.readAsDataURL(file);
                    }
                }
            });
        });
        
        // Drag and drop uploads
        const dropZones = document.querySelectorAll('[data-drop-zone]');
        dropZones.forEach(zone => {
            this.setupDropZone(zone);
        });
    },
    
    // Setup drop zone
    setupDropZone: function(zone) {
        const input = zone.querySelector('input[type="file"]');
        if (!input) return;
        
        zone.addEventListener('dragover', function(e) {
            e.preventDefault();
            zone.classList.add('drag-over');
        });
        
        zone.addEventListener('dragleave', function(e) {
            e.preventDefault();
            zone.classList.remove('drag-over');
        });
        
        zone.addEventListener('drop', function(e) {
            e.preventDefault();
            zone.classList.remove('drag-over');
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                input.files = files;
                input.dispatchEvent(new Event('change'));
            }
        });
    },
    
    // Setup date pickers
    setupDatePickers: function() {
        const dateInputs = document.querySelectorAll('input[type="date"], input[data-datepicker]');
        
        dateInputs.forEach(input => {
            // Add custom date picker if needed
            if (input.getAttribute('data-datepicker') && typeof flatpickr !== 'undefined') {
                const options = {
                    locale: 'tr',
                    dateFormat: 'd.m.Y'
                };
                
                const customOptions = input.getAttribute('data-datepicker-options');
                if (customOptions) {
                    try {
                        Object.assign(options, JSON.parse(customOptions));
                    } catch (e) {
                        console.error('Invalid datepicker options:', e);
                    }
                }
                
                flatpickr(input, options);
            }
        });
    },
    
    // Setup real-time updates
    setupRealTimeUpdates: function() {
        this.updateInterval = setInterval(() => {
            this.updateDashboardStats();
            this.updateNotifications();
            this.updatePendingCounts();
        }, 30000); // Update every 30 seconds
    },
    
    // Update dashboard statistics
    updateDashboardStats: function() {
        const statsElements = document.querySelectorAll('[data-stat-update]');
        
        if (statsElements.length === 0) return;
        
        fetch('/api/stats?type=overview')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    statsElements.forEach(element => {
                        const statType = element.getAttribute('data-stat-update');
                        const value = this.getNestedValue(data.basic_stats, statType);
                        
                        if (value !== undefined) {
                            this.animateStatUpdate(element, value);
                        }
                    });
                }
            })
            .catch(error => console.error('Stats update error:', error));
    },
    
    // Get nested object value
    getNestedValue: function(obj, path) {
        return path.split('.').reduce((current, key) => current && current[key], obj);
    },
    
    // Animate stat update
    animateStatUpdate: function(element, newValue) {
        const currentValue = parseInt(element.textContent.replace(/[^\d]/g, '')) || 0;
        
        if (currentValue !== newValue) {
            const duration = 1000;
            const steps = 20;
            const stepValue = (newValue - currentValue) / steps;
            const stepDuration = duration / steps;
            
            let step = 0;
            const interval = setInterval(() => {
                step++;
                const value = Math.round(currentValue + (stepValue * step));
                element.textContent = value.toLocaleString('tr-TR');
                
                if (step >= steps) {
                    clearInterval(interval);
                    element.textContent = newValue.toLocaleString('tr-TR');
                    
                    // Add update indicator
                    element.classList.add('stat-updated');
                    setTimeout(() => element.classList.remove('stat-updated'), 2000);
                }
            }, stepDuration);
        }
    },
    
    // Update notifications
    updateNotifications: function() {
        // This will be handled by the main app.js
        if (window.KahvePortal && window.KahvePortal.updateNotificationCount) {
            KahvePortal.updateNotificationCount();
        }
    },
    
    // Update pending counts
    updatePendingCounts: function() {
        const pendingElements = document.querySelectorAll('[data-pending-count]');
        
        if (pendingElements.length === 0) return;
        
        pendingElements.forEach(element => {
            const type = element.getAttribute('data-pending-count');
            
            fetch(`/api/stats?type=${type}&pending_only=true`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.count !== undefined) {
                        element.textContent = data.count;
                        
                        if (data.count > 0) {
                            element.style.display = 'inline';
                            element.classList.add('urgent-badge');
                        } else {
                            element.style.display = 'none';
                            element.classList.remove('urgent-badge');
                        }
                    }
                })
                .catch(error => console.error('Pending count update error:', error));
        });
    },
    
    // Setup keyboard shortcuts
    setupKeyboardShortcuts: function() {
        document.addEventListener('keydown', (e) => {
            // Ctrl/Cmd + S: Save form
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                const activeForm = document.querySelector('form:focus-within');
                if (activeForm) {
                    activeForm.requestSubmit();
                }
            }
            
            // Ctrl/Cmd + K: Search
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                const searchInput = document.querySelector('input[type="search"], input[placeholder*="ara"]');
                if (searchInput) {
                    searchInput.focus();
                }
            }
            
            // Escape: Close modals
            if (e.key === 'Escape') {
                const openModal = document.querySelector('.modal.show');
                if (openModal) {
                    const modal = bootstrap.Modal.getInstance(openModal);
                    if (modal) modal.hide();
                }
            }
        });
    },
    
    // Utility functions
    utils: {
        // Confirm action
        confirmAction: function(message, callback) {
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
                        callback();
                    }
                });
            } else {
                if (confirm(message)) {
                    callback();
                }
            }
        },
        
        // Show loading overlay
        showLoading: function(container) {
            const overlay = document.createElement('div');
            overlay.className = 'loading-overlay';
            overlay.innerHTML = '<div class="loading-spinner"></div>';
            
            if (container) {
                container.style.position = 'relative';
                container.appendChild(overlay);
            } else {
                document.body.appendChild(overlay);
            }
            
            return overlay;
        },
        
        // Hide loading overlay
        hideLoading: function(overlay) {
            if (overlay && overlay.parentElement) {
                overlay.parentElement.removeChild(overlay);
            }
        },
        
        // Export table data
        exportTable: function(table, filename, format = 'csv') {
            const rows = [];
            const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent.trim());
            rows.push(headers);
            
            table.querySelectorAll('tbody tr').forEach(tr => {
                const row = Array.from(tr.querySelectorAll('td')).map(td => td.textContent.trim());
                rows.push(row);
            });
            
            if (format === 'csv') {
                const csv = rows.map(row => row.map(cell => `"${cell}"`).join(',')).join('\n');
                this.downloadFile(csv, filename + '.csv', 'text/csv');
            }
        },
        
        // Download file
        downloadFile: function(content, filename, mimeType) {
            const blob = new Blob([content], { type: mimeType });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
    },
    
    // Cleanup
    destroy: function() {
        if (this.updateInterval) {
            clearInterval(this.updateInterval);
        }
    }
};

// Initialize admin panel when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    if (document.body.classList.contains('admin-page') || window.location.pathname.includes('/admin')) {
        AdminPanel.init();
    }
});

// Cleanup on page unload
window.addEventListener('beforeunload', function() {
    if (window.AdminPanel) {
        AdminPanel.destroy();
    }
});

// Export for modules if needed
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AdminPanel;
}