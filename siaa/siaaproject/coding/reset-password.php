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

function checkPasswordStrengthPHP($password) {
    $score = 0;
    if (strlen($password) >= 8) $score++;
    if (preg_match("/[a-z]/", $password)) $score++;
    if (preg_match("/[A-Z]/", $password)) $score++;
    if (preg_match("/[0-9]/", $password)) $score++;
    if (preg_match("/[^a-zA-Z0-9]/", $password)) $score++;
    // A score of 4 or 5 is 'strong' or 'very-strong'.
    return $score;

}

$message = '';
$token = $_GET['token'] ?? '';
$show_form = false;

if (empty($token) || !is_string($token) || strlen($token) !== 100) { // Tokens are 100 chars (50 bytes hex)
    $message = 'Invalid or missing reset token.';
} else {
    $stmt = $conn->prepare("SELECT id, reset_token_expires_at FROM users WHERE reset_token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if ($user && new DateTime() < new DateTime($user['reset_token_expires_at'])) {
        // Token is valid and not expired

        $show_form = true;
        $_SESSION['reset_user_id'] = (int)$user['id']; // Store user ID in session for the update
    } else {
        $message = 'Your password reset link is invalid or has expired.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['reset_user_id'])) {
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    $userId = (int)$_SESSION['reset_user_id'];
 
    if ($new_password === '' || $new_password !== $confirm_password) {
        $message = 'Passwords do not match or are empty.';
        $show_form = true; // Keep showing the form on error
    } elseif (checkPasswordStrengthPHP($new_password) < 4) {
        $message = 'Password is not strong enough. Please use a mix of uppercase, lowercase, numbers, and symbols.';
        $show_form = true;
    } else {
        // Hash and update password
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expires_at = NULL WHERE id = ?");
        $stmt->bind_param("si", $hashed_password, $userId);
        if ($stmt->execute()) {

            $stmt->close();

            // Fetch user's email and notification preference to send an alert
            $user_stmt = $conn->prepare("SELECT email, email_critical_alerts FROM users WHERE id = ?");
            $user_stmt->bind_param("i", $userId);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            $user_data = $user_result->fetch_assoc();
            $user_stmt->close();

            if ($user_data && $user_data['email_critical_alerts']) {
                $mail = new PHPMailer(true);
                try {
                    // Server settings from config
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = $config['mailer']['username'];
                    $mail->Password   = $config['mailer']['password'];
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;

                    //Recipients
                    $mail->setFrom($config['mailer']['username'], 'DAN-LEN Security');
                    $mail->addAddress($user_data['email']);

                    //Content
                    $mail->isHTML(true);
                    $mail->Subject = 'Security Alert: Your Password Has Been Changed';
                    $mail->Body    = "<div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'><h2 style='color: #d9534f;'>Security Alert</h2><p>Hello,</p><p>This is a notification that the password for your DAN-LEN account was recently changed. If you made this change, you can safely disregard this email.</p><p>If you did <strong>not</strong> make this change, please secure your account immediately by resetting your password and contacting our support team.</p><p>Thank you,<br>The DAN-LEN Team</p></div>";
                    $mail->AltBody = 'This is a notification that the password for your DAN-LEN account was recently changed. If you did not make this change, please secure your account immediately.';

                    $mail->send();
                } catch (Exception $e) {
                    // Log error but don't prevent the user from proceeding
                    error_log("Password change notification failed to send for user ID {$userId}: " . $mail->ErrorInfo);
                }
            }

            // Invalidate session info and regenerate session id
            unset($_SESSION['reset_user_id']);
            session_regenerate_id(true); // Prevent session fixation
            header("Location: login.php?message=" . urlencode("Password has been reset successfully!"));
            exit();
        } else {
            $message = 'Error updating password.';
            $show_form = true;

            $stmt->close();
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body.rp-page {
            font-family: 'Inter', sans-serif;
            background-image: url('/siaa/siaaproject/coding/backgrounds/loginbg.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .rp-page .form-wrapper { background: #fff; border-radius: 24px; padding: 2rem; text-align: center; width: 90%; max-width: 400px; box-shadow: 0 15px 30px rgba(0, 0, 0, 0.1); }
        .rp-page .form-title { font-size: 2rem; font-weight: 700; color: #2d3748; margin-bottom: 1rem; }
        .rp-page .form-group { position: relative; margin-bottom: 1.5rem; text-align: left; }
        .rp-page .form-input { width: 100%; padding: 8px 0; font-size: 1rem; color: #4a5568; border: none; border-bottom: 2px solid #e2e8f0; background: transparent; outline: none; transition: border-bottom-color 0.3s; }
        .rp-page .form-input:focus { border-bottom-color: #6c5ce7; }
        .rp-page .form-label { position: absolute; left: 0; top: 8px; font-size: 1rem; color: #a0aec0; pointer-events: none; transition: 0.3s ease all; }
        .rp-page .form-input:focus ~ .form-label, .rp-page .form-input:not(:placeholder-shown) ~ .form-label { top: -16px; font-size: 0.875rem; color: #6c5ce7; }
        .rp-page .btn-submit { width: 100%; padding: 12px; background-color: #4f46e5; color: #fff; border: none; border-radius: 9999px; font-weight: 600; cursor: pointer; font-size: 1rem; transition: all 0.2s; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); }
        .rp-page .btn-submit:hover { background-color: #4338ca; box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15); }
        .rp-page .password-toggle {
            position: absolute;
            right: 0;
            top: 8px;
            cursor: pointer;
            color: #a0aec0;
            transition: color 0.2s;
        }
        .rp-page .link-text { color: #6c5ce7; font-weight: 600; text-decoration: none; transition: color 0.2s; }
        .rp-page .link-text:hover { text-decoration: underline; color: #5544d1; }
        /* Password Strength Meter Styles */
        .password-strength-meter {
            display: flex;
            gap: 4px;
            height: 4px;
            margin-top: 0.5rem;
        }
        .strength-bar {
            flex-grow: 1;
            background-color: #e5e7eb; /* gray-200 */
            border-radius: 2px;
            transition: background-color 0.3s ease;
        }
        .strength-weak .strength-bar:nth-child(-n+1) { background-color: #ef4444; }
        .strength-medium .strength-bar:nth-child(-n+2) { background-color: #f97316; }
        .strength-strong .strength-bar:nth-child(-n+3) { background-color: #22c55e; }
        .strength-very-strong .strength-bar:nth-child(-n+4) { background-color: #16a34a; }
    </style>
</head>
<body class="rp-page">
    <main class="w-full p-4">
        <div class="form-wrapper mx-auto">
            <div class="text-center mb-6">
                <a href="index.php" class="text-3xl font-bold text-indigo-600 hover:text-indigo-700 transition-colors">DAN-LEN</a>
            </div>
            <h1 class="form-title">Reset Your Password</h1>

            <?php if (!empty($message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4 text-sm" role="alert">
                    <span><?php echo htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($show_form): ?>
                <form action="reset-password.php?token=<?= htmlspecialchars($token, ENT_QUOTES) ?>" method="POST">
                    <div class="form-group">
                        <input type="password" id="new_password" name="new_password" required maxlength="32" class="form-input" placeholder=" " autocomplete="new-password">
                        <label for="new_password" class="form-label">New Password</label>
                        <span class="password-toggle">
                            <i class="fa-solid fa-eye-slash"></i>
                        </span>
                    </div>
                    <div class="form-group">
                        <input type="password" id="confirm_password" name="confirm_password" required maxlength="32" class="form-input" placeholder=" " autocomplete="new-password">
                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                        <span class="password-toggle">
                            <i class="fa-solid fa-eye-slash"></i>
                        </span>
                        <div id="password-strength-meter" class="password-strength-meter">
                            <div class="strength-bar"></div>
                            <div class="strength-bar"></div>
                            <div class="strength-bar"></div>
                            <div class="strength-bar"></div>
                        </div>
                        <p id="password-strength-text" class="text-xs mt-1 text-gray-500"></p>
                    </div>
                    <button type="submit" class="btn-submit">Reset Password</button>
                </form>
            <?php else: ?>
                <div class="text-center mt-4 text-sm text-gray-500">
                    <a href="login.php" class="link-text">Back to Login</a>
                </div>
            <?php endif; ?>
        </div>
    </main>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const passwordInput = document.getElementById('new_password');
            const strengthMeter = document.getElementById('password-strength-meter');
            const strengthText = document.getElementById('password-strength-text');

            if (passwordInput && strengthMeter && strengthText) {
                passwordInput.addEventListener('input', () => {
                    const password = passwordInput.value;
                    const strength = checkPasswordStrength(password);
                    
                    strengthMeter.className = 'password-strength-meter'; // Reset classes
                    if (password.length > 0) {
                        strengthMeter.classList.add(`strength-${strength.level}`);
                    }
                    strengthText.textContent = strength.text;
                });
            }

            function checkPasswordStrength(password) {
                let score = 0;
                if (password.length >= 8) score++;
                if (password.match(/[a-z]/)) score++;
                if (password.match(/[A-Z]/)) score++;
                if (password.match(/[0-9]/)) score++;
                if (password.match(/[^a-zA-Z0-9]/)) score++;

                if (password.length === 0) return { level: '', text: '' };
                if (score <= 2) return { level: 'weak', text: 'Weak' };
                if (score === 3) return { level: 'medium', text: 'Medium' };
                if (score === 4) return { level: 'strong', text: 'Strong' };
                return { level: 'very-strong', text: 'Very Strong' };
            }

            // --- Password Visibility Toggle ---
            document.querySelectorAll('.password-toggle').forEach(btn => {
                btn.addEventListener('click', () => {
                    const input = btn.parentElement.querySelector('input');
                    const icon = btn.querySelector('i');
                    if (input.type === 'password') {
                        input.type = 'text';
                        icon.classList.replace('fa-eye-slash', 'fa-eye');
                    } else {
                        input.type = 'password';
                        icon.classList.replace('fa-eye', 'fa-eye-slash');
                    }
                });
            });
        });
    </script>
</body>
</html>