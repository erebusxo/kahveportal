<?php
/**
 * KahvePortal - User Profile Management Page
 * profile.php
 */

require_once 'config.php';
require_once 'includes/session.php';

// Giriş kontrolü
requireLogin();

$user_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';

// Kullanıcı bilgilerini getir
try {
    $stmt = $db->prepare("
        SELECT u.*, 
               (SELECT COUNT(*) FROM orders WHERE user_id = u.id) as total_orders,
               (SELECT SUM(total_amount) FROM orders WHERE user_id = u.id) as total_spent,
               (SELECT COUNT(*) FROM orders WHERE user_id = u.id AND DATE(created_at) = CURDATE()) as today_orders
        FROM users u 
        WHERE u.id = ?
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if (!$user) {
        header('Location: login.php');
        exit;
    }
} catch (PDOException $e) {
    error_log("Profile fetch error: " . $e->getMessage());
    $error_message = 'Profil bilgileri alınamadı.';
}

// Form işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // CSRF token kontrolü
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error_message = 'Güvenlik hatası. Lütfen tekrar deneyin.';
    } else {
        switch ($action) {
            case 'update_profile':
                updateProfile($user_id, $db);
                break;
            case 'change_password':
                changePassword($user_id, $db);
                break;
            case 'update_preferences':
                updatePreferences($user_id, $db);
                break;
            case 'request_balance':
                requestBalance($user_id, $db);
                break;
        }
    }
}

// Profil güncelleme
function updateProfile($user_id, $db) {
    global $success_message, $error_message;
    
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    
    if (empty($first_name) || empty($last_name)) {
        $error_message = 'Ad ve soyad gerekli.';
        return;
    }
    
    try {
        $stmt = $db->prepare("
            UPDATE users 
            SET first_name = ?, last_name = ?, phone = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$first_name, $last_name, $phone, $user_id]);
        
        // Session'ı güncelle
        $_SESSION['user_name'] = $first_name . ' ' . $last_name;
        $_SESSION['user_first_name'] = $first_name;
        $_SESSION['user_last_name'] = $last_name;
        $_SESSION['user_phone'] = $phone;
        
        $success_message = 'Profil bilgileriniz güncellendi.';
        
    } catch (PDOException $e) {
        error_log("Profile update error: " . $e->getMessage());
        $error_message = 'Profil güncellenirken hata oluştu.';
    }
}

// Şifre değişikliği
function changePassword($user_id, $db) {
    global $success_message, $error_message;
    
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error_message = 'Tüm şifre alanları gerekli.';
        return;
    }
    
    if ($new_password !== $confirm_password) {
        $error_message = 'Yeni şifreler eşleşmiyor.';
        return;
    }
    
    if (strlen($new_password) < PASSWORD_MIN_LENGTH) {
        $error_message = 'Yeni şifre en az ' . PASSWORD_MIN_LENGTH . ' karakter olmalı.';
        return;
    }
    
    try {
        // Mevcut şifreyi kontrol et
        $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $current_hash = $stmt->fetchColumn();
        
        if (!password_verify($current_password, $current_hash)) {
            $error_message = 'Mevcut şifre yanlış.';
            return;
        }
        
        // Yeni şifreyi güncelle
        $new_hash = hashPassword($new_password);
        $stmt = $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$new_hash, $user_id]);
        
        $success_message = 'Şifreniz başarıyla değiştirildi.';
        
    } catch (PDOException $e) {
        error_log("Password change error: " . $e->getMessage());
        $error_message = 'Şifre değiştirilirken hata oluştu.';
    }
}

// Tercihler güncelleme
function updatePreferences($user_id, $db) {
    global $success_message, $error_message;
    
    $theme = $_POST['theme'] ?? 'light';
    $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
    
    if (!in_array($theme, ['light', 'dark'])) {
        $theme = 'light';
    }
    
    try {
        $stmt = $db->prepare("
            UPDATE users 
            SET theme = ?, email_notifications = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$theme, $email_notifications, $user_id]);
        
        // Session'ı güncelle
        $_SESSION['user_theme'] = $theme;
        
        $success_message = 'Tercihleriniz güncellendi.';
        
    } catch (PDOException $e) {
        error_log("Preferences update error: " . $e->getMessage());
        $error_message = 'Tercihler güncellenirken hata oluştu.';
    }
}

