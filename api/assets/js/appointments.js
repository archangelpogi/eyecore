let appointmentModal = new bootstrap.Modal(document.getElementById('appointmentModal'));
let saveBtn = document.getElementById('saveAppointmentBtn');
let saveBtnText = document.getElementById('saveBtnText');
let saveBtnSpinner = document.getElementById('saveBtnSpinner');

let editId = null;

// Open modal for adding
function openAppointmentModal() {
    editId = null;
    document.getElementById('appointmentForm').reset();
    document.getElementById('modalTitle').innerText = 'Schedule Appointment';
    saveBtnText.innerText = 'Save Appointment';
    appointmentModal.show();
}

// Save or update appointment
function saveAppointment() {
    saveBtn.classList.add('loading');
    saveBtnSpinner.classList.remove('d-none');

    let data = {
        id: editId,
        patient_name: document.getElementById('patientName').value,
        patient_id: document.getElementById('patientId').value,
        clinic_id: document.getElementById('clinicId').value,
        doctor: document.getElementById('doctor').value,
        appointment_date: document.getElementById('appointmentDate').value,
        appointment_time: document.getElementById('appointmentTime').value,
        service_type: document.getElementById('serviceType').value,
        status: document.getElementById('status').value,
        notes: document.getElementById('notes').value
    };

    // Validation
    if(!data.patient_name || !data.patient_id || !data.clinic_id || !data.doctor || !data.appointment_date || !data.appointment_time || !data.service_type) {
        Swal.fire('Error','Please fill all required fields','error');
        saveBtn.classList.remove('loading');
        saveBtnSpinner.classList.add('d-none');
        return;
    }

    let method = editId ? 'PUT' : 'POST';

    fetch('api/appointments.php', {
        method: method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(res => res.json())
    .then(res => {
        if(res.success) {
            Swal.fire('Success', res.message, 'success');
            appointmentModal.hide();
            loadAppointments();
            loadStats(); // refresh stats after CRUD
        } else {
            Swal.fire('Error', res.message || 'Something went wrong', 'error');
        }
    })
    .catch(err => {
        console.error(err);
        Swal.fire('Error', 'Network or server error', 'error');
    })
    .finally(() => {
        saveBtn.classList.remove('loading');
        saveBtnSpinner.classList.add('d-none');
    });
}

// Load appointments
function loadAppointments() {
    let search = document.getElementById('searchInput').value;
    let clinic = document.getElementById('clinicFilter').value;
    let status = document.getElementById('statusFilter').value;

    fetch(`api/appointments.php?search=${search}&clinic=${clinic}&status=${status}`)
    .then(res => res.json())
    .then(data => {
        let tbody = document.getElementById('appointmentsTableBody');
        if(data.length === 0) {
            tbody.innerHTML = `<tr><td colspan="8" class="text-center py-5">No appointments found</td></tr>`;
            return;
        }

        tbody.innerHTML = data.map(a => `
            <tr>
                <td>${a.id}</td>
                <td>${a.patient_name} (${a.patient_id})</td>
                <td class="d-none d-md-table-cell">${a.doctor}</td>
                <td>${a.appointment_date} ${a.appointment_time}</td>
                <td class="d-none d-lg-table-cell">${a.service_type}</td>
                <td>${a.clinic_name}</td>
                <td><span class="status-badge badge-${a.status}">${a.status.charAt(0).toUpperCase()+a.status.slice(1)}</span></td>
                <td>
                    <button class="btn btn-sm btn-primary me-1" onclick="editAppointment(${a.id})">Edit</button>
                    <button class="btn btn-sm btn-danger" onclick="deleteAppointment(${a.id})">Delete</button>
                </td>
            </tr>
        `).join('');
    });
}

// Edit
function editAppointment(id) {
    fetch(`api/appointments.php?search=${id}`)
    .then(res => res.json())
    .then(data => {
        let a = data.find(x => x.id == id);
        if(!a) return;

        editId = a.id;
        document.getElementById('modalTitle').innerText = 'Edit Appointment';
        saveBtnText.innerText = 'Update Appointment';
        document.getElementById('patientName').value = a.patient_name;
        document.getElementById('patientId').value = a.patient_id;
        document.getElementById('clinicId').value = a.clinic_id;
        document.getElementById('doctor').value = a.doctor;
        document.getElementById('appointmentDate').value = a.appointment_date;
        document.getElementById('appointmentTime').value = a.appointment_time;
        document.getElementById('serviceType').value = a.service_type;
        document.getElementById('status').value = a.status;
        document.getElementById('notes').value = a.notes;
        appointmentModal.show();
    });
}

// Delete
function deleteAppointment(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "This will permanently delete the appointment",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch(`api/appointments.php?id=${id}`, { method: 'DELETE' })
            .then(res => res.json())
            .then(res => {
                if(res.success) {
                    Swal.fire('Deleted!', res.message, 'success');
                    loadAppointments();
                    loadStats(); // refresh stats
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            });
        }
    });
}

// Load stats
function loadStats() {
    fetch('api/appointments.php?stats=1')
        .then(res => res.json())
        .then(data => {
            document.getElementById('totalToday').innerText = data.totalToday;
            document.getElementById('confirmedCount').innerText = data.confirmed;
            document.getElementById('pendingCount').innerText = data.pending;
            document.getElementById('completedCount').innerText = data.completed;
        });
}

// Initial load
loadAppointments();
loadStats();

// Optional: refresh stats every 30 seconds
setInterval(loadStats, 30000);
