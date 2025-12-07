#!/bin/bash
# Server Health Check for devsync.konsulence.al
# Run this on production server

echo "======================================"
echo "  Server Health Check"
echo "======================================"
echo ""

# 1. Check services status
echo "1. Service Status:"
echo "-------------------"
services=("apache2" "mysql" "hestia")
for service in "${services[@]}"; do
    if systemctl is-active --quiet $service; then
        echo "✓ $service: Running"
    else
        echo "✗ $service: NOT RUNNING"
    fi
done
echo ""

# 2. Check Apache/PHP
echo "2. Web Server:"
echo "-------------------"
if curl -s -o /dev/null -w "%{http_code}" http://localhost | grep -q "200"; then
    echo "✓ Apache responding on localhost"
else
    echo "✗ Apache not responding"
fi

php -v | head -1
echo ""

# 3. Check MySQL
echo "3. Database:"
echo "-------------------"
if mysql -u root -p${MYSQL_ROOT_PASSWORD:-''} -e "SELECT 1" &>/dev/null; then
    echo "✓ MySQL accessible"
    mysql -u root -e "SHOW DATABASES;" 2>/dev/null | grep -q "Xhelo_qbo_devpos" && echo "✓ Database Xhelo_qbo_devpos exists" || echo "✗ Database missing"
else
    echo "⚠ MySQL check skipped (password required)"
fi
echo ""

# 4. Check application files
echo "4. Application Files:"
echo "-------------------"
cd /var/www/html || exit 1

files=("vendor/autoload.php" ".env" "bootstrap/app.php" "public/index.php" "src/Services/AuthService.php")
for file in "${files[@]}"; do
    if [ -f "$file" ]; then
        echo "✓ $file exists"
    else
        echo "✗ $file MISSING"
    fi
done
echo ""

# 5. Check git status
echo "5. Git Status:"
echo "-------------------"
git rev-parse --abbrev-ref HEAD
echo "Last commit: $(git log -1 --oneline)"
if [ -n "$(git status --porcelain)" ]; then
    echo "⚠ Uncommitted changes detected"
else
    echo "✓ Repository clean"
fi
echo ""

# 6. Check file permissions
echo "6. File Permissions:"
echo "-------------------"
webuser=$(ps aux | grep -E 'apache|httpd' | grep -v root | head -1 | awk '{print $1}')
echo "Web server user: $webuser"
if [ -w "storage" ] || [ -w "." ]; then
    echo "✓ Web directory writable"
else
    echo "⚠ Permission issues detected"
fi
echo ""

# 7. Check disk space
echo "7. Disk Usage:"
echo "-------------------"
df -h / | tail -1 | awk '{print "Used: " $3 " / " $2 " (" $5 ")"}'
echo ""

# 8. Check PHP error log
echo "8. Recent PHP Errors (last 10):"
echo "-------------------"
if [ -f "/var/log/apache2/error.log" ]; then
    tail -10 /var/log/apache2/error.log | grep -i "php\|fatal\|error" | tail -5 || echo "No recent PHP errors"
else
    echo "Log file not found"
fi
echo ""

# 9. Check application health
echo "9. Application Health:"
echo "-------------------"
echo "Testing vendor autoload..."
php -r "require '/var/www/html/vendor/autoload.php'; echo 'OK';" 2>&1

echo ""
echo "Testing .env loading..."
php -r "require '/var/www/html/vendor/autoload.php'; \$d = Dotenv\Dotenv::createImmutable('/var/www/html'); \$d->load(); echo 'OK';" 2>&1

echo ""
echo "Testing database connection..."
php -r "require '/var/www/html/vendor/autoload.php'; \$d = Dotenv\Dotenv::createImmutable('/var/www/html'); \$d->load(); try { \$pdo = new PDO('mysql:host=' . \$_ENV['DB_HOST'] . ';dbname=' . \$_ENV['DB_NAME'], \$_ENV['DB_USER'], \$_ENV['DB_PASS']); echo 'OK'; } catch (Exception \$e) { echo 'FAILED: ' . \$e->getMessage(); }" 2>&1

echo ""
echo ""

# 10. Check critical tables
echo "10. Database Tables:"
echo "-------------------"
php -r "
require '/var/www/html/vendor/autoload.php';
\$d = Dotenv\Dotenv::createImmutable('/var/www/html');
\$d->load();
try {
    \$pdo = new PDO('mysql:host=' . \$_ENV['DB_HOST'] . ';dbname=' . \$_ENV['DB_NAME'], \$_ENV['DB_USER'], \$_ENV['DB_PASS']);
    \$tables = \$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    \$critical = ['users', 'companies', 'user_sessions', 'user_company_access', 'sync_jobs'];
    foreach (\$critical as \$table) {
        if (in_array(\$table, \$tables)) {
            echo '✓ ' . \$table . PHP_EOL;
        } else {
            echo '✗ ' . \$table . ' MISSING' . PHP_EOL;
        }
    }
} catch (Exception \$e) {
    echo 'Database check failed: ' . \$e->getMessage();
}
" 2>&1

echo ""
echo "======================================"
echo "  Health Check Complete"
echo "======================================"
