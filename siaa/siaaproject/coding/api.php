<?php
// Start the session at the very beginning to ensure $_SESSION is available.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'auth_check.php';
require_once 'db_connect.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get the request body for POST requests
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    if (empty($input) || !isset($input['action'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid POST request.']);
        exit();
    }
    $action = $input['action'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    // Handle GET requests
    $action = $_GET['action'];
    $input = $_GET; // Use GET parameters as input
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method or missing action.']);
    exit();
}

switch ($action) {
    case 'scan_barcode':
        handle_scan_barcode($conn, $input);
        exit();
        break;

    case 'process_sale':
        handle_process_sale($conn, $input);
        exit();
        break;

    case 'get_notifications':
        handle_get_notifications($conn, $_SESSION['user_id']);
        exit();
        break;

    case 'mark_notifications_as_read':
        handle_mark_notifications_as_read($conn, $_SESSION['user_id']);
        exit();
        break;

    case 'get_transactions':
        handle_get_transactions($conn, $_SESSION['user_id'], $_SESSION['role']);
        break;

    case 'check_stock':
        handle_check_stock($conn, $input);
        exit();
        break;

    case 'get_dashboard_stats':
        handle_get_dashboard_stats($conn);
        exit();
        break;

    case 'get_sales_analysis':
        handle_get_sales_analysis($conn);
        exit();
        break;

    case 'get_transaction_details':
        handle_get_transaction_details($conn, $input);
        exit();
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        break;
}

/**
 * Handles the logic for a scanned barcode.
 *
 * @param mysqli $conn The database connection.
 * @param array $input The JSON input from the frontend.
 */
function handle_scan_barcode($conn, $input) {
    $sku = $input['sku'] ?? null;
    $cartItems = $input['cart'] ?? [];

    if (empty($sku)) {
        echo json_encode(['success' => false, 'message' => 'SKU is missing.']);
        return;
    }

    // Look up the product by its barcode SKU
    $stmt = $conn->prepare("SELECT id, name, price, quantity FROM products WHERE barcode_sku = ?");
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'DB prepare failed: ' . $conn->error]);
        return;
    }
    $stmt->bind_param("s", $sku);
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'message' => 'DB execute failed: ' . $stmt->error]);
        $stmt->close();
        return;
    }
    $result = $stmt->get_result();
    $product = $result->fetch_assoc();
    $stmt->close();

    if (!$product) {
        echo json_encode(['success' => false, 'message' => "The scanned barcode is not registered. Please register the item or type it manually."]);
        return;
    }

    // Check if product is out of stock
    if ($product['quantity'] <= 0) {
        echo json_encode(['success' => false, 'message' => "Product '{$product['name']}' is out of stock."]);
        return;
    }

    $productId = (int)$product['id'];
    $foundInCart = false;

    // Check if the item is already in the cart
    foreach ($cartItems as &$item) {
        if ((int)$item['id'] === $productId) {
            // Check if we can add more
            if ((int)$item['quantity'] < (int)$product['quantity']) {
                $item['quantity'] = (int)$item['quantity'] + 1;
                $item['total'] = $item['quantity'] * (float)$item['price'];
            } else {
                echo json_encode(['success' => false, 'message' => "No more stock for '{$product['name']}'."]);
                return;
            }
            $foundInCart = true;
            break;
        }
    }
    unset($item); // Unset reference

    // If not found, add it to the cart
    if (!$foundInCart) {
        $cartItems[] = [
            'id' => $productId,
            'name' => $product['name'],
            'price' => (float)$product['price'],
            'quantity' => 1,
            'total' => (float)$product['price']
        ];
    }

    // Return the updated cart
    echo json_encode(['success' => true, 'cart' => $cartItems, 'product_name' => $product['name']]);
}

/**
 * Processes a sale, updates inventory, and sends low stock alerts.
 */
