<?php
require_once 'auth_check.php';

// --- NEW: Post/Redirect/Get (PRG) Pattern to prevent form resubmission dialog ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cartData'])) {
    // 1. Save the POST data to the session
    $_SESSION['cartData'] = $_POST['cartData'];
    // 2. Redirect to the same page using a GET request
    header('Location: payment.php');
    exit();
}

// 3. Load data from the session on the GET request
$cartDataJSON = $_SESSION['cartData'] ?? '[]';
// Unset the session data so it's not reused accidentally
unset($_SESSION['cartData']);

$cartItems = json_decode($cartDataJSON, true);

if (empty($cartItems)) {
    header('Location: index1.php');
    exit();
}

$taxRate = 0.12; // 12% VAT

// Get user info from session for the receipt
$cashier_name = htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']);
$cashier_role = htmlspecialchars(ucfirst($_SESSION['role']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Process Payment - DAN-LEN</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-image: url('/siaa/siaaproject/coding/backgrounds/loginbg.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }
        .card { background-color: white; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -2px rgba(0,0,0,0.1); }
        @media print {
            body * { visibility: hidden; }
            #receipt-container, #receipt-container * { visibility: visible; }
            #receipt-container {
                position: absolute;
                left: 0;
                top: 0;
                width: 300px; /* Set a fixed, narrow width for printing */
            }
            .no-print { display: none !important; }
        }
        #receipt-container {
            font-family: 'Courier New', Courier, monospace;
            width: 300px; /* Typical thermal receipt width */
            margin: 0 auto;
        }
        /* Hide number input spinners */
        input[type=number]::-webkit-inner-spin-button,
        input[type=number]::-webkit-outer-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        input[type=number] {
            -moz-appearance: textfield; /* For Firefox */
        }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4 sm:p-6">

    <div id="payment-view" class="w-full max-w-4xl mx-auto grid grid-cols-1 lg:grid-cols-2 gap-8">
        <!-- Order Summary -->
        <div class="card p-6 sm:p-8 order-1 lg:order-1">
            <h2 class="text-2xl font-bold text-gray-900 mb-6 border-b border-gray-200 pb-4">Order Summary</h2>
            <div class="space-y-4 max-h-72 overflow-y-auto pr-3">
                <?php foreach ($cartItems as $item): ?>
                <div class="flex justify-between items-center text-gray-700">
                    <span class="font-medium"><?= htmlspecialchars($item['name']) ?> <span class="text-sm text-gray-400 font-normal">x<?= htmlspecialchars($item['quantity']) ?></span></span>
                    <span class="font-semibold">P<?= number_format($item['total'], 2) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="mt-6 pt-6 border-t-2 border-dashed space-y-4 text-base">
                <div class="flex justify-between text-gray-800">
                    <span class="font-medium">Subtotal</span>
                    <span id="subtotal-display" class="font-semibold">P0.00</span>
                </div>
                <div class="flex justify-between text-gray-600">
                    <span class="font-medium">Tax (12%)</span>
                    <span id="tax-display" class="font-semibold">P0.00</span>
                </div>
                <div class="flex justify-between text-gray-600">
                    <span class="font-medium">Discount</span>
                    <span id="discount-display" class="font-semibold text-red-500">P0.00</span>
                </div>
                <div class="flex justify-between text-gray-900 text-2xl font-bold mt-4 pt-4 border-t border-gray-200">
                    <span>Total</span>
                    <span id="grand-total">P0.00</span>
                </div>
            </div>
        </div>

        <!-- Payment Actions -->
        <div class="card p-6 sm:p-8 order-2 lg:order-2">
            <h2 class="text-2xl font-bold text-gray-900 mb-6 border-b border-gray-200 pb-4">Payment Details</h2>
            <div class="space-y-6">
                <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                    <label for="pwd-count" class="font-medium text-gray-700">Number of Senior/PWD Cards (20% per person)</label>
                    <div class="flex items-center border border-gray-300 rounded-lg">
                        <button id="pwd-decrement" class="px-3 py-1 text-lg font-bold text-gray-600 hover:bg-gray-200 rounded-l-md transition-colors">-</button>
                        <input type="number" id="pwd-count" value="0" min="0" class="w-16 text-center font-semibold text-lg border-l border-r focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <button id="pwd-increment" class="px-3 py-1 text-lg font-bold text-gray-600 hover:bg-gray-200 rounded-r-md transition-colors">+</button>
                    </div>
                </div>
                <div>
                    <label for="cash-tendered" class="block font-medium text-gray-700 mb-2">Payment</label>
                    <input type="number" id="cash-tendered" placeholder="Enter amount received" class="w-full px-4 py-3 text-lg border-2 border-gray-200 bg-gray-50 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 transition-colors">
                </div>
                <div class="bg-indigo-50 p-4 rounded-lg text-center">
                    <p class="text-lg font-medium text-indigo-800">Change</p>
                    <p id="change-display" class="text-4xl font-bold text-indigo-600 mt-1">P0.00</p>
                </div>
            </div>
            <div class="mt-8 flex flex-col gap-4">
                <button id="finalize-payment-btn" class="w-full py-4 bg-indigo-600 text-white font-bold text-lg rounded-lg shadow-lg hover:bg-indigo-700 transition-all duration-300 transform hover:scale-105 disabled:bg-indigo-300 disabled:cursor-not-allowed disabled:scale-100">
                    <i class="fas fa-check-circle mr-2"></i> Finalize Payment
                </button>
                <a href="index1.php" class="w-full py-3 text-center bg-gray-200 text-gray-800 font-semibold rounded-lg hover:bg-gray-300 transition-colors"><i class="fas fa-times-circle mr-2"></i> Cancel</a>
            </div>
        </div>
    </div>

    <!-- Receipt View (hidden by default) -->
    <div id="receipt-view" class="hidden w-full max-w-md mx-auto">
        <div id="receipt-container" class="bg-white p-6 shadow-lg">
            <div class="text-center mb-6">
                <h1 class="text-xl font-bold">DAN-LEN Store</h1>
                <p class="text-xs text-gray-600">6 Block 41 Lot 8 Brgy 176 .C Bagong Silang Caloocan City</p>
                <p id="receipt-date" class="text-sm mt-2"></p>
                <div class="text-xs text-gray-500 mt-1">
                    <p>Served by: <span id="receipt-cashier-name"></span></p>
                    <p>Role: <span id="receipt-cashier-role"></span></p>
                </div>
            </div>
            <div class="border-t border-b border-dashed py-4">
                <div class="flex justify-between font-bold">
                    <span>Item</span>
                    <span>Total</span>
                </div>
                <div id="receipt-items" class="mt-2 space-y-1 text-sm">
                    <!-- Items will be injected here -->
                </div>
            </div>
            <div class="mt-4 space-y-2 text-sm">
                <div class="flex justify-between"><span>Subtotal:</span><span id="receipt-subtotal"></span></div>
                <div class="flex justify-between"><span>Discount:</span><span id="receipt-discount"></span></div>
                <div class="flex justify-between"><span>Tax (12%):</span><span id="receipt-tax"></span></div>
                <div class="flex justify-between font-bold text-lg"><span>Total:</span><span id="receipt-total"></span></div>
                <div class="flex justify-between"><span>Payment:</span><span id="receipt-cash"></span></div>
                <div class="flex justify-between"><span>Change:</span><span id="receipt-change"></span></div>
            </div>
            <div class="text-center mt-6">
                <p class="text-sm font-semibold">Thank you for your purchase!</p>
            </div>
        </div>
        <div class="mt-6 flex gap-4 no-print">
            <button onclick="window.print()" class="w-full py-3 bg-blue-600 text-white font-bold rounded-lg hover:bg-blue-700"><i class="fas fa-print mr-2"></i> Print Receipt</button>
            <a href="index1.php" class="w-full py-3 text-center bg-gray-600 text-white font-bold rounded-lg hover:bg-gray-700"><i class="fas fa-plus-circle mr-2"></i> New Transaction</a>
        </div>
    </div>

    <!-- Modal for messages -->
    <div id="modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center p-4 z-50">
        <div class="bg-white rounded-xl shadow-2xl p-6 max-w-sm w-full text-center">
            <h3 id="modal-title" class="text-xl font-bold mb-4"></h3>
            <p id="modal-message" class="text-gray-600 mb-6"></p>
            <button id="modal-close-btn" class="w-full py-2 bg-indigo-500 text-white rounded-full font-semibold hover:bg-indigo-600">Close</button>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const cartItems = <?= $cartDataJSON ?>;
            const taxRate = <?= $taxRate ?>;
            const cashierName = '<?= $cashier_name ?>';
            const cashierRole = '<?= $cashier_role ?>';

            const pwdCountInput = document.getElementById('pwd-count');
            const cashTenderedInput = document.getElementById('cash-tendered');
            const subtotalDisplay = document.getElementById('subtotal-display');
            const discountDisplay = document.getElementById('discount-display');
            const taxDisplay = document.getElementById('tax-display');
            const grandTotalDisplay = document.getElementById('grand-total');
            const changeDisplay = document.getElementById('change-display');
            const finalizeBtn = document.getElementById('finalize-payment-btn');

            let discountAmount = 0;
            let taxAmount = 0;
            let grandTotal = 0;
            let subtotalForCalc = 0; // Declare here to make it accessible in the finalizeBtn listener

            function formatCurrency(amount) {
                return `P${amount.toFixed(2)}`;
            }

            function calculateTotals() {
                const totalFromCart = cartItems.reduce((acc, item) => acc + item.total, 0);
                subtotalForCalc = totalFromCart; // Assign to the outer scope variable
                const pwdCount = parseInt(pwdCountInput.value) || 0;

                if (pwdCount > 0) {
                    // Calculate discount from the subtotal.
                    // Each card gives a 20% discount.
                    discountAmount = subtotalForCalc * (0.20 * pwdCount);
                } else {
                    discountAmount = 0; // No discount
                }

                // Tax is calculated on the subtotal.
                taxAmount = subtotalForCalc * taxRate;
                grandTotal = (subtotalForCalc + taxAmount) - discountAmount;

                subtotalDisplay.textContent = formatCurrency(subtotalForCalc);
                taxDisplay.textContent = formatCurrency(taxAmount);
                discountDisplay.textContent = formatCurrency(discountAmount);
                grandTotalDisplay.textContent = formatCurrency(grandTotal);

                calculateChange();
            }

            function calculateChange() {
                const cashTendered = parseFloat(cashTenderedInput.value) || 0;
                const change = cashTendered - grandTotal;
                changeDisplay.textContent = formatCurrency(change > 0 ? change : 0);
            }

            // --- PWD Discount Stepper Logic ---
            document.getElementById('pwd-decrement').addEventListener('click', () => {
                let count = parseInt(pwdCountInput.value) || 0;
                if (count > 0) {
                    pwdCountInput.value = count - 1;
                    calculateTotals();
                }
            });
            document.getElementById('pwd-increment').addEventListener('click', () => {
                let count = parseInt(pwdCountInput.value) || 0;
                pwdCountInput.value = count + 1;
                calculateTotals();
            });
            pwdCountInput.addEventListener('input', calculateTotals);


            cashTenderedInput.addEventListener('input', calculateChange);

            finalizeBtn.addEventListener('click', async function() {
                this.disabled = true;
                this.textContent = 'Processing...';

                // 1. Check for sufficient cash tendered
                const cashTendered = parseFloat(cashTenderedInput.value) || 0;
                if (cashTendered < grandTotal) {
                    showModal('Payment Error', 'Insufficient money. The cash tendered is less than the grand total.');
                    this.disabled = false;
                    this.textContent = 'Finalize Payment';
                    return;
                }

                // 2. Perform a final stock check before processing
                const stockCheckResponse = await fetch('api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'check_stock', items: cartItems })
                });
                const stockResult = await stockCheckResponse.json();

                if (!stockResult.success) {
                    showModal('Stock Error', stockResult.message);
                    this.disabled = false;
                    this.textContent = 'Finalize Payment';
                    return;
                }

                const saleData = {
                    action: 'process_sale',
                    subtotal: subtotalForCalc,
                    tax_amount: taxAmount,
                    discount_amount: discountAmount,
                    grand_total: grandTotal,
                    items: cartItems
                };

                try {
                    const response = await fetch('api.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(saleData)
                    });
                    const result = await response.json();

                    if (result.success) {
                        showReceipt(saleData);
                    } else {
                        showModal('Error', result.message || 'Could not process payment.');
                        this.disabled = false;
                        this.textContent = 'Finalize Payment';
                    }
                } catch (error) {
                    showModal('Connection Error', 'Could not connect to the server.');
                    this.disabled = false;
                    this.textContent = 'Finalize Payment';
                }
            });

            function showReceipt(saleData) {
                // Hide payment view, show receipt view
                document.getElementById('payment-view').classList.add('hidden');
                document.getElementById('receipt-view').classList.remove('hidden');

                // Populate receipt details
                document.getElementById('receipt-date').textContent = new Date().toLocaleString();
                document.getElementById('receipt-cashier-name').textContent = cashierName;
                document.getElementById('receipt-cashier-role').textContent = cashierRole;
                const receiptItemsContainer = document.getElementById('receipt-items');
                receiptItemsContainer.innerHTML = '';
                saleData.items.forEach(item => {
                    const itemEl = document.createElement('div');
                    itemEl.className = 'flex justify-between';
                    itemEl.innerHTML = `
                        <span>${item.quantity}x ${item.name}</span>
                        <span>${formatCurrency(item.total)}</span>
                    `;
                    receiptItemsContainer.appendChild(itemEl);
                });

                document.getElementById('receipt-subtotal').textContent = formatCurrency(saleData.subtotal);
                document.getElementById('receipt-discount').textContent = formatCurrency(saleData.discount_amount);
                document.getElementById('receipt-tax').textContent = formatCurrency(saleData.tax_amount);
                document.getElementById('receipt-total').textContent = formatCurrency(saleData.grand_total);
                
                const cash = parseFloat(cashTenderedInput.value) || 0;
                const change = cash - saleData.grand_total;
                document.getElementById('receipt-cash').textContent = formatCurrency(cash);
                document.getElementById('receipt-change').textContent = formatCurrency(change > 0 ? change : 0);
            }

            // Modal handling
            const modal = document.getElementById('modal');
            const modalTitle = document.getElementById('modal-title');
            const modalMessage = document.getElementById('modal-message');
            const modalCloseBtn = document.getElementById('modal-close-btn');

            function showModal(title, message) {
                modalTitle.textContent = title;
                modalMessage.textContent = message;
                modal.classList.remove('hidden');
            }
            modalCloseBtn.addEventListener('click', () => modal.classList.add('hidden'));

            // Initial calculation
            calculateTotals();
        });
    </script>
</body>
</html>