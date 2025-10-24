# Production Server Information

## CRITICAL: Correct Production Path
```
❌ WRONG: /var/www/qbo-devpos-sync (this is NOT production!)
✅ CORRECT: /home/converter/web/devsync.konsulence.al/public_html
```

## Server Details
- **Server**: devsync.konsulence.al (78.46.201.151)
- **SSH User**: root (converter password issues)
- **Production Path**: `/home/converter/web/devsync.konsulence.al/public_html/`
- **Database**: qbo_devpos (NOT qbo_multicompany)
- **Web Server**: Apache (not nginx)
- **File Owner**: converter:converter

## Deployment Checklist
1. ✅ SSH: `ssh root@devsync.konsulence.al`
2. ✅ Go to: `cd /home/converter/web/devsync.konsulence.al/public_html`
3. ✅ Backup: `cp -r ../public_html ../backups/backup-$(date +%Y%m%d-%H%M%S)`
4. ✅ Pull changes: `git pull origin main`
5. ✅ Fix permissions: `chown -R converter:converter .`
6. ✅ Test: https://devsync.konsulence.al/public/login.html
7. ✅ Clear browser cache if paths look wrong

## Current Production State
- Commit: a85d622 (Fix PAGE_BASE for production)
- Status: Working ✅
- Login: https://devsync.konsulence.al/public/login.html

## Git Repository
- GitHub: https://github.com/Xhelo-hub/multi-company-Dev2Qbo.git
- Branch: main

## Notes
- Production has different commit history than local
- Need to sync before deploying new changes
- Always verify you're in `/home/converter/web/devsync.konsulence.al/public_html` before running git commands
