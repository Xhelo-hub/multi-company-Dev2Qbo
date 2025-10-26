# VAT Rate Mapping System

## Overview

The Multi-Company Dev2QBO system now supports **flexible VAT handling** with two modes:

1. **VAT OFF** (Default): Companies with `tracks_vat = FALSE` send invoices without any tax information
   - QuickBooks company must have "Tax Tracking" disabled
   - Invoice totals are posted as-is without VAT breakdown
   - Simple, straightforward for non-VAT registered businesses

2. **VAT ON**: Companies with `tracks_vat = TRUE` use configurable VAT rate mappings
   - Maps DevPos VAT rates to QuickBooks tax codes
   - Supports multiple VAT rates (Standard 20%, Reduced 10%, Zero 0%, etc.)
   - Handles VAT-excluded transactions
   - QuickBooks company must have "Tax Tracking" enabled

## Database Structure

### VAT Rate Mappings Table

```sql
CREATE TABLE vat_rate_mappings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    devpos_vat_rate DECIMAL(5,2) NOT NULL,    -- e.g., 20.00, 10.00, 0.00
    qbo_tax_code VARCHAR(50) NOT NULL,         -- e.g., 'TAX', 'EXEMPT', '20VAT'
    qbo_tax_code_name VARCHAR(100),            -- Display name
    is_excluded BOOLEAN DEFAULT FALSE,         -- TRUE for VAT-excluded
    FOREIGN KEY (company_id) REFERENCES companies(id)
);
```

## How It Works

### For Non-VAT Companies (`tracks_vat = FALSE`)

1. **QuickBooks Setup**: Turn OFF "Tax Tracking" in QuickBooks company settings
2. **Sync Behavior**: 
   - Invoice payload omits ALL tax-related fields
   - Total amount from DevPos is posted directly to QuickBooks
   - No `TaxCodeRef`, no `GlobalTaxCalculation`, no `TxnTaxDetail`
3. **Use Case**: Businesses not registered for VAT

### For VAT-Registered Companies (`tracks_vat = TRUE`)

1. **QuickBooks Setup**: 
   - Turn ON "Tax Tracking" in QuickBooks
   - Configure tax rates (Taxes → Tax Rates)
   - Note the tax code values (e.g., 'TAX', '20VAT', 'EXEMPT')

2. **Configure VAT Mappings**:
   - Map each DevPos VAT rate to a QuickBooks tax code
   - Example mappings:
     ```
     DevPos 0%   → QBO 'EXEMPT'  (VAT-excluded)
     DevPos 10%  → QBO '10VAT'   (Reduced rate)
     DevPos 20%  → QBO 'TAX'     (Standard rate)
     ```

3. **Sync Behavior**:
   - System reads `vatRate` from DevPos invoice
   - Looks up corresponding `qbo_tax_code` in mappings table
   - Includes `TaxCodeRef` in invoice payload
   - QuickBooks calculates VAT breakdown based on the tax code

## API Endpoints

### Get VAT Mappings
```http
GET /api/companies/{id}/vat-mappings
Authorization: Bearer {token}
```

**Response:**
```json
[
  {
    "id": 1,
    "company_id": 4,
    "devpos_vat_rate": 0.00,
    "qbo_tax_code": "EXEMPT",
    "qbo_tax_code_name": "Tax Exempt",
    "is_excluded": true
  },
  {
    "id": 2,
    "company_id": 4,
    "devpos_vat_rate": 20.00,
    "qbo_tax_code": "TAX",
    "qbo_tax_code_name": "Standard VAT 20%",
    "is_excluded": false
  }
]
```

### Create/Update VAT Mapping
```http
POST /api/companies/{id}/vat-mappings
Authorization: Bearer {token}
Content-Type: application/json

{
  "devpos_vat_rate": 20.00,
  "qbo_tax_code": "TAX",
  "qbo_tax_code_name": "Standard VAT 20%",
  "is_excluded": false
}
```

