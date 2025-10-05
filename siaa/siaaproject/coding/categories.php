<?php
require_once 'auth_check.php';
require_once 'db_connect.php';

if ($_SESSION['role'] !== 'admin') {
    die("Access Denied: You do not have permission to access this page.");
}

$message = '';
$categoryToEdit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';

    if (empty($name)) {
        $message = "Category name is required.";
    } else {
        // --- NEW: Check for unique category name ---
        $id_to_exclude = ($_POST['action'] === 'update_category') ? (int)$_POST['id'] : 0;
        $stmt_check = $conn->prepare("SELECT id FROM categories WHERE name = ? AND id != ?");
        $stmt_check->bind_param("si", $name, $id_to_exclude);
        $stmt_check->execute();
        $stmt_check->store_result();
        if ($stmt_check->num_rows > 0) {
            $message = "Error: Category '" . htmlspecialchars($name) . "' already exists.";
            header("Location: categories.php?message=" . urlencode($message));
            exit();
        }
        $stmt_check->close();

        if ($_POST['action'] === 'add_category') {
            $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
            $stmt->bind_param("s", $name);
        } elseif ($_POST['action'] === 'update_category') {
            $id = $_POST['id'];
            $stmt = $conn->prepare("UPDATE categories SET name=? WHERE id=?");
            $stmt->bind_param("si", $name, $id);
        }

        if (isset($stmt) && $stmt->execute()) {
            $message = "Category " . ($_POST['action'] === 'add_category' ? 'added' : 'updated') . " successfully!";
        } else {
            $message = "Error: " . ($stmt->error ?? 'Could not perform action.');
        }
        $stmt->close();
    }
    header("Location: categories.php?message=" . urlencode($message));
    exit();
}

if (isset($_GET['delete_id'])) {
    $id = $_GET['delete_id'];

    // --- SAFER DELETE: Check if the category is in use ---
    $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM product_categories WHERE category_id = ?");
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $product_count = $check_stmt->get_result()->fetch_assoc()['count'];
    $check_stmt->close();

    if ($product_count > 0) {
        $message = "Error: Cannot delete category because it is assigned to " . $product_count . " product(s).";
    } else {
        // Proceed with deletion if not in use
        $stmt = $conn->prepare("DELETE FROM categories WHERE id=?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $message = "Category deleted successfully!";
        } else {
            $message = "Error deleting category: " . $stmt->error;
        }
        $stmt->close();
    }
    header("Location: categories.php?message=" . urlencode($message));
    exit();
}

if (isset($_GET['edit_id'])) {
    $id = $_GET['edit_id'];
    $stmt = $conn->prepare("SELECT * FROM categories WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $categoryToEdit = $result->fetch_assoc();
    }
    $stmt->close();
}

// --- MODIFIED QUERY: Fetch product count for each category ---
$sql = "SELECT c.id, c.name, COUNT(pc.product_id) as product_count
        FROM categories c
        LEFT JOIN product_categories pc ON c.id = pc.category_id
        GROUP BY c.id, c.name
        ORDER BY c.name ASC";
