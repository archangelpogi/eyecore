<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['ClinicAdmin', 'CRM', 'Staff'])) {
    header('Location: ../auth/login.php');
    exit;
}

$clinicId = $_SESSION['clinic_id'];
$userId = $_SESSION['user_id'];
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'followups';
?>

<div class="container-fluid p-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h5 class="fw-bold mb-0" style="color: #0d9488;">
                <i class="bi bi-person-lines-fill me-2"></i>Customer Relationship Management
            </h5>
            <p class="text-muted small mb-0">Manage patients, follow-ups, feedback, and tasks</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-teal" onclick="exportCRMData()" style="border-color: #0d9488; color: #0d9488;">
                <i class="bi bi-download me-1"></i>Export
            </button>
            <button class="btn btn-sm btn-teal" onclick="showAddModal()" style="background: #0d9488; border-color: #0d9488;">
                <i class="bi bi-plus-lg me-1"></i>Add New
            </button>
        </div>
    </div>

    <!-- Tabs Navigation -->
    <ul class="nav nav-tabs mb-4" id="crmTabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link <?= $activeTab === 'followups' ? 'active' : '' ?>" data-tab="followups" onclick="switchTab('followups')">
                <i class="bi bi-bell me-1"></i> Follow-ups
                <span class="badge bg-danger ms-1" id="followupBadge">0</span>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link <?= $activeTab === 'feedback' ? 'active' : '' ?>" data-tab="feedback" onclick="switchTab('feedback')">
                <i class="bi bi-star me-1"></i> Feedback
                <span class="badge bg-warning ms-1" id="feedbackBadge">0</span>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link <?= $activeTab === 'tags' ? 'active' : '' ?>" data-tab="tags" onclick="switchTab('tags')">
                <i class="bi bi-tags me-1"></i> Patient Tags
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link <?= $activeTab === 'summary' ? 'active' : '' ?>" data-tab="summary" onclick="switchTab('summary')">
                <i class="bi bi-info-circle me-1"></i> Patient Summary
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link <?= $activeTab === 'tasks' ? 'active' : '' ?>" data-tab="tasks" onclick="switchTab('tasks')">
                <i class="bi bi-list-task me-1"></i> My Tasks
                <span class="badge bg-warning ms-1" id="taskBadge">0</span>
            </button>
        </li>
    </ul>

    <!-- Tab Content Container -->
    <div id="tabContent">
        <div class="text-center py-5">
            <div class="spinner-border text-teal" role="status"></div>
            <p class="mt-2 text-muted">Loading...</p>
        </div>
    </div>
</div>

