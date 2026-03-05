# CLAUDE.md — Multi-Company DevPos to QuickBooks Sync

## Project Overview

A multi-company SaaS API that synchronizes data between the **DevPos** point-of-sale system and **QuickBooks Online (QBO)**. Each company has completely isolated credentials, tokens, and sync operations.

**Stack:** PHP 8.0+, Slim Framework 4.12, MySQL 5.7+, Guzzle 7.8, PHPMailer 7.0
**Auth:** Session-based (7-day tokens), plus optional API key auth
**Encryption:** AES-256-CBC for all stored credentials
**Local dev URL:** `http://localhost:8081/multi-company-Dev2Qbo/public/`

---

## Directory Structure

```
bootstrap/         App initialization, DI container (SimpleContainer), Slim setup
bin/               45+ CLI scripts for setup, testing, sync workers
public/            Web root: index.php entry point, HTML dashboards, .htaccess
routes/            Slim route definitions (api.php, auth.php, email.php, etc.)
src/
  Http/            API clients: DevposClient.php, QboClient.php
  Middleware/      AuthMiddleware.php, AdminAuthMiddleware
  Services/        Business logic: CompanyService, MultiCompanySyncService, SyncExecutor, AuthService, EmailService
  Storage/         MapStore.php (doc ID mapping), TokenStore.php (OAuth tokens)
  Transformers/    InvoiceTransformer, SalesReceiptTransformer, BillTransformer
sql/               Database schema + 20+ migration files
```

---

## Key Architectural Decisions

- **Multi-company isolation:** All DB tables scoped by `company_id` foreign key.
- **Company code = NIPT:** Tax ID doubles as DevPos Tenant ID.
- **Sync job queue:** Jobs created as `pending`, processed by `bin/sync-worker.php`. Status lifecycle: `pending → running → completed/failed`.
- **Dynamic base path:** `bootstrap/app.php` detects XAMPP subdirectory vs. production root automatically.
- **Deleted files:** `src/Sync/BillsSync.php` and `src/Sync/SalesSync.php` were removed; logic moved into `SyncExecutor.php`.

---

## Database

**Database name:** `Xhelo_qbo_devpos` (or `qbo_multicompany` in older configs — use `Xhelo_qbo_devpos`)

Key tables:
- `companies`, `company_credentials_devpos`, `company_credentials_qbo`
- `sync_jobs`, `sync_schedules`, `sync_cursors`
- `maps_documents`, `maps_masterdata`
- `oauth_tokens_devpos`, `oauth_tokens_qbo`
- `users`, `user_sessions`, `user_company_access`
- `audit_log`

Credentials stored encrypted: MySQL `AES_ENCRYPT()` write, PHP OpenSSL `AES-256-CBC` decrypt.

---

## Routes

Loaded in `public/index.php`:
1. `routes/api.php` — companies, sync jobs, admin endpoints (internally loads `routes/auth.php`)
2. `routes/field-mappings.php`
3. `routes/email-providers.php`

Key route groups:
```
/api/auth/*          Login, logout, register, password reset
/api/companies/*     List, create, stats, sync jobs (auth required)
/api/email/*         Send, test, config
/api/email-providers CRUD for email provider configs
/api/field-mappings  Field mapping CRUD
```

---

## Environment Variables (`.env`)

Critical variables:
- `DB_DSN`, `DB_USER`, `DB_PASS` — MySQL connection
- `API_KEY` — Default API key for headless clients
- `DEVPOS_TOKEN_URL`, `DEVPOS_API_URL` — Static DevPos endpoints
- `QBO_CLIENT_ID`, `QBO_CLIENT_SECRET`, `QBO_REALM_ID` — QuickBooks OAuth
- `ENCRYPTION_KEY` — AES-256 key for credential encryption
- `SYNC_BATCH_SIZE`, `SYNC_TIMEOUT`, `SYNC_RETRY_ATTEMPTS`
- Email SMTP settings and provider configs

Template: `.env.example`

---

## Running Locally

```bash
# Install dependencies
php composer.phar install

# Start dev server
php -S localhost:8081 -t public

# Create admin user
php bin/create-admin-user.php

# Run sync worker
php bin/sync-worker.php

# Run scheduled syncs
php bin/run-scheduled-syncs.php
```

---

## Common CLI Scripts (`bin/`)

| Script | Purpose |
|--------|---------|
| `sync-worker.php` | Background worker for pending sync jobs |
| `run-scheduled-syncs.php` | Execute scheduled syncs |
| `add-company.php` | Add a company + credentials |
| `create-admin-user.php` | Bootstrap first admin user |
| `qbo-connect.php` | QuickBooks OAuth setup |
| `test-devpos-connection.php` | Validate DevPos API connectivity |
| `debug-field-mapping.php` | Validate field mapping config |

---

## Testing

Framework: PHPUnit 10.0

```bash
vendor/bin/phpunit
```

Test/debug scripts are in `bin/test-*.php` — run individually with `php bin/test-*.php`.

---

## Code Conventions

- **PSR-4 autoloading:** Namespace `App\` → `src/`
- **Dependency injection:** Custom `SimpleContainer` (PSR-11) in `bootstrap/app.php`
- **Error handling:** Slim error middleware; job errors stored as JSON in `sync_jobs.error_message`
- **SQL:** PDO prepared statements everywhere — never raw string interpolation in queries
- **Encryption:** Always use `ENCRYPTION_KEY` env var; never hardcode keys

---

## Security Notes

- All passwords/credentials stored AES-256 encrypted
- Session tokens validated via `AuthMiddleware` on every protected route
- Admin routes require `AdminAuthMiddleware`
- API key alternative auth supported for programmatic access
- Audit log (`audit_log` table) tracks all operations

---

## Documentation Files

50+ markdown docs in root. Key ones:
- `README.md` — Main project docs
- `QUICK-START.md` — Getting started
- `DEPLOYMENT-GUIDE.md` — Production setup
- `COMPANY-ISOLATION.md` — Multi-company architecture
- `EMAIL-SYSTEM-OVERVIEW.md` — Email system
- `DEVPOS-API-OFFICIAL-FIELDS.md` — DevPos API field reference
- `DEVPOS-FIELD-MAPPING.md` — Field mapping docs
