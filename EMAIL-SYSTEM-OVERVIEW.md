# Email System Overview

## ✅ Implementation Complete

The multi-company DEV-QBO sync platform now has a fully functional **database-driven email notification system** with admin panel for configuration and template management.

---

## 🎯 Key Features

### 1. **Database-Driven Configuration**
- SMTP settings stored in `email_config` table (not in .env)
- Encrypted password storage using AES-256-CBC
- Support for SMTP, Sendmail, and PHP Mail drivers
- Microsoft 365 (Outlook) default configuration

### 2. **Customizable Email Templates**
- Four default templates: Welcome, Password Reset, Temporary Password, Account Modified
- HTML and plain text versions for all templates
- Variable substitution system: `{{name}}`, `{{email}}`, `{{temp_password}}`, etc.
- Template preview with sample data
- Test email sending for each template

### 3. **Email Audit Logging**
- All sent emails logged to `email_logs` table
- Status tracking (sent/failed/pending)
- Error message recording for troubleshooting
- Queryable logs with pagination and filtering

### 4. **Admin API Endpoints**
- Complete CRUD operations for email configuration
- Template management (list, get, update)
- Test SMTP connection
- Preview templates with sample data
- Send test emails
- View email send history

---

## 📊 Database Schema

### `email_config` Table
Stores SMTP configuration with encrypted credentials:
```sql
- mail_driver: smtp, sendmail, mail
- mail_host: smtp.office365.com
- mail_port: 587
- mail_username: devsync@konsulence.al
- mail_password: [ENCRYPTED]
- mail_encryption: tls, ssl
- mail_from_address: devsync@konsulence.al
- mail_from_name: DEV-QBO Sync
- is_enabled: 0 (disabled by default)
```

### `email_templates` Table
Stores customizable email templates:
```sql
- template_key: Unique identifier (user_welcome, password_reset, etc.)
- template_name: Display name
- subject: Email subject with variables
- body_html: HTML email body
- body_text: Plain text fallback
- available_variables: JSON array of supported placeholders
- is_active: Template enable/disable flag
```

### `email_logs` Table
Tracks all email sending attempts:
```sql
- recipient_email: Who received the email
- subject: Email subject line
- status: sent, failed, pending
- error_message: Error details if failed
- sent_at: Timestamp
```

---

## 🔌 API Endpoints

### Configuration Management
```
GET    /api/admin/email/config           - Get current SMTP configuration
PUT    /api/admin/email/config           - Update SMTP settings
POST   /api/admin/email/config/test      - Test SMTP connection
```

**Example: Update Configuration**
```json
PUT /api/admin/email/config
{
  "mail_host": "smtp.office365.com",
  "mail_port": 587,
  "mail_username": "devsync@konsulence.al",
  "mail_password": "YourPassword123!",
  "mail_encryption": "tls",
  "mail_from_address": "devsync@konsulence.al",
  "mail_from_name": "DEV-QBO Sync",
  "is_enabled": 1
}
```

### Template Management
```
GET    /api/admin/email/templates         - List all templates
GET    /api/admin/email/templates/{key}   - Get single template
PUT    /api/admin/email/templates/{key}   - Update template
POST   /api/admin/email/templates/{key}/preview  - Preview with data
POST   /api/admin/email/templates/{key}/test     - Send test email
```

**Example: Update Template**
```json
PUT /api/admin/email/templates/user_welcome
{
  "subject": "Welcome to {{name}}!",
  "body_html": "<h1>Hello {{name}}</h1><p>Your email is {{email}}</p>",
  "body_text": "Hello {{name}}, Your email is {{email}}",
  "is_active": 1
}
```

### Email Logs
```
GET    /api/admin/email/logs              - View email send history
       ?status=sent|failed                 - Filter by status
       &limit=50&offset=0                  - Pagination
```

---

## 🎨 Template Variables

### Available Variables
- `{{name}}` - User's full name
- `{{email}}` - User's email address
- `{{temp_password}}` - Temporary password
- `{{login_url}}` - Login page URL
- `{{reset_url}}` - Password reset URL with token
- `{{changes_list}}` - HTML list of account changes
- `{{year}}` - Current year (automatic)