<style>
    .btn-teal { background: #0d9488; border-color: #0d9488; color: white; }
    .btn-teal:hover { background: #0f766e; border-color: #0f766e; color: white; }
    .btn-outline-teal { border-color: #0d9488; color: #0d9488; }
    .btn-outline-teal:hover { background: #0d9488; border-color: #0d9488; color: white; }
    .task-card { border-left: 3px solid; margin-bottom: 10px; transition: all 0.2s; }
    .task-card:hover { transform: translateX(3px); }
    .task-high { border-left-color: #dc2626; }
    .task-medium { border-left-color: #f59e0b; }
    .task-low { border-left-color: #10b981; }
    .task-done { opacity: 0.7; text-decoration: line-through; }
    .feedback-rating { color: #fbbf24; }
    .tag-badge { background: #e2e8f0; color: #1e293b; padding: 4px 10px; border-radius: 20px; font-size: 12px; margin: 2px; display: inline-block; }
    .summary-card { background: #f8fafc; border-radius: 12px; padding: 16px; border: 1px solid #e2e8f0; }
</style>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
let currentTab = '<?= $activeTab ?>';

document.addEventListener('DOMContentLoaded', function() {
    loadTab(currentTab);
});

function switchTab(tab) {
    currentTab = tab;
    
    // Update URL without reload
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    window.history.pushState({}, '', url);
    
    // Update active tab style
    document.querySelectorAll('#crmTabs .nav-link').forEach(link => {
        link.classList.remove('active');
        if(link.getAttribute('data-tab') === tab) {
            link.classList.add('active');
        }
    });
    
    loadTab(tab);
}

function loadTab(tab) {
    const container = document.getElementById('tabContent');
    
    switch(tab) {
        case 'followups':
            loadFollowups(container);
            break;
        case 'feedback':
            loadFeedback(container);
            break;
        case 'tags':
            loadTags(container);
            break;
        case 'summary':
            loadSummary(container);
            break;
        case 'tasks':
            loadTasks(container);
            break;
    }
}

// ==================== FOLLOWUPS MODULE ====================
function loadFollowups(container) {
    container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-teal"></div><p class="mt-2">Loading follow-ups...</p></div>';
    
    fetch('api/crm.php?action=get_followups')
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                renderFollowups(container, data);
                document.getElementById('followupBadge').innerText = data.stats?.pending || 0;
            } else {
                container.innerHTML = '<div class="alert alert-danger">Failed to load follow-ups</div>';
            }
        })
        .catch(err => {
            container.innerHTML = '<div class="alert alert-danger">Error loading data</div>';
        });
}

function renderFollowups(container, data) {
    const followups = data.followups || [];
    const stats = data.stats || {};
    
    let html = `
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="summary-card text-center">
                    <div class="h3 mb-0 text-teal">${stats.today || 0}</div>
                    <div class="text-muted small">Today</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-card text-center">
                    <div class="h3 mb-0 text-warning">${stats.pending || 0}</div>
                    <div class="text-muted small">Pending</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-card text-center">
                    <div class="h3 mb-0 text-success">${stats.completed || 0}</div>
                    <div class="text-muted small">Completed</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-card text-center">
                    <div class="h3 mb-0 text-info">${stats.week || 0}</div>
                    <div class="text-muted small">This Week</div>
                </div>
            </div>
        </div>
        
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="bi bi-bell me-2 text-teal"></i>Follow-up List</h6>
                <button class="btn btn-sm btn-teal" onclick="showAddFollowupModal()">
                    <i class="bi bi-plus-lg me-1"></i>Add Follow-up
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                                <th>Patient</th>
                                <th>Type</th>
                                <th>Date & Time</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </thead>
                        <tbody>
    `;
    
    if(followups.length === 0) {
        html += '<tr><td colspan="6" class="text-center text-muted py-4">No follow-ups found</td></tr>';
    } else {
        followups.forEach(f => {
            html += `
                <tr>
                    <td class="fw-medium">${escapeHtml(f.patient_name || 'N/A')}</td>
                    <td><span class="badge bg-secondary">${escapeHtml(f.followup_type)}</span></td>
                    <td>${formatDate(f.followup_date)} at ${f.followup_time?.substring(0,5) || '--:--'}</td>
                    <td>${escapeHtml(f.description?.substring(0,50) || '')}${f.description?.length > 50 ? '...' : ''}</td>
                    <td>
                        ${f.status === 'Pending' ? '<span class="badge bg-warning">Pending</span>' : '<span class="badge bg-success">Completed</span>'}
                    </td>
                    <td>
                        ${f.status === 'Pending' ? `<button class="btn btn-sm btn-outline-success me-1" onclick="completeFollowup(${f.id})"><i class="bi bi-check-lg"></i></button>` : ''}
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteFollowup(${f.id})"><i class="bi bi-trash"></i></button>
                    </td>
                </tr>
            `;
        });
    }
    
    html += `</tbody></table></div></div></div>`;
    container.innerHTML = html;
}

// ==================== FEEDBACK MODULE ====================
function loadFeedback(container) {
    container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-teal"></div><p class="mt-2">Loading feedback...</p></div>';
    
    fetch('api/crm.php?action=get_feedbacks')
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                renderFeedback(container, data);
                document.getElementById('feedbackBadge').innerText = data.stats?.pending_reply || 0;
            } else {
                container.innerHTML = '<div class="alert alert-danger">Failed to load feedback</div>';
            }
        });
}

function renderFeedback(container, data) {
    const feedbacks = data.feedbacks || [];
    const stats = data.stats || {};
    
    let html = `
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="summary-card text-center">
                    <div class="h3 mb-0 text-warning">${stats.avg_rating || 0}<span class="fs-6">/5</span></div>
                    <div class="text-muted small">Average Rating</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-card text-center">
                    <div class="h3 mb-0">${stats.total || 0}</div>
                    <div class="text-muted small">Total Feedback</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-card text-center">
                    <div class="h3 mb-0 text-primary">${stats.this_month || 0}</div>
                    <div class="text-muted small">This Month</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-card text-center">
                    <div class="h3 mb-0 text-danger">${stats.pending_reply || 0}</div>
                    <div class="text-muted small">Pending Reply</div>
                </div>
            </div>
        </div>
        
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h6 class="mb-0 fw-bold"><i class="bi bi-star me-2 text-warning"></i>Patient Feedback</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            
                                <th>Patient</th>
                                <th>Rating</th>
                                <th>Feedback</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </thead>
                        <tbody>
    `;
    
    if(feedbacks.length === 0) {
        html += '<tr><td colspan="6" class="text-center text-muted py-4">No feedback found</td></tr>';
    } else {
        feedbacks.forEach(f => {
            const stars = '★'.repeat(f.rating) + '☆'.repeat(5-f.rating);
            html += `
                <tr>
                    <td class="fw-medium">${escapeHtml(f.patient_name || 'N/A')}</td>
                    <td class="feedback-rating">${stars}</td>
                    <td>${escapeHtml(f.feedback?.substring(0,50) || '')}${f.feedback?.length > 50 ? '...' : ''}</td>
                    <td>${f.feedback_date}</td>
                    <td>
                        ${f.status === 'pending' ? '<span class="badge bg-warning">Pending</span>' : '<span class="badge bg-success">Replied</span>'}
                    </td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" onclick="viewFeedback(${f.id})"><i class="bi bi-eye"></i></button>
                        ${f.status === 'pending' ? `<button class="btn btn-sm btn-outline-success ms-1" onclick="replyFeedback(${f.id})"><i class="bi bi-reply"></i></button>` : ''}
                    </td>
                </tr>
            `;
        });
    }
    
    html += `</tbody></table></div></div></div>`;
    container.innerHTML = html;
}

// ==================== TAGS MODULE ====================
function loadTags(container) {
    container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-teal"></div><p class="mt-2">Loading tags...</p></div>';
    
    Promise.all([
        fetch('api/crm.php?action=get_custom_tags').then(res => res.json()),
        fetch('api/crm.php?action=get_patients').then(res => res.json())
    ]).then(([tagsData, patientsData]) => {
        renderTags(container, tagsData, patientsData);
    }).catch(err => {
        container.innerHTML = '<div class="alert alert-danger">Error loading tags</div>';
    });
}

function renderTags(container, tags, patients) {
    let html = `
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-tags me-2 text-teal"></i>Custom Tags</h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <div class="input-group">
                                <input type="text" class="form-control" id="newTagName" placeholder="New tag name">
                                <select class="form-select w-auto" id="newTagColor" style="width: 100px;">
                                    <option value="#0d9488">Teal</option>
                                    <option value="#3b82f6">Blue</option>
                                    <option value="#10b981">Green</option>
                                    <option value="#f59e0b">Orange</option>
                                    <option value="#ef4444">Red</option>
                                </select>
                                <button class="btn btn-teal" onclick="addCustomTag()">Add</button>
                            </div>
                        </div>
                        <div id="tagsList">
    `;
    
    if(tags.length === 0) {
        html += '<p class="text-muted">No custom tags yet</p>';
    } else {
        tags.forEach(tag => {
            html += `
                <div class="d-inline-block me-2 mb-2">
                    <span class="tag-badge" style="background: ${tag.color}20; color: ${tag.color};">${escapeHtml(tag.name)}</span>
                    <button class="btn btn-sm btn-link text-danger p-0 ms-1" onclick="deleteCustomTag(${tag.id})"><i class="bi bi-x"></i></button>
                </div>
            `;
        });
    }
    
    html += `</div></div></div></div>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-people me-2 text-teal"></i>Patients by Tag</h6>
                    </div>
                    <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                        <div class="list-group list-group-flush">
    `;
    
    if(patients.length === 0) {
        html += '<div class="list-group-item text-muted">No patients found</div>';
    } else {
        patients.forEach(p => {
            html += `
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong>${escapeHtml(p.name || p.first_name + ' ' + p.last_name)}</strong>
                            <div class="text-muted small">${p.patient_code || ''}</div>
                        </div>
                        <button class="btn btn-sm btn-outline-teal" onclick="editPatientTags(${p.id}, '${escapeHtml(p.name || p.first_name + ' ' + p.last_name)}')">
                            <i class="bi bi-pencil"></i> Edit Tags
                        </button>
                    </div>
                </div>
            `;
        });
    }
    
    html += `</div></div></div></div></div>`;
    container.innerHTML = html;
}

// ==================== SUMMARY MODULE ====================
function loadSummary(container) {
    container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-teal"></div><p class="mt-2">Loading summary...</p></div>';
    
    fetch('api/crm.php?action=get_summary')
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                renderSummary(container, data);
            } else {
                container.innerHTML = '<div class="alert alert-danger">Failed to load summary</div>';
            }
        });
}

function renderSummary(container, data) {
    const stats = data.stats || {};
    const patients = data.patients || [];
    
    let html = `
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="summary-card text-center">
                    <div class="h3 mb-0 text-teal">${stats.total || 0}</div>
                    <div class="text-muted small">Total Patients</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-card text-center">
                    <div class="h3 mb-0 text-primary">${stats.with_appointments || 0}</div>
                    <div class="text-muted small">With Appointments</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-card text-center">
                    <div class="h3 mb-0 text-warning">${stats.with_followups || 0}</div>
                    <div class="text-muted small">With Follow-ups</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-card text-center">
                    <div class="h3 mb-0 text-info">${stats.with_feedback || 0}</div>
                    <div class="text-muted small">With Feedback</div>
                </div>
            </div>
        </div>
        
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="bi bi-people me-2 text-teal"></i>Patient List</h6>
                <input type="text" class="form-control form-control-sm w-25" id="patientSearch" placeholder="Search..." onkeyup="searchPatients()">
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="patientTable">
                        <thead class="table-light">
                            
                                <th>Code</th>
                                <th>Name</th>
                                <th>Age/Gender</th>
                                <th>Type</th>
                                <th>Last Visit</th>
                                <th>Next Appt</th>
                            </thead>
                        <tbody>
    `;
    
    if(patients.length === 0) {
        html += '<tr><td colspan="6" class="text-center text-muted py-4">No patients found</td></tr>';
    } else {
        patients.forEach(p => {
            html += `
                <tr onclick="viewPatientDetail(${p.id})" style="cursor: pointer;">
                    <td><code>${escapeHtml(p.patient_code)}</code></td>
                    <td class="fw-medium">${escapeHtml(p.first_name + ' ' + p.last_name)}</td>
                    <td>${p.age || '?'} / ${p.gender || '?'}</td>
                    <td><span class="badge bg-secondary">${p.patient_type || 'Regular'}</span></td>
                    <td>${p.last_visit || '-'}</td>
                    <td>${p.next_appointment || '-'}</td>
                </tr>
            `;
        });
    }
    
    html += `</tbody></table></div></div></div>`;
    container.innerHTML = html;
}

// ==================== TASKS MODULE ====================
function loadTasks(container) {
    container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-teal"></div><p class="mt-2">Loading tasks...</p></div>';
    
    fetch('api/crm.php?action=get_tasks')
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                renderTasks(container, data);
                document.getElementById('taskBadge').innerText = data.stats?.pending || 0;
            } else {
                container.innerHTML = '<div class="alert alert-danger">Failed to load tasks</div>';
            }
        });
}