$result = $conn->query($sql);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">    <title>DAN-LEN</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
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
        .sidebar-nav li {
            margin-bottom: 0.75rem; /* Add vertical space between items */
        }
        .sidebar-nav a {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;            color: #ffffff; /* White */
            text-decoration: none;
            border-radius: 0.375rem;
            transition: background-color 0.2s, color 0.2s, transform 0.2s ease-in-out;
        }        .sidebar-nav a:hover { 
            background-color: #111827; /* Near black */ 
            color: white; 
            transform: scale(1.03); /* Add a subtle scale effect */
        }
        .sidebar-nav a.active { background-color: #111827; /* Near black */ color: white; }
        .sidebar-nav a i { width: 1.25rem; margin-right: 0.75rem; text-align: center; }
        .main-content {
            margin-left: 0;
            flex-grow: 1;
            padding: 1.5rem;
            transition: margin-left 0.3s ease-in-out;
        }
        .main-header { display: flex; align-items: center; margin-bottom: 1.5rem; }
        .sidebar-toggle { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #374151; margin-right: 1rem; }
        .stat-card { background-color: white; padding: 1.5rem; border-radius: 0.75rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06); }
        .sidebar-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 30; opacity: 0; visibility: hidden; transition: opacity 0.3s, visibility 0.3s; }
        .sidebar-overlay.show { opacity: 1; visibility: visible; }
        @media (min-width: 768px) {
            .sidebar { transform: translateX(0); }
            .main-content { margin-left: 256px; }
            .sidebar-toggle { display: none; }
            .sidebar-overlay { display: none; }
        }

        /* Page-specific styles */
        .btn-action { padding: 0.25rem 0.5rem; border-radius: 0.375rem; font-size: 0.875rem; font-weight: 500; transition: background-color 0.2s; text-decoration: none; display: inline-block; }
        .btn-edit { color: #2563eb; background-color: #dbeafe; }
        .btn-edit:hover { background-color: #bfdbfe; }
        .btn-delete { color: #dc2626; background-color: #fee2e2; }
        .btn-delete:hover { background-color: #fecaca; }
        .notification { position: fixed; bottom: 1rem; right: 1rem; background-color: rgba(0, 0, 0, 0.7); color: white; padding: 0.75rem 1.5rem; border-radius: 0.5rem; opacity: 0; transition: opacity 0.3s ease-in-out; z-index: 1000; }
        .notification.show { opacity: 1; }
        /* Table styles */
        table th, table td {
            padding: 0.75rem;
            text-align: left;
            vertical-align: middle;
        }
        table th {
            font-weight: 600;
            color: #4b5563; /* text-gray-600 */
        }
        /* NEW: Style for scrollable category table */
        .category-table-container {
            max-height: 550px; /* Approx height for 10 rows + header */
            overflow-y: auto;
            overflow-x: hidden; /* Prevent horizontal scroll on the container itself */
        }
        /* Custom Modal Styles */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.6); display: flex; justify-content: center; align-items: center; z-index: 2000; opacity: 0; visibility: hidden; transition: opacity 0.3s, visibility 0.3s; }
        .modal-overlay.show { opacity: 1; visibility: visible; }
        .modal-content { background-color: #fff; padding: 2rem; border-radius: 0.75rem; text-align: center; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); max-width: 400px; }
        .modal-buttons { display: flex; justify-content: center; gap: 1rem; margin-top: 1.5rem; }
        .btn-modal-delete { background-color: #dc2626; color: white; padding: 0.5rem 1.5rem; border-radius: 0.375rem; font-weight: 500; transition: background-color 0.2s; }
        .btn-modal-delete:hover { background-color: #b91c1c; }
        .btn-modal-cancel { background-color: #e5e7eb; color: #374151; padding: 0.5rem 1.5rem; border-radius: 0.375rem; font-weight: 500; transition: background-color 0.2s; }
        .btn-modal-cancel:hover { background-color: #d1d5db; }
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
                <li><a href="dashboard.php"><i class="fas fa-home"></i>Dashboard</a></li>
                <li><a href="includes/notifications.php"><i class="fas fa-bell"></i>Notifications</a></li>
                <li><a href="users.php"><i class="fa-solid fa-user"></i>Users</a></li>
                <li><a href="includes/products.php"><i class="fas fa-tags"></i>All Products</a></li>
                <li><a href="categories.php" class="active"><i class="fas fa-sitemap"></i>Categories</a></li>
                <li><a href="includes/transactions.php"><i class="fas fa-receipt"></i>Transactions</a></li>
                <li><a href="index1.php"><i class="fas fa-chart-line"></i>POS</a></li>
            </ul>
        </nav>

        <main class="main-content">
            <div class="notification" id="notification"></div>

            <!-- Custom Confirmation Modal -->
            <div class="modal-overlay" id="confirm-modal">
                <div class="modal-content">
                    <p id="confirm-text" class="text-lg font-medium mb-6"></p>
                    <div class="modal-buttons">
                        <button type="button" class="btn-modal-delete" id="modal-confirm-btn">Delete</button>
                        <button type="button" class="btn-modal-cancel" id="modal-cancel-btn">Cancel</button>
                    </div>
                </div>
            </div>

            <div class="main-header">
                <button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
                <h1 class="text-2xl font-bold text-gray-800">Categories Management</h1>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mt-6 items-start">
                <div class="stat-card md:col-span-1">
                    <h2 class="text-xl font-semibold text-gray-700 mb-4"><?= $categoryToEdit ? 'Edit Category' : 'Add New Category' ?></h2>
                    <form action="categories.php" method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="<?= $categoryToEdit ? 'update_category' : 'add_category' ?>">
                        <?php if ($categoryToEdit): ?>
                            <input type="hidden" name="id" value="<?= $categoryToEdit['id'] ?>">
                        <?php endif; ?>
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-600">Category Name</label>
                            <input type="text" id="name" name="name" value="<?= $categoryToEdit ? htmlspecialchars($categoryToEdit['name']) : '' ?>" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                        </div>
                        <div class="flex items-center gap-4 pt-2">
                            <button type="submit" class="w-full py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                <?= $categoryToEdit ? 'Update Category' : 'Add Category' ?>
                            </button>
                            <?php if ($categoryToEdit): ?>
                                <a href="categories.php" class="w-full text-center py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <div class="stat-card md:col-span-2">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-semibold text-gray-700">All Categories</h2>
                        <!-- Search Bar -->
                        <div class="relative w-full max-w-xs">
                            <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                <i class="fas fa-search text-gray-400"></i>
                            </div>
                            <input type="text" id="category-search" placeholder="Search categories..." class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                        </div>
                    </div>
                    <div class="category-table-container overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead>
                                <tr>
                                    <th>ID</th><th>Name</th><th>Products</th><th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="category-table-body" class="bg-white divide-y divide-gray-200">
                                <?php if ($result->num_rows > 0): ?>
                                    <?php while($row = $result->fetch_assoc()): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($row['id']) ?></td>
                                            <td class="font-medium text-gray-900"><?= htmlspecialchars($row['name']) ?></td>
                                            <td><span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800"><?= htmlspecialchars($row['product_count']) ?></span></td>
                                            <td class="actions">
                                                <a href="categories.php?edit_id=<?= $row['id'] ?>" class="btn-action btn-edit">Edit</a>
                                                <a href="#" class="btn-action btn-delete delete-category" data-id="<?= $row['id'] ?>" data-name="<?= htmlspecialchars($row['name']) ?>">Delete</a>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="text-center py-4">No categories found.</td></tr>
                                <?php endif; ?>
                                <?php $conn->close(); ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
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

            const urlParams = new URLSearchParams(window.location.search);
            const message = urlParams.get('message');
            const notification = document.getElementById('notification');
            if (message) {
                notification.textContent = decodeURIComponent(message);
                notification.classList.add('show');
                setTimeout(() => {
                    notification.classList.remove('show');
                    window.history.replaceState({}, document.title, "categories.php");
                }, 3000);
            }

            // --- Custom Confirmation Modal Logic ---
            const confirmModal = document.getElementById('confirm-modal');
            const confirmText = document.getElementById('confirm-text');
            const confirmBtn = document.getElementById('modal-confirm-btn');
            const cancelBtn = document.getElementById('modal-cancel-btn');
            let categoryIdToDelete = null;

            document.querySelectorAll('.delete-category').forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    categoryIdToDelete = this.getAttribute('data-id');
                    const categoryName = this.getAttribute('data-name');
                    const productCount = this.closest('tr').querySelector('td:nth-child(3)').textContent;

                    if (parseInt(productCount, 10) > 0) {
                        alert(`Error: Cannot delete "${categoryName}" because it is assigned to ${productCount} product(s).`);
                        return;
                    }

                    confirmText.innerHTML = `Are you sure you want to delete the category <strong class="text-red-600">"${categoryName}"</strong>? This action cannot be undone.`;
                    confirmModal.classList.add('show');
                });
            });

            function closeConfirmModal() {
                confirmModal.classList.remove('show');
                categoryIdToDelete = null;
            }

            confirmBtn.addEventListener('click', function() {
                if (categoryIdToDelete) {
                    window.location.href = `categories.php?delete_id=${categoryIdToDelete}`;
                }
            });

            cancelBtn.addEventListener('click', closeConfirmModal);
            confirmModal.addEventListener('click', function(e) {
                if (e.target === confirmModal) closeConfirmModal();
            });

            // --- NEW: Search/Filter Logic ---
            const searchInput = document.getElementById('category-search');
            const tableBody = document.getElementById('category-table-body');
            const tableRows = tableBody.getElementsByTagName('tr');

            searchInput.addEventListener('keyup', function() {
                const searchTerm = searchInput.value.toLowerCase();
                let found = false;
                for (let i = 0; i < tableRows.length; i++) {
                    const nameCell = tableRows[i].getElementsByTagName('td')[1];
                    if (nameCell) {
                        const nameText = nameCell.textContent || nameCell.innerText;
                        if (nameText.toLowerCase().indexOf(searchTerm) > -1) {
                            tableRows[i].style.display = "";
                        } else {
                            tableRows[i].style.display = "none";
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>