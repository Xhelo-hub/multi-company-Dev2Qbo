# Multi-Company Credentials Management Guide

## Architecture Overview

This multi-company system uses a **database-driven credential storage** approach where:
- Static API configuration (URLs, endpoints) → `.env` file
- Company-specific credentials (tenant IDs, usernames, passwords, realm IDs) → `database tables`

## Why Database Storage?

✅ **Scalability**: Add unlimited companies without modifying code or .env files
✅ **Security**: Credentials encrypted in database, not plain text in .env
✅ **Per-Company Isolation**: Each company has independent credentials
✅ **Easy Updates**: Change credentials via dashboard or CLI without file editing
✅ **Audit Trail**: Track who accessed/modified credentials and when

## Credential Types

### 1. Static Configuration (.env file)
These are **shared across all companies** and rarely change:

```env
# DevPos API Endpoints (same for all Albanian companies)
DEVPOS_TOKEN_URL=https://online.devpos.al/connect/token
DEVPOS_API_BASE=https://online.devpos.al/api/v3

# QuickBooks OAuth App Credentials (your app registration)
QBO_CLIENT_ID=your-intuit-app-client-id
QBO_CLIENT_SECRET=your-intuit-app-client-secret
QBO_REDIRECT_URI=http://your-domain.com/oauth/callback
QBO_AUTH_URL=https://appcenter.intuit.com/connect/oauth2

# Custom Field Definition IDs (configured in QBO once)
QBO_CF_EIC_DEF_ID=1
QBO_CF_IIC_DEF_ID=2
QBO_CF_DOCNO_DEF_ID=3
```

### 2. Company-Specific Credentials (Database)
These are **unique per company** and stored in database tables:

#### DevPos Credentials (Table: `company_credentials_devpos`)
```sql
company_id    | 1
tenant        | K43128625A         -- Unique per company
username      | xhelo-aem          -- Unique per company
password_enc  | [encrypted]        -- Encrypted password
```

#### QuickBooks Credentials (Table: `company_credentials_qbo`)
```sql
company_id    | 1
realm_id      | 9341453199574798   -- Unique QBO company ID
access_token  | [encrypted]        -- OAuth access token
refresh_token | [encrypted]        -- OAuth refresh token
```

## How to Add Company Credentials

### Method 1: CLI Tool (Recommended for Setup)

```powershell
# Add a new company
php bin/company-manager.php add

# Follow prompts:
# - Company Code: AEM
# - Company Name: Xhelo AEM Company
# - DevPos Tenant: K43128625A
# - DevPos Username: xhelo-aem
# - DevPos Password: [enter securely]
# - QBO Realm ID: 9341453199574798

# List all companies
php bin/company-manager.php list

# Update company credentials
php bin/company-manager.php update AEM --devpos-password new-password
```

### Method 2: Dashboard UI (Recommended for Users)

```
1. Navigate to: http://localhost:8081/admin/companies
2. Click "Add Company"
3. Fill in form:
   - Company Name: Xhelo AEM Company
   - Company Code: AEM
   - DevPos Tenant: K43128625A
   - DevPos Username: xhelo-aem
   - DevPos Password: ••••••••
4. Click "Authorize QuickBooks" to connect QBO
5. Save
```

### Method 3: Direct Database (For Migration)

```sql
-- 1. Add company
INSERT INTO companies (company_code, company_name, is_active)
VALUES ('AEM', 'Xhelo AEM Company', 1);

-- 2. Add DevPos credentials (password will be encrypted by application)
INSERT INTO company_credentials_devpos 
(company_id, tenant, username, password_encrypted)
VALUES (
    1, 
    'K43128625A', 
    'xhelo-aem',
    'base64_or_encrypted_password_here'
);

-- 3. Add QBO credentials (after OAuth flow)
INSERT INTO company_credentials_qbo
(company_id, realm_id, access_token, refresh_token, client_id, client_secret_encrypted)
VALUES (
    1,
    '9341453199574798',
    'encrypted_access_token',
    'encrypted_refresh_token',
    'your_qbo_client_id',
    'encrypted_client_secret'
);
```

## Security Best Practices

### Encryption
- All passwords stored using AES-256 encryption
- Encryption key in `.env`: `ENCRYPTION_KEY=base64_encoded_key`
- Never store plaintext passwords in database
- Tokens encrypted at rest, decrypted in memory only

### API Keys
- Dashboard access requires API key: `X-API-Key` header
- Different API keys can be scoped to specific companies
- API keys stored as SHA-256 hashes in `api_keys` table

### OAuth Tokens
- QBO access tokens expire after 1 hour
- Refresh tokens used to get new access tokens automatically
- Token refresh happens before each sync operation

## Migrating Existing Credentials

### From Single-Tenant .env Files

If you have existing companies in separate .env files:

```powershell
# Use the migration script
php bin/migrate-from-env.php

# Or manually for each company:
php bin/company-manager.php add-from-env --env-file=".env.company1"
```

### From Existing Database

If credentials are already in a database:

```sql
-- Export from old system
SELECT tenant, username, password FROM old_credentials WHERE company = 'AEM';

-- Import to new system using CLI tool or dashboard
```

## Environment-Specific Setup

### Development (.env.development)
```env
APP_ENV=development
DB_DSN=mysql:host=localhost;dbname=qbo_multicompany_dev
API_KEY=DEV_KEY_12345
```

### Production (.env.production)
```env
APP_ENV=production
DB_DSN=mysql:host=production-db;dbname=qbo_multicompany
API_KEY=PROD_SECURE_KEY_CHANGE_ME
```

## FAQ

**Q: Can I still use .env for a single company?**
A: Yes, but not recommended for multi-company. The system will check database first.

**Q: What if I want different DevPos API URLs per company?**
A: Add `api_base_url` column to `company_credentials_devpos` table and override.

**Q: How do I rotate passwords?**
A: Use CLI: `php bin/company-manager.php update COMPANY_CODE --devpos-password new-pwd`

**Q: Can I backup credentials?**
A: Yes, use: `php bin/backup-credentials.php --output backup.json.enc`

**Q: What about CI/CD?**
A: Store only `.env` in CI/CD. Credentials loaded from secure database at runtime.

## Quick Reference

| Credential Type | Storage Location | How to Update |
|----------------|------------------|---------------|
| DevPos API URLs | .env | Edit .env file |
| QBO OAuth App | .env | Edit .env file |
| DevPos Tenant | Database | CLI or Dashboard |
| DevPos Username | Database | CLI or Dashboard |
| DevPos Password | Database (encrypted) | CLI or Dashboard |
| QBO Realm ID | Database | OAuth flow |
| QBO Access Token | Database (encrypted) | Auto-refreshed |

## Next Steps

1. ✅ Configure `.env` with your QBO OAuth app credentials
2. ⏳ Create database: `mysql -e "CREATE DATABASE qbo_multicompany"`
3. ⏳ Run schema: `mysql qbo_multicompany < sql/multi-company-schema.sql`
4. ⏳ Add first company: `php bin/company-manager.php add`
5. ⏳ Test sync: Open dashboard and run sync for company
