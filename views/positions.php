<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Position & Salary Rate Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    
    <style>
        .card-soft {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
        }
        .salary-cell {
            font-weight: bold;
            color: #059669;
        }
    </style>
</head>
<body>

<div class="container-fluid p-3 p-md-4">
    
    <!-- HEADER -->
    <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
        <div>
            <h2 class="fw-bold">Position & Salary Rate Management</h2>
            <p class="text-muted mb-0">Manage job positions and salary rates</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary px-3 d-flex align-items-center gap-2"
                    onclick="openAddPositionModal()">
                <i class="bi bi-plus-circle"></i> Add Position
            </button>
        </div>
    </div>
    
    <!-- STATS -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card-soft p-3 h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <small class="text-muted">Total Positions</small>
                        <h3 id="totalPositions" class="fw-bold mt-1 mb-2">0</h3>
                    </div>
                    <div class="rounded p-2 bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-briefcase fs-5"></i>
                    </div>
                </div>
                <small class="text-muted d-block">Active job positions</small>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card-soft p-3 h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <small class="text-muted">Average Salary</small>
                        <h3 id="averageSalary" class="fw-bold mt-1 mb-2">₱0</h3>
                    </div>
                    <div class="rounded p-2 bg-success bg-opacity-10 text-success">
                        <i class="bi bi-cash-coin fs-5"></i>
                    </div>
                </div>
                <small class="text-muted d-block">Average monthly salary</small>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card-soft p-3 h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <small class="text-muted">Total Employees</small>
                        <h3 id="totalEmployees" class="fw-bold mt-1 mb-2">0</h3>
                    </div>
                    <div class="rounded p-2 bg-info bg-opacity-10 text-info">
                        <i class="bi bi-people fs-5"></i>
                    </div>
                </div>
                <small class="text-muted d-block">Employees with positions</small>
            </div>
        </div>
    </div>
    
    <!-- DATA TABLE -->
    <div class="card-soft overflow-hidden mb-4">
        <div class="table-responsive">
            <table id="positionsTable" class="table table-hover mb-0" style="width:100%">
                <thead>
                    <tr>
                        <th>Position Name</th>
                        <th>Department</th>
                        <th>Salary Rate</th>
                        <th>Employees</th>
                        <th>Created</th>
                        <th width="120">Actions</th>
                    </tr>
                </thead>
                <tbody id="positionsTableBody">
                    <!-- Positions will be loaded here -->
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- MODAL - ADD/EDIT POSITION -->
<div class="modal fade" id="positionModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="positionModalTitle">Add New Position</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="positionForm">
                <div class="modal-body">
                    <input type="hidden" id="position_id">
                    <div class="mb-3">
                        <label class="form-label">Position Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="position_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Department</label>
                        <select class="form-select" id="department">
                            <option value="">Select Department</option>
                            <option value="Optometry">Optometry</option>
                            <option value="Human Resources">Human Resources</option>
                            <option value="Finance">Finance</option>
                            <option value="Customer Service">Customer Service</option>
                            <option value="Inventory">Inventory</option>
                            <option value="Administration">Administration</option>
                            <option value="Marketing">Marketing</option>
                            <option value="IT">IT</option>
                            <option value="Sales">Sales</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Salary Rate (₱)</label>
                        <input type="number" class="form-control" id="salary_rate" step="0.01" min="0">
                        <div class="form-text">Default salary for this position (optional)</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Position</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- DataTables JS -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="assets/js/positions.js"></script>

</body>
</html>