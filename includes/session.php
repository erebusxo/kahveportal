<?php
/**
 * KahvePortal - Session Management Functions
 * includes/session.php
 */

// Güvenlik: Direkt erişimi engelle
if (!defined('DB_HOST')) {
    die('Direct access not permitted');
}

/**
 * Kullanıcının giriş yapıp yapmadığını kontrol et
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_email']);
}

/**
 * Admin kontrolü
 */
function isAdmin() {
    return isLoggedIn() && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

/**
 * Kullanıcı bilgilerini session'a kaydet
 */
function setUserSession($user) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
    $_SESSION['user_first_name'] = $user['first_name'];
    $_SESSION['user_last_name'] = $user['last_name'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_avatar'] = $user['avatar'] ?? null;
    $_SESSION['user_balance'] = $user['balance'] ?? 0;
    $_SESSION['user_phone'] = $user['phone'] ?? '';
    $_SESSION['user_theme'] = $user['theme'] ?? 'light';
    $_SESSION['last_activity'] = time();
}

/**
 * Session'ı temizle
 */
function clearUserSession() {
    unset($_SESSION['user_id']);
    unset($_SESSION['user_email']);
    unset($_SESSION['user_name']);
    unset($_SESSION['user_first_name']);
    unset($_SESSION['user_last_name']);
    unset($_SESSION['user_role']);
    unset($_SESSION['user_avatar']);
    unset($_SESSION['user_balance']);
    unset($_SESSION['user_phone']);
    unset($_SESSION['user_theme']);
    unset($_SESSION['last_activity']);
    
    // Remember me token'ını da temizle
    if (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', time() - 3600, '/');
        unset($_COOKIE['remember_token']);
    }
}

/**
 * Session zaman aşımı kontrolü
 */
function checkSessionTimeout() {
    if (isLoggedIn()) {
        $last_activity = $_SESSION['last_activity'] ?? 0;
        $current_time = time();
        
        // Session zaman aşımı kontrolü (2 saat)
        if (($current_time - $last_activity) > SESSION_LIFETIME) {
            clearUserSession();
            return false;
        }
        
        // Son aktivite zamanını güncelle
        $_SESSION['last_activity'] = $current_time;
    }
    
    return true;
}

/**
 * Remember me token oluştur
 */
function generateRememberToken($user_id, $db) {
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + REMEMBER_ME_LIFETIME);
    
    try {
        // Eski tokenları temizle
        $stmt = $db->prepare("DELETE FROM remember_tokens WHERE user_id = ? OR expires_at < NOW()");
        $stmt->execute([$user_id]);
        
        // Yeni token ekle
        $stmt = $db->prepare("INSERT INTO remember_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$user_id, hash('sha256', $token), $expires]);
        
        // Cookie'yi set et
        setcookie('remember_token', $token, time() + REMEMBER_ME_LIFETIME, '/', '', isset($_SERVER['HTTPS']), true);
        
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Remember me token kontrolü
 */
function checkRememberToken() {
    global $db;
    
    if (!isset($_COOKIE['remember_token'])) {
        return false;
    }
    
    $token = $_COOKIE['remember_token'];
    $hashed_token = hash('sha256', $token);
    
    try {
        $stmt = $db->prepare("
            SELECT rt.user_id, u.* 
            FROM remember_tokens rt
            JOIN users u ON rt.user_id = u.id
            WHERE rt.token = ? AND rt.expires_at > NOW() AND u.status = 'active'
        ");
        $stmt->execute([$hashed_token]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Kullanıcıyı giriş yap
            setUserSession($user);
            
            // Token'ı yenile
            generateRememberToken($user['id'], $db);
            
            return true;
        } else {
            // Geçersiz token, cookie'yi temizle
            setcookie('remember_token', '', time() - 3600, '/');
            unset($_COOKIE['remember_token']);
        }
    } catch (PDOException $e) {
        // Hata durumunda cookie'yi temizle
        setcookie('remember_token', '', time() - 3600, '/');
        unset($_COOKIE['remember_token']);
    }
    
    return false;
}

/**
 * Remember me token'ını kaldır
 */
function removeRememberToken($user_id, $db) {
    try {
        $stmt = $db->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
        $stmt->execute([$user_id]);
        
        // Cookie'yi temizle
        if (isset($_COOKIE['remember_token'])) {
            setcookie('remember_token', '', time() - 3600, '/');
            unset($_COOKIE['remember_token']);
        }
        
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Giriş gerekli sayfalar için yönlendirme
 */
function requireLogin($redirect_to = '/login.php') {
    if (!isLoggedIn()) {
        header('Location: ' . $redirect_to);
        exit;
    }
}

/**
 * Admin gerekli sayfalar için yönlendirme
 */
function requireAdmin($redirect_to = '/login.php') {
    if (!isAdmin()) {
        header('Location: ' . $redirect_to);
        exit;
    }
}

/**
 * Kullanıcı bakiyesini güncelle
 */
function updateUserBalance($user_id, $new_balance, $db) {
    try {
        $stmt = $db->prepare("UPDATE users SET balance = ? WHERE id = ?");
        $result = $stmt->execute([$new_balance, $user_id]);
        
        if ($result && isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user_id) {
            $_SESSION['user_balance'] = $new_balance;
        }
        
        return $result;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Son aktivite zamanını güncelle
 */
function updateLastActivity($user_id, $db) {
    if (!$user_id) return false;
    
    try {
        $stmt = $db->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
        return $stmt->execute([$user_id]);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Kullanıcı tema ayarını güncelle
 */
function updateUserTheme($user_id, $theme, $db) {
    try {
        $stmt = $db->prepare("UPDATE users SET theme = ? WHERE id = ?");
        $result = $stmt->execute([$theme, $user_id]);
        
        if ($result && isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user_id) {
            $_SESSION['user_theme'] = $theme;
        }
        
        return $result;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Aktif kullanıcı sayısını getir (son 15 dakika)
 */
function getActiveUsersCount($db) {
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM users WHERE last_activity >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Session verilerini güvenli şekilde getir
 */
function getSessionData($key, $default = null) {
    return $_SESSION[$key] ?? $default;
}

/**
 * Flash mesajları için fonksiyonlar
 */
function setFlashMessage($type, $message) {
    $_SESSION['flash_messages'][] = [
        'type' => $type,
        'message' => $message
    ];
}

function getFlashMessages() {
    $messages = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);
    return $messages;
}

function hasFlashMessages() {
    return !empty($_SESSION['flash_messages']);
}

/**
 * Session hijacking koruması
 */
function regenerateSessionId() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

/**
 * IP adresi kontrolü (session hijacking koruması)
 */
function validateSessionIP() {
    $current_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $session_ip = $_SESSION['session_ip'] ?? '';
    
    if (!$session_ip) {
        $_SESSION['session_ip'] = $current_ip;
        return true;
    }
    
    return $current_ip === $session_ip;
}

/**
 * User Agent kontrolü (session hijacking koruması)
 */
function validateSessionUserAgent() {
    $current_ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $session_ua = $_SESSION['session_user_agent'] ?? '';
    
    if (!$session_ua) {
        $_SESSION['session_user_agent'] = $current_ua;
        return true;
    }
    
    return $current_ua === $session_ua;
}

/**
 * Session güvenlik kontrolü
 */
function validateSessionSecurity() {
    if (!validateSessionIP() || !validateSessionUserAgent()) {
        clearUserSession();
        return false;
    }
    
    return true;
}
?>