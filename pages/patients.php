<?php
include __DIR__ . '/../config/db.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Patient Oversight</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Bootstrap -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://unpkg.com/lucide@latest"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="assets/css/patients.css">
<style>
body { background:#f8fafc; }
.card-soft { background:#fff; border:1px solid #e5e7eb; border-radius:12px; }
.icon-box { width:48px; height:48px; border-radius:10px; display:flex; align-items:center; justify-content:center; }
.gradient-btn { background:linear-gradient(to right,#0d9488,#3b82f6); color:#fff; border:none; }
.gradient-btn:hover { box-shadow:0 10px 20px rgba(0,0,0,.15); }
.table-hover tbody tr:hover { background:#f9fafb; }
.badge-active { background:#dcfce7; color:#166534; }
.badge-inactive { background:#f3f4f6; color:#374151; }
</style>
</head>
<body>
<div class="container-fluid p-4">

<!-- HEADER -->
<div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
    <div>
        <h2 class="fw-bold">Patient Oversight</h2>
        <p class="text-muted">Multi-clinic patient management and records</p>
    </div>
    <button class="btn gradient-btn px-4 py-2 d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#patientModal">
        <i data-lucide="plus"></i> Add New Patient
    </button>
</div>

<!-- STATS -->
<div class="row g-3 mb-4" id="statsContainer">
    <div class="col-md-3"><div class="card-soft p-4 d-flex justify-content-between"><div><small class="text-muted">Total Patients</small><h4 id="totalPatients" class="fw-bold mt-1">0</h4></div><div class="icon-box bg-teal-100"><i data-lucide="user" class="text-success"></i></div></div></div>
    <div class="col-md-3"><div class="card-soft p-4 d-flex justify-content-between"><div><small class="text-muted">Active Patients</small><h4 id="activePatients" class="fw-bold mt-1">0</h4></div><div class="icon-box bg-green-100"><i data-lucide="user" class="text-success"></i></div></div></div>
    <div class="col-md-3"><div class="card-soft p-4 d-flex justify-content-between"><div><small class="text-muted">New This Month</small><h4 id="newThisMonth" class="fw-bold mt-1">0</h4></div><div class="icon-box bg-blue-100"><i data-lucide="calendar" class="text-primary"></i></div></div></div>
    <div class="col-md-3"><div class="card-soft p-4 d-flex justify-content-between"><div><small class="text-muted">Total Visits</small><h4 id="totalVisits" class="fw-bold mt-1">0</h4></div><div class="icon-box bg-purple-100"><i data-lucide="building-2" class="text-purple"></i></div></div></div>
</div>

<!-- FILTER -->
<div class="card-soft p-3 mb-4">
    <div class="row g-3">
        <div class="col-md-4 position-relative">
            <i data-lucide="search" class="position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
            <input type="text" id="searchInput" class="form-control ps-5" placeholder="Search by patient name, ID, or email...">
        </div>
        <div class="col-md-4">
            <select id="clinicFilter" class="form-select">
                <option value="">All Clinics</option>
                <?php
                $stmt = $pdo->query("SELECT id, clinic_name FROM clinics ORDER BY clinic_name ASC");
                $clinics = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach($clinics as $c) {
                    echo "<option value='{$c['id']}'>{$c['clinic_name']}</option>";
                }
                ?>
            </select>
        </div>
        <div class="col-md-4">
            <select id="statusFilter" class="form-select">
                <option value="">All Status</option>
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
                <option value="Archived">Archived</option>
            </select>
        </div>
    </div>
</div>

<!-- TABLE -->
<div class="card-soft overflow-hidden">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="bg-light">
                <tr>
                    <th>Patient ID</th><th>Name</th><th class="d-none d-md-table-cell">Age/Gender</th>
                    <th class="d-none d-lg-table-cell">Contact</th><th>Clinic</th><th class="d-none d-lg-table-cell">Last Visit</th>
                    <th>Status</th><th>Actions</th>
                </tr>
            </thead>
            <tbody id="patientsTableBody">
                <tr><td colspan="8" class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-muted">Loading patients...</p></td></tr>
            </tbody>
        </table>
    </div>
</div>
<!-- MODAL - Add/Edit Patient -->
<div class="modal fade" id="patientModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-4">
            <div class="modal-header">
                <h5 class="modal-title fw-semibold" id="modalTitle">Add New Patient</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="patientForm" class="row g-3">
                    <input type="hidden" id="patientId">
                    <div class="col-md-6">
                        <label class="form-label">First Name *</label>
                        <input type="text" id="firstName" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Last Name *</label>
                        <input type="text" id="lastName" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Age</label>
                        <input type="number" id="age" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Gender</label>
                        <select id="gender" class="form-select">
                            <option value="">Select</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Clinic *</label>
                        <select id="clinicId" class="form-select" required>
                            <?php
                            $stmt = $pdo->query("SELECT id, clinic_name FROM clinics ORDER BY clinic_name ASC");
                            $clinics = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            foreach($clinics as $c) {
                                echo "<option value='{$c['id']}'>{$c['clinic_name']}</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-6">
    <label class="form-label">Phone</label>
    <input type="text" id="phone" class="form-control">
</div>
                    <div class="col-md-6">
                        <label class="form-label">Contact</label>
                        <input type="text" id="contact" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" id="email" class="form-control">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Address</label>
                        <textarea id="address" class="form-control"></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Status</label>
                        <select id="status" class="form-select">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                            <option value="Archived">Archived</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="savePatientBtn" class="btn gradient-btn">Save Patient</button>
            </div>
        </div>
    </div>
</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>lucide.createIcons();</script>
<script src="assets/js/patients.js"></script>
</body>
</html>
