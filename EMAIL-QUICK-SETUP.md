# Quick Email Configuration Guide

## 🚀 For Immediate Use - Gmail Setup (5 minutes)

### Step 1: Generate Gmail App Password
1. Go to: https://myaccount.google.com/apppasswords
2. Click "Generate" → Select "Mail" → Enter "DEV-QBO Sync"
3. Copy the 16-character password (e.g., `abcd efgh ijkl mnop`)

### Step 2: Configure in System
1. Access: https://devsync.konsulence.al/public/admin-email-config.html
2. Click on "Gmail / Google Workspace" card
3. Enter:
   - **Email**: your-email@gmail.com
   - **Password**: Paste the 16-character app password (remove spaces)
   - **From Address**: your-email@gmail.com
   - **From Name**: DEV-QBO Sync
4. Click "Save Configuration"
5. Click "Test Email" and enter your email to verify

### Done! ✅
Password reset emails, welcome emails, and notifications will now work.

---

## 📧 Alternative: Microsoft 365

### Step 1: Get Credentials
- **Email**: your-email@yourdomain.com (full address)
- **Password**: Your regular password
- **If 2FA enabled**: Generate app password at https://account.live.com/proofs/AppPassword

### Step 2: Configure
1. Go to: https://devsync.konsulence.al/public/admin-email-config.html
2. Click "Microsoft 365 / Outlook.com"
3. Enter credentials and save

---

## 🛠️ For System Administrators

### Deployment Commands
```bash
ssh root@78.46.201.151
cd /var/www/html
git pull origin main
mysql -u root -p Xhelo_qbo_devpos < sql/add-email-provider-presets.sql
systemctl restart apache2
```

### Verify Installation
```bash
mysql -u root -p Xhelo_qbo_devpos -e "SELECT COUNT(*) FROM email_provider_presets;"
# Should show: 6

curl -s https://devsync.konsulence.al/api/email/providers | jq '.providers[].provider_name'
# Should list: Gmail, Microsoft 365, SendGrid, Mailgun, Amazon SES, Custom
```

### Check Configuration Status
```bash
mysql -u root -p Xhelo_qbo_devpos -e "SELECT provider_key, mail_host, mail_username, is_enabled FROM email_config;"
```

---

## 🔧 Troubleshooting

### "Test email failed"
1. **Gmail**: Did you use app password (not regular password)?
2. **Credentials**: Double-check email and password
3. **2FA**: App password required when 2FA is enabled
4. **Firewall**: Check port 587 (TLS) is open

### "Configuration not saved"
1. Check database connection
2. Verify file permissions: `chown -R www-data:www-data /var/www/html`
3. Check logs: `tail -50 /var/log/apache2/error.log`

### "Provider not showing"
1. Run database migration: `mysql -u root -p Xhelo_qbo_devpos < sql/add-email-provider-presets.sql`
2. Clear browser cache (Ctrl+Shift+R)

---

## 📚 Full Documentation

For complete setup instructions, API details, and security notes:
- See: `EMAIL-CONFIG-DEPLOYMENT.md`
- Or run: `cat EMAIL-CONFIG-DEPLOYMENT.md | less`

---

## 🔐 Security Checklist

- [x] Passwords encrypted with AES-256-CBC
- [x] Encryption key stored securely in .env
- [x] Use app-specific passwords (not main passwords)
- [x] Database configuration prioritized over .env
- [x] HTTPS enforced for all email settings pages

---

## 📝 Quick Notes

- **Preferred Provider**: Gmail (most reliable, easy setup)
- **App Passwords Required**: Gmail with 2FA, Microsoft with 2FA
- **Default Port**: 587 (TLS)
- **Test First**: Always test after configuration
- **Logs Location**: `/var/www/html/storage/logs/app.log`

---

**Need Help?**
Check EMAIL-CONFIG-DEPLOYMENT.md for detailed troubleshooting.
