# Multi-Tenant Web-Based Sync System - Implementation Guide

## 🎯 System Architecture

### User Roles
1. **Admin Users** (`role='admin'`)
   - Access to ALL companies
   - Create/manage users
   - Assign users to companies with granular permissions
   - Manage all credentials (DevPos + QuickBooks)
   - Configure sync schedules for any company

2. **Company Users** (`role='company_user'`)
   - Access ONLY to assigned companies
   - Permissions per company:
     - `can_view_sync`: View sync jobs and results
     - `can_run_sync`: Trigger manual sync
     - `can_edit_credentials`: Modify DevPos/QBO credentials
     - `can_manage_schedules`: Create/edit sync schedules

## 📁 Files Created

### Database Schema
- ✅ `sql/user-management.sql` - User authentication tables
  - `users` - User accounts with bcrypt password hashing
  - `user_company_access` - User-to-company assignments with permissions
  - `user_sessions` - Active session tokens
  - Default admin: `admin@devpos-sync.local` / `admin123`

### Backend Services
- ✅ `src/Services/AuthService.php` - Authentication logic
  - `login()` - User authentication + session creation
  - `logout()` - Session invalidation
  - `createUser()` - User registration
  - `assignUserToCompany()` - Grant company access
  - `hasPermission()` - Permission checking

- ✅ `src/Middleware/AuthMiddleware.php` - Request authentication
  - Validates session tokens (cookie or Bearer header)
  - Loads user context with company access
  - Enforces admin-only routes

- ✅ `routes/auth.php` - Authentication API endpoints
  - `POST /api/auth/login` - Login
  - `POST /api/auth/logout` - Logout
  - `GET /api/auth/me` - Get current user
  - `GET /api/auth/users` - List users (admin)
  - `POST /api/auth/users` - Create user (admin)
  - `POST /api/auth/users/{id}/companies/{companyId}` - Assign to company
  - `DELETE /api/auth/users/{id}/companies/{companyId}` - Remove access

### Frontend
- ✅ `public/login.html` - Login page with modern UI
- ⏳ `public/dashboard.html` - Main dashboard (needs to be created)

## 🚀 Implementation Steps

### Step 1: Install User Management Schema
```bash
mysql -u root qbo_multicompany < sql/user-management.sql
```

This creates:
- User tables
- Default admin user
- Sample company users (commented out)

### Step 2: Update routes/api.php
Add authentication routes and protect existing routes:

```php
<?php
// Load authentication routes
$authRoutes = require __DIR__ . '/auth.php';
$authRoutes($app);

// Protect sync routes with authentication
$app->group('/companies', function ($group) use ($pdo) {
    // ... existing company routes ...
})->add(new \App\Middleware\AuthMiddleware($pdo));

$app->group('/sync', function ($group) use ($pdo) {
    // ... existing sync routes ...
})->add(new \App\Middleware\AuthMiddleware($pdo));
```

### Step 3: Create Dashboard Features

The dashboard needs these sections:

#### 1. **Company Selector** (if user has multiple companies)
```javascript
<select id="companySelector">
  <option value="1">AEM - Albanian Engineering</option>
  <option value="2">PGROUP - Professional Group</option>
</select>
```

#### 2. **Credentials Management**
Allow users to configure:
- DevPos credentials (tenant, username, password)
- QuickBooks connection (OAuth flow)

#### 3. **Sync Controls**
- Manual sync triggers (Sales, Purchases, Bills, Full)
- Date range selection
- Real-time sync status
- Job history with results

#### 4. **Schedule Management**
- Create/edit sync schedules
- Frequency options (hourly, daily, weekly, monthly, custom cron)
- Active/inactive toggle

#### 5. **Admin Panel** (admin users only)
- User management (create, edit, disable)
- Company assignment
- Permission management
- System logs

## 🔐 Security Features

### Password Hashing
- Uses PHP `password_hash()` with bcrypt
- Automatically salted
- Secure against rainbow tables

### Session Management
- 64-character random tokens
- 7-day expiration
- Stored in HTTP-only cookies
- IP address + user agent tracking

### Permission Checking
```php
// In sync route
$user = $request->getAttribute('user');
$companyId = $args['companyId'];

// Check access
$hasAccess = false;
foreach ($user['companies'] as $company) {
    if ($company['company_id'] == $companyId) {
        if (!$company['can_run_sync']) {
            throw new Exception('Permission denied');
        }
        $hasAccess = true;
        break;
    }
}
```

## 📋 Dashboard UI Requirements

### Modern, Professional Design
- Responsive layout (desktop + mobile)
- Clean, intuitive navigation
- Real-time updates (WebSockets or polling)
- Loading states and error handling

### Key Components Needed
1. **Navigation Bar**
   - Company selector
   - User menu (profile, logout)
   - Admin link (if admin)

2. **Company Overview Card**
   - DevPos connection status
   - QuickBooks connection status
   - Last sync timestamp
   - Quick sync buttons

