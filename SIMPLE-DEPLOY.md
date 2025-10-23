# Simple Production Deployment Guide

## Current Situation
- ✅ **Local**: Up to date with commit `b9efa10` (latest)
- ✅ **GitHub**: Up to date with commit `b9efa10` (latest)  
- ❌ **Production**: At commit `53587db` or earlier, **NO git repository**

## What You'll Get After Deployment
All these commits will be deployed:
1. `53587db` - Company creation reorganization (already had this)
2. `4711832` - Email service refactor  
3. `6905e32` - Email documentation
4. `ca39187` - Email settings admin panel
5. `b9efa10` - Deployment docs

## Copy-Paste Deployment Commands

**Step 1:** SSH into your server
```bash
ssh converter@devsync.konsulence.al
```

**Step 2:** Once logged in, copy and paste these commands ONE BY ONE:

```bash
# Backup current production
sudo cp -r /var/www/qbo-devpos-sync /var/www/backups/qbo-backup-$(date +%Y%m%d-%H%M%S)
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
sudo cp /var/www/qbo-devpos-sync/.env /tmp/multi-company-Dev2Qbo/.env
echo "✅ .env restored"
```

```bash
# Replace production directory
sudo rm -rf /var/www/qbo-devpos-sync
sudo mv /tmp/multi-company-Dev2Qbo /var/www/qbo-devpos-sync
echo "✅ Production updated"
```

```bash
# Fix permissions
sudo chown -R www-data:www-data /var/www/qbo-devpos-sync
sudo chmod -R 755 /var/www/qbo-devpos-sync
echo "✅ Permissions fixed"
```

```bash
# Import email database schema
sudo mysql -u root -p qbo_multicompany < /var/www/qbo-devpos-sync/sql/email-system-schema.sql
echo "✅ Database schema imported"
```

```bash
# Verify deployment
cd /var/www/qbo-devpos-sync
sudo -u www-data git status
sudo -u www-data git log --oneline -5
echo "✅ Deployment complete!"
```

## Verify Everything Works

Test these URLs in your browser:
- https://devsync.konsulence.al/public/multi-company-dashboard.html
- https://devsync.konsulence.al/public/admin-companies.html
- https://devsync.konsulence.al/public/admin-create-company.html ← This existed before
- https://devsync.konsulence.al/public/admin-email-settings.html ← **NEW**

## What's New After Deployment

### 1. Email Settings Admin Panel (NEW)
- Access via "Email Settings" button in admin panel
- Or: https://devsync.konsulence.al/public/admin-email-settings.html
- Configure SMTP, manage templates, view email logs

### 2. Updated Files
- `bootstrap/app.php` - EmailService uses database now
- `routes/api.php` - Added email routes
- `public/app.html` - Added Email Settings button
- `src/Services/EmailService.php` - Database-driven

### 3. New Database Tables
- `email_config` - SMTP configuration
- `email_templates` - Email templates (4 default)
- `email_logs` - Email audit trail

## Future: Using Git on Production

After this deployment, you can use git normally:
```bash
# SSH into server
ssh converter@devsync.konsulence.al

# Go to project
cd /var/www/qbo-devpos-sync

# Pull latest changes
sudo -u www-data git pull origin main

# Check status
sudo -u www-data git status
```

## Rollback (if needed)
If something goes wrong:
```bash
# List backups
ls -lh /var/www/backups/

# Restore from backup
sudo rm -rf /var/www/qbo-devpos-sync
sudo cp -r /var/www/backups/qbo-backup-YYYYMMDD-HHMMSS /var/www/qbo-devpos-sync
```
