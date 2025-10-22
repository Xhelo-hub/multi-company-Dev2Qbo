-- Add columns for user registration and password recovery
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS status ENUM('pending', 'active', 'suspended') DEFAULT 'pending' AFTER is_active,
ADD COLUMN IF NOT EXISTS password_reset_token VARCHAR(64) NULL AFTER password_hash,
ADD COLUMN IF NOT EXISTS password_reset_expires TIMESTAMP NULL AFTER password_reset_token,
ADD COLUMN IF NOT EXISTS email_verification_code VARCHAR(6) NULL AFTER password_reset_expires,
ADD COLUMN IF NOT EXISTS email_verification_expires TIMESTAMP NULL AFTER email_verification_code,
ADD COLUMN IF NOT EXISTS new_email VARCHAR(255) NULL AFTER email_verification_expires;

-- Create password_reset_requests table
CREATE TABLE IF NOT EXISTS password_reset_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used TINYINT(1) DEFAULT 0,
    used_at TIMESTAMP NULL,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token),
    INDEX idx_expires (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create email_verification_codes table
CREATE TABLE IF NOT EXISTS email_verification_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code VARCHAR(6) NOT NULL,
    new_email VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    verified TINYINT(1) DEFAULT 0,
    verified_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_code (code),
    INDEX idx_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Update is_active to allow NULL (we'll use status instead)
ALTER TABLE users MODIFY COLUMN is_active TINYINT(1) DEFAULT 1;

-- Add index for email searches
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_email (email);
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_full_name (full_name);
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_status (status);

-- Migrate existing users: Set status='active' for all currently active users
UPDATE users SET status = 'active' WHERE is_active = 1 AND (status IS NULL OR status = 'pending');
