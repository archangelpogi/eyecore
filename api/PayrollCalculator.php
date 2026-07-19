<?php
class PayrollCalculator {
    private $conn;
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    public function calculateNetPay($employee_id, $period_start, $period_end) {
        // Get employee data
        $stmt = $this->conn->prepare("
            SELECT e.*, 
                   COALESCE(e.basic_salary, 0) as basic_salary,
                   e.employment_type,
                   e.date_hired
            FROM employees e
            WHERE e.id = ?
        ");
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $employee = $stmt->get_result()->fetch_assoc();
        
        if (!$employee) {
            throw new Exception("Employee not found");
        }
        
        // Calculate daily rate
        $daily_rate = $employee['basic_salary'] / 22; // Assuming 22 working days
        $hourly_rate = $daily_rate / 8;
        
        // Get attendance for period
        $stmt = $this->conn->prepare("
            SELECT 
                COUNT(*) as total_days,
                SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_days,
                SUM(CASE WHEN status = 'Late' THEN 1 ELSE 0 END) as late_days,
                SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absent_days,
                SUM(CASE WHEN status = 'Half-day' THEN 0.5 ELSE 0 END) as half_days,
                SUM(CASE WHEN status = 'On-Leave' AND l.leave_with_pay = 'with_pay' THEN 1 ELSE 0 END) as leave_with_pay,
                SUM(CASE WHEN status = 'On-Leave' AND l.leave_with_pay = 'without_pay' THEN 1 ELSE 0 END) as leave_without_pay
            FROM attendance a
            LEFT JOIN leaves l ON a.employee_id = l.employee_id 
                AND a.date BETWEEN l.start_date AND l.end_date
            WHERE a.employee_id = ?
            AND a.date BETWEEN ? AND ?
        ");
        $stmt->bind_param("iss", $employee_id, $period_start, $period_end);
        $stmt->execute();
        $attendance = $stmt->get_result()->fetch_assoc();
        
        // Calculate basic pay
        $basic_pay = $attendance['present_days'] * $daily_rate;
        $basic_pay += $attendance['half_days'] * $daily_rate;
        $basic_pay += $attendance['leave_with_pay'] * $daily_rate;
        
        // Calculate tardiness deduction
        $tardiness_deduction = $attendance['late_days'] * ($hourly_rate * 0.5); // 30 mins late = half hour deduction
        
        // Calculate absences deduction
        $absences_deduction = $attendance['absent_days'] * $daily_rate;
        $absences_deduction += $attendance['leave_without_pay'] * $daily_rate;
        
        // Get overtime for period
        $stmt = $this->conn->prepare("
            SELECT 
                SUM(CASE 
                    WHEN overtime_type = 'regular' THEN total_hours * 1.25
                    WHEN overtime_type = 'rest_day' THEN total_hours * 1.30
                    WHEN overtime_type = 'special_holiday' THEN total_hours * 1.50
                    WHEN overtime_type = 'regular_holiday' THEN total_hours * 2.00
                    WHEN overtime_type = 'double_holiday' THEN total_hours * 3.00
                    ELSE total_hours
                END) as overtime_pay
            FROM overtime_requests
            WHERE employee_id = ?
            AND overtime_date BETWEEN ? AND ?
            AND status = 'approved'
        ");
        $stmt->bind_param("iss", $employee_id, $period_start, $period_end);
        $stmt->execute();
        $overtime = $stmt->get_result()->fetch_assoc();
        $overtime_pay = $overtime['overtime_pay'] * $hourly_rate ?? 0;
        
        // Get holidays
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as holiday_days
            FROM holidays
            WHERE holiday_date BETWEEN ? AND ?
            AND holiday_type IN ('regular', 'special_non_working')
        ");
        $stmt->bind_param("ss", $period_start, $period_end);
        $stmt->execute();
        $holidays = $stmt->get_result()->fetch_assoc();
        $holiday_pay = $holidays['holiday_days'] * $daily_rate * 2;
        
        // Get allowances and bonuses from payroll_adjustments
        $stmt = $this->conn->prepare("
            SELECT 
                SUM(CASE WHEN adjustment_type = 'addition' THEN amount ELSE 0 END) as total_additions,
                SUM(CASE WHEN adjustment_type = 'deduction' THEN amount ELSE 0 END) as total_deductions
            FROM payroll_adjustments
            WHERE employee_id = ?
            AND effective_date <= ?
            AND (end_date IS NULL OR end_date >= ?)
            AND status = 'active'
        ");
        $stmt->bind_param("iss", $employee_id, $period_end, $period_end);
        $stmt->execute();
        $adjustments = $stmt->get_result()->fetch_assoc();
        
        // Calculate gross pay
        $gross_pay = $basic_pay + $overtime_pay + $holiday_pay + ($adjustments['total_additions'] ?? 0);
        
        // Calculate government contributions
        $sss = $this->calculateSSS($employee['basic_salary']);
        $philhealth = $this->calculatePhilHealth($employee['basic_salary']);
        $pagibig = $this->calculatePagIBIG($employee['basic_salary']);
        
        // Calculate withholding tax
        $taxable_income = $gross_pay - ($sss + $philhealth + $pagibig);
        $withholding_tax = $this->calculateWithholdingTax($taxable_income);
        
        // Calculate total deductions
        $total_deductions = $sss + $philhealth + $pagibig + $withholding_tax + 
                           ($adjustments['total_deductions'] ?? 0) + 
                           $tardiness_deduction + $absences_deduction;
        
        // Calculate net pay
        $net_pay = $gross_pay - $total_deductions;
        
        return [
            'basic_salary' => $employee['basic_salary'],
            'basic_pay' => $basic_pay,
            'overtime_pay' => $overtime_pay,
            'holiday_pay' => $holiday_pay,
            'allowances' => $adjustments['total_additions'] ?? 0,
            'gross_pay' => $gross_pay,
            'sss' => $sss,
            'philhealth' => $philhealth,
            'pagibig' => $pagibig,
            'withholding_tax' => $withholding_tax,
            'tardiness' => $tardiness_deduction,
            'absences' => $absences_deduction,
            'other_deductions' => $adjustments['total_deductions'] ?? 0,
            'total_deductions' => $total_deductions,
            'net_pay' => $net_pay
        ];
    }
    
    private function calculateSSS($salary) {
        $stmt = $this->conn->prepare("
            SELECT employee_share
            FROM sss_contributions
            WHERE ? BETWEEN compensation_range_from AND compensation_range_to
            AND status = 'active'
            ORDER BY effective_date DESC
            LIMIT 1
        ");
        $stmt->bind_param("d", $salary);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return $result['employee_share'] ?? 1350; // Default if not found
    }
    
    private function calculatePhilHealth($salary) {
        $stmt = $this->conn->prepare("
            SELECT employee_share
            FROM philhealth_contributions
            WHERE ? BETWEEN basic_salary_from AND basic_salary_to
            AND status = 'active'
            ORDER BY effective_date DESC
            LIMIT 1
        ");
        $stmt->bind_param("d", $salary);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return $result['employee_share'] ?? 800; // Default if not found
    }
    
    private function calculatePagIBIG($salary) {
        $stmt = $this->conn->prepare("
            SELECT employee_share
            FROM pagibig_contributions
            WHERE ? BETWEEN basic_salary_from AND basic_salary_to
            AND status = 'active'
            ORDER BY effective_date DESC
            LIMIT 1
        ");
        $stmt->bind_param("d", $salary);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return $result['employee_share'] ?? 100; // Default if not found
    }
    
    private function calculateWithholdingTax($taxable_income) {
        $stmt = $this->conn->prepare("
            SELECT base_tax, tax_rate
            FROM tax_brackets
            WHERE ? BETWEEN salary_range_from AND salary_range_to
            AND status = 'active'
            AND effective_year = YEAR(CURDATE())
            ORDER BY salary_range_from
            LIMIT 1
        ");
        $stmt->bind_param("d", $taxable_income);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if ($result) {
            $excess = $taxable_income - $this->getLowerBracket($taxable_income);
            return $result['base_tax'] + ($excess * $result['tax_rate']);
        }
        
        return 0;
    }
    
    private function getLowerBracket($taxable_income) {
        $stmt = $this->conn->prepare("
            SELECT MAX(salary_range_from) as lower_bracket
            FROM tax_brackets
            WHERE salary_range_from <= ?
            AND status = 'active'
            AND effective_year = YEAR(CURDATE())
        ");
        $stmt->bind_param("d", $taxable_income);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return $result['lower_bracket'] ?? 0;
    }
}
?>