<?php
/**
 * KahvePortal - Email Functions using PHPMailer
 * includes/mail.php
 */

// Güvenlik: Direkt erişimi engelle
if (!defined('DB_HOST')) {
    die('Direct access not permitted');
}

// PHPMailer sınıflarını yükle
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// PHPMailer autoloader - Composer kullanılıyorsa
if (file_exists(ROOT_PATH . 'vendor/autoload.php')) {
    require ROOT_PATH . 'vendor/autoload.php';
} else {
    // Manuel yükleme
    require_once ROOT_PATH . 'vendor/phpmailer/src/PHPMailer.php';
    require_once ROOT_PATH . 'vendor/phpmailer/src/SMTP.php';
    require_once ROOT_PATH . 'vendor/phpmailer/src/Exception.php';
}

/**
 * PHPMailer yapılandırması
 */
function createMailer() {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = SMTP_AUTH;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        
        // Gönderen bilgileri
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        
        // Debug modunu ayarla
        if (DEBUG_MODE) {
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
        }
        
        return $mail;
        
    } catch (Exception $e) {
        error_log("PHPMailer yapılandırma hatası: " . $e->getMessage());
        return false;
    }
}

/**
 * Hoş geldin emaili gönder
 */
function sendWelcomeEmail($user_email, $user_name) {
    $mail = createMailer();
    if (!$mail) return false;
    
    try {
        $mail->addAddress($user_email, $user_name);
        
        $mail->isHTML(true);
        $mail->Subject = SITE_NAME . ' - Hoş Geldiniz!';
        
        $body = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
                .content { padding: 30px; background: #f9f9f9; }
                .button { display: inline-block; padding: 12px 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>' . SITE_NAME . '</h1>
                    <p>Kahve Sipariş Platformu</p>
                </div>
                <div class="content">
                    <h2>Merhaba ' . htmlspecialchars($user_name) . '!</h2>
                    <p>' . SITE_NAME . ' ailesine hoş geldiniz! Hesabınız başarıyla oluşturuldu.</p>
                    
                    <h3>Neler yapabilirsiniz?</h3>
                    <ul>
                        <li>Geniş kahve menümüzden sipariş verebilirsiniz</li>
                        <li>Bakiye yükleyebilir ve hızlı ödeme yapabilirsiniz</li>
                        <li>Sipariş geçmişinizi takip edebilirsiniz</li>
                        <li>Favorilerinizi kaydedebilirsiniz</li>
                    </ul>
                    
                    <p>Hemen başlamak için aşağıdaki butona tıklayın:</p>
                    <a href="' . SITE_URL . '" class="button">Sipariş Vermeye Başla</a>
                    
                    <p>Herhangi bir sorunuz olursa bizimle iletişime geçmekten çekinmeyin.</p>
                </div>
                <div class="footer">
                    <p>&copy; ' . date('Y') . ' ' . SITE_NAME . '. Tüm hakları saklıdır.</p>
                    <p>' . SITE_EMAIL . '</p>
                </div>
            </div>
        </body>
        </html>';
        
        $mail->Body = $body;
        $mail->AltBody = strip_tags($body);
        
        return $mail->send();
        
    } catch (Exception $e) {
        error_log("Email gönderme hatası: " . $e->getMessage());
        return false;
    }
}

/**
 * Şifre sıfırlama emaili gönder
 */
function sendPasswordResetEmail($user_email, $user_name, $reset_token) {
    $mail = createMailer();
    if (!$mail) return false;
    
    try {
        $mail->addAddress($user_email, $user_name);
        
        $mail->isHTML(true);
        $mail->Subject = SITE_NAME . ' - Şifre Sıfırlama';
        
        $reset_link = SITE_URL . '/reset-password.php?token=' . urlencode($reset_token);
        
        $body = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
                .content { padding: 30px; background: #f9f9f9; }
                .button { display: inline-block; padding: 12px 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 14px; }
                .warning { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 20px 0; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>' . SITE_NAME . '</h1>
                    <p>Şifre Sıfırlama</p>
                </div>
                <div class="content">
                    <h2>Merhaba ' . htmlspecialchars($user_name) . '</h2>
                    <p>Hesabınız için şifre sıfırlama talebinde bulundunuz.</p>
                    
                    <p>Şifrenizi sıfırlamak için aşağıdaki butona tıklayın:</p>
                    <a href="' . $reset_link . '" class="button">Şifremi Sıfırla</a>
                    
                    <div class="warning">
                        <strong>Önemli:</strong> Bu link 1 saat boyunca geçerlidir ve sadece bir kez kullanılabilir.
                    </div>
                    
                    <p>Eğer şifre sıfırlama talebinde bulunmadıysanız, bu emaili görmezden gelebilirsiniz.</p>
                    
                    <p><small>Link çalışmıyorsa, aşağıdaki adresi tarayıcınıza kopyalayın:<br>' . $reset_link . '</small></p>
                </div>
                <div class="footer">
                    <p>&copy; ' . date('Y') . ' ' . SITE_NAME . '. Tüm hakları saklıdır.</p>
                    <p>' . SITE_EMAIL . '</p>
                </div>
            </div>
        </body>
        </html>';
        
        $mail->Body = $body;
        $mail->AltBody = strip_tags($body);
        
        return $mail->send();
        
    } catch (Exception $e) {
        error_log("Şifre sıfırlama emaili hatası: " . $e->getMessage());
        return false;
    }
}

/**
 * Sipariş onay emaili gönder
 */
function sendOrderConfirmationEmail($user_email, $user_name, $order_data) {
    $mail = createMailer();
    if (!$mail) return false;
    
    try {
        $mail->addAddress($user_email, $user_name);
        
        $mail->isHTML(true);
        $mail->Subject = SITE_NAME . ' - Sipariş Onayı #' . $order_data['order_id'];
        
        $order_items = '';
        foreach ($order_data['items'] as $item) {
            $order_items .= '<tr>
                <td>' . htmlspecialchars($item['name']) . '</td>
                <td>' . $item['quantity'] . '</td>
                <td>' . number_format($item['price'], 2) . ' ₺</td>
                <td>' . number_format($item['total'], 2) . ' ₺</td>
            </tr>';
        }
        
        $body = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
                .content { padding: 30px; background: #f9f9f9; }
                .order-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                .order-table th, .order-table td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
                .order-table th { background-color: #667eea; color: white; }
                .total { font-size: 18px; font-weight: bold; color: #667eea; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>Sipariş Onayı</h1>
                    <p>Sipariş #' . $order_data['order_id'] . '</p>
                </div>
                <div class="content">
                    <h2>Merhaba ' . htmlspecialchars($user_name) . '</h2>
                    <p>Siparişiniz başarıyla alındı ve hazırlanmaya başlandı.</p>
                    
                    <h3>Sipariş Detayları:</h3>
                    <table class="order-table">
                        <thead>
                            <tr>
                                <th>Ürün</th>
                                <th>Adet</th>
                                <th>Birim Fiyat</th>
                                <th>Toplam</th>
                            </tr>
                        </thead>
                        <tbody>
                            ' . $order_items . '
                        </tbody>
                    </table>
                    
                    <p class="total">Toplam Tutar: ' . number_format($order_data['total'], 2) . ' ₺</p>
                    
                    <p><strong>Sipariş Zamanı:</strong> ' . date('d.m.Y H:i', strtotime($order_data['created_at'])) . '</p>
                    <p><strong>Tahmini Hazırlanma Süresi:</strong> 5-10 dakika</p>
                    
                    <p>Siparişiniz hazır olduğunda size bildirim göndereceğiz.</p>
                </div>
                <div class="footer">
                    <p>&copy; ' . date('Y') . ' ' . SITE_NAME . '. Tüm hakları saklıdır.</p>
                    <p>' . SITE_EMAIL . '</p>
                </div>
            </div>
        </body>
        </html>';
        
        $mail->Body = $body;
        $mail->AltBody = strip_tags($body);
        
        return $mail->send();
        
    } catch (Exception $e) {
        error_log("Sipariş onay emaili hatası: " . $e->getMessage());
        return false;
    }
}

/**
 * Sipariş durum güncelleme emaili gönder
 */
function sendOrderStatusEmail($user_email, $user_name, $order_id, $status, $message = '') {
    $mail = createMailer();
    if (!$mail) return false;
    
    try {
        $mail->addAddress($user_email, $user_name);
        
        $status_texts = [
            'preparing' => 'Hazırlanıyor',
            'ready' => 'Hazır',
            'delivered' => 'Teslim Edildi',
            'cancelled' => 'İptal Edildi'
        ];
        
        $status_text = $status_texts[$status] ?? $status;
        
        $mail->isHTML(true);
        $mail->Subject = SITE_NAME . ' - Sipariş Durumu: ' . $status_text;
        
        $body = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
                .content { padding: 30px; background: #f9f9f9; }
                .status { padding: 20px; text-align: center; border-radius: 10px; margin: 20px 0; }
                .status.ready { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
                .status.preparing { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
                .status.delivered { background: #cce5ff; color: #004085; border: 1px solid #99d3ff; }
                .status.cancelled { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>Sipariş Durumu</h1>
                    <p>Sipariş #' . $order_id . '</p>
                </div>
                <div class="content">
                    <h2>Merhaba ' . htmlspecialchars($user_name) . '</h2>
                    
                    <div class="status ' . $status . '">
                        <h3>' . $status_text . '</h3>
                        ' . ($message ? '<p>' . htmlspecialchars($message) . '</p>' : '') . '
                    </div>
                    
                    <p>Sipariş durumunuzu web sitemizden de takip edebilirsiniz.</p>
                </div>
                <div class="footer">
                    <p>&copy; ' . date('Y') . ' ' . SITE_NAME . '. Tüm hakları saklıdır.</p>
                    <p>' . SITE_EMAIL . '</p>
                </div>
            </div>
        </body>
        </html>';
        
        $mail->Body = $body;
        $mail->AltBody = strip_tags($body);
        
        return $mail->send();
        
    } catch (Exception $e) {
        error_log("Sipariş durum emaili hatası: " . $e->getMessage());
        return false;
    }
}

/**
 * Bakiye bildirim emaili gönder
 */
function sendBalanceNotificationEmail($user_email, $user_name, $transaction_type, $amount, $new_balance) {
    $mail = createMailer();
    if (!$mail) return false;
    
    try {
        $mail->addAddress($user_email, $user_name);
        
        $type_texts = [
            'deposit' => 'Bakiye Yükleme',
            'purchase' => 'Satın Alma',
            'refund' => 'İade'
        ];
        
        $type_text = $type_texts[$transaction_type] ?? $transaction_type;
        
        $mail->isHTML(true);
        $mail->Subject = SITE_NAME . ' - ' . $type_text . ' Bildirimi';
        
        $body = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
                .content { padding: 30px; background: #f9f9f9; }
                .amount { font-size: 24px; font-weight: bold; color: #667eea; text-align: center; margin: 20px 0; }
                .balance { background: #e9ecef; padding: 15px; border-radius: 5px; text-align: center; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>' . $type_text . '</h1>
                </div>
                <div class="content">
                    <h2>Merhaba ' . htmlspecialchars($user_name) . '</h2>
                    <p>Hesabınızda bir bakiye hareketi gerçekleşti.</p>
                    
                    <div class="amount">
                        ' . ($transaction_type === 'purchase' ? '-' : '+') . number_format($amount, 2) . ' ₺
                    </div>
                    
                    <div class="balance">
                        <strong>Güncel Bakiye: ' . number_format($new_balance, 2) . ' ₺</strong>
                    </div>
                    
                    <p><strong>İşlem Zamanı:</strong> ' . date('d.m.Y H:i') . '</p>
                    
                    <p>Detaylı bakiye geçmişinizi hesabınızdan görüntüleyebilirsiniz.</p>
                </div>
                <div class="footer">
                    <p>&copy; ' . date('Y') . ' ' . SITE_NAME . '. Tüm hakları saklıdır.</p>
                    <p>' . SITE_EMAIL . '</p>
                </div>
            </div>
        </body>
        </html>';
        
        $mail->Body = $body;
        $mail->AltBody = strip_tags($body);
        
        return $mail->send();
        
    } catch (Exception $e) {
        error_log("Bakiye bildirim emaili hatası: " . $e->getMessage());
        return false;
    }
}

/**
 * Genel bildirim emaili gönder
 */
function sendNotificationEmail($user_email, $user_name, $subject, $message, $is_html = true) {
    $mail = createMailer();
    if (!$mail) return false;
    
    try {
        $mail->addAddress($user_email, $user_name);
        
        $mail->isHTML($is_html);
        $mail->Subject = SITE_NAME . ' - ' . $subject;
        
        if ($is_html) {
            $body = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
                    .content { padding: 30px; background: #f9f9f9; }
                    .footer { text-align: center; padding: 20px; color: #666; font-size: 14px; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h1>' . SITE_NAME . '</h1>
                        <p>' . htmlspecialchars($subject) . '</p>
                    </div>
                    <div class="content">
                        <h2>Merhaba ' . htmlspecialchars($user_name) . '</h2>
                        ' . $message . '
                    </div>
                    <div class="footer">
                        <p>&copy; ' . date('Y') . ' ' . SITE_NAME . '. Tüm hakları saklıdır.</p>
                        <p>' . SITE_EMAIL . '</p>
                    </div>
                </div>
            </body>
            </html>';
            $mail->Body = $body;
        } else {
            $mail->Body = $message;
        }
        
        return $mail->send();
        
    } catch (Exception $e) {
        error_log("Bildirim emaili hatası: " . $e->getMessage());
        return false;
    }
}

/**
 * Toplu email gönderimi
 */
function sendBulkEmail($recipients, $subject, $message, $is_html = true) {
    $mail = createMailer();
    if (!$mail) return false;
    
    $success_count = 0;
    $failed_recipients = [];
    
    foreach ($recipients as $recipient) {
        try {
            $mail->clearAddresses();
            $mail->addAddress($recipient['email'], $recipient['name']);
            
            $mail->isHTML($is_html);
            $mail->Subject = SITE_NAME . ' - ' . $subject;
            
            // Mesajda kişiselleştirme
            $personalized_message = str_replace('{name}', $recipient['name'], $message);
            
            if ($is_html) {
                $body = '
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="UTF-8">
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
                        .content { padding: 30px; background: #f9f9f9; }
                        .footer { text-align: center; padding: 20px; color: #666; font-size: 14px; }
                    </style>
                </head>
                <body>
                    <div class="container">
                        <div class="header">
                            <h1>' . SITE_NAME . '</h1>
                            <p>' . htmlspecialchars($subject) . '</p>
                        </div>
                        <div class="content">
                            ' . $personalized_message . '
                        </div>
                        <div class="footer">
                            <p>&copy; ' . date('Y') . ' ' . SITE_NAME . '. Tüm hakları saklıdır.</p>
                            <p>' . SITE_EMAIL . '</p>
                        </div>
                    </div>
                </body>
                </html>';
                $mail->Body = $body;
            } else {
                $mail->Body = $personalized_message;
            }
            
            if ($mail->send()) {
                $success_count++;
            } else {
                $failed_recipients[] = $recipient['email'];
            }
            
        } catch (Exception $e) {
            error_log("Toplu email hatası: " . $e->getMessage());
            $failed_recipients[] = $recipient['email'];
        }
    }
    
    return [
        'success_count' => $success_count,
        'failed_recipients' => $failed_recipients,
        'total_count' => count($recipients)
    ];
}
?>