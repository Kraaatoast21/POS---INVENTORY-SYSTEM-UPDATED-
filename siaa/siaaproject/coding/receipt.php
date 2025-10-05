<?php
require_once 'auth_check.php';
require_once 'db_connect.php';

// Role-based access control: only admins and cashiers can view
if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'cashier') {
    die("Access Denied: You do not have permission to access this page.");
}

$sale_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($sale_id <= 0) {
    die("Invalid Transaction ID.");
}

// --- Fetch Transaction Data ---
$sale = null;
$sale_items = [];

// Use a single transaction to ensure data consistency
$conn->begin_transaction();
try {
    // Fetch main sale details and cashier info
    $stmt = $conn->prepare(
        "SELECT s.id, s.subtotal, s.tax_amount, s.discount_amount, s.grand_total, s.sale_date, IFNULL(CONCAT(u.first_name, ' ', u.last_name), 'Deleted User') AS cashier_name
         FROM sales s
         LEFT JOIN users u ON s.user_id = u.id
         WHERE s.id = ?"
    );
    if (!$stmt) throw new Exception("Prepare failed for sale details: " . $conn->error);
    $stmt->bind_param("i", $sale_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $sale = $result->fetch_assoc();
    $stmt->close();

    if (!$sale) {
        throw new Exception("Transaction not found.");
    }

    // Fetch sale items
    // MODIFIED: Join with products table to get the product name
    $stmt = $conn->prepare(
        "SELECT si.quantity, si.price, IFNULL(p.name, 'N/A') AS product_name
         FROM sale_items si
         LEFT JOIN products p ON si.product_id = p.id
         WHERE si.sale_id = ?"
    );
    // Check if the product_id is NULL (due to permanent deletion) and handle it.
    // The query now uses a LEFT JOIN and IFNULL to prevent errors if a product was deleted.
    // The original query used an INNER JOIN which would fail to fetch items for deleted products.
    // We also need to fetch the product name from the `products` table, not `sale_items`.

    if (!$stmt) throw new Exception("Prepare failed for sale items: " . $conn->error);
    $stmt->bind_param("i", $sale_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $sale_items[] = $row;
    }
    $stmt->close();

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    die("Error fetching transaction data: " . $e->getMessage());
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt #<?= htmlspecialchars($sale['id']) ?> - DAN-LEN</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
        #receipt-container {
            font-family: 'Consolas', 'Courier New', monospace;
            width: 448px; /* Larger width for screen viewing */            
            background-color: white;
            padding: 1rem;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);
        }
        @media print {
            body { background-color: white; }
            body * { visibility: hidden; }
            #receipt-container, #receipt-container * { visibility: visible; }
            #receipt-container {
                position: absolute;
                left: 0;
                top: 0; 
                width: 300px; /* Standard thermal receipt width */
                box-shadow: none;
                padding: 0;
            }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen flex flex-col items-center justify-center p-4">

    <div id="receipt-container">
        <div class="text-center mb-4">
            <h1 class="text-xl font-bold">DAN-LEN Store</h1>
            <p class="text-sm">6 Block 41 Lot 8 Brgy 176 .C</p>
            <p class="text-sm">Bagong Silang Caloocan City</p>
        </div>

        <div class="text-sm mb-4">
            <p>Date: <?= htmlspecialchars(date('m/d/Y H:i:s', strtotime($sale['sale_date']))) ?></p>
            <p>Trans ID: #<?= htmlspecialchars($sale['id']) ?></p>
            <p>Cashier: <?= htmlspecialchars($sale['cashier_name']) ?></p>
        </div>

        <div class="border-t border-b border-dashed border-black py-2">
            <div class="flex justify-between font-bold text-sm">
                <span>ITEM</span>
                <span class="text-right">TOTAL</span>
            </div>
            <div class="mt-1 space-y-1 text-sm">
                <?php foreach ($sale_items as $item): ?>
                    <div class="flex">
                        <div class="flex-grow pr-2"><?= htmlspecialchars($item['product_name']) ?></div>
                        <div class="w-16 text-right">P<?= number_format($item['quantity'] * $item['price'], 2) ?></div>
                    </div>
                    <div class="flex pl-2">
                        <div class="flex-grow pr-2"><?= htmlspecialchars($item['quantity']) ?> x P<?= number_format($item['price'], 2) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="mt-2 space-y-1 text-sm">
            <div class="flex justify-between"><span>Subtotal</span><span>P<?= number_format($sale['subtotal'], 2) ?></span></div>
            <?php if ($sale['discount_amount'] > 0): ?>
                <div class="flex justify-between"><span>Discount</span><span>-P<?= number_format($sale['discount_amount'], 2) ?></span></div>
            <?php endif; ?>
            <div class="flex justify-between"><span>Tax (12%)</span><span>P<?= number_format($sale['tax_amount'], 2) ?></span></div>
            <div class="flex justify-between font-bold text-lg mt-2 pt-1 border-t border-dashed border-black">
                <span>TOTAL</span>
                <span>P<?= number_format($sale['grand_total'], 2) ?></span>
            </div>
        </div>

        <div class="text-center mt-4 text-sm">
            <p class="font-semibold">Thank you for your purchase!</p>
            <p>Please come again.</p>
        </div>
    </div>

    <div class="mt-6 flex justify-center gap-4 no-print">
        <button onclick="window.print()" class="w-48 py-3 bg-blue-600 text-white font-bold rounded-lg hover:bg-blue-700 transition-colors shadow-lg">
            <i class="fas fa-print mr-2"></i> Print Receipt
        </button>
        <a href="includes/transactions.php" class="w-48 py-3 text-center bg-gray-600 text-white font-bold rounded-lg hover:bg-gray-700 transition-colors shadow-lg">
            <i class="fas fa-arrow-left mr-2"></i> Back
        </a>
    </div>

</body>
</html>
