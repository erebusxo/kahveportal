<?php
/**
 * KahvePortal - Balance API Endpoints
 * api/balance.php
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
            if (isset($_GET['transactions'])) {
                handleGetTransactions($user_id, $db);
            } else {
                handleGetBalance($user_id, $db);
            }
            break;
            
        case 'POST':
            handleAddBalance($user_id, $db);
            break;
            
        case 'PUT':
            if (isAdmin()) {
                handleUpdateBalance($db);
            } else {
                errorResponse('Yetkiniz yok', 403);
            }
            break;
            
        default:
            errorResponse('Desteklenmeyen HTTP metodu', 405);
    }
} catch (Exception $e) {
    error_log("Balance API Error: " . $e->getMessage());
    errorResponse('Sunucu hatası', 500);
}

/**
 * Kullanıcı bakiyesini getir
 */
function handleGetBalance($user_id, $db) {
    try {
        $stmt = $db->prepare("SELECT balance FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if (!$user) {
            errorResponse('Kullanıcı bulunamadı');
        }
        
        // Son 30 günün harcama özeti
        $spending_stmt = $db->prepare("
            SELECT 
                SUM(CASE WHEN type = 'purchase' THEN ABS(amount) ELSE 0 END) as total_spent,
                SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END) as total_deposited,
                COUNT(CASE WHEN type = 'purchase' THEN 1 END) as purchase_count
            FROM balance_transactions 
            WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $spending_stmt->execute([$user_id]);
        $spending = $spending_stmt->fetch();
        
        jsonResponse([
            'success' => true,
            'balance' => (float)$user['balance'],
            'currency' => CURRENCY_SYMBOL,
            'monthly_summary' => [
                'total_spent' => (float)($spending['total_spent'] ?? 0),
                'total_deposited' => (float)($spending['total_deposited'] ?? 0),
                'purchase_count' => (int)($spending['purchase_count'] ?? 0)
            ]
        ]);
        
    } catch (PDOException $e) {
        error_log("Get balance error: " . $e->getMessage());
        errorResponse('Bakiye bilgisi alınamadı');
    }
}

/**
 * Bakiye işlemlerini getir
 */
function handleGetTransactions($user_id, $db) {
    $page = (int)($_GET['page'] ?? 1);
    $limit = (int)($_GET['limit'] ?? 20);
    $type = $_GET['type'] ?? null;
    
    if ($page < 1) $page = 1;
    if ($limit < 1 || $limit > 100) $limit = 20;
    
    $offset = ($page - 1) * $limit;
    
    try {
        $where_clause = "WHERE user_id = ?";
        $params = [$user_id];
        
        if ($type && in_array($type, ['deposit', 'purchase', 'refund'])) {
            $where_clause .= " AND type = ?";
            $params[] = $type;
        }
        
        // Toplam işlem sayısı
        $count_stmt = $db->prepare("SELECT COUNT(*) FROM balance_transactions $where_clause");
        $count_stmt->execute($params);
        $total_transactions = $count_stmt->fetchColumn();
        
        // İşlemleri getir
        $stmt = $db->prepare("
            SELECT 
                id,
                type,
                amount,
                description,
                created_at
            FROM balance_transactions
            $where_clause
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $transactions = $stmt->fetchAll();
        
        // İşlem tiplerini Türkçeye çevir
        $type_translations = [
            'deposit' => 'Bakiye Yükleme',
            'purchase' => 'Satın Alma',
            'refund' => 'İade'
        ];
        
        foreach ($transactions as &$transaction) {
            $transaction['amount'] = (float)$transaction['amount'];
            $transaction['type_text'] = $type_translations[$transaction['type']] ?? $transaction['type'];
            $transaction['is_positive'] = $transaction['amount'] > 0;
        }
        
        jsonResponse([
            'success' => true,
            'transactions' => $transactions,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => ceil($total_transactions / $limit),
                'total_transactions' => (int)$total_transactions,
                'per_page' => $limit
            ]
        ]);
        
    } catch (PDOException $e) {
        error_log("Get transactions error: " . $e->getMessage());
        errorResponse('İşlem geçmişi alınamadı');
    }
}

/**
 * Bakiye yükle
 */
