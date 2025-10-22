-- Add table for user-specific DevPos credentials
-- This allows different users to have different DevPos login data for the same company

CREATE TABLE IF NOT EXISTS user_devpos_credentials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    company_id INT NOT NULL,
    tenant VARCHAR(100) NOT NULL,
    username VARCHAR(255) NOT NULL,
    password_encrypted TEXT NOT NULL,
    is_default TINYINT(1) DEFAULT 0 COMMENT 'Whether this is the default credential for this user-company pair',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_company (user_id, company_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_company_id (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='User-specific DevPos credentials - allows different users to have different login data for the same company';

-- Add index to existing table to support fallback to company-level credentials
ALTER TABLE company_credentials_devpos 
ADD INDEX idx_company_id (company_id);
