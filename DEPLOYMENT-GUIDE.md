# Deployment Guide - devsync.konsulence.al

## Prerequisites on Server

1. **Web Server**: Apache or Nginx with PHP support
2. **PHP**: Version 8.0 or higher with extensions:
   - pdo_mysql
   - openssl
   - json
   - curl
   - mbstring
3. **MySQL/MariaDB**: Version 5.7 or higher
4. **Composer**: For dependency management
5. **Git**: For pulling the repository

## Deployment Steps

### 1. SSH into Server

```bash
ssh your-user@devsync.konsulence.al
```

### 2. Navigate to Web Root

```bash
cd /var/www/html
# or wherever your web root is
```

### 3. Clone the Repository

```bash
git clone https://github.com/Xhelo-hub/multi-company-Dev2Qbo.git
cd multi-company-Dev2Qbo
```

### 4. Install Dependencies

```bash
composer install --no-dev --optimize-autoloader
```

### 5. Create Database

```bash
mysql -u root -p
```

```sql
CREATE DATABASE qbo_multicompany CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'qbo_user'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD_HERE';
GRANT ALL PRIVILEGES ON qbo_multicompany.* TO 'qbo_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### 6. Import Database Schema

```bash
mysql -u root -p qbo_multicompany < sql/multi-company-schema.sql
mysql -u root -p qbo_multicompany < sql/add-vendor-invoice-mappings.sql
```

### 7. Configure Environment

```bash
cp .env.example .env
nano .env
```

Update the following values:

```env
# Database
DB_HOST=localhost
DB_DATABASE=qbo_multicompany
DB_USERNAME=qbo_user
DB_PASSWORD=STRONG_PASSWORD_HERE

# Security
ENCRYPTION_KEY=your-32-char-encryption-key-here
API_KEY=your-api-key-for-external-access

# DevPos API
DEVPOS_TOKEN_URL=https://online.devpos.al/connect/token
DEVPOS_API_BASE=https://online.devpos.al/api/v3
DEVPOS_AUTH_BASIC=Zmlza2FsaXppbWlfc3BhOg==

# QuickBooks OAuth
QBO_CLIENT_ID=your-qbo-client-id
QBO_CLIENT_SECRET=your-qbo-client-secret
QBO_REDIRECT_URI=https://devsync.konsulence.al/oauth/callback
QBO_ENV=production

# QuickBooks Settings
QBO_DEFAULT_EXPENSE_ACCOUNT=1
```

### 8. Generate Encryption Key

```bash
php -r "echo bin2hex(random_bytes(16)) . PHP_EOL;"
# Copy the output and use it as ENCRYPTION_KEY in .env
```

### 9. Set Permissions

```bash
# Set ownership
chown -R www-data:www-data /var/www/html/multi-company-Dev2Qbo

# Set directory permissions
find /var/www/html/multi-company-Dev2Qbo -type d -exec chmod 755 {} \;

# Set file permissions
find /var/www/html/multi-company-Dev2Qbo -type f -exec chmod 644 {} \;

