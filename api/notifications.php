<?php
/**
 * KahvePortal - Notifications API Endpoints
 * api/notifications.php
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

try {
    switch ($method) {
        case 'GET':
            handleGetNotifications($user_id, $db);
            break;
            
        case 'POST':
            if (isAdmin()) {
                handleCreateNotification($db);
            } else {
                errorResponse('Yetkiniz yok', 403);
            }
            break;
            
        case 'PUT':
            handleMarkAsRead($user_id, $db);
            break;
            
        case 'DELETE':
            handleDeleteNotification($user_id, $db);
            break;
            
        default:
            errorResponse('Desteklenmeyen HTTP metodu', 405);
    }
} catch (Exception $e) {
    error_log("Notifications API Error: " . $e->getMessage());
    errorResponse('Sunucu hatası', 500);
}

/**
 * Bildirimleri getir
 */
function handleGetNotifications($user_id, $db) {
    $page = (int)($_GET['page'] ?? 1);
    $limit = (int)($_GET['limit'] ?? 20);
    $unread_only = isset($_GET['unread_only']) && $_GET['unread_only'] === 'true';
    
    if ($page < 1) $page = 1;
    if ($limit < 1 || $limit > 50) $limit = 20;
    
    $offset = ($page - 1) * $limit;
    
    try {
        $where_clause = "WHERE (n.user_id = ? OR n.user_id IS NULL)";
        $params = [$user_id];
        
        if ($unread_only) {
            $where_clause .= " AND nr.is_read = 0";
        }
        
        // Toplam bildirim sayısı
        $count_stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM notifications n
            LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = ?
            $where_clause
        ");
        $count_stmt->execute([$user_id, $user_id]);
        $total_notifications = $count_stmt->fetchColumn();
        
        // Bildirimleri getir
        $stmt = $db->prepare("
            SELECT 
                n.id,
                n.title,
                n.message,
                n.type,
                n.action_url,
                n.created_at,
                COALESCE(nr.is_read, 0) as is_read,
                nr.read_at
            FROM notifications n
            LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = ?
            $where_clause
            ORDER BY n.created_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute(array_merge([$user_id], $params));
        $notifications = $stmt->fetchAll();
        
        // Okunmamış bildirim sayısı
        $unread_stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM notifications n
            LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = ?
            WHERE (n.user_id = ? OR n.user_id IS NULL) AND COALESCE(nr.is_read, 0) = 0
        ");
        $unread_stmt->execute([$user_id, $user_id]);
        $unread_count = $unread_stmt->fetchColumn();
        
        foreach ($notifications as &$notification) {
            $notification['is_read'] = (bool)$notification['is_read'];
        }
        
        jsonResponse([
            'success' => true,
            'notifications' => $notifications,
            'unread_count' => (int)$unread_count,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => ceil($total_notifications / $limit),
                'total_notifications' => (int)$total_notifications,
                'per_page' => $limit
            ]
        ]);
        
    } catch (PDOException $e) {
        error_log("Get notifications error: " . $e->getMessage());
        errorResponse('Bildirimler alınamadı');
    }
}

/**
 * Yeni bildirim oluştur (Admin)
 */
