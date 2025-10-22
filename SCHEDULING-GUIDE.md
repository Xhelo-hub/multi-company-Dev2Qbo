# Sync Scheduling Guide

## Overview

The multi-company system supports flexible sync scheduling for each company independently:
- ⏰ **Hourly** - Every hour
- 📅 **Daily** - Once per day at specified time
- 📆 **Weekly** - Once per week on specified day
- 📊 **Monthly** - Once per month on specified day
- ⚙️ **Custom** - Using cron expressions for complex schedules

## How Scheduling Works

### Database-Driven Schedules

Each company can have multiple schedules stored in the `sync_schedules` table:

```sql
CREATE TABLE sync_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    schedule_name VARCHAR(100) NOT NULL,
    job_type ENUM('sales', 'purchases', 'full') NOT NULL,
    frequency ENUM('hourly', 'daily', 'weekly', 'monthly', 'custom') DEFAULT 'daily',
    cron_expression VARCHAR(100) NULL,  -- For custom schedules
    time_of_day TIME DEFAULT '02:00:00',  -- For daily/weekly/monthly
    day_of_week INT NULL,  -- 1-7 for weekly (1=Monday)
    day_of_month INT NULL,  -- 1-31 for monthly
    is_active TINYINT(1) DEFAULT 1,
    last_run_at TIMESTAMP NULL,
    next_run_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Schedule Executor

A background process checks for due schedules and executes them:

```php
// bin/schedule-runner.php
while (true) {
    $dueSchedules = $db->query("
        SELECT * FROM sync_schedules 
        WHERE is_active = 1 
        AND next_run_at <= NOW()
    ")->fetchAll();
    
    foreach ($dueSchedules as $schedule) {
        // Create and execute sync job
        $jobId = $syncService->createJob(
            $schedule['company_id'],
            $schedule['job_type'],
            date('Y-m-d', strtotime('-1 day')),  // or custom logic
            date('Y-m-d'),
            'schedule'
        );
        $syncService->executeJob($jobId);
        
        // Update next run time
        $nextRun = calculateNextRun($schedule);
        $db->execute("UPDATE sync_schedules SET next_run_at = ?, last_run_at = NOW() WHERE id = ?", 
                     [$nextRun, $schedule['id']]);
    }
    
    sleep(60);  // Check every minute
}
```

## Creating Schedules

### Method 1: Via Dashboard UI

```
1. Navigate to: http://localhost:8081/companies/1/schedules
2. Click "Add Schedule"
3. Select options:
   - Schedule Name: "Daily Sales Sync"
   - Job Type: Sales / Purchases / Full
   - Frequency: Daily
   - Time: 02:00 AM
4. Save
```

### Method 2: Via CLI Tool

```powershell
# Add daily schedule for Company 1
php bin/schedule-manager.php add --company=1 --type=daily --time="02:00" --job-type=full

# Add weekly schedule (every Monday at 3 AM)
php bin/schedule-manager.php add --company=1 --type=weekly --day=monday --time="03:00" --job-type=sales

# Add monthly schedule (1st of month at midnight)
php bin/schedule-manager.php add --company=1 --type=monthly --day=1 --time="00:00" --job-type=purchases

# Add hourly schedule
php bin/schedule-manager.php add --company=1 --type=hourly --job-type=full

# Add custom cron schedule (every 6 hours)
php bin/schedule-manager.php add --company=1 --type=custom --cron="0 */6 * * *" --job-type=full

# List all schedules for Company 1
php bin/schedule-manager.php list --company=1

# Disable a schedule
php bin/schedule-manager.php disable --schedule-id=5
```

### Method 3: Via API

```powershell
# Create daily schedule
Invoke-RestMethod -Uri "http://localhost:8081/api/companies/1/schedules" `
  -Method Post `
  -Headers @{"X-API-Key"="your-key"; "Content-Type"="application/json"} `
  -Body '{
    "schedule_name": "Daily Full Sync",
    "job_type": "full",
    "frequency": "daily",
    "time_of_day": "02:00:00"
  }'

# Create weekly schedule
Invoke-RestMethod -Uri "http://localhost:8081/api/companies/2/schedules" `
  -Method Post `
  -Headers @{"X-API-Key"="your-key"; "Content-Type"="application/json"} `
  -Body '{
    "schedule_name": "Weekly Sales Sync",
    "job_type": "sales",
    "frequency": "weekly",
    "day_of_week": 1,
    "time_of_day": "03:00:00"
  }'

