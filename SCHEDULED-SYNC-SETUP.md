# Scheduled Sync Setup Guide

## Overview
The system now includes **built-in scheduled sync** with automatic token refresh. No external cron setup needed from users - just one server-side cron job!

## Architecture

```
┌─────────────────┐
│   Server Cron   │  Run every hour: php bin/run-scheduled-syncs.php
└────────┬────────┘
         │
         ▼
┌─────────────────────────┐
│ Check scheduled_syncs   │  Find schedules due to run
│ table for due jobs      │
└────────┬────────────────┘
         │
         ▼
┌─────────────────────────┐
│  For each schedule:     │
│  1. Check QBO token     │  ← AUTO-REFRESH HAPPENS HERE
│  2. Refresh if needed   │
│  3. Run sync            │
│  4. Update next_run_at  │
└─────────────────────────┘
```

## Setup (One-Time Server Configuration)

### Step 1: Create Database Table

```bash
ssh root@78.46.201.151
cd /home/converter/web/devsync.konsulence.al/public_html
mariadb -u Xhelo_qbo_user -p'Albania@2030' Xhelo_qbo_devpos < sql/scheduled_syncs.sql
```

### Step 2: Set Up Server Cron

```bash
# Edit crontab
crontab -e

# Add this line (runs every hour at :00)
0 * * * * cd /home/converter/web/devsync.konsulence.al/public_html && /usr/bin/php bin/run-scheduled-syncs.php >> /var/log/scheduled-syncs.log 2>&1
```

**That's it!** Server setup is complete. Users can now manage schedules via dashboard.

## User Experience

### From Dashboard UI (Coming Soon):

Users will see a new "Scheduled Syncs" card with:

- **Frequency selector:** Hourly / Daily / Weekly / Monthly
- **Time picker:** What time to run (e.g., 9:00 AM)
- **Job type:** Sales / Bills / Full sync
- **Date range:** Last 7/30/60/90 days
- **Enable/Disable toggle**
- **Next run display:** "Next sync: Tomorrow at 9:00 AM"

### API Usage (Available Now):

```javascript
// Get current schedules
GET /api/companies/4/schedules

// Create/update schedule
POST /api/companies/4/schedules
{
  "job_type": "full",           // sales, bills, or full
  "frequency": "daily",          // hourly, daily, weekly, monthly
  "hour_of_day": 9,             // 0-23
  "day_of_week": null,          // 0-6 for weekly (0=Sunday)
  "day_of_month": null,         // 1-31 for monthly
  "date_range_days": 30,        // How many days back to sync
  "enabled": true
}

// Enable/disable schedule
PATCH /api/schedules/{id}/toggle
{
  "enabled": false
}

// Delete schedule
DELETE /api/schedules/{id}
```

## How Token Auto-Refresh Works

When `run-scheduled-syncs.php` executes:

1. Finds schedules due to run (WHERE `next_run_at <= NOW()`)
2. For each schedule, calls `$syncService->queueSync()`
3. `SyncExecutor` gets QBO credentials
4. **`ensureFreshToken()` checks expiration**:
   - Expires in >10 min → Use existing token ✓
   - Expires in <10 min → Auto-refresh token ✓
   - Already expired → Auto-refresh token ✓
5. Sync runs with fresh token
6. Updates `next_run_at` for next scheduled run

**Users never interact with tokens - everything automatic!**

## Example Schedules

### Daily Full Sync at 9 AM
```json
{
  "job_type": "full",
  "frequency": "daily",
  "hour_of_day": 9,
  "date_range_days": 30
}
```

### Hourly Sales Sync (last 24 hours)
```json
{
  "job_type": "sales",
  "frequency": "hourly",
  "date_range_days": 1
}
```

### Weekly Bills Sync (Mondays at 8 AM)
```json
{
  "job_type": "bills",
  "frequency": "weekly",
  "hour_of_day": 8,
  "day_of_week": 1,
  "date_range_days": 7
}
```

### Monthly Full Sync (1st of month at 6 AM)
```json
{
  "job_type": "full",
  "frequency": "monthly",
  "hour_of_day": 6,
  "day_of_month": 1,
  "date_range_days": 60
}
```

## Monitoring

### Check Schedule Status

```bash
ssh root@78.46.201.151
mariadb -u Xhelo_qbo_user -p'Albania@2030' Xhelo_qbo_devpos -e "
SELECT 
  ss.id,
  c.company_name,
  ss.job_type,
  ss.frequency,
  ss.enabled,
  ss.last_run_at,
  ss.next_run_at
FROM scheduled_syncs ss
JOIN companies c ON ss.company_id = c.id
ORDER BY ss.next_run_at;"
```

### View Sync Logs

```bash
tail -f /var/log/scheduled-syncs.log
```

### Manual Test Run

```bash
cd /home/converter/web/devsync.konsulence.al/public_html
php bin/run-scheduled-syncs.php
```

## Benefits

✅ **Zero User Configuration** - Users don't set up cron jobs  
✅ **Auto Token Refresh** - Tokens refresh automatically before expiring  
✅ **Per-Company Schedules** - Each company has independent schedule  
✅ **Flexible Timing** - Hourly, daily, weekly, monthly options  
✅ **Easy Management** - Enable/disable from dashboard  
✅ **Reliable** - Syncs run even if user is offline  
✅ **Logged** - All runs logged for troubleshooting  
✅ **Self-Healing** - If token expired, auto-refreshes and continues  

## Next Steps

1. ✅ Server cron configured (one-time)
2. ✅ API endpoints deployed
3. 🔄 Add UI to dashboard (optional - can use API directly)
4. ✅ Users configure schedules via API or dashboard
5. ✅ Syncs run automatically in background

**Users enjoy "set it and forget it" experience! 🎉**
