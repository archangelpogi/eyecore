let currentSupplierId = null;
let contactCounter = 0;
let currentPageUrl = window.location.href; // Added for consistency

document.addEventListener('DOMContentLoaded', function() {
    initForms();
    loadSuppliers();
    setupFilterListeners();
});

function initForms() {
    // New Supplier form
    const newSupplierForm = document.getElementById('newSupplierForm');
    if (newSupplierForm) {
        newSupplierForm.addEventListener('submit', function(e) {
            e.preventDefault();
            handleSubmitSupplier();
        });
    }
    
    // Import form
    const importForm = document.getElementById('importForm');
    if (importForm) {
        importForm.addEventListener('submit', function(e) {
            e.preventDefault();
            handleImport();
        });
    }
    
    // Modal reset
    const newSupplierModal = document.getElementById('newSupplierModal');
    if (newSupplierModal) {
        newSupplierModal.addEventListener('hidden.bs.modal', function() {
            resetSupplierForm();
        });
    }
}

function setupFilterListeners() {
    ['statusFilter', 'categoryFilter'].forEach(filterId => {
        const element = document.getElementById(filterId);
        if (element) {
            element.addEventListener('change', applyFilters);
        }
    });
    
    const searchFilter = document.getElementById('searchFilter');
    if (searchFilter) {
        searchFilter.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') applyFilters();
        });
    }
}

function applyFilters() {
    const status = document.getElementById('statusFilter').value;
    const category = document.getElementById('categoryFilter').value;
    const search = document.getElementById('searchFilter').value;
    
    const params = new URLSearchParams();
    if (status !== 'all') params.set('status', status);
    if (category !== 'all') params.set('category', category);
    if (search) params.set('search', search);
    
    const queryString = params.toString();
    const newUrl = window.location.pathname + (queryString ? '?' + queryString : '');
    window.history.pushState({}, '', newUrl);
    
    loadSuppliers();
}

function clearFilters() {
    document.getElementById('statusFilter').value = 'all';
    document.getElementById('categoryFilter').value = 'all';
    document.getElementById('searchFilter').value = '';
    
    window.history.pushState({}, '', window.location.pathname);
    loadSuppliers();
}