function renderTasks(container, data) {
    const pending = data.pending || [];
    const inProgress = data.in_progress || [];
    const completed = data.completed || [];
    const stats = data.stats || {};
    
    let html = `
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="summary-card text-center">
                    <div class="h3 mb-0 text-warning">${stats.pending || 0}</div>
                    <div class="text-muted small">Pending</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-card text-center">
                    <div class="h3 mb-0 text-primary">${stats.in_progress || 0}</div>
                    <div class="text-muted small">In Progress</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-card text-center">
                    <div class="h3 mb-0 text-success">${stats.completed || 0}</div>
                    <div class="text-muted small">Completed</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-card text-center">
                    <div class="h3 mb-0 text-danger">${stats.overdue || 0}</div>
                    <div class="text-muted small">Overdue</div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-clock-history me-2 text-warning"></i>Pending</h6>
                    </div>
                    <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                        ${renderTaskList(pending, 'pending')}
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-play-circle me-2 text-primary"></i>In Progress</h6>
                    </div>
                    <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                        ${renderTaskList(inProgress, 'in_progress')}
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-check2-circle me-2 text-success"></i>Completed</h6>
                    </div>
                    <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                        ${renderTaskList(completed, 'completed')}
                    </div>
                </div>
            </div>
        </div>
        
        <div class="mt-3 text-end">
            <button class="btn btn-teal btn-sm" onclick="showAddTaskModal()">
                <i class="bi bi-plus-lg me-1"></i>Add Task
            </button>
        </div>
    `;
    
    container.innerHTML = html;
}

