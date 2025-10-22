# Web-Based Multi-Tenant System - Quick Start

## 🚀 Quick Setup (5 minutes)

### Step 1: Run the Authentication Setup Script
```bash
php bin/setup-auth.php
```

This will:
- Create user management tables (users, user_sessions, user_company_access)
- Create default admin user
- Grant admin access to all existing companies

### Step 2: Start the Server
```bash
# Make sure XAMPP Apache is running on port 8081
# Or use PHP built-in server:
php -S localhost:8081 -t public
```

### Step 3: Access the Application
1. **Login Page:** http://localhost:8081/login.html
2. **Dashboard (Authenticated):** http://localhost:8081/app.html

### Step 4: Login with Default Credentials
```
Email: admin@devpos-sync.local
Password: admin123
```

**⚠️ IMPORTANT: Change this password immediately in production!**

---

## 👥 User Roles & Permissions

### Admin Users
- Access to **ALL companies**
- Can create and manage users
- Can assign users to companies
- Full access to all features
- Grant permissions:
  - `can_view_sync` - View sync jobs and results
  - `can_run_sync` - Trigger manual sync operations
  - `can_edit_credentials` - Modify DevPos/QBO credentials
  - `can_manage_schedules` - Create/edit sync schedules

### Company Users
- Access **ONLY to assigned companies**
- Cannot create other users
- Permissions are per-company basis
- See only their company's data

---

## 🔐 Authentication Flow

### Login Process
1. User enters email + password at `/login.html`
2. POST request to `/api/auth/login`
3. Server validates credentials (bcrypt)
4. Server creates session token (64-char hex)
5. Token stored in HTTP-only cookie (7-day expiry)
6. User redirected to `/app.html` (authenticated dashboard)

### Session Validation
- Every API request includes session cookie
- `AuthMiddleware` validates token
- Checks expiration (7 days from creation)
- Loads user context (id, email, role, companies)
- Attaches user object to request

### Logout
1. User clicks "Logout"
2. POST request to `/api/auth/logout`
3. Session deleted from database
4. Cookie cleared
5. Redirect to `/login.html`

---

## 🏢 Company Management

### How Admin Assigns Users to Companies

#### Via API:
```bash
# Assign user to company with all permissions
curl -X POST http://localhost:8081/api/auth/users/2/companies/1 \
  -H "Content-Type: application/json" \
  -H "Cookie: session_token=YOUR_ADMIN_TOKEN" \
  -d '{
    "can_view_sync": true,
    "can_run_sync": true,
    "can_edit_credentials": true,
    "can_manage_schedules": true
  }'
```

#### Via Admin Panel (Coming Soon):
- Navigate to "Manage Users" in admin panel
- Select user
- Check companies to grant access
- Toggle specific permissions per company
- Save

### How Users See Their Companies

**Admin:**
- Company selector shows ALL active companies
- No restrictions

**Company User:**
- Company selector shows ONLY assigned companies
- Attempts to access other companies return 403 Forbidden

---

## 🔄 Sync Operations

### Manual Sync from Dashboard
1. Select company from dropdown
2. Choose date range
3. Click sync button:
   - "Sync Sales Invoices" → POST `/api/sync/{companyId}/sales`
   - "Sync Purchase Invoices" → POST `/api/sync/{companyId}/purchases`
   - "Sync Bills" → POST `/api/sync/{companyId}/bills`
   - "Full Sync" → POST `/api/sync/{companyId}/full`
4. Job appears in "Recent Sync Jobs" section
5. Auto-refreshes every 10 seconds

### Required Permissions
- `can_run_sync` must be enabled for the user on that company

---

## 🔑 Credentials Management

### DevPos Credentials
Stored in `company_credentials_devpos` table (encrypted):
```php
{
  "tenant": "M01419018I",
  "username": "your_username", 
  "password": "encrypted_password"
}
```

**How to Configure:**
1. Select company
2. Fill DevPos credentials form
3. Click "Save DevPos Credentials"
4. Click "Test" to verify connection
5. Status indicator turns green if successful

### QuickBooks OAuth
Stored in `company_credentials_qbo` and `oauth_tokens_qbo`:
```php
{
  "realm_id": "4620816365...",
  "access_token": "...",
  "refresh_token": "...",
  "expires_at": "2024-01-15 10:30:00"
}
```

**How to Configure:**
1. Select company
2. Click "Connect to QuickBooks Online"
3. Popup opens with Intuit OAuth page
4. Login to QuickBooks
5. Select company to connect
6. Authorize access
7. Popup closes, dashboard shows "Connected"
8. Realm ID displayed

---

## 📊 Dashboard Features

### Main Dashboard (`/app.html`)
- **Company Selector:** Switch between accessible companies
- **User Info:** Email and role badge
- **Credentials Card:**
  - DevPos connection status
  - DevPos credentials form
  - QuickBooks connection button
- **Sync Operations Card:**
  - Date range picker
  - Sync type buttons
  - Schedule manager link
- **Recent Sync Jobs:**
  - Job type and status
  - Date range
  - Timestamps
  - Auto-refreshes every 10 seconds

### Admin Panel (Admin Only)
- **Manage Users:** Create, edit, disable users
- **Manage Companies:** Add new companies
- **Audit Log:** View all system actions

---

## 🛠️ Development

### Creating Additional Users

