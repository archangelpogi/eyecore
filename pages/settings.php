<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id      = $_SESSION['user_id'];
$message      = '';
$message_type = '';

if (isset($_GET['message'])) {
    $message      = htmlspecialchars($_GET['message']);
    $message_type = $_GET['type'] ?? 'success';
}

// ─────────────────────────────────────────────
// 1. Get actual columns that exist in the table
// ─────────────────────────────────────────────
$skip_cols = ['id', 'created_by', 'updated_by', 'created_at', 'updated_at'];

try {
    $col_stmt   = $pdo->query("DESCRIBE system_settings");
    $db_columns = array_column($col_stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $db_columns = array_values(array_diff($db_columns, $skip_cols));
} catch (Exception $e) {
    error_log("Could not describe system_settings: " . $e->getMessage());
    $db_columns = [];
}

// ─────────────────────────────────────────────
// 2. Fetch current values
// ─────────────────────────────────────────────
$settings = [];
try {
    $row = $pdo->query("SELECT * FROM system_settings ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        foreach ($db_columns as $col) {
            $settings[$col] = $row[$col] ?? '';
        }
    }
} catch (Exception $e) {
    error_log("Error fetching settings: " . $e->getMessage());
}

// ─────────────────────────────────────────────
// 2b. NEW: Convert stored decimal rates (0.12) to
//     percent form (12) for display in the number inputs.
//     Saved back as decimal in api/save_settings.php.
// ─────────────────────────────────────────────
$percent_fields = ['vat_rate', 'pwd_senior_discount'];
foreach ($percent_fields as $pf) {
    if (isset($settings[$pf]) && $settings[$pf] !== '') {
        $settings[$pf] = round(((float)$settings[$pf]) * 100, 2);
    }
}