function renderTaskList(tasks, status) {
    if(tasks.length === 0) {
        return '<div class="text-center text-muted py-4">No tasks</div>';
    }
    
    let html = '';
    tasks.forEach(task => {
        const priorityClass = task.priority === 'high' ? 'task-high' : (task.priority === 'medium' ? 'task-medium' : 'task-low');
        const isOverdue = task.due_date < new Date().toISOString().split('T')[0] && status !== 'completed';
        
        html += `
            <div class="task-card ${priorityClass} p-3 border-bottom">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="flex-grow-1">
                        <div class="fw-bold">${escapeHtml(task.title)}</div>
                        <div class="small text-muted">${escapeHtml(task.description?.substring(0,60) || '')}</div>
                        <div class="small mt-1">
                            <i class="bi bi-calendar me-1"></i>Due: ${task.due_date}
                            ${isOverdue ? '<span class="badge bg-danger ms-2">Overdue</span>' : ''}
                        </div>
                    </div>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-link" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
                        <ul class="dropdown-menu">
    `;
        
        // IBA'T IBANG BUTTONS DEPENDE SA STATUS
        if(status === 'pending') {
            html += `
                <li><a class="dropdown-item" href="#" onclick="startTask(${task.id})"><i class="bi bi-play-circle me-2"></i>Start (In Progress)</a></li>
                <li><a class="dropdown-item" href="#" onclick="completeTask(${task.id})"><i class="bi bi-check-lg me-2"></i>Complete</a></li>
            `;
        } else if(status === 'in_progress') {
            html += `
                <li><a class="dropdown-item" href="#" onclick="completeTask(${task.id})"><i class="bi bi-check-lg me-2"></i>Complete</a></li>
                <li><a class="dropdown-item text-warning" href="#" onclick="moveToPending(${task.id})"><i class="bi bi-arrow-left me-2"></i>Move to Pending</a></li>
            `;
        } else if(status === 'completed') {
            html += `
                <li><a class="dropdown-item text-warning" href="#" onclick="moveToPending(${task.id})"><i class="bi bi-arrow-left me-2"></i>Reopen</a></li>
            `;
        }
        
        html += `
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="#" onclick="deleteTask(${task.id})"><i class="bi bi-trash me-2"></i>Delete</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        `;
    });
    
    return html;
}

