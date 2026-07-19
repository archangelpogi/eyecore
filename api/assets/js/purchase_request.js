// ============================================
// PURCHASE REQUEST JAVASCRIPT - ORGANIZED VERSION
// ============================================

// ========== GLOBAL VARIABLES ==========
let currentPRId = null;
let itemCounter = 0;
let inventoryItems = [];
let currentCompletePRId = null;
let currentPRItems = [];
let currentPageUrl = window.location.href;
let currentRating = 0;
let selectedTags = [];

// ========== INITIALIZATION ==========
document.addEventListener('DOMContentLoaded', function() {
    initForms();
    loadPRTable();
    setupFilterListeners();
    console.log('Suppliers loaded:', suppliersList);
});

// ========== FORM INITIALIZATION ==========
function initForms() {
    // New PR form
    const newPRForm = document.getElementById('newPRForm');
    if (newPRForm) {
        newPRForm.addEventListener('submit', function(e) {
            e.preventDefault();
        });
    }
    
    // Create PO form
    const createPOForm = document.getElementById('createPOForm');
    if (createPOForm) {
        createPOForm.addEventListener('submit', function(e) {
            e.preventDefault();
            createPOFromForm();
        });
    }
    
    // Initialize modal reset
    const newPRModal = document.getElementById('newPRModal');
    if (newPRModal) {
        newPRModal.addEventListener('hidden.bs.modal', function() {
            resetNewPRForm();
        });
    }
}

// ========== FILTER FUNCTIONS ==========
function setupFilterListeners() {
    const filters = ['statusFilter', 'priorityFilter', 'dateFromFilter', 'dateToFilter'];
    filters.forEach(filterId => {
        const element = document.getElementById(filterId);
        if (element) {
            element.addEventListener('change', applyFilters);
        }
    });
    
    const searchFilter = document.getElementById('searchFilter');
    if (searchFilter) {
        searchFilter.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                applyFilters();
            }
        });
    }
}

function applyFilters() {
    const status = document.getElementById('statusFilter').value;
    const priority = document.getElementById('priorityFilter').value;
    const dateFrom = document.getElementById('dateFromFilter').value;
    const dateTo = document.getElementById('dateToFilter').value;
    const search = document.getElementById('searchFilter').value;
    
    const params = new URLSearchParams();
    if (status !== 'all') params.set('status', status);
    if (priority) params.set('priority', priority);
    if (dateFrom) params.set('date_from', dateFrom);
    if (dateTo) params.set('date_to', dateTo);
    if (search) params.set('search', search);
    
    const queryString = params.toString();
    const newUrl = window.location.pathname + (queryString ? '?' + queryString : '');
    window.history.pushState({}, '', newUrl);
    
    loadPRTable();
}

function clearFilters() {
    document.getElementById('statusFilter').value = 'all';
    document.getElementById('priorityFilter').value = '';
    document.getElementById('dateFromFilter').value = '';
    document.getElementById('dateToFilter').value = '';
    document.getElementById('searchFilter').value = '';
    
    window.history.pushState({}, '', window.location.pathname);
    loadPRTable();
}