// ─────────────────────────────────────────────
// 3. Field definitions — only rendered if the
//    column actually exists in the DB table.
// ─────────────────────────────────────────────
$field_def = [
    // General
    'site_name'           => ['label' => 'Platform Name',           'type' => 'text',   'section' => 'General'],
    'timezone'            => ['label' => 'Timezone',                 'type' => 'select', 'section' => 'General',
                               'options' => [
                                   'Asia/Manila'    => 'Asia/Manila (GMT+8)',
                                   'Asia/Singapore' => 'Asia/Singapore (GMT+8)',
                                   'Asia/Tokyo'     => 'Asia/Tokyo (GMT+9)',
                                   'Asia/Hong_Kong' => 'Asia/Hong Kong (GMT+8)',
                                   'Asia/Shanghai'  => 'Asia/Shanghai (GMT+8)',
                                   'Asia/Seoul'     => 'Asia/Seoul (GMT+9)',
                               ]],
    'currency'            => ['label' => 'Currency',                 'type' => 'select', 'section' => 'General',
                               'options' => [
                                   'PHP' => 'Philippine Peso (₱)',
                                   'USD' => 'US Dollar ($)',
                                   'EUR' => 'Euro (€)',
                                   'GBP' => 'British Pound (£)',
                                   'JPY' => 'Japanese Yen (¥)',
                                   'SGD' => 'Singapore Dollar (S$)',
                               ]],
    'language'            => ['label' => 'Language',                 'type' => 'select', 'section' => 'General',
                               'options' => [
                                   'en' => 'English',
                                   'tl' => 'Tagalog',
                                   'es' => 'Spanish',
                                   'zh' => 'Chinese',
                                   'ja' => 'Japanese',
                                   'ko' => 'Korean',
                               ]],
    // Notifications
    'email_notifications' => ['label' => 'Notification Email',       'type' => 'email',  'section' => 'Notifications',
                               'hint' => 'Email address that receives platform notifications'],
    'sms_notifications'   => ['label' => 'SMS Notifications',        'type' => 'toggle', 'section' => 'Notifications',
                               'hint' => 'Receive alerts via SMS'],
    // Backup
    'auto_backup'         => ['label' => 'Automatic Backup',         'type' => 'toggle', 'section' => 'Backup',
                               'hint' => 'Enable scheduled database backups'],
    'backup_frequency'    => ['label' => 'Backup Frequency',         'type' => 'select', 'section' => 'Backup',
                               'options' => [
                                   'hourly'  => 'Every Hour',
                                   'daily'   => 'Daily',
                                   'weekly'  => 'Weekly',
                                   'monthly' => 'Monthly',
                               ]],
    // Security
    'maintenance_mode'    => ['label' => 'Maintenance Mode',         'type' => 'toggle', 'section' => 'Security',
                               'hint' => 'Temporarily disable access for all users'],
    'session_timeout'     => ['label' => 'Session Timeout (min)',    'type' => 'number', 'section' => 'Security',
                               'min' => 1, 'max' => 1440],
    'password_expiry'     => ['label' => 'Password Expiry (days)',   'type' => 'number', 'section' => 'Security',
                               'min' => 1, 'max' => 365],
    // Theme
    'theme'               => ['label' => 'Theme',                    'type' => 'theme',  'section' => 'Theme'],
    // Payment
    'gcash_name'          => ['label' => 'GCash Account Name',       'type' => 'text',   'section' => 'Payment'],
    'gcash_number'        => ['label' => 'GCash Number',             'type' => 'text',   'section' => 'Payment'],
    'maya_name'           => ['label' => 'Maya Account Name',        'type' => 'text',   'section' => 'Payment'],
    'maya_number'         => ['label' => 'Maya Number',              'type' => 'text',   'section' => 'Payment'],
    'bank_name'           => ['label' => 'Bank Name',                'type' => 'text',   'section' => 'Payment'],
    'bank_account_name'   => ['label' => 'Bank Account Name',        'type' => 'text',   'section' => 'Payment'],
    'bank_account_number' => ['label' => 'Bank Account Number',      'type' => 'text',   'section' => 'Payment'],

    // ============================================
    // NEW: Tax & Discounts (legally mandated, SuperAdmin-only, system-wide)
    // ============================================
    'vat_rate'              => ['label' => 'VAT Rate (%)', 'type' => 'number', 'section' => 'Tax & Discounts',
                                 'min' => 0, 'max' => 100, 'step' => '0.01',
                                 'hint' => 'Philippine VAT per the NIRC / TRAIN Law. Currently 12%. Change only if the law itself changes — this is not a per-clinic setting.'],
    'pwd_senior_discount'   => ['label' => 'PWD / Senior Citizen Discount (%)', 'type' => 'number', 'section' => 'Tax & Discounts',
                                 'min' => 0, 'max' => 100, 'step' => '0.01',
                                 'hint' => 'Mandated 20% discount per RA 9994 (Expanded Senior Citizens Act) and RA 10754 (Magna Carta for Persons with Disability).'],
    'pwd_senior_vat_exempt' => ['label' => 'PWD / Senior Citizen VAT Exemption', 'type' => 'toggle', 'section' => 'Tax & Discounts',
                                 'hint' => 'Legally required to stay ON. PWD and Senior Citizens are VAT-exempt by law — this should not be turned off.'],
];

// Section display meta
$section_meta = [
    'General'          => ['icon' => 'bi-globe',       'bg' => '#d1fae5', 'color' => '#065f46', 'desc' => 'Basic platform configuration'],
    'Notifications'    => ['icon' => 'bi-bell',         'bg' => '#dbeafe', 'color' => '#1e40af', 'desc' => 'Configure alert and notification preferences'],
    'Backup'           => ['icon' => 'bi-database',     'bg' => '#dcfce7', 'color' => '#166534', 'desc' => 'Automatic database backup configuration'],
    'Security'         => ['icon' => 'bi-shield-lock',  'bg' => '#ede9fe', 'color' => '#5b21b6', 'desc' => 'Platform security and access control'],
    'Theme'            => ['icon' => 'bi-palette',      'bg' => '#fce7f3', 'color' => '#9d174d', 'desc' => 'Customize platform appearance'],
    'Payment'          => ['icon' => 'bi-credit-card',  'bg' => '#fef9c3', 'color' => '#854d0e', 'desc' => 'Payment method details'],
    'Tax & Discounts'  => ['icon' => 'bi-receipt',      'bg' => '#fee2e2', 'color' => '#991b1b', 'desc' => 'Legally-mandated tax and discount rates. These apply platform-wide and are not configurable per clinic — do not change without a legal basis.'],
];

