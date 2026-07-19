let currentExpenseId = null;
let currentPage = 1;
let budgetChart = null;


$(document).ready(function() {
    try {
        console.log('=== DOCUMENT READY START ===');
        
        // Initialize components
        console.log('Calling loadStats...');
        loadStats();
        
        console.log('Calling loadPendingPRs...');
        loadPendingPRs();
        
        console.log('Calling loadExpenses pending...');
        loadExpenses('pending');
        
        console.log('Calling loadExpenses all...');
        loadExpenses('all');
        
        console.log('Calling loadExpenses paid...');
        loadExpenses('paid');
        
        console.log('Calling loadExpenses overdue...');
        loadExpenses('overdue');
        
        console.log('Calling loadBudgetData...');
        loadBudgetData();
        
        // Set up event listeners
        console.log('Calling setupEventListeners...');
        setupEventListeners();
        
        // Initialize DataTables
        console.log('Calling initializeDataTables...');
        initializeDataTables();
        
        console.log('=== DOCUMENT READY COMPLETE ===');
        
    } catch(e) {
        console.error('ERROR in document ready:', e);
        console.error('Stack trace:', e.stack);
    }
});

// Load on tab click
$('button[data-bs-target="#budgetView"]').on('click', loadBudgetData);
// Auto-compute total
$('.budget-amount').on('input', function() {
    let total = 0;
    $('.budget-amount').each(function() {
        total += parseFloat($(this).val()) || 0;
    });
    $('#totalBudgetDisplay').text('₱' + total.toFixed(2));
});

// ============================================
// EVENT LISTENERS
// ============================================
function setupEventListeners() {
    // Month selector change
    $('#monthSelector').on('change', function() {
        currentMonth = $(this).val();
        loadStats();
        loadExpenses('all');
        loadBudgetData();
    });
    
    // Category filter change
    $('#categoryFilter').on('change', function() {
        loadExpenses('all');
    });
    
    // Approve PR form submit
    $('#approvePRForm').on('submit', function(e) {
        e.preventDefault();
        approvePurchaseRequest();
    });
    
    // Reject PR form submit
    $('#rejectPRForm').on('submit', function(e) {
        e.preventDefault();
        rejectPurchaseRequest();
    });
    
    // Add Expense form submit
    $('#addExpenseForm').on('submit', function(e) {
        e.preventDefault();
        createExpense();
    });
    
    // Upload Receipt form submit
    $('#uploadReceiptForm').on('submit', function(e) {
        e.preventDefault();
        uploadReceipt();
    });
}

// ============================================
// STATS FUNCTIONS (CONNECTED VERSION)
// ============================================
function loadStats() {
    $.ajax({
        url: 'api/expenses.php',
        method: 'GET',
        data: {
            action: 'get_expenses',
            month: currentMonth,
            limit: 1
        },
        success: function(response) {
            if (response.success && response.stats) {
                renderStats(response.stats);
            }
        },
        error: function() {
            console.error('Failed to load stats');
        }
    });
}

