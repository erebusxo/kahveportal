<?php
/**
 * KahvePortal - Statistics API Endpoints
 * api/stats.php
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once '../config.php';
require_once '../includes/session.php';

// CORS headers (gerekirse)
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Max-Age: 86400");
}

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    }
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
    }
    exit(0);
}

// JSON response function
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Error response function
function errorResponse($message, $status = 400) {
    jsonResponse(['success' => false, 'message' => $message], $status);
}

// Session timeout kontrolü
if (!checkSessionTimeout()) {
    errorResponse('Session zaman aşımına uğradı', 401);
}

// Giriş kontrolü
if (!isLoggedIn()) {
    errorResponse('Giriş yapmanız gerekiyor', 401);
}

$method = $_SERVER['REQUEST_METHOD'];
$user_id = $_SESSION['user_id'];

if ($method !== 'GET') {
    errorResponse('Sadece GET metodu destekleniyor', 405);
}

$type = $_GET['type'] ?? 'overview';

try {
    switch ($type) {
        case 'overview':
            if (isAdmin()) {
                handleAdminOverview($db);
            } else {
                handleUserOverview($user_id, $db);
            }
            break;
            
        case 'sales':
            if (!isAdmin()) {
                errorResponse('Yetkiniz yok', 403);
            }
            handleSalesStats($db);
            break;
            
        case 'products':
            if (!isAdmin()) {
                errorResponse('Yetkiniz yok', 403);
            }
            handleProductStats($db);
            break;
            
        case 'users':
            if (!isAdmin()) {
                errorResponse('Yetkiniz yok', 403);
            }
            handleUserStats($db);
            break;
            
        case 'revenue':
            if (!isAdmin()) {
                errorResponse('Yetkiniz yok', 403);
            }
            handleRevenueStats($db);
            break;
            
        case 'user_activity':
            handleUserActivity($user_id, $db);
            break;
            
        default:
            errorResponse('Geçersiz istatistik tipi');
    }
} catch (Exception $e) {
    error_log("Stats API Error: " . $e->getMessage());
    errorResponse('Sunucu hatası', 500);
}

/**
 * Admin genel bakış istatistikleri
 */
