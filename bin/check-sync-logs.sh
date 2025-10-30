#!/bin/bash
# Check logs for DevPos sync debug information

echo "=== Checking PHP-FPM logs for DevPos sync data ==="
echo ""

if [ -f /var/log/php8.3-fpm.log ]; then
    echo "📋 Last 100 lines with DevPos debug info:"
    tail -500 /var/log/php8.3-fpm.log | grep -E "DEBUG: DevPos|Available date fields|Found date in field|ALL FIELDS" | tail -50
    echo ""
    echo "✅ Check complete"
else
    echo "❌ PHP-FPM log not found at /var/log/php8.3-fpm.log"
fi

echo ""
echo "=== Alternative: Check Apache error.log ==="
if [ -f /var/log/apache2/error.log ]; then
    tail -500 /var/log/apache2/error.log | grep -E "DEBUG: DevPos|Available date fields|Found date in field" | tail -20
fi
