// Sales & Billing Module
const Sales = {
    render(container) {
        container.innerHTML = `
            <div class="page-header d-flex justify-content-between align-items-start mb-4">
                <div>
                    <h1 class="page-title">Sales & Billing</h1>
                    <p class="page-subtitle">Manage transactions and invoices</p>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-primary"><i class="bi bi-download me-2"></i>Export</button>
                    <button class="btn btn-primary"><i class="bi bi-receipt me-2"></i>New Sale</button>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-6 col-lg-3">
                    <div class="stat-card">
                        <div class="text-muted small">Today's Sales</div>
                        <div class="stat-value">₱14,800</div>
                        <div class="text-muted small">5 transactions</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card">
                        <div class="text-muted small">Paid</div>
                        <div class="stat-value text-success">₱10,800</div>
                        <div class="text-muted small">fully paid invoices</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card">
                        <div class="text-muted small">Partial Payment</div>
                        <div class="stat-value text-warning">₱2,000</div>
                        <div class="text-muted small">pending balance</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card">
                        <div class="text-muted small">Unpaid</div>
                        <div class="stat-value text-danger">₱2,800</div>
                        <div class="text-muted small">outstanding amount</div>
                    </div>
                </div>
            </div>

            <div class="card table-card">
                <div class="card-header"><h5 class="mb-0">Recent Invoices</h5></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table">${this.renderInvoices()}</table>
                    </div>
                </div>
            </div>
        `;
    },
    renderInvoices() {
        return `<thead><tr><th>Invoice ID</th><th>Date</th><th>Patient</th><th>Items</th><th>Amount</th><th>Paid</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
            <tr><td>INV-001</td><td>2026-01-03</td><td>Maria Santos</td><td>Frame + Lenses</td><td>₱4,500</td><td>₱4,500</td><td><span class="badge bg-success">Paid</span></td><td><button class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></button></td></tr>
            <tr><td>INV-002</td><td>2026-01-03</td><td>Juan Dela Cruz</td><td>Progressive Lenses</td><td>₱3,200</td><td>₱2,000</td><td><span class="badge bg-warning">Partial</span></td><td><button class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></button></td></tr>
        </tbody>`;
    }
};