// ========== TABLE LOADING ==========
function loadPRTable() {
    const params = new URLSearchParams(window.location.search);
    const status = params.get('status') || 'all';
    const priority = params.get('priority') || '';
    const date_from = params.get('date_from') || '';
    const date_to = params.get('date_to') || '';
    const search = params.get('search') || '';
    
    let url = `api/purchase_request.php?action=get_prs&status=${status}`;
    if (priority) url += `&priority=${priority}`;
    if (date_from) url += `&date_from=${date_from}`;
    if (date_to) url += `&date_to=${date_to}`;
    if (search) url += `&search=${encodeURIComponent(search)}`;
    
    fetch(url)
    .then(response => response.json())
    .then(data => {
        const tbody = document.getElementById('prTableBody');
        
        if (data.success && data.data.length > 0) {
            let html = '';
            const currentUserRole = data.user_role || 'SCM';
            
            data.data.forEach(pr => {
                const createdDate = new Date(pr.created_at).toLocaleDateString();
                const totalAmount = parseFloat(pr.total_amount || 0).toFixed(2);
                
                // Check if may rejection note
                const rejectionNote = pr.rejection_note ? 
                    `<br><small class="text-danger"><i class="bi bi-exclamation-circle"></i> ${pr.rejection_note}</small>` : '';
                
                html += `
                    <tr>
                        <td>
                            <strong>${pr.pr_number || 'N/A'}</strong>
                            <br><small class="text-muted">${createdDate}</small>
                        </td>
                        <td>${pr.department || 'N/A'}</td>
                        <td><span class="badge bg-${getPriorityColor(pr.priority)}">${pr.priority || 'Medium'}</span></td>
                        <td>${pr.item_count || 0} items</td>
                        <td class="fw-bold">₱${totalAmount}</td>
                        <td><span class="badge bg-${getStatusColor(pr.status)}">${pr.status || 'Draft'}</span></td>
                        <td>${createdDate}</td>
                        <td class="text-center">
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-info" onclick="viewPR(${pr.id})" title="View"><i class="bi bi-eye"></i></button>
                            
                            ${pr.status === 'Draft' || pr.status === 'Pending Approval' || pr.status === 'Supplier Rejected' ? 
                                `<button class="btn btn-outline-warning" onclick="editPR(${pr.id})" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </button>` : ''}
                            
                            ${pr.status === 'Draft' || pr.status === 'Supplier Rejected' ? 
                                `<button class="btn btn-outline-primary" onclick="submitPR(${pr.id})" title="Submit for Approval">
                                    <i class="bi bi-send"></i>
                                </button>` : ''}
                            
                            ${pr.status === 'Approved' ? 
                                `<a href="views/po_form.php?pr_id=${pr.id}" class="btn btn-outline-success btn-sm" title="Create PO">
                                    <i class="bi bi-file-earmark-text"></i>
                                </a>` : ''}

                            ${pr.status === 'Shipped' ? 
                                `<button class="btn btn-success" onclick="openReceiveOrderModal(${pr.id})" title="Receive Order">
                                    <i class="bi bi-check-circle"></i> Receive
                                </button>` : ''}
                            
                            ${pr.status === 'Draft' || (pr.status === 'Pending Approval' && currentUserRole === 'SCM') ? 
                                `<button class="btn btn-outline-danger" onclick="deletePR(${pr.id}, '${pr.pr_number || ''}')" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>` : ''}
                        </div>
                        </td>
                    </tr>
                `;
            });
            
            tbody.innerHTML = html;
        } else {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center py-4">
                        <div class="text-muted">
                            <i class="bi bi-inbox display-6"></i>
                            <p class="mt-2">No purchase requests found</p>
                        </div>
                    </td>
                </tr>
            `;
        }
    })
    .catch(error => {
        console.error('Error loading PRs:', error);
        document.getElementById('prTableBody').innerHTML = `
            <tr>
                <td colspan="8" class="text-center py-4 text-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Error loading data. Please try again.
                </td>
            </tr>
        `;
    });
}


// ========== PR FORM RESET ==========
function resetNewPRForm() {
    document.getElementById('newPRForm').reset();
    document.querySelector('select[name="department"]').value = 'SCM';
    document.querySelector('select[name="priority"]').value = 'Medium';

    const date = new Date();
    date.setDate(date.getDate() + 7);
    document.querySelector('input[name="needed_by"]').value = date.toISOString().split('T')[0];

    document.getElementById('prItemsBody').innerHTML = `
        <tr id="noItemsRow">
            <td colspan="7" class="text-center text-muted py-3">
                No items added. Click "Add Item" to start.
            </td>
        </tr>
    `;

    document.getElementById('prTotal').textContent = '₱0.00';
    currentPRId = null;
    itemCounter = 0;
}

// ========== OPEN NEW PR MODAL ==========
function openNewPRModal() {
    resetNewPRForm();
    const modal = new bootstrap.Modal(document.getElementById('newPRModal'));
    modal.show();
}

function checkout() {
    // Get cart items and pass to parent window (PR modal)
    fetch('../api/cart.php?action=get_items')
        .then(res => res.json())
        .then(data => {
            if (data.success && data.items.length > 0) {
                // Pass to parent window (main.php with PR modal)
                if (window.opener) {
                    window.opener.receiveSelectedProducts(data.items);
                }
                window.close();
            }
        });
}

// ========== RECEIVE SELECTED PRODUCTS FROM CART ==========
window.receiveSelectedProducts = function(items) {
    // Fetch product details from API
    fetch('api/cart.php?action=get_product_details', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(items)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            data.products.forEach(product => {
                addPRItem(product, 'search');
            });
        }
    });
};

// ========== PR ITEM MANAGEMENT ==========
function addPRItem(item = null, mode = 'category') {
    console.log('Adding PR item:', item, 'Mode:', mode);
    
    const tbody = document.getElementById('prItemsBody');
    const itemCount = document.querySelectorAll('tr[id^="itemRow_"]').length;
    const newId = 'new_' + Date.now() + '_' + itemCount;
    
    const noItemsRow = document.getElementById('noItemsRow');
    if (noItemsRow) noItemsRow.remove();
    
    let itemSelectionHtml = '';
    
    if (mode === 'category' && !item) {
        itemSelectionHtml = `
            <div class="flex-grow-1">
                <select class="form-select form-select-sm category-select select2-category" 
                        data-row="${newId}" id="category_${newId}" style="width: 100%;">
                    <option value="">-- Select Category --</option>
                    <option value="Frames">Frames</option>
                    <option value="Lenses">Lenses</option>
                    <option value="Contact Lenses">Contact Lenses</option>
                    <option value="Accessories">Accessories</option>
                    <option value="Others">Others</option>
                </select>
                <div id="supplier_products_${newId}" style="display: none; margin-top: 10px;">
                    <select class="form-select form-select-sm supplier-product-select select2-product" 
                            data-row="${newId}" id="product_${newId}" style="width: 100%;">
                        <option value="">-- Select Supplier Product --</option>
                    </select>
                </div>
            </div>
            <input type="hidden" name="items[${newId}][item_name]" id="item_name_${newId}" value="">
            <input type="hidden" name="items[${newId}][supplier_id]" id="supplier_id_${newId}" value="">
            <input type="hidden" name="items[${newId}][supplier_name]" id="supplier_name_${newId}" value="">
            <input type="hidden" name="items[${newId}][supplier_product_id]" id="supplier_product_id_${newId}" value="">
        `;
    } else {
        const photoHtml = item?.photo_path ? 
            `<img src="${item.photo_path}" style="width: 40px; height: 40px; object-fit: cover; border-radius: 5px; margin-right: 8px;">` : 
            '';
        
        itemSelectionHtml = `
            <div class="d-flex align-items-center">
                ${photoHtml}
                <div>
                    <strong>${item?.item_name || ''}</strong>
                    <input type="hidden" name="items[${newId}][item_name]" value="${item?.item_name || ''}">
                    <input type="hidden" name="items[${newId}][supplier_product_id]" value="${item?.supplier_product_id || ''}">
                    <input type="hidden" name="items[${newId}][description]" value="${item?.description || ''}">
                </div>
            </div>
        `;
    }
    
    const newRow = `
        <tr id="itemRow_${newId}">
            <td>${itemSelectionHtml}</td>
            <td>
                <input type="number" class="form-control form-control-sm" name="items[${newId}][quantity]" 
                       id="quantity_${newId}" value="${item?.quantity || 1}" min="${item?.min_order || 1}" 
                       onchange="calculatePRItemTotal('${newId}')" required>
                <small class="text-muted" id="min_order_${newId}">${item?.min_order ? 'Min: '+item.min_order : ''}</small>
            </td>
            <td>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light">₱</span>
                    <input type="number" step="0.01" class="form-control form-control-sm bg-light" 
                           name="items[${newId}][unit_price]" id="unit_price_${newId}" value="${item?.unit_price || 0}" 
                           readonly style="background-color: #f8f9fa;" disabled>
                </div>
            </td>
            <td>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light">₱</span>
                    <input type="text" class="form-control form-control-sm bg-light fw-bold text-primary" 
                           name="items[${newId}][total_price]" id="total_price_${newId}" readonly 
                           value="${((item?.quantity || 1) * (item?.unit_price || 0)).toFixed(2)}"
                           style="background-color: #f8f9fa;" disabled>
                </div>
            </td>
            <td>
                <div class="small p-1 bg-light rounded" id="supplier_display_${newId}">
                    ${item?.supplier_name || '<span class="text-muted">Select product first</span>'}
                </div>
                <input type="hidden" name="items[${newId}][supplier_id]" id="supplier_id_${newId}" value="${item?.supplier_id || ''}">
                <input type="hidden" name="items[${newId}][supplier_name]" id="supplier_name_${newId}" value="${item?.supplier_name || ''}">
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-danger" onclick="removePRItem('${newId}')">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        </tr>
    `;
    
    tbody.insertAdjacentHTML('beforeend', newRow);
    
    setTimeout(() => {
        if ($.fn.select2) {
            $(`#category_${newId}`).select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: '-- Select Category --',
                allowClear: true
            }).on('change', function() {
                loadSupplierProductsByCategory(this, newId);
            });
        }
    }, 100);
    
    calculatePRTotal();
}

// ========== LOAD SUPPLIER PRODUCTS BY CATEGORY ==========
function loadSupplierProductsByCategory(select, rowId) {
    const category = select.value;
    const productsDiv = document.getElementById(`supplier_products_${rowId}`);
    
    console.log('Category selected:', category);
    
    if (!category) {
        productsDiv.style.display = 'none';
        return;
    }
    
    productsDiv.style.display = 'block';
    
    if ($.fn.select2) {
        $(`#product_${rowId}`).select2('destroy');
    }
    
    $(`#product_${rowId}`).html('<option value="">Loading products...</option>');
    
    fetch(`api/supplier_products.php?action=get_by_category&category=${encodeURIComponent(category)}`)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.data && data.data.length > 0) {
                let options = '<option value="">-- Select Supplier Product --</option>';
                
                data.data.forEach(p => {
                    const stockStatus = p.stock > 0 ? 'In Stock' : 'Out of Stock';
                    options += `<option value="${p.id}" 
                                data-supplier-id="${p.supplier_id}"
                                data-supplier-name="${p.supplier_name}"
                                data-price="${p.cost_price}"
                                data-stock="${p.stock}"
                                data-min-order="${p.min_order_qty || 1}"
                                data-lead-time="${p.lead_time_days || 3}"
                                data-photo="${p.photo_path || ''}">
                                ${p.product_name} - ₱${parseFloat(p.cost_price).toFixed(2)} (${stockStatus}) - ${p.supplier_name}
                            </option>`;
                });
                
                $(`#product_${rowId}`).html(options);
                
                if ($.fn.select2) {
                    $(`#product_${rowId}`).select2({
                        theme: 'bootstrap-5',
                        width: '100%',
                        placeholder: '-- Select Supplier Product --',
                        allowClear: true
                    }).on('change', function() {
                        selectSupplierProduct(this, rowId);
                    });
                }
            } else {
                $(`#product_${rowId}`).html('<option value="">No products found in this category</option>');
            }
        })
        .catch(err => {
            console.error('Error loading products:', err);
            $(`#product_${rowId}`).html('<option value="">Error loading products</option>');
        });
}

