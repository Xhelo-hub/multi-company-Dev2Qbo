-- =============================================================================
-- USER MANAGEMENT & AUTHENTICATION TABLES
-- =============================================================================

-- Users table for authentication
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL COMMENT 'bcrypt hash',
    full_name VARCHAR(255) NOT NULL,
    role ENUM('admin', 'company_user') DEFAULT 'company_user',
    is_active TINYINT(1) DEFAULT 1,
    last_login_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='System users with authentication';

-- User-Company assignments (many-to-many for flexibility)
CREATE TABLE IF NOT EXISTS user_company_access (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    company_id INT NOT NULL,
    can_view_sync TINYINT(1) DEFAULT 1 COMMENT 'Can view sync jobs and results',
    can_run_sync TINYINT(1) DEFAULT 1 COMMENT 'Can trigger sync manually',
    can_edit_credentials TINYINT(1) DEFAULT 0 COMMENT 'Can modify DevPos/QBO credentials',
    can_manage_schedules TINYINT(1) DEFAULT 0 COMMENT 'Can create/edit sync schedules',
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    assigned_by INT NULL COMMENT 'User ID who assigned this access',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_company (user_id, company_id),
    INDEX idx_user (user_id),
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='User access to companies';

-- Session management
CREATE TABLE IF NOT EXISTS user_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_token VARCHAR(64) NOT NULL UNIQUE,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (session_token),
    INDEX idx_user (user_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Active user sessions';

-- =============================================================================
-- SEED DATA - Default Admin User
-- =============================================================================
-- Password: admin123 (CHANGE THIS IN PRODUCTION!)
-- You should change this password immediately after first login
INSERT INTO users (id, email, password_hash, full_name, role, is_active) 
VALUES (1, 'admin@devpos-sync.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'admin', 1)
ON DUPLICATE KEY UPDATE id=id;

-- Grant admin access to all existing companies
INSERT INTO user_company_access (user_id, company_id, can_view_sync, can_run_sync, can_edit_credentials, can_manage_schedules)
SELECT 1, id, 1, 1, 1, 1 FROM companies
ON DUPLICATE KEY UPDATE user_id=user_id;

-- =============================================================================
-- EXAMPLE: Create a company user
-- =============================================================================
-- Uncomment and modify these to create company-specific users:

/*
-- User for AEM company
INSERT INTO users (email, password_hash, full_name, role, is_active) 
VALUES ('aem-user@company.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'AEM User', 'company_user', 1);

-- Grant access only to Company 1 (AEM)
INSERT INTO user_company_access (user_id, company_id, can_view_sync, can_run_sync, can_edit_credentials, can_manage_schedules)
VALUES (LAST_INSERT_ID(), 1, 1, 1, 1, 1);

-- User for PGROUP company
INSERT INTO users (email, password_hash, full_name, role, is_active) 
VALUES ('pgroup-user@company.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'PGROUP User', 'company_user', 1);

-- Grant access only to Company 2 (PGROUP)
INSERT INTO user_company_access (user_id, company_id, can_view_sync, can_run_sync, can_edit_credentials, can_manage_schedules)
VALUES (LAST_INSERT_ID(), 2, 1, 1, 1, 1);
*/

-- =============================================================================
-- NOTES
-- =============================================================================
-- Admin users (role='admin') can:
--   - Access all companies
--   - Create/edit/delete users
--   - Assign users to companies
--   - Manage all credentials and settings
--
-- Company users (role='company_user') can only:
--   - Access companies they're assigned to
--   - Permissions controlled by user_company_access table
--
-- Default password for all example users: admin123
-- Generate new password hash with: password_hash('your_password', PASSWORD_DEFAULT)