// ============================================
// STATS FUNCTIONS (CONNECTED VERSION) - ITO ANG ITIRAGA MO
// ============================================
function renderStats(stats) {
    const totalMonth = stats.total_month || 0;
    const totalPaid = stats.total_paid || 0;
    const totalPending = stats.total_pending || 0;
    const totalOverdue = stats.total_overdue || 0;
    const totalForApproval = stats.total_for_approval || 0; // DAPAT ITO ANG PR PENDING
    const budgetAllocated = stats.budget_allocated || 0;
    const budgetSpent = stats.budget_spent || 0;
    
    // Calculate budget utilization
    const budgetUtilization = budgetAllocated > 0 ? 
        ((budgetSpent / budgetAllocated) * 100).toFixed(2) : 0;
    
    const html = `
        <div class="col-md-3">
            <div class="stat-card d-flex align-items-center">
                <div class="stat-icon bg-primary bg-opacity-10 text-primary me-3">
                    <i class="bi bi-cash-stack"></i>
                </div>
                <div>
                    <small class="text-muted text-uppercase fw-semibold">Total This Month</small>
                    <h3 class="fw-bold mb-0">₱${formatNumber(totalMonth)}</h3>
                    <small class="text-success">
                        <i class="bi bi-arrow-down"></i> All expenses this month
                    </small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card d-flex align-items-center">
                <div class="stat-icon bg-warning bg-opacity-10 text-warning me-3">
                    <i class="bi bi-clock-history"></i>
                </div>
                <div>
                    <small class="text-muted text-uppercase fw-semibold">Pending Approval</small>
                    <h3 class="fw-bold mb-0">₱${formatNumber(totalForApproval)}</h3>
                    <small class="text-muted">${stats.total_count || 0} PRs awaiting approval</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card d-flex align-items-center">
                <div class="stat-icon bg-danger bg-opacity-10 text-danger me-3">
                    <i class="bi bi-exclamation-triangle"></i>
                </div>
                <div>
                    <small class="text-muted text-uppercase fw-semibold">Overdue</small>
                    <h3 class="fw-bold mb-0">₱${formatNumber(totalOverdue)}</h3>
                    <small class="text-muted">Requires immediate attention</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card d-flex align-items-center">
                <div class="stat-icon bg-info bg-opacity-10 text-info me-3">
                    <i class="bi bi-pie-chart"></i>
                </div>
                <div>
                    <small class="text-muted text-uppercase fw-semibold">Budget Utilization</small>
                    <h3 class="fw-bold mb-0">${budgetUtilization}%</h3>
                    <small class="text-muted">₱${formatNumber(budgetSpent)} of ₱${formatNumber(budgetAllocated)}</small>
                    <div class="progress mt-2" style="height: 6px; width: 150px;">
                        <div class="progress-bar bg-info" style="width: ${budgetUtilization}%"></div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    $('#statsContainer').html(html);
}

function loadPendingPRs() {
    console.log('=== loadPendingPRs FUNCTION CALLED ===');
    console.log('User role:', userRole);
    
    // Check if userRole is defined
    if (typeof userRole === 'undefined') {
        console.error('userRole is undefined!');
        return;
    }
    
    // Check if user is authorized
    if (userRole !== 'Finance' && userRole !== 'ClinicAdmin') {
        console.log('User role not authorized:', userRole);
        return;
    }
    
    console.log('Authorized! Making AJAX call...');
    
    $.ajax({
        url: 'api/expenses.php',
        method: 'GET',
        data: {
            action: 'get_pending_prs'
        },
        success: function(response) {
            console.log('Pending PRs response:', response);
            if (response.success) {
                renderPendingPRs(response.data);
                $('#pendingPrCount').text(response.data.length || 0);
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', status, error);
            $('#pendingPRBody').html(`
                <tr>
                    <td colspan="9" class="text-center py-4 text-danger">
                        <i class="bi bi-exclamation-triangle"></i>
                        Failed to load pending requests: ${xhr.status} ${xhr.statusText}
                    </td>
                </tr>
            `);
        }
    });
}
function renderPendingPRs(prs) {
    const tbody = $('#pendingPRBody');
    
    if (!prs || prs.length === 0) {
        tbody.html(`
            <tr>
                <td colspan="9" class="text-center py-5">
                    <i class="bi bi-check-circle text-success fs-1"></i>
                    <p class="mt-3 text-muted">No pending purchase requests for approval</p>
                </td>
            </tr>
        `);
        return;
    }
    
    let html = '';
    prs.forEach(pr => {
        const priorityClass = getPriorityClass(pr.priority);
        const createdDate = new Date(pr.created_at).toLocaleDateString();
        
        html += `
            <tr>
                <td><strong>${pr.pr_number}</strong></td>
                <td>${pr.department}</td>
                <td>
                    <span title="${pr.purpose}">${truncate(pr.purpose, 40)}</span>
                </td>
                <td>${pr.item_count || 0} items</td>
                <td class="fw-bold">₱${formatNumber(pr.total_amount)}</td>
                <td><span class="${priorityClass}">${pr.priority}</span></td>
                <td>${pr.requested_by_name || 'Unknown'}</td>
                <td><small>${createdDate}</small></td>
                <td>
                    <button class="btn btn-sm btn-outline-info" onclick="viewPRDetails(${pr.id})" title="View Details">
                        <i class="bi bi-eye"></i> View
                    </button>
                </td>
            </tr>
        `;
    });
    
    tbody.html(html);
}

function viewPRDetails(prId) {
    // Show loading
    Swal.fire({
        title: 'Loading PR Details...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    // Fetch PR details with supplier info
    $.ajax({
        url: 'api/purchase_request.php',
        method: 'GET',
        data: {
            action: 'get',
            id: prId,
            include_suppliers: true // Para makuha ang supplier details
        },
        success: function(response) {
            Swal.close();
            if (response.success) {
                showPRDetailsModal(response.data);
            } else {
                Swal.fire('Error', 'Failed to load PR details', 'error');
            }
        },
        error: function(xhr, status, error) {
            Swal.close();
            Swal.fire('Error', 'Network error: ' + error, 'error');
        }
    });
}

// ========== SHOW PR DETAILS MODAL (MAY SCROLLBAR AT CLEAN ALIGNMENT) ==========
function showPRDetailsModal(pr) {
    console.log('PR Data:', pr);
    
    const items = pr.items || [];
    let itemsHtml = '';
    let totalAmount = parseFloat(pr.total_amount || 0);
    
    // Determine category based on department
    const categoryMap = {
        'SCM': 'Office Supplies',
        'Optical': 'Equipment',
        'Clinic': 'Equipment',
        'Admin': 'Office Supplies',
        'Pharmacy': 'Medical Supplies',
        'Laboratory': 'Equipment'
    };
    const expenseCategory = categoryMap[pr.department] || 'Other';
    
    // Get current month for budget check
    const currentDate = new Date();
    const currentMonth = currentDate.getFullYear() + '-' + String(currentDate.getMonth() + 1).padStart(2, '0');
    
    // Items table with supplier details inline
    items.forEach(item => {
        // Supplier details
        const supplierContact = item.supplier_contact ? `<br><span class="text-secondary small">${item.supplier_contact}</span>` : '';
        const supplierEmail = item.supplier_email ? `<br><span class="text-secondary small">${item.supplier_email}</span>` : '';
        const supplierPhone = item.supplier_mobile ? `<br><span class="text-secondary small">${item.supplier_mobile}</span>` : '';
        
        itemsHtml += `
            <tr>
                <td class="py-2">${item.item_name}</td>
                <td class="py-2 text-secondary">${item.description || '—'}</td>
                <td class="py-2 text-center">${item.quantity}</td>
                <td class="py-2 text-end">₱${formatNumber(item.unit_price)}</td>
                <td class="py-2 text-end fw-medium">₱${formatNumber(item.total_price)}</td>
                <td class="py-2">
                    <div class="fw-medium">${item.supplier_name || '—'}</div>
                    ${supplierContact}
                    ${supplierEmail}
                    ${supplierPhone}
                </td>
            </tr>
        `;
    });
    
    // Budget check (minimal)
    let budgetCheckHtml = '';
    if (window.userRole === 'Finance' || window.userRole === 'ClinicAdmin') {
        budgetCheckHtml = `
            <div class="mb-4" id="budgetCheckCard">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="fw-light text-secondary">Budget Check</span>
                    <span class="badge bg-light text-dark px-3 py-2 fw-light">${expenseCategory}</span>
                </div>
                <div class="text-center py-4 bg-light rounded-3">
                    <div class="spinner-border text-secondary" style="width: 2rem; height: 2rem;"></div>
                    <p class="mt-2 text-muted small fw-light">checking budget...</p>
                </div>
            </div>
        `;
    }
    
    const modalHtml = `
        <div class="modal fade" id="prDetailsModal" tabindex="-1" data-bs-backdrop="static">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    
                    <!-- HEADER - fixed -->
                    <div class="modal-header py-3" style="border-bottom: 2px solid #008080;">
                        <div>
                            <span class="badge bg-light text-dark fw-light mb-1 px-3 py-2">${pr.pr_number}</span>
                            <h5 class="modal-title fw-light mt-2">Purchase Request Details</h5>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    
                    <!-- BODY - scrollable with fixed max height -->
                    <div class="modal-body p-4" style="max-height: 70vh; overflow-y: auto;">
                        
                        <!-- REQUEST INFO GRID - 4 columns -->
                        <div class="row g-3 mb-4">
                            <div class="col-md-3">
                                <div class="p-3 bg-light rounded-3">
                                    <small class="text-secondary d-block fw-light mb-1">Department</small>
                                    <span class="fw-light fs-6">${pr.department}</span>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="p-3 bg-light rounded-3">
                                    <small class="text-secondary d-block fw-light mb-1">Priority</small>
                                    <span class="badge ${getPriorityClass(pr.priority)} px-3 py-2 fw-light">${pr.priority}</span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="p-3 bg-light rounded-3">
                                    <small class="text-secondary d-block fw-light mb-1">Requested By</small>
                                    <span class="fw-light">${pr.requested_by_name || 'Unknown'}</span>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="p-3 bg-light rounded-3">
                                    <small class="text-secondary d-block fw-light mb-1">Date Requested</small>
                                    <span class="fw-light">${new Date(pr.created_at).toLocaleDateString()}</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- PURPOSE -->
                        <div class="mb-4">
                            <small class="text-secondary d-block fw-light mb-2">Purpose</small>
                            <div class="bg-light p-3 rounded-3">
                                <p class="mb-0 fw-light">${pr.purpose}</p>
                            </div>
                        </div>
                        
                        <!-- BUDGET CHECK - dynamic -->
                        ${budgetCheckHtml}
                        
                        <!-- REQUESTED ITEMS TABLE -->
                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="fw-light text-secondary">Requested Items</span>
                                <span class="fw-light fs-5" style="color: #008080;">₱${formatNumber(pr.total_amount)}</span>
                            </div>
                            
                            <div class="table-responsive" style="max-height: 300px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 8px;">
                                <table class="table table-hover align-middle mb-0" style="min-width: 1000px;">
                                    <thead class="bg-light" style="position: sticky; top: 0; z-index: 1;">
                                        <tr>
                                            <th class="fw-light py-2" width="150">Item Name</th>
                                            <th class="fw-light py-2" width="150">Description</th>
                                            <th class="fw-light py-2 text-center" width="60">Qty</th>
                                            <th class="fw-light py-2 text-end" width="100">Unit Price</th>
                                            <th class="fw-light py-2 text-end" width="100">Total</th>
                                            <th class="fw-light py-2" width="200">Supplier</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${itemsHtml || '<tr><td colspan="6" class="text-center py-4 text-muted fw-light">No items found</td></tr>'}
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Grand total footer -->
                            <div class="d-flex justify-content-end mt-3 pt-2" style="border-top: 1px dashed #dee2e6;">
                                <div style="width: 300px;">
                                    <div class="d-flex justify-content-between">
                                        <span class="fw-light">GRAND TOTAL</span>
                                        <span class="fw-light" style="color: #008080;">₱${formatNumber(pr.total_amount)}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- NOTES - if any -->
                        ${pr.notes ? `
                        <div class="mb-4">
                            <small class="text-secondary d-block fw-light mb-2">Notes</small>
                            <div class="bg-light p-3 rounded-3">
                                <p class="mb-0 small fw-light">${pr.notes}</p>
                            </div>
                        </div>
                        ` : ''}
                        
                        <!-- APPROVAL BUTTONS -->
                        ${window.userRole === 'Finance' || window.userRole === 'ClinicAdmin' ? `
                        <div class="d-flex gap-3 pt-4 mt-2" style="border-top: 2px solid #f0f0f0;">
                            <button class="btn flex-fill py-2 text-white border-0 rounded-pill" style="background-color: #008080;" onclick="approveFromDetails(${pr.id}, '${pr.pr_number}', ${pr.total_amount})">
                                <i class="bi bi-check-lg me-2"></i>Approve Request
                            </button>
                            <button class="btn btn-outline-secondary flex-fill py-2 rounded-pill" style="color: #dc3545; border-color: #dc3545;" onclick="rejectFromDetails(${pr.id}, '${pr.pr_number}')">
                                <i class="bi bi-x-lg me-2"></i>Reject Request
                            </button>
                        </div>
                        ` : ''}
                        
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Remove existing modal if any
    $('#prDetailsModal').remove();
    $('body').append(modalHtml);
    $('#prDetailsModal').modal('show');
    
    // Load budget check if Finance/ClinicAdmin
    if (window.userRole === 'Finance' || window.userRole === 'ClinicAdmin') {
        checkBudgetForPR(expenseCategory, totalAmount, currentMonth);
    }
    
    $('#prDetailsModal').on('hidden.bs.modal', function() { 
        $(this).remove(); 
    });
}