// ========== OPEN PRODUCT SELECTOR ==========
function openProductSelector() {
    const prModal = bootstrap.Modal.getInstance(document.getElementById('newPRModal'));
    if (prModal) {
        prModal.hide();
    }
    
    Swal.fire({
        title: 'Select Products',
        html: `
            <div class="row mb-3">
                <div class="col-md-8">
                    <input type="text" id="searchQuery" class="form-control" 
                           placeholder="🔍 Search products...">
                </div>
                <div class="col-md-4">
                    <select id="categoryFilter" class="form-select">
                        <option value="">All Categories</option>
                        <option value="Frames">Frames</option>
                        <option value="Lenses">Lenses</option>
                        <option value="Contact Lenses">Contact Lenses</option>
                        <option value="Accessories">Accessories</option>
                        <option value="Others">Others</option>
                    </select>
                </div>
            </div>
            <div id="productList" style="max-height: 500px; overflow-y: auto;">
                <div class="text-center text-muted py-5">
                    <i class="bi bi-box-seam" style="font-size: 3rem;"></i>
                    <p>Search for products or select a category</p>
                </div>
            </div>
        `,
        showConfirmButton: false,
        showCancelButton: true,
        cancelButtonText: 'Close',
        didOpen: () => {
            let timeout = null;
            
            document.getElementById('searchQuery').addEventListener('keyup', function(e) {
                clearTimeout(timeout);
                const query = this.value.trim();
                const category = document.getElementById('categoryFilter').value;
                
                if(query.length >= 2 || category) {
                    timeout = setTimeout(() => {
                        loadProducts(query, category);
                    }, 500);
                }
            });
            
            document.getElementById('categoryFilter').addEventListener('change', function() {
                const query = document.getElementById('searchQuery').value.trim();
                loadProducts(query, this.value);
            });
        }
    }).then((result) => {
        if (result.dismiss) {
            const prModal = new bootstrap.Modal(document.getElementById('newPRModal'));
            prModal.show();
        }
    });
}

// ========== LOAD PRODUCTS ==========
function loadProducts(search = '', category = '') {
    const productList = document.getElementById('productList');
    productList.innerHTML = '<div class="text-center"><div class="spinner-border text-primary"></div></div>';
    
    let url = 'api/supplier_products.php?action=search';
    if (search) {
        url += `&q=${encodeURIComponent(search)}`;
    } else if (category) {
        url += `&category=${encodeURIComponent(category)}`;
    } else {
        url += '&featured=1';
    }
    
    fetch(url)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.data.length > 0) {
                displayProductGrid(data.data);
            } else {
                productList.innerHTML = `
                    <div class="alert alert-info text-center">
                        No products found
                    </div>
                `;
            }
        })
        .catch(err => {
            console.error('Error:', err);
            productList.innerHTML = '<div class="alert alert-danger">Error loading products</div>';
        });
}

