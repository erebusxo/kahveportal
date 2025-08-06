<?php
/**
 * KahvePortal - Admin Header
 * admin/includes/header.php
 */

if (!defined('DB_HOST')) {
    die('Direct access not permitted');
}

// Admin kontrolü
if (!isAdmin()) {
    header('Location: ../login.php');
    exit;
}

// Session timeout kontrolü
if (!checkSessionTimeout()) {
    header('Location: index.php');
    exit;
}

// Güvenlik kontrolü
if (!validateSessionSecurity()) {
    header('Location: index.php');
    exit;
}

// Sayfa başlığını belirle
if (!isset($page_title)) {
    $page_title = 'Admin Panel';
}

// Okunmamış bildirim sayısını getir
try {
    $notification_stmt = $db->prepare("
        SELECT COUNT(*) 
        FROM notifications n
        LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = ?
        WHERE (n.user_id = ? OR n.user_id IS NULL) AND COALESCE(nr.is_read, 0) = 0
    ");
    $notification_stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
    $unread_notifications = $notification_stmt->fetchColumn();
} catch (PDOException $e) {
    $unread_notifications = 0;
}

// Acil durumları kontrol et
try {
    $urgent_checks = $db->query("
        SELECT 
            (SELECT COUNT(*) FROM orders WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)) as old_pending_orders,
            (SELECT COUNT(*) FROM balance_requests WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)) as old_balance_requests,
            (SELECT COUNT(*) FROM products WHERE is_active = 1 AND is_available = 0) as unavailable_products
    ")->fetch();
} catch (PDOException $e) {
    $urgent_checks = ['old_pending_orders' => 0, 'old_balance_requests' => 0, 'unavailable_products' => 0];
}

