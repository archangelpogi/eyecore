document.addEventListener('DOMContentLoaded', function() {
    loadInventoryData();
    initModalListeners();
    loadSuppliersForDropdown();
    
    const supplierSelect = document.getElementById('supplierSelect');
    const supplierDetails = document.getElementById('supplierDetails');
    const supplierContact = document.getElementById('supplierContact');
    const supplierEmail = document.getElementById('supplierEmail');
    const supplierMobile = document.getElementById('supplierMobile');

    if (supplierSelect) {
        supplierSelect.addEventListener('change', function() {
            const selected = this.options[this.selectedIndex];
            
            if (this.value && selected) {
                const contact = selected.dataset.contact;
                const email = selected.dataset.email;
                const mobile = selected.dataset.mobile;
                
                if (contact || email || mobile) {
                    supplierContact.textContent = contact || 'N/A';
                    supplierEmail.textContent = email || 'N/A';
                    supplierMobile.textContent = mobile || 'N/A';
                    supplierDetails.style.display = 'block';
                } else {
                    supplierDetails.style.display = 'none';
                }
            } else {
                supplierDetails.style.display = 'none';
            }
        });
    }
});

// Global variables
let currentPage = 1;
let currentSearch = '';
let currentCategory = 'all';
let currentStatus = 'all';

// ============================================
// COLOR VARIANTS FUNCTIONS - ADD FORM
// ============================================

let colorRowCount = 0;
let editColorRowCount = 0;

// Predefined color suggestions with proper mappings
const colorSuggestions = {
    'red': '#FF0000',
    'blue': '#0000FF',
    'green': '#00FF00',
    'yellow': '#FFFF00',
    'black': '#000000',
    'white': '#FFFFFF',
    'purple': '#800080',
    'orange': '#FFA500',
    'pink': '#FFC0CB',
    'brown': '#8B4513',
    'gray': '#808080',
    'grey': '#808080',
    'silver': '#C0C0C0',
    'gold': '#FFD700',
    'navy': '#000080',
    'teal': '#008080',
    'maroon': '#800000',
    'olive': '#808000',
    'lime': '#00FF00',
    'cyan': '#00FFFF',
    'magenta': '#FF00FF',
    'violet': '#EE82EE',
    'indigo': '#4B0082',
    'turquoise': '#40E0D0',
    'beige': '#F5F5DC',
    'coral': '#FF7F50',
    'lavender': '#E6E6FA',
    'mint': '#98FB98',
    'peach': '#FFDAB9',
    'apricot': '#FBCEB1',
    'rose': '#FF007F',
    'ruby': '#E0115F',
    'emerald': '#50C878',
    'sapphire': '#0F52BA',
    'topaz': '#FFC87C',
    'amber': '#FFBF00',
    'bronze': '#CD7F32',
    'copper': '#B87333'
};

// Reverse mapping for code to name lookup
const codeToName = {};
Object.keys(colorSuggestions).forEach(key => {
    codeToName[colorSuggestions[key]] = key;
});

function addColorRow() {
    colorRowCount++;
    const container = document.getElementById('colorRowsContainer');
    const noColorsMsg = document.getElementById('noColorsMessage');
    
    if (noColorsMsg) noColorsMsg.style.display = 'none';
    
    const rowId = `color_row_${colorRowCount}`;
    const colorRow = document.createElement('div');
    colorRow.className = 'color-row animate__animated animate__fadeIn';
    colorRow.id = rowId;
    
    colorRow.innerHTML = `
        <div class="row align-items-center g-3">
            <div class="col-lg-3 col-md-12">
                <div class="d-flex align-items-center gap-3">
                    <div class="color-preview" id="preview_${colorRowCount}" style="background-color: #FF0000;"></div>
                    <div class="flex-grow-1">
                        <label class="form-label small text-muted mb-1">
                            <i class="fas fa-tag me-1"></i>Color Name
                        </label>
                        <input type="text" class="form-control form-control-sm color-name-input" 
                               placeholder="e.g., Red, Blue, Black"
                               id="color_name_${colorRowCount}" 
                               value="Red"
                               oninput="updateColorFromName('${colorRowCount}', this.value)"
                               list="colorSuggestions">
                        <datalist id="colorSuggestions">
                            ${Object.keys(colorSuggestions).map(color => 
                                `<option value="${color.charAt(0).toUpperCase() + color.slice(1)}">`
                            ).join('')}
                        </datalist>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-2 col-md-4 col-6">
                <label class="form-label small text-muted mb-1">
                    <i class="fas fa-palette me-1"></i>Color Code
                </label>
                <div class="d-flex align-items-center gap-2">
                    <input type="color" class="form-control form-control-sm color-code-input" 
                           id="color_code_${colorRowCount}" value="#FF0000"
                           onchange="updateColorFromCode('${colorRowCount}', this.value)">
                    <span class="color-code-text" id="code_text_${colorRowCount}">#FF0000</span>
                </div>
            </div>
            
            <div class="col-lg-1 col-md-4 col-6">
                <label class="form-label small text-muted mb-1">
                    <i class="fas fa-cubes me-1"></i>Qty
                </label>
                <input type="number" class="form-control form-control-sm color-quantity-input" 
                       placeholder="0" min="0" value="1"
                       id="color_qty_${colorRowCount}">
            </div>
            
            <div class="col-lg-2 col-md-4 col-6">
                <label class="form-label small text-muted mb-1">
                    <i class="fas fa-check-circle me-1"></i>Status
                </label>
                <div class="d-flex align-items-center">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" 
                               id="color_available_${colorRowCount}" checked
                               onchange="updateAvailabilityBadge('${colorRowCount}', this.checked)">
                        <label class="form-check-label" for="color_available_${colorRowCount}">
                            <span class="badge bg-success" id="avail_badge_${colorRowCount}">Available</span>
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-2 col-md-8">
                <label class="form-label small text-muted mb-1">
                    <i class="fas fa-info-circle me-1"></i>Hex Value
                </label>
                <div class="d-flex align-items-center">
                    <code class="hex-value" id="hex_${colorRowCount}">#FF0000</code>
                </div>
            </div>
            
            <div class="col-lg-2 col-md-4">
                <label class="form-label small text-muted mb-1">&nbsp;</label>
                <div>
                    <button type="button" class="btn-delete-color" onclick="removeColorRow('${rowId}')" 
                            title="Remove this color variant">
                        <i class="fas fa-trash-alt me-1"></i> Remove
                    </button>
                </div>
            </div>
        </div>
    `;
    
    container.appendChild(colorRow);
    
    setTimeout(() => {
        colorRow.classList.remove('animate__animated', 'animate__fadeIn');
    }, 500);
}

