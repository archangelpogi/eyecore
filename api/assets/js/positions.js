// ============= PERMISSION VARIABLES =============
let currentUserRole = null;
let userPermissions = {
    view: false,
    add: false,
    edit: false,
    delete: false,
    export: false
};
let hasHR = false;
let isOwner = false;
let positionsData = []; // Initialize as empty array immediately

async function loadPermissions() {
    try {
        const response = await fetch('api/positions.php?get_permissions=true');
        const data = await response.json();
        
        if (data.success) {
            currentUserRole = data.data.role;
            userPermissions = data.data.permissions;
            hasHR = data.data.hasHR;
            isOwner = data.data.isOwner;
            
            console.log('✅ Positions Permissions loaded:', {
                role: currentUserRole,
                permissions: userPermissions,
                hasHR: hasHR
            });
            
            applyPermissionBasedUI();
            await loadPositions();
        } else {
            console.error('Failed to load permissions:', data.error);
            loadPositions(); // Still try to load positions
        }
    } catch (error) {
        console.error('Error loading permissions:', error);
        loadPositions();
    }
}

// ============= PERMISSION HELPER FUNCTIONS =============
function canView() { return userPermissions.view; }
function canAdd() { return userPermissions.add; }
function canEdit() { return userPermissions.edit; }
function canDelete() { return userPermissions.delete; }
function canExport() { return userPermissions.export; }

function applyPermissionBasedUI() {
    // Hide/show Add Position button
    const addButton = document.querySelector('button[onclick="openAddPositionModal()"]');
    if (addButton) {
        addButton.style.display = canAdd() ? 'inline-block' : 'none';
    }
    
    // Hide/show Export button if exists
    const exportButton = document.querySelector('button[onclick="exportPositions()"]');
    if (exportButton) {
        exportButton.style.display = canExport() ? 'inline-block' : 'none';
    }
    
    // If user can't view at all, show access denied message
    if (!canView()) {
        const container = document.querySelector('.container-fluid');
        if (container) {
            const mainContent = document.querySelector('.card-soft');
            if (mainContent) {
                mainContent.innerHTML = `
                    <div class="card-body text-center py-5">
                        <i class="bi bi-shield-lock text-muted" style="font-size: 4rem;"></i>
                        <h4 class="mt-3">Access Denied</h4>
                        <p class="text-muted">You don't have permission to view positions.</p>
                    </div>
                `;
            }
        }
        return;
    }
}

// ============= LOAD POSITIONS =============
async function loadPositions() {
    try {
        const response = await fetch('api/positions.php');
        const data = await response.json();
        
        // Check if data is array (positions) or error
        if (Array.isArray(data)) {
            positionsData = data;
        } else {
            console.error('Error loading positions:', data);
            positionsData = [];
        }
    } catch (error) {
        console.error('Fetch error:', error);
        positionsData = [];
    }
    
    // Always render the table after positions are loaded
    renderPositionsTable();
    updateStats();
}

function renderPositionsTable() {
    const tbody = document.getElementById('positionsTableBody');
    if (!tbody) return;
    
    tbody.innerHTML = '';
    
    if (!canView()) {
        tbody.innerHTML = `
            <td colspan="6" class="text-center py-5">
                <i class="bi bi-shield-lock text-muted" style="font-size: 3rem;"></i>
                <h5 class="mt-3">Access Denied</h5>
                <p class="text-muted">You don't have permission to view positions.</p>
            </td>
        </tr>`;
        return;
    }
    
    if (!positionsData || positionsData.length === 0) {
        tbody.innerHTML = `
            <td colspan="6" class="text-center py-5">
                <i class="bi bi-briefcase text-muted" style="font-size: 3rem;"></i>
                <h5 class="mt-3">No Positions Found</h5>
                <p class="text-muted">Click "Add Position" to create your first position.</p>
            </td>
        </tr>`;
        return;
    }
    
    positionsData.forEach(position => {
        const departmentBadge = position.department ? 
            `<span class="badge bg-info">${position.department}</span>` : 
            '<span class="badge bg-secondary">N/A</span>';
        
        // ✅ ITO ANG BAGO - build action buttons based on permissions
        let actionButtons = '';
        
        // Edit button - only if can edit
        if (canEdit()) {
            actionButtons += `
                <button class="btn btn-outline-primary btn-sm" onclick="editPosition(${position.id})">
                    <i class="bi bi-pencil"></i>
                </button>
            `;
        }
        
        // Delete button - only if can delete
        if (canDelete()) {
            actionButtons += `
                <button class="btn btn-outline-danger btn-sm" onclick="deletePosition(${position.id})"
                    ${position.employee_count > 0 ? 'disabled title="Cannot delete: Has employees"' : ''}>
                    <i class="bi bi-trash"></i>
                </button>
            `;
        }
        
        // If no action buttons, show "View Only" badge
        if (!actionButtons) {
            actionButtons = '<span class="badge bg-secondary">View Only</span>';
        }
        
        tbody.innerHTML += `
            <tr>
                <td>
                    <div class="fw-bold">${position.position_name}</div>
                 </td>
                 <td>${departmentBadge}</td>
                <td class="salary-cell">
                    ${position.salary_rate ? '₱' + parseFloat(position.salary_rate).toLocaleString() : 'Not set'}
                 </td>
                 <td>
                    <span class="badge ${position.employee_count > 0 ? 'bg-primary' : 'bg-secondary'}">
                        ${position.employee_count} employee(s)
                    </span>
                 </td>
                 <td>
                    <small class="text-muted">${formatDate(position.created_at, true)}</small>
                 </td>
                 <td>
                    <div class="btn-group btn-group-sm">
                        ${actionButtons}
                    </div>
                 </td>
             </tr>
        `;
    });
}

