
// Navigation and Page Routing
const Navigation = {
    currentPage: 'dashboard',
    
    init() {
        this.setupEventListeners();
        this.loadPage('dashboard');
    },
    
    setupEventListeners() {
        // Sidebar navigation links
        document.querySelectorAll('.sidebar-nav .nav-link').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const page = link.getAttribute('data-page');
                this.loadPage(page);
                
                // Close sidebar on mobile
                if (window.innerWidth < 992) {
                    document.getElementById('sidebar').classList.remove('show');
                }
            });
        });
        
        // Toggle sidebar
        const toggleBtn = document.getElementById('toggleSidebar');
        const toggleMobileBtn = document.getElementById('toggleSidebarMobile');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.querySelector('.main-content');
        
        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => {
                if (window.innerWidth >= 992) {
                    sidebar.classList.toggle('collapsed');
                    mainContent.classList.toggle('expanded');
                } else {
                    sidebar.classList.toggle('show');
                }
            });
        }
        
        if (toggleMobileBtn) {
            toggleMobileBtn.addEventListener('click', () => {
                sidebar.classList.remove('show');
            });
        }
    },
    
    loadPage(page) {
        this.currentPage = page;
        
        // Update active nav link
        document.querySelectorAll('.sidebar-nav .nav-link').forEach(link => {
            link.classList.remove('active');
            if (link.getAttribute('data-page') === page) {
                link.classList.add('active');
            }
        });
        
        // Load page content
        const contentArea = document.getElementById('mainContent');
        
        switch(page) {
            case 'dashboard':
                Dashboard.render(contentArea);
                break;
            case 'patients':
                Patients.render(contentArea);
                break;
            case 'appointments':
                Appointments.render(contentArea);
                break;
            case 'optical-records':
                OpticalRecords.render(contentArea);
                break;
            case 'inventory':
                Inventory.render(contentArea);
                break;
            case 'sales':
                Sales.render(contentArea);
                break;
            case 'decision-support':
                DecisionSupport.render(contentArea);
                break;
            case 'frame-review':
                FrameReview.render(contentArea);
                break;
            case 'reports':
                Reports.render(contentArea);
                break;
            case 'users':
                Users.render(contentArea);
                break;
            case 'settings':
                Settings.render(contentArea);
                break;
            case 'logs':
                Logs.render(contentArea);
                break;
            default:
                Dashboard.render(contentArea);
        }
    }
};