function handleAddBalance($user_id, $db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['amount'])) {
        errorResponse('Tutar gerekli');
    }
    
    $amount = (float)$input['amount'];
    $receipt_file = $input['receipt_file'] ?? null;
    $description = trim($input['description'] ?? '');
    
    if ($amount <= 0 || $amount > 10000) {
        errorResponse('Geçersiz tutar (0-10000 TL arası)');
    }
    
    // CSRF token kontrolü
    if (!isset($input['csrf_token']) || !validateCSRFToken($input['csrf_token'])) {
        errorResponse('Güvenlik hatası', 403);
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
        
        // Bakiye talebini kaydet (admin onayı için)
        $request_stmt = $db->prepare("
            INSERT INTO balance_requests (user_id, amount, receipt_file, description, status, created_at) 
            VALUES (?, ?, ?, ?, 'pending', NOW())
        ");
        $request_stmt->execute([$user_id, $amount, $receipt_file, $description]);
        $request_id = $db->lastInsertId();
        
        $db->commit();
        
        jsonResponse([
            'success' => true,
            'message' => 'Bakiye yükleme talebiniz gönderildi. Admin onayından sonra bakiyenize eklenecektir.',
            'request_id' => $request_id,
            'amount' => $amount
        ]);
        
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Add balance error: " . $e->getMessage());
        errorResponse('Bakiye yükleme talebi gönderilemedi');
    }
}

/**
 * Bakiye güncelle (Admin)
 */
function handleUpdateBalance($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['user_id']) || !isset($input['action'])) {
        errorResponse('Geçersiz istek');
    }
    
    $user_id = (int)$input['user_id'];
    $action = $input['action']; // approve_request, reject_request, direct_update
    $admin_notes = trim($input['admin_notes'] ?? '');
    
    // CSRF token kontrolü
    if (!isset($input['csrf_token']) || !validateCSRFToken($input['csrf_token'])) {
        errorResponse('Güvenlik hatası', 403);
    }
    
    try {
        $db->beginTransaction();
        
        switch ($action) {
            case 'approve_request':
                $request_id = (int)$input['request_id'];
                handleApproveBalanceRequest($request_id, $admin_notes, $db);
                break;
                
            case 'reject_request':
                $request_id = (int)$input['request_id'];
                handleRejectBalanceRequest($request_id, $admin_notes, $db);
                break;
                
            case 'direct_update':
                $amount = (float)$input['amount'];
                $description = trim($input['description'] ?? '');
                handleDirectBalanceUpdate($user_id, $amount, $description, $db);
                break;
                
            default:
                $db->rollBack();
                errorResponse('Geçersiz işlem');
        }
        
        $db->commit();
        
        jsonResponse([
            'success' => true,
            'message' => 'İşlem başarıyla tamamlandı'
        ]);
        
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Update balance error: " . $e->getMessage());
        errorResponse('Bakiye güncellenemedi');
    }
}

/**
 * Bakiye talebini onayla
 */
