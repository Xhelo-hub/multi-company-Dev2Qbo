# Quick Deployment to devsync.konsulence.al

## Option 1: Automated Deployment (Recommended)

### Step 1: SSH to Server
```bash
ssh root@devsync.konsulence.al
```

### Step 2: Download and Run Deploy Script
```bash
cd /tmp
wget https://raw.githubusercontent.com/Xhelo-hub/multi-company-Dev2Qbo/main/deploy.sh
chmod +x deploy.sh
sudo bash deploy.sh
```

The script will:
- ✅ Install all dependencies (Apache, PHP, MySQL, Composer)
- ✅ Clone the repository
- ✅ Create database and user
- ✅ Import schema
- ✅ Configure Apache
- ✅ Setup SSL certificate
- ✅ Configure cron jobs
- ✅ Setup backups

### Step 3: Create Admin User
```bash
cd /var/www/html/multi-company-Dev2Qbo
php bin/create-admin-user.php
```

### Step 4: Configure QuickBooks
Edit `.env` and add your QuickBooks credentials:
```bash
nano /var/www/html/multi-company-Dev2Qbo/.env
```

Update:
```
QBO_CLIENT_ID=your_actual_client_id
QBO_CLIENT_SECRET=your_actual_client_secret
```

### Step 5: Access Application
Open browser: https://devsync.konsulence.al/login.html

---

## Option 2: Manual Deployment

If the automated script fails, follow the detailed steps in `DEPLOYMENT-GUIDE.md`.

---

## Post-Deployment Checklist

- [ ] Admin user created
- [ ] Admin password changed from default
- [ ] QuickBooks OAuth configured
- [ ] DevPos credentials added for companies
- [ ] Test sync manually
- [ ] Verify cron jobs running
- [ ] Check logs: `tail -f /var/log/apache2/devsync-error.log`
- [ ] Setup firewall rules
- [ ] Enable fail2ban
- [ ] Configure monitoring/alerts

---

## Troubleshooting

### Can't access site
```bash
# Check Apache status
systemctl status apache2

# Check error logs
tail -100 /var/log/apache2/devsync-error.log

# Restart Apache
systemctl restart apache2
```

### Database connection fails
```bash
# Test database
cd /var/www/html/multi-company-Dev2Qbo
php bin/test-db-connection.php

# Check MySQL status
systemctl status mysql
```

### SSL certificate issues
```bash
# Re-run certbot
certbot --apache -d devsync.konsulence.al

# Check certificate status
certbot certificates
```

---

## Quick Commands

```bash
# View logs
tail -f /var/log/apache2/devsync-error.log
tail -f /var/log/qbo-sync.log

# Restart services
systemctl restart apache2
systemctl restart mysql

# Update application
cd /var/www/html/multi-company-Dev2Qbo
git pull origin main
composer install --no-dev

# Backup database manually
/usr/local/bin/backup-qbo-db.sh

# Test sync
php bin/test-bills-sync.php
```

---

## Support

For detailed documentation, see:
- `DEPLOYMENT-GUIDE.md` - Complete deployment instructions
- `README.md` - Application documentation
- `BILLS-SYNC-FIX.md` - Bills sync implementation details

---

## Success!

Your application should now be live at:
🌐 **https://devsync.konsulence.al**

Default login (after running create-admin-user.php):
- Email: The email you entered
- Password: The password you created
