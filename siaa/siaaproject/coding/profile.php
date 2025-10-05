<?php
require_once 'auth_check.php'; // Check if user is logged in
require 'vendor/autoload.php';
require_once 'db_connect.php'; // Connect to the database

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$config = require 'config.php';
$userId = $_SESSION['user_id'];
$message = '';
$error = false;

// --- AJAX REQUEST HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    switch ($action) {
        case 'update_personal':
            $firstName = $_POST['first_name'] ?? '';
            $lastName = $_POST['last_name'] ?? '';
            $username = $_POST['username'] ?? '';
            $email = $_POST['email'] ?? '';

            // Validate uniqueness of username and email
            $stmt = $conn->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
            $stmt->bind_param("ssi", $username, $email, $userId);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows > 0) {
                echo json_encode(['success' => false, 'message' => 'Username or email is already in use by another account.']);
                $stmt->close();
                exit();
            }
            $stmt->close();

            // Update user data
            $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, username = ?, email = ? WHERE id = ?");
            $stmt->bind_param("ssssi", $firstName, $lastName, $username, $email, $userId);
            if ($stmt->execute()) {
                // Update session variables
                $_SESSION['first_name'] = $firstName;
                $_SESSION['last_name'] = $lastName;
                $_SESSION['username'] = $username;
                echo json_encode(['success' => true, 'message' => 'Profile details updated successfully!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error updating profile: ' . $stmt->error]);
            }
            $stmt->close();
            break;

        case 'upload_photo':
            if (isset($_POST['image'])) {
                // Get the old image path to delete it later
                $stmt = $conn->prepare("SELECT image_url FROM users WHERE id = ?");
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $result = $stmt->get_result();
                $user = $result->fetch_assoc();
                $old_image_url = $user['image_url'];
                $stmt->close();

                // Decode the base64 image data from Cropper.js
                $data = $_POST['image'];
                list($type, $data) = explode(';', $data);
                list(, $data)      = explode(',', $data);
                $data = base64_decode($data);

                $upload_dir = 'uploads/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $image_name = 'profile_' . $userId . '_' . time() . '.png';
                $image_path = $upload_dir . $image_name;

                if (file_put_contents($image_path, $data)) {
                    // Update the database with the new image path
                    $stmt = $conn->prepare("UPDATE users SET image_url = ? WHERE id = ?");
                    $stmt->bind_param("si", $image_path, $userId);
                    $stmt->execute();
                    $stmt->close();

                    // Update session with the new image URL
                    $_SESSION['image_url'] = $image_path;

                    // Delete the old photo if it's not a default one and exists
                    if (!empty($old_image_url) && file_exists($old_image_url) && strpos($old_image_url, 'ui-avatars.com') === false) {
                        unlink($old_image_url);
                    }

                    // SIMPLIFIED: Return only the relative path. This is more consistent.
                    echo json_encode(['success' => true, 'message' => 'Profile photo updated!', 'url' => $image_path]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to save the image.']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'No image data received.']);
            }
            break;

        case 'update_security':
            $currentPassword = $_POST['currentPassword'] ?? '';
            $newPassword = $_POST['newPassword'] ?? '';
            $confirmPassword = $_POST['confirmPassword'] ?? '';

            // Fetch current user's password
            $stmt = $conn->prepare("SELECT email, password, email_critical_alerts FROM users WHERE id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();

            if (!$user || !password_verify($currentPassword, $user['password'])) {
                echo json_encode(['success' => false, 'message' => 'Your current password is not correct.']);
                exit();
            }

            if (empty($newPassword) || $newPassword !== $confirmPassword) {
                echo json_encode(['success' => false, 'message' => 'New passwords do not match.']);
                exit();
            }

            // Optional: Add password strength check here

            $hashed_password = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $hashed_password, $userId);
            if ($stmt->execute()) {
                // If password change is successful and user has alerts enabled, send email.
                if ($user['email_critical_alerts']) {
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
                        $mail->addAddress($user['email']);

                        //Content
                        $mail->isHTML(true);
                        $mail->Subject = 'Security Alert: Your Password Has Been Changed';
                        $mail->Body    = "<div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'><h2 style='color: #d9534f;'>Security Alert</h2><p>Hello,</p><p>This is a notification that the password for your DAN-LEN account was recently changed from your profile page. If you made this change, you can safely disregard this email.</p><p>If you did <strong>not</strong> make this change, please secure your account immediately by resetting your password and contacting our support team.</p><p>Thank you,<br>The DAN-LEN Team</p></div>";
                        $mail->AltBody = 'This is a notification that the password for your DAN-LEN account was recently changed. If you did not make this change, please secure your account immediately.';

                        $mail->send();
                    } catch (Exception $e) {
                        // Log error but don't fail the user's request
                        error_log("Password change notification failed to send for user ID {$userId}: " . $mail->ErrorInfo);
                    }
                }

                echo json_encode(['success' => true, 'message' => 'Password changed successfully!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error changing password.']);
            }
            break;

        case 'update_notifications':
            // Convert checkbox values to boolean
            $emailCriticalAlerts = isset($_POST['emailCriticalAlerts']) && $_POST['emailCriticalAlerts'] === 'true';
            $lowStockAlerts = isset($_POST['lowStockAlerts']) && $_POST['lowStockAlerts'] === 'true';

            $stmt = $conn->prepare("UPDATE users SET email_critical_alerts = ?, low_stock_alerts = ? WHERE id = ?");
            $stmt->bind_param("iii", $emailCriticalAlerts, $lowStockAlerts, $userId);
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Notification settings updated!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error updating settings.']);
            }
            break;

        case 'enable_email_2fa':
            $password = $_POST['password'] ?? '';
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            if ($user && password_verify($password, $user['password'])) {
                $update_stmt = $conn->prepare("UPDATE users SET two_factor_method = 'email' WHERE id = ?");
                $update_stmt->bind_param("i", $userId);
                $update_stmt->execute();
                echo json_encode(['success' => true, 'message' => 'Email 2FA enabled successfully!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Incorrect password.']);
            }
            break;

        case 'disable_2fa':
            $password = $_POST['password'] ?? '';
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            if ($user && password_verify($password, $user['password'])) {
                $update_stmt = $conn->prepare("UPDATE users SET two_factor_method = NULL WHERE id = ?");
                $update_stmt->bind_param("i", $userId);
                $update_stmt->execute();
                echo json_encode(['success' => true, 'message' => '2FA has been disabled.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Incorrect password.']);
            }
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action.']);
            break;
    }
    $conn->close();
    exit();
}

