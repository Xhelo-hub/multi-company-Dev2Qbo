#!/bin/bash
# Deploy Email Configuration System to Production

set -e  # Exit on error

echo "========================================="
echo "Email Configuration System Deployment"
echo "========================================="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    echo -e "${RED}Please run as root (sudo)${NC}"
    exit 1
fi

# Navigate to web directory
cd /var/www/html || { echo -e "${RED}Failed to navigate to /var/www/html${NC}"; exit 1; }

echo -e "${YELLOW}Step 1: Backing up current database...${NC}"
mysqldump -u root -p Xhelo_qbo_devpos > /var/backups/qbo_backup_before_email_config_$(date +%Y%m%d_%H%M%S).sql
echo -e "${GREEN}✓ Database backed up${NC}"
echo ""

echo -e "${YELLOW}Step 2: Pulling latest changes from Git...${NC}"
git pull origin main
echo -e "${GREEN}✓ Code updated${NC}"
echo ""

echo -e "${YELLOW}Step 3: Updating database schema...${NC}"
mysql -u root -p Xhelo_qbo_devpos < sql/add-email-provider-presets.sql
echo -e "${GREEN}✓ Database schema updated${NC}"
echo ""

echo -e "${YELLOW}Step 4: Verifying email provider presets...${NC}"
PRESET_COUNT=$(mysql -u root -p Xhelo_qbo_devpos -se "SELECT COUNT(*) FROM email_provider_presets;")
if [ "$PRESET_COUNT" -ge 6 ]; then
    echo -e "${GREEN}✓ Found $PRESET_COUNT email provider presets${NC}"
else
    echo -e "${RED}⚠ Only found $PRESET_COUNT presets, expected 6${NC}"
fi
echo ""

echo -e "${YELLOW}Step 5: Setting proper file permissions...${NC}"
chown -R www-data:www-data /var/www/html
chmod -R 755 /var/www/html
chmod 644 .env
echo -e "${GREEN}✓ Permissions set${NC}"
echo ""

echo -e "${YELLOW}Step 6: Restarting Apache...${NC}"
systemctl restart apache2
if systemctl is-active --quiet apache2; then
    echo -e "${GREEN}✓ Apache restarted successfully${NC}"
else
    echo -e "${RED}⚠ Apache may not be running properly${NC}"
fi
echo ""

echo -e "${YELLOW}Step 7: Testing API endpoints...${NC}"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" https://devsync.konsulence.al/api/email/providers)
if [ "$HTTP_CODE" -eq 200 ]; then
    echo -e "${GREEN}✓ Email providers API responding (HTTP $HTTP_CODE)${NC}"
else
    echo -e "${RED}⚠ Email providers API returned HTTP $HTTP_CODE${NC}"
fi
echo ""

echo "========================================="
echo -e "${GREEN}Deployment Complete!${NC}"
echo "========================================="
echo ""
echo "Next Steps:"
echo "1. Access: https://devsync.konsulence.al/public/admin-email-config.html"
echo "2. Choose your email provider (Gmail recommended)"
echo "3. Enter credentials and save"
echo "4. Send a test email to verify"
echo ""
echo "For Gmail setup:"
echo "- Generate app password: https://myaccount.google.com/apppasswords"
echo "- Use 16-character password (no spaces)"
echo ""
echo "For support, check: EMAIL-CONFIG-DEPLOYMENT.md"
echo ""