// ========== DISPLAY PRODUCT GRID ==========
function displayProductGrid(products) {
    const productList = document.getElementById('productList');
    
    let html = '<div class="row g-3">';
    
    products.forEach(prod => {
        const isAvailable = prod.stock >= prod.min_order_qty;
        const stockBadge = !isAvailable ? 
            '<span class="badge bg-warning">Insufficient Stock</span>' :
            (prod.stock > 0 ? '<span class="badge bg-success">In Stock</span>' : '<span class="badge bg-danger">Out of Stock</span>');
        
        const photoHtml = prod.photo_path ? 
            `<img src="${prod.photo_path}" class="card-img-top" style="height: 150px; object-fit: cover;">` : 
            `<div class="bg-light d-flex align-items-center justify-content-center" style="height: 150px;">
                <i class="bi bi-image text-muted" style="font-size: 3rem;"></i>
            </div>`;
        
        html += `
            <div class="col-md-6">
                <div class="card h-100 ${!isAvailable ? 'opacity-50' : ''}">
                    ${photoHtml}
                    <div class="card-body">
                        <h6 class="card-title">${prod.product_name}</h6>
                        <p class="card-text small">
                            <span class="text-primary fw-bold">₱${parseFloat(prod.cost_price).toFixed(2)}</span><br>
                            <span>Supplier: ${prod.supplier_name}</span><br>
                            <span>Stock: ${prod.stock} | Min: ${prod.min_order_qty}</span><br>
                            <span>Lead: ${prod.lead_time_days || 3} days</span>
                        </p>
                        ${stockBadge}
                    </div>
                    <div class="card-footer bg-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <input type="number" class="form-control form-control-sm" 
                                   style="width: 80px;" id="qty_${prod.id}" 
                                   value="${prod.min_order_qty || 1}" 
                                   min="${prod.min_order_qty || 1}" max="${prod.stock}">
                            <button class="btn btn-sm btn-primary" 
                                    onclick="addToCart(${prod.id}, ${prod.supplier_id}, '${prod.supplier_name.replace(/'/g, "\\'")}', '${prod.product_name.replace(/'/g, "\\'")}', ${prod.cost_price}, ${prod.stock}, ${prod.min_order_qty || 1}, ${prod.lead_time_days || 3}, '${prod.photo_path || ''}')"
                                    ${!isAvailable ? 'disabled' : ''}>
                                <i class="bi bi-cart-plus"></i> Add
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    });
    
    html += '</div>';
    productList.innerHTML = html;
}

// ========== ADD TO CART ==========
function addToCart(productId, supplierId, supplierName, productName, price, stock, minOrder, leadTime, photoPath) {
    const qtyInput = document.getElementById(`qty_${productId}`);
    const quantity = parseInt(qtyInput.value);
    
    if (quantity < minOrder) {
        Swal.fire('Error', `Minimum order is ${minOrder}`, 'error');
        return;
    }
    
    if (quantity > stock) {
        Swal.fire('Error', `Only ${stock} available`, 'error');
        return;
    }
    
    const itemData = {
        supplier_product_id: productId,
        supplier_id: supplierId,
        supplier_name: supplierName,
        item_name: productName,
        quantity: quantity,
        unit_price: price,
        photo_path: photoPath,
        min_order: minOrder,
        lead_time: leadTime,
        stock: stock
    };
    
    Swal.close();
    
    setTimeout(() => {
        const prModal = new bootstrap.Modal(document.getElementById('newPRModal'));
        prModal.show();
        addPRItem(itemData, 'search');
    }, 300);
}

// ========== CALCULATION FUNCTIONS ==========
function calculatePRItemTotal(rowId) {
    const quantity = parseFloat(document.getElementById(`quantity_${rowId}`).value) || 0;
    const unitPrice = parseFloat(document.getElementById(`unit_price_${rowId}`).value) || 0;
    const total = quantity * unitPrice;
    
    document.getElementById(`total_price_${rowId}`).value = total.toFixed(2);
    calculatePRTotal();
}

function calculatePRTotal() {
    let total = 0;
    document.querySelectorAll('input[id^="total_price_"]').forEach(input => {
        total += parseFloat(input.value) || 0;
    });
    document.getElementById('prTotal').textContent = '₱' + total.toFixed(2);
}

// ========== REMOVE ITEM ==========
function removePRItem(rowId) {
    const row = document.getElementById(`itemRow_${rowId}`);
    if (row) {
        row.remove();
        calculatePRTotal();
        
        if (document.querySelectorAll('tr[id^="itemRow_"]').length === 0) {
            document.getElementById('prItemsBody').innerHTML = `
                <tr id="noItemsRow">
                    <td colspan="7" class="text-center text-muted py-3">
                        No items added. Click "Add Item" to start.
                    </td>
                </tr>
            `;
        }
    }
}

// ========== SUBMIT PR FORM ==========
function submitPRForm(status = 'submit') {
    const form = document.getElementById('newPRForm');
    
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    const formData = new FormData(form);
    
    const items = [];
    document.querySelectorAll('tr[id^="itemRow_"]').forEach(row => {
        const rowId = row.id.replace('itemRow_', '');
        
        const itemName = document.querySelector(`input[name="items[${rowId}][item_name]"]`)?.value;
        const supplierId = document.querySelector(`input[name="items[${rowId}][supplier_id]"]`)?.value;
        const supplierName = document.querySelector(`input[name="items[${rowId}][supplier_name]"]`)?.value;
        const quantity = document.getElementById(`quantity_${rowId}`)?.value;
        const unitPrice = document.getElementById(`unit_price_${rowId}`)?.value;
        const totalPrice = document.getElementById(`total_price_${rowId}`)?.value;
        
        if (!itemName || !supplierId) {
            return;
        }
        
        items.push({
            item_name: itemName,
            description: document.getElementById(`description_${rowId}`)?.value || '',
            current_stock: 0,
            quantity: quantity,
            unit_price: unitPrice,
            total_price: totalPrice,
            supplier_id: supplierId,
            supplier_name: supplierName
        });
    });
    
    if (items.length === 0) {
        Swal.fire('Error', 'Please add at least one item', 'error');
        return;
    }
    
    const action = currentPRId ? 'update' : 'create';
    formData.append('action', action);
    if (currentPRId) formData.append('pr_id', currentPRId);
    formData.append('status', status === 'draft' ? 'Draft' : 'Pending Approval');
    formData.append('items', JSON.stringify(items));
    
    Swal.fire({
        title: 'Saving...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch('api/purchase_request.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: data.message,
                timer: 1500,
                showConfirmButton: false
            }).then(() => {
                $('#newPRModal').modal('hide');
                window.location.reload();
            });
        } else {
            Swal.fire('Error', data.error || 'An error occurred', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'An error occurred', 'error');
    });
}

function saveAsDraft() {
    submitPRForm('draft');
}

// ========== VIEW PR ==========
function viewPR(prId) {
    fetch(`api/purchase_request.php?action=get&id=${prId}`)
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const pr = data.data;
            let itemsHtml = '';
            
            if (pr.items && pr.items.length > 0) {
                itemsHtml = `
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Item</th>
                                <th class="text-center">Quantity</th>
                                <th class="text-end">Unit Price</th>
                                <th class="text-end">Total</th>
                                <th>Supplier</th>
                            </tr>
                        </thead>
                        <tbody>`;
                
                pr.items.forEach(item => {
                    const total = item.total_price || (item.quantity * item.unit_price);
                    
                    itemsHtml += `<tr>
                        <td>
                            <strong>${item.item_name}</strong>
                            ${item.description ? `<br><small class="text-muted">${item.description}</small>` : ''}
                        </td>
                        <td class="text-center">${item.quantity}</td>
                        <td class="text-end">₱${parseFloat(item.unit_price).toFixed(2)}</td>
                        <td class="text-end fw-bold">₱${parseFloat(total).toFixed(2)}</td>
                        <td>
                            <span class="badge bg-info">${item.supplier_name || 'Not specified'}</span>
                        </td>
                    </tr>`;
                });
                
                itemsHtml += '</tbody></table></div>';
            }
            
            // Rejection History Section
            let rejectionHtml = '';
            if (pr.rejection_notes && pr.rejection_notes.length > 0) {
                rejectionHtml = `
                <div class="mt-4">
                    <h6 class="text-danger">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        Rejection History:
                    </h6>
                    <div class="border-start border-danger border-3 ps-3">`;
                
                pr.rejection_notes.forEach((note, index) => {
                    const date = new Date(note.date).toLocaleString();
                    const icon = note.type === 'supplier' ? 'bi-shop' : 'bi-cash-stack';
                    const color = note.type === 'supplier' ? 'danger' : 'warning';
                    
                    rejectionHtml += `
                        <div class="mb-3">
                            <div class="d-flex align-items-center">
                                <span class="badge bg-${color} me-2">
                                    <i class="bi ${icon}"></i> ${note.type === 'supplier' ? 'Supplier' : 'Finance'}
                                </span>
                                <small class="text-muted">${date}</small>
                            </div>
                            <p class="mb-1">${note.message}</p>
                            ${note.po_number ? `<small class="text-muted">PO #: ${note.po_number}</small>` : ''}
                            ${index < pr.rejection_notes.length - 1 ? '<hr class="my-2">' : ''}
                        </div>`;
                });
                
                rejectionHtml += '</div></div>';
            }
            
            // Approval Notes Section (for Finance comments)
            let approvalHtml = '';
            if (pr.approval_notes) {
                approvalHtml = `
                <div class="mt-3">
                    <strong>Finance Notes:</strong>
                    <div class="border p-2 rounded bg-light">
                        <i class="bi bi-chat-quote me-1"></i>
                        ${pr.approval_notes}
                    </div>
                </div>`;
            }
            
            const html = `
                <div class="row mb-3">
                    <div class="col-md-8">
                        <h5 class="text-primary">${pr.pr_number}</h5>
                        <p><strong>Department:</strong> ${pr.department || 'N/A'}</p>
                        <p><strong>Purpose:</strong> ${pr.purpose || 'N/A'}</p>
                        <p><strong>Requested by:</strong> ${pr.requested_by_name || 'Unknown'}</p>
                        <p><strong>Date Needed:</strong> ${pr.needed_by ? new Date(pr.needed_by).toLocaleDateString() : 'N/A'}</p>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-subtitle mb-2 text-muted">Status</h6>
                                <h5><span class="badge bg-${getStatusColor(pr.status)}">${pr.status || 'Draft'}</span></h5>
                                
                                <h6 class="card-subtitle mt-3 mb-2 text-muted">Priority</h6>
                                <h5><span class="badge bg-${getPriorityColor(pr.priority)}">${pr.priority || 'Medium'}</span></h5>
                                
                                <h6 class="card-subtitle mt-3 mb-2 text-muted">Total Amount</h6>
                                <h4 class="text-primary">₱${parseFloat(pr.total_amount || 0).toFixed(2)}</h4>
                            </div>
                        </div>
                    </div>
                </div>
                
                <h6 class="mt-4 mb-2">Requested Items:</h6>
                ${itemsHtml || '<p class="text-muted">No items found</p>'}
                
                ${approvalHtml}
                
                ${pr.notes ? `
                <div class="mt-3">
                    <strong>Requestor Notes:</strong>
                    <div class="border p-2 rounded bg-light">
                        <i class="bi bi-chat-dots me-1"></i>
                        ${pr.notes}
                    </div>
                </div>` : ''}
                
                ${rejectionHtml}
            `;
            
            document.getElementById('viewPRContent').innerHTML = html;
            new bootstrap.Modal(document.getElementById('viewPRModal')).show();
        } else {
            Swal.fire('Error', data.error || 'Failed to load PR details', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'Failed to load PR details', 'error');
    });
}

// ========== EDIT PR ==========
function editPR(prId) {
    Swal.fire({
        title: 'Loading PR data...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch('api/inventory.php?action=get_all_items')
        .then(response => {
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            return response.json();
        })
        .then(data => {
            if (data.success) {
                inventoryItems = data.data;
                return fetch(`api/purchase_request.php?action=get&id=${prId}`);
            } else {
                throw new Error('Failed to load inventory items');
            }
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const pr = data.data;
                
                document.querySelector('select[name="department"]').value = pr.department;
                document.querySelector('select[name="priority"]').value = pr.priority;
                document.querySelector('input[name="needed_by"]').value = pr.needed_by;
                document.querySelector('textarea[name="purpose"]').value = pr.purpose;
                document.querySelector('textarea[name="notes"]').value = pr.notes || '';
                
                document.getElementById('prItemsBody').innerHTML = '';
                
                if (pr.items && pr.items.length > 0) {
                    pr.items.forEach((item) => {
                        const inventoryItem = inventoryItems.find(inv => 
                            inv.name === item.item_name || inv.item_id === item.item_id
                        );
                        
                        const itemData = {
                            item_id: inventoryItem ? inventoryItem.id : null,
                            item_name: item.item_name,
                            description: item.description || '',
                            current_stock: item.current_stock || 0,
                            quantity: item.quantity,
                            unit_price: item.unit_price,
                            total_price: item.total_price,
                            supplier_id: item.supplier_id || null,
                            supplier_name: item.supplier_name || ''
                        };
                        
                        addPRItem(itemData);
                    });
                }
                
                currentPRId = prId;
                
                const modal = new bootstrap.Modal(document.getElementById('newPRModal'));
                modal.show();
            } else {
                Swal.fire('Error', data.error || 'Failed to load PR', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.close();
            Swal.fire('Error', 'Failed to load data: ' + error.message, 'error');
        });
}

// ========== SUBMIT/APPROVE/REJECT PR ==========
function submitPR(prId) {
    Swal.fire({
        title: 'Submit for Approval',
        text: 'Are you sure?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Submit'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'submit');
            formData.append('pr_id', prId);
            
            fetch('api/purchase_request.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Success', data.message, 'success').then(() => {
                       window.location.href = currentPageUrl;
                    });
                } else {
                    Swal.fire('Error', data.error, 'error');
                }
            });
        }
    });
}

function deletePR(prId, prNumber) {
    Swal.fire({
        title: 'Delete PR?',
        html: `Delete <strong>${prNumber}</strong>?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Delete'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('pr_id', prId);
            
            fetch('api/purchase_request.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Deleted!', data.message, 'success').then(() => {
                        window.location.href = currentPageUrl;
                    });
                } else {
                    Swal.fire('Error', data.error, 'error');
                }
            });
        }
    });
}