function updateColorFromName(rowId, colorName) {
    const normalizedName = colorName.toLowerCase().trim();
    
    // Check if the color name matches our predefined colors
    if (colorSuggestions[normalizedName]) {
        const colorCode = colorSuggestions[normalizedName];
        
        // Update color code input
        const codeInput = document.getElementById(`color_code_${rowId}`);
        if (codeInput) {
            codeInput.value = colorCode;
        }
        
        // Update preview
        const preview = document.getElementById(`preview_${rowId}`);
        if (preview) {
            preview.style.backgroundColor = colorCode;
        }
        
        // Update code text
        const codeText = document.getElementById(`code_text_${rowId}`);
        if (codeText) {
            codeText.textContent = colorCode;
        }
        
        // Update hex value
        const hexValue = document.getElementById(`hex_${rowId}`);
        if (hexValue) {
            hexValue.textContent = colorCode;
        }
    }
}

function updateColorFromCode(rowId, colorCode) {
    // Update preview
    const preview = document.getElementById(`preview_${rowId}`);
    if (preview) {
        preview.style.backgroundColor = colorCode;
    }
    
    // Update code text
    const codeText = document.getElementById(`code_text_${rowId}`);
    if (codeText) {
        codeText.textContent = colorCode;
    }
    
    // Update hex value
    const hexValue = document.getElementById(`hex_${rowId}`);
    if (hexValue) {
        hexValue.textContent = colorCode;
    }
    
    // Try to find color name from code (case-insensitive)
    const upperCode = colorCode.toUpperCase();
    const colorName = Object.keys(codeToName).find(code => code.toUpperCase() === upperCode);
    
    if (colorName) {
        const nameInput = document.getElementById(`color_name_${rowId}`);
        if (nameInput) {
            const properName = codeToName[colorName];
            // Capitalize first letter
            const formattedName = properName.charAt(0).toUpperCase() + properName.slice(1);
            nameInput.value = formattedName;
        }
    }
}

function updateAvailabilityBadge(rowId, isAvailable) {
    const badge = document.getElementById(`avail_badge_${rowId}`);
    if (isAvailable) {
        badge.className = 'badge bg-success';
        badge.textContent = 'Available';
    } else {
        badge.className = 'badge bg-secondary';
        badge.textContent = 'Unavailable';
    }
}

function removeColorRow(rowId) {
    Swal.fire({
        title: 'Remove Color?',
        text: "This color variant will be removed from the list. You can add it again later if needed.",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, remove it',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            const row = document.getElementById(rowId);
            if (row) {
                row.classList.add('animate__animated', 'animate__fadeOut');
                setTimeout(() => {
                    row.remove();
                    
                    // Check if no more color rows
                    const container = document.getElementById('colorRowsContainer');
                    const noColorsMsg = document.getElementById('noColorsMessage');
                    
                    if (container.children.length === 0 && noColorsMsg) {
                        noColorsMsg.style.display = 'block';
                    }
                }, 300);
            }
            
            Swal.fire({
                icon: 'success',
                title: 'Removed!',
                text: 'Color variant has been removed.',
                timer: 1500,
                showConfirmButton: false
            });
        }
    });
}

function getAllColorRows() {
    const rows = document.querySelectorAll('#colorRowsContainer .color-row');
    const colors = [];
    
    rows.forEach(row => {
        const id = row.id.split('_')[2];
        const name = document.getElementById(`color_name_${id}`)?.value;
        const code = document.getElementById(`color_code_${id}`)?.value;
        const qty = parseInt(document.getElementById(`color_qty_${id}`)?.value) || 0;
        const available = document.getElementById(`color_available_${id}`)?.checked || false;
        
        if (name && code) {
            colors.push({
                name: name,
                code: code,
                quantity: qty,
                is_available: available
            });
        }
    });
    
    return colors;
}

// ============================================
// COLOR VARIANTS FUNCTIONS - EDIT FORM
// ============================================

