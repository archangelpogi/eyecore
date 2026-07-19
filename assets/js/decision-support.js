// Decision Support System Module
const DecisionSupport = {
    render(container) {
        container.innerHTML = `
            <div class="page-header d-flex justify-content-between align-items-start mb-4">
                <div>
                    <h1 class="page-title">Decision Support System</h1>
                    <p class="page-subtitle">AI-powered insights and recommendations for patient care</p>
                </div>
                <button class="btn btn-outline-primary"><i class="bi bi-lightbulb me-2"></i>Generate New Insights</button>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="text-muted small">High Priority Cases</div>
                        <div class="stat-value text-danger">3</div>
                        <div class="text-muted small">require immediate attention</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="text-muted small">Active Recommendations</div>
                        <div class="stat-value">12</div>
                        <div class="text-muted small">pending suggestions</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card">
                        <div class="text-muted small">Risk Patients</div>
                        <div class="stat-value text-warning">7</div>
                        <div class="text-muted small">worsening vision trends</div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><h5 class="mb-0">Patient Risk Assessment</h5></div>
                <div class="card-body">${this.renderRiskAssessments()}</div>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="mb-0">Eye Grade Trend Analysis - Maria Santos (P-001)</h5></div>
                <div class="card-body">
                    <div class="chart-container"><canvas id="trendChart"></canvas></div>
                </div>
            </div>
        `;
        setTimeout(() => this.initChart(), 100);
    },
    renderRiskAssessments() {
        const cases = [
            { patient: 'Maria Santos', id: 'P-001', risk: 'High', issue: 'Progressive Myopia', recommendation: 'Consider orthokeratology or myopia control lenses' },
            { patient: 'Juan Dela Cruz', id: 'P-002', risk: 'Medium', issue: 'Presbyopia Onset', recommendation: 'Suggest progressive or bifocal lenses' }
        ];
        return cases.map(c => `
            <div class="border rounded p-3 mb-3">
                <div class="d-flex justify-content-between mb-2">
                    <div><h6>${c.patient}</h6><small class="text-muted">${c.id}</small></div>
                    <span class="badge bg-${c.risk === 'High' ? 'danger' : 'warning'}">${c.risk} Risk</span>
                </div>
                <div class="mb-2"><strong>Issue:</strong> ${c.issue}</div>
                <div class="alert alert-info mb-2"><i class="bi bi-lightbulb me-2"></i><strong>Recommendation:</strong> ${c.recommendation}</div>
                <div class="btn-group"><button class="btn btn-sm btn-primary">Apply</button><button class="btn btn-sm btn-outline-secondary">Dismiss</button></div>
            </div>
        `).join('');
    },
    initChart() {
        const ctx = document.getElementById('trendChart');
        if (ctx) {
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: ['2024-01', '2024-07', '2025-01', '2025-07', '2026-01'],
                    datasets: [
                        { label: 'Right Eye', data: [-1.50, -1.75, -2.00, -2.25, -2.50], borderColor: '#0891b2', tension: 0.4 },
                        { label: 'Left Eye', data: [-1.75, -2.00, -2.25, -2.50, -2.75], borderColor: '#f97316', tension: 0.4 }
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false }
            });
        }
    }
};