**Via CLI:**
```php
<?php
// bin/create-user.php
require __DIR__ . '/../vendor/autoload.php';
$pdo = new PDO("mysql:host=localhost;dbname=qbo_multicompany", "root", "");

$stmt = $pdo->prepare("
    INSERT INTO users (email, password_hash, role, is_active)
    VALUES (?, ?, ?, 1)
");

$stmt->execute([
    'john@company.com',
    password_hash('password123', PASSWORD_DEFAULT),
    'company_user'
]);

echo "User created with ID: " . $pdo->lastInsertId() . "\n";
```

**Via API:**
```bash
curl -X POST http://localhost:8081/api/auth/users \
  -H "Content-Type: application/json" \
  -H "Cookie: session_token=ADMIN_TOKEN" \
  -d '{
    "email": "jane@company.com",
    "password": "password123",
    "role": "company_user"
  }'
```

### Permission Checking in Code

**In Backend:**
```php
// AuthMiddleware attaches user to request
$user = $request->getAttribute('user');

// Check role
if ($user['role'] !== 'admin') {
    throw new Exception('Admin only');
}

// Check company access
$hasAccess = false;
foreach ($user['companies'] as $company) {
    if ($company['company_id'] == $companyId) {
        if (!$company['can_edit_credentials']) {
            throw new Exception('Permission denied');
        }
        $hasAccess = true;
        break;
    }
}
```

**In Frontend:**
```javascript
// currentUser loaded in init()
if (currentUser.role === 'admin') {
    // Show admin panel
    document.getElementById('adminPanel').classList.remove('hidden');
}

// Check company access before sync
const company = currentUser.companies.find(c => c.company_id == companyId);
if (!company || !company.can_run_sync) {
    alert('You do not have permission to run sync for this company');
    return;
}
```

---

## 🐛 Troubleshooting

### "Failed to initialize dashboard"
- Check browser console for errors
- Verify session cookie exists
- Try logging out and back in
- Clear browser cookies

### "Company not found" or "Permission denied"
- Admin user may not have company assignments
- Re-run `php bin/setup-auth.php` to grant admin access
- Check `user_company_access` table

### DevPos Connection Test Fails
- Verify tenant ID is correct (e.g., M01419018I)
- Check username/password
- Ensure .env has `DEVPOS_AUTH_BASIC=Zmlza2FsaXppbWlfc3BhOg==`
- Test with: `php bin/test-devpos-working.php`

### QuickBooks OAuth Not Working
- Verify QBO_CLIENT_ID and QBO_CLIENT_SECRET in .env
- Check QBO_REDIRECT_URI matches your domain
- Ensure popup not blocked by browser
- Check OAuth callback endpoint exists

### Session Expires Too Quickly
- Default: 7 days
- Change in AuthService: `strtotime('+7 days')` → `strtotime('+30 days')`
- Or implement "Remember Me" checkbox

---

## 📁 File Structure

```
public/
  login.html          # Login page (public)
  app.html            # Authenticated dashboard (protected)
  dashboard.html      # Legacy API-key dashboard
  index.php           # Entry point

routes/
  api.php             # Main API routes
  auth.php            # Authentication endpoints

src/
  Middleware/
    AuthMiddleware.php   # Session validation
  Services/
    AuthService.php      # User management logic

sql/
  user-management.sql   # User tables schema

bin/
  setup-auth.php        # Setup script
```

---

## 🔒 Security Best Practices

### Production Checklist
- [ ] Change default admin password
- [ ] Use HTTPS only (no HTTP)
- [ ] Set secure cookie flags in AuthService
- [ ] Enable CORS only for your domain
- [ ] Rotate ENCRYPTION_KEY regularly
- [ ] Implement rate limiting on login endpoint
- [ ] Log all authentication attempts
- [ ] Enable 2FA for admin users
- [ ] Regular security audits
- [ ] Keep dependencies updated

### Password Policy
```php
// Enforce in AuthService::createUser()
if (strlen($password) < 12) {
    throw new Exception('Password must be at least 12 characters');
}
if (!preg_match('/[A-Z]/', $password)) {
    throw new Exception('Password must contain uppercase letter');
}
if (!preg_match('/[0-9]/', $password)) {
    throw new Exception('Password must contain number');
}
```

---

## 🎯 Next Steps

### Immediate Tasks
1. ✅ Run `php bin/setup-auth.php`
2. ✅ Login to app
3. ✅ Change admin password
4. Configure DevPos credentials for test company
5. Connect QuickBooks via OAuth
6. Run test sync

### Feature Roadmap
- [ ] User management UI (admin panel)
- [ ] Password reset flow (email link)
- [ ] Audit log viewer
- [ ] Sync schedule manager
- [ ] Real-time WebSocket updates
- [ ] Email notifications on sync completion
- [ ] Two-factor authentication
- [ ] API rate limiting
- [ ] Export sync results to CSV/Excel

---

## 📞 Support

**Documentation:**
- `WEB-BASED-SYSTEM.md` - Complete architecture guide
- `DEVPOS-SOLVED.md` - DevPos authentication solution
- `CREDENTIALS-MANAGEMENT.md` - Credential storage guide

**Database Schema:**
- `sql/multi-company-schema.sql` - Main schema
- `sql/user-management.sql` - User tables

**Test Scripts:**
- `bin/test-devpos-working.php` - Test DevPos connection
- `bin/setup-auth.php` - Setup authentication system

---

**Ready to start? Run:** `php bin/setup-auth.php`
