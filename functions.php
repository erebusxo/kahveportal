<?php
/**
 * KahvePortal - Yardımcı Fonksiyonlar
 * includes/functions.php
 */

// Güvenlik: Direkt erişimi engelle
if (!defined('DB_HOST')) {
    die('Direct access not permitted');
}

/**
 * XSS Koruması için string temizleme
 */
function clean($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * SQL Injection koruması için input temizleme
 */
function cleanInput($input) {
    $input = trim($input);
    $input = stripslashes($input);
    $input = htmlspecialchars($input);
    return $input;
}

/**
 * CSRF Token Kontrolü
 */
function validateCSRFToken($token) {
    return isset($_SESSION[CSRF_TOKEN_NAME]) && 
           hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

/**
 * CSRF Token Input Alanı Oluştur
 */
function generateCSRFInput() {
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . $_SESSION[CSRF_TOKEN_NAME] . '">';
}

/**
 * Şifre Hashleme
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Şifre Doğrulama
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Rastgele String Oluştur
 */
function generateRandomString($length = 10) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Sipariş Numarası Oluştur
 */
function generateOrderNumber() {
    return 'ORD-' . date('Ymd') . '-' . strtoupper(generateRandomString(4));
}

/**
 * Grup Sipariş Kodu Oluştur
 */
function generateGroupOrderCode() {
    return strtoupper(generateRandomString(6));
}

/**
 * Para Formatı
 */
function formatMoney($amount, $showSymbol = true) {
    $formatted = number_format($amount, DECIMAL_PLACES, ',', '.');
    return $showSymbol ? $formatted . ' ' . CURRENCY_SYMBOL : $formatted;
}

/**
 * Tarih Formatı
 */
function formatDate($date, $format = 'd.m.Y H:i') {
    if (empty($date)) return '-';
    $timestamp = is_numeric($date) ? $date : strtotime($date);
    return date($format, $timestamp);
}

/**
 * Göreceli Zaman (2 saat önce, dün, vs.)
 */
function timeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'Az önce';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' dakika önce';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' saat önce';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' gün önce';
    } else {
        return formatDate($datetime, 'd.m.Y');
    }
}

/**
 * Dosya Yükleme
 */
function uploadFile($file, $uploadDir, $allowedTypes = null) {
    if ($allowedTypes === null) {
        $allowedTypes = ALLOWED_IMAGE_TYPES;
    }
    
    // Hata kontrolü
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Dosya yükleme hatası'];
    }
    
    // Boyut kontrolü
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return ['success' => false, 'error' => 'Dosya boyutu çok büyük (Max: ' . (MAX_UPLOAD_SIZE / 1024 / 1024) . 'MB)'];
    }
    
    // Dosya tipi kontrolü
    $fileInfo = pathinfo($file['name']);
    $extension = strtolower($fileInfo['extension']);
    
    if (!in_array($extension, $allowedTypes)) {
        return ['success' => false, 'error' => 'Geçersiz dosya tipi'];
    }
    
    // Güvenli dosya adı oluştur
    $newFileName = uniqid() . '_' . time() . '.' . $extension;
    $uploadPath = $uploadDir . $newFileName;
    
    // Dizin yoksa oluştur
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Dosyayı taşı
    if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
        return ['success' => true, 'filename' => $newFileName];
    }
    
    return ['success' => false, 'error' => 'Dosya yüklenemedi'];
}

/**
 * Resim Boyutlandırma
 */
function resizeImage($sourcePath, $targetPath, $maxWidth, $maxHeight) {
    list($width, $height, $type) = getimagesize($sourcePath);
    
    $ratio = min($maxWidth / $width, $maxHeight / $height);
    $newWidth = round($width * $ratio);
    $newHeight = round($height * $ratio);
    
    $newImage = imagecreatetruecolor($newWidth, $newHeight);
    
    switch ($type) {
        case IMAGETYPE_JPEG:
            $sourceImage = imagecreatefromjpeg($sourcePath);
            break;
        case IMAGETYPE_PNG:
            $sourceImage = imagecreatefrompng($sourcePath);
            break;
        case IMAGETYPE_GIF:
            $sourceImage = imagecreatefromgif($sourcePath);
            break;
        default:
            return false;
    }
    
    imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    
    switch ($type) {
        case IMAGETYPE_JPEG:
            imagejpeg($newImage, $targetPath, 90);
            break;
        case IMAGETYPE_PNG:
            imagepng($newImage, $targetPath, 9);
            break;
        case IMAGETYPE_GIF:
            imagegif($newImage, $targetPath);
            break;
    }
    
    imagedestroy($newImage);
    imagedestroy($sourceImage);
    
    return true;
}

