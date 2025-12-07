# Email Configuration - Deployment Checklist

## ✅ Pre-Deployment Checklist

- [ ] System is running and accessible
- [ ] MySQL is running (`systemctl status mariadb`)
- [ ] Apache is running (`systemctl status apache2`)
- [ ] Have root/sudo access to server
- [ ] Have MySQL root password
- [ ] Disk space available (`df -h`)

## 📦 Deployment Steps

### 1. Backup (IMPORTANT!)
```bash
# Backup database
mysqldump -u root -p Xhelo_qbo_devpos > /var/backups/qbo_backup_$(date +%Y%m%d).sql

# Backup code
cp -r /var/www/html /var/backups/html_backup_$(date +%Y%m%d)
```
- [ ] Database backed up
- [ ] Code backed up

### 2. Pull Latest Code
```bash
cd /var/www/html
git status  # Check for uncommitted changes
git pull origin main
```
- [ ] Code pulled successfully
- [ ] No merge conflicts

### 3. Update Database Schema
```bash
mysql -u root -p Xhelo_qbo_devpos < sql/add-email-provider-presets.sql
```
- [ ] Migration executed without errors
- [ ] 6 email provider presets created

### 4. Verify Installation
```bash
# Check if presets exist
mysql -u root -p Xhelo_qbo_devpos -e "SELECT COUNT(*) as count FROM email_provider_presets;"
# Expected: count = 6

# Check if columns were added
mysql -u root -p Xhelo_qbo_devpos -e "DESCRIBE email_config;"
# Should see: provider_preset_id, provider_key columns
```
- [ ] Presets table has 6 rows
- [ ] email_config table updated

### 5. Set Permissions
```bash
chown -R www-data:www-data /var/www/html
chmod -R 755 /var/www/html
chmod 644 /var/www/html/.env
```
- [ ] Ownership set to www-data
- [ ] Permissions correct

### 6. Restart Services
```bash
systemctl restart apache2
systemctl status apache2  # Verify running
```
- [ ] Apache restarted successfully
- [ ] No errors in status

### 7. Test API Endpoints
```bash
# Test providers list
curl -I https://devsync.konsulence.al/api/email/providers
# Expected: HTTP/2 200

# Test specific provider
curl https://devsync.konsulence.al/api/email/providers/gmail
# Expected: JSON with Gmail configuration
```
- [ ] API returns 200 OK
- [ ] Provider data returned

### 8. Test UI Access
- [ ] Visit: https://devsync.konsulence.al/public/admin-email-config.html
- [ ] Page loads without errors
- [ ] 6 provider cards display
- [ ] No JavaScript console errors

## ⚙️ Configuration Steps (Post-Deployment)

### Option A: Gmail (Recommended - 5 minutes)

1. **Generate App Password**:
   - [ ] Go to https://myaccount.google.com/apppasswords
   - [ ] Enable 2FA if not already enabled
   - [ ] Create app password for "Mail"
   - [ ] Copy 16-character password

2. **Configure in System**:
   - [ ] Access admin-email-config.html
   - [ ] Click "Gmail / Google Workspace"
   - [ ] Enter Gmail address
   - [ ] Paste app password (no spaces)
   - [ ] Set from address
   - [ ] Click "Save Configuration"

3. **Test Email**:
   - [ ] Click "Test Email" button
   - [ ] Enter your email address
   - [ ] Check inbox for test email
   - [ ] Verify email received successfully

### Option B: Microsoft 365

1. **Get Credentials**:
   - [ ] Full email address
   - [ ] Regular password OR app password if 2FA enabled

2. **Configure in System**:
   - [ ] Select "Microsoft 365 / Outlook.com"
   - [ ] Enter email and password
   - [ ] Save and test

### Option C: Other Providers

Follow setup instructions shown in the UI for:
- [ ] SendGrid
- [ ] Mailgun
- [ ] Amazon SES
- [ ] Custom SMTP

## 🧪 Testing Checklist

### Functionality Tests

1. **Password Reset Email**:
   - [ ] Go to login page
   - [ ] Click "Forgot Password"
   - [ ] Enter email address
   - [ ] Receive password reset email
   - [ ] Link works and resets password

2. **Welcome Email** (if creating new users):
   - [ ] Create a test user
   - [ ] User receives welcome email
   - [ ] Email contains correct information

