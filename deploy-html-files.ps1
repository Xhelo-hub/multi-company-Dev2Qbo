# Deploy HTML files to production server
# This script uploads all modified HTML files to devsync.konsulence.al

$localPath = "c:\xampp\htdocs\multi-company-Dev2Qbo\public"
$serverUser = "devsync"
$serverHost = "devsync.konsulence.al"
$serverPath = "~/web/devsync.konsulence.al/public_html/public"

# List of HTML files to deploy
$htmlFiles = @(
    "login.html",
    "app.html",
    "admin-users.html",
    "admin-companies.html",
    "admin-audit.html",
    "admin-test.html",
    "transactions.html",
    "register.html",
    "password-recovery.html",
    "profile.html",
    "dashboard.html"
)

Write-Host "Starting deployment of HTML files..." -ForegroundColor Green
Write-Host ""

foreach ($file in $htmlFiles) {
    $localFile = Join-Path $localPath $file
    
    if (Test-Path $localFile) {
        Write-Host "Uploading $file..." -ForegroundColor Yellow
        
        # Create a temporary base64 encoded file for transfer
        $content = Get-Content $localFile -Raw -Encoding UTF8
        $bytes = [System.Text.Encoding]::UTF8.GetBytes($content)
        $base64 = [Convert]::ToBase64String($bytes)
        
        # Upload via SSH using base64 encoding to avoid special character issues
        $sshCommand = "echo '$base64' | base64 -d > $serverPath/$file"
        
        ssh "$serverUser@$serverHost" $sshCommand
        
        if ($LASTEXITCODE -eq 0) {
            Write-Host "  ✓ $file uploaded successfully" -ForegroundColor Green
        } else {
            Write-Host "  ✗ Failed to upload $file" -ForegroundColor Red
        }
    } else {
        Write-Host "  ⚠ $file not found locally" -ForegroundColor Yellow
    }
}

Write-Host ""
Write-Host "Deployment complete!" -ForegroundColor Green
Write-Host "Test at: https://devsync.konsulence.al/public/login.html" -ForegroundColor Cyan
