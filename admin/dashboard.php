<?php
/**
 * KahvePortal - Admin Dashboard
 * admin/dashboard.php
 */

require_once '../config.php';
require_once '../includes/session.php';

// Admin kontrolü
requireAdmin();

// İstatistikleri getir
try {
    // Temel istatistikler
    $stats = $db->query("
        SELECT 
            (SELECT COUNT(*) FROM users WHERE status = 'active') as total_users,
            (SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()) as today_orders,
            (SELECT COUNT(*) FROM orders WHERE status = 'pending') as pending_orders,
            (SELECT COUNT(*) FROM products WHERE is_active = 1) as active_products,
            (SELECT SUM(total_amount) FROM orders WHERE DATE(created_at) = CURDATE()) as today_revenue,
            (SELECT SUM(total_amount) FROM orders WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())) as monthly_revenue,
            (SELECT COUNT(*) FROM balance_requests WHERE status = 'pending') as pending_balance_requests,
            (SELECT COUNT(*) FROM users WHERE last_activity >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as active_users_24h
    ")->fetch();
    
    // Son siparişler
    $recent_orders = $db->query("
        SELECT 
            o.id,
            o.total_amount,
            o.status,
            o.created_at,
            u.first_name,
            u.last_name,
            COUNT(oi.id) as item_count
        FROM orders o
        JOIN users u ON o.user_id = u.id
        LEFT JOIN order_items oi ON o.id = oi.order_id
        GROUP BY o.id
        ORDER BY o.created_at DESC
        LIMIT 10
    ")->fetchAll();
    
    // Bekleyen bakiye talepleri
    $pending_balance = $db->query("
        SELECT 
            br.id,
            br.amount,
            br.created_at,
            u.first_name,
            u.last_name
        FROM balance_requests br
        JOIN users u ON br.user_id = u.id
        WHERE br.status = 'pending'
        ORDER BY br.created_at ASC
        LIMIT 5
    ")->fetchAll();
    
    // Popüler ürünler (son 30 gün)
    $popular_products = $db->query("
        SELECT 
            p.name,
            SUM(oi.quantity) as total_sold
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        JOIN orders o ON oi.order_id = o.id
        WHERE o.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY p.id, p.name
        ORDER BY total_sold DESC
        LIMIT 5
    ")->fetchAll();
    
    // Son 7 günün sipariş trendi
    $order_trend = $db->query("
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as order_count,
            SUM(total_amount) as revenue
        FROM orders 
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ")->fetchAll();
    
} catch (PDOException $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
    $stats = ['total_users' => 0, 'today_orders' => 0, 'pending_orders' => 0, 'active_products' => 0, 'today_revenue' => 0, 'monthly_revenue' => 0, 'pending_balance_requests' => 0, 'active_users_24h' => 0];
    $recent_orders = [];
    $pending_balance = [];
    $popular_products = [];
    $order_trend = [];
}

$page_title = 'Dashboard';
include 'includes/header.php';
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800">Dashboard</h1>
            <p class="mb-0 text-muted">Hoş geldiniz, <?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
        </div>
        <div>
            <span class="badge bg-success">
                <i class="fas fa-circle me-1" style="font-size: 0.5rem;"></i>
                Sistem Aktif
            </span>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="icon-circle bg-primary">
                                <i class="fas fa-users text-white"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="small font-weight-bold text-primary text-uppercase mb-1">
                                Toplam Kullanıcı
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['total_users']); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="icon-circle bg-success">
                                <i class="fas fa-shopping-cart text-white"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="small font-weight-bold text-success text-uppercase mb-1">
                                Bugünkü Siparişler
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['today_orders']); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="icon-circle bg-warning">
                                <i class="fas fa-clock text-white"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="small font-weight-bold text-warning text-uppercase mb-1">
                                Bekleyen Siparişler
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['pending_orders']); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="icon-circle bg-info">
                                <i class="fas fa-lira-sign text-white"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="small font-weight-bold text-info text-uppercase mb-1">
                                Bugünkü Gelir
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['today_revenue'], 2); ?> ₺
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Additional Stats Row -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="icon-circle bg-secondary">
                                <i class="fas fa-coffee text-white"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="small font-weight-bold text-secondary text-uppercase mb-1">
                                Aktif Ürünler
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['active_products']); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="icon-circle bg-purple">
                                <i class="fas fa-wallet text-white"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="small font-weight-bold text-purple text-uppercase mb-1">
                                Bekleyen Bakiye Talepleri
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['pending_balance_requests']); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="icon-circle bg-danger">
                                <i class="fas fa-chart-line text-white"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="small font-weight-bold text-danger text-uppercase mb-1">
                                Aylık Gelir
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['monthly_revenue'], 2); ?> ₺
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="icon-circle bg-dark">
                                <i class="fas fa-user-clock text-white"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="small font-weight-bold text-dark text-uppercase mb-1">
                                Aktif Kullanıcılar (24s)
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['active_users_24h']); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Content Row -->
    <div class="row">
        <!-- Son Siparişler -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-gradient-primary text-white d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold">Son Siparişler</h6>
                    <a href="orders.php" class="btn btn-light btn-sm">
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                <div class="card-body">
                    <?php if (empty($recent_orders)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
                            <p class="text-muted">Henüz sipariş bulunmuyor</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Sipariş #</th>
                                        <th>Müşteri</th>
                                        <th>Tutar</th>
                                        <th>Durum</th>
                                        <th>Tarih</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_orders as $order): ?>
                                        <tr>
                                            <td>
                                                <a href="orders.php?view=<?php echo $order['id']; ?>" class="text-decoration-none">
                                                    #<?php echo $order['id']; ?>
                                                </a>
                                            </td>
                                            <td><?php echo htmlspecialchars($order['first_name'] . ' ' . $order['last_name']); ?></td>
                                            <td><?php echo number_format($order['total_amount'], 2); ?> ₺</td>
                                            <td>
                                                <?php
                                                $status_classes = [
                                                    'pending' => 'bg-warning text-dark',
                                                    'preparing' => 'bg-info text-white',
                                                    'ready' => 'bg-success text-white',
                                                    'delivered' => 'bg-primary text-white',
                                                    'cancelled' => 'bg-danger text-white'
                                                ];
                                                $status_texts = [
                                                    'pending' => 'Bekliyor',
                                                    'preparing' => 'Hazırlanıyor',
                                                    'ready' => 'Hazır',
                                                    'delivered' => 'Teslim Edildi',
                                                    'cancelled' => 'İptal'
                                                ];
                                                ?>
                                                <span class="badge <?php echo $status_classes[$order['status']] ?? 'bg-secondary'; ?>">
                                                    <?php echo $status_texts[$order['status']] ?? $order['status']; ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('d.m.Y H:i', strtotime($order['created_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Bekleyen Bakiye Talepleri -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-gradient-primary text-white d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold">Bekleyen Bakiye Talepleri</h6>
                    <a href="balance.php" class="btn btn-light btn-sm">
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                <div class="card-body">
                    <?php if (empty($pending_balance)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-wallet fa-3x text-muted mb-3"></i>
                            <p class="text-muted">Bekleyen bakiye talebi yok</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Talep #</th>
                                        <th>Kullanıcı</th>
                                        <th>Tutar</th>
                                        <th>Tarih</th>
                                        <th>İşlem</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_balance as $request): ?>
                                        <tr>
                                            <td>#<?php echo $request['id']; ?></td>
                                            <td><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></td>
                                            <td><?php echo number_format($request['amount'], 2); ?> ₺</td>
                                            <td><?php echo date('d.m.Y H:i', strtotime($request['created_at'])); ?></td>
                                            <td>
                                                <a href="balance.php?request=<?php echo $request['id']; ?>" class="btn btn-sm btn-primary">
                                                    İncele
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row">
        <!-- Popüler Ürünler -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-gradient-primary text-white">
                    <h6 class="m-0 font-weight-bold">Popüler Ürünler (Son 30 Gün)</h6>
                </div>
                <div class="card-body">
                    <?php if (empty($popular_products)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-coffee fa-3x text-muted mb-3"></i>
                            <p class="text-muted">Henüz satış verisi yok</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($popular_products as $index => $product): ?>
                            <div class="d-flex align-items-center mb-3">
                                <div class="me-3">
                                    <span class="badge bg-primary rounded-pill"><?php echo $index + 1; ?></span>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="fw-bold"><?php echo htmlspecialchars($product['name']); ?></div>
                                    <div class="small text-muted"><?php echo number_format($product['total_sold']); ?> adet satıldı</div>
                                </div>
                                <div class="text-end">
                                    <div class="progress" style="width: 100px; height: 8px;">
                                        <div class="progress-bar bg-primary" 
                                             role="progressbar" 
                                             style="width: <?php echo min(100, ($product['total_sold'] / $popular_products[0]['total_sold']) * 100); ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Sipariş Trendi -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-gradient-primary text-white">
                    <h6 class="m-0 font-weight-bold">Son 7 Günün Sipariş Trendi</h6>
                </div>
                <div class="card-body">
                    <canvas id="orderTrendChart" width="400" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.icon-circle {
    width: 3rem;
    height: 3rem;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.bg-purple {
    background-color: #9f7aea !important;
}

.text-purple {
    color: #9f7aea !important;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Sipariş Trend Grafiği
const ctx = document.getElementById('orderTrendChart').getContext('2d');
const orderTrendChart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: [
            <?php 
            foreach ($order_trend as $day) {
                echo "'" . date('d.m', strtotime($day['date'])) . "',";
            }
            ?>
        ],
        datasets: [{
            label: 'Sipariş Sayısı',
            data: [
                <?php 
                foreach ($order_trend as $day) {
                    echo $day['order_count'] . ',';
                }
                ?>
            ],
            borderColor: 'rgb(102, 126, 234)',
            backgroundColor: 'rgba(102, 126, 234, 0.1)',
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    stepSize: 1
                }
            }
        }
    }
});

// Auto refresh every 30 seconds for real-time updates
setInterval(function() {
    // Refresh page content via AJAX if needed
    // For now, we'll just update the timestamp or specific elements
}, 30000);
</script>

<?php include 'includes/footer.php'; ?>