### Example Template
```html
<h1>Welcome {{name}}!</h1>
<p>Your account email is: {{email}}</p>
<p>Login here: <a href="{{login_url}}">{{login_url}}</a></p>
<p>© {{year}} DEV-QBO Sync</p>
```

---

## 🔒 Security Features

### Password Encryption
- AES-256-CBC encryption for SMTP passwords
- Uses `ENCRYPTION_KEY` from .env
- 16-byte random IV for each encryption
- Base64 encoding for database storage

### Access Control
- All endpoints require admin authentication
- AuthMiddleware validates session and role
- Password field never returned in GET requests

### Email Validation
- PHPMailer handles email validation
- Test connection before saving credentials
- Error logging for troubleshooting

---

## 📧 Default Email Templates

### 1. Welcome Email (`user_welcome`)
**Sent when:** New user account is created  
**Variables:** `{{name}}`, `{{email}}`, `{{temp_password}}`, `{{login_url}}`  
**Features:** Responsive design, temporary password display, security notice

### 2. Password Reset (`password_reset`)
**Sent when:** User requests password reset  
**Variables:** `{{name}}`, `{{reset_url}}`  
**Features:** Token-based reset link, expiration notice, security warning

### 3. Temporary Password (`temp_password`)
**Sent when:** Admin resets user password  
**Variables:** `{{name}}`, `{{temp_password}}`, `{{login_url}}`  
**Features:** Clear password display, security notice, change password reminder

### 4. Account Modified (`account_modified`)
**Sent when:** Admin updates user profile  
**Variables:** `{{name}}`, `{{changes_list}}`  
**Features:** Change summary, security notice, contact information

---

## 🚀 Integration Points

### User Registration
**File:** `routes/auth.php` (Line 273)
```php
$emailService->sendWelcomeEmail($email, $fullName, $temporaryPassword);
```

### Password Recovery
**File:** `routes/auth.php` (Line 328)
```php
$emailService->sendPasswordResetEmail($email, $user['full_name'], $token);
```

### Admin Password Reset
**File:** `routes/auth.php` (Line 640)
```php
$emailService->sendTemporaryPasswordEmail($email, $user['full_name'], $tempPassword);
```

---

## ⚙️ Configuration Steps

### 1. Configure Email Credentials (Via API)
```bash
curl -X PUT https://devsync.konsulence.al/public/api/admin/email/config \
  -H "Content-Type: application/json" \
  -H "Cookie: YOUR_SESSION_COOKIE" \
  -d '{
    "mail_host": "smtp.office365.com",
    "mail_port": 587,
    "mail_username": "devsync@konsulence.al",
    "mail_password": "YourActualPassword",
    "mail_encryption": "tls",
    "mail_from_address": "devsync@konsulence.al",
    "mail_from_name": "DEV-QBO Sync",
    "is_enabled": 1
  }'
```

### 2. Test Configuration
```bash
curl -X POST https://devsync.konsulence.al/public/api/admin/email/config/test \
  -H "Content-Type: application/json" \
  -H "Cookie: YOUR_SESSION_COOKIE" \
  -d '{"email": "your-test@email.com"}'
```

### 3. Customize Templates (Optional)
```bash
curl -X PUT https://devsync.konsulence.al/public/api/admin/email/config/templates/user_welcome \
  -H "Content-Type: application/json" \
  -H "Cookie: YOUR_SESSION_COOKIE" \
  -d '{
    "subject": "Your Custom Subject",
    "body_html": "<h1>Custom HTML</h1>",
    "body_text": "Custom plain text",
    "is_active": 1
  }'
```

---

## 🧪 Testing

### Test SMTP Connection
```bash
POST /api/admin/email/config/test
{
  "email": "admin@example.com"
}
```

### Send Test Welcome Email
```bash
POST /api/admin/email/templates/user_welcome/test
{
  "email": "admin@example.com"
}
```

### Preview Template
```bash
POST /api/admin/email/templates/password_reset/preview
{
  "variables": {
    "name": "John Doe",
    "reset_url": "https://example.com/reset?token=abc123"
  }
}
```