### Delete VAT Mapping
```http
DELETE /api/companies/{id}/vat-mappings/{mappingId}
Authorization: Bearer {token}
```

## Setup Instructions

### Step 1: Enable VAT Tracking (When Needed)

1. Open company in dashboard
2. Scroll to "VAT Tracking Configuration"
3. Check ☑ "Company is VAT-registered"
4. Save changes

### Step 2: Configure QuickBooks Tax Settings

#### For Non-VAT Companies:
- Log into QuickBooks Online
- Go to **Settings → Company Settings → Tax**
- Turn **OFF** "Track tax"
- Save

#### For VAT-Registered Companies:
- Log into QuickBooks Online
- Go to **Taxes → Tax Rates**
- Add required tax rates:
  - Standard VAT (20%)
  - Reduced VAT (10%)
  - Zero rate (0%)
  - Tax Exempt
- Note the **tax code values** for each rate

### Step 3: Create VAT Mappings (For VAT Companies Only)

Use the API or dashboard to create mappings:

```bash
# Example: Map 20% VAT rate
curl -X POST https://devsync.konsulence.al/api/companies/4/vat-mappings \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "devpos_vat_rate": 20.00,
    "qbo_tax_code": "TAX",
    "qbo_tax_code_name": "Standard VAT 20%",
    "is_excluded": false
  }'

# Example: Map VAT-excluded transactions
curl -X POST https://devsync.konsulence.al/api/companies/4/vat-mappings \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "devpos_vat_rate": 0.00,
    "qbo_tax_code": "EXEMPT",
    "qbo_tax_code_name": "Tax Exempt",
    "is_excluded": true
  }'
```

## Example Scenarios

### Scenario 1: Non-VAT Business

**Company:** Small business not registered for VAT  
**QuickBooks:** Tax tracking OFF  
**System Config:** `tracks_vat = FALSE`

**DevPos Invoice:**
```json
{
  "documentNumber": "INV-001",
  "totalAmount": 100.00,
  "vatRate": 0
}
```

**QuickBooks Payload:**
```json
{
  "Line": [{
    "Amount": 100.00,
    "SalesItemLineDetail": {
      "ItemRef": {"value": "1"},
      "UnitPrice": 100.00,
      "Qty": 1
    }
  }],
  "CustomerRef": {"value": "1"},
  "TxnDate": "2025-10-26"
}
```

### Scenario 2: VAT-Registered Business - Standard Rate

**Company:** VAT-registered business  
**QuickBooks:** Tax tracking ON, 20% VAT configured  
**System Config:** `tracks_vat = TRUE`  
**Mapping:** 20.00 → 'TAX'

**DevPos Invoice:**
```json
{
  "documentNumber": "INV-002",
  "totalAmount": 120.00,
  "vatRate": 20.00
}
```

**QuickBooks Payload:**
```json
{
  "Line": [{
    "Amount": 120.00,
    "SalesItemLineDetail": {
      "ItemRef": {"value": "1"},
      "TaxCodeRef": {"value": "TAX"}
    },
    "Description": "Invoice: INV-002 - VAT: 20%"
  }],
  "CustomerRef": {"value": "1"},
  "TxnDate": "2025-10-26"
}
```

QuickBooks will calculate: Net £100.00 + VAT £20.00 = Total £120.00

### Scenario 3: VAT-Registered Business - Exempt Transaction

**Company:** VAT-registered business  
**QuickBooks:** Tax tracking ON  
**System Config:** `tracks_vat = TRUE`  
**Mapping:** 0.00 → 'EXEMPT' (is_excluded = TRUE)

**DevPos Invoice:**
```json
{
  "documentNumber": "INV-003",
  "totalAmount": 50.00,
  "vatRate": 0.00
}
```

**QuickBooks Payload:**
```json
{
  "Line": [{
    "Amount": 50.00,
    "SalesItemLineDetail": {
      "ItemRef": {"value": "1"},
      "TaxCodeRef": {"value": "EXEMPT"}
    },
    "Description": "Invoice: INV-003 - VAT: 0%"
  }]
}
```