function handleAdminOverview($db) {
    try {
        // Temel istatistikler
        $basic_stats = $db->query("
            SELECT 
                (SELECT COUNT(*) FROM users WHERE status = 'active') as total_users,
                (SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()) as today_orders,
                (SELECT COUNT(*) FROM orders WHERE status = 'pending') as pending_orders,
                (SELECT COUNT(*) FROM products WHERE is_active = 1) as active_products,
                (SELECT SUM(total_amount) FROM orders WHERE DATE(created_at) = CURDATE()) as today_revenue,
                (SELECT SUM(total_amount) FROM orders WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())) as monthly_revenue,
                (SELECT COUNT(*) FROM balance_requests WHERE status = 'pending') as pending_balance_requests
        ")->fetch();
        
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
        
        // Popüler ürünler (son 30 gün)
        $popular_products = $db->query("
            SELECT 
                p.name,
                SUM(oi.quantity) as total_sold,
                SUM(oi.quantity * oi.price) as revenue
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            JOIN orders o ON oi.order_id = o.id
            WHERE o.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY p.id, p.name
            ORDER BY total_sold DESC
            LIMIT 5
        ")->fetchAll();
        
        // Sipariş durumu dağılımı
        $order_status = $db->query("
            SELECT 
                status,
                COUNT(*) as count
            FROM orders 
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY status
        ")->fetchAll();
        
        // Aktif kullanıcılar (son 24 saat)
        $active_users = $db->query("
            SELECT COUNT(*) as count
            FROM users 
            WHERE last_activity >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ")->fetchColumn();
        
        jsonResponse([
            'success' => true,
            'basic_stats' => [
                'total_users' => (int)$basic_stats['total_users'],
                'today_orders' => (int)$basic_stats['today_orders'],
                'pending_orders' => (int)$basic_stats['pending_orders'],
                'active_products' => (int)$basic_stats['active_products'],
                'today_revenue' => (float)($basic_stats['today_revenue'] ?? 0),
                'monthly_revenue' => (float)($basic_stats['monthly_revenue'] ?? 0),
                'pending_balance_requests' => (int)$basic_stats['pending_balance_requests'],
                'active_users_24h' => (int)$active_users
            ],
            'order_trend' => array_map(function($item) {
                return [
                    'date' => $item['date'],
                    'order_count' => (int)$item['order_count'],
                    'revenue' => (float)$item['revenue']
                ];
            }, $order_trend),
            'popular_products' => array_map(function($item) {
                return [
                    'name' => $item['name'],
                    'total_sold' => (int)$item['total_sold'],
                    'revenue' => (float)$item['revenue']
                ];
            }, $popular_products),
            'order_status' => array_map(function($item) {
                return [
                    'status' => $item['status'],
                    'count' => (int)$item['count']
                ];
            }, $order_status)
        ]);
        
    } catch (PDOException $e) {
        error_log("Admin overview stats error: " . $e->getMessage());
        errorResponse('İstatistikler alınamadı');
    }
}

/**
 * Kullanıcı genel bakış istatistikleri
 */
function handleUserOverview($user_id, $db) {
    try {
        // Kullanıcı temel istatistikleri
        $user_stats = $db->prepare("
            SELECT 
                u.balance,
                u.created_at as join_date,
                (SELECT COUNT(*) FROM orders WHERE user_id = ?) as total_orders,
                (SELECT SUM(total_amount) FROM orders WHERE user_id = ?) as total_spent,
                (SELECT COUNT(*) FROM orders WHERE user_id = ? AND DATE(created_at) = CURDATE()) as today_orders,
                (SELECT COUNT(*) FROM orders WHERE user_id = ? AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())) as monthly_orders
            FROM users u WHERE u.id = ?
        ");
        $user_stats->execute([$user_id, $user_id, $user_id, $user_id, $user_id]);
        $stats = $user_stats->fetch();
        
        // Favori ürünler
        $favorite_products = $db->prepare("
            SELECT 
                p.name,
                SUM(oi.quantity) as order_count
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            JOIN orders o ON oi.order_id = o.id
            WHERE o.user_id = ?
            GROUP BY p.id, p.name
            ORDER BY order_count DESC
            LIMIT 5
        ");
        $favorite_products->execute([$user_id]);
        $favorites = $favorite_products->fetchAll();
        
        // Son sipariş durumu
        $last_order = $db->prepare("
            SELECT id, status, total_amount, created_at
            FROM orders 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $last_order->execute([$user_id]);
        $last_order_data = $last_order->fetch();
        
        // Aylık harcama trendi (son 6 ay)
        $monthly_spending = $db->prepare("
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                SUM(total_amount) as total_spent,
                COUNT(*) as order_count
            FROM orders 
            WHERE user_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month ASC
        ");
        $monthly_spending->execute([$user_id]);
        $spending_trend = $monthly_spending->fetchAll();
        
        jsonResponse([
            'success' => true,
            'user_stats' => [
                'balance' => (float)$stats['balance'],
                'total_orders' => (int)$stats['total_orders'],
                'total_spent' => (float)($stats['total_spent'] ?? 0),
                'today_orders' => (int)$stats['today_orders'],
                'monthly_orders' => (int)$stats['monthly_orders'],
                'member_since' => $stats['join_date']
            ],
            'favorite_products' => array_map(function($item) {
                return [
                    'name' => $item['name'],
                    'order_count' => (int)$item['order_count']
                ];
            }, $favorites),
            'last_order' => $last_order_data ? [
                'id' => (int)$last_order_data['id'],
                'status' => $last_order_data['status'],
                'total_amount' => (float)$last_order_data['total_amount'],
                'created_at' => $last_order_data['created_at']
            ] : null,
            'spending_trend' => array_map(function($item) {
                return [
                    'month' => $item['month'],
                    'total_spent' => (float)$item['total_spent'],
                    'order_count' => (int)$item['order_count']
                ];
            }, $spending_trend)
        ]);
        
    } catch (PDOException $e) {
        error_log("User overview stats error: " . $e->getMessage());
        errorResponse('İstatistikler alınamadı');
    }
}

/**
 * Satış istatistikleri (Admin)
 */
function handleSalesStats($db) {
    $period = $_GET['period'] ?? '30'; // days
    $period = (int)$period;
    
    if (!in_array($period, [7, 30, 90, 365])) {
        $period = 30;
    }
    
    try {
        // Günlük satış verileri
        $daily_sales = $db->query("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as order_count,
                SUM(total_amount) as revenue,
                AVG(total_amount) as avg_order_value
            FROM orders 
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL $period DAY)
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ")->fetchAll();
        
        // Saatlik satış verileri (bugün)
        $hourly_sales = $db->query("
            SELECT 
                HOUR(created_at) as hour,
                COUNT(*) as order_count,
                SUM(total_amount) as revenue
            FROM orders 
            WHERE DATE(created_at) = CURDATE()
            GROUP BY HOUR(created_at)
            ORDER BY hour ASC
        ")->fetchAll();
        
        // Ödeme yöntemi dağılımı
        $payment_methods = $db->query("
            SELECT 
                payment_method,
                COUNT(*) as count,
                SUM(total_amount) as revenue
            FROM orders 
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL $period DAY)
            GROUP BY payment_method
        ")->fetchAll();
        
        jsonResponse([
            'success' => true,
            'period' => $period,
            'daily_sales' => array_map(function($item) {
                return [
                    'date' => $item['date'],
                    'order_count' => (int)$item['order_count'],
                    'revenue' => (float)$item['revenue'],
                    'avg_order_value' => (float)$item['avg_order_value']
                ];
            }, $daily_sales),
            'hourly_sales' => array_map(function($item) {
                return [
                    'hour' => (int)$item['hour'],
                    'order_count' => (int)$item['order_count'],
                    'revenue' => (float)$item['revenue']
                ];
            }, $hourly_sales),
            'payment_methods' => array_map(function($item) {
                return [
                    'method' => $item['payment_method'],
                    'count' => (int)$item['count'],
                    'revenue' => (float)$item['revenue']
                ];
            }, $payment_methods)
        ]);
        
    } catch (PDOException $e) {
        error_log("Sales stats error: " . $e->getMessage());
        errorResponse('Satış istatistikleri alınamadı');
    }
}

/**
 * Ürün istatistikleri (Admin)
 */
function handleProductStats($db) {
    try {
        // En çok satan ürünler
        $top_products = $db->query("
            SELECT 
                p.id,
                p.name,
                p.price,
                c.name as category_name,
                SUM(oi.quantity) as total_sold,
                SUM(oi.quantity * oi.price) as revenue,
                COUNT(DISTINCT o.user_id) as unique_customers
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            JOIN orders o ON oi.order_id = o.id
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE o.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY p.id
            ORDER BY total_sold DESC
            LIMIT 20
        ")->fetchAll();
        
        // Kategori performansı
        $category_performance = $db->query("
            SELECT 
                c.name as category_name,
                COUNT(DISTINCT p.id) as product_count,
                SUM(oi.quantity) as total_sold,
                SUM(oi.quantity * oi.price) as revenue
            FROM categories c
            LEFT JOIN products p ON c.id = p.category_id
            LEFT JOIN order_items oi ON p.id = oi.product_id
            LEFT JOIN orders o ON oi.order_id = o.id
            WHERE o.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) OR o.id IS NULL
            GROUP BY c.id, c.name
            ORDER BY revenue DESC
        ")->fetchAll();
        
        // Stok durumu
        $stock_status = $db->query("
            SELECT 
                COUNT(CASE WHEN is_available = 1 THEN 1 END) as available,
                COUNT(CASE WHEN is_available = 0 THEN 1 END) as unavailable,
                COUNT(CASE WHEN is_active = 0 THEN 1 END) as inactive
            FROM products
        ")->fetch();
        
        jsonResponse([
            'success' => true,
            'top_products' => array_map(function($item) {
                return [
                    'id' => (int)$item['id'],
                    'name' => $item['name'],
                    'price' => (float)$item['price'],
                    'category_name' => $item['category_name'],
                    'total_sold' => (int)($item['total_sold'] ?? 0),
                    'revenue' => (float)($item['revenue'] ?? 0),
                    'unique_customers' => (int)($item['unique_customers'] ?? 0)
                ];
            }, $top_products),
            'category_performance' => array_map(function($item) {
                return [
                    'category_name' => $item['category_name'],
                    'product_count' => (int)($item['product_count'] ?? 0),
                    'total_sold' => (int)($item['total_sold'] ?? 0),
                    'revenue' => (float)($item['revenue'] ?? 0)
                ];
            }, $category_performance),
            'stock_status' => [
                'available' => (int)$stock_status['available'],
                'unavailable' => (int)$stock_status['unavailable'],
                'inactive' => (int)$stock_status['inactive']
            ]
        ]);
        
    } catch (PDOException $e) {
        error_log("Product stats error: " . $e->getMessage());
        errorResponse('Ürün istatistikleri alınamadı');
    }
}

/**
 * Kullanıcı istatistikleri (Admin)
 */
function handleUserStats($db) {
    try {
        // Kullanıcı kayıt trendi (son 12 ay)
        $registration_trend = $db->query("
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as new_users
            FROM users 
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month ASC
        ")->fetchAll();
        
        // En aktif kullanıcılar
        $active_users = $db->query("
            SELECT 
                u.first_name,
                u.last_name,
                u.email,
                COUNT(o.id) as order_count,
                SUM(o.total_amount) as total_spent,
                MAX(o.created_at) as last_order
            FROM users u
            LEFT JOIN orders o ON u.id = o.user_id
            WHERE u.status = 'active'
            GROUP BY u.id
            ORDER BY order_count DESC
            LIMIT 20
        ")->fetchAll();
        
        // Kullanıcı durumu
        $user_status = $db->query("
            SELECT 
                status,
                COUNT(*) as count
            FROM users 
            GROUP BY status
        ")->fetchAll();
        
        // Bakiye dağılımı
        $balance_distribution = $db->query("
            SELECT 
                CASE 
                    WHEN balance = 0 THEN '0 TL'
                    WHEN balance > 0 AND balance <= 50 THEN '1-50 TL'
                    WHEN balance > 50 AND balance <= 100 THEN '51-100 TL'
                    WHEN balance > 100 AND balance <= 200 THEN '101-200 TL'
                    ELSE '200+ TL'
                END as balance_range,
                COUNT(*) as user_count
            FROM users 
            WHERE status = 'active'
            GROUP BY balance_range
        ")->fetchAll();
        
        jsonResponse([
            'success' => true,
            'registration_trend' => array_map(function($item) {
                return [
                    'month' => $item['month'],
                    'new_users' => (int)$item['new_users']
                ];
            }, $registration_trend),
            'active_users' => array_map(function($item) {
                return [
                    'name' => $item['first_name'] . ' ' . $item['last_name'],
                    'email' => $item['email'],
                    'order_count' => (int)($item['order_count'] ?? 0),
                    'total_spent' => (float)($item['total_spent'] ?? 0),
                    'last_order' => $item['last_order']
                ];
            }, $active_users),
            'user_status' => array_map(function($item) {
                return [
                    'status' => $item['status'],
                    'count' => (int)$item['count']
                ];
            }, $user_status),
            'balance_distribution' => array_map(function($item) {
                return [
                    'range' => $item['balance_range'],
                    'user_count' => (int)$item['user_count']
                ];
            }, $balance_distribution)
        ]);
        
    } catch (PDOException $e) {
        error_log("User stats error: " . $e->getMessage());
        errorResponse('Kullanıcı istatistikleri alınamadı');
    }
}

/**
 * Gelir istatistikleri (Admin)
 */
function handleRevenueStats($db) {
    $period = $_GET['period'] ?? '30';
    $period = (int)$period;
    
    if (!in_array($period, [7, 30, 90, 365])) {
        $period = 30;
    }
    
    try {
        // Toplam gelir özeti
        $revenue_summary = $db->query("
            SELECT 
                SUM(total_amount) as total_revenue,
                AVG(total_amount) as avg_order_value,
                COUNT(*) as total_orders
            FROM orders 
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL $period DAY)
        ")->fetch();
        
        // Günlük gelir trendi
        $daily_revenue = $db->query("
            SELECT 
                DATE(created_at) as date,
                SUM(total_amount) as revenue,
                COUNT(*) as order_count
            FROM orders 
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL $period DAY)
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ")->fetchAll();
        
        // Kategori bazında gelir
        $category_revenue = $db->query("
            SELECT 
                c.name as category_name,
                SUM(oi.quantity * oi.price) as revenue,
                SUM(oi.quantity) as items_sold
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            JOIN categories c ON p.category_id = c.id
            JOIN orders o ON oi.order_id = o.id
            WHERE o.created_at >= DATE_SUB(CURDATE(), INTERVAL $period DAY)
            GROUP BY c.id, c.name
            ORDER BY revenue DESC
        ")->fetchAll();
        
        jsonResponse([
            'success' => true,
            'period' => $period,
            'summary' => [
                'total_revenue' => (float)($revenue_summary['total_revenue'] ?? 0),
                'avg_order_value' => (float)($revenue_summary['avg_order_value'] ?? 0),
                'total_orders' => (int)($revenue_summary['total_orders'] ?? 0)
            ],
            'daily_revenue' => array_map(function($item) {
                return [
                    'date' => $item['date'],
                    'revenue' => (float)$item['revenue'],
                    'order_count' => (int)$item['order_count']
                ];
            }, $daily_revenue),
            'category_revenue' => array_map(function($item) {
                return [
                    'category_name' => $item['category_name'],
                    'revenue' => (float)$item['revenue'],
                    'items_sold' => (int)$item['items_sold']
                ];
            }, $category_revenue)
        ]);
        
    } catch (PDOException $e) {
        error_log("Revenue stats error: " . $e->getMessage());
        errorResponse('Gelir istatistikleri alınamadı');
    }
}

/**
 * Kullanıcı aktivite istatistikleri
 */
function handleUserActivity($user_id, $db) {
    try {
        // Son aktivite
        $activity_stmt = $db->prepare("
            SELECT 
                'order' as type,
                created_at,
                CONCAT('Sipariş #', id, ' - ', total_amount, ' ₺') as description
            FROM orders 
            WHERE user_id = ?
            UNION ALL
            SELECT 
                'balance' as type,
                created_at,
                CONCAT(type, ' - ', ABS(amount), ' ₺') as description
            FROM balance_transactions 
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $activity_stmt->execute([$user_id, $user_id]);
        $recent_activity = $activity_stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'recent_activity' => array_map(function($item) {
                return [
                    'type' => $item['type'],
                    'description' => $item['description'],
                    'created_at' => $item['created_at']
                ];
            }, $recent_activity)
        ]);
        
    } catch (PDOException $e) {
        error_log("User activity stats error: " . $e->getMessage());
        errorResponse('Aktivite istatistikleri alınamadı');
    }
}
?>