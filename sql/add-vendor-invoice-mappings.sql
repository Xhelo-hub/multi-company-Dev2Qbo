-- Migration: Add vendor and invoice mapping tables
-- Run this after the main schema has been created

-- =============================================================================
-- VENDOR MAPPINGS TABLE
-- =============================================================================
CREATE TABLE IF NOT EXISTS vendor_mappings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    devpos_nuis VARCHAR(50) NOT NULL COMMENT 'Vendor NUIS (tax ID) from DevPos',
    vendor_name VARCHAR(255) NOT NULL COMMENT 'Vendor display name',
    qbo_vendor_id VARCHAR(50) NOT NULL COMMENT 'QuickBooks Vendor ID',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    UNIQUE KEY unique_vendor (company_id, devpos_nuis),
    INDEX idx_company (company_id),
    INDEX idx_nuis (devpos_nuis),
    INDEX idx_qbo_vendor (qbo_vendor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='DevPos to QuickBooks vendor mappings';

-- =============================================================================
-- INVOICE MAPPINGS TABLE (for transactions display)
-- =============================================================================
CREATE TABLE IF NOT EXISTS invoice_mappings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    devpos_eic VARCHAR(255) NULL COMMENT 'DevPos EIC or composite key (docNumber|vendorNUIS)',
    devpos_document_number VARCHAR(100) NULL COMMENT 'Original document number from DevPos',
    transaction_type ENUM('invoice', 'receipt', 'purchase', 'bill') NOT NULL,
    qbo_invoice_id VARCHAR(50) NOT NULL COMMENT 'QuickBooks entity ID',
    qbo_doc_number VARCHAR(100) NULL COMMENT 'QuickBooks document number',
    amount DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Transaction amount',
    customer_name VARCHAR(255) NULL COMMENT 'Customer or vendor name',
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'First sync timestamp',
    last_synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    UNIQUE KEY unique_transaction (company_id, devpos_eic, transaction_type),
    INDEX idx_company (company_id),
    INDEX idx_type (transaction_type),
    INDEX idx_doc_number (devpos_document_number),
    INDEX idx_synced (synced_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Transaction mappings for dashboard display';

-- =============================================================================
-- OAUTH STATE TOKENS TABLE (for QuickBooks OAuth flow)
-- =============================================================================
CREATE TABLE IF NOT EXISTS oauth_state_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    state_token TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_company (company_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Temporary OAuth state tokens for security';

-- =============================================================================
-- USER MANAGEMENT TABLES
-- =============================================================================

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user') DEFAULT 'user',
    status ENUM('active', 'inactive', 'pending') DEFAULT 'pending',
    is_active TINYINT(1) DEFAULT 1,
    last_login_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='System users';

-- User company access
CREATE TABLE IF NOT EXISTS user_company_access (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    company_id INT NOT NULL,
    can_view_sync TINYINT(1) DEFAULT 1,
    can_run_sync TINYINT(1) DEFAULT 0,
    can_edit_credentials TINYINT(1) DEFAULT 0,
    can_manage_schedules TINYINT(1) DEFAULT 0,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_company (user_id, company_id),
    INDEX idx_user (user_id),
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='User permissions per company';

-- User sessions
CREATE TABLE IF NOT EXISTS user_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_token VARCHAR(255) NOT NULL UNIQUE,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_token (session_token),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Active user sessions';

-- User DevPos credentials (user-specific, overrides company-level)
CREATE TABLE IF NOT EXISTS user_devpos_credentials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    user_id INT NOT NULL,
    tenant VARCHAR(100) NOT NULL COMMENT 'DevPos tenant ID',
    username VARCHAR(255) NOT NULL COMMENT 'DevPos username',
    password_encrypted TEXT NOT NULL COMMENT 'AES-256 encrypted password',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_company_devpos (company_id, user_id),
    INDEX idx_company (company_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='User-specific DevPos credentials';

-- Audit logs (enhanced)
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL COMMENT 'user.login, company.create, sync.run, etc.',
    entity_type VARCHAR(50) NULL COMMENT 'user, company, sync_job, etc.',
    entity_id INT NULL COMMENT 'ID of affected entity',
    details TEXT NULL COMMENT 'JSON with change details',
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_action (action),
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Comprehensive audit trail';

-- =============================================================================
-- SEED DATA - Create admin user
-- =============================================================================
-- Password: admin123 (change in production!)
-- Password hash generated with: password_hash('admin123', PASSWORD_DEFAULT)
INSERT INTO users (email, password_hash, full_name, role, status, is_active) 
VALUES ('admin@dev2qbo.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'admin', 'active', 1)
ON DUPLICATE KEY UPDATE email = email;

-- Grant admin access to all companies
INSERT INTO user_company_access (user_id, company_id, can_view_sync, can_run_sync, can_edit_credentials, can_manage_schedules)
SELECT 
    (SELECT id FROM users WHERE email = 'admin@dev2qbo.local' LIMIT 1),
    id,
    1, 1, 1, 1
FROM companies
ON DUPLICATE KEY UPDATE can_view_sync = 1, can_run_sync = 1, can_edit_credentials = 1, can_manage_schedules = 1;
