<?php
/**
 * KahvePortal - Kayıt Sayfası
 * register.php
 */

require_once 'includes/config.php';

// Zaten giriş yapmış kullanıcıyı yönlendir
if (isLoggedIn()) {
    redirect('dashboard.php');
}

// Form gönderildiğinde
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token kontrolü
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Güvenlik hatası. Lütfen tekrar deneyin.';
    } else {
        $data = [
            'username' => cleanInput($_POST['username'] ?? ''),
            'email' => cleanInput($_POST['email'] ?? ''),
            'password' => $_POST['password'] ?? '',
            'password_confirm' => $_POST['password_confirm'] ?? '',
            'full_name' => cleanInput($_POST['full_name'] ?? ''),
            'phone' => cleanInput($_POST['phone'] ?? '')
        ];
        
        // Validasyon
        $errors = [];
        
        if (empty($data['username']) || strlen($data['username']) < 3) {
            $errors[] = 'Kullanıcı adı en az 3 karakter olmalıdır.';
        }
        
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Geçerli bir email adresi giriniz.';
        }
        
        if (strlen($data['password']) < PASSWORD_MIN_LENGTH) {
            $errors[] = 'Şifre en az ' . PASSWORD_MIN_LENGTH . ' karakter olmalıdır.';
        }
        
        if ($data['password'] !== $data['password_confirm']) {
            $errors[] = 'Şifreler eşleşmiyor.';
        }
        
        if (empty($data['full_name'])) {
            $errors[] = 'Ad Soyad alanı zorunludur.';
        }
        
        if (!isset($_POST['terms'])) {
            $errors[] = 'Kullanım koşullarını kabul etmelisiniz.';
        }
        
        if (empty($errors)) {
            $result = registerUser($data);
            
            if ($result['success']) {
                redirect('login.php', $result['message'], 'success');
            } else {
                $error = $result['message'];
            }
        } else {
            $error = implode('<br>', $errors);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kayıt Ol - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px 0;
        }
        
        .register-container {
            max-width: 500px;
            width: 100%;
            padding: 20px;
        }
        
        .register-card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideIn 0.5s ease-out;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .register-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .register-header .logo {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 2rem;
            color: white;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .form-floating {
            margin-bottom: 15px;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        .btn-register {
            width: 100%;
            padding: 12px;
            font-size: 1.1rem;
            font-weight: 600;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 10px;
            color: white;
            transition: all 0.3s ease;
        }
        
        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        
        .password-strength {
            height: 5px;
            border-radius: 3px;
            margin-top: 5px;
            transition: all 0.3s ease;
        }
        
        .strength-weak { background: #f56565; width: 33%; }
        .strength-medium { background: #ed8936; width: 66%; }
        .strength-strong { background: #48bb78; width: 100%; }
        
        .links {
            text-align: center;
            margin-top: 20px;
        }
        
        .links a {
            color: #667eea;
            text-decoration: none;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }
        
        .links a:hover {
            color: #764ba2;
            text-decoration: underline;
        }
        
        .form-check-input:checked {
            background-color: #667eea;
            border-color: #667eea;
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-card">
            <div class="register-header">
                <div class="logo">
                    <i class="fas fa-coffee"></i>
                </div>
                <h2 class="mb-2">Hesap Oluştur</h2>
                <p class="text-muted">KahvePortal'a katılın</p>
            </div>
            
            <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="registerForm">
                <?php echo generateCSRFInput(); ?>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-floating">
                            <input type="text" class="form-control" id="username" name="username" 
                                   placeholder="Kullanıcı Adı" required
                                   value="<?php echo isset($_POST['username']) ? clean($_POST['username']) : ''; ?>">
                            <label for="username">Kullanıcı Adı</label>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="form-floating">
                            <input type="email" class="form-control" id="email" name="email" 
                                   placeholder="Email" required
                                   value="<?php echo isset($_POST['email']) ? clean($_POST['email']) : ''; ?>">
                            <label for="email">Email Adresi</label>
                        </div>
                    </div>
                </div>
                
                <div class="form-floating">
                    <input type="text" class="form-control" id="full_name" name="full_name" 
                           placeholder="Ad Soyad" required
                           value="<?php echo isset($_POST['full_name']) ? clean($_POST['full_name']) : ''; ?>">
                    <label for="full_name">Ad Soyad</label>
                </div>
                
                <div class="form-floating">
                    <input type="tel" class="form-control" id="phone" name="phone" 
                           placeholder="Telefon (Opsiyonel)"
                           value="<?php echo isset($_POST['phone']) ? clean($_POST['phone']) : ''; ?>">
                    <label for="phone">Telefon (Opsiyonel)</label>
                </div>
                
                <div class="form-floating">
                    <input type="password" class="form-control" id="password" name="password" 
                           placeholder="Şifre" required>
                    <label for="password">Şifre</label>
                    <div class="password-strength" id="passwordStrength"></div>
                </div>
                
                <div class="form-floating">
                    <input type="password" class="form-control" id="password_confirm" name="password_confirm" 
                           placeholder="Şifre Tekrar" required>
                    <label for="password_confirm">Şifre Tekrar</label>
                </div>
                
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="terms" name="terms" required>
                    <label class="form-check-label" for="terms">
                        <a href="#" data-bs-toggle="modal" data-bs-target="#termsModal">Kullanım koşullarını</a> okudum ve kabul ediyorum
                    </label>
                </div>
                
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="newsletter" name="newsletter">
                    <label class="form-check-label" for="newsletter">
                        Kampanya ve duyurulardan haberdar olmak istiyorum
                    </label>
                </div>
                
                <button type="submit" class="btn btn-register">
                    <i class="fas fa-user-plus me-2"></i> Kayıt Ol
                </button>
            </form>
            
            <div class="links">
                <p class="mb-2">Zaten hesabınız var mı?</p>
                <a href="login.php" class="d-block mb-3">
                    <i class="fas fa-sign-in-alt me-1"></i> Giriş Yap
                </a>
                <a href="/" class="text-muted">
                    <i class="fas fa-home me-1"></i> Ana Sayfaya Dön
                </a>
            </div>
        </div>
    </div>
    
    <!-- Kullanım Koşulları Modal -->
    <div class="modal fade" id="termsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Kullanım Koşulları ve Gizlilik Politikası</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6>1. Genel Koşullar</h6>
                    <p>KahvePortal'ı kullanarak aşağıdaki koşulları kabul etmiş olursunuz...</p>
                    
                    <h6>2. Üyelik</h6>
                    <p>Üyelik bilgilerinizin doğruluğundan siz sorumlusunuz...</p>
                    
                    <h6>3. Gizlilik</h6>
                    <p>Kişisel verileriniz güvenli bir şekilde saklanır ve üçüncü şahıslarla paylaşılmaz...</p>
                    
                    <h6>4. Ödeme ve İptal</h6>
                    <p>Bakiye yüklemeleri ve sipariş iptal koşulları...</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Şifre güvenlik kontrolü
        document.getElementById('password').addEventListener('input', function(e) {
            const password = e.target.value;
            const strength = document.getElementById('passwordStrength');
            
            if (password.length === 0) {
                strength.className = 'password-strength';
                return;
            }
            
            let score = 0;
            
            // Uzunluk kontrolü
            if (password.length >= 8) score++;
            if (password.length >= 12) score++;
            
            // Karakter çeşitliliği
            if (/[a-z]/.test(password)) score++;
            if (/[A-Z]/.test(password)) score++;
            if (/[0-9]/.test(password)) score++;
            if (/[^a-zA-Z0-9]/.test(password)) score++;
            
            if (score <= 2) {
                strength.className = 'password-strength strength-weak';
            } else if (score <= 4) {
                strength.className = 'password-strength strength-medium';
            } else {
                strength.className = 'password-strength strength-strong';
            }
        });
        
        // Şifre eşleşme kontrolü
        document.getElementById('password_confirm').addEventListener('input', function(e) {
            const password = document.getElementById('password').value;
            const confirm = e.target.value;
            
            if (confirm && password !== confirm) {
                e.target.setCustomValidity('Şifreler eşleşmiyor');
            } else {
                e.target.setCustomValidity('');
            }
        });
        
        // Form validasyonu
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('password_confirm').value;
            
            if (password !== confirm) {
                e.preventDefault();
                alert('Şifreler eşleşmiyor!');
                return false;
            }
            
            if (password.length < 8) {
                e.preventDefault();
                alert('Şifre en az 8 karakter olmalıdır!');
                return false;
            }
        });
    </script>
</body>
</html>