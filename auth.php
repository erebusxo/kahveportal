<?php
/**
 * KahvePortal - Kimlik Doğrulama Fonksiyonları
 * includes/auth.php
 */

// Güvenlik: Direkt erişimi engelle
if (!defined('DB_HOST')) {
    die('Direct access not permitted');
}

/**
 * Kullanıcı Giriş Kontrolü
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Admin Kontrolü
 */
function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

/**
 * Kullanıcı Giriş Yap
 */
function loginUser($email, $password, $remember = false) {
    global $db;
    
    try {
        // Kullanıcıyı bul
        $stmt = $db->prepare("
            SELECT id, username, email, password, full_name, role, status, balance, avatar, theme
            FROM users 
            WHERE email = ? OR username = ?
            LIMIT 1
        ");
        
        $stmt->execute([$email, $email]);
        $user = $stmt->fetch();
        
        // Kullanıcı kontrolü
        if (!$user) {
            return ['success' => false, 'message' => 'Kullanıcı bulunamadı'];
        }
        
        // Hesap durumu kontrolü
        if ($user['status'] === 'banned') {
            return ['success' => false, 'message' => 'Hesabınız askıya alınmış'];
        }
        
        if ($user['status'] === 'inactive') {
            return ['success' => false, 'message' => 'Hesabınız aktif değil'];
        }
        
        // Şifre kontrolü
        if (!verifyPassword($password, $user['password'])) {
            // Başarısız giriş denemesi kaydet
            logFailedLogin($email);
            return ['success' => false, 'message' => 'Hatalı şifre'];
        }
        
        // Session değişkenlerini ayarla
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_balance'] = $user['balance'];
        $_SESSION['user_avatar'] = $user['avatar'];
        $_SESSION['user_theme'] = $user['theme'];
        
        // Beni hatırla
        if ($remember) {
            $token = generateRandomString(32);
            setcookie('remember_token', $token, time() + REMEMBER_ME_LIFETIME, '/', '', true, true);
            
            // Token'ı veritabanına kaydet (bu özellik için users tablosuna remember_token kolonu eklenebilir)
            $stmt = $db->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
            $stmt->execute([$token, $user['id']]);
        }
        
        // Son giriş zamanını güncelle
        $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);
        
        // Activity log
        logActivity('login', 'Kullanıcı girişi yapıldı');
        
        return ['success' => true, 'message' => 'Giriş başarılı'];
        
    } catch (PDOException $e) {
        error_log('Giriş hatası: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Bir hata oluştu'];
    }
}

/**
 * Kullanıcı Kayıt
 */
function registerUser($data) {
    global $db;
    
    try {
        // Email kontrolü
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$data['email']]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Bu email adresi zaten kayıtlı'];
        }
        
        // Username kontrolü
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$data['username']]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Bu kullanıcı adı zaten alınmış'];
        }
        
        // Şifre güvenlik kontrolü
        if (strlen($data['password']) < PASSWORD_MIN_LENGTH) {
            return ['success' => false, 'message' => 'Şifre en az ' . PASSWORD_MIN_LENGTH . ' karakter olmalıdır'];
        }
        
        // Kullanıcı oluştur
        $stmt = $db->prepare("
            INSERT INTO users (username, email, password, full_name, phone, balance, role, status)
            VALUES (?, ?, ?, ?, ?, ?, 'user', 'active')
        ");
        
        $result = $stmt->execute([
            $data['username'],
            $data['email'],
            hashPassword($data['password']),
            $data['full_name'],
            $data['phone'] ?? null,
            DEFAULT_USER_BALANCE
        ]);
        
        if ($result) {
            $userId = $db->lastInsertId();
            
            // Hoşgeldin bildirimi
            createNotification($userId, 'system', 'Hoş Geldiniz!', 
                'KahvePortal\'a hoş geldiniz. Keyifli kahveler dileriz!');
            
            // Hoşgeldin emaili gönder
            $emailBody = "
                <h2>Hoş Geldiniz {$data['full_name']}!</h2>
                <p>KahvePortal ailesine katıldığınız için teşekkür ederiz.</p>
                <p>Artık favori kahvelerinizi sipariş edebilir, bakiye yükleyebilir ve arkadaşlarınızla grup siparişi oluşturabilirsiniz.</p>
                <br>
                <p>İyi kahveler!</p>
            ";
            
            sendEmail($data['email'], 'KahvePortal\'a Hoş Geldiniz', $emailBody);
            
            // Activity log
            logActivity('register', 'Yeni kullanıcı kaydı: ' . $data['username']);
            
            return ['success' => true, 'message' => 'Kayıt başarılı! Giriş yapabilirsiniz.'];
        }
        
        return ['success' => false, 'message' => 'Kayıt oluşturulamadı'];
        
    } catch (PDOException $e) {
        error_log('Kayıt hatası: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Bir hata oluştu'];
    }
}

/**
 * Kullanıcı Çıkış
 */
function logoutUser() {
    // Activity log
    if (isset($_SESSION['user_id'])) {
        logActivity('logout', 'Kullanıcı çıkışı yapıldı');
    }
    
    // Session temizle
    $_SESSION = [];
    
    // Session cookie sil
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    
    // Remember me cookie sil
    if (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', time() - 3600, '/');
    }
    
    // Session'ı sonlandır
    session_destroy();
    
    return true;
}

/**
 * Şifre Sıfırlama Talebi
 */
