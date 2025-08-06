<?php
/**
 * KahvePortal - Ana Sayfa
 * index.php
 */

require_once 'includes/config.php';

// Remember me token kontrolü
if (!isLoggedIn() && isset($_COOKIE['remember_token'])) {
    checkRememberToken();
}

// Popüler kahveleri getir
try {
    $stmt = $db->query("
        SELECT p.*, c.name as category_name, c.slug as category_slug
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.is_active = 1 AND p.is_featured = 1
        ORDER BY p.order_count DESC
        LIMIT 8
    ");
    $featuredProducts = $stmt->fetchAll();
    
    // İstatistikler
    $stats = $db->query("
        SELECT 
            (SELECT COUNT(*) FROM users WHERE status = 'active') as total_users,
            (SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()) as today_orders,
            (SELECT COUNT(*) FROM products WHERE is_active = 1) as total_products,
            (SELECT SUM(order_count) FROM products) as total_coffee_served
    ")->fetch();
    
} catch (PDOException $e) {
    $featuredProducts = [];
    $stats = ['total_users' => 0, 'today_orders' => 0, 'total_products' => 0, 'total_coffee_served' => 0];
}
?>
<!DOCTYPE html>
<html lang="tr" data-bs-theme="<?php echo $_SESSION['user_theme'] ?? 'light'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - Kahve Sipariş Platformu</title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/images/favicon.png">
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
                        <a class="nav-link active" href="/"><i class="fas fa-home me-1"></i> Ana Sayfa</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="menu.php"><i class="fas fa-coffee me-1"></i> Menü</a>
                    </li>
                    <?php if (isLoggedIn()): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="orders.php"><i class="fas fa-receipt me-1"></i> Siparişlerim</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="balance.php"><i class="fas fa-wallet me-1"></i> Bakiye</a>
                    </li>
                    <?php endif; ?>
                </ul>
                
                <ul class="navbar-nav">
                    <?php if (isLoggedIn()): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="cartDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-shopping-cart me-1"></i>
                            <span class="badge bg-danger cart-count">0</span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end cart-dropdown">
                            <li class="px-3 py-2">
                                <div class="text-center text-muted">Sepetiniz boş</div>
                            </li>
                        </ul>
                    </li>
                    
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="notificationDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-bell me-1"></i>
                            <span class="badge bg-warning notification-count">0</span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end notification-dropdown">
                            <li class="dropdown-header">Bildirimler</li>
                            <li><hr class="dropdown-divider"></li>
                            <li class="px-3 py-2">
                                <div class="text-center text-muted">Yeni bildirim yok</div>
                            </li>
                        </ul>
                    </li>
                    
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                            <img src="<?php echo $_SESSION['user_avatar'] ?? DEFAULT_AVATAR; ?>" alt="Avatar" class="rounded-circle me-2" width="30" height="30">
                            <span><?php echo $_SESSION['full_name'] ?? $_SESSION['username']; ?></span>
                            <span class="badge bg-success ms-2"><?php echo formatMoney($_SESSION['user_balance'] ?? 0); ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="dashboard.php"><i class="fas fa-tachometer-alt me-2"></i> Dashboard</a></li>
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> Profil</a></li>
                            <?php if (isAdmin()): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="admin/"><i class="fas fa-cog me-2"></i> Admin Panel</a></li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <button class="dropdown-item" id="themeToggle">
                                    <i class="fas fa-moon me-2"></i> <span>Dark Mode</span>
                                </button>
                            </li>
                            <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i> Çıkış</a></li>
                        </ul>
                    </li>
                    <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link" href="login.php"><i class="fas fa-sign-in-alt me-1"></i> Giriş</a>
                    </li>
                    <li class="nav-item">
                        <a class="btn btn-warning ms-2" href="register.php"><i class="fas fa-user-plus me-1"></i> Kayıt Ol</a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
    
    <!-- Hero Section -->
    <section class="hero-section py-5">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <h1 class="display-4 fw-bold mb-4 animate-fade-in">
                        Günün Her Saati <br>
                        <span class="text-gradient">Mükemmel Kahve</span>
                    </h1>
                    <p class="lead mb-4 animate-fade-in-delay">
                        Arkadaşlarınızla birlikte kahve siparişi verin, bakiyenizi yönetin ve favori kahvelerinizi keşfedin.
                    </p>
                    <div class="d-flex gap-3 animate-fade-in-delay-2">
                        <?php if (isLoggedIn()): ?>
                        <a href="menu.php" class="btn btn-primary btn-lg">
                            <i class="fas fa-coffee me-2"></i> Menüyü Keşfet
                        </a>
                        <a href="orders.php" class="btn btn-outline-primary btn-lg">
                            <i class="fas fa-clock-rotate-left me-2"></i> Siparişlerim
                        </a>
                        <?php else: ?>
                        <a href="register.php" class="btn btn-primary btn-lg">
                            <i class="fas fa-user-plus me-2"></i> Hemen Başla
                        </a>
                        <a href="login.php" class="btn btn-outline-primary btn-lg">
                            <i class="fas fa-sign-in-alt me-2"></i> Giriş Yap
                        </a>
                        <?php endif; ?>
                    </div>
                    
                    <!-- İstatistikler -->
                    <div class="row mt-5">
                        <div class="col-6 col-md-3">
                            <div class="stat-card">
                                <h3 class="fw-bold text-primary counter" data-target="<?php echo $stats['total_users']; ?>">0</h3>
                                <p class="small text-muted mb-0">Aktif Kullanıcı</p>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="stat-card">
                                <h3 class="fw-bold text-success counter" data-target="<?php echo $stats['today_orders']; ?>">0</h3>
                                <p class="small text-muted mb-0">Bugünkü Sipariş</p>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="stat-card">
                                <h3 class="fw-bold text-warning counter" data-target="<?php echo $stats['total_products']; ?>">0</h3>
                                <p class="small text-muted mb-0">Kahve Çeşidi</p>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="stat-card">
                                <h3 class="fw-bold text-info counter" data-target="<?php echo $stats['total_coffee_served']; ?>">0</h3>
                                <p class="small text-muted mb-0">Servis Edilen</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-6">
                    <div class="hero-image-wrapper">
                        <img src="assets/images/hero-coffee.svg" alt="Coffee" class="img-fluid animate-float">
                        <div class="coffee-steam">
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    
    <!-- Öne Çıkan Kahveler -->
    <?php if (!empty($featuredProducts)): ?>
    <section class="py-5 bg-light">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="display-5 fw-bold">Popüler Kahveler</h2>
                <p class="text-muted">En çok tercih edilen lezzetler</p>
            </div>
            
            <div class="row g-4">
                <?php foreach ($featuredProducts as $product): ?>
                <div class="col-md-6 col-lg-3">
                    <div class="product-card h-100">
                        <div class="product-image">
                            <img src="<?php echo $product['image'] ?? DEFAULT_COFFEE_IMAGE; ?>" alt="<?php echo clean($product['name']); ?>">
                            <?php if ($product['stock_status'] === 'out_of_stock'): ?>
                            <span class="badge bg-danger position-absolute top-0 end-0 m-2">Tükendi</span>
                            <?php elseif ($product['is_featured']): ?>
                            <span class="badge bg-warning position-absolute top-0 end-0 m-2">Öne Çıkan</span>
                            <?php endif; ?>
                        </div>
                        <div class="product-body">
                            <span class="text-muted small"><?php echo clean($product['category_name']); ?></span>
                            <h5 class="product-title"><?php echo clean($product['name']); ?></h5>
                            <p class="product-description"><?php echo clean($product['description']); ?></p>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="product-price"><?php echo formatMoney($product['price']); ?></span>
                                <?php if (isLoggedIn() && $product['stock_status'] !== 'out_of_stock'): ?>
                                <button class="btn btn-sm btn-primary add-to-cart" data-product-id="<?php echo $product['id']; ?>">
                                    <i class="fas fa-cart-plus"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="text-center mt-4">
                <a href="menu.php" class="btn btn-outline-primary">
                    Tüm Menüyü Gör <i class="fas fa-arrow-right ms-2"></i>
                </a>
            </div>
        </div>
    </section>
    <?php endif; ?>
    
    <!-- Özellikler -->
    <section class="py-5">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="display-5 fw-bold">Neden KahvePortal?</h2>
                <p class="text-muted">Kahve deneyiminizi kolaylaştırıyoruz</p>
            </div>
            
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="feature-box text-center">
                        <div class="feature-icon mb-3">
                            <i class="fas fa-users fa-3x text-primary"></i>
                        </div>
                        <h4>Grup Siparişi</h4>
                        <p class="text-muted">Arkadaşlarınızla birlikte sipariş verin, toplu teslimat alın</p>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="feature-box text-center">
                        <div class="feature-icon mb-3">
                            <i class="fas fa-wallet fa-3x text-success"></i>
                        </div>
                        <h4>Bakiye Sistemi</h4>
                        <p class="text-muted">Ön ödemeli bakiye ile hızlı ve güvenli ödeme</p>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="feature-box text-center">
                        <div class="feature-icon mb-3">
                            <i class="fas fa-clock fa-3x text-warning"></i>
                        </div>
                        <h4>Zaman Yönetimi</h4>
                        <p class="text-muted">Belirlenen saatlerde sipariş verin, zamanında teslim alın</p>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="feature-box text-center">
                        <div class="feature-icon mb-3">
                            <i class="fas fa-heart fa-3x text-danger"></i>
                        </div>
                        <h4>Favoriler</h4>
                        <p class="text-muted">Sevdiğiniz kahveleri favorilere ekleyin, hızlıca sipariş verin</p>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="feature-box text-center">
                        <div class="feature-icon mb-3">
                            <i class="fas fa-bell fa-3x text-info"></i>
                        </div>
                        <h4>Bildirimler</h4>
                        <p class="text-muted">Sipariş durumunuzu anlık olarak takip edin</p>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="feature-box text-center">
                        <div class="feature-icon mb-3">
                            <i class="fas fa-chart-line fa-3x text-purple"></i>
                        </div>
                        <h4>İstatistikler</h4>
                        <p class="text-muted">Kahve tüketiminizi ve harcamalarınızı takip edin</p>
                    </div>
                </div>
            </div>
        </div>
    </section>
    
    <!-- CTA Section -->
    <section class="py-5 bg-gradient-primary text-white">
        <div class="container text-center">
            <h2 class="display-5 fw-bold mb-4">Kahve Zamanı!</h2>
            <p class="lead mb-4">Hemen üye ol, ilk siparişinde %10 indirim kazan</p>
            <?php if (!isLoggedIn()): ?>
            <a href="register.php" class="btn btn-warning btn-lg">
                <i class="fas fa-rocket me-2"></i> Ücretsiz Üye Ol
            </a>
            <?php else: ?>
            <a href="menu.php" class="btn btn-warning btn-lg">
                <i class="fas fa-coffee me-2"></i> Sipariş Ver
            </a>
            <?php endif; ?>
        </div>
    </section>
    
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
                    <a href="#" class="text-white"><i class="fab fa-linkedin"></i></a>
                </div>
            </div>
        </div>
    </footer>
    
    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/app.js"></script>
    
    <script>
        // Sayaç animasyonu
        const counters = document.querySelectorAll('.counter');
        const speed = 200;
        
        counters.forEach(counter => {
            const animate = () => {
                const value = +counter.getAttribute('data-target');
                const data = +counter.innerText;
                const time = value / speed;
                
                if (data < value) {
                    counter.innerText = Math.ceil(data + time);
                    setTimeout(animate, 1);
                } else {
                    counter.innerText = value;
                }
            }
            animate();
        });
    </script>
</body>
</html>