function handle_process_sale($conn, $input) {
    $subtotal = $input['subtotal'] ?? 0;
    $tax_amount = $input['tax_amount'] ?? 0;
    $discount_amount = $input['discount_amount'] ?? 0;
    $grand_total = $input['grand_total'] ?? 0;
    $items = $input['items'] ?? [];

    if (empty($items) || $grand_total <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid sale data.']);
        return;
    }

    $conn->begin_transaction();

    try {
        // Insert the main sale record with detailed financial info
        $stmt = $conn->prepare(
            "INSERT INTO sales (subtotal, tax_amount, discount_amount, grand_total, user_id) VALUES (?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            throw new Exception('DB prepare failed: ' . $conn->error);
        }
        $stmt->bind_param("ddddi", $subtotal, $tax_amount, $discount_amount, $grand_total, $_SESSION['user_id']);
        if (!$stmt->execute()) {
            throw new Exception('DB execute failed: ' . $stmt->error);
        }
        $saleId = $stmt->insert_id;
        $stmt->close();

        // Prepare statements for sale items and inventory update
        $sale_item_stmt = $conn->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
        if (!$sale_item_stmt) {
            throw new Exception('DB prepare failed: ' . $conn->error);
        }
        $update_product_stmt = $conn->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?");
        if (!$update_product_stmt) {
            $sale_item_stmt->close();
            throw new Exception('DB prepare failed: ' . $conn->error);
        }

        $stock_check_stmt = $conn->prepare("SELECT quantity FROM products WHERE id = ?");
        if (!$stock_check_stmt) {
            throw new Exception('DB prepare failed for stock check: ' . $conn->error);
        }

        $low_stock_products = [];
        $low_stock_threshold = 10; // Set your low stock threshold here

        foreach ($items as $item) {
            $productId = (int)$item['id'];
            $quantity = (int)$item['quantity'];
            $price = (float)$item['price'];

            // Get current stock before updating
            $stock_check_stmt->bind_param("i", $productId);
            $stock_check_stmt->execute();
            $current_stock = (int) $stock_check_stmt->get_result()->fetch_assoc()['quantity'];

            // Insert into sale_items
            $sale_item_stmt->bind_param("iiid", $saleId, $productId, $quantity, $price);
            $sale_item_stmt->execute();

            // Update product quantity
            $update_product_stmt->bind_param("ii", $quantity, $productId);
            $update_product_stmt->execute();
            
            // Check for low stock using the fetched value
            $new_stock = $current_stock - $quantity;
            if ($new_stock <= $low_stock_threshold) {
                $low_stock_products[] = ['id' => $productId, 'name' => $item['name'], 'quantity' => $new_stock];
            }
        }

        // Close item/update statements
        $sale_item_stmt->close();
        $update_product_stmt->close();
        $stock_check_stmt->close();

        $conn->commit();

        // --- Send Low Stock Notifications (after the transaction is committed) ---
        if (!empty($low_stock_products)) {
            $users_to_notify_result = $conn->query("SELECT id FROM users WHERE (role = 'admin' OR role = 'cashier') AND low_stock_alerts = 1");
            if ($users_to_notify_result) {
                $users_to_notify = $users_to_notify_result->fetch_all(MYSQLI_ASSOC);
                if (!empty($users_to_notify)) {
                    $notification_stmt = $conn->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'low_stock', ?, ?)");
                    if ($notification_stmt) {
                        foreach ($low_stock_products as $product) {
                            $message = sprintf("Low stock for '%s'. Only %d left.", $product['name'], $product['quantity']);
                            $link = "includes/products.php#product-row-{$product['id']}";
                            foreach ($users_to_notify as $user) {
                                $notification_stmt->bind_param("iss", $user['id'], $message, $link);
                                $notification_stmt->execute();
                            }
                        }
                        $notification_stmt->close();
                    }
                }
            }
        }

        echo json_encode(['success' => true, 'message' => 'Sale processed successfully.', 'sale_id' => $saleId]);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Transaction failed: ' . $e->getMessage()]);
    }
}

/**
 * Fetches transaction history.
 * Admins see all transactions, cashiers see only their own.
 */
