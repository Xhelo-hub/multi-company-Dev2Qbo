-- Create default admin user
-- Password: admin123 (hashed with bcrypt)

-- First check if user already exists
DELETE FROM users WHERE email = 'admin@devpos-sync.local';

-- Create the user with bcrypt hash for 'admin123'
INSERT INTO users (email, password_hash, full_name, role, is_active, status, created_at, updated_at)
VALUES (
    'admin@devpos-sync.local',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'Default Admin',
    'admin',
    1,
    'active',
    NOW(),
    NOW()
);

-- Show the created user
SELECT id, email, full_name, role, is_active, status FROM users WHERE email = 'admin@devpos-sync.local';