// ─────────────────────────────────────────────
// 4. Group DB-present columns into sections
//    Unknown columns → fallback to General
// ─────────────────────────────────────────────
$sections = [];
foreach ($db_columns as $col) {
    if (!isset($field_def[$col])) {
        // Unknown column: auto-generate a plain text definition
        $field_def[$col] = [
            'label'   => ucwords(str_replace('_', ' ', $col)),
            'type'    => 'text',
            'section' => 'General',
        ];
    }
    $sections[$field_def[$col]['section']][] = $col;
}

// Backups list
$backups = [];
try {
    $resp = @file_get_contents('api/admin_settings.php');
    $data = $resp ? json_decode($resp, true) : null;
    if (is_array($data) && !empty($data['success'])) {
        $backups = $data['backups'] ?? [];
    }
} catch (Exception $e) {
    error_log("Error fetching backups: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>System Settings</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
    body            { background:#f8fafc; font-family:'Segoe UI',sans-serif; }
    .card-soft      { background:#fff; border:1px solid #e5e7eb; border-radius:12px; }
    .section-icon   { width:46px; height:46px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0; }
    .gradient-btn   { background:linear-gradient(to right,#0d9488,#3b82f6); color:#fff; border:none; border-radius:8px; padding:10px 24px; }
    .gradient-btn:hover { transform:translateY(-1px); box-shadow:0 8px 20px rgba(13,148,136,.25); color:#fff; }
    .toggle-switch  { position:relative; display:inline-block; width:56px; height:28px; flex-shrink:0; }
    .toggle-slider  { position:absolute; cursor:pointer; inset:0; background:#ccc; transition:.3s; border-radius:34px; }
    .toggle-slider:before { position:absolute; content:""; height:20px; width:20px; left:4px; bottom:4px; background:#fff; transition:.3s; border-radius:50%; }
    input:checked + .toggle-slider           { background:#0d9488; }
    input:checked + .toggle-slider:before    { transform:translateX(28px); }
    .theme-card     { cursor:pointer; border:2px solid #e5e7eb; border-radius:8px; padding:20px; transition:all .25s; }
    .theme-card:hover { border-color:#9ca3af; }
    .theme-card.active { border-color:#0d9488; background:#f0fdfa; }
    .preview-box    { width:100%; height:80px; border-radius:6px; margin-bottom:10px; }
    .backup-file:hover { background:#f8fafc; }
    .legal-badge    { display:inline-block; background:#fee2e2; color:#991b1b; font-size:.7rem; font-weight:600; padding:2px 8px; border-radius:10px; margin-left:8px; vertical-align:middle; }
    </style>
</head>
<body>
<div class="container-fluid p-4">

    <!-- HEADER -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h2 class="fw-bold mb-1">System Settings</h2>
            <p class="text-muted mb-0">Platform-wide configuration and preferences</p>
        </div>
        <button type="button" class="btn gradient-btn d-flex align-items-center gap-2" onclick="saveSettings()">
            <i class="bi bi-save"></i> Save Changes
        </button>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $message_type ?> alert-dismissible fade show">
        <?= $message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if (empty($sections)): ?>
        <div class="alert alert-warning">No settings columns found in <code>system_settings</code> table.</div>
    <?php endif; ?>

    <form id="settingsForm" method="POST" action="api/save_settings.php">

    <?php foreach ($sections as $section_name => $cols):
        $meta = $section_meta[$section_name] ?? ['icon' => 'bi-gear', 'bg' => '#f3f4f6', 'color' => '#374151', 'desc' => ''];

        $toggle_cols  = array_values(array_filter($cols, fn($c) => ($field_def[$c]['type'] ?? '') === 'toggle'));
        $theme_cols   = array_values(array_filter($cols, fn($c) => ($field_def[$c]['type'] ?? '') === 'theme'));
        $regular_cols = array_values(array_filter($cols, fn($c) => !in_array($field_def[$c]['type'] ?? '', ['toggle', 'theme'])));

        $is_legal_section = ($section_name === 'Tax & Discounts');
    ?>

    <div class="card-soft p-4 mb-4">
        <!-- Section header -->
        <div class="d-flex align-items-center gap-3 mb-4">
            <div class="section-icon" style="background:<?= $meta['bg'] ?>; color:<?= $meta['color'] ?>;">
                <i class="<?= $meta['icon'] ?>"></i>
            </div>
            <div>
                <h5 class="fw-semibold mb-0">
                    <?= htmlspecialchars($section_name) ?> Settings
                    <?php if ($is_legal_section): ?>
                        <span class="legal-badge"><i class="bi bi-exclamation-triangle"></i> Legally mandated</span>
                    <?php endif; ?>
                </h5>
                <?php if ($meta['desc']): ?>
                <small class="text-muted"><?= htmlspecialchars($meta['desc']) ?></small>
                <?php endif; ?>
            </div>
        </div>

        <!-- Regular + select + number + email + text fields (2-col grid) -->
        <?php if ($regular_cols): ?>
        <div class="row g-3 mb-3">
            <?php foreach ($regular_cols as $col):
                $def   = $field_def[$col];
                $value = $settings[$col] ?? '';
            ?>
            <div class="col-md-6">
                <label class="form-label fw-medium"><?= htmlspecialchars($def['label']) ?></label>

                <?php if ($def['type'] === 'select'): ?>
                    <select name="<?= $col ?>" class="form-select">
                        <?php foreach ($def['options'] as $opt_val => $opt_label): ?>
                        <option value="<?= htmlspecialchars($opt_val) ?>" <?= $value == $opt_val ? 'selected' : '' ?>>
                            <?= htmlspecialchars($opt_label) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>

                <?php elseif ($def['type'] === 'number'): ?>
                    <div class="input-group">
                        <input type="number" name="<?= $col ?>" class="form-control"
                               value="<?= htmlspecialchars($value) ?>"
                               <?= isset($def['min']) ? 'min="'.(float)$def['min'].'"' : '' ?>
                               <?= isset($def['max']) ? 'max="'.(float)$def['max'].'"' : '' ?>
                               <?= isset($def['step']) ? 'step="'.htmlspecialchars($def['step']).'"' : '' ?>>
                        <?php if (in_array($col, ['vat_rate', 'pwd_senior_discount'])): ?>
                            <span class="input-group-text">%</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($def['hint'])): ?>
                    <div class="form-text"><?= htmlspecialchars($def['hint']) ?></div>
                    <?php endif; ?>

                <?php elseif ($def['type'] === 'email'): ?>
                    <input type="email" name="<?= $col ?>" class="form-control"
                           value="<?= htmlspecialchars($value) ?>">
                    <?php if (!empty($def['hint'])): ?>
                    <div class="form-text"><?= htmlspecialchars($def['hint']) ?></div>
                    <?php endif; ?>

                <?php else: /* text */ ?>
                    <input type="text" name="<?= $col ?>" class="form-control"
                           value="<?= htmlspecialchars($value) ?>">
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Toggle fields -->
        <?php foreach ($toggle_cols as $col):
            $def   = $field_def[$col];
            $value = $settings[$col] ?? 0;
        ?>
        <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded mb-2">
            <div>
                <p class="fw-medium mb-0"><?= htmlspecialchars($def['label']) ?></p>
                <?php if (!empty($def['hint'])): ?>
                <small class="text-muted"><?= htmlspecialchars($def['hint']) ?></small>
                <?php endif; ?>
            </div>
            <div class="toggle-switch">
                <input type="checkbox" name="<?= $col ?>" id="toggle_<?= $col ?>"
                       class="d-none" <?= $value ? 'checked' : '' ?>
                       <?= $col === 'auto_backup' ? 'onchange="toggleBackupFrequency()"' : '' ?>>
                <label for="toggle_<?= $col ?>" class="toggle-slider"></label>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Backup action buttons -->
        <?php if ($section_name === 'Backup'): ?>
        <div class="d-flex gap-2 mt-3">
            <button type="button" class="btn btn-success btn-sm d-flex align-items-center gap-1" onclick="backupNow()">
                <i class="bi bi-database"></i> Backup Now
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1" onclick="viewBackups()">
                <i class="bi bi-folder2-open"></i> View Backups
            </button>
        </div>
        <?php endif; ?>

        <!-- Theme picker -->
        <?php foreach ($theme_cols as $col):
            $value  = $settings[$col] ?? 'light';
            $themes = [
                'light' => ['label' => 'Light Theme', 'hint' => 'Default theme',    'style' => 'background:#ffffff; border:1px solid #e5e7eb;'],
                'dark'  => ['label' => 'Dark Theme',  'hint' => 'Eye-friendly',      'style' => 'background:#1f2937;'],
                'auto'  => ['label' => 'Auto Theme',  'hint' => 'System preference', 'style' => 'background:linear-gradient(to right,#ffffff,#1f2937);'],
            ];
        ?>
        <div class="row g-3">
            <?php foreach ($themes as $t_val => $t): ?>
            <div class="col-md-4">
                <div class="theme-card <?= $value === $t_val ? 'active' : '' ?>"
                     onclick="selectTheme(this, '<?= $col ?>', '<?= $t_val ?>')">
                    <div class="preview-box" style="<?= $t['style'] ?>"></div>
                    <p class="fw-medium mb-0"><?= $t['label'] ?></p>
                    <small class="text-muted"><?= $t['hint'] ?></small>
                    <input type="radio" name="<?= $col ?>" value="<?= $t_val ?>"
                           <?= $value === $t_val ? 'checked' : '' ?> class="d-none">
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>

        <?php if ($is_legal_section): ?>
        <div class="alert alert-danger mt-3 mb-0 d-flex align-items-start gap-2">
            <i class="bi bi-exclamation-triangle-fill mt-1"></i>
            <div>
                <strong>Note:</strong> These values are set by Philippine law (BIR/TRAIN Law for VAT; RA 9994 and RA 10754 for PWD/Senior discount), not by business preference.
                Changing them here changes the rate for <em>every</em> clinic on the platform. Only update these if the underlying law changes.
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /card-soft -->
    <?php endforeach; ?>

    </form>
</div>

<!-- BACKUPS MODAL -->
<div class="modal fade" id="backupsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Database Backups</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if (empty($backups)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-database display-4 d-block mb-2"></i>
                    No backups found
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead><tr><th>Filename</th><th>Size</th><th>Created</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($backups as $backup): ?>
                        <tr class="backup-file">
                            <td><i class="bi bi-file-earmark me-2 text-muted"></i><?= htmlspecialchars($backup['name']) ?></td>
                            <td><?= formatBytes($backup['size']) ?></td>
                            <td><?= date('Y-m-d H:i:s', $backup['modified']) ?></td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary"
                                        onclick="downloadBackup('<?= htmlspecialchars($backup['name']) ?>')">
                                    <i class="bi bi-download"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger ms-1"
                                        onclick="deleteBackup('<?= htmlspecialchars($backup['name']) ?>')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/superadmin_settings.js"></script>
<script>
function selectTheme(card, colName, val) {
    // Deactivate all cards in the same group
    card.closest('.row').querySelectorAll('.theme-card').forEach(c => c.classList.remove('active'));
    card.classList.add('active');
    card.querySelector('input[type="radio"]').checked = true;
}

function toggleBackupFrequency() {
    const cb  = document.getElementById('toggle_auto_backup');
    // Find the backup_frequency field row (if it exists)
    const sel = document.querySelector('[name="backup_frequency"]');
    if (sel) {
        sel.closest('.col-md-6').style.display = cb?.checked ? '' : 'none';
    }
}

// Hide backup_frequency on load if auto_backup is off
document.addEventListener('DOMContentLoaded', function() {
    const cb  = document.getElementById('toggle_auto_backup');
    const sel = document.querySelector('[name="backup_frequency"]');
    if (cb && sel) {
        sel.closest('.col-md-6').style.display = cb.checked ? '' : 'none';
    }
});

function viewBackups() {
    new bootstrap.Modal(document.getElementById('backupsModal')).show();
}
</script>
</body>
</html>

<?php
function formatBytes($bytes, $precision = 2) {
    $units = ['B','KB','MB','GB','TB'];
    $bytes = max($bytes, 0);
    $pow   = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow   = min($pow, count($units) - 1);
    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
}
?>