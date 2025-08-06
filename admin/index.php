<?php
/**
 * KahvePortal - Admin Login Page
 * admin/index.php
 */

require_once '../config.php';
require_once '../includes/session.php';

// Eğer zaten giriş yapmışsa ve admin ise dashboard'a yönlendir
if (isLoggedIn()) {
    if (isAdmin()) {
        header('Location: dashboard.php');
        exit;
    } else {
        header('Location: ../index.php');
        exit;
    }
}

$error_message = '';
$success_message = '';

// Giriş işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    // CSRF token kontrolü
    if (!validateCSRFToken($csrf_token)) {
        $error_message = 'Güvenlik hatası. Lütfen tekrar deneyin.';
    } elseif (empty($email) || empty($password)) {
        $error_message = 'Email ve şifre gerekli.';
    } else {
        try {
            // Kullanıcıyı bul
            $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND status = 'active'");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                // Admin kontrolü
                if ($user['role'] !== 'admin') {
                    $error_message = 'Bu alana erişim yetkiniz yok.';
                } else {
                    // Giriş başarılı
                    setUserSession($user);
                    updateLastActivity($user['id'], $db);
                    
                    // Güvenlik için session ID'yi yenile
                    regenerateSessionId();
                    
                    header('Location: dashboard.php');
                    exit;
                }
            } else {
                $error_message = 'Geçersiz email veya şifre.';
                
                // Failed login attempt log
                error_log("Admin login failed for email: " . $email . " from IP: " . $_SERVER['REMOTE_ADDR']);
            }
        } catch (PDOException $e) {
            error_log("Admin login database error: " . $e->getMessage());
            $error_message = 'Sistem hatası. Lütfen daha sonra tekrar deneyin.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - Admin Girişi</title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../style.css">
    
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .admin-login-container {
            width: 100%;
            max-width: 400px;
            padding: 2rem;
        }
        
        .admin-login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            padding: 3rem 2rem;
        }
        
        .admin-logo {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .admin-logo i {
            font-size: 4rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .admin-logo h2 {
            margin-top: 1rem;
            color: #2d3748;
            font-weight: 700;
        }
        
        .admin-logo p {
            color: #718096;
            font-size: 0.9rem;
        }
        
        .form-floating {
            margin-bottom: 1.5rem;
        }
        
        .form-control {
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(102, 126, 234, 0.2);
            border-radius: 10px;
        }
        
        .form-control:focus {
            background: rgba(255, 255, 255, 0.9);
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        .btn-admin {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 10px;
            padding: 0.75rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-admin:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        
        .back-to-site {
            text-align: center;
            margin-top: 2rem;
        }
        
        .back-to-site a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .back-to-site a:hover {
            color: #764ba2;
        }
        
        .alert {
            border-radius: 10px;
            border: none;
            margin-bottom: 2rem;
        }
        
        .floating-elements {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            pointer-events: none;
            z-index: -1;
        }
        
        .floating-circle {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            animation: float 6s ease-in-out infinite;
        }
        
        .floating-circle:nth-child(1) {
            width: 80px;
            height: 80px;
            top: 20%;
            left: 10%;
            animation-delay: 0s;
        }
        
        .floating-circle:nth-child(2) {
            width: 120px;
            height: 120px;
            top: 60%;
            right: 15%;
            animation-delay: 2s;
        }
        
        .floating-circle:nth-child(3) {
            width: 60px;
            height: 60px;
            bottom: 20%;
            left: 20%;
            animation-delay: 4s;
        }
        
        @keyframes float {
            0%, 100% {
                transform: translateY(0px);
            }
            50% {
                transform: translateY(-20px);
            }
        }
        
        @media (max-width: 576px) {
            .admin-login-container {
                padding: 1rem;
            }
            
            .admin-login-card {
                padding: 2rem 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="floating-elements">
        <div class="floating-circle"></div>
        <div class="floating-circle"></div>
        <div class="floating-circle"></div>
    </div>

    <div class="admin-login-container">
        <div class="admin-login-card">
            <div class="admin-logo">
                <i class="fas fa-coffee"></i>
                <h2><?php echo SITE_NAME; ?></h2>
                <p>Yönetim Paneli</p>
            </div>
            
            <?php if ($error_message): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" class="needs-validation" novalidate>
                <?php echo generateCSRFInput(); ?>
                
                <div class="form-floating">
                    <input type="email" 
                           class="form-control" 
                           id="email" 
                           name="email" 
                           placeholder="admin@example.com" 
                           value="<?php echo htmlspecialchars($email ?? ''); ?>"
                           required>
                    <label for="email">
                        <i class="fas fa-envelope me-2"></i>Email Adresi
                    </label>
                    <div class="invalid-feedback">
                        Lütfen geçerli bir email adresi girin.
                    </div>
                </div>
                
                <div class="form-floating">
                    <input type="password" 
                           class="form-control" 
                           id="password" 
                           name="password" 
                           placeholder="Şifre"
                           required>
                    <label for="password">
                        <i class="fas fa-lock me-2"></i>Şifre
                    </label>
                    <div class="invalid-feedback">
                        Lütfen şifrenizi girin.
                    </div>
                </div>
                
                <button type="submit" class="btn btn-admin w-100 text-white">
                    <i class="fas fa-sign-in-alt me-2"></i>
                    Giriş Yap
                </button>
            </form>
            
            <div class="back-to-site">
                <a href="../index.php">
                    <i class="fas fa-arrow-left me-2"></i>
                    Ana Siteye Dön
                </a>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
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
        
        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    setTimeout(function() {
                        alert.remove();
                    }, 500);
                }, 5000);
            });
        });
        
        // Focus first input
        document.addEventListener('DOMContentLoaded', function() {
            const emailInput = document.getElementById('email');
            if (emailInput && !emailInput.value) {
                emailInput.focus();
            }
        });
    </script>
</body>
</html>