function loadSuppliers() {
    const params = new URLSearchParams(window.location.search);
    const status = params.get('status') || 'all';
    const category = params.get('category') || 'all';
    const search = params.get('search') || '';
    
    let url = `api/supplier.php?action=get_suppliers&status=${status}&category=${category}`;
    if (search) url += `&search=${encodeURIComponent(search)}`;
    
    fetch(url)
    .then(response => response.json())
    .then(data => {
        const container = document.getElementById('suppliersGrid');
        
        if (data.success && data.data.length > 0) {
            let html = '';
            
            data.data.forEach(supplier => {
                const ratingStars = '★'.repeat(supplier.rating) + '☆'.repeat(5 - supplier.rating);
                
                html += `
                    <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
                        <div class="card supplier-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div>
                                        <h5 class="card-title mb-1">${supplier.supplier_name}</h5>
                                        <small class="text-muted">${supplier.supplier_code}</small>
                                    </div>
                                    <span class="badge ${getStatusBadgeClass(supplier.status)}">
                                        ${supplier.status}
                                    </span>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="rating-stars mb-2" title="Rating: ${supplier.rating}/5">
                                        ${ratingStars}
                                    </div>
                                    <span class="badge bg-light text-dark category-badge">
                                        ${supplier.category}
                                    </span>
                                </div>
                                
                                <div class="supplier-info mb-3">
                                    ${supplier.contact_person ? `
                                        <p class="mb-1">
                                            <i class="bi bi-person me-2"></i>
                                            ${supplier.contact_person}
                                        </p>
                                    ` : ''}
                                    
                                    ${supplier.email ? `
                                        <p class="mb-1">
                                            <i class="bi bi-envelope me-2"></i>
                                            <a href="mailto:${supplier.email}" class="text-decoration-none">${supplier.email}</a>
                                        </p>
                                    ` : ''}
                                    
                                    ${supplier.phone ? `
                                        <p class="mb-0">
                                            <i class="bi bi-telephone me-2"></i>
                                            ${supplier.phone}
                                        </p>
                                    ` : ''}
                                </div>
                                
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">
                                        ${supplier.city ? supplier.city + ', ' : ''} 
                                        ${supplier.country || ''}
                                    </small>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-info" onclick="viewSupplier(${supplier.id})" title="View">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button class="btn btn-outline-warning" onclick="editSupplier(${supplier.id})" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-outline-danger" onclick="deleteSupplier(${supplier.id}, '${supplier.supplier_name}')" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        } else {
            container.innerHTML = `
                <div class="col-12 text-center py-5">
                    <i class="bi bi-building-x display-6 text-muted mb-3"></i>
                    <h5 class="text-muted">No suppliers found</h5>
                    <p class="text-muted">Try adjusting your search or filters</p>
                    <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#newSupplierModal">
                        <i class="bi bi-plus-circle me-1"></i>Add First Supplier
                    </button>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Error loading suppliers:', error);
        document.getElementById('suppliersGrid').innerHTML = `
            <div class="col-12 text-center py-5 text-danger">
                <i class="bi bi-exclamation-triangle display-6 mb-3"></i>
                <h5>Error loading suppliers</h5>
                <p>Please try again later</p>
            </div>
        `;
    });
}

// CRUD OPERATIONS
function handleSubmitSupplier() {
    const form = document.getElementById('newSupplierForm');
    const formData = new FormData(form);
    
    const action = currentSupplierId ? 'update' : 'create';
    formData.append('action', action);
    if (currentSupplierId) formData.append('id', currentSupplierId);
    
    Swal.fire({
        title: 'Saving...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch('api/supplier.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        Swal.close();
        if (data.success) {
            Swal.fire({
                title: 'Success!',
                text: data.message,
                icon: 'success',
                timer: 1500,
                showConfirmButton: false
            }).then(() => {
                reloadSupplierView();
            });
        } else {
            Swal.fire('Error!', data.error, 'error');
        }
    })
    .catch(error => {
        Swal.close();
        Swal.fire('Error!', 'Network error. Please try again.', 'error');
    });
}

function viewSupplier(id) {
    fetch(`api/supplier.php?action=get&id=${id}`)
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const supplier = data.data;
            const ratingStars = '★'.repeat(supplier.rating) + '☆'.repeat(5 - supplier.rating);
            
            const html = `
                <div class="row">
                    <div class="col-md-8">
                        <h4>${supplier.supplier_name}</h4>
                        <p class="text-muted">${supplier.supplier_code}</p>
                        
                        <div class="mb-4">
                            <span class="badge ${getStatusBadgeClass(supplier.status)} me-2">${supplier.status}</span>
                            <span class="badge bg-light text-dark">${supplier.category}</span>
                            <span class="ms-2 text-warning">${ratingStars}</span>
                        </div>
                        
                        <h6 class="mb-3"><i class="bi bi-info-circle me-2"></i>Contact Information</h6>
                        <div class="row mb-4">
                            ${supplier.contact_person ? `
                                <div class="col-md-6 mb-2">
                                    <strong>Contact Person:</strong><br>
                                    ${supplier.contact_person}
                                </div>
                            ` : ''}
                            
                            ${supplier.email ? `
                                <div class="col-md-6 mb-2">
                                    <strong>Email:</strong><br>
                                    <a href="mailto:${supplier.email}">${supplier.email}</a>
                                </div>
                            ` : ''}
                            
                            ${supplier.phone ? `
                                <div class="col-md-6 mb-2">
                                    <strong>Phone:</strong><br>
                                    ${supplier.phone}
                                </div>
                            ` : ''}
                            
                            ${supplier.mobile ? `
                                <div class="col-md-6 mb-2">
                                    <strong>Mobile:</strong><br>
                                    ${supplier.mobile}
                                </div>
                            ` : ''}
                        </div>
                        
                        ${supplier.address ? `
                            <h6 class="mb-2"><i class="bi bi-geo-alt me-2"></i>Address</h6>
                            <p>${supplier.address}</p>
                            <p>${supplier.city} ${supplier.state ? ', ' + supplier.state : ''} ${supplier.postal_code ? ' - ' + supplier.postal_code : ''}</p>
                        ` : ''}
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6 class="card-title">Financial Information</h6>
                                
                                <div class="mb-3">
                                    <small class="text-muted">Payment Terms</small>
                                    <p class="mb-0">${supplier.payment_terms || 'Net 30'}</p>
                                </div>
                                
                                <div class="mb-3">
                                    <small class="text-muted">Credit Limit</small>
                                    <h5 class="mb-0">₱${parseFloat(supplier.credit_limit || 0).toLocaleString()}</h5>
                                </div>
                                
                                <div class="mb-3">
                                    <small class="text-muted">Current Balance</small>
                                    <h5 class="mb-0 ${parseFloat(supplier.current_balance || 0) > 0 ? 'text-danger' : 'text-success'}">
                                        ₱${parseFloat(supplier.current_balance || 0).toLocaleString()}
                                    </h5>
                                </div>
                                
                                ${supplier.tax_id ? `
                                    <div class="mb-3">
                                        <small class="text-muted">Tax ID</small>
                                        <p class="mb-0">${supplier.tax_id}</p>
                                    </div>
                                ` : ''}
                                
                                ${supplier.website ? `
                                    <div class="mb-3">
                                        <small class="text-muted">Website</small>
                                        <p class="mb-0">
                                            <a href="${supplier.website}" target="_blank">${supplier.website}</a>
                                        </p>
                                    </div>
                                ` : ''}
                            </div>
                        </div>
                        
                        ${supplier.notes ? `
                            <div class="card bg-light mt-3">
                                <div class="card-body">
                                    <h6 class="card-title">Notes</h6>
                                    <p class="mb-0">${supplier.notes}</p>
                                </div>
                            </div>
                        ` : ''}
                    </div>
                </div>
                
                ${supplier.created_at ? `
                    <div class="mt-4 pt-3 border-top">
                        <small class="text-muted">
                            Created: ${new Date(supplier.created_at).toLocaleDateString()}
                            ${supplier.updated_at ? ' | Updated: ' + new Date(supplier.updated_at).toLocaleDateString() : ''}
                        </small>
                    </div>
                ` : ''}
            `;
            
            document.getElementById('viewSupplierContent').innerHTML = html;
            currentSupplierId = supplier.id;
            new bootstrap.Modal(document.getElementById('viewSupplierModal')).show();
        } else {
            Swal.fire('Error', data.error, 'error');
        }
    });
}

