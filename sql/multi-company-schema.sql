-- Multi-Company Schema for qbo_multicompany database
-- This schema supports multiple companies with isolated credentials and sync operations

-- Drop existing tables if they exist (in reverse dependency order)
DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS sync_schedules;
DROP TABLE IF EXISTS sync_jobs;
DROP TABLE IF EXISTS api_keys;
DROP TABLE IF EXISTS company_credentials_qbo;
DROP TABLE IF EXISTS company_credentials_devpos;
DROP TABLE IF EXISTS companies;
DROP TABLE IF EXISTS sync_cursors;
DROP TABLE IF EXISTS maps_masterdata;
DROP TABLE IF EXISTS maps_documents;
DROP TABLE IF EXISTS oauth_tokens_devpos;
DROP TABLE IF EXISTS oauth_tokens_qbo;

-- =============================================================================
-- COMPANIES TABLE
-- =============================================================================
CREATE TABLE companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_code VARCHAR(50) NOT NULL UNIQUE COMMENT 'Short code for company (e.g., AEM, PGROUP)',
    company_name VARCHAR(255) NOT NULL COMMENT 'Full company name',
    is_active TINYINT(1) DEFAULT 1 COMMENT '1=active, 0=inactive',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active),
    INDEX idx_code (company_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Master list of companies';

-- =============================================================================
-- DEVPOS CREDENTIALS (per company)
-- =============================================================================
CREATE TABLE company_credentials_devpos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    tenant VARCHAR(100) NOT NULL COMMENT 'DevPos tenant ID (e.g., K43128625A)',
    username VARCHAR(255) NOT NULL COMMENT 'DevPos username',
    password_encrypted TEXT NOT NULL COMMENT 'AES-256 encrypted password',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    UNIQUE KEY unique_company_devpos (company_id),
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='DevPos credentials per company';

-- =============================================================================
-- QUICKBOOKS CREDENTIALS (per company)
-- =============================================================================
CREATE TABLE company_credentials_qbo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    realm_id VARCHAR(100) NOT NULL COMMENT 'QuickBooks Realm ID',
    access_token TEXT NULL COMMENT 'Current OAuth access token',
    refresh_token TEXT NULL COMMENT 'OAuth refresh token',
    token_expires_at TIMESTAMP NULL COMMENT 'When access token expires',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    UNIQUE KEY unique_company_qbo (company_id),
    INDEX idx_company (company_id),
    INDEX idx_realm (realm_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='QuickBooks credentials per company';

-- =============================================================================
-- SYNC JOBS (per company)
-- =============================================================================
CREATE TABLE sync_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    job_type ENUM('sales', 'purchases', 'bills', 'full') NOT NULL,
    status ENUM('pending', 'running', 'completed', 'failed') DEFAULT 'pending',
    trigger_source ENUM('manual', 'schedule', 'api') DEFAULT 'manual',
    from_date DATE NOT NULL,
    to_date DATE NOT NULL,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    results_json TEXT NULL COMMENT 'JSON with invoices_created, receipts_created, etc.',
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_company (company_id),
    INDEX idx_status (status),
    INDEX idx_dates (from_date, to_date),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Sync job history per company';

-- =============================================================================
-- SYNC SCHEDULES (per company)
-- =============================================================================
CREATE TABLE sync_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    schedule_name VARCHAR(100) NOT NULL COMMENT 'Descriptive name for schedule',
    job_type ENUM('sales', 'purchases', 'bills', 'full') NOT NULL,
    frequency ENUM('hourly', 'daily', 'weekly', 'monthly', 'custom') DEFAULT 'daily',
    cron_expression VARCHAR(100) NULL COMMENT 'Cron expression for custom schedules',
    time_of_day TIME DEFAULT '02:00:00' COMMENT 'Time to run (for daily/weekly/monthly)',
    day_of_week INT NULL COMMENT '1-7 for weekly (1=Monday, 7=Sunday)',
    day_of_month INT NULL COMMENT '1-31 for monthly',
    is_active TINYINT(1) DEFAULT 1,
    last_run_at TIMESTAMP NULL,
    next_run_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_company (company_id),
    INDEX idx_active (is_active),
    INDEX idx_next_run (next_run_at),
    INDEX idx_frequency (frequency)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Scheduled sync jobs per company';

-- =============================================================================
-- API KEYS (optional company-scoped authentication)
-- =============================================================================
CREATE TABLE api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    key_name VARCHAR(100) NOT NULL COMMENT 'Descriptive name for key',
    key_hash VARCHAR(255) NOT NULL COMMENT 'Hashed API key',
    company_id INT NULL COMMENT 'NULL=access all companies, set=access only this company',
    permissions JSON NULL COMMENT 'JSON array of permissions: ["sync:read", "sync:write"]',
    is_active TINYINT(1) DEFAULT 1,
    last_used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    UNIQUE KEY unique_key_hash (key_hash),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_active (is_active),
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API keys with optional company scoping';

-- =============================================================================
-- AUDIT LOG (per company)
-- =============================================================================
CREATE TABLE audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NULL COMMENT 'NULL for system-wide events',
    user_identifier VARCHAR(255) NULL COMMENT 'API key name, username, or IP',
    action VARCHAR(100) NOT NULL COMMENT 'create_company, run_sync, update_credentials, etc.',
    entity_type VARCHAR(50) NULL COMMENT 'company, sync_job, schedule, etc.',
    entity_id INT NULL COMMENT 'ID of affected entity',
    details TEXT NULL COMMENT 'Additional context in plain text or JSON',
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
    INDEX idx_company (company_id),
    INDEX idx_action (action),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Audit trail for all operations';

-- =============================================================================
-- OAUTH TOKENS - QBO (per company)
-- =============================================================================
CREATE TABLE oauth_tokens_qbo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    access_token TEXT NOT NULL,
    refresh_token TEXT NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_company (company_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='QuickBooks OAuth tokens per company';

-- =============================================================================
-- OAUTH TOKENS - DEVPOS (per company)
-- =============================================================================
CREATE TABLE oauth_tokens_devpos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    access_token TEXT NOT NULL,
    token_type VARCHAR(50) DEFAULT 'Bearer',
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_company (company_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='DevPos OAuth tokens per company';

-- =============================================================================
-- DOCUMENT MAPS (per company)
-- =============================================================================
CREATE TABLE maps_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    source_system VARCHAR(50) NOT NULL COMMENT 'devpos or quickbooks',
    source_type VARCHAR(50) NOT NULL COMMENT 'sale, cash, invoice, receipt, bill',
    source_id VARCHAR(255) NOT NULL COMMENT 'EIC or document number from DevPos',
    target_system VARCHAR(50) NOT NULL COMMENT 'quickbooks or devpos',
    target_type VARCHAR(50) NOT NULL COMMENT 'Invoice, SalesReceipt, Bill',
    target_id VARCHAR(255) NOT NULL COMMENT 'QBO entity ID',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    UNIQUE KEY unique_source (company_id, source_system, source_type, source_id),
    INDEX idx_company (company_id),
    INDEX idx_source (source_system, source_type, source_id),
    INDEX idx_target (target_system, target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Document ID mappings per company';

-- =============================================================================
-- MASTERDATA MAPS (per company)
-- =============================================================================
CREATE TABLE maps_masterdata (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    source_system VARCHAR(50) NOT NULL,
    entity_type VARCHAR(50) NOT NULL COMMENT 'customer, vendor, item, account',
    source_id VARCHAR(255) NOT NULL,
    target_system VARCHAR(50) NOT NULL,
    target_id VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    UNIQUE KEY unique_source (company_id, source_system, entity_type, source_id),
    INDEX idx_company (company_id),
    INDEX idx_source (source_system, entity_type, source_id),
    INDEX idx_target (target_system, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Master data ID mappings per company';

-- =============================================================================
-- SYNC CURSORS (per company)
-- =============================================================================
CREATE TABLE sync_cursors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    cursor_key VARCHAR(100) NOT NULL COMMENT 'sales_einvoice, cash_sales, purchases, etc.',
    cursor_value VARCHAR(255) NOT NULL COMMENT 'Last synced timestamp or ID',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    UNIQUE KEY unique_cursor (company_id, cursor_key),
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Sync progress cursors per company';

-- =============================================================================
-- SEED DATA - Example Companies
-- =============================================================================

-- Company 1: AEM (Albania)
INSERT INTO companies (id, company_code, company_name, is_active) 
VALUES (1, 'AEM', 'Albanian Engineering & Management', 1);

-- Company 2: PGROUP (Albania)
INSERT INTO companies (id, company_code, company_name, is_active) 
VALUES (2, 'PGROUP', 'Professional Group Albania', 1);

-- =============================================================================
-- NOTES
-- =============================================================================
-- To add credentials for a company:
-- 1. DevPos: INSERT INTO company_credentials_devpos (company_id, tenant, username, password_encrypted)
--            VALUES (1, 'K43128625A', 'xhelo-aem', AES_ENCRYPT('password', 'encryption_key'));
-- 
-- 2. QBO:    INSERT INTO company_credentials_qbo (company_id, realm_id)
--            VALUES (1, '9341453045416158');
--
-- To create a sync job:
-- INSERT INTO sync_jobs (company_id, job_type, from_date, to_date)
-- VALUES (1, 'sales', '2025-10-01', '2025-10-20');
--
-- To add a daily schedule:
-- INSERT INTO sync_schedules (company_id, schedule_name, job_type, frequency, time_of_day, next_run_at)
-- VALUES (1, 'Daily Full Sync', 'full', 'daily', '02:00:00', DATE_ADD(CURDATE() + INTERVAL 1 DAY, INTERVAL 2 HOUR));
