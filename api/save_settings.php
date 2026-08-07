<?php
require_once __DIR__ . '/../../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../superadmin_settings.php');
    exit();
}

// ─────────────────────────────────────────────
// 1. Get actual columns that exist in the table
//    (same logic as the settings page — keeps
//    both files in sync automatically)
// ─────────────────────────────────────────────
$skip_cols = ['id', 'created_by', 'updated_by', 'created_at', 'updated_at'];

try {
    $col_stmt   = $pdo->query("DESCRIBE system_settings");
    $db_columns = array_column($col_stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $db_columns = array_values(array_diff($db_columns, $skip_cols));
} catch (Exception $e) {
    error_log("Could not describe system_settings: " . $e->getMessage());
    header('Location: ../superadmin_settings.php?message=' . urlencode('Could not read settings table.') . '&type=danger');
    exit();
}

// ─────────────────────────────────────────────
// 2. Which columns are toggles (checkboxes) and
//    which are stored as decimal-but-edited-as-percent.
//    Keep this list in sync with $field_def in
//    superadmin_settings.php.
// ─────────────────────────────────────────────
$toggle_cols = [
    'sms_notifications',
    'auto_backup',
    'maintenance_mode',
    'pwd_senior_vat_exempt',
];

$percent_to_decimal_cols = [
    'vat_rate',
    'pwd_senior_discount',
];

// ─────────────────────────────────────────────
// 3. Build the update payload
//    - Toggles: unchecked checkboxes are NOT sent
//      by the browser at all, so any toggle column
//      not present in $_POST must be saved as 0.
//    - Percent fields (vat_rate, pwd_senior_discount):
//      user enters "12", we store "0.12".
//    - Everything else: taken as-is from $_POST.
// ─────────────────────────────────────────────
$update_data = [];

foreach ($db_columns as $col) {
    if (in_array($col, $toggle_cols)) {
        $update_data[$col] = isset($_POST[$col]) ? 1 : 0;
        continue;
    }

    if (!isset($_POST[$col])) {
        // Field wasn't submitted (shouldn't normally happen for non-toggles) — skip it
        continue;
    }

    $value = $_POST[$col];

    if (in_array($col, $percent_to_decimal_cols)) {
        // "12" -> 0.12 ; also guard against someone typing "0.12" directly
        $num = is_numeric($value) ? (float)$value : 0;
        if ($num > 1) {
            $num = $num / 100;
        }
        // Clamp to a sane 0–1 range
        $num = max(0, min(1, $num));
        $update_data[$col] = round($num, 4);
        continue;
    }

    $update_data[$col] = trim($value);
}

if (empty($update_data)) {
    header('Location: ../superadmin_settings.php?message=' . urlencode('Nothing to save.') . '&type=warning');
    exit();
}

// ─────────────────────────────────────────────
// 4. Sanity checks for the legally-mandated fields
//    (soft guardrails — still saved, but warn if
//    someone puts an unusual value)
// ─────────────────────────────────────────────
$warnings = [];
if (isset($update_data['vat_rate']) && $update_data['vat_rate'] != 0.12) {
    $warnings[] = 'VAT rate was changed away from the standard 12% — make sure this matches current Philippine tax law.';
}
if (isset($update_data['pwd_senior_discount']) && $update_data['pwd_senior_discount'] != 0.20) {
    $warnings[] = 'PWD/Senior discount was changed away from the mandated 20%.';
}
if (isset($update_data['pwd_senior_vat_exempt']) && $update_data['pwd_senior_vat_exempt'] == 0) {
    $warnings[] = 'PWD/Senior VAT exemption was turned OFF — this is legally required to stay ON.';
}

// ─────────────────────────────────────────────
// 5. Check if a settings row already exists
//    (this table is designed to hold a single
//    platform-wide row, per system_settings usage
//    elsewhere in the app)
// ─────────────────────────────────────────────
try {
    $existing = $pdo->query("SELECT id FROM system_settings ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // UPDATE existing row
        $set_parts = [];
        foreach ($update_data as $col => $val) {
            $set_parts[] = "`$col` = :$col";
        }
        $set_parts[] = "updated_by = :updated_by";
        $set_parts[] = "updated_at = NOW()";

        $sql = "UPDATE system_settings SET " . implode(', ', $set_parts) . " WHERE id = :id";
        $stmt = $pdo->prepare($sql);

        foreach ($update_data as $col => $val) {
            $stmt->bindValue(":$col", $val);
        }
        $stmt->bindValue(':updated_by', $_SESSION['user_id']);
        $stmt->bindValue(':id', $existing['id']);
        $stmt->execute();

    } else {
        // INSERT first-ever row
        $cols = array_keys($update_data);
        $cols[] = 'created_by';
        $cols[] = 'created_at';

        $placeholders = array_map(fn($c) => ":$c", array_keys($update_data));
        $placeholders[] = ':created_by';
        $placeholders[] = 'NOW()';

        $sql = "INSERT INTO system_settings (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $pdo->prepare($sql);

        foreach ($update_data as $col => $val) {
            $stmt->bindValue(":$col", $val);
        }
        $stmt->bindValue(':created_by', $_SESSION['user_id']);
        $stmt->execute();
    }

    $msg  = 'Settings saved successfully.';
    $type = 'success';
    if (!empty($warnings)) {
        $msg .= ' Note: ' . implode(' ', $warnings);
        $type = 'warning';
    }

    header('Location: ../superadmin_settings.php?message=' . urlencode($msg) . '&type=' . $type);
    exit();

} catch (Exception $e) {
    error_log("Error saving settings: " . $e->getMessage());
    header('Location: ../superadmin_settings.php?message=' . urlencode('Failed to save settings: ' . $e->getMessage()) . '&type=danger');
    exit();
}