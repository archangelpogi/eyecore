document.addEventListener('DOMContentLoaded', function () {
    if (window.lucide) lucide.replace();

    // Elements
    const autoBackupCheckbox = document.getElementById('autoBackup');
    const backupFreqSection = document.getElementById('backupFrequencySection');
    const saveBtn = document.getElementById('saveSettingsBtn');

    autoBackupCheckbox.addEventListener('change', function () {
        backupFreqSection.style.display = this.checked ? 'block' : 'none';
    });

    saveBtn.addEventListener('click', saveSettings);

    // Load settings from backend
    fetchSettings();
});

// ALERT FUNCTIONS
function showAlert(message, type = 'success') {
    const alertDiv = document.getElementById('alertMessage');
    const alertText = document.getElementById('alertText');
    alertDiv.classList.remove('d-none', 'alert-success', 'alert-danger', 'alert-warning');
    alertDiv.classList.add(`alert-${type}`);
    alertText.textContent = message;
    alertDiv.scrollIntoView({ behavior: 'smooth' });
}

// LOAD SETTINGS
function fetchSettings() {
    fetch('api/admin_settings.php?action=get_settings')
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const s = data.settings;
                document.getElementById('siteName').value = s.site_name;
                document.getElementById('timezone').value = s.timezone;
                document.getElementById('currency').value = s.currency;
                document.getElementById('language').value = s.language;

                document.getElementById('emailNotifications').checked = s.email_notifications == 1;
                document.getElementById('smsNotifications').checked = s.sms_notifications == 1;

                document.getElementById('autoBackup').checked = s.auto_backup == 1;
                document.getElementById('backupFrequency').value = s.backup_frequency;
                document.getElementById('backupFrequencySection').style.display = s.auto_backup == 1 ? 'block' : 'none';

                document.getElementById('maintenanceMode').checked = s.maintenance_mode == 1;
                document.getElementById('sessionTimeout').value = s.session_timeout;
                document.getElementById('passwordExpiry').value = s.password_expiry;

                selectTheme(s.theme || 'light');
            } else {
                showAlert(data.message, 'danger');
            }
        })
        .catch(err => showAlert('Failed to fetch settings: ' + err, 'danger'));
}

// SAVE SETTINGS
function saveSettings() {
    const settings = {
        site_name: document.getElementById('siteName').value,
        timezone: document.getElementById('timezone').value,
        currency: document.getElementById('currency').value,
        language: document.getElementById('language').value,
        email_notifications: document.getElementById('emailNotifications').checked ? 1 : 0,
        sms_notifications: document.getElementById('smsNotifications').checked ? 1 : 0,
        auto_backup: document.getElementById('autoBackup').checked ? 1 : 0,
        backup_frequency: document.getElementById('backupFrequency').value,
        maintenance_mode: document.getElementById('maintenanceMode').checked ? 1 : 0,
        session_timeout: parseInt(document.getElementById('sessionTimeout').value, 10),
        password_expiry: parseInt(document.getElementById('passwordExpiry').value, 10),
        theme: document.querySelector('input[name="theme"]:checked')?.value || 'light'
    };

    fetch('api/admin_settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'save_settings', settings })
    })
        .then(res => res.json())
        .then(data => {
            if (data.success) showAlert(data.message, 'success');
            else showAlert(data.message, 'danger');
        })
        .catch(err => showAlert('Failed to save settings: ' + err, 'danger'));
}

// THEME SELECTION
function selectTheme(theme) {
    document.querySelectorAll('.theme-card').forEach(card => card.classList.remove('active'));
    if (theme === 'light') document.getElementById('themeLight').parentElement.classList.add('active');
    else if (theme === 'dark') document.getElementById('themeDark').parentElement.classList.add('active');
    else if (theme === 'auto') document.getElementById('themeAuto').parentElement.classList.add('active');

    const input = document.getElementById(`theme${theme.charAt(0).toUpperCase() + theme.slice(1)}`);
    if (input) input.checked = true;
}

// BACKUP FUNCTIONS
function backupNow() {
    Swal.fire({
        title: 'Backup Database',
        text: 'Are you sure you want to backup the database now?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, backup now'
    }).then(result => {
        if (result.isConfirmed) {
            fetch('api/admin_settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'create_backup' })
            })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showAlert(data.message, 'success');
                        viewBackups();
                    } else showAlert(data.message, 'danger');
                })
                .catch(err => showAlert('Backup failed: ' + err, 'danger'));
        }
    });
}

function viewBackups() {
    fetch('api/admin_ettings.php?action=get_backups')
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const container = document.getElementById('backupsContent');
                container.innerHTML = '';

                data.backups.forEach(file => {
                    const sizeMB = (file.size / (1024 * 1024)).toFixed(2);
                    const date = new Date(file.modified * 1000).toLocaleString();

                    const div = document.createElement('div');
                    div.className = 'd-flex justify-content-between align-items-center p-2 border-bottom backup-file';
                    div.innerHTML = `
                        <div>
                            <strong>${file.name}</strong><br>
                            <small class="text-muted">${date} | ${sizeMB} MB</small>
                        </div>
                        <div>
                            <button class="btn btn-sm btn-outline-primary me-2" onclick="downloadBackup('${file.name}')">
                                <i data-lucide="download" style="width:16px;height:16px"></i> Download
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteBackup('${file.name}')">
                                <i data-lucide="trash-2" style="width:16px;height:16px"></i> Delete
                            </button>
                        </div>
                    `;
                    container.appendChild(div);
                });

                if (window.lucide) lucide.replace();
                const modal = new bootstrap.Modal(document.getElementById('backupsModal'));
                modal.show();
            } else showAlert(data.message, 'danger');
        })
        .catch(err => showAlert('Failed to fetch backups: ' + err, 'danger'));
}

function downloadBackup(filename) {
    window.location.href = `api/admin_settings.php?action=download_backup&filename=${encodeURIComponent(filename)}`;
}

function deleteBackup(filename) {
    Swal.fire({
        title: 'Delete Backup',
        text: `Are you sure you want to delete ${filename}?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete it'
    }).then(result => {
        if (result.isConfirmed) {
            fetch('api/admin_settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete_backup', filename })
            })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showAlert(data.message, 'success');
                        viewBackups();
                    } else showAlert(data.message, 'danger');
                })
                .catch(err => showAlert('Delete failed: ' + err, 'danger'));
        }
    });
}
