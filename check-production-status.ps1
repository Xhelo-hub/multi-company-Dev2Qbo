# Production Server Health Check
# Run this from PowerShell on your local machine

$server = "root@78.46.201.151"

Write-Host "`n=== CHECKING PRODUCTION SERVER ===" -ForegroundColor Cyan

# Check MySQL/MariaDB status
Write-Host "`n1. Checking MySQL/MariaDB status..." -ForegroundColor Yellow
ssh $server "systemctl status mariadb --no-pager | head -15"

# Check MySQL error logs
Write-Host "`n2. Checking MySQL error logs (last 30 lines)..." -ForegroundColor Yellow
ssh $server "tail -30 /var/log/mysql/error.log"

# Check if Git repository exists
Write-Host "`n3. Checking Git repository..." -ForegroundColor Yellow
ssh $server "cd /var/www/html && pwd && ls -la .git 2>&1 | head -5"

# Check Apache status
Write-Host "`n4. Checking Apache status..." -ForegroundColor Yellow
ssh $server "systemctl status apache2 --no-pager | grep -E 'Active|Main PID'"

# Check disk space
Write-Host "`n5. Checking disk space..." -ForegroundColor Yellow
ssh $server "df -h | grep -E 'Filesystem|/dev/sda|/dev/vda'"

# Check if website is accessible
Write-Host "`n6. Checking website accessibility..." -ForegroundColor Yellow
ssh $server "curl -s -o /dev/null -w 'HTTP Status: %{http_code}\n' https://devsync.konsulence.al/public/login.html"

Write-Host "`n=== CHECK COMPLETE ===" -ForegroundColor Cyan
Write-Host "`nNext steps:" -ForegroundColor Green
Write-Host "1. If MySQL is down, you need to fix it first (see fix-mysql.txt)"
Write-Host "2. If .git doesn't exist, we need to reinitialize the repository"
Write-Host "3. After MySQL is running, we can deploy the login fix"
