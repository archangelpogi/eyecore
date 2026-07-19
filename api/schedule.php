<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

$DEV_MODE = true;

if ($DEV_MODE) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

header('Content-Type: application/json; charset=UTF-8');

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'Fatal Error: ' . $err['message'],
            'file'    => $err['file'],
            'line'    => $err['line']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
});

try {
    require_once __DIR__ . '/../config/db.php';

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception('Database connection failed');
    }

    // ✅ Include RBACHelper
    require_once __DIR__ . '/../include/RBACHelper.php';
require_once __DIR__ . '/../include/SubscriptionHelper.php';  // ✅ IDAGDAG ITO!

// ✅ Initialize RBACHelper
RBACHelper::init($pdo);

// ✅ SUBSCRIPTION CHECK - HR module (ENTERPRISE plan required)
$subHelper = new SubscriptionHelper($pdo, $_SESSION['clinic_id']);
if (!$subHelper->canAccessModule('hr')) {
    header('Location: ../views/subscription.php');
    exit;
}
    
    // Load permissions to session if not already loaded
    if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
        RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
    }

    $api = new ScheduleAPI($pdo);
    $api->handleRequest();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Exception: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ====================================================================
class ScheduleAPI
{
    private PDO $pdo;
    private int $clinic_id;
    private int $user_id;
    private string $user_role;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->checkAuth();
    }

    private function checkAuth(): void
    {
        if (!isset($_SESSION['user_id'], $_SESSION['clinic_id'])) {
            $this->send(401, ['success' => false, 'error' => 'Unauthorized']);
        }
        $this->clinic_id = (int) $_SESSION['clinic_id'];
        $this->user_id   = (int) $_SESSION['user_id'];
        $this->user_role = $_SESSION['role'] ?? '';
    }

    // ============= RBAC PERMISSION METHODS =============
    private function hasPermission(string $permission_name): bool
    {
        return RBACHelper::hasPermission($permission_name);
    }

    private function canView(): bool
    {
        return $this->hasPermission('schedule_management_view');
    }

    private function canEdit(): bool
    {
        return $this->hasPermission('schedule_management_edit');
    }

    private function canManageDoctorSchedule(): bool
    {
        return $this->canEdit();
    }

    private function canManageEmployeeSchedule(): bool
    {
        return $this->canEdit();
    }

    private function canViewMySchedule(): bool
    {
        return $this->hasPermission('my_schedule_view');
    }

    private function getPermissions(): array
    {
        return [
            'role'    => $this->user_role,
            'user_id' => $this->user_id,
            'permissions' => [
                'view'                    => $this->canView(),
                'edit_doctor_schedule'    => $this->canManageDoctorSchedule(),
                'manage_employee_sched'   => $this->canManageEmployeeSchedule(),
                'self_view'               => $this->canViewMySchedule(),
            ]
        ];
    }

    // ----------------------------------------------------------------
    // AUDIT
    // ----------------------------------------------------------------
    private function audit(string $action, string $table, ?int $record_id, $old = null, $new = null): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO audit_logs
                    (user_id, clinic_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $this->user_id,
                $this->clinic_id,
                $action,
                $table,
                $record_id,
                is_array($old) ? json_encode($old) : $old,
                is_array($new) ? json_encode($new) : $new,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        } catch (Exception $e) {
            error_log('Audit log failed: ' . $e->getMessage());
        }
    }


    public function handleRequest(): void
    {
        match ($_SERVER['REQUEST_METHOD']) {
            'GET'  => $this->handleGet(),
            'POST' => $this->handlePost(),
            default => $this->send(405, ['success' => false, 'error' => 'Method not allowed']),
        };
    }

    private function handleGet(): void
    {
        if (isset($_GET['get_permissions'])) {
            $this->send(200, ['success' => true, 'data' => $this->getPermissions()]);
        }

        $action = $_GET['action'] ?? '';

        // Self-view actions: any employee role can access
        $selfActions = ['get_my_schedule', 'get_my_leaves', 'get_my_balances', 'get_today_appointments'];
        if (in_array($action, $selfActions)) {
            if (!$this->canViewMySchedule()) {
                $this->send(403, ['success' => false, 'error' => 'Access denied']);
            }
            match ($action) {
                'get_my_schedule'        => $this->getMySchedule(),
                'get_my_leaves'          => $this->getMyLeaves(),
                'get_my_balances'        => $this->getMyBalances(),
                'get_today_appointments' => $this->getTodayAppointments(),
            };
            return;
        }

        // Management actions: require view permission
        if (!$this->canView()) {
            $this->send(403, ['success' => false, 'error' => 'Access denied — schedule management requires permission']);
        }

        match ($action) {
            'get_doctors'             => $this->getDoctors(),
            'get_employee_schedules'  => $this->getEmployeeSchedules(),
            'get_employees'           => $this->getEmployees(),
            'get_employee_schedule'   => $this->getEmployeeSchedule(),
            default                   => $this->send(400, ['success' => false, 'error' => 'Invalid action']),
        };
    }

    private function getEmployeeSchedule(): void
    {
        $employeeId = (int)($_GET['employee_id'] ?? 0);
        if (!$employeeId) {
            $this->send(400, ['success' => false, 'error' => 'Employee ID required']);
        }

        $today = date('Y-m-d');
        $stmt = $this->pdo->prepare("
            SELECT
                es.*,
                CONCAT(u.first_name, ' ', u.last_name) AS employee_name
            FROM employee_schedules es
            INNER JOIN employees e ON e.id = es.employee_id
            INNER JOIN users u ON u.id = e.user_id
            WHERE es.employee_id = ?
              AND es.clinic_id   = ?
              AND es.is_active   = 1
              AND (es.effective_from IS NULL OR es.effective_from <= ?)
              AND (es.effective_to   IS NULL OR es.effective_to   >= ?)
            ORDER BY es.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$employeeId, $this->clinic_id, $today, $today]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->send(200, ['success' => true, 'data' => $row ?: null]);
    }

    private function getDoctors(): void
    {
        $stmt = $this->pdo->prepare("
            SELECT
                d.id,
                d.clinic_id,
                d.user_id,
                d.name,
                d.specialty,
                d.schedule,
                d.is_active,
                d.created_at
            FROM doctors d
            WHERE d.clinic_id = ?
            ORDER BY d.name
        ");
        $stmt->execute([$this->clinic_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['schedule'] = $row['schedule']
                ? (json_decode($row['schedule'], true) ?: [])
                : [];
        }

        $this->send(200, ['success' => true, 'data' => $rows]);
    }

    private function getEmployeeSchedules(): void
    {
        $today = date('Y-m-d');

        $stmt = $this->pdo->prepare("
            SELECT
                e.id                                                      AS employee_id,
                e.employee_no,
                e.position_id,
                CONCAT(u.first_name, ' ', u.last_name)                   AS employee_name,
                u.role,
                es.id                                                     AS employee_schedule_id,
                es.id                                                     AS schedule_id,
                es.schedule_name,
                COALESCE(es.schedule_type,       ps.schedule_type)       AS schedule_type,
                COALESCE(es.time_in,             ps.time_in)             AS time_in,
                COALESCE(es.time_out,            ps.time_out)            AS time_out,
                COALESCE(es.break_start,         ps.break_start)         AS break_start,
                COALESCE(es.break_end,           ps.break_end)           AS break_end,
                COALESCE(es.grace_period,        ps.grace_period)        AS grace_period,
                COALESCE(es.required_work_hours, ps.required_work_hours) AS required_work_hours,
                es.effective_from,
                es.effective_to,
                es.notes,
                es.is_active,
                CASE
                    WHEN es.id IS NOT NULL THEN 'employee'
                    WHEN ps.id IS NOT NULL THEN 'position'
                    ELSE 'none'
                END AS schedule_source
            FROM employees e
            INNER JOIN users u
                ON  u.id        = e.user_id
                AND u.clinic_id = :cid1
            LEFT JOIN employee_schedules es
                ON  es.employee_id  = e.id
                AND es.clinic_id    = :cid2
                AND es.is_active    = 1
                AND (es.effective_from IS NULL OR es.effective_from <= :today1)
                AND (es.effective_to   IS NULL OR es.effective_to   >= :today2)
            LEFT JOIN attendance_schedules ps
                ON  ps.clinic_id    = :cid3
                AND ps.is_active    = 1
                AND (ps.position_id = e.position_id OR ps.position_id IS NULL)
            WHERE e.status = 'Active'
            GROUP BY e.id, e.employee_no, e.position_id, u.first_name, u.last_name, u.role,
                     es.id, es.schedule_name, es.schedule_type,
                     es.time_in, es.time_out, es.break_start, es.break_end,
                     es.grace_period, es.required_work_hours,
                     es.effective_from, es.effective_to, es.notes, es.is_active,
                     ps.id, ps.schedule_type, ps.time_in, ps.time_out,
                     ps.break_start, ps.break_end, ps.grace_period, ps.required_work_hours
            ORDER BY u.first_name, u.last_name
        ");
        $stmt->execute([
            ':cid1'   => $this->clinic_id,
            ':cid2'   => $this->clinic_id,
            ':today1' => $today,
            ':today2' => $today,
            ':cid3'   => $this->clinic_id,
        ]);

        $this->send(200, ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    private function getEmployees(): void
    {
        $stmt = $this->pdo->prepare("
            SELECT
                e.id,
                e.employee_no,
                e.employment_type,
                u.first_name,
                u.last_name,
                CONCAT(u.first_name, ' ', u.last_name) AS full_name,
                u.role
            FROM employees e
            INNER JOIN users u ON u.id = e.user_id
            WHERE e.status = 'Active' AND u.clinic_id = ?
            ORDER BY u.first_name, u.last_name
        ");
        $stmt->execute([$this->clinic_id]);
        $this->send(200, ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // ----------------------------------------------------------------
    // POST HANDLERS
    // ----------------------------------------------------------------
    private function handlePost(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->send(400, ['success' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg()]);
        }

        $postAction = $data['action'] ?? '';
        if (!$postAction) {
            if (!empty($data['save_doctor_schedule']))     $postAction = 'save_doctor_schedule';
            elseif (!empty($data['save_employee_schedule'])) $postAction = 'save_employee_schedule';
            elseif (!empty($data['update_employee_schedule'])) $postAction = 'update_employee_schedule';
            elseif (!empty($data['delete_employee_schedule'])) $postAction = 'delete_employee_schedule';
        }

        // ✅ Permission checks using RBACHelper
        if ($postAction === 'save_doctor_schedule') {
            if (!$this->canManageDoctorSchedule()) {
                $this->send(403, ['success' => false, 'error' => 'You do not have permission to edit doctor schedules']);
            }
            $this->saveDoctorSchedule($data);
        } elseif ($postAction === 'save_employee_schedule' || $postAction === 'update_employee_schedule') {
            if (!$this->canManageEmployeeSchedule()) {
                $this->send(403, ['success' => false, 'error' => 'You do not have permission to manage employee schedules']);
            }
            $this->saveEmployeeSchedule($data);
        } elseif ($postAction === 'delete_employee_schedule') {
            if (!$this->canManageEmployeeSchedule()) {
                $this->send(403, ['success' => false, 'error' => 'You do not have permission to delete employee schedules']);
            }
            $this->deleteEmployeeSchedule($data);
        } else {
            $this->send(400, ['success' => false, 'error' => 'Invalid action: "' . $postAction . '"']);
        }
    }

    // ----------------------------------------------------------------
    private function saveDoctorSchedule(array $data): void
    {
        $doctorId = (int)($data['doctor_id'] ?? 0);
        $schedule = $data['schedule'] ?? [];
        $slotDur  = (int)($data['slot_duration'] ?? 30);

        if (!$doctorId)         { $this->send(400, ['success' => false, 'error' => 'Doctor ID required']); }
        if (empty($schedule))   { $this->send(400, ['success' => false, 'error' => 'At least one active day required']); }
        if ($slotDur < 5)       { $this->send(400, ['success' => false, 'error' => 'Slot duration too short']); }

        $check = $this->pdo->prepare("SELECT id, schedule FROM doctors WHERE id = ? AND clinic_id = ?");
        $check->execute([$doctorId, $this->clinic_id]);
        $doctor = $check->fetch(PDO::FETCH_ASSOC);
        if (!$doctor) { $this->send(404, ['success' => false, 'error' => 'Doctor not found']); }

        $newJson = json_encode($schedule, JSON_UNESCAPED_UNICODE);

        $this->pdo->beginTransaction();
        try {
            // 1. Update doctors.schedule
            $this->pdo->prepare("UPDATE doctors SET schedule = ? WHERE id = ? AND clinic_id = ?")
                      ->execute([$newJson, $doctorId, $this->clinic_id]);

            // 2. Remove future available slots only (preserve booked ones)
            $this->pdo->prepare("
                DELETE FROM appointment_slots
                WHERE doctor_id = ? AND clinic_id = ? AND slot_date >= CURDATE() AND status = 'available'
            ")->execute([$doctorId, $this->clinic_id]);

            // 3. Generate new slots for next 30 days
            try {
                $this->generateSlots($doctorId, $schedule, $slotDur);
            } catch (Exception $slotEx) {
                error_log('Slot generation skipped: ' . $slotEx->getMessage());
            }

            $this->audit('UPDATE', 'doctors', $doctorId,
                ['schedule' => $doctor['schedule']],
                ['schedule' => $newJson]
            );

            $this->pdo->commit();
            $this->send(200, ['success' => true, 'message' => 'Schedule saved and appointment slots regenerated.']);
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->send(500, ['success' => false, 'error' => 'Save failed: ' . $e->getMessage()]);
        }
    }

    private function generateSlots(int $doctorId, array $schedule, int $duration): void
    {
        $dayMap = ['mon'=>1,'tue'=>2,'wed'=>3,'thu'=>4,'fri'=>5,'sat'=>6,'sun'=>0];

        $colCheck = $this->pdo->query("SHOW COLUMNS FROM appointment_slots")->fetchAll(PDO::FETCH_COLUMN);
        $hasDuration = in_array('duration_minutes', $colCheck);
        $hasSlotDate = in_array('slot_date', $colCheck);
        $hasDate     = in_array('date', $colCheck);
        $dateCol     = $hasSlotDate ? 'slot_date' : ($hasDate ? 'date' : 'slot_date');

        if ($hasDuration) {
            $sql = "INSERT IGNORE INTO appointment_slots
                        (doctor_id, clinic_id, {$dateCol}, slot_time, duration_minutes, status, created_at)
                    VALUES (?, ?, ?, ?, ?, 'available', NOW())";
        } else {
            $sql = "INSERT IGNORE INTO appointment_slots
                        (doctor_id, clinic_id, {$dateCol}, slot_time, status, created_at)
                    VALUES (?, ?, ?, ?, 'available', NOW())";
        }

        $stmt = $this->pdo->prepare($sql);

        $today = new DateTime('today');
        $end   = (new DateTime())->modify('+30 days');
        $cur   = clone $today;

        while ($cur <= $end) {
            $dow    = (int)$cur->format('w');
            $dayKey = array_search($dow, $dayMap);

            if ($dayKey !== false && !empty($schedule[$dayKey])) {
                foreach ($schedule[$dayKey] as $period) {
                    [$startStr, $endStr] = explode('-', $period);
                    $slot    = new DateTime($cur->format('Y-m-d') . ' ' . $startStr);
                    $endTime = new DateTime($cur->format('Y-m-d') . ' ' . $endStr);
                    $now     = new DateTime();

                    while ($slot < $endTime) {
                        if ($slot > $now) {
                            $params = [$doctorId, $this->clinic_id, $cur->format('Y-m-d'), $slot->format('H:i:s')];
                            if ($hasDuration) $params[] = $duration;
                            $stmt->execute($params);
                        }
                        $slot->modify("+{$duration} minutes");
                    }
                }
            }
            $cur->modify('+1 day');
        }
    }

    // ----------------------------------------------------------------
    private function saveEmployeeSchedule(array $data): void
    {
        $id         = isset($data['id']) && $data['id'] ? (int)$data['id'] : null;
        $employeeId = (int)($data['employee_id'] ?? 0);
        $timeIn     = $this->normalizeTime($data['time_in']  ?? '');
        $timeOut    = $this->normalizeTime($data['time_out'] ?? '');

        if (!$employeeId) { $this->send(400, ['success' => false, 'error' => 'Employee ID required']); }
        if (!$timeIn)     { $this->send(400, ['success' => false, 'error' => 'Time in is required']); }
        if (!$timeOut)    { $this->send(400, ['success' => false, 'error' => 'Time out is required']); }

        // Verify employee belongs to clinic
        $chk = $this->pdo->prepare("
            SELECT e.id FROM employees e
            INNER JOIN users u ON u.id = e.user_id
            WHERE e.id = ? AND u.clinic_id = ?
        ");
        $chk->execute([$employeeId, $this->clinic_id]);
        if (!$chk->fetch()) {
            $this->send(404, ['success' => false, 'error' => 'Employee not found in this clinic']);
        }

        $fields = [
            'schedule_name'       => $data['schedule_name']       ?? null,
            'schedule_type'       => $data['schedule_type']       ?? 'fixed',
            'time_in'             => $timeIn,
            'time_out'            => $timeOut,
            'break_start'         => !empty($data['break_start']) ? $this->normalizeTime($data['break_start']) : null,
            'break_end'           => !empty($data['break_end'])   ? $this->normalizeTime($data['break_end'])   : null,
            'grace_period'        => isset($data['grace_period'])        ? (int)$data['grace_period']        : 15,
            'required_work_hours' => isset($data['required_work_hours']) ? (float)$data['required_work_hours'] : 8.00,
            'effective_from'      => !empty($data['effective_from']) ? $data['effective_from'] : null,
            'effective_to'        => !empty($data['effective_to'])   ? $data['effective_to']   : null,
            'notes'               => !empty($data['notes'])          ? $data['notes']          : null,
            'is_active'           => isset($data['is_active']) ? (int)$data['is_active'] : 1,
        ];

        if ($id) {
            $oldStmt = $this->pdo->prepare("SELECT * FROM employee_schedules WHERE id = ? AND clinic_id = ?");
            $oldStmt->execute([$id, $this->clinic_id]);
            $oldRow = $oldStmt->fetch(PDO::FETCH_ASSOC);
            if (!$oldRow) { $this->send(404, ['success' => false, 'error' => 'Schedule not found']); }

            $upd = $this->pdo->prepare("
                UPDATE employee_schedules SET
                    schedule_name       = :schedule_name,
                    schedule_type       = :schedule_type,
                    time_in             = :time_in,
                    time_out            = :time_out,
                    break_start         = :break_start,
                    break_end           = :break_end,
                    grace_period        = :grace_period,
                    required_work_hours = :required_work_hours,
                    effective_from      = :effective_from,
                    effective_to        = :effective_to,
                    notes               = :notes,
                    is_active           = :is_active,
                    updated_at          = NOW()
                WHERE id = :id AND clinic_id = :clinic_id
            ");

            $params = [];
            foreach ($fields as $k => $v) { $params[":$k"] = $v; }
            $params[':id']        = $id;
            $params[':clinic_id'] = $this->clinic_id;
            $upd->execute($params);

            $this->audit('UPDATE', 'employee_schedules', $id, $oldRow, $fields);
            $this->send(200, ['success' => true, 'message' => 'Employee schedule updated.']);
        } else {
            // INSERT — deactivate any existing first
            $this->pdo->prepare("
                UPDATE employee_schedules SET is_active = 0
                WHERE employee_id = ? AND clinic_id = ? AND is_active = 1
            ")->execute([$employeeId, $this->clinic_id]);

            $ins = $this->pdo->prepare("
                INSERT INTO employee_schedules
                    (employee_id, clinic_id, schedule_name, schedule_type,
                     time_in, time_out, break_start, break_end,
                     grace_period, required_work_hours,
                     effective_from, effective_to, notes, is_active, created_by,
                     created_at, updated_at)
                VALUES
                    (:employee_id, :clinic_id, :schedule_name, :schedule_type,
                     :time_in, :time_out, :break_start, :break_end,
                     :grace_period, :required_work_hours,
                     :effective_from, :effective_to, :notes, :is_active, :created_by,
                     NOW(), NOW())
            ");
            $params = [];
            foreach ($fields as $k => $v) { $params[":$k"] = $v; }
            $params[':employee_id'] = $employeeId;
            $params[':clinic_id']   = $this->clinic_id;
            $params[':created_by']  = $this->user_id;
            $ins->execute($params);

            $newId = (int)$this->pdo->lastInsertId();
            $this->audit('CREATE', 'employee_schedules', $newId, null, array_merge($fields, ['employee_id' => $employeeId]));
            $this->send(200, ['success' => true, 'message' => 'Employee schedule assigned.', 'id' => $newId]);
        }
    }

    // ----------------------------------------------------------------
    private function deleteEmployeeSchedule(array $data): void
    {
        $id = (int)($data['id'] ?? 0);
        if (!$id) { $this->send(400, ['success' => false, 'error' => 'Schedule ID required']); }

        $old = $this->pdo->prepare("SELECT * FROM employee_schedules WHERE id = ? AND clinic_id = ?");
        $old->execute([$id, $this->clinic_id]);
        $oldRow = $old->fetch(PDO::FETCH_ASSOC);
        if (!$oldRow) { $this->send(404, ['success' => false, 'error' => 'Schedule not found']); }

        $this->pdo->prepare("DELETE FROM employee_schedules WHERE id = ? AND clinic_id = ?")
                  ->execute([$id, $this->clinic_id]);

        $this->audit('DELETE', 'employee_schedules', $id, $oldRow, null);
        $this->send(200, ['success' => true, 'message' => 'Schedule removed. Employee will fall back to position default.']);
    }

    // ----------------------------------------------------------------
    // MY SCHEDULE (employee self-view)
    // ----------------------------------------------------------------
    private function getMySchedule(): void
    {
        $today = date('Y-m-d');

        $empStmt = $this->pdo->prepare("
            SELECT
                e.id            AS employee_id,
                e.employee_no,
                e.position_id,
                e.employment_type,
                e.salary_frequency,
                e.basic_salary,
                u.first_name,
                u.last_name,
                u.role
            FROM employees e
            INNER JOIN users u ON u.id = e.user_id
            WHERE e.user_id = ? AND u.clinic_id = ?
            LIMIT 1
        ");
        $empStmt->execute([$this->user_id, $this->clinic_id]);
        $emp = $empStmt->fetch(PDO::FETCH_ASSOC);

        if (!$emp) {
            $this->send(404, ['success' => false, 'error' => 'Employee record not found for this user']);
        }

        $employeeId = (int) $emp['employee_id'];

        $schedStmt = $this->pdo->prepare("
            SELECT
                COALESCE(es.id,              NULL)                       AS employee_schedule_id,
                COALESCE(es.schedule_name,   NULL)                       AS schedule_name,
                COALESCE(es.schedule_type,   ps.schedule_type)           AS schedule_type,
                COALESCE(es.time_in,         ps.time_in)                 AS time_in,
                COALESCE(es.time_out,        ps.time_out)                AS time_out,
                COALESCE(es.break_start,     ps.break_start)             AS break_start,
                COALESCE(es.break_end,       ps.break_end)               AS break_end,
                COALESCE(es.grace_period,    ps.grace_period)            AS grace_period,
                COALESCE(es.required_work_hours, ps.required_work_hours) AS required_work_hours,
                es.effective_from,
                es.effective_to,
                CASE
                    WHEN es.id IS NOT NULL THEN 'employee'
                    WHEN ps.id IS NOT NULL THEN 'position'
                    ELSE 'none'
                END AS schedule_source
            FROM employees e
            LEFT JOIN employee_schedules es
                ON  es.employee_id  = e.id
                AND es.clinic_id    = :cid1
                AND es.is_active    = 1
                AND (es.effective_from IS NULL OR es.effective_from <= :today1)
                AND (es.effective_to   IS NULL OR es.effective_to   >= :today2)
            LEFT JOIN attendance_schedules ps
                ON  ps.clinic_id   = :cid2
                AND ps.is_active   = 1
                AND (ps.position_id = e.position_id OR ps.position_id IS NULL)
            WHERE e.id = :emp_id
            LIMIT 1
        ");
        $schedStmt->execute([
            ':cid1'   => $this->clinic_id,
            ':today1' => $today,
            ':today2' => $today,
            ':cid2'   => $this->clinic_id,
            ':emp_id' => $employeeId,
        ]);
        $sched = $schedStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $attStmt = $this->pdo->prepare("
            SELECT
                time_in,
                time_out,
                status,
                remarks
            FROM attendance
            WHERE employee_id = ? AND DATE(date) = CURDATE()
            ORDER BY id DESC LIMIT 1
        ");
        $attStmt->execute([$employeeId]);
        $att = $attStmt->fetch(PDO::FETCH_ASSOC);

        $absStmt = $this->pdo->prepare("
            SELECT COUNT(*) AS cnt
            FROM attendance
            WHERE employee_id = ?
              AND MONTH(date) = MONTH(CURDATE())
              AND YEAR(date)  = YEAR(CURDATE())
              AND status = 'Absent'
        ");
        $absStmt->execute([$employeeId]);
        $abs = (int)($absStmt->fetchColumn() ?? 0);

        $lateStmt = $this->pdo->prepare("
            SELECT COUNT(*) AS cnt
            FROM attendance
            WHERE employee_id = ?
              AND MONTH(date) = MONTH(CURDATE())
              AND YEAR(date)  = YEAR(CURDATE())
              AND status = 'Late'
        ");
        $lateStmt->execute([$employeeId]);
        $lates = (int)($lateStmt->fetchColumn() ?? 0);

        $doctorData = [];
        if ($emp['role'] === 'Optometrist') {
            $docStmt = $this->pdo->prepare("
                SELECT id, schedule FROM doctors
                WHERE user_id = ? AND clinic_id = ? AND is_active = 1
                LIMIT 1
            ");
            $docStmt->execute([$this->user_id, $this->clinic_id]);
            $doc = $docStmt->fetch(PDO::FETCH_ASSOC);

            if ($doc) {
                $doctorData['doctor_id']       = (int)$doc['id'];
                $doctorData['doctor_schedule']  = $doc['schedule']
                    ? (json_decode($doc['schedule'], true) ?: [])
                    : [];

                $apptStmt = $this->pdo->prepare("
                    SELECT COUNT(*) FROM appointment_slots
                    WHERE doctor_id = ? AND clinic_id = ? AND slot_date = CURDATE()
                      AND status IN ('booked','confirmed')
                ");
                $apptStmt->execute([$doc['id'], $this->clinic_id]);
                $doctorData['appointments_today'] = (int)$apptStmt->fetchColumn();
            }
        }

        $result = array_merge([
            'employee_id'          => $employeeId,
            'name'                 => trim($emp['first_name'] . ' ' . $emp['last_name']),
            'employee_no'          => $emp['employee_no'],
            'role'                 => $emp['role'],
            'employment_type'      => $emp['employment_type'],
            'salary_frequency'     => $emp['salary_frequency'],
            'time_in_today'        => $att ? substr($att['time_in'] ?? '', 0, 5) : null,
            'attendance_status'    => $att['status'] ?? 'Not yet in',
            'absences_this_month'  => $abs,
            'lates_this_month'     => $lates,
        ], $sched, $doctorData);

        $this->send(200, ['success' => true, 'data' => $result]);
    }

    // ----------------------------------------------------------------
    private function getMyLeaves(): void
    {
        $empId = $this->getMyEmployeeId();
        if (!$empId) {
            $this->send(200, ['success' => true, 'data' => []]);
        }

        $stmt = $this->pdo->prepare("
            SELECT
                l.id,
                l.start_date,
                l.end_date,
                l.number_of_days,
                l.status,
                l.reason,
                l.leave_with_pay,
                l.created_at,
                lt.type_name,
                lt.code
            FROM leaves l
            LEFT JOIN leave_types lt ON lt.id = l.leave_type_id
            WHERE l.employee_id = ?
              AND l.clinic_id   = ?
            ORDER BY l.created_at DESC
            LIMIT 20
        ");
        $stmt->execute([$empId, $this->clinic_id]);
        $this->send(200, ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // ----------------------------------------------------------------
    private function getMyBalances(): void
    {
        $empId = $this->getMyEmployeeId();
        if (!$empId) {
            $this->send(200, ['success' => true, 'data' => []]);
        }

        $year = date('Y');
        $stmt = $this->pdo->prepare("
            SELECT
                lb.id,
                lb.total_entitled,
                lb.used,
                lb.balance,
                lb.carried_over,
                lb.year,
                lt.type_name,
                lt.code
            FROM leave_balances lb
            INNER JOIN leave_types lt ON lt.id = lb.leave_type_id
            WHERE lb.employee_id = ?
              AND lb.clinic_id   = ?
              AND lb.year        = ?
            ORDER BY lt.type_name
        ");
        $stmt->execute([$empId, $this->clinic_id, $year]);
        $this->send(200, ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // ----------------------------------------------------------------
    private function getTodayAppointments(): void
    {
        $docStmt = $this->pdo->prepare("
            SELECT id FROM doctors
            WHERE user_id = ? AND clinic_id = ? AND is_active = 1
            LIMIT 1
        ");
        $docStmt->execute([$this->user_id, $this->clinic_id]);
        $doc = $docStmt->fetch(PDO::FETCH_ASSOC);

        if (!$doc) {
            $this->send(200, ['success' => true, 'data' => []]);
        }

        $stmt = $this->pdo->prepare("
            SELECT
                asl.slot_time,
                asl.status,
                asl.duration_minutes,
                CONCAT(u.first_name, ' ', u.last_name) AS patient_name,
                s.name AS service
            FROM appointment_slots asl
            LEFT JOIN appointments a ON a.slot_id = asl.id
            LEFT JOIN patients p     ON p.id = a.patient_id
            LEFT JOIN users u        ON u.id = p.user_id
            LEFT JOIN services s     ON s.id = a.service_id
            WHERE asl.doctor_id  = ?
              AND asl.clinic_id  = ?
              AND asl.slot_date  = CURDATE()
              AND asl.status IN ('booked','confirmed')
            ORDER BY asl.slot_time
        ");
        $stmt->execute([$doc['id'], $this->clinic_id]);
        $this->send(200, ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // ----------------------------------------------------------------
    private function getMyEmployeeId(): ?int
    {
        $stmt = $this->pdo->prepare("
            SELECT e.id FROM employees e
            INNER JOIN users u ON u.id = e.user_id
            WHERE e.user_id = ? AND u.clinic_id = ?
            LIMIT 1
        ");
        $stmt->execute([$this->user_id, $this->clinic_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['id'] : null;
    }

    // ----------------------------------------------------------------
    private function normalizeTime(?string $t): ?string
    {
        if ($t === null || trim($t) === '') return null;
        $t = trim($t);
        if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $t)) return $t;
        if (preg_match('/^\d{1,2}:\d{2}$/', $t)) return $t . ':00';
        return null;
    }

    // ----------------------------------------------------------------
    private function send(int $code, array $data): never
    {
        if (ob_get_length()) ob_clean();
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}