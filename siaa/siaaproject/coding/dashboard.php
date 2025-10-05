<?php
require_once 'auth_check.php'; // Check if user is logged in
require_once 'db_connect.php'; // Connect to the database

// Role-based access control: only admins can view
if ($_SESSION['role'] !== 'admin') {
    die("Access Denied: You do not have permission to access this page.");
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">    <title>DAN-LEN</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6; /* bg-gray-100 */
            overflow-x: hidden; /* Prevent horizontal scroll */
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
        .list-item { border-bottom: 1px solid #e5e7eb; }
        .list-item:last-child { border-bottom: none; }
        .list-item .rank { font-size: 0.75rem; color: #9ca3af; }
        .list-item .name { font-weight: 500; color: #1f2937; }
        .sidebar-nav a i { width: 1.25rem; margin-right: 0.75rem; text-align: center; }
        .main-content {
            margin-left: 0;
            flex-grow: 1;
            padding: 1rem; /* p-4 */
            min-width: 0; /* Prevent flex item from overflowing */
            transition: margin-left 0.3s ease-in-out;
        }
        .main-header { display: flex; align-items: center; margin-bottom: 1.5rem; }
        .sidebar-toggle { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #374151; margin-right: 1rem; }
        .stat-card { background-color: white; padding: 1rem; border-radius: 0.75rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06); }
        .sidebar-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 30; opacity: 0; visibility: hidden; transition: opacity 0.3s, visibility 0.3s; }
        .sidebar-overlay.show { opacity: 1; visibility: visible; }
        @media (min-width: 768px) {
            .sidebar { transform: translateX(0); }
            .main-content { margin-left: 256px; }
            .sidebar-toggle { display: none; }
            .sidebar-overlay { display: none; }
            .main-content { padding: 1.5rem; } /* p-6 on larger screens */
            .stat-card { padding: 1.5rem; } /* p-6 on larger screens */
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
                <li><a href="dashboard.php" class="active"><i class="fas fa-home"></i>Dashboard</a></li>
                <li><a href="includes/notifications.php"><i class="fas fa-bell"></i>Notifications</a></li>
                <li><a href="users.php"><i class="fa-solid fa-user"></i>Users</a></li>
                <li><a href="includes/products.php"><i class="fas fa-tags"></i>All Products</a></li>
                <li><a href="categories.php"><i class="fas fa-sitemap"></i>Categories</a></li>
                <li><a href="includes/transactions.php"><i class="fas fa-receipt"></i>Transactions</a></li>
                <li><a href="index1.php"><i class="fas fa-chart-line"></i>POS</a></li>
            </ul>
        </nav>

        <main class="main-content">
            <div class="main-header">
                <button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
                <h1 class="text-xl sm:text-2xl font-bold text-gray-800">Sales Dashboard</h1>
            </div>

            <!-- Timeframe Filter Buttons -->
            <div class="flex flex-wrap justify-start sm:justify-end items-center mb-4 gap-2">
                <h3 class="text-sm font-medium text-gray-600 w-full sm:w-auto mb-2 sm:mb-0 sm:mr-2">Show Stats For:</h3>
                <button data-period="daily" class="filter-btn px-3 py-1.5 sm:px-4 sm:py-2 text-sm font-semibold rounded-lg border transition-colors">Today</button>
                <button data-period="weekly" class="filter-btn px-3 py-1.5 sm:px-4 sm:py-2 text-sm font-semibold rounded-lg border transition-colors active">This Week</button>
                <button data-period="monthly" class="filter-btn px-3 py-1.5 sm:px-4 sm:py-2 text-sm font-semibold rounded-lg border transition-colors">This Month</button>
                <button data-period="yearly" class="filter-btn px-3 py-1.5 sm:px-4 sm:py-2 text-sm font-semibold rounded-lg border transition-colors">This Year</button>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-6 mb-6">
                <div class="stat-card flex flex-col justify-center text-center p-6">
                    <h3 id="revenue-label" class="text-base font-medium text-gray-500">Weekly Revenue</h3>
                    <p id="period-revenue" class="text-4xl font-extrabold text-indigo-600 mt-1">P0.00</p>
                </div>
                <div class="stat-card flex flex-col justify-center text-center p-6">
                    <h3 class="text-base font-medium text-gray-500">Total Revenue</h3>
                    <p id="total-revenue" class="text-4xl font-extrabold text-gray-800 mt-1">P0.00</p>
                </div>
                <div class="stat-card flex flex-col justify-center text-center p-6">
                    <h3 class="text-base font-medium text-gray-500">Total Transactions</h3>
                    <p id="total-transactions" class="text-4xl font-extrabold text-gray-800 mt-1">0</p>
                </div>
                <div class="stat-card flex flex-col justify-center text-center p-6">
                    <h3 class="text-base font-medium text-gray-500">Total Products</h3>
                    <p id="total-products" class="text-4xl font-extrabold text-gray-800 mt-1">0</p>
                </div>
                <div class="stat-card flex flex-col justify-center text-center p-6 sm:col-span-2 lg:col-span-1 xl:col-span-1">
                    <h3 class="text-base font-medium text-gray-500">Total Users</h3>
                    <p id="total-users" class="text-4xl font-extrabold text-gray-800 mt-1">0</p>
                </div>
            </div>

            <div class="stat-card h-80 sm:h-96 lg:h-[500px]" id="sales-chart-container">
                <div id="salesChart" style="width: 100%; height: 100%;"></div>
            </div>

            <!-- New Section for Top Products and Categories -->
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6 mt-6">
                <!-- Top 3 Products Pie Chart -->
                <div class="stat-card h-80 sm:h-96 lg:h-[500px]" id="pie-chart-container">
                    <div id="topProductsPieChart" style="width: 100%; height: 100%;"></div>
                </div>
                <!-- Top Selling Products List -->
                <div class="stat-card" id="top-products-container">
                    <h3 class="text-lg font-semibold text-gray-700 mb-4">Top Selling Products</h3>
                    <div id="top-products-list" class="space-y-3"></div>
                </div>
                <div class="stat-card" id="top-categories-container">
                    <h3 class="text-lg font-semibold text-gray-700 mb-4">Top Selling Categories</h3>
                    <div id="top-categories-list" class="space-y-3"></div>
                </div>
            </div>

            <!-- Sales Analysis and Prediction Section -->
            <div class="stat-card mt-6">
                <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-3">Sales Analysis & Prediction</h2>
                <div class="flex flex-wrap items-end gap-4 mb-4">
                    <div>
                        <label for="analysis-year" class="block text-sm font-medium text-gray-600">Year</label>                        <input type="number" id="analysis-year" class="mt-1 block w-full sm:w-32 rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" placeholder="YYYY">
                    </div>
                    <div>
                        <label for="analysis-month" class="block text-sm font-medium text-gray-600">Month (Optional)</label>
                        <select id="analysis-month" class="mt-1 block w-full sm:w-48 rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                            <option value="">All Months</option>
                            <!-- Months will be populated by JS -->
                        </select>
                    </div>
                    <div>
                        <label for="analysis-day" class="block text-sm font-medium text-gray-600">Day (Optional)</label>
                        <input type="number" id="analysis-day" class="mt-1 block w-full sm:w-24 rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" placeholder="DD" min="1" max="31">
                    </div>
                    <button id="generate-analysis-btn" class="px-5 py-2 bg-indigo-600 text-white font-semibold rounded-lg hover:bg-indigo-700 transition-colors shadow-sm">
                        <i class="fas fa-cogs mr-2"></i>Generate Report
                    </button>
                </div>
                <div id="analysis-results-container" class="mt-6 p-4 bg-gray-50 rounded-lg hidden">
                    <!-- Analysis results will be injected here by JavaScript -->
                </div>
            </div>

        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebarMenu');
            const toggleButton = document.getElementById('sidebarToggle');
            const overlay = document.getElementById('sidebar-overlay');

            // --- NEW: Chart data cache ---
            let cachedChartData = null;
            let cachedPeriod = null;

            // --- NEW: Filter button logic ---
            const filterButtons = document.querySelectorAll('.filter-btn');
            let currentPeriod = 'weekly'; // Default period

            filterButtons.forEach(button => {
                button.addEventListener('click', function() {
                    currentPeriod = this.dataset.period;
                    fetchDashboardData(currentPeriod);
                });
            });

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

            async function fetchDashboardData(period = 'weekly') {
                // Update active button style
                filterButtons.forEach(btn => {
                    if (btn.dataset.period === period) {
                        btn.classList.add('bg-indigo-600', 'text-white', 'border-indigo-600');
                        btn.classList.remove('bg-white', 'text-gray-700');
                    } else {
                        btn.classList.remove('bg-indigo-600', 'text-white', 'border-indigo-600');
                        btn.classList.add('bg-white', 'text-gray-700');
                    }
                });

                try {
                    const response = await fetch(`api.php?action=get_dashboard_stats&period=${period}`);
                    const data = await response.json();

                    if (data.success) {
                        document.getElementById('revenue-label').textContent = `${period.charAt(0).toUpperCase() + period.slice(1)} Revenue`;
                        document.getElementById('period-revenue').textContent = `P${parseFloat(data.stats.period_revenue || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                        document.getElementById('total-revenue').textContent = `P${parseFloat(data.stats.total_revenue || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                        document.getElementById('total-transactions').textContent = data.stats.total_transactions || 0;
                        document.getElementById('total-products').textContent = data.stats.total_products || 0;
                        document.getElementById('total-users').textContent = data.stats.total_users || 0;
                        
                        // Cache the data for resizing
                        cachedChartData = data.chart_data;
                        cachedPeriod = period;

                        drawChart(cachedChartData, cachedPeriod);
                        // Pass top products to the pie chart and list functions
                        drawPieChart(data.top_products);
                        renderTopProductsList(data.top_products);
                        renderTopCategoriesList(data.top_categories);
                    } else {
                        console.error('Failed to load dashboard stats:', data.message);
                    }
                } catch (error) {
                    console.error('Error fetching dashboard data:', error);
                    document.getElementById('sales-chart-container').innerHTML = '<p class="text-center text-red-500">Could not load chart data.</p>';
                    document.getElementById('pie-chart-container').innerHTML = '<p class="text-center text-red-500">Could not load chart data.</p>';
                    document.getElementById('top-products-container').innerHTML = '<p class="text-center text-red-500">Could not load top products.</p>';
                }
            }

            // --- Google Charts Setup ---
            google.charts.load('current', {'packages':['corechart']});
            google.charts.setOnLoadCallback(() => fetchDashboardData(currentPeriod));

            // --- NEW: Redraw charts on window resize ---
            let resizeTimer;
            window.addEventListener('resize', () => {
                clearTimeout(resizeTimer);
                // Debounce the resize event to avoid excessive redraws
                resizeTimer = setTimeout(redrawCharts, 250);
            });

            function drawChart(salesData, period) {
                let chartTitle = 'Sales Performance';
                let hAxisTitle = 'Day';
                if (period === 'daily') {
                    chartTitle = 'Today\'s Sales by Hour';
                    hAxisTitle = 'Hour';
                } else if (period === 'weekly') {
                    chartTitle = 'Last 7 Days Sales';
                } else if (period === 'monthly') {
                    chartTitle = 'This Month\'s Sales by Day';
                }

                const options = {
                    title: chartTitle,
                    titleTextStyle: { color: '#374151', fontSize: 16, bold: false },
                    hAxis: { title: hAxisTitle, textStyle: { color: '#6b7280' } },
                    vAxis: { 
                        minValue: 0,
                        format: '₱#,##0.00', // Correct currency format
                        gridlines: { color: '#e5e7eb' }, 
                        textStyle: { color: '#6b7280' }
                    },
                    legend: { position: 'none' },
                    chartArea: { left: '14%', top: '15%', width: '82%', height: '70%' },
                    backgroundColor: 'transparent',
                    animation: {
                        startup: true,
                        duration: 1000,
                        easing: 'out',
                    }
                };

                if (!salesData || salesData.length === 0) {
                    document.getElementById('salesChart').innerHTML = `<p class="text-center text-gray-500 h-full flex items-center justify-center">No sales data for this period.</p>`;
                    return;
                }

                const dataTable = new google.visualization.DataTable();
                dataTable.addColumn('string', hAxisTitle);
                dataTable.addColumn('number', 'Sales');
                dataTable.addColumn({ type: 'string', role: 'style' });

                // Find the maximum sales value to highlight it
                let maxSales = 0;
                if (salesData.length > 0) {
                    maxSales = Math.max(...salesData.map(item => parseFloat(item.total)));
                }

                salesData.forEach(item => {
                    const total = parseFloat(item.total);
                    // Use a brighter color for the highest value bar, and a softer one for others
                    const barColor = total === maxSales ? '#4f46e5' : '#a5b4fc';
                    dataTable.addRow([item.label, total, barColor]);
                });

                const chart = new google.visualization.BarChart(document.getElementById('salesChart'));
                chart.draw(dataTable, options);
            }

            function drawPieChart(topProducts) {
                const options = {
                    title: 'Top 3 Products by Quantity Sold',
                    titleTextStyle: { color: '#374151', fontSize: 16, bold: false },
                    is3D: true,
                    legend: { position: 'bottom', textStyle: { color: '#333', fontSize: 12 } }, // Keep legend at bottom for consistency
                    // Add more padding around the chart to contain the 3D effect
                    chartArea: { left: '10%', top: '10%', width: '80%', height: '70%' },
                    backgroundColor: 'transparent',
                    // A more vibrant and distinct color palette
                    colors: ['#4f46e5', '#818cf8', '#f59e0b', '#10b981'],
                    animation: {
                        startup: true,
                        duration: 1200,
                        easing: 'inAndOut',
                    }
                };

                if (!topProducts || topProducts.length === 0) {
                    document.getElementById('topProductsPieChart').innerHTML = '<p class="text-center text-gray-500 h-full flex items-center justify-center">No product sales data available.</p>';
                    return;
                }

                const dataTable = new google.visualization.DataTable();
                dataTable.addColumn('string', 'Product');
                dataTable.addColumn('number', 'Quantity Sold');

                // Take top 3 and group the rest into "Others"
                let otherTotal = 0;
                topProducts.slice(0, 3).forEach(product => {
                    dataTable.addRow([product.name, parseInt(product.total_sold)]);
                });
                if (topProducts.length > 3) {
                    topProducts.slice(3).forEach(product => {
                        otherTotal += parseInt(product.total_sold);
                    });
                    dataTable.addRow(['Others', otherTotal]);
                }

                const chart = new google.visualization.PieChart(document.getElementById('topProductsPieChart'));
                chart.draw(dataTable, options);
            }

            function renderTopProductsList(products) {
                const listContainer = document.getElementById('top-products-list');
                listContainer.innerHTML = ''; // Clear previous content
                if (!products || products.length === 0) {
                    listContainer.innerHTML = '<p class="text-center text-gray-500">No data available.</p>';
                    return;
                }
                products.forEach((product, index) => {
                    const item = `
                        <div class="list-item flex items-center justify-between py-2">
                            <div class="flex items-center gap-3">
                                <span class="rank">#${index + 1}</span>
                                <span class="name">${product.name}</span>
                            </div>
                            <span class="font-bold text-gray-600">${product.total_sold} sold</span>
                        </div>`;
                    listContainer.innerHTML += item;
                });
            }

            function renderTopCategoriesList(categories) {
                const listContainer = document.getElementById('top-categories-list');
                listContainer.innerHTML = ''; // Clear previous content
                if (!categories || categories.length === 0) {
                    listContainer.innerHTML = '<p class="text-center text-gray-500">No data available.</p>';
                    return;
                }
                categories.forEach((category, index) => {
                    const categoryName = category.category ? category.category.replace(/-/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) : 'Uncategorized';
                    const item = `<div class="list-item flex items-center justify-between py-2"><div class="flex items-center gap-3"><span class="rank">#${index + 1}</span><span class="name">${categoryName}</span></div><span class="font-bold text-gray-600">${category.total_sold} sold</span></div>`;
                    listContainer.innerHTML += item;
                });
            }

            function redrawCharts() {
                if (cachedChartData) {
                    drawChart(cachedChartData, cachedPeriod);
                    // You can add redraw logic for other charts here if needed
                }
            }

            // --- NEW: Sales Analysis & Prediction Logic ---
            const analysisYearSelect = document.getElementById('analysis-year');
            analysisYearSelect.value = new Date().getFullYear(); // Set current year by default
            const analysisMonthSelect = document.getElementById('analysis-month');
            const analysisDaySelect = document.getElementById('analysis-day');
            const generateAnalysisBtn = document.getElementById('generate-analysis-btn');
            const analysisResultsContainer = document.getElementById('analysis-results-container');

            // Populate month dropdown
            const months = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
            months.forEach((month, index) => {
                analysisMonthSelect.innerHTML += `<option value="${index + 1}">${month}</option>`;
            });

            generateAnalysisBtn.addEventListener('click', async () => {
                const year = analysisYearSelect.value;
                const month = analysisMonthSelect.value;
                const day = analysisDaySelect.value;

                generateAnalysisBtn.disabled = true;
                generateAnalysisBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Generating...';
                analysisResultsContainer.classList.add('hidden');
                analysisResultsContainer.innerHTML = '';

                try {
                    const response = await fetch(`api.php?action=get_sales_analysis&year=${year}&month=${month}&day=${day}`);
                    const data = await response.json();

                    if (data.success) {
                        renderAnalysisResults(data);
                    } else {
                        analysisResultsContainer.innerHTML = `<p class="text-center text-red-500 font-semibold">${data.message}</p>`;
                    }
                } catch (error) {
                    console.error('Error fetching analysis data:', error);
                    analysisResultsContainer.innerHTML = '<p class="text-center text-red-500 font-semibold">Could not generate report due to a connection error.</p>';
                } finally {
                    generateAnalysisBtn.disabled = false;
                    generateAnalysisBtn.innerHTML = '<i class="fas fa-cogs mr-2"></i>Generate Report';
                    analysisResultsContainer.classList.remove('hidden');
                }
            });

            function renderAnalysisResults(data) {
                const stats = data.statistics;
                const chartData = data.chart_data;
                const formatQuantity = (val) => `${parseInt(val || 0).toLocaleString('en-US')}`;

                const resultsHtml = `
                    <h3 class="text-lg font-bold text-gray-800 mb-3">${stats.title}</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 text-center">
                        <div class="bg-white p-6 rounded-lg shadow-sm flex flex-col justify-center">
                            <p class="font-semibold text-gray-500">Total Items Sold</p>
                            <p class="text-3xl font-bold text-indigo-600 mt-1">${formatQuantity(stats.total_items)}</p>
                        </div>
                        <div class="bg-white p-6 rounded-lg shadow-sm flex flex-col justify-center">
                            <p class="font-semibold text-gray-500">Avg. Monthly Items Sold</p>
                            <p class="text-3xl font-bold text-gray-700 mt-1">${formatQuantity(stats.avg_monthly_items)}</p>
                        </div>
                        <div class="bg-white p-6 rounded-lg shadow-sm flex flex-col justify-center">
                            <p class="font-semibold text-gray-500">Avg. Daily Items Sold</p>
                            <p class="text-3xl font-bold text-gray-700 mt-1">${formatQuantity(stats.avg_daily_items)}</p>
                        </div>
                        <div class="bg-white p-6 rounded-lg shadow-sm flex flex-col justify-center">
                            <p class="font-semibold text-gray-500">Best Month</p>
                            <p class="text-2xl font-bold text-green-600 mt-1">${stats.best_month.name || 'N/A'}</p>
                            <p class="text-base font-medium text-gray-600">(${formatQuantity(stats.best_month.quantity)} items)</p>
                        </div>
                        <div class="bg-white p-6 rounded-lg shadow-sm flex flex-col justify-center">
                            <p class="font-semibold text-gray-500">Best Week</p>
                            <p class="text-2xl font-bold text-green-600 mt-1">Week ${stats.best_week.week_num || 'N/A'}</p>
                            <p class="text-base font-medium text-gray-600">(${formatQuantity(stats.best_week.quantity)} items)</p>
                        </div>
                        <div class="bg-white p-6 rounded-lg shadow-sm flex flex-col justify-center">
                            <p class="font-semibold text-gray-500">Best Day</p>
                            <p class="text-2xl font-bold text-green-600 mt-1">${stats.best_day.date || 'N/A'}</p>
                            <p class="text-base font-medium text-gray-600">(${formatQuantity(stats.best_day.quantity)} items)</p>
                        </div>
                    </div>

                    <!-- Chart for Analysis -->
                    <div class="mt-6 border-t pt-4">
                        <h4 class="font-bold text-gray-800">Sales Breakdown Chart</h4>
                        <div id="analysis-chart-container" class="stat-card h-80 sm:h-96 lg:h-[500px] mt-4 -mx-4 sm:mx-0">
                            <div id="analysisChart" style="width: 100%; height: 100%;"></div>
                        </div>
                    </div>

                    <div class="mt-6 border-t pt-4">
                        <h4 class="font-bold text-gray-800">Prediction & Justification</h4>
                        <p class="mt-2 text-gray-600 text-sm leading-relaxed">${data.justification.replace(/\n/g, '<br>')}</p>
                    </div>
                `;
                analysisResultsContainer.innerHTML = resultsHtml;

                // Now, draw the new chart
                drawAnalysisChart(data.chart_data, data.chart_config);
            }

            function drawAnalysisChart(salesData, config) {
                const chartContainer = document.getElementById('analysisChart');
                if (!salesData || salesData.length === 0) {
                    chartContainer.innerHTML = `<p class="text-center text-gray-500 h-full flex items-center justify-center">No chart data available for this period.</p>`;
                    return;
                }

                const dataTable = new google.visualization.DataTable();
                dataTable.addColumn('string', config.hAxisTitle);
                dataTable.addColumn('number', 'Items Sold');
                dataTable.addColumn({ type: 'string', role: 'style' });

                salesData.forEach(item => {
                    const quantity = parseFloat(item.total_quantity || 0);
                    dataTable.addRow([String(item.label), quantity, '#a5b4fc']);
                });

                const options = {
                    title: 'Items Sold for Selected Period',
                    width: 900,
                    height: 500,
                    titleTextStyle: { color: '#374151', fontSize: 16, bold: false },
                    hAxis: { title: config.hAxisTitle, textStyle: { color: '#6b7280' } },
                    vAxis: { title: 'Items Sold', format: 'short', textStyle: { color: '#6b7280' } },
                    legend: { position: 'none' },
                    chartArea: { left: '14%', top: '15%', width: '82%', height: '70%' },
                    backgroundColor: 'transparent',
                    bar: { groupWidth: '70%' },
                    colors: ['#a5b4fc']
                };

                const chart = new google.visualization.ColumnChart(chartContainer);
                chart.draw(dataTable, options);
            }
        });
    </script>
</body>
</html>