// Start task - move from Pending to In Progress
function startTask(id) {
    Swal.fire({
        title: 'Start this task?',
        text: 'Task will be moved to In Progress',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Start',
        confirmButtonColor: '#0d9488'
    }).then(result => {
        if(result.isConfirmed) {
            fetch('api/crm.php?action=start_task', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id: id})
            })
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    refreshCurrentTab();
                    Swal.fire('Started!', 'Task is now In Progress', 'success');
                } else {
                    Swal.fire('Error', data.message || 'Failed to update task', 'error');
                }
            })
            .catch(err => {
                Swal.fire('Error', 'Connection error', 'error');
            });
        }
    });
}

// Move task to Pending (from In Progress or Completed)
function moveToPending(id) {
    Swal.fire({
        title: 'Move task to Pending?',
        text: 'Task will be moved back to Pending',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Move',
        confirmButtonColor: '#f59e0b'
    }).then(result => {
        if(result.isConfirmed) {
            fetch('api/crm.php?action=move_to_pending', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id: id})
            })
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    refreshCurrentTab();
                    Swal.fire('Moved!', 'Task is now Pending', 'success');
                } else {
                    Swal.fire('Error', data.message || 'Failed to update task', 'error');
                }
            })
            .catch(err => {
                Swal.fire('Error', 'Connection error', 'error');
            });
        }
    });
}
// ==================== HELPER FUNCTIONS ====================
function formatDate(dateStr) {
    if(!dateStr) return '';
    const d = new Date(dateStr);
    return d.toLocaleDateString();
}

