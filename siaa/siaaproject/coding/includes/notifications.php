<?php
require_once '../auth_check.php';
require_once '../db_connect.php';

// Role-based access control: only admins and cashiers can view
if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'cashier') {
    die("Access Denied: You do not have permission to access this page.");
}

$userId = $_SESSION['user_id'];

/**
 * Validates that a URL is a safe, local path and not an external redirect.
 * @param string $url The URL to validate.
 * @return bool True if the URL is safe, false otherwise.
 */
function is_safe_redirect($url) {
    // First, decode any URL encoding to prevent bypasses like %2e%2e%2f
    $decoded_url = urldecode($url);

    // 1. Ensure it's a relative path.
    // It should start with '../' to go up one level from 'includes/'.
    // It should not contain '..' again to prevent going up further.
    if (strpos($decoded_url, '../') !== 0 || strpos(substr($decoded_url, 3), '..') !== false) {
        return false;
    }
    // 2. Disallow protocols like http://, https://, ftp://, etc.
    if (preg_match('#^//|:|\\\\#', $decoded_url)) {
        return false;
    }
    return true;
}

// Handle marking notifications as read
if (isset($_GET['mark_as_read'])) {
    $notificationId = (int)$_GET['mark_as_read'];
    $unsafeRedirectUrl = $_GET['redirect_url'] ?? '../includes/notifications.php';
    $redirectUrl = is_safe_redirect($unsafeRedirectUrl) ? $unsafeRedirectUrl : '../includes/notifications.php'; // Keep validation but the logic below is more important

    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $notificationId, $userId);
    $stmt->execute();
    $stmt->close();
    header("Location: " . $redirectUrl);
    exit();
}

// Fetch all notifications for the user
$sql = "SELECT id, type, message, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$notifications = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

function getIconForType($type) {
    switch ($type) {
        case 'low_stock':
            return ['icon' => 'fa-exclamation-triangle', 'color' => 'text-yellow-500'];
        case 'restock':
            return ['icon' => 'fa-check-circle', 'color' => 'text-green-500'];
        default:
            return ['icon' => 'fa-info-circle', 'color' => 'text-blue-500'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DAN-LEN</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/notifications.css?v=<?php echo time(); ?>"> <!-- External stylesheet -->
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body>
    <div class="dashboard-layout">
        <div id="sidebar-overlay" class="sidebar-overlay"></div>
        <nav id="sidebarMenu" class="sidebar flex flex-col">
            <div class="sidebar-header">
                <a href="../index1.php"><i class="fas fa-store"></i><span>DAN-LEN</span></a>
            </div>
            <ul class="sidebar-nav flex flex-col h-full"> <!-- Ensure flex properties are on the UL -->
                <?php if ($_SESSION['role'] === 'admin'): ?>
                    <li><a href="../dashboard.php"><i class="fas fa-home"></i>Dashboard</a></li>
                    <li><a href="../includes/notifications.php" class="active"><i class="fas fa-bell"></i>Notifications</a></li>
                    <li><a href="../users.php"><i class="fa-solid fa-user"></i>Users</a></li>
                    <li><a href="../includes/products.php"><i class="fas fa-tags"></i>All Products</a></li>
                    <li><a href="../categories.php"><i class="fas fa-sitemap"></i>Categories</a></li>
                    <li><a href="../includes/transactions.php"><i class="fas fa-receipt"></i>Transactions</a></li>
                    <li><a href="../index1.php"><i class="fas fa-chart-line"></i>POS</a></li>
                <?php elseif ($_SESSION['role'] === 'cashier'): ?>
                    <li><a href="../includes/notifications.php" class="active"><i class="fas fa-bell"></i>Notifications</a></li>
                    <li><a href="../includes/transactions.php"><i class="fas fa-receipt"></i>Transactions</a></li>
                    <li class="mt-auto">
                        <a href="../index1.php" class="border-t border-indigo-500 pt-4"><i class="fas fa-arrow-left"></i>Back to POS</a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>

        <main class="main-content">
            <div class="main-header">
                <button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
                <h1 class="text-2xl font-bold text-gray-800">All Notifications</h1>
            </div>

            <div class="bg-white p-6 rounded-lg shadow-md">
                <div class="space-y-4">
                    <?php if (empty($notifications)): ?>
                        <p class="text-center text-gray-500 py-8">You have no notifications.</p>
                    <?php else: ?>
                        <?php foreach ($notifications as $notif): ?>
                            <?php
                                $iconInfo = getIconForType($notif['type']);
                                $isUnread = !$notif['is_read'];
                                $itemClass = $isUnread ? 'notification-item unread' : 'notification-item';                                
                                $link = '#'; // Default link for read notifications or non-clickable items

                                if ($isUnread) {
                                    // Base action is to mark as read.
                                    $link = "../includes/notifications.php?mark_as_read=" . $notif['id'];
                                    // For admins, add a redirect if the link is valid.
                                    if ($_SESSION['role'] === 'admin' && !empty($notif['link'])) {
                                        $target_path = $notif['link'];
                                        // FIX: If it's an old link without 'includes/', prepend it.
                                        if (strpos($target_path, 'includes/') !== 0 && strpos($target_path, 'products.php') === 0) {
                                            $target_path = 'includes/' . $target_path;
                                        }
                                        $redirect_target = '../' . $target_path;
                                        if (is_safe_redirect($redirect_target)) {
                                            $link .= "&redirect_url=" . urlencode($redirect_target);
                                        }
                                    }
                                } else {
                                    // If already read, only admins can click to navigate.
                                    if ($_SESSION['role'] === 'admin' && !empty($notif['link'])) {
                                        $target_path = $notif['link'];
                                        // FIX: If it's an old link without 'includes/', prepend it.
                                        if (strpos($target_path, 'includes/') !== 0 && strpos($target_path, 'products.php') === 0) {
                                            $target_path = 'includes/' . $target_path;
                                        }
                                        $direct_link = '../' . $target_path;
                                        if (is_safe_redirect($direct_link)) {
                                            $link = $direct_link;
                                        }
                                    }
                                }
                            ?>
                            <a href="<?= htmlspecialchars($link) ?>" class="<?= $itemClass ?> block p-4 border-l-4 border-gray-200 rounded-r-lg hover:bg-gray-100 transition-colors duration-200">
                                <div class="flex items-start gap-4">
                                    <i class="fas <?= $iconInfo['icon'] ?> <?= $iconInfo['color'] ?> text-xl mt-1"></i>
                                    <div class="flex-grow">
                                        <p class="font-semibold text-gray-800"><?= htmlspecialchars($notif['message']) ?></p>
                                        <p class="text-sm text-gray-500 mt-1">
                                            <?= date('F j, Y, g:i a', strtotime($notif['created_at'])) ?>
                                        </p>
                                    </div>
                                    <?php if ($isUnread): ?>
                                        <div class="flex-shrink-0">
                                            <span class="w-3 h-3 bg-blue-500 rounded-full inline-block" title="Unread"></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script src="../js/notifications.js"></script>
</body>
</html>