# Create monthly schedule
Invoke-RestMethod -Uri "http://localhost:8081/api/companies/1/schedules" `
  -Method Post `
  -Headers @{"X-API-Key"="your-key"; "Content-Type"="application/json"} `
  -Body '{
    "schedule_name": "Monthly Full Sync",
    "job_type": "full",
    "frequency": "monthly",
    "day_of_month": 1,
    "time_of_day": "00:00:00"
  }'
```

### Method 4: Direct SQL

```sql
-- Daily sync for Company 1 at 2 AM
INSERT INTO sync_schedules 
(company_id, schedule_name, job_type, frequency, time_of_day, next_run_at)
VALUES (
    1, 
    'Daily Sales Sync', 
    'sales', 
    'daily', 
    '02:00:00',
    DATE_ADD(CURDATE() + INTERVAL 1 DAY, INTERVAL 2 HOUR)
);

-- Weekly sync for Company 2 every Monday at 3 AM
INSERT INTO sync_schedules 
(company_id, schedule_name, job_type, frequency, day_of_week, time_of_day, next_run_at)
VALUES (
    2, 
    'Weekly Full Sync', 
    'full', 
    'weekly', 
    1,  -- Monday
    '03:00:00',
    -- Calculate next Monday 3 AM
    DATE_ADD(
        DATE_ADD(CURDATE(), INTERVAL (8 - DAYOFWEEK(CURDATE())) % 7 DAY),
        INTERVAL 3 HOUR
    )
);

-- Monthly sync for Company 1 on 1st at midnight
INSERT INTO sync_schedules 
(company_id, schedule_name, job_type, frequency, day_of_month, time_of_day, next_run_at)
VALUES (
    1, 
    'Monthly Purchase Sync', 
    'purchases', 
    'monthly', 
    1,  -- 1st of month
    '00:00:00',
    -- Calculate next 1st of month
    DATE_ADD(
        LAST_DAY(CURDATE()),
        INTERVAL 1 DAY
    )
);

-- Custom cron: Every 6 hours
INSERT INTO sync_schedules 
(company_id, schedule_name, job_type, frequency, cron_expression, next_run_at)
VALUES (
    1, 
    'Every 6 Hours Sync', 
    'full', 
    'custom', 
    '0 */6 * * *',
    NOW() + INTERVAL 6 HOUR
);
```

## Schedule Examples

### Example 1: Different Companies, Different Schedules

```sql
-- Company 1 (AEM): Daily at 2 AM
INSERT INTO sync_schedules (company_id, schedule_name, job_type, frequency, time_of_day)
VALUES (1, 'AEM Daily Sync', 'full', 'daily', '02:00:00');

-- Company 2 (PGROUP): Hourly
INSERT INTO sync_schedules (company_id, schedule_name, job_type, frequency)
VALUES (2, 'PGROUP Hourly Sync', 'sales', 'hourly');

-- Company 3: Weekly on Sunday midnight
INSERT INTO sync_schedules (company_id, schedule_name, job_type, frequency, day_of_week, time_of_day)
VALUES (3, 'Company3 Weekly Sync', 'full', 'weekly', 7, '00:00:00');
```

### Example 2: Multiple Schedules Per Company

```sql
-- Company 1: Sales sync hourly during business hours (8 AM - 6 PM)
INSERT INTO sync_schedules (company_id, schedule_name, job_type, frequency, cron_expression)
VALUES (1, 'Business Hours Sales', 'sales', 'custom', '0 8-18 * * 1-5');

-- Company 1: Full sync daily at night
INSERT INTO sync_schedules (company_id, schedule_name, job_type, frequency, time_of_day)
VALUES (1, 'Nightly Full Sync', 'full', 'daily', '02:00:00');

-- Company 1: Monthly report sync on 1st
INSERT INTO sync_schedules (company_id, schedule_name, job_type, frequency, day_of_month, time_of_day)
VALUES (1, 'Monthly Report Sync', 'purchases', 'monthly', 1, '00:00:00');
```

## Running the Scheduler

### Option 1: Background Process (Development)

```powershell
# Start the scheduler (runs continuously)
php bin/schedule-runner.php

# Or run in background on Windows
Start-Process powershell -ArgumentList "-NoExit", "-Command", "php bin/schedule-runner.php"

# Or run in background on Linux
nohup php bin/schedule-runner.php > logs/scheduler.log 2>&1 &
```

