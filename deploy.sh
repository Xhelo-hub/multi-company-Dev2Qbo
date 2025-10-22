#!/bin/bash

##############################################################################
# Deployment Script for devsync.konsulence.al
# 
# This script automates the deployment process
# Run with: sudo bash deploy.sh
##############################################################################

set -e  # Exit on error

echo "========================================="
echo "   Multi-Company Dev2QBO Deployment"
echo "   Server: devsync.konsulence.al"
echo "========================================="
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    echo "❌ Please run as root (use sudo)"
    exit 1
fi

# Configuration
WEB_ROOT="/var/www/html"
APP_NAME="multi-company-Dev2Qbo"
APP_DIR="$WEB_ROOT/$APP_NAME"
DB_NAME="qbo_multicompany"
DB_USER="qbo_user"

echo "📦 Step 1: Installing system dependencies..."
apt-get update
apt-get install -y \
    apache2 \
    php8.1 \
    php8.1-cli \
    php8.1-mysql \
    php8.1-curl \
    php8.1-json \
    php8.1-mbstring \
    php8.1-xml \
    php8.1-zip \
    mariadb-server \
    composer \
    git \
    certbot \
    python3-certbot-apache

echo ""
echo "✅ Dependencies installed"
echo ""

echo "📥 Step 2: Cloning repository..."
cd $WEB_ROOT

if [ -d "$APP_DIR" ]; then
    echo "⚠️  Directory already exists. Updating..."
    cd $APP_DIR
    git pull origin main
else
    git clone https://github.com/Xhelo-hub/multi-company-Dev2Qbo.git
    cd $APP_DIR
fi

echo ""
echo "✅ Repository cloned/updated"
echo ""

echo "📦 Step 3: Installing PHP dependencies..."
composer install --no-dev --optimize-autoloader

echo ""
echo "✅ Dependencies installed"
echo ""

echo "🗄️  Step 4: Setting up database..."

# Generate random password
DB_PASSWORD=$(openssl rand -base64 16)

# Create database and user
mysql -u root << EOF
CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
EOF

echo "✅ Database created"
echo ""

echo "📋 Step 5: Importing schema..."
mysql -u root $DB_NAME < sql/multi-company-schema.sql
mysql -u root $DB_NAME < sql/add-vendor-invoice-mappings.sql

echo ""
echo "✅ Schema imported"
echo ""

echo "⚙️  Step 6: Configuring environment..."

if [ ! -f ".env" ]; then
    cp .env.example .env
    
    # Generate encryption key
    ENCRYPTION_KEY=$(php -r "echo bin2hex(random_bytes(16));")
    API_KEY=$(php -r "echo bin2hex(random_bytes(24));")
    
    # Update .env file
    sed -i "s/DB_PASSWORD=.*/DB_PASSWORD=$DB_PASSWORD/" .env
    sed -i "s/ENCRYPTION_KEY=.*/ENCRYPTION_KEY=$ENCRYPTION_KEY/" .env
    sed -i "s/API_KEY=.*/API_KEY=$API_KEY/" .env
    sed -i "s/APP_URL=.*/APP_URL=https:\/\/devsync.konsulence.al/" .env
    sed -i "s/QBO_REDIRECT_URI=.*/QBO_REDIRECT_URI=https:\/\/devsync.konsulence.al\/oauth\/callback/" .env
    
    echo "✅ .env file created and configured"
    echo ""
    echo "📝 IMPORTANT: Save these credentials:"
    echo "   Database User: $DB_USER"
    echo "   Database Password: $DB_PASSWORD"
    echo "   Encryption Key: $ENCRYPTION_KEY"
    echo "   API Key: $API_KEY"
    echo ""
    read -p "Press enter to continue after saving these credentials..."
else
    echo "⚠️  .env file already exists. Skipping..."
fi

echo ""