// ========== RECEIVE ORDER FUNCTIONS ==========



// Render Receive Items
function renderReceiveItems(items) {
    const container = document.getElementById('itemsContainer');
    if (!container) return;
    
    if (!items || items.length === 0) {
        container.innerHTML = '<div class="text-center text-muted py-4">No items found</div>';
        return;
    }
    
    let html = '';
    items.forEach((item, index) => {
        const photoPath = item.photo_path || 'assets/img/no-image.png';
        const hasPhoto = item.photo_path ? true : false;
        
        html += `
            <div class="border rounded-3 p-2 bg-white">
                <div class="row g-2 align-items-center">
                    <div class="col-md-6">
                        <div class="d-flex gap-2">
                            <div class="flex-shrink-0">
                                ${hasPhoto ? 
                                    `<img src="${photoPath}" style="width: 48px; height: 48px; object-fit: cover; border-radius: 4px;">` : 
                                    `<div class="bg-secondary bg-opacity-10 rounded-2 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                                        <i class="bi bi-image text-secondary"></i>
                                    </div>`
                                }
                            </div>
                            <div>
                                <div class="fw-medium small">${item.item_name}</div>
                                <small class="text-secondary">Ordered: ${item.quantity}</small>
                                <small class="text-secondary ms-2">₱${formatNumber(item.unit_price)}</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <label class="form-label small mb-1">Quantity to Receive</label>
                        <input type="number" class="form-control form-control-sm received-qty" 
                               id="received_${index}" value="${item.quantity}" 
                               min="0" max="${item.quantity}" 
                               onchange="validateReceivedQty(${index})">
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label small mb-1">Remarks</label>
                        <input type="text" class="form-control form-control-sm" 
                               id="remark_${index}" placeholder="Optional">
                    </div>
                </div>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

// Validate Received Quantity
function validateReceivedQty(index) {
    const receivedInput = document.getElementById(`received_${index}`);
    const maxQty = parseInt(receivedInput.max) || 0;
    let value = parseInt(receivedInput.value) || 0;
    
    if (value > maxQty) {
        receivedInput.value = maxQty;
        Swal.fire({
            icon: 'warning',
            title: 'Warning',
            text: `Received quantity cannot exceed ordered quantity (${maxQty})`,
            timer: 2000
        });
    }
}



// ========== RECEIVE ORDER FUNCTIONS ==========

// Open Receive Order Modal
window.openReceiveOrderModal = function(prId) {
    currentCompletePRId = prId;
    currentRating = 0;
    selectedTags = [];
    
    // Reset stars
    for (let i = 1; i <= 5; i++) {
        const star = document.getElementById(`star${i}`);
        if (star) star.className = 'bi bi-star fs-4 text-warning';
    }
    
    // Reset tags
    document.querySelectorAll('.badge[onclick="toggleTag(this)"]').forEach(tag => {
        tag.classList.remove('bg-success', 'text-white');
        tag.classList.add('bg-light', 'text-dark');
    });
    
    const itemsContainer = document.getElementById('itemsContainer');
    if (itemsContainer) {
        itemsContainer.innerHTML = '<div class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div> Loading items...</div>';
    }
    
    Swal.fire({
        title: 'Loading order details...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    // Load inventory items for selection
    fetch('api/inventory.php?action=get_items&limit=1000')
        .then(res => res.json())
        .then(invData => {
            if (invData.success) {
                window.inventoryList = invData.data;
                console.log('Inventory loaded:', window.inventoryList);
            }
            return fetch(`api/purchase_request.php?action=get_pr_details&id=${prId}`);
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            
            if (data.success) {
                const pr = data.data;
                window.currentOrderItems = pr.items;
                
                const setElementContent = (id, value) => {
                    const el = document.getElementById(id);
                    if (el) el.textContent = value || 'N/A';
                };
                
                setElementContent('modal_po_number', pr.po_number);
                setElementContent('modal_pr_number', pr.pr_number);
                setElementContent('modal_tracking', pr.tracking_number || 'No tracking yet');
                setElementContent('modal_carrier', pr.carrier || 'Not specified');
                setElementContent('modal_order_date', pr.order_date ? new Date(pr.order_date).toLocaleDateString() : 'N/A');
                setElementContent('modal_total_amount', '₱' + formatNumber(pr.total_amount));
                setElementContent('modal_approved_date', pr.approved_at ? new Date(pr.approved_at).toLocaleDateString() : 'N/A');
                setElementContent('modal_shipped_date', pr.shipped_date ? new Date(pr.shipped_date).toLocaleDateString() : 'N/A');
                
                setElementContent('modal_supplier', pr.supplier_name);
                setElementContent('modal_contact', pr.contact_person);
                setElementContent('modal_contact_no', pr.supplier_phone);
                setElementContent('modal_address', pr.supplier_address);
                
                // Render items with inventory selection
                renderReceiveItemsWithInventory(pr.items || []);
                
                setTimeout(() => {
                    const modalElement = document.getElementById('receiveOrderModal');
                    if (modalElement) {
                        const modal = new bootstrap.Modal(modalElement);
                        modal.show();
                    }
                }, 100);
            } else {
                Swal.fire('Error', data.error || 'Failed to load order details', 'error');
            }
        })
        .catch(error => {
            Swal.close();
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to load order details', 'error');
        });
};

// Render Receive Items WITH INVENTORY SELECTION - READONLY QUANTITY
function renderReceiveItemsWithInventory(items) {
    const container = document.getElementById('itemsContainer');
    if (!container) return;
    
    if (!items || items.length === 0) {
        container.innerHTML = '<div class="text-center text-muted py-4">No items found</div>';
        return;
    }
    
    let html = '';
    items.forEach((item, index) => {
        const photoPath = item.photo_path || 'assets/img/no-image.png';
        const hasPhoto = item.photo_path ? true : false;
        
        // Generate inventory options
        let inventoryOptions = '<option value="">-- Select Inventory Item --</option>';
        inventoryOptions += '<option value="new">➕ Create New Inventory Item</option>';
        
        if (window.inventoryList && window.inventoryList.length > 0) {
            window.inventoryList.forEach(inv => {
                inventoryOptions += `<option value="${inv.id}">${inv.name} (Current Stock: ${inv.stock})</option>`;
            });
        }
        
        html += `
            <div class="border rounded-3 p-3 mb-2 bg-white">
                <div class="row g-3">
                    <!-- Item Info with Photo -->
                    <div class="col-md-12">
                        <div class="d-flex gap-3">
                            <div class="flex-shrink-0">
                                ${hasPhoto ? 
                                    `<img src="${photoPath}" style="width: 60px; height: 60px; object-fit: cover; border-radius: 4px;">` : 
                                    `<div class="bg-secondary bg-opacity-10 rounded-2 d-flex align-items-center justify-content-center" style="width: 60px; height: 60px;">
                                        <i class="bi bi-image text-secondary fs-4"></i>
                                    </div>`
                                }
                            </div>
                            <div class="flex-grow-1">
                                <div class="fw-medium">${item.item_name}</div>
                                <div class="small text-secondary">
                                    Ordered: ${item.quantity} × ₱${formatNumber(item.unit_price)}
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Receive Quantity - READONLY -->
                    <div class="col-md-4">
                        <label class="form-label fw-medium small">Quantity to Receive</label>
                        <input type="number" class="form-control form-control-sm received-qty" 
                               id="received_${index}" value="${item.quantity}" 
                               min="0" max="${item.quantity}" 
                               data-index="${index}"
                               readonly
                               style="background-color: #f8f9fa; cursor: not-allowed;">
                        <small class="text-muted">Full quantity will be received</small>
                    </div>
                    
                    <!-- Inventory Selection -->
                    <div class="col-md-5">
                        <label class="form-label fw-medium small">Add to Inventory</label>
                        <select class="form-select form-select-sm inventory-select" 
                                id="inventory_select_${index}" 
                                data-index="${index}"
                                onchange="toggleNewInventoryInput(${index})">
                            ${inventoryOptions}
                        </select>
                        
                        <!-- New Inventory Name Input (shown when "Create New" is selected) -->
                        <div id="new_inventory_container_${index}" style="display: none; margin-top: 8px;">
                            <input type="text" class="form-control form-control-sm" 
                                   id="new_inventory_name_${index}" 
                                   placeholder="Enter new inventory item name" 
                                   value="${item.item_name}">
                            <small class="text-muted">This will create a new item in inventory</small>
                        </div>
                    </div>
                    
                    <!-- Remarks -->
                    <div class="col-md-3">
                        <label class="form-label fw-medium small">Remarks</label>
                        <input type="text" class="form-control form-control-sm" 
                               id="remark_${index}" placeholder="Optional">
                    </div>
                </div>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

// Toggle New Inventory Input
function toggleNewInventoryInput(index) {
    const select = document.getElementById(`inventory_select_${index}`);
    const newContainer = document.getElementById(`new_inventory_container_${index}`);
    
    if (select && select.value === 'new') {
        newContainer.style.display = 'block';
    } else if (select) {
        newContainer.style.display = 'none';
    }
}

// Validate Received Quantity
function validateReceivedQty(index) {
    const receivedInput = document.getElementById(`received_${index}`);
    const maxQty = parseInt(receivedInput.max) || 0;
    let value = parseInt(receivedInput.value) || 0;
    
    if (value > maxQty) {
        receivedInput.value = maxQty;
        Swal.fire({
            icon: 'warning',
            title: 'Warning',
            text: `Received quantity cannot exceed ordered quantity (${maxQty})`,
            timer: 2000
        });
    }
}

// Submit Receive Order - Simplified (with inventory)
window.submitReceiveOrder = function() {
    if (currentRating === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Rate Seller',
            text: 'Please rate the supplier before confirming receipt',
            confirmButtonText: 'OK'
        });
        return;
    }
    
    const items = [];
    let hasMissingInventory = false;
    
    document.querySelectorAll('.received-qty').forEach((input, index) => {
        const received = parseInt(input.value) || 0;
        const inventorySelect = document.getElementById(`inventory_select_${index}`);
        const itemName = window.currentOrderItems[index]?.item_name || `Item ${index + 1}`;
        
        // Skip if no quantity received (though dapat meron since readonly)
        if (received === 0) return;
        
        // Check if inventory is selected
        if (!inventorySelect || !inventorySelect.value) {
            hasMissingInventory = true;
            Swal.fire({
                icon: 'warning',
                title: 'Missing Inventory',
                text: `Please select inventory for "${itemName}"`,
                timer: 2000
            });
            return;
        }
        
        // Get inventory action
        let inventoryAction = null;
        if (inventorySelect.value === 'new') {
            const newName = document.getElementById(`new_inventory_name_${index}`)?.value || itemName;
            inventoryAction = {
                type: 'create',
                name: newName
            };
        } else if (inventorySelect.value) {
            inventoryAction = {
                type: 'add',
                inventory_id: parseInt(inventorySelect.value)
            };
        }
        
        items.push({
            index: index,
            item_name: itemName,
            received: received,
            remark: document.getElementById(`remark_${index}`)?.value || '',
            inventory_action: inventoryAction
        });
    });
    
    if (hasMissingInventory) return;
    
    if (items.length === 0) {
        Swal.fire('Error', 'No items to receive', 'error');
        return;
    }
    
    // Proceed to confirmation...
    Swal.fire({
        title: 'Confirm Receipt',
        html: generateReceiptSummary(items),
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        confirmButtonText: 'Yes, confirm'
    }).then((result) => {
        if (result.isConfirmed) {
            processReceiveOrder(items);
        }
    });
};

// Generate Receipt Summary
function generateReceiptSummary(items) {
    let html = '<div class="text-start">';
    
    // Rating
    html += '<div class="mb-3">';
    html += '<span class="fw-medium">Rating: </span>';
    for (let i = 1; i <= 5; i++) {
        html += i <= currentRating ? 
            '<i class="bi bi-star-fill text-warning ms-1"></i>' : 
            '<i class="bi bi-star text-warning ms-1"></i>';
    }
    html += '</div>';
    
    // Items summary
    html += '<div class="small">';
    html += '<span class="fw-medium">Items to receive:</span>';
    items.forEach(item => {
        let inventoryText = '';
        if (item.inventory_action) {
            if (item.inventory_action.type === 'create') {
                inventoryText = ` (New: ${item.inventory_action.name})`;
            } else {
                const inv = window.inventoryList.find(i => i.id === item.inventory_action.inventory_id);
                inventoryText = inv ? ` (Add to: ${inv.name})` : '';
            }
        }
        
        html += `<div class="mt-2 p-2 bg-light rounded">
            <div>${item.item_name}</div>
            <div class="text-success">✓ Received: ${item.received} of ${item.ordered}</div>
            ${inventoryText ? `<div class="text-info small">${inventoryText}</div>` : ''}
            ${item.remark ? `<div class="text-muted small">Note: ${item.remark}</div>` : ''}
        </div>`;
    });
    html += '</div>';
    
    html += '</div>';
    return html;
}

// Process Receive Order
function processReceiveOrder(items) {
    Swal.fire({
        title: 'Processing...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    const formData = new FormData();
    formData.append('action', 'receive_order');
    formData.append('pr_id', currentCompletePRId);
    formData.append('received_items', JSON.stringify(items));
    formData.append('delivery_notes', document.getElementById('delivery_notes')?.value || '');
    formData.append('supplier_rating', currentRating);
    formData.append('supplier_tags', JSON.stringify(selectedTags));
    
    fetch('api/purchase_request.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: data.message || 'Order received successfully',
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                bootstrap.Modal.getInstance(document.getElementById('receiveOrderModal')).hide();
                location.reload();
            });
        } else {
            Swal.fire('Error', data.error || 'Failed to process order', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'Network error', 'error');
    });
}

