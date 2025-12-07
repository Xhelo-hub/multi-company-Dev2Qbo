# Email Configuration System - Deployment Guide

## Overview
Added comprehensive email provider configuration system with presets for Gmail, Microsoft 365, SendGrid, Mailgun, Amazon SES, and custom SMTP servers.

## What's New

### 1. Email Provider Presets
- **Gmail / Google Workspace**: Pre-configured SMTP settings with app password instructions
- **Microsoft 365 / Outlook.com**: Pre-configured for Office 365 accounts
- **SendGrid**: Popular email delivery service
- **Mailgun**: Email API service
- **Amazon SES**: AWS email service
- **Custom SMTP**: Manual configuration for any SMTP server

### 2. New Files Created
- `sql/add-email-provider-presets.sql` - Database schema for provider presets
- `routes/email-providers.php` - API endpoints for provider management
- `public/admin-email-config.html` - User-friendly configuration wizard

### 3. Updated Files
- `.env` - Added email configuration fallback settings
- `public/index.php` - Loaded email provider routes

## Deployment Steps

### Step 1: SSH to Production Server
```bash
ssh root@78.46.201.151
cd /var/www/html
```

### Step 2: Pull Latest Changes
```bash
git pull origin main
```

### Step 3: Update Database Schema
```bash
mysql -u root -p Xhelo_qbo_devpos < sql/add-email-provider-presets.sql
```

Enter your MySQL root password when prompted.

### Step 4: Update .env File (if needed)
If you want to add fallback email configuration:

```bash
nano .env
```

Add these lines at the end (already included in the repository .env):
```env
# Email Configuration (Fallback if database not configured)
MAIL_ENABLED=false
MAIL_DRIVER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-app-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=your-email@gmail.com
MAIL_FROM_NAME="DEV-QBO Sync"
```

**Note**: The system prioritizes database configuration over .env settings.

### Step 5: Restart Apache
```bash
systemctl restart apache2
```

### Step 6: Verify Deployment
```bash
# Check if provider presets were created
mysql -u root -p Xhelo_qbo_devpos -e "SELECT COUNT(*) as preset_count FROM email_provider_presets;"

# Should show 6 presets (gmail, microsoft365, sendgrid, mailgun, amazon_ses, custom)
```

## Usage Instructions

### For Admin Users:

1. **Access Email Configuration**:
   - Go to: https://devsync.konsulence.al/public/admin-email-config.html
   - Or from Email Settings page, click "Configure Email Provider"

2. **Choose Provider**:
   - Click on your preferred email provider card
   - Read the setup instructions displayed

3. **Configure Settings**:
   - Enter your email/username
   - Enter password or app-specific password
   - Set "From" address and name
   - For custom SMTP, also enter host, port, and encryption

4. **Save & Test**:
   - Click "Save Configuration" to store settings
   - Click "Test Email" to verify configuration works

### Gmail Setup (Recommended):

1. **Enable 2-Factor Authentication** on your Gmail account:
   - Go to https://myaccount.google.com/security

2. **Generate App Password**:
   - Go to https://myaccount.google.com/apppasswords
   - Select "Mail" and "Other (Custom name)"
   - Enter "DEV-QBO Sync"
   - Copy the 16-character password

3. **Configure in System**:
   - Select "Gmail / Google Workspace" provider
   - Enter your full Gmail address
   - Paste the 16-character app password (no spaces)
   - Save configuration

### Microsoft 365 Setup:

1. **Use your Office 365 credentials**:
   - Email: your-email@yourdomain.com
   - Password: Your regular account password

2. **If 2FA is enabled**:
   - Go to https://account.live.com/proofs/AppPassword
   - Generate an app-specific password
   - Use that instead of your regular password

## API Endpoints

### Get Available Providers
```
GET /api/email/providers
```

Returns list of all available email provider presets.

### Get Specific Provider
```
GET /api/email/providers/{key}
```

Returns details for a specific provider (e.g., `/api/email/providers/gmail`).

### Apply Provider Configuration
```
POST /api/email/apply-preset
Content-Type: application/json

{
  "provider_key": "gmail",
  "username": "your-email@gmail.com",
  "password": "your-app-password",
  "from_address": "noreply@yourdomain.com",
  "from_name": "DEV-QBO Sync"
}
```

For custom SMTP, also include:
```json
{
  "provider_key": "custom",
  "custom_host": "mail.yourdomain.com",
  "custom_port": 587,
  "custom_encryption": "tls",
  "username": "...",
  "password": "..."
}
```

## Security Notes

- Passwords are encrypted using AES-256-CBC before storage
- Encryption key is stored in `.env` file (`ENCRYPTION_KEY`)
- Never commit `.env` file with real credentials to Git
- Use app-specific passwords instead of main account passwords
- Gmail and Microsoft 365 both support app passwords

## Troubleshooting

### Test Email Fails
1. Verify credentials are correct
2. Check if 2FA requires app password
3. For Gmail: Ensure "Less secure app access" is NOT needed (app passwords work with 2FA)
4. Check firewall allows outbound SMTP (port 587 or 465)
5. Review logs: `tail -50 /var/www/html/storage/logs/app.log`

### Provider Not Showing
1. Verify database migration ran: 
   ```bash
   mysql -u root -p Xhelo_qbo_devpos -e "SHOW TABLES LIKE 'email_provider_presets';"
   ```
2. Check if presets were inserted:
   ```bash
   mysql -u root -p Xhelo_qbo_devpos -e "SELECT * FROM email_provider_presets;"
   ```

### Configuration Not Saving
1. Check file permissions: `ls -la /var/www/html`
2. Verify Apache can write to database
3. Check error logs: `tail -50 /var/log/apache2/error.log`

## Future Enhancements

Planned features:
- Support for more providers (Postmark, SparkPost, etc.)
- Email queue system for better reliability
- Webhook support for delivery tracking
- Email analytics dashboard
- Bulk email sending capabilities
- Template customization per company

## Links

- **Gmail App Passwords**: https://myaccount.google.com/apppasswords
- **Microsoft App Passwords**: https://account.live.com/proofs/AppPassword
- **SendGrid**: https://sendgrid.com
- **Mailgun**: https://mailgun.com
- **Amazon SES**: https://aws.amazon.com/ses/
