-- Create an admin user for testing
-- Password: admin123

INSERT INTO users (email, password_hash, full_name, role, is_active, created_at, updated_at)
VALUES (
    'admin@test.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password: admin123
    'Test Admin',
    'admin',
    1,
    NOW(),
    NOW()
)
ON DUPLICATE KEY UPDATE role = 'admin', is_active = 1;

-- Verify admin users
SELECT id, email, role, is_active FROM users WHERE role = 'admin';
