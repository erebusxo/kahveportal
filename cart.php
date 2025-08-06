<?php
/**
 * KahvePortal - Sepet Sayfası
 * cart.php
 */

require_once 'includes/config.php';

// Giriş kontrolü
requireLogin();

// Sepet işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_quantity') {
        $cartId = (int)$_POST['cart_id'];
        $quantity = (int)$_POST['quantity'];
        
        if ($quantity > 0) {
            $stmt = $db->prepare("UPDATE cart SET quantity = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$quantity, $cartId, $_SESSION['user_id']]);
        } else {
            $stmt = $db->prepare("DELETE FROM cart WHERE id = ? AND user_id = ?");
            $stmt->execute([$cartId, $_SESSION['user_id']]);
        }
        
        redirect('cart.php', 'Sepet güncellendi', 'success');
    }
    
    if ($action === 'remove_item') {
        $cartId = (int)$_POST['cart_id'];
        $stmt = $db->prepare("DELETE FROM cart WHERE id = ? AND user_id = ?");
        $stmt->execute([$cartId, $_SESSION['user_id']]);
        redirect('cart.php', 'Ürün sepetten kaldırıldı', 'success');
    }
    
    if ($action === 'clear_cart') {
        $stmt = $db->prepare("DELETE FROM cart WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        redirect('cart.php', 'Sepet temizlendi', 'success');
    }
    
    if ($action === 'checkout') {
        // Sipariş oluştur
        try {
            $db->beginTransaction();
            
            // Sepet ürünlerini getir
            $stmt = $db->prepare("
                SELECT c.*, p.name, p.price, p.stock_status
                FROM cart c
                JOIN products p ON c.product_id = p.id
                WHERE c.user_id = ?
            ");
            $stmt->execute([$_SESSION['user_id']]);
            $cartItems = $stmt->fetchAll();
            
            if (empty($cartItems)) {
                throw new Exception('Sepetiniz boş');
            }
            
            // Toplam tutarı hesapla
            $totalAmount = 0;
            foreach ($cartItems as $item) {
                if ($item['stock_status'] === 'out_of_stock') {
                    throw new Exception($item['name'] . ' stokta yok');
                }
                $totalAmount += $item['price'] * $item['quantity'];
            }
            
            // Bakiye kontrolü
            if ($_SESSION['user_balance'] < $totalAmount) {
                throw new Exception('Yetersiz bakiye. Lütfen bakiye yükleyin.');
            }
            
            // Sipariş oluştur
            $orderNumber = generateOrderNumber();
            $stmt = $db->prepare("
                INSERT INTO orders (order_number, user_id, total_amount, status, payment_status, notes)
                VALUES (?, ?, ?, 'pending', 'pending', ?)
            ");
            $stmt->execute([$orderNumber, $_SESSION['user_id'], $totalAmount, $_POST['notes'] ?? '']);
            $orderId = $db->lastInsertId();
            
            // Sipariş detaylarını ekle
            foreach ($cartItems as $item) {
                $stmt = $db->prepare("
                    INSERT INTO order_items 
                    (order_id, product_id, product_name, quantity, unit_price, total_price, size, customizations, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $orderId,
                    $item['product_id'],
                    $item['name'],
                    $item['quantity'],
                    $item['price'],
                    $item['price'] * $item['quantity'],
                    $item['size'],
                    $item['customizations'],
                    $item['notes']
                ]);
                
                // Ürün sipariş sayısını artır
                $stmt = $db->prepare("UPDATE products SET order_count = order_count + ? WHERE id = ?");
                $stmt->execute([$item['quantity'], $item['product_id']]);
            }
            
            // Bakiyeden düş
            updateUserBalance($_SESSION['user_id'], -$totalAmount, 'order', 'Sipariş #' . $orderNumber, $orderId, 'order');
            
            // Sepeti temizle
            $stmt = $db->prepare("DELETE FROM cart WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            
            // Bildirim oluştur
            createNotification($_SESSION['user_id'], 'order', 'Siparişiniz Alındı', 
                'Sipariş numaranız: ' . $orderNumber . '. Siparişiniz hazırlanıyor.', 
                'order-detail.php?id=' . $orderId);
            
            $db->commit();
            
            // Session'daki bakiyeyi güncelle
            refreshUserSession($_SESSION['user_id']);
            
            redirect('order-success.php?order=' . $orderNumber, 'Siparişiniz başarıyla oluşturuldu!', 'success');
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = $e->getMessage();
        }
    }
}

// Sepet ürünlerini getir
try {
    $stmt = $db->prepare("
        SELECT c.*, p.name, p.price, p.image, p.stock_status, cat.name as category_name
        FROM cart c
        JOIN products p ON c.product_id = p.id
        JOIN categories cat ON p.category_id = cat.id
        WHERE c.user_id = ?
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $cartItems = $stmt->fetchAll();
    
    // Toplam hesapla
    $subtotal = 0;
    foreach ($cartItems as $item) {
        $subtotal += $item['price'] * $item['quantity'];
    }
    
} catch (PDOException $e) {
    $cartItems = [];
    $subtotal = 0;
}

// Sipariş saati kontrolü
$canOrder = isOrderTimeValid();
?>
<!DOCTYPE html>
<html lang="tr" data-bs-theme="<?php echo $_SESSION['user_theme'] ?? 'light'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sepetim - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    
    <style>
        .cart-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            border-radius: 0 0 30px 30px;
        }
        
        .cart-item {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
        }
        
        .cart-item:hover {
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .product-image {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 10px;
        }
        
        .quantity-control {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .quantity-control button {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            border: 1px solid #e2e8f0;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }
        
        .quantity-control button:hover {
            background: #f7fafc;
            border-color: #667eea;
        }
        
        .quantity-control input {
            width: 60px;
            text-align: center;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 5px;
        }
        
        .summary-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            position: sticky;
            top: 80px;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .summary-row:last-child {
            border-bottom: none;
            font-size: 1.25rem;
            font-weight: bold;
            color: #667eea;
        }
        
        .empty-cart {
            text-align: center;
            padding: 4rem 2rem;
        }
        
        .empty-cart i {
            font-size: 5rem;
            color: #cbd5e0;
            margin-bottom: 1rem;
        }
        
        .customization-badge {
            display: inline-block;
            padding: 3px 8px;
            background: #f7fafc;
            border-radius: 5px;
            font-size: 0.85rem;
            margin: 2px;
        }
    </style>
</head>
<body>
    <!-- Navbar (index.php'deki gibi) -->
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
                        <a class="nav-link" href="orders.php"><i class="fas fa-receipt me-1"></i> Siparişlerim</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="balance.php"><i class="fas fa-wallet me-1"></i> Bakiye</a>
                    </li>
                </ul>
                
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <span class="nav-link">
                            <i class="fas fa-wallet me-1"></i> 
                            <?php echo formatMoney($_SESSION['user_balance'] ?? 0); ?>
                        </span>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
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
    
    <!-- Cart Header -->
    <div class="cart-header">
        <div class="container">
            <h1 class="display-5 mb-0">
                <i class="fas fa-shopping-cart me-2"></i> Sepetim
            </h1>
        </div>
    </div>
    
    <div class="container">
        <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i> <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php echo displaySessionAlert(); ?>
        
        <?php if (!empty($cartItems)): ?>
        <div class="row">
            <div class="col-lg-8">
                <?php foreach ($cartItems as $item): ?>
                <div class="cart-item">
                    <div class="row align-items-center">
                        <div class="col-md-2">
                            <img src="<?php echo $item['image'] ?? 'assets/images/default-coffee.jpg'; ?>" 
                                 alt="<?php echo clean($item['name']); ?>" class="product-image">
                        </div>
                        <div class="col-md-4">
                            <h5 class="mb-1"><?php echo clean($item['name']); ?></h5>
                            <small class="text-muted"><?php echo clean($item['category_name']); ?></small>
                            
                            <?php if ($item['size']): ?>
                            <div class="mt-1">
                                <span class="customization-badge">
                                    <i class="fas fa-expand-alt"></i> <?php echo clean($item['size']); ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($item['customizations']): ?>
                            <div class="mt-1">
                                <?php 
                                $customs = json_decode($item['customizations'], true);
                                foreach ($customs as $key => $value): 
                                ?>
                                <span class="customization-badge">
                                    <?php echo clean($key); ?>: <?php echo clean($value); ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($item['notes']): ?>
                            <div class="mt-1">
                                <small class="text-muted">
                                    <i class="fas fa-sticky-note"></i> <?php echo clean($item['notes']); ?>
                                </small>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($item['stock_status'] === 'out_of_stock'): ?>
                            <span class="badge bg-danger mt-2">Stokta Yok</span>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-2">
                            <span class="fw-bold"><?php echo formatMoney($item['price']); ?></span>
                        </div>
                        <div class="col-md-2">
                            <form method="POST" action="" class="quantity-control">
                                <input type="hidden" name="action" value="update_quantity">
                                <input type="hidden" name="cart_id" value="<?php echo $item['id']; ?>">
                                <button type="button" onclick="updateQuantity(<?php echo $item['id']; ?>, -1)">
                                    <i class="fas fa-minus"></i>
                                </button>
                                <input type="number" name="quantity" value="<?php echo $item['quantity']; ?>" 
                                       min="1" max="10" id="qty-<?php echo $item['id']; ?>" readonly>
                                <button type="button" onclick="updateQuantity(<?php echo $item['id']; ?>, 1)">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </form>
                        </div>
                        <div class="col-md-2 text-end">
                            <strong><?php echo formatMoney($item['price'] * $item['quantity']); ?></strong>
                            <form method="POST" action="" class="d-inline ms-3">
                                <input type="hidden" name="action" value="remove_item">
                                <input type="hidden" name="cart_id" value="<?php echo $item['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" 
                                        onclick="return confirm('Bu ürünü sepetten kaldırmak istediğinize emin misiniz?')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <div class="d-flex justify-content-between mt-3">
                    <a href="menu.php" class="btn btn-outline-primary">
                        <i class="fas fa-arrow-left me-2"></i> Alışverişe Devam Et
                    </a>
                    <form method="POST" action="" class="d-inline">
                        <input type="hidden" name="action" value="clear_cart">
                        <button type="submit" class="btn btn-outline-danger" 
                                onclick="return confirm('Sepeti temizlemek istediğinize emin misiniz?')">
                            <i class="fas fa-trash me-2"></i> Sepeti Temizle
                        </button>
                    </form>
                </div>
            </div>
            
            <div class="col-lg-4">
                <div class="summary-card">
                    <h4 class="mb-4">Sipariş Özeti</h4>
                    
                    <div class="summary-row">
                        <span>Ara Toplam:</span>
                        <span><?php echo formatMoney($subtotal); ?></span>
                    </div>
                    
                    <div class="summary-row">
                        <span>Mevcut Bakiye:</span>
                        <span class="<?php echo $_SESSION['user_balance'] >= $subtotal ? 'text-success' : 'text-danger'; ?>">
                            <?php echo formatMoney($_SESSION['user_balance']); ?>
                        </span>
                    </div>
                    
                    <?php if ($_SESSION['user_balance'] < $subtotal): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Yetersiz bakiye! Sipariş vermek için <?php echo formatMoney($subtotal - $_SESSION['user_balance']); ?> yüklemeniz gerekiyor.
                    </div>
                    <?php endif; ?>
                    
                    <div class="summary-row">
                        <span>Toplam:</span>
                        <span><?php echo formatMoney($subtotal); ?></span>
                    </div>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="checkout">
                        
                        <div class="mb-3">
                            <label for="notes" class="form-label">Sipariş Notu (Opsiyonel)</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3" 
                                      placeholder="Özel istekleriniz..."></textarea>
                        </div>
                        
                        <?php if (!$canOrder): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-clock me-2"></i> Şu anda sipariş saatleri dışındasınız.
                        </div>
                        <?php endif; ?>
                        
                        <button type="submit" class="btn btn-primary w-100 btn-lg" 
                                <?php echo (!$canOrder || $_SESSION['user_balance'] < $subtotal) ? 'disabled' : ''; ?>>
                            <i class="fas fa-check-circle me-2"></i> Siparişi Tamamla
                        </button>
                    </form>
                    
                    <?php if ($_SESSION['user_balance'] < $subtotal): ?>
                    <a href="balance.php" class="btn btn-success w-100 mt-2">
                        <i class="fas fa-wallet me-2"></i> Bakiye Yükle
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="empty-cart">
            <i class="fas fa-shopping-cart"></i>
            <h3>Sepetiniz Boş</h3>
            <p class="text-muted">Henüz sepetinize ürün eklemediniz.</p>
            <a href="menu.php" class="btn btn-primary btn-lg">
                <i class="fas fa-coffee me-2"></i> Menüye Git
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
        function updateQuantity(cartId, change) {
            const input = document.getElementById('qty-' + cartId);
            let newQty = parseInt(input.value) + change;
            
            if (newQty < 1) newQty = 1;
            if (newQty > 10) newQty = 10;
            
            input.value = newQty;
            
            // Form oluştur ve gönder
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="update_quantity">
                <input type="hidden" name="cart_id" value="${cartId}">
                <input type="hidden" name="quantity" value="${newQty}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    </script>
</body>
</html>