function escapeHtml(text) {
    if(!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showAddModal() {
    if(currentTab === 'followups') showAddFollowupModal();
    else if(currentTab === 'tasks') showAddTaskModal();
    else if(currentTab === 'feedback') Swal.fire('Info', 'Add feedback from patient profile', 'info');
}

function refreshCurrentTab() {
    loadTab(currentTab);
}

// ==================== FOLLOWUP ACTIONS ====================
function showAddFollowupModal() {
    fetch('api/crm.php?action=get_patients')
        .then(res => res.json())
        .then(patients => {
            let patientOptions = '<option value="">Select Patient</option>';
            patients.forEach(p => {
                patientOptions += `<option value="${p.id}">${escapeHtml(p.name || p.first_name + ' ' + p.last_name)}</option>`;
            });
            
            Swal.fire({
                title: 'Add Follow-up',
                html: `
                    <div class="text-start">
                        <div class="mb-3">
                            <label class="form-label">Patient</label>
                            <select class="form-select" id="followupPatient">${patientOptions}</select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Type</label>
                            <select class="form-select" id="followupType">
                                <option value="Call">Call</option>
                                <option value="Visit">Visit</option>
                                <option value="Email">Email</option>
                                <option value="Reminder">Reminder</option>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-6">
                                <label class="form-label">Date</label>
                                <input type="date" class="form-control" id="followupDate" value="${new Date().toISOString().split('T')[0]}">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Time</label>
                                <input type="time" class="form-control" id="followupTime" value="09:00">
                            </div>
                        </div>
                        <div class="mb-3 mt-2">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" id="followupDesc" rows="2"></textarea>
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Add',
                preConfirm: () => {
                    return {
                        patient_id: document.getElementById('followupPatient').value,
                        followup_type: document.getElementById('followupType').value,
                        followup_date: document.getElementById('followupDate').value,
                        followup_time: document.getElementById('followupTime').value,
                        description: document.getElementById('followupDesc').value
                    };
                }
            }).then(result => {
                if(result.isConfirmed && result.value.patient_id) {
                    fetch('api/crm.php?action=add', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify(result.value)
                    }).then(() => refreshCurrentTab());
                }
            });
        });
}

function completeFollowup(id) {
    Swal.fire({
        title: 'Complete this follow-up?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes'
    }).then(result => {
        if(result.isConfirmed) {
            fetch('api/crm.php?action=complete', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id: id})
            }).then(() => refreshCurrentTab());
        }
    });
}

function deleteFollowup(id) {
    Swal.fire({
        title: 'Delete follow-up?',
        text: 'This action cannot be undone',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Delete'
    }).then(result => {
        if(result.isConfirmed) {
            fetch('api/crm.php?action=delete', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id: id})
            }).then(() => refreshCurrentTab());
        }
    });
}

// ==================== FEEDBACK ACTIONS ====================
function viewFeedback(id) {
    fetch(`api/crm.php?action=get&id=${id}`)
        .then(res => res.json())
        .then(data => {
            const f = data.feedback;
            Swal.fire({
                title: `Feedback from ${f.patient_name}`,
                html: `
                    <div class="text-start">
                        <p><strong>Rating:</strong> ${'★'.repeat(f.rating)}${'☆'.repeat(5-f.rating)}</p>
                        <p><strong>Feedback:</strong> ${escapeHtml(f.feedback)}</p>
                        <p><strong>Date:</strong> ${f.feedback_date}</p>
                        ${f.reply ? `<hr><p><strong>Reply:</strong> ${escapeHtml(f.reply)}</p><p><small>Replied: ${f.replied_date}</small></p>` : ''}
                    </div>
                `,
                width: '500px'
            });
        });
}

function replyFeedback(id) {
    Swal.fire({
        title: 'Reply to Feedback',
        input: 'textarea',
        inputLabel: 'Your reply',
        inputPlaceholder: 'Type your response here...',
        inputAttributes: { rows: 4 },
        showCancelButton: true,
        confirmButtonText: 'Send Reply',
        preConfirm: (reply) => {
            if(!reply) return Swal.showValidationMessage('Please enter a reply');
            return reply;
        }
    }).then(result => {
        if(result.isConfirmed) {
            fetch('api/crm.php?action=reply', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id: id, reply: result.value})
            }).then(() => refreshCurrentTab());
        }
    });
}

// ==================== TAGS ACTIONS ====================
function addCustomTag() {
    const name = document.getElementById('newTagName').value;
    const color = document.getElementById('newTagColor').value;
    
    if(!name) {
        Swal.fire('Error', 'Please enter a tag name', 'error');
        return;
    }
    
    fetch('api/crm.php?action=add_custom_tag', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({name: name, color: color})
    }).then(() => refreshCurrentTab());
}

function deleteCustomTag(id) {
    Swal.fire({
        title: 'Delete tag?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Delete'
    }).then(result => {
        if(result.isConfirmed) {
            fetch('api/crm.php?action=delete_custom_tag', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id: id})
            }).then(() => refreshCurrentTab());
        }
    });
}

function editPatientTags(patientId, patientName) {
    fetch('api/crm.php?action=get_patient_tags&patient_id=' + patientId)
        .then(res => res.json())
        .then(currentTags => {
            fetch('api/crm.php?action=get_custom_tags')
                .then(res => res.json())
                .then(allTags => {
                    let tagsHtml = '';
                    allTags.forEach(tag => {
                        const checked = currentTags.includes(tag.name) ? 'checked' : '';
                        tagsHtml += `
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="${tag.name}" id="tag_${tag.id}" ${checked}>
                                <label class="form-check-label" for="tag_${tag.id}">${tag.name}</label>
                            </div>
                        `;
                    });
                    
                    Swal.fire({
                        title: `Edit Tags: ${patientName}`,
                        html: `
                            <div class="text-start">
                                ${tagsHtml}
                                <hr>
                                <div class="mb-2">
                                    <label class="form-label">Patient Type</label>
                                    <select class="form-select" id="patientTypeSelect">
                                        <option value="Regular">Regular</option>
                                        <option value="Senior">Senior</option>
                                        <option value="PWD">PWD</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label">Remarks</label>
                                    <textarea class="form-control" id="patientRemarks" rows="2"></textarea>
                                </div>
                            </div>
                        `,
                        showCancelButton: true,
                        confirmButtonText: 'Save',
                        preConfirm: () => {
                            const selectedTags = [];
                            document.querySelectorAll('#swal2-html-container input[type="checkbox"]:checked').forEach(cb => {
                                selectedTags.push(cb.value);
                            });
                            return {
                                patient_id: patientId,
                                custom_tags: selectedTags.join(','),
                                patient_type: document.getElementById('patientTypeSelect').value,
                                remarks: document.getElementById('patientRemarks').value
                            };
                        }
                    }).then(result => {
                        if(result.isConfirmed) {
                            fetch('api/crm.php?action=update_patient_tags', {
                                method: 'POST',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify(result.value)
                            }).then(() => refreshCurrentTab());
                        }
                    });
                });
        });
}

// ==================== TASKS ACTIONS ====================
function showAddTaskModal() {
    Swal.fire({
        title: 'Add Task',
        html: `
            <div class="text-start">
                <div class="mb-3">
                    <label class="form-label">Title</label>
                    <input type="text" class="form-control" id="taskTitle" placeholder="Task title">
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" id="taskDesc" rows="2"></textarea>
                </div>
                <div class="row">
                    <div class="col-6">
                        <label class="form-label">Priority</label>
                        <select class="form-select" id="taskPriority">
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Due Date</label>
                        <input type="date" class="form-control" id="taskDueDate" value="${new Date().toISOString().split('T')[0]}">
                    </div>
                </div>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Add Task',
        preConfirm: () => {
            return {
                title: document.getElementById('taskTitle').value,
                description: document.getElementById('taskDesc').value,
                priority: document.getElementById('taskPriority').value,
                due_date: document.getElementById('taskDueDate').value
            };
        }
    }).then(result => {
        if(result.isConfirmed && result.value.title) {
            fetch('api/crm.php?action=add_task', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(result.value)
            }).then(() => refreshCurrentTab());
        }
    });
}

function completeTask(id) {
    Swal.fire({
        title: 'Complete this task?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes'
    }).then(result => {
        if(result.isConfirmed) {
            fetch('api/crm.php?action=complete_task', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id: id})
            }).then(() => refreshCurrentTab());
        }
    });
}

