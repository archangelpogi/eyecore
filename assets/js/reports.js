// reports.js
let revenueChart = null;
let cityRevenueChart = null;
let clinicStatusChart = null;
const COLORS = ['#0d9488', '#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ef4444', '#ec4899', '#14b8a6'];

async function loadReportData() {
    try {
        // Get current filter values from URL parameters
        const urlParams = new URLSearchParams(window.location.search);
        const range = urlParams.get('range') || '6months';
        const city = urlParams.get('city') || 'all';
        const status = urlParams.get('status') || 'all';
        
        // Build API URL with parameters
        let apiUrl = `api/get_reports_data.php?range=${range}`;
        if (city !== 'all') apiUrl += `&city=${encodeURIComponent(city)}`;
        if (status !== 'all') apiUrl += `&status=${encodeURIComponent(status)}`;
        
        const response = await fetch(apiUrl);
        const data = await response.json();
        
        if (data.success) {
            updateSummaryCards(data.summary);
            updateCharts(data.charts);
            updateTables(data.data);
            updateSystemMetrics(data.summary);
        } else {
            showError('Failed to load report data');
        }
    } catch (error) {
        console.error('Error loading report data:', error);
        showError('Network error. Please try again.');
    }
}

function updateSummaryCards(summary) {
    const cardsContainer = document.getElementById('summaryCards');
    const revenue = summary.totalRevenue || 0;
    const growth = summary.revenueGrowth || 0;
    const avgPerClinic = summary.avgRevenuePerClinic || 0;
    const collectionRate = summary.collectionRate || 0;
    
    cardsContainer.innerHTML = `
        <div class="col-md-3">
            <div class="card-soft p-4 d-flex justify-content-between">
                <div>
                    <div class="text-sm text-muted">Total System Revenue</div>
                    <div class="revenue-display mt-2">₱${formatCurrency(revenue / 1000)}K</div>
                    <div class="text-xs ${growth >= 0 ? 'text-success' : 'text-danger'} mt-2 d-flex align-items-center gap-1">
                        <i data-lucide="${growth >= 0 ? 'trending-up' : 'trending-down'}" style="width:12px;height:12px"></i>
                        ${growth >= 0 ? '+' : ''}${growth}% vs last period
                    </div>
                </div>
                <div class="icon-box bg-teal-100">
                    <i data-lucide="banknote" class="text-teal-600"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-soft p-4 d-flex justify-content-between">
                <div>
                    <div class="text-sm text-muted">Active Clinics</div>
                    <div class="h4 fw-bold mt-2">${formatNumber(summary.totalActiveClinics)}</div>
                    <div class="text-xs text-muted mt-1">
                        ${summary.citiesCovered} cities covered
                    </div>
                </div>
                <div class="icon-box bg-blue-100">
                    <i data-lucide="building-2" class="text-blue-600"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-soft p-4 d-flex justify-content-between">
                <div>
                    <div class="text-sm text-muted">Avg per Clinic</div>
                    <div class="h4 fw-bold mt-2">₱${formatNumber(avgPerClinic, 2)}</div>
                    <div class="text-xs text-success mt-2 d-flex align-items-center gap-1">
                        <i data-lucide="dollar-sign" style="width:12px;height:12px"></i>
                        Revenue efficiency
                    </div>
                </div>
                <div class="icon-box bg-green-100">
                    <i data-lucide="pie-chart" class="text-green-600"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-soft p-4 d-flex justify-content-between">
                <div>
                    <div class="text-sm text-muted">Collection Rate</div>
                    <div class="h4 fw-bold mt-2">${collectionRate}%</div>
                    <div class="text-xs text-muted mt-1">
                        ₱${formatNumber(summary.pendingCollection)} pending
                    </div>
                </div>
                <div class="icon-box bg-purple-100">
                    <i data-lucide="credit-card" class="text-purple-600"></i>
                </div>
            </div>
        </div>
    `;
    
    // Re-initialize Lucide icons in the new content
    lucide.createIcons();
}