// --- DATA FETCHING FOR PAGE LOAD ---
$stmt = $conn->prepare("SELECT first_name, last_name, username, email, image_url, email_critical_alerts, low_stock_alerts, two_factor_method FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();
$conn->close();
?>
<!DOCTYPE html> 
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DAN-LEN</title>    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.js"></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6; /* bg-gray-100 */
        }
        .dashboard-layout {
            display: flex;
        }
        .sidebar {
            width: 256px; /* w-64 */
            background-color: #4338ca; /* bg-indigo-700 */
            color: #d1d5db; /* text-gray-300 */
            padding: 1rem; /* p-4 */
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            transform: translateX(-100%);
            transition: transform 0.3s ease-in-out;
            z-index: 40;
        }
        .sidebar.show {
            transform: translateX(0);
        }
        .sidebar-header {
            padding: 1rem;
            font-size: 1.25rem;
            font-weight: 700;
            color: white;
        }
        .sidebar-header a { color: inherit; text-decoration: none; display: flex; align-items: center; gap: 0.75rem; }
        .sidebar-nav { list-style: none; padding: 0; margin: 1rem 0 0; }
        .sidebar-nav li { margin-bottom: 0.75rem; }
        .sidebar-nav a {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            color: #ffffff;
            text-decoration: none;
            border-radius: 0.375rem;
            transition: background-color 0.2s, color 0.2s, transform 0.2s ease-in-out;
        }
        .sidebar-nav a:hover { background-color: #111827; color: white; transform: scale(1.03); }
        .sidebar-nav a.active { background-color: #111827; color: white; }        .sidebar-nav a i { width: 1.25rem; margin-right: 0.75rem; text-align: center; }
        .main-content { margin-left: 0; flex-grow: 1; padding: 1.5rem; transition: margin-left 0.3s ease-in-out; }
        .main-header { display: flex; align-items: center; margin-bottom: 1.5rem; }
        .sidebar-toggle { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #374151; margin-right: 1rem; }
        .sidebar-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 30; opacity: 0; visibility: hidden; transition: opacity 0.3s, visibility 0.3s; }
        .sidebar-overlay.show { opacity: 1; visibility: visible; }
        @media (min-width: 768px) {
            .sidebar { transform: translateX(0); }
            .main-content { margin-left: 256px; }
            .sidebar-toggle { display: none; }
            .sidebar-overlay { display: none; }
        }

        .sidebar-item {
            position: relative;
            transition: all 0.2s;
        }
        .sidebar-item.active {
            background-color: #4f46e5;
            color: white;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
        }
        .content-tab {
            display: none;
        }
        /* Cropper.js Modal Styles */
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background-color: rgba(0, 0, 0, 0.6);
            display: flex; justify-content: center; align-items: center;
            z-index: 1000; opacity: 0; visibility: hidden;
            transition: opacity 0.3s, visibility 0.3s;
        }
        .modal-overlay.show { opacity: 1; visibility: visible; }
        .modal-content {
            background-color: #fff; padding: 1.5rem; border-radius: 0.75rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            max-width: 90vw; max-height: 90vh;
            width: 500px;
        }
        .password-toggle {
            position: absolute;
            right: 1rem;
            top: 2.35rem; /* Adjust to align with the input field */
            cursor: pointer;
            color: #9ca3af; /* gray-400 */
            transition: color 0.2s;
        }
        #image-to-crop {
            max-width: 100%;
            max-height: 60vh;
        }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <div id="sidebar-overlay" class="sidebar-overlay"></div>
        <nav id="sidebarMenu" class="sidebar">
            <div class="sidebar-header">
                <a href="index1.php"><i class="fas fa-store"></i><span>DAN-LEN</span></a>
            </div>
            <ul class="sidebar-nav">
                <li><a href="#" class="sidebar-settings-item active" data-tab="personal"><i class="fas fa-user-circle"></i>Personal Details</a></li>
                <li><a href="#" class="sidebar-settings-item" data-tab="security"><i class="fas fa-lock"></i>Security & Login</a></li>
                <li><a href="#" class="sidebar-settings-item" data-tab="picture"><i class="fas fa-camera"></i>Profile Picture</a></li>
                <li><a href="#" class="sidebar-settings-item" data-tab="notifications"><i class="fas fa-bell"></i>Notifications</a></li>
                <li><a href="index1.php" class="mt-auto pt-4 border-t border-indigo-500"><i class="fas fa-arrow-left"></i>Back to POS</a></li>
            </ul>
        </nav>

        <main class="main-content">
        <div id="save-message" class="fixed top-0 left-0 right-0 z-50 p-4 hidden justify-center">
            <div class="bg-green-600 text-white px-6 py-3 rounded-lg shadow-xl font-semibold transition-opacity duration-300">
                Profile successfully updated!
            </div>
        </div>

        <div class="main-header">
            <button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
            <h1 class="text-4xl font-extrabold text-gray-900">Account Information</h1>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="md:col-span-3 bg-white p-6 sm:p-8 rounded-xl shadow-lg">
                <div id="content-area">
                    <div id="tab-personal" class="content-tab space-y-6">
                        <h2 class="text-3xl font-bold text-gray-800">Personal Details</h2>
                        <p class="text-gray-500">Update your name, address, and contact information.</p>
                        <form id="personal-details-form">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6">
                                 <div class="mb-4">
                                    <label class="block text-sm font-medium text-gray-700 mb-1" for="first_name">First Name</label>
                                    <input type="text" id="first_name" name="first_name" oninput="updatePreview()"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 transition duration-150">
                                </div>
                                <div class="mb-4">
                                    <label class="block text-sm font-medium text-gray-700 mb-1" for="last_name">Last Name</label>
                                    <input type="text" id="last_name" name="last_name" oninput="updatePreview()"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 transition duration-150">
                                </div>
                                <div class="mb-4">
                                    <label class="block text-sm font-medium text-gray-700 mb-1" for="username">Username</label>
                                    <input type="text" id="username" name="username" oninput="updatePreview()"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 transition duration-150">
                                </div>
                                 <div class="mb-4">
                                    <label class="block text-sm font-medium text-gray-700 mb-1" for="email">Email Address</label>
                                    <input type="email" id="email" name="email" oninput="updatePreview()"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 transition duration-150">
                                </div>
                            </div>
                            <div class="pt-4">
                                <button type="button" onclick="handleSave('personal')" id="save-personal" disabled
                                    class="w-full sm:w-auto flex items-center justify-center px-6 py-3 border border-transparent text-base font-medium rounded-xl shadow-sm text-white bg-indigo-400 cursor-not-allowed transition duration-300">
                                    <span id="save-personal-text">Save Changes</span>
                                </button>
                            </div>
                        </form>
                    </div>

                    <div id="tab-security" class="content-tab space-y-6">
                        <h2 class="text-3xl font-bold text-gray-800">Security & Login</h2>
                        
                        <div class="border-b pb-6">
                            <h3 class="text-xl font-semibold text-gray-700">Change Password</h3>
                            <p class="text-gray-500 mt-1">Update your account password.</p>
                            <form id="security-form" class="mt-4">
                                <div class="space-y-4">
                                    <div class="relative">
                                        <label class="block text-sm font-medium text-gray-700 mb-1" for="currentPassword">Current Password</label>
                                        <input type="password" id="currentPassword" name="currentPassword" required maxlength="32" class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 transition duration-150">
                                        <span class="password-toggle">
                                            <i class="fa-solid fa-eye-slash"></i>
                                        </span>
                                    </div>
                                    <div class="relative">
                                        <label class="block text-sm font-medium text-gray-700 mb-1" for="newPassword">New Password</label>
                                        <input type="password" id="newPassword" name="newPassword" required maxlength="32" class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 transition duration-150">
                                        <span class="password-toggle">
                                            <i class="fa-solid fa-eye-slash"></i>
                                        </span>
                                    </div>
                                    <div class="relative">
                                        <label class="block text-sm font-medium text-gray-700 mb-1" for="confirmPassword">Confirm New Password</label>
                                        <input type="password" id="confirmPassword" name="confirmPassword" required maxlength="32" class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 transition duration-150">
                                        <span class="password-toggle">
                                            <i class="fa-solid fa-eye-slash"></i>
                                        </span>
                                </div>
                            </div>
                            <div class="pt-4">
                                <button type="button" onclick="handleSave('security')" id="save-security"
                                    class="w-full sm:w-auto flex items-center justify-center px-6 py-3 border border-transparent text-base font-medium rounded-xl shadow-sm text-white bg-red-600 hover:bg-red-700 transition duration-300">
                                    Change Password
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="border-b pb-6">
                        <h3 class="text-xl font-semibold text-gray-700">Two-Factor Authentication (2FA)</h3>
                        <p class="text-gray-500 mt-1">Add an extra layer of security to your account via email.</p>
                        <div class="mt-4 flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                            <p class="font-medium text-gray-700">
                                Status:
                                <span id="2fa-status-text" class="font-bold <?= empty($user['two_factor_method']) ? 'text-red-600' : 'text-green-600' ?>">
                                    <?= empty($user['two_factor_method']) ? 'DISABLED' : 'ENABLED' ?>
                                </span>
                            </p>
                            <?php if (empty($user['two_factor_method'])): ?>
                                <button id="enable-2fa-btn" class="px-4 py-2 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700">Enable 2FA</button>
                            <?php else: ?>
                                <button id="disable-2fa-btn" class="px-4 py-2 bg-red-600 text-white text-sm font-semibold rounded-lg hover:bg-red-700">Disable 2FA</button>
                            <?php endif; ?>
                        </div>
                    </div>




                    </div>

                    <div id="tab-picture" class="content-tab space-y-6 text-center">
                        <h2 class="text-3xl font-bold text-gray-800">Profile Picture</h2>
                        <p class="text-gray-500">A clear photo helps others recognize you.</p>
                        <div class="flex flex-col items-center space-y-4">
                            <img id="profile-photo-display" src="" alt="Current Profile"
                                class="w-32 h-32 object-cover rounded-full border-4 border-indigo-500 shadow-xl">
                            <input type="file" id="photo-upload-input" class="hidden" accept="image/*">
                            <button onclick="document.getElementById('photo-upload-input').click()"
                                class="flex items-center justify-center px-4 py-2 bg-indigo-500 text-white rounded-lg hover:bg-indigo-600 transition duration-200 shadow-md">
                                Change/Upload Photo
                            </button>
                        </div>
                    </div>

                    <div id="tab-notifications" class="content-tab space-y-6">
                        <h2 class="text-3xl font-bold text-gray-800">Notifications</h2>
                        <p class="text-gray-500">Decide how you want to be notified about updates.</p>
                        <form id="notifications-form">
                            <div class="bg-gray-50 p-6 rounded-xl space-y-2">
                                <div class="flex items-center justify-between py-3 border-b">
                                    <span class="text-base text-gray-700 font-medium">Email alerts for critical account activity</span>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name="emailCriticalAlerts" id="emailCriticalAlerts" onchange="checkFormDirty('notifications')" class="sr-only peer">
                                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                                    </label>
                                </div>
                                <div class="flex items-center justify-between py-3">
                                    <span class="text-base text-gray-700 font-medium">Notification alerts for low stock items</span>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name="lowStockAlerts" id="lowStockAlerts" onchange="checkFormDirty('notifications')" class="sr-only peer">
                                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                                    </label>
                                </div>
                            </div>
                            <div class="pt-4">
                                <button type="button" onclick="handleSave('notifications')" id="save-notifications" disabled
                                    class="w-full sm:w-auto flex items-center justify-center px-6 py-3 border border-transparent text-base font-medium rounded-xl shadow-sm text-white bg-indigo-400 cursor-not-allowed transition duration-300">
                                    <span id="save-notifications-text">Save Notification Preferences</span>
                                </button>
                            </div>
                        </form>
                    </div>

                </div>

                <div id="profile-preview" class="p-6 bg-white rounded-xl shadow-2xl border-t-4 border-indigo-500 mt-8">
                    <h3 class="text-2xl font-extrabold text-gray-900 mb-4 flex items-center">
                        Live Profile Preview
                    </h3>
                    <div class="flex flex-col sm:flex-row items-center space-y-4 sm:space-y-0 sm:space-x-6">
                        <img id="preview-photo" src="" alt="Profile Avatar"
                            class="w-24 h-24 object-cover rounded-full border-4 border-indigo-200 shadow-md">
                        <div class="text-center sm:text-left">
                            <p id="preview-name" class="text-2xl font-bold text-gray-800">Your Name</p>
                            <p id="preview-username" class="text-lg text-indigo-600 font-medium">@username</p>
                            <p id="preview-contact" class="text-sm text-gray-500 mt-1">Email not set</p>
                        </div>
                    </div>
                </div>

            </div> </main> <div id="photo-crop-modal" class="modal-overlay">
            <div class="modal-content">
                <h3 class="text-xl font-bold mb-4">Crop Your Photo</h3>
                <div>
                    <img id="image-to-crop" src="">
                </div>
                <div class="flex justify-end gap-4 mt-4">
                    <button id="cancel-crop-btn" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300">Cancel</button>
                    <button id="save-crop-btn" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">Save Photo</button>
                </div>
            </div>
        </div>

        <!-- 2FA Enable/Verify Modal -->
        <div id="2fa-enable-modal" class="modal-overlay">
            <div class="modal-content">
                <h3 class="text-xl font-bold mb-4">Confirm to Enable 2FA</h3>
                <p class="text-gray-600 mb-6">Please enter your password to confirm that you want to enable email-based Two-Factor Authentication.</p>
                <form id="enable-2fa-form">
                    <div class="mb-4">
                        <label for="enable-password-input" class="block text-sm font-medium text-gray-700 mb-1">Current Password</label>
                        <input type="password" id="enable-password-input" name="password" required maxlength="32" class="w-full px-4 py-2 border border-gray-300 rounded-lg" placeholder="Enter your password" autocomplete="current-password">
                    </div>
                    <div class="flex justify-end gap-4 mt-8">
                        <button type="button" id="cancel-2fa-enable" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">Confirm & Enable</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 2FA Disable Modal -->
        <div id="2fa-disable-modal" class="modal-overlay">
            <div class="modal-content">
                <h3 class="text-xl font-bold mb-4">Disable Two-Factor Authentication</h3>
                <p class="text-gray-600 mb-4">For your security, please enter your current password to disable 2FA.</p>
                <form id="disable-2fa-form">
                    <div class="mb-4">
                        <label for="disable-password-input" class="block text-sm font-medium text-gray-700 mb-1">Current Password</label>
                        <input type="password" id="disable-password-input" name="password" required maxlength="32" class="w-full px-4 py-2 border border-gray-300 rounded-lg" placeholder="Enter your password" autocomplete="current-password">
                    </div>
                    <div class="flex justify-end gap-4 mt-8">
                        <button type="button" id="cancel-2fa-disable" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">Confirm & Disable</button>
                    </div>
                </form>
            </div>
        </div>

    </div>

    <script>
        // Data is now fetched from PHP and injected into this JS object
        const userProfile = {
            firstName: "<?= htmlspecialchars($user['first_name'], ENT_QUOTES) ?>",
            lastName: "<?= htmlspecialchars($user['last_name'], ENT_QUOTES) ?>",
            username: "<?= htmlspecialchars($user['username'], ENT_QUOTES) ?>",
            email: "<?= htmlspecialchars($user['email'], ENT_QUOTES) ?>", // Add email to the profile object
            photoUrl: "<?= !empty($user['image_url']) ? htmlspecialchars($user['image_url'], ENT_QUOTES) : 'https://ui-avatars.com/api/?name=' . urlencode($user['first_name'] . ' ' . $user['last_name']) . '&background=4F46E5&color=fff' ?>",
            notifications: {
                emailCriticalAlerts: <?= !empty($user['email_critical_alerts']) ? 'true' : 'false' ?>,
                lowStockAlerts: <?= !empty($user['low_stock_alerts']) ? 'true' : 'false' ?>,
            },
            is2faEnabled: <?= !empty($user['two_factor_method']) ? 'true' : 'false' ?>
        };
        
        // Stores a copy of the original data to check for changes.
        // We will update this to the form's current state when a tab is switched.
        let originalFormData = {};
        let activeTab = 'personal';
        let cropper;
        
        // --- Core Functions ---

        // 2. Tab Switching Logic
        function switchTab(tabId) {
            activeTab = tabId;
            // Store the current state of the form when switching away
            storeOriginalFormData(tabId);

            // Hide all content tabs and reset sidebar buttons
            document.querySelectorAll('.content-tab').forEach(tab => {
                tab.style.display = 'none';
            });
            document.querySelectorAll('.sidebar-settings-item').forEach(btn => {
                btn.classList.remove('active');
            });

            // Show active content tab and set active button style
            const activeContent = document.getElementById(`tab-${tabId}`);
            const activeButton = document.querySelector(`.sidebar-settings-item[data-tab="${tabId}"]`);

            if (activeContent) activeContent.style.display = 'block';
            if (activeButton) activeButton.classList.add('active');

            // Re-check dirty state for the newly active form
            checkFormDirty(tabId);
        }

        // 3. Load Data into Form Fields (Simulated fetch/PHP data injection)
        function loadProfile() {
            // Personal Details
            document.getElementById('first_name').value = userProfile.firstName;
            document.getElementById('last_name').value = userProfile.lastName;
            document.getElementById('username').value = userProfile.username;
            document.getElementById('email').value = userProfile.email;

            // Notifications
            document.getElementById('emailCriticalAlerts').checked = userProfile.notifications.emailCriticalAlerts;
            document.getElementById('lowStockAlerts').checked = userProfile.notifications.lowStockAlerts;

            // Profile Picture
            document.getElementById('profile-photo-display').src = userProfile.photoUrl;

            // Initial preview update
            updatePreview();
            storeOriginalFormData('personal');
            storeOriginalFormData('notifications');
        }

        // 4. Live Profile Preview Update
        function updatePreview() {
            const firstName = document.getElementById('first_name').value;
            const lastName = document.getElementById('last_name').value;
            const username = document.getElementById('username').value;
            const email = document.getElementById('email').value;
            const photoUrl = document.getElementById('profile-photo-display').src;

            document.getElementById('preview-name').textContent = `${firstName || 'First'} ${lastName || 'Name'}`;
            document.getElementById('preview-username').textContent = `@${username || 'username'}`;
            document.getElementById('preview-contact').textContent = `${email || 'Email not set'}`;
            document.getElementById('preview-photo').src = photoUrl;

            // Check if Personal Details form is dirty
            if (activeTab === 'personal') {
                checkFormDirty('personal');
            }
        }

        // 5. Check if the form content differs from original data
        function checkFormDirty(tabId) {
            const form = document.getElementById(`${tabId}-details-form`) || document.getElementById(`${tabId}-form`);
            if (!form) return;

            const saveButton = document.getElementById(`save-${tabId}`);
            let isDirty = false;

            if (tabId === 'personal') {
                const currentData = {
                    first_name: form.elements['first_name'].value,
                    last_name: form.elements['last_name'].value,
                    username: form.elements['username'].value,
                    email: form.elements['email'].value,
                };
                if (JSON.stringify(currentData) !== JSON.stringify(originalFormData.personal)) {
                    isDirty = true;
                }
            } else if (tabId === 'notifications') {
                const newNotifications = {
                    emailCriticalAlerts: form.elements['emailCriticalAlerts'].checked,
                    lowStockAlerts: form.elements['lowStockAlerts'].checked,
                };
                if (JSON.stringify(newNotifications) !== JSON.stringify(originalFormData.notifications)) {
                    isDirty = true;
                }
            } else if (tabId === 'security') {
                // For security, we don't check for dirtiness, the button is always active.
                return;
            }

            // Enable/Disable save button and change style
            if (saveButton) {
                saveButton.disabled = !isDirty;
                saveButton.classList.toggle('bg-indigo-600', isDirty);
                saveButton.classList.toggle('hover:bg-indigo-700', isDirty);
                saveButton.classList.toggle('bg-indigo-400', !isDirty);
                saveButton.classList.toggle('cursor-not-allowed', !isDirty);
            }
        }

        function storeOriginalFormData(tabId) {
            const form = document.getElementById(`${tabId}-details-form`) || document.getElementById(`${tabId}-form`);
            if (!form) return;

            if (tabId === 'personal') {
                originalFormData.personal = {
                    first_name: form.elements['first_name'].value,
                    last_name: form.elements['last_name'].value,
                    username: form.elements['username'].value,
                    email: form.elements['email'].value,
                };
            } else if (tabId === 'notifications') {
                originalFormData.notifications = {
                    emailCriticalAlerts: form.elements['emailCriticalAlerts'].checked,
                    lowStockAlerts: form.elements['lowStockAlerts'].checked,
                };
            }
            // Add other forms here if needed
        }


        // 6. Handle Save Action (Simulated API call)
        async function handleSave(tabId) {
            const form = document.getElementById(`${tabId}-details-form`) || document.getElementById(`${tabId}-form`);
            const saveButton = document.getElementById(`save-${tabId}`); // e.g., save-personal
            const buttonTextSpan = saveButton.querySelector('span'); // Correctly find the span inside the button
            let formData;

            if (tabId === 'notifications') {
                // For notifications, we need to build the FormData manually to send boolean values
                formData = new FormData();
                formData.append('emailCriticalAlerts', form.elements['emailCriticalAlerts'].checked);
                formData.append('lowStockAlerts', form.elements['lowStockAlerts'].checked);
            } else {
                formData = new FormData(form);
            }
            formData.append('action', `update_${tabId}`);
            
            let originalButtonHTML = '';

            // For security form, we don't use the generic save button text logic
            if (tabId === 'security') {
                saveButton.disabled = true;
                saveButton.textContent = 'Saving...';
            } else {
                originalButtonHTML = saveButton.innerHTML;

                // Prevent double click and show loading
                saveButton.disabled = true;
                saveButton.textContent = 'Saving...';

            }
            try {
                const response = await fetch('profile.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    showSaveMessage(result.message);
                    storeOriginalFormData(tabId); // Update original data to match new state
                    if (tabId === 'security') {
                        form.reset(); // Clear password fields
                    } else {
                        checkFormDirty(tabId); // This will disable the button
                    }
                } else {
                    showSaveMessage(result.message, true); // Show error message
                }
            } catch (error) {
                showSaveMessage('A connection error occurred.', true);
            } finally {
                if (tabId === 'security') {
                    saveButton.disabled = false;
                    saveButton.textContent = 'Change Password';
                } else {
                    saveButton.innerHTML = originalButtonHTML;
                }
            }
        }

        function showSaveMessage(message, isError = false) {
            const messageBox = document.getElementById('save-message');
            const innerDiv = messageBox.querySelector('div');
            innerDiv.textContent = message;
            innerDiv.classList.toggle('bg-green-600', !isError);
            innerDiv.classList.toggle('bg-red-600', isError);

            messageBox.classList.remove('hidden');
            messageBox.classList.add('flex');
            setTimeout(() => {
                messageBox.classList.remove('flex');
                messageBox.classList.add('hidden');
            }, 3000);
        }


        // --- Initialization ---
        window.onload = function() {
            loadProfile();
            // Default to the first tab
            switchTab('personal');
        };

        // Event listener for Personal Details form input to trigger dirtiness check
        document.getElementById('personal-details-form').addEventListener('input', () => { checkFormDirty('personal'); });
        document.getElementById('notifications-form').addEventListener('change', () => { checkFormDirty('notifications'); });

        // New: Event listener for the main sidebar settings items
        document.querySelectorAll('.sidebar-settings-item').forEach(item => {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                const tabId = this.dataset.tab;
                switchTab(tabId);
            });
        });

        // --- Sidebar Toggle Logic from other pages ---
        const sidebar = document.getElementById('sidebarMenu');
        const toggleButton = document.getElementById('sidebarToggle');
        const overlay = document.getElementById('sidebar-overlay');

        function closeSidebar() {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
        }

        if (toggleButton) {
            toggleButton.addEventListener('click', () => {
                sidebar.classList.toggle('show');
                overlay.classList.toggle('show');
            });
        }

        if (overlay) {
            overlay.addEventListener('click', closeSidebar);
        }

        // --- Photo Cropping Logic ---
        const photoInput = document.getElementById('photo-upload-input');
        const cropModal = document.getElementById('photo-crop-modal');
        const imageToCrop = document.getElementById('image-to-crop');
        const cancelCropBtn = document.getElementById('cancel-crop-btn');
        const saveCropBtn = document.getElementById('save-crop-btn');

        photoInput.addEventListener('change', (e) => {
            const files = e.target.files;
            if (files && files.length > 0) {
                const reader = new FileReader();
                reader.onload = (event) => {
                    imageToCrop.src = event.target.result;
                    cropModal.classList.add('show');
                    cropper = new Cropper(imageToCrop, {
                        aspectRatio: 1,
                        viewMode: 1,
                        background: false,
                    });
                };
                reader.readAsDataURL(files[0]);
            }
        });

        function closeCropModal() {
            cropModal.classList.remove('show');
            if (cropper) {
                cropper.destroy();
            }
            photoInput.value = ''; // Reset file input
        }

        cancelCropBtn.addEventListener('click', closeCropModal);

        saveCropBtn.addEventListener('click', async () => {
            if (cropper) {
                const canvas = cropper.getCroppedCanvas({
                    width: 256,
                    height: 256,
                });
                const croppedImageData = canvas.toDataURL('image/png');
                
                const formData = new FormData();
                formData.append('action', 'upload_photo');
                formData.append('image', croppedImageData);

                const response = await fetch('profile.php', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    // Add a cache-busting query parameter to force the browser to reload the image
                    const newImageUrl = result.url + '?t=' + new Date().getTime();
                    document.getElementById('profile-photo-display').src = newImageUrl;
                    // Set item in localStorage to notify other tabs
                    localStorage.setItem('profileImageUrl', result.url);

                    updatePreview();
                    showSaveMessage(result.message);
                } else {
                    showSaveMessage(result.message, true);
                }
                closeCropModal();
            }
        });

        // --- 2FA Logic ---
        const enable2faBtn = document.getElementById('enable-2fa-btn');
        const disable2faBtn = document.getElementById('disable-2fa-btn');
        const enableModal = document.getElementById('2fa-enable-modal');
        const disableModal = document.getElementById('2fa-disable-modal');

        if (enable2faBtn) {
            enable2faBtn.addEventListener('click', () => {
                enableModal.classList.add('show');
            });
        }

        if (disable2faBtn) {
            disable2faBtn.addEventListener('click', () => {
                disableModal.classList.add('show');
            });
        }

        // Close Modals
        document.getElementById('cancel-2fa-enable').addEventListener('click', () => {
            enableModal.classList.remove('show');
        });
        document.getElementById('cancel-2fa-disable').addEventListener('click', () => {
            disableModal.classList.remove('show');
        });

        // Handle 2FA Verification and Enabling
        document.getElementById('enable-2fa-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            formData.append('action', 'enable_email_2fa');

            const response = await fetch('profile.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                showSaveMessage(result.message);
                enableModal.classList.remove('show');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showSaveMessage(result.message, true);
            }
        });

        // Handle 2FA Disabling
        document.getElementById('disable-2fa-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            formData.append('action', 'disable_2fa');

            const response = await fetch('profile.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                showSaveMessage(result.message);
                disableModal.classList.remove('show');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showSaveMessage(result.message, true);
            }
        });

        // --- Password Visibility Toggle ---
        document.querySelectorAll('.password-toggle').forEach(btn => {
            btn.addEventListener('click', () => {
                const input = btn.previousElementSibling; // The input is right before the span
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




    </script>
</body>
</html>