# Automatic Job Timeout Setup

## Overview
The `bin/cancel-stuck-jobs.php` script automatically cancels sync jobs that have been running for more than 30 minutes (configurable).

## Linux/Production Server Setup (Recommended)

### 1. Make the script executable
```bash
chmod +x /home/converter/web/devsync.konsulence.al/public_html/bin/cancel-stuck-jobs.php
```

### 2. Test the script manually
```bash
cd /home/converter/web/devsync.konsulence.al/public_html
php bin/cancel-stuck-jobs.php
```

Expected output:
```
[2025-10-28 10:00:00] No stuck jobs found.
```

Or if stuck jobs exist:
```
[2025-10-28 10:00:00] Found 2 stuck job(s):
  - Job #126 (Company 1, bills): Running for 45 minutes
  - Job #127 (Company 2, sales): Running for 32 minutes
[2025-10-28 10:00:00] Successfully cancelled 2 job(s).
```

### 3. Add to crontab
```bash
crontab -e
```

Add this line to run every 15 minutes:
```bash
*/15 * * * * /usr/bin/php /home/converter/web/devsync.konsulence.al/public_html/bin/cancel-stuck-jobs.php >> /var/log/sync-timeout.log 2>&1
```

Or run every 10 minutes:
```bash
*/10 * * * * /usr/bin/php /home/converter/web/devsync.konsulence.al/public_html/bin/cancel-stuck-jobs.php >> /var/log/sync-timeout.log 2>&1
```

### 4. Verify cron job is running
```bash
# Check crontab
crontab -l

# Check logs after 15 minutes
tail -f /var/log/sync-timeout.log
```

## Windows/Local Development Setup

### Option A: Task Scheduler (GUI)

1. Open Task Scheduler (taskschd.msc)
2. Click "Create Basic Task"
3. Name: "Dev2QBO Cancel Stuck Jobs"
4. Trigger: Daily
5. Start time: 00:00:00
6. Action: Start a program
   - Program: `C:\xampp\php\php.exe`
   - Arguments: `C:\xampp\htdocs\multi-company-Dev2Qbo\bin\cancel-stuck-jobs.php`
   - Start in: `C:\xampp\htdocs\multi-company-Dev2Qbo`
7. In Triggers tab, edit to repeat every 15 minutes for a duration of 1 day
8. Save

### Option B: PowerShell Script

Create a scheduled task via PowerShell:

```powershell
$action = New-ScheduledTaskAction -Execute "C:\xampp\php\php.exe" -Argument "C:\xampp\htdocs\multi-company-Dev2Qbo\bin\cancel-stuck-jobs.php" -WorkingDirectory "C:\xampp\htdocs\multi-company-Dev2Qbo"

$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 15) -RepetitionDuration ([TimeSpan]::MaxValue)

Register-ScheduledTask -Action $action -Trigger $trigger -TaskName "Dev2QBO-CancelStuckJobs" -Description "Automatically cancel stuck sync jobs"
```

To remove the task:
```powershell
Unregister-ScheduledTask -TaskName "Dev2QBO-CancelStuckJobs" -Confirm:$false
```

## Configuration

### Change Timeout Duration
Edit `bin/cancel-stuck-jobs.php` line 14:

```php
$TIMEOUT_MINUTES = 30; // Default is 30 minutes
```

Common values:
- `15` - For testing or quick syncs
- `30` - Default (recommended)
- `60` - For large data syncs
- `120` - For very large imports

### Change Check Frequency
Edit the cron schedule:
- `*/5 * * * *` - Every 5 minutes (aggressive)
- `*/10 * * * *` - Every 10 minutes
- `*/15 * * * *` - Every 15 minutes (recommended)
- `*/30 * * * *` - Every 30 minutes (relaxed)

## Monitoring

### Check for stuck jobs manually
```bash
mysql -u Xhelo_qbo_user -p"Albania@2030" Xhelo_qbo_devpos -e "
SELECT id, company_id, job_type, status, 
       started_at, 
       TIMESTAMPDIFF(MINUTE, started_at, NOW()) as minutes_running
FROM sync_jobs 
WHERE status = 'running' 
ORDER BY started_at ASC"
```

### Check cancelled jobs
```bash
mysql -u Xhelo_qbo_user -p"Albania@2030" Xhelo_qbo_devpos -e "
SELECT id, company_id, job_type, status, 
       error_message, 
       started_at, 
       completed_at
FROM sync_jobs 
WHERE error_message LIKE '%timeout%' 
ORDER BY completed_at DESC 
LIMIT 10"
```

### View timeout script logs
```bash
# Production
tail -f /var/log/sync-timeout.log

# Or check system logs
grep "cancel-stuck-jobs" /var/log/syslog
```

## Troubleshooting

### Script doesn't run
1. Check PHP path: `which php` (Linux) or `where php` (Windows)
2. Check script permissions: `ls -l bin/cancel-stuck-jobs.php`
3. Check cron logs: `grep CRON /var/log/syslog`
4. Test manually: `php bin/cancel-stuck-jobs.php`

### Jobs not being cancelled
1. Check timeout threshold is correct
2. Verify database connection in .env
3. Check for errors: `php bin/cancel-stuck-jobs.php` (manual run)
4. Verify jobs are actually in 'running' state

### Too many false positives
- Increase `$TIMEOUT_MINUTES` to 45 or 60
- Check if syncs legitimately take longer
- Review sync performance optimization

## Production Deployment

```bash
# 1. Upload the script
scp bin/cancel-stuck-jobs.php root@78.46.201.151:/home/converter/web/devsync.konsulence.al/public_html/bin/

# 2. SSH to server
ssh root@78.46.201.151

# 3. Navigate to project
cd /home/converter/web/devsync.konsulence.al/public_html

# 4. Make executable
chmod +x bin/cancel-stuck-jobs.php

# 5. Test it
php bin/cancel-stuck-jobs.php

# 6. Add to crontab
crontab -e
# Add: */15 * * * * /usr/bin/php /home/converter/web/devsync.konsulence.al/public_html/bin/cancel-stuck-jobs.php >> /var/log/sync-timeout.log 2>&1

# 7. Verify cron
crontab -l
```

## Quick Start Commands

### Production Server
```bash
# Deploy and setup in one go
ssh root@78.46.201.151 << 'EOF'
cd /home/converter/web/devsync.konsulence.al/public_html
chmod +x bin/cancel-stuck-jobs.php
php bin/cancel-stuck-jobs.php
(crontab -l 2>/dev/null; echo "*/15 * * * * /usr/bin/php /home/converter/web/devsync.konsulence.al/public_html/bin/cancel-stuck-jobs.php >> /var/log/sync-timeout.log 2>&1") | crontab -
crontab -l
EOF
```

### Local Development
```powershell
# Test the script
cd C:\xampp\htdocs\multi-company-Dev2Qbo
php bin\cancel-stuck-jobs.php

# Setup scheduled task
$action = New-ScheduledTaskAction -Execute "C:\xampp\php\php.exe" -Argument "bin\cancel-stuck-jobs.php" -WorkingDirectory "C:\xampp\htdocs\multi-company-Dev2Qbo"
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 15) -RepetitionDuration ([TimeSpan]::MaxValue)
Register-ScheduledTask -Action $action -Trigger $trigger -TaskName "Dev2QBO-CancelStuckJobs" -Description "Cancel stuck sync jobs"
```

## Notes
- The script only cancels jobs in 'running' status
- Cancelled jobs are marked as 'failed' with a clear error message
- The script logs all actions for audit purposes
- Safe to run multiple times - idempotent operation
- No impact on properly running jobs (under timeout threshold)
