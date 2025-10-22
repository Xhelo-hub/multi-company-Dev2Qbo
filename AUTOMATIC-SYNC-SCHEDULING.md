# Automatic Sync Scheduling - Quick Guide

## ✅ YES! You can set up automatic syncs with predefined frequencies

The system now includes an easy-to-use schedule management interface with **quick templates** for common sync patterns.

## 🚀 Quick Start

### Step 1: Access Schedule Manager
1. Login to your dashboard: `http://localhost/multi-company-Dev2Qbo/public/app.html`
2. Select your company
3. Click **⏰ Manage Sync Schedules** button

### Step 2: Choose a Template

The system provides three ready-to-use templates:

#### 📅 Daily at 1:00 PM
- Perfect for: Daily end-of-day syncs
- Runs every day at 13:00 (1:00 PM)
- Job type: Full Sync
- **One click setup!**

#### 📆 Weekly (Friday 5:00 PM)
- Perfect for: Weekly reconciliation
- Runs every Friday at 17:00 (5:00 PM)
- Job type: Full Sync
- **One click setup!**

#### 📊 Monthly (1st of Month, 1:00 AM)
- Perfect for: Monthly closing
- Runs on the 1st day of each month at 01:00 (1:00 AM)
- Job type: Full Sync
- **One click setup!**

### Step 3: Or Create Custom Schedule

Click **⚙️ Custom Schedule** to configure:

**Frequencies Available:**
- **Hourly** - Every hour
- **Daily** - Once per day (choose time)
- **Weekly** - Once per week (choose day + time)
- **Monthly** - Once per month (choose day of month + time)

**Job Types:**
- **Full Sync (All)** - Syncs sales, purchases, and bills
- **Sales Invoices Only** - Just sales data
- **Purchase Invoices Only** - Just purchase data
- **Bills Only** - Just bills

## 📋 Managing Schedules

### View Active Schedules
All schedules are listed in the modal with:
- Schedule name and type
- Frequency details
- Next run time
- Last run time
- Active/Paused status

### Pause/Activate Schedule
Click the **⏸ Pause** or **▶ Activate** button on any schedule.

### Delete Schedule
Click the **🗑 Delete** button and confirm.

## ⚡ Examples

### Example 1: Daily Sales Sync
```
Template: Custom Schedule
Name: "Daily Sales Sync"
Type: Sales Invoices Only
Frequency: Daily
Time: 13:00 (1:00 PM)

Result: Syncs sales invoices every day at 1:00 PM
```

### Example 2: Weekend Full Sync
```
Template: Custom Schedule
Name: "Weekend Full Reconciliation"
Type: Full Sync (All)
Frequency: Weekly
Day: Sunday
Time: 02:00 (2:00 AM)

Result: Full sync every Sunday at 2:00 AM
```

### Example 3: Monthly Closing
```
Template: Monthly (1st of Month, 1:00 AM)

Result: Full sync on the 1st of every month at 1:00 AM
```

### Example 4: Multiple Schedules for One Company
You can have multiple schedules running for the same company:

```
Schedule 1: Hourly sales sync (during business hours via custom cron)
Schedule 2: Daily full sync at night (2:00 AM)
Schedule 3: Monthly reconciliation (1st of month)
```

## 🔄 How Schedules Execute

### Background Scheduler Required

Schedules are stored in the database and executed by a background process:

**For Development/Testing:**
```powershell
# Run the scheduler (checks every minute for due schedules)
php bin/schedule-runner.php
```

**For Production:**

#### Windows (Task Scheduler)
1. Open Task Scheduler
2. Create Basic Task
3. Trigger: On a schedule → Daily
4. Action: Start a program → `php.exe`
5. Arguments: `C:\path\to\multi-company-Dev2Qbo\bin\schedule-runner.php`
6. Repeat every: 1 minute

#### Linux (Crontab)
```bash
# Edit crontab
crontab -e

# Add this line (runs every minute)
* * * * * cd /path/to/multi-company-Dev2Qbo && php bin/schedule-runner.php --once >> logs/scheduler.log 2>&1
```

### What Happens When Schedule Runs

1. Background scheduler checks for due schedules
2. Creates a sync job in `sync_jobs` table
3. Executes the sync (DevPos → QuickBooks)
4. Updates schedule's `last_run_at` timestamp
5. Calculates and sets `next_run_at` timestamp
6. Results stored in sync job history

## 🎯 Pre-Configured Frequencies

