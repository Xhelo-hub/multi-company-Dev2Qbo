# Company Isolation & Independent Operations

## How Company Isolation Works

Each company in the multi-company system operates **completely independently**:

### Database-Level Isolation

Every database table has a `company_id` foreign key that scopes all operations:

```sql
-- Company 1 (AEM) data
SELECT * FROM sync_jobs WHERE company_id = 1;

-- Company 2 (PGROUP) data  
SELECT * FROM sync_jobs WHERE company_id = 2;

-- No data mixing - completely isolated
```

**Isolated Tables:**
- ✅ `oauth_tokens_devpos` - Each company has own DevPos tokens
- ✅ `oauth_tokens_qbo` - Each company has own QBO tokens
- ✅ `maps_documents` - Document mappings per company
- ✅ `maps_masterdata` - Customer/vendor mappings per company
- ✅ `sync_cursors` - Sync progress tracking per company
- ✅ `sync_jobs` - Job history per company

### API-Level Isolation

You can interact with each company independently via API:

```powershell
# Sync ONLY Company 1 (AEM)
Invoke-RestMethod -Uri "http://localhost:8081/api/companies/1/sync" `
  -Method Post `
  -Headers @{"X-API-Key"="your-key"; "Content-Type"="application/json"} `
  -Body '{"jobType":"sales","fromDate":"2025-10-01","toDate":"2025-10-31"}'

# Sync ONLY Company 2 (PGROUP) - Company 1 unaffected
Invoke-RestMethod -Uri "http://localhost:8081/api/companies/2/sync" `
  -Method Post `
  -Headers @{"X-API-Key"="your-key"; "Content-Type"="application/json"} `
  -Body '{"jobType":"sales","fromDate":"2025-10-01","toDate":"2025-10-31"}'
```

### Runtime Isolation

When a sync runs for a company, the system:

1. **Loads only that company's credentials** from database
2. **Sets company context** (`$_ENV['CURRENT_COMPANY_ID'] = 1`)
3. **All queries automatically scoped** to that company_id
4. **Uses that company's OAuth tokens** (DevPos + QBO)
5. **Stores results** in that company's job record

```php
// Example: Syncing Company 1
$company = $companyService->getCompanyWithCredentials(1);

// This sync ONLY touches Company 1 data
$_ENV['CURRENT_COMPANY_ID'] = 1;
$_ENV['DEVPOS_TENANT'] = $company['tenant'];        // K43128625A
$_ENV['DEVPOS_USERNAME'] = $company['username'];    // xhelo-aem
$_ENV['QBO_REALM_ID'] = $company['realm_id'];       // 9341453199574798

// All database queries now automatically filter by company_id = 1
$salesSync->syncDateRange('2025-10-01', '2025-10-31');
```

## Running Single Company API

### Option 1: Company-Specific Endpoint

Target a specific company by ID:

```powershell
# Get Company 1 stats only
GET /api/companies/1/stats

# Run sync for Company 1 only
POST /api/companies/1/sync
Body: { "jobType": "sales", "fromDate": "2025-10-01", "toDate": "2025-10-31" }

# Get Company 1 job history only
GET /api/companies/1/jobs?limit=10
```

### Option 2: Company-Scoped API Keys

Create API keys that only work for specific companies:

```sql
-- Create API key that ONLY works for Company 1
INSERT INTO api_keys (key_hash, key_name, company_id, permissions, is_active)
VALUES (
    SHA2('COMPANY1_ONLY_KEY_123', 256),
    'Company 1 - AEM Access',
    1,  -- Limited to company_id = 1
    '["sync:run", "sync:view"]',
    1
);

-- Create API key that ONLY works for Company 2
INSERT INTO api_keys (key_hash, key_name, company_id, permissions, is_active)
VALUES (
    SHA2('COMPANY2_ONLY_KEY_456', 256),
    'Company 2 - PGROUP Access',
    2,  -- Limited to company_id = 2
    '["sync:run", "sync:view"]',
    1
);
```

Now different users can only access their assigned company:

```powershell
# User with Company 1 key can ONLY access Company 1
Invoke-RestMethod -Uri "http://localhost:8081/api/companies/1/sync" `
  -Headers @{"X-API-Key"="COMPANY1_ONLY_KEY_123"}  # ✅ Works

Invoke-RestMethod -Uri "http://localhost:8081/api/companies/2/sync" `
  -Headers @{"X-API-Key"="COMPANY1_ONLY_KEY_123"}  # ❌ 403 Forbidden
```

### Option 3: Separate Dashboard Views

Create company-specific dashboard URLs:

```
# Dashboard for Company 1 only
http://localhost:8081/dashboard?company=1

# Dashboard for Company 2 only
http://localhost:8081/dashboard?company=2

# Admin dashboard (all companies)
http://localhost:8081/multi-company-dashboard
```

