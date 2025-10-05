document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebarMenu');
    const toggleButton = document.getElementById('sidebarToggle');
    const overlay = document.getElementById('sidebar-overlay');

    function closeSidebar() {
        sidebar.classList.remove('show');
        overlay.classList.remove('show');
        document.body.classList.remove('overflow-hidden');
    }

    if (toggleButton) {
        toggleButton.addEventListener('click', () => {
            sidebar.classList.toggle('show');
            overlay.classList.toggle('show');
            document.body.classList.toggle('overflow-hidden');
        });
    }

    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }

    // --- Live Search/Filter Logic ---
    const searchInput = document.getElementById('transaction-search');
    const transactionList = document.querySelectorAll('.transaction-item');
    const noResultsMessage = document.getElementById('no-results-message');

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            let visibleCount = 0;

            transactionList.forEach(item => {
                const transactionIdText = item.querySelector('[data-search-id]').textContent.toLowerCase();
                const cashierNameText = item.querySelector('.text-sm.font-normal')?.textContent.toLowerCase() || '';

                if (transactionIdText.includes(searchTerm) || cashierNameText.includes(searchTerm)) {
                    item.style.display = '';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            });

            // Show or hide the "no results" message
            noResultsMessage.style.display = visibleCount === 0 ? 'block' : 'none';
        });
    }

    const transactionItems = document.querySelectorAll('.transaction-item');
    
    transactionItems.forEach(item => {
        const header = item.querySelector('.transaction-header');
        const detailsContainer = item.querySelector('.transaction-details');
        const icon = header.querySelector('i.fa-chevron-down');

        header.addEventListener('click', async () => {
            const wasOpen = item.classList.contains('open');

            // Always close all items. This simplifies the logic.
            transactionItems.forEach(otherItem => {
                otherItem.classList.remove('open');
                otherItem.querySelector('.transaction-details').style.maxHeight = '0px';
                otherItem.querySelector('i.fa-chevron-down').style.transform = 'rotate(0deg)';
            });

            if (wasOpen) {
                // If the item was open, our job is done. It has been closed by the loop above.
                return;
            }

            // If it was not open, we proceed to open it.
            item.classList.add('open');
            icon.style.transform = 'rotate(180deg)';
            detailsContainer.innerHTML = '<p class="p-4 text-center text-gray-500">Loading details...</p>';

            const transactionId = item.dataset.transactionId;

            // Fetch data *before* starting the main animation
            try {
                const response = await fetch('../api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'get_transaction_details', transaction_id: transactionId })
                });
                const data = await response.json();

                if (data.success && data.items.length > 0) {
                    let tableHtml = `
                        <table class="details-table">
                            <thead>
                                <tr>
                                    <th>Product Name</th>
                                    <th class="text-right">Quantity</th>
                                    <th class="text-right">Price</th>
                                    <th class="text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody>`;
                    data.items.forEach(p => {
                        tableHtml += `<tr class="text-sm">
                            <td data-label="Product">${p.product_name}</td>
                            <td data-label="Qty" class="text-right">${p.quantity}</td>
                            <td data-label="Price" class="text-right">P${parseFloat(p.price).toFixed(2)}</td>
                            <td data-label="Total" class="text-right font-semibold">P${(p.quantity * p.price).toFixed(2)}</td>
                        </tr>`;
                    });

                    // Add the summary footer with Subtotal, Tax, and Discount
                    const details = data.details;
                    if (details) {
                        const subtotal = parseFloat(details.subtotal || 0);
                        const tax = parseFloat(details.tax_amount || 0);
                        const discount = parseFloat(details.discount_amount || 0);
                        const grandTotal = subtotal + tax - discount;

                        let discountRow = '';
                        if (discount > 0) {
                            discountRow = `<tr class="details-summary-row text-sm">
                                <td colspan="3" class="text-right"><span class="text-red-500 font-semibold">Discount</span></td>
                                <td data-label="" class="text-right"><span class="text-red-500 font-semibold">-P${discount.toFixed(2)}</span></td>
                            </tr>
                            `;
                        }

                        tableHtml += `
                            </tbody>
                            <tfoot class="border-t-2 border-gray-200">
                                <tr class="details-summary-row text-sm"><td colspan="3" class="text-right">Subtotal</td><td data-label="" class="text-right">P${subtotal.toFixed(2)}</td></tr>
                                ${discountRow}
                                <tr class="details-summary-row text-sm"><td colspan="3" class="text-right">Tax (12%)</td><td data-label="" class="text-right">P${tax.toFixed(2)}</td></tr>
                                <tr class="details-summary-row text-lg"><td colspan="3" class="text-right font-bold">Total</td><td data-label="" class="text-right font-bold">P${grandTotal.toFixed(2)}</td></tr>
                            </tfoot>
                        `;
                    }

                    tableHtml += `</table>`;
                    detailsContainer.innerHTML = tableHtml;
                } else {
                    // Handle cases where no items are found
                    detailsContainer.innerHTML = '<p class="p-4 text-center text-gray-500">No details found for this transaction.</p>';
                }
                // Now, trigger a single, smooth animation to the final height
                detailsContainer.style.maxHeight = detailsContainer.scrollHeight + "px";
            } catch (error) {
                detailsContainer.innerHTML = '<p class="p-4 text-center text-red-500">Could not connect to the server.</p>';
            }
        });
    });
});