3. **Email Logs**:
   ```bash
   mysql -u root -p Xhelo_qbo_devpos -e "SELECT * FROM email_logs ORDER BY id DESC LIMIT 5;"
   ```
   - [ ] Emails logged in database
   - [ ] Status shows "sent"
   - [ ] No error messages

### Error Handling Tests

1. **Wrong Password**:
   - [ ] Enter wrong password
   - [ ] System shows error
   - [ ] Configuration not saved

2. **Invalid SMTP Host** (for custom):
   - [ ] Enter invalid host
   - [ ] Test email fails gracefully
   - [ ] Error message displayed

3. **Network Issues**:
   - [ ] Temporarily block port 587
   - [ ] Test email fails gracefully
   - [ ] Error logged properly

## 📊 Monitoring

### Check Logs Regularly
```bash
# Application logs
tail -f /var/www/html/storage/logs/app.log

# Apache error logs
tail -f /var/log/apache2/error.log

# Email logs (database)
watch -n 10 "mysql -u root -p Xhelo_qbo_devpos -e 'SELECT recipient_email, subject, status, sent_at FROM email_logs ORDER BY id DESC LIMIT 5;'"
```

### Monitor Email Delivery
- [ ] Check email_logs table daily
- [ ] Review failed emails
- [ ] Monitor bounce rates
- [ ] Check spam folder reports

## 🔒 Security Checklist

- [ ] Passwords encrypted in database (verify with: `SELECT LENGTH(mail_password) FROM email_config;` - should be long)
- [ ] ENCRYPTION_KEY in .env is secure (32+ characters)
- [ ] .env file not accessible via web (test: https://devsync.konsulence.al/.env should 404)
- [ ] Using app passwords (not main passwords)
- [ ] HTTPS enforced for admin pages
- [ ] Admin authentication required

## 📝 Documentation Checklist

Make sure team has access to:
- [ ] EMAIL-QUICK-SETUP.md - Quick Gmail setup
- [ ] EMAIL-CONFIG-DEPLOYMENT.md - Full deployment guide
- [ ] EMAIL-CONFIG-SUMMARY.md - Feature overview
- [ ] EMAIL-CONFIG-VISUAL.md - Architecture diagrams

## 🆘 Rollback Plan (If Needed)

If something goes wrong:

1. **Rollback Database**:
   ```bash
   mysql -u root -p Xhelo_qbo_devpos < /var/backups/qbo_backup_YYYYMMDD.sql
   ```

2. **Rollback Code**:
   ```bash
   cd /var/www/html
   git reset --hard d6ff337  # Commit before email config
   ```

3. **Or Restore Full Backup**:
   ```bash
   rm -rf /var/www/html
   cp -r /var/backups/html_backup_YYYYMMDD /var/www/html
   chown -R www-data:www-data /var/www/html
   systemctl restart apache2
   ```

## ✨ Success Criteria

Deployment is successful when:
- [ ] All API endpoints return 200 OK
- [ ] UI displays 6 email provider cards
- [ ] Can configure Gmail successfully
- [ ] Test email sends and arrives
- [ ] Password reset emails work
- [ ] Email logs show "sent" status
- [ ] No errors in Apache logs
- [ ] No JavaScript console errors

## 📞 Support Contacts

If issues arise:
- **Documentation**: Check EMAIL-CONFIG-DEPLOYMENT.md
- **Logs**: `/var/www/html/storage/logs/app.log`
- **Database**: `mysql -u root -p Xhelo_qbo_devpos`
- **Apache**: `tail -50 /var/log/apache2/error.log`

## 🎉 Post-Deployment

After successful deployment:
1. **Notify Users**: Email configuration is now available
2. **Update Documentation**: If any custom changes were made
3. **Schedule Review**: Check email logs in 1 week
4. **Plan Enhancements**: Consider email queue, analytics, etc.

---

**Deployment Date**: _______________  
**Deployed By**: _______________  
**Configuration Used**: [ ] Gmail  [ ] M365  [ ] Other: _______________  
**Test Email Sent**: [ ] Yes  [ ] No  
**Status**: [ ] Success  [ ] Issues (describe): _______________  

---

**Ready to deploy? Let's go! 🚀**
