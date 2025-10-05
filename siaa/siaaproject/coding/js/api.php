<?php
header('Content-Type: application/json');
require_once 'auth_check.php';
require_once 'db_connect.php';

$response = ['success' => false, 'message' => 'Invalid request.'];

// Get the request body
$input = json_decode(file_get_contents('php://input'), true);

if (isset($input['action'])) {
    switch ($input['action']) {
        case 'get_transaction_details':
            if (isset($input['transaction_id'])) {
                $transactionId = (int)$input['transaction_id'];
                
                // --- Fetch Sale Details (Subtotal, Tax, Discount) ---
                $detailsSql = "SELECT subtotal, tax_amount, discount_amount FROM sales WHERE id = ?";
                $detailsStmt = $conn->prepare($detailsSql);
                $detailsStmt->bind_param("i", $transactionId);
                $detailsStmt->execute();
                $detailsResult = $detailsStmt->get_result();
                $saleDetails = $detailsResult->fetch_assoc();
                $detailsStmt->close();

                // --- Fetch Sale Items ---
                $itemsSql = "SELECT si.quantity, si.price, p.name AS product_name
                             FROM sale_items si
                             JOIN products p ON si.product_id = p.id
                             WHERE si.sale_id = ?";
                
                $itemsStmt = $conn->prepare($itemsSql);
                $itemsStmt->bind_param("i", $transactionId);
                $itemsStmt->execute();
                $itemsResult = $itemsStmt->get_result();
                $items = $itemsResult->fetch_all(MYSQLI_ASSOC);
                $itemsStmt->close();

                if ($items) {
                    $response = [
                        'success' => true,
                        'items' => $items,
                        'details' => $saleDetails // Include subtotal, tax, etc.
                    ];
                } else {
                    $response['message'] = 'No items found for this transaction.';
                }
            } else {
                $response['message'] = 'Transaction ID not provided.';
            }
            break;

        default:
            $response['message'] = 'Unknown action.';
            break;
    }
}

$conn->close();
echo json_encode($response);
exit();
?>