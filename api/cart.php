<?php
/**
 * KahvePortal - Cart API Endpoints
 * api/cart.php
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
            handleGetCart($user_id, $db);
            break;
            
        case 'POST':
            handleAddToCart($user_id, $db);
            break;
            
        case 'PUT':
            handleUpdateCart($user_id, $db);
            break;
            
        case 'DELETE':
            handleRemoveFromCart($user_id, $db);
            break;
            
        default:
            errorResponse('Desteklenmeyen HTTP metodu', 405);
    }
} catch (Exception $e) {
    error_log("Cart API Error: " . $e->getMessage());
    errorResponse('Sunucu hatası', 500);
}

/**
 * Sepeti getir
 */
function handleGetCart($user_id, $db) {
    try {
        $stmt = $db->prepare("
            SELECT 
                ci.id,
                ci.product_id,
                ci.quantity,
                ci.options,
                ci.created_at,
                p.name,
                p.description,
                p.price,
                p.image,
                p.is_available,
                c.name as category_name
            FROM cart_items ci
            JOIN products p ON ci.product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE ci.user_id = ? AND p.is_active = 1
            ORDER BY ci.created_at DESC
        ");
        
        $stmt->execute([$user_id]);
        $items = $stmt->fetchAll();
        
        $cart = [];
        $total = 0;
        $total_items = 0;
        
        foreach ($items as $item) {
            $options = json_decode($item['options'], true) ?? [];
            $item_total = $item['price'] * $item['quantity'];
            
            // Seçenekler için ek fiyat hesaplama
            foreach ($options as $option) {
                if (isset($option['price'])) {
                    $item_total += $option['price'] * $item['quantity'];
                }
            }
            
            $cart[] = [
                'id' => $item['id'],
                'product_id' => $item['product_id'],
                'name' => $item['name'],
                'description' => $item['description'],
                'price' => (float)$item['price'],
                'quantity' => (int)$item['quantity'],
                'options' => $options,
                'item_total' => $item_total,
                'image' => $item['image'],
                'is_available' => (bool)$item['is_available'],
                'category_name' => $item['category_name'],
                'created_at' => $item['created_at']
            ];
            
            if ($item['is_available']) {
                $total += $item_total;
                $total_items += $item['quantity'];
            }
        }
        
        jsonResponse([
            'success' => true,
            'cart' => $cart,
            'summary' => [
                'total_items' => $total_items,
                'total_amount' => $total,
                'currency' => CURRENCY_SYMBOL
            ]
        ]);
        
    } catch (PDOException $e) {
        error_log("Cart fetch error: " . $e->getMessage());
        errorResponse('Sepet bilgisi alınamadı');
    }
}

/**
 * Sepete ürün ekle
 */
function handleAddToCart($user_id, $db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['product_id'])) {
        errorResponse('Geçersiz istek');
    }
    
    $product_id = (int)$input['product_id'];
    $quantity = (int)($input['quantity'] ?? 1);
    $options = $input['options'] ?? [];
    
    if ($quantity < 1 || $quantity > 50) {
        errorResponse('Geçersiz adet');
    }
    
    // CSRF token kontrolü
    if (!isset($input['csrf_token']) || !validateCSRFToken($input['csrf_token'])) {
        errorResponse('Güvenlik hatası', 403);
    }
    
    try {
        // Ürün kontrolü
        $stmt = $db->prepare("SELECT id, name, price, is_active, is_available FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch();
        
        if (!$product) {
            errorResponse('Ürün bulunamadı');
        }
        
        if (!$product['is_active'] || !$product['is_available']) {
            errorResponse('Ürün şu anda mevcut değil');
        }
        
        // Sepette aynı ürün var mı kontrol et
        $stmt = $db->prepare("
            SELECT id, quantity 
            FROM cart_items 
            WHERE user_id = ? AND product_id = ? AND options = ?
        ");
        $stmt->execute([$user_id, $product_id, json_encode($options)]);
        $existing_item = $stmt->fetch();
        
        if ($existing_item) {
            // Mevcut ürünü güncelle
            $new_quantity = $existing_item['quantity'] + $quantity;
            if ($new_quantity > 50) {
                $new_quantity = 50;
            }
            
            $stmt = $db->prepare("
                UPDATE cart_items 
                SET quantity = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$new_quantity, $existing_item['id']]);
            
            $message = 'Ürün sepetinizde güncellendi';
        } else {
            // Yeni ürün ekle
            $stmt = $db->prepare("
                INSERT INTO cart_items (user_id, product_id, quantity, options, created_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$user_id, $product_id, $quantity, json_encode($options)]);
            
            $message = 'Ürün sepetinize eklendi';
        }
        
        // Güncel sepet bilgisini getir
        $stmt = $db->prepare("
            SELECT COUNT(*) as item_count, 
                   COALESCE(SUM(ci.quantity), 0) as total_quantity
            FROM cart_items ci
            JOIN products p ON ci.product_id = p.id
            WHERE ci.user_id = ? AND p.is_active = 1 AND p.is_available = 1
        ");
        $stmt->execute([$user_id]);
        $cart_summary = $stmt->fetch();
        
        jsonResponse([
            'success' => true,
            'message' => $message,
            'cart_summary' => [
                'item_count' => (int)$cart_summary['item_count'],
                'total_quantity' => (int)$cart_summary['total_quantity']
            ]
        ]);
        
    } catch (PDOException $e) {
        error_log("Add to cart error: " . $e->getMessage());
        errorResponse('Ürün sepete eklenemedi');
    }
}

/**
 * Sepetteki ürün miktarını güncelle
 */
function handleUpdateCart($user_id, $db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['cart_item_id']) || !isset($input['quantity'])) {
        errorResponse('Geçersiz istek');
    }
    
    $cart_item_id = (int)$input['cart_item_id'];
    $quantity = (int)$input['quantity'];
    
    if ($quantity < 1 || $quantity > 50) {
        errorResponse('Geçersiz adet (1-50 arası olmalı)');
    }
    
    // CSRF token kontrolü
    if (!isset($input['csrf_token']) || !validateCSRFToken($input['csrf_token'])) {
        errorResponse('Güvenlik hatası', 403);
    }
    
    try {
        // Sepet öğesi kontrolü
        $stmt = $db->prepare("
            SELECT ci.id, p.name, p.is_active, p.is_available
            FROM cart_items ci
            JOIN products p ON ci.product_id = p.id
            WHERE ci.id = ? AND ci.user_id = ?
        ");
        $stmt->execute([$cart_item_id, $user_id]);
        $cart_item = $stmt->fetch();
        
        if (!$cart_item) {
            errorResponse('Sepet öğesi bulunamadı');
        }
        
        if (!$cart_item['is_active'] || !$cart_item['is_available']) {
            errorResponse('Ürün artık mevcut değil');
        }
        
        // Miktarı güncelle
        $stmt = $db->prepare("
            UPDATE cart_items 
            SET quantity = ?, updated_at = NOW() 
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$quantity, $cart_item_id, $user_id]);
        
        jsonResponse([
            'success' => true,
            'message' => 'Miktar güncellendi'
        ]);
        
    } catch (PDOException $e) {
        error_log("Update cart error: " . $e->getMessage());
        errorResponse('Miktar güncellenemedi');
    }
}

