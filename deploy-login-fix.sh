#!/bin/bash
# Quick fix deployment for login issue
# Copies fixed AuthService and runs migration

echo "=== Deploying Login Fix to Production ==="
echo ""

# Navigate to local directory
cd "c:\xampp\htdocs\multi-company-Dev2Qbo" || exit 1

# Copy fixed file to production
echo "1. Uploading fixed AuthService.php..."
scp src/Services/AuthService.php root@78.46.201.151:/var/www/html/src/Services/AuthService.php

# Copy migration script
echo "2. Uploading migration script..."
scp sql/migrations/002-user-management-tables.sql root@78.46.201.151:/var/www/html/sql/migrations/

# Run migration on production
echo "3. Running migration on production..."
ssh root@78.46.201.151 'cd /var/www/html && mysql -u ${DB_USER} -p${DB_PASS} ${DB_NAME} < sql/migrations/002-user-management-tables.sql'

echo ""
echo "=== Deployment Complete ==="
echo "Please test login at https://devsync.konsulence.al"
