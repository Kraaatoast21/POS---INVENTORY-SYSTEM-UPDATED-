<?php
require_once 'auth_check.php'; // Check if user is logged in
require_once 'db_connect.php';

// Role-based access control: only admins can manage users
if ($_SESSION['role'] !== 'admin') {
    die("Access Denied: You do not have permission to access this page.");
}

$message = '';

// --- AJAX REQUEST HANDLING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_user') {
    header('Content-Type: application/json');
    $userId = $_POST['id'];
    $firstName = $_POST['first_name'];
    $lastName = $_POST['last_name'];
    $username = $_POST['username'];
    $email = $_POST['email'];
    $password = $_POST['password'];
    $role = $_POST['role'];

    // Prevent editing self or primary admin
    if ($userId == $_SESSION['user_id']) {
        echo json_encode(['success' => false, 'message' => 'You cannot edit this user.']);
        exit();
    }

    $sql_parts = ["first_name = ?", "last_name = ?", "username = ?", "email = ?", "role = ?"];
    $types = "sssss";
    $params = [$firstName, $lastName, $username, $email, $role];

    // Prevent changing the role of the primary admin (ID 1)
    if ($userId == 1) {
        $sql_parts = ["first_name = ?", "last_name = ?", "username = ?", "email = ?"];
        $types = "ssss";
        $params = [$firstName, $lastName, $username, $email];
    }
    if (!empty($password)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $sql_parts[] = "password = ?";
        $types .= "s";
        $params[] = $hashed_password;
    }

    $sql = "UPDATE users SET " . implode(", ", $sql_parts) . " WHERE id = ?";
    $types .= "i";
    $params[] = $userId;

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'User updated successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error updating user: ' . $stmt->error]);
    }
    $stmt->close();
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_role') {
    header('Content-Type: application/json');
    $userId = $_POST['id'];
    $currentRole = $_POST['role'];

    // Prevent changing self or primary admin
    if ($userId == $_SESSION['user_id'] || $userId == 1) {
        echo json_encode(['success' => false, 'message' => 'You cannot change the role for this user.']);
        exit();
    }

    // Cycle through roles: Admin -> Cashier -> User -> Admin
    if ($currentRole === 'admin') {
        $newRole = 'cashier';
    } elseif ($currentRole === 'cashier') {
        $newRole = 'user';
    } else { // Assumes the current role is 'user' or something else
        $newRole = 'admin';
    }


    $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
    $stmt->bind_param("si", $newRole, $userId);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'newRole' => $newRole, 'message' => 'User role updated successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error updating user role: ' . $stmt->error]);
    }
    $stmt->close();
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'get_user' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id = $_GET['id'];
    $stmt = $conn->prepare("SELECT id, first_name, last_name, username, email, role FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($user = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'user' => $user]);
    } else {
        echo json_encode(['success' => false, 'message' => 'User not found.']);
    }
    $stmt->close();
    exit();
}

// --- GET REQUEST HANDLING (DELETE) ---
if (isset($_GET['delete_id'])) {
    $id = $_GET['delete_id'];

    // Begin a transaction to ensure both operations succeed or fail together
    $conn->begin_transaction();
    try {
        // Step 1: Anonymize sales records by setting user_id to NULL
        $anonymize_stmt = $conn->prepare("UPDATE sales SET user_id = NULL WHERE user_id = ?");
        $anonymize_stmt->bind_param("i", $id);
        $anonymize_stmt->execute(); // This will set user_id to NULL for associated sales
        $anonymize_stmt->close();

        // Step 2: Now it's safe to delete the user
        $delete_stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $delete_stmt->bind_param("i", $id);
        $delete_stmt->execute(); // This deletes the user record
        $delete_stmt->close();

        // If both queries were successful, commit the transaction
        $conn->commit();
        $message = "User deleted successfully! Associated sales records have been anonymized.";
    } catch (Exception $e) {
        // If anything went wrong, roll back the changes
        $conn->rollback();
        $message = "Error: Could not delete the user. " . $e->getMessage();
    }

    header("Location: users.php?message=" . urlencode($message));
    exit();
}

