<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection
$host = 'localhost';
$dbname = 'u334978718_eyecore_db';
$username = 'u334978718_eyecore_user';
$password = 'Eyecore@2026';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Check if user is logged in
if (!isset($_SESSION['user_id'], $_SESSION['employee_id'])) {
    header('Location: login.php');
    exit;
}

$employee_id = $_SESSION['employee_id'];
$message = '';
$message_type = '';

// Get employee details WITHOUT schedules table
$empQuery = $pdo->prepare("
    SELECT 
        e.id as employee_id,
        e.employee_no,
        e.position_id,
        e.status as emp_status,
        u.id as user_id,
        u.first_name,
        u.last_name,
        u.clinic_id,
        c.clinic_name,
        p.position_name
    FROM employees e
    INNER JOIN users u ON e.user_id = u.id
    LEFT JOIN clinics c ON u.clinic_id = c.id
    LEFT JOIN positions p ON e.position_id = p.id
    WHERE e.id = ?
");
$empQuery->execute([$employee_id]);
$employee = $empQuery->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    die("ERROR: Employee not found");
}

// Default schedule values (since wala pang schedules table)
$employee['scheduled_time_in'] = '09:00:00';
$employee['scheduled_time_out'] = '18:00:00';
$employee['scheduled_hours'] = 8;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $overtime_date = $_POST['overtime_date'];
    $time_start = $_POST['time_start'];
    $time_end = $_POST['time_end'];
    $overtime_type = $_POST['overtime_type'];
    $reason = $_POST['reason'];
    
    // Calculate total hours
    $start = new DateTime($time_start);
    $end = new DateTime($time_end);
    $interval = $start->diff($end);
    $total_hours = $interval->h + ($interval->i / 60);
    
    // Check if date is in the past
    $today = date('Y-m-d');
    if ($overtime_date < $today) {
        $message = "Cannot file overtime for past dates.";
        $message_type = "danger";
    } else {
        // Check for existing pending/approved request
        $check = $pdo->prepare("SELECT id FROM overtime_requests WHERE employee_id = ? AND overtime_date = ? AND status IN ('pending', 'approved')");
        $check->execute([$employee_id, $overtime_date]);
        
        if ($check->fetch()) {
            $message = "You already have a pending or approved request for this date.";
            $message_type = "warning";
        } else {
            // Insert overtime request
            $sql = "INSERT INTO overtime_requests 
                    (employee_id, overtime_date, time_start, time_end, total_hours, overtime_type, reason, status, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
            
            $stmt = $pdo->prepare($sql);
            if ($stmt->execute([$employee_id, $overtime_date, $time_start, $time_end, $total_hours, $overtime_type, $reason])) {
                $message = "Overtime request submitted successfully!";
                $message_type = "success";
                
                // Refresh the page to show new request
                echo "<script>setTimeout(function() { window.location.reload(); }, 2000);</script>";
            } else {
                $message = "Error submitting request.";
                $message_type = "danger";
            }
        }
    }
}

