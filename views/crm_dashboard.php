<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';

// ✅ Initialize RBACHelper
RBACHelper::init($pdo);

// Load permissions to session if not already loaded
if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

// ✅ RBAC Permission Check - MUST HAVE CRM DASHBOARD VIEW PERMISSION
if (!RBACHelper::hasPermission('crm_dashboard_view')) {
    ?>
    <div class="container-fluid p-5 text-center">
        <div class="alert alert-danger">
            <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
            <h3>Access Denied</h3>
            <p>You don't have permission to access CRM Dashboard.</p>
        </div>
    </div>
    <?php
    exit;
}

$clinicId = $_SESSION['clinic_id'];
$clinicName = $_SESSION['clinic_name'] ?? 'Clinic';
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';

// ✅ Get user permissions for UI elements
$canView = RBACHelper::hasPermission('crm_dashboard_view');
$canCreate = RBACHelper::hasPermission('crm_dashboard_create');
$canEdit = RBACHelper::hasPermission('crm_dashboard_edit');
$canDelete = RBACHelper::hasPermission('crm_dashboard_delete');
$canApprove = RBACHelper::hasPermission('crm_dashboard_approve');
$canReject = RBACHelper::hasPermission('crm_dashboard_reject');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRM Dashboard - EyeCore</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --teal: #0d9488;
            --teal-dark: #0f766e;
            --teal-light: #99f6e4;
            --teal-soft: #f0fdfa;
            --orange: #f97316;
            --orange-light: #fed7aa;
        }
        
        body {
            background: #f8fafc;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }
        
        /* Modern Cards */
        .stat-card {
            background: white;
            border-radius: 24px;
            padding: 1.25rem;
            transition: all 0.3s ease;
            border: 1px solid #e2e8f0;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px -12px rgba(0, 0, 0, 0.15);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--teal), var(--teal-light));
        }
        
        .stat-card.primary::before { background: linear-gradient(90deg, #3b82f6, #93c5fd); }
        .stat-card.success::before { background: linear-gradient(90deg, #10b981, #6ee7b7); }
        .stat-card.warning::before { background: linear-gradient(90deg, #f59e0b, #fcd34d); }
        .stat-card.info::before { background: linear-gradient(90deg, #06b6d4, #67e8f9); }
        
        .stat-value {
            font-size: 32px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.2;
        }
        
        .stat-label {
            font-size: 13px;
            color: #64748b;
            font-weight: 500;
            letter-spacing: 0.3px;
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .stat-trend {
            font-size: 12px;
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .trend-up { color: #10b981; }
        .trend-down { color: #ef4444; }
        
        /* Activity Feed */
        .activity-item {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            transition: all 0.2s;
        }
        
        .activity-item:hover {
            background: #f8fafc;
            transform: translateX(4px);
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        
        /* Appointment Cards */
        .appointment-card {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            transition: all 0.2s;
            cursor: pointer;
        }
        
        .appointment-card:hover {
            background: #f8fafc;
            padding-left: 20px;
        }
        
        .time-badge {
            background: #f1f5f9;
            border-radius: 30px;
            padding: 4px 12px;
            font-size: 11px;
            font-weight: 600;
            color: #475569;
        }
        
        /* Quick Action Buttons */
        .quick-action {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 14px;
            text-align: center;
            transition: all 0.2s;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .quick-action:hover {
            border-color: var(--teal);
            background: var(--teal-soft);
            transform: translateY(-2px);
        }
        
        .quick-action-icon {
            width: 32px;
            height: 32px;
            background: var(--teal-soft);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            color: var(--teal);
        }
        
        .quick-action:hover .quick-action-icon {
            background: var(--teal-light);
        }
        
        /* Welcome Section */
        .welcome-section {
            background: linear-gradient(135deg, var(--teal) 0%, var(--teal-dark) 100%);
            border-radius: 24px;
            padding: 1.5rem 2rem;
            color: white;
            margin-bottom: 1.5rem;
        }
        
        /* Card Headers */
        .card-header-custom {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 1rem 1.25rem;
        }
        
        .card-header-custom h6 {
            font-weight: 600;
            margin: 0;
            color: #0f172a;
        }
        
        /* Badge Styles */
        .badge-teal {
            background: var(--teal-soft);
            color: var(--teal);
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 500;
        }
        
        /* Patient Type Cards */
        .type-card {
            border-radius: 20px;
            padding: 16px;
            text-align: center;
            transition: all 0.2s;
            cursor: pointer;
        }
        
        .type-card:hover {
            transform: translateY(-2px);
        }
        
        .type-card.regular { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; }
        .type-card.senior { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; }
        .type-card.pwd { background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%); color: white; }
        
        .type-number {
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 4px;
        }
        
        .type-label {
            font-size: 12px;
            opacity: 0.9;
        }
        
        /* Chart Container */
        .chart-container {
            position: relative;
            height: 250px;
            width: 100%;
        }
        
        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #94a3b8;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }
        
        /* Loading Spinner */
        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 3px solid #e2e8f0;
            border-top-color: var(--teal);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Permission-based visibility */
        .no-permission-badge {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #ef4444;
            color: white;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 12px;
            z-index: 9999;
        }
    </style>
</head>
<body>

<div class="container-fluid p-4">
    
    <!-- Welcome Section -->
    <div class="welcome-section">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h4 class="mb-1 fw-bold">Welcome back, <?= htmlspecialchars($_SESSION['first_name'] ?? 'User'); ?>!</h4>
                <p class="mb-0 opacity-75">CRM Dashboard · <?= htmlspecialchars($clinicName); ?></p>
            </div>
            <div class="text-end">
                <div class="badge-teal bg-opacity-20">
                    <i class="bi bi-calendar3 me-1"></i> <?= date('F j, Y'); ?>
                </div>
                <div class="small mt-2 opacity-75">
                    <i class="bi bi-clock me-1"></i> Last login: <?= $_SESSION['last_login'] ?? date('h:i A'); ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ==================== STATS CARDS ==================== -->
    <div class="row g-3 mb-4" id="statsContainer">
        <div class="col-md-3">
            <div class="stat-card primary">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label">Total Patients</div>
                        <div class="stat-value" id="totalPatients">0</div>
                        <div class="stat-trend trend-up" id="patientTrend">
                            <i class="bi bi-arrow-up-short"></i> <span id="newPatients">0</span> new this month
                        </div>
                    </div>
                    <div class="stat-icon bg-primary bg-opacity-10" style="color: #3b82f6;">
                        <i class="bi bi-people-fill"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card success">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label">Today's Appointments</div>
                        <div class="stat-value" id="todayAppointments">0</div>
                        <div class="stat-trend">
                            <i class="bi bi-calendar-check"></i> <span id="completedAppointments">0</span> completed
                        </div>
                    </div>
                    <div class="stat-icon bg-success bg-opacity-10" style="color: #10b981;">
                        <i class="bi bi-calendar-check-fill"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card warning">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label">Pending Follow-ups</div>
                        <div class="stat-value" id="pendingFollowups">0</div>
                        <div class="stat-trend">
                            <i class="bi bi-bell"></i> Due this week
                        </div>
                    </div>
                    <div class="stat-icon bg-warning bg-opacity-10" style="color: #f59e0b;">
                        <i class="bi bi-bell-fill"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card info">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-label">Unread Messages</div>
                        <div class="stat-value" id="unreadMessages">0</div>
                        <div class="stat-trend">
                            <i class="bi bi-chat-dots"></i> New inquiries
                        </div>
                    </div>
                    <div class="stat-icon bg-info bg-opacity-10" style="color: #06b6d4;">
                        <i class="bi bi-chat-dots-fill"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== PATIENT TYPES + QUICK ACTIONS ==================== -->
    <div class="row g-4 mb-4">
        <!-- Patient Types Distribution -->
        <div class="col-md-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header-custom">
                    <h6 class="mb-0"><i class="bi bi-pie-chart me-2" style="color: var(--teal);"></i>Patient Demographics</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-4">
                            <div class="type-card regular" onclick="filterPatients('regular')">
                                <div class="type-number" id="regularCount">0</div>
                                <div class="type-label">Regular</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="type-card senior" onclick="filterPatients('senior')">
                                <div class="type-number" id="seniorCount">0</div>
                                <div class="type-label">Senior Citizen</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="type-card pwd" onclick="filterPatients('pwd')">
                                <div class="type-number" id="pwdCount">0</div>
                                <div class="type-label">PWD</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Stats -->
                    <div class="mt-4 pt-3 border-top">
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="p-2 bg-light rounded">
                                    <div class="small text-muted">Avg Rating</div>
                                    <div class="h5 mb-0 text-warning" id="avgRating">0.0</div>
                                    <div class="small" id="ratingStars">☆☆☆☆☆</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-2 bg-light rounded">
                                    <div class="small text-muted">Return Rate</div>
                                    <div class="h5 mb-0 text-success" id="returnRate">0%</div>
                                    <div class="small">returning patients</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="col-md-7">
            <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, var(--teal) 0%, var(--teal-dark) 100%);">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3 mb-4">
                        <div class="bg-opacity-20 p-3 rounded-circle">
                            <i class="bi bi-lightning-charge fs-1 text-white"></i>
                        </div>
                        <div>
                            <h5 class="text-white mb-1 fw-bold">Quick Actions</h5>
                            <p class="text-white-50 mb-0 small">Common tasks to help you manage patients</p>
                        </div>
                    </div>
                    <div class="row g-2">
                        <?php if ($canCreate): ?>
                        <div class="col-6">
                            <a href="?view=patients&action=add" class="quick-action">
                                <div class="quick-action-icon"><i class="bi bi-person-plus"></i></div>
                                <span class="small fw-medium">Add Patient</span>
                            </a>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($canCreate): ?>
                        <div class="col-6">
                            <a href="?view=appointments&action=add" class="quick-action">
                                <div class="quick-action-icon"><i class="bi bi-calendar-plus"></i></div>
                                <span class="small fw-medium">New Appointment</span>
                            </a>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($canCreate): ?>
                        <div class="col-6">
                            <a href="?view=crm_messages" class="quick-action">
                                <div class="quick-action-icon"><i class="bi bi-envelope-plus"></i></div>
                                <span class="small fw-medium">Send Message</span>
                            </a>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($canEdit): ?>
                        <div class="col-6">
                            <a href="?view=crm_tasks" class="quick-action">
                                <div class="quick-action-icon"><i class="bi bi-bell"></i></div>
                                <span class="small fw-medium">Manage Follow-ups</span>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== CHART ==================== -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header-custom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-graph-up me-2" style="color: var(--teal);"></i>Patient Engagement Trends</h6>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-secondary active" onclick="loadChart('weekly')">Weekly</button>
                        <button class="btn btn-outline-secondary" onclick="loadChart('monthly')">Monthly</button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="engagementChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== RECENT ACTIVITIES + TODAY'S APPOINTMENTS ==================== -->
    <div class="row g-4">
        <!-- Recent Activities -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header-custom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-activity me-2" style="color: var(--teal);"></i>Recent Activities</h6>
                    <?php if ($canView): ?>
                    <a href="?view=logs" class="btn btn-sm btn-link text-teal">View All</a>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0" id="activitiesList">
                    <div class="text-center py-4">
                        <div class="loading-spinner mx-auto"></div>
                        <p class="text-muted mt-2 mb-0">Loading activities...</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Today's Appointments -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header-custom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-calendar-event me-2" style="color: var(--teal);"></i>Today's Appointments</h6>
                    <?php if ($canView): ?>
                    <a href="?view=appointments" class="btn btn-sm btn-link text-teal">View All</a>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0" id="appointmentsList">
                    <div class="text-center py-4">
                        <div class="loading-spinner mx-auto"></div>
                        <p class="text-muted mt-2 mb-0">Loading appointments...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Permission Badge (for debugging - optional) -->
    <?php if (!$canCreate && !$canEdit && !$canDelete): ?>
    <div class="no-permission-badge">
        <i class="bi bi-info-circle me-1"></i> View Only Mode
    </div>
    <?php endif; ?>
    
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// ✅ Pass permissions to JavaScript
const permissions = {
    canView: <?= json_encode($canView) ?>,
    canCreate: <?= json_encode($canCreate) ?>,
    canEdit: <?= json_encode($canEdit) ?>,
    canDelete: <?= json_encode($canDelete) ?>,
    canApprove: <?= json_encode($canApprove) ?>,
    canReject: <?= json_encode($canReject) ?>
};

let engagementChart = null;

$(document).ready(function() {
    if (permissions.canView) {
        loadDashboardData();
        loadChart('weekly');
        setInterval(loadDashboardData, 30000);
    } else {
        $('#statsContainer').html('<div class="col-12"><div class="alert alert-danger text-center">You don\'t have permission to view dashboard data.</div></div>');
    }
});

function loadDashboardData() {
    $.ajax({
        url: 'api/crm_dashboard.php',
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            // Update stats
            $('#totalPatients').text(data.stats.total_patients || 0);
            $('#todayAppointments').text(data.stats.today_appointments || 0);
            $('#pendingFollowups').text(data.stats.pending_followups || 0);
            $('#unreadMessages').text(data.stats.unread_messages || 0);
            $('#newPatients').text(data.stats.new_patients || 0);
            $('#completedAppointments').text(data.stats.completed_appointments || 0);
            
            // Update patient types
            $('#regularCount').text(data.stats.regular_patients || 0);
            $('#seniorCount').text(data.stats.senior_patients || 0);
            $('#pwdCount').text(data.stats.pwd_patients || 0);
            
            // Update ratings
            $('#avgRating').text(data.stats.avg_rating || '0.0');
            const rating = parseFloat(data.stats.avg_rating || 0);
            const fullStars = Math.floor(rating);
            const halfStar = rating % 1 >= 0.5;
            let stars = '';
            for(let i = 0; i < fullStars; i++) stars += '★';
            if(halfStar) stars += '½';
            for(let i = stars.length; i < 5; i++) stars += '☆';
            $('#ratingStars').text(stars);
            $('#returnRate').text(data.stats.return_rate || '0');
            
            // Update activities list
            let activitiesHtml = '';
            if (data.activities && data.activities.length > 0) {
                data.activities.forEach(act => {
                    let icon = act.activity_type === 'Appointment' ? 'bi-calendar-check' : 
                              (act.activity_type === 'Follow-up' ? 'bi-bell' : 'bi-chat-dots');
                    let color = act.activity_type === 'Appointment' ? '#10b981' : 
                               (act.activity_type === 'Follow-up' ? '#f59e0b' : '#3b82f6');
                    activitiesHtml += `
                        <div class="activity-item d-flex gap-3">
                            <div class="activity-icon" style="background: ${color}10; color: ${color};">
                                <i class="bi ${icon}"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between">
                                    <span class="fw-medium small">${act.activity_type}</span>
                                    <span class="text-muted small">${act.activity_time}</span>
                                </div>
                                <div class="small text-muted mt-1">${escapeHtml(act.description || 'No description')}</div>
                                <div class="small text-teal mt-1">
                                    <i class="bi bi-person-circle me-1"></i>${escapeHtml(act.patient_name)}
                                </div>
                            </div>
                        </div>
                    `;
                });
            } else {
                activitiesHtml = `
                    <div class="empty-state">
                        <i class="bi bi-inbox"></i>
                        <p class="mb-0">No recent activities</p>
                    </div>
                `;
            }
            $('#activitiesList').html(activitiesHtml);
            
            // Update appointments list
            let appointmentsHtml = '';
            if (data.appointments && data.appointments.length > 0) {
                data.appointments.forEach(app => {
                    let statusClass = app.status === 'confirmed' || app.status === 'paid' ? 'success' : 
                                    app.status === 'pending' ? 'warning' : 'secondary';
                    let statusText = app.status === 'confirmed' ? 'Confirmed' : 
                                   (app.status === 'paid' ? 'Paid' : 
                                   (app.status === 'pending' ? 'Pending' : app.status));
                    appointmentsHtml += `
                        <div class="appointment-card" onclick="viewAppointment(${app.id})">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="flex-grow-1">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="fw-medium">${escapeHtml(app.patient_name)}</span>
                                        <span class="time-badge"><i class="bi bi-clock me-1"></i>${app.appointment_time}</span>
                                    </div>
                                    <div class="small text-muted">
                                        <i class="bi bi-tag me-1"></i>${escapeHtml(app.service_type || 'Check-up')}
                                        ${app.doctor_name ? ` • <i class="bi bi-person-badge me-1"></i>Dr. ${escapeHtml(app.doctor_name)}` : ''}
                                    </div>
                                </div>
                                <div>
                                    <span class="badge bg-${statusClass} px-3 py-2">${statusText}</span>
                                </div>
                            </div>
                        </div>
                    `;
                });
            } else {
                appointmentsHtml = `
                    <div class="empty-state">
                        <i class="bi bi-calendar-x"></i>
                        <p class="mb-0">No appointments scheduled for today</p>
                    </div>
                `;
            }
            $('#appointmentsList').html(appointmentsHtml);
        },
        error: function() {
            $('#activitiesList').html('<div class="empty-state"><i class="bi bi-exclamation-triangle"></i><p>Failed to load data</p></div>');
            $('#appointmentsList').html('<div class="empty-state"><i class="bi bi-exclamation-triangle"></i><p>Failed to load appointments</p></div>');
        }
    });
}

function loadChart(period) {
    $.ajax({
        url: 'api/crm_dashboard.php',
        method: 'GET',
        data: { action: 'chart_data', period: period },
        dataType: 'json',
        success: function(data) {
            const ctx = document.getElementById('engagementChart').getContext('2d');
            
            if (engagementChart) {
                engagementChart.destroy();
            }
            
            engagementChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.labels || [],
                    datasets: [
                        {
                            label: 'Appointments',
                            data: data.appointments || [],
                            borderColor: '#0d9488',
                            backgroundColor: 'rgba(13, 148, 136, 0.05)',
                            fill: true,
                            tension: 0.3,
                            pointBackgroundColor: '#0d9488',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            pointRadius: 4
                        },
                        {
                            label: 'New Patients',
                            data: data.patients || [],
                            borderColor: '#f59e0b',
                            backgroundColor: 'rgba(245, 158, 11, 0.05)',
                            fill: true,
                            tension: 0.3,
                            pointBackgroundColor: '#f59e0b',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            pointRadius: 4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: { usePointStyle: true, boxWidth: 8 }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + context.raw;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { drawBorder: false },
                            ticks: { stepSize: 1 }
                        },
                        x: {
                            grid: { display: false }
                        }
                    }
                }
            });
        }
    });
}

function filterPatients(type) {
    if (permissions.canView) {
        window.location.href = `?view=patients&type=${type}`;
    } else {
        Swal.fire('Access Denied', 'You don\'t have permission to view patients', 'error');
    }
}

function viewAppointment(id) {
    if (permissions.canView) {
        window.location.href = `?view=appointments&action=view&id=${id}`;
    } else {
        Swal.fire('Access Denied', 'You don\'t have permission to view appointments', 'error');
    }
}

function escapeHtml(text) {
    if(!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
</body>
</html>