function addEditColorRow() {
    editColorRowCount++;
    const container = document.getElementById('editColorRowsContainer');
    const noColorsMsg = document.getElementById('editNoColorsMessage');
    
    if (noColorsMsg) noColorsMsg.style.display = 'none';
    
    const rowId = `edit_color_row_${editColorRowCount}`;
    const colorRow = document.createElement('div');
    colorRow.className = 'color-row animate__animated animate__fadeIn';
    colorRow.id = rowId;
    
    colorRow.innerHTML = `
        <div class="row align-items-center g-3">
            <div class="col-lg-3 col-md-12">
                <div class="d-flex align-items-center gap-3">
                    <div class="color-preview" id="edit_preview_${editColorRowCount}" style="background-color: #FF0000;"></div>
                    <div class="flex-grow-1">
                        <label class="form-label small text-muted mb-1">
                            <i class="fas fa-tag me-1"></i>Color Name
                        </label>
                        <input type="text" class="form-control form-control-sm color-name-input" 
                               placeholder="e.g., Red, Blue, Black"
                               id="edit_color_name_${editColorRowCount}" 
                               value="Red"
                               oninput="updateEditColorFromName('${editColorRowCount}', this.value)"
                               list="editColorSuggestions">
                        <datalist id="editColorSuggestions">
                            ${Object.keys(colorSuggestions).map(color => 
                                `<option value="${color.charAt(0).toUpperCase() + color.slice(1)}">`
                            ).join('')}
                        </datalist>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-2 col-md-4 col-6">
                <label class="form-label small text-muted mb-1">
                    <i class="fas fa-palette me-1"></i>Color Code
                </label>
                <div class="d-flex align-items-center gap-2">
                    <input type="color" class="form-control form-control-sm color-code-input" 
                           id="edit_color_code_${editColorRowCount}" value="#FF0000"
                           onchange="updateEditColorFromCode('${editColorRowCount}', this.value)">
                    <span class="color-code-text" id="edit_code_text_${editColorRowCount}">#FF0000</span>
                </div>
            </div>
            
            <div class="col-lg-1 col-md-4 col-6">
                <label class="form-label small text-muted mb-1">
                    <i class="fas fa-cubes me-1"></i>Qty
                </label>
                <input type="number" class="form-control form-control-sm color-quantity-input" 
                       placeholder="0" min="0" value="1"
                       id="edit_color_qty_${editColorRowCount}">
            </div>
            
            <div class="col-lg-2 col-md-4 col-6">
                <label class="form-label small text-muted mb-1">
                    <i class="fas fa-check-circle me-1"></i>Status
                </label>
                <div class="d-flex align-items-center">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" 
                               id="edit_color_available_${editColorRowCount}" checked
                               onchange="updateEditAvailabilityBadge('${editColorRowCount}', this.checked)">
                        <label class="form-check-label" for="edit_color_available_${editColorRowCount}">
                            <span class="badge bg-success" id="edit_avail_badge_${editColorRowCount}">Available</span>
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-2 col-md-8">
                <label class="form-label small text-muted mb-1">
                    <i class="fas fa-info-circle me-1"></i>Hex Value
                </label>
                <div class="d-flex align-items-center">
                    <code class="hex-value" id="edit_hex_${editColorRowCount}">#FF0000</code>
                </div>
            </div>
            
            <div class="col-lg-2 col-md-4">
                <label class="form-label small text-muted mb-1">&nbsp;</label>
                <div>
                    <button type="button" class="btn-delete-color" onclick="removeEditColorRow('${rowId}')" 
                            title="Remove this color variant">
                        <i class="fas fa-trash-alt me-1"></i> Remove
                    </button>
                </div>
            </div>
        </div>
    `;
    
    container.appendChild(colorRow);
    
    setTimeout(() => {
        colorRow.classList.remove('animate__animated', 'animate__fadeIn');
    }, 500);
}

function updateEditColorFromName(rowId, colorName) {
    const normalizedName = colorName.toLowerCase().trim();
    
    if (colorSuggestions[normalizedName]) {
        const colorCode = colorSuggestions[normalizedName];
        
        // Update color code input
        const codeInput = document.getElementById(`edit_color_code_${rowId}`);
        if (codeInput) {
            codeInput.value = colorCode;
        }
        
        // Update preview
        const preview = document.getElementById(`edit_preview_${rowId}`);
        if (preview) {
            preview.style.backgroundColor = colorCode;
        }
        
        // Update code text
        const codeText = document.getElementById(`edit_code_text_${rowId}`);
        if (codeText) {
            codeText.textContent = colorCode;
        }
        
        // Update hex value
        const hexValue = document.getElementById(`edit_hex_${rowId}`);
        if (hexValue) {
            hexValue.textContent = colorCode;
        }
    }
}

function updateEditColorFromCode(rowId, colorCode) {
    // Update preview
    const preview = document.getElementById(`edit_preview_${rowId}`);
    if (preview) {
        preview.style.backgroundColor = colorCode;
    }
    
    // Update code text
    const codeText = document.getElementById(`edit_code_text_${rowId}`);
    if (codeText) {
        codeText.textContent = colorCode;
    }
    
    // Update hex value
    const hexValue = document.getElementById(`edit_hex_${rowId}`);
    if (hexValue) {
        hexValue.textContent = colorCode;
    }
    
    // Try to find color name from code
    const upperCode = colorCode.toUpperCase();
    const colorName = Object.keys(codeToName).find(code => code.toUpperCase() === upperCode);
    
    if (colorName) {
        const nameInput = document.getElementById(`edit_color_name_${rowId}`);
        if (nameInput) {
            const properName = codeToName[colorName];
            const formattedName = properName.charAt(0).toUpperCase() + properName.slice(1);
            nameInput.value = formattedName;
        }
    }
}

function updateEditAvailabilityBadge(rowId, isAvailable) {
    const badge = document.getElementById(`edit_avail_badge_${rowId}`);
    if (isAvailable) {
        badge.className = 'badge bg-success';
        badge.textContent = 'Available';
    } else {
        badge.className = 'badge bg-secondary';
        badge.textContent = 'Unavailable';
    }
}

function removeEditColorRow(rowId) {
    Swal.fire({
        title: 'Remove Color?',
        text: "This color variant will be removed from the list. You can add it again later if needed.",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, remove it',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            const row = document.getElementById(rowId);
            if (row) {
                row.classList.add('animate__animated', 'animate__fadeOut');
                setTimeout(() => {
                    row.remove();
                    
                    const container = document.getElementById('editColorRowsContainer');
                    const noColorsMsg = document.getElementById('editNoColorsMessage');
                    
                    if (container.children.length === 0 && noColorsMsg) {
                        noColorsMsg.style.display = 'block';
                    }
                }, 300);
            }
            
            Swal.fire({
                icon: 'success',
                title: 'Removed!',
                text: 'Color variant has been removed.',
                timer: 1500,
                showConfirmButton: false
            });
        }
    });
}

function getAllEditColorRows() {
    const rows = document.querySelectorAll('#editColorRowsContainer .color-row');
    const colors = [];
    
    rows.forEach(row => {
        const id = row.id.split('_')[3];
        const name = document.getElementById(`edit_color_name_${id}`)?.value;
        const code = document.getElementById(`edit_color_code_${id}`)?.value;
        const qty = parseInt(document.getElementById(`edit_color_qty_${id}`)?.value) || 0;
        const available = document.getElementById(`edit_color_available_${id}`)?.checked || false;
        
        if (name && code) {
            colors.push({
                name: name,
                code: code,
                quantity: qty,
                is_available: available
            });
        }
    });
    
    return colors;
}

