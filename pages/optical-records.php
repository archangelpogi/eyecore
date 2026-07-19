<?php
include __DIR__ . '/../config/db.php';

// Search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$whereClause = '';
$params = [];

if (!empty($search)) {
    $whereClause = "WHERE p.first_name LIKE ? OR p.last_name LIKE ? OR p.patient_id LIKE ? OR o.record_id LIKE ?";
    $searchTerm = "%$search%";
    $params = array_fill(0, 4, $searchTerm);
}

// Fetch optical records
$query = "
    SELECT o.*, 
           CONCAT(p.first_name, ' ', p.last_name) as patient_name,
           p.patient_id as patient_code
    FROM optical_records o
    LEFT JOIN patients p ON o.patient_id = p.id 
    $whereClause
    ORDER BY o.examination_date DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid">
    <!-- Header -->
    <div class="page-header d-flex justify-content-between align-items-start mb-4">
        <div>
            <h1 class="page-title">Optical Records</h1>
            <p class="page-subtitle">Eye examination and prescription records</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newExamModal">
            <i class="bi bi-plus-circle me-2"></i>New Eye Examination
        </button>
    </div>

    <!-- Search Bar -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-2">
                <div class="col-md-8">
                    <input type="text" class="form-control" name="search" 
                           placeholder="Search by patient name, ID, or record ID" 
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Search</button>
                </div>
                <div class="col-md-2">
                    <a href="?page=optical" class="btn btn-outline-secondary w-100">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card table-card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Record ID</th>
                            <th>Patient</th>
                            <th>Date</th>
                            <th>OD (SPH/CYL)</th>
                            <th>OS (SPH/CYL)</th>
                            <th>PD</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($records)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">No records found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($records as $record): ?>
                            <tr>
                                <td><?php echo $record['record_id']; ?></td>
                                <td>
                                    <div><?php echo $record['patient_name']; ?></div>
                                    <div class="text-muted small"><?php echo $record['patient_code']; ?></div>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($record['examination_date'])); ?></td>
                                <td>
                                    <div class="small">SPH: <?php echo $record['od_sph'] ?? 'N/A'; ?></div>
                                    <div class="small">CYL: <?php echo $record['od_cyl'] ?? 'N/A'; ?></div>
                                </td>
                                <td>
                                    <div class="small">SPH: <?php echo $record['os_sph'] ?? 'N/A'; ?></div>
                                    <div class="small">CYL: <?php echo $record['os_cyl'] ?? 'N/A'; ?></div>
                                </td>
                                <td><?php echo $record['pd'] ? $record['pd'] . ' mm' : 'N/A'; ?></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary" onclick="viewRecord(<?php echo $record['id']; ?>)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary" onclick="printRecord(<?php echo $record['id']; ?>)">
                                        <i class="bi bi-file-text"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-success" onclick="downloadRecord(<?php echo $record['id']; ?>)">
                                        <i class="bi bi-download"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- New Exam Modal -->
<div class="modal fade" id="newExamModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="newExamForm">
                <div class="modal-header">
                    <h5 class="modal-title">New Eye Examination</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Patient</label>
                            <select class="form-select" name="patient_id" required>
                                <option value="" disabled selected>Select Patient</option>
                                <?php
                                $stmt = $pdo->query("SELECT id, CONCAT(first_name, ' ', last_name) as full_name FROM patients WHERE status = 'Active' ORDER BY first_name ASC");
                                while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                                    echo "<option value='{$row['id']}'>{$row['full_name']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Examination Date</label>
                            <input type="date" class="form-control" name="examination_date" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>

                    <h6 class="border-bottom pb-2 mb-3">Right Eye (OD)</h6>
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">SPH</label>
                            <input type="number" step="0.01" class="form-control" name="od_sph" placeholder="0.00">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">CYL</label>
                            <input type="number" step="0.01" class="form-control" name="od_cyl" placeholder="0.00">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Axis</label>
                            <input type="number" class="form-control" name="od_axis" placeholder="0">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">ADD</label>
                            <input type="number" step="0.01" class="form-control" name="od_add" placeholder="0.00">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Visual Acuity (VA)</label>
                            <input type="text" class="form-control" name="od_va" placeholder="20/20">
                        </div>
                    </div>

                    <h6 class="border-bottom pb-2 mb-3">Left Eye (OS)</h6>
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">SPH</label>
                            <input type="number" step="0.01" class="form-control" name="os_sph" placeholder="0.00">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">CYL</label>
                            <input type="number" step="0.01" class="form-control" name="os_cyl" placeholder="0.00">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Axis</label>
                            <input type="number" class="form-control" name="os_axis" placeholder="0">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">ADD</label>
                            <input type="number" step="0.01" class="form-control" name="os_add" placeholder="0.00">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Visual Acuity (VA)</label>
                            <input type="text" class="form-control" name="os_va" placeholder="20/20">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">PD (mm)</label>
                            <input type="number" class="form-control" name="pd" placeholder="62">
                        </div>
                        <div class="col-md-9 mb-3">
                            <label class="form-label">Optometrist</label>
                            <select class="form-select" name="optometrist" required>
                                <option value="" disabled selected>Select Optometrist</option>
                                <option value="Dr. Juan Dela Cruz">Dr. Juan Dela Cruz</option>
                                <option value="Dr. Maria Santos">Dr. Maria Santos</option>
                                <option value="Dr. Paul Reyes">Dr. Paul Reyes</option>
                            </select>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Record Modal -->
