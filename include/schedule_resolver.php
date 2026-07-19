<?php
/**
 * Schedule Resolver Helper
 * 
 * Drop-in replacement for the old single-table attendance_schedules query.
 * Priority: employee_schedules (per-employee) > attendance_schedules (per-position)
 * 
 * Usage in attendance_action.php and attendance_status.php:
 *   require_once __DIR__ . '/../include/schedule_resolver.php';
 *   $schedule = ScheduleResolver::get($pdo, $employee_id, $clinic_id);
 *   if (!$schedule) { // no schedule found }
 *   $time_in      = $schedule['time_in'];
 *   $grace_period = $schedule['grace_period'];
 *   $source       = $schedule['source']; // 'employee' or 'position'
 */

class ScheduleResolver
{
    /**
     * Get the effective schedule for an employee.
     * Returns the per-employee schedule if set, otherwise falls back to
     * the position-based schedule from attendance_schedules.
     *
     * @param PDO    $pdo
     * @param int    $employee_id   employees.id
     * @param int    $clinic_id
     * @param string $on_date       Date to check effective_from/to (default: today)
     * @return array|null           Schedule row, or null if none found
     */
    public static function get(PDO $pdo, int $employee_id, int $clinic_id, ?string $on_date = null): ?array
    {
        $on_date = $on_date ?? date('Y-m-d');

        $sql = "
            SELECT
                COALESCE(es.time_in,             ps.time_in)             AS time_in,
                COALESCE(es.time_out,            ps.time_out)            AS time_out,
                COALESCE(es.break_start,         ps.break_start)         AS break_start,
                COALESCE(es.break_end,           ps.break_end)           AS break_end,
                COALESCE(es.grace_period,        ps.grace_period)        AS grace_period,
                COALESCE(es.required_work_hours, ps.required_work_hours) AS required_work_hours,
                COALESCE(es.schedule_type,       ps.schedule_type)       AS schedule_type,
                CASE WHEN es.id IS NOT NULL THEN 'employee' ELSE 'position' END AS source,
                es.id     AS employee_schedule_id,
                ps.id     AS position_schedule_id,
                es.schedule_name
            FROM employees e
            LEFT JOIN employee_schedules es
                ON  es.employee_id  = e.id
                AND es.clinic_id    = :clinic_id1
                AND es.is_active    = 1
                AND (es.effective_from IS NULL OR es.effective_from <= :on_date1)
                AND (es.effective_to   IS NULL OR es.effective_to   >= :on_date2)
            LEFT JOIN attendance_schedules ps
                ON  ps.clinic_id   = :clinic_id2
                AND ps.is_active   = 1
                AND (ps.position_id = e.position_id OR ps.position_id IS NULL)
            WHERE e.id         = :employee_id
              AND e.clinic_id  = :clinic_id3
            ORDER BY ps.position_id DESC
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':clinic_id1'   => $clinic_id,
            ':on_date1'     => $on_date,
            ':on_date2'     => $on_date,
            ':clinic_id2'   => $clinic_id,
            ':clinic_id3'   => $clinic_id,
            ':employee_id'  => $employee_id,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (!$row['time_in'] && !$row['position_schedule_id'])) {
            return null;
        }
        return $row;
    }

    /**
     * Get schedules for all active employees in a clinic.
     * Useful for HR dashboard / bulk display.
     *
     * @param PDO $pdo
     * @param int $clinic_id
     * @return array  Keyed by employee_id
     */
    public static function getAll(PDO $pdo, int $clinic_id): array
    {
        $today = date('Y-m-d');

        $sql = "
            SELECT
                e.id                                                     AS employee_id,
                e.employee_no,
                CONCAT(u.first_name, ' ', u.last_name)                  AS employee_name,
                u.role,
                COALESCE(es.schedule_name, CONCAT('Position #', e.position_id)) AS schedule_name,
                COALESCE(es.schedule_type,       ps.schedule_type)       AS schedule_type,
                COALESCE(es.time_in,             ps.time_in)             AS time_in,
                COALESCE(es.time_out,            ps.time_out)            AS time_out,
                COALESCE(es.break_start,         ps.break_start)         AS break_start,
                COALESCE(es.break_end,           ps.break_end)           AS break_end,
                COALESCE(es.grace_period,        ps.grace_period)        AS grace_period,
                COALESCE(es.required_work_hours, ps.required_work_hours) AS required_work_hours,
                CASE WHEN es.id IS NOT NULL THEN 'employee' ELSE 'position' END AS source,
                es.effective_from,
                es.effective_to
            FROM employees e
            INNER JOIN users u ON u.id = e.user_id
            LEFT JOIN employee_schedules es
                ON  es.employee_id  = e.id
                AND es.clinic_id    = :clinic_id1
                AND es.is_active    = 1
                AND (es.effective_from IS NULL OR es.effective_from <= :today1)
                AND (es.effective_to   IS NULL OR es.effective_to   >= :today2)
            LEFT JOIN attendance_schedules ps
                ON  ps.clinic_id    = :clinic_id2
                AND ps.is_active    = 1
                AND (ps.position_id = e.position_id OR ps.position_id IS NULL)
            WHERE e.clinic_id = :clinic_id3
              AND e.status    = 'Active'
            ORDER BY u.first_name, u.last_name
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':clinic_id1' => $clinic_id,
            ':today1'     => $today,
            ':today2'     => $today,
            ':clinic_id2' => $clinic_id,
            ':clinic_id3' => $clinic_id,
        ]);

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[$row['employee_id']] = $row;
        }
        return $results;
    }
}


/**
 * ============================================================
 * HOW TO USE IN attendance_action.php
 * ============================================================
 * 
 * BEFORE (old code, around line 319):
 * 
 *   $stmt = $pdo->prepare("
 *       SELECT time_in, grace_period
 *       FROM attendance_schedules
 *       WHERE position_id = ? AND clinic_id = ? AND is_active = 1
 *   ");
 *   $stmt->execute([$position_id, $clinic_id]);
 *   $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
 * 
 * AFTER (new code):
 * 
 *   require_once __DIR__ . '/../include/schedule_resolver.php';
 *   $schedule = ScheduleResolver::get($pdo, $employee_id, $clinic_id);
 * 
 *   if (!$schedule) {
 *       // No schedule set — handle accordingly
 *       echo json_encode(['success' => false, 'error' => 'No schedule found for this employee']);
 *       exit;
 *   }
 * 
 *   $time_in      = $schedule['time_in'];       // same as before
 *   $grace_period = $schedule['grace_period'];  // same as before
 *   $source       = $schedule['source'];        // 'employee' or 'position' — new!
 * 
 * ============================================================
 * HOW TO USE IN attendance_status.php
 * ============================================================
 * 
 * BEFORE (old code, around line 113):
 * 
 *   $stmt = $pdo->prepare("
 *       SELECT time_in, grace_period
 *       FROM attendance_schedules
 *       WHERE position_id = ? AND clinic_id = ? AND is_active = 1
 *   ");
 *   $stmt->execute([$position_id, $clinic_id]);
 *   $sched = $stmt->fetch(PDO::FETCH_ASSOC);
 * 
 * AFTER:
 * 
 *   require_once __DIR__ . '/../include/schedule_resolver.php';
 *   $sched = ScheduleResolver::get($pdo, $employee_id, $clinic_id);
 * 
 *   // All existing field access ($sched['time_in'], etc.) stays the same
 */