---

## 📝 Implementation Files

### Core Service
- **src/Services/EmailService.php** (432 lines)
  - Database configuration loading
  - Template rendering with variable substitution
  - Email sending with logging
  - Password encryption/decryption

### API Routes
- **routes/email.php** (493 lines)
  - 9 admin endpoints for email management
  - Configuration CRUD
  - Template CRUD
  - Preview and testing
  - Log viewing

### Database Schema
- **sql/email-system-schema.sql** (253 lines)
  - 3 tables: email_config, email_templates, email_logs
  - Default Microsoft 365 configuration
  - 4 pre-populated templates
  - Foreign key constraints

### Bootstrap
- **bootstrap/app.php** (Updated)
  - EmailService DI registration with PDO and encryption key

---

## 🎭 Admin Panel UI (TODO)

The next phase is to create an admin panel UI for managing email configuration and templates:

### Planned Pages
1. **admin-email-settings.html**
   - Configuration tab (SMTP settings, test connection)
   - Templates tab (list, edit, preview, test)
   - Logs tab (view sent emails, filter by status)

### Features
- Visual template editor with HTML/text tabs
- Live preview with sample data
- Test email sending
- Configuration validation
- Error message display
- Email log filtering and search

---

## 🔄 Email Flow

```
1. User Action (register, password reset, etc.)
   ↓
2. EmailService loads config from database
   ↓
3. EmailService loads template from database
   ↓
4. Template variables substituted
   ↓
5. PHPMailer sends email via SMTP
   ↓
6. Result logged to email_logs table
   ↓
7. Success/failure returned to API
```

---

## 📦 Dependencies

- **PHPMailer 7.0.0** - SMTP email sending
- **OpenSSL** - Password encryption (PHP extension)
- **PDO** - Database access
- **Slim Framework 4** - API routing

---

## 🐛 Troubleshooting

### Email Not Sending
1. Check `email_config.is_enabled = 1`
2. Verify SMTP credentials are correct
3. Test connection via `/api/admin/email/config/test`
4. Check `email_logs` table for error messages
5. Verify Microsoft 365 allows SMTP (App Password may be required)

### Template Not Loading
1. Check `email_templates.is_active = 1`
2. Verify template_key matches exactly
3. Check for missing variables in template

### Password Encryption Error
1. Ensure `ENCRYPTION_KEY` is set in .env
2. Check OpenSSL extension is enabled in PHP
3. Verify database column `mail_password` is TEXT type

---

## 📊 Status Summary

| Component | Status | Notes |
|-----------|--------|-------|
| Database Schema | ✅ Complete | 3 tables created with defaults |
| EmailService | ✅ Complete | Database-driven, encrypted passwords |
| API Endpoints | ✅ Complete | 9 endpoints, admin-only |
| Email Templates | ✅ Complete | 4 default templates with variables |
| Email Logging | ✅ Complete | Audit trail with status tracking |
| Admin Panel UI | ⏳ Pending | Next phase implementation |
| Production Deploy | ⏳ Pending | After admin UI complete |

---

## 🎯 Next Steps

1. **Create Admin Panel UI** (`admin-email-settings.html`)
   - Configuration management form
   - Template editor with preview
   - Email logs viewer

2. **Deploy to Production**
   - Execute schema on production database
   - Configure devsync@konsulence.al credentials
   - Test all email types
   - Monitor logs

3. **Documentation**
   - Add admin panel usage guide
   - Create troubleshooting FAQ
   - Document Microsoft 365 setup steps

---

## 📝 Version History

- **v1.0** (Current) - Initial database-driven email system
  - Database configuration storage
  - Template management system
  - Email logging and audit trail
  - 9 admin API endpoints
  - Password encryption
  - Default Microsoft 365 setup

---

## 📧 Contact & Support

**Email:** devsync@konsulence.al  
**Platform:** https://devsync.konsulence.al/public/  
**Repository:** https://github.com/Xhelo-hub/multi-company-Dev2Qbo

For issues or questions about the email system, please contact the development team.
