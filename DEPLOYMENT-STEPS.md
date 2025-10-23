# Email System Deployment Steps for Production Server

## 🚀 Deployment Checklist

### Step 1: Pull Latest Code from GitHub
SSH into your production server and run:

```bash
cd /path/to/devsync.konsulence.al/public_html
git pull origin main
```

This will pull commits:
- `4711832` - Database-driven email service
- `6905e32` - Email system documentation  
- `ca39187` - Admin panel UI

### Step 2: Execute Database Schema
Run the email system schema on production database:

```bash
mysql -u your_db_user -p your_database_name < sql/email-system-schema.sql
```

Or using phpMyAdmin:
1. Login to phpMyAdmin
2. Select your database (qbo_multicompany)
3. Go to "Import" tab
4. Choose file: `sql/email-system-schema.sql`
5. Click "Go"

This will create:
- `email_config` table (SMTP configuration)
- `email_templates` table (4 default templates)
- `email_logs` table (email audit trail)

### Step 3: Verify Database Tables
Check that tables were created:

```sql
SHOW TABLES LIKE 'email%';
SELECT * FROM email_config;
SELECT template_key, template_name FROM email_templates;
```

You should see:
- email_config (1 row with default settings)
- email_templates (4 rows: user_welcome, password_reset, temp_password, account_modified)
- email_logs (empty initially)

### Step 4: Check File Permissions
Ensure Apache/Nginx can read the new files:

```bash
chmod 644 public/admin-email-settings.html
chmod 644 routes/email.php
chmod 644 src/Services/EmailService.php
```

### Step 5: Verify .env Configuration
Make sure your production `.env` has:

```bash
# Required for password encryption
ENCRYPTION_KEY=your-secure-32-character-key

# Application URL for email links
APP_URL=https://devsync.konsulence.al/public
```

### Step 6: Access Admin Panel
1. Login as admin: https://devsync.konsulence.al/public/app.html
2. Click "Email Settings" button in admin panel
3. Or direct: https://devsync.konsulence.al/public/admin-email-settings.html

### Step 7: Configure Email Settings
In the Configuration tab:
1. Check "Enable Email Service"
2. Enter SMTP details:
   - Host: `smtp.office365.com`
   - Port: `587`
   - Username: `devsync@konsulence.al`
   - Password: `[Your Microsoft 365 password]`
   - Encryption: `TLS`
   - From Address: `devsync@konsulence.al`
   - From Name: `DEV-QBO Sync`
3. Click "Test Connection"
4. Click "Save Configuration"

### Step 8: Test Email Functionality
1. Go to Templates tab
2. Click "user_welcome" template
3. Click "Send Test" button
4. Enter your email address
5. Check if email arrives

### Step 9: Verify Integration
Test that emails are sent automatically:
1. Create a new user (should send welcome email)
2. Request password reset (should send reset link)
3. Admin reset user password (should send temp password)
4. Go to Email Logs tab to see sent emails

---

## 🔍 Troubleshooting

### Issue: "Email Settings" button not visible
- Clear browser cache (Ctrl+Shift+R)
- Check if logged in as admin user
- Verify `public/app.html` was updated

### Issue: admin-email-settings.html returns 404
- Verify file was deployed: `ls public/admin-email-settings.html`
- Check file permissions: `ls -la public/admin-email-settings.html`
- Restart web server if needed

### Issue: API endpoints return 500 error
- Check PHP error logs: `tail -f /var/log/apache2/error.log`
- Verify database tables exist
- Check EmailService can connect to database
- Verify PDO is passed to EmailService in bootstrap/app.php

### Issue: Database schema import fails
- Check if tables already exist (drop them first if testing)
- Verify database user has CREATE TABLE permissions
- Check for syntax errors in schema file

### Issue: Cannot save email configuration
- Verify ENCRYPTION_KEY is set in .env
- Check email_config table exists
- Verify logged in user is admin
- Check browser console for JavaScript errors

### Issue: Test email fails
- Verify SMTP credentials are correct
- Check if Microsoft 365 requires App Password
- Enable "Less secure app access" if needed
- Check firewall allows port 587 outbound
- Review email_logs table for error messages

---

## 📝 Quick Deployment Commands

If you have SSH access to production:

```bash
# Navigate to project directory
cd /var/www/devsync.konsulence.al/public_html

# Pull latest code
git pull origin main

# Import database schema
mysql -u username -p database_name < sql/email-system-schema.sql

# Set permissions
chmod 644 public/admin-email-settings.html
chmod 644 routes/email.php

# Restart PHP-FPM (if needed)
sudo systemctl restart php8.1-fpm

# Check if files are accessible
curl -I https://devsync.konsulence.al/public/admin-email-settings.html
```

---

## ✅ Deployment Verification Checklist

- [ ] Code pulled from GitHub (commit ca39187)
- [ ] Database schema executed successfully
- [ ] 3 email tables created (config, templates, logs)
- [ ] admin-email-settings.html accessible
- [ ] "Email Settings" button visible in admin panel
- [ ] Can access configuration form
- [ ] Can view templates list
- [ ] Can save SMTP configuration
- [ ] Test connection works
- [ ] Test email sends successfully
- [ ] Email logs showing in Logs tab
- [ ] User registration sends welcome email
- [ ] Password reset sends reset link
- [ ] Admin password reset sends temp password

---

## 🎯 Post-Deployment

After successful deployment:

1. **Configure Production Credentials:**
   - Use real devsync@konsulence.al password
   - Test email delivery to actual users

2. **Monitor Email Logs:**
   - Check Logs tab regularly
   - Watch for failed deliveries
   - Review error messages

3. **Customize Templates (Optional):**
   - Update email content to match branding
   - Add company logo/signature
   - Adjust wording as needed

4. **Enable Email Service:**
   - Once tested, enable in Configuration tab
   - All new users will receive welcome emails
   - All password resets will send emails automatically

---

## 📞 Need Help?

If deployment issues persist:
1. Check server error logs
2. Verify all prerequisites are met
3. Test each component individually
4. Review EMAIL-SYSTEM-OVERVIEW.md for details