function updateCharts(charts) {
    // Monthly Revenue Chart
    const monthlyData = charts.monthlyRevenue || [];
    const revenueCtx = document.getElementById('revenueChart');
    
    if (revenueChart) {
        revenueChart.destroy();
    }
    
    revenueChart = new Chart(revenueCtx, {
        type: 'line',
        data: {
            labels: monthlyData.map(d => d.month),
            datasets: [{
                label: 'Total Revenue (₱)',
                data: monthlyData.map(d => d.revenue || 0),
                borderColor: '#0d9488',
                backgroundColor: 'rgba(13, 148, 136, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.3,
                pointBackgroundColor: '#0d9488',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 5
            }]
        },
        options: getChartOptions('Revenue (₱)')
    });

    // City Revenue Chart
    const cityData = charts.cityRevenue || [];
    const cityCtx = document.getElementById('cityRevenueChart');
    
    if (cityRevenueChart) {
        cityRevenueChart.destroy();
    }
    
    cityRevenueChart = new Chart(cityCtx, {
        type: 'bar',
        data: {
            labels: cityData.map(d => d.city),
            datasets: [{
                label: 'Revenue (₱)',
                data: cityData.map(d => d.revenue || 0),
                backgroundColor: COLORS,
                borderColor: COLORS.map(color => color.replace('0.6', '1')),
                borderWidth: 1,
                borderRadius: 6
            }]
        },
        options: getChartOptions('Revenue (₱)', 'bar')
    });

    // Clinic Status Chart
    const clinicStatusData = charts.clinicStatus || [];
    const statusCtx = document.getElementById('clinicStatusChart');
    
    if (clinicStatusChart) {
        clinicStatusChart.destroy();
    }
    
    clinicStatusChart = new Chart(statusCtx, {
        type: 'doughnut',
        data: {
            labels: clinicStatusData.map(d => d.status),
            datasets: [{
                data: clinicStatusData.map(d => d.count || 0),
                backgroundColor: clinicStatusData.map((_, i) => COLORS[i % COLORS.length]),
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        boxWidth: 12,
                        padding: 15,
                        font: {
                            size: 12
                        }
                    }
                }
            }
        }
    });
}

function updateTables(data) {
    // Top Clinics Table
    const topClinics = data.topClinics || [];
    const topClinicsTable = document.getElementById('topClinicsTable');
    
    if (topClinics.length > 0) {
        let html = `
            <table class="table table-hover">
                <thead class="bg-light">
                    <tr>
                        <th class="text-muted">Clinic</th>
                        <th class="text-muted">City</th>
                        <th class="text-muted">Revenue</th>
                        <th class="text-muted">Share</th>
                    </tr>
                </thead>
                <tbody>
        `;
        
        topClinics.forEach((clinic, index) => {
            const revenueShare = clinic.revenue_share || 0;
            html += `
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="text-primary fw-medium">#${index + 1}</div>
                            <div class="fw-medium">${escapeHtml(clinic.clinic_name)}</div>
                            <span class="badge ${clinic.clinic_type === 'standalone' ? 'bg-info' : 'bg-secondary'}">
                                ${clinic.clinic_type}
                            </span>
                        </div>
                    </td>
                    <td>
                        <span class="city-badge">${escapeHtml(clinic.city)}</span>
                    </td>
                    <td class="fw-semibold text-success">₱${formatNumber(clinic.revenue || 0, 2)}</td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="performance-bar" style="width: 100px;">
                                <div class="performance-fill" 
                                     style="width:${Math.min(revenueShare * 2, 100)}%;
                                            background:${COLORS[index % COLORS.length]}"></div>
                            </div>
                            <span class="text-muted small">${revenueShare}%</span>
                        </div>
                    </td>
                </tr>
            `;
        });
        
        html += '</tbody></table>';
        topClinicsTable.innerHTML = html;
    } else {
        topClinicsTable.innerHTML = `
            <div class="text-center text-muted py-4">
                <i data-lucide="alert-circle" class="mb-2" style="width:24px;height:24px"></i>
                <p class="mb-0">No revenue data available for the selected period</p>
            </div>
        `;
    }

    // Clinic Status Cards
    const clinicStatus = data.clinicStatus || [];
    const clinicStatusCards = document.getElementById('clinicStatusCards');
    
    if (clinicStatus.length > 0) {
        let html = '';
        clinicStatus.forEach(status => {
            html += `
                <div class="col-6">
                    <div class="p-3 rounded-3 border">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-medium">${status.status}</span>
                            <span class="h5 fw-bold mb-0">${status.count}</span>
                        </div>
                        ${status.new_this_period > 0 ? `
                            <div class="text-xs text-success mt-1">
                                <i data-lucide="plus" style="width:10px;height:10px"></i>
                                +${status.new_this_period} new
                            </div>
                        ` : ''}
                    </div>
                </div>
            `;
        });
        clinicStatusCards.innerHTML = html;
    }

    // Clinic Type Table
    const clinicTypes = data.clinicTypes || [];
    const clinicTypeTable = document.getElementById('clinicTypeTable');
    
    if (clinicTypes.length > 0) {
        let html = `
            <table class="table table-hover">
                <thead class="bg-light">
                    <tr>
                        <th class="text-muted">Clinic Type</th>
                        <th class="text-muted">Number of Clinics</th>
                        <th class="text-muted">Total Revenue</th>
                        <th class="text-muted">Avg Revenue per Clinic</th>
                        <th class="text-muted">Total Patients Served</th>
                    </tr>
                </thead>
                <tbody>
        `;
        
        clinicTypes.forEach(type => {
            html += `
                <tr>
                    <td>
                        <span class="badge ${type.clinic_type === 'standalone' ? 'bg-info' : 'bg-secondary'}">
                            ${type.clinic_type.charAt(0).toUpperCase() + type.clinic_type.slice(1)}
                        </span>
                    </td>
                    <td class="fw-semibold">${type.clinic_count}</td>
                    <td class="text-success fw-bold">₱${formatNumber(type.total_revenue || 0, 2)}</td>
                    <td class="text-primary fw-medium">₱${formatNumber(type.avg_revenue_per_clinic || 0, 2)}</td>
                    <td>${formatNumber(type.total_patients || 0)}</td>
                </tr>
            `;
        });
        
        html += '</tbody></table>';
        clinicTypeTable.innerHTML = html;
    }

    // Re-initialize Lucide icons in new content
    lucide.createIcons();
}