### Option 4: CLI Tool Per Company

Run syncs from command line for specific companies:

```powershell
# Sync only Company 1
php bin/sync-company.php --company=1 --from=2025-10-01 --to=2025-10-31

# Sync only Company 2
php bin/sync-company.php --company=2 --from=2025-10-01 --to=2025-10-31

# Check status for Company 1 only
php bin/company-status.php --company=1
```

## Concurrent Operations

Multiple companies can sync **simultaneously** without interference:

```powershell
# Start Company 1 sync (runs in background)
Start-Job { 
    Invoke-RestMethod -Uri "http://localhost:8081/api/companies/1/sync" -Method Post ...
}

# Start Company 2 sync (runs in background)
Start-Job { 
    Invoke-RestMethod -Uri "http://localhost:8081/api/companies/2/sync" -Method Post ...
}

# Both run in parallel, completely isolated
# - Different DevPos tenants
# - Different QBO realms
# - Different database records
# - Different job tracking
```

## Safety Guarantees

### Data Isolation
```php
// Company 1 sync cannot access Company 2 data
$maps->findDocument(1, 'devpos', 'sale', 'INV-001');  // ✅ Company 1 data
$maps->findDocument(2, 'devpos', 'sale', 'INV-001');  // ✅ Company 2 data (different result)
```

### Credential Isolation
```php
// Company 1 uses its own credentials
DevPosClient::authenticate('K43128625A', 'xhelo-aem', 'password1');
QBOClient::setRealmId('9341453199574798');

// Company 2 uses its own credentials (completely different)
DevPosClient::authenticate('M01419018I', 'xhelo-pgroup', 'password2');
QBOClient::setRealmId('1234567890123456');
```

### Job Isolation
```sql
-- Company 1 jobs never appear in Company 2 queries
SELECT * FROM sync_jobs WHERE company_id = 1;  -- Only Company 1 jobs
SELECT * FROM sync_jobs WHERE company_id = 2;  -- Only Company 2 jobs
```

## Use Cases

### Use Case 1: Different Teams
```
Team A manages Company 1 (AEM):
- API Key: TEAM_A_KEY (company_id=1 only)
- Dashboard: /dashboard?company=1
- Can only sync/view Company 1

Team B manages Company 2 (PGROUP):
- API Key: TEAM_B_KEY (company_id=2 only)  
- Dashboard: /dashboard?company=2
- Can only sync/view Company 2
```

### Use Case 2: Different Schedules
```sql
-- Company 1: Daily sync at 2 AM
INSERT INTO sync_schedules (company_id, schedule_name, frequency, cron_expression)
VALUES (1, 'Daily Sync', 'daily', '0 2 * * *');

-- Company 2: Hourly sync
INSERT INTO sync_schedules (company_id, schedule_name, frequency, cron_expression)
VALUES (2, 'Hourly Sync', 'hourly', '0 * * * *');
```

### Use Case 3: Different Sync Ranges
```powershell
# Company 1: Sync all 2025 data
POST /api/companies/1/sync
{ "fromDate": "2025-01-01", "toDate": "2025-12-31" }

# Company 2: Sync only last 7 days
POST /api/companies/2/sync
{ "fromDate": "2025-10-13", "toDate": "2025-10-20" }
```

## Testing Isolation

```powershell
# Test 1: Verify Company 1 sync doesn't affect Company 2
# Before: Check Company 2 job count
$before = (Invoke-RestMethod -Uri "http://localhost:8081/api/companies/2/stats").total_jobs

# Run Company 1 sync
Invoke-RestMethod -Uri "http://localhost:8081/api/companies/1/sync" -Method Post ...

# After: Company 2 job count should be unchanged
$after = (Invoke-RestMethod -Uri "http://localhost:8081/api/companies/2/stats").total_jobs
if ($before -eq $after) { Write-Host "✅ Company 2 unaffected" }

# Test 2: Verify Company 1 has new job
$company1Jobs = (Invoke-RestMethod -Uri "http://localhost:8081/api/companies/1/jobs?limit=1")
if ($company1Jobs.Count -gt 0) { Write-Host "✅ Company 1 job created" }
```

## Summary

✅ **Yes, you can run the API for individual companies without affecting others!**

Each company is:
- 🔒 **Completely isolated** at database level
- 🔑 **Uses its own credentials** (DevPos tenant, QBO realm)
- 📊 **Has its own job history** and statistics
- ⏰ **Can have its own sync schedule** 
- 👥 **Can have its own API keys** and access control
- 🚀 **Can sync in parallel** with other companies

This design ensures that:
- A bug in Company 1 sync won't affect Company 2
- Company 1 credentials never leak to Company 2
- Company 1 data never mixes with Company 2 data
- Each company can be managed independently

**Just use the company ID in the API endpoint:** `/api/companies/{id}/sync`