// ========== RETURN/REFUND FUNCTIONS ==========

// Open Return Refund Modal
function openReturnRefundModal() {
    const receiveModal = bootstrap.Modal.getInstance(document.getElementById('receiveOrderModal'));
    if (receiveModal) {
        receiveModal.hide();
    }
    
    const items = window.currentOrderItems || [];
    const container = document.getElementById('returnItemsContainer');
    
    let html = '';
    items.forEach((item, index) => {
        const photoPath = item.photo_path || 'assets/img/no-image.png';
        const hasPhoto = item.photo_path ? true : false;
        
        html += `
            <div class="border rounded-3 p-3">
                <div class="d-flex align-items-center gap-3">
                    <input type="checkbox" class="form-check-input return-item-checkbox" 
                           data-index="${index}" onchange="toggleReturnItem(this)">
                    <div class="flex-shrink-0">
                        ${hasPhoto ? 
                            `<img src="${photoPath}" style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px;">` : 
                            `<div class="bg-secondary bg-opacity-10 rounded-2 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                <i class="bi bi-image text-secondary"></i>
                            </div>`
                        }
                    </div>
                    <div class="flex-grow-1">
                        <div class="fw-medium">${item.item_name}</div>
                        <div class="small text-secondary">
                            Ordered: ${item.quantity} × ₱${formatNumber(item.unit_price)}
                        </div>
                    </div>
                    <div style="width: 100px;">
                        <input type="number" class="form-control form-control-sm return-qty" 
                               data-index="${index}" value="0" min="0" max="${item.quantity}"
                               disabled onchange="calculateRefundTotal()">
                    </div>
                </div>
            </div>
        `;
    });
    
    container.innerHTML = html;
    
    document.getElementById('return_po_number').textContent = 
        document.getElementById('modal_po_number')?.textContent || 'N/A';
    document.getElementById('return_supplier').textContent = 
        document.getElementById('modal_supplier')?.textContent || 'N/A';
    
    const returnModal = new bootstrap.Modal(document.getElementById('returnRefundModal'));
    returnModal.show();
}

// Toggle Return Item
function toggleReturnItem(checkbox) {
    const index = checkbox.dataset.index;
    const qtyInput = document.querySelector(`.return-qty[data-index="${index}"]`);
    
    qtyInput.disabled = !checkbox.checked;
    qtyInput.value = checkbox.checked ? 1 : 0;
    
    calculateRefundTotal();
}

// Calculate Refund Total
function calculateRefundTotal() {
    let subtotal = 0;
    
    document.querySelectorAll('.return-item-checkbox:checked').forEach(checkbox => {
        const index = checkbox.dataset.index;
        const qtyInput = document.querySelector(`.return-qty[data-index="${index}"]`);
        const price = parseFloat(window.currentOrderItems[index]?.unit_price) || 0;
        const qty = parseInt(qtyInput.value) || 0;
        
        subtotal += price * qty;
    });
    
    document.getElementById('refund_subtotal').textContent = '₱' + formatNumber(subtotal);
    document.getElementById('refund_total').textContent = '₱' + formatNumber(subtotal);
}

// Submit Return Request
function submitReturnRequest() {
    const selectedItems = [];
    let hasItems = false;
    
    document.querySelectorAll('.return-item-checkbox:checked').forEach(checkbox => {
        hasItems = true;
        const index = checkbox.dataset.index;
        const qty = parseInt(document.querySelector(`.return-qty[data-index="${index}"]`).value) || 0;
        
        if (qty > 0) {
            selectedItems.push({
                item: window.currentOrderItems[index],
                quantity: qty
            });
        }
    });
    
    if (!hasItems) {
        Swal.fire('Error', 'Please select items to return', 'error');
        return;
    }
    
    const reason = document.getElementById('return_reason').value;
    if (!reason) {
        Swal.fire('Error', 'Please select a reason for return', 'error');
        return;
    }
    
    const contactNumber = document.getElementById('return_contact_number').value;
    if (!contactNumber) {
        Swal.fire('Error', 'Please provide contact number', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Submit Return Request?',
        html: `
            <div class="text-start">
                <p><strong>Items to return:</strong> ${selectedItems.length}</p>
                <p><strong>Reason:</strong> ${reason}</p>
                <p><strong>Refund Method:</strong> ${document.getElementById('refund_method').value}</p>
                <p class="text-warning mt-2">Return request will be sent to supplier for approval</p>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#ffc107',
        confirmButtonText: 'Yes, submit'
    }).then((result) => {
        if (result.isConfirmed) {
            processReturnRequest(selectedItems);
        }
    });
}

