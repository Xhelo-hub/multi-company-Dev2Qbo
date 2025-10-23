-- Email Configuration and Templates Schema

-- Email configuration table (stores SMTP settings)
CREATE TABLE IF NOT EXISTS email_config (
    id INT PRIMARY KEY AUTO_INCREMENT,
    is_enabled TINYINT(1) DEFAULT 1,
    mail_driver VARCHAR(20) DEFAULT 'smtp',
    mail_host VARCHAR(255) DEFAULT 'smtp.office365.com',
    mail_port INT DEFAULT 587,
    mail_encryption VARCHAR(10) DEFAULT 'tls',
    mail_username VARCHAR(255) DEFAULT NULL,
    mail_password TEXT DEFAULT NULL, -- Encrypted
    mail_from_address VARCHAR(255) DEFAULT 'devsync@konsulence.al',
    mail_from_name VARCHAR(255) DEFAULT 'DEV-QBO Sync',
    updated_by INT DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email templates table
CREATE TABLE IF NOT EXISTS email_templates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    template_key VARCHAR(50) UNIQUE NOT NULL,
    template_name VARCHAR(100) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body_html TEXT NOT NULL,
    body_text TEXT DEFAULT NULL,
    available_variables TEXT DEFAULT NULL, -- JSON array of available variables
    is_active TINYINT(1) DEFAULT 1,
    description TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT DEFAULT NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email logs table (track sent emails)
CREATE TABLE IF NOT EXISTS email_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    template_key VARCHAR(50) DEFAULT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    recipient_name VARCHAR(255) DEFAULT NULL,
    subject VARCHAR(255) NOT NULL,
    status ENUM('sent', 'failed', 'pending') DEFAULT 'pending',
    error_message TEXT DEFAULT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    user_id INT DEFAULT NULL,
    INDEX idx_recipient (recipient_email),
    INDEX idx_status (status),
    INDEX idx_sent_at (sent_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default email configuration
INSERT INTO email_config (
    is_enabled, mail_driver, mail_host, mail_port, mail_encryption,
    mail_username, mail_password, mail_from_address, mail_from_name
) VALUES (
    0, 'smtp', 'smtp.office365.com', 587, 'tls',
    NULL, NULL, 'devsync@konsulence.al', 'DEV-QBO Sync'
) ON DUPLICATE KEY UPDATE id=id;

-- Insert default email templates
INSERT INTO email_templates (template_key, template_name, subject, body_html, body_text, available_variables, description) VALUES
('user_welcome', 'Welcome Email', 'Welcome to DEV-QBO Sync Platform', 
'<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, ''Segoe UI'', Roboto, sans-serif; background-color: #f4f5f7; margin: 0; padding: 20px;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
        <tr>
            <td style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
                <h1 style="color: #ffffff; margin: 0; font-size: 28px;">✨ Welcome to DEV-QBO Sync!</h1>
            </td>
        </tr>
        <tr>
            <td style="padding: 40px 30px;">
                <h2 style="color: #333; margin-top: 0;">Hello {{name}}! 👋</h2>
                <p style="color: #555; line-height: 1.6; font-size: 16px;">
                    Your account has been successfully created. You can now synchronize your DevPos data with QuickBooks Online seamlessly.
                </p>
                <div style="background-color: #e6f7ed; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #33cc66;">
                    <p style="margin: 5px 0; color: #333;"><strong>📧 Email:</strong> {{email}}</p>
                </div>
                {{password_section}}
                <div style="text-align: center; margin: 30px 0;">
                    <a href="{{login_url}}" style="display: inline-block; background-color: #667eea; color: #ffffff; padding: 15px 40px; text-decoration: none; border-radius: 5px; font-weight: 600; font-size: 16px;">
                        🚀 Login to Your Account
                    </a>
                </div>
            </td>
        </tr>
        <tr>
            <td style="background-color: #f8f9fa; padding: 20px 30px; text-align: center; border-radius: 0 0 10px 10px; border-top: 1px solid #e0e0e0;">
                <p style="color: #999; font-size: 12px; margin: 0;">© {{year}} DEV-QBO Sync Platform. All rights reserved.</p>
            </td>
        </tr>
    </table>
</body>
</html>',
'Hello {{name}}! Your account has been successfully created. Email: {{email}}. Login at: {{login_url}}',
'["name", "email", "login_url", "password_section", "year"]',
'Sent when a new user account is created'),

('password_reset', 'Password Reset', 'Password Reset Request - DEV-QBO Sync',
'<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, ''Segoe UI'', Roboto, sans-serif; background-color: #f4f5f7; margin: 0; padding: 20px;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
        <tr>
            <td style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
                <h1 style="color: #ffffff; margin: 0; font-size: 28px;">🔒 Password Reset Request</h1>
            </td>
        </tr>
        <tr>
            <td style="padding: 40px 30px;">
                <h2 style="color: #333; margin-top: 0;">Hello {{name}},</h2>
                <p style="color: #555; line-height: 1.6; font-size: 16px;">
                    We received a request to reset your password. Click the button below to create a new password.
                </p>
                <div style="background-color: #fff3cd; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ffc107;">
                    <p style="margin: 0; color: #856404;"><strong>⏰ This link will expire in 1 hour</strong></p>
                </div>
                <div style="text-align: center; margin: 30px 0;">
                    <a href="{{reset_url}}" style="display: inline-block; background-color: #667eea; color: #ffffff; padding: 15px 40px; text-decoration: none; border-radius: 5px; font-weight: 600; font-size: 16px;">
                        🔑 Reset My Password
                    </a>
                </div>
                <p style="color: #555; line-height: 1.6; font-size: 14px;">Or copy this URL: {{reset_url}}</p>
                <div style="background-color: #f8d7da; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #dc3545;">
                    <p style="margin: 0; color: #721c24;"><strong>⚠️ Didn''t request this?</strong> Please ignore this email.</p>
                </div>
            </td>
        </tr>
        <tr>
            <td style="background-color: #f8f9fa; padding: 20px 30px; text-align: center; border-radius: 0 0 10px 10px; border-top: 1px solid #e0e0e0;">
                <p style="color: #999; font-size: 12px; margin: 0;">© {{year}} DEV-QBO Sync Platform. All rights reserved.</p>
            </td>
        </tr>
    </table>
</body>
</html>',
'Hello {{name}}, Reset your password: {{reset_url}} (expires in 1 hour)',
'["name", "reset_url", "year"]',
'Sent when user requests password reset'),

('temp_password', 'Temporary Password', 'Your Password Has Been Reset - DEV-QBO Sync',
'<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, ''Segoe UI'', Roboto, sans-serif; background-color: #f4f5f7; margin: 0; padding: 20px;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
        <tr>
            <td style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
                <h1 style="color: #ffffff; margin: 0; font-size: 28px;">🔐 Password Reset</h1>
            </td>
        </tr>
        <tr>
            <td style="padding: 40px 30px;">
                <h2 style="color: #333; margin-top: 0;">Hello {{name}},</h2>
                <p style="color: #555; line-height: 1.6; font-size: 16px;">
                    Your password has been reset by an administrator. Use this temporary password to log in.
                </p>
                <div style="background-color: #fff3cd; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ffc107;">
                    <h3 style="color: #856404; margin-top: 0;">🔑 Temporary Password</h3>
                    <p style="margin: 10px 0; text-align: center;">
                        <code style="background: #f8f9fa; padding: 10px 20px; border-radius: 5px; font-size: 18px; color: #d63384; font-weight: bold;">{{temp_password}}</code>
                    </p>
                </div>
                <div style="background-color: #f8d7da; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #dc3545;">
                    <p style="margin: 0; color: #721c24;"><strong>⚠️ Valid for 24 hours.</strong> Change it immediately after login.</p>
                </div>
                <div style="text-align: center; margin: 30px 0;">
                    <a href="{{login_url}}" style="display: inline-block; background-color: #667eea; color: #ffffff; padding: 15px 40px; text-decoration: none; border-radius: 5px; font-weight: 600; font-size: 16px;">
                        🚀 Login Now
                    </a>
                </div>
            </td>
        </tr>
        <tr>
            <td style="background-color: #f8f9fa; padding: 20px 30px; text-align: center; border-radius: 0 0 10px 10px; border-top: 1px solid #e0e0e0;">
                <p style="color: #999; font-size: 12px; margin: 0;">© {{year}} DEV-QBO Sync Platform. All rights reserved.</p>
            </td>
        </tr>
    </table>
</body>
</html>',
'Hello {{name}}, Your temporary password: {{temp_password}} (valid for 24 hours). Login: {{login_url}}',
'["name", "temp_password", "login_url", "year"]',
'Sent when admin resets user password'),

('account_modified', 'Account Updated', 'Your Account Has Been Updated - DEV-QBO Sync',
'<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, ''Segoe UI'', Roboto, sans-serif; background-color: #f4f5f7; margin: 0; padding: 20px;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
        <tr>
            <td style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
                <h1 style="color: #ffffff; margin: 0; font-size: 28px;">ℹ️ Account Updated</h1>
            </td>
        </tr>
        <tr>
            <td style="padding: 40px 30px;">
                <h2 style="color: #333; margin-top: 0;">Hello {{name}},</h2>
                <p style="color: #555; line-height: 1.6; font-size: 16px;">
                    Your account information has been updated by an administrator.
                </p>
                <div style="background-color: #d1ecf1; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #17a2b8;">
                    <h3 style="color: #0c5460; margin-top: 0;">📝 Changes Made:</h3>
                    {{changes_list}}
                </div>
                <div style="background-color: #f8d7da; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #dc3545;">
                    <p style="margin: 0; color: #721c24;"><strong>⚠️ Security Notice:</strong> If you didn''t authorize these changes, contact your administrator immediately.</p>
                </div>
            </td>
        </tr>
        <tr>
            <td style="background-color: #f8f9fa; padding: 20px 30px; text-align: center; border-radius: 0 0 10px 10px; border-top: 1px solid #e0e0e0;">
                <p style="color: #999; font-size: 12px; margin: 0;">© {{year}} DEV-QBO Sync Platform. All rights reserved.</p>
            </td>
        </tr>
    </table>
</body>
</html>',
'Hello {{name}}, Your account has been updated. Changes: {{changes_list}}',
'["name", "changes_list", "year"]',
'Sent when admin modifies user account')

ON DUPLICATE KEY UPDATE template_name=VALUES(template_name);
