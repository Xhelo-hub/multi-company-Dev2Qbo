#!/bin/bash
# Automated deployment script for devsync.konsulence.al
# Run this on the production server

set -e  # Exit on error

echo "================================================"
echo "🚀 Starting Automated Deployment"
echo "================================================"
echo ""

# Step 1: Backup existing production
echo "📦 Step 1: Creating backup..."
BACKUP_DIR="/var/www/backups/qbo-backup-$(date +%Y%m%d-%H%M%S)"
sudo cp -r /var/www/qbo-devpos-sync "$BACKUP_DIR"
echo "✅ Backup created: $BACKUP_DIR"
echo ""

# Step 2: Clone fresh repository
echo "📥 Step 2: Cloning from GitHub..."
cd /tmp
rm -rf multi-company-Dev2Qbo
git clone https://github.com/Xhelo-hub/multi-company-Dev2Qbo.git
cd multi-company-Dev2Qbo
echo "✅ Repository cloned (commit: $(git log -1 --format=%h))"
echo ""

# Step 3: Restore .env file
echo "⚙️ Step 3: Restoring .env configuration..."
sudo cp /var/www/qbo-devpos-sync/.env /tmp/multi-company-Dev2Qbo/.env
echo "✅ .env file restored"
echo ""

# Step 4: Replace production directory
echo "🔄 Step 4: Deploying new code..."
sudo rm -rf /var/www/qbo-devpos-sync
sudo mv /tmp/multi-company-Dev2Qbo /var/www/qbo-devpos-sync
echo "✅ Production directory updated"
echo ""

# Step 5: Fix permissions
echo "🔐 Step 5: Setting permissions..."
sudo chown -R www-data:www-data /var/www/qbo-devpos-sync
sudo chmod -R 755 /var/www/qbo-devpos-sync
echo "✅ Permissions fixed"
echo ""

# Step 6: Import database schema
echo "💾 Step 6: Importing database schema..."
sudo mysql -u root -p qbo_multicompany < /var/www/qbo-devpos-sync/sql/email-system-schema.sql
echo "✅ Database schema imported"
echo ""

# Step 7: Verify deployment
echo "🔍 Step 7: Verifying deployment..."
cd /var/www/qbo-devpos-sync
echo "Git status:"
sudo -u www-data git status
echo ""
echo "Latest commits:"
sudo -u www-data git log --oneline -5
echo ""
echo "New files:"
ls -lh public/admin-email-settings.html public/admin-create-company.html routes/email.php sql/email-system-schema.sql
echo ""

echo "================================================"
echo "✅ DEPLOYMENT COMPLETE!"
echo "================================================"
echo ""
echo "📋 Deployed files:"
echo "  ✓ public/admin-email-settings.html"
echo "  ✓ public/admin-create-company.html"
echo "  ✓ routes/email.php"
echo "  ✓ Updated admin-companies.html"
echo "  ✓ Updated bootstrap/app.php"
echo "  ✓ Updated routes/api.php"
echo ""
echo "🔗 Test URLs:"
echo "  • https://devsync.konsulence.al/public/multi-company-dashboard.html"
echo "  • https://devsync.konsulence.al/public/admin-companies.html"
echo "  • https://devsync.konsulence.al/public/admin-create-company.html"
echo "  • https://devsync.konsulence.al/public/admin-email-settings.html"
echo ""
echo "📧 Next: Configure SMTP in email settings panel"
echo "================================================"