// ========== DISPLAY BUDGET CHECK (MINIMALIST) ==========
function displayBudgetCheck(budgetData, prCategory, prAmount) {
    const categoryBudget = budgetData.find(b => b.category === prCategory) || { allocated: 0, spent: 0, remaining: 0 };
    
    const allocated = parseFloat(categoryBudget.allocated || 0);
    const spent = parseFloat(categoryBudget.spent || 0);
    const remaining = parseFloat(categoryBudget.remaining || 0);
    const prAmountNum = parseFloat(prAmount || 0);
    
    const wouldRemain = remaining - prAmountNum;
    const isWithinBudget = wouldRemain >= 0;
    const spentPercent = allocated > 0 ? (spent / allocated * 100) : 0;
    const wouldSpentPercent = allocated > 0 ? ((spent + prAmountNum) / allocated * 100) : 0;
    
    // Status indicator
    let statusColor = '#198754';
    let statusText = 'Within Budget';
    if (!isWithinBudget) { statusColor = '#dc3545'; statusText = 'Exceeds Budget'; }
    else if (wouldSpentPercent > 80) { statusColor = '#ffc107'; statusText = 'Near Limit'; }
    
    const html = `
        <div class="row g-3">
            <!-- Left - Summary -->
            <div class="col-md-5">
                <div class="bg-light p-3 rounded-3">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-secondary">Monthly Budget</span>
                        <span class="fw-semibold">₱${formatNumber(allocated)}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-secondary">Spent</span>
                        <span class="fw-semibold text-danger">₱${formatNumber(spent)}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-secondary">Remaining</span>
                        <span class="fw-semibold text-success">₱${formatNumber(remaining)}</span>
                    </div>
                    <div class="d-flex justify-content-between pt-2 border-top">
                        <span class="fw-medium">This Request</span>
                        <span class="fw-semibold text-primary">₱${formatNumber(prAmountNum)}</span>
                    </div>
                </div>
            </div>
            
            <!-- Right - Status & Progress -->
            <div class="col-md-7">
                <div class="bg-light p-3 rounded-3">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="badge px-3 py-2" style="background-color: ${statusColor}20; color: ${statusColor}; border: 1px solid ${statusColor}40;">
                            <i class="bi ${isWithinBudget ? 'bi-check-circle' : 'bi-exclamation-triangle'} me-1"></i>
                            ${statusText}
                        </span>
                        <small class="text-secondary">After approval</small>
                    </div>
                    
                    <!-- Current Progress -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="text-secondary">Current spent</span>
                            <span>${spentPercent.toFixed(1)}%</span>
                        </div>
                        <div class="progress" style="height: 6px;">
                            <div class="progress-bar bg-secondary" style="width: ${Math.min(spentPercent, 100)}%"></div>
                        </div>
                    </div>
                    
                    <!-- Projected Progress -->
                    <div>
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="text-secondary">If approved</span>
                            <span>${wouldSpentPercent.toFixed(1)}%</span>
                        </div>
                        <div class="progress" style="height: 6px;">
                            <div class="progress-bar ${isWithinBudget ? 'bg-primary' : 'bg-danger'}" 
                                 style="width: ${Math.min(wouldSpentPercent, 100)}%"></div>
                        </div>
                    </div>
                    
                    <!-- Would remain -->
                    <div class="mt-3 text-end">
                        <small class="text-secondary">Would remain: </small>
                        <span class="fw-semibold ${isWithinBudget ? 'text-success' : 'text-danger'}">
                            ₱${formatNumber(wouldRemain)}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    $('#budgetCheckCard').html(`
        <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="fw-medium text-secondary">Budget Check</span>
            <span class="badge bg-light text-dark px-3 py-2">${prCategory}</span>
        </div>
        ${html}
    `);
}

// ========== CHECK BUDGET FOR PR ==========
function checkBudgetForPR(category, amount, month) {
    console.log('Checking budget for:', { category, amount, month }); // Debug
    
    $.ajax({
        url: 'api/expenses.php',
        method: 'GET',
        data: {
            action: 'get_budget',
            month: month
        },
        success: function(response) {
            console.log('Budget response:', response); // Debug
            
            if (response.success) {
                if (response.data && response.data.length > 0) {
                    displayBudgetCheck(response.data, category, amount);
                } else {
                    // No budget data found
                    $('#budgetCheckCard').html(`
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="fw-medium text-secondary">Budget Check</span>
                            <span class="badge bg-light text-dark px-3 py-2">${category}</span>
                        </div>
                        <div class="alert alert-info mb-0 py-3">
                            <i class="bi bi-info-circle me-2"></i>
                            No budget set for this month
                        </div>
                    `);
                }
            } else {
                $('#budgetCheckCard').html(`
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="fw-medium text-secondary">Budget Check</span>
                        <span class="badge bg-light text-dark px-3 py-2">${category}</span>
                    </div>
                    <div class="alert alert-warning mb-0 py-3">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Unable to load budget data
                    </div>
                `);
            }
        },
        error: function(xhr, status, error) {
            console.error('Budget check error:', error); // Debug
            $('#budgetCheckCard').html(`
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="fw-medium text-secondary">Budget Check</span>
                    <span class="badge bg-light text-dark px-3 py-2">${category}</span>
                </div>
                <div class="alert alert-danger mb-0 py-3">
                    <i class="bi bi-x-circle me-2"></i>
                    Error checking budget
                </div>
            `);
        }
    });
}

// ========== DISPLAY BUDGET CHECK ==========
function displayBudgetCheck(budgetData, prCategory, prAmount) {
    console.log('Displaying budget for:', { prCategory, prAmount, budgetData }); // Debug
    
    // Find budget for this category
    const categoryBudget = budgetData.find(b => b.category === prCategory) || { 
        allocated: 0, 
        spent: 0, 
        remaining: 0 
    };
    
    console.log('Category budget:', categoryBudget); // Debug
    
    const allocated = parseFloat(categoryBudget.allocated || 0);
    const spent = parseFloat(categoryBudget.spent || 0);
    const remaining = parseFloat(categoryBudget.remaining || 0);
    const prAmountNum = parseFloat(prAmount || 0);
    
    const wouldRemain = remaining - prAmountNum;
    const isWithinBudget = wouldRemain >= 0;
    
    // Calculate percentages (avoid division by zero)
    const spentPercent = allocated > 0 ? (spent / allocated * 100) : 0;
    const wouldSpentPercent = allocated > 0 ? ((spent + prAmountNum) / allocated * 100) : 0;
    
    // Determine status
    let statusColor = '#6c757d'; // gray
    let statusText = 'No Budget Set';
    let statusIcon = 'bi-dash-circle';
    
    if (allocated === 0 && spent > 0) {
        statusColor = '#dc3545'; // red
        statusText = 'No Budget, Has Spendings';
        statusIcon = 'bi-exclamation-triangle';
    } else if (allocated > 0) {
        if (!isWithinBudget) {
            statusColor = '#dc3545'; // red
            statusText = 'Exceeds Budget';
            statusIcon = 'bi-exclamation-triangle';
        } else if (wouldSpentPercent > 80) {
            statusColor = '#ffc107'; // yellow
            statusText = 'Near Budget Limit';
            statusIcon = 'bi-exclamation-circle';
        } else {
            statusColor = '#198754'; // green
            statusText = 'Within Budget';
            statusIcon = 'bi-check-circle';
        }
    }
    
    const html = `
        <div class="row g-3">
            <!-- Left - Summary -->
            <div class="col-md-5">
                <div class="bg-light p-3 rounded-3">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-secondary">Monthly Budget</span>
                        <span class="fw-semibold">₱${formatNumber(allocated)}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-secondary">Spent so far</span>
                        <span class="fw-semibold ${spent > 0 ? 'text-danger' : ''}">₱${formatNumber(spent)}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-secondary">Current Remaining</span>
                        <span class="fw-semibold ${remaining < 0 ? 'text-danger' : 'text-success'}">₱${formatNumber(remaining)}</span>
                    </div>
                    <div class="d-flex justify-content-between pt-2 border-top">
                        <span class="fw-medium">This Request</span>
                        <span class="fw-semibold text-primary">₱${formatNumber(prAmountNum)}</span>
                    </div>
                    <div class="d-flex justify-content-between pt-2">
                        <span class="fw-medium">Would Remain</span>
                        <span class="fw-semibold ${wouldRemain < 0 ? 'text-danger' : 'text-success'}">
                            ₱${formatNumber(wouldRemain)}
                        </span>
                    </div>
                </div>
            </div>
            
            <!-- Right - Status & Progress -->
            <div class="col-md-7">
                <div class="bg-light p-3 rounded-3">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="badge px-3 py-2" 
                              style="background-color: ${statusColor}20; color: ${statusColor}; border: 1px solid ${statusColor}40;">
                            <i class="bi ${statusIcon} me-1"></i>
                            ${statusText}
                        </span>
                    </div>
                    
                    ${allocated > 0 ? `
                        <!-- Current Progress -->
                        <div class="mb-3">
                            <div class="d-flex justify-content-between small mb-1">
                                <span class="text-secondary">Current spent</span>
                                <span>${spentPercent.toFixed(1)}%</span>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar ${spentPercent > 100 ? 'bg-danger' : 'bg-secondary'}" 
                                     style="width: ${Math.min(spentPercent, 100)}%"></div>
                            </div>
                            <small class="text-muted">₱${formatNumber(spent)} of ₱${formatNumber(allocated)}</small>
                        </div>
                        
                        <!-- Projected Progress -->
                        <div>
                            <div class="d-flex justify-content-between small mb-1">
                                <span class="text-secondary">If approved</span>
                                <span>${wouldSpentPercent.toFixed(1)}%</span>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar ${wouldSpentPercent > 100 ? 'bg-danger' : 'bg-primary'}" 
                                     style="width: ${Math.min(wouldSpentPercent, 100)}%"></div>
                            </div>
                            <small class="text-muted">₱${formatNumber(spent + prAmountNum)} total</small>
                        </div>
                    ` : `
                        <!-- No budget set -->
                        <div class="alert alert-warning mb-0 py-2 small">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            No budget allocated for this category
                            ${spent > 0 ? `<br><span class="text-danger">But has ₱${formatNumber(spent)} spendings</span>` : ''}
                        </div>
                    `}
                </div>
            </div>
        </div>
    `;
    
    $('#budgetCheckCard').html(`
        <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="fw-medium text-secondary">Budget Check</span>
            <span class="badge bg-light text-dark px-3 py-2">${prCategory}</span>
        </div>
        ${html}
    `);
}

// Approve from details modal
function approveFromDetails(prId, prNumber, amount) {
    // Close details modal
    $('#prDetailsModal').modal('hide');
    
    // Set values
    $('#approvePRId').val(prId);
    $('#approvePRNumberDisplay').text(prNumber);
    $('#approvePRAmountDisplay').text('₱' + formatNumber(amount));
    
    // Show budget summary if available (optional)
    const category = $('#budgetCheckCard .badge').text(); // Get category from budget check
    if (category && category !== 'Budget Check') {
        $('#approveBudgetSummary').show();
        // You can add more budget details here if needed
    }
    
    // Show approve modal
    $('#approvePRModal').modal('show');
}

// Reject from details modal
function rejectFromDetails(prId, prNumber) {
    // Close details modal
    $('#prDetailsModal').modal('hide');
    
    // Set values to display
    $('#rejectPRId').val(prId);
    $('#rejectPRNumber').text(prNumber);
    
    // Show reject modal
    $('#rejectPRModal').modal('show');
}

// Confirm Reject - mag-oopen ng rejection reason modal
function confirmRejectPR() {
    const prId = $('#rejectPRId').val();
    const prNumber = $('#rejectPRNumber').text();
    
    // Close reject modal
    $('#rejectPRModal').modal('hide');
    
    // Show prompt for rejection reason
    Swal.fire({
        title: 'Rejection Reason',
        input: 'textarea',
        inputLabel: 'Please specify reason for rejection',
        inputPlaceholder: 'Type your reason here...',
        inputAttributes: {
            'aria-label': 'Type your reason here'
        },
        showCancelButton: true,
        confirmButtonText: 'Reject PR',
        confirmButtonColor: '#dc3545',
        cancelButtonText: 'Cancel',
        inputValidator: (value) => {
            if (!value) {
                return 'Rejection reason is required!'
            }
        }
    }).then((result) => {
        if (result.isConfirmed) {
            processRejectPR(prId, result.value);
        }
    });
}

// Process rejection with reason
function processRejectPR(prId, reason) {
    Swal.fire({
        title: 'Rejecting PR...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    $.ajax({
        url: 'api/expenses.php',
        method: 'POST',
        data: {
            action: 'reject_pr',
            pr_id: prId,
            rejection_reason: reason
        },
        success: function(response) {
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Rejected!',
                    text: response.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    loadPendingPRs();
                });
            } else {
                Swal.fire('Error!', response.error || 'Failed to reject PR', 'error');
            }
        },
        error: function() {
            Swal.fire('Error!', 'Failed to reject PR', 'error');
        }
    });
}

// APPROVE MODAL - DAPAT MAY VALUES NA ILAGAY
function showApproveModal(prId, prNumber, amount) {
    // Set the values
    $('#approvePRId').val(prId);                    // Hidden input
    $('#approvePRNumber').val(prNumber);             // PR Number display
    $('#approvePRAmount').val('₱' + formatNumber(amount)); // Amount display
    
    // Optional: Reset expense category to default
    $('#approvePRForm select[name="expense_category"]').val('Office Supplies');
    
    // Reset approval notes
    $('#approvePRForm textarea[name="approval_notes"]').val('');
    
    // Show the modal
    $('#approvePRModal').modal('show');
}

function showRejectModal(prId, prNumber) {
    // Set the values
    $('#rejectPRId').val(prId);           // Hidden input
    $('#rejectPRNumber').val(prNumber);    // PR Number display
    
    // Reset rejection reason
    $('#rejectPRForm textarea[name="rejection_reason"]').val('');
    
    // Show the modal
    $('#rejectPRModal').modal('show');
}

function approvePurchaseRequest() {
    const formData = new FormData(document.getElementById('approvePRForm'));
    
    Swal.fire({
        title: 'Approving PR...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    $.ajax({
        url: 'api/expenses.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            Swal.close();
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Approved!',
                    text: response.message,
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => {
                    $('#approvePRModal').modal('hide');
                    
                    // ✅ REAL-TIME UPDATES
                    loadPendingPRs();        // Update PR pending table
                    loadStats();              // Update stats
                    loadBudgetData();          // Update budget
                    
                    // ✅ I-RELOAD LAHAT NG EXPENSE TABS
                    loadExpenses('pending');  // ← ITO ANG KULANG - para sa Pending Approval tab
                    loadExpenses('all');       // Update all expenses
                    loadExpenses('paid');      // Update paid expenses
                    loadExpenses('overdue');   // Update overdue expenses
                    
                    // Show expense created notification
                    if (response.expense_code) {
                        Swal.fire({
                            icon: 'info',
                            title: 'Expense Created',
                            text: `Expense ${response.expense_code} has been created and is now in Pending Approval tab.`,
                            timer: 3000,
                            showConfirmButton: true
                        });
                    }
                });
            } else {
                Swal.fire('Error!', response.error || 'Failed to approve PR', 'error');
            }
        },
        error: function(xhr) {
            Swal.close();
            Swal.fire('Error!', 'Server error: ' + xhr.status, 'error');
        }
    });
}

function rejectPurchaseRequest() {
    const formData = new FormData(document.getElementById('rejectPRForm'));
    const reason = formData.get('rejection_reason');
    
    if (!reason) {
        Swal.fire('Error!', 'Rejection reason is required', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Rejecting PR...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    $.ajax({
        url: 'api/expenses.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            Swal.close();
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Rejected!',
                    text: response.message,
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => {
                    $('#rejectPRModal').modal('hide');
                    
                    // Update pending PRs only
                    loadPendingPRs();
                });
            } else {
                Swal.fire('Error!', response.error || 'Failed to reject PR', 'error');
            }
        },
        error: function() {
            Swal.close();
            Swal.fire('Error!', 'Failed to reject PR', 'error');
        }
    });
}

// ============================================
// EXPENSE FUNCTIONS
// ============================================
function loadExpenses(tab) {
    console.log(`Loading ${tab} expenses...`);
    
    // Make sure currentMonth is set
    if (!currentMonth) {
        currentMonth = new Date().toISOString().slice(0, 7);
        console.log('Set current month to:', currentMonth);
    }
    
    let url = 'api/expenses.php?action=get_expenses';
    url += `&month=${currentMonth}&tab=${tab}`;
    
    // Add category filter for all expenses tab
    if (tab === 'all') {
        const category = $('#categoryFilter').val();
        if (category && category !== 'all') {
            url += `&category=${encodeURIComponent(category)}`;
            console.log('Category filter:', category);
        }
    }
    
    console.log('Fetching:', url);
    
    $.ajax({
        url: url,
        method: 'GET',
        success: function(response) {
            console.log(`Response for ${tab}:`, response);
            
            if (response.success) {
                renderExpenses(tab, response.data, response.pagination);
                
                // Update counts for badges
                if (tab === 'pending') {
                    $('#pendingExpenseCount').text(response.data.length || 0);
                }
            } else {
                console.error('Failed to load expenses:', response.error);
                showError(`Failed to load ${tab} expenses`);
            }
        },
        error: function(xhr, status, error) {
            console.error('Failed to load expenses for tab:', tab, error);
            showError(`Error loading ${tab} expenses`);
        }
    });
}

function renderExpenses(tab, expenses, pagination) {
    console.log(`Rendering ${tab} expenses:`, expenses);
    
    let tbodyId;
    switch(tab) {
        case 'pending':
            tbodyId = '#pendingExpensesBody';
            break;
        case 'all':
            tbodyId = '#allExpensesBody';
            $('#expensePaginationInfo').text(
                `Showing ${expenses.length} of ${pagination?.total || 0} records`
            );
            renderPagination(pagination);
            break;
        case 'paid':
            tbodyId = '#paidExpensesBody';
            break;
        case 'overdue':
            tbodyId = '#overdueExpensesBody';
            break;
        default:
            return;
    }
    
    const tbody = $(tbodyId);
    
    if (!expenses || expenses.length === 0) {
        console.log(`No expenses found for ${tab}`);
        tbody.html(`
            <tr>
                <td colspan="10" class="text-center py-5">
                    <i class="bi bi-inbox fs-1 text-muted"></i>
                    <p class="mt-3 text-muted">No expenses found</p>
                </td>
            </tr>
        `);
        return;
    }
    
    let html = '';
    expenses.forEach(exp => {
        if (tab === 'pending') {
            html += renderPendingExpenseRow(exp);
        } else if (tab === 'all') {
            html += renderAllExpenseRow(exp);
        } else if (tab === 'paid') {
            html += renderPaidExpenseRow(exp);
        } else if (tab === 'overdue') {
            html += renderOverdueExpenseRow(exp);
        }
    });
    
    tbody.html(html);
    console.log(`Rendered ${expenses.length} rows for ${tab}`);
}
function renderPendingExpenseRow(exp) {
    const statusClass = exp.status === 'Pending' ? 'status-pending' : 
                       exp.status === 'Overdue' ? 'status-overdue' : 'status-paid';
    
    // Check if status is 'Ready to Pay' para lumabas ang approve at upload buttons
    const isReadyToPay = exp.status === 'Ready to Pay';
    
    return `
        <tr>
            <!-- CHECKBOX REMOVED - reduced colspan -->
            <td><strong>${exp.expense_code}</strong></td>
            <td>
                <div class="fw-bold">${exp.description}</div>
                <small class="text-muted">${exp.vendor || 'No vendor'}</small>
            </td>
            <td>
                <span class="badge-category" style="background: ${exp.color_code || '#4361ee'}20; color: ${exp.color_code || '#4361ee'}">
                    <i class="bi ${exp.icon || 'bi-receipt'} me-1"></i>
                    ${exp.category}
                </span>
            </td>
            <td class="fw-bold">₱${formatNumber(exp.amount)}</td>
            <td>
                <small>${formatDate(exp.due_date)}</small>
            </td>
            <td>${exp.department || 'N/A'}</td>
            <td><span class="badge-status ${statusClass}">${exp.status}</span></td>
            <td>
                <div class="btn-group btn-group-sm">
                    <!-- View button - laging visible -->
                    <button class="btn btn-outline-primary" onclick="viewExpense(${exp.id})">
                        <i class="bi bi-eye"></i>
                    </button>
                    
                    <!-- Approve button - Ready to Pay lang -->
                    ${isReadyToPay ? 
                        `<button class="btn btn-outline-success" onclick="approveExpense(${exp.id})">
                            <i class="bi bi-check-lg"></i>
                        </button>` : 
                        ''}
                    
                    <!-- Upload Receipt button - Ready to Pay lang -->
                    ${isReadyToPay ? 
                        `<button class="btn btn-outline-secondary" onclick="uploadReceiptModal(${exp.id})">
                            <i class="bi bi-cloud-upload"></i>
                        </button>` : 
                        ''}
                </div>
            </td>
        </tr>
    `;
}

function renderAllExpenseRow(exp) {
    const statusClass = exp.status === 'Paid' ? 'status-paid' : 
                       exp.status === 'Pending' ? 'status-pending' : 'status-overdue';
    
    let receiptIcon = '';
    if (exp.has_receipt) {
        receiptIcon = '<i class="bi bi-file-earmark-check text-success"></i>';
    } else if (exp.attachment_count > 0) {
        receiptIcon = '<i class="bi bi-file-earmark-check text-success"></i>';
    } else {
        receiptIcon = '<i class="bi bi-file-earmark text-muted"></i>';
    }
    
    return `
        <tr>
            <td><strong>${exp.expense_code}</strong></td>
            <td>
                <div class="fw-bold">${truncate(exp.description, 30)}</div>
                <small class="text-muted">${exp.vendor || ''}</small>
            </td>
            <td>
                <span class="badge-category" style="background: ${exp.color_code || '#4361ee'}20; color: ${exp.color_code || '#4361ee'}">
                    ${exp.category}
                </span>
            </td>
            <td class="fw-bold">₱${formatNumber(exp.amount)}</td>
            <td><small>${formatDate(exp.expense_date)}</small></td>
            <td>${exp.vendor || 'N/A'}</td>
            <td>${exp.department || 'N/A'}</td>
            <td><span class="badge-status ${statusClass}">${exp.status}</span></td>
            <td class="text-center">
                <span onclick="viewReceipt(${exp.id})" style="cursor: pointer;">
                    ${receiptIcon}
                </span>
            </td>
            <td>
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-primary" onclick="viewExpense(${exp.id})">
                        <i class="bi bi-eye"></i>
                    </button>
                    <button class="btn btn-outline-warning" onclick="editExpense(${exp.id})">
                        <i class="bi bi-pencil"></i>
                    </button>
                </div>
            </td>
        </tr>
    `;
}

function renderPaidExpenseRow(exp) {
    return `
        <tr>
            <td><strong>${exp.expense_code}</strong></td>
            <td>${truncate(exp.description, 30)}</td>
            <td class="fw-bold">₱${formatNumber(exp.amount)}</td>
            <td>${formatDate(exp.payment_date)}</td>
            <td>${exp.payment_method || 'N/A'}</td>
            <td>${exp.payment_reference || 'N/A'}</td>
            <td>
                <button class="btn btn-sm btn-outline-primary" onclick="viewExpense(${exp.id})">
                    <i class="bi bi-eye"></i>
                </button>
            </td>
        </tr>
    `;
}

function renderOverdueExpenseRow(exp) {
    const dueDate = new Date(exp.due_date);
    const today = new Date();
    const daysOverdue = Math.floor((today - dueDate) / (1000 * 60 * 60 * 24));
    
    return `
        <tr>
            <td><strong>${exp.expense_code}</strong></td>
            <td>${truncate(exp.description, 30)}</td>
            <td class="fw-bold text-danger">₱${formatNumber(exp.amount)}</td>
            <td><span class="text-danger">${formatDate(exp.due_date)}</span></td>
            <td><span class="badge bg-danger">${daysOverdue} days</span></td>
            <td>${exp.vendor || 'N/A'}</td>
            <td>
                <button class="btn btn-sm btn-success" onclick="approveExpense(${exp.id})">
                    <i class="bi bi-check-lg"></i> Pay Now
                </button>
            </td>
        </tr>
    `;
}

function renderPagination(pagination) {
    if (!pagination || pagination.pages <= 1) {
        $('#expensePagination').empty();
        return;
    }
    
    let html = '';
    const current = pagination.page || 1;
    const total = pagination.pages || 1;
    
    // Previous button
    html += `<li class="page-item ${current === 1 ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="changePage(${current - 1})">&laquo;</a>
    </li>`;
    
    // Page numbers
    for (let i = 1; i <= total; i++) {
        if (i === 1 || i === total || (i >= current - 2 && i <= current + 2)) {
            html += `<li class="page-item ${i === current ? 'active' : ''}">
                <a class="page-link" href="#" onclick="changePage(${i})">${i}</a>
            </li>`;
        } else if (i === current - 3 || i === current + 3) {
            html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        }
    }
    
    // Next button
    html += `<li class="page-item ${current === total ? 'disabled' : ''}">
        <a class="page-link" href="#" onclick="changePage(${current + 1})">&raquo;</a>
    </li>`;
    
    $('#expensePagination').html(html);
}

function changePage(page) {
    currentPage = page;
    loadExpenses('all');
}

function addNewExpense() {
    $('#addExpenseForm')[0].reset();
    $('#addExpenseModal').modal('show');
}

function createExpense() {
    const formData = new FormData(document.getElementById('addExpenseForm'));
    
    // Debug: check form data
    console.log('=== CREATING EXPENSE ===');
    console.log('Current month:', currentMonth);
    for (let pair of formData.entries()) {
        console.log(pair[0] + ':', pair[1]);
    }
    
    // Check expense_date
    const expenseDate = formData.get('expense_date');
    console.log('Expense date:', expenseDate);
    console.log('Should be in month:', expenseDate ? expenseDate.substring(0, 7) : 'N/A');
    
    // Validate amount
    const amount = formData.get('amount');
    if (parseFloat(amount) <= 0) {
        Swal.fire('Error!', 'Amount must be greater than 0', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Saving Expense...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    $.ajax({
        url: 'api/expenses.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            console.log('Create expense response:', response);
            
            Swal.close();
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: response.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    $('#addExpenseModal').modal('hide');
                    
                    // Reload all tabs
                    setTimeout(() => {
                        loadExpenses('all');
                        loadExpenses('pending');
                        loadExpenses('paid');
                        loadExpenses('overdue');
                        loadStats();
                    }, 500);
                });
            } else {
                Swal.fire('Error!', response.error || 'Failed to create expense', 'error');
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', {xhr, status, error});
            Swal.close();
            Swal.fire('Error!', 'Server error: ' + xhr.status, 'error');
        }
    });
}
function viewExpense(id) {
    $.ajax({
        url: `api/expenses.php?action=get_expense&id=${id}`,
        method: 'GET',
        success: function(response) {
            if (response.success) {
                renderExpenseDetails(response.data);
                currentExpenseId = id;
                $('#viewExpenseModal').modal('show');
            } else {
                Swal.fire('Error!', response.error || 'Expense not found', 'error');
            }
        },
        error: function() {
            Swal.fire('Error!', 'Failed to load expense details', 'error');
        }
    });
}

function renderExpenseDetails(expense) {
    console.log('Expense details:', expense); // Debug
    
    const statusClass = expense.status === 'Paid' ? 'bg-success' : 
                       expense.status === 'Pending' ? 'bg-warning' : 'bg-danger';
    
    // Get approver name properly
    const approvedByName = expense.approved_by_name || 
                          (expense.activity_log && expense.activity_log.length > 0 ? 
                          expense.activity_log[0].user_name : 'N/A');
    
    let attachmentsHtml = '';
    if (expense.attachments && expense.attachments.length > 0) {
        attachmentsHtml = '<h6 class="mt-4">Attachments</h6><div class="d-flex gap-2">';
        expense.attachments.forEach(att => {
            attachmentsHtml += `
                <div class="border rounded p-2">
                    <i class="bi bi-file-earmark-text"></i>
                    <small>${att.file_name}</small>
                    <a href="${att.file_path}" target="_blank" class="ms-2">
                        <i class="bi bi-download"></i>
                    </a>
                </div>
            `;
        });
        attachmentsHtml += '</div>';
    }
    
    let activityHtml = '';
    if (expense.activity_log && expense.activity_log.length > 0) {
        activityHtml = '<h6 class="mt-4">Activity Log</h6><div class="timeline">';
        expense.activity_log.forEach(log => {
            activityHtml += `
                <div class="timeline-item">
                    <div class="timeline-dot"></div>
                    <div class="timeline-line"></div>
                    <small class="text-muted">${formatDateTime(log.created_at)}</small>
                    <div class="fw-bold">${log.details}</div>
                    <small>by ${log.user_name}</small>
                </div>
            `;
        });
        activityHtml += '</div>';
    }
    
    const html = `
        <div class="row">
            <div class="col-md-8">
                <h5 class="fw-bold">${expense.expense_code}</h5>
                <p class="mb-2">${expense.description}</p>
                
                <div class="row mt-4">
                    <div class="col-md-6">
                        <table class="table table-sm">
                            <tr>
                                <td width="120"><strong>Category</strong></td>
                                <td>
                                    <span class="badge-category" style="background: ${expense.color_code || '#4361ee'}20; color: ${expense.color_code || '#4361ee'}">
                                        ${expense.category}
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Amount</strong></td>
                                <td class="fw-bold fs-5">₱${formatNumber(expense.amount)}</td>
                            </tr>
                            <tr>
                                <td><strong>Status</strong></td>
                                <td><span class="badge ${statusClass}">${expense.status}</span></td>
                            </tr>
                            <tr>
                                <td><strong>Expense Date</strong></td>
                                <td>${formatDate(expense.expense_date)}</td>
                            </tr>
                            <tr>
                                <td><strong>Due Date</strong></td>
                                <td>${formatDate(expense.due_date) || 'N/A'}</td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm">
                            <tr>
                                <td width="120"><strong>Vendor</strong></td>
                                <td>${expense.vendor || 'N/A'}</td>
                            </tr>
                            <tr>
                                <td><strong>Department</strong></td>
                                <td>${expense.department || 'N/A'}</td>
                            </tr>
                            <tr>
                                <td><strong>Requested By</strong></td>
                                <td>${expense.requested_by_name || expense.requested_by || 'N/A'}</td>
                            </tr>
                            <tr>
                                <td><strong>Approved By</strong></td>
                                <td>${approvedByName}</td>
                            </tr>
                            <tr>
                                <td><strong>Payment Method</strong></td>
                                <td>${expense.payment_method || 'N/A'}</td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                ${expense.notes ? `
                    <div class="mt-3">
                        <strong>Notes:</strong>
                        <p class="text-muted">${expense.notes}</p>
                    </div>
                ` : ''}
                
                ${attachmentsHtml}
                ${activityHtml}
            </div>
            
            <div class="col-md-4">
                <div class="card bg-light">
                    <div class="card-body">
                        <h6 class="card-title">Quick Actions</h6>
                        
                        ${expense.status === 'Pending' ? `
                            <button class="btn btn-success w-100 mb-2" onclick="approveExpense(${expense.id})">
                                <i class="bi bi-check-circle"></i> Approve & Pay
                            </button>
                        ` : ''}
                        
                        <button class="btn btn-outline-primary w-100 mb-2" onclick="uploadReceiptModal(${expense.id})">
                            <i class="bi bi-cloud-upload"></i> Upload Receipt
                        </button>
                        
                        <button class="btn btn-outline-secondary w-100" onclick="downloadExpense(${expense.id})">
                            <i class="bi bi-download"></i> Download Details
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    $('#viewExpenseContent').html(html);
}

