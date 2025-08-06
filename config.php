<?php
/**
 * KahvePortal - Konfigürasyon Dosyası
 * includes/config.php
 */

// Hata raporlama (Production'da kapatın)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Zaman dilimi
date_default_timezone_set('Europe/Istanbul');

// Veritabanı Bağlantı Bilgileri
define('DB_HOST', 'localhost');
define('DB_NAME', 'acillvcs_kahve');
define('DB_USER', 'acillvcs_kahve');
define('DB_PASS', 'Ata120303*');
define('DB_CHARSET', 'utf8mb4');

// Site URL ve Dizin Yapısı
define('SITE_URL', 'https://kahveportal.com'); // Sitenizin tam URL'si
define('SITE_NAME', 'KahvePortal');
define('SITE_EMAIL', 'info@kahveportal.com');
define('ADMIN_EMAIL', 'admin@kahveportal.com');

// Dizin Yolları
define('ROOT_PATH', dirname(dirname(__FILE__)) . '/');
define('INCLUDES_PATH', ROOT_PATH . 'includes/');
define('ASSETS_PATH', ROOT_PATH . 'assets/');
define('UPLOADS_PATH', ROOT_PATH . 'uploads/');
define('ADMIN_PATH', ROOT_PATH . 'admin/');

// URL Yolları
define('ASSETS_URL', SITE_URL . '/assets/');
define('UPLOADS_URL', SITE_URL . '/uploads/');
define('ADMIN_URL', SITE_URL . '/admin/');

// Upload Dizinleri
define('RECEIPT_UPLOAD_PATH', UPLOADS_PATH . 'receipts/');
define('AVATAR_UPLOAD_PATH', UPLOADS_PATH . 'avatars/');
define('COFFEE_IMAGE_PATH', ASSETS_PATH . 'images/coffee/');

// Upload Limitleri
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp']);

// Session Ayarları
define('SESSION_NAME', 'kahveportal_session');
define('SESSION_LIFETIME', 7200); // 2 saat
define('REMEMBER_ME_LIFETIME', 30 * 24 * 60 * 60); // 30 gün

// Güvenlik Ayarları
define('CSRF_TOKEN_NAME', 'csrf_token');
define('PASSWORD_MIN_LENGTH', 8);
define('LOGIN_ATTEMPTS_LIMIT', 5);
define('LOGIN_BLOCK_TIME', 900); // 15 dakika

// Email Ayarları (PHPMailer için)
define('SMTP_HOST', 'smtp.gmail.com'); // SMTP sunucusu
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls'); // 'tls' veya 'ssl'
define('SMTP_AUTH', true);
define('SMTP_USERNAME', 'your-email@gmail.com'); // Email adresiniz
define('SMTP_PASSWORD', 'your-app-password'); // Email şifreniz veya app password
define('SMTP_FROM_EMAIL', 'noreply@kahveportal.com');
define('SMTP_FROM_NAME', 'KahvePortal');

// Sayfalama
define('ITEMS_PER_PAGE', 20);
define('PAGINATION_RANGE', 3);

// Para Birimi
define('CURRENCY_SYMBOL', '₺');
define('CURRENCY_CODE', 'TRY');
define('DECIMAL_PLACES', 2);

// Varsayılan Değerler
define('DEFAULT_AVATAR', ASSETS_URL . 'images/default-avatar.png');
define('DEFAULT_COFFEE_IMAGE', ASSETS_URL . 'images/default-coffee.png');
define('DEFAULT_USER_BALANCE', 0);
define('MIN_BALANCE_WARNING', 10); // Düşük bakiye uyarısı
define('MAX_NEGATIVE_BALANCE', -50); // Maksimum eksi bakiye

// Cache Ayarları
define('CACHE_ENABLED', false);
define('CACHE_LIFETIME', 3600); // 1 saat

// API Keys (Gerekirse)
define('GOOGLE_MAPS_API_KEY', '');
define('PUSHER_APP_ID', '');
define('PUSHER_APP_KEY', '');
define('PUSHER_APP_SECRET', '');
define('PUSHER_APP_CLUSTER', 'eu');

// Veritabanı Bağlantısı
try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET . " COLLATE utf8mb4_unicode_ci"
    ];
    
    $db = new PDO($dsn, DB_USER, DB_PASS, $options);
    
} catch (PDOException $e) {
    // Production'da hata detaylarını göstermeyin
    if (isset($_SERVER['SERVER_NAME']) && $_SERVER['SERVER_NAME'] !== 'localhost') {
        die('Veritabanı bağlantı hatası. Lütfen daha sonra tekrar deneyin.');
    } else {
        die('Veritabanı Bağlantı Hatası: ' . $e->getMessage());
    }
}

// Session Başlat
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

// CSRF Token Oluştur
if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
    $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
}

// Autoloader (Basit)
spl_autoload_register(function ($class) {
    $file = INCLUDES_PATH . 'classes/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Yardımcı Fonksiyonları Yükle
require_once INCLUDES_PATH . 'functions.php';
require_once INCLUDES_PATH . 'auth.php';

// Sistem Ayarlarını Yükle
function loadSettings($db) {
    $settings = [];
    try {
        $stmt = $db->query("SELECT setting_key, setting_value, setting_type FROM settings");
        while ($row = $stmt->fetch()) {
            $value = $row['setting_value'];
            
            // Tip dönüşümü
            switch ($row['setting_type']) {
                case 'number':
                    $value = is_numeric($value) ? (float)$value : 0;
                    break;
                case 'boolean':
                    $value = (bool)$value;
                    break;
                case 'json':
                    $value = json_decode($value, true) ?: [];
                    break;
            }
            
            $settings[$row['setting_key']] = $value;
        }
    } catch (PDOException $e) {
        // Hata durumunda varsayılan ayarlar kullanılır
    }
    
    return $settings;
}

// Global ayarlar değişkeni
$settings = loadSettings($db);

// Bakım Modu Kontrolü
if (isset($settings['maintenance_mode']) && $settings['maintenance_mode'] && !isAdmin()) {
    if (!strpos($_SERVER['REQUEST_URI'], '/admin') && !strpos($_SERVER['REQUEST_URI'], '/login')) {
        include ROOT_PATH . 'maintenance.php';
        exit;
    }
}

// Özel Sabitler (Ayarlardan)
if (isset($settings['iban_number'])) {
    define('BANK_IBAN', $settings['iban_number']);
}
if (isset($settings['bank_name'])) {
    define('BANK_NAME', $settings['bank_name']);
}
if (isset($settings['account_holder'])) {
    define('ACCOUNT_HOLDER', $settings['account_holder']);
}

// Debug Modu (Development için)
define('DEBUG_MODE', $_SERVER['SERVER_NAME'] === 'localhost');

if (DEBUG_MODE) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}
?>