function handleApproveBalanceRequest($request_id, $admin_notes, $db) {
    // Talebi getir
    $request_stmt = $db->prepare("
        SELECT br.*, u.balance, u.first_name, u.last_name, u.email 
        FROM balance_requests br
        JOIN users u ON br.user_id = u.id
        WHERE br.id = ? AND br.status = 'pending'
    ");
    $request_stmt->execute([$request_id]);
    $request = $request_stmt->fetch();
    
    if (!$request) {
        throw new Exception('Bakiye talebi bulunamadı veya zaten işlenmiş');
    }
    
    // Bakiyeyi güncelle
    $new_balance = $request['balance'] + $request['amount'];
    $balance_stmt = $db->prepare("UPDATE users SET balance = ? WHERE id = ?");
    $balance_stmt->execute([$new_balance, $request['user_id']]);
    
    // Talebi onayla
    $update_request_stmt = $db->prepare("
        UPDATE balance_requests 
        SET status = 'approved', admin_notes = ?, processed_at = NOW() 
        WHERE id = ?
    ");
    $update_request_stmt->execute([$admin_notes, $request_id]);
    
    // İşlemi kaydet
    $transaction_stmt = $db->prepare("
        INSERT INTO balance_transactions (user_id, type, amount, description, created_at) 
        VALUES (?, 'deposit', ?, ?, NOW())
    ");
    $transaction_stmt->execute([
        $request['user_id'], 
        $request['amount'], 
        'Bakiye yükleme - Talep #' . $request_id
    ]);
    
    // Email bildirim gönder
    sendBalanceNotificationEmail(
        $request['email'],
        $request['first_name'] . ' ' . $request['last_name'],
        'deposit',
        $request['amount'],
        $new_balance
    );
    
    // Session güncelle (eğer mevcut kullanıcıysa)
    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $request['user_id']) {
        $_SESSION['user_balance'] = $new_balance;
    }
}

/**
 * Bakiye talebini reddet
 */
function handleRejectBalanceRequest($request_id, $admin_notes, $db) {
    // Talebi getir
    $request_stmt = $db->prepare("
        SELECT br.*, u.first_name, u.last_name, u.email 
        FROM balance_requests br
        JOIN users u ON br.user_id = u.id
        WHERE br.id = ? AND br.status = 'pending'
    ");
    $request_stmt->execute([$request_id]);
    $request = $request_stmt->fetch();
    
    if (!$request) {
        throw new Exception('Bakiye talebi bulunamadı veya zaten işlenmiş');
    }
    
    // Talebi reddet
    $update_request_stmt = $db->prepare("
        UPDATE balance_requests 
        SET status = 'rejected', admin_notes = ?, processed_at = NOW() 
        WHERE id = ?
    ");
    $update_request_stmt->execute([$admin_notes, $request_id]);
    
    // Email bildirim gönder
    sendNotificationEmail(
        $request['email'],
        $request['first_name'] . ' ' . $request['last_name'],
        'Bakiye Yükleme Talebi Reddedildi',
        '<p>Bakiye yükleme talebiniz reddedildi.</p>' .
        '<p><strong>Tutar:</strong> ' . number_format($request['amount'], 2) . ' ₺</p>' .
        ($admin_notes ? '<p><strong>Red Sebebi:</strong> ' . htmlspecialchars($admin_notes) . '</p>' : '') .
        '<p>Sorularınız için bizimle iletişime geçebilirsiniz.</p>'
    );
}

/**
 * Doğrudan bakiye güncelleme
 */
function handleDirectBalanceUpdate($user_id, $amount, $description, $db) {
    if ($amount == 0) {
        throw new Exception('Tutar sıfır olamaz');
    }
    
    // Kullanıcı bilgilerini getir
    $user_stmt = $db->prepare("SELECT balance, first_name, last_name, email FROM users WHERE id = ?");
    $user_stmt->execute([$user_id]);
    $user = $user_stmt->fetch();
    
    if (!$user) {
        throw new Exception('Kullanıcı bulunamadı');
    }
    
    $new_balance = $user['balance'] + $amount;
    
    if ($new_balance < 0) {
        throw new Exception('Bakiye eksi değer alamaz');
    }
    
    // Bakiyeyi güncelle
    $balance_stmt = $db->prepare("UPDATE users SET balance = ? WHERE id = ?");
    $balance_stmt->execute([$new_balance, $user_id]);
    
    // İşlemi kaydet
    $type = $amount > 0 ? 'deposit' : 'purchase';
    $transaction_stmt = $db->prepare("
        INSERT INTO balance_transactions (user_id, type, amount, description, created_at) 
        VALUES (?, ?, ?, ?, NOW())
    ");
    $transaction_stmt->execute([$user_id, $type, $amount, $description]);
    
    // Email bildirim gönder
    sendBalanceNotificationEmail(
        $user['email'],
        $user['first_name'] . ' ' . $user['last_name'],
        $type,
        abs($amount),
        $new_balance
    );
    
    // Session güncelle (eğer mevcut kullanıcıysa)
    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user_id) {
        $_SESSION['user_balance'] = $new_balance;
    }
}

/**
 * Bekleyen bakiye taleplerini getir (Admin)
 */
if (isset($_GET['pending_requests']) && isAdmin()) {
    try {
        $stmt = $db->query("
            SELECT 
                br.*,
                u.first_name,
                u.last_name,
                u.email
            FROM balance_requests br
            JOIN users u ON br.user_id = u.id
            WHERE br.status = 'pending'
            ORDER BY br.created_at ASC
        ");
        $requests = $stmt->fetchAll();
        
        foreach ($requests as &$request) {
            $request['amount'] = (float)$request['amount'];
        }
        
        jsonResponse([
            'success' => true,
            'requests' => $requests
        ]);
        
    } catch (PDOException $e) {
        error_log("Get pending requests error: " . $e->getMessage());
        errorResponse('Bekleyen talepler alınamadı');
    }
}
?>