// Fetch employee's overtime requests
$stmt = $pdo->prepare("SELECT * FROM overtime_requests WHERE employee_id = ? ORDER BY created_at DESC");
$stmt->execute([$employee_id]);
$overtime_requests = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Overtime Request - <?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }
        .overtime-card {
            background: white;
            border-radius: 12px;
            border: 1px solid #e0e0e0;
            transition: all 0.2s;
        }
        .overtime-card:hover {
            box-shadow: 0 4px 12px rgba(0,128,128,0.1);
        }
        .header-bar {
            background: white;
            border-bottom: 2px solid #008080;
            padding: 16px 0;
            margin-bottom: 24px;
        }
        .summary-badge {
            background: #e6f3f3;
            color: #008080;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.85rem;
            border: 1px solid #008080;
        }
        .btn-teal {
            background: #008080;
            color: white;
            border: none;
        }
        .btn-teal:hover {
            background: #006666;
            color: white;
        }
        .btn-outline-teal {
            background: transparent;
            color: #008080;
            border: 1px solid #008080;
        }
        .btn-outline-teal:hover {
            background: #008080;
            color: white;
        }
        .teal-border {
            border-color: #008080 !important;
        }
        .teal-text {
            color: #008080 !important;
        }
        .teal-bg-light {
            background: #e6f3f3 !important;
        }
        .badge-teal {
            background: #008080;
            color: white;
        }
        .form-control:focus, .form-select:focus {
            border-color: #008080;
            box-shadow: 0 0 0 0.2rem rgba(0,128,128,0.25);
        }
        .form-control[readonly] {
            background-color: #f8f9fa;
            cursor: not-allowed;
        }
        .employee-info {
            background: #f8f9fa;
            border-left: 4px solid #008080;
            padding: 15px;
            border-radius: 8px;
        }
        .badge-pending { background-color: #fff3cd; color: #856404; }
        .badge-approved { background-color: #d4edda; color: #155724; }
        .badge-rejected { background-color: #f8d7da; color: #721c24; }
        .badge-cancelled { background-color: #e2e3e5; color: #383d41; }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        .stat-card {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
        }
        .stat-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: #008080;
        }
        .stat-label {
            font-size: 0.85rem;
            color: #6c757d;
        }
        .schedule-note {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 10px;
            border-radius: 6px;
            margin-top: 10px;
        }
    </style>
</head>
<body>


<div class="container">
    <!-- Employee Summary Card -->
    <div class="overtime-card p-3 mb-4">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h6 class="fw-semibold mb-2 teal-text">
                    <i class="bi bi-info-circle me-2"></i>
                    Employee Information
                </h6>
                <div class="employee-info">
                    <div class="row">
                        <div class="col-6">
                            <small class="text-secondary d-block">Full Name</small>
                            <span class="fw-medium"><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></span>
                        </div>
                        <div class="col-6">
                            <small class="text-secondary d-block">Employee No.</small>
                            <span class="fw-medium"><?php echo htmlspecialchars($employee['employee_no']); ?></span>
                        </div>
                        <div class="col-6 mt-2">
                            <small class="text-secondary d-block">Position</small>
                            <span class="fw-medium"><?php echo htmlspecialchars($employee['position_name'] ?? 'Not Set'); ?></span>
                        </div>
                        <div class="col-6 mt-2">
                            <small class="text-secondary d-block">Department/Clinic</small>
                            <span class="fw-medium"><?php echo htmlspecialchars($employee['clinic_name'] ?? 'Not Set'); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <h6 class="fw-semibold mb-2 teal-text">
                    <i class="bi bi-clock me-2"></i>
                    Schedule Information
                </h6>
                <div class="employee-info">
                    <div class="row">
                        <div class="col-6">
                            <small class="text-secondary d-block">Standard Time In</small>
                            <span class="fw-medium"><?php echo date('h:i A', strtotime($employee['scheduled_time_in'])); ?></span>
                        </div>
                        <div class="col-6">
                            <small class="text-secondary d-block">Standard Time Out</small>
                            <span class="fw-medium"><?php echo date('h:i A', strtotime($employee['scheduled_time_out'])); ?></span>
                        </div>
                        <div class="col-12 mt-2">
                            <small class="text-secondary d-block">Standard Hours</small>
                            <span class="fw-medium teal-text"><?php echo $employee['scheduled_hours']; ?> hours per day</span>
                        </div>
                    </div>
                </div>
                <!-- Note about schedule -->
                <div class="schedule-note small">
                    <i class="bi bi-info-circle me-1"></i>
                    These are default schedule values. Please coordinate with HR for your actual schedule.
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Overtime Request Form -->
        <div class="col-md-5 mb-4">
            <div class="overtime-card p-4">
                <h6 class="fw-semibold mb-3 teal-text">
                    <i class="bi bi-pencil-square me-2"></i>
                    New Overtime Request
                </h6>
                
                <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                    <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <form method="POST" id="overtimeForm">
                    <!-- Read-only Employee Fields -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-person-badge text-teal me-1"></i> Employee Name
                        </label>
                        <input type="text" class="form-control" 
                               value="<?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?>" 
                               readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-briefcase text-teal me-1"></i> Position
                        </label>
                        <input type="text" class="form-control" 
                               value="<?php echo htmlspecialchars($employee['position_name'] ?? 'Not Set'); ?>" 
                               readonly>
                    </div>
                    
                    <!-- Schedule Info -->
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-sun text-teal me-1"></i> Standard Time In
                            </label>
                            <input type="text" class="form-control" 
                                   value="<?php echo date('h:i A', strtotime($employee['scheduled_time_in'])); ?>" 
                                   readonly>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-moon text-teal me-1"></i> Standard Time Out
                            </label>
                            <input type="text" class="form-control" 
                                   value="<?php echo date('h:i A', strtotime($employee['scheduled_time_out'])); ?>" 
                                   readonly>
                        </div>
                    </div>
                    
                    <hr class="my-3" style="border-color: #00808020;">
                    
                    <!-- Overtime Fields -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-calendar3 text-teal me-1"></i> Overtime Date
                        </label>
                        <input type="date" name="overtime_date" class="form-control" 
                               min="<?php echo date('Y-m-d'); ?>" required
                               onchange="updateTotalHours()">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-clock text-teal me-1"></i> Time Start
                            </label>
                            <input type="time" name="time_start" class="form-control" required
                                   onchange="updateTotalHours()">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-clock-fill text-teal me-1"></i> Time End
                            </label>
                            <input type="time" name="time_end" class="form-control" required
                                   onchange="updateTotalHours()">
                        </div>
                    </div>
                    
                    <!-- Total Hours (auto-calculated) -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-hourglass-split text-teal me-1"></i> Total Hours
                        </label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="total_hours_display" 
                                   value="0.00" readonly>
                            <span class="input-group-text bg-light">hours</span>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-tag text-teal me-1"></i> Overtime Type
                        </label>
                        <select name="overtime_type" class="form-select" required>
                            <option value="">Select type...</option>
                            <option value="weekday">Weekday Overtime</option>
                            <option value="weekend">Weekend Overtime</option>
                            <option value="holiday">Holiday Overtime</option>
                            <option value="emergency">Emergency Overtime</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-chat-text text-teal me-1"></i> Reason
                        </label>
                        <textarea name="reason" class="form-control" rows="3" 
                                  placeholder="Explain why you need overtime..." required></textarea>
                    </div>
                    
                    <!-- Summary -->
                    <div class="teal-bg-light p-3 rounded-3 mb-3 border teal-border">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-medium">Total Overtime:</span>
                            <span class="fw-bold fs-5 teal-text" id="summary_hours">0.00 hours</span>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-teal w-100 py-2">
                        <i class="bi bi-send-check me-2"></i> Submit Request
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Overtime Requests List -->
        <div class="col-md-7 mb-4">
            <div class="overtime-card">
                <div class="p-3 border-bottom teal-border" style="background: #f8f9fa;">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="fw-semibold mb-0 teal-text">
                            <i class="bi bi-list-ul me-2"></i>
                            My Overtime Requests
                        </h6>
                        <span class="badge-teal px-3 py-2 rounded-pill">
                            <?php echo count($overtime_requests); ?> Total
                        </span>
                    </div>
                </div>
                
                <?php if (empty($overtime_requests)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox fs-1 text-secondary" style="opacity: 0.5;"></i>
                    <p class="text-secondary mt-3">No overtime requests yet.</p>
                    <small class="text-muted">Use the form to submit your first request</small>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Hours</th>
                                <th>Type</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($overtime_requests as $req): ?>
                            <tr>
                                <td>
                                    <span class="fw-semibold">
                                        <?php echo date('M d, Y', strtotime($req['overtime_date'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <small>
                                        <?php echo date('h:i A', strtotime($req['time_start'])); ?> - 
                                        <?php echo date('h:i A', strtotime($req['time_end'])); ?>
                                    </small>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        <?php echo $req['total_hours']; ?> hrs
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                    $type = $req['overtime_type'];
                                    $icon = match($type) {
                                        'weekday' => 'bi-brightness-high',
                                        'weekend' => 'bi-calendar-week',
                                        'holiday' => 'bi-gift',
                                        'emergency' => 'bi-exclamation-triangle',
                                        default => 'bi-clock'
                                    };
                                    ?>
                                    <i class="bi <?php echo $icon; ?> teal-text me-1"></i>
                                    <?php echo ucfirst($type); ?>
                                </td>
                                <td>
                                    <?php 
                                    $status = $req['status'];
                                    $badge_class = match($status) {
                                        'pending' => 'badge-pending',
                                        'approved' => 'badge-approved',
                                        'rejected' => 'badge-rejected',
                                        'cancelled' => 'badge-cancelled',
                                        default => 'bg-secondary'
                                    };
                                    ?>
                                    <span class="badge <?php echo $badge_class; ?> p-2">
                                        <?php echo ucfirst($status); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Statistics Cards -->
            <?php 
            $pending_count = 0;
            $approved_count = 0;
            $total_ot_hours = 0;
            
            foreach ($overtime_requests as $req) {
                if ($req['status'] == 'pending') $pending_count++;
                if ($req['status'] == 'approved') {
                    $approved_count++;
                    $total_ot_hours += floatval($req['total_hours']);
                }
            }
            ?>
            
            <div class="row mt-4">
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $pending_count; ?></div>
                        <div class="stat-label">Pending Requests</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $approved_count; ?></div>
                        <div class="stat-label">Approved</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="stat-value"><?php echo number_format($total_ot_hours, 1); ?></div>
                        <div class="stat-label">Total OT Hours</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Important Notes -->
    <div class="small text-secondary mt-3 text-center">
        <i class="bi bi-shield-check me-1 teal-text"></i>
        Overtime requests require approval from your supervisor.
        You'll be notified once your request is processed.
    </div>
</div>

<script>
// Calculate total hours
function updateTotalHours() {
    const timeStart = document.querySelector('input[name="time_start"]').value;
    const timeEnd = document.querySelector('input[name="time_end"]').value;
    
    if (timeStart && timeEnd) {
        // Parse time strings
        const start = new Date(`2000-01-01T${timeStart}`);
        const end = new Date(`2000-01-01T${timeEnd}`);
        
        // Calculate difference in hours
        let diffMs = end - start;
        
        // If end is less than start, assume it's next day
        if (diffMs < 0) {
            diffMs += 24 * 60 * 60 * 1000;
        }
        
        const diffHours = diffMs / (1000 * 60 * 60);
        
        // Update displays
        document.getElementById('total_hours_display').value = diffHours.toFixed(2);
        document.getElementById('summary_hours').textContent = diffHours.toFixed(2) + ' hours';
    }
}

// Form validation
document.getElementById('overtimeForm').addEventListener('submit', function(e) {
    const timeStart = document.querySelector('input[name="time_start"]').value;
    const timeEnd = document.querySelector('input[name="time_end"]').value;
    const totalHours = parseFloat(document.getElementById('total_hours_display').value);
    
    if (totalHours <= 0) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Invalid Time',
            text: 'End time must be after start time',
            confirmButtonColor: '#008080'
        });
        return;
    }
    
    if (totalHours > 24) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Excessive Overtime',
            text: 'Overtime cannot exceed 24 hours',
            confirmButtonColor: '#008080'
        });
        return;
    }
    
    // Show confirmation
    e.preventDefault();
    
    Swal.fire({
        title: 'Submit Overtime Request?',
        html: `
            <div class="text-start">
                <p><strong>Date:</strong> ${document.querySelector('input[name="overtime_date"]').value}</p>
                <p><strong>Time:</strong> ${timeStart} - ${timeEnd}</p>
                <p><strong>Total Hours:</strong> ${totalHours.toFixed(2)}</p>
                <p><strong>Type:</strong> ${document.querySelector('select[name="overtime_type"]').options[document.querySelector('select[name="overtime_type"]').selectedIndex].text}</p>
                <hr>
                <p class="text-muted small">This request will be sent for approval.</p>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#008080',
        confirmButtonText: 'Yes, submit',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            e.target.submit();
        }
    });
});

// Auto-hide alerts
setTimeout(function() {
    document.querySelectorAll('.alert').forEach(function(alert) {
        let bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 5000);
</script>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>