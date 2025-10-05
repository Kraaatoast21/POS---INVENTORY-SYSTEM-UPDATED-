<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db_connect.php';
require 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$config = require 'config.php';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    // Allow login with username or email
    $stmt = $conn->prepare("SELECT id, username, email, password, role, first_name, last_name, image_url, two_factor_method FROM users WHERE username = ? OR email = ?");
    $stmt->bind_param("ss", $username, $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        if (password_verify($password, $user['password'])) {
            // Password is correct. Check for 2FA.
            if ($user['two_factor_method'] === 'email') {
                // Send Email OTP
                $otp = random_int(100000, 999999);
                $expires = new DateTime('+5 minutes');
                $expires_str = $expires->format('Y-m-d H:i:s');

                $update_stmt = $conn->prepare("UPDATE users SET email_otp_secret = ?, email_otp_expires_at = ? WHERE id = ?");
                $update_stmt->bind_param("ssi", $otp, $expires_str, $user['id']);
                $update_stmt->execute();

                try {
                    $mail = new PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host = 'smtp.gmail.com';
                    $mail->SMTPAuth = true;
                    $mail->Username = $config['mailer']['username'];
                    $mail->Password = $config['mailer']['password'];
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = 587;
                    $mail->setFrom($config['mailer']['username'], 'DAN-LEN Security');
                    $mail->addAddress($user['email']);
                    $mail->isHTML(true);
                    $mail->Subject = 'Your Login Verification Code';
                    $mail->Body = "Your login code is: <b>$otp</b>";
                    $mail->send();

                    $_SESSION['2fa_user_id'] = $user['id'];
                    header("Location: verify_otp.php");
                    exit();
                } catch (Exception $e) {
                    $message = "Could not send login code. Please try again.";
                    error_log("Login Email OTP Error for user {$user['id']}: " . $e->getMessage());
                }
            } else {
                // No 2FA, log in directly.
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name'] = $user['last_name'];
                $_SESSION['image_url'] = $user['image_url'];
                header("Location: index1.php?message=" . urlencode("Successfully logged in!"));
                exit();
            }
        }
    }
    $message = "Invalid username or password.";
    // Close statement if it was initialized
    if (isset($stmt)) {
        $stmt->close();
    }
    $conn->close();
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login & Register</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Tailwind CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-image: url('/siaa/siaaproject/coding/backgrounds/loginbg.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .form-wrapper {
            background: #fff;
            border-radius: 24px;
            padding: 2rem; /* Reduced padding */
            text-align: center;
            width: 90%;
            max-width: 400px; 
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.1);
            transition: height 0.3s ease-in-out;
        }

        .form-title {
            text-align: center;
            font-size: 2rem;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 1.5rem; /* Reduced margin */
            
            @media (min-width: 640px) {
                font-size: 2.5rem;
            }
        }
        
        .form-group {
            position: relative;
            margin-bottom: 1.5rem; /* Reduced margin */
            text-align: left;
        }

        .form-input {
            width: 100%;
            padding: 8px 0;
            font-size: 1rem;
            color: #4a5568;
            border: none;
            border-bottom: 2px solid #e2e8f0;
            background: transparent;
            outline: none;
            transition: border-bottom-color 0.3s ease-in-out;
        }

        .form-input:focus {
            border-bottom-color: #6c5ce7;
        }

        .form-label {
            position: absolute;
            left: 0;
            top: 8px;
            font-size: 1rem;
            color: #a0aec0;
            pointer-events: none;
            transition: 0.3s ease all;
        }

        .form-input:focus ~ .form-label,
        .form-input:not(:placeholder-shown) ~ .form-label {
            top: -16px;
            font-size: 0.875rem;
            color: #6c5ce7;
        }

        .password-toggle {
            position: absolute;
            right: 0;
            top: 8px;
            cursor: pointer;
            color: #a0aec0;
            transition: color 0.2s;
        }

        .password-toggle:hover {
            color: #2d3748;
        }

        .btn-submit {
            width: 100%;
            padding: 12px;
            background-color: #4f46e5; /* Tailwind's indigo-600 */
            color: #fff;
            border: none;
            border-radius: 9999px;
            font-weight: 600;
            cursor: pointer;
            font-size: 1rem;
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .btn-submit:hover {
            background-color: #4338ca; /* A slightly darker indigo-700 */
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }

        .link-text {
            color: #6c5ce7;
            font-weight: 600;
            text-decoration: none;
            transition: color 0.2s;
        }
        
        .link-text:hover {
            text-decoration: underline;
            color: #5544d1;
        }

        .hidden {
            display: none;
        }

        /* Notification styles */
        .notification {
            position: fixed;
            top: 1.5rem; /* Position from the top */
            left: 50%;
            background-color: #22c55e; /* green-500 */
            color: white;
            padding: 1rem 2rem; /* Make it bigger */
            border-radius: 9999px; /* Pill shape */
            display: flex; /* For icon alignment */
            align-items: center; /* For icon alignment */
            font-size: 1.125rem; /* Larger text */
            font-weight: 600;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            opacity: 0;
            transition: opacity 0.3s ease-in-out, transform 0.3s ease-in-out;
            transform: translateX(-50%) translateY(-50px); /* Start off-screen */
            z-index: 1000;
        }
        .notification.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0); /* Slide into view */
        }

    </style>
