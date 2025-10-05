<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db_connect.php';
require 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Load secure configuration
$config = require 'config.php';

$message = '';
$message_type = 'error'; // 'error' or 'success'

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    // Basic validation to avoid unnecessary DB work / misuse
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Invalid email format provided.';
        $message_type = 'error';
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {
            // User found: proceed with token generation and email sending.
            $token = bin2hex(random_bytes(50));
            $expires = new DateTime('+1 hour');
            $expires_str = $expires->format('Y-m-d H:i:s');

            $update_stmt = $conn->prepare("UPDATE users SET reset_token = ?, reset_token_expires_at = ? WHERE id = ?");
            $update_stmt->bind_param("ssi", $token, $expires_str, $user['id']);

            if ($update_stmt->execute()) {
                $update_stmt->close();

                // Build a safe reset link
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
                $baseUrl = "{$scheme}://{$host}{$basePath}";
                $reset_link = $baseUrl . '/reset-password.php?token=' . urlencode($token);

                // Email Sending Logic
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = $config['mailer']['username'];
                    $mail->Password   = $config['mailer']['password'];
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;

                    $mail->setFrom($config['mailer']['username'], 'DAN-LEN Support');
                    $mail->addAddress($email);

                    $mail->isHTML(true);
                    $mail->Subject = 'Password Reset Request for DAN-LEN';
                    $mail->Body = "
                        <div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                            <h2 style='color: #4f46e5;'>Password Reset Request</h2>
                            <p>Hello,</p>
                            <p>We received a request to reset the password for your account. Please click the button below to set a new password. This link is only valid for 1 hour.</p>
                            <p style='text-align: center; margin: 20px 0;'>
                                <a href='{$reset_link}' style='background-color: #4f46e5; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold;'>Reset Password</a>
                            </p>
                            <p>If you did not request a password reset, please ignore this email or contact support if you have concerns.</p>
                            <hr style='border: none; border-top: 1px solid #eee; margin: 20px 0;'>
                            <p style='font-size: 0.9em; color: #777;'>Thank you,<br>The DAN-LEN Team</p>
                        </div>
                    ";
                    $mail->AltBody = "You requested a password reset. Copy and paste this link into your browser: " . $reset_link;

                    $mail->send();
                    $message = 'A password reset link has been sent to your email.';
                    $message_type = 'success';
                } catch (Exception $e) {
                    error_log('Password reset mailer error: ' . $e->getMessage());
                    // Inform the user that sending failed, but not the detailed reason.
                    $message = 'Could not send reset email. Please try again later.';
                    $message_type = 'error';
                }
            } else {
                $message = 'Could not create a reset token. Please try again.';
                $message_type = 'error';
            }
        } else {
            // User not found.
            // Note: Revealing that an email is not in the database can be a security risk (user enumeration).
            // The previous implementation intentionally avoided this. This change is per your request.
            $message = 'Email not found.';
            $message_type = 'error';
        }
        $stmt->close();
    }
    $conn->close();
}

// Compute alert classes once to avoid inline PHP in attributes which can break markup
$alert_classes = $message_type === 'success'
    ? 'bg-green-100 border-green-400 text-green-700' // Success style
    : 'bg-red-100 border-red-400 text-red-700';      // Error style
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        /* Scope base styles to the page class and let Tailwind handle layout centering */
        body.fp-page {
            font-family: 'Inter', sans-serif;
            background-image: url('/siaa/siaaproject/coding/backgrounds/loginbg.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }
        .fp-page .form-wrapper { background: #fff; border-radius: 24px; padding: 2rem; text-align: center; width: 90%; max-width: 400px; box-shadow: 0 15px 30px rgba(0, 0, 0, 0.1); }
        .fp-page .form-title { font-size: 2rem; font-weight: 700; color: #2d3748; margin-bottom: 1rem; }
        .fp-page .form-group { position: relative; margin-bottom: 1.5rem; text-align: left; }
        .fp-page .form-input { width: 100%; padding: 8px 0; font-size: 1rem; color: #4a5568; border: none; border-bottom: 2px solid #e2e8f0; background: transparent; outline: none; transition: border-bottom-color 0.3s; }
        .fp-page .form-input:focus { border-bottom-color: #6c5ce7; }
        .fp-page .form-label { position: absolute; left: 0; top: 8px; font-size: 1rem; color: #a0aec0; pointer-events: none; transition: 0.3s ease all; }
        .fp-page .form-input:focus ~ .form-label, .fp-page .form-input:not(:placeholder-shown) ~ .form-label { top: -16px; font-size: 0.875rem; color: #6c5ce7; }
        .fp-page .btn-submit { width: 100%; padding: 12px; background-color: #4f46e5; color: #fff; border: none; border-radius: 9999px; font-weight: 600; cursor: pointer; font-size: 1rem; transition: all 0.2s; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); }
        .fp-page .btn-submit:hover { background-color: #4338ca; box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15); }
        .fp-page .link-text { color: #6c5ce7; font-weight: 600; text-decoration: none; transition: color 0.2s; }
        .fp-page .link-text:hover { text-decoration: underline; color: #5544d1; }
    </style>
</head>
<!-- Let Tailwind utilities handle layout centering; keep page-specific class for scoped styles -->
<body class="fp-page flex items-center justify-center min-h-screen">
    <main class="w-full p-4">
        <div class="form-wrapper mx-auto">
            <?php if (!empty($message)): ?>
                <div class="<?php echo $alert_classes; ?> px-4 py-3 rounded relative mb-4 text-sm" role="alert">
                    <span><?php echo htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <form action="forgot-password.php" method="POST">
                <div class="text-center mb-6">
                    <a href="index.php" class="text-3xl font-bold text-indigo-600 hover:text-indigo-700 transition-colors">DAN-LEN</a>
                </div>
                <h1 class="form-title">Forgot Password</h1>
                <p class="text-gray-600 mb-6">Enter your email address and we will send you a link to reset your password.</p>
                <div class="form-group">
                    <input type="email" id="email" name="email" required class="form-input" placeholder=" " autocomplete="email">
                    <label for="email" class="form-label">Email Address</label>
                </div>
                <button type="submit" class="btn-submit">Send Reset Link</button>
            </form>
            <div class="text-center mt-4 text-sm text-gray-500">
                Remember your password? <a href="login.php" class="link-text">Log in</a>
            </div>
        </div>
    </main>
</body>
</html>