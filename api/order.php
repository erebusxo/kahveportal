<?php
/**
 * KahvePortal - Order API Endpoints
 * api/order.php
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once '../config.php';
require_once '../includes/session.php';
require_once '../includes/mail.php';

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

try {
    switch ($method) {
        case 'GET':
            handleGetOrders($user_id, $db);
            break;
            
        case 'POST':
            handleCreateOrder($user_id, $db);
            break;
            
        case 'PUT':
            if (isAdmin()) {
                handleUpdateOrderStatus($db);
            } else {
                errorResponse('Yetkiniz yok', 403);
            }
            break;
            
        case 'DELETE':
            handleCancelOrder($user_id, $db);
            break;
            
        default:
            errorResponse('Desteklenmeyen HTTP metodu', 405);
    }
} catch (Exception $e) {
    error_log("Order API Error: " . $e->getMessage());
    errorResponse('Sunucu hatası', 500);
}

/**
 * Siparişleri getir
 */
function handleGetOrders($user_id, $db) {
    $page = (int)($_GET['page'] ?? 1);
    $limit = (int)($_GET['limit'] ?? 10);
    $status = $_GET['status'] ?? null;
    
    if ($page < 1) $page = 1;
    if ($limit < 1 || $limit > 50) $limit = 10;
    
    $offset = ($page - 1) * $limit;
    
    try {
        $where_clause = "WHERE o.user_id = ?";
        $params = [$user_id];
        
        if ($status && in_array($status, ['pending', 'preparing', 'ready', 'delivered', 'cancelled'])) {
            $where_clause .= " AND o.status = ?";
            $params[] = $status;
        }
        
        // Toplam sipariş sayısı
        $count_stmt = $db->prepare("SELECT COUNT(*) FROM orders o $where_clause");
        $count_stmt->execute($params);
        $total_orders = $count_stmt->fetchColumn();
        
        // Siparişleri getir
        $stmt = $db->prepare("
            SELECT 
                o.id,
                o.total_amount,
                o.status,
                o.payment_method,
                o.notes,
                o.created_at,
                o.updated_at,
                COUNT(oi.id) as item_count
            FROM orders o
            LEFT JOIN order_items oi ON o.id = oi.order_id
            $where_clause
            GROUP BY o.id
            ORDER BY o.created_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $orders = $stmt->fetchAll();
        
        // Her sipariş için detayları getir
        foreach ($orders as &$order) {
            $item_stmt = $db->prepare("
                SELECT 
                    oi.product_id,
                    oi.quantity,
                    oi.price,
                    oi.options,
                    p.name,
                    p.image
                FROM order_items oi
                JOIN products p ON oi.product_id = p.id
                WHERE oi.order_id = ?
            ");
            $item_stmt->execute([$order['id']]);
            $order['items'] = $item_stmt->fetchAll();
            
            // Seçenekleri decode et
            foreach ($order['items'] as &$item) {
                $item['options'] = json_decode($item['options'], true) ?? [];
                $item['price'] = (float)$item['price'];
                $item['quantity'] = (int)$item['quantity'];
            }
            
            $order['total_amount'] = (float)$order['total_amount'];
            $order['item_count'] = (int)$order['item_count'];
        }
        
        jsonResponse([
            'success' => true,
            'orders' => $orders,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => ceil($total_orders / $limit),
                'total_orders' => (int)$total_orders,
                'per_page' => $limit
            ]
        ]);
        
    } catch (PDOException $e) {
        error_log("Get orders error: " . $e->getMessage());
        errorResponse('Siparişler alınamadı');
    }
}

/**
 * Yeni sipariş oluştur
 */
function handleCreateOrder($user_id, $db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        errorResponse('Geçersiz istek');
    }
    
    // CSRF token kontrolü
    if (!isset($input['csrf_token']) || !validateCSRFToken($input['csrf_token'])) {
        errorResponse('Güvenlik hatası', 403);
    }
    
    $payment_method = $input['payment_method'] ?? 'balance';
    $notes = trim($input['notes'] ?? '');
    
    if (!in_array($payment_method, ['balance', 'cash', 'card'])) {
        errorResponse('Geçersiz ödeme yöntemi');
    }
    
    try {
        $db->beginTransaction();
        
        // Kullanıcı bilgilerini getir
        $user_stmt = $db->prepare("SELECT balance, first_name, last_name, email FROM users WHERE id = ?");
        $user_stmt->execute([$user_id]);
        $user = $user_stmt->fetch();
        
        if (!$user) {
            $db->rollBack();
            errorResponse('Kullanıcı bulunamadı');
        }
        
        // Sepet öğelerini getir
        $cart_stmt = $db->prepare("
            SELECT 
                ci.product_id,
                ci.quantity,
                ci.options,
                p.name,
                p.price,
                p.is_active,
                p.is_available
            FROM cart_items ci
            JOIN products p ON ci.product_id = p.id
            WHERE ci.user_id = ? AND p.is_active = 1
        ");
        $cart_stmt->execute([$user_id]);
        $cart_items = $cart_stmt->fetchAll();
        
        if (empty($cart_items)) {
            $db->rollBack();
            errorResponse('Sepetiniz boş');
        }
        
        // Toplam tutarı hesapla
        $total_amount = 0;
        $order_items = [];
        
        foreach ($cart_items as $item) {
            if (!$item['is_available']) {
                $db->rollBack();
                errorResponse($item['name'] . ' ürünü artık mevcut değil');
            }
            
            $options = json_decode($item['options'], true) ?? [];
            $item_price = $item['price'];
            
            // Seçenekler için ek fiyat hesaplama
            foreach ($options as $option) {
                if (isset($option['price'])) {
                    $item_price += $option['price'];
                }
            }
            
            $item_total = $item_price * $item['quantity'];
            $total_amount += $item_total;
            
            $order_items[] = [
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'price' => $item_price,
                'options' => $item['options'],
                'name' => $item['name']
            ];
        }
        
        // Bakiye kontrolü (balance ödeme yöntemi için)
        if ($payment_method === 'balance' && $user['balance'] < $total_amount) {
            $db->rollBack();
            errorResponse('Yetersiz bakiye. Mevcut bakiye: ' . number_format($user['balance'], 2) . ' ₺');
        }
        
        // Sipariş oluştur
        $order_stmt = $db->prepare("
            INSERT INTO orders (user_id, total_amount, status, payment_method, notes, created_at) 
            VALUES (?, ?, 'pending', ?, ?, NOW())
        ");
        $order_stmt->execute([$user_id, $total_amount, $payment_method, $notes]);
        $order_id = $db->lastInsertId();
        
        // Sipariş öğelerini ekle
        $item_stmt = $db->prepare("
            INSERT INTO order_items (order_id, product_id, quantity, price, options) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        foreach ($order_items as $item) {
            $item_stmt->execute([
                $order_id,
                $item['product_id'],
                $item['quantity'],
                $item['price'],
                $item['options']
            ]);
            
            // Ürün sipariş sayısını artır
            $update_stmt = $db->prepare("UPDATE products SET order_count = order_count + ? WHERE id = ?");
            $update_stmt->execute([$item['quantity'], $item['product_id']]);
        }
        
        // Bakiyeden düş (balance ödeme yöntemi için)
        if ($payment_method === 'balance') {
            $new_balance = $user['balance'] - $total_amount;
            $balance_stmt = $db->prepare("UPDATE users SET balance = ? WHERE id = ?");
            $balance_stmt->execute([$new_balance, $user_id]);
            
            // Session'daki bakiyeyi güncelle
            $_SESSION['user_balance'] = $new_balance;
            
            // Bakiye işlemi kaydet
            $transaction_stmt = $db->prepare("
                INSERT INTO balance_transactions (user_id, type, amount, description, created_at) 
                VALUES (?, 'purchase', ?, ?, NOW())
            ");
            $transaction_stmt->execute([
                $user_id, 
                -$total_amount, 
                'Sipariş #' . $order_id . ' ödemesi'
            ]);
        }
        
        // Sepeti temizle
        $clear_cart_stmt = $db->prepare("DELETE FROM cart_items WHERE user_id = ?");
        $clear_cart_stmt->execute([$user_id]);
        
        $db->commit();
        
        // Email bildirim gönder
        $order_data = [
            'order_id' => $order_id,
            'total' => $total_amount,
            'items' => $order_items,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        sendOrderConfirmationEmail(
            $user['email'], 
            $user['first_name'] . ' ' . $user['last_name'], 
            $order_data
        );
        
        jsonResponse([
            'success' => true,
            'message' => 'Siparişiniz başarıyla oluşturuldu',
            'order_id' => $order_id,
            'total_amount' => $total_amount,
            'new_balance' => $payment_method === 'balance' ? $new_balance : null
        ]);
        
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Create order error: " . $e->getMessage());
        errorResponse('Sipariş oluşturulamadı');
    }
}

/**
 * Sipariş durumunu güncelle (Admin)
 */
function handleUpdateOrderStatus($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['order_id']) || !isset($input['status'])) {
        errorResponse('Geçersiz istek');
    }
    
    $order_id = (int)$input['order_id'];
    $status = $input['status'];
    $admin_notes = trim($input['admin_notes'] ?? '');
    
    if (!in_array($status, ['pending', 'preparing', 'ready', 'delivered', 'cancelled'])) {
        errorResponse('Geçersiz durum');
    }
    
    // CSRF token kontrolü
    if (!isset($input['csrf_token']) || !validateCSRFToken($input['csrf_token'])) {
        errorResponse('Güvenlik hatası', 403);
    }
    
    try {
        $db->beginTransaction();
        
        // Mevcut sipariş bilgilerini getir
        $order_stmt = $db->prepare("
            SELECT o.*, u.email, u.first_name, u.last_name 
            FROM orders o 
            JOIN users u ON o.user_id = u.id 
            WHERE o.id = ?
        ");
        $order_stmt->execute([$order_id]);
        $order = $order_stmt->fetch();
        
        if (!$order) {
            $db->rollBack();
            errorResponse('Sipariş bulunamadı');
        }
        
        // İptal durumu kontrolleri
        if ($status === 'cancelled' && $order['status'] === 'delivered') {
            $db->rollBack();
            errorResponse('Teslim edilmiş sipariş iptal edilemez');
        }
        
        // Sipariş durumunu güncelle
        $update_stmt = $db->prepare("
            UPDATE orders 
            SET status = ?, admin_notes = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $update_stmt->execute([$status, $admin_notes, $order_id]);
        
        // İptal durumunda bakiye iadesi (balance ödeme yöntemi için)
        if ($status === 'cancelled' && $order['payment_method'] === 'balance' && $order['status'] !== 'cancelled') {
            $refund_stmt = $db->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $refund_stmt->execute([$order['total_amount'], $order['user_id']]);
            
            // Bakiye işlemi kaydet
            $transaction_stmt = $db->prepare("
                INSERT INTO balance_transactions (user_id, type, amount, description, created_at) 
                VALUES (?, 'refund', ?, ?, NOW())
            ");
            $transaction_stmt->execute([
                $order['user_id'], 
                $order['total_amount'], 
                'Sipariş #' . $order_id . ' iadesi'
            ]);
        }
        
        $db->commit();
        
        // Email bildirim gönder
        $status_messages = [
            'preparing' => 'Siparişiniz hazırlanmaya başladı.',
            'ready' => 'Siparişiniz hazır! Teslim alabilirsiniz.',
            'delivered' => 'Siparişiniz teslim edildi. Afiyet olsun!',
            'cancelled' => 'Siparişiniz iptal edildi.' . ($admin_notes ? ' Sebep: ' . $admin_notes : '')
        ];
        
        if (isset($status_messages[$status])) {
            sendOrderStatusEmail(
                $order['email'],
                $order['first_name'] . ' ' . $order['last_name'],
                $order_id,
                $status,
                $status_messages[$status]
            );
        }
        
        jsonResponse([
            'success' => true,
            'message' => 'Sipariş durumu güncellendi',
            'order_id' => $order_id,
            'new_status' => $status
        ]);
        
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Update order status error: " . $e->getMessage());
        errorResponse('Sipariş durumu güncellenemedi');
    }
}

/**
 * Siparişi iptal et (Kullanıcı)
 */
function handleCancelOrder($user_id, $db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['order_id'])) {
        errorResponse('Sipariş ID gerekli');
    }
    
    $order_id = (int)$input['order_id'];
    $cancel_reason = trim($input['reason'] ?? '');
    
    // CSRF token kontrolü
    if (!isset($input['csrf_token']) || !validateCSRFToken($input['csrf_token'])) {
        errorResponse('Güvenlik hatası', 403);
    }
    
    try {
        $db->beginTransaction();
        
        // Sipariş kontrolü
        $order_stmt = $db->prepare("
            SELECT * FROM orders 
            WHERE id = ? AND user_id = ?
        ");
        $order_stmt->execute([$order_id, $user_id]);
        $order = $order_stmt->fetch();
        
        if (!$order) {
            $db->rollBack();
            errorResponse('Sipariş bulunamadı');
        }
        
        if ($order['status'] === 'delivered') {
            $db->rollBack();
            errorResponse('Teslim edilmiş sipariş iptal edilemez');
        }
        
        if ($order['status'] === 'cancelled') {
            $db->rollBack();
            errorResponse('Sipariş zaten iptal edilmiş');
        }
        
        // Siparişi iptal et
        $cancel_stmt = $db->prepare("
            UPDATE orders 
            SET status = 'cancelled', notes = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $cancel_stmt->execute([$cancel_reason, $order_id]);
        
        // Bakiye iadesi (balance ödeme yöntemi için)
        if ($order['payment_method'] === 'balance') {
            $refund_stmt = $db->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $refund_stmt->execute([$order['total_amount'], $user_id]);
            
            // Session'daki bakiyeyi güncelle
            $_SESSION['user_balance'] += $order['total_amount'];
            
            // Bakiye işlemi kaydet
            $transaction_stmt = $db->prepare("
                INSERT INTO balance_transactions (user_id, type, amount, description, created_at) 
                VALUES (?, 'refund', ?, ?, NOW())
            ");
            $transaction_stmt->execute([
                $user_id, 
                $order['total_amount'], 
                'Sipariş #' . $order_id . ' iadesi'
            ]);
        }
        
        $db->commit();
        
        jsonResponse([
            'success' => true,
            'message' => 'Siparişiniz iptal edildi',
            'refund_amount' => $order['payment_method'] === 'balance' ? $order['total_amount'] : 0
        ]);
        
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Cancel order error: " . $e->getMessage());
        errorResponse('Sipariş iptal edilemedi');
    }
}
?>