function handle_get_transactions($conn, $userId, $userRole) {
    // Defensive casts
    $userId = (int)$userId;
    $userRole = (string)$userRole;

    // Build a query that always returns the same columns
    if ($userRole === 'admin') {
        $sql = "SELECT s.id, s.grand_total, s.created_at, COALESCE(u.username, '') AS cashier_name
                FROM sales s
                LEFT JOIN users u ON s.user_id = u.id
                ORDER BY s.created_at DESC
                LIMIT 100";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("handle_get_transactions prepare failed (admin): " . $conn->error);
            echo json_encode(['success' => false, 'message' => 'DB prepare failed.']);
            return;
        }
    } else {
        $sql = "SELECT s.id, s.grand_total, s.created_at, COALESCE(u.username, '') AS cashier_name
                FROM sales s
                LEFT JOIN users u ON s.user_id = u.id
                WHERE s.user_id = ?
                ORDER BY s.created_at DESC
                LIMIT 100";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("handle_get_transactions prepare failed (cashier): " . $conn->error);
            echo json_encode(['success' => false, 'message' => 'DB prepare failed.']);
            return;
        }
        $stmt->bind_param("i", $userId);
    }

    if (!$stmt->execute()) {
        error_log("handle_get_transactions execute failed: " . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'DB execute failed.']);
        $stmt->close();
        return;
    }

    // Try to use get_result(); if not available, fall back to bind_result fetch
    $result = $stmt->get_result();
    $transactions = [];

    if ($result !== false) {
        while ($row = $result->fetch_assoc()) {
            // normalize fields as needed
            $transactions[] = [
                'id' => isset($row['id']) ? (int)$row['id'] : null,
                'grand_total' => isset($row['grand_total']) ? (float)$row['grand_total'] : 0.0,
                'created_at' => $row['created_at'] ?? null,
                'cashier_name' => $row['cashier_name'] ?? ''
            ];
        }
    } else {
        // Fallback when get_result() isn't supported (e.g., no mysqlnd)
        $stmt->bind_result($id, $grand_total, $created_at, $cashier_name);
        while ($stmt->fetch()) {
            $transactions[] = [
                'id' => (int)$id,
                'grand_total' => (float)$grand_total,
                'created_at' => $created_at,
                'cashier_name' => $cashier_name ?? ''
            ];
        }
    }

    $stmt->close();

    echo json_encode(['success' => true, 'transactions' => $transactions]);
    // do not exit here; allow caller to close connection
}

/**
 * Checks stock levels for items in the cart before finalizing a sale.
 */
function handle_check_stock($conn, $input) {
    $items = $input['items'] ?? [];
    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'No items to check.']);
        return;
    }

    $stmt = $conn->prepare("SELECT name, quantity FROM products WHERE id = ?");
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error preparing stock check.']);
        return;
    }

    foreach ($items as $item) {
        $productId = (int)$item['id'];
        $cartQuantity = (int)$item['quantity'];

        $stmt->bind_param("i", $productId);
        $stmt->execute();
        $result = $stmt->get_result();
        $product = $result->fetch_assoc();

        if (!$product || $product['quantity'] < $cartQuantity) {
            $productName = $product['name'] ?? $item['name'];
            echo json_encode(['success' => false, 'message' => "Item '{$productName}' is out of stock. Please return to POS to update your cart."]);
            $stmt->close();
            return;
        }
    }
    $stmt->close();
    echo json_encode(['success' => true]);
}
/**
 * Fetches unread notifications for the current user.
 */
