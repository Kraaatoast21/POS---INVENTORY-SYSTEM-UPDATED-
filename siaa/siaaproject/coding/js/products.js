document.addEventListener('DOMContentLoaded', function() {
    // --- Sidebar Logic ---
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

    // Show notification if a message is present in the URL
    const urlParams = new URLSearchParams(window.location.search);
    const message = urlParams.get('message');
    const notification = document.getElementById('notification');

    if (message) {
        notification.textContent = decodeURIComponent(message);
        notification.classList.add('show');
        setTimeout(() => {
            notification.classList.remove('show');
            // Clean the message from the URL, but keep other parameters like 'filter'
            const cleanUrl = new URL(window.location);
            cleanUrl.searchParams.delete('message');
            window.history.replaceState({}, document.title, cleanUrl);
        }, 3000);
    }

    // --- Product Search/Filter Logic ---
    const searchInput = document.getElementById('product-search');
    const tableBody = document.querySelector('.inventory-table tbody');

    if (searchInput && tableBody) {
        searchInput.addEventListener('keyup', function() {
            const filter = searchInput.value.toLowerCase();
            const rows = tableBody.querySelectorAll('tr');

            rows.forEach(row => {
                let text = row.textContent.toLowerCase();
                if (text.includes(filter)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    }

    // Custom confirmation modal for delete button
    const deleteButtons = document.querySelectorAll('.delete-product');
    const confirmModal = document.getElementById('confirm-modal');
    const confirmText = document.getElementById('confirm-text');
    const confirmBtn = document.getElementById('modal-confirm-btn');
    const cancelBtn = document.getElementById('modal-cancel-btn');
    const confirmInput = document.getElementById('confirm-delete-input');
    let productIdToDelete = null;
    let productNameToDelete = null;

    function resetConfirmModal() {
        confirmModal.classList.remove('show');
        confirmInput.value = '';
        confirmBtn.disabled = true;
        productIdToDelete = null;
        productNameToDelete = null;
    }

    deleteButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            productIdToDelete = this.getAttribute('data-id');
            productNameToDelete = this.getAttribute('data-name');
            confirmText.innerHTML = `You are about to archive the product: <strong class="text-red-600">"${productNameToDelete}"</strong>. This will make it inactive and hide it from the POS, but it can be restored later from the 'Archived' filter view.`;
            confirmBtn.disabled = true; // Ensure button is disabled on open
            confirmModal.classList.add('show');
            confirmInput.focus();
        });
    });

    confirmInput.addEventListener('input', function() {
        confirmBtn.disabled = this.value.trim() !== productNameToDelete;
    });

    confirmBtn.addEventListener('click', function() {
        if (productIdToDelete && !this.disabled) {
            // Create a temporary form to submit the delete request via POST
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'products.php';

            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete_product';
            form.appendChild(actionInput); 

            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'delete_id';
            idInput.value = productIdToDelete;
            form.appendChild(idInput);

            // Add the current filter to the form so the page reloads with the same view
            const filterInput = document.createElement('input');
            filterInput.type = 'hidden';
            filterInput.name = 'current_filter';
            const currentFilter = new URLSearchParams(window.location.search).get('filter') || 'active';
            filterInput.value = currentFilter;
            form.appendChild(filterInput);

            document.body.appendChild(form);
            form.submit();
        }
    });

    cancelBtn.addEventListener('click', function() {
        resetConfirmModal();
    });

    // --- NEW: Restore Confirmation Modal Logic ---
    const restoreButtons = document.querySelectorAll('.restore-product');
    const restoreModal = document.getElementById('restore-confirm-modal');
    const restoreConfirmText = document.getElementById('restore-confirm-text');
    const restoreConfirmBtn = document.getElementById('restore-modal-confirm-btn');
    const restoreCancelBtn = document.getElementById('restore-modal-cancel-btn');
    let productIdToRestore = null;

    if (restoreModal) { // Ensure the modal exists before adding listeners
        restoreButtons.forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                productIdToRestore = this.getAttribute('data-id');
                const productName = this.getAttribute('data-name');
                restoreConfirmText.innerHTML = `This will make <strong class="text-indigo-600 font-semibold">"${productName}"</strong> active and visible in the POS again.`;
                restoreModal.classList.add('show');
            });
        });

        restoreConfirmBtn.addEventListener('click', function() {
            if (productIdToRestore) {
                // Create a temporary form to submit the restore request via POST
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'products.php';

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'restore_product';
                form.appendChild(actionInput);

                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'restore_id';
                idInput.value = productIdToRestore;
                form.appendChild(idInput);

                // Add the current filter to the form so the page reloads with the same view
                const filterInput = document.createElement('input');
                filterInput.type = 'hidden';
                filterInput.name = 'current_filter';
                const currentFilter = new URLSearchParams(window.location.search).get('filter') || 'active';
                filterInput.value = currentFilter;
                form.appendChild(filterInput);

                document.body.appendChild(form);
                form.submit();
            }
        });

        restoreCancelBtn.addEventListener('click', function() {
            restoreModal.classList.remove('show');
            productIdToRestore = null;
        });
    }

    // --- NEW: Permanent Delete Confirmation Modal Logic ---
    const permDeleteButtons = document.querySelectorAll('.permanent-delete-product');
    const permDeleteModal = document.getElementById('perm-delete-modal');

    if (permDeleteModal) { // Check if the modal exists
        const permDeleteText = document.getElementById('perm-delete-text');
        const permConfirmBtn = document.getElementById('perm-modal-confirm-btn');
        const permCancelBtn = document.getElementById('perm-modal-cancel-btn');
        const permConfirmInput = document.getElementById('perm-delete-input');
        let productIdToPermDelete = null;
        let productNameToPermDelete = null;

        function resetPermDeleteModal() {
            permDeleteModal.classList.remove('show');
            permConfirmInput.value = '';
            permConfirmBtn.disabled = true;
            productIdToPermDelete = null;
            productNameToPermDelete = null;
        }

        permDeleteButtons.forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                productIdToPermDelete = this.getAttribute('data-id');
                productNameToPermDelete = this.getAttribute('data-name');
                const hasSales = this.getAttribute('data-has-sales') === 'true';

                let warningMessage = `You are about to <strong class="text-red-700">PERMANENTLY DELETE</strong> the product: <strong class="text-red-700">"${productNameToPermDelete}"</strong>. This action cannot be undone.`;

                if (hasSales) {
                    warningMessage += `<br><br><strong class="text-yellow-600 bg-yellow-100 p-2 rounded-md block">NOTE: This product has sales history. Deleting it will remove it from past transaction records, which may affect sales reports.</strong>`;
                }

                permDeleteText.innerHTML = warningMessage;
                permConfirmBtn.disabled = true;
                permDeleteModal.classList.add('show');
                permConfirmInput.focus();
            });
        });

        permConfirmInput.addEventListener('input', function() {
            permConfirmBtn.disabled = this.value.trim() !== productNameToPermDelete;
        });

        permConfirmBtn.addEventListener('click', function() {
            if (productIdToPermDelete && !this.disabled) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'products.php';

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'permanent_delete_product';
                form.appendChild(actionInput);

                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'delete_id';
                idInput.value = productIdToPermDelete;
                form.appendChild(idInput);

                // Add the current filter to the form so the page reloads with the same view
                const filterInput = document.createElement('input');
                filterInput.type = 'hidden';
                filterInput.name = 'current_filter';
                filterInput.value = new URLSearchParams(window.location.search).get('filter') || 'active';
                form.appendChild(filterInput);

                document.body.appendChild(form);
                form.submit();
            }
        });

        permCancelBtn.addEventListener('click', resetPermDeleteModal);
    }

    // Barcode scanner integration
    const barcodeSkuInput = document.getElementById('barcode_sku');

    barcodeSkuInput.addEventListener('keypress', function(event) {
        // Check if the pressed key is "Enter"
        if (event.key === 'Enter') {
            event.preventDefault();
            // You can add further validation or processing here if needed
        }
    });
});
