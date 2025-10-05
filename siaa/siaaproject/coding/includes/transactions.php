<?php
require_once '../auth_check.php';

require_once '../db_connect.php';

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

// Fetch transactions. Admins see all, cashiers see their own.
if ($userRole === 'admin') {
    $sql = "SELECT s.id, s.grand_total, s.sale_date, IFNULL(CONCAT(u.first_name, ' ', u.last_name), 'Deleted User') AS cashier_name
        FROM sales s
        LEFT JOIN users u ON s.user_id = u.id
        ORDER BY s.sale_date DESC
        LIMIT 200"; // Increased limit
    $stmt = $conn->prepare($sql);
} else {
    $sql = "SELECT s.id, s.grand_total, s.sale_date, IFNULL(CONCAT(u.first_name, ' ', u.last_name), 'Deleted User') AS cashier_name
        FROM sales s
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.user_id = ?
        ORDER BY s.sale_date DESC
        LIMIT 200";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $userId);
}

$stmt->execute();
$result = $stmt->get_result();
$transactions = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transactions - DAN-LEN</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/transactions.css?v=<?php echo time(); ?>">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body>
    <div class="dashboard-layout">
        <div id="sidebar-overlay" class="sidebar-overlay"></div>
        <nav id="sidebarMenu" class="sidebar flex flex-col">
            <div class="sidebar-header">
                <a href="../index1.php"><i class="fas fa-store"></i><span>DAN-LEN</span></a>
            </div>
            <ul class="sidebar-nav flex flex-col h-full">
                <?php if ($_SESSION['role'] === 'admin'): ?>
                    <li><a href="../dashboard.php"><i class="fas fa-home"></i>Dashboard</a></li>
                    <li><a href="../includes/notifications.php"><i class="fas fa-bell"></i>Notifications</a></li>
                    <li><a href="../users.php"><i class="fa-solid fa-user"></i>Users</a></li>
                    <li><a href="../includes/products.php"><i class="fas fa-tags"></i>All Products</a></li>
                    <li><a href="../categories.php"><i class="fas fa-sitemap"></i>Categories</a></li>
                    <li><a href="../includes/transactions.php" class="active"><i class="fas fa-receipt"></i>Transactions</a></li>
                    <li><a href="../index1.php"><i class="fas fa-chart-line"></i>POS</a></li>
                <?php elseif ($_SESSION['role'] === 'cashier'): ?>
                    <li><a href="../includes/notifications.php"><i class="fas fa-bell"></i>Notifications</a></li>
                    <li><a href="../includes/transactions.php" class="active"><i class="fas fa-receipt"></i>Transactions</a></li>
                    <li class="mt-auto">
                        <a href="../index1.php" class="border-t border-indigo-500 pt-4"><i class="fas fa-arrow-left"></i>Back to POS</a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>

        <main class="main-content">
            <div class="main-header">
                <button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
                <h1 class="text-2xl font-bold text-gray-800">Transaction History</h1>
            </div>

            <div class="bg-white p-4 sm:p-6 rounded-lg shadow-md">
                <!-- Search Bar -->
                <div class="relative mb-4">
                    <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                        <i class="fas fa-search text-gray-400"></i>
                    </div>
                    <input type="text" id="transaction-search" placeholder="Search by Transaction ID or Cashier Name..." class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-full shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                </div>

                <!-- Transaction List -->
                <div class="space-y-3">
                    <?php if (empty($transactions)): ?>
                        <p class="text-center text-gray-500 py-8">You have no transactions.</p>
                    <?php else: ?>
                        <?php foreach ($transactions as $trans): ?>
                            <div class="transaction-item" data-transaction-id="<?= htmlspecialchars($trans['id']) ?>">
                                <!-- MODIFIED: Added flex-col and sm:flex-row for responsiveness -->
                                <div class="transaction-header flex-col sm:flex-row">
                                    <!-- MODIFIED: Added sm:text-left for alignment -->
                                    <div class="w-full sm:w-auto text-center sm:text-left">
                                        <p class="font-semibold text-gray-800" data-search-id="Transaction #<?= htmlspecialchars($trans['id']) ?>">
                                            Transaction #<?= htmlspecialchars($trans['id']) ?>
                                            <?php if ($userRole === 'admin'): ?>
                                                <span class="block sm:inline text-sm font-normal text-gray-500 sm:ml-2">(Cashier: <?= htmlspecialchars($trans['cashier_name']) ?>)</span>
                                            <?php endif; ?>
                                        </p>
                                        <p class="text-sm text-gray-500 mt-1">
                                            <i class="fas fa-calendar-alt fa-fw mr-1"></i>
                                            <?= date('F j, Y, g:i a', strtotime($trans['sale_date'])) ?>
                                        </p>
                                    </div>
                                    <!-- MODIFIED: Added classes for responsive alignment and spacing -->
                                    <div class="w-full sm:w-auto text-center sm:text-right mt-2 sm:mt-0">
                                        <p class="text-lg font-bold text-indigo-600">P<?= number_format($trans['grand_total'], 2) ?></p>
                                        <i class="fas fa-chevron-down text-gray-400 transition-transform"></i>
                                    </div>
                                </div>
                                <div class="transaction-details">
                                            <!-- Details will be loaded here by JavaScript -->
                                </div>
                            </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                </div>
                    <div id="no-results-message" class="text-center text-gray-500 py-8 hidden">
                        No transactions found matching your search.
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="../js/transactions.js?v=<?php echo time(); ?>"></script>
</body>
</html>