function handle_get_notifications($conn, $userId) {
    // Get latest 5 notifications
    $stmt = $conn->prepare("SELECT id, type, message, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $notifications = [];
    while ($row = $result->fetch_assoc()) {
        // Add a 'read_status' for easier frontend handling
        $row['is_read'] = (bool)$row['is_read'];
        $notifications[] = $row;
    }
    $stmt->close();

    // Get total and unread notification counts
    $count_stmt = $conn->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread FROM notifications WHERE user_id = ?");
    $count_stmt->bind_param("i", $userId);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result()->fetch_assoc();
    $total_count = $count_result['total'] ?? 0;
    $unread_count = $count_result['unread'] ?? 0;
    $count_stmt->close();

    echo json_encode(['success' => true, 'notifications' => $notifications, 'total' => (int)$total_count, 'unread' => (int)$unread_count]);
}

/**
 * Marks all unread notifications for a user as read.
 */
function handle_mark_notifications_as_read($conn, $userId) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param("i", $userId);
    
    if ($stmt->execute()) {
        // Check if any rows were actually updated
        $affected_rows = $stmt->affected_rows;
        echo json_encode(['success' => true, 'message' => "$affected_rows notifications marked as read."]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to mark notifications as read.']);
    }
    $stmt->close();
}

/**
 * Fetches statistics for the admin dashboard.
 */
function handle_get_dashboard_stats($conn) {
    // This API endpoint should be restricted to admins.
    if ($_SESSION['role'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Access Denied.']);
        return;
    }

    $period = $_GET['period'] ?? 'weekly'; // 'daily', 'weekly', 'monthly'
    $date_condition = '';
    $chart_label_format = '';
    $chart_group_by = '';
    $chart_order_by = '';

    switch ($period) {
        case 'daily':
            $date_condition = "WHERE DATE(sale_date) = CURDATE()";
            $chart_label_format = "'%h %p'"; // Hour and AM/PM
            $chart_group_by = "HOUR(sale_date)";
            $chart_order_by = "HOUR(sale_date) ASC";
            break;
        case 'monthly':
            $date_condition = "WHERE YEAR(sale_date) = YEAR(CURDATE()) AND MONTH(sale_date) = MONTH(CURDATE())";
            $chart_label_format = "'%b %d'"; // Month and Day (e.g., Jan 01)
            $chart_group_by = "DATE(sale_date)";
            $chart_order_by = "DATE(sale_date) ASC";
            break;
        case 'yearly':
            $date_condition = "WHERE YEAR(sale_date) = YEAR(CURDATE())";
            $chart_label_format = "DATE_FORMAT(sale_date, '%b')"; // Abbreviated month name (e.g., Jan)
            $chart_group_by = "MONTH(sale_date)";
            $chart_order_by = "MONTH(sale_date) ASC";
            break;
        case 'weekly':
        default:
            $date_condition = "WHERE sale_date >= CURDATE() - INTERVAL 6 DAY AND sale_date < CURDATE() + INTERVAL 1 DAY";
            $chart_label_format = "DAYNAME(sale_date)";
            $chart_group_by = "DAYOFWEEK(sale_date), DAYNAME(sale_date)";
            $chart_order_by = "DAYOFWEEK(sale_date) ASC";
            break;
    }

    // Fetch main statistics in one query for efficiency
    $stats_query = "
        SELECT
            (SELECT SUM(grand_total) FROM sales {$date_condition}) as period_revenue,
            (SELECT SUM(grand_total) FROM sales) as total_revenue,
            (SELECT COUNT(id) FROM sales) as total_transactions,
            (SELECT COUNT(id) FROM products) as total_products,
            (SELECT COUNT(id) FROM users) as total_users
    ";

    $stats_result = $conn->query($stats_query);
    $stats = $stats_result->fetch_assoc();

    // Fetch sales data for the chart based on the period
    $chart_data_query = "
        SELECT 
            DATE_FORMAT(sale_date, {$chart_label_format}) as label, 
            SUM(grand_total) as total 
        FROM sales 
        {$date_condition}
        GROUP BY {$chart_group_by}
        ORDER BY {$chart_order_by}
    ";
    $chart_data_result = $conn->query($chart_data_query);
    $chart_data = [];
    if ($chart_data_result) {
        while ($row = $chart_data_result->fetch_assoc()) {
            $chart_data[] = $row;
        }
    }

    // Fetch top 5 selling products by quantity
    $top_products_query = "
        SELECT p.name, SUM(si.quantity) as total_sold
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.id
        JOIN products p ON si.product_id = p.id
        {$date_condition}
        GROUP BY si.product_id
        ORDER BY total_sold DESC
        LIMIT 5
    ";
    $top_products_result = $conn->query($top_products_query);
    $top_products = [];
    if ($top_products_result && $top_products_result->num_rows > 0) {
        while ($row = $top_products_result->fetch_assoc()) {
            $top_products[] = $row;
        }
    }

    // Fetch top 5 selling categories by quantity for the period
    $top_categories_query = "
        SELECT c.name as category_name, SUM(si.quantity) as total_sold
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.id
        JOIN product_categories pc ON si.product_id = pc.product_id
        JOIN categories c ON pc.category_id = c.id
        {$date_condition}
        GROUP BY c.id
        ORDER BY total_sold DESC
        LIMIT 5
    ";
    $top_categories_result = $conn->query($top_categories_query);
    $top_categories = [];
    if ($top_categories_result && $top_categories_result->num_rows > 0) {
        while ($row = $top_categories_result->fetch_assoc()) {
            $top_categories[] = $row;
        }
    }

    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'chart_data' => $chart_data,
        'top_products' => $top_products,
        'top_categories' => $top_categories
    ]);
}