# Make bin scripts executable
chmod +x bin/*.php
```

### 10. Configure Apache Virtual Host

Create `/etc/apache2/sites-available/devsync.konsulence.al.conf`:

```apache
<VirtualHost *:80>
    ServerName devsync.konsulence.al
    ServerAdmin admin@konsulence.al
    DocumentRoot /var/www/html/multi-company-Dev2Qbo/public

    <Directory /var/www/html/multi-company-Dev2Qbo/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        
        # Enable rewrite
        RewriteEngine On
        
        # Redirect to index.php if file doesn't exist
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^ index.php [QSA,L]
    </Directory>

    # Logs
    ErrorLog ${APACHE_LOG_DIR}/devsync-error.log
    CustomLog ${APACHE_LOG_DIR}/devsync-access.log combined

    # Security headers
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"
</VirtualHost>

# SSL Configuration (after getting SSL certificate)
<IfModule mod_ssl.c>
<VirtualHost *:443>
    ServerName devsync.konsulence.al
    ServerAdmin admin@konsulence.al
    DocumentRoot /var/www/html/multi-company-Dev2Qbo/public

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/devsync.konsulence.al/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/devsync.konsulence.al/privkey.pem

    <Directory /var/www/html/multi-company-Dev2Qbo/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        
        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^ index.php [QSA,L]
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/devsync-ssl-error.log
    CustomLog ${APACHE_LOG_DIR}/devsync-ssl-access.log combined

    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Strict-Transport-Security "max-age=31536000"
</VirtualHost>
</IfModule>
```

### 11. Enable Site and Modules

```bash
# Enable required Apache modules
a2enmod rewrite
a2enmod headers
a2enmod ssl

# Enable the site
a2ensite devsync.konsulence.al.conf

# Disable default site (optional)
a2dissite 000-default.conf

# Test configuration
apache2ctl configtest

# Restart Apache
systemctl restart apache2
```

### 12. Setup SSL Certificate (Let's Encrypt)

```bash
# Install Certbot
apt-get update
apt-get install certbot python3-certbot-apache

# Get SSL certificate
certbot --apache -d devsync.konsulence.al

# Certbot will automatically configure SSL in Apache
```

### 13. Create Admin User

```bash
cd /var/www/html/multi-company-Dev2Qbo
php bin/create-admin-user.php
```

Or manually insert into database:

```sql
-- Password: admin123 (change after first login!)
INSERT INTO users (email, password_hash, full_name, role, status, is_active) 
VALUES (
    'admin@konsulence.al', 
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'Administrator',
    'admin',
    'active',
    1
);
```

### 14. Setup Cron Jobs (For Scheduled Syncs)

```bash
crontab -e
```

Add:

```cron
# Run scheduled syncs every hour
0 * * * * cd /var/www/html/multi-company-Dev2Qbo && php bin/schedule-runner.php >> /var/log/qbo-sync.log 2>&1

# Clean up old sessions daily
0 2 * * * cd /var/www/html/multi-company-Dev2Qbo && php bin/cleanup-sessions.php >> /var/log/qbo-cleanup.log 2>&1
```

### 15. Test the Deployment

```bash
# Test database connection
php bin/test-db-connection.php

# Test DevPos connection
php bin/test-devpos-connection.php

# Visit the site
curl https://devsync.konsulence.al
```

### 16. Access the Application

Open browser and navigate to:
- https://devsync.konsulence.al/login.html (login page)
- https://devsync.konsulence.al/app.html (main dashboard)

Default credentials:
- Email: `admin@konsulence.al`
- Password: `admin123` (change immediately!)

## Post-Deployment

### 1. Change Default Admin Password

Log in and go to Profile → Change Password

### 2. Add Companies

- Navigate to Admin → Companies
- Add your companies with NIPT codes
- Configure DevPos credentials for each company
- Connect QuickBooks OAuth for each company

### 3. Configure Schedules

- Set up automatic sync schedules for each company
- Test manual syncs first to ensure everything works

### 4. Monitor Logs

```bash
# Apache error logs
tail -f /var/log/apache2/devsync-error.log

# Application sync logs
tail -f /var/log/qbo-sync.log

# MySQL slow query log
tail -f /var/log/mysql/slow-query.log
```

## Security Checklist

- [ ] Change default admin password
- [ ] Set strong ENCRYPTION_KEY in .env
- [ ] Set strong database password
- [ ] Enable SSL/HTTPS
- [ ] Configure firewall (allow only 80, 443, 22)
- [ ] Keep PHP and Apache updated
- [ ] Regular database backups
- [ ] Monitor access logs for suspicious activity
- [ ] Set up fail2ban for SSH protection

## Backup Strategy

### Database Backup

```bash
# Create backup script
cat > /usr/local/bin/backup-qbo-db.sh << 'EOF'
#!/bin/bash
BACKUP_DIR="/backups/qbo"
DATE=$(date +%Y%m%d_%H%M%S)
mkdir -p $BACKUP_DIR
mysqldump -u qbo_user -p'PASSWORD' qbo_multicompany | gzip > $BACKUP_DIR/qbo_backup_$DATE.sql.gz
# Keep only last 30 days
find $BACKUP_DIR -name "qbo_backup_*.sql.gz" -mtime +30 -delete
EOF

chmod +x /usr/local/bin/backup-qbo-db.sh

# Add to crontab for daily backup at 3 AM
0 3 * * * /usr/local/bin/backup-qbo-db.sh
```

### Code Backup

```bash
# Git backup (if you have changes)
cd /var/www/html/multi-company-Dev2Qbo
git add .
git commit -m "Production changes"
git push origin main
```

## Updating the Application

```bash
cd /var/www/html/multi-company-Dev2Qbo

# Pull latest changes
git pull origin main

# Update dependencies
composer install --no-dev --optimize-autoloader

# Run any new migrations
mysql -u qbo_user -p qbo_multicompany < sql/migrations/YYYY-MM-DD-description.sql

# Clear any cache
rm -rf cache/*

# Restart Apache
systemctl restart apache2
```

## Troubleshooting

### Issue: 500 Internal Server Error

```bash
# Check Apache error log
tail -100 /var/log/apache2/devsync-error.log

# Check PHP errors
tail -100 /var/log/apache2/error.log

# Verify file permissions
ls -la /var/www/html/multi-company-Dev2Qbo/public
```

### Issue: Database Connection Failed

```bash
# Test MySQL connection
mysql -u qbo_user -p qbo_multicompany

# Check .env configuration
cat .env | grep DB_

# Verify MySQL is running
systemctl status mysql
```

### Issue: DevPos Authentication Fails

```bash
# Test credentials
php bin/test-devpos-connection.php

# Check environment variables
grep DEVPOS .env
```

### Issue: QuickBooks OAuth Fails

- Verify QBO_REDIRECT_URI in .env matches QuickBooks app settings
- Check SSL is enabled and working
- Verify QBO_CLIENT_ID and QBO_CLIENT_SECRET are correct

## Support

For issues or questions:
- Documentation: Check project README.md
- Logs: Review application and Apache logs
- Database: Check sync_jobs table for error messages

## Production Optimizations

### Enable OPcache

Edit `/etc/php/8.x/apache2/php.ini`:

```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
```

### Database Optimization

```sql
-- Add indexes if missing
ALTER TABLE sync_jobs ADD INDEX idx_company_status (company_id, status);
ALTER TABLE invoice_mappings ADD INDEX idx_company_type_synced (company_id, transaction_type, synced_at);

-- Optimize tables monthly
OPTIMIZE TABLE sync_jobs;
OPTIMIZE TABLE invoice_mappings;
OPTIMIZE TABLE maps_documents;
```

### Enable Gzip Compression

In Apache config:

```apache
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript application/json
</IfModule>
```

## Success!

Your application should now be running at:
🌐 https://devsync.konsulence.al

Default login:
📧 admin@konsulence.al
🔑 admin123 (change immediately!)
