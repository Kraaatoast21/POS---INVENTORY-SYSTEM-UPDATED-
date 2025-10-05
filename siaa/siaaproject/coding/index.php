<?php
// No auth_check, this is a public page
require_once 'db_connect.php'; // Connects to the database

// Fetch all available products from the database for display
$product_result = $conn->query("
    SELECT p.id, p.name, p.price, p.quantity, p.image_url, GROUP_CONCAT(c.slug) as categories
    FROM products p
    LEFT JOIN product_categories pc ON p.id = pc.product_id
    LEFT JOIN categories c ON pc.category_id = c.id
    WHERE p.quantity > 0 AND p.is_active = 1
    GROUP BY p.id
    ORDER BY p.name ASC");
$products_from_db = [];
if ($product_result && $product_result->num_rows > 0) {
    while ($row = $product_result->fetch_assoc()) {
        $products_from_db[] = $row;
    }
}

// Fetch categories for the sidebar
$category_result = $conn->query("SELECT name, slug FROM categories ORDER BY name ASC");
$categories_from_db = [];
if ($category_result && $category_result->num_rows > 0) {
    $categories_from_db = $category_result->fetch_all(MYSQLI_ASSOC);
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DAN-LEN</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6;
            overflow-x: hidden; /* Prevents horizontal scrollbar */
        }
        .product-card {
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .sidebar-item {
            display: flex;
            align-items: center;
            padding: 1rem 1.5rem;            margin-bottom: 0.75rem; /* Increased from 0.5rem */
            border-radius: 0.75rem;
            font-weight: 500;
            color: #ffffff;
            transition: background-color 0.2s, color 0.2s, transform 0.2s ease-in-out;
        }

        .sidebar-sub-item {
            display: block;
            padding: 0.5rem 1.5rem 0.5rem 3rem;
            margin-bottom: 0.25rem;
            border-radius: 0.75rem;
            font-weight: 500;
            color: #ffffff; /* White */
            transition: background-color 0.2s, color 0.2s, transform 0.2s ease-in-out;
        }
        .sidebar-item:hover,
        .sidebar-sub-item:hover {
            background-color: #111827; /* Near black */
            color: #ffffff; /* White */
            transform: scale(1.03); /* Add a subtle scale effect */
        }
        .sidebar-item.active,
        .sidebar-sub-item.active {
            background-color: #111827; /* Near black */
            color: white;
        }
        #sidebar {
            transform: translateX(-100%);
            transition: transform 0.3s ease-in-out;
            z-index: 50;
        }
        #sidebar.open {
            transform: translateX(0);
        }
        .sidebar-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 40;
            display: none;
        }
        .sidebar-backdrop.show {
            display: block;
        }
    </style>
</head>

