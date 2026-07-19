class Dashboard {
    constructor() {
        this.apiBase = 'api/dashboard_api.php';
        this.clinicId = 'all';
        this.dateRange = '30';
        this.charts = {};
        
        this.init();
    }
    
    init() {
        // Load clinics dropdown first
        this.loadClinics().then(() => {
            // Load all dashboard data
            this.loadAllData();
        });
        
        // Setup event listeners
        this.setupEventListeners();
    }
    
    setupEventListeners() {
        // Clinic filter change
        document.getElementById('clinicFilter').addEventListener('change', (e) => {
            this.clinicId = e.target.value;
            this.loadAllData();
        });
        
        // Date filter change
        document.getElementById('dateFilter').addEventListener('change', (e) => {
            this.dateRange = e.target.value;
            this.loadAllData();
        });
    }
    
    async loadClinics() {
        try {
            const response = await fetch(`${this.apiBase}?action=get_clinics`);
            const clinics = await response.json();
            
            const clinicSelect = document.getElementById('clinicFilter');
            
            // Clear existing options except "All Clinics"
            while (clinicSelect.options.length > 1) {
                clinicSelect.remove(1);
            }
            
            // Add clinic options
            clinics.forEach(clinic => {
                if (!clinic.error) {
                    const option = document.createElement('option');
                    option.value = clinic.id;
                    option.textContent = clinic.clinic_name;
                    clinicSelect.appendChild(option);
                }
            });
        } catch (error) {
            console.error('Error loading clinics:', error);
        }
    }
    
    async loadAllData() {
        // Show loading states
        this.showLoadingStates();
        
        // Load data in parallel
        await Promise.all([
            this.loadKPIs(),
            this.loadPatientChart(),
            this.loadSalesChart(),
            this.loadClinicDistribution(),
            this.loadRecentActivity(),
            this.loadLowStockAlerts(),
            this.loadQuickStats()
        ]);
    }
    
    showLoadingStates() {
        // KPI cards
        document.querySelectorAll('.stat-value').forEach(el => {
            el.textContent = '0';
        });
        
        // Subtitles
        document.getElementById('patientSubtitle').textContent = 'Loading...';
        document.getElementById('appointmentSubtitle').textContent = 'Loading...';
        document.getElementById('revenueSubtitle').textContent = 'Loading...';
    }
    
    async loadKPIs() {
        try {
            const response = await fetch(
                `${this.apiBase}?action=get_kpis&clinic_id=${this.clinicId}&date_range=${this.dateRange}`
            );
            const kpis = await response.json();
            
            if (kpis.error) {
                console.error('KPI Error:', kpis.error);
                return;
            }
            
            // Update KPI values
            document.getElementById('totalClinics').textContent = this.formatNumber(kpis.total_clinics);
            document.getElementById('totalPatients').textContent = this.formatNumber(kpis.total_patients);
            document.getElementById('totalAppointments').textContent = this.formatNumber(kpis.total_appointments);
            document.getElementById('monthlyRevenue').textContent = this.formatCurrency(kpis.monthly_revenue);
            
            // Update subtitles
            document.getElementById('patientSubtitle').textContent = 
                `+${kpis.this_month_patients} this month`;
            document.getElementById('appointmentSubtitle').textContent = 
                `${kpis.completed_appointments} completed`;
            document.getElementById('revenueSubtitle').textContent = 
                `vs last month`;
            
            // Update trends
            document.getElementById('clinicTrend').innerHTML = 
                `<i class="bi bi-arrow-up"></i> ${kpis.clinic_trend}`;
            document.getElementById('patientTrend').innerHTML = 
                `<i class="bi bi-arrow-up"></i> ${kpis.patient_trend}`;
            document.getElementById('appointmentTrend').innerHTML = 
                `<i class="bi bi-arrow-up"></i> ${kpis.appointment_trend}`;
            document.getElementById('revenueTrend').innerHTML = 
                `<i class="bi bi-arrow-up"></i> ${kpis.revenue_trend}`;
                
        } catch (error) {
            console.error('Error loading KPIs:', error);
            this.showError('kpis');
        }
    }
    
    async loadPatientChart() {
        try {
            const response = await fetch(
                `${this.apiBase}?action=get_patient_chart&clinic_id=${this.clinicId}&date_range=${this.dateRange}`
            );
            const data = await response.json();
            
            if (data.error) {
                console.error('Patient Chart Error:', data.error);
                return;
            }
            
            this.renderPatientChart(data);
        } catch (error) {
            console.error('Error loading patient chart:', error);
        }
    }
    
    renderPatientChart(data) {
        const ctx = document.getElementById('patientChart');
        
        // Destroy existing chart
        if (this.charts.patient) {
            this.charts.patient.destroy();
        }
        
        this.charts.patient = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [{
                    label: 'Patient Growth',
                    data: data.data,
                    borderColor: '#0d9488',
                    backgroundColor: 'rgba(13, 148, 136, 0.1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#0d9488',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        },
                        ticks: {
                            precision: 0
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }
    
    async loadSalesChart() {
        try {
            const response = await fetch(
                `${this.apiBase}?action=get_sales_chart&clinic_id=${this.clinicId}&date_range=${this.dateRange}`
            );
            const data = await response.json();
            
            if (data.error) {
                console.error('Sales Chart Error:', data.error);
                return;
            }
            
            this.renderSalesChart(data);
        } catch (error) {
            console.error('Error loading sales chart:', error);
        }
    }
    
    renderSalesChart(data) {
        const ctx = document.getElementById('salesChart');
        
        // Destroy existing chart
        if (this.charts.sales) {
            this.charts.sales.destroy();
        }
        
        this.charts.sales = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [
                    {
                        label: 'Revenue (₱)',
                        data: data.revenue,
                        backgroundColor: 'rgba(245, 158, 11, 0.8)',
                        borderColor: 'rgba(245, 158, 11, 1)',
                        borderWidth: 1,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Sales Count',
                        data: data.sales,
                        backgroundColor: 'rgba(13, 148, 136, 0.8)',
                        borderColor: 'rgba(13, 148, 136, 1)',
                        borderWidth: 1,
                        yAxisID: 'y1',
                        type: 'line',
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top'
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Revenue (₱)'
                        },
                        ticks: {
                            callback: function(value) {
                                return '₱' + value.toLocaleString();
                            }
                        }
                    },
                    y1: {
                        type: 'linear',
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Sales Count'
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                }
            }
        });
    }
    
    async loadClinicDistribution() {
        try {
            const response = await fetch(
                `${this.apiBase}?action=get_clinic_distribution&clinic_id=${this.clinicId}`
            );
            const data = await response.json();
            
            if (data.error) {
                console.error('Clinic Distribution Error:', data.error);
                return;
            }
            
            this.renderClinicDistribution(data);
        } catch (error) {
            console.error('Error loading clinic distribution:', error);
        }
    }
    
    renderClinicDistribution(data) {
        const ctx = document.getElementById('clinicDistributionChart');
        
        // Destroy existing chart
        if (this.charts.clinicDistribution) {
            this.charts.clinicDistribution.destroy();
        }
        
        this.charts.clinicDistribution = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: [{
                    data: data.patients,
                    backgroundColor: [
                        '#0d9488',
                        '#3b82f6',
                        '#8b5cf6',
                        '#10b981',
                        '#f59e0b'
                    ],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                },
                cutout: '70%'
            }
        });
    }
    
    async loadRecentActivity() {
        try {
            const response = await fetch(
                `${this.apiBase}?action=get_recent_activity&clinic_id=${this.clinicId}`
            );
            const activities = await response.json();
            
            if (activities.error) {
                console.error('Activity Error:', activities.error);
                return;
            }
            
            this.renderRecentActivity(activities);
        } catch (error) {
            console.error('Error loading recent activity:', error);
        }
    }
    
    renderRecentActivity(activities) {
        const container = document.getElementById('recentActivity');
        
        if (!activities || activities.length === 0) {
            container.innerHTML = `
                <div class="text-center py-5">
                    <i class="bi bi-activity fs-4 text-muted mb-3"></i>
                    <p class="text-muted">No recent activities found</p>
                </div>
            `;
            return;
        }
        
        let html = '';
        
        activities.forEach(activity => {
            // Format time
            const time = new Date(activity.created_at);
            const timeAgo = this.formatTimeAgo(time);
            
            // Get icon based on action
            let icon = 'activity';
            let bgColor = 'primary';
            
            if (activity.action.includes('Login')) {
                icon = 'box-arrow-in-right';
                bgColor = 'info';
            } else if (activity.action.includes('Add') || activity.action.includes('Create')) {
                icon = 'plus-circle';
                bgColor = 'success';
            } else if (activity.action.includes('Update') || activity.action.includes('Edit')) {
                icon = 'pencil-square';
                bgColor = 'warning';
            } else if (activity.action.includes('Delete')) {
                icon = 'trash';
                bgColor = 'danger';
            }
            
            html += `
                <div class="d-flex gap-3 border-bottom pb-3 mb-3">
                    <div class="activity-icon bg-${bgColor} text-white">
                        <i class="bi bi-${icon}"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="fw-medium">${activity.user_name || 'System'}</div>
                        <div class="text-muted small mb-1">${activity.action} • ${activity.module || 'System'}</div>
                        ${activity.details ? `<div class="text-muted extra-small">${activity.details}</div>` : ''}
                        <small class="text-muted">${timeAgo}</small>
                    </div>
                </div>
            `;
        });
        
        container.innerHTML = html;
    }
    
    async loadLowStockAlerts() {
        try {
            const response = await fetch(
                `${this.apiBase}?action=get_low_stock&clinic_id=${this.clinicId}`
            );
            const items = await response.json();
            
            if (items.error) {
                console.error('Low Stock Error:', items.error);
                return;
            }
            
            this.renderLowStockAlerts(items);
        } catch (error) {
            console.error('Error loading low stock alerts:', error);
        }
    }
    
    renderLowStockAlerts(items) {
        const container = document.getElementById('lowStockAlerts');
        
        if (!items || items.length === 0) {
            container.innerHTML = `
                <div class="text-center py-5">
                    <i class="bi bi-check-circle fs-4 text-success mb-3"></i>
                    <p class="text-muted">All items in stock</p>
                </div>
            `;
            return;
        }
        
        let html = '';
        
        items.forEach(item => {
            const stockPercent = Math.round((item.stock / item.reorder_level) * 100);
            let stockClass = 'danger';
            
            if (stockPercent > 50) stockClass = 'warning';
            if (stockPercent > 80) stockClass = 'success';
            
            html += `
                <div class="d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom">
                    <div>
                        <div class="fw-medium">${item.name}</div>
                        <div class="text-muted small">${item.category} • ${item.brand || 'No brand'}</div>
                    </div>
                    <div class="text-end">
                        <span class="badge bg-${stockClass}">${item.stock} left</span>
                        <div class="text-muted small mt-1">₱${this.formatNumber(item.price)}</div>
                    </div>
                </div>
            `;
        });
        
        container.innerHTML = html;
    }
    
    async loadQuickStats() {
        try {
            const response = await fetch(
                `${this.apiBase}?action=get_quick_stats&clinic_id=${this.clinicId}`
            );
            const stats = await response.json();
            
            if (stats.error) {
                console.error('Quick Stats Error:', stats.error);
                return;
            }
            
            document.getElementById('activeUsers').textContent = this.formatNumber(stats.active_users);
            document.getElementById('todayRevenue').textContent = this.formatCurrency(stats.today_revenue);
            document.getElementById('pendingAppointments').textContent = this.formatNumber(stats.pending_appointments);
        } catch (error) {
            console.error('Error loading quick stats:', error);
        }
    }
    
    formatNumber(num) {
        return new Intl.NumberFormat().format(num);
    }
    
    formatCurrency(amount) {
        return '₱' + this.formatNumber(amount);
    }
    
    formatTimeAgo(date) {
        const seconds = Math.floor((new Date() - date) / 1000);
        
        let interval = Math.floor(seconds / 31536000);
        if (interval >= 1) return interval + ' year' + (interval === 1 ? '' : 's') + ' ago';
        
        interval = Math.floor(seconds / 2592000);
        if (interval >= 1) return interval + ' month' + (interval === 1 ? '' : 's') + ' ago';
        
        interval = Math.floor(seconds / 86400);
        if (interval >= 1) return interval + ' day' + (interval === 1 ? '' : 's') + ' ago';
        
        interval = Math.floor(seconds / 3600);
        if (interval >= 1) return interval + ' hour' + (interval === 1 ? '' : 's') + ' ago';
        
        interval = Math.floor(seconds / 60);
        if (interval >= 1) return interval + ' minute' + (interval === 1 ? '' : 's') + ' ago';
        
        return 'just now';
    }
    
    showError(elementId) {
        // Implement error display if needed
        console.error(`Error loading ${elementId}`);
    }
}

// Initialize dashboard when page loads
document.addEventListener('DOMContentLoaded', () => {
    new Dashboard();
});