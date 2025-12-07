-- Migration: Add user management tables
-- Date: 2025-12-04
-- Description: Creates user_company_access, user_sessions, and audit_logs tables

-- Table: user_company_access
-- Manages which users have access to which companies and their permissions
CREATE TABLE IF NOT EXISTS user_company_access (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    company_id INT NOT NULL,
    can_view_sync TINYINT(1) DEFAULT 1,
    can_run_sync TINYINT(1) DEFAULT 0,
    can_edit_credentials TINYINT(1) DEFAULT 0,
    can_manage_schedules TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_company (user_id, company_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: user_sessions
-- Tracks active user sessions for authentication
CREATE TABLE IF NOT EXISTS user_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_token VARCHAR(128) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_session_token (session_token),
    KEY idx_user_id (user_id),
    KEY idx_expires_at (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: audit_logs
-- Tracks all user actions for security and debugging
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NULL,
    entity_id INT NULL,
    details JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user_id (user_id),
    KEY idx_action (action),
    KEY idx_entity (entity_type, entity_id),
    KEY idx_created_at (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add status column to users table if it doesn't exist
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS status ENUM('active', 'pending', 'suspended') DEFAULT 'active' AFTER is_active;

-- Add last_login_at column to users table if it doesn't exist
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL AFTER status;

-- Add password reset fields if they don't exist
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS password_reset_token VARCHAR(255) NULL AFTER password_hash,
ADD COLUMN IF NOT EXISTS password_reset_expires DATETIME NULL AFTER password_reset_token;

-- Create index on email for faster lookups
CREATE INDEX IF NOT EXISTS idx_email ON users(email);
CREATE INDEX IF NOT EXISTS idx_status ON users(status);
CREATE INDEX IF NOT EXISTS idx_is_active ON users(is_active);

SELECT 'Migration completed successfully' AS status;