// Process Return Request
function processReturnRequest(items) {
    Swal.fire({
        title: 'Submitting...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    const formData = new FormData();
    formData.append('action', 'create_return');
    formData.append('pr_id', currentCompletePRId);
    formData.append('items', JSON.stringify(items));
    formData.append('reason', document.getElementById('return_reason').value);
    formData.append('description', document.getElementById('return_description').value);
    formData.append('refund_method', document.getElementById('refund_method').value);
    formData.append('contact_number', document.getElementById('return_contact_number').value);
    formData.append('email', document.getElementById('return_email').value);
    
    const photos = document.getElementById('return_photos').files;
    for (let i = 0; i < photos.length; i++) {
        formData.append('photos[]', photos[i]);
    }
    
    fetch('api/returns.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Return Request Submitted!',
                text: 'Return reference: ' + data.return_number,
                confirmButtonColor: '#ffc107'
            }).then(() => {
                bootstrap.Modal.getInstance(document.getElementById('returnRefundModal')).hide();
                new bootstrap.Modal(document.getElementById('receiveOrderModal')).show();
            });
        } else {
            Swal.fire('Error', data.error || 'Failed to submit return request', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'Network error', 'error');
    });
}

// ========== RATING FUNCTIONS ==========
function setRating(rating) {
    currentRating = rating;
    
    for (let i = 1; i <= 5; i++) {
        const star = document.getElementById(`star${i}`);
        if (i <= rating) {
            star.className = 'bi bi-star-fill fs-3 text-warning';
        } else {
            star.className = 'bi bi-star fs-3 text-warning';
        }
    }
}