QuickBooks will record: Total £50.00 (no VAT)

## Fallback Behavior

If no VAT mapping exists for a specific rate, the system uses intelligent defaults:

- **0% VAT**: Uses 'NON' (non-taxable) tax code
- **Non-zero VAT**: Uses 'TAX' (standard taxable) tax code

## Troubleshooting

### Error: "Make sure all your transactions have a VAT rate"

**Cause:** QuickBooks has tax tracking ON but no tax rates configured  
**Solution:** Either disable tax tracking or add tax rates in QuickBooks

### Error: "Invalid TaxCode"

**Cause:** The `qbo_tax_code` in your mapping doesn't exist in QuickBooks  
**Solution:** 
1. Check available tax codes in QuickBooks (Taxes → Tax Rates)
2. Update your VAT mapping with the correct code

### Invoices syncing without VAT breakdown

**Cause:** `tracks_vat = FALSE` when it should be TRUE  
**Solution:** Enable VAT tracking for the company in dashboard

### VAT calculated twice

**Cause:** DevPos total already includes VAT, but wrong QuickBooks setup  
**Solution:** 
- If `tracks_vat = TRUE`: Ensure QuickBooks tax rate matches DevPos rate
- If `tracks_vat = FALSE`: Ensure QuickBooks tax tracking is OFF

## Migration from Old System

If you have existing companies using the old VAT system:

1. All companies default to `tracks_vat = FALSE` (safe default)
2. For each VAT-registered company:
   - Set `tracks_vat = TRUE`
   - Create VAT rate mappings
   - Test with a few invoices
   - Verify totals match in QuickBooks

## Dashboard UI (Coming Soon)

The dashboard will include a VAT mapping manager:

```
📊 VAT Rate Mappings

DevPos VAT Rate | QuickBooks Tax Code | Type      | Actions
0.00%           | EXEMPT              | Excluded  | [Edit] [Delete]
10.00%          | 10VAT               | Reduced   | [Edit] [Delete]
20.00%          | TAX                 | Standard  | [Edit] [Delete]

[+ Add New Mapping]
```

## Technical Implementation

### Code Flow

1. **SyncExecutor.php** Line 520: Check `tracks_vat` for company
2. **Line 564**: If VAT tracking ON, call `getQBOTaxCodeForVATRate()`
3. **Line 638**: Query `vat_rate_mappings` table
4. **Line 648**: Return mapped tax code or intelligent default
5. **Line 570**: Include `TaxCodeRef` in invoice payload

### Database Query

```php
$stmt = $pdo->prepare("
    SELECT qbo_tax_code, is_excluded
    FROM vat_rate_mappings
    WHERE company_id = ? AND devpos_vat_rate = ?
");
$stmt->execute([$companyId, $vatRate]);
```

### Fallback Logic

```php
if (!$mapping) {
    if ($vatRate == 0) {
        return 'NON'; // Non-taxable
    }
    return 'TAX'; // Standard taxable
}
```

## Best Practices

1. **Start Simple**: Keep `tracks_vat = FALSE` until you need VAT breakdown
2. **Test First**: Test VAT mappings with 1-2 invoices before bulk sync
3. **Document Codes**: Keep a list of your QuickBooks tax codes
4. **Regular Review**: Review VAT mappings quarterly as tax rates change
5. **Backup Before**: Always backup before changing VAT settings

## Future Enhancements

- Dashboard UI for VAT mapping management
- Import/export VAT mappings
- Bulk apply mappings across companies
- VAT reconciliation reports
- Automatic tax rate detection from QuickBooks API

## Support

For issues or questions:
- Check sync job logs for detailed error messages
- Review QuickBooks tax setup (Taxes → Tax Rates)
- Verify VAT mappings match QuickBooks tax codes
- Contact support with company ID and sync job ID