function handleCreateNotification($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['title']) || !isset($input['message'])) {
        errorResponse('Başlık ve mesaj gerekli');
    }
    
    $title = trim($input['title']);
    $message = trim($input['message']);
    $type = $input['type'] ?? 'info';
    $action_url = trim($input['action_url'] ?? '');
    $target_users = $input['target_users'] ?? 'all'; // all, specific, role
    $user_ids = $input['user_ids'] ?? [];
    $role = $input['role'] ?? '';
    
    if (empty($title) || empty($message)) {
        errorResponse('Başlık ve mesaj boş olamaz');
    }
    
    if (!in_array($type, ['info', 'success', 'warning', 'error', 'order', 'balance'])) {
        errorResponse('Geçersiz bildirim tipi');
    }
    
    // CSRF token kontrolü
    if (!isset($input['csrf_token']) || !validateCSRFToken($input['csrf_token'])) {
        errorResponse('Güvenlik hatası', 403);
    }
    
    try {
        $db->beginTransaction();
        
        if ($target_users === 'all') {
            // Tüm kullanıcılara genel bildirim
            $stmt = $db->prepare("
                INSERT INTO notifications (title, message, type, action_url, created_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$title, $message, $type, $action_url]);
            
        } elseif ($target_users === 'role' && !empty($role)) {
            // Belirli role sahip kullanıcılara
            $users_stmt = $db->prepare("SELECT id FROM users WHERE role = ? AND status = 'active'");
            $users_stmt->execute([$role]);
            $users = $users_stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($users as $user_id) {
                $stmt = $db->prepare("
                    INSERT INTO notifications (user_id, title, message, type, action_url, created_at) 
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$user_id, $title, $message, $type, $action_url]);
            }
            
        } elseif ($target_users === 'specific' && !empty($user_ids)) {
            // Belirli kullanıcılara
            foreach ($user_ids as $user_id) {
                $user_id = (int)$user_id;
                if ($user_id > 0) {
                    $stmt = $db->prepare("
                        INSERT INTO notifications (user_id, title, message, type, action_url, created_at) 
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$user_id, $title, $message, $type, $action_url]);
                }
            }
        } else {
            $db->rollBack();
            errorResponse('Geçersiz hedef kullanıcı seçimi');
        }
        
        $db->commit();
        
        jsonResponse([
            'success' => true,
            'message' => 'Bildirim başarıyla gönderildi'
        ]);
        
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Create notification error: " . $e->getMessage());
        errorResponse('Bildirim gönderilemedi');
    }
}

/**
 * Bildirimi okundu olarak işaretle
 */