function loadEditColorRows(colors) {
    if (!colors || colors.length === 0) return;
    
    colors.forEach(color => {
        addEditColorRow();
        const id = editColorRowCount;
        
        setTimeout(() => {
            const nameInput = document.getElementById(`edit_color_name_${id}`);
            const codeInput = document.getElementById(`edit_color_code_${id}`);
            const qtyInput = document.getElementById(`edit_color_qty_${id}`);
            const availCheck = document.getElementById(`edit_color_available_${id}`);
            const preview = document.getElementById(`edit_preview_${id}`);
            const codeText = document.getElementById(`edit_code_text_${id}`);
            const hexValue = document.getElementById(`edit_hex_${id}`);
            const availBadge = document.getElementById(`edit_avail_badge_${id}`);
            
            if (nameInput) nameInput.value = color.color_name || color.name || '';
            if (codeInput) {
                const code = color.color_code || color.code || '#FF0000';
                codeInput.value = code;
                if (preview) preview.style.backgroundColor = code;
                if (codeText) codeText.textContent = code;
                if (hexValue) hexValue.textContent = code;
            }
            if (qtyInput) qtyInput.value = color.quantity || 0;
            if (availCheck) {
                const isAvailable = color.is_available !== false;
                availCheck.checked = isAvailable;
                if (availBadge) {
                    availBadge.className = isAvailable ? 'badge bg-success' : 'badge bg-secondary';
                    availBadge.textContent = isAvailable ? 'Available' : 'Unavailable';
                }
            }
            
            // If there's a color code, try to update the name from it
            if (codeInput && nameInput) {
                const upperCode = codeInput.value.toUpperCase();
                const colorName = Object.keys(codeToName).find(code => code.toUpperCase() === upperCode);
                if (colorName) {
                    const properName = codeToName[colorName];
                    nameInput.value = properName.charAt(0).toUpperCase() + properName.slice(1);
                }
            }
        }, 100);
    });
}

// ============================================
// END COLOR VARIANTS FUNCTIONS
// ============================================

// Load suppliers for dropdown
function loadSuppliersForDropdown() {
    fetch('api/suppliers.php?action=get_all')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data) {
                const supplierSelect = document.getElementById('supplierSelect');
                if (supplierSelect) {
                    supplierSelect.innerHTML = '<option value="">-- Select Supplier (Optional) --</option>';
                    data.data.forEach(supplier => {
                        const option = document.createElement('option');
                        option.value = supplier.id;
                        option.textContent = `${supplier.supplier_name} (${supplier.city || 'N/A'})`;
                        option.dataset.contact = supplier.contact_person || '';
                        option.dataset.email = supplier.email || '';
                        option.dataset.mobile = supplier.mobile || '';
                        supplierSelect.appendChild(option);
                    });
                }
            }
        })
        .catch(error => console.error('Error loading suppliers:', error));
}

// Load inventory data via AJAX
function loadInventoryData(page = null) {
    if (page !== null) {
        currentPage = page;
    }
    
    currentSearch = document.getElementById('searchInput').value;
    currentCategory = document.getElementById('categoryFilter').value;
    currentStatus = document.getElementById('statusFilter').value;
    
    // Show loading
    document.getElementById('inventoryTableBody').innerHTML = `
        <tr>
            <td colspan="10" class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2 text-muted">Loading inventory data...</p>
            </td>
        </tr>
    `;
    
    // AJAX call
    fetch(`api/inventory.php?action=get_items&page=${currentPage}&search=${encodeURIComponent(currentSearch)}&category=${currentCategory}&status=${currentStatus}`)
        .then(response => response.json())
        .then(data => {
            if(data.success) {
                renderInventoryTable(data.data);
                renderPagination(data.pagination);
                updateStats();
            } else {
                showError('Failed to load inventory data');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showError('Network error. Please try again.');
        });
}

// Update stats
function updateStats() {
    fetch('api/inventory.php?action=get_stats')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('statTotalItems').textContent = data.total_items;
                document.getElementById('statLowStock').textContent = data.low_stock;
                document.getElementById('statOutOfStock').textContent = data.out_of_stock;
                document.getElementById('statTotalValue').textContent = `₱${formatNumber(data.total_value)}`;
            }
        })
        .catch(error => console.error('Error updating stats:', error));
}