<div class="modal fade" id="viewRecordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Eye Examination Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="recordDetails">
                <div class="text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// Submit new examination
document.getElementById('newExamForm').addEventListener('submit', function(e){
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    
    const data = {};
    formData.forEach((value, key) => data[key] = value);
    data.record_id = 'REC-' + Date.now().toString().slice(-6);
    
    Swal.fire({
        title: 'Saving...',
        text: 'Please wait',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch('api/optical_records.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(data)
    })
    .then(res => res.json())
    .then(resp => {
        if(resp.success){
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: 'Record saved successfully',
                timer: 1500,
                showConfirmButton: false
            }).then(() => {
                form.reset();
                bootstrap.Modal.getInstance(document.getElementById('newExamModal')).hide();
                location.reload();
            });
        } else {
            Swal.fire('Error!', resp.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error!', 'Error saving record', 'error');
    });
});

// View record details
function viewRecord(id) {
    Swal.fire({
        title: 'Loading...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch(`api/optical_records.php?id=${id}`)
        .then(res => res.json())
        .then(record => {
            Swal.close();
            const details = document.getElementById('recordDetails');
            details.innerHTML = `
                <div class="row">
                    <div class="col-md-6">
                        <h6>Patient Information</h6>
                        <p><strong>Name:</strong> ${record.patient_name}</p>
                        <p><strong>Patient ID:</strong> ${record.patient_code}</p>
                        <p><strong>Examination Date:</strong> ${record.examination_date}</p>
                        <p><strong>Optometrist:</strong> ${record.optometrist}</p>
                    </div>
                    <div class="col-md-6">
                        <h6>Prescription</h6>
                        <p><strong>PD:</strong> ${record.pd} mm</p>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-6">
                        <h6>Right Eye (OD)</h6>
                        <p><strong>SPH:</strong> ${record.od_sph || 'N/A'}</p>
                        <p><strong>CYL:</strong> ${record.od_cyl || 'N/A'}</p>
                        <p><strong>Axis:</strong> ${record.od_axis || 'N/A'}</p>
                        <p><strong>ADD:</strong> ${record.od_add || 'N/A'}</p>
                        <p><strong>VA:</strong> ${record.od_va || 'N/A'}</p>
                    </div>
                    <div class="col-md-6">
                        <h6>Left Eye (OS)</h6>
                        <p><strong>SPH:</strong> ${record.os_sph || 'N/A'}</p>
                        <p><strong>CYL:</strong> ${record.os_cyl || 'N/A'}</p>
                        <p><strong>Axis:</strong> ${record.os_axis || 'N/A'}</p>
                        <p><strong>ADD:</strong> ${record.os_add || 'N/A'}</p>
                        <p><strong>VA:</strong> ${record.os_va || 'N/A'}</p>
                    </div>
                </div>
                
                ${record.notes ? `
                <div class="row mt-3">
                    <div class="col-12">
                        <h6>Notes</h6>
                        <p>${record.notes}</p>
                    </div>
                </div>
                ` : ''}
            `;
            
            new bootstrap.Modal(document.getElementById('viewRecordModal')).show();
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error!', 'Error loading record', 'error');
        });
}

// Print record
function printRecord(id) {
    Swal.fire({
        title: 'Generating PDF...',
        text: 'Preparing document for printing',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch(`api/print_record.php?id=${id}`)
        .then(response => response.blob())
        .then(blob => {
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `optical_record_${id}.pdf`;
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);
            
            Swal.close();
            Swal.fire({
                icon: 'success',
                title: 'Ready!',
                text: 'PDF downloaded successfully',
                timer: 1500,
                showConfirmButton: false
            });
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error!', 'Failed to generate PDF', 'error');
        });
}

// Download record
function downloadRecord(id) {
    Swal.fire({
        title: 'Exporting...',
        text: 'Preparing CSV export',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch(`api/download_record.php?id=${id}`)
        .then(response => response.blob())
        .then(blob => {
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `optical_record_${id}.csv`;
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);
            
            Swal.close();
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: 'CSV downloaded successfully',
                timer: 1500,
                showConfirmButton: false
            });
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error!', 'Failed to download CSV', 'error');
        });
}
</script>