-- Add email provider presets table
CREATE TABLE IF NOT EXISTS email_provider_presets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    provider_key VARCHAR(50) UNIQUE NOT NULL,
    provider_name VARCHAR(100) NOT NULL,
    mail_host VARCHAR(255) NOT NULL,
    mail_port INT NOT NULL,
    mail_encryption VARCHAR(10) NOT NULL,
    smtp_auth TINYINT(1) DEFAULT 1,
    description TEXT DEFAULT NULL,
    setup_instructions TEXT DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert common email provider presets
INSERT INTO email_provider_presets (provider_key, provider_name, mail_host, mail_port, mail_encryption, description, setup_instructions, sort_order) VALUES
('gmail', 'Gmail / Google Workspace', 'smtp.gmail.com', 587, 'tls', 
 'Use Gmail or Google Workspace email accounts',
 '1. Go to https://myaccount.google.com/apppasswords\n2. Generate an "App Password" for this application\n3. Use your Gmail address and the generated App Password\n4. Note: 2-Factor Authentication must be enabled',
 1),

('microsoft365', 'Microsoft 365 / Outlook.com', 'smtp.office365.com', 587, 'tls',
 'Use Microsoft 365, Outlook.com, or Hotmail accounts',
 '1. Use your full email address as username\n2. Use your regular account password\n3. If 2FA is enabled, you may need an app-specific password\n4. Go to https://account.live.com/proofs/AppPassword for app passwords',
 2),

('sendgrid', 'SendGrid', 'smtp.sendgrid.net', 587, 'tls',
 'SendGrid email delivery service',
 '1. Create a SendGrid account at https://sendgrid.com\n2. Generate an API Key from Settings > API Keys\n3. Use "apikey" as the username\n4. Use your API Key as the password',
 3),

('mailgun', 'Mailgun', 'smtp.mailgun.org', 587, 'tls',
 'Mailgun email delivery service',
 '1. Create a Mailgun account at https://mailgun.com\n2. Get your SMTP credentials from the domain settings\n3. Use the provided SMTP username and password\n4. Make sure your domain is verified',
 4),

('amazon_ses', 'Amazon SES', 'email-smtp.us-east-1.amazonaws.com', 587, 'tls',
 'Amazon Simple Email Service',
 '1. Create SMTP credentials in AWS SES console\n2. Choose your region (update host accordingly)\n3. Use the provided SMTP username and password\n4. Verify your sender email or domain',
 5),

('custom', 'Custom SMTP Server', '', 587, 'tls',
 'Configure a custom SMTP server',
 'Enter your SMTP server details:\n1. SMTP Host (e.g., mail.yourdomain.com)\n2. SMTP Port (usually 25, 465, or 587)\n3. Encryption (TLS or SSL)\n4. Username and Password from your email provider',
 99);

-- Add provider preset reference to email_config table
ALTER TABLE email_config 
ADD COLUMN IF NOT EXISTS provider_preset_id INT DEFAULT NULL AFTER id,
ADD COLUMN IF NOT EXISTS provider_key VARCHAR(50) DEFAULT NULL AFTER provider_preset_id,
ADD FOREIGN KEY (provider_preset_id) REFERENCES email_provider_presets(id) ON DELETE SET NULL;

-- Update existing email_config to use Microsoft 365 preset if applicable
UPDATE email_config 
SET provider_key = 'microsoft365', 
    provider_preset_id = (SELECT id FROM email_provider_presets WHERE provider_key = 'microsoft365')
WHERE mail_host = 'smtp.office365.com';
