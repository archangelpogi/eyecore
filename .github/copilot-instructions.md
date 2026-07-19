# Eyecore AI Copilot Instructions

## Project Overview

**Eyecore** is a comprehensive web-based optical clinic management system serving multiple clinics with a SuperAdmin dashboard, role-based access control, and advanced features like Decision Support System and 3D Frame Review.

### Architecture

- **Backend**: PHP 7.4+ with PDO/MySQL using XAMPP
- **Frontend**: React + TypeScript (src/app/) with Vite build tool, Tailwind CSS, Radix UI components, MUI
- **Database**: MySQL 5.7+/MariaDB with 40+ interconnected tables
- **Key Entrypoint**: `index.php` (SuperAdmin dashboard) routes to pages/ directory

## Critical Patterns

### Authentication & Authorization

**Single Sign-On Model**: Only SuperAdmin role can access the main dashboard (`index.php`). ClinicAdmin, Optician, and Staff access separate clinic interfaces via `main.php`.

**Security Implementation**:
- Location: [config/security.php](config/security.php) - Static class with role checks, CSRF tokens, login attempt tracking
- Session timeout: 1800 seconds (30 min) with activity tracking
- All API calls require: `$_SESSION['user_id']` and `$_SESSION['clinic_id']`
- Example: [auth/middleware.php](auth/middleware.php) validates session before granting access

**Key Methods**:
```php
Security::isAuthenticated()     // Check login status
Security::isSuperAdmin()         // SuperAdmin only
Security::validateCSRFToken()    // Anti-CSRF protection
Security::validateSession()      // Check inactivity timeout
```

### API Pattern

All API endpoints in `/api/` follow this pattern:
1. Require `$_SESSION['user_id']` and clinic context
2. Return JSON responses with `['status' => 'error/success']`
3. Use prepared statements with PDO to prevent SQL injection
4. Handle pagination via DataTables parameters (draw, start, length)

Example: [api/patients.php](api/patients.php#L14-L50) - Fetch patients with filtering, search, and pagination.

### Database Conventions

**Key Tables** (from [database/schema.sql](database/schema.sql)):
- `users` - User accounts with roles (SuperAdmin, ClinicAdmin, Optician, Staff)
- `clinics` - Multi-tenant clinic info with status (Active, Pending, Suspended, Rejected)
- `patients` - Patient records scoped to clinic_id
- `appointments` - Scheduling with status tracking
- `optical_records` - Eye exam measurements (OD/OS)
- `inventory` - Stock management (frames, lenses, accessories)
- `sales` - Transactions with line items
- `activity_logs` - Audit trail for all actions

**Scoping Rule**: ALL patient/appointment/inventory queries MUST filter by `clinic_id` from session.

### File Organization

- **[pages/](pages/)** - PHP view templates (appointments.php, patients.php, optical-records.php, etc.)
- **[api/](api/)** - RESTful endpoints returning JSON (no direct rendering)
- **[config/](config/)** - Configuration ([db.php](config/db.php), [security.php](config/security.php))
- **[auth/](auth/)** - Authentication handlers (login.php, logout.php, middleware.php)
- **[src/app/](src/app/)** - React components for advanced UI (Decision Support, Frame Review)
- **[admin/](admin/)** - SuperAdmin-only dashboard pages

### Build & Development

**Frontend Build**:
```bash
npm run build   # Vite transpiles src/ → dist/
npm run dev     # Start dev server (Vite)
```

**No NPM Startup**: This is a hybrid app—PHP backend runs on XAMPP, React components compile to static assets.

## Development Workflows

### Adding a New Page

1. Create PHP file in [pages/](pages/) (e.g., `pages/my_feature.php`)
2. Add route case in [index.php](index.php) around line 48 superAdminModules array
3. Include [includes/header.php](includes/header.php) and [includes/sidebar.php](includes/sidebar.php)
4. Fetch data via [api/](api/) endpoints using fetch() with `clinic_id` from session
5. Reference [pages/patients.php](pages/patients.php) as template

### Adding an API Endpoint

1. Create [api/my_endpoint.php](api/)
2. Start with session check:
```php
<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}
```
3. Use `$_SESSION['clinic_id']` for filtering
4. Prepare all SQL statements: `$pdo->prepare()` + `execute()`
5. Return JSON: `echo json_encode(['status' => 'success', 'data' => $result])`

### Adding React Components

1. Create in [src/app/](src/app/) directory
2. Import Radix UI primitives from `@radix-ui/react-*`
3. Use Tailwind CSS classes for styling
4. Build via `npm run build` before deployment

## Common Gotchas

1. **Multi-clinic Scoping**: EVERY database query filtering patients/appointments/inventory MUST include `WHERE clinic_id = ?` bound parameter
2. **Session Variables**: Use `$_SESSION['clinic_id']` (not from request), set at login in [auth/login.php](auth/login.php)
3. **CSRF Protection**: All POST forms require hidden input: `<input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">`
4. **No Direct DB Calls in Views**: Use api/ endpoints, never include config/db.php directly in pages/
5. **Timezone**: Set to `Asia/Manila` in [config/db.php](config/db.php#L25) - do not change without migration plan

## Key Files by Purpose

| Purpose | File |
|---------|------|
| Database config | [config/db.php](config/db.php) |
| Auth & security | [config/security.php](config/security.php) |
| User login flow | [auth/login.php](auth/login.php) |
| SuperAdmin routes | [index.php](index.php) |
| Patient API | [api/patients.php](api/patients.php) |
| Dashboard data | [api/dashboard_api.php](api/dashboard_api.php) |
| DB schema | [database/schema.sql](database/schema.sql) |
| React entry | [src/main.tsx](src/main.tsx) |

## Deployment Notes

- Database: Import [database/schema.sql](database/schema.sql) to initialize
- Change default credentials (admin@eyecore.ph / admin123) in users table
- Update [config/db.php](config/db.php) with production credentials
- Store email credentials safely (currently in [config/db.php](config/db.php) lines 2-3)
- Build frontend: `npm run build` before production release