3. **Sync Job List**
   - Filterable table
   - Status indicators (pending, running, completed, failed)
   - Results summary (invoices created, errors)
   - View details button

4. **Credentials Form**
   - DevPos: Tenant, Username, Password (encrypted before save)
   - QuickBooks: "Connect to QuickBooks" button (OAuth)
   - Connection test buttons

5. **Schedule Editor**
   - Visual schedule builder
   - Cron expression helper
   - Next run preview
   - Enable/disable toggle

6. **Admin Dashboard** (admin only)
   - User list with filters
   - Quick actions (create, edit, disable)
   - Company assignment matrix
   - Audit log viewer

## 🔄 OAuth Flow for QuickBooks

### In-Dashboard Implementation
```javascript
// When user clicks "Connect to QuickBooks"
async function connectQuickBooks(companyId) {
    // Step 1: Get authorization URL from backend
    const response = await fetch(`/api/companies/${companyId}/qbo/auth-url`);
    const { auth_url, state } = await response.json();
    
    // Step 2: Open popup window
    const popup = window.open(auth_url, 'qbo-auth', 'width=800,height=600');
    
    // Step 3: Listen for callback
    window.addEventListener('message', (event) => {
        if (event.data.type === 'qbo-connected') {
            popup.close();
            showSuccess('QuickBooks connected!');
            refreshCompanyStatus();
        }
    });
}
```

### Backend OAuth Callback Handler
```php
// routes/oauth.php
$app->get('/oauth/callback', function (Request $request, Response $response) use ($pdo) {
    $code = $request->getQueryParams()['code'] ?? null;
    $realmId = $request->getQueryParams()['realmId'] ?? null;
    $state = $request->getQueryParams()['state'] ?? null;
    
    // Exchange code for tokens
    // Save to database
    // Return success page that posts message to parent window
    
    return $response->write('
        <script>
            window.opener.postMessage({type: "qbo-connected"}, "*");
            window.close();
        </script>
    ');
});
```

## 📊 Sync Job Flow

### User Triggers Sync
1. User clicks "Sync Sales" in dashboard
2. Frontend sends POST to `/api/sync/{companyId}/sales`
3. Middleware validates user has permission
4. Backend creates sync job in `sync_jobs` table
5. Job processor runs sync logic
6. Results saved to database
7. Frontend polls for updates or receives WebSocket notification

### Scheduled Sync
1. Cron job runs `php bin/scheduler.php`
2. Finds due schedules in `sync_schedules`
3. Creates sync jobs for each
4. Background worker processes queue
5. Results emailed/notified to assigned users

## 🎨 Recommended UI Framework

### Option 1: Plain HTML/CSS/JS (Current)
- ✅ No build step
- ✅ Fast to implement
- ✅ Easy to customize
- Recommended for quick MVP

### Option 2: Vue.js / React
- Better for complex interactions
- Component reusability
- Requires build process

## 📦 Next Steps to Complete

### Critical (Required for MVP)
1. ✅ User management schema - **DONE**
2. ✅ Authentication service - **DONE**
3. ✅ Auth middleware - **DONE**
4. ✅ Login page - **DONE**
5. ⏳ Main dashboard HTML
6. ⏳ Credentials management UI
7. ⏳ Sync controls UI
8. ⏳ Integrate auth routes with api.php

### Important (Phase 2)
9. Schedule management UI
10. Admin panel
11. Real-time updates (WebSockets or polling)
12. Email notifications
13. Audit logging UI

### Nice to Have (Phase 3)
14. User profile settings
15. Password reset flow
16. Two-factor authentication
17. API rate limiting
18. Export sync results

## 🔧 Configuration

### Environment Variables
Already configured in `.env`:
- `ENCRYPTION_KEY` - For password encryption
- `QBO_CLIENT_ID` / `QBO_CLIENT_SECRET` - QuickBooks OAuth
- `DEVPOS_AUTH_BASIC` - DevPos API authentication

### Default Credentials
**Admin Account:**
- Email: `admin@devpos-sync.local`
- Password: `admin123`
- **⚠️ CHANGE IMMEDIATELY IN PRODUCTION!**

## 📝 Usage Examples

### As Admin
1. Login with admin credentials
2. See all companies dashboard
3. Click "Users" → Create company user
4. Assign user to specific company
5. Set permissions (can view, can sync, can edit credentials)

### As Company User
1. Login with company user credentials
2. See only assigned companies
3. Configure DevPos credentials for company
4. Click "Connect to QuickBooks"
5. Run manual sync or schedule automated syncs

## 🎯 Success Metrics

- ✅ Users can login securely
- ✅ Admin can manage users and permissions
- ✅ Company users see only their data
- ✅ Credentials stored encrypted
- ✅ OAuth flow works in-app
- ✅ Sync jobs tracked per company
- ✅ Audit trail for all actions

---

**Status:** Foundation complete, dashboard UI needs implementation
**Estimated Completion:** Dashboard = 4-6 hours, Admin panel = 2-3 hours
