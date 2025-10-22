# Multi-Company DevPos to QuickBooks Sync

Multi-company synchronization API that manages DevPos to QuickBooks Online integration for multiple companies with isolated credentials, independent sync operations, and flexible scheduling.

## Features

- ✅ **Multi-Company Support** - Manage unlimited companies with isolated credentials
- 🔐 **Secure Credential Storage** - AES-256 encrypted passwords in database
- 🔄 **Flexible Sync Operations** - Sales, purchases, bills, or full sync
- ⏰ **Smart Scheduling** - Hourly, daily, weekly, monthly, or custom cron schedules
- 📊 **Comprehensive Dashboard** - Web UI for managing all companies
- 🎯 **Company Isolation** - Complete data separation between companies
- 📝 **Job History** - Track all sync operations with detailed results
- 🔑 **API Key Authentication** - Secure API access with optional company scoping

## Quick Start

### 1. Install Dependencies

```powershell
c:\xampp\php\php.exe composer.phar install
```

### 2. Configure Environment

Copy `.env.example` to `.env` and configure:

```env
# Database
DB_HOST=localhost
DB_NAME=qbo_multicompany
DB_USER=root
DB_PASS=

# API Key
API_KEY=Multi-C0mpany-S3cure-K3y-2024!

# Encryption Key (for password storage)
ENCRYPTION_KEY=sewQHws7jDVcUtUNHdbONxro+NA7Uxyb0ycKJCHAwgM=

# DevPos API URLs (static - same for all companies)
DEVPOS_TOKEN_URL=https://online.devpos.al/connect/token
DEVPOS_API_BASE=https://online.devpos.al/api/v3

# QuickBooks OAuth (your app credentials)
QBO_CLIENT_ID=your-client-id
QBO_CLIENT_SECRET=your-client-secret
```

### 3. Create Database

```powershell
# Create database
mysql -u root -p -e "CREATE DATABASE qbo_multicompany CHARACTER SET utf8mb4"

# Run schema migration
mysql -u root -p qbo_multicompany < sql/multi-company-schema.sql
```

### 4. Add Companies & Credentials

**Option A: Via SQL**

```sql
-- Add company
INSERT INTO companies (company_code, company_name) 
VALUES ('AEM', 'Albanian Engineering & Management');

-- Add DevPos credentials (company_id=1)
INSERT INTO company_credentials_devpos (company_id, tenant, username, password_encrypted)
VALUES (1, 'K43128625A', 'xhelo-aem', 
    AES_ENCRYPT('your-password', 'sewQHws7jDVcUtUNHdbONxro+NA7Uxyb0ycKJCHAwgM='));

-- Add QBO credentials
INSERT INTO company_credentials_qbo (company_id, realm_id)
VALUES (1, '9341453045416158');
```

**Option B: Via CLI** (to be created)

```powershell
php bin/company-manager.php add --code="AEM" --name="Albanian Engineering"
php bin/company-manager.php set-devpos --company=1 --tenant="K43128625A" --username="xhelo-aem" --password="xxx"
php bin/company-manager.php set-qbo --company=1 --realm="9341453045416158"
```

### 5. Start Server

```powershell
# Development server on port 8081
c:\xampp\php\php.exe -S localhost:8081 -t public
```

### 6. Access Dashboard

Open browser: **http://localhost:8081/dashboard**

## Architecture

### Database Tables

```
companies                    - Master list of companies
company_credentials_devpos   - DevPos credentials per company
company_credentials_qbo      - QuickBooks credentials per company
sync_jobs                    - Job history and results
sync_schedules               - Scheduled sync configurations
api_keys                     - API keys with company scoping
audit_log                    - Audit trail for all operations
maps_documents               - Document ID mappings per company
maps_masterdata              - Master data mappings per company
sync_cursors                 - Sync progress tracking per company
oauth_tokens_qbo             - QuickBooks OAuth tokens per company
oauth_tokens_devpos          - DevPos OAuth tokens per company
```

### Project Structure

```
src/
  ├── Services/          - CompanyService, MultiCompanySyncService
  ├── Sync/              - SalesSync, BillsSync (copied from single-tenant)
  ├── Storage/           - MapStore, TokenStore (company-scoped)
  └── API/               - DevPosClient, QuickBooksClient
routes/
  └── api.php            - REST API endpoints
public/
  ├── index.php          - Application entry point
  └── dashboard.html     - Web dashboard
sql/
  └── multi-company-schema.sql  - Database schema
bootstrap/
  └── app.php            - Application bootstrap
bin/
  └── (CLI tools - to be created)
```

## API Endpoints

All endpoints require `X-API-Key` header.

### Companies

```
GET  /api/companies              - List all active companies
GET  /api/companies/{id}         - Get company details
GET  /api/companies/{id}/stats   - Get company sync statistics
GET  /api/companies/{id}/jobs    - Get company job history
POST /api/companies/{id}/sync    - Create and run sync job
```

### Sync Job (POST /api/companies/{id}/sync)

**Request Body:**
```json
{
  "job_type": "sales|purchases|bills|full",
  "from_date": "2025-10-01",
  "to_date": "2025-10-20"
}
```