// ============= UPDATE STATS =============
function updateStats() {
    // Get elements with null checks
    const totalPositionsEl = document.getElementById('totalPositions');
    const totalEmployeesEl = document.getElementById('totalEmployees');
    const averageSalaryEl = document.getElementById('averageSalary');
    
    if (!totalPositionsEl || !totalEmployeesEl || !averageSalaryEl) return;
    
    // If no positions or can't view, show zeros
    if (!positionsData || positionsData.length === 0 || !canView()) {
        totalPositionsEl.textContent = '0';
        totalEmployeesEl.textContent = '0';
        averageSalaryEl.textContent = '₱0';
        return;
    }
    
    const totalPositions = positionsData.length;
    const totalEmployees = positionsData.reduce((sum, pos) => sum + parseInt(pos.employee_count || 0), 0);
    
    // Calculate average salary
    const positionsWithSalary = positionsData.filter(p => p.salary_rate);
    const totalSalary = positionsWithSalary.reduce((sum, pos) => sum + parseFloat(pos.salary_rate || 0), 0);
    const averageSalary = positionsWithSalary.length > 0 ? totalSalary / positionsWithSalary.length : 0;
    
    totalPositionsEl.textContent = totalPositions;
    totalEmployeesEl.textContent = totalEmployees;
    averageSalaryEl.textContent = '₱' + Math.round(averageSalary).toLocaleString();
}

function openAddPositionModal() {
    if (!canAdd()) {
        // Remove the alert, just do nothing
        return;
    }
    
    document.getElementById('positionModalTitle').textContent = 'Add New Position';
    document.getElementById('positionForm').reset();
    document.getElementById('position_id').value = '';
    new bootstrap.Modal(document.getElementById('positionModal')).show();
}

function editPosition(id) {
    if (!canEdit()) {
        // Remove the alert, just do nothing
        return;
    }
    
    const position = positionsData.find(p => p.id == id);
    if (position) {
        document.getElementById('positionModalTitle').textContent = 'Edit Position';
        document.getElementById('position_id').value = position.id;
        document.getElementById('position_name').value = position.position_name;
        document.getElementById('department').value = position.department || '';
        document.getElementById('salary_rate').value = position.salary_rate || '';
        
        new bootstrap.Modal(document.getElementById('positionModal')).show();
    }
}

function deletePosition(id) {
    if (!canDelete()) {
        // Remove the alert, just do nothing
        return;
    }
    
    Swal.fire({
        title: 'Delete Position?',
        text: 'This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete it',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('api/positions.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({delete_position: true, position_id: id})
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Deleted!', data.message, 'success');
                    loadPositions();
                } else {
                    Swal.fire('Error!', data.error, 'error');
                }
            });
        }
    });
}

// ============= SAVE POSITION =============
function savePosition(e) {
    e.preventDefault();
    
    // Double-check permission
    const positionId = document.getElementById('position_id').value;
    const action = positionId ? 'edit' : 'add';
    
    if ((action === 'add' && !canAdd()) || (action === 'edit' && !canEdit())) {
        Swal.fire('Access Denied', 'You do not have permission to perform this action', 'error');
        return;
    }
    
    const formData = {
        position_name: document.getElementById('position_name').value,
        department: document.getElementById('department').value,
        salary_rate: document.getElementById('salary_rate').value || null
    };
    
    if (!formData.position_name) {
        Swal.fire('Error!', 'Position name is required', 'error');
        return;
    }
    
    const url = 'api/positions.php';
    
    fetch(url, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            [positionId ? 'update_position' : 'add_position']: true,
            position_id: positionId || null,
            ...formData
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            Swal.fire('Success!', data.message, 'success');
            bootstrap.Modal.getInstance(document.getElementById('positionModal')).hide();
            loadPositions();
        } else {
            Swal.fire('Error!', data.error, 'error');
        }
    });
}

// ============= FORMAT DATE =============
function formatDate(dateString, dateOnly = false) {
    if (!dateString) return 'N/A';
    
    const date = new Date(dateString);
    
    if (dateOnly) {
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }
    
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// ============= EXPORT POSITIONS (if needed) =============
function exportPositions() {
    if (!canExport()) {
        Swal.fire('Access Denied', 'You do not have permission to export positions', 'error');
        return;
    }
    
    // Add your export logic here
    let csv = 'Position Name,Department,Salary Rate,Employee Count\n';
    positionsData.forEach(pos => {
        csv += `"${pos.position_name}","${pos.department || ''}","${pos.salary_rate || ''}","${pos.employee_count || 0}"\n`;
    });
    
    const blob = new Blob([csv], {type: 'text/csv'});
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `positions_${new Date().toISOString().split('T')[0]}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

// ============= MODIFY DOCUMENT READY =============
document.addEventListener('DOMContentLoaded', async () => {
    // Initialize positionsData as empty array
    positionsData = [];
    
    // Load permissions first, then positions will be loaded automatically
    await loadPermissions();
    
    // Add form submit listener
    const form = document.getElementById('positionForm');
    if (form) {
        form.addEventListener('submit', savePosition);
    }
});