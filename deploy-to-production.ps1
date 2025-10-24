#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Deploy Multi-Company Dev2QBO to production server
.DESCRIPTION
    Pulls latest code from GitHub and syncs files to production web root
#>

param(
    [switch]$SkipGitPull = $false,
    [switch]$SkipFileSync = $false
)

$SERVER = "root@devsync.konsulence.al"
$REMOTE_PATH = "/home/converter/web/devsync.konsulence.al/public_html"

Write-Host "====================================" -ForegroundColor Cyan
Write-Host "  Production Deployment Script" -ForegroundColor Cyan
Write-Host "====================================" -ForegroundColor Cyan
Write-Host ""

# Step 1: Pull latest code from GitHub on production
if (-not $SkipGitPull) {
    Write-Host "[1/3] Pulling latest code from GitHub..." -ForegroundColor Yellow
    ssh $SERVER "cd $REMOTE_PATH && git pull origin main"
    
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ERROR: Git pull failed!" -ForegroundColor Red
        exit 1
    }
    Write-Host "✓ Git pull completed" -ForegroundColor Green
    Write-Host ""
}

# Step 2: Sync HTML files to document root (excluding dashboard.html)
if (-not $SkipFileSync) {
    Write-Host "[2/3] Syncing HTML files to document root..." -ForegroundColor Yellow
    ssh $SERVER "cd $REMOTE_PATH && find public/ -maxdepth 1 -name '*.html' ! -name 'dashboard.html' -exec cp -v {} . \;"
    
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ERROR: Failed to copy HTML files!" -ForegroundColor Red
        exit 1
    }
    Write-Host "✓ HTML files synced (dashboard.html excluded)" -ForegroundColor Green
    Write-Host ""
}

# Step 3: Sync static assets (CSS, JS, etc.)
if (-not $SkipFileSync) {
    Write-Host "[3/3] Syncing static assets..." -ForegroundColor Yellow
    ssh $SERVER @"
cd $REMOTE_PATH
if [ -d public/css ]; then cp -rv public/css .; fi
if [ -d public/js ]; then cp -rv public/js .; fi  
if [ -d public/assets ]; then cp -rv public/assets .; fi
"@
    
    if ($LASTEXITCODE -ne 0) {
        Write-Host "WARNING: Some assets may not have been copied" -ForegroundColor Yellow
    } else {
        Write-Host "✓ Assets synced" -ForegroundColor Green
    }
    Write-Host ""
}

# Verification
Write-Host "====================================" -ForegroundColor Cyan
Write-Host "  Deployment Complete!" -ForegroundColor Green
Write-Host "====================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Site URL: https://devsync.konsulence.al/" -ForegroundColor Cyan
Write-Host "Main Dashboard: https://devsync.konsulence.al/app.html" -ForegroundColor Cyan
Write-Host "Field Mappings: https://devsync.konsulence.al/admin-field-mappings.html" -ForegroundColor Cyan
Write-Host ""
Write-Host "Run with -SkipGitPull to skip git pull" -ForegroundColor Gray
Write-Host "Run with -SkipFileSync to skip file sync" -ForegroundColor Gray