$urgent_count = array_sum($urgent_checks);
?>
<!DOCTYPE html>
<html lang="tr" data-bs-theme="<?php echo $_SESSION['user_theme'] ?? 'light'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo SITE_NAME; ?> Admin</title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <?php if (($_SESSION['user_theme'] ?? 'light') === 'dark'): ?>
        <link rel="stylesheet" href="../assets/css/dark-mode.css">
    <?php endif; ?>
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="../assets/images/favicon.ico">
    
    <style>
        .sidebar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            width: 250px;
            z-index: 1000;
            transition: all 0.3s ease;
        }
        
        .sidebar.collapsed {
            width: 80px;
        }
        
        .sidebar-brand {
            padding: 1.5rem;
            color: white;
            text-decoration: none;
            display: block;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-nav {
            padding: 1rem 0;
        }
        
        .sidebar-nav .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 0.75rem 1.5rem;
            transition: all 0.3s ease;
            border: none;
            display: flex;
            align-items: center;
        }
        
        .sidebar-nav .nav-link:hover,
        .sidebar-nav .nav-link.active {
            color: white;
            background: rgba(255,255,255,0.1);
        }
        
        .sidebar-nav .nav-link i {
            width: 20px;
            margin-right: 0.75rem;
        }
        
        .main-content {
            margin-left: 250px;
            transition: all 0.3s ease;
            min-height: 100vh;
        }
        
        .main-content.expanded {
            margin-left: 80px;
        }
        
        .top-navbar {
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 1rem 2rem;
            position: sticky;
            top: 0;
            z-index: 999;
        }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            font-size: 0.7rem;
            min-width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .urgent-badge {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                transform: translateX(-100%);
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <nav class="sidebar" id="sidebar">
        <a href="dashboard.php" class="sidebar-brand">
            <i class="fas fa-coffee me-2"></i>
            <span class="brand-text"><?php echo SITE_NAME; ?></span>
        </a>
        
        <ul class="sidebar-nav nav flex-column">
            <li class="nav-item">
                <a href="dashboard.php" class="nav-link <?php echo ($page_title === 'Dashboard') ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i>
                    <span class="nav-text">Dashboard</span>
                </a>
            </li>
            
            <li class="nav-item">
                <a href="orders.php" class="nav-link <?php echo ($page_title === 'Siparişler') ? 'active' : ''; ?>">
                    <i class="fas fa-shopping-cart"></i>
                    <span class="nav-text">Siparişler</span>
                    <?php if ($urgent_checks['old_pending_orders'] > 0): ?>
                        <span class="badge bg-danger notification-badge urgent-badge">
                            <?php echo $urgent_checks['old_pending_orders']; ?>
                        </span>
                    <?php endif; ?>
                </a>
            </li>
            
            <li class="nav-item">
                <a href="menu.php" class="nav-link <?php echo ($page_title === 'Menü Yönetimi') ? 'active' : ''; ?>">
                    <i class="fas fa-coffee"></i>
                    <span class="nav-text">Menü</span>
                </a>
            </li>
            
            <li class="nav-item">
                <a href="users.php" class="nav-link <?php echo ($page_title === 'Kullanıcılar') ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i>
                    <span class="nav-text">Kullanıcılar</span>
                </a>
            </li>
            
            <li class="nav-item">
                <a href="balance.php" class="nav-link <?php echo ($page_title === 'Bakiye Yönetimi') ? 'active' : ''; ?>">
                    <i class="fas fa-wallet"></i>
                    <span class="nav-text">Bakiye</span>
                    <?php if ($urgent_checks['old_balance_requests'] > 0): ?>
                        <span class="badge bg-warning notification-badge urgent-badge">
                            <?php echo $urgent_checks['old_balance_requests']; ?>
                        </span>
                    <?php endif; ?>
                </a>
            </li>
            
            <li class="nav-item">
                <a href="reports.php" class="nav-link <?php echo ($page_title === 'Raporlar') ? 'active' : ''; ?>">
                    <i class="fas fa-chart-bar"></i>
                    <span class="nav-text">Raporlar</span>
                </a>
            </li>
            
            <li class="nav-item">
                <a href="notifications.php" class="nav-link <?php echo ($page_title === 'Bildirimler') ? 'active' : ''; ?>">
                    <i class="fas fa-bell"></i>
                    <span class="nav-text">Bildirimler</span>
                    <?php if ($unread_notifications > 0): ?>
                        <span class="badge bg-info notification-badge">
                            <?php echo $unread_notifications; ?>
                        </span>
                    <?php endif; ?>
                </a>
            </li>
            
            <li class="nav-item">
                <a href="settings.php" class="nav-link <?php echo ($page_title === 'Ayarlar') ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i>
                    <span class="nav-text">Ayarlar</span>
                </a>
            </li>
            
            <hr class="sidebar-divider my-3" style="border-color: rgba(255,255,255,0.2);">
            
            <li class="nav-item">
                <a href="../index.php" class="nav-link" target="_blank">
                    <i class="fas fa-external-link-alt"></i>
                    <span class="nav-text">Siteyi Görüntüle</span>
                </a>
            </li>
            
            <li class="nav-item">
                <a href="../profile.php" class="nav-link">
                    <i class="fas fa-user"></i>
                    <span class="nav-text">Profilim</span>
                </a>
            </li>
            
            <li class="nav-item">
                <a href="../auth.php?action=logout" class="nav-link" onclick="return confirm('Çıkış yapmak istediğinizden emin misiniz?')">
                    <i class="fas fa-sign-out-alt"></i>
                    <span class="nav-text">Çıkış</span>
                </a>
            </li>
        </ul>
    </nav>

    <!-- Main Content -->
    <div class="main-content" id="main-content">
        <!-- Top Navbar -->
        <nav class="top-navbar d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center">
                <button class="btn btn-link p-0 me-3" id="sidebar-toggle">
                    <i class="fas fa-bars fa-lg"></i>
                </button>
                
                <div class="d-none d-md-block">
                    <h5 class="mb-0"><?php echo $page_title; ?></h5>
                    <small class="text-muted"><?php echo date('d.m.Y H:i'); ?></small>
                </div>
            </div>
            
            <div class="d-flex align-items-center">
                <!-- Acil Durum Bildirimleri -->
                <?php if ($urgent_count > 0): ?>
                    <div class="dropdown me-3">
                        <button class="btn btn-outline-danger position-relative" type="button" data-bs-toggle="dropdown">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span class="badge bg-danger notification-badge urgent-badge">
                                <?php echo $urgent_count; ?>
                            </span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <?php if ($urgent_checks['old_pending_orders'] > 0): ?>
                                <li>
                                    <a class="dropdown-item" href="orders.php?status=pending">
                                        <i class="fas fa-clock text-warning me-2"></i>
                                        <?php echo $urgent_checks['old_pending_orders']; ?> eski bekleyen sipariş
                                    </a>
                                </li>
                            <?php endif; ?>
                            <?php if ($urgent_checks['old_balance_requests'] > 0): ?>
                                <li>
                                    <a class="dropdown-item" href="balance.php">
                                        <i class="fas fa-wallet text-warning me-2"></i>
                                        <?php echo $urgent_checks['old_balance_requests']; ?> eski bakiye talebi
                                    </a>
                                </li>
                            <?php endif; ?>
                            <?php if ($urgent_checks['unavailable_products'] > 0): ?>
                                <li>
                                    <a class="dropdown-item" href="menu.php?filter=unavailable">
                                        <i class="fas fa-coffee text-warning me-2"></i>
                                        <?php echo $urgent_checks['unavailable_products']; ?> müsait olmayan ürün
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <!-- Bildirimler -->
                <div class="dropdown me-3">
                    <button class="btn btn-outline-primary position-relative" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-bell"></i>
                        <?php if ($unread_notifications > 0): ?>
                            <span class="badge bg-primary notification-badge">
                                <?php echo $unread_notifications; ?>
                            </span>
                        <?php endif; ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" style="width: 300px;">
                        <li class="dropdown-header">Bildirimler</li>
                        <li><hr class="dropdown-divider"></li>
                        <li class="px-3 py-2">
                            <div id="notification-list">
                                <div class="text-center">
                                    <div class="spinner-border spinner-border-sm" role="status">
                                        <span class="visually-hidden">Yükleniyor...</span>
                                    </div>
                                </div>
                            </div>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-center" href="notifications.php">
                                Tüm Bildirimleri Görüntüle
                            </a>
                        </li>
                    </ul>
                </div>
                
                <!-- Kullanıcı Menüsü -->
                <div class="dropdown">
                    <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user me-2"></i>
                        <?php echo htmlspecialchars($_SESSION['user_first_name']); ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <span class="dropdown-item-text">
                                <strong><?php echo htmlspecialchars($_SESSION['user_name']); ?></strong><br>
                                <small class="text-muted"><?php echo htmlspecialchars($_SESSION['user_email']); ?></small>
                            </span>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="../profile.php">
                                <i class="fas fa-user me-2"></i>Profilim
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="#" onclick="toggleTheme()">
                                <i class="fas fa-moon me-2"></i>Tema Değiştir
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-danger" href="../auth.php?action=logout" onclick="return confirm('Çıkış yapmak istediğinizden emin misiniz?')">
                                <i class="fas fa-sign-out-alt me-2"></i>Çıkış Yap
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>

        <!-- Page Content -->
        <div class="p-4">
            <!-- Flash Messages -->
            <?php 
            $flash_messages = getFlashMessages();
            foreach ($flash_messages as $message): 
            ?>
                <div class="alert alert-<?php echo $message['type']; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message['message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endforeach; ?>

            <!-- Page Content Starts Here -->