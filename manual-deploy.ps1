#!/usr/bin/env pwsh
# Manual deployment helper - copy this and paste into SSH session

Write-Host "Copy and paste these commands into your SSH session:" -ForegroundColor Cyan
Write-Host ""
Write-Host "cd /home/converter/web/devsync.konsulence.al/public_html" -ForegroundColor Yellow
Write-Host "git pull origin main" -ForegroundColor Yellow
Write-Host "cd public && cp -v *.html .." -ForegroundColor Yellow
Write-Host ""
Write-Host "Or as one command:" -ForegroundColor Cyan
Write-Host "cd /home/converter/web/devsync.konsulence.al/public_html && git pull origin main && cd public && cp -v *.html .." -ForegroundColor Green
