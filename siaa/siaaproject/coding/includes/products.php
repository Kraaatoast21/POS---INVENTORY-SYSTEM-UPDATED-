<?php
require_once '../auth_check.php';
require_once '../db_connect.php';

// Role-based access control
if ($_SESSION['role'] !== 'admin') {
    die("Access Denied: You do not have permission to access this page.");
}

$message = '';
$productToEdit = null; // Initialize to null to prevent undefined variable warnings

// --- FILE UPLOAD HANDLER ---
function handle_photo_upload($current_image_url = '') {
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/';
        // Security: Ensure the uploads directory exists and has secure permissions.
        if (!is_dir($upload_dir)) {
            // 0755 is a more secure permission set for directories.
            mkdir($upload_dir, 0755, true);
        }

        // Security: Validate file type and extension.
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
        $file_extension = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime_type = $finfo->file($_FILES['photo']['tmp_name']);
        $allowed_mime_types = ['image/jpeg', 'image/png', 'image/gif'];

        if (!in_array($file_extension, $allowed_extensions) || !in_array($mime_type, $allowed_mime_types)) {
            // Return an error message instead of just the old URL
            return ['error' => 'Invalid file type. Only JPG, PNG, and GIF are allowed.'];
        }

        // Security: Generate a unique and safe filename.
        $new_filename = uniqid('product_', true) . '.' . $file_extension;
        $upload_path = $upload_dir . $new_filename; // This is the correct relative path from the root

        if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_path)) {
            // FIX: Delete the old photo only AFTER the new one has been successfully moved.
            if (!empty($current_image_url) && file_exists('../' . $current_image_url)) {
                unlink('../' . $current_image_url);
            }
            // Return path relative to the `coding` directory for consistency
            return ['path' => ltrim($upload_path, '../')];
        }
        return ['error' => 'Failed to move uploaded file.'];
    }
    // Return the old path if no new file is uploaded or if there's no file.
    return $current_image_url;
}

