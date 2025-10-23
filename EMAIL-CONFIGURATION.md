# Email Service Configuration Guide

## Overview
The email service is now integrated into the DEV-QBO Sync application for sending:
- Welcome emails when new users are created
- Password reset links
- Temporary passwords (admin password resets)
- Account modification notifications

## Email Provider Configuration

### Option 1: Gmail Configuration

1. **Enable 2-Factor Authentication** on your Gmail account
2. **Generate App Password**:
   - Go to: https://myaccount.google.com/apppasswords
   - Select "Mail" and your device
   - Copy the generated 16-character password

3. **Update .env file**:
```env
MAIL_ENABLED=true
MAIL_DRIVER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-app-password-here
MAIL_FROM_ADDRESS=your-email@gmail.com
MAIL_FROM_NAME=DEV-QBO Sync
```

### Option 2: Microsoft 365 Configuration

1. **Use your Microsoft 365 email credentials**
2. **Update .env file**:
```env
MAIL_ENABLED=true
MAIL_DRIVER=smtp
MAIL_HOST=smtp.office365.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=your-email@yourdomain.com
MAIL_PASSWORD=your-microsoft-password
MAIL_FROM_ADDRESS=your-email@yourdomain.com
MAIL_FROM_NAME=DEV-QBO Sync
```

### Option 3: Other SMTP Providers

Common SMTP settings for popular providers:

**Outlook.com / Hotmail.com:**
```env
MAIL_HOST=smtp-mail.outlook.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
```

**Yahoo Mail:**
```env
MAIL_HOST=smtp.mail.yahoo.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
```

**SendGrid:**
```env
MAIL_HOST=smtp.sendgrid.net
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=apikey
MAIL_PASSWORD=your-sendgrid-api-key
```

**Mailgun:**
```env
MAIL_HOST=smtp.mailgun.org
MAIL_PORT=587
MAIL_ENCRYPTION=tls
```

## Configuration Parameters

| Parameter | Description | Default |
|-----------|-------------|---------|
| `MAIL_ENABLED` | Enable/disable email sending | `true` |
| `MAIL_DRIVER` | Mail driver (smtp, sendmail, mail) | `smtp` |
| `MAIL_HOST` | SMTP server hostname | `smtp.gmail.com` |
| `MAIL_PORT` | SMTP server port | `587` |
| `MAIL_ENCRYPTION` | Encryption type (tls, ssl) | `tls` |
| `MAIL_USERNAME` | SMTP username (usually email) | - |
| `MAIL_PASSWORD` | SMTP password or app password | - |
| `MAIL_FROM_ADDRESS` | Default sender email | `noreply@devpos-sync.local` |
| `MAIL_FROM_NAME` | Default sender name | `DEV-QBO Sync` |

## Testing Email Configuration

### Via API (Recommended)

**Endpoint:** `POST /api/admin/test-email`

**Request:**
```bash
curl -X POST https://devsync.konsulence.al/public/api/admin/test-email \
  -H "Content-Type: application/json" \
  -b "session_token=YOUR_SESSION_TOKEN" \
  -d '{"email": "your-test-email@example.com"}'
```

**Response (Success):**
```json
{
  "success": true,
  "message": "Test email sent successfully to your-test-email@example.com"
}
```

**Response (Failure):**
```json
{
  "success": false,
  "message": "Failed to send test email: SMTP Error details..."
}
```

### Via Browser Console

```javascript
fetch('/public/api/admin/test-email', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
    body: JSON.stringify({ email: 'your-test-email@example.com' })
})
.then(r => r.json())
.then(data => console.log(data));
```

## Email Templates

The system includes 4 HTML email templates:

### 1. Welcome Email
**Sent when:** New user registers or is created by admin
**Includes:**
- Welcome message
- Account credentials (if provided by admin)
- Login button
- Security notice (if temporary password)

### 2. Password Reset Email
**Sent when:** User requests password reset
**Includes:**
- Reset link with token
- Expiration notice (1 hour)
- Security warning
- Plain text URL fallback

### 3. Temporary Password Email
**Sent when:** Admin resets user password
**Includes:**
- Temporary password
- Expiration notice (24 hours)
- Login button
- Security reminder to change password

### 4. Account Modified Email
**Sent when:** Admin modifies user account
**Includes:**
- List of changes made
- Security notice
- Contact information

## Triggering Events

### User Registration
```php
// Automatically sends welcome email
POST /api/auth/register
{
  "email": "user@example.com",
  "password": "securepass123",
  "full_name": "John Doe"
}
```

