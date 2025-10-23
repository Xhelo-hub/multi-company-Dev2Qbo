#!/bin/bash

# Email System Deployment Script for Production Server
# Run this on your production server after SSHing in

set -e  # Exit on any error

echo "================================================"
echo "📧 Email System Deployment Script"
echo "================================================"
echo ""

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Configuration (UPDATE THESE)
PROJECT_DIR="/var/www/devsync.konsulence.al/public_html"
DB_USER="your_db_username"
DB_NAME="qbo_multicompany"
DB_PASS="your_db_password"

echo -e "${YELLOW}Step 1: Checking current directory...${NC}"
if [ ! -d "$PROJECT_DIR" ]; then
    echo -e "${RED}Error: Project directory not found: $PROJECT_DIR${NC}"
    echo "Please update PROJECT_DIR in this script"
    exit 1
fi
cd "$PROJECT_DIR"
echo -e "${GREEN}✓ In project directory: $(pwd)${NC}"
echo ""

echo -e "${YELLOW}Step 2: Pulling latest code from GitHub...${NC}"
git pull origin main
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Code pulled successfully${NC}"
else
    echo -e "${RED}✗ Git pull failed${NC}"
    exit 1
fi
echo ""

echo -e "${YELLOW}Step 3: Checking if database schema file exists...${NC}"
if [ ! -f "sql/email-system-schema.sql" ]; then
    echo -e "${RED}Error: sql/email-system-schema.sql not found${NC}"
    exit 1
fi
echo -e "${GREEN}✓ Schema file found${NC}"
echo ""

echo -e "${YELLOW}Step 4: Importing database schema...${NC}"
echo "This will create email_config, email_templates, and email_logs tables"
read -p "Continue? (y/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < sql/email-system-schema.sql
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✓ Database schema imported successfully${NC}"
    else
        echo -e "${RED}✗ Database import failed${NC}"
        echo "You may need to import manually via phpMyAdmin"
    fi
else
    echo -e "${YELLOW}⊘ Skipped database import${NC}"
fi
echo ""

echo -e "${YELLOW}Step 5: Verifying database tables...${NC}"
TABLES=$(mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SHOW TABLES LIKE 'email%';" -s)
if [ ! -z "$TABLES" ]; then
    echo -e "${GREEN}✓ Email tables found:${NC}"
    echo "$TABLES"
else
    echo -e "${RED}✗ No email tables found${NC}"
fi
echo ""

echo -e "${YELLOW}Step 6: Setting file permissions...${NC}"
chmod 644 public/admin-email-settings.html 2>/dev/null || true
chmod 644 routes/email.php 2>/dev/null || true
chmod 644 src/Services/EmailService.php 2>/dev/null || true
echo -e "${GREEN}✓ Permissions set${NC}"
echo ""

echo -e "${YELLOW}Step 7: Checking if admin-email-settings.html is accessible...${NC}"
if [ -f "public/admin-email-settings.html" ]; then
    echo -e "${GREEN}✓ admin-email-settings.html exists${NC}"
    FILE_SIZE=$(stat -f%z "public/admin-email-settings.html" 2>/dev/null || stat -c%s "public/admin-email-settings.html" 2>/dev/null)
    echo "  File size: $FILE_SIZE bytes"
else
    echo -e "${RED}✗ admin-email-settings.html not found${NC}"
fi
echo ""

echo -e "${YELLOW}Step 8: Restarting PHP-FPM (optional)...${NC}"
read -p "Restart PHP-FPM? (y/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    # Try different PHP-FPM service names
    if sudo systemctl restart php8.1-fpm 2>/dev/null; then
        echo -e "${GREEN}✓ PHP 8.1 FPM restarted${NC}"
    elif sudo systemctl restart php8.0-fpm 2>/dev/null; then
        echo -e "${GREEN}✓ PHP 8.0 FPM restarted${NC}"
    elif sudo systemctl restart php-fpm 2>/dev/null; then
        echo -e "${GREEN}✓ PHP FPM restarted${NC}"
    else
        echo -e "${YELLOW}⊘ Could not restart PHP-FPM (may not be needed)${NC}"
    fi
else
    echo -e "${YELLOW}⊘ Skipped PHP-FPM restart${NC}"
fi
echo ""

echo "================================================"
echo -e "${GREEN}✅ Deployment Complete!${NC}"
echo "================================================"
echo ""
echo "Next steps:"
echo "1. Access: https://devsync.konsulence.al/public/admin-email-settings.html"
echo "2. Configure SMTP settings in Configuration tab"
echo "3. Enable email service"
echo "4. Test connection"
echo "5. Send test email"
echo ""
echo "Troubleshooting:"
echo "- If page doesn't load, check nginx/apache error logs"
echo "- If API returns errors, check PHP error logs"
echo "- If emails don't send, verify SMTP credentials"
echo ""
echo "Documentation: EMAIL-SYSTEM-OVERVIEW.md"
echo "================================================"