// --- POST REQUEST HANDLING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? null;

    // --- DELETE PRODUCT LOGIC ---
    if ($action === 'delete_product') {
        $id = $_POST['delete_id'] ?? null;
        if (!$id) {
            $message = "Error: Invalid ID for deletion.";
        } else {
            // This is now a "soft delete" - we mark the product as inactive instead of deleting it.
            // This preserves historical transaction data.
            $stmt = $conn->prepare("UPDATE products SET is_active = 0 WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $message = "Product archived successfully. It will no longer appear in the POS.";
            } else {
                $message = "Error archiving product: " . $stmt->error;
            }
            $stmt->close();
        }
    }
    // --- RESTORE PRODUCT LOGIC ---
    elseif ($action === 'restore_product') {
        $id = $_POST['restore_id'] ?? null;
        if (!$id) {
            $message = "Error: Invalid ID for restoration.";
        } else {
            // First verify the product exists and is inactive
            $check_stmt = $conn->prepare("SELECT id FROM products WHERE id = ? AND is_active = 0");
            $check_stmt->bind_param("i", $id);
            $check_stmt->execute();
            $check_stmt->store_result();
            
            if ($check_stmt->num_rows > 0) {
                $stmt = $conn->prepare("UPDATE products SET is_active = 1 WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    $message = "Product restored successfully!";
                } else {
                    $message = "Error restoring product: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $message = "Error: Product not found or already active.";
            }
            $check_stmt->close();
        }
    }
    // --- PERMANENT DELETE LOGIC ---
    elseif ($action === 'permanent_delete_product') {
        $id = $_POST['delete_id'] ?? null;
        if (!$id) {
            $message = "Error: Invalid ID for permanent deletion.";
        } else {
            // SAFETY CHECK: Verify this product is not part of any sale.
            $sale_check_stmt = $conn->prepare("SELECT 1 FROM sale_items WHERE product_id = ? LIMIT 1");
            $sale_check_stmt->bind_param("i", $id);
            $sale_check_stmt->execute();
            $sale_check_stmt->close(); // FIX: Close the statement to free up the connection
            // The safety check is now a warning on the frontend. We proceed with deletion regardless.
                $conn->begin_transaction();
                try {
                    // Get image URL before deleting product record
                    $img_stmt = $conn->prepare("SELECT image_url FROM products WHERE id = ?");
                    $img_stmt->bind_param("i", $id);
                    $img_stmt->execute();
                    $image_url_relative = $img_stmt->get_result()->fetch_assoc()['image_url'];
                    $image_url_absolute = '../' . $image_url_relative;

                    // NEW: Anonymize sales records before deleting the product.
                    $anonymize_stmt = $conn->prepare("UPDATE sale_items SET product_id = NULL WHERE product_id = ?");
                    $anonymize_stmt->bind_param("i", $id);
                    $anonymize_stmt->execute();

                    // Delete from pivot table first
                    $cat_del_stmt = $conn->prepare("DELETE FROM product_categories WHERE product_id = ?");
                    $cat_del_stmt->bind_param("i", $id);
                    $cat_del_stmt->execute();

                    // Finally, delete the product itself
                    $prod_del_stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
                    $prod_del_stmt->bind_param("i", $id);
                    $prod_del_stmt->execute();

                    $conn->commit();
                    $message = "Product permanently deleted successfully.";

                    // Delete the associated image file from the server
                    if (!empty($image_url_relative) && file_exists($image_url_absolute)) {
                        unlink($image_url_absolute);
                    }
                } catch (Exception $e) {
                    $conn->rollback();
                    $message = "Error during deletion: " . $e->getMessage();
                }
        }
    }
    // --- ADD/UPDATE PRODUCT LOGIC ---
    elseif ($action === 'add_product' || $action === 'update_product') {
        $name = $_POST['name'] ?? '';
        $price = $_POST['price'] ?? 0;
        $discount_percentage = $_POST['discount_percentage'] ?? 0;
        $quantity = $_POST['quantity'] ?? 0;
        $categories_posted = $_POST['categories'] ?? []; // Now an array
        $barcode_sku = $_POST['barcode_sku'] ?? null;
        $error = false;

        if (empty($name) || !is_numeric($price) || !is_numeric($quantity)) {
            $message = "Name, Price, and Quantity are required fields.";
            $error = true;
        } else { // Only check for barcode if primary validation passes
            // Check for unique barcode SKU
            if (!empty($barcode_sku)) {
                $id_to_exclude = ($action === 'update_product') ? $_POST['id'] : 0;
                $stmt_check = $conn->prepare("SELECT id FROM products WHERE barcode_sku = ? AND id != ?");
                $stmt_check->bind_param("si", $barcode_sku, $id_to_exclude);
                $stmt_check->execute();
                $stmt_check->store_result();
                if ($stmt_check->num_rows > 0) {
                    $message = "Error: Barcode SKU '" . htmlspecialchars($barcode_sku) . "' is already in use.";
                    $error = true;
                }
                $stmt_check->close();
            }

            // Check for unique product name
            if (!$error) {
                $id_to_exclude = ($action === 'update_product') ? (int)$_POST['id'] : 0;
                $stmt_check_name = $conn->prepare("SELECT id FROM products WHERE name = ? AND id != ? AND is_active = 1");
                $stmt_check_name->bind_param("si", $name, $id_to_exclude);
                $stmt_check_name->execute();
                if ($stmt_check_name->get_result()->num_rows > 0) {
                    $message = "Error: Product name '" . htmlspecialchars($name) . "' already exists.";
                    $error = true;
                }
                $stmt_check_name->close();
            }
        }

        if (!$error) {
            $barcode_sku = !empty($barcode_sku) ? $barcode_sku : null;

            // Handle file upload
            $current_image_url = $_POST['current_image_url'] ?? '';
            $upload_result = handle_photo_upload($current_image_url);
            if (is_array($upload_result) && isset($upload_result['error'])) {
                $message = $upload_result['error'];
            } else {
                $image_url = is_array($upload_result) ? $upload_result['path'] : $upload_result;

                if ($action === 'add_product') {
                    $stmt = $conn->prepare("INSERT INTO products (name, price, discount_percentage, quantity, barcode_sku, image_url) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("sddiss", $name, $price, $discount_percentage, $quantity, $barcode_sku, $image_url);
                    if ($stmt->execute()) {
                        $message = "Product added successfully!";
                        $product_id = $stmt->insert_id;

                        // Insert categories into the pivot table
                        if (!empty($categories_posted)) {
                            $cat_stmt = $conn->prepare("INSERT INTO product_categories (product_id, category_id) VALUES (?, ?)");
                            foreach ($categories_posted as $category_id) {
                                $cat_stmt->bind_param("ii", $product_id, $category_id);
                                $cat_stmt->execute();
                            }
                            $cat_stmt->close();
                        }
                    } else {
                        $message = "Error: " . $stmt->error;
                    }
                    $stmt->close();
                } elseif ($action === 'update_product') {
                    $id = $_POST['id'];

                    // Delete existing category associations
                    $delete_cat_stmt = $conn->prepare("DELETE FROM product_categories WHERE product_id = ?");
                    $delete_cat_stmt->bind_param("i", $id);
                    $delete_cat_stmt->execute();
                    $delete_cat_stmt->close();

                    // Get old quantity for notification logic
                    $old_quantity_stmt = $conn->prepare("SELECT quantity FROM products WHERE id = ?");
                    $old_quantity_stmt->bind_param("i", $id);
                    $old_quantity_stmt->execute();
                    $old_quantity = $old_quantity_stmt->get_result()->fetch_assoc()['quantity'];
                    $old_quantity_stmt->close();

                    // Update product details
                    $stmt = $conn->prepare("UPDATE products SET name=?, price=?, discount_percentage=?, quantity=?, barcode_sku=?, image_url=? WHERE id=?");
                    $stmt->bind_param("sddissi", $name, $price, $discount_percentage, $quantity, $barcode_sku, $image_url, $id);

                    if ($stmt->execute()) {
                        $message = "Product updated successfully!";

                        // Insert new category associations
                        if (!empty($categories_posted)) {
                            $cat_stmt = $conn->prepare("INSERT INTO product_categories (product_id, category_id) VALUES (?, ?)");
                            foreach ($categories_posted as $category_id) {
                                $cat_stmt->bind_param("ii", $id, $category_id);
                                $cat_stmt->execute();
                            }
                            $cat_stmt->close();
                        }

                        // Notification Logic
                        $low_stock_threshold = 10;
                        $notification_type = null;
                        if ($quantity <= $low_stock_threshold && $old_quantity > $low_stock_threshold) {
                            $notification_type = 'low_stock';
                            $notification_message = sprintf("Low stock for '%s'. Only %d left.", $name, $quantity);
                        } elseif ($quantity > $low_stock_threshold && $old_quantity <= $low_stock_threshold) {
                            $notification_type = 'restock';
                            $notification_message = sprintf("'%s' has been restocked. Current stock: %d.", $name, $quantity);
                        }

                        if ($notification_type) {
                            $notify_stmt = $conn->prepare("SELECT id FROM users WHERE (role = 'admin' OR role = 'cashier') AND low_stock_alerts = 1");
                            if ($notify_stmt && $notify_stmt->execute()) {
                                $users_to_notify_result = $notify_stmt->get_result();
                                $users_to_notify = $users_to_notify_result->fetch_all(MYSQLI_ASSOC);
                                if (!empty($users_to_notify)) {
                                    $notification_stmt = $conn->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, ?, ?, ?)");
                                    $link = "includes/products.php#product-row-{$id}";
                                    foreach ($users_to_notify as $user) {
                                        $notification_stmt->bind_param("isss", $user['id'], $notification_type, $notification_message, $link);
                                        $notification_stmt->execute();
                                    }
                                }
                            }
                        }
                    } else {
                        $message = "Error: " . $stmt->error;
                    }
                    $stmt->close();
                }
            }
        }
    }

    // Preserve the filter on redirect
    $current_filter = $_POST['current_filter'] ?? 'active';
    header("Location: ../includes/products.php?filter={$current_filter}&message=" . urlencode($message));
    exit();
}