// --- DATA FETCHING FOR DISPLAY ---
$sql = "SELECT id, first_name, last_name, username, email, role, created_at, image_url FROM users ORDER BY id ASC";
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
        .btn-action {
            padding: 0.4rem 0.5rem; /* Increased vertical padding for better spacing */
            border-radius: 0.375rem;
            font-size: 0.875rem;
            font-weight: 500;
            transition: background-color 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-edit {
            color: #2563eb; /* blue-600 */
            background-color: #dbeafe; /* blue-100 */
        }
        .btn-edit:hover {
            background-color: #111827; color: white;
        }
        .btn-make-admin {
            color: #15803d; /* green-700 */
            background-color: #dcfce7; /* green-100 */
        }
        .btn-make-admin:hover {
            background-color: #111827; color: white;
        }
        .btn-make-cashier {
            color: #c2410c; /* orange-700 */
            background-color: #ffedd5; /* orange-100 */
        }
        .btn-make-cashier:hover { background-color: #111827; color: white; }
        .btn-delete {
            color: #dc2626;
            background-color: #fee2e2;
        }
        .btn-delete:hover {
            background-color: #111827; color: white;
        }
        .notification {
            position: fixed; top: 1.5rem; left: 50%;
            background-color: #16a34a; /* Default success color */
            color: white;
            padding: 1.25rem 2.5rem;
            border-radius: 9999px;
            font-size: 1.25rem;
            font-weight: 600;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            opacity: 0;
            transition: opacity 0.3s ease-in-out, transform 0.3s ease-in-out;
            transform: translateX(-50%) translateY(-50px);
            z-index: 1000;
        }
        .notification.show {
            opacity: 1; transform: translateX(-50%) translateY(0);
        }
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex; justify-content: center; align-items: center;
            z-index: 2000; opacity: 0; visibility: hidden;
            transition: opacity 0.3s, visibility 0.3s;
        }
        .modal-overlay.show { opacity: 1; visibility: visible; }
        .modal-content {
            background-color: #fff; padding: 2rem; border-radius: 0.75rem;
            text-align: center; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            max-width: 400px;
        }
        .modal-buttons { display: flex; justify-content: center; gap: 1rem; }

        /* New Actions Dropdown Styles */
        .actions-menu-container {
            position: relative;
        }
        /* NEW: Wrapper to correctly position the dropdown relative to the button */
        .actions-wrapper {
            position: relative;
        }
        .actions-avatar-btn {
            width: 2.25rem; /* w-9 */
            height: 2.25rem; /* h-9 */
            border-radius: 9999px; /* rounded-full */
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #e5e7eb; /* bg-gray-200 */
            color: #4b5563; /* text-gray-600 */
            transition: background-color 0.2s, box-shadow 0.2s;
        }
        .actions-avatar-btn:hover {
            background-color: #d1d5db; /* bg-gray-300 */
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.3); /* ring-indigo-300/30 */
        }
        .actions-dropdown {
            position: absolute;
            right: 0;
            top: 100%;
            margin-top: 0.5rem;
            background-color: white;
            border-radius: 0.5rem;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
            z-index: 10;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: opacity 0.2s ease, transform 0.2s ease;
            min-width: 160px;
            padding: 0.5rem;
            padding: 0.25rem;
        }
        .actions-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0); }
        .actions-dropdown-divider { height: 1px; background-color: #e5e7eb; margin: 0.25rem 0; }
        /* Table styles */
        table th, table td {
            padding: 1rem 0.75rem; /* Increased vertical padding */
            text-align: left;
            vertical-align: middle; 
        }
        table th {
            font-weight: 600;
            color: #4b5563; /* text-gray-600 */
            font-size: 0.75rem; /* Smaller header text */
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        /* NEW: Role Badge Styles */
        .role-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-weight: 500;
            font-size: 0.75rem;
        }
        .role-admin { background-color: #ede9fe; color: #5b21b6; } /* violet-100, violet-700 */
        .role-cashier { background-color: #f3f4f6; color: #4b5563; } /* gray-100, gray-600 */

        /* --- NEW: Responsive Styles for Mobile --- */
        @media (max-width: 767px) {
            /* Hide the table header on mobile */
            .users-table thead {
                display: none;
            }
            /* Make each row a card */
            .users-table tbody, .users-table tr {
                display: block;
                width: 100%;
            }
            .users-table tr {
                background-color: white;
                border-radius: 0.5rem;
                box-shadow: 0 2px 4px rgba(0,0,0,0.05);
                margin-bottom: 1rem;
                padding: 1rem;
            }
            /* Make each cell a block element with a label */
            .users-table td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 0.5rem 0;
                border-bottom: 1px solid #f3f4f6;
            }
            .users-table td:last-child {
                border-bottom: none;
                padding-top: 1rem;
                justify-content: flex-end; /* Align actions to the right */
            }
            .users-table td::before {
                content: attr(data-label);
                font-weight: 600;
                color: #4b5563;
            }
            /* Ensure the actions dropdown doesn't get cut off */
            .users-table td.actions-menu-container {
                overflow: visible;
            }
            /* Override Tailwind's min-width on mobile */
            .users-table {
                min-width: 0;
                width: 100%;
            }
        }
        /* Add horizontal scroll only on medium screens if needed */
        @media (min-width: 768px) and (max-width: 1023px) {
            .table-wrapper {
                overflow-x: auto;
            }
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
                <li><a href="dashboard.php"><i class="fas fa-home"></i>Dashboard</a></li>
                <li><a href="includes/notifications.php"><i class="fas fa-bell"></i>Notifications</a></li>
                <li><a href="users.php" class="active"><i class="fa-solid fa-user"></i>Users</a></li>
                <li><a href="includes/products.php"><i class="fas fa-tags"></i>All Products</a></li>
                <li><a href="categories.php"><i class="fas fa-sitemap"></i>Categories</a></li>
                <li><a href="includes/transactions.php"><i class="fas fa-receipt"></i>Transactions</a></li>
                <li><a href="index1.php"><i class="fas fa-chart-line"></i>POS</a></li>
            </ul>
        </nav>

        <main class="main-content">
            <div class="notification" id="notification"></div>
            <div class="modal-overlay" id="confirm-modal">
                 <div class="modal-content">
                     <p id="confirm-text" class="text-lg font-medium mb-6"></p>
                     <div class="modal-buttons">
                         <button class="btn-action btn-delete" id="modal-confirm-btn">Delete</button>
                         <button class="btn-action bg-gray-200 hover:bg-gray-300" id="modal-cancel-btn">Cancel</button>
                     </div>
                 </div>
            </div>

            <!-- Edit User Modal -->
            <div class="modal-overlay" id="edit-modal">
                <div class="modal-content !max-w-lg !text-left">
                    <h3 class="text-xl font-bold mb-4" id="edit-modal-title">Edit User</h3>
                    <form id="edit-user-form" class="space-y-4">
                        <input type="hidden" id="edit-user-id" name="id">
                        <input type="hidden" name="action" value="update_user">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="edit-first-name" class="block text-sm font-medium text-gray-700">First Name</label>
                                <input type="text" id="edit-first-name" name="first_name" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" autocomplete="given-name">
                            </div>
                            <div>
                                <label for="edit-last-name" class="block text-sm font-medium text-gray-700">Last Name</label>
                                <input type="text" id="edit-last-name" name="last_name" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" autocomplete="family-name">
                            </div>
                        </div>
                        <div>
                            <label for="edit-username" class="block text-sm font-medium text-gray-700">Username</label>
                            <input type="text" id="edit-username" name="username" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" autocomplete="username">
                        </div>
                        <div>
                            <label for="edit-email" class="block text-sm font-medium text-gray-700">Email</label>
                            <input type="email" id="edit-email" name="email" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" autocomplete="email">
                        </div>
                        <div>
                            <label for="edit-password" class="block text-sm font-medium text-gray-700">New Password (optional)</label>
                            <input type="password" id="edit-password" name="password" maxlength="32" placeholder="Leave blank to keep current" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" autocomplete="new-password">
                        </div>
                        <div>
                            <label for="edit-role" class="block text-sm font-medium text-gray-700">Role</label>
                            <select id="edit-role" name="role" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                                <option value="cashier">Cashier</option>
                                <option value="user">User</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div class="modal-buttons pt-4 !justify-end">
                            <button type="button" class="btn-action bg-gray-200 hover:bg-gray-300" id="edit-modal-cancel-btn">Cancel</button>
                            <button type="submit" class="btn-action !bg-indigo-600 !text-white hover:!bg-gray-900" id="edit-modal-save-btn">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="main-header">
                <button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
                <h1 class="text-2xl font-bold text-gray-800">Users Management</h1>
            </div>

            <div class="stat-card mt-6" id="users-table-container">
                <div class="flex flex-col sm:flex-row justify-between items-center mb-4 gap-4">
                    <h2 class="text-xl font-semibold text-gray-700">All Users</h2>
                    <!-- Search bar is now above the table -->
                </div>
                <div class="relative w-full mb-4">
                    <input type="text" id="user-search-input" placeholder="Search by name, email, or username..." class="w-full pl-10 pr-4 py-2 border rounded-full focus:outline-none focus:ring-2 focus:ring-indigo-300">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fas fa-search text-gray-400"></i>
                    </div>
                </div>
                <div class="table-wrapper">
                    <table class="min-w-full users-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th class="w-2/5">User</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="user-table-body" class="divide-y divide-gray-200">
                            <?php if ($result->num_rows > 0): ?>
                                <?php while($row = $result->fetch_assoc()): ?>
                                    <?php
                                        // Determine the correct image URL for the user
                                        $user_image_url = 'https://ui-avatars.com/api/?name=' . urlencode($row['first_name'] . ' ' . $row['last_name']) . '&background=e0e7ff&color=4338ca&bold=true';
                                        if (!empty($row['image_url']) && file_exists($row['image_url'])) {
                                            // Use the uploaded image with a cache-busting timestamp
                                            $user_image_url = htmlspecialchars($row['image_url']) . '?t=' . time();
                                        }
                                    ?>
                                    <tr id="user-row-<?= $row['id'] ?>">
                                        <td data-label="ID" class="text-sm text-gray-500"><?= htmlspecialchars($row['id']) ?></td>
                                        <td data-label="User" data-field="user">
                                            <div class="flex items-center gap-3">
                                                <img src="<?= $user_image_url ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover">
                                                <div>
                                                    <div class="font-medium text-gray-900" data-field="name"><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></div>
                                                    <a href="mailto:<?= htmlspecialchars($row['email']) ?>" class="text-sm text-gray-500 hover:text-indigo-600" data-field="email"><?= htmlspecialchars($row['email']) ?></a>
                                                </div>
                                            </div>
                                        </td>
                                        <td data-label="Username" data-field="username" class="text-gray-700"><?= htmlspecialchars($row['username']) ?></td>
                                        <td data-label="Role" data-field="role">
                                            <span class="role-badge role-<?= htmlspecialchars($row['role']) ?>"><?= htmlspecialchars(ucfirst($row['role'])) ?></span>
                                        </td>
                                        <td class="actions-menu-container">
                                            <?php if ($row['id'] != 1): // Prevent deleting admin ID 1 ?>
                                                <?php if ($row['id'] == $_SESSION['user_id']): ?>
                                                    <span class="text-xs text-gray-400">This is you</span>
                                                <?php else: ?>
                                                    <!-- NEW: Wrapper div for correct positioning -->
                                                    <div class="actions-wrapper inline-block">
                                                        <button class="actions-avatar-btn" data-dropdown-toggle="actions-dropdown-<?= $row['id'] ?>" title="Actions">
                                                            <i class="fas fa-ellipsis-h"></i>
                                                        </button>
                                                        <div id="actions-dropdown-<?= $row['id'] ?>" class="actions-dropdown">
                                                            <a href="#" class="btn-action edit-user w-full !flex !items-center !gap-2 text-left !bg-transparent !text-gray-700 hover:!bg-gray-100" data-id="<?= $row['id'] ?>">
                                                                <i class="fas fa-pencil-alt w-6 text-center"></i> Edit User
                                                        </a>
                                                        <a href="#" class="btn-action change-role w-full !flex !items-center !gap-2 text-left !bg-transparent !text-gray-700 hover:!bg-gray-100" data-id="<?= $row['id'] ?>" data-role="<?= $row['role'] ?>">
                                                            <i class="fas fa-user-shield w-6 text-center"></i> 
                                                            <span>
                                                                <?php
                                                                    // Set the correct next action text for the button
                                                                    if ($row['role'] === 'admin') { echo 'Make Cashier'; }
                                                                    elseif ($row['role'] === 'cashier') { echo 'Make User'; }
                                                                    else { echo 'Make Admin'; }
                                                                ?>
                                                            </span>
                                                        </a>
                                                        <div class="actions-dropdown-divider"></div>
                                                        <a href="#" class="btn-action delete-user w-full !flex !items-center !gap-2 text-left !bg-transparent !text-red-600 hover:!bg-red-50" data-id="<?= htmlspecialchars($row['id']) ?>" data-name="<?= htmlspecialchars($row['username']) ?>">
                                                            <i class="fas fa-trash-alt w-6 text-center"></i> Delete User
                                                        </a>
                                                    </div>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-xs text-gray-400">Primary Admin</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-center py-4">No users found.</td></tr>
                            <?php endif; ?>
                            <?php $conn->close(); ?>
                        </tbody>
                    </table>
                    <div id="no-results-message" class="text-center py-8 text-gray-500 hidden">
                        No users found matching your search.
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
                    window.history.replaceState({}, document.title, "users.php");
                }, 3000);
            }

            const deleteButtons = document.querySelectorAll('.delete-user');
            const confirmModal = document.getElementById('confirm-modal');
            const confirmText = document.getElementById('confirm-text');
            const confirmBtn = document.getElementById('modal-confirm-btn');
            const cancelBtn = document.getElementById('modal-cancel-btn');
            const editModal = document.getElementById('edit-modal');
            const editForm = document.getElementById('edit-user-form');
            const editCancelBtn = document.getElementById('edit-modal-cancel-btn');
            let userIdToDelete = null;

            function showNotification(text, isError = false) {
                const notification = document.getElementById('notification');
                if (!notification) return;

                notification.textContent = text;
                notification.style.backgroundColor = isError ? '#dc2626' : '#16a34a'; // Red for error, Green for success
                notification.classList.add('show');

                setTimeout(() => {
                    notification.classList.remove('show');
                }, 3000);
            }


            deleteButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    userIdToDelete = this.getAttribute('data-id');
                    const userName = this.getAttribute('data-name');
                    confirmText.textContent = `Are you sure you want to delete user "${userName}"?`;
                    confirmModal.classList.add('show');
                });
            });

            confirmBtn.addEventListener('click', function(e) {
                if (userIdToDelete) {
                    window.location.href = `users.php?delete_id=${userIdToDelete}`;
                }
            });

            cancelBtn.addEventListener('click', function() {
                confirmModal.classList.remove('show');
                userIdToDelete = null;
            });

            // --- Edit User Modal Logic ---
            document.querySelectorAll('.edit-user').forEach(button => {
                button.addEventListener('click', async function(e) {
                    e.preventDefault();
                    const userId = this.getAttribute('data-id');

                    // Fetch user data via AJAX
                    const response = await fetch(`users.php?action=get_user&id=${userId}`);
                    const result = await response.json();

                    if (result.success) {
                        const user = result.user;
                        // Populate the form
                        document.getElementById('edit-user-id').value = user.id;
                        document.getElementById('edit-modal-title').textContent = `Edit User: ${user.username}`;
                        document.getElementById('edit-first-name').value = user.first_name;
                        document.getElementById('edit-last-name').value = user.last_name;
                        document.getElementById('edit-username').value = user.username;
                        document.getElementById('edit-email').value = user.email;
                        document.getElementById('edit-role').value = user.role;
                        document.getElementById('edit-password').value = ''; // Clear password field

                        // Disable role editing for the primary admin
                        const roleSelect = document.getElementById('edit-role');
                        if (user.id == 1) {
                            roleSelect.disabled = true;
                        } else {
                            roleSelect.disabled = false;
                        }
                        // Show the modal
                        editModal.classList.add('show');
                    } else {
                        showNotification(result.message || 'Could not fetch user data.', true);
                    }
                });
            });

            // Handle Edit Form Submission
            editForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                const formData = new FormData(editForm);
                const saveButton = document.getElementById('edit-modal-save-btn');
                saveButton.disabled = true;
                saveButton.textContent = 'Saving...';

                const response = await fetch('users.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    editModal.classList.remove('show');
                    showNotification(result.message);

                    // Update the table row without reloading the page
                    const userId = formData.get('id');
                    const row = document.getElementById(`user-row-${userId}`);
                    if (row) {
                        // Update user avatar and name/email block
                        const name = `${formData.get('first_name')} ${formData.get('last_name')}`;
                        const email = formData.get('email');
                        const userCell = row.querySelector('[data-field="user"]');
                        const img = userCell.querySelector('img');
                        // Only update to UI-Avatar if there's no custom image.
                        // This prevents overwriting a custom photo with a generated one.
                        if (img.src.includes('ui-avatars.com')) {
                            img.src = `https://ui-avatars.com/api/?name=${encodeURIComponent(name)}&background=e0e7ff&color=4338ca&bold=true`;
                        }
                        userCell.querySelector('[data-field="name"]').textContent = name;
                        userCell.querySelector('[data-field="email"]').textContent = email;
                        userCell.querySelector('[data-field="email"]').href = `mailto:${email}`;

                        // Update other fields
                        row.querySelector('[data-field="username"]').textContent = formData.get('username');
                        const role = formData.get('role');
                        const roleCell = row.querySelector('[data-field="role"]');
                        const roleBadge = roleCell.querySelector('.role-badge');
                        roleBadge.textContent = role.charAt(0).toUpperCase() + role.slice(1);
                        roleBadge.className = `role-badge role-${role}`;
                    }
                } else {
                    showNotification(result.message || 'An error occurred.', true);
                }
                saveButton.disabled = false;
                saveButton.textContent = 'Save Changes';
            });

            // Close edit modal
            editCancelBtn.addEventListener('click', () => editModal.classList.remove('show'));
            editModal.addEventListener('click', (e) => {
                if (e.target === editModal) {
                    editModal.classList.remove('show');
                }
            });

            // --- New Change Role Logic ---
            document.querySelectorAll('.change-role').forEach(button => {
                button.addEventListener('click', async function(e) {
                    e.preventDefault();
                    const userId = this.getAttribute('data-id');
                    const currentRole = this.getAttribute('data-role');

                    const formData = new FormData();
                    formData.append('action', 'change_role');
                    formData.append('id', userId);
                    formData.append('role', currentRole);

                    const response = await fetch('users.php', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();

                    if (result.success) {
                        showNotification(result.message);
                        // Update UI instantly
                        const row = document.getElementById(`user-row-${userId}`);
                        if (row) {
                            const roleCell = row.querySelector('[data-field="role"]');
                            const roleBadge = roleCell.querySelector('.role-badge');
                            const newRoleCapitalized = result.newRole.charAt(0).toUpperCase() + result.newRole.slice(1);

                            // Update button text based on the new role
                            let nextRoleText = '';
                            if (result.newRole === 'admin') {
                                nextRoleText = 'Make Cashier';
                            } else if (result.newRole === 'cashier') {
                                nextRoleText = 'Make User';
                            } else { // 'user'
                                nextRoleText = 'Make Admin';
                            }
                            roleBadge.textContent = newRoleCapitalized;
                            roleBadge.className = `role-badge role-${result.newRole}`;
                            this.setAttribute('data-role', result.newRole);
                            this.querySelector('span').textContent = nextRoleText;
                        }
                    } else {
                        showNotification(result.message || 'An error occurred.', true);
                    }
                });
            });

            // --- New Actions Dropdown Logic ---
            document.querySelectorAll('[data-dropdown-toggle]').forEach(button => {
                button.addEventListener('click', function (e) {
                    e.stopPropagation();
                    const dropdownId = this.getAttribute('data-dropdown-toggle');
                    const dropdown = document.getElementById(dropdownId);

                    // Close all other dropdowns
                    document.querySelectorAll('.actions-dropdown.show').forEach(openDropdown => {
                        if (openDropdown !== dropdown) {
                            openDropdown.classList.remove('show');
                        }
                    });
                    dropdown.classList.toggle('show');
                });
            });
            window.addEventListener('click', function() {
                document.querySelectorAll('.actions-dropdown.show').forEach(openDropdown => openDropdown.classList.remove('show'));
            });

            // --- New Search/Filter Logic ---
            const searchInput = document.getElementById('user-search-input');
            const tableBody = document.getElementById('user-table-body');
            const noResultsMessage = document.getElementById('no-results-message');

            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const rows = tableBody.querySelectorAll('tr');
                let visibleRowCount = 0;

                rows.forEach(row => {
                    const rowText = row.textContent.toLowerCase();
                    if (rowText.includes(searchTerm)) {
                        row.style.display = '';
                        visibleRowCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });

                if (visibleRowCount === 0) {
                    noResultsMessage.classList.remove('hidden');
                } else {
                    noResultsMessage.classList.add('hidden');
                }
            });
        });
    </script>
</body>
</html>