function handleMarkAsRead($user_id, $db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['notification_id'])) {
        errorResponse('Bildirim ID gerekli');
    }
    
    $notification_id = (int)$input['notification_id'];
    $mark_all = $input['mark_all'] ?? false;
    
    try {
        if ($mark_all) {
            // Tüm bildirimleri okundu olarak işaretle
            $stmt = $db->prepare("
                INSERT INTO notification_reads (notification_id, user_id, is_read, read_at)
                SELECT n.id, ?, 1, NOW()
                FROM notifications n
                LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = ?
                WHERE (n.user_id = ? OR n.user_id IS NULL) AND nr.id IS NULL
            ");
            $stmt->execute([$user_id, $user_id, $user_id]);
            
            // Mevcut okunmamışları güncelle
            $update_stmt = $db->prepare("
                UPDATE notification_reads nr
                JOIN notifications n ON nr.notification_id = n.id
                SET nr.is_read = 1, nr.read_at = NOW()
                WHERE nr.user_id = ? AND nr.is_read = 0 AND (n.user_id = ? OR n.user_id IS NULL)
            ");
            $update_stmt->execute([$user_id, $user_id]);
            
            $message = 'Tüm bildirimler okundu olarak işaretlendi';
            
        } else {
            // Belirli bildirimi okundu olarak işaretle
            $check_stmt = $db->prepare("
                SELECT id FROM notifications 
                WHERE id = ? AND (user_id = ? OR user_id IS NULL)
            ");
            $check_stmt->execute([$notification_id, $user_id]);
            
            if (!$check_stmt->fetch()) {
                errorResponse('Bildirim bulunamadı');
            }
            
            // Mevcut okuma kaydını kontrol et
            $read_check_stmt = $db->prepare("
                SELECT id FROM notification_reads 
                WHERE notification_id = ? AND user_id = ?
            ");
            $read_check_stmt->execute([$notification_id, $user_id]);
            
            if ($read_check_stmt->fetch()) {
                // Güncelle
                $stmt = $db->prepare("
                    UPDATE notification_reads 
                    SET is_read = 1, read_at = NOW() 
                    WHERE notification_id = ? AND user_id = ?
                ");
                $stmt->execute([$notification_id, $user_id]);
            } else {
                // Yeni kayıt ekle
                $stmt = $db->prepare("
                    INSERT INTO notification_reads (notification_id, user_id, is_read, read_at) 
                    VALUES (?, ?, 1, NOW())
                ");
                $stmt->execute([$notification_id, $user_id]);
            }
            
            $message = 'Bildirim okundu olarak işaretlendi';
        }
        
        jsonResponse([
            'success' => true,
            'message' => $message
        ]);
        
    } catch (PDOException $e) {
        error_log("Mark as read error: " . $e->getMessage());
        errorResponse('Bildirim güncellenemedi');
    }
}

/**
 * Bildirimi sil
 */
function handleDeleteNotification($user_id, $db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        // URL'den notification_id al
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $segments = explode('/', trim($path, '/'));
        $notification_id = end($segments);
    } else {
        $notification_id = $input['notification_id'] ?? null;
    }
    
    if (!$notification_id) {
        errorResponse('Bildirim ID gerekli');
    }
    
    $notification_id = (int)$notification_id;
    
    try {
        // Sadece kullanıcıya özel bildirimleri silebilir
        $stmt = $db->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
        $result = $stmt->execute([$notification_id, $user_id]);
        
        if ($stmt->rowCount() === 0) {
            errorResponse('Bildirim bulunamadı veya silme yetkiniz yok');
        }
        
        jsonResponse([
            'success' => true,
            'message' => 'Bildirim silindi'
        ]);
        
    } catch (PDOException $e) {
        error_log("Delete notification error: " . $e->getMessage());
        errorResponse('Bildirim silinemedi');
    }
}

/**
 * Otomatik bildirim oluşturma fonksiyonları
 */
function createOrderNotification($user_id, $order_id, $status, $db) {
    $status_messages = [
        'pending' => ['Siparişiniz Alındı', 'Sipariş #' . $order_id . ' başarıyla alındı ve hazırlanmaya başlandı.'],
        'preparing' => ['Siparişiniz Hazırlanıyor', 'Sipariş #' . $order_id . ' hazırlanıyor.'],
        'ready' => ['Siparişiniz Hazır!', 'Sipariş #' . $order_id . ' hazır! Teslim alabilirsiniz.'],
        'delivered' => ['Sipariş Teslim Edildi', 'Sipariş #' . $order_id . ' teslim edildi. Afiyet olsun!'],
        'cancelled' => ['Sipariş İptal Edildi', 'Sipariş #' . $order_id . ' iptal edildi.']
    ];
    
    if (!isset($status_messages[$status])) return false;
    
    list($title, $message) = $status_messages[$status];
    
    try {
        $stmt = $db->prepare("
            INSERT INTO notifications (user_id, title, message, type, action_url, created_at) 
            VALUES (?, ?, ?, 'order', '/orders.php?id=?', NOW())
        ");
        return $stmt->execute([$user_id, $title, $message, $order_id]);
    } catch (PDOException $e) {
        error_log("Create order notification error: " . $e->getMessage());
        return false;
    }
}

function createBalanceNotification($user_id, $type, $amount, $db) {
    $type_messages = [
        'deposit' => ['Bakiye Yüklendi', number_format($amount, 2) . ' ₺ bakiye hesabınıza eklendi.'],
        'purchase' => ['Bakiye Kullanıldı', number_format($amount, 2) . ' ₺ bakiye hesabınızdan düşüldü.'],
        'refund' => ['Bakiye İadesi', number_format($amount, 2) . ' ₺ bakiye hesabınıza iade edildi.']
    ];
    
    if (!isset($type_messages[$type])) return false;
    
    list($title, $message) = $type_messages[$type];
    
    try {
        $stmt = $db->prepare("
            INSERT INTO notifications (user_id, title, message, type, action_url, created_at) 
            VALUES (?, ?, ?, 'balance', '/profile.php#balance', NOW())
        ");
        return $stmt->execute([$user_id, $title, $message]);
    } catch (PDOException $e) {
        error_log("Create balance notification error: " . $e->getMessage());
        return false;
    }
}

// Ek endpoint: Okunmamış bildirim sayısı
if (isset($_GET['unread_count']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM notifications n
            LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = ?
            WHERE (n.user_id = ? OR n.user_id IS NULL) AND COALESCE(nr.is_read, 0) = 0
        ");
        $stmt->execute([$user_id, $user_id]);
        $unread_count = $stmt->fetchColumn();
        
        jsonResponse([
            'success' => true,
            'unread_count' => (int)$unread_count
        ]);
        
    } catch (PDOException $e) {
        error_log("Get unread count error: " . $e->getMessage());
        errorResponse('Okunmamış bildirim sayısı alınamadı');
    }
}
?>