/**
 * Generates a sales analysis and prediction report.
 */
function handle_get_sales_analysis($conn) {
    if ($_SESSION['role'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Access Denied.']);
        return;
    }

    $year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
    $month = isset($_GET['month']) && !empty($_GET['month']) ? (int)$_GET['month'] : null;
    $day = isset($_GET['day']) && !empty($_GET['day']) ? (int)$_GET['day'] : null;

    // Validate the date. If the day/month is invalid for the year, block the report.
    if ($month && $day && !checkdate($month, $day, $year)) {
        echo json_encode(['success' => false, 'message' => 'The selected date is invalid. Please enter a valid day and month for the chosen year.']);
        return;
    }

    // Block report generation for past dates.
    $is_past_date = ($year < date('Y')) ||
                    ($year == date('Y') && $month && $month < date('m')) ||
                    ($year == date('Y') && $month && $month == date('m') && $day && $day < date('d'));

    if ($is_past_date) {
        echo json_encode(['success' => false, 'message' => 'Reports can only be generated for current or future dates. Please select a valid date.']);
        return;
    }

    $reference_year = $year;
    $is_estimate = false;
    $justification = "";

    // Check if data exists for the selected year. If not, find the most recent year with data.
    $check_stmt = $conn->prepare("SELECT 1 FROM sales WHERE YEAR(sale_date) = ? LIMIT 1");
    $check_stmt->bind_param("i", $year);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows === 0) {
        $is_estimate = true;
        $latest_year_res = $conn->query("SELECT MAX(YEAR(sale_date)) as latest_year FROM sales");
        $latest_year_row = $latest_year_res->fetch_assoc();
        if (!$latest_year_row || !$latest_year_row['latest_year']) {
            echo json_encode(['success' => false, 'message' => 'No sales data found in the database to generate a report.']);
            return;
        }
        $reference_year = (int)$latest_year_row['latest_year'];
        $justification .= "No sales data found for the selected year ($year). The following analysis is based on the most recent available data from {$reference_year}.\n";
    }

    // Build date conditions for the reference year
    $year_condition = "WHERE YEAR(sale_date) = {$reference_year}";
    $period_condition = $year_condition;
    $report_title = "Sales Analysis for {$reference_year}";

    if ($month) {
        $period_condition .= " AND MONTH(sale_date) = {$month}";
        $month_name = date('F', mktime(0, 0, 0, $month, 10));
        $report_title = "Sales Analysis for {$month_name} {$reference_year}";

        // Check if the specific month has data. If not, it's an estimation.
        $month_check_res = $conn->query("SELECT 1 FROM sales {$period_condition} LIMIT 1");
        if ($month_check_res->num_rows === 0) {
            $is_estimate = true;
            $justification .= "No sales data found for {$month_name} {$year}. The values shown are estimations based on the yearly average.\n";
        }
    }

    if ($day) {
        if (!$month) { // A day can't be selected without a month
            echo json_encode(['success' => false, 'message' => 'Please select a month when filtering by a specific day.']);
            return;
        }
        $period_condition .= " AND DAY(sale_date) = {$day}";
        $report_title = "Sales Analysis for {$month_name} {$day}, {$reference_year}";

        $day_check_res = $conn->query("SELECT 1 FROM sales {$period_condition} LIMIT 1");
        if ($day_check_res->num_rows === 0) { $is_estimate = true; }
    }

    // --- CALCULATIONS ---

    // 1. Total Sales for the period
    $total_sales_res = $conn->query("SELECT SUM(si.quantity) as total FROM sale_items si JOIN sales s ON si.sale_id = s.id {$period_condition}");
    $total_items_sold = $total_sales_res->fetch_assoc()['total'] ?? 0;

    // 2. Average Daily & Monthly Sales (based on the whole reference year for better averages)
    // Calculate average daily sales
    $avg_daily_res = $conn->query("
        SELECT
            AVG(daily_quantity) as avg_daily_quantity
        FROM (SELECT SUM(si.quantity) as daily_quantity FROM sale_items si JOIN sales s ON si.sale_id = s.id {$year_condition} GROUP BY DATE(s.sale_date)) as daily_sales
    ");
    $avg_daily_data = $avg_daily_res->fetch_assoc();
    $avg_daily_items = $avg_daily_data['avg_daily_quantity'] ?? 0;

    // Calculate average monthly sales and count operating months
    $avg_monthly_res = $conn->query("
        SELECT AVG(monthly_quantity) as avg_monthly_quantity, COUNT(*) as operating_months
        FROM (SELECT SUM(si.quantity) as monthly_quantity FROM sale_items si JOIN sales s ON si.sale_id = s.id {$year_condition} GROUP BY MONTH(s.sale_date)) as monthly_sales
    ");
    $avg_monthly_data = $avg_monthly_res->fetch_assoc();
    $avg_monthly_items = $avg_monthly_data['avg_monthly_quantity'] ?? 0;
    $operating_months = $avg_monthly_data['operating_months'] ?? 0;

    // If the requested period had no data, estimate its total sales
    if ($is_estimate && $total_items_sold == 0) {
        if ($month) {
            $total_items_sold = $avg_monthly_items;
            if ($day) {
                $total_items_sold = $avg_daily_items;
            }
        } else {
            $total_items_sold = $avg_monthly_items * ($operating_months > 0 ? $operating_months : 12);
        }
    }

    // 3. Best Day, Week, Month (based on the reference year)
    $best_day_res = $conn->query("SELECT DATE(s.sale_date) as sale_day, SUM(si.quantity) as total_quantity FROM sale_items si JOIN sales s ON si.sale_id = s.id {$year_condition} GROUP BY sale_day ORDER BY total_quantity DESC LIMIT 1");
    $best_day_row = $best_day_res->fetch_assoc();

    $best_week_res = $conn->query("SELECT WEEK(s.sale_date, 1) as week_num, SUM(si.quantity) as total_quantity FROM sale_items si JOIN sales s ON si.sale_id = s.id {$year_condition} GROUP BY week_num ORDER BY total_quantity DESC LIMIT 1");
    $best_week_row = $best_week_res->fetch_assoc();

    $best_month_res = $conn->query("SELECT MONTHNAME(s.sale_date) as month_name, SUM(si.quantity) as total_quantity FROM sale_items si JOIN sales s ON si.sale_id = s.id {$year_condition} GROUP BY month_name ORDER BY total_quantity DESC LIMIT 1");
    $best_month_row = $best_month_res->fetch_assoc();

    // --- NEW: Chart Data Generation ---
    $chart_data = [];
    $chart_config = ['hAxisTitle' => 'Period', 'type' => 'yearly'];

    if ($day && $month) { // Daily report, show hourly breakdown of quantity sold
        $chart_query = "SELECT DATE_FORMAT(s.sale_date, '%h %p') as label, SUM(si.quantity) as total_quantity FROM sale_items si JOIN sales s ON si.sale_id = s.id {$period_condition} GROUP BY HOUR(s.sale_date) ORDER BY HOUR(s.sale_date) ASC";
        $chart_config['hAxisTitle'] = 'Hour';
        $chart_config['type'] = 'daily';
    } elseif ($month) { // Monthly report, show daily breakdown of quantity sold
        $chart_query = "SELECT DAY(s.sale_date) as label, SUM(si.quantity) as total_quantity FROM sale_items si JOIN sales s ON si.sale_id = s.id {$period_condition} GROUP BY DAY(s.sale_date) ORDER BY DAY(s.sale_date) ASC";
        $chart_config['hAxisTitle'] = 'Day';
        $chart_config['type'] = 'monthly';
    } else { // Yearly report, show monthly breakdown of quantity sold
        $chart_query = "SELECT DATE_FORMAT(s.sale_date, '%b') as label, SUM(si.quantity) as total_quantity FROM sale_items si JOIN sales s ON si.sale_id = s.id {$period_condition} GROUP BY MONTH(s.sale_date) ORDER BY MONTH(s.sale_date) ASC";
        $chart_config['hAxisTitle'] = 'Month';
        $chart_config['type'] = 'yearly';
    }

    if (isset($chart_query)) {
        $chart_res = $conn->query($chart_query);
        if ($chart_res) {
            while ($row = $chart_res->fetch_assoc()) {
                $chart_data[] = $row;
            }
        }
    }

    // --- PREDICTION & JUSTIFICATION ---

    // Get previous year's total sales for growth calculation
    $prev_year = $reference_year - 1;
    $prev_year_sales_res = $conn->query("SELECT SUM(si.quantity) as total FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE YEAR(s.sale_date) = {$prev_year}");
    $prev_year_sales = $prev_year_sales_res->fetch_assoc()['total'] ?? 0;

    // Get current year's total sales
    $current_year_sales_res = $conn->query("SELECT SUM(si.quantity) as total FROM sale_items si JOIN sales s ON si.sale_id = s.id {$year_condition}");
    $current_year_sales = $current_year_sales_res->fetch_assoc()['total'] ?? 0;

    $growth_rate = 0;
    if ($prev_year_sales > 0 && $current_year_sales > 0) {
        $growth_rate = (($current_year_sales - $prev_year_sales) / $prev_year_sales);
    }

    $predicted_sales_next_year = $current_year_sales * (1 + $growth_rate);

    // Refined Justification Logic
    // For current or future dates, provide a prediction.
    $justification .= "Based on historical data from {$reference_year}, the highest sales volume is predicted to be in <strong>" . ($best_month_row['month_name'] ?? 'N/A') . "</strong>. ";
    if ($growth_rate > 0) {
        $justification .= "With a year-over-year growth of " . number_format($growth_rate * 100, 2) . "%, focusing marketing efforts around this peak time could yield significant results.";
    } elseif ($growth_rate < 0) {
        $justification .= "However, a sales decline of " . number_format(abs($growth_rate) * 100, 2) . "% was observed. It is crucial to bolster marketing efforts during historically strong periods like " . ($best_month_row['month_name'] ?? 'N/A') . " to reverse this trend.";
    } else {
        $justification .= "Sales have been stable. Introducing new promotions during the identified peak periods is an effective strategy to stimulate growth.";
    }

    $statistics = [
        'title' => $report_title . ($is_estimate ? " (Estimation)" : ""),
        'total_items' => $total_items_sold,
        'avg_daily_items' => $avg_daily_items,
        'avg_monthly_items' => $avg_monthly_items,
        'best_day' => [
            'date' => $best_day_row['sale_day'] ?? 'N/A',
            'quantity' => $best_day_row['total_quantity'] ?? 0
        ],
        'best_week' => [
            'week_num' => $best_week_row['week_num'] ?? 'N/A',
            'quantity' => $best_week_row['total_quantity'] ?? 0
        ],
        'best_month' => [
            'name' => $best_month_row['month_name'] ?? 'N/A',
            'quantity' => $best_month_row['total_quantity'] ?? 0
        ]
    ];

    echo json_encode([
        'success' => true,
        'statistics' => $statistics,
        'justification' => $justification,
        'chart_data' => $chart_data,        'chart_config' => $chart_config
    ]);
}

/**
 * Fetches the items for a single transaction.
 */
function handle_get_transaction_details($conn, $input) {
    $transactionId = $input['transaction_id'] ?? null;
    $userId = $_SESSION['user_id'];
    $userRole = $_SESSION['role'];

    if (!$transactionId) {
        echo json_encode(['success' => false, 'message' => 'Transaction ID is missing.']);
        return;
    }

    // Security check: Admins can see any transaction, cashiers can only see their own.
    $sql = "SELECT IFNULL(p.name, 'Archived Product') as product_name, si.quantity, si.price 
            FROM sale_items si
            LEFT JOIN products p ON si.product_id = p.id
            JOIN sales s ON si.sale_id = s.id
            WHERE si.sale_id = ?";

    if ($userRole !== 'admin') {
        $sql .= " AND s.user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $transactionId, $userId);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $transactionId);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $items = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Security: Sanitize product names before sending to frontend
    foreach ($items as &$item) {
        $item['product_name'] = htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8');
    }

    // NEW: Fetch the sale's financial details (subtotal, tax, discount)
    $sale_details_stmt = $conn->prepare("SELECT subtotal, tax_amount, discount_amount FROM sales WHERE id = ?");
    $sale_details_stmt->bind_param("i", $transactionId);
    $sale_details_stmt->execute();
    $sale_details = $sale_details_stmt->get_result()->fetch_assoc();
    $sale_details_stmt->close();

    echo json_encode([
        'success' => true, 
        'items' => $items,
        'details' => $sale_details // Send details along with items
    ]);
}

?>