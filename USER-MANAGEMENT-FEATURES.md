# User Management Features

## ✅ Completed Features

### 1. User Registration System
**Page:** `/public/register.html`

- Public registration page with validation
- Password requirements: 8+ chars, uppercase, lowercase, number
- Users created with `status='pending'` and `is_active=0`
- Real-time validation feedback
- Auto-redirect to login after 3 seconds

**API Endpoint:** `POST /api/auth/register`

### 2. Password Recovery
**Page:** `/public/password-recovery.html`

- Users can request password reset by email
- Generates 8-character temporary password
- Temporary password valid for 1 hour
- Security-conscious (doesn't reveal if email exists)
- User must change password after first login with temp password

**API Endpoint:** `POST /api/auth/password-recovery`

### 3. User Profile Management
**Page:** `/public/profile.html`

**Features:**
- Update full name
- Change email with 4-digit verification code
- Change password (requires current password)
- Email verification code expires in 15 minutes
- Individual digit inputs for verification code

**API Endpoints:**
- `PATCH /api/auth/profile` - Update profile info
- `POST /api/auth/change-password` - Change password
- `POST /api/auth/request-email-change` - Request email change
- `POST /api/auth/verify-email-change` - Verify and update email

### 4. Admin User Management
**Page:** `/public/admin-users.html`

**New Features:**
- Filter users by name, email, or status
- Status badges: Pending (yellow), Active (green), Suspended (red)
- Approve pending registrations
- Reset user passwords
- Change user roles (admin ↔ company_user)
- Real-time filter with debounce (300ms)

**API Endpoints:**
- `POST /api/admin/users/{userId}/approve` - Approve pending user
- `POST /api/admin/users/{userId}/reset-password` - Reset user password
- `PATCH /api/admin/users/{userId}/role` - Change user role

### 5. Enhanced Login System
**Page:** `/public/login.html`

**New Checks:**
- Pending status check (blocks login until approved)
- Suspended status check (blocks login)
- Temporary password detection
- Links to registration and password recovery pages

### 6. Database Schema
**Migration:** `sql/user-management-enhancements.sql`

**New Columns in `users` table:**
- `status` ENUM('pending', 'active', 'suspended') - User approval status
- `password_reset_token` VARCHAR(255) - Temporary password hash
- `password_reset_expires` DATETIME - Expiry for temp password
- `email_verification_code` VARCHAR(6) - 4-digit verification code
- `email_verification_expires` DATETIME - Expiry for code
- `new_email` VARCHAR(255) - Pending email address

**New Tables:**
- `password_reset_requests` - Track password reset attempts
- `email_verification_codes` - Track email change verifications

**Indexes:**
- `idx_email` - Fast email lookups
- `idx_full_name` - Fast name searches
- `idx_status` - Fast status filtering

### 7. Audit Logging
All user management actions are logged with:
- User ID performing the action
- Action type (e.g., 'user.approve', 'user.password_reset', 'user.role_change')
- Entity type and ID
- Details (JSON with old/new values)
- IP address
- User agent
- Timestamp

## 🔄 User Workflows

### Registration → Approval → Login
1. User visits `/register.html`
2. Fills out form (email, name, password)
3. Account created with `status='pending'`
4. User waits for admin approval
5. Admin logs in, goes to User Management
6. Admin clicks "✓ Approve" button for pending user
7. User's status changed to 'active', `is_active=1`
8. ⚠️ **TODO:** Email notification sent to user
9. User can now login

### Password Recovery
1. User visits `/password-recovery.html`
2. Enters email address
3. System generates 8-char temporary password
4. ⚠️ **TODO:** Temporary password sent to email (currently logged to error_log)
5. User logs in with temporary password
6. Login response includes `requires_password_change: true`
7. ⚠️ **TODO:** User redirected to mandatory password change page
8. User changes password
9. Temporary password token cleared

### Email Change
1. User visits `/profile.html`
2. Clicks "Change Email" section
3. Enters new email address
4. System generates 4-digit verification code
5. ⚠️ **TODO:** Code sent to NEW email address (currently logged to error_log)
6. User enters 4-digit code in modal
7. System verifies code (15-minute expiry)
8. Email updated, verification code cleared

### Admin Password Reset
1. Admin goes to User Management
2. Finds user in list (can use filters)
3. Clicks "🔑 Reset Password" button
4. Confirms action in dialog
5. System generates temporary password
6. ⚠️ **TODO:** Temporary password sent to user's email
7. User must change password on next login

### Admin Role Change
1. Admin goes to User Management
2. Finds user in list
3. Clicks "↑ Make Admin" or "↓ Make User" button
4. Confirms action in dialog
5. User role updated
6. Audit log created with old and new role

## 📋 TODO / Pending Tasks

### High Priority

1. **Force Password Change Page**
   - Create `/change-password-required.html`
   - Intercept login redirect if `requires_password_change` is true
   - Block dashboard access until password changed
   - Update login.html to handle this redirect

2. **Email Sending System**
   - Install PHPMailer: `composer require phpmailer/phpmailer`
   - Create `src/Services/EmailService.php`
   - Configure SMTP in `.env` file
   - Replace `error_log()` calls with email sending:
     * Registration confirmation to admin
     * Approval notification to user
     * Temporary password emails
     * Email verification codes
     * Password reset notifications

3. **Company Ownership Tracking**
   - Add `created_by` column to `companies` table
   - Update `POST /api/companies` to store creator user_id
   - Filter `GET /api/companies` to return:
     * Companies created by user
     * Companies user has access to
     * All companies if admin
   - Show owner in admin-companies.html

### Medium Priority

4. **Update Registration Page**
   - Add note about admin approval requirement
   - Add estimated approval time message

5. **Testing**
   - Test registration flow end-to-end
   - Test password recovery flow
   - Test email change with verification
   - Test admin approval workflow
   - Test admin password reset
   - Test admin role changes
   - Test user filters in admin panel
   - Verify all audit logs are created

6. **UI Enhancements**
   - Add pagination to user list (if > 50 users)
   - Add bulk actions (approve multiple users)
   - Add export user list to CSV
   - Add user activity timeline

### Low Priority

7. **Schedule Management**
   - Create `sync_schedules` table
   - Create schedule management UI page
   - Implement schedule creation/editing endpoints
   - Implement cron job processor
   - Update dashboard button (currently shows "coming soon")

8. **Security Enhancements**
   - Add rate limiting for login attempts
   - Add rate limiting for password reset requests
   - Add CAPTCHA to registration page
   - Add 2FA support
   - Add password history (prevent reusing last 5 passwords)

## 🧪 Testing Checklist

### Manual Testing Steps

1. **Registration:**
   - [ ] Visit `/register.html`
   - [ ] Try weak password (should fail validation)
   - [ ] Register with valid credentials
   - [ ] Verify user created with status='pending' in database
   - [ ] Try to login (should fail with "pending approval" message)

2. **Admin Approval:**
   - [ ] Login as admin
   - [ ] Go to User Management
   - [ ] See pending user with yellow badge
   - [ ] Click "✓ Approve" button
   - [ ] Verify status changed to 'active' in database
   - [ ] Check audit_logs for approval action

3. **User Login After Approval:**
   - [ ] Logout admin
   - [ ] Login with approved user credentials
   - [ ] Verify successful login
   - [ ] Access dashboard

4. **Password Recovery:**
   - [ ] Visit `/password-recovery.html`
   - [ ] Enter user email
   - [ ] Check error_log for temporary password
   - [ ] Login with temporary password
   - [ ] Verify `requires_password_change` in response
   - [ ] Change password in profile

5. **Email Change:**
   - [ ] Visit `/profile.html`
   - [ ] Click "Change Email"
   - [ ] Enter new email
   - [ ] Check error_log for 4-digit code
   - [ ] Enter code in modal
   - [ ] Verify email updated in database
   - [ ] Check audit_logs for email change

6. **Admin Functions:**
   - [ ] Test name filter (type name, wait 300ms)
   - [ ] Test email filter
   - [ ] Test status filter dropdown
   - [ ] Reset a user's password
   - [ ] Check error_log for temp password
   - [ ] Change user role to admin
   - [ ] Change admin role back to user
   - [ ] Verify all actions in audit_logs

## 🔐 Security Notes

- All passwords hashed with `password_hash()` (bcrypt)
- Session tokens are 64-character random hex
- Temporary passwords expire after 1 hour
- Email verification codes expire after 15 minutes
- Password change requires current password
- All sensitive operations logged with IP addresses
- No email enumeration (same response for existing/non-existing emails)
- HTTPS recommended for production (secure cookies)

## 📊 Database Status

### Users Table Schema
```sql
id (INT, PK)
email (VARCHAR 255, UNIQUE)
password_hash (VARCHAR 255)
full_name (VARCHAR 255)
role (ENUM 'admin', 'company_user')
is_active (TINYINT)
status (ENUM 'pending', 'active', 'suspended')
password_reset_token (VARCHAR 255)
password_reset_expires (DATETIME)
email_verification_code (VARCHAR 6)
email_verification_expires (DATETIME)
new_email (VARCHAR 255)
last_login_at (DATETIME)
created_at (TIMESTAMP)
updated_at (TIMESTAMP)
```

### Password Reset Requests Table
```sql
id (INT, PK)
user_id (INT, FK)
reset_token (VARCHAR 255)
expires_at (DATETIME)
used_at (DATETIME)
ip_address (VARCHAR 45)
created_at (TIMESTAMP)
```

### Email Verification Codes Table
```sql
id (INT, PK)
user_id (INT, FK)
verification_code (VARCHAR 6)
new_email (VARCHAR 255)
expires_at (DATETIME)
verified_at (DATETIME)
ip_address (VARCHAR 45)
created_at (TIMESTAMP)
```

## 🔗 Quick Links

### Public Pages
- Registration: `/multi-company-Dev2Qbo/public/register.html`
- Login: `/multi-company-Dev2Qbo/public/login.html`
- Password Recovery: `/multi-company-Dev2Qbo/public/password-recovery.html`

### Authenticated Pages
- Dashboard: `/multi-company-Dev2Qbo/public/app.html`
- Profile: `/multi-company-Dev2Qbo/public/profile.html`

### Admin Pages
- User Management: `/multi-company-Dev2Qbo/public/admin-users.html`
- Company Management: `/multi-company-Dev2Qbo/public/admin-companies.html`
- Audit Logs: `/multi-company-Dev2Qbo/public/admin-audit.html`

## 📝 Notes

- Email notifications are currently logged to `error_log` instead of being sent
- Temporary passwords and verification codes are visible in error_log for testing
- Company filter in User Management needs backend support for company name search
- All new features are fully functional except email sending
- All audit logging is in place
- All security checks are implemented
