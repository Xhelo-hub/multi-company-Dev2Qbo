# Deploy login fix to production
# Run this from your local Windows machine

$server = "root@78.46.201.151"
$remotePath = "/var/www/html"

Write-Host "=== Deploying Login Fix ===" -ForegroundColor Cyan
Write-Host ""

# Step 1: Upload fixed AuthService.php
Write-Host "1. Uploading AuthService.php..." -ForegroundColor Yellow
scp src/Services/AuthService.php "${server}:${remotePath}/src/Services/AuthService.php"

if ($LASTEXITCODE -eq 0) {
    Write-Host "   ✓ Uploaded successfully" -ForegroundColor Green
} else {
    Write-Host "   ✗ Upload failed" -ForegroundColor Red
    exit 1
}

# Step 2: Create migration SQL on server and run it
Write-Host ""
Write-Host "2. Creating database tables..." -ForegroundColor Yellow

$sqlScript = @'
CREATE TABLE IF NOT EXISTS user_company_access (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    company_id INT NOT NULL,
    can_view_sync TINYINT(1) DEFAULT 1,
    can_run_sync TINYINT(1) DEFAULT 0,
    can_edit_credentials TINYINT(1) DEFAULT 0,
    can_manage_schedules TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_company (user_id, company_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_token VARCHAR(128) NOT NULL UNIQUE,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user_id (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NULL,
    entity_id INT NULL,
    details JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user_id (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SELECT 'Tables created successfully' AS status;
'@

# Save SQL to temp file
$sqlScript | Out-File -FilePath ".\temp-migration.sql" -Encoding UTF8

# Upload SQL file
scp temp-migration.sql "${server}:/tmp/fix-tables.sql"

# Run migration
ssh $server "mysql -u root -p Xhelo_qbo_devpos < /tmp/fix-tables.sql 2>&1"

if ($LASTEXITCODE -eq 0) {
    Write-Host "   ✓ Tables created" -ForegroundColor Green
} else {
    Write-Host "   ⚠ Migration may have failed (check if tables already exist)" -ForegroundColor Yellow
}

# Cleanup
Remove-Item ".\temp-migration.sql" -ErrorAction SilentlyContinue

Write-Host ""
Write-Host "=== Deployment Complete ===" -ForegroundColor Cyan
Write-Host "Please test login at: https://devsync.konsulence.al" -ForegroundColor White
Write-Host ""