### Password Recovery
```php
// Sends password reset link
POST /api/auth/password-recovery
{
  "email": "user@example.com"
}
```

### Admin Password Reset
```php
// Sends temporary password
POST /api/admin/users/{userId}/reset-password
```

## Troubleshooting

### Email Not Sending

1. **Check if email is enabled:**
   ```env
   MAIL_ENABLED=true
   ```

2. **Verify SMTP credentials:**
   - Test with Gmail app password first (easiest to set up)
   - Check username/password are correct
   - Verify 2FA is enabled (for Gmail)

3. **Check PHP error logs:**
   ```bash
   tail -f /var/log/apache2/error.log
   # or
   tail -f /xampp/apache/logs/error_log
   ```

4. **Test SMTP connection manually:**
   ```bash
   telnet smtp.gmail.com 587
   # Should connect successfully
   ```

5. **Check firewall rules:**
   - Ensure port 587 (or 465) is open
   - Some hosting providers block outgoing SMTP

### Common Errors

**"SMTP Error: Could not authenticate"**
- Wrong username/password
- 2FA not enabled (Gmail)
- App password not generated (Gmail)

**"SMTP Error: Could not connect to SMTP host"**
- Wrong host or port
- Firewall blocking connection
- Server blocking outgoing SMTP

**"SMTP Error: Data not accepted"**
- From address not verified
- Sending limits exceeded
- Content flagged as spam

### Gmail Specific Issues

**"Less secure app access":**
- No longer supported by Gmail
- Must use App Password with 2FA

**Rate Limiting:**
- Gmail limits: 500 emails/day (free), 2000/day (workspace)
- Add delays between emails in bulk operations

### Microsoft 365 Specific Issues

**"Authentication Failed":**
- SMTP AUTH may be disabled for the mailbox
- Enable via: https://admin.microsoft.com > Users > Active users > Mail settings

**"Relay Access Denied":**
- Use authenticated SMTP (port 587)
- Ensure username/password are correct

## Security Best Practices

1. **Never commit credentials to git:**
   - .env file is in .gitignore
   - Use environment variables

2. **Use App Passwords (Gmail):**
   - More secure than account password
   - Can be revoked independently

3. **Enable email only in production:**
   ```env
   # Development
   MAIL_ENABLED=false
   
   # Production
   MAIL_ENABLED=true
   ```

4. **Monitor email logs:**
   - Check PHP error logs regularly
   - Monitor for failed deliveries

5. **Set appropriate from address:**
   - Use a real, monitored email
   - Avoid noreply@ if possible (for better deliverability)

## Advanced Configuration

### Custom SMTP Settings

```env
# SSL on port 465
MAIL_PORT=465
MAIL_ENCRYPTION=ssl

# No encryption (not recommended)
MAIL_PORT=25
MAIL_ENCRYPTION=
```

### Disable Email in Development

```env
MAIL_ENABLED=false
```
Emails will be logged but not sent.

### Custom From Address per Environment

**Production .env:**
```env
MAIL_FROM_ADDRESS=noreply@devsync.konsulence.al
MAIL_FROM_NAME=DevPos-QBO Sync
BASE_URL=https://devsync.konsulence.al
```

**Development .env:**
```env
MAIL_FROM_ADDRESS=dev@localhost
MAIL_FROM_NAME=DEV-QBO Sync (Development)
BASE_URL=http://localhost/multi-company-Dev2Qbo/public
```

## Integration Points

The email service is automatically called in:

1. **routes/auth.php** - User registration (line ~320)
2. **routes/auth.php** - Password recovery (line ~365)
3. **routes/auth.php** - Admin password reset (line ~680)

## Production Deployment

1. Update production `.env` with real SMTP credentials
2. Test email configuration with `/api/admin/test-email`
3. Monitor first few user registrations
4. Check email logs for delivery issues

## Files Modified

- `src/Services/EmailService.php` - Email service implementation
- `routes/auth.php` - Integration with auth endpoints
- `routes/api.php` - Test email endpoint
- `bootstrap/app.php` - Service registration
- `.env` - Email configuration
- `composer.json` - PHPMailer dependency

## Support

For issues with:
- Gmail: https://support.google.com/accounts/answer/185833
- Microsoft 365: https://support.microsoft.com/en-us/office/pop-imap-and-smtp-settings
- SendGrid: https://docs.sendgrid.com/for-developers/sending-email/getting-started-smtp