function editCurrentExpense() {
    $('#viewExpenseModal').modal('hide');
    editExpense(currentExpenseId);
}

function editExpense(id) {
    // In a real system, this would populate the add/edit form
    Swal.fire({
        title: 'Edit Expense',
        text: 'Edit expense #' + id,
        icon: 'info',
        showCancelButton: true,
        confirmButtonText: 'Edit',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            addNewExpense(); // Reuse add form
        }
    });
}

function approveExpense(id) {
    Swal.fire({
        title: 'Approve & Pay Expense',
        html: `
            <div class="text-start">
                <p>Process payment for expense #${id}</p>
                <div class="mb-3">
                    <label class="form-label">Payment Method</label>
                    <select class="form-select" id="paymentMethod">
                        <option>Bank Transfer</option>
                        <option>Check</option>
                        <option>Cash</option>
                        <option>Credit Card</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Payment Date</label>
                    <input type="date" class="form-control" id="paymentDate" value="${new Date().toISOString().split('T')[0]}">
                </div>
                <div class="mb-3">
                    <label class="form-label">Reference Number</label>
                    <input type="text" class="form-control" id="paymentRef" placeholder="Check # / Ref #">
                </div>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Approve & Pay',
        confirmButtonColor: '#06d6a0',
        preConfirm: () => {
            return {
                payment_method: document.getElementById('paymentMethod').value,
                payment_date: document.getElementById('paymentDate').value,
                payment_reference: document.getElementById('paymentRef').value
            };
        }
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'approve_expense');
            formData.append('id', id);
            formData.append('payment_method', result.value.payment_method);
            formData.append('payment_date', result.value.payment_date);
            formData.append('payment_reference', result.value.payment_reference);
            
            Swal.fire({
                title: 'Processing...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            $.ajax({
                url: 'api/expenses.php',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    Swal.close();
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Approved!',
                            text: response.message,
                            timer: 2000,
                            showConfirmButton: false
                        }).then(() => {
                            loadExpenses('pending');
                            loadExpenses('all');
                            loadExpenses('paid');
                            loadStats();
                            loadBudgetData();
                        });
                    } else {
                        Swal.fire('Error!', response.error, 'error');
                    }
                },
                error: function() {
                    Swal.close();
                    Swal.fire('Error!', 'Failed to approve expense', 'error');
                }
            });
        }
    });
}



// ============================================
// RECEIPT FUNCTIONS
// ============================================
function uploadReceiptModal(expenseId) {
    $('#receiptExpenseId').val(expenseId);
    $('#uploadReceiptModal').modal('show');
}

function uploadReceipt() {
    const formData = new FormData(document.getElementById('uploadReceiptForm'));
    
    Swal.fire({
        title: 'Uploading...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    $.ajax({
        url: 'api/expenses.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            Swal.close();
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Uploaded!',
                    text: response.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    $('#uploadReceiptModal').modal('hide');
                    loadExpenses('all');
                    
                    // If viewing the expense, refresh details
                    if (currentExpenseId) {
                        viewExpense(currentExpenseId);
                    }
                });
            } else {
                Swal.fire('Error!', response.error || 'Upload failed', 'error');
            }
        },
        error: function() {
            Swal.close();
            Swal.fire('Error!', 'Failed to upload receipt', 'error');
        }
    });
}

function viewReceipt(expenseId) {
    $.ajax({
        url: `api/expenses.php?action=get_expense&id=${expenseId}`,
        method: 'GET',
        success: function(response) {
            if (response.success && response.data.attachments && response.data.attachments.length > 0) {
                const attachment = response.data.attachments[0];
                Swal.fire({
                    title: 'Receipt',
                    html: `
                        <div class="text-center">
                            <img src="${attachment.file_path}" class="img-fluid border rounded" 
                                 style="max-height: 400px;" alt="Receipt">
                            <p class="mt-3">${attachment.file_name}</p>
                            <a href="${attachment.file_path}" download class="btn btn-primary">
                                <i class="bi bi-download"></i> Download
                            </a>
                        </div>
                    `,
                    width: 600,
                    showConfirmButton: false,
                    showCloseButton: true
                });
            } else {
                // Show placeholder
                Swal.fire({
                    title: 'No Receipt',
                    html: `
                        <div class="text-center py-4">
                            <i class="bi bi-receipt fs-1 text-muted"></i>
                            <p class="mt-3">No receipt uploaded for this expense.</p>
                            <button class="btn btn-primary" onclick="uploadReceiptModal(${expenseId})">
                                <i class="bi bi-cloud-upload"></i> Upload Receipt
                            </button>
                        </div>
                    `,
                    showConfirmButton: false,
                    showCloseButton: true
                });
            }
        }
    });
}

function loadBudgetData() {
    const month = $('#monthSelector').val() || new Date().toISOString().slice(0, 7);
    
    $.ajax({
        url: 'api/expenses.php',
        method: 'GET',
        data: {
            action: 'get_budget',
            month: month
        },
        success: function(response) {
            if (response.success) {
                renderBudgetTable(response.data);
                updateBudgetSummary(response.data);
            } else {
                showError('Failed to load budget data');
            }
        },
        error: function() {
            $('#budgetTableBody').html(`
                <tr>
                    <td colspan="5" class="text-center py-4 text-danger">
                        Error loading budget data
                    </td>
                </tr>
            `);
        }
    });
}

function renderBudgetTable(data) {
    if (!data || data.length === 0) {
        $('#budgetTableBody').html(`
            <tr>
                <td colspan="5" class="text-center py-5">
                    <i class="bi bi-inbox text-muted fs-1"></i>
                    <p class="mt-3 text-muted">No budget set for this month</p>
                    <button class="btn btn-primary btn-sm" onclick="loadBudgetToModal()">
                        <i class="bi bi-plus-circle"></i> Set Budget
                    </button>
                </td>
            </tr>
        `);
        return;
    }
    
    let html = '';
    data.forEach(item => {
        const utilization = item.allocated > 0 ? 
            ((item.spent / item.allocated) * 100).toFixed(1) : 0;
        const statusClass = utilization > 90 ? 'bg-danger' : 
                           utilization > 75 ? 'bg-warning' : 'bg-success';
        
        html += `<tr>
            <td><span class="fw-bold">${item.category}</span></td>
            <td class="fw-bold">₱${formatNumber(item.allocated)}</td>
            <td>₱${formatNumber(item.spent)}</td>
            <td class="fw-bold ${item.remaining > 0 ? 'text-success' : 'text-danger'}">
                ₱${formatNumber(item.remaining)}
            </td>
            <td>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge ${statusClass}" style="min-width: 45px;">${utilization}%</span>
                    <div class="progress flex-grow-1" style="height: 6px;">
                        <div class="progress-bar ${statusClass}" style="width: ${utilization}%"></div>
                    </div>
                </div>
            </td>
        </tr>`;
    });
    
    $('#budgetTableBody').html(html);
}

function updateBudgetSummary(data) {
    let totalAllocated = 0, totalSpent = 0;
    
    data.forEach(item => {
        totalAllocated += item.allocated;
        totalSpent += item.spent;
    });
    
    const totalRemaining = totalAllocated - totalSpent;
    
    $('#totalAllocated').text('₱' + formatNumber(totalAllocated));
    $('#totalSpent').text('₱' + formatNumber(totalSpent));
    $('#totalRemaining').text('₱' + formatNumber(totalRemaining));
}

// FORMAT NUMBER HELPER
function formatNumber(num) {
    return parseFloat(num || 0).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
}

// LOAD BUDGET TO MODAL - WITH ALL CATEGORIES
function loadBudgetToModal() {
    const month = $('#monthSelector').val() || new Date().toISOString().slice(0, 7);
    $('#budgetMonth').val(month);
    
    $.ajax({
        url: 'api/expenses.php',
        method: 'GET',
        data: {
            action: 'get_budget',
            month: month
        },
        success: function(response) {
            if (response.success) {
                // Reset all inputs
                $('.budget-amount').val('');
                
                // Fill with existing data - LAHAT NG CATEGORIES
                response.data.forEach(item => {
                    if (item.category === 'Equipment') $('#budgetEquipment').val(item.allocated);
                    if (item.category === 'Office Supplies') $('#budgetOffice').val(item.allocated);
                    if (item.category === 'Medical Equipment') $('#budgetMedical').val(item.allocated);
                    if (item.category === 'Optical Supplies') $('#budgetOptical').val(item.allocated);
                    if (item.category === 'Maintenance') $('#budgetMaintenance').val(item.allocated);
                    if (item.category === 'Utilities') $('#budgetUtilities').val(item.allocated);
                    if (item.category === 'Marketing') $('#budgetMarketing').val(item.allocated);
                    if (item.category === 'Rent') $('#budgetRent').val(item.allocated);
                    if (item.category === 'Salaries') $('#budgetSalaries').val(item.allocated);
                    if (item.category === 'Training') $('#budgetTraining').val(item.allocated);
                    if (item.category === 'Travel') $('#budgetTravel').val(item.allocated);
                    if (item.category === 'Other') $('#budgetOthers').val(item.allocated);
                });
                
                // Update total
                updateBudgetTotal();
            }
            $('#budgetModal').modal('show');
        }
    });
}

// SAVE BUDGET - WITH ALL CATEGORIES
function saveBudget() {
    const month = $('#budgetMonth').val();
    
    // Kunin ang values ng LAHAT ng categories
    const budgets = [
        { category: 'Office Supplies', amount: $('#budgetOffice').val() || 0 },
        { category: 'Medical Equipment', amount: $('#budgetMedical').val() || 0 },
        { category: 'Optical Supplies', amount: $('#budgetOptical').val() || 0 },
        { category: 'Maintenance', amount: $('#budgetMaintenance').val() || 0 },
        { category: 'Utilities', amount: $('#budgetUtilities').val() || 0 },
        { category: 'Other', amount: $('#budgetOthers').val() || 0 },
        // Add missing categories from your display
        { category: 'Equipment', amount: $('#budgetEquipment').val() || 0 },
        { category: 'Marketing', amount: $('#budgetMarketing').val() || 0 },
        { category: 'Rent', amount: $('#budgetRent').val() || 0 },
        { category: 'Salaries', amount: $('#budgetSalaries').val() || 0 },
        { category: 'Training', amount: $('#budgetTraining').val() || 0 },
        { category: 'Travel', amount: $('#budgetTravel').val() || 0 }
    ];
    
    console.log('Saving all budgets:', budgets);
    
    $.ajax({
        url: 'api/expenses.php',
        method: 'POST',
        data: {
            action: 'save_budget',
            month: month,
            budgets: JSON.stringify(budgets)
        },
        success: function(response) {
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Budget Saved!',
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => {
                    $('#budgetModal').modal('hide');
                    loadBudgetData();
                });
            } else {
                Swal.fire('Error', response.error || 'Failed to save budget', 'error');
            }
        },
        error: function(xhr) {
            console.error('Save error:', xhr.responseText);
            Swal.fire('Error', 'Server error', 'error');
        }
    });
}

function formatDate(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-PH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

function formatDateTime(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleString('en-PH', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function truncate(str, length) {
    if (!str) return '';
    return str.length > length ? str.substring(0, length) + '...' : str;
}

function getPriorityClass(priority) {
    switch(priority) {
        case 'Critical': return 'priority-critical';
        case 'High': return 'priority-high';
        case 'Medium': return 'priority-medium';
        case 'Low': return 'priority-low';
        default: return 'priority-medium';
    }
}

function initializeDataTables() {
    // Initialize DataTables if needed
}

function refreshPendingPRs() {
    loadPendingPRs();
}

function exportExpenses() {
    const params = new URLSearchParams();
    params.append('action', 'export');
    params.append('month', currentMonth);
    
    window.location.href = `api/expenses.php?${params.toString()}`;
}

function downloadExpense(id) {
    Swal.fire({
        icon: 'info',
        title: 'Download',
        text: `Downloading expense #${id} details...`,
        timer: 1500,
        showConfirmButton: false
    });
}