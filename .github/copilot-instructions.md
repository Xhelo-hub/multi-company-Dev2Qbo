# Multi-Company Dev2QBO - Copilot Instructions

## Project Overview
Multi-company DevPos to QuickBooks Online synchronization API that manages multiple QBO companies with different DevPos tenant credentials through a unified dashboard.

## Technology Stack
- PHP 8.x with Slim Framework 4.x (REST API)
- MySQL/MariaDB with PDO
- Composer for dependency management
- Guzzle HTTP client for API calls
- PHPMailer for email notifications
- HTML/CSS/JavaScript dashboard

## Project Structure
```
src/
  ├── Http/               # API clients (DevposClient, QboClient)
  ├── Services/           # Business logic (CompanyService, MultiCompanySyncService, SyncExecutor)
  ├── Sync/               # Sync implementations (SalesSync, BillsSync)
  ├── Storage/            # Data persistence (MapStore, TokenStore)
  ├── Transformers/       # Data transformation (InvoiceTransformer, etc.)
  ├── Middleware/         # Auth middleware
routes/                   # API route definitions
public/                  # Web assets and dashboard
sql/                     # Database schema and migrations
bin/                     # CLI tools and utilities
bootstrap/               # Application bootstrap with DI container
```

## Architecture Patterns

### Company Isolation
- All operations are company-scoped using `company_id` foreign keys
- Credentials stored encrypted (AES-256) per company in `company_credentials_devpos`/`company_credentials_qbo`
- Mappings isolated: `maps_documents`, `maps_masterdata`, `sync_cursors` all company-scoped
- Example: Sync jobs reference `company_id`, ensuring no cross-company data leakage

### Dependency Injection
- Custom `SimpleContainer` class in `bootstrap/app.php` for service registration
- Services injected: `CompanyService`, `MultiCompanySyncService`, `EmailService`
- Example: `$container->set(PDO::class, function() { return new PDO(...); })`

### Sync Workflow
- Jobs created via `MultiCompanySyncService::createJob()` with status 'pending'
- `SyncExecutor::executeJob()` processes based on `job_type` ('sales'|'purchases'|'bills'|'full')
- Sync classes (`SalesSync`, `BillsSync`) fetch from DevPos, transform data, create in QBO, store mappings
- Results stored as JSON in `sync_jobs.results`

### Authentication
- Session-based auth for dashboard (users table, roles: 'admin'|'user')
- API key auth for external calls (`X-API-Key` header)
- Admin routes require `AuthMiddleware($pdo, true)`

## Database Operations

### Schema
- Core tables: `companies`, `sync_jobs`, `company_credentials_*`, `oauth_tokens_*`
- Mappings: `maps_documents` (prevents duplicates), `maps_masterdata` (QBO IDs)
- Cursors: `sync_cursors` for incremental sync
- Use migrations in `sql/migrations/` for schema changes

### Common Patterns
- Prepared statements with PDO for security
- Company-scoped queries: `WHERE company_id = ?`
- Encryption: `AES_ENCRYPT()/AES_DECRYPT()` for sensitive data
- Example: `SELECT * FROM company_credentials_devpos WHERE company_id = ?`

## Development Workflows

### Local Development
```bash
composer install
# Create .env file (copy from .env.example)
php -S localhost:8081 -t public  # or composer start
# Access: http://localhost:8081/dashboard
```

### Database Setup
```sql
CREATE DATABASE qbo_multicompany CHARACTER SET utf8mb4;
mysql -u root -p qbo_multicompany < sql/multi-company-schema.sql
```

### Adding Companies
```sql
INSERT INTO companies (company_code, company_name) VALUES ('ABC', 'ABC Corp');
INSERT INTO company_credentials_devpos (company_id, tenant, username, password_encrypted)
VALUES (1, 'tenant123', 'user', AES_ENCRYPT('pass', 'your-key'));
```

### Testing Sync
```bash
php bin/test-devpos-connection.php  # Test API connectivity
php bin/test-bills-sync.php         # Test sync logic
```

### Debugging
- Logs: `error_log()` calls throughout code
- Job status: Check `sync_jobs` table for failures
- API responses: Inspect `results` JSON in sync_jobs

## CLI Tools
Located in `bin/` directory - use for maintenance and testing:
- `add-company.php` - Add new companies
- `run-scheduled-syncs.php` - Execute pending schedules
- `check-mysql.php` - Database connectivity test
- `sync-worker.php` - Background job processor

## Key Features
- Multi-company credential management with encryption
- Company-scoped sync jobs with status tracking ('pending'|'running'|'completed'|'failed'|'cancelled')
- Unified dashboard for all companies with role-based access
- Job history and statistics per company
- Email notifications with configurable providers (SMTP/IMAP/PHP mail)
- API endpoints for company and sync management
- Scheduled syncs with cron support