/**
 * Sepetten ürün kaldır
 */
function handleRemoveFromCart($user_id, $db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        // URL'den cart_item_id al
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $segments = explode('/', trim($path, '/'));
        $cart_item_id = end($segments);
    } else {
        $cart_item_id = $input['cart_item_id'] ?? null;
        
        // CSRF token kontrolü
        if (!isset($input['csrf_token']) || !validateCSRFToken($input['csrf_token'])) {
            errorResponse('Güvenlik hatası', 403);
        }
    }
    
    if (!$cart_item_id) {
        errorResponse('Sepet öğesi ID\'si gerekli');
    }
    
    $cart_item_id = (int)$cart_item_id;
    
    try {
        // Sepet öğesi kontrolü
        $stmt = $db->prepare("SELECT id FROM cart_items WHERE id = ? AND user_id = ?");
        $stmt->execute([$cart_item_id, $user_id]);
        
        if (!$stmt->fetch()) {
            errorResponse('Sepet öğesi bulunamadı');
        }
        
        // Sepetten kaldır
        $stmt = $db->prepare("DELETE FROM cart_items WHERE id = ? AND user_id = ?");
        $stmt->execute([$cart_item_id, $user_id]);
        
        jsonResponse([
            'success' => true,
            'message' => 'Ürün sepetten kaldırıldı'
        ]);
        
    } catch (PDOException $e) {
        error_log("Remove from cart error: " . $e->getMessage());
        errorResponse('Ürün sepetten kaldırılamadı');
    }
}

/**
 * Sepeti temizle
 */
function clearCart($user_id, $db) {
    try {
        $stmt = $db->prepare("DELETE FROM cart_items WHERE user_id = ?");
        return $stmt->execute([$user_id]);
    } catch (PDOException $e) {
        error_log("Clear cart error: " . $e->getMessage());
        return false;
    }
}

// Ek endpoint: Sepeti tamamen temizle
if (isset($_GET['action']) && $_GET['action'] === 'clear' && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
    if (clearCart($user_id, $db)) {
        jsonResponse([
            'success' => true,
            'message' => 'Sepet temizlendi'
        ]);
    } else {
        errorResponse('Sepet temizlenemedi');
    }
}
?>