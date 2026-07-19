// Main application initialization
document.addEventListener('DOMContentLoaded', function() {
    // Initialize navigation
   
    
    // Handle window resize for responsive sidebar
    window.addEventListener('resize', function() {
        const sidebar = document.getElementById('sidebar');
        if (window.innerWidth >= 992) {
            sidebar.classList.remove('show');
        }
    });
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(event) {
        const sidebar = document.getElementById('sidebar');
        const toggleBtn = document.getElementById('toggleSidebar');
        
        if (window.innerWidth < 992) {
            if (sidebar.classList.contains('show') && 
                !sidebar.contains(event.target) && 
                event.target !== toggleBtn && 
                !toggleBtn.contains(event.target)) {
                sidebar.classList.remove('show');
            }
        }
    });
    
    // console.log('Eyecore Management System initialized');
});

// Toggle Sidebar
document.addEventListener('DOMContentLoaded', function() {
    const toggleSidebar = document.getElementById('toggleSidebar');
    const toggleSidebarMobile = document.getElementById('toggleSidebarMobile');
    const sidebar = document.getElementById('sidebar');
    
    if (toggleSidebar) {
        toggleSidebar.addEventListener('click', function() {
            sidebar.classList.toggle('show');
        });
    }
    
    if (toggleSidebarMobile) {
        toggleSidebarMobile.addEventListener('click', function() {
            sidebar.classList.remove('show');
        });
    }
    
    // Auto-hide alerts
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
});
