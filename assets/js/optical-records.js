// Optical Records Module
const OpticalRecords = {
    render(container) {
        container.innerHTML = `
            <div class="page-header d-flex justify-content-between align-items-start mb-4">
                <div>
                    <h1 class="page-title">Optical Records</h1>
                    <p class="page-subtitle">Eye examination and prescription records</p>
                </div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newExamModal">
                    <i class="bi bi-plus-circle me-2"></i>New Eye Examination
                </button>
            </div>

            <div class="card table-card">
                <div class="card-header">
                    <h5 class="mb-0">Eye Examination Records</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Record ID</th>
                                    <th>Patient</th>
                                    <th>Date</th>
                                    <th>OD (SPH/CYL)</th>
                                    <th>OS (SPH/CYL)</th>
                                    <th>PD</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${this.renderRecords()}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;
    },
    
    renderRecords() {
        const records = [
            { id: 'REC-001', patient: 'Maria Santos', patientId: 'P-001', date: '2026-01-02', odSph: '-2.00', odCyl: '-0.50', osSph: '-2.25', osCyl: '-0.75', pd: '62' },
            { id: 'REC-002', patient: 'Juan Dela Cruz', patientId: 'P-002', date: '2025-12-28', odSph: '-1.50', odCyl: '-0.25', osSph: '-1.75', osCyl: '-0.50', pd: '64' },
            { id: 'REC-003', patient: 'Anna Reyes', patientId: 'P-003', date: '2025-12-15', odSph: '-3.00', odCyl: '-1.00', osSph: '-3.25', osCyl: '-1.25', pd: '60' }
        ];
        
        return records.map(rec => `
            <tr>
                <td>${rec.id}</td>
                <td>
                    <div>${rec.patient}</div>
                    <div class="text-muted small">${rec.patientId}</div>
                </td>
                <td>${rec.date}</td>
                <td>
                    <div class="small">SPH: ${rec.odSph}</div>
                    <div class="small">CYL: ${rec.odCyl}</div>
                </td>
                <td>
                    <div class="small">SPH: ${rec.osSph}</div>
                    <div class="small">CYL: ${rec.osCyl}</div>
                </td>
                <td>${rec.pd} mm</td>
                <td>
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#viewRecordModal">
                        <i class="bi bi-eye"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-file-text"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-success">
                        <i class="bi bi-download"></i>
                    </button>
                </td>
            </tr>
        `).join('');
    }
};