function renderInventoryTable(items) {
    const tbody = document.getElementById('inventoryTableBody');
    
    if(items.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="10" class="text-center py-5">
                    <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
                    <h5 class="text-muted">No items found</h5>
                    <p class="text-muted">Try adjusting your search or filters</p>
                </td>
            </tr>
        `;
        return;
    }
    
    tbody.innerHTML = items.map(item => {
        const stock = parseInt(item.stock) || 0;
        const reorderLevel = parseInt(item.reorder_level) || 5;
        const sellingPrice = parseFloat(item.selling_price) || 0;
        const cost = parseFloat(item.cost) || 0;
        const supplierPrice = parseFloat(item.supplier_product_price) || 0;
        const suggestedPr = parseInt(item.suggested_pr) || 0;
        
        const profit = sellingPrice - cost;
        const margin = sellingPrice > 0 ? ((profit / sellingPrice) * 100).toFixed(1) : 0;
        
        let statusBadge = '';
        let statusText = '';
        
        if (stock <= 0) {
            statusBadge = 'bg-danger';
            statusText = 'Out of Stock';
        } else if (stock <= reorderLevel) {
            statusBadge = 'bg-warning';
            statusText = 'Low Stock';
        } else {
            statusBadge = 'bg-success';
            statusText = 'In Stock';
        }
        
        return `
        <tr>
            <td>
                <span class="badge bg-light text-dark border">${escapeHtml(item.item_id || '')}</span>
                ${suggestedPr > 0 ? 
                    `<span class="badge bg-warning text-dark ms-1" title="Suggested reorder: ${suggestedPr} pcs">🔄</span>` : 
                    ''}
            </td>
            <td class="fw-bold">${escapeHtml(item.name || '')}</td>
            <td>
                <span class="badge ${getCategoryBadgeClass(item.category)}">
                    ${escapeHtml(item.category || '')}
                </span>
            </td>
            <td>
                ${item.brand ? `<div class="small">${escapeHtml(item.brand)}</div>` : ''}
                ${item.type ? `<div class="text-muted small">${escapeHtml(item.type)}</div>` : ''}
            </td>
            <td>
                <div class="fw-bold ${stock <= 0 ? 'text-danger' : (stock <= reorderLevel ? 'text-warning' : '')}">
                    ${stock}
                </div>
                <div class="progress" style="height: 5px;">
                    <div class="progress-bar ${getStockProgressClass(stock, reorderLevel)}" 
                         style="width: ${Math.min(100, (stock / (reorderLevel * 3)) * 100)}%">
                    </div>
                </div>
                ${suggestedPr > 0 ? 
                    `<small class="text-warning">Order: ${suggestedPr}</small>` : 
                    ''}
            </td>
            <td class="text-end">
                <div class="fw-bold text-primary">₱${formatNumber(sellingPrice)}</div>
                <small class="text-muted">Cost: ₱${formatNumber(cost)}</small>
            </td>
            <td class="text-end">
                <div>₱${formatNumber(supplierPrice)}</div>
                <small class="${profit >= 0 ? 'text-success' : 'text-danger'}">
                    ${profit >= 0 ? '+' : ''}₱${formatNumber(profit)} (${margin}%)
                </small>
            </td>
            <td>
                <span class="badge ${statusBadge}">
                    ${statusText}
                </span>
            </td>
            <td>
                ${item.supplier ? `<small>${escapeHtml(item.supplier)}</small>` : '-'}
            </td>
            <td class="text-center">
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-primary" onclick="showEditModal(${item.id})" title="Edit">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn btn-outline-success" onclick="showRestockModal(${item.id})" title="Restock">
                        <i class="bi bi-plus-circle"></i>
                    </button>
                    <button class="btn btn-outline-info" onclick="quickSaleItem(${item.id})" title="Quick Sale">
                        <i class="bi bi-cart"></i>
                    </button>
                    <button class="btn btn-outline-danger" onclick="deleteItem(${item.id})" title="Archive">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </td>
        </tr>
    `}).join('');
}

// Initialize modal event listeners
function initModalListeners() {
    const addItemForm = document.getElementById('addItemForm');
    if (addItemForm) {
        addItemForm.addEventListener('submit', function(e){
            e.preventDefault();
            handleAddItem();
        });
    }
    
    const restockForm = document.getElementById('restockForm');
    if (restockForm) {
        restockForm.addEventListener('submit', function(e){
            e.preventDefault();
            handleRestockItem();
        });
    }
    
    const editItemForm = document.getElementById('editItemForm');
    if (editItemForm) {
        editItemForm.addEventListener('submit', function(e){
            e.preventDefault();
            handleEditItem();
        });
    }
    
    // Profit preview listeners
    const addCost = document.getElementById('addCost');
    const addPrice = document.getElementById('addPrice');
    if (addCost && addPrice) {
        [addCost, addPrice].forEach(field => {
            field.addEventListener('input', updateAddProfitPreview);
        });
    }
    
    const editCost = document.getElementById('editCost');
    const editPrice = document.getElementById('editPrice');
    if (editCost && editPrice) {
        [editCost, editPrice].forEach(field => {
            field.addEventListener('input', updateEditProfitPreview);
        });
    }
}

// Profit preview functions
function updateAddProfitPreview() {
    const cost = parseFloat(document.getElementById('addCost').value) || 0;
    const price = parseFloat(document.getElementById('addPrice').value) || 0;
    const profit = price - cost;
    const margin = price > 0 ? ((profit / price) * 100).toFixed(1) : 0;
    
    const preview = document.getElementById('addProfitPreview');
    if (preview) {
        preview.innerHTML = `Profit: ₱${formatNumber(profit)} (${margin}%)`;
        preview.className = profit >= 0 ? 'alert alert-success py-2 mb-0' : 'alert alert-danger py-2 mb-0';
    }
}

function updateEditProfitPreview() {
    const cost = parseFloat(document.getElementById('editCost').value) || 0;
    const price = parseFloat(document.getElementById('editPrice').value) || 0;
    const profit = price - cost;
    const margin = price > 0 ? ((profit / price) * 100).toFixed(1) : 0;
    
    const preview = document.getElementById('editProfitPreview');
    if (preview) {
        preview.innerHTML = `Profit: ₱${formatNumber(profit)} (${margin}%)`;
        preview.className = profit >= 0 ? 'alert alert-success py-2 mb-0' : 'alert alert-danger py-2 mb-0';
    }
}

// ============================================
// FIXED: Handle Add Item with proper color formatting
// ============================================
function handleAddItem() {
    const form = document.getElementById('addItemForm');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);
    data.action = 'add';
    
    // Convert to proper types
    data.stock = parseInt(data.stock) || 0;
    data.reorder_level = parseInt(data.reorder_level) || 5;
    data.selling_price = parseFloat(data.selling_price) || 0;
    data.cost = parseFloat(data.cost) || 0;
    data.supplier_product_price = parseFloat(data.supplier_product_price) || 0;
    
    // ✅ FIXED: Get colors with proper formatting
    const colors = getAllColorRows();
    if (colors.length > 0) {
        // Format properly for database - use color_code and color_name
        data.colors = colors.map(color => ({
            color_code: color.code,
            color_name: color.name,
            quantity: color.quantity,
            is_available: color.is_available ? 1 : 0
        }));
    }
    
    // Get supplier
    const supplierSelect = document.getElementById('supplierSelect');
    if (supplierSelect && supplierSelect.value) {
        const selectedOption = supplierSelect.options[supplierSelect.selectedIndex];
        data.supplier_name = selectedOption.text.split('(')[0].trim();
        data.supplier_id = supplierSelect.value;
    }
    
    Swal.fire({
        title: 'Saving...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch('api/inventory.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    })
    .then(res => {
        if (!res.ok) throw new Error('Network response was not ok');
        return res.json();
    })
    .then(resp => {
        if(resp.success){
            let message = 'Item added successfully';
            if (resp.needs_reorder) {
                message += `<br><small class="text-warning">Suggested reorder: ${resp.suggested_pr} units</small>`;
            }
            
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                html: message,
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                form.reset();
                // Clear colors
                document.getElementById('colorRowsContainer').innerHTML = `
                    <div class="text-center text-muted py-4" id="noColorsMessage">
                        <i class="fas fa-palette fa-3x mb-3 opacity-50"></i>
                        <p class="mb-2">No colors added yet.</p>
                        <button type="button" class="btn btn-sm btn-primary" onclick="addColorRow()">
                            <i class="fas fa-plus me-1"></i>Add Your First Color
                        </button>
                    </div>
                `;
                colorRowCount = 0;
                document.getElementById('supplierDetails').style.display = 'none';
                const modal = bootstrap.Modal.getInstance(document.getElementById('addItemModal'));
                if (modal) modal.hide();
                loadInventoryData(1);
            });
        } else {
            Swal.fire('Error!', resp.message, 'error');
        }
    })
    .catch(err => {
        console.error('Error:', err);
        Swal.fire('Error!', 'Failed to save item. Please try again.', 'error');
    });
}

// Restock functions
function showRestockModal(id) {
    fetch(`api/inventory.php?action=get_item&id=${id}`)
        .then(res => res.json())
        .then(response => {
            if(response.success) {
                const item = response.data;
                document.getElementById('restockItemId').value = item.id;
                document.getElementById('restockItemName').textContent = item.name;
                document.getElementById('currentStock').textContent = item.stock;
                document.getElementById('reorderLevel').textContent = item.reorder_level;
                
                // Show suggested reorder if needed
                const suggestedEl = document.getElementById('suggestedReorder');
                if (suggestedEl && item.suggested_pr > 0) {
                    suggestedEl.textContent = `Suggested reorder quantity: ${item.suggested_pr} units`;
                    suggestedEl.style.display = 'block';
                } else if (suggestedEl) {
                    suggestedEl.style.display = 'none';
                }
                
                const modal = new bootstrap.Modal(document.getElementById('restockModal'));
                modal.show();
            } else {
                showError('Error loading item: ' + (response.message || 'Unknown error'));
            }
        })
        .catch(err => {
            console.error(err);
            showError('Error loading item');
        });
}

function handleRestockItem() {
    const itemId = document.getElementById('restockItemId').value;
    const quantity = document.querySelector('#restockForm input[name="quantity"]').value;
    
    if (!itemId || !quantity) {
        Swal.fire('Error!', 'Please fill all fields', 'error');
        return;
    }
    
    const data = {
        action: 'restock',
        id: itemId,
        quantity: parseInt(quantity)
    };
    
    Swal.fire({
        title: 'Restocking...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch('api/inventory.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    })
    .then(res => res.json())
    .then(resp => {
        if(resp.success){
            let message = 'Item restocked successfully';
            if (resp.needs_reorder) {
                message += `<br><small class="text-warning">Still needs reorder: ${resp.suggested_pr} units</small>`;
            }
            
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                html: message,
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                document.getElementById('restockForm').reset();
                bootstrap.Modal.getInstance(document.getElementById('restockModal')).hide();
                loadInventoryData();
            });
        } else {
            Swal.fire('Error!', resp.message, 'error');
        }
    })
    .catch(err => {
        console.error(err);
        Swal.fire('Error!', 'Failed to restock item', 'error');
    });
}

// Edit functions
function showEditModal(id) {
    fetch(`api/inventory.php?action=get_item&id=${id}`)
        .then(res => res.json())
        .then(response => {
            if(response.success) {
                const item = response.data;
                
                // Populate edit modal
                document.getElementById('editItemId').value = item.id;
                document.getElementById('editName').value = item.name;
                document.getElementById('editCategory').value = item.category;
                document.getElementById('editStock').value = item.stock;
                document.getElementById('editReorderLevel').value = item.reorder_level;
                document.getElementById('editPrice').value = item.selling_price;
                document.getElementById('editCost').value = item.cost || 0;
                document.getElementById('editSupplierPrice').value = item.supplier_product_price || 0;
                document.getElementById('editBrand').value = item.brand || '';
                document.getElementById('editType').value = item.type || '';
                
                // Clear existing color rows
                const container = document.getElementById('editColorRowsContainer');
                container.innerHTML = '';
                editColorRowCount = 0;
                
                // Load colors if any
                if (item.colors && item.colors.length > 0) {
                    loadEditColorRows(item.colors);
                } else {
                    // Show no colors message
                    container.innerHTML = `
                        <div class="text-center text-muted py-4" id="editNoColorsMessage">
                            <i class="fas fa-palette fa-3x mb-3 opacity-50"></i>
                            <p class="mb-2">No colors added yet.</p>
                            <button type="button" class="btn btn-sm btn-primary" onclick="addEditColorRow()">
                                <i class="fas fa-plus me-1"></i>Add Your First Color
                            </button>
                        </div>
                    `;
                }
                
                // Update profit preview
                updateEditProfitPreview();
                
                const modal = new bootstrap.Modal(document.getElementById('editItemModal'));
                modal.show();
            } else {
                showError('Error loading item');
            }
        })
        .catch(err => {
            console.error(err);
            showError('Error loading item');
        });
}

// ============================================
// FIXED: Handle Edit Item with proper color formatting
// ============================================
function handleEditItem() {
    const form = document.getElementById('editItemForm');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);
    data.action = 'update';
    
    // Convert to proper types
    data.stock = parseInt(data.stock) || 0;
    data.reorder_level = parseInt(data.reorder_level) || 5;
    data.selling_price = parseFloat(data.selling_price) || 0;
    data.cost = parseFloat(data.cost) || 0;
    data.supplier_product_price = parseFloat(data.supplier_product_price) || 0;
    
    // ✅ FIXED: Get colors with proper formatting
    const colors = getAllEditColorRows();
    if (colors.length > 0) {
        data.colors = colors.map(color => ({
            color_code: color.code,
            color_name: color.name,
            quantity: color.quantity,
            is_available: color.is_available ? 1 : 0
        }));
    } else {
        data.colors = [];
    }
    
    Swal.fire({
        title: 'Updating...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch('api/inventory.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    })
    .then(res => res.json())
    .then(resp => {
        if(resp.success){
            let message = 'Item updated successfully';
            if (resp.needs_reorder) {
                message += `<br><small class="text-warning">Suggested reorder: ${resp.suggested_pr} units</small>`;
            }
            
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                html: message,
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                const modal = bootstrap.Modal.getInstance(document.getElementById('editItemModal'));
                if (modal) modal.hide();
                loadInventoryData(currentPage);
            });
        } else {
            Swal.fire('Error!', resp.message, 'error');
        }
    })
    .catch(err => {
        console.error(err);
        Swal.fire('Error!', 'Failed to update item', 'error');
    });
}

// Delete Item
function deleteItem(id) {
    Swal.fire({
        title: 'Archive Item?',
        text: "This will archive the item (hide from inventory)",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, archive it!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch(`api/inventory.php?action=delete&id=${id}`, {
                method: 'DELETE'
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    Swal.fire(
                        'Archived!',
                        'Item has been archived.',
                        'success'
                    );
                    loadInventoryData();
                } else {
                    Swal.fire('Error!', result.message, 'error');
                }
            })
            .catch(error => {
                Swal.fire('Error!', 'Failed to archive item', 'error');
            });
        }
    });
}

// Quick Sale
function quickSaleItem(id) {
    fetch(`api/inventory.php?action=get_item&id=${id}`)
        .then(res => res.json())
        .then(response => {
            if(response.success) {
                const item = response.data;
                const profit = item.selling_price - (item.cost || 0);
                const margin = item.selling_price > 0 ? ((profit / item.selling_price) * 100).toFixed(1) : 0;
                
                Swal.fire({
                    title: 'Quick Sale',
                    html: `
                        <div class="text-start">
                            <p><strong>Item:</strong> ${escapeHtml(item.name)}</p>
                            <p><strong>Available Stock:</strong> ${item.stock}</p>
                            <p><strong>Selling Price:</strong> ₱${formatNumber(item.selling_price)}</p>
                            <p><strong>Cost:</strong> ₱${formatNumber(item.cost || 0)}</p>
                            <p class="${profit >= 0 ? 'text-success' : 'text-danger'}">
                                <strong>Profit per unit:</strong> ₱${formatNumber(profit)} (${margin}%)
                            </p>
                            <div class="mb-3">
                                <label class="form-label">Quantity to Sell</label>
                                <input type="number" class="form-control" id="saleQuantity" value="1" min="1" max="${item.stock}">
                            </div>
                            <div class="text-end" id="saleTotal">
                                <strong>Total:</strong> ₱${formatNumber(item.selling_price)}
                            </div>
                        </div>
                    `,
                    showCancelButton: true,
                    confirmButtonText: 'Sell',
                    cancelButtonText: 'Cancel',
                    didOpen: () => {
                        const qtyInput = document.getElementById('saleQuantity');
                        const totalDiv = document.getElementById('saleTotal');
                        
                        qtyInput.addEventListener('input', function() {
                            const qty = parseInt(this.value) || 0;
                            const total = qty * item.selling_price;
                            totalDiv.innerHTML = `<strong>Total:</strong> ₱${formatNumber(total)}`;
                        });
                    },
                    preConfirm: () => {
                        const quantity = document.getElementById('saleQuantity').value;
                        if (!quantity || quantity < 1 || quantity > item.stock) {
                            Swal.showValidationMessage('Please enter a valid quantity');
                            return false;
                        }
                        return { quantity: quantity, item_id: id };
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        const data = {
                            action: 'quick_sale',
                            item_id: result.value.item_id,
                            quantity: result.value.quantity
                        };
                        
                        Swal.fire({
                            title: 'Processing sale...',
                            allowOutsideClick: false,
                            didOpen: () => Swal.showLoading()
                        });
                        
                        fetch('api/inventory.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(data)
                        })
                        .then(res => res.json())
                        .then(resp => {
                            if(resp.success){
                                const totalProfit = (item.selling_price - (item.cost || 0)) * result.value.quantity;
                                
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Sale Completed!',
                                    html: `
                                        ${result.value.quantity} item(s) sold successfully<br>
                                        <small class="text-success">Profit: ₱${formatNumber(totalProfit)}</small>
                                        ${resp.needs_reorder ? '<br><small class="text-warning">Item needs reorder!</small>' : ''}
                                    `,
                                    timer: 2000,
                                    showConfirmButton: false
                                }).then(() => {
                                    loadInventoryData(currentPage);
                                });
                            } else {
                                Swal.fire('Error!', resp.message, 'error');
                            }
                        });
                    }
                });
            }
        })
        .catch(err => {
            console.error(err);
            showError('Error loading item');
        });
}

// Reports
function showLowStockReport() {
    fetch('api/inventory.php?action=low_stock_report')
        .then(response => response.json())
        .then(data => {
            if(data.success && data.data.length > 0) {
                let report = '<div class="text-start"><h6>Low Stock Items</h6>';
                report += '<div class="table-responsive"><table class="table table-sm">';
                report += '<thead><tr><th>Item</th><th>Stock/Level</th><th>Suggested</th><th>Cost</th></tr></thead><tbody>';
                
                data.data.forEach(item => {
                    report += `<tr>
                        <td>
                            <strong>${escapeHtml(item.name)}</strong><br>
                            <small class="text-muted">${item.item_id}</small>
                        </td>
                        <td>
                            <span class="badge ${getStatusBadgeClass(item.item_status)}">
                                ${item.stock} / ${item.reorder_level}
                            </span>
                        </td>
                        <td class="text-warning fw-bold">${item.suggested_pr}</td>
                        <td>₱${formatNumber(item.cost || 0)}</td>
                    </tr>`;
                });
                
                report += '</tbody></table></div></div>';
                
                Swal.fire({
                    title: 'Low Stock Report',
                    html: report,
                    width: 700,
                    showCloseButton: true,
                    showConfirmButton: false
                });
            } else {
                Swal.fire({
                    icon: 'info',
                    title: 'Good News!',
                    text: 'No items are currently low on stock.',
                    timer: 2000
                });
            }
        });
}

function showReorderSuggestions() {
    fetch('api/inventory.php?action=get_reorder_suggestions')
        .then(response => response.json())
        .then(data => {
            if(data.success && data.data.length > 0) {
                let report = '<div class="text-start"><h6>Items Needing Reorder</h6>';
                report += '<div class="table-responsive"><table class="table table-sm">';
                report += '<thead><tr><th>Item</th><th>Current</th><th>Suggested</th><th>Est. Cost</th></tr></thead><tbody>';
                
                data.data.forEach(item => {
                    report += `<tr>
                        <td>
                            <strong>${escapeHtml(item.name)}</strong><br>
                            <small class="text-muted">${item.brand || ''}</small>
                        </td>
                        <td>
                            <span class="badge bg-warning">${item.stock}</span>
                            <small> / ${item.reorder_level}</small>
                        </td>
                        <td class="fw-bold text-primary">${item.suggested_pr}</td>
                        <td>₱${formatNumber(item.estimated_cost)}</td>
                    </tr>`;
                });
                
                report += `<tr class="table-info">
                    <td colspan="3" class="text-end fw-bold">Total Estimated Cost:</td>
                    <td class="fw-bold">₱${formatNumber(data.total_estimated_cost)}</td>
                </tr>`;
                
                report += '</tbody></table></div></div>';
                
                Swal.fire({
                    title: 'Purchase Suggestions',
                    html: report,
                    width: 700,
                    showCloseButton: true,
                    confirmButtonText: 'Create Purchase Request',
                    showCancelButton: true,
                    cancelButtonText: 'Close'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = 'purchase_requests.php?auto_create=' + JSON.stringify(data.data);
                    }
                });
            } else {
                Swal.fire({
                    icon: 'success',
                    title: 'All Good!',
                    text: 'No items need reordering at this time.',
                    timer: 2000
                });
            }
        });
}

// Pagination
function renderPagination(pagination) {
    const container = document.getElementById('paginationContainer');
    const info = document.getElementById('paginationInfo');
    
    if (!pagination) {
        container.innerHTML = '';
        info.textContent = '';
        return;
    }
    
    const { total, page, limit, total_pages } = pagination;
    const start = (page - 1) * limit + 1;
    const end = Math.min(page * limit, total);
    
    info.textContent = `Showing ${start} to ${end} of ${total} items`;
    
    let html = '';
    
    // Previous button
    html += `
        <li class="page-item ${page === 1 ? 'disabled' : ''}">
            <a class="page-link" href="#" onclick="loadInventoryData(${page - 1})" aria-label="Previous">
                <span aria-hidden="true">&laquo;</span>
            </a>
        </li>
    `;
    
    // Page numbers
    const maxPagesToShow = 5;
    let startPage = Math.max(1, page - Math.floor(maxPagesToShow / 2));
    let endPage = Math.min(total_pages, startPage + maxPagesToShow - 1);
    
    if (endPage - startPage + 1 < maxPagesToShow) {
        startPage = Math.max(1, endPage - maxPagesToShow + 1);
    }
    
    for (let i = startPage; i <= endPage; i++) {
        html += `
            <li class="page-item ${i === page ? 'active' : ''}">
                <a class="page-link" href="#" onclick="loadInventoryData(${i})">${i}</a>
            </li>
        `;
    }
    
    // Next button
    html += `
        <li class="page-item ${page === total_pages ? 'disabled' : ''}">
            <a class="page-link" href="#" onclick="loadInventoryData(${page + 1})" aria-label="Next">
                <span aria-hidden="true">&raquo;</span>
            </a>
        </li>
    `;
    
    container.innerHTML = html;
}

// Search and Filter
function performSearch() {
    currentPage = 1;
    loadInventoryData();
}

function clearFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('categoryFilter').value = 'all';
    document.getElementById('statusFilter').value = 'all';
    currentPage = 1;
    loadInventoryData();
}

// Helper functions
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatNumber(num) {
    if (num === undefined || num === null || isNaN(num)) {
        return '0.00';
    }
    return Number(num).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function getCategoryBadgeClass(category) {
    const classes = {
        'Frames': 'bg-info bg-opacity-10 text-info',
        'Lenses': 'bg-primary bg-opacity-10 text-primary',
        'Contact Lenses': 'bg-success bg-opacity-10 text-success',
        'Accessories': 'bg-secondary bg-opacity-10 text-secondary'
    };
    return classes[category] || 'bg-light text-dark';
}

function getStatusBadgeClass(status) {
    const classes = {
        'in-stock': 'bg-success',
        'low-stock': 'bg-warning',
        'out-of-stock': 'bg-danger'
    };
    return classes[status] || 'bg-secondary';
}

function getStockProgressClass(stock, reorderLevel) {
    if (stock <= 0) return 'bg-danger';
    if (stock <= reorderLevel) return 'bg-warning';
    if (stock <= reorderLevel * 2) return 'bg-info';
    return 'bg-success';
}

function showError(message) {
    Swal.fire({
        icon: 'error',
        title: 'Error',
        text: message,
        timer: 3000
    });
}

function showSuccess(message) {
    Swal.fire({
        icon: 'success',
        title: 'Success',
        text: message,
        timer: 1500
    });
}