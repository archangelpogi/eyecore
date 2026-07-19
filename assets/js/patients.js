document.addEventListener('DOMContentLoaded', () => {
    // ELEMENTS
    const patientsTableBody = document.getElementById('patientsTableBody');
    const totalPatients = document.getElementById('totalPatients');
    const activePatients = document.getElementById('activePatients');
    const totalVisits = document.getElementById('totalVisits');
    const newThisMonth = document.getElementById('newThisMonth');
    const searchInput = document.getElementById('searchInput');
    const clinicFilter = document.getElementById('clinicFilter');
    const statusFilter = document.getElementById('statusFilter');

    const patientModal = new bootstrap.Modal(document.getElementById('patientModal'));
    const patientForm = document.getElementById('patientForm');
    const savePatientBtn = document.getElementById('savePatientBtn');

    let patients = [];

    // FETCH PATIENTS
    const fetchPatients = async () => {
        const params = new URLSearchParams({
            search: searchInput.value,
            clinic_id: clinicFilter.value,
            status: statusFilter.value
        });
        try {
            const res = await fetch(`api/patients.php?${params}`);
            const data = await res.json();
            if (data.status === 'success') {
                patients = data.patients.map(p => ({
                    ...p,
                    phone: p.phone || '',
                    contact: p.contact || '',
                    email: p.email || '',
                    address: p.address || '',
                    total_visits: parseInt(p.total_visits || 0)
                }));
                renderTable();
                renderStats();
            } else {
                patientsTableBody.innerHTML = `<tr><td colspan="9" class="text-center py-5">${data.message}</td></tr>`;
            }
        } catch (err) {
            console.error(err);
            patientsTableBody.innerHTML = `<tr><td colspan="9" class="text-center py-5 text-danger">Failed to load patients</td></tr>`;
        }
    };

    // RENDER TABLE
    const renderTable = () => {
        if (!patients.length) {
            patientsTableBody.innerHTML = `<tr><td colspan="9" class="text-center py-5">No patients found</td></tr>`;
            return;
        }
        patientsTableBody.innerHTML = '';
        patients.forEach(p => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${p.patient_code}</td>
                <td>${p.first_name} ${p.last_name}</td>
                <td class="d-none d-md-table-cell">${p.age} yrs, ${p.gender}</td>
                <td class="d-none d-lg-table-cell">
                    ${p.phone}<br>${p.contact}<br>${p.email}
                </td>
                <td>${p.clinic_name}</td>
                <td class="d-none d-lg-table-cell">${p.updated_at || ''}</td>
                <td><span class="${p.status==='Active'?'badge-active':'badge-inactive'} px-2 py-1 rounded">${p.status}</span></td>
                <td class="text-center">
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-primary" onclick="editPatient(${p.id})">Edit</button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deletePatient(${p.id}, '${p.first_name} ${p.last_name}')">Delete</button>
                    </div>
                </td>
            `;
            patientsTableBody.appendChild(tr);
        });
    };

    // RENDER STATS
    const renderStats = () => {
        totalPatients.textContent = patients.length;
        activePatients.textContent = patients.filter(p => p.status === 'Active').length;
        totalVisits.textContent = patients.reduce((sum, p) => sum + (p.total_visits || 0), 0);
        newThisMonth.textContent = patients.filter(p => new Date(p.created_at).getMonth() === new Date().getMonth()).length;
    };

    // SHOW TOAST
    const showToast = (msg, icon='success') => {
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon,
            title: msg,
            showConfirmButton: false,
            timer: 2500,
            timerProgressBar: true
        });
    };

    // ADD / EDIT PATIENT
    savePatientBtn.addEventListener('click', async () => {
        const id = document.getElementById('patientId').value;
        const payload = {
            first_name: document.getElementById('firstName').value,
            last_name: document.getElementById('lastName').value,
            age: document.getElementById('age').value,
            gender: document.getElementById('gender').value,
            clinic_id: document.getElementById('clinicId').value,
            phone: document.getElementById('phone').value,
            contact: document.getElementById('contact').value,
            email: document.getElementById('email').value,
            address: document.getElementById('address').value,
            status: document.getElementById('status').value
        };
        let method = 'POST';
        if (id) { payload.id = id; method = 'PUT'; }

        try {
            const res = await fetch('api/patients.php', {
                method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data.status === 'success') {
                patientModal.hide();
                patientForm.reset();
                document.getElementById('modalTitle').textContent = 'Add New Patient';

                // Refresh table immediately
                fetchPatients();
                showToast(data.message, 'success');
            } else {
                showToast(data.message || 'Error', 'error');
            }
        } catch (err) {
            console.error(err);
            showToast('Something went wrong', 'error');
        }
    });

    // EDIT PATIENT
    window.editPatient = (id) => {
        const patient = patients.find(p => p.id == id);
        if (!patient) return;
        document.getElementById('modalTitle').textContent = 'Edit Patient';
        document.getElementById('patientId').value = patient.id;
        document.getElementById('firstName').value = patient.first_name;
        document.getElementById('lastName').value = patient.last_name;
        document.getElementById('age').value = patient.age;
        document.getElementById('gender').value = patient.gender;
        document.getElementById('clinicId').value = patient.clinic_id;
        document.getElementById('phone').value = patient.phone || '';
        document.getElementById('contact').value = patient.contact || '';
        document.getElementById('email').value = patient.email || '';
        document.getElementById('address').value = patient.address || '';
        document.getElementById('status').value = patient.status;

        patientModal.show();
    };

    // DELETE PATIENT
    window.deletePatient = async (id, name) => {
        const confirmed = await Swal.fire({
            title: `Delete ${name}?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, delete',
            cancelButtonText: 'Cancel',
            toast: true,
            position: 'top-end'
        });
        if (confirmed.isConfirmed) {
            try {
                const res = await fetch('api/patients.php', {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    fetchPatients();
                    showToast(data.message, 'success');
                } else showToast(data.message || 'Error', 'error');
            } catch (err) {
                console.error(err);
                showToast('Something went wrong', 'error');
            }
        }
    };

    // FILTERS
    searchInput.addEventListener('input', fetchPatients);
    clinicFilter.addEventListener('change', fetchPatients);
    statusFilter.addEventListener('change', fetchPatients);

    // INITIAL FETCH
    fetchPatients();
});