function requestPasswordReset($email) {
    global $db;
    
    try {
        // Kullanıcıyı bul
        $stmt = $db->prepare("SELECT id, full_name FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return ['success' => false, 'message' => 'Bu email adresi kayıtlı değil'];
        }
        
        // Reset token oluştur
        $token = generateRandomString(32);
        $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Token'ı kaydet (users tablosuna reset_token ve reset_token_expiry kolonları eklenebilir)
        $stmt = $db->prepare("
            UPDATE users 
            SET reset_token = ?, reset_token_expiry = ? 
            WHERE id = ?
        ");
        $stmt->execute([$token, $expiry, $user['id']]);
        
        // Reset emaili gönder
        $resetLink = SITE_URL . '/reset-password.php?token=' . $token;
        
        $emailBody = "
            <h2>Şifre Sıfırlama</h2>
            <p>Merhaba {$user['full_name']},</p>
            <p>Şifrenizi sıfırlamak için aşağıdaki linke tıklayın:</p>
            <p><a href='{$resetLink}' style='background-color: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Şifremi Sıfırla</a></p>
            <p>Bu link 1 saat içinde geçerliliğini yitirecektir.</p>
            <p>Eğer bu talebi siz yapmadıysanız, bu emaili görmezden gelebilirsiniz.</p>
        ";
        
        sendEmail($email, 'Şifre Sıfırlama Talebi', $emailBody);
        
        return ['success' => true, 'message' => 'Şifre sıfırlama linki email adresinize gönderildi'];
        
    } catch (PDOException $e) {
        error_log('Şifre sıfırlama hatası: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Bir hata oluştu'];
    }
}

/**
 * Şifre Sıfırla
 */
function resetPassword($token, $newPassword) {
    global $db;
    
    try {
        // Token kontrolü
        $stmt = $db->prepare("
            SELECT id 
            FROM users 
            WHERE reset_token = ? 
            AND reset_token_expiry > NOW()
        ");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return ['success' => false, 'message' => 'Geçersiz veya süresi dolmuş token'];
        }
        
        // Şifre güvenlik kontrolü
        if (strlen($newPassword) < PASSWORD_MIN_LENGTH) {
            return ['success' => false, 'message' => 'Şifre en az ' . PASSWORD_MIN_LENGTH . ' karakter olmalıdır'];
        }
        
        // Şifreyi güncelle
        $stmt = $db->prepare("
            UPDATE users 
            SET password = ?, reset_token = NULL, reset_token_expiry = NULL 
            WHERE id = ?
        ");
        $stmt->execute([hashPassword($newPassword), $user['id']]);
        
        // Bildirim gönder
        createNotification($user['id'], 'system', 'Şifre Değiştirildi', 
            'Şifreniz başarıyla değiştirildi. Eğer bu işlemi siz yapmadıysanız lütfen bizimle iletişime geçin.');
        
        return ['success' => true, 'message' => 'Şifreniz başarıyla değiştirildi'];
        
    } catch (PDOException $e) {
        error_log('Şifre sıfırlama hatası: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Bir hata oluştu'];
    }
}

/**
 * Başarısız Giriş Denemesi Kaydet
 */
function logFailedLogin($identifier) {
    global $db;
    
    try {
        $stmt = $db->prepare("
            INSERT INTO activity_logs (action, description, ip_address, user_agent)
            VALUES ('failed_login', ?, ?, ?)
        ");
        
        $stmt->execute([
            'Başarısız giriş denemesi: ' . $identifier,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (PDOException $e) {
        error_log('Failed login log hatası: ' . $e->getMessage());
    }
}

/**
 * Remember Me Token Kontrolü
 */
function checkRememberToken() {
    if (!isset($_COOKIE['remember_token'])) {
        return false;
    }
    
    global $db;
    
    try {
        $token = $_COOKIE['remember_token'];
        
        $stmt = $db->prepare("
            SELECT id, username, email, full_name, role, balance, avatar, theme
            FROM users 
            WHERE remember_token = ? 
            AND status = 'active'
        ");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Session değişkenlerini ayarla
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_balance'] = $user['balance'];
            $_SESSION['user_avatar'] = $user['avatar'];
            $_SESSION['user_theme'] = $user['theme'];
            
            // Son giriş zamanını güncelle
            $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $stmt->execute([$user['id']]);
            
            return true;
        }
    } catch (PDOException $e) {
        error_log('Remember token hatası: ' . $e->getMessage());
    }
    
    return false;
}

/**
 * Erişim Kontrolü - Giriş Gerekli
 */
function requireLogin() {
    if (!isLoggedIn()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        redirect('/login.php', 'Bu sayfaya erişmek için giriş yapmalısınız', 'warning');
    }
}

/**
 * Erişim Kontrolü - Admin Gerekli
 */
function requireAdmin() {
    requireLogin();
    
    if (!isAdmin()) {
        redirect('/', 'Bu sayfaya erişim yetkiniz yok', 'error');
    }
}

/**
 * Kullanıcı Bilgilerini Güncelle (Session)
 */
function refreshUserSession($userId) {
    global $db;
    
    try {
        $stmt = $db->prepare("
            SELECT username, email, full_name, role, balance, avatar, theme
            FROM users 
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if ($user) {
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_balance'] = $user['balance'];
            $_SESSION['user_avatar'] = $user['avatar'];
            $_SESSION['user_theme'] = $user['theme'];
            return true;
        }
    } catch (PDOException $e) {
        error_log('Session yenileme hatası: ' . $e->getMessage());
    }
    
    return false;
}
?>