### Daily at 1:00 PM
```json
{
  "frequency": "daily",
  "time_of_day": "13:00:00"
}
```
**Use case:** End-of-business-day sync

### Weekly Friday 5:00 PM
```json
{
  "frequency": "weekly",
  "day_of_week": 5,
  "time_of_day": "17:00:00"
}
```
**Use case:** Weekly reconciliation before weekend

### Monthly 1st at 1:00 AM
```json
{
  "frequency": "monthly",
  "day_of_month": 1,
  "time_of_day": "01:00:00"
}
```
**Use case:** Monthly closing and reporting

## 📊 Schedule Database Structure

Schedules are stored in `sync_schedules` table:

```sql
CREATE TABLE sync_schedules (
    id INT PRIMARY KEY,
    company_id INT NOT NULL,
    schedule_name VARCHAR(100),
    job_type ENUM('sales', 'purchases', 'bills', 'full'),
    frequency ENUM('hourly', 'daily', 'weekly', 'monthly', 'custom'),
    time_of_day TIME,           -- e.g., '13:00:00'
    day_of_week INT,            -- 1-7 (Monday-Sunday)
    day_of_month INT,           -- 1-31
    cron_expression VARCHAR(100), -- For custom schedules
    is_active TINYINT(1),
    last_run_at TIMESTAMP,
    next_run_at TIMESTAMP,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

## 🔐 Permissions

- **Admin Users**: Can create/manage schedules for all companies
- **Company Users**: Can create/manage schedules for assigned companies
- Requires `can_manage_schedules` permission in `user_company_access` table

## 🎨 UI Features

### Quick Templates Section
- Visual grid of common schedules
- One-click activation
- Pre-configured optimal settings

### Custom Schedule Form
- Full control over all parameters
- Smart field visibility (shows only relevant options)
- Real-time validation

### Active Schedules List
- Color-coded status (blue = active, gray = paused)
- Quick actions (pause/activate/delete)
- Next run preview
- Last run timestamp

## ⚠️ Important Notes

1. **Scheduler Must Be Running**: Schedules won't execute automatically unless the background scheduler process is running.

2. **Server Time**: All times are in server time zone. Make sure your server clock is correct.

3. **Missed Schedules**: If scheduler was offline and missed a schedule, it will execute it on next check.

4. **Multiple Schedules**: You can have multiple schedules for the same company (e.g., hourly + daily).

5. **Credentials Required**: Company must have DevPos and QuickBooks credentials configured for schedules to work.

## 🛠️ Troubleshooting

### Schedule not running?
1. Check if scheduler process is running: `ps aux | grep schedule-runner`
2. Check scheduler logs: `tail -f logs/scheduler.log`
3. Verify schedule is active (not paused)
4. Check `next_run_at` timestamp in database

### Schedule runs but sync fails?
1. Check sync job history in dashboard
2. Verify DevPos credentials are correct
3. Verify QuickBooks connection is active
4. Check error logs in `sync_jobs` table

### Want to test a schedule immediately?
Use the manual sync button in the dashboard, or update `next_run_at` to current time:
```sql
UPDATE sync_schedules 
SET next_run_at = NOW() 
WHERE id = YOUR_SCHEDULE_ID;
```

## 📖 API Endpoints

### Get Schedules
```
GET /api/companies/{companyId}/schedules
```

### Create Schedule
```
POST /api/companies/{companyId}/schedules
Body: {
  "schedule_name": "Daily Sync",
  "job_type": "full",
  "frequency": "daily",
  "time_of_day": "13:00:00"
}
```

### Update Schedule (Pause/Activate)
```
PUT /api/companies/{companyId}/schedules/{scheduleId}
Body: { "is_active": 1 }
```

### Delete Schedule
```
DELETE /api/companies/{companyId}/schedules/{scheduleId}
```

## ✨ Summary

**Yes, you absolutely can set up automatic syncs!**

✅ Daily at 1:00 PM → One click template  
✅ Weekly Friday 5:00 PM → One click template  
✅ Monthly 1st at 1:00 AM → One click template  
✅ Custom schedules → Full flexibility  

Just open the schedule manager, click a template, and you're done! The background scheduler will handle the rest automatically.

**Next Steps:**
1. Configure your company credentials (DevPos + QuickBooks)
2. Open schedule manager and pick a template
3. Start the background scheduler process
4. Monitor sync history in the dashboard

🎉 That's it! Your syncs will run automatically on schedule!
