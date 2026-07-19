<?php
/**
 * PayrollCalculator
 *
 * Flow: ScheduleResolver → attendance → deductions → contributions → net pay
 *
 * Requires: include/schedule_resolver.php (already autoloaded by payroll.php)
 */
class PayrollCalculator
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ================================================================
    // PUBLIC: Main entry point
    // ================================================================
    public function calculatePayroll(
        int    $employee_id,
        string $period_start,
        string $period_end,
        string $payroll_period,
        bool   $include_adjustments = true,
        ?array $attendance_data     = null,
        bool   $prorate             = false,
        array  $settings            = [],
        array  $manual_values       = []
    ): array {
        try {
            // ── Employee ─────────────────────────────────────────────
            $employee = $this->getEmployeeDetails($employee_id);
            if (!$employee) {
                return ['error' => true, 'message' => 'Employee not found'];
            }

            $clinic_id = (int)($employee['clinic_id'] ?? 0);

            // ── Effective schedule (per-employee > position) ──────────
            $schedule = null;
            if (class_exists('ScheduleResolver')) {
                $schedule = ScheduleResolver::get($this->pdo, $employee_id, $clinic_id);
            }

            // ── Attendance ───────────────────────────────────────────
            $attendance = $attendance_data
                ?? $this->calculateAttendance($employee_id, $period_start, $period_end, $schedule);

            // ── Salary config ─────────────────────────────────────────
            $salary_frequency  = $employee['salary_frequency'] ?? 'monthly';
            $salary_type       = $employee['salary_type']      ?? 'fixed';
            $monthly_basic     = (float)($employee['basic_salary'] ?? 0);

            // ── Working days for the period ───────────────────────────
            $total_working_days = $this->getScheduleWorkingDays($period_start, $period_end, $schedule);
            if ($total_working_days <= 0) $total_working_days = 22; // safe fallback

            // ── Rates ─────────────────────────────────────────────────
            if ($salary_type === 'hourly') {
                $hourly_rate = $monthly_basic;
                $daily_rate  = $hourly_rate * 8;
                $monthly_pay = $daily_rate * $total_working_days;
            } else {
                // Normalise monthly equivalent
                $monthly_pay = match($salary_frequency) {
                    'semi-monthly' => $monthly_basic * 2,
                    'weekly'       => $monthly_basic * 4.33,
                    'daily'        => $monthly_basic * $total_working_days,
                    default        => $monthly_basic,
                };
                $daily_rate  = $monthly_pay / $total_working_days;
                $hourly_rate = $daily_rate / 8;
            }

            // ── Basic salary ──────────────────────────────────────────
            $has_attendance = ($attendance['present_days'] ?? 0) > 0 || ($attendance['total_hours'] ?? 0) > 0;

            if (!$has_attendance) {
                $basic_salary = 0;
            } elseif ($prorate) {
                $basic_salary = $daily_rate * (float)($attendance['total_days_worked'] ?? $attendance['present_days']);
            } else {
                $basic_salary = $monthly_pay;
            }

            // ── Earnings ──────────────────────────────────────────────
            $overtime           = $this->calculateOvertime($employee_id, $period_start, $period_end, $hourly_rate);
            $holiday_pay        = $this->calculateHolidayPay($employee_id, $period_start, $period_end, $daily_rate);
            $night_differential = $this->calculateNightDifferential($employee_id, $period_start, $period_end, $daily_rate);
            $thirteenth_month   = $this->calculateThirteenthMonth($employee_id, $period_start, $period_end, $basic_salary);
            $leaves             = $this->calculateLeaves($employee_id, $period_start, $period_end, $daily_rate);

            $adjustments = $include_adjustments
                ? $this->calculateAdjustments($employee_id, $period_start, $period_end)
                : ['total_earnings' => 0, 'total_deductions' => 0, 'allowances' => 0, 'bonuses' => 0, 'other_deductions' => 0];

            // ── Contributions ─────────────────────────────────────────
            $settings = array_merge([
                'include_sss'        => true,
                'include_philhealth' => true,
                'include_pagibig'    => true,
                'include_tax'        => true,
            ], $settings);

            $contributions  = $this->calculateGovernmentContributions($basic_salary, $settings, $manual_values);
            $taxable_income = $basic_salary + $overtime + $holiday_pay + (float)($adjustments['total_earnings'] ?? 0);
            $withholding_tax = $settings['include_tax']
                ? (float)($manual_values['tax'] ?? 0) ?: $this->calculateWithholdingTax($taxable_income)
                : 0;

            // ── Totals ────────────────────────────────────────────────
            $gross_pay = $basic_salary + $overtime + $holiday_pay + $night_differential
                       + $thirteenth_month + (float)($adjustments['allowances'] ?? 0)
                       + (float)($adjustments['bonuses'] ?? 0);

            $total_deductions = $contributions['sss'] + $contributions['philhealth'] + $contributions['pagibig']
                              + $withholding_tax + (float)($adjustments['other_deductions'] ?? 0)
                              + (float)($attendance['absences_deduction'] ?? 0)
                              + (float)($attendance['tardiness_deduction'] ?? 0)
                              + (float)($attendance['break_deduction'] ?? 0)
                              + (float)($leaves['without_pay_deduction'] ?? 0);

            $net_pay = max(0, $gross_pay - $total_deductions);

            return [
                'error'                    => false,
                'basic_salary'             => round($basic_salary, 2),
                'overtime'                 => round($overtime, 2),
                'holiday_pay'              => round($holiday_pay, 2),
                'allowances'               => round((float)($adjustments['allowances'] ?? 0), 2),
                'bonuses'                  => round((float)($adjustments['bonuses'] ?? 0), 2),
                'tardiness'                => round((float)($attendance['tardiness_deduction'] ?? 0), 2),
                'absences'                 => round((float)($attendance['absences_deduction'] ?? 0), 2),
                'break_deduction'          => round((float)($attendance['break_deduction'] ?? 0), 2),
                'night_differential'       => round($night_differential, 2),
                'thirteenth_month'         => round($thirteenth_month, 2),
                'leave_without_pay'        => round((float)($leaves['without_pay_deduction'] ?? 0), 2),
                'sss_contribution'         => round($contributions['sss'], 2),
                'philhealth_contribution'  => round($contributions['philhealth'], 2),
                'pagibig_contribution'     => round($contributions['pagibig'], 2),
                'withholding_tax'          => round($withholding_tax, 2),
                'other_deductions'         => round((float)($adjustments['other_deductions'] ?? 0), 2),
                'gross_pay'                => round($gross_pay, 2),
                'total_deductions'         => round($total_deductions, 2),
                'net_pay'                  => round($net_pay, 2),
                'attendance_summary'       => $attendance,
                'leave_summary'            => $leaves,
            ];

        } catch (Exception $e) {
            return ['error' => true, 'message' => 'Calculation error: ' . $e->getMessage()];
        }
    }

    // Wrapper kept for backward compatibility
    public function calculatePayrollWithAttendance(
        int $employee_id, string $start, string $end, string $period,
        array $attendance_data, bool $prorate, array $settings
    ): array {
        return $this->calculatePayroll($employee_id, $start, $end, $period, true, $attendance_data, $prorate, $settings, []);
    }

    // ================================================================
    // ATTENDANCE — connected to ScheduleResolver
    // ================================================================
    private function calculateAttendance(
        int    $employee_id,
        string $start_date,
        string $end_date,
        ?array $schedule = null
    ): array {
        try {
            // Scheduled time_in and required work hours
            $sched_time_in      = $schedule['time_in']             ?? null;
            $grace_period       = (int)($schedule['grace_period']  ?? 15);
            $req_hours          = (float)($schedule['required_work_hours'] ?? 8.0);
            $break_hours        = 0; // Using fixed-deduction approach (no break button tracking)

            // If schedule has break times, compute break duration
            if (!empty($schedule['break_start']) && !empty($schedule['break_end'])) {
                $bs = strtotime('1970-01-01 ' . $schedule['break_start']);
                $be = strtotime('1970-01-01 ' . $schedule['break_end']);
                $break_hours = max(0, ($be - $bs) / 3600);
            }

            // Fetch raw attendance records
            $stmt = $this->pdo->prepare("
                SELECT date, time_in, time_out, total_hours, status,
                       break_start, break_end
                FROM attendance
                WHERE employee_id = ? AND date BETWEEN ? AND ?
                ORDER BY date
            ");
            $stmt->execute([$employee_id, $start_date, $end_date]);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $present_days      = 0;
            $absent_days       = 0;
            $half_days         = 0;
            $late_days         = 0;
            $total_hours       = 0.0;
            $total_late_mins   = 0;
            $total_break_deduct= 0.0;

            foreach ($records as $r) {
                $status = $r['status'] ?? 'Present';

                if ($status === 'Absent') {
                    $absent_days++;
                    continue;
                }

                if ($status === 'Half-day') {
                    $half_days += 0.5;
                }

                // Count present
                if (in_array($status, ['Present', 'Late', 'Half-day'])) {
                    $present_days += ($status === 'Half-day') ? 0.5 : 1;
                }

                // Actual hours worked
                $hours = (float)($r['total_hours'] ?? 0);

                // Break deduction — if schedule defines break but total_hours already
                // includes break time, deduct it
                if ($break_hours > 0 && $hours > 0) {
                    $raw_break = 0.0;
                    if (!empty($r['break_start']) && !empty($r['break_end'])
                        && strtotime($r['break_start']) >= strtotime($r['time_in'])) {
                        // Tracked break — use actual
                        $raw_break = (strtotime($r['break_end']) - strtotime($r['break_start'])) / 3600;
                    } else {
                        // Fixed break deduction from schedule
                        $raw_break = $break_hours;
                    }
                    $total_break_deduct += max(0, $raw_break);
                }

                $total_hours += $hours;

                // Tardiness — actual minutes late vs schedule
                if ($status === 'Late' && $sched_time_in && !empty($r['time_in'])) {
                    $late_days++;
                    $actual_ti  = strtotime('1970-01-01 ' . $r['time_in']);
                    $sched_ti   = strtotime('1970-01-01 ' . $sched_time_in);
                    $grace_secs = $grace_period * 60;
                    $late_secs  = max(0, $actual_ti - $sched_ti - $grace_secs);
                    $total_late_mins += (int)($late_secs / 60);
                } elseif ($status === 'Late') {
                    $late_days++;
                    $total_late_mins += 30; // fallback: 30 min if no schedule
                }
            }

            // Rates for deductions
            $daily_rate  = $this->getDailyRate($employee_id, $start_date, $end_date);
            $hourly_rate = $daily_rate / ($req_hours > 0 ? $req_hours : 8);

            $absences_deduction  = ($absent_days + ($half_days * 0.5)) * $daily_rate;
            $tardiness_deduction = ($total_late_mins / 60) * $hourly_rate;
            $break_deduction     = $total_break_deduct * $hourly_rate;

            $total_days_worked = $present_days; // half_days already counted as 0.5

            return [
                'present_days'        => $present_days,
                'absent_days'         => $absent_days,
                'half_days'           => $half_days,
                'late_days'           => $late_days,
                'total_hours'         => round($total_hours, 2),
                'total_days_worked'   => round($total_days_worked, 2),
                'total_late_minutes'  => $total_late_mins,
                'absences_deduction'  => round($absences_deduction, 2),
                'tardiness_deduction' => round($tardiness_deduction, 2),
                'break_deduction'     => round($break_deduction, 2),
            ];

        } catch (Exception $e) {
            error_log('calculateAttendance error: ' . $e->getMessage());
            return [
                'present_days' => 0, 'absent_days' => 0, 'half_days' => 0,
                'late_days' => 0, 'total_hours' => 0, 'total_days_worked' => 0,
                'total_late_minutes' => 0,
                'absences_deduction' => 0, 'tardiness_deduction' => 0, 'break_deduction' => 0,
            ];
        }
    }

    // ================================================================
    // WORKING DAYS — schedule-aware
    // ================================================================
    private function getScheduleWorkingDays(string $start, string $end, ?array $schedule): int
    {
        // Build set of active days from schedule if available
        $active_days = null;
        if ($schedule && !empty($schedule['schedule_type'])) {
            // schedule_type stores JSON: {"mon":true,"tue":true,...}
            // OR is a string like 'weekdays'
            $type = $schedule['schedule_type'];
            if ($type === 'weekdays') {
                $active_days = [1,2,3,4,5]; // Mon–Fri (PHP date('w') = 1..5)
            } elseif ($type === 'daily') {
                $active_days = [1,2,3,4,5,6]; // Mon–Sat
            } elseif (is_string($type) && str_starts_with($type, '{')) {
                $days_map = json_decode($type, true) ?? [];
                $map = ['sun'=>0,'mon'=>1,'tue'=>2,'wed'=>3,'thu'=>4,'fri'=>5,'sat'=>6];
                $active_days = [];
                foreach ($map as $key => $num) {
                    if (!empty($days_map[$key])) $active_days[] = $num;
                }
            }
        }

        $count   = 0;
        $current = strtotime($start);
        $end_ts  = strtotime($end);

        while ($current <= $end_ts) {
            $dow = (int)date('w', $current); // 0=Sun, 6=Sat
            if ($active_days !== null) {
                if (in_array($dow, $active_days)) $count++;
            } else {
                // Default: Mon–Sat (exclude Sunday)
                if ($dow !== 0) $count++;
            }
            $current = strtotime('+1 day', $current);
        }

        return max(1, $count);
    }

    // ================================================================
    // OVERTIME — uses correct hourly rate
    // ================================================================
    private function calculateOvertime(
        int    $employee_id,
        string $start_date,
        string $end_date,
        float  $hourly_rate
    ): float {
        try {
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE 'overtime_requests'");
            $stmt->execute();
            if (!$stmt->fetch()) return 0;

            $stmt = $this->pdo->prepare("
                SELECT overtime_type, SUM(total_hours) AS hrs
                FROM overtime_requests
                WHERE employee_id = ? AND overtime_date BETWEEN ? AND ? AND status = 'approved'
                GROUP BY overtime_type
            ");
            $stmt->execute([$employee_id, $start_date, $end_date]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $total = 0.0;
            foreach ($rows as $r) {
                $multiplier = match ($r['overtime_type'] ?? 'regular') {
                    'holiday'  => 2.0,
                    'restday'  => 1.3,
                    default    => 1.25, // regular overtime — DOLE standard
                };
                $total += (float)$r['hrs'] * $hourly_rate * $multiplier;
            }
            return $total;

        } catch (Exception $e) {
            error_log('calculateOvertime error: ' . $e->getMessage());
            return 0;
        }
    }

    // ================================================================
    // HOLIDAY PAY
    // ================================================================
    private function calculateHolidayPay(
        int    $employee_id,
        string $start_date,
        string $end_date,
        float  $daily_rate
    ): float {
        try {
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE 'holidays'");
            $stmt->execute();
            if (!$stmt->fetch()) return 0;

            $stmt = $this->pdo->prepare("
                SELECT holiday_date, holiday_type FROM holidays
                WHERE holiday_date BETWEEN ? AND ?
            ");
            $stmt->execute([$start_date, $end_date]);
            $holidays = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$holidays) return 0;

            $total = 0.0;
            foreach ($holidays as $h) {
                $stmt = $this->pdo->prepare("
                    SELECT COUNT(*) FROM attendance
                    WHERE employee_id=? AND date=? AND status IN ('Present','Late','Half-day')
                ");
                $stmt->execute([$employee_id, $h['holiday_date']]);
                $worked = (int)$stmt->fetchColumn();

                if ($worked) {
                    $total += $daily_rate * ($h['holiday_type'] === 'regular_holiday' ? 2.0 : 1.3);
                } elseif ($h['holiday_type'] === 'regular_holiday') {
                    $total += $daily_rate; // pay even without work on regular holiday
                }
            }
            return $total;

        } catch (Exception $e) {
            error_log('calculateHolidayPay error: ' . $e->getMessage());
            return 0;
        }
    }

    // ================================================================
    // NIGHT DIFFERENTIAL
    // ================================================================
    private function calculateNightDifferential(
        int    $employee_id,
        string $start_date,
        string $end_date,
        float  $daily_rate
    ): float {
        try {
            $col = $this->pdo->prepare("SHOW COLUMNS FROM attendance LIKE 'shift_type'");
            $col->execute();

            if ($col->fetch()) {
                $stmt = $this->pdo->prepare("
                    SELECT COUNT(*) FROM attendance
                    WHERE employee_id=? AND date BETWEEN ? AND ? AND shift_type='night'
                ");
            } else {
                $stmt = $this->pdo->prepare("
                    SELECT COUNT(*) FROM attendance
                    WHERE employee_id=? AND date BETWEEN ? AND ?
                    AND (TIME(time_in) >= '22:00:00' OR TIME(time_out) <= '06:00:00')
                ");
            }
            $stmt->execute([$employee_id, $start_date, $end_date]);
            return (int)$stmt->fetchColumn() * $daily_rate * 0.10;

        } catch (Exception $e) {
            error_log('calculateNightDifferential error: ' . $e->getMessage());
            return 0;
        }
    }

    // ================================================================
    // 13TH MONTH PAY
    // ================================================================
    private function calculateThirteenthMonth(
        int    $employee_id,
        string $period_start,
        string $period_end,
        float  $basic_salary
    ): float {
        try {
            $end_dt = new DateTime($period_end);
            if ($end_dt->format('m') != '12') return 0;

            $year = $end_dt->format('Y');
            $stmt = $this->pdo->prepare("
                SELECT COUNT(DISTINCT DATE_FORMAT(date,'%Y-%m')) AS months_worked
                FROM attendance
                WHERE employee_id=? AND YEAR(date)=? AND status IN ('Present','Half-day','Late')
            ");
            $stmt->execute([$employee_id, $year]);
            $months = (int)$stmt->fetchColumn();

            if ($months === 0) {
                $stmt = $this->pdo->prepare("SELECT start_date FROM employees WHERE id=?");
                $stmt->execute([$employee_id]);
                $emp = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($emp && $emp['start_date']) {
                    $diff   = $end_dt->diff(new DateTime($emp['start_date']));
                    $months = min(12, $diff->m + ($diff->y * 12) + 1);
                } else {
                    $months = 12;
                }
            }

            return ($basic_salary * min(12, $months)) / 12;

        } catch (Exception $e) {
            error_log('calculateThirteenthMonth error: ' . $e->getMessage());
            return 0;
        }
    }

    // ================================================================
    // LEAVES
    // ================================================================
    private function calculateLeaves(
        int    $employee_id,
        string $start_date,
        string $end_date,
        float  $daily_rate
    ): array {
        $empty = ['paid_leave_days' => 0, 'unpaid_leave_days' => 0, 'without_pay_deduction' => 0];
        try {
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE 'leaves'");
            $stmt->execute();
            if (!$stmt->fetch()) return $empty;

            $stmt = $this->pdo->prepare("
                SELECT
                    SUM(CASE WHEN leave_with_pay='with_pay'    THEN number_of_days ELSE 0 END) AS paid,
                    SUM(CASE WHEN leave_with_pay='without_pay' THEN number_of_days ELSE 0 END) AS unpaid
                FROM leaves
                WHERE employee_id=? AND status='Approved'
                  AND (start_date BETWEEN ? AND ? OR end_date BETWEEN ? AND ?
                       OR ? BETWEEN start_date AND end_date)
            ");
            $stmt->execute([$employee_id, $start_date, $end_date, $start_date, $end_date, $start_date]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $unpaid = (float)($row['unpaid'] ?? 0);
            return [
                'paid_leave_days'       => (float)($row['paid'] ?? 0),
                'unpaid_leave_days'     => $unpaid,
                'without_pay_deduction' => round($unpaid * $daily_rate, 2),
            ];

        } catch (Exception $e) {
            error_log('calculateLeaves error: ' . $e->getMessage());
            return $empty;
        }
    }

    // ================================================================
    // ADJUSTMENTS — safe: works with or without payroll_items
    // ================================================================
    private function calculateAdjustments(int $employee_id, string $start_date, string $end_date): array
    {
        $empty = ['total_earnings' => 0, 'total_deductions' => 0, 'allowances' => 0, 'bonuses' => 0, 'other_deductions' => 0];
        try {
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE 'payroll_adjustments'");
            $stmt->execute();
            if (!$stmt->fetch()) return $empty;

            // Check if payroll_items join is safe
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE 'payroll_items'");
            $stmt->execute();
            $has_items_table = (bool)$stmt->fetch();

            if ($has_items_table) {
                $stmt = $this->pdo->prepare("
                    SELECT pa.adjustment_type, pa.amount,
                           pi.item_type, pi.item_name
                    FROM payroll_adjustments pa
                    LEFT JOIN payroll_items pi ON pi.id = pa.payroll_item_id
                    WHERE pa.employee_id = ? AND pa.status = 'active'
                      AND (
                          (pa.is_recurring = 1 AND pa.effective_date <= ? AND (pa.end_date IS NULL OR pa.end_date >= ?))
                          OR (pa.is_recurring = 0 AND pa.effective_date BETWEEN ? AND ?)
                      )
                ");
            } else {
                $stmt = $this->pdo->prepare("
                    SELECT pa.adjustment_type, pa.amount,
                           'earning' AS item_type, pa.reason AS item_name
                    FROM payroll_adjustments pa
                    WHERE pa.employee_id = ? AND pa.status = 'active'
                      AND (
                          (pa.is_recurring = 1 AND pa.effective_date <= ? AND (pa.end_date IS NULL OR pa.end_date >= ?))
                          OR (pa.is_recurring = 0 AND pa.effective_date BETWEEN ? AND ?)
                      )
                ");
            }
            $stmt->execute([$employee_id, $end_date, $start_date, $start_date, $end_date]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $earnings = $deductions = $allowances = $bonuses = $other = 0.0;
            foreach ($rows as $r) {
                $amt  = (float)$r['amount'];
                $type = $r['item_type'] ?? 'earning';
                $name = strtolower($r['item_name'] ?? '');

                if ($type === 'deduction' || $r['adjustment_type'] === 'deduction') {
                    $deductions += $amt;
                    $other      += $amt;
                } else {
                    $earnings += $amt;
                    if (str_contains($name, 'allowance')) $allowances += $amt;
                    elseif (str_contains($name, 'bonus'))  $bonuses    += $amt;
                }
            }

            return [
                'total_earnings'   => $earnings,
                'total_deductions' => $deductions,
                'allowances'       => $allowances,
                'bonuses'          => $bonuses,
                'other_deductions' => $other,
            ];

        } catch (Exception $e) {
            error_log('calculateAdjustments error: ' . $e->getMessage());
            return $empty;
        }
    }

    // ================================================================
    // GOVERNMENT CONTRIBUTIONS
    // ================================================================
    private function calculateGovernmentContributions(float $salary, array $settings, array $manual): array
    {
        if (!empty($manual['use_manual'])) {
            return [
                'sss'        => (float)($manual['sss']        ?? 0),
                'philhealth' => (float)($manual['philhealth'] ?? 0),
                'pagibig'    => (float)($manual['pagibig']    ?? 0),
            ];
        }
        if ($salary <= 0) return ['sss' => 0, 'philhealth' => 0, 'pagibig' => 0];

        return [
            'sss'        => $settings['include_sss']        ? $this->calculateSSS($salary)        : 0,
            'philhealth' => $settings['include_philhealth'] ? $this->calculatePhilhealth($salary) : 0,
            'pagibig'    => $settings['include_pagibig']    ? $this->calculatePagibig($salary)    : 0,
        ];
    }

    private function calculateSSS(float $salary): float
    {
        // SSS 2024 Employee contribution table
        $brackets = [
            3250=>135, 3750=>157.5, 4250=>180, 4750=>202.5, 5250=>225,
            5750=>247.5, 6250=>270, 6750=>292.5, 7250=>315, 7750=>337.5,
            8250=>360, 8750=>382.5, 9250=>405, 9750=>427.5, 10250=>450,
            10750=>472.5, 11250=>495, 11750=>517.5, 12250=>540, 12750=>562.5,
            13250=>585, 13750=>607.5, 14250=>630, 14750=>652.5, 15250=>675,
            15750=>697.5, 16250=>720, 16750=>742.5, 17250=>765, 17750=>787.5,
            18250=>810, 18750=>832.5, 19250=>855, 19750=>877.5, 20250=>900,
            20750=>922.5, 21250=>945, 21750=>967.5, 22250=>990, 22750=>1012.5,
            23250=>1035, 23750=>1057.5, 24250=>1080, 24750=>1102.5, 25250=>1125,
            25750=>1147.5, 26250=>1170, 26750=>1192.5, 27250=>1215, 27750=>1237.5,
            28250=>1260, 28750=>1282.5, 29250=>1305, 29750=>1327.5,
        ];
        foreach ($brackets as $limit => $contrib) {
            if ($salary <= $limit) return $contrib;
        }
        return 1350.00; // max 2024
    }

    private function calculatePhilhealth(float $salary): float
    {
        // PhilHealth 2024: 5% total, employee pays 2.5%
        if ($salary <= 10000) return 250.00;  // floor
        if ($salary >= 100000) return 2500.00; // ceiling
        return round($salary * 0.025, 2);     // 2.5% employee share
    }

    private function calculatePagibig(float $salary): float
    {
        // Pag-IBIG 2024
        return $salary <= 1500
            ? round($salary * 0.01, 2)         // 1%
            : min(100.00, round($salary * 0.02, 2)); // 2%, max ₱100
    }

    private function calculateWithholdingTax(float $taxable): float
    {
        // BIR 2024 monthly tax table
        if ($taxable <= 20833)  return 0;
        if ($taxable <= 33333)  return ($taxable - 20833)  * 0.20;
        if ($taxable <= 66667)  return 2500   + ($taxable - 33333)  * 0.25;
        if ($taxable <= 166667) return 10833  + ($taxable - 66667)  * 0.30;
        if ($taxable <= 666667) return 40833  + ($taxable - 166667) * 0.32;
        return 200833 + ($taxable - 666667) * 0.35;
    }

    // ================================================================
    // RATE HELPERS
    // ================================================================
    private function getEmployeeDetails(int $employee_id): array|false
    {
        $stmt = $this->pdo->prepare("
            SELECT e.*, u.first_name, u.last_name, u.clinic_id,
                   p.position_name, e.salary_frequency, e.salary_type
            FROM employees e
            JOIN users u ON u.id = e.user_id
            LEFT JOIN positions p ON p.id = e.position_id
            WHERE e.id = ?
        ");
        $stmt->execute([$employee_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function getDailyRate(int $employee_id, ?string $start = null, ?string $end = null): float
    {
        $stmt = $this->pdo->prepare("SELECT basic_salary FROM employees WHERE id=?");
        $stmt->execute([$employee_id]);
        $monthly = (float)($stmt->fetchColumn() ?? 0);
        $days    = ($start && $end) ? $this->getScheduleWorkingDays($start, $end, null) : 22;
        return $days > 0 ? $monthly / $days : 0;
    }

    // Legacy alias
    private function getWorkingDays(string $start, string $end): int
    {
        return $this->getScheduleWorkingDays($start, $end, null);
    }

    // Manual adjustment helper (called from payroll.php if needed)
    public function addManualAdjustments(array $result, float $bonus, float $deduction): array
    {
        $result['bonuses']          += $bonus;
        $result['gross_pay']        += $bonus;
        $result['other_deductions'] += $deduction;
        $result['total_deductions'] += $deduction;
        $result['net_pay']           = max(0, $result['gross_pay'] - $result['total_deductions']);
        return $result;
    }
}