echo "🔐 Step 7: Setting permissions..."
chown -R www-data:www-data $APP_DIR
find $APP_DIR -type d -exec chmod 755 {} \;
find $APP_DIR -type f -exec chmod 644 {} \;
chmod +x $APP_DIR/bin/*.php

echo ""
echo "✅ Permissions set"
echo ""

echo "🌐 Step 8: Configuring Apache..."

cat > /etc/apache2/sites-available/devsync.konsulence.al.conf << 'APACHECONF'
<VirtualHost *:80>
    ServerName devsync.konsulence.al
    ServerAdmin admin@konsulence.al
    DocumentRoot /var/www/html/multi-company-Dev2Qbo/public

    <Directory /var/www/html/multi-company-Dev2Qbo/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        
        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^ index.php [QSA,L]
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/devsync-error.log
    CustomLog ${APACHE_LOG_DIR}/devsync-access.log combined

    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"
</VirtualHost>
APACHECONF

# Enable required modules
a2enmod rewrite
a2enmod headers
a2enmod ssl

# Enable site
a2ensite devsync.konsulence.al.conf

# Test configuration
apache2ctl configtest

# Restart Apache
systemctl restart apache2

echo ""
echo "✅ Apache configured"
echo ""

echo "🔒 Step 9: Setting up SSL certificate..."
echo "Running certbot..."
certbot --apache -d devsync.konsulence.al --non-interactive --agree-tos --email admin@konsulence.al

echo ""
echo "✅ SSL certificate installed"
echo ""

echo "⏰ Step 10: Setting up cron jobs..."

# Create cron jobs
(crontab -l 2>/dev/null; echo "# QBO Sync scheduled tasks") | crontab -
(crontab -l 2>/dev/null; echo "0 * * * * cd $APP_DIR && php bin/schedule-runner.php >> /var/log/qbo-sync.log 2>&1") | crontab -
(crontab -l 2>/dev/null; echo "0 2 * * * cd $APP_DIR && php bin/cleanup-sessions.php >> /var/log/qbo-cleanup.log 2>&1") | crontab -
(crontab -l 2>/dev/null; echo "0 3 * * * /usr/local/bin/backup-qbo-db.sh") | crontab -

echo ""
echo "✅ Cron jobs configured"
echo ""

echo "💾 Step 11: Setting up backup script..."

cat > /usr/local/bin/backup-qbo-db.sh << 'BACKUPSCRIPT'
#!/bin/bash
BACKUP_DIR="/backups/qbo"
DATE=$(date +%Y%m%d_%H%M%S)
mkdir -p $BACKUP_DIR
mysqldump -u qbo_user -p'PASSWORD_PLACEHOLDER' qbo_multicompany | gzip > $BACKUP_DIR/qbo_backup_$DATE.sql.gz
find $BACKUP_DIR -name "qbo_backup_*.sql.gz" -mtime +30 -delete
BACKUPSCRIPT

# Replace password placeholder
sed -i "s/PASSWORD_PLACEHOLDER/$DB_PASSWORD/" /usr/local/bin/backup-qbo-db.sh

chmod +x /usr/local/bin/backup-qbo-db.sh
mkdir -p /backups/qbo

echo ""
echo "✅ Backup script configured"
echo ""

echo "========================================="
echo "   ✅ Deployment Complete!"
echo "========================================="
echo ""
echo "📊 Summary:"
echo "   • Application URL: https://devsync.konsulence.al"
echo "   • Database: $DB_NAME"
echo "   • Database User: $DB_USER"
echo "   • Log files: /var/log/apache2/devsync-*.log"
echo ""
echo "📝 Next Steps:"
echo "   1. Create admin user:"
echo "      cd $APP_DIR"
echo "      php bin/create-admin-user.php"
echo ""
echo "   2. Test database connection:"
echo "      php bin/test-db-connection.php"
echo ""
echo "   3. Configure QuickBooks OAuth credentials in .env:"
echo "      nano .env"
echo "      (Update QBO_CLIENT_ID and QBO_CLIENT_SECRET)"
echo ""
echo "   4. Add companies via web interface"
echo ""
echo "   5. Test syncs manually before scheduling"
echo ""
echo "⚠️  SECURITY REMINDER:"
echo "   • Change admin password after first login"
echo "   • Review firewall settings"
echo "   • Setup monitoring and alerts"
echo "   • Enable fail2ban for SSH protection"
echo ""
echo "🎉 Happy syncing!"
echo ""
