<?php
require_once 'auth_check.php'; // Ensures user is logged in
require_once 'db_connect.php'; // Connects to the database

// Fetch ALL products from the database, including their categories
$product_result = $conn->query("SELECT p.id, p.name, p.price, p.discount_percentage, p.quantity, p.image_url, GROUP_CONCAT(c.slug) as categories
    FROM products p
    LEFT JOIN product_categories pc ON p.id = pc.product_id
    LEFT JOIN categories c ON pc.category_id = c.id
    WHERE p.is_active = 1
    GROUP BY p.id
    ORDER BY p.name ASC
");
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

// Get the profile image URL from the session.
// The path in the session is relative (e.g., 'uploads/profile.png').
// We need to check if it exists and is not empty.
$profile_image_path = $_SESSION['image_url'] ?? null;
if (!empty($profile_image_path) && file_exists($profile_image_path)) {
    // If it exists, use it. Add a timestamp to prevent browser caching issues.
    $profile_image_url = htmlspecialchars($profile_image_path) . '?t=' . time();
} else {
    // Fallback to UI Avatars if no image is set or the file is missing.
    $profile_image_url = 'https://ui-avatars.com/api/?name=' . urlencode($_SESSION['first_name'] . ' ' . $_SESSION['last_name']) . '&background=6366f1&color=fff&bold=true';
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
        .product-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1);
        }
        .product-card.disabled {
            opacity: 0.6;
            cursor: not-allowed;
            filter: grayscale(100%);
        }
        .product-card.disabled:hover {
            transform: none;
        }
        /* Custom styles for the sidebar and main content to ensure proper overflow */
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

        /* Notification styles */
        .notification {
            position: fixed;
            top: 1.5rem; /* Position from the top */
            left: 50%;
            background-color: #22c55e; /* green-500 */
            color: white;
            padding: 1rem 2rem; /* Make it bigger */
            border-radius: 9999px; /* Pill shape */
            display: flex; /* For icon alignment */
            align-items: center; /* For icon alignment */
            font-size: 1.125rem; /* Larger text */
            font-weight: 600;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            opacity: 0;
            transition: opacity 0.3s ease-in-out, transform 0.3s ease-in-out;
            transform: translateX(-50%) translateY(-50px); /* Start off-screen */
            z-index: 1000;
        }
        .notification.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0); /* Slide into view */
        }

        /* Enhanced Avatar Hover */
        #profile-avatar-btn img {
            transition: box-shadow 0.3s ease, transform 0.3s ease;
        }
        #profile-avatar-btn:hover img {
            transform: scale(1.05);
            box-shadow: 0 0 15px rgba(255, 255, 255, 0.6);
        }

        /* Notification Badge */
        .notification-badge {
            position: absolute; top: -2px; right: -2px; width: 0.75rem; height: 0.75rem; background-color: #ef4444; border-radius: 9999px;
        }

        /* New Dropdown Styles */
        .dropdown {
            position: absolute;
            right: 0;
            margin-top: 0.5rem;
            background-color: white;
            background-color: rgba(255, 255, 255, 0.8); /* Semi-transparent for blur effect */
            backdrop-filter: blur(10px); /* Background blur */
            border-radius: 0.5rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1); /* Softer shadow */
            z-index: 50;
            opacity: 0;
            transform: translateY(-10px);
            visibility: hidden;
            transition: opacity 0.2s ease-out, transform 0.2s ease-out, visibility 0.2s;
        }
        .dropdown.show {
            opacity: 1;
            transform: translateY(0);
            visibility: visible;
        }
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 0.75rem; /* space between icon and text */
            padding: 0.75rem 1rem;
            color: #111827; /* Near black */
            text-decoration: none;
            transition: background-color 0.2s;
            border-radius: 0.375rem; /* rounded corners for hover */
        }
        .dropdown-item:hover {
            background-color: #f3f4f6; /* bg-gray-100 */
        }
        .dropdown-divider {
            border-top: 1px solid #e5e7eb; /* border-gray-200 */
        }

        /* Style to hide number input spinners */
        input[type=number]::-webkit-inner-spin-button,
        input[type=number]::-webkit-outer-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        input[type=number] {
            -moz-appearance: textfield; /* For Firefox */
        }

        /* Fix for notification dropdown layout */
        #notification-dropdown {
            display: flex;
            flex-direction: column;
        }

    </style>