function editCurrentSupplier() {
    $('#viewSupplierModal').modal('hide');
    editSupplier(currentSupplierId);
}

function editSupplier(id) {
    fetch(`api/supplier.php?action=get&id=${id}`)
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const supplier = data.data;
            
            // Populate form
            Object.keys(supplier).forEach(key => {
                const element = document.querySelector(`[name="${key}"]`);
                if (element && supplier[key] !== null) {
                    element.value = supplier[key];
                }
            });
            
            currentSupplierId = supplier.id;
            
            // Activate first tab
            document.querySelector('#basic-tab').click();
            
            new bootstrap.Modal(document.getElementById('newSupplierModal')).show();
        }
    });
}

function deleteSupplier(id, name) {
    Swal.fire({
        title: 'Delete Supplier?',
        html: `Are you sure you want to delete <strong>${name}</strong>?<br>This action cannot be undone.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);
            
            Swal.fire({
                title: 'Deleting...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            fetch('api/supplier.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                if (data.success) {
                    Swal.fire({
                        title: 'Deleted!',
                        text: data.message,
                        icon: 'success',
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        reloadSupplierView();
                    });
                } else {
                    Swal.fire('Error!', data.error, 'error');
                }
            });
        }
    });
}

// OTHER FUNCTIONS
function showImportModal() {
    new bootstrap.Modal(document.getElementById('importModal')).show();
}

function handleImport() {
    const formData = new FormData(document.getElementById('importForm'));
    formData.append('action', 'import');
    
    Swal.fire({
        title: 'Importing...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch('api/supplier.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        Swal.close();
        if (data.success) {
            Swal.fire({
                title: 'Import Successful!',
                html: `Imported ${data.imported} suppliers successfully.`,
                icon: 'success',
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                $('#importModal').modal('hide');
                reloadSupplierView();
            });
        } else {
            Swal.fire('Import Failed!', data.error, 'error');
        }
    });
}

function downloadTemplate() {
    // Create CSV template
    const headers = [
        'supplier_code', 'supplier_name', 'contact_person', 'email', 'phone',
        'mobile', 'fax', 'address', 'city', 'state', 'country', 'postal_code',
        'tax_id', 'website', 'category', 'status', 'payment_terms', 
        'credit_limit', 'current_balance', 'rating', 'notes'
    ];
    
    const csvContent = headers.join(',') + '\n' +
        'SUP-001,Example Supplier,John Doe,john@example.com,+639123456789,,,,Makati City,Metro Manila,Philippines,1200,,https://example.com,Medical Supplies,Active,Net 30,100000,0,4,"Notes here"';
    
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'supplier_template.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}

function exportSuppliers() {
    const params = new URLSearchParams(window.location.search);
    params.set('action', 'export');
    window.location.href = `api/supplier.php?${params.toString()}`;
}

function showSupplierReport() {
    fetch('api/supplier.php?action=report')
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            let html = `
                <div class="row">
                    <div class="col-md-6">
                        <h6>Suppliers by Category</h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr><th>Category</th><th>Count</th><th>%</th></tr>
                                </thead>
                                <tbody>`;
            
            data.report.by_category.forEach(item => {
                html += `<tr><td>${item.category}</td><td>${item.count}</td><td>${item.percentage}%</td></tr>`;
            });
            
            html += `</tbody></table></div></div>
                    <div class="col-md-6">
                        <h6>Suppliers by Status</h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr><th>Status</th><th>Count</th><th>%</th></tr>
                                </thead>
                                <tbody>`;
            
            data.report.by_status.forEach(item => {
                html += `<tr><td>${item.status}</td><td>${item.count}</td><td>${item.percentage}%</td></tr>`;
            });
            
            html += `</tbody></table></div></div>
                </div>
                <div class="mt-3">
                    <h6>Credit Summary</h6>
                    <p>Total Credit Limit: ₱${parseFloat(data.report.total_credit_limit).toLocaleString()}</p>
                    <p>Total Balance: ₱${parseFloat(data.report.total_balance).toLocaleString()}</p>
                </div>`;
            
            Swal.fire({
                title: 'Supplier Report',
                html: html,
                width: 800,
                showCloseButton: true,
                showConfirmButton: false
            });
        }
    });
}

function updateStats() {
    // Refresh stats
    fetch('api/supplier.php?action=stats')
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update stat cards here if needed
        }
    });
}

// NEW FUNCTION: RELOAD SUPPLIER VIEW (Gaya ng sa purchase_request.js)
function reloadSupplierView() {
    // I-refresh ang suppliers list
    loadSuppliers();
    
    // I-refresh ang statistics
    updateStats();
    
    // Isara ang lahat ng active modals gaya ng ginagawa sa purchase_request.js
    const modals = ['newSupplierModal', 'viewSupplierModal', 'importModal'];
    modals.forEach(modalId => {
        const modalElement = document.getElementById(modalId);
        if (modalElement) {
            const modal = bootstrap.Modal.getInstance(modalElement);
            if (modal) {
                modal.hide();
            }
        }
    });
    
    // I-reset ang form gaya ng sa purchase_request.js
    resetSupplierForm();
    
    // I-reset ang current supplier ID
    currentSupplierId = null;
    
    // Mag-scroll sa taas ng page
    window.scrollTo(0, 0);
    
    // Mag-show ng console log para sa debugging (optional)
    console.log('Supplier view reloaded successfully');
}

function resetSupplierForm() {
    const form = document.getElementById('newSupplierForm');
    if (form) {
        form.reset();
        const categorySelect = document.querySelector('select[name="category"]');
        const statusSelect = document.querySelector('select[name="status"]');
        const ratingSelect = document.querySelector('select[name="rating"]');
        
        if (categorySelect) categorySelect.value = 'General';
        if (statusSelect) statusSelect.value = 'Active';
        if (ratingSelect) ratingSelect.value = '4';
    }
    currentSupplierId = null;
}

// HELPER FUNCTIONS
function getStatusBadgeClass(status) {
    const classes = {
        'Active': 'bg-success',
        'Inactive': 'bg-secondary',
        'Pending': 'bg-warning',
        'Suspended': 'bg-danger'
    };
    return classes[status] || 'bg-secondary';
}

function getRatingColor(rating) {
    if (rating >= 4) return 'text-success';
    if (rating >= 3) return 'text-warning';
    return 'text-danger';
}