</head>

<body>
    <!-- Main Content Container -->
    <main class="w-full p-4">
        <!-- Notification placeholder -->
        <div class="notification" id="notification"></div>

        <div class="form-wrapper mx-auto" id="main-forms">
            <?php if (!empty($message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4 text-sm" role="alert">
                    <span><?php echo htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>
            <!-- Login Form -->
            <form id="loginForm" action="login.php" method="POST">
                <!-- Modern Logo Header -->
                <div class="text-center mb-6">
                    <a href="index.php" class="text-3xl font-bold text-indigo-600 hover:text-indigo-700 transition-colors">DAN-LEN</a> <!-- Reduced margin -->
                </div>
                <h1 class="form-title">Login</h1>
                <div class="form-group">
                    <input type="text" id="login-username" name="username" required class="form-input" placeholder=" " autocomplete="username" readonly onfocus="this.removeAttribute('readonly');">
                    <label for="login-username" class="form-label">Username or Email</label>
                </div>
                <div class="form-group">
                    <input type="password" id="login-password" name="password" required class="form-input" placeholder=" " autocomplete="current-password" readonly onfocus="this.removeAttribute('readonly');">
                    <label for="login-password" class="form-label">Password</label>
                    <span class="password-toggle hidden">
                        <i class="fa-solid fa-eye-slash"></i>
                    </span>
                    <div id="caps-lock-warning" class="text-green-500 text-base mt-2 hidden">CapsLock is ON.</div>
                </div>
                <button type="submit" class="btn-submit">Login</button>
                <div class="text-center mt-4 text-sm text-gray-500"> <!-- Reduced margin -->
                    <a href="forgot-password.php" class="link-text">Forgot Password?</a>
                </div>
                <div class="text-center mt-3 text-sm text-gray-500"> <!-- Reduced margin -->
                    Don't have an account? <a href="register.php" class="link-text">Sign up</a>
                </div>
            </form>
        </div>
    </main>

    <script>
        const navButton = document.getElementById("navButton");
        const loginForm = document.getElementById("loginForm");
        const passwordInput = document.getElementById("login-password");

        // This is no longer needed as the form now submits to PHP
        // loginForm.addEventListener("submit", e => {
        //     e.preventDefault();
        //     window.location.href = 'index.php';
        // });

        // Password Toggle
        document.querySelectorAll(".password-toggle").forEach(btn => {
            btn.addEventListener("click", () => {
                const input = btn.parentNode.querySelector("input[type='password'], input[type='text']");
                const icon = btn.querySelector("i");
                if (input.type === "password") {
                    input.type = "text";
                    icon.classList.replace("fa-eye-slash", "fa-eye");
                } else {
                    input.type = "password";
                    icon.classList.replace("fa-eye", "fa-eye-slash");
                }
            });
        });

        // Show/hide password toggle on input and change events
        document.querySelectorAll("input[type='password']").forEach(input => {
            function toggleIcon() {
                const toggle = input.parentNode.querySelector('.password-toggle');
                if (input.value.length > 0) {
                    toggle.classList.remove("hidden");
                } else {
                    toggle.classList.add("hidden");
                }
            }
            
            // Listen for input events (typing)
            input.addEventListener("input", toggleIcon);
            // Listen for change events (autofill)
            input.addEventListener("change", toggleIcon);
            
            // Also check on page load in case of autofill
            toggleIcon();
        });

        // Caps Lock Warning Logic
        const capsLockWarning = document.getElementById('caps-lock-warning');
        if (passwordInput && capsLockWarning) {
            passwordInput.addEventListener('keyup', function(event) {
                if (event.getModifierState('CapsLock')) {
                    capsLockWarning.classList.remove('hidden');
                } else {
                    capsLockWarning.classList.add('hidden');
                }
            });
        }
        // Notification Logic
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const message = urlParams.get('message');
            const notification = document.getElementById('notification');

            if (message && notification) {
                notification.innerHTML = `<i class="fas fa-check-circle mr-3"></i> ${decodeURIComponent(message)}`;
                notification.classList.add('show');
                setTimeout(() => {
                    notification.classList.remove('show');
                    // Clean the URL
                    window.history.replaceState({}, document.title, "login.php");
                }, 4000);
            }
        });
    </script>
</body>
</html>
