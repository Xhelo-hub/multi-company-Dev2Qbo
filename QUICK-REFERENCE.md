# 🎯 QUICK REFERENCE - Multi-Company Sync System

## ✅ SYSTEM STATUS: **FULLY OPERATIONAL**

---

## 🔗 APPLICATION URLs

### Login Page
```
http://localhost/multi-company-Dev2Qbo/public/login.html
```

### Dashboard (Authenticated)
```
http://localhost/multi-company-Dev2Qbo/public/app.html
```

### Legacy Dashboard (API Key)
```
http://localhost/multi-company-Dev2Qbo/public/dashboard.html
```

---

## 🔑 DEFAULT CREDENTIALS

```
Email:    admin@devpos-sync.local
Password: admin123
```

⚠️ **CHANGE THIS PASSWORD IMMEDIATELY IN PRODUCTION!**

---

## 🧪 API TESTING

### Test Login
```bash
curl -X POST "http://localhost/multi-company-Dev2Qbo/public/api/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@devpos-sync.local","password":"admin123"}'
```

**Expected Response:**
```json
{
  "session_token": "eee6d2b...",
  "expires_at": "2025-10-28 23:48:47",
  "user": {
    "id": 1,
    "email": "admin@devpos-sync.local",
    "role": "admin",
    "companies": [...]
  }
}
```

### Test Get Current User
```bash
curl "http://localhost/multi-company-Dev2Qbo/public/api/auth/me" \
  -H "Cookie: session_token=YOUR_TOKEN_HERE"
```

### Test Get Companies
```bash
curl "http://localhost/multi-company-Dev2Qbo/public/api/companies" \
  -H "Cookie: session_token=YOUR_TOKEN_HERE"
```

---

## 📊 DATABASE INFO

**Database:** `qbo_multicompany`
**Host:** `localhost`
**User:** `root`
**Password:** *(empty)*

### Tables Created:
- ✅ `companies` - Company records (AEM, PGROUP)
- ✅ `users` - User accounts
- ✅ `user_sessions` - Active sessions
- ✅ `user_company_access` - Company assignments
- ✅ `company_credentials_devpos` - DevPos credentials (encrypted)
- ✅ `company_credentials_qbo` - QuickBooks credentials
- ✅ `oauth_tokens_devpos` - DevPos API tokens
- ✅ `oauth_tokens_qbo` - QuickBooks OAuth tokens
- ✅ `sync_jobs` - Sync job tracking
- ✅ `sync_schedules` - Automated schedules
- ✅ `invoice_map` - Invoice ID mappings

---

## 👤 CURRENT USERS

### Admin User
- **ID:** 1
- **Email:** admin@devpos-sync.local
- **Role:** admin
- **Access:** All companies (AEM, PGROUP)
- **Permissions:** Full (view, run, edit, manage)

---

## 🏢 COMPANIES

### Company 1: AEM
- **Code:** AEM
- **Name:** Albanian Engineering & Management
- **ID:** 1
- **Status:** Active ✅

### Company 2: PGROUP  
- **Code:** PGROUP
- **Name:** Professional Group Albania
- **ID:** 2
- **Status:** Active ✅

---

## 🛠️ USEFUL SCRIPTS

### Check Database Tables
```bash
php bin/check-table.php
```

### Check Users
```bash
php bin/check-users.php
```

### Test Password
```bash
php bin/test-password.php
```

### Fix Admin Password
```bash
php bin/fix-password.php
```

### Test DevPos Connection
```bash
php bin/test-devpos-working.php
```

### List All Routes
```bash
php bin/list-routes.php
```

### Re-run Authentication Setup
```bash
php bin/setup-auth.php
```

---

## 🚀 WORKFLOW

### 1. Login
1. Open: http://localhost/multi-company-Dev2Qbo/public/login.html
2. Enter credentials
3. Click "Sign In"
4. Redirected to dashboard

### 2. Configure Credentials
1. Select company from dropdown
2. Enter DevPos credentials (tenant, username, password)
3. Click "Save DevPos Credentials"
4. Click "Test" to verify connection

### 3. Connect QuickBooks
1. Click "Connect to QuickBooks Online"
2. Login to QuickBooks in popup
3. Select company
4. Authorize
5. Popup closes, shows "Connected ✅"

### 4. Run Sync
1. Select date range
2. Click sync type:
   - 📄 Sales Invoices
   - 🛒 Purchase Invoices
   - 💰 Bills
   - 🔄 Full Sync
3. View results in "Recent Sync Jobs"

---

## 🔒 SECURITY FEATURES

- ✅ Bcrypt password hashing
- ✅ Session-based authentication (7-day expiry)
- ✅ HTTP-only cookies
- ✅ Role-based access control (admin vs company_user)
- ✅ Per-company granular permissions
- ✅ Encrypted credential storage
- ✅ IP address + user agent tracking

---

## 📁 KEY FILES

### Frontend
- `public/login.html` - Login page ✅
- `public/app.html` - Main dashboard ✅
- `public/.htaccess` - URL rewriting ✅

### Backend
- `bootstrap/app.php` - Application bootstrap ✅
- `routes/api.php` - API routes ✅
- `routes/auth.php` - Authentication endpoints ✅
- `src/Middleware/AuthMiddleware.php` - Session validation ✅
- `src/Services/AuthService.php` - User management ✅

### Database
- `sql/multi-company-schema.sql` - Main schema ✅
- `sql/user-management.sql` - User tables ✅

---

## ✅ SYSTEM CHECKLIST

- [x] Database created and schema loaded
- [x] User management tables created
- [x] Admin user created with correct password
- [x] Admin has access to both companies
- [x] Authentication API working
- [x] Login page functional
- [x] Dashboard accessible
- [x] URL rewriting configured
- [x] Base path set correctly
- [x] All fetch() calls updated
- [x] Session management working
- [x] DevPos authentication method implemented
- [x] QuickBooks OAuth configured

**Status: READY FOR USE! 🎉**

---

**Last Updated:** October 22, 2025
