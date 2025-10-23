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
# Import email database schema
mysql -u root -p qbo_multicompany < /var/www/qbo-devpos-sync/sql/email-system-schema.sql
echo "✅ Database schema imported (enter MySQL root password when prompted)"
```

```bash
# Verify deployment
cd /var/www/qbo-devpos-sync
git status
git log --oneline -5
ls -lh public/admin-email-settings.html public/admin-create-company.html
echo "✅ Deployment complete!"
```

## Verify Everything Works

Test these URLs:
- https://devsync.konsulence.al/public/app.html (main dashboard after login)
- https://devsync.konsulence.al/public/login.html (login page)
- https://devsync.konsulence.al/public/admin-companies.html (companies admin)
- https://devsync.konsulence.al/public/admin-create-company.html (create company)
- https://devsync.konsulence.al/public/admin-email-settings.html ← **NEW EMAIL ADMIN**

## Configure Email After Deployment

1. Login to admin panel
2. Click "Email Settings" button
3. Configure SMTP:
   - Host: smtp.office365.com
   - Port: 587
   - Username: devsync@konsulence.al
   - Password: [Your Microsoft 365 password]
   - Enable TLS
4. Test connection
5. Send test email
