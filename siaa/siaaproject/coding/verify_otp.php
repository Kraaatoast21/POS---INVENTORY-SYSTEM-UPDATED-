<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db_connect.php';

// If the user isn't in the 2FA verification process, redirect them.
if (!isset($_SESSION['2fa_user_id'])) {
    header("Location: login.php");
    exit();
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = $_SESSION['2fa_user_id'];
    $otp = $_POST['otp'] ?? '';

    $stmt = $conn->prepare("SELECT email_otp_secret, email_otp_expires_at, username, role, first_name, last_name, image_url FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user) {
        $isExpired = new DateTime() > new DateTime($user['email_otp_expires_at']);
        if ($isExpired) {
            $message = "Verification code has expired. Please log in again.";
        } elseif ($otp == $user['email_otp_secret']) {
            // OTP is correct. Log the user in.
            $_SESSION['user_id'] = $userId;
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['image_url'] = $user['image_url'];

            // Clean up session and database
            unset($_SESSION['2fa_user_id']);
            $conn->query("UPDATE users SET email_otp_secret = NULL, email_otp_expires_at = NULL WHERE id = $userId");

            // Close connections before redirecting
            $stmt->close();
            $conn->close();

            header("Location: index1.php?message=" . urlencode("Successfully logged in!"));
            exit();
        } else {
            $message = "Invalid verification code.";
        }
    } else {
        $message = "An error occurred. Please try again.";
    }
    $stmt->close();
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-Factor Authentication</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: linear-gradient(180deg, #6c5ce7 0%, #00cec9 100%); }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen">
    <div class="bg-white p-8 rounded-2xl shadow-xl w-full max-w-md text-center">
        <h1 class="text-2xl font-bold text-gray-800 mb-2">Two-Factor Authentication</h1>
        <p class="text-gray-600 mb-6">Enter the 6-digit code sent to your email address.</p>

        <?php if (!empty($message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4 text-sm" role="alert">
                <span><?php echo htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>

        <form action="verify_otp.php" method="POST">
            <div class="mb-6">
                <label for="otp" class="block text-sm font-medium text-gray-700 mb-1">Verification Code</label>
                <input type="text" id="otp" name="otp" required class="w-full px-4 py-3 text-center text-2xl tracking-[1em] border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500" maxlength="6" autocomplete="one-time-code">
            </div>
            <button type="submit" class="w-full py-3 px-4 bg-indigo-600 text-white font-semibold rounded-lg shadow-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">Verify</button>
        </form>
        <div class="mt-4 text-sm">
            <a href="login.php" class="font-medium text-indigo-600 hover:text-indigo-500">Back to Login</a>
        </div>
    </div>
</body>
</html>