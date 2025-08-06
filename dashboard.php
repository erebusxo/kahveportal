<?php
/**
 * KahvePortal - Kullanıcı Dashboard
 * dashboard.php
 */

require_once 'includes/config.php';

// Giriş kontrolü
requireLogin();

// Kullanıcı istatistiklerini getir
try {
    $userId = $_SESSION['user_id'];
    
    // Toplam sipariş sayısı ve tutarı
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_orders,
            COALESCE(SUM(total_amount), 0) as total_spent,
            COUNT(CASE WHEN status = 'delivered' THEN 1 END) as completed_orders,
            COUNT(CASE WHEN status IN ('pending', 'preparing', 'ready') THEN 1 END) as active_orders
        FROM orders 
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $orderStats = $stmt->fetch();
    
    // Son siparişler
    $stmt = $db->prepare("
        SELECT o.*, 
            (SELECT GROUP_CONCAT(CONCAT(oi.product_name, ' (', oi.quantity, ')') SEPARATOR ', ')
             FROM order_items oi WHERE oi.order_id = o.id) as items
        FROM orders o
        WHERE o.user_id = ?
        ORDER BY o.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $recentOrders = $stmt->fetchAll();
    
    // Favori kahveler
    $stmt = $db->prepare("
        SELECT p.*, c.name as category_name
        FROM favorites f
        JOIN products p ON f.product_id = p.id
        JOIN categories c ON p.category_id = c.id
        WHERE f.user_id = ?
        LIMIT 6
    ");
    $stmt->execute([$userId]);
    $favorites = $stmt->fetchAll();
    
    // Son bakiye hareketleri
    $stmt = $db->prepare("
        SELECT * FROM balance_transactions
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $balanceHistory = $stmt->fetchAll();
    
    // Okunmamış bildirimler
    $stmt = $db->prepare("
        SELECT COUNT(*) as unread_count
        FROM notifications
        WHERE user_id = ? AND is_read = 0
    ");
    $stmt->execute([$userId]);
    $unreadNotifications = $stmt->fetch()['unread_count'];
    
    // En çok sipariş edilen kahve
    $stmt = $db->prepare("
        SELECT p.name, COUNT(*) as order_count, SUM(oi.quantity) as total_quantity
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        JOIN products p ON oi.product_id = p.id
        WHERE o.user_id = ?
        GROUP BY oi.product_id
        ORDER BY total_quantity DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $favoriteProduct = $stmt->fetch();
    
} catch (PDOException $e) {
    error_log('Dashboard veri hatası: ' . $e->getMessage());
}

// Kullanıcı bilgilerini güncelle
refreshUserSession($userId);
?>
<!DOCTYPE html>
<html lang="tr" data-bs-theme="<?php echo $_SESSION['user_theme'] ?? 'light'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    
    <style>
        .dashboard-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 3rem 0;
            margin-bottom: 2rem;
            border-radius: 0 0 30px 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.15);
        }
        
        .stat-card .icon {
            width: 60px;
            height: 60px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .quick-action {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
            height: 100%;
        }
        
        .quick-action:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.15);
            text-decoration: none;
            color: inherit;
        }
        
        .quick-action i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }
        
        .timeline-item {
            position: relative;
            padding-left: 40px;
            margin-bottom: 1.5rem;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 5px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #667eea;
        }
        
        .timeline-item::after {
            content: '';
            position: absolute;
            left: 14px;
            top: 15px;
            width: 2px;
            height: calc(100% + 10px);
            background: #e2e8f0;
        }
        
        .timeline-item:last-child::after {
            display: none;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <?php include 'includes/navbar.php'; ?>
    
    <!-- Dashboard Header -->
    <div class="dashboard-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="display-5 mb-2">
                        Hoş Geldiniz, <?php echo clean($_SESSION['full_name']); ?>! 👋
                    </h1>
                    <p class="lead mb-0">
                        <?php 
                        $hour = date('H');
                        if ($hour < 12) echo 'Günaydın! Güne bir kahve ile başlamaya ne dersiniz?';
                        elseif ($hour < 18) echo 'İyi öğlenler! Öğleden sonra enerjiniz için bir kahve?';
                        else echo 'İyi akşamlar! Akşam kahveniz hazır mı?';
                        ?>
                    </p>
                </div>
                <div class="col-md-4 text-md-end">
                    <div class="bg-white bg-opacity-25 rounded-pill px-4 py-2 d-inline-block">
                        <i class="fas fa-wallet me-2"></i>
                        <span class="fw-bold">Bakiye: <?php echo formatMoney($_SESSION['user_balance']); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="container">
        <?php echo displaySessionAlert(); ?>
        
        <!-- İstatistik Kartları -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <div class="icon bg-primary bg-opacity-10 text-primary">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <h3 class="mb-1"><?php echo $orderStats['total_orders']; ?></h3>
                    <p class="text-muted mb-0">Toplam Sipariş</p>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <div class="icon bg-success bg-opacity-10 text-success">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3 class="mb-1"><?php echo $orderStats['completed_orders']; ?></h3>
                    <p class="text-muted mb-0">Tamamlanan</p>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <div class="icon bg-warning bg-opacity-10 text-warning">
                        <i class="fas fa-clock"></i>
                    </div>
                    <h3 class="mb-1"><?php echo $orderStats['active_orders']; ?></h3>
                    <p class="text-muted mb-0">Aktif Sipariş</p>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <div class="icon bg-info bg-opacity-10 text-info">
                        <i class="fas fa-turkish-lira-sign"></i>
                    </div>
                    <h3 class="mb-1"><?php echo formatMoney($orderStats['total_spent'], false); ?></h3>
                    <p class="text-muted mb-0">Toplam Harcama</p>
                </div>
            </div>
        </div>
        
        <!-- Hızlı İşlemler -->
        <div class="row mb-4">
            <div class="col-12">
                <h4 class="mb-3">Hızlı İşlemler</h4>
            </div>
            <div class="col-md-3 col-6 mb-3">
                <a href="menu.php" class="quick-action">
                    <i class="fas fa-coffee text-primary"></i>
                    <h6>Sipariş Ver</h6>
                </a>
            </div>
            <div class="col-md-3 col-6 mb-3">
                <a href="balance.php" class="quick-action">
                    <i class="fas fa-wallet text-success"></i>
                    <h6>Bakiye Yükle</h6>
                </a>
            </div>
            <div class="col-md-3 col-6 mb-3">
                <a href="orders.php" class="quick-action">
                    <i class="fas fa-receipt text-warning"></i>
                    <h6>Siparişlerim</h6>
                </a>
            </div>
            <div class="col-md-3 col-6 mb-3">
                <a href="profile.php" class="quick-action">
                    <i class="fas fa-user text-info"></i>
                    <h6>Profilim</h6>
                </a>
            </div>
        </div>
        
        <div class="row">
            <!-- Son Siparişler -->
            <div class="col-lg-8 mb-4">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">
                            <i class="fas fa-history me-2"></i> Son Siparişler
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recentOrders)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Sipariş No</th>
                                        <th>Ürünler</th>
                                        <th>Tutar</th>
                                        <th>Durum</th>
                                        <th>Tarih</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentOrders as $order): ?>
                                    <tr style="cursor: pointer;" onclick="window.location='order-detail.php?id=<?php echo $order['id']; ?>'">
                                        <td><small><?php echo clean($order['order_number']); ?></small></td>
                                        <td><small><?php echo clean($order['items']); ?></small></td>
                                        <td><?php echo formatMoney($order['total_amount']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo $order['status'] == 'delivered' ? 'success' : 
                                                    ($order['status'] == 'cancelled' ? 'danger' : 'warning'); 
                                            ?>">
                                                <?php 
                                                $statusText = [
                                                    'pending' => 'Bekliyor',
                                                    'preparing' => 'Hazırlanıyor',
                                                    'ready' => 'Hazır',
                                                    'delivered' => 'Teslim Edildi',
                                                    'cancelled' => 'İptal'
                                                ];
                                                echo $statusText[$order['status']] ?? $order['status'];
                                                ?>
                                            </span>
                                        </td>
                                        <td><small><?php echo formatDate($order['created_at'], 'd.m H:i'); ?></small></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="text-center mt-3">
                            <a href="orders.php" class="btn btn-sm btn-outline-primary">
                                Tüm Siparişleri Gör <i class="fas fa-arrow-right ms-1"></i>
                            </a>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-shopping-basket fa-3x text-muted mb-3"></i>
                            <p class="text-muted">Henüz sipariş vermediniz</p>
                            <a href="menu.php" class="btn btn-primary">İlk Siparişi Ver</a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Sağ Sidebar -->
            <div class="col-lg-4">
                <!-- Favori Kahve -->
                <?php if ($favoriteProduct): ?>
                <div class="card mb-3">
                    <div class="card-body">
                        <h6 class="card-title">
                            <i class="fas fa-star text-warning me-2"></i> En Sevdiğiniz Kahve
                        </h6>
                        <h5 class="mb-1"><?php echo clean($favoriteProduct['name']); ?></h5>
                        <p class="text-muted mb-0">
                            <?php echo $favoriteProduct['order_count']; ?> sipariş, 
                            toplam <?php echo $favoriteProduct['total_quantity']; ?> adet
                        </p>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Bakiye Hareketleri -->
                <div class="card">
                    <div class="card-header bg-white">
                        <h6 class="mb-0">
                            <i class="fas fa-exchange-alt me-2"></i> Bakiye Hareketleri
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($balanceHistory)): ?>
                        <div class="timeline">
                            <?php foreach ($balanceHistory as $transaction): ?>
                            <div class="timeline-item">
                                <small class="text-muted"><?php echo timeAgo($transaction['created_at']); ?></small>
                                <div>
                                    <?php
                                    $typeIcons = [
                                        'deposit' => 'plus-circle text-success',
                                        'order' => 'shopping-cart text-warning',
                                        'refund' => 'undo text-info',
                                        'admin_add' => 'gift text-success',
                                        'admin_remove' => 'minus-circle text-danger'
                                    ];
                                    $icon = $typeIcons[$transaction['type']] ?? 'circle';
                                    ?>
                                    <i class="fas fa-<?php echo $icon; ?> me-2"></i>
                                    <span class="<?php echo $transaction['amount'] > 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo $transaction['amount'] > 0 ? '+' : ''; ?><?php echo formatMoney($transaction['amount']); ?>
                                    </span>
                                </div>
                                <small class="text-muted"><?php echo clean($transaction['description'] ?? ''); ?></small>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="text-center mt-3">
                            <a href="balance.php" class="btn btn-sm btn-outline-primary">
                                Tümünü Gör <i class="fas fa-arrow-right ms-1"></i>
                            </a>
                        </div>
                        <?php else: ?>
                        <p class="text-muted text-center mb-0">Henüz bakiye hareketi yok</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Footer -->
    <?php include 'includes/footer.php'; ?>
    
    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app.js"></script>
</body>
</html>