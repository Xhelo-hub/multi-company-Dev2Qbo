# Simple Production Deployment Guide - USING ROOT

## SSH as root user instead

Since `converter` user password isn't working, use **root** instead.

**Step 1:** SSH into your server as root
```bash
ssh root@devsync.konsulence.al
```

**Step 2:** Once logged in, copy and paste these commands ONE BY ONE:

```bash
# Backup current production
cp -r /var/www/qbo-devpos-sync /var/www/backups/qbo-backup-$(date +%Y%m%d-%H%M%S)
echo "✅ Backup created"
```

```bash
# Clone fresh from GitHub
cd /tmp
rm -rf multi-company-Dev2Qbo
git clone https://github.com/Xhelo-hub/multi-company-Dev2Qbo.git
echo "✅ Repository cloned"
```

```bash
# Copy .env file
cp /var/www/qbo-devpos-sync/.env /tmp/multi-company-Dev2Qbo/.env
echo "✅ .env restored"
```

```bash
# Replace production directory
rm -rf /var/www/qbo-devpos-sync
mv /tmp/multi-company-Dev2Qbo /var/www/qbo-devpos-sync
echo "✅ Production updated"
```

```bash
# Fix permissions
chown -R www-data:www-data /var/www/qbo-devpos-sync
chmod -R 755 /var/www/qbo-devpos-sync
echo "✅ Permissions fixed"
```

```bash
# Import email database schema (if not already done)
mysql -u root -p qbo_multicompany < /var/www/qbo-devpos-sync/sql/email-system-schema.sql 2>/dev/null || echo "Email schema already exists"
echo "✅ Email schema checked"
```

```bash
# Import email provider presets (NEW)
mysql -u root -p qbo_multicompany < /var/www/qbo-devpos-sync/sql/add-email-provider-presets.sql
echo "✅ Email provider presets added"
```

```bash
# Verify deployment
cd /var/www/qbo-devpos-sync
git status
git log --oneline -5
ls -lh public/admin-email-settings.html public/admin-email-config.html
echo "✅ Deployment complete!"
```

## Verify Everything Works

Test these URLs:
- https://devsync.konsulence.al/public/app.html (main dashboard after login)
- https://devsync.konsulence.al/public/login.html (login page)
- https://devsync.konsulence.al/public/admin-companies.html (companies admin)
- https://devsync.konsulence.al/public/admin-create-company.html (create company)
- https://devsync.konsulence.al/public/admin-email-config.html ← **NEW EMAIL CONFIGURATION**
- https://devsync.konsulence.al/public/admin-email-settings.html (email templates & logs)

## Configure Email After Deployment

### Option 1: Gmail (Recommended - 5 minutes)

1. **Generate app password**: https://myaccount.google.com/apppasswords
2. **Go to**: https://devsync.konsulence.al/public/admin-email-config.html
3. **Click**: Gmail / Google Workspace card
4. **Enter**:
   - Email: your-email@gmail.com
   - Password: 16-character app password (no spaces)
   - From Address: your-email@gmail.com
   - From Name: DEV-QBO Sync
5. **Save & Test**

### Option 2: Microsoft 365

1. **Go to**: https://devsync.konsulence.al/public/admin-email-config.html
2. **Click**: Microsoft 365 / Outlook.com card
3. **Enter**:
   - Email: devsync@konsulence.al
   - Password: Your Microsoft 365 password
   - From Address: devsync@konsulence.al
   - From Name: DEV-QBO Sync
4. **Save & Test**

**Note**: 6 email providers available (Gmail, Microsoft 365, SendGrid, Mailgun, Amazon SES, Custom SMTP)