/**
 * Email Gönderme
 */
function sendEmail($to, $subject, $body, $isHTML = true) {
    // PHPMailer kullanımı için
    require_once ROOT_PATH . 'vendor/phpmailer/PHPMailer.php';
    require_once ROOT_PATH . 'vendor/phpmailer/SMTP.php';
    require_once ROOT_PATH . 'vendor/phpmailer/Exception.php';
    
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    
    try {
        // SMTP Ayarları
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = SMTP_AUTH;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';
        
        // Gönderen ve Alıcı
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($to);
        
        // İçerik
        $mail->isHTML($isHTML);
        $mail->Subject = $subject;
        $mail->Body = $body;
        
        if (!$isHTML) {
            $mail->AltBody = strip_tags($body);
        }
        
        return $mail->send();
    } catch (Exception $e) {
        error_log('Email gönderme hatası: ' . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Bildirim Oluştur
 */
function createNotification($userId, $type, $title, $message, $link = null) {
    global $db;
    
    try {
        $stmt = $db->prepare("
            INSERT INTO notifications (user_id, type, title, message, link)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        return $stmt->execute([$userId, $type, $title, $message, $link]);
    } catch (PDOException $e) {
        error_log('Bildirim oluşturma hatası: ' . $e->getMessage());
        return false;
    }
}

/**
 * Kullanıcı Bakiyesini Güncelle
 */
function updateUserBalance($userId, $amount, $type, $description = null, $referenceId = null, $referenceType = null) {
    global $db;
    
    try {
        $db->beginTransaction();
        
        // Mevcut bakiyeyi al
        $stmt = $db->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            throw new Exception('Kullanıcı bulunamadı');
        }
        
        $balanceBefore = $user['balance'];
        $balanceAfter = $balanceBefore + $amount;
        
        // Eksi bakiye kontrolü
        if ($balanceAfter < MAX_NEGATIVE_BALANCE) {
            throw new Exception('Yetersiz bakiye');
        }
        
        // Bakiyeyi güncelle
        $stmt = $db->prepare("UPDATE users SET balance = ? WHERE id = ?");
        $stmt->execute([$balanceAfter, $userId]);
        
        // İşlem kaydı oluştur
        $stmt = $db->prepare("
            INSERT INTO balance_transactions 
            (user_id, type, amount, balance_before, balance_after, description, reference_id, reference_type, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $userId,
            $type,
            $amount,
            $balanceBefore,
            $balanceAfter,
            $description,
            $referenceId,
            $referenceType,
            $_SESSION['user_id'] ?? null
        ]);
        
        $db->commit();
        
        // Düşük bakiye uyarısı
        if ($balanceAfter < MIN_BALANCE_WARNING && $balanceAfter >= 0) {
            createNotification($userId, 'balance', 'Düşük Bakiye Uyarısı', 
                'Bakiyeniz ' . formatMoney($balanceAfter) . ' seviyesine düştü. Lütfen bakiye yükleyin.');
        }
        
        return true;
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log('Bakiye güncelleme hatası: ' . $e->getMessage());
        return false;
    }
}

/**
 * Sipariş Saati Kontrolü
 */
function isOrderTimeValid() {
    global $db;
    
    $currentDay = date('N'); // 1=Pazartesi, 7=Pazar
    $currentTime = date('H:i:s');
    
    try {
        $stmt = $db->prepare("
            SELECT * FROM order_hours 
            WHERE day_of_week = ? 
            AND start_time <= ? 
            AND end_time >= ?
            AND is_active = 1
        ");
        
        $stmt->execute([$currentDay, $currentTime, $currentTime]);
        return $stmt->fetch() !== false;
        
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Aktif Sipariş Saatlerini Getir
 */
function getActiveOrderHours() {
    global $db;
    
    try {
        $stmt = $db->query("
            SELECT * FROM order_hours 
            WHERE is_active = 1 
            ORDER BY day_of_week, start_time
        ");
        
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Alert Mesajı Göster
 */
function showAlert($message, $type = 'info') {
    $icons = [
        'success' => 'check-circle',
        'error' => 'exclamation-circle', 
        'warning' => 'exclamation-triangle',
        'info' => 'info-circle'
    ];
    
    $icon = $icons[$type] ?? 'info-circle';
    
    return '
    <div class="alert alert-' . $type . ' alert-dismissible fade show" role="alert">
        <i class="fas fa-' . $icon . ' me-2"></i>
        ' . $message . '
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>';
}

/**
 * Sayfalama Oluştur
 */
function createPagination($totalItems, $currentPage, $itemsPerPage, $url) {
    $totalPages = ceil($totalItems / $itemsPerPage);
    
    if ($totalPages <= 1) return '';
    
    $html = '<nav><ul class="pagination justify-content-center">';
    
    // Önceki sayfa
    if ($currentPage > 1) {
        $html .= '<li class="page-item">
                    <a class="page-link" href="' . $url . '?page=' . ($currentPage - 1) . '">Önceki</a>
                  </li>';
    }
    
    // Sayfa numaraları
    for ($i = max(1, $currentPage - PAGINATION_RANGE); 
         $i <= min($totalPages, $currentPage + PAGINATION_RANGE); 
         $i++) {
        
        $active = ($i == $currentPage) ? 'active' : '';
        $html .= '<li class="page-item ' . $active . '">
                    <a class="page-link" href="' . $url . '?page=' . $i . '">' . $i . '</a>
                  </li>';
    }
    
    // Sonraki sayfa
    if ($currentPage < $totalPages) {
        $html .= '<li class="page-item">
                    <a class="page-link" href="' . $url . '?page=' . ($currentPage + 1) . '">Sonraki</a>
                  </li>';
    }
    
    $html .= '</ul></nav>';
    
    return $html;
}

/**
 * QR Kod Oluştur
 */
function generateQRCode($data, $size = 200) {
    $qrApiUrl = 'https://api.qrserver.com/v1/create-qr-code/';
    $params = [
        'size' => $size . 'x' . $size,
        'data' => $data
    ];
    
    return $qrApiUrl . '?' . http_build_query($params);
}

/**
 * Activity Log Kaydet
 */
function logActivity($action, $description = null) {
    global $db;
    
    try {
        $stmt = $db->prepare("
            INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        return $stmt->execute([
            $_SESSION['user_id'] ?? null,
            $action,
            $description,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (PDOException $e) {
        error_log('Activity log hatası: ' . $e->getMessage());
        return false;
    }
}

/**
 * Slug Oluştur
 */
function createSlug($text) {
    // Türkçe karakterleri değiştir
    $turkish = ['ş', 'Ş', 'ı', 'İ', 'ğ', 'Ğ', 'ü', 'Ü', 'ö', 'Ö', 'ç', 'Ç'];
    $english = ['s', 's', 'i', 'i', 'g', 'g', 'u', 'u', 'o', 'o', 'c', 'c'];
    
    $text = str_replace($turkish, $english, $text);
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9-]/', '-', $text);
    $text = preg_replace('/-+/', '-', $text);
    $text = trim($text, '-');
    
    return $text;
}

/**
 * JSON Response
 */
function jsonResponse($success, $message = '', $data = []) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

/**
 * Redirect
 */
function redirect($url, $message = null, $type = 'info') {
    if ($message) {
        $_SESSION['alert'] = [
            'message' => $message,
            'type' => $type
        ];
    }
    
    header('Location: ' . $url);
    exit;
}

/**
 * Session Alert Göster
 */
function displaySessionAlert() {
    if (isset($_SESSION['alert'])) {
        $alert = $_SESSION['alert'];
        unset($_SESSION['alert']);
        return showAlert($alert['message'], $alert['type']);
    }
    return '';
}
?>