// Bakiye talebi
function requestBalance($user_id, $db) {
    global $success_message, $error_message;
    
    $amount = (float)($_POST['amount'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    
    if ($amount <= 0 || $amount > 10000) {
        $error_message = 'Geçersiz tutar (1-10000 TL arası).';
        return;
    }
    
    try {
        $stmt = $db->prepare("
            INSERT INTO balance_requests (user_id, amount, description, status, created_at) 
            VALUES (?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([$user_id, $amount, $description]);
        
        $success_message = 'Bakiye yükleme talebiniz gönderildi. Admin onayından sonra bakiyenize eklenecektir.';
        
    } catch (PDOException $e) {
        error_log("Balance request error: " . $e->getMessage());
        $error_message = 'Bakiye talebi gönderilirken hata oluştu.';
    }
}

// Bakiye geçmişini getir
try {
    $balance_stmt = $db->prepare("
        SELECT * FROM balance_transactions 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $balance_stmt->execute([$user_id]);
    $balance_history = $balance_stmt->fetchAll();
} catch (PDOException $e) {
    $balance_history = [];
}

// Sipariş geçmişini getir
try {
    $orders_stmt = $db->prepare("
        SELECT o.*, COUNT(oi.id) as item_count
        FROM orders o
        LEFT JOIN order_items oi ON o.id = oi.order_id
        WHERE o.user_id = ?
        GROUP BY o.id
        ORDER BY o.created_at DESC
        LIMIT 10
    ");
    $orders_stmt->execute([$user_id]);
    $recent_orders = $orders_stmt->fetchAll();
} catch (PDOException $e) {
    $recent_orders = [];
}
?>
<!DOCTYPE html>
<html lang="tr" data-bs-theme="<?php echo $_SESSION['user_theme'] ?? 'light'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profilim - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="style.css">
    <?php if (($_SESSION['user_theme'] ?? 'light') === 'dark'): ?>
        <link rel="stylesheet" href="assets/css/dark-mode.css">
    <?php endif; ?>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold text-gradient" href="index.php">
                <i class="fas fa-coffee me-2"></i><?php echo SITE_NAME; ?>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">Ana Sayfa</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="menu.php">Menü</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="orders.php">Siparişlerim</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="profile.php">Profilim</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="auth.php?action=logout">Çıkış</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container my-5">
        <!-- Profil Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-2 text-center">
                                <div class="avatar-circle bg-gradient-primary text-white d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px; border-radius: 50%; font-size: 2rem;">
                                    <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h2 class="mb-1"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h2>
                                <p class="text-muted mb-2"><?php echo htmlspecialchars($user['email']); ?></p>
                                <p class="text-muted mb-0">
                                    <i class="fas fa-calendar me-2"></i>
                                    Üyelik: <?php echo date('d.m.Y', strtotime($user['created_at'])); ?>
                                </p>
                            </div>
                            <div class="col-md-4">
                                <div class="row text-center">
                                    <div class="col-4">
                                        <div class="fw-bold text-primary fs-4"><?php echo number_format($user['balance'], 2); ?> ₺</div>
                                        <div class="small text-muted">Bakiye</div>
                                    </div>
                                    <div class="col-4">
                                        <div class="fw-bold text-success fs-4"><?php echo $user['total_orders']; ?></div>
                                        <div class="small text-muted">Sipariş</div>
                                    </div>
                                    <div class="col-4">
                                        <div class="fw-bold text-info fs-4"><?php echo number_format($user['total_spent'], 0); ?> ₺</div>
                                        <div class="small text-muted">Harcama</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($success_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?php echo htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Sol Sidebar -->
            <div class="col-lg-3 mb-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="nav nav-pills flex-column" id="profile-tabs" role="tablist">
                            <button class="nav-link active" id="profile-tab" data-bs-toggle="pill" data-bs-target="#profile" type="button" role="tab">
                                <i class="fas fa-user me-2"></i>Profil Bilgileri
                            </button>
                            <button class="nav-link" id="password-tab" data-bs-toggle="pill" data-bs-target="#password" type="button" role="tab">
                                <i class="fas fa-lock me-2"></i>Şifre Değiştir
                            </button>
                            <button class="nav-link" id="balance-tab" data-bs-toggle="pill" data-bs-target="#balance" type="button" role="tab">
                                <i class="fas fa-wallet me-2"></i>Bakiye Yönetimi
                            </button>
                            <button class="nav-link" id="orders-tab" data-bs-toggle="pill" data-bs-target="#orders" type="button" role="tab">
                                <i class="fas fa-shopping-cart me-2"></i>Son Siparişler
                            </button>
                            <button class="nav-link" id="preferences-tab" data-bs-toggle="pill" data-bs-target="#preferences" type="button" role="tab">
                                <i class="fas fa-cog me-2"></i>Tercihler
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ana İçerik -->
            <div class="col-lg-9">
                <div class="tab-content" id="profile-tab-content">
                    <!-- Profil Bilgileri -->
                    <div class="tab-pane fade show active" id="profile" role="tabpanel" aria-labelledby="profile-tab">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-gradient-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-user me-2"></i>Profil Bilgileri</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" class="needs-validation" novalidate>
                                    <?php echo generateCSRFInput(); ?>
                                    <input type="hidden" name="action" value="update_profile">
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="first_name" class="form-label">Ad *</label>
                                            <input type="text" class="form-control" id="first_name" name="first_name" 
                                                   value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
                                            <div class="invalid-feedback">Ad gerekli.</div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="last_name" class="form-label">Soyad *</label>
                                            <input type="text" class="form-control" id="last_name" name="last_name" 
                                                   value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
                                            <div class="invalid-feedback">Soyad gerekli.</div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="email" class="form-label">Email</label>
                                            <input type="email" class="form-control" id="email" 
                                                   value="<?php echo htmlspecialchars($user['email']); ?>" readonly>
                                            <div class="form-text">Email adresi değiştirilemez.</div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="phone" class="form-label">Telefon</label>
                                            <input type="tel" class="form-control" id="phone" name="phone" 
                                                   value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" 
                                                   placeholder="05XX XXX XX XX">
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-end">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save me-2"></i>Değişiklikleri Kaydet
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Şifre Değiştir -->
                    <div class="tab-pane fade" id="password" role="tabpanel" aria-labelledby="password-tab">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-gradient-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-lock me-2"></i>Şifre Değiştir</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" class="needs-validation" novalidate>
                                    <?php echo generateCSRFInput(); ?>
                                    <input type="hidden" name="action" value="change_password">
                                    
                                    <div class="mb-3">
                                        <label for="current_password" class="form-label">Mevcut Şifre *</label>
                                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                                        <div class="invalid-feedback">Mevcut şifre gerekli.</div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="new_password" class="form-label">Yeni Şifre *</label>
                                        <input type="password" class="form-control" id="new_password" name="new_password" 
                                               minlength="<?php echo PASSWORD_MIN_LENGTH; ?>" required>
                                        <div class="form-text">En az <?php echo PASSWORD_MIN_LENGTH; ?> karakter olmalı.</div>
                                        <div class="invalid-feedback">Yeni şifre gerekli.</div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="confirm_password" class="form-label">Yeni Şifre Tekrar *</label>
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                        <div class="invalid-feedback">Şifre tekrarı gerekli.</div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-end">
                                        <button type="submit" class="btn btn-warning">
                                            <i class="fas fa-key me-2"></i>Şifreyi Değiştir
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Bakiye Yönetimi -->
                    <div class="tab-pane fade" id="balance" role="tabpanel" aria-labelledby="balance-tab">
                        <div class="row">
                            <div class="col-md-6 mb-4">
                                <div class="card border-0 shadow-sm">
                                    <div class="card-header bg-gradient-primary text-white">
                                        <h5 class="mb-0"><i class="fas fa-plus me-2"></i>Bakiye Yükle</h5>
                                    </div>
                                    <div class="card-body">
                                        <form method="POST" class="needs-validation" novalidate>
                                            <?php echo generateCSRFInput(); ?>
                                            <input type="hidden" name="action" value="request_balance">
                                            
                                            <div class="mb-3">
                                                <label for="amount" class="form-label">Tutar (₺) *</label>
                                                <input type="number" class="form-control" id="amount" name="amount" 
                                                       min="1" max="10000" step="0.01" required>
                                                <div class="form-text">1-10000 TL arası</div>
                                                <div class="invalid-feedback">Geçerli bir tutar girin.</div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="description" class="form-label">Açıklama</label>
                                                <textarea class="form-control" id="description" name="description" rows="3" 
                                                          placeholder="Opsiyonel açıklama"></textarea>
                                            </div>
                                            
                                            <div class="alert alert-info">
                                                <i class="fas fa-info-circle me-2"></i>
                                                Bakiye yükleme talebi admin onayından sonra hesabınıza yansıyacaktır.
                                            </div>
                                            
                                            <button type="submit" class="btn btn-success w-100">
                                                <i class="fas fa-paper-plane me-2"></i>Talep Gönder
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6 mb-4">
                                <div class="card border-0 shadow-sm">
                                    <div class="card-header bg-gradient-primary text-white">
                                        <h5 class="mb-0"><i class="fas fa-history me-2"></i>Son İşlemler</h5>
                                    </div>
                                    <div class="card-body">
                                        <?php if (empty($balance_history)): ?>
                                            <div class="text-center py-3">
                                                <i class="fas fa-wallet fa-3x text-muted mb-3"></i>
                                                <p class="text-muted">Henüz işlem geçmişi yok</p>
                                            </div>
                                        <?php else: ?>
                                            <div class="transaction-list">
                                                <?php foreach ($balance_history as $transaction): ?>
                                                    <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                                                        <div>
                                                            <div class="fw-bold <?php echo $transaction['amount'] > 0 ? 'text-success' : 'text-danger'; ?>">
                                                                <?php echo $transaction['amount'] > 0 ? '+' : ''; ?><?php echo number_format($transaction['amount'], 2); ?> ₺
                                                            </div>
                                                            <div class="small text-muted"><?php echo htmlspecialchars($transaction['description']); ?></div>
                                                        </div>
                                                        <div class="text-end">
                                                            <div class="small text-muted">
                                                                <?php echo date('d.m.Y', strtotime($transaction['created_at'])); ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Son Siparişler -->
                    <div class="tab-pane fade" id="orders" role="tabpanel" aria-labelledby="orders-tab">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-gradient-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-shopping-cart me-2"></i>Son Siparişler</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recent_orders)): ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">Henüz sipariş bulunmuyor</p>
                                        <a href="menu.php" class="btn btn-primary">
                                            <i class="fas fa-coffee me-2"></i>Sipariş Ver
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Sipariş #</th>
                                                    <th>Tutar</th>
                                                    <th>Durum</th>
                                                    <th>Tarih</th>
                                                    <th>İşlem</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recent_orders as $order): ?>
                                                    <tr>
                                                        <td>#<?php echo $order['id']; ?></td>
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
                                                        <td>
                                                            <a href="orders.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                                Detay
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="text-center">
                                        <a href="orders.php" class="btn btn-outline-primary">
                                            Tüm Siparişleri Görüntüle
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Tercihler -->
                    <div class="tab-pane fade" id="preferences" role="tabpanel" aria-labelledby="preferences-tab">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-gradient-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-cog me-2"></i>Tercihler</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <?php echo generateCSRFInput(); ?>
                                    <input type="hidden" name="action" value="update_preferences">
                                    
                                    <div class="mb-4">
                                        <label class="form-label">Tema</label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="theme" id="theme_light" 
                                                   value="light" <?php echo ($user['theme'] ?? 'light') === 'light' ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="theme_light">
                                                <i class="fas fa-sun me-2"></i>Açık Tema
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="theme" id="theme_dark" 
                                                   value="dark" <?php echo ($user['theme'] ?? 'light') === 'dark' ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="theme_dark">
                                                <i class="fas fa-moon me-2"></i>Koyu Tema
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-4">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="email_notifications" 
                                                   name="email_notifications" <?php echo ($user['email_notifications'] ?? 1) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="email_notifications">
                                                <i class="fas fa-envelope me-2"></i>Email Bildirimleri
                                            </label>
                                        </div>
                                        <div class="form-text">Sipariş durumu ve bakiye değişiklikleri için email bildirimi al</div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-end">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save me-2"></i>Tercihleri Kaydet
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Form validation
        (function() {
            'use strict';
            window.addEventListener('load', function() {
                var forms = document.getElementsByClassName('needs-validation');
                var validation = Array.prototype.filter.call(forms, function(form) {
                    form.addEventListener('submit', function(event) {
                        if (form.checkValidity() === false) {
                            event.preventDefault();
                            event.stopPropagation();
                        }
                        form.classList.add('was-validated');
                    }, false);
                });
            }, false);
        })();

        // Password confirmation validation
        document.getElementById('confirm_password')?.addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            
            if (newPassword !== confirmPassword) {
                this.setCustomValidity('Şifreler eşleşmiyor');
            } else {
                this.setCustomValidity('');
            }
        });

        // Auto-hide alerts
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);

        // Theme change effect
        document.querySelectorAll('input[name="theme"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                document.documentElement.setAttribute('data-bs-theme', this.value);
            });
        });
    </script>
</body>
</html>