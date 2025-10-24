# Production Deployment Guide

## Quick Deployment

### From Windows (PowerShell)
```powershell
.\deploy-to-production.ps1
```

### From Linux/Mac (Bash)
```bash
chmod +x deploy-to-production.sh
./deploy-to-production.sh
```

---

## Manual Deployment Steps

If you prefer to deploy manually or the scripts don't work:

### 1. Commit and Push Local Changes
```powershell
git add .
git commit -m "Your commit message"
git push origin main
```

### 2. Connect to Production Server
```powershell
ssh root@devsync.konsulence.al
```

### 3. Pull Latest Code
```bash
cd /home/converter/web/devsync.konsulence.al/public_html
git pull origin main
```

### 4. Sync Files to Web Root
```bash
# Copy HTML files
cp public/*.html .

# Copy static assets (if they exist)
cp -r public/css public/js public/assets . 2>/dev/null || true
```

### 5. Set Proper Permissions (if needed)
```bash
chown -R converter:converter /home/converter/web/devsync.konsulence.al/public_html
chmod -R 755 /home/converter/web/devsync.konsulence.al/public_html
```

### 6. Restart Services (if needed)
```bash
# Only if you changed PHP configuration or middleware
systemctl reload apache2
# OR
systemctl reload php8.3-fpm
```

---

## Production Structure

```
/home/converter/web/devsync.konsulence.al/
├── public_html/                    ← Web server document root
│   ├── *.html                     ← HTML files (served by web server)
│   ├── css/                       ← Stylesheets
│   ├── js/                        ← JavaScript
│   ├── assets/                    ← Images, fonts, etc.
│   ├── .htaccess                  ← Apache rewrite rules
│   ├── index.php                  ← Slim Framework entry point
│   ├── bootstrap/                 ← Application bootstrap
│   ├── routes/                    ← API route definitions
│   ├── src/                       ← PHP source code
│   ├── vendor/                    ← Composer dependencies
│   └── public/                    ← Git repository structure
│       └── *.html                 ← Source files (copied up one level)
```

### Why This Structure?

- **Control Panel Hosting**: The hosting uses cPanel/Plesk where `public_html/` is the document root
- **Git Repository**: Kept in `public_html/` for easy updates
- **File Duplication**: HTML files copied from `public/` to `public_html/` for web access
- **Composer/Vendor**: Lives at repository root for proper autoloading

---

## What Gets Deployed

### Automatically via Git Pull:
- ✅ PHP source code (`src/`, `routes/`, `bootstrap/`)
- ✅ Configuration files (`.env`, `.htaccess`)
- ✅ Database migrations (`sql/`)
- ✅ Documentation (`*.md`)
- ✅ Composer files (`composer.json`, `composer.lock`)

### Manually Synced After Pull:
- ✅ HTML files (`public/*.html` → `public_html/*.html`)
- ✅ CSS files (`public/css/` → `public_html/css/`)
- ✅ JavaScript files (`public/js/` → `public_html/js/`)
- ✅ Assets (`public/assets/` → `public_html/assets/`)

### NOT Deployed (Stays on Production):
- ❌ `.env` with production credentials
- ❌ `vendor/` (regenerate with `composer install`)
- ❌ Database data
- ❌ Log files

---

## Database Migrations

When SQL schema changes:

```bash
ssh root@devsync.konsulence.al
cd /home/converter/web/devsync.konsulence.al/public_html

# Run migration
mysql -u Xhelo -p Xhelo_qbo_devpos < sql/your-migration-file.sql
```

Or manually via phpMyAdmin/MySQL client.

---

## Rollback Procedure

If deployment breaks something:

```bash
ssh root@devsync.konsulence.al
cd /home/converter/web/devsync.konsulence.al/public_html

# Revert to previous commit
git log --oneline -5                    # Find previous commit hash
git reset --hard COMMIT_HASH            # Revert code
cp public/*.html .                      # Re-sync files

# Or restore from backup
cp -r /backup/public_html_YYYYMMDD/* .
```

---

## Troubleshooting

### HTML Files Not Showing
```bash
# Re-sync files
ssh root@devsync.konsulence.al
cd /home/converter/web/devsync.konsulence.al/public_html
cp public/*.html .
```

### API Routes Not Working
```bash
# Check if .htaccess exists and is correct
cat /home/converter/web/devsync.konsulence.al/public_html/.htaccess

# Check if mod_rewrite is enabled
apache2ctl -M | grep rewrite
```

### Permission Errors
```bash
# Fix ownership
chown -R converter:converter /home/converter/web/devsync.konsulence.al/public_html

# Fix permissions
find /home/converter/web/devsync.konsulence.al/public_html -type d -exec chmod 755 {} \;
find /home/converter/web/devsync.konsulence.al/public_html -type f -exec chmod 644 {} \;
```

### 500 Internal Server Error
```bash
# Check PHP error logs
tail -100 /var/log/php8.3-fpm.log
tail -100 /var/log/apache2/error.log

# Check file syntax
cd /home/converter/web/devsync.konsulence.al/public_html
php -l index.php
php -l bootstrap/app.php
```

---

## Environment Variables

Production `.env` file location:
```
/home/converter/web/devsync.konsulence.al/public_html/.env
```

Never commit this file! Keep production credentials secure.

---

## Post-Deployment Verification

1. **Homepage**: https://devsync.konsulence.al/dashboard.html
2. **Login**: Test authentication works
3. **API Health**: https://devsync.konsulence.al/api/auth/me
4. **Field Mappings**: https://devsync.konsulence.al/admin-field-mappings.html
5. **Check Logs**: Verify no PHP errors

---

## Security Checklist

Before deploying to production:

- [ ] `.env` contains production credentials (not dev)
- [ ] `APP_ENV=production` in `.env`
- [ ] Database credentials are correct
- [ ] Encryption key is set and secure
- [ ] No debug mode enabled in production
- [ ] File permissions are restrictive (755/644)
- [ ] SSL certificate is valid
- [ ] Backup created before major changes

---

## Support

If deployment fails or you encounter issues:

1. Check this guide's troubleshooting section
2. Review recent commit changes: `git log -5`
3. Check production error logs
4. Test locally first to ensure code works
5. Consider rolling back to previous working version
