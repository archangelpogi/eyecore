<?php
include __DIR__ . '/../config/db.php';

// Check if user is SuperAdmin
$currentUserId = $_SESSION['user_id'] ?? null;
$userRole = $_SESSION['role'] ?? '';

if ($userRole !== 'SuperAdmin') {
    header('Location: ../access-denied.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clinic Oversight Dashboard - Eyecore</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
        }
        
        .trend-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .chart-container {
            height: 250px;
            position: relative;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .gradient-bg {
            background: linear-gradient(135deg, #0d9488, #3b82f6);
        }
        
        .loading-spinner {
            width: 1.5rem;
            height: 1.5rem;
            border: 2px solid #dee2e6;
            border-right-color: transparent;
            border-radius: 50%;
            animation: spin 0.75s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .card-hover {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .card-hover:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1) !important;
        }
        
        .city-badge {
            font-size: 0.75rem;
            padding: 2px 8px;
            border-radius: 12px;
        }
        
        .clinic-status-badge {
            font-size: 0.7rem;
            padding: 3px 10px;
            border-radius: 12px;
        }
    </style>
</head>

<body class="bg-light">
<div class="container-fluid py-4 px-3 px-md-4">

    <!-- HEADER -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
        <div class="mb-2 mb-md-0">
            <h1 class="h2 fw-bold text-dark mb-1">
                <i class="bi bi-building-gear me-2"></i>Clinic Oversight Dashboard
            </h1>
            <p class="text-muted mb-0">Super Admin - Complete Clinic Management System Overview</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <select id="cityFilter" class="form-select form-select-sm" style="width: 200px;">
                <option value="all">All Cities in Cavite</option>
            </select>
            <select id="statusFilter" class="form-select form-select-sm" style="width: 180px;">
                <option value="all">All Status</option>
                <option value="Active">Active Only</option>
                <option value="Pending">Pending Only</option>
                <option value="Suspended">Suspended Only</option>
            </select>
        </div>
    </div>

    <!-- CLINIC OVERVIEW CARDS -->
    <div class="row g-3 g-md-4 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm card-hover h-100">
                <div class="card-body p-3 p-md-4">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                            <i class="bi bi-building"></i>
                        </div>
                        <span id="clinicGrowth" class="trend-badge bg-success bg-opacity-10 text-success">
                            <i class="bi bi-arrow-up me-1"></i>
                            <span id="growthPercent">0%</span>
                        </span>
                    </div>
                    <div class="text-muted small text-uppercase fw-medium mb-1">Total Clinics</div>
                    <div id="totalClinics" class="stat-value">0</div>
                    <small class="text-muted d-flex align-items-center mt-1">
                        <i class="bi bi-geo-alt-fill text-danger me-1"></i>
                        <span id="clinicCities">Across Cavite</span>
                    </small>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm card-hover h-100">
                <div class="card-body p-3 p-md-4">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="stat-icon bg-success bg-opacity-10 text-success">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <span class="trend-badge bg-success bg-opacity-10 text-success">
                            <i class="bi bi-speedometer2 me-1"></i>
                            Operational
                        </span>
                    </div>
                    <div class="text-muted small text-uppercase fw-medium mb-1">Active Clinics</div>
                    <div id="activeClinics" class="stat-value">0</div>
                    <small class="text-muted d-flex align-items-center mt-1">
                        <i class="bi bi-clock-history text-success me-1"></i>
                        <span id="activeCities">0 cities</span>
                    </small>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm card-hover h-100">
                <div class="card-body p-3 p-md-4">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                            <i class="bi bi-hourglass-split"></i>
                        </div>
                        <span class="trend-badge bg-warning bg-opacity-10 text-warning">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            Needs Action
                        </span>
                    </div>
                    <div class="text-muted small text-uppercase fw-medium mb-1">Pending Approval</div>
                    <div id="pendingClinics" class="stat-value">0</div>
                    <small class="text-muted d-flex align-items-center mt-1">
                        <i class="bi bi-clock text-warning me-1"></i>
                        <span id="pendingInfo">Awaiting verification</span>
                    </small>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm card-hover h-100">
                <div class="card-body p-3 p-md-4">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="stat-icon bg-info bg-opacity-10 text-info">
                            <i class="bi bi-people"></i>
                        </div>
                        <span class="trend-badge bg-info bg-opacity-10 text-info">
                            <i class="bi bi-person-badge me-1"></i>
                            Staff Count
                        </span>
                    </div>
                    <div class="text-muted small text-uppercase fw-medium mb-1">Total Staff</div>
                    <div id="totalStaff" class="stat-value">0</div>
                    <small class="text-muted d-flex align-items-center mt-1">
                        <i class="bi bi-person-check text-info me-1"></i>
                        <span id="staffDistribution">Across all clinics</span>
                    </small>
                </div>
            </div>
        </div>
    </div>

    <!-- CLINIC DISTRIBUTION & STATUS CHARTS -->
    <div class="row g-3 g-md-4 mb-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="card-title fw-semibold mb-0">Clinic Distribution by City</h6>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                Cavite
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="#" data-region="cavite">Cavite Only</a></li>
                                <li><a class="dropdown-item" href="#" data-region="all">All Regions</a></li>
                            </ul>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="cityDistributionChart"></canvas>
                    </div>
                    <div class="mt-3 small text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        Shows number of clinics per city/municipality
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="card-title fw-semibold mb-0">Clinic Status Overview</h6>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                Status Breakdown
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="#" data-view="status">By Status</a></li>
                                <li><a class="dropdown-item" href="#" data-view="type">By Clinic Type</a></li>
                            </ul>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="clinicStatusChart"></canvas>
                    </div>
                    <div class="mt-3 small text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        Current operational status of all clinics
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- CLINIC DETAILS & ACTIVITY -->
    <div class="row g-3 g-md-4 mb-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="card-title fw-semibold mb-0">Clinic Directory</h6>
                        <div class="d-flex gap-2">
                            <input type="text" id="clinicSearch" class="form-control form-control-sm" placeholder="Search clinic..." style="width: 200px;">
                            <a href="clinics.php" class="btn btn-sm btn-outline-primary">
                                View All <i class="bi bi-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Clinic Code</th>
                                    <th>Clinic Name</th>
                                    <th>City</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Staff</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="clinicDirectory">
                                <!-- Clinic data will be loaded here -->
                                <tr>
                                    <td colspan="7" class="text-center py-4">
                                        <div class="loading-spinner mx-auto"></div>
                                        <p class="text-muted mt-2 mb-0">Loading clinic data...</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="card-title fw-semibold mb-0">Top Cities</h6>
                        <span class="badge bg-primary">Most Clinics</span>
                    </div>
                    <div id="topCitiesList" class="list-group list-group-flush">
                        <!-- Top cities will be loaded here -->
                        <div class="text-center py-4">
                            <div class="loading-spinner mx-auto"></div>
                            <p class="text-muted mt-2 mb-0">Loading city data...</p>
                        </div>
                    </div>
                    <div class="mt-3 pt-3 border-top">
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted">New This Month</small>
                            <strong id="newClinicsMonth" class="text-success">0</strong>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-2">
                            <small class="text-muted">Need Attention</small>
                            <strong id="attentionNeeded" class="text-warning">0</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- DOCUMENT STATUS & VERIFICATION -->
    <div class="row g-3 g-md-4 mb-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="card-title fw-semibold mb-0">Document Verification Status</h6>
                        <a href="clinic_documents.php" class="btn btn-sm btn-outline-warning">
                            Review <i class="bi bi-clipboard-check ms-1"></i>
                        </a>
                    </div>
                    <div class="row g-3 text-center">
                        <div class="col-4">
                            <div class="p-3 rounded-3 bg-warning bg-opacity-10">
                                <div class="h4 fw-bold mb-1 text-warning" id="pendingDocs">0</div>
                                <small class="text-muted">Pending</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-3 rounded-3 bg-success bg-opacity-10">
                                <div class="h4 fw-bold mb-1 text-success" id="approvedDocs">0</div>
                                <small class="text-muted">Approved</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-3 rounded-3 bg-danger bg-opacity-10">
                                <div class="h4 fw-bold mb-1 text-danger" id="rejectedDocs">0</div>
                                <small class="text-muted">Rejected</small>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <small>Expiring This Month</small>
                            <strong id="expiringDocs" class="text-danger">0</strong>
                        </div>
                        <div class="progress" style="height: 6px;">
                            <div id="expiryProgress" class="progress-bar bg-danger" role="progressbar" style="width: 0%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="card-title fw-semibold mb-0">System Health & Performance</h6>
                        <span class="badge bg-success">Online</span>
                    </div>
                    <div class="list-group list-group-flush">
                        <div class="list-group-item px-0 border-0 d-flex justify-content-between align-items-center py-2">
                            <span class="text-muted">Average Staff per Clinic</span>
                            <strong id="avgStaff" class="text-primary">0</strong>
                        </div>
                        <div class="list-group-item px-0 border-0 d-flex justify-content-between align-items-center py-2">
                            <span class="text-muted">Clinic Verification Rate</span>
                            <strong id="verificationRate" class="text-success">0%</strong>
                        </div>
                        <div class="list-group-item px-0 border-0 d-flex justify-content-between align-items-center py-2">
                            <span class="text-muted">High Risk Clinics</span>
                            <strong id="highRiskClinics" class="text-danger">0</strong>
                        </div>
                        <div class="list-group-item px-0 border-0 d-flex justify-content-between align-items-center py-2">
                            <span class="text-muted">Standalone vs Hospital-Based</span>
                            <div>
                                <span class="badge bg-info" id="standaloneCount">0</span>
                                <span class="badge bg-secondary" id="hospitalBasedCount">0</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- QUICK ACTIONS FOR SUPER ADMIN -->
    <div class="card border-0 gradient-bg text-white overflow-hidden">
        <div class="card-body p-4">
            <h5 class="card-title fw-semibold mb-4">
                <i class="bi bi-shield-check me-2"></i>Super Admin Controls
            </h5>
            <div class="row g-3">
                <div class="col-6 col-md-3">
                    <a href="clinics.php?action=verify" class="text-decoration-none">
                        <div class="p-3 rounded-3 bg-white bg-opacity-15 hover-effect h-100">
                            <div class="d-flex align-items-center mb-2">
                                <i class="bi bi-clipboard-check fs-4 me-2"></i>
                                <span class="fw-medium">Verify Clinics</span>
                            </div>
                            <small class="opacity-75">Review pending applications</small>
                        </div>
                    </a>
                </div>
                <div class="col-6 col-md-3">
                    <a href="reports.php?type=clinic" class="text-decoration-none">
                        <div class="p-3 rounded-3 bg-white bg-opacity-15 hover-effect h-100">
                            <div class="d-flex align-items-center mb-2">
                                <i class="bi bi-graph-up fs-4 me-2"></i>
                                <span class="fw-medium">Clinic Reports</span>
                            </div>
                            <small class="opacity-75">Generate analytics</small>
                        </div>
                    </a>
                </div>
                <div class="col-6 col-md-3">
                    <a href="audit_logs.php" class="text-decoration-none">
                        <div class="p-3 rounded-3 bg-white bg-opacity-15 hover-effect h-100">
                            <div class="d-flex align-items-center mb-2">
                                <i class="bi bi-journal-check fs-4 me-2"></i>
                                <span class="fw-medium">Audit Logs</span>
                            </div>
                            <small class="opacity-75">System activity review</small>
                        </div>
                    </a>
                </div>
                <div class="col-6 col-md-3">
                    <a href="settings.php?section=clinic" class="text-decoration-none">
                        <div class="p-3 rounded-3 bg-white bg-opacity-15 hover-effect h-100">
                            <div class="d-flex align-items-center mb-2">
                                <i class="bi bi-gear fs-4 me-2"></i>
                                <span class="fw-medium">Clinic Settings</span>
                            </div>
                            <small class="opacity-75">Configure system rules</small>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    loadClinicDashboardData();
    initializeCharts();
    
    // Event listeners for filters
    document.getElementById('cityFilter').addEventListener('change', filterClinics);
    document.getElementById('statusFilter').addEventListener('change', filterClinics);
    document.getElementById('clinicSearch').addEventListener('input', searchClinics);
});

function loadClinicDashboardData() {
    fetch('api/get_clinic_dashboard.php')
        .then(response => response.json())
        .then(data => {
            updateKPICards(data);
            updateClinicDirectory(data.clinics);
            updateTopCities(data.topCities);
            updateDocumentStats(data.documents);
            updateSystemStats(data.system);
            updateCharts(data.charts);
        })
        .catch(error => {
            console.error('Error loading dashboard data:', error);
        });
}

function updateKPICards(data) {
    // Total Clinics
    document.getElementById('totalClinics').textContent = data.totalClinics || 0;
    document.getElementById('clinicCities').textContent = `Across ${data.uniqueCities || 0} cities`;
    document.getElementById('growthPercent').textContent = data.growthPercent ? `${data.growthPercent}%` : '0%';
    
    // Active Clinics
    document.getElementById('activeClinics').textContent = data.activeClinics || 0;
    document.getElementById('activeCities').textContent = `${data.activeCities || 0} cities active`;
    
    // Pending Clinics
    document.getElementById('pendingClinics').textContent = data.pendingClinics || 0;
    document.getElementById('pendingInfo').textContent = data.pendingInfo || 'Awaiting verification';
    
    // Total Staff
    document.getElementById('totalStaff').textContent = data.totalStaff || 0;
    document.getElementById('staffDistribution').textContent = `${data.avgStaff || 0} avg per clinic`;
}

function updateClinicDirectory(clinics) {
    const tbody = document.getElementById('clinicDirectory');
    if (!clinics || clinics.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="7" class="text-center py-4">
                    <i class="bi bi-building-slash fs-1 text-muted mb-3 d-block"></i>
                    <p class="text-muted">No clinics found</p>
                </td>
            </tr>
        `;
        return;
    }
    
    let html = '';
    clinics.forEach(clinic => {
        const statusBadge = getStatusBadge(clinic.status);
        const typeBadge = clinic.clinic_type === 'standalone' 
            ? '<span class="badge bg-info">Standalone</span>'
            : '<span class="badge bg-secondary">Hospital-Based</span>';
        
        html += `
            <tr>
                <td><strong>${clinic.clinic_code}</strong></td>
                <td>${clinic.clinic_name}</td>
                <td>
                    <span class="badge bg-light text-dark city-badge">
                        <i class="bi bi-geo-alt"></i> ${clinic.city || 'N/A'}
                    </span>
                </td>
                <td>${typeBadge}</td>
                <td>${statusBadge}</td>
                <td><span class="badge bg-primary">${clinic.staff_count || 0}</span></td>
                <td>
                    <a href="clinics.php?id=${clinic.id}" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-eye"></i>
                    </a>
                </td>
            </tr>
        `;
    });
    
    tbody.innerHTML = html;
}

function getStatusBadge(status) {
    const badges = {
        'Active': 'bg-success',
        'Pending': 'bg-warning',
        'Suspended': 'bg-danger',
        'Rejected': 'bg-secondary',
        'Reapplying': 'bg-info'
    };
    
    const color = badges[status] || 'bg-secondary';
    return `<span class="badge ${color} clinic-status-badge">${status}</span>`;
}

function updateTopCities(cities) {
    const container = document.getElementById('topCitiesList');
    if (!cities || cities.length === 0) {
        container.innerHTML = '<div class="text-center py-3 text-muted">No city data available</div>';
        return;
    }
    
    let html = '';
    cities.forEach((city, index) => {
        html += `
            <div class="list-group-item d-flex justify-content-between align-items-center py-2">
                <div>
                    <span class="badge bg-light text-dark me-2">${index + 1}</span>
                    ${city.name}
                </div>
                <span class="badge bg-primary">${city.clinic_count} clinics</span>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

function updateDocumentStats(docs) {
    document.getElementById('pendingDocs').textContent = docs.pending || 0;
    document.getElementById('approvedDocs').textContent = docs.approved || 0;
    document.getElementById('rejectedDocs').textContent = docs.rejected || 0;
    document.getElementById('expiringDocs').textContent = docs.expiring || 0;
    document.getElementById('expiryProgress').style.width = `${docs.expiryPercent || 0}%`;
}

function updateSystemStats(stats) {
    document.getElementById('avgStaff').textContent = stats.avgStaff || 0;
    document.getElementById('verificationRate').textContent = `${stats.verificationRate || 0}%`;
    document.getElementById('highRiskClinics').textContent = stats.highRisk || 0;
    document.getElementById('standaloneCount').textContent = stats.standalone || 0;
    document.getElementById('hospitalBasedCount').textContent = stats.hospitalBased || 0;
    document.getElementById('newClinicsMonth').textContent = stats.newThisMonth || 0;
    document.getElementById('attentionNeeded').textContent = stats.attentionNeeded || 0;
}

function initializeCharts() {
    window.cityChart = new Chart(document.getElementById('cityDistributionChart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: [],
            datasets: [{
                label: 'Clinics per City',
                data: [],
                backgroundColor: 'rgba(59, 130, 246, 0.5)',
                borderColor: 'rgba(59, 130, 246, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    });
    
    window.statusChart = new Chart(document.getElementById('clinicStatusChart').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: [],
            datasets: [{
                data: [],
                backgroundColor: [
                    'rgba(34, 197, 94, 0.5)',
                    'rgba(234, 179, 8, 0.5)',
                    'rgba(239, 68, 68, 0.5)',
                    'rgba(156, 163, 175, 0.5)',
                    'rgba(6, 182, 212, 0.5)'
                ],
                borderColor: [
                    'rgba(34, 197, 94, 1)',
                    'rgba(234, 179, 8, 1)',
                    'rgba(239, 68, 68, 1)',
                    'rgba(156, 163, 175, 1)',
                    'rgba(6, 182, 212, 1)'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right'
                }
            }
        }
    });
}

function updateCharts(chartData) {
    if (window.cityChart && chartData.cityDistribution) {
        window.cityChart.data.labels = chartData.cityDistribution.labels;
        window.cityChart.data.datasets[0].data = chartData.cityDistribution.data;
        window.cityChart.update();
    }
    
    if (window.statusChart && chartData.statusDistribution) {
        window.statusChart.data.labels = chartData.statusDistribution.labels;
        window.statusChart.data.datasets[0].data = chartData.statusDistribution.data;
        window.statusChart.update();
    }
}

function filterClinics() {
    const city = document.getElementById('cityFilter').value;
    const status = document.getElementById('statusFilter').value;
    
    // Implement filtering logic here
    console.log('Filtering by:', { city, status });
}

function searchClinics(event) {
    const searchTerm = event.target.value.toLowerCase();
    // Implement search logic here
    console.log('Searching for:', searchTerm);
}
</script>
</body>
</html>