function updateSystemMetrics(summary) {
    const systemMetrics = document.getElementById('systemMetrics');
    
    systemMetrics.innerHTML = `
        <div class="col-md-4">
            <div class="card-soft p-4">
                <div class="d-flex align-items-center gap-3">
                    <div class="icon-box bg-orange-100">
                        <i data-lucide="users" class="text-orange-600"></i>
                    </div>
                    <div>
                        <div class="text-muted small">System Staff</div>
                        <div class="h5 fw-bold mb-0">${formatNumber(summary.totalSystemStaff)}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card-soft p-4">
                <div class="d-flex align-items-center gap-3">
                    <div class="icon-box bg-red-100">
                        <i data-lucide="clock" class="text-red-600"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Pending Clinics</div>
                        <div class="h5 fw-bold mb-0">${formatNumber(summary.pendingClinics)}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card-soft p-4">
                <div class="d-flex align-items-center gap-3">
                    <div class="icon-box bg-indigo-100">
                        <i data-lucide="map" class="text-indigo-600"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Cities Covered</div>
                        <div class="h5 fw-bold mb-0">${formatNumber(summary.citiesCovered)}</div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Re-initialize Lucide icons
    lucide.createIcons();
}

function applyFilters() {
    const form = document.getElementById('filterForm');
    const city = form.city.value;
    const status = form.status.value;
    const range = form.range.value;
    
    // Build the correct URL with page parameter
    let url = 'index.php?page=reports';
    
    if (city !== 'all') url += `&city=${encodeURIComponent(city)}`;
    if (status !== 'all') url += `&status=${encodeURIComponent(status)}`;
    if (range !== '6months') url += `&range=${encodeURIComponent(range)}`;
    
    // Show loading state
    showLoading();
    
    // Reload the page with new filters
    window.location.href = url;
}

function showLoading() {
    const summaryCards = document.getElementById('summaryCards');
    const topClinicsTable = document.getElementById('topClinicsTable');
    const clinicTypeTable = document.getElementById('clinicTypeTable');
    const systemMetrics = document.getElementById('systemMetrics');
    
    summaryCards.innerHTML = `
        <div class="col-12 text-center py-5">
            <div class="loading-spinner mx-auto mb-3" style="width: 3rem; height: 3rem;"></div>
            <p class="text-muted">Loading system data...</p>
        </div>
    `;
    
    topClinicsTable.innerHTML = `
        <div class="text-center py-4">
            <div class="loading-spinner mx-auto mb-3"></div>
            <p class="text-muted">Loading clinic performance data...</p>
        </div>
    `;
    
    clinicTypeTable.innerHTML = `
        <div class="text-center py-4">
            <div class="loading-spinner mx-auto mb-3"></div>
            <p class="text-muted">Loading clinic type data...</p>
        </div>
    `;
    
    systemMetrics.innerHTML = '';
    
    // Clear charts
    if (revenueChart) revenueChart.destroy();
    if (cityRevenueChart) cityRevenueChart.destroy();
    if (clinicStatusChart) clinicStatusChart.destroy();
}

function getChartOptions(yAxisLabel, type = 'line') {
    return {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { 
                display: type === 'line',
                position: 'top'
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return '₱' + context.parsed.y.toLocaleString('en-PH', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        });
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        if (value >= 1000000) {
                            return '₱' + (value/1000000).toFixed(1) + 'M';
                        } else if (value >= 1000) {
                            return '₱' + (value/1000).toFixed(0) + 'K';
                        } else {
                            return '₱' + value;
                        }
                    }
                },
                grid: {
                    drawBorder: false
                }
            },
            x: {
                grid: {
                    display: false
                }
            }
        }
    };
}

function formatNumber(num, decimals = 0) {
    return parseFloat(num).toLocaleString('en-PH', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
}

function formatCurrency(num) {
    return parseFloat(num).toLocaleString('en-PH', {
        minimumFractionDigits: 1,
        maximumFractionDigits: 1
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showError(message) {
    Swal.fire({
        icon: 'error',
        title: 'Error',
        text: message,
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000
    });
}

// Export functions (placeholder implementations)
function exportPDF() {
    Swal.fire({
        title: 'Export PDF Report',
        text: 'This will generate a PDF report with all current data.',
        icon: 'info',
        showCancelButton: true,
        confirmButtonText: 'Generate PDF',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            // In a real app, this would call a PDF generation API
            Swal.fire({
                icon: 'success',
                title: 'PDF Generated',
                text: 'Your PDF report is being downloaded.',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000
            });
        }
    });
}

function exportExcel() {
    Swal.fire({
        title: 'Export Excel Report',
        text: 'This will generate an Excel spreadsheet with all current data.',
        icon: 'info',
        showCancelButton: true,
        confirmButtonText: 'Generate Excel',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            // In a real app, this would call an Excel generation API
            Swal.fire({
                icon: 'success',
                title: 'Excel Generated',
                text: 'Your Excel report is being downloaded.',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000
            });
        }
    });
}

function generateRevenueReport() {
    Swal.fire({
        title: 'Generate Revenue Report',
        text: 'This will create a detailed revenue analysis report.',
        icon: 'info',
        showCancelButton: true,
        confirmButtonText: 'Generate Report',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            // In a real app, this would generate a specialized report
            Swal.fire({
                icon: 'success',
                title: 'Report Generated',
                text: 'Revenue report has been generated successfully.',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000
            });
        }
    });
}

function generateClinicPerformanceReport() {
    Swal.fire({
        title: 'Generate Clinic Performance Report',
        text: 'This will create a detailed clinic performance analysis.',
        icon: 'info',
        showCancelButton: true,
        confirmButtonText: 'Generate Report',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            // In a real app, this would generate a specialized report
            Swal.fire({
                icon: 'success',
                title: 'Report Generated',
                text: 'Clinic performance report has been generated successfully.',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000
            });
        }
    });
}

function generateGrowthReport() {
    Swal.fire({
        title: 'Generate Growth Forecast Report',
        text: 'This will create a growth trends and forecast analysis.',
        icon: 'info',
        showCancelButton: true,
        confirmButtonText: 'Generate Report',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            // In a real app, this would generate a specialized report
            Swal.fire({
                icon: 'success',
                title: 'Report Generated',
                text: 'Growth forecast report has been generated successfully.',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000
            });
        }
    });
}

function generateCollectionReport() {
    Swal.fire({
        title: 'Generate Collection Report',
        text: 'This will create a payment collection analysis report.',
        icon: 'info',
        showCancelButton: true,
        confirmButtonText: 'Generate Report',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            // In a real app, this would generate a specialized report
            Swal.fire({
                icon: 'success',
                title: 'Report Generated',
                text: 'Collection report has been generated successfully.',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000
            });
        }
    });
}