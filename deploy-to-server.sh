#!/bin/bash
# Deployment script for devsync.konsulence.al
# Run this on the server after SSH login

set -e

echo "=== Multi-Company Dev2QBO Deployment ==="
echo ""

# Configuration
REPO_URL="https://github.com/Xhelo-hub/multi-company-Dev2Qbo.git"
WEB_DIR="/home/devsync/web/devsync.konsulence.al/public_html"
TEMP_DIR="/tmp/multi-company-Dev2Qbo-$(date +%s)"

echo "Step 1: Cloning repository..."
git clone "$REPO_URL" "$TEMP_DIR"

echo "Step 2: Copying files to web directory..."
rsync -av --exclude='.git' "$TEMP_DIR/" "$WEB_DIR/"

echo "Step 3: Installing Composer dependencies..."
cd "$WEB_DIR"
composer install --no-dev --optimize-autoloader

echo "Step 4: Setting up .env file..."
if [ ! -f "$WEB_DIR/.env" ]; then
    echo "Creating .env file from .env.example..."
    cp .env.example .env
    echo "⚠️  IMPORTANT: Edit .env with your production credentials!"
fi

echo "Step 5: Setting permissions..."
chmod -R 755 "$WEB_DIR"
chmod -R 775 "$WEB_DIR/tmp" 2>/dev/null || mkdir -p "$WEB_DIR/tmp" && chmod 775 "$WEB_DIR/tmp"
chmod 600 "$WEB_DIR/.env"

echo "Step 6: Cleanup..."
rm -rf "$TEMP_DIR"

echo ""
echo "✅ Deployment complete!"
echo ""
echo "Next steps:"
echo "1. Create database: mysql -u root -p -e 'CREATE DATABASE qbo_multicompany;'"
echo "2. Import schema: mysql -u root -p qbo_multicompany < $WEB_DIR/sql/multi-company-schema.sql"
echo "3. Edit .env file: nano $WEB_DIR/.env"
echo "4. Create admin user: php $WEB_DIR/bin/create-admin-user.php"
echo "5. Visit: https://devsync.konsulence.al"
echo ""