### Option 2: System Cron (Production - Linux)

```bash
# Add to crontab (checks every minute)
* * * * * cd /path/to/multi-company-dev2qbo && php bin/schedule-runner.php --once >> logs/scheduler.log 2>&1
```

### Option 3: Windows Task Scheduler (Production - Windows)

```powershell
# Create scheduled task that runs every minute
$action = New-ScheduledTaskAction -Execute "php.exe" -Argument "C:\xampp\htdocs\multi-company-dev2qbo\bin\schedule-runner.php --once"
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration ([TimeSpan]::MaxValue)
Register-ScheduledTask -TaskName "MultiCompanySync" -Action $action -Trigger $trigger
```

### Option 4: Supervisor (Production - Linux)

```ini
; /etc/supervisor/conf.d/multicompany-scheduler.conf
[program:multicompany-scheduler]
command=php /path/to/multi-company-dev2qbo/bin/schedule-runner.php
directory=/path/to/multi-company-dev2qbo
autostart=true
autorestart=true
stderr_logfile=/var/log/multicompany-scheduler.err.log
stdout_logfile=/var/log/multicompany-scheduler.out.log
user=www-data
```

## Cron Expression Reference

For custom schedules using cron expressions:

```
# Format: minute hour day month weekday
# *      *    *   *     *

# Every hour at minute 0
0 * * * *

# Every day at 2:30 AM
30 2 * * *

# Every Monday at 3 AM
0 3 * * 1

# Every 1st of month at midnight
0 0 1 * *

# Every 6 hours
0 */6 * * *

# Business hours (8 AM - 6 PM) on weekdays
0 8-18 * * 1-5

# Every 15 minutes during business hours
*/15 8-18 * * 1-5

# First Monday of each month at 9 AM
0 9 1-7 * 1

# Last day of month at 11 PM
0 23 28-31 * *
```

## Management Commands

```powershell
# View all schedules
php bin/schedule-manager.php list

# View schedules for specific company
php bin/schedule-manager.php list --company=1

# Enable/disable schedule
php bin/schedule-manager.php enable --schedule-id=5
php bin/schedule-manager.php disable --schedule-id=5

# Delete schedule
php bin/schedule-manager.php delete --schedule-id=5

# Test schedule (dry run)
php bin/schedule-manager.php test --schedule-id=5

# Manual trigger (run schedule now)
php bin/schedule-manager.php trigger --schedule-id=5
```

## Monitoring

### Check Schedule Status

```powershell
# View upcoming schedules
php bin/schedule-manager.php upcoming --hours=24

# View schedule execution history
php bin/schedule-manager.php history --schedule-id=5 --limit=10

# Check for missed schedules
php bin/schedule-manager.php check-missed
```

### Dashboard View

The dashboard shows schedule information per company:
- Active schedules
- Last run time
- Next scheduled run
- Success/failure rate
- Quick enable/disable toggle

## Best Practices

### 1. Stagger Company Schedules
```sql
-- Don't sync all companies at same time
Company 1: Daily at 02:00
Company 2: Daily at 03:00
Company 3: Daily at 04:00
```

### 2. Use Different Frequencies Based on Need
```sql
-- High-volume company: Hourly
-- Medium-volume company: Daily
-- Low-volume company: Weekly
```

### 3. Monitor Schedule Health
```sql
-- Alert if schedule hasn't run in 2x expected interval
SELECT * FROM sync_schedules 
WHERE is_active = 1 
AND last_run_at < DATE_SUB(NOW(), INTERVAL 2 DAY)
AND frequency = 'daily';
```

### 4. Handle Failures Gracefully
```sql
-- Automatic retry on failure (in schedule runner)
if ($result['success'] === false) {
    // Log error
    // Optionally send notification
    // Schedule will try again next interval
}
```

## Summary

✅ **Yes, you can schedule syncs on monthly, weekly, daily, or hourly basis!**

Each company can have:
- 📅 **Multiple schedules** (e.g., hourly sales + daily full sync)
- ⏰ **Independent timing** (Company 1 at 2 AM, Company 2 at 3 AM)
- 🎯 **Different frequencies** (Company 1 daily, Company 2 hourly)
- ⚙️ **Custom cron expressions** for complex patterns
- 🔄 **Automatic execution** via background scheduler
- 📊 **Full history tracking** of all scheduled runs

The scheduler runs as a background process and automatically executes syncs based on your configured schedules!
