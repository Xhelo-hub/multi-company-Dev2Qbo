# DevPos API Authentication - Investigation Guide

## Current Status
We've tested multiple authentication methods:
- ✗ OAuth with various client_id values (all return "invalid_client")
- ✗ API Key authentication (returns HTML login page)
- ✗ Direct API access without auth (returns HTML login page)

## What We Know
1. DevPos uses OAuth 2.0 authentication (IdentityServer4 or similar)
2. Token endpoint: https://online.devpos.al/connect/token
3. API base: https://online.devpos.al/api/v3
4. Requires valid OAuth client_id (and possibly client_secret)

## How to Find the Client ID

### Option 1: Browser Developer Tools (RECOMMENDED)
1. Open Chrome/Edge/Firefox
2. Open Developer Tools (F12)
3. Go to **Network** tab
4. Log into https://online.devpos.al with your credentials
5. Look for a request to `/connect/token`
6. Check the **Request Payload** or **Form Data**
7. You should see something like:
   ```
   grant_type: password
   client_id: <THE VALUE YOU NEED>
   username: M01419018I|xhelo-pgroup
   password: [your password]
   scope: api offline_access
   ```

### Option 2: Check Browser Storage
After logging in:
1. Open Developer Tools (F12)
2. Go to **Application** tab (Chrome) or **Storage** tab (Firefox)
3. Check **Local Storage** or **Session Storage**
4. Look for entries containing:
   - `client_id`
   - `access_token`
   - `devpos` or similar keys

### Option 3: JavaScript Source Code
1. While on https://online.devpos.al
2. Open Developer Tools
3. Go to **Sources** tab
4. Search for: `client_id` (Ctrl+Shift+F)
5. Look for hardcoded values like:
   ```javascript
   clientId: 'web-client',
   clientId: 'devpos-web',
   // or similar
   ```

### Option 4: Check Network Requests
After logging in successfully:
1. Stay in Network tab
2. Click around the DevPos interface
3. Look for API calls to `/api/v3/*`
4. Check the **Request Headers**
5. Look for: `Authorization: Bearer eyJ...`
6. That's the token we need to obtain

## What to Look For

### In /connect/token Request:
```
Form Data:
  grant_type: password
  client_id: ????????  <-- THIS IS WHAT WE NEED
  client_secret: ????  <-- MIGHT BE PRESENT
  username: M01419018I|xhelo-pgroup
  password: [hidden]
  scope: api offline_access
```

### In /connect/token Response:
```json
{
  "access_token": "eyJ...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_token": "..."
}
```

## Once You Have the Client ID

Add it to .env:
```bash
DEVPOS_CLIENT_ID=the-value-you-found
DEVPOS_CLIENT_SECRET=if-required
```

Then run:
```bash
php bin/test-devpos-with-client.php
```

## Alternative: Contact DevPos

If you can't find the client_id in browser tools:

**Email:** support@devpos.al (or check their contact page)

**Subject:** API Integration - OAuth Credentials Request

**Message:**
```
Hello,

We are integrating our system with DevPos API and need OAuth credentials.

Our account details:
- Tenant: M01419018I
- Username: xhelo-pgroup
- URL: https://online.devpos.al

Could you please provide:
1. OAuth client_id for API access
2. OAuth client_secret (if required)
3. API documentation

We need to access:
- /api/v3/sale-einvoices
- /api/v3/purchase-einvoices

Thank you!
```

## Next Steps After Getting Credentials

1. Update .env with DEVPOS_CLIENT_ID
2. Test authentication
3. Implement DevPosClient class
4. Enable sync functionality