function toggleTag(element) {
    const tag = element.textContent.trim();
    
    if (element.classList.contains('bg-success')) {
        element.classList.remove('bg-success', 'text-white');
        element.classList.add('bg-light', 'text-dark');
        selectedTags = selectedTags.filter(t => t !== tag);
    } else {
        element.classList.remove('bg-light', 'text-dark');
        element.classList.add('bg-success', 'text-white');
        selectedTags.push(tag);
    }
    
    console.log('Selected tags:', selectedTags);
}

// ========== CREATE PO FUNCTIONS ==========
function createPO(prId) {
    const modalElement = document.getElementById('createPOModal');
    if (!modalElement) {
        Swal.fire('Error', 'Modal not found', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Loading Approved PR Details...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch(`api/purchase_request.php?action=get_approved_pr_details&id=${prId}`)
    .then(response => response.json())
    .then(data => {
        Swal.close();
        
        if (data.success) {
            const pr = data.data;
            
            document.getElementById('poPRId').value = prId;
            document.getElementById('poPRNumber').textContent = pr.pr_number || 'N/A';
            document.getElementById('poDepartmentDisplay').textContent = pr.department || 'N/A';
            document.getElementById('poPriorityDisplay').textContent = pr.priority || 'Medium';
            document.getElementById('poPurposeDisplay').textContent = pr.purpose || 'N/A';
            document.getElementById('poRequestedBy').textContent = pr.requested_by_name || 'Unknown';
            document.getElementById('poApprovedBy').textContent = pr.approved_by_name || 'N/A';
            document.getElementById('poApprovedDate').textContent = pr.approved_at ? new Date(pr.approved_at).toLocaleDateString() : 'N/A';
            
            const totalAmount = parseFloat(pr.total_amount || 0).toFixed(2);
            document.getElementById('poTotalAmount').textContent = '₱' + totalAmount;
            document.getElementById('poGrandTotal').textContent = '₱' + totalAmount;
            
            if (pr.supplier_info) {
                document.getElementById('poSupplierId').value = pr.supplier_info.id || '';
                document.getElementById('poSupplierName').textContent = pr.supplier_info.name || 'No supplier assigned';
                document.getElementById('poContactPerson').textContent = pr.supplier_info.contact_person || 'No contact person';
                document.getElementById('poSupplierEmail').textContent = pr.supplier_info.email || 'No email';
                document.getElementById('poSupplierPhone').textContent = pr.supplier_info.phone || pr.supplier_info.mobile || 'No phone';
                document.getElementById('poSupplierAddress').textContent = pr.supplier_info.address || 'No address provided';
                document.getElementById('poPaymentTerms').textContent = pr.supplier_info.payment_terms || 'Net 30';
            } else {
                document.getElementById('poSupplierName').textContent = 'No supplier assigned';
                document.getElementById('poContactPerson').textContent = 'N/A';
                document.getElementById('poSupplierEmail').textContent = 'N/A';
                document.getElementById('poSupplierPhone').textContent = 'N/A';
                document.getElementById('poSupplierAddress').textContent = 'No address provided';
                document.getElementById('poPaymentTerms').textContent = 'Net 30';
            }
            
            renderPOItemsWithPhotos(pr.items || []);
            
            const itemsInput = document.getElementById('poItemsData');
            if (itemsInput) {
                itemsInput.value = JSON.stringify(pr.items || []);
            }
            
            const modal = new bootstrap.Modal(modalElement);
            modal.show();
        } else {
            Swal.fire('Error', data.error || 'Failed to load PR details', 'error');
        }
    })
    .catch(error => {
        Swal.close();
        console.error('Error:', error);
        Swal.fire('Error', 'Failed to load purchase request details', 'error');
    });
}

function renderPOItemsWithPhotos(items) {
    const container = document.getElementById('poItemsContainer');
    const loadingEl = document.getElementById('poItemsLoading');
    const grandTotalEl = document.getElementById('poGrandTotal');
    
    if (!container) return;
    
    if (loadingEl) loadingEl.style.display = 'none';
    
    if (!items || items.length === 0) {
        container.innerHTML = '<div class="text-center text-muted py-4">No items found</div>';
        return;
    }
    
    let html = '';
    let grandTotal = 0;
    
    items.forEach(item => {
        const total = parseFloat(item.total_price || 0);
        grandTotal += total;
        
        const photoPath = item.photo_path || 'assets/img/no-image.png';
        const hasPhoto = item.photo_path ? true : false;
        
        html += `
            <div class="d-flex align-items-center gap-3 p-2 mb-2 bg-white rounded-3 border-0 shadow-sm">
                <div class="flex-shrink-0">
                    ${hasPhoto ? 
                        `<img src="${photoPath}" class="rounded-2" style="width: 50px; height: 50px; object-fit: cover; border: 1px solid #eee;" 
                              onerror="this.onerror=null; this.src='assets/img/no-image.png';">` : 
                        `<div class="bg-secondary bg-opacity-10 rounded-2 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                            <i class="bi bi-image text-secondary"></i>
                        </div>`
                    }
                </div>
                
                <div class="flex-grow-1">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-medium">${item.item_name || ''}</span>
                        <span class="fw-bold text-primary">₱${formatNumber(total)}</span>
                    </div>
                    <div class="d-flex justify-content-between small text-secondary">
                        <span>${item.quantity || 0} × ₱${formatNumber(item.unit_price || 0)}</span>
                        <span>${item.supplier_name || ''}</span>
                    </div>
                    ${item.description ? `<div class="small text-muted mt-1">${item.description}</div>` : ''}
                </div>
            </div>
        `;
    });
    
    container.innerHTML = html;
    
    if (grandTotalEl) {
        grandTotalEl.textContent = '₱' + formatNumber(grandTotal);
    }
}

function createPOFromForm() {
    const form = document.getElementById('createPOForm');
    const formData = new FormData(form);
    formData.append('action', 'create_po');
    
    Swal.fire({
        title: 'Creating Purchase Order...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch('api/purchase_request.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: data.message,
                timer: 1500,
                showConfirmButton: false
            }).then(() => {
                const modal = bootstrap.Modal.getInstance(document.getElementById('createPOModal'));
                if (modal) modal.hide();
                location.reload();
            });
        } else {
            Swal.fire('Error', data.error || 'Failed to create PO', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error', 'Network error', 'error');
    });
}

// ========== HELPER FUNCTIONS ==========
function formatNumber(num) {
    if (num === null || num === undefined || isNaN(num)) return '0.00';
    return parseFloat(num).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
}

function getStatusColor(status) {
    const colors = {
        'Draft': 'secondary',
        'Pending Approval': 'warning',
        'Approved': 'success',
        'Rejected': 'danger',
        'PO Created': 'info',           
        'Pending Supplier Approval': 'warning', 
        'Supplier Approved': 'success', 
        'Supplier Rejected': 'danger',  
        'On Order': 'primary',
        'Completed': 'success'
    };
    return colors[status] || 'secondary';
}

function getPriorityColor(priority) {
    const colors = {
        'Critical': 'danger',
        'High': 'warning',
        'Medium': 'info',
        'Low': 'secondary'
    };
    return colors[priority] || 'secondary';
}