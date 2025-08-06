<?php
/**
 * KahvePortal - Siparişlerim
 * orders.php
 */

require_once 'includes/config.php';

// Giriş kontrolü
requireLogin();

// Sayfalama
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Filtreler
$statusFilter = $_GET['status'] ?? '';
$dateFilter = $_GET['date'] ?? '';

// Siparişleri getir
try {
    // Toplam sipariş sayısı
    $countSql = "SELECT COUNT(*) FROM orders WHERE user_id = ?";
    $countParams = [$_SESSION['user_id']];
    
    if ($statusFilter) {
        $countSql .= " AND status = ?";
        $countParams[] = $statusFilter;
    }
    
    if ($dateFilter) {
        switch ($dateFilter) {
            case 'today':
                $countSql .= " AND DATE(created_at) = CURDATE()";
                break;
            case 'week':
                $countSql .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)";
                break;
            case 'month':
                $countSql .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
                break;
        }
    }
    
    $stmt = $db->prepare($countSql);
    $stmt->execute($countParams);
    $totalOrders = $stmt->fetchColumn();
    $totalPages = ceil($totalOrders / $limit);
    
    // Siparişleri getir
    $sql = "
        SELECT o.*,
            (SELECT GROUP_CONCAT(CONCAT(oi.product_name, ' (', oi.quantity, ')') SEPARATOR ', ')
             FROM order_items oi WHERE oi.order_id = o.id) as items
        FROM orders o
        WHERE o.user_id = ?
    ";
    
    $params = [$_SESSION['user_id']];
    
    if ($statusFilter) {
        $sql .= " AND o.status = ?";
        $params[] = $statusFilter;
    }
    
    if ($dateFilter) {
        switch ($dateFilter) {
            case 'today':
                $sql .= " AND DATE(o.created_at) = CURDATE()";
                break;
            case 'week':
                $sql .= " AND o.created_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)";
                break;
            case 'month':
                $sql .= " AND o.created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
                break;
        }
    }
    
    $sql .= " ORDER BY o.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();
    
    // İstatistikler
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
            COUNT(CASE WHEN status = 'preparing' THEN 1 END) as preparing,
            COUNT(CASE WHEN status = 'ready' THEN 1 END) as ready,
            COUNT(CASE WHEN status = 'delivered' THEN 1 END) as delivered,
            COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled,
            COALESCE(SUM(total_amount), 0) as total_spent
        FROM orders 
        WHERE user_id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $stats = $stmt->fetch();
    
} catch (PDOException $e) {
    error_log('Sipariş listesi hatası: ' . $e->getMessage());
    $orders = [];
    $stats = ['total' => 0, 'pending' => 0, 'preparing' => 0, 'ready' => 0, 'delivered' => 0, 'cancelled' => 0, 'total_spent' => 0];
}

