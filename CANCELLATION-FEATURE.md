# Job Cancellation Feature - Implementation Summary

## Overview
Implemented comprehensive job cancellation functionality for pending and running sync jobs with graceful shutdown mechanisms.

## Changes Deployed

### 1. Database Migration ✅
**File:** `sql/add-cancelled-status.sql`
- Added `'cancelled'` to sync_jobs status ENUM
- Previous values: `pending`, `running`, `completed`, `failed`
- New status distinguishes user-initiated cancellation from errors
- **Status:** Deployed to production database

### 2. SyncExecutor Enhancements ✅
**File:** `src/Services/SyncExecutor.php`

**New Methods:**
- `isJobCancelled(int $jobId): bool` - Checks database for cancellation flag
- `cancelJob(int $jobId, string $message): void` - Marks job as cancelled

**Modified Methods:**
- `executeJob()` - Added cancellation checks between job types (full sync)
- `syncSales()` - Checks for cancellation every 10 invoices
- `syncPurchases()` - Checks for cancellation every 10 purchases  
- `syncBills()` - Checks for cancellation every 10 bills

**How It Works:**
```php
// Every 10 records during sync
if ($index % 10 === 0 && $this->isJobCancelled($jobId)) {
    throw new Exception("Job cancelled by user after processing $synced items");
}
```

### 3. API Endpoints ✅
**File:** `routes/api.php`

#### Updated Endpoints:

**POST `/api/sync/jobs/{jobId}/cancel`**
- Changes: Uses `'cancelled'` status instead of `'failed'`
- Supports cancelling both `pending` and `running` jobs
- Returns previous status in response

**POST `/api/companies/{id}/cancel-jobs`**
- Changes: Uses `'cancelled'` status
- New feature: Can cancel pending jobs via `cancel_pending` parameter
- Default: Cancels both running AND pending jobs
- Example:
  ```json
  POST /api/companies/1/cancel-jobs
  {
    "cancel_pending": true  // false to only cancel running
  }
  ```

**POST `/api/sync/jobs/cancel-stuck`**
- Changes: Uses `'cancelled'` status instead of `'failed'`
- Adds timeout information to error message
- Returns threshold minutes in response

#### New Endpoints:

**POST `/api/sync/jobs/cancel-pending`** ⭐ NEW
- Cancels all pending jobs before they start execution
- Supports company-specific or global cancellation
- Example:
  ```json
  // Cancel pending jobs for specific company
  POST /api/sync/jobs/cancel-pending
  {
    "company_id": 1
  }
  
  // Cancel ALL pending jobs globally
  POST /api/sync/jobs/cancel-pending
  {
    // No company_id = global
  }
  ```
- Returns list of cancelled jobs with details

## How to Use

### Cancel a Single Running/Pending Job
```bash
POST /api/sync/jobs/123/cancel
```

### Cancel All Running/Pending Jobs for a Company
```bash
POST /api/companies/1/cancel-jobs
{
  "cancel_pending": true  # Include pending jobs
}
```

### Cancel All Pending Jobs (Not Started Yet)
```bash
# Specific company
POST /api/sync/jobs/cancel-pending
{ "company_id": 1 }

# All companies
POST /api/sync/jobs/cancel-pending
{}
```

### Cancel Stuck Jobs (Running > 30 minutes)
```bash
POST /api/sync/jobs/cancel-stuck
{
  "minutes": 30  # Optional, default 30
}
```

## Cancellation Behavior

### Pending Jobs
- Cancelled immediately (never started execution)
- Status changed from `pending` → `cancelled`
- No sync operations performed

### Running Jobs
- Graceful shutdown at next checkpoint
- Checks every 10 records during sync
- Completes current record before stopping
- Status changed from `running` → `cancelled`
- Error message indicates how many items were processed

### Example Running Job Cancellation:
```
1. Job starts: status = 'running'
2. Admin calls POST /api/sync/jobs/123/cancel
3. Database updated: status = 'cancelled'
4. SyncExecutor processing invoice 45/100
5. At invoice 50, checks: isJobCancelled() = true
6. Throws exception: "Job cancelled by user after processing 49 invoices"
7. Exception caught in executeJob()
8. Job marked as cancelled with partial results
```

## Database Schema
```sql
status ENUM('pending', 'running', 'completed', 'failed', 'cancelled') 
DEFAULT 'pending'
COMMENT 'pending: waiting to start, running: in progress, 
         completed: finished successfully, failed: error occurred, 
         cancelled: manually stopped'
```

## Testing Checklist

- ✅ Cancel pending job (never executed)
- ✅ Cancel running job (graceful shutdown)
- ✅ Cancel multiple jobs for company
- ✅ Cancel all pending jobs globally
- ✅ Cancel stuck jobs (timeout)
- ✅ Database migration applied
- ✅ Code deployed to production
- ✅ No syntax errors

## Technical Details

**Checkpoint Frequency:** Every 10 records
- Balances responsiveness vs performance
- Running job stops within ~1 second typically
- Could process 10 records after cancellation signal

**Database Polling:** 
- Checks sync_jobs table on each checkpoint
- Minimal overhead (indexed query)
- Allows cancellation from any source (API, dashboard, CLI)

**Error Messages:**
- Pending: "Cancelled before execution by admin"
- Running: "Job cancelled by user after processing X items"
- Stuck: "Job timeout - exceeded X minutes (automatic cancellation)"

## Deployment Info

- **Commit:** 5b9d12d
- **Date:** November 2, 2025
- **Migration:** Applied to Xhelo_qbo_devpos database
- **Production:** Deployed and PHP-FPM restarted

## Future Enhancements

Potential improvements (not implemented yet):
1. Real-time progress updates via WebSocket
2. Resume cancelled jobs from checkpoint
3. Scheduled cancellation (cancel at specific time)
4. Job priorities (cancel low-priority first)
5. User-specific cancellation permissions
6. Cancellation confirmation UI
7. Bulk cancel by job type or date range