<body class="bg-gray-100 min-h-screen flex flex-col">
    <!-- Navbar -->
    <nav class="bg-indigo-700 text-white shadow-md p-4">
        <div class="w-full grid grid-cols-3 items-center">
            <!-- Left Side: Hamburger -->
            <div class="justify-self-start">
                <button id="menu-toggle-btn" class="text-white focus:outline-none mr-4">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                        xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 6h16M4 12h16m-7 6h7"></path>
                    </svg>
                </button>
            </div>

            <!-- Center: Search Bar -->
            <div class="relative justify-self-center w-full max-w-xs sm:max-w-sm md:max-w-md">
                <input id="search-bar" type="text" placeholder="Search products..." class="w-full py-2 pl-10 pr-4 rounded-full text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-300 transition-all duration-200">
                <svg class="absolute left-3 top-1/2 transform -translate-y-1/2 h-5 w-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
            </div>

            <!-- Right Side: Login -->
            <div class="justify-self-end">
                <a href="login.php" class="flex items-center gap-2 bg-white hover:bg-gray-200 text-indigo-700 font-bold py-2 px-4 rounded-lg transition-colors duration-200 shadow-md">
                    <i class="fas fa-sign-in-alt"></i>
                    <span>Login</span>
                </a>
            </div>
        </div>
    </nav>

    <!-- Sidebar container with a fixed position -->
    <div id="sidebar" class="fixed top-0 left-0 h-full w-64 md:w-80 bg-gray-800 text-gray-300 p-4 flex flex-col">
        <div class="flex items-center justify-between mb-6">
            <div class="text-lg font-bold text-white">Menu</div>
        </div>
        <nav class="flex-grow">
            <a href="#" class="sidebar-item active" data-view="dashboard" data-category="all">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-3" viewBox="0 0 20 20" fill="currentColor">
                    <path
                        d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z" />
                </svg>
                <span>Dashboard</span>
            </a>
            <a href="#" class="sidebar-item" data-view="transactions">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-3" fill="none" viewBox="0 0 24 24"
                    stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                </svg>
                <span>Transactions</span>
            </a>
            <!-- Categories Section -->
            <div class="my-2">
                <a href="#" class="sidebar-item" id="categories-toggle">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-3" viewBox="0 0 20 20"
                        fill="currentColor">
                        <path d="M7 3a1 1 0 000 2h6a1 1 0 100-2H7z" />
                        <path fill-rule="evenodd" d="M3 6a1 1 0 011-1h12a1 1 0 011 1v10a1 1 0 01-1 1H4a1 1 0 01-1-1V6z"
                            clip-rule="evenodd" />
                    </svg>
                    <span>Categories</span>
                    <svg id="categories-arrow" class="h-5 w-5 ml-auto transform transition-transform" fill="none"
                        stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7">
                        </path>
                    </svg>
                </a>
                <!-- Sub-categories (initially hidden) -->
                <div id="sub-categories-menu" class="hidden">
                </div>
            </div>
        </nav>
    </div>
    <div id="sidebar-backdrop" class="sidebar-backdrop"></div>

    <!-- Main Content Area -->
    <div id="main-content"
        class="flex-grow p-2 sm:p-4 space-y-4 md:space-y-0 md:space-x-4 flex flex-col md:flex-row">
        <!-- Products Section (Initially visible) -->
        <div id="dashboard-view" class="bg-white rounded-2xl shadow-lg p-2 sm:p-6 flex-grow overflow-hidden flex flex-col min-h-[400px] md:min-h-0">
            <h2 class="text-2xl font-semibold text-gray-800 mb-4">Products</h2>
            <div class="relative flex-grow overflow-hidden">
                <div id="products-grid"
                    class="absolute inset-0 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 sm:gap-4 overflow-y-auto pr-2">
                    <!-- Product cards will be generated here -->
                </div>
            </div>
        </div>

        <!-- Transactions Section (Initially hidden) -->
        <div id="transactions-view"
            class="bg-white rounded-2xl shadow-lg p-6 flex-grow overflow-hidden flex-col hidden">
            <h2 class="text-2xl font-semibold text-gray-800 mb-4">Transaction History</h2>
            <div class="relative flex-grow overflow-hidden">
                <div id="transactions-list" class="absolute inset-0 overflow-y-auto pr-2 flex items-center justify-center">
                    <p class="text-center text-gray-500">Please log in to view transaction history.</p>
                </div>
            </div>
        </div>

        <!-- Cart Section -->
        <div class="w-full md:w-96 bg-white rounded-2xl shadow-lg p-4 sm:p-6 flex flex-col md:overflow-hidden">
            <div>
                <!-- New Date/Time/Cashier Card inside the Cart Section -->
                <div class="bg-indigo-600 text-white rounded-xl shadow-md p-4 mb-4">
                    <div class="text-sm font-medium"><span class="font-bold" id="cashierName"></span>
                    </div>
                    <div class="flex flex-col mt-2">
                        <div id="dayDisplay" class="text-sm font-semibold"></div>
                        <div id="dateDisplay" class="text-lg font-semibold"></div>
                        <div id="timeDisplay" class="text-2xl font-bold"></div>
                    </div>
                </div>

                <!-- Scanner Toggle and Input from index1.php -->
                <div class="mb-4 space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-gray-700">Enable Automatic Scanner</span>
                        <label for="scanner-toggle" class="inline-flex relative items-center cursor-pointer">
                            <input type="checkbox" id="scanner-toggle" class="sr-only peer">
                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                        </label>
                    </div>
                    <div id="barcode-scanner-wrapper" class="hidden">
                        <label for="barcode-scanner" class="sr-only">Automatic Barcode Scanner</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-barcode text-gray-400"></i>
                            </div>
                            <input type="text" id="barcode-scanner" placeholder="Scan automatically" class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                        </div>
                    </div>
                </div>

                <!-- Manual Barcode Entry from index1.php -->
                <div class="mb-4 space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-gray-700">Enable Manual Entry</span>
                        <label for="manual-entry-toggle" class="inline-flex relative items-center cursor-pointer">
                            <input type="checkbox" id="manual-entry-toggle" class="sr-only peer">
                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                        </label>
                    </div>
                    <div id="manual-barcode-wrapper" class="hidden">
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-keyboard text-gray-400"></i>
                            </div>
                            <input type="text" id="manual-barcode-input" placeholder="Type barcode and press Enter" class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                        </div>
                    </div>
                </div>

                <h2 class="text-2xl font-semibold text-gray-800 mb-4">Current Order</h2>
            </div>

            <!-- Wrapper for scrollable cart content -->
            <div class="flex-grow flex flex-col overflow-hidden">
                <!-- Column Headings for the Cart -->
                <div class="flex-shrink-0 flex justify-between items-center px-1 pb-2 border-b border-gray-200 text-xs font-bold text-gray-500 uppercase">
                    <div class="flex-grow flex items-center space-x-2">
                        <span class="flex-grow">Product Name</span>
                        <span class="w-16 text-center">Quantity</span>
                    </div>
                    <div class="w-20 text-right pr-4">
                        <!-- Price heading placeholder -->
                    </div>
                </div>
                <!-- This div will grow and scroll -->
                <div id="cart-items" class="flex-grow overflow-y-auto border-b border-gray-200 pb-4">
                    <!-- Cart items will be generated here -->
                </div>
            </div>

            <!-- This div contains the totals and payment button, which will be fixed at the bottom -->
            <div class="mt-auto pt-4 space-y-4">
                <button onclick="showModal('Login Required', 'Please log in to remove items from the cart.');" class="w-full py-2 px-4 border border-red-300 rounded-lg text-sm font-semibold text-red-600 bg-red-50 hover:bg-red-100 transition-colors">
                    Remove Items
                </button>
                <div class="text-lg font-medium text-gray-700 space-y-2 pt-2 border-t border-gray-200">
                    <div class="flex justify-between text-xl font-bold text-gray-900">
                        <span>Total:</span>
                        <span id="totalDisplay">P0.00</span>
                    </div>
                </div>

                <button id="process-payment-btn"
                    class="mt-6 w-full py-3 bg-indigo-500 text-white rounded-full font-bold text-lg shadow-lg hover:bg-indigo-600 transition-colors">
                    Process Payment
                </button>
            </div>

            <!-- Custom Modal UI for messages -->
            <div id="modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center p-4">
                <div class="bg-white rounded-xl shadow-2xl p-6 max-w-sm w-full text-center">
                    <h3 id="modal-title" class="text-xl font-bold mb-4"></h3>
                    <p id="modal-message" class="text-gray-600 mb-6"></p>
                    <button id="modal-close-btn"
                        class="w-full py-2 bg-indigo-500 text-white rounded-full font-semibold hover:bg-indigo-600">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Products are loaded from the database for display
        const products = <?php echo json_encode($products_from_db); ?>;
        const categories = <?php echo json_encode($categories_from_db); ?>;

        let cartItems = [];
        const TAX_RATE = 0.12;
        let currentCategory = 'all';
        const cashierName = 'Guest'; // Set to Guest for public view

        // UI elements
        const menuToggleBtn = document.getElementById('menu-toggle-btn');
        const sidebar = document.getElementById('sidebar');
        const sidebarBackdrop = document.getElementById('sidebar-backdrop');
        const productsGrid = document.getElementById('products-grid');
        const cartItemsContainer = document.getElementById('cart-items');
        const totalDisplay = document.getElementById('totalDisplay');
        const processPaymentBtn = document.getElementById('process-payment-btn');
        const modal = document.getElementById('modal');
        const modalTitle = document.getElementById('modal-title');
        const modalMessage = document.getElementById('modal-message');
        const modalCloseBtn = document.getElementById('modal-close-btn');
        const searchBar = document.getElementById('search-bar');
        const dashboardView = document.getElementById('dashboard-view');
        const transactionsView = document.getElementById('transactions-view');
        const transactionsList = document.getElementById('transactions-list');
        const dayDisplay = document.getElementById('dayDisplay');
        const dateDisplay = document.getElementById('dateDisplay');
        const timeDisplay = document.getElementById('timeDisplay');
        const cashierNameDisplay = document.getElementById('cashierName');

        // Scanner UI elements
        const scannerToggle = document.getElementById('scanner-toggle');
        const barcodeScannerWrapper = document.getElementById('barcode-scanner-wrapper');
        const manualEntryToggle = document.getElementById('manual-entry-toggle');
        const manualBarcodeWrapper = document.getElementById('manual-barcode-wrapper');


        const categoriesToggle = document.getElementById('categories-toggle');
        const subCategoriesMenu = document.getElementById('sub-categories-menu');
        const categoriesArrow = document.getElementById('categories-arrow');
        const sidebarItems = document.querySelectorAll('.sidebar-item, .sidebar-sub-item');

        // Function to show a custom modal
        function showModal(title, message) {
            modalTitle.textContent = title;
            modalMessage.textContent = message;
            modal.classList.remove('hidden');
        }

        // Function to update the date and time
        function updateDateTime() {
            const now = new Date();
            const dateOptions = { year: 'numeric', month: 'long', day: 'numeric' };
            const timeOptions = { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
            const dayOptions = { weekday: 'long' };

            const formattedDate = now.toLocaleDateString('en-US', dateOptions);
            const formattedTime = now.toLocaleTimeString('en-US', timeOptions);
            dayDisplay.textContent = now.toLocaleDateString('en-US', dayOptions);
            dateDisplay.textContent = formattedDate;
            timeDisplay.textContent = formattedTime;
        }

        // --- MODIFIED FOR PUBLIC PAGE: Scanner toggles show a modal ---
        function handleGuestToggle(event) {
            event.target.checked = false; // Prevent the toggle from changing state
            showModal('Login Required', 'Please log in to use the scanner features.');
        }

        scannerToggle.addEventListener('change', handleGuestToggle);
        manualEntryToggle.addEventListener('change', handleGuestToggle);

        // Also prevent typing in the input fields if they somehow become visible
        document.getElementById('barcode-scanner').addEventListener('keydown', (e) => e.preventDefault());
        document.getElementById('manual-barcode-input').addEventListener('keydown', (e) => e.preventDefault());
        function processBarcode() {
            showModal('Login Required', 'Please log in to scan items.');
        }

        // --- MODIFIED FOR PUBLIC PAGE: All actions requiring login show a modal ---

        // MODIFIED: Sidebar is disabled for guests.
        function openSidebar() {
            showModal('Login Required', 'Please log in to access the menu.');
        }

        menuToggleBtn.addEventListener('click', openSidebar);

        // Dynamically build category sub-menu
        categories.forEach(cat => {
            const link = document.createElement('a');
            link.href = '#';
            link.className = 'sidebar-sub-item';
            link.dataset.view = 'dashboard';
            link.dataset.category = cat.slug;
            link.textContent = cat.name;
            subCategoriesMenu.appendChild(link);
        });

        function showView(viewName) {
            dashboardView.classList.add('hidden');
            transactionsView.classList.add('hidden');

            if (viewName === 'dashboard') {
                dashboardView.classList.remove('hidden');
                dashboardView.classList.add('flex');
            } else if (viewName === 'transactions') {
                transactionsView.classList.remove('hidden');
                transactionsView.classList.add('flex');
            }
        }

        searchBar.addEventListener('input', () => {
            sidebarItems.forEach(i => i.classList.remove('active'));

            showView('dashboard');
            const searchTerm = searchBar.value.toLowerCase();
            const filteredProducts = products.filter(product =>
                product.name.toLowerCase().includes(searchTerm)
            );
            renderProducts(filteredProducts);
        });

        function renderProducts(productsToRender = null) {
            productsGrid.innerHTML = '';
            const listToRender = productsToRender || (currentCategory === 'all'
                ? products
                : products.filter(p => p.categories && p.categories.split(',').includes(currentCategory)));

            listToRender.forEach(product => {
                const card = document.createElement('div');
                product.id = parseInt(product.id);
                product.price = parseFloat(product.price);
                product.quantity = parseInt(product.quantity);

                card.className = 'product-card bg-white p-3 rounded-xl shadow-md flex flex-row items-center text-left sm:flex-col sm:p-4 sm:text-center';
                card.innerHTML = `
                    <img src="${product.image_url || 'https://placehold.co/150x150/e2e8f0/a0aec0?text=N/A'}" alt="${product.name}" class="rounded-lg w-16 h-16 sm:w-full sm:h-auto aspect-square object-cover mr-4 sm:mr-0 sm:mb-2">
                    <div class="flex-grow">
                        <span class="font-semibold text-gray-800 w-full truncate">${product.name}</span>
                        <div class="flex flex-col sm:items-center mt-1 text-sm">
                            <span class="text-gray-800 font-bold">P${product.price.toFixed(2)}</span>
                            <span class="text-xs font-medium text-gray-500 mt-0.5">Stock: ${product.quantity}</span>
                        </div>
                    </div>
                `;
                card.onclick = () => showModal('Login Required', 'Please log in or register to add items to the cart.');
                productsGrid.appendChild(card);
            });
        }

        // MODIFIED: Adding to cart is disabled
        function addToCart(productToAdd) {
            showModal('Login Required', 'Please log in or register to add items to the cart.');
        }

        function renderCart() {
            cartItemsContainer.innerHTML = '<p class="text-center text-gray-500 mt-4">Please log in to view your cart.</p>';
            totalDisplay.textContent = `P0.00`;
        }

        // MODIFIED: Payment is disabled
        processPaymentBtn.addEventListener('click', () => {
            showModal('Login Required', 'Please log in or register to process a payment.');
        });

        modalCloseBtn.addEventListener('click', () => {
            modal.classList.add('hidden');
        });

        cashierNameDisplay.textContent = cashierName;
        renderProducts();
        renderCart();
        updateDateTime();
        setInterval(updateDateTime, 1000);
    </script>
</body>

</html>