function deleteTask(id) {
    Swal.fire({
        title: 'Delete task?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Delete'
    }).then(result => {
        if(result.isConfirmed) {
            fetch('api/crm.php?action=delete_task', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id: id})
            }).then(() => refreshCurrentTab());
        }
    });
}

function searchPatients() {
    const search = document.getElementById('patientSearch')?.value.toLowerCase();
    if(!search) {
        document.querySelectorAll('#patientTable tbody tr').forEach(row => row.style.display = '');
        return;
    }
    document.querySelectorAll('#patientTable tbody tr').forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(search) ? '' : 'none';
    });
}

function viewPatientDetail(patientId) {
    window.location.href = `?view=patients&action=view&id=${patientId}`;
}

function exportCRMData() {
    const startDate = prompt('Enter start date (YYYY-MM-DD):', new Date().toISOString().split('T')[0]);
    const endDate = prompt('Enter end date (YYYY-MM-DD):', new Date().toISOString().split('T')[0]);
    
    if(startDate && endDate) {
        window.location.href = `api/crm.php?action=export&type=${currentTab}&start=${startDate}&end=${endDate}`;
    }
}

// Force re-initialize Bootstrap dropdowns after loading tasks
function initDropdowns() {
    // Re-initialize all dropdowns
    var dropdownElements = document.querySelectorAll('[data-bs-toggle="dropdown"]');
    dropdownElements.forEach(function(element) {
        new bootstrap.Dropdown(element);
    });
}

// I-update ang refreshCurrentTab function
const originalRefresh = refreshCurrentTab;
refreshCurrentTab = function() {
    originalRefresh();
    setTimeout(initDropdowns, 100);
};

// I-update din ang loadTab function para sa tasks
const originalLoadTab = loadTab;
loadTab = function(tab) {
    originalLoadTab(tab);
    if(tab === 'tasks') {
        setTimeout(initDropdowns, 200);
    }
};


</script>