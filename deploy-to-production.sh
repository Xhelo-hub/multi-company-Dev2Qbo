#!/bin/bash
# Deploy Multi-Company Dev2QBO to production server

set -e  # Exit on error

SERVER="root@devsync.konsulence.al"
REMOTE_PATH="/home/converter/web/devsync.konsulence.al/public_html"

echo "===================================="
echo "  Production Deployment Script"
echo "===================================="
echo ""

# Step 1: Pull latest code from GitHub on production
echo "[1/3] Pulling latest code from GitHub..."
ssh $SERVER "cd $REMOTE_PATH && git pull origin main"
echo "✓ Git pull completed"
echo ""

# Step 2: Sync HTML files to document root
echo "[2/3] Syncing HTML files to document root..."
ssh $SERVER "cd $REMOTE_PATH && cp -v public/*.html ."
echo "✓ HTML files synced"
echo ""

# Step 3: Sync static assets (CSS, JS, etc.)
echo "[3/3] Syncing static assets..."
ssh $SERVER << 'EOF'
cd /home/converter/web/devsync.konsulence.al/public_html
if [ -d public/css ]; then cp -rv public/css .; fi
if [ -d public/js ]; then cp -rv public/js .; fi  
if [ -d public/assets ]; then cp -rv public/assets .; fi
EOF
echo "✓ Assets synced"
echo ""

# Verification
echo "===================================="
echo "  Deployment Complete!"
echo "===================================="
echo ""
echo "Site URL: https://devsync.konsulence.al/"
echo "Dashboard: https://devsync.konsulence.al/dashboard.html"
echo "Field Mappings: https://devsync.konsulence.al/admin-field-mappings.html"
