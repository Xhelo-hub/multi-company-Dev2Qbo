# User-Specific DevPos Credentials

## Overview
The system now supports **user-specific DevPos credentials**, allowing different users to have their own login credentials for the same company. This is useful when:

- Multiple team members need to sync data from the same company
- Each user has their own DevPos account with different permissions
- You want to track which user performed which sync operation
- Companies change credentials and different users need to transition at different times

## How It Works

### Credential Hierarchy
The system uses a **two-tier credential system**:

1. **User-Specific Credentials** (Priority 1)
   - Stored in `user_devpos_credentials` table
   - Unique per user-company combination
   - Takes precedence when available

2. **Company-Wide Default Credentials** (Priority 2 - Fallback)
   - Stored in `company_credentials_devpos` table
   - Used when a user hasn't set personal credentials
   - Can only be set by admin users

### Database Schema

#### user_devpos_credentials
```sql
CREATE TABLE user_devpos_credentials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    company_id INT NOT NULL,
    tenant VARCHAR(100) NOT NULL,
    username VARCHAR(255) NOT NULL,
    password_encrypted TEXT NOT NULL,
    is_default TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_company (user_id, company_id)
);
```

#### company_credentials_devpos (Existing)
```sql
CREATE TABLE company_credentials_devpos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL UNIQUE,
    tenant VARCHAR(100) NOT NULL,
    username VARCHAR(255) NOT NULL,
    password_encrypted TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

## Usage

### For Regular Users

1. Navigate to **Admin > Companies**
2. Click the **🔐 DevPos** button for any company
3. Enter your personal DevPos credentials:
   - Tenant ID (usually the company NIPT)
   - Your DevPos username
   - Your DevPos password
4. Click **💾 Save Credentials**
5. Optionally, click **🔌 Test Connection** to verify

Your credentials are now saved and will be used for all sync operations you perform for this company.

### For Admin Users

Admins have an additional option to set **Company-Wide Default Credentials**:

1. Click **🔐 DevPos** button
2. Select the credential scope:
   - **👤 My Credentials Only** - Your personal credentials (default)
   - **🏢 Company-Wide Default** - Credentials used by all users without personal settings
3. Enter the credentials
4. Save

**When to use Company-Wide credentials:**
- Setting up initial credentials for new companies
- Providing a fallback for users who haven't set their own
- Using a service account for automated syncs

## API Endpoints

### GET /api/companies/{companyId}/credentials/devpos
Retrieves DevPos credentials for the authenticated user and company.

**Response:**
```json
{
  "tenant": "K43128625A",
  "username": "john.doe@company.com",
  "credential_type": "user"  // or "company" or "none"
}
```

**Credential Resolution:**
1. Check for user-specific credentials
2. If not found, fallback to company-level credentials
3. If neither exists, return empty credentials

### POST /api/companies/{companyId}/credentials/devpos
Saves DevPos credentials.

**Request Body:**
```json
{
  "tenant": "K43128625A",
  "username": "john.doe@company.com",
  "password": "secret123",
  "scope": "user"  // or "company" (admin only)
}
```

**Notes:**
- Password is optional when updating existing credentials
- `scope: "company"` requires admin role
- Passwords are encrypted using AES-256-CBC before storage

### GET /api/companies/{companyId}/credentials/devpos/test
Tests the connection using the user's credentials (user-specific or fallback).

**Response (Success):**
```json
{
  "success": true,
  "message": "✅ DevPos connection successful!",
  "expires_in": 86400,
  "expires_in_hours": 24.0
}
```

**Response (Failure):**
```json
{
  "success": false,
  "message": "DevPos authentication failed",
  "error": "Invalid credentials"
}
```

## Security Features

1. **Password Encryption**
   - All passwords encrypted using AES-256-CBC
   - Encryption key stored in environment variables
   - Passwords never returned in API responses

2. **Access Control**
   - Users can only manage their own credentials
   - Admins can manage both user and company credentials
   - Foreign key constraints ensure data integrity

3. **Audit Trail**
   - `created_at` and `updated_at` timestamps tracked
   - Can be integrated with audit logging system

## Migration

To add this feature to an existing installation:

```bash
# Execute the migration SQL
mysql -u root -D qbo_multicompany < sql/add-user-devpos-credentials.sql
```

This will:
- Create the `user_devpos_credentials` table
- Add necessary indexes
- Preserve existing company-level credentials

## Use Cases

### Scenario 1: Multiple Users, Same Company
**Setup:**
- Company: ABC Ltd (K12345678A)
- Users: Alice (Accountant), Bob (Manager)

**Configuration:**
- Alice sets her own DevPos credentials (alice@devpos.com)
- Bob sets his own DevPos credentials (bob@devpos.com)
- Both can sync data from the same company independently

**Result:**
- Alice's syncs use her credentials
- Bob's syncs use his credentials
- Audit logs show who performed each sync

### Scenario 2: Admin Default + User Override
**Setup:**
- Admin sets company-wide credentials (service@company.com)
- Some users set personal credentials
- Other users don't set any

**Configuration:**
- Admin: Sets company-wide default credentials
- Power User: Sets personal credentials (poweruser@devpos.com)
- Regular User: No personal credentials set

**Result:**
- Power User: Uses personal credentials
- Regular User: Uses company-wide default
- Transparent fallback mechanism

### Scenario 3: Credential Rotation
**Setup:**
- Company changes DevPos password
- Need to transition users gradually

**Configuration:**
- Week 1: Admin updates company-wide credentials to new password
- Week 2-3: Users update their personal credentials
- Week 4: All users on new credentials

**Result:**
- Smooth transition without service interruption
- Users can update at their own pace
- Fallback ensures continuous operation

## Best Practices

1. **For Admins:**
   - Set company-wide credentials as a fallback
   - Use service accounts for automated syncs
   - Regularly audit who has credentials set

2. **For Users:**
   - Set your own credentials if you have a DevPos account
   - Test connection after saving credentials
   - Update credentials promptly when changed

3. **For Security:**
   - Rotate passwords regularly
   - Use strong passwords
   - Monitor failed authentication attempts
   - Don't share credentials between users

## Troubleshooting

### "No credentials configured"
**Problem:** Neither user-specific nor company-wide credentials exist.
**Solution:** Set at least one set of credentials (user or company-wide).

### "DevPos authentication failed"
**Problem:** Credentials are incorrect or expired.
**Solution:** 
1. Verify credentials in DevPos system
2. Update credentials in the app
3. Test connection to confirm

### "Which credentials am I using?"
**Problem:** Unsure if using personal or company-wide credentials.
**Solution:** Check the credential type indicator when editing credentials.

## Future Enhancements

Potential improvements:
- [ ] Credential expiration warnings
- [ ] Automatic credential validation on save
- [ ] Bulk credential import/export
- [ ] Credential usage statistics
- [ ] Multi-factor authentication support
- [ ] Credential sharing between users (with permission)

## Support

For issues or questions:
1. Check the credential type indicator in the modal
2. Test the connection to verify credentials
3. Check audit logs for sync failures
4. Contact system administrator