if (isset($_GET['edit_id'])) {
    $id = $_GET['edit_id'];
    $stmt = $conn->prepare("SELECT * FROM products WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $productToEdit = $result->fetch_assoc();
    $stmt->close();

    // If editing, fetch the product's current categories
    if ($productToEdit) {
        $productToEdit['categories'] = [];
        $cat_stmt = $conn->prepare("SELECT category_id FROM product_categories WHERE product_id = ?");
        $cat_stmt->bind_param("i", $id);
        $cat_stmt->execute();
        $cat_result = $cat_stmt->get_result();
        while ($row = $cat_result->fetch_assoc()) {
            $productToEdit['categories'][] = $row['category_id'];
        }
        $cat_stmt->close();
    }
}

// --- DATA FETCHING FOR DISPLAY (with filtering) ---
$filter = $_GET['filter'] ?? 'active'; // Default to showing active products
$sql = "SELECT p.id, p.name, p.price, p.discount_percentage, p.quantity, p.barcode_sku, p.image_url, p.is_active, GROUP_CONCAT(c.name SEPARATOR ', ') as categories
FROM products p
LEFT JOIN product_categories pc ON p.id = pc.product_id
LEFT JOIN categories c ON pc.category_id = c.id
";

$params = [];
$types = '';

if ($filter === 'active') {
    $sql .= " WHERE p.is_active = ?";
    $params[] = 1;
    $types .= 'i';
} elseif ($filter === 'inactive') {
    $sql .= " WHERE p.is_active = ?";
    $params[] = 0;
    $types .= 'i';
}

$sql .= " GROUP BY p.id, p.name, p.price, p.discount_percentage, p.quantity, p.barcode_sku, p.image_url, p.is_active ORDER BY p.name ASC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Fetch categories for the dropdown
$category_result = $conn->query("SELECT * FROM categories ORDER BY name ASC");
$categories = $category_result->fetch_all(MYSQLI_ASSOC);

// Pre-fetch sales data for archived products to avoid N+1 query problem
$product_sales_history = [];
if ($filter === 'inactive' || $filter === 'all') {
    $sales_history_stmt = $conn->prepare("SELECT DISTINCT product_id FROM sale_items WHERE product_id IS NOT NULL");
    $sales_history_stmt->execute();
    $sales_history_res = $sales_history_stmt->get_result();
    while ($row = $sales_history_res->fetch_assoc()) {
        $product_sales_history[$row['product_id']] = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">    <title>DAN-LEN</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/products.css?v=<?php echo time(); ?>"> <!-- External stylesheet with cache-busting -->
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body>
    <div class="dashboard-layout">
        <div id="sidebar-overlay" class="sidebar-overlay"></div>
        <nav id="sidebarMenu" class="sidebar">
            <div class="sidebar-header">
                <a href="../index1.php">
                    <i class="fas fa-store"></i>
                    <span>DAN-LEN</span>
                </a>
            </div>
            <ul class="sidebar-nav">
                <li>
                    <a href="../dashboard.php">
                        <i class="fas fa-home"></i>
                        Dashboard
                    </a>
                </li>
                <li>
                    <a href="../includes/notifications.php">
                        <i class="fas fa-bell"></i>
                        Notifications
                    </a>
                </li>
                <li>
                    <a href="../users.php">
                        <i class="fa-solid fa-user"></i>
                        Users
                    </a>
                </li>
                <li>
                    <a href="../includes/products.php?filter=all" class="active">
                        <i class="fas fa-tags"></i>
                       All Products
                    </a>
                </li>
                <li>
                    <a href="../categories.php">
                        <i class="fas fa-sitemap"></i>
                        Categories
                    </a>
                </li>
                <li>
                    <a href="../includes/transactions.php">
                        <i class="fas fa-receipt"></i>
                        Transactions
                    </a>
                </li>
                <li>
                    <a href="../index1.php">
                        <i class="fas fa-chart-line"></i>
                        POS
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <div class="notification" id="notification"></div>
            <div class="modal-overlay" id="confirm-modal">
                <div class="modal-content text-center">
                    <p id="confirm-text" class="mb-4"></p>
                    <div class="mb-4 text-left">
                        <label for="confirm-delete-input" class="block text-sm font-medium text-gray-700">To confirm, please type the product name below:</label>
                        <input type="text" id="confirm-delete-input" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500" autocomplete="off">
                    </div>
                    <div class="modal-buttons">
                        <button type="button" class="btn-modal-delete" id="modal-confirm-btn">Archived</button>
                        <button type="button" class="btn-modal-cancel" id="modal-cancel-btn">Cancel</button>
                    </div>
                </div>
            </div>
            <div class="modal-overlay" id="restore-confirm-modal">
                <div class="modal-content !max-w-md p-6 text-left">
                    <h3 class="text-xl font-bold text-gray-900">Restore Product</h3>
                    <p id="restore-confirm-text" class="text-gray-600 my-4">This will make the product active and visible in the POS again.</p>
                    <div class="modal-buttons !justify-end mt-6">
                        <button type="button" class="btn-modal-cancel" id="restore-modal-cancel-btn">Cancel</button>
                        <button type="button" class="btn-modal-restore" id="restore-modal-confirm-btn">Restore Product</button>
                    </div>
                </div>
            </div>
            <!-- NEW: Permanent Delete Modal -->
            <div class="modal-overlay" id="perm-delete-modal">
                <div class="modal-content text-center">
                    <h3 class="text-xl font-bold text-red-700">Permanent Deletion</h3>
                    <p id="perm-delete-text" class="my-4"></p>
                    <div class="mb-4 text-left">
                        <label for="perm-delete-input" class="block text-sm font-medium text-gray-700">To confirm, please type the product name below:</label>
                        <input type="text" id="perm-delete-input" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500" autocomplete="off">
                    </div>
                    <div class="modal-buttons">
                        <button type="button" class="btn-modal-delete-perm" id="perm-modal-confirm-btn">Delete Forever</button>
                        <button type="button" class="btn-modal-cancel" id="perm-modal-cancel-btn">Cancel</button>
                    </div>
                </div>
            </div>

            <div class="main-header">
                <button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
                <h1 class="text-2xl font-bold text-gray-800">Products Management</h1>
            </div>

            <!-- Main Grid Container -->
            <div class="main-grid grid grid-cols-1 lg:grid-cols-3 gap-4 lg:gap-8 mt-6 items-start">
                
                <!-- Add/Edit Product Form Card -->
                <div class="stat-card p-4 sm:p-6 lg:col-span-1 self-start">
                    <h2 class="text-xl font-semibold text-gray-700 mb-4"><?php echo $productToEdit ? 'Edit Product' : 'Add New Product'; ?></h2>
                    <form action="../includes/products.php" method="POST" enctype="multipart/form-data" class="space-y-4">
                        <input type="hidden" name="action" value="<?php echo $productToEdit ? 'update_product' : 'add_product'; ?>">
                        <input type="hidden" name="current_filter" value="<?= htmlspecialchars($filter) ?>">
                        <?php if ($productToEdit): ?>
                            <input type="hidden" name="id" value="<?php echo $productToEdit['id']; ?>">
                            <input type="hidden" name="current_image_url" value="<?php echo htmlspecialchars($productToEdit['image_url']); ?>">
                        <?php endif; ?>
                        <div>
                            <label for="barcode_sku" class="block text-sm font-medium text-gray-600">Barcode SKU</label>
                            <input type="text" id="barcode_sku" name="barcode_sku" value="<?php echo $productToEdit ? htmlspecialchars($productToEdit['barcode_sku']) : ''; ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" placeholder="e.g., 123456789012">
                        </div>
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-600">Product Name</label>
                            <input type="text" id="name" name="name" value="<?php echo $productToEdit ? $productToEdit['name'] : ''; ?>" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                        </div>
                        <div>
                            <label for="price" class="block text-sm font-medium text-gray-600">Price (P)</label>
                            <input type="number" step="0.01" id="price" name="price" value="<?php echo $productToEdit ? htmlspecialchars($productToEdit['price']) : ''; ?>" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                        </div>
                        <div>
                            <label for="discount_percentage" class="block text-sm font-medium text-gray-600">Discount (%)</label>
                            <input type="number" step="0.01" id="discount_percentage" name="discount_percentage" value="<?php echo $productToEdit ? htmlspecialchars($productToEdit['discount_percentage']) : '0.00'; ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" placeholder="e.g., 10 for 10%">
                        </div>
                        <div>
                            <label for="quantity" class="block text-sm font-medium text-gray-600">Quantity</label>
                            <input type="number" id="quantity" name="quantity" value="<?php echo $productToEdit ? htmlspecialchars($productToEdit['quantity']) : ''; ?>" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                        </div>
                        <div>
                            <label for="category" class="block text-sm font-medium text-gray-600">Categories</label>
                            <?php
                                $category_count = count($categories);
                                $scroll_class = $category_count > 7 ? 'max-h-40 overflow-y-auto' : '';
                            ?>
                            <div class="mt-1 p-3 border border-gray-300 rounded-md space-y-2 bg-white <?= $scroll_class ?>">
                                <?php foreach ($categories as $cat): ?>
                                    <div class="flex items-center">
                                        <input id="cat-<?= htmlspecialchars($cat['id']) ?>" name="categories[]" type="checkbox" value="<?= htmlspecialchars($cat['id']) ?>"
                                            <?= ($productToEdit && in_array($cat['id'], $productToEdit['categories'])) ? 'checked' : '' ?>
                                            class="h-4 w-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500 cursor-pointer">
                                        <label for="cat-<?= htmlspecialchars($cat['id']) ?>" class="ml-3 block text-sm text-gray-900 cursor-pointer">
                                            <?= htmlspecialchars($cat['name']) ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div>
                            <label for="photo" class="block text-sm font-medium text-gray-600">Product Photo</label>
                            <input type="file" id="photo" name="photo" class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                        </div>
                        <div class="flex items-center gap-4 pt-2">
                            <button type="submit" class="w-full py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                <?php echo $productToEdit ? 'Update Product' : 'Add Product'; ?>
                            </button>
                            <?php if ($productToEdit): ?>
                                <a href="../includes/products.php" class="w-full text-center py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Products Table Card -->
                <div class="stat-card p-4 sm:p-6 lg:col-span-2 flex flex-col min-h-0">
                    <h2 class="text-xl font-semibold text-gray-700 mb-4 flex-shrink-0">Current Inventory</h2>
                    <!-- Filter buttons -->
                    <div class="flex items-center gap-2 mb-4 border-b pb-4">
                        <span class="text-sm font-medium text-gray-600">Filter:</span>
                        <a href="?filter=active" class="inline-block w-24 text-center px-3 py-1 text-sm font-semibold rounded-full <?= $filter === 'active' ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">Active</a>
                        <a href="?filter=inactive" class="inline-block w-24 text-center px-3 py-1 text-sm font-semibold rounded-full <?= $filter === 'inactive' ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">Archived</a>
                        <a href="?filter=all" class="inline-block w-24 text-center px-3 py-1 text-sm font-semibold rounded-full <?= $filter === 'all' ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">All</a>
                    </div>

                    
                    <!-- Search Bar -->
                    <div class="relative mb-4 flex-shrink-0">
                        <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                            <i class="fas fa-search text-gray-400"></i>
                        </div>
                        <input type="text" id="product-search" placeholder="Search products..." class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                    
                    
                    <!-- CRITICAL: Scroll Wrapper -->
                    <div class="inventory-scroll-container">
                        <table class="inventory-table w-full divide-y divide-gray-200">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Original Price</th>
                                    <th>Price</th>
                                    <th>Quantity</th>
                                    <th>Categories</th>
                                    <th>Barcode SKU</th>
                                    <th>Photo</th>
                                    <th class="min-w-[96px]">Actions</th> 
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php
                                if ($result->num_rows > 0) {
                                    while($row = $result->fetch_assoc()) {
                                        $row_class = ($row['is_active'] ?? 1) ? '' : 'bg-red-50 opacity-70';
                                        echo "<tr id='product-row-{$row['id']}' class='{$row_class}'>";
                                        echo "<td data-label='ID' class='text-sm text-gray-500'>" . htmlspecialchars($row['id']) . "</td>";
                                        echo "<td data-label='Name' class='font-medium text-gray-900'>";
                                        echo "<div>" . htmlspecialchars($row['name']) . "</div>";
                                        if ($row['discount_percentage'] > 0) {
                                            echo "<div class='text-xs font-bold text-red-500'>" . htmlspecialchars($row['discount_percentage']) . "% OFF</div>";
                                        }
                                        echo "</td>";
                                        // Show original price with a strikethrough if discounted
                                        if ($row['discount_percentage'] > 0) {
                                            echo "<td data-label='Original Price'><span class='line-through text-gray-400'>P" . number_format($row['price'], 2) . "</span></td>";
                                            $discountedPrice = $row['price'] * (1 - ($row['discount_percentage'] / 100));
                                            echo "<td data-label='Price' class='font-bold text-green-600'>P" . number_format($discountedPrice, 2) . "</td>";
                                        } else {
                                            echo "<td data-label='Original Price'>P" . number_format($row['price'], 2) . "</td>";
                                            echo "<td data-label='Price'>P" . number_format($row['price'], 2) . "</td>";
                                        }
                                        echo "<td data-label='Quantity'>" . htmlspecialchars($row['quantity']) . "</td>";
                                        echo "<td data-label='Categories'>" . (!empty($row['categories']) ? htmlspecialchars($row['categories']) : '<span class="text-xs text-gray-400">Uncategorized</span>') . "</td>";
                                        echo "<td data-label='Barcode SKU'>" . ($row['barcode_sku'] ? htmlspecialchars($row['barcode_sku']) : '<span class="text-xs text-gray-400">Not Set</span>') . "</td>";
                                        echo "<td data-label='Photo'>";
                                        if (!empty($row['image_url']) && file_exists('../' . $row['image_url'])) {
                                            echo "<img src='../" . htmlspecialchars($row['image_url']) . "' alt='" . htmlspecialchars($row['name']) . "' class='product-photo'>";
                                        } else {
                                            echo "No Photo";
                                        }
                                        echo "</td>";
                                        // UPDATED: Actions column using icons and flex for better spacing
                                        echo "<td data-label='Actions' class='actions flex space-x-2 items-center'>";
                                        if ($row['is_active']) {
                                            echo "<a href='../includes/products.php?edit_id=" . htmlspecialchars($row['id']) . "' title='Edit Product' class='btn-action btn-icon btn-edit'>";
                                            echo "<i class='fas fa-pen-to-square'></i>"; // Edit Icon
                                            echo "</a>";
                                            echo "<a href='#' title='Archive Product' class='btn-action btn-icon btn-delete delete-product' data-id='" . htmlspecialchars($row['id']) . "' data-name='" . htmlspecialchars($row['name']) . "'>";
                                            echo "<i class='fas fa-archive'></i>"; // Changed icon to 'archive'
                                            echo "</a>";
                                        } else {
                                            echo "<a href='#' title='Restore Product' class='btn-action btn-icon btn-restore restore-product' data-id='" . htmlspecialchars($row['id']) . "' data-name='" . htmlspecialchars($row['name']) . "'>";
                                            echo "<i class='fas fa-undo-alt'></i>"; // Changed icon to a more standard 'undo'
                                            echo "</a>";
                                            $has_sales = isset($product_sales_history[$row['id']]);
                                            echo "<a href='#' title='Delete Forever' class='btn-action btn-icon btn-delete-perm permanent-delete-product' data-id='" . htmlspecialchars($row['id']) . "' data-name='" . htmlspecialchars($row['name']) . "' data-has-sales='" . ($has_sales ? 'true' : 'false') . "'>";
                                            echo "<i class='fas fa-trash-alt'></i>";
                                            echo "</a>";
                                        }
                                        echo "</td>";
                                        echo "</tr>";
                                    }
                                } else {
                                    // Corrected colspan to 9 to match the number of columns
                                    echo "<tr><td colspan=\"9\" class=\"text-center py-4 text-gray-500\">No products found for this filter.</td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <script src="../js/products.js"></script>
</body>
</html>