<?php
/**
 * KahvePortal - Kahve Menüsü
 * menu.php
 */

require_once 'includes/config.php';

// Sipariş saati kontrolü
$canOrder = isOrderTimeValid();
$orderHours = getActiveOrderHours();

// Kategori filtresi
$categoryFilter = $_GET['category'] ?? '';
$searchQuery = $_GET['search'] ?? '';
$sortBy = $_GET['sort'] ?? 'name';

// Kategorileri getir
try {
    $stmt = $db->query("SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order");
    $categories = $stmt->fetchAll();
    
    // Ürünleri getir
    $sql = "SELECT p.*, c.name as category_name, c.slug as category_slug,
            (SELECT COUNT(*) FROM favorites WHERE product_id = p.id AND user_id = ?) as is_favorite
            FROM products p
            JOIN categories c ON p.category_id = c.id
            WHERE p.is_active = 1";
    
    $params = [isLoggedIn() ? $_SESSION['user_id'] : 0];
    
    if ($categoryFilter) {
        $sql .= " AND c.slug = ?";
        $params[] = $categoryFilter;
    }
    
    if ($searchQuery) {
        $sql .= " AND (p.name LIKE ? OR p.description LIKE ?)";
        $params[] = "%$searchQuery%";
        $params[] = "%$searchQuery%";
    }
    
    // Sıralama
    switch ($sortBy) {
        case 'price_asc':
            $sql .= " ORDER BY p.price ASC";
            break;
        case 'price_desc':
            $sql .= " ORDER BY p.price DESC";
            break;
        case 'popular':
            $sql .= " ORDER BY p.order_count DESC";
            break;
        default:
            $sql .= " ORDER BY c.sort_order, p.name";
    }
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log('Menü veri hatası: ' . $e->getMessage());
    $categories = [];
    $products = [];
}
?>
<!DOCTYPE html>
<html lang="tr" data-bs-theme="<?php echo $_SESSION['user_theme'] ?? 'light'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menü - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    
    <style>
        .menu-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }
        
        .category-pills {
            display: flex;
            gap: 10px;
            overflow-x: auto;
            padding: 10px 0;
            margin-bottom: 20px;
        }
        
        .category-pills::-webkit-scrollbar {
            height: 5px;
        }
        
        .category-pill {
            padding: 8px 20px;
            border-radius: 20px;
            background: white;
            color: #667eea;
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.3s ease;
            border: 2px solid #667eea;
        }
        
        .category-pill:hover,
        .category-pill.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            transform: translateY(-2px);
        }
        
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }
        
        .product-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            position: relative;
        }
        
        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.15);
        }
        
        .product-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
            background: linear-gradient(135deg, #f5f5f5 0%, #e0e0e0 100%);
        }
        
        .product-body {
            padding: 1.5rem;
        }
        
        .product-category {
            font-size: 0.85rem;
            color: #718096;
            margin-bottom: 0.25rem;
        }
        
        .product-name {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .product-description {
            font-size: 0.9rem;
            color: #718096;
            margin-bottom: 1rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .product-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .product-price {
            font-size: 1.5rem;
            font-weight: 700;
            color: #667eea;
        }
        
        .product-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-favorite {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #e2e8f0;
            background: white;
            color: #cbd5e0;
            transition: all 0.3s ease;
        }
        
        .btn-favorite:hover,
        .btn-favorite.active {
            background: #fff5f5;
            border-color: #f56565;
            color: #f56565;
        }
        
        .stock-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .stock-badge.out-of-stock {
            background: #fed7d7;
            color: #c53030;
        }
        
        .stock-badge.limited {
            background: #feebc8;
            color: #c05621;
        }
        
        .stock-badge.featured {
            background: #faf089;
            color: #744210;
        }
        
        .order-time-alert {
            background: #fff5f5;
            border-left: 4px solid #f56565;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .modal-product-image {
            width: 100%;
            height: 300px;
            object-fit: cover;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .size-options,
        .customization-options {
            margin-bottom: 20px;
        }
        
        .size-option,
        .custom-option {
            padding: 10px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            margin: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .size-option:hover,
        .custom-option:hover {
            border-color: #667eea;
        }
        
        .size-option.selected,
        .custom-option.selected {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: transparent;
        }
        
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #cbd5e0;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <?php include 'includes/navbar.php'; ?>
    
    <!-- Menu Header -->
    <div class="menu-header">
        <div class="container">
            <h1 class="display-5 mb-2">Kahve Menüsü</h1>
            <p class="lead mb-0">En sevdiğiniz kahveleri keşfedin</p>
        </div>
    </div>
    
    <div class="container">
        <!-- Sipariş Saati Uyarısı -->
        <?php if (!$canOrder): ?>
        <div class="order-time-alert">
            <h6 class="mb-2"><i class="fas fa-clock me-2"></i> Sipariş Saatleri Dışındasınız</h6>
            <p class="mb-2">Şu anda sipariş veremezsiniz. Sipariş saatlerimiz:</p>
            <div class="row">
                <?php 
                $days = ['', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];
                $currentDay = 0;
                foreach ($orderHours as $hour): 
                    if ($currentDay != $hour['day_of_week']):
                        if ($currentDay > 0) echo '</ul></div>';
                        $currentDay = $hour['day_of_week'];
                ?>
                <div class="col-md-4">
                    <strong><?php echo $days[$hour['day_of_week']]; ?>:</strong>
                    <ul class="mb-0">
                <?php endif; ?>
                    <li><?php echo substr($hour['start_time'], 0, 5) . ' - ' . substr($hour['end_time'], 0, 5); ?></li>
                <?php endforeach; ?>
                <?php if ($currentDay > 0) echo '</ul></div>'; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Arama ve Filtreler -->
        <div class="row mb-4">
            <div class="col-md-6">
                <form method="GET" action="" class="d-flex gap-2">
                    <input type="text" name="search" class="form-control" 
                           placeholder="Kahve ara..." value="<?php echo clean($searchQuery); ?>">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i>
                    </button>
                </form>
            </div>
            <div class="col-md-6">
                <div class="d-flex gap-2 justify-content-md-end">
                    <select name="sort" class="form-select" style="width: auto;" onchange="window.location.href='?sort=' + this.value + '&category=<?php echo $categoryFilter; ?>'">
                        <option value="name" <?php echo $sortBy == 'name' ? 'selected' : ''; ?>>İsme Göre</option>
                        <option value="price_asc" <?php echo $sortBy == 'price_asc' ? 'selected' : ''; ?>>Fiyat (Düşük)</option>
                        <option value="price_desc" <?php echo $sortBy == 'price_desc' ? 'selected' : ''; ?>>Fiyat (Yüksek)</option>
                        <option value="popular" <?php echo $sortBy == 'popular' ? 'selected' : ''; ?>>Popülerlik</option>
                    </select>
                </div>
            </div>
        </div>
        
        <!-- Kategori Filtreleri -->
        <div class="category-pills">
            <a href="menu.php" class="category-pill <?php echo !$categoryFilter ? 'active' : ''; ?>">
                <i class="fas fa-th me-1"></i> Tümü
            </a>
            <?php foreach ($categories as $category): ?>
            <a href="?category=<?php echo $category['slug']; ?>" 
               class="category-pill <?php echo $categoryFilter == $category['slug'] ? 'active' : ''; ?>">
                <?php echo clean($category['name']); ?>
            </a>
            <?php endforeach; ?>
        </div>
        
        <!-- Ürün Listesi -->
        <?php if (!empty($products)): ?>
        <div class="product-grid">
            <?php foreach ($products as $product): ?>
            <div class="product-card">
                <?php if ($product['stock_status'] == 'out_of_stock'): ?>
                <span class="stock-badge out-of-stock">Tükendi</span>
                <?php elseif ($product['stock_status'] == 'limited'): ?>
                <span class="stock-badge limited">Son Stoklar</span>
                <?php elseif ($product['is_featured']): ?>
                <span class="stock-badge featured">Öne Çıkan</span>
                <?php endif; ?>
                
                <img src="<?php echo $product['image'] ?? 'assets/images/default-coffee.jpg'; ?>" 
                     alt="<?php echo clean($product['name']); ?>" class="product-image">
                
                <div class="product-body">
                    <div class="product-category"><?php echo clean($product['category_name']); ?></div>
                    <h3 class="product-name"><?php echo clean($product['name']); ?></h3>
                    <p class="product-description"><?php echo clean($product['description']); ?></p>
                    
                    <?php if ($product['calories'] || $product['caffeine_mg']): ?>
                    <div class="d-flex gap-3 mb-3 small text-muted">
                        <?php if ($product['calories']): ?>
                        <span><i class="fas fa-fire"></i> <?php echo $product['calories']; ?> kal</span>
                        <?php endif; ?>
                        <?php if ($product['caffeine_mg']): ?>
                        <span><i class="fas fa-bolt"></i> <?php echo $product['caffeine_mg']; ?>mg kafein</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="product-footer">
                        <div class="product-price"><?php echo formatMoney($product['price']); ?></div>
                        <div class="product-actions">
                            <?php if (isLoggedIn()): ?>
                            <button class="btn-favorite <?php echo $product['is_favorite'] ? 'active' : ''; ?>" 
                                    onclick="toggleFavorite(<?php echo $product['id']; ?>)">
                                <i class="fas fa-heart"></i>
                            </button>
                            <?php endif; ?>
                            
                            <?php if ($product['stock_status'] != 'out_of_stock' && $canOrder): ?>
                            <button class="btn btn-primary" 
                                    onclick="showProductModal(<?php echo htmlspecialchars(json_encode($product), ENT_QUOTES); ?>)">
                                <i class="fas fa-cart-plus"></i> Sepete Ekle
                            </button>
                            <?php elseif ($product['stock_status'] == 'out_of_stock'): ?>
                            <button class="btn btn-secondary" disabled>
                                <i class="fas fa-times"></i> Stokta Yok
                            </button>
                            <?php else: ?>
                            <button class="btn btn-secondary" disabled>
                                <i class="fas fa-clock"></i> Sipariş Saati Değil
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-coffee"></i>
            <h3>Ürün Bulunamadı</h3>
            <p class="text-muted">Arama kriterlerinize uygun ürün bulunamadı.</p>
            <a href="menu.php" class="btn btn-primary">Tüm Menüyü Gör</a>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Ürün Detay Modal -->
    <div class="modal fade" id="productModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Ürün Detayı</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- JavaScript ile doldurulacak -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                    <button type="button" class="btn btn-primary" onclick="addToCart()">
                        <i class="fas fa-cart-plus"></i> Sepete Ekle
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Footer -->
    <?php include 'includes/footer.php'; ?>
    
    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/app.js"></script>
    <script src="assets/js/cart.js"></script>
    
    <script>
        let selectedProduct = null;
        
        function showProductModal(product) {
            selectedProduct = product;
            
            let sizeOptions = '';
            if (product.size_options) {
                const sizes = JSON.parse(product.size_options);
                sizeOptions = '<h6>Boyut Seçin:</h6><div class="d-flex flex-wrap">';
                sizes.forEach((size, index) => {
                    sizeOptions += `<div class="size-option ${index === 0 ? 'selected' : ''}" data-size="${size}">${size}</div>`;
                });
                sizeOptions += '</div>';
            }
            
            let customOptions = '';
            if (product.customization_options) {
                const customs = JSON.parse(product.customization_options);
                customOptions = '<h6 class="mt-3">Özelleştir:</h6>';
                
                for (const [key, values] of Object.entries(customs)) {
                    customOptions += `<div class="mb-2"><strong>${key}:</strong><div class="d-flex flex-wrap">`;
                    values.forEach((value, index) => {
                        customOptions += `<div class="custom-option" data-key="${key}" data-value="${value}">${value}</div>`;
                    });
                    customOptions += '</div></div>';
                }
            }
            
            const modalBody = `
                <div class="row">
                    <div class="col-md-6">
                        <img src="${product.image || 'assets/images/default-coffee.jpg'}" 
                             alt="${product.name}" class="modal-product-image">
                    </div>
                    <div class="col-md-6">
                        <h4>${product.name}</h4>
                        <p class="text-muted">${product.description}</p>
                        <h3 class="text-primary mb-3">${formatMoney(product.price)}</h3>
                        
                        ${sizeOptions}
                        ${customOptions}
                        
                        <div class="mt-3">
                            <label for="quantity">Adet:</label>
                            <input type="number" id="quantity" class="form-control" value="1" min="1" max="10" style="width: 100px;">
                        </div>
                        
                        <div class="mt-3">
                            <label for="notes">Not (Opsiyonel):</label>
                            <textarea id="notes" class="form-control" rows="2" placeholder="Özel istekleriniz..."></textarea>
                        </div>
                    </div>
                </div>
            `;
            
            document.querySelector('#productModal .modal-body').innerHTML = modalBody;
            
            // Event listeners
            document.querySelectorAll('.size-option').forEach(el => {
                el.addEventListener('click', function() {
                    document.querySelectorAll('.size-option').forEach(s => s.classList.remove('selected'));
                    this.classList.add('selected');
                });
            });
            
            document.querySelectorAll('.custom-option').forEach(el => {
                el.addEventListener('click', function() {
                    const key = this.dataset.key;
                    document.querySelectorAll(`.custom-option[data-key="${key}"]`).forEach(s => s.classList.remove('selected'));
                    this.classList.add('selected');
                });
            });
            
            new bootstrap.Modal(document.getElementById('productModal')).show();
        }
        
        function formatMoney(amount) {
            return new Intl.NumberFormat('tr-TR', { 
                style: 'currency', 
                currency: 'TRY' 
            }).format(amount);
        }
        
        function toggleFavorite(productId) {
            <?php if (!isLoggedIn()): ?>
            window.location.href = 'login.php';
            return;
            <?php endif; ?>
            
            $.post('api/favorites.php', {
                action: 'toggle',
                product_id: productId
            }).done(function(response) {
                if (response.success) {
                    location.reload();
                }
            });
        }
        
        function addToCart() {
            <?php if (!isLoggedIn()): ?>
            window.location.href = 'login.php';
            return;
            <?php endif; ?>
            
            const size = document.querySelector('.size-option.selected')?.dataset.size;
            const quantity = document.getElementById('quantity').value;
            const notes = document.getElementById('notes').value;
            
            const customizations = {};
            document.querySelectorAll('.custom-option.selected').forEach(el => {
                customizations[el.dataset.key] = el.dataset.value;
            });
            
            $.post('api/cart.php', {
                action: 'add',
                product_id: selectedProduct.id,
                quantity: quantity,
                size: size,
                customizations: JSON.stringify(customizations),
                notes: notes
            }).done(function(response) {
                if (response.success) {
                    bootstrap.Modal.getInstance(document.getElementById('productModal')).hide();
                    Swal.fire({
                        icon: 'success',
                        title: 'Sepete Eklendi!',
                        text: selectedProduct.name + ' sepetinize eklendi.',
                        showConfirmButton: false,
                        timer: 1500
                    });
                    updateCartCount();
                } else {
                    Swal.fire('Hata!', response.message, 'error');
                }
            });
        }
    </script>
</body>
</html>