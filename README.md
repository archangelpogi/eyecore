# Eyecore - Optical Clinic Management System

A comprehensive web-based integrated management platform for optical clinics in Cavite with Decision Support System, 3D Frame Review, and Mobile Application Integration.

## Technology Stack

- **Frontend**: HTML5, CSS3, Bootstrap 5, JavaScript
- **Backend**: PHP 7.4+
- **Database**: MySQL 5.7+ / MariaDB
- **Charts**: Chart.js
- **Icons**: Bootstrap Icons

## Features

### 🏥 Core Modules

1. **Dashboard** - System overview with KPI cards, charts, and quick actions
2. **Patient Management** - Complete patient database with search and filter
3. **Appointment Scheduling** - Calendar-based booking system with walk-in support
4. **Optical Records** - Eye examination records with OD/OS measurements
5. **Inventory Management** - Stock tracking for frames, lenses, and accessories
6. **Sales & Billing** - Invoice management with payment tracking
7. **Decision Support System** - AI-powered patient risk assessment and recommendations
8. **3D Frame Review** - Virtual frame fitting and preview
9. **Reports & Analytics** - Business insights with interactive charts
10. **User & Staff Management** - Role-based access control
11. **System Settings** - Clinic configuration and preferences
12. **Activity Logs** - Audit trail and security monitoring

## Installation

### Prerequisites

- Web server (Apache/Nginx)
- PHP 7.4 or higher
- MySQL 5.7+ or MariaDB
- Web browser (Chrome, Firefox, Safari, Edge)

### Setup Instructions

1. **Clone or Download**
   ```bash
   git clone <repository-url>
   cd eyecore
   ```

2. **Database Setup**
   - Create a new MySQL database
   - Import the schema:
   ```bash
   mysql -u root -p eyecore_db < database/schema.sql
   ```

3. **Configure Database Connection**
   - Edit `/api/patients.php` and other API files
   - Update database credentials:
   ```php
   $host = 'localhost';
   $dbname = 'eyecore_db';
   $username = 'your_username';
   $password = 'your_password';
   ```

4. **Deploy to Web Server**
   - Copy all files to your web server's document root
   - For XAMPP: `C:/xampp/htdocs/eyecore/`
   - For WAMP: `C:/wamp64/www/eyecore/`
   - For Linux: `/var/www/html/eyecore/`

5. **Access the Application**
   - Open your browser and navigate to:
   - `http://localhost/eyecore/` (local)
   - `http://your-domain.com/` (production)

## Default Credentials

- **Email**: admin@eyecore.ph
- **Password**: admin123

⚠️ **Important**: Change the default password after first login!

## File Structure

```
eyecore/
├── index.html              # Main application file
├── assets/
│   ├── css/
│   │   └── style.css      # Custom styles
│   └── js/
│       ├── navigation.js   # Navigation & routing
│       ├── dashboard.js    # Dashboard module
│       ├── patients.js     # Patient management
│       ├── appointments.js # Appointment scheduling
│       ├── optical-records.js
│       ├── inventory.js
│       ├── sales.js
│       ├── decision-support.js
│       ├── frame-review.js
│       ├── reports.js
│       ├── users.js
│       ├── settings.js
│       ├── logs.js
│       └── main.js         # Main initialization
├── api/
│   ├── patients.php        # Patient CRUD API
│   └── appointments.php    # Appointment API
├── database/
│   └── schema.sql          # Database schema
└── README.md
```

## Usage

### Navigation

- Use the sidebar menu to navigate between different modules
- Click the hamburger icon (☰) to toggle sidebar on desktop
- Sidebar auto-collapses on mobile devices

### Patient Management

1. Click "Add New Patient" button
2. Fill in patient information
3. Click "Create Patient Record"
4. View/Edit patients from the table

### Appointments

1. Click "New Appointment" button
2. Select patient, date, time, and service type
3. Assign an optometrist
4. Walk-in patients can be registered separately

### Optical Records

1. Click "New Eye Examination"
2. Enter eye measurements (OD/OS)
3. Add PD and visual acuity data
4. Save examination record

### Inventory

1. Use tabs to switch between Frames, Lenses, Accessories
2. Monitor stock levels with color-coded badges
3. Restock items when they reach reorder level

### Sales & Billing

1. Create invoices for patients
2. Track payment status (Paid/Partial/Unpaid)
3. Generate receipts and export data

## Browser Compatibility

- ✅ Chrome 90+
- ✅ Firefox 88+
- ✅ Safari 14+
- ✅ Edge 90+

## Responsive Design

- Desktop: Full sidebar + main content
- Tablet: Collapsible sidebar
- Mobile: Off-canvas sidebar menu

## API Endpoints

### Patients API (`/api/patients.php`)
- `GET` - Get all patients or single patient by ID
- `POST` - Create new patient
- `PUT` - Update patient information
- `DELETE` - Archive patient

### Appointments API (`/api/appointments.php`)
- `GET` - Get appointments (all or by date)
- `POST` - Create new appointment
- `PUT` - Update appointment status

## Customization

### Colors

Edit `/assets/css/style.css` to change the color scheme:

```css
:root {
    --primary-color: #0891b2;  /* Teal/Cyan */
    --primary-hover: #0e7490;
    /* ... other colors */
}
```

### Branding

Update clinic information in System Settings or directly in the database:

```sql
UPDATE settings SET setting_value = 'Your Clinic Name' WHERE setting_key = 'clinic_name';
```

## Security Recommendations

1. **Change default credentials** immediately
2. Use **HTTPS** in production
3. Implement **session management** for user authentication
4. Add **CSRF protection** to forms
5. Validate and **sanitize all inputs**
6. Use **prepared statements** (already implemented in PHP)
7. Set proper **file permissions**
8. Keep software **updated**

## Backup

- Daily automatic backups can be configured in System Settings
- Manual backup: Export MySQL database
  ```bash
  mysqldump -u root -p eyecore_db > backup_$(date +%Y%m%d).sql
  ```

## Support

For issues and questions:
- Email: support@eyecore.ph
- Phone: (046) 123-4567

## License

Copyright © 2026 Eyecore. All rights reserved.

## Credits

- **Bootstrap 5** - UI Framework
- **Chart.js** - Data Visualization
- **Bootstrap Icons** - Icon Set

---

**Version**: 1.0.0  
**Last Updated**: January 2026