$statusLabels = [
    'pending' => ['text' => 'Bekliyor', 'class' => 'warning', 'icon' => 'clock'],
    'preparing' => ['text' => 'Hazırlanıyor', 'class' => 'info', 'icon' => 'coffee'],
    'ready' => ['text' => 'Hazır', 'class' => 'primary', 'icon' => 'check-circle'],
    'delivered' => ['text' => 'Teslim Edildi', 'class' => 'success', 'icon' => 'check-double'],
    'cancelled' => ['text' => 'İptal Edildi', 'class' => 'danger', 'icon' => 'times-circle']
];
?>
<!DOCTYPE html>
<html lang="tr" data-bs-theme="<?php echo $_SESSION['user_theme'] ?? 'light'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Siparişlerim - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    
    <style>
        .orders-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            border-radius: 0 0 30px 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .stat-card .icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        
        .stat-card .number {
            font-size: 1.5rem;
            font-weight: bold;
        }
        
        .stat-card .label {
            font-size: 0.9rem;
            color: #718096;
        }
        
        .order-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
        }
        
        .order-card:hover {
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .order-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .timeline {
            position: relative;
            padding-left: 30px;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e2e8f0;
        }
        
        .timeline-item {
            position: relative;
            padding-bottom: 1rem;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -24px;
            top: 5px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #667eea;
            border: 2px solid white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
        }
        
        .timeline-item.completed::before {
            background: #48bb78;
            box-shadow: 0 0 0 3px rgba(72, 187, 120, 0.2);
        }
        
        .filter-pills {
            display: flex;
            gap: 10px;
            overflow-x: auto;
            padding: 10px 0;
        }
        
        .filter-pill {
            padding: 8px 16px;
            border-radius: 20px;
            background: white;
            color: #4a5568;
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.3s ease;
            border: 1px solid #e2e8f0;
        }
        
        .filter-pill:hover,
        .filter-pill.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: transparent;
        }
        
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
        }
        
        .empty-state i {
            font-size: 5rem;
            color: #cbd5e0;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-gradient-primary sticky-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="/">
                <i class="fas fa-coffee me-2"></i>
                <span class="fw-bold"><?php echo SITE_NAME; ?></span>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="/"><i class="fas fa-home me-1"></i> Ana Sayfa</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="menu.php"><i class="fas fa-coffee me-1"></i> Menü</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="orders.php"><i class="fas fa-receipt me-1"></i> Siparişlerim</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="balance.php"><i class="fas fa-wallet me-1"></i> Bakiye</a>
                    </li>
                </ul>
                
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="cart.php">
                            <i class="fas fa-shopping-cart"></i>
                        </a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle me-1"></i> 
                            <?php echo clean($_SESSION['full_name'] ?? $_SESSION['username']); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="dashboard.php"><i class="fas fa-tachometer-alt me-2"></i> Dashboard</a></li>
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> Profil</a></li>
                            <?php if (isAdmin()): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="admin/"><i class="fas fa-cog me-2"></i> Admin Panel</a></li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i> Çıkış</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    
    <!-- Orders Header -->
    <div class="orders-header">
        <div class="container">
            <h1 class="display-5 mb-0">
                <i class="fas fa-receipt me-2"></i> Siparişlerim
            </h1>
        </div>
    </div>
    
    <div class="container">
        <!-- İstatistikler -->
        <div class="row mb-4">
            <div class="col-6 col-md-2 mb-3">
                <div class="stat-card" onclick="filterByStatus('')">
                    <div class="icon text-primary"><i class="fas fa-shopping-bag"></i></div>
                    <div class="number"><?php echo $stats['total']; ?></div>
                    <div class="label">Toplam</div>
                </div>
            </div>
            <div class="col-6 col-md-2 mb-3">
                <div class="stat-card" onclick="filterByStatus('pending')">
                    <div class="icon text-warning"><i class="fas fa-clock"></i></div>
                    <div class="number"><?php echo $stats['pending']; ?></div>
                    <div class="label">Bekliyor</div>
                </div>
            </div>
            <div class="col-6 col-md-2 mb-3">
                <div class="stat-card" onclick="filterByStatus('preparing')">
                    <div class="icon text-info"><i class="fas fa-coffee"></i></div>
                    <div class="number"><?php echo $stats['preparing']; ?></div>
                    <div class="label">Hazırlanıyor</div>
                </div>
            </div>
            <div class="col-6 col-md-2 mb-3">
                <div class="stat-card" onclick="filterByStatus('ready')">
                    <div class="icon text-primary"><i class="fas fa-check-circle"></i></div>
                    <div class="number"><?php echo $stats['ready']; ?></div>
                    <div class="label">Hazır</div>
                </div>
            </div>
            <div class="col-6 col-md-2 mb-3">
                <div class="stat-card" onclick="filterByStatus('delivered')">
                    <div class="icon text-success"><i class="fas fa-check-double"></i></div>
                    <div class="number"><?php echo $stats['delivered']; ?></div>
                    <div class="label">Teslim</div>
                </div>
            </div>
            <div class="col-6 col-md-2 mb-3">
                <div class="stat-card">
                    <div class="icon text-purple"><i class="fas fa-turkish-lira-sign"></i></div>
                    <div class="number"><?php echo number_format($stats['total_spent'], 0); ?></div>
                    <div class="label">Toplam Harcama</div>
                </div>
            </div>
        </div>
        
        <!-- Filtreler -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="filter-pills">
                <a href="?date=today" class="filter-pill <?php echo $dateFilter == 'today' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-day me-1"></i> Bugün
                </a>
                <a href="?date=week" class="filter-pill <?php echo $dateFilter == 'week' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-week me-1"></i> Bu Hafta
                </a>
                <a href="?date=month" class="filter-pill <?php echo $dateFilter == 'month' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-alt me-1"></i> Bu Ay
                </a>
                <a href="orders.php" class="filter-pill <?php echo !$dateFilter ? 'active' : ''; ?>">
                    <i class="fas fa-calendar me-1"></i> Tümü
                </a>
            </div>
            
            <a href="menu.php" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i> Yeni Sipariş
            </a>
        </div>
        
        <!-- Sipariş Listesi -->
        <?php if (!empty($orders)): ?>
        <?php foreach ($orders as $order): ?>
        <div class="order-card">
            <div class="row">
                <div class="col-md-8">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <h5 class="mb-1">Sipariş #<?php echo clean($order['order_number']); ?></h5>
                            <p class="text-muted mb-2">
                                <i class="fas fa-clock me-1"></i> <?php echo formatDate($order['created_at']); ?>
                            </p>
                        </div>
                        <span class="order-status bg-<?php echo $statusLabels[$order['status']]['class']; ?> bg-opacity-10 text-<?php echo $statusLabels[$order['status']]['class']; ?>">
                            <i class="fas fa-<?php echo $statusLabels[$order['status']]['icon']; ?>"></i>
                            <?php echo $statusLabels[$order['status']]['text']; ?>
                        </span>
                    </div>
                    
                    <div class="mb-2">
                        <strong>Ürünler:</strong> <?php echo clean($order['items']); ?>
                    </div>
                    
                    <?php if ($order['notes']): ?>
                    <div class="mb-2">
                        <small class="text-muted">
                            <i class="fas fa-sticky-note me-1"></i> <?php echo clean($order['notes']); ?>
                        </small>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($order['admin_notes']): ?>
                    <div class="alert alert-info py-2 px-3 mb-2">
                        <small><i class="fas fa-info-circle me-1"></i> <?php echo clean($order['admin_notes']); ?></small>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="col-md-4 text-md-end">
                    <h4 class="text-primary mb-3"><?php echo formatMoney($order['total_amount']); ?></h4>
                    
                    <div class="btn-group" role="group">
                        <a href="order-detail.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-eye"></i> Detay
                        </a>
                        
                        <?php if ($order['status'] == 'pending'): ?>
                        <button class="btn btn-sm btn-outline-danger" onclick="cancelOrder(<?php echo $order['id']; ?>)">
                            <i class="fas fa-times"></i> İptal
                        </button>
                        <?php endif; ?>
                        
                        <?php if ($order['status'] == 'delivered'): ?>
                        <button class="btn btn-sm btn-outline-success" onclick="reorder(<?php echo $order['id']; ?>)">
                            <i class="fas fa-redo"></i> Tekrarla
                        </button>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($order['delivery_time']): ?>
                    <div class="mt-2">
                        <small class="text-muted">
                            Tahmini Teslim: <?php echo formatDate($order['delivery_time'], 'H:i'); ?>
                        </small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Sipariş Timeline -->
            <?php if (in_array($order['status'], ['preparing', 'ready', 'delivered'])): ?>
            <hr>
            <div class="timeline">
                <div class="timeline-item completed">
                    <small class="text-muted">Sipariş Alındı</small>
                </div>
                <?php if (in_array($order['status'], ['preparing', 'ready', 'delivered'])): ?>
                <div class="timeline-item <?php echo in_array($order['status'], ['ready', 'delivered']) ? 'completed' : ''; ?>">
                    <small class="text-muted">Hazırlanıyor</small>
                </div>
                <?php endif; ?>
                <?php if (in_array($order['status'], ['ready', 'delivered'])): ?>
                <div class="timeline-item <?php echo $order['status'] == 'delivered' ? 'completed' : ''; ?>">
                    <small class="text-muted">Hazır</small>
                </div>
                <?php endif; ?>
                <?php if ($order['status'] == 'delivered'): ?>
                <div class="timeline-item completed">
                    <small class="text-muted">Teslim Edildi</small>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        
        <!-- Sayfalama -->
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Sayfalama">
            <ul class="pagination justify-content-center">
                <?php if ($page > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?php echo $page - 1; ?>&status=<?php echo $statusFilter; ?>&date=<?php echo $dateFilter; ?>">
                        Önceki
                    </a>
                </li>
                <?php endif; ?>
                
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo $statusFilter; ?>&date=<?php echo $dateFilter; ?>">
                        <?php echo $i; ?>
                    </a>
                </li>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?php echo $page + 1; ?>&status=<?php echo $statusFilter; ?>&date=<?php echo $dateFilter; ?>">
                        Sonraki
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
        <?php endif; ?>
        
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-receipt"></i>
            <h3>Sipariş Bulunamadı</h3>
            <p class="text-muted">Henüz sipariş vermediniz.</p>
            <a href="menu.php" class="btn btn-primary btn-lg">
                <i class="fas fa-coffee me-2"></i> İlk Siparişi Ver
            </a>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Footer -->
    <footer class="bg-dark text-white py-4 mt-5">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <p class="mb-0">&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. Tüm hakları saklıdır.</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <a href="#" class="text-white me-3"><i class="fab fa-facebook"></i></a>
                    <a href="#" class="text-white me-3"><i class="fab fa-twitter"></i></a>
                    <a href="#" class="text-white me-3"><i class="fab fa-instagram"></i></a>
                </div>
            </div>
        </div>
    </footer>
    
    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        function filterByStatus(status) {
            window.location.href = '?status=' + status;
        }
        
        function cancelOrder(orderId) {
            Swal.fire({
                title: 'Siparişi iptal etmek istediğinize emin misiniz?',
                text: "Bu işlem geri alınamaz!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Evet, İptal Et',
                cancelButtonText: 'Hayır'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('api/order.php', {
                        action: 'cancel',
                        order_id: orderId
                    }).done(function(response) {
                        if (response.success) {
                            Swal.fire('İptal Edildi!', 'Siparişiniz iptal edildi.', 'success')
                                .then(() => location.reload());
                        } else {
                            Swal.fire('Hata!', response.message, 'error');
                        }
                    });
                }
            });
        }
        
        function reorder(orderId) {
            $.post('api/order.php', {
                action: 'reorder',
                order_id: orderId
            }).done(function(response) {
                if (response.success) {
                    window.location.href = 'cart.php';
                } else {
                    Swal.fire('Hata!', response.message, 'error');
                }
            });
        }
    </script>
</body>
</html>