</head>
<body class="bg-gray-100 min-h-screen flex flex-col">
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

            <!-- Right Side: Notifications and Profile -->
            <div class="justify-self-end flex items-center gap-4">
                <!-- Notification Bell -->
                <div class="relative" x-data="{ open: false }">
                    <button id="notification-bell-btn" class="text-white hover:text-gray-200 transition-colors">
                        <i class="fas fa-bell fa-lg"></i>
                        <span id="notification-badge" class="notification-badge hidden"></span>
                    </button>
                    <div id="notification-dropdown" class="dropdown w-80 p-2">
                        <!-- Notifications will be rendered here -->
                    </div>
                </div>
 
                <!-- Profile Avatar -->
                <div class="relative">
                    <button id="profile-avatar-btn" class="block">
                        <img id="profile-avatar-img" src="<?= $profile_image_url ?>" alt="Profile" class="w-10 h-10 rounded-full border-2 border-white/50 object-cover">
                    </button>
                    <div id="profile-dropdown" class="dropdown w-48 p-2">
                        <a href="profile.php" class="dropdown-item">
                            <i class="fas fa-user-cog w-5 text-center text-gray-500"></i>
                            <span>Profile/Settings</span>
                        </a>
                        <a href="logout.php" class="dropdown-item text-red-600">
                            <i class="fas fa-sign-out-alt w-5 text-center"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Sidebar container with a fixed position -->
    <div id="sidebar" class="fixed top-0 left-0 h-full w-64 md:w-80 bg-indigo-700 text-gray-300 p-4 flex flex-col">
        <div class="flex items-center justify-between mb-6">
            <div class="text-lg font-bold text-white">Menu</div>
        </div>
        <nav class="flex-grow">
                <a href="#" class="sidebar-item active" data-view="dashboard" data-category="all">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-3" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M5 3a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2V5a2 2 0 00-2-2H5zm0 2h10v10H5V5z" clip-rule="evenodd" />
                        <path d="M7 7h6v2H7V7z" />
                    </svg>
                    <span>Dashboard</span>
                </a>
                <?php if ($_SESSION['role'] === 'admin'): ?>
                <a href="dashboard.php" class="sidebar-item">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-3" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M5 8a1 1 0 00-1 1v1a1 1 0 001 1h1v3a1 1 0 001 1h8a1 1 0 001-1v-3h1a1 1 0 001-1V9a1 1 0 00-1-1H5z" />
                        <path d="M3 3a1 1 0 000 2h14a1 1 0 100-2H3z" />
                    </svg>
                    <span>Inventory</span>
                </a>
            <?php endif; ?>
            <?php if ($_SESSION['role'] === 'cashier'): ?>
                <a href="includes/notifications.php" class="sidebar-item">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-3" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M10 2a6 6 0 00-6 6v3.586l-1.707 1.707A1 1 0 003 15h14a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z" />
                    </svg>
                    <span>Notifications</span>
                </a>
            <?php endif; ?>
            <a href="includes/transactions.php" class="sidebar-item">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-3" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M4 4a2 2 0 00-2 2v4a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2H4zm0 10a2 2 0 00-2 2v4a2 2 0 002 2h12a2 2 0 002-2v-4a2 2 0 00-2-2H4z" clip-rule="evenodd" />
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
    <!-- Main Content Area - Make it a flex container that doesn't scroll on large screens -->
    <div id="main-content" class="flex-grow p-2 sm:p-4 flex flex-col md:flex-row md:space-x-4 md:overflow-hidden">
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

                <!-- NEW: Scanner Toggle and Input -->
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

                <!-- NEW: Manual Barcode Entry -->
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

            <!-- NEW: Wrapper for scrollable cart content -->
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
                <!-- NEW: Batch Removal Controls -->
                <div id="removal-controls-container">
                    <!-- Normal State Button -->
                    <div id="start-removal-wrapper">
                        <button onclick="toggleRemovalMode(true)" class="w-full py-2 px-4 border border-red-300 rounded-lg text-sm font-semibold text-red-600 bg-red-50 hover:bg-red-600 hover:text-white transition-colors">
                            Remove Items
                        </button>
                    </div>
                    <!-- Removal Mode Buttons -->
                    <div id="confirm-removal-wrapper" class="hidden flex gap-2">
                        <button onclick="confirmBatchRemoval()" class="w-full py-2 px-4 rounded-lg text-sm font-semibold text-white bg-red-600 hover:bg-red-700 transition-colors">Confirm Removal</button>
                        <button onclick="toggleRemovalMode(false)" class="w-full py-2 px-4 rounded-lg text-sm font-semibold text-gray-700 bg-gray-200 hover:bg-gray-900 hover:text-white">Cancel</button>
                    </div>
                </div>
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
                        class="w-full py-2 bg-indigo-500 text-white rounded-full font-semibold hover:bg-gray-900">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Notification placeholder -->
    <div class="notification" id="notification"></div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const products = <?php echo json_encode($products_from_db); ?>;
        const categories = <?php echo json_encode($categories_from_db); ?>;

        // This will be populated from the database in a future step
        let transactionHistory = [];

        let isRemovalMode = false; // State for batch removal
        let cartItems = [];
        const TAX_RATE = 0.12;
        let currentCategory = 'all';
        const cashierName = '<?php
            $role = $_SESSION['role'] ?? 'user'; // Default to 'user' if role is not set
            if ($role === 'admin') {
                echo 'Admin: ' . htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']);
            } elseif ($role === 'cashier') {
                echo 'Cashier: ' . htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']);
            } else {
                echo 'User: ' . htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']);
            }
        ?>'; // Get cashier name from session
        const userRole = '<?= $_SESSION['role'] ?? 'user' ?>';

        // UI elements
        const menuToggleBtn = document.getElementById('menu-toggle-btn');
        const sidebar = document.getElementById('sidebar');
        const sidebarBackdrop = document.getElementById('sidebar-backdrop');
        const cartItemsContainer = document.getElementById('cart-items');
        const totalDisplay = document.getElementById('totalDisplay');
        const startRemovalWrapper = document.getElementById('start-removal-wrapper');
        const confirmRemovalWrapper = document.getElementById('confirm-removal-wrapper');


        const processPaymentBtn = document.getElementById('process-payment-btn');
        const modal = document.getElementById('modal');
        const modalTitle = document.getElementById('modal-title');
        const modalMessage = document.getElementById('modal-message');
        const modalCloseBtn = document.getElementById('modal-close-btn');
        const notification = document.getElementById('notification');
        const barcodeScannerInput = document.getElementById('barcode-scanner');
        const searchBar = document.getElementById('search-bar');
        const manualBarcode_input = document.getElementById('manual-barcode-input');
        const scannerToggle = document.getElementById('scanner-toggle');
        const barcodeScannerWrapper = document.getElementById('barcode-scanner-wrapper');
        const manualEntryToggle = document.getElementById('manual-entry-toggle');
        const manualBarcodeWrapper = document.getElementById('manual-barcode-wrapper');
        const productsGrid = document.getElementById('products-grid');

        const dashboardView = document.getElementById('dashboard-view');
        const notificationBellBtn = document.getElementById('notification-bell-btn');
        const notificationDropdown = document.getElementById('notification-dropdown');
        const notificationBadge = document.getElementById('notification-badge');
        const profileAvatarBtn = document.getElementById('profile-avatar-btn');
        const profileDropdown = document.getElementById('profile-dropdown');

        // New elements for date, time, and cashier
        const dayDisplay = document.getElementById('dayDisplay');
        const dateDisplay = document.getElementById('dateDisplay');
        const timeDisplay = document.getElementById('timeDisplay');
        const cashierNameDisplay = document.getElementById('cashierName');


        // New category elements
        const categoriesToggle = document.getElementById('categories-toggle');
        const subCategoriesMenu = document.getElementById('sub-categories-menu');
        const categoriesArrow = document.getElementById('categories-arrow');

        // Function to show a custom modal
        function showModal(title, message) {
            modalTitle.textContent = title;
            modalMessage.textContent = message;
            modal.classList.remove('hidden');
        }

        // --- NEW: Function to show a temporary notification ---
        function showNotification(message, isSuccess = true) {
            const notification = document.getElementById('notification');
            if (!notification) return;

            // Set content and style based on success or error
            const icon = isSuccess ? 'fa-check-circle' : 'fa-exclamation-triangle';
            const bgColor = isSuccess ? '#22c55e' : '#ef4444'; // green-500 or red-500

            notification.innerHTML = `<i class="fas ${icon} mr-3"></i> ${message}`;
            notification.style.backgroundColor = bgColor;

            // Show the notification
            notification.classList.add('show');

            // Hide it after a few seconds
            setTimeout(() => {
                notification.classList.remove('show');
            }, 3000); // 3-second duration
        }

        // Function to update the date and time
        function updateDateTime() {
            const now = new Date();
            const dateOptions = { year: 'numeric', month: 'long', day: 'numeric' };
            const timeOptions = { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
            const dayOptions = { weekday: 'long' };

            const formattedDate = now.toLocaleDateString('en-US', dateOptions);
            const formattedTime = now.toLocaleTimeString('en-US', timeOptions);
            const formattedDay = now.toLocaleDateString('en-US', dayOptions);

            dayDisplay.textContent = formattedDay;
            dateDisplay.textContent = formattedDate;
            timeDisplay.textContent = formattedTime;
        }

        // --- NEW: Scanner Toggle Logic ---
        scannerToggle.addEventListener('change', function() {
            if (userRole !== 'admin' && userRole !== 'cashier') {
                this.checked = false; // Prevent toggle
                showModal('Role Not Assigned', 'Please contact the admin to assign you a role to use the scanner.');
                return;
            }
            if (this.checked) {
                barcodeScannerWrapper.classList.remove('hidden');
                barcodeScannerInput.focus();
            } else {
                barcodeScannerWrapper.classList.add('hidden');
            }
        });

        // --- NEW: Manual Entry Toggle Logic ---
        manualEntryToggle.addEventListener('change', function() {
            if (userRole !== 'admin' && userRole !== 'cashier') {
                this.checked = false; // Prevent toggle
                showModal('Role Not Assigned', 'Please contact the admin to assign you a role to use manual entry.');
                return;
            }
            if (this.checked) {
                manualBarcodeWrapper.classList.remove('hidden');
                manualBarcode_input.focus();
            } else {
                manualBarcodeWrapper.classList.add('hidden');
            }
        });

        // --- NEW: Barcode Scanning Logic (Supports Auto-scan and Manual Enter) ---
        async function processBarcode(sku, inputField) {
            if (userRole !== 'admin' && userRole !== 'cashier') {
                showModal('Role Not Assigned', 'Please contact the admin to assign you a role to scan items.');
                return;
            }

            if (!sku) {
                return;
            }

            const payload = {
                action: 'scan_barcode',
                sku: sku,
                cart: cartItems
            };

            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const result = await response.json();

                if (result.success) {
                    cartItems = result.cart;
                    showNotification(`Added: ${result.product_name}`);
                    renderCart();
                } else {
                    showNotification(result.message || 'An unknown error occurred.', false);
                }
            } catch (error) {
                showNotification('Connection Error: Could not connect to server.', false);
            } finally {
                // Clear the input and re-focus for the next scan
                if (inputField) {
                    inputField.value = '';
                    inputField.focus();
                }
            }
        }

        let scanTimeout;
        // Listener for fast scanner input (auto-submit)
        barcodeScannerInput.addEventListener('input', () => {
            if (!scannerToggle.checked) return;
            clearTimeout(scanTimeout);
            // A 500ms delay is a good balance for both fast scanners and manual typing.
            scanTimeout = setTimeout(() => processBarcode(barcodeScannerInput.value.trim(), barcodeScannerInput), 500);
        });

        // Listener for manual entry (Enter key) to override the timer
        barcodeScannerInput.addEventListener('keydown', function(e) {
            if (!scannerToggle.checked) return;
            if (e.key === 'Enter') {
                e.preventDefault();
                // Clear the auto-scan timeout to prevent double submission
                clearTimeout(scanTimeout);
                // Immediately process the barcode
                processBarcode(this.value.trim(), this);
            }
        });

        // Listener for the new manual barcode input field
        manualBarcode_input.addEventListener('keydown', function(e) {
            if (!manualEntryToggle.checked) return;
            if (e.key === 'Enter') {
                e.preventDefault();
                processBarcode(this.value.trim(), this);
            }
        });



        // Function to open the sidebar
        function openSidebar() {
            if (userRole !== 'admin' && userRole !== 'cashier') {
                showModal('Role Not Assigned', 'Please contact the admin to assign you a role to access the menu.');
                return;
            }
            sidebar.classList.add('open');
            sidebarBackdrop.classList.add('show');
        }

        // Function to close the sidebar
        function closeSidebar() {
            sidebar.classList.remove('open');
            sidebarBackdrop.classList.remove('show');
        }

        menuToggleBtn.addEventListener('click', openSidebar);
        sidebarBackdrop.addEventListener('click', closeSidebar);

        // Function to show/hide views
        function showView(viewName) {
            dashboardView.classList.add('hidden');
            dashboardView.classList.remove('hidden');
            dashboardView.classList.add('flex');
        }

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

        // Use event delegation on the sidebar to handle clicks on static and dynamic items
        sidebar.addEventListener('click', (e) => {
            const item = e.target.closest('.sidebar-item, .sidebar-sub-item');
            if (!item) return;

            e.preventDefault();

            const href = item.getAttribute('href');
            if (href && href !== '#') {
                window.location.href = href;
                return;
            }

            // If the item is the main category toggle, just handle the dropdown
            if (item.id === 'categories-toggle') {
                subCategoriesMenu.classList.toggle('hidden');
                categoriesArrow.classList.toggle('rotate-180');
                return;
            }

            // For any other item, close the sidebar
            closeSidebar();

            // --- Handle active state and view switching ---
            // Remove active class from all items first
            document.querySelectorAll('.sidebar-item, .sidebar-sub-item').forEach(i => i.classList.remove('active'));
            item.classList.add('active');

            const view = item.dataset.view;
            const category = item.dataset.category;

            showView(view);

            if (view === 'dashboard' && category) {
                currentCategory = category;
                searchBar.value = '';
                renderProducts(); // Only dashboard view is handled here now
            }
        });

        // Event listener for the search bar
        searchBar.addEventListener('input', () => {
            document.querySelectorAll('.sidebar-item, .sidebar-sub-item').forEach(i => i.classList.remove('active'));

            showView('dashboard');
            const searchTerm = searchBar.value.toLowerCase();
            const filteredProducts = products.filter(product =>
                product.name.toLowerCase().includes(searchTerm)
            );
            renderProducts(filteredProducts);
        });

        // Function to render products
        function renderProducts(productsToRender = null) {
            productsGrid.innerHTML = '';
            const listToRender = productsToRender ? productsToRender : (currentCategory === 'all'
                ? products
                : products.filter(p => p.categories && p.categories.split(',').includes(currentCategory)));

            listToRender.forEach(product => {
                const card = document.createElement('div');
                // Ensure product properties are treated as the correct type
                product.id = parseInt(product.id);
                product.price = parseFloat(product.price);
                product.quantity = parseInt(product.quantity);
                product.discount_percentage = parseFloat(product.discount_percentage);

                let stockHtml = `<span class="text-xs font-medium text-gray-500 mt-0.5">Stock: ${product.quantity}</span>`;
                let displayPrice = product.price;
                let priceHtml = `<span class="text-gray-800 font-bold">P${product.price.toFixed(2)}</span>`;
                if (product.discount_percentage > 0) {
                    displayPrice = product.price * (1 - (product.discount_percentage / 100));
                    priceHtml = `<span class="text-red-500 font-bold">P${displayPrice.toFixed(2)}</span>
                                 <span class="text-gray-400 line-through text-xs ml-1">P${product.price.toFixed(2)}</span>`;
                }

                card.className = 'product-card bg-white p-3 rounded-xl shadow-md flex flex-row items-center text-left sm:flex-col sm:p-4 sm:text-center';
                card.dataset.productId = product.id; // Add product ID as a data attribute

                if (product.quantity <= 0) {
                    card.classList.add('disabled');
                    stockHtml = `<span class="text-xs font-bold text-red-500 mt-0.5">Out of Stock</span>`;
                } else {
                    card.onclick = () => addToCart(product);
                }

                card.innerHTML = `
                    <img src="${product.image_url || 'https://placehold.co/150x150/e2e8f0/a0aec0?text=N/A'}" alt="${product.name}" class="rounded-lg w-16 h-16 sm:w-full sm:h-auto aspect-square object-cover mr-4 sm:mr-0 sm:mb-2">
                    <div class="flex-grow">
                        <span class="font-semibold text-gray-800 w-full truncate">${product.name}</span>
                        <div class="flex flex-col sm:items-center mt-1 text-sm">
                            ${priceHtml}
                            ${stockHtml}
                        </div>
                    </div>
                `;
                productsGrid.appendChild(card);
            });
        }


        // Function to add an item to the cart
        function addToCart(productToAdd) {
            if (userRole !== 'admin' && userRole !== 'cashier') {
                showModal('Role Not Assigned', 'Please contact the admin to assign you a role to start using the POS.');
                return;
            }

            let priceToUse = productToAdd.price;
            if (productToAdd.discount_percentage > 0) {
                priceToUse = productToAdd.price * (1 - (productToAdd.discount_percentage / 100));
            }

            const existingItem = cartItems.find(item => item.id === productToAdd.id);

            if (existingItem) {
                // Check if adding one more exceeds the stock
                const productInDb = products.find(p => p.id === productToAdd.id);
                if (productInDb && existingItem.quantity >= productInDb.quantity) {
                    showModal('Stock Limit', `No more stock available for '${productToAdd.name}'.`);
                    return;
                }
                existingItem.quantity++;
                existingItem.total = existingItem.quantity * priceToUse;
            } else {
                cartItems.push({
                    id: productToAdd.id,
                    name: productToAdd.name,
                    price: priceToUse,
                    quantity: 1,
                    total: priceToUse
                });
            }

            renderCart();
        }

        // Function to set item quantity directly from input
        function setQuantity(itemId, newQuantity) {
            if (userRole !== 'admin' && userRole !== 'cashier') {
                showModal('Role Not Assigned', 'Please contact the admin to assign you a role to use this feature.');
                return;
            }

            const idToUpdate = parseInt(itemId, 10);
            const itemInCart = cartItems.find(i => i.id === idToUpdate);
            const productInDb = products.find(p => p.id === idToUpdate);

            if (itemInCart && productInDb) {
                // Validate and sanitize the input
                let requestedQuantity = parseInt(newQuantity, 10);
                const maxStock = parseInt(productInDb.quantity, 10);

                // If input is not a number, less than 1, or empty, default to 1
                if (isNaN(requestedQuantity) || requestedQuantity < 1) {
                    requestedQuantity = 1;
                }

                // Check against available stock
                if (requestedQuantity > maxStock) {
                    showModal('Stock Limit', `Only ${maxStock} of '${productInDb.name}' available in stock.`);
                    itemInCart.quantity = maxStock; // Revert to max available stock
                } else {
                    itemInCart.quantity = requestedQuantity;
                }
                
                itemInCart.total = itemInCart.quantity * itemInCart.price;
                renderCart();
            }
        }
        window.setQuantity = setQuantity; // Make it globally accessible

        // Function to remove an item from the cart
        function removeItem(itemId) {
            const idToRemove = parseInt(itemId, 10); // Always specify radix
            cartItems = cartItems.filter(i => i.id !== idToRemove);
            renderCart();
        }
        window.removeItem = removeItem; // Make it globally accessible

        // --- NEW: Batch Removal Functions ---

        // Toggles the removal mode UI
        function toggleRemovalMode(active) {
            if (userRole !== 'admin' && userRole !== 'cashier') {
                showModal('Role Not Assigned', 'Please contact the admin to assign you a role to use this feature.');
                return;
            }

            isRemovalMode = active;
            if (active) {
                startRemovalWrapper.classList.add('hidden');
                confirmRemovalWrapper.classList.remove('hidden');
            } else {
                startRemovalWrapper.classList.remove('hidden');
                confirmRemovalWrapper.classList.add('hidden');
            }
            renderCart(); // Re-render the cart to show/hide checkboxes
        }
        window.toggleRemovalMode = toggleRemovalMode;

        // Confirms and executes the batch removal
        function confirmBatchRemoval() {
            const checkboxes = document.querySelectorAll('.remove-item-checkbox:checked');
            const idsToRemove = Array.from(checkboxes).map(cb => parseInt(cb.dataset.id, 10));

            if (idsToRemove.length === 0) {
                toggleRemovalMode(false); // Just exit if nothing is selected
                return;
            }

            // Filter out the selected items from the cart
            cartItems = cartItems.filter(item => !idsToRemove.includes(item.id));

            toggleRemovalMode(false); // Exit removal mode and re-render the updated cart
        }
        window.confirmBatchRemoval = confirmBatchRemoval;

        // Function to render the cart
        function renderCart() {
            cartItemsContainer.innerHTML = '';
            let subtotal = 0;

            if (cartItems.length === 0) {
                cartItemsContainer.innerHTML = '<p class="text-center text-gray-500 mt-4">Cart is empty.</p>';
            }

            cartItems.forEach(item => {
                subtotal += item.total;
                const cartItem = document.createElement('div');
                cartItem.className = 'flex justify-between items-center py-2 border-b border-gray-100';
                cartItem.innerHTML = `
                    <div class="flex-grow flex items-center space-x-2">
                        ${isRemovalMode ? `
                            <input type="checkbox" data-id="${item.id}" class="remove-item-checkbox h-5 w-5 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
                        ` : ''}
                        <div class="flex-grow flex items-center space-x-2 ${isRemovalMode ? 'pl-2' : ''}">
                            <span class="font-medium text-gray-800 flex-grow truncate" title="${item.name}">${item.name}</span>
                            <input 
                                type="number" 
                                value="${item.quantity}" 
                                onchange="setQuantity(${item.id}, this.value)"
                                oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                                class="w-16 flex-shrink-0 text-center font-semibold text-sm text-gray-700 bg-gray-100 rounded-md border border-gray-300 focus:ring-indigo-500 focus:border-indigo-500 p-1"
                                ${isRemovalMode ? 'disabled' : ''}
                            >
                        </div>
                    </div>
                    <div class="flex-shrink-0 flex items-center space-x-4">
                        <span class="font-semibold text-gray-900 w-20 text-right pr-4 ml-4">P${item.total.toFixed(2)}</span>
                    </div>
                `;
                cartItemsContainer.appendChild(cartItem);
            });

            totalDisplay.textContent = `P${subtotal.toFixed(2)}`;
        }

        // Function to process payment and clear the cart
        processPaymentBtn.addEventListener('click', () => {
            if (userRole !== 'admin' && userRole !== 'cashier') {
                showModal('Role Not Assigned', 'Please contact the admin to assign you a role to process payments.');
                return;
            }
            if (cartItems.length === 0) {
                showModal('Cart Empty', 'Please add items to the cart before processing payment.');
                return;
            }

            // Create a hidden form to post cart data to the payment page
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'payment.php';
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'cartData';
            input.value = JSON.stringify(cartItems);
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        });

        // --- Dropdown Logic ---
        function setupDropdown(button, dropdown) {
            button.addEventListener('click', (e) => {
                e.stopPropagation();
                // Close other dropdowns
                document.querySelectorAll('.dropdown.show').forEach(d => {
                    if (d !== dropdown) d.classList.remove('show');
                });
                dropdown.classList.toggle('show');
            });
        }

        // Custom handler for notification bell to check role
        notificationBellBtn.addEventListener('click', async (e) => {
            if (userRole === 'user') {
                showModal('Access Denied', 'Please contact an admin to assign you a role to view notifications.');
                return;
            }

            // If admin or cashier, show the dropdown
            e.stopPropagation();
            document.querySelectorAll('.dropdown.show').forEach(d => {
                if (d !== notificationDropdown) d.classList.remove('show');
            });
            notificationDropdown.classList.toggle('show');

            // If the dropdown is being opened and there are unread notifications, mark them as read.
            if (notificationDropdown.classList.contains('show') && !notificationBadge.classList.contains('hidden')) {
                // Hide badge immediately for instant UI feedback
                notificationBadge.classList.add('hidden');
                // Make API call to update the database in the background
                try {
                    await fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'mark_notifications_as_read' }) });
                } catch (error) { console.error("Failed to mark notifications as read:", error); }
            }
        });

        setupDropdown(profileAvatarBtn, profileDropdown);

        // Close dropdowns when clicking outside
        window.addEventListener('click', (e) => {
            if (!e.target.closest('.relative')) {
                document.querySelectorAll('.dropdown.show').forEach(dropdown => {
                    dropdown.classList.remove('show');
                });
            }
        });

        // --- NEW: Notification Fetching and Rendering ---
        async function fetchNotifications() {
            try {
                // In a real app, this would be an API call
                // For now, we'll simulate it. In your api.php, you'd create a new action.
                // const response = await fetch('api.php?action=get_notifications');
                // const data = await response.json();
                
                // Let's create a placeholder in api.php for this
                const response = await fetch('api.php?action=get_notifications');
                const data = await response.json();

                if (data.success) {
                    renderNotifications(data.notifications, data.total, data.unread);
                }
            } catch (error) {
                console.error("Failed to fetch notifications:", error);
            }
        }

        function renderNotifications(notifications, totalCount, unreadCount) {
            // Clear previous content
            notificationDropdown.innerHTML = '';
            notificationDropdown.innerHTML = '<div class="p-3 font-bold border-b border-gray-200 text-gray-900 flex-shrink-0">Notifications</div>';
            const contentDiv = document.createElement('div');
            contentDiv.className = 'p-2 space-y-1 overflow-y-auto'; // Removed max-h-80, flex will handle it
            if (notifications.length === 0) {
                contentDiv.innerHTML = '<p class="text-center text-sm text-gray-500 p-4">No new notifications.</p>';
            } else {
                notifications.forEach(notif => {
                    const notifItem = document.createElement('a');

                    // Make notification clickable only for admins
                    if (userRole === 'admin' && notif.link) {
                        notifItem.href = notif.link;
                        notifItem.className = 'dropdown-item text-sm rounded-md flex items-start gap-3'; // Apply full styling for admins
                    } else {
                        // For cashiers, apply the item style but don't make it a link
                        notifItem.className = 'dropdown-item text-sm rounded-md flex items-start gap-3';
                        notifItem.style.cursor = 'default'; // Make it look non-clickable
                    }

                    // --- NEW: Differentiate notification icon and color by type ---
                    let iconClass = 'fa-exclamation-triangle text-yellow-500'; // Default for low_stock
                    if (notif.type === 'restock') {
                        iconClass = 'fa-check-circle text-green-500';
                    }
                    // You can add more types here with 'else if' in the future

                    notifItem.innerHTML = `
                        <i class="fas ${iconClass} mt-1"></i>
                        <div>
                            <p class="font-semibold">${notif.message}</p>
                            <p class="text-xs text-gray-500 mt-1">${new Date(notif.created_at).toLocaleString()}</p>
                        </div>
                    `;

                    contentDiv.appendChild(notifItem);
                });
            }
            notificationDropdown.appendChild(contentDiv);

            // Add "View All" link if there are more than 5 notifications
            if (totalCount > 5) {
                const viewAllLink = document.createElement('a');
                viewAllLink.href = 'includes/notifications.php';
                viewAllLink.className = 'block text-center py-2 text-sm font-semibold text-indigo-600 hover:bg-gray-100 rounded-b-lg flex-shrink-0';
                viewAllLink.textContent = 'View All Notifications';
                notificationDropdown.appendChild(viewAllLink);
            }

            // Update badge
            if (unreadCount > 0) {
                notificationBadge.classList.remove('hidden');
            } else {
                notificationBadge.classList.add('hidden');
            }
        }

        modalCloseBtn.addEventListener('click', () => {
            modal.classList.add('hidden');
        });

        // Set the cashier name on load
        cashierNameDisplay.textContent = cashierName;
        
        // Initial rendering of products and cart
        renderProducts();
        renderCart();

        // Fetch notifications on load if user is an admin or cashier
        if (userRole === 'admin' || userRole === 'cashier') {
            fetchNotifications();
        }

        // Update date and time on load and every second
        updateDateTime();
        setInterval(updateDateTime, 1000);

        const urlParams = new URLSearchParams(window.location.search);
        const message = urlParams.get('message');

        if (message && notification) {
            notification.innerHTML = `<i class="fas fa-check-circle mr-3"></i> ${decodeURIComponent(message)}`;
            notification.classList.add('show');
            setTimeout(() => {
                notification.classList.remove('show');
                window.history.replaceState({}, document.title, "index1.php");
            }, 4000);
        }

        // --- NEW: Listen for profile image changes from other tabs ---
        window.addEventListener('storage', function(event) {
            if (event.key === 'profileImageUrl') {
                const newImageUrl = event.newValue;
                const profileImg = document.getElementById('profile-avatar-img');
                if (profileImg && newImageUrl) {
                    profileImg.src = newImageUrl + '?t=' + new Date().getTime();
                }
            }
        });
    });
    </script>
</body>

</html>