**Response:**
```json
{
  "success": true,
  "job_id": 123,
  "results": {
    "type": "sales",
    "company_id": 1,
    "company_code": "AEM",
    "invoices_created": 15,
    "receipts_created": 8,
    "skipped": 2
  }
}
```

### Examples

**PowerShell:**
```powershell
# List companies
Invoke-RestMethod -Uri "http://localhost:8081/api/companies" `
  -Headers @{"X-API-Key"="Multi-C0mpany-S3cure-K3y-2024!"}

# Run sales sync for Company 1
Invoke-RestMethod -Uri "http://localhost:8081/api/companies/1/sync" `
  -Method Post `
  -Headers @{"X-API-Key"="Multi-C0mpany-S3cure-K3y-2024!"; "Content-Type"="application/json"} `
  -Body '{"job_type":"sales","from_date":"2025-10-20","to_date":"2025-10-20"}'

# Get company stats
Invoke-RestMethod -Uri "http://localhost:8081/api/companies/1/stats" `
  -Headers @{"X-API-Key"="Multi-C0mpany-S3cure-K3y-2024!"}
```

**cURL:**
```bash
# List companies
curl -H "X-API-Key: Multi-C0mpany-S3cure-K3y-2024!" \
  http://localhost:8081/api/companies

# Run sync
curl -X POST \
  -H "X-API-Key: Multi-C0mpany-S3cure-K3y-2024!" \
  -H "Content-Type: application/json" \
  -d '{"job_type":"sales","from_date":"2025-10-20","to_date":"2025-10-20"}' \
  http://localhost:8081/api/companies/1/sync
```

## Scheduling

See [SCHEDULING-GUIDE.md](SCHEDULING-GUIDE.md) for complete scheduling documentation.

**Quick Example:**

```sql
-- Daily sales sync at 2 AM
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
```

Then run the scheduler:

```powershell
php bin/schedule-runner.php
```

## Company Isolation

Each company operates completely independently:

- ✅ Separate credentials (DevPos + QuickBooks)
- ✅ Isolated database records (company_id foreign keys)
- ✅ Independent sync operations (no interference)
- ✅ Concurrent operations supported
- ✅ Company-scoped API keys (optional)

See [COMPANY-ISOLATION.md](COMPANY-ISOLATION.md) for detailed explanation.

## Credential Management

- **Static Configuration** (in .env): API URLs, OAuth app credentials, custom field IDs
- **Company-Specific** (in database): Tenant IDs, usernames, passwords, realm IDs

See [CREDENTIALS-MANAGEMENT.md](CREDENTIALS-MANAGEMENT.md) for complete guide.

## Security

- ✅ AES-256 encryption for passwords
- ✅ API key authentication
- ✅ HTTPS recommended for production
- ✅ SQL injection protection (PDO prepared statements)
- ✅ Input validation
- ✅ Error logging

## Development vs Production

### Development (localhost)

```env
APP_ENV=development
APP_DEBUG=true
BASE_URL=http://localhost:8081
```

```powershell
c:\xampp\php\php.exe -S localhost:8081 -t public
```

### Production (Apache/Nginx)

```env
APP_ENV=production
APP_DEBUG=false
BASE_URL=https://sync.yourdomain.com
```

**Apache .htaccess:**
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

## Troubleshooting

### Database Connection Error

```
Check .env: DB_HOST, DB_NAME, DB_USER, DB_PASS
Verify MySQL is running: mysql -u root -p -e "SHOW DATABASES"
```

### API Key Error

```
Dashboard: Check .env API_KEY matches injected value in dashboard.html
API calls: Verify X-API-Key header matches .env API_KEY
```

### Sync Errors

```
Check company credentials: SELECT * FROM company_credentials_devpos WHERE company_id=1
Check QBO OAuth: SELECT * FROM company_credentials_qbo WHERE company_id=1
Check job errors: SELECT * FROM sync_jobs WHERE status='failed' ORDER BY created_at DESC
```

## Next Steps

1. **Add More Companies**
   - Insert into `companies` table
   - Add credentials via SQL or CLI
   
2. **Setup Schedules**
   - Create sync schedules per company
   - Run background scheduler

3. **Integrate Working Sync Logic**
   - Update `MultiCompanySyncService` to use actual `SalesSync`/`BillsSync` classes
   - Add company-scoped `MapStore` and `TokenStore`

4. **Create CLI Tools**
   - `bin/company-manager.php` - Add/edit companies and credentials
   - `bin/sync-company.php` - Run syncs from command line
   - `bin/schedule-manager.php` - Manage schedules

5. **Deploy to Production**
   - Setup Apache/Nginx virtual host
   - Configure HTTPS
   - Setup system cron or supervisor for scheduler
   - Monitor logs

## Documentation

- [SCHEDULING-GUIDE.md](SCHEDULING-GUIDE.md) - Complete scheduling documentation
- [COMPANY-ISOLATION.md](COMPANY-ISOLATION.md) - Company isolation mechanisms
- [CREDENTIALS-MANAGEMENT.md](CREDENTIALS-MANAGEMENT.md) - Credential storage strategy

## Support

- GitHub: Create an issue for bugs or feature requests
- Documentation: Check markdown files in project root

## License

MIT License - See LICENSE file for details
