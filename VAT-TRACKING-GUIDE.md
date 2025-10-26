# VAT Tracking Configuration Guide

## Overview
This system supports **two types of companies**:

1. **VAT-Registered Companies** - Track VAT separately in QuickBooks
2. **Non-VAT Companies** - Post totals only without VAT tracking

## How It Works

### Database Configuration
Each company has a `tracks_vat` flag in the `companies` table:

```sql
-- Check current VAT tracking status
SELECT id, company_code, company_name, tracks_vat 
FROM companies;

-- Enable VAT tracking for a company (VAT-registered)
UPDATE companies SET tracks_vat = TRUE WHERE id = 5;

-- Disable VAT tracking for a company (non-VAT)
UPDATE companies SET tracks_vat = FALSE WHERE id = 10;
```

### Invoice Posting Behavior

#### Non-VAT Companies (`tracks_vat = FALSE`)
- **Use Case**: Companies not qualified for VAT tracking
- **QuickBooks Behavior**: Posts total amount with `TaxCodeRef = 'NON'` (Non-taxable)
- **Result**: Invoice total is posted as-is, no VAT calculated or tracked
- **Example**:
  ```json
  {
    "Line": [{
      "Amount": 12000,
      "TaxCodeRef": { "value": "NON" }
    }]
  }
  ```
- **QuickBooks Shows**: Total: 12,000 ALL (no tax breakdown)

#### VAT-Registered Companies (`tracks_vat = TRUE`)
- **Use Case**: Companies with VAT registration that need to track VAT separately
- **QuickBooks Behavior**: Posts with `TaxCodeRef = 'TAX'` (Taxable)
- **Result**: QuickBooks calculates VAT based on customer/item tax settings
- **Example**:
  ```json
  {
    "Line": [{
      "Amount": 12000,
      "TaxCodeRef": { "value": "TAX" }
    }]
  }
  ```
- **QuickBooks Shows**: 
  - Subtotal: 10,000 ALL
  - VAT (20%): 2,000 ALL
  - Total: 12,000 ALL

## Setup Instructions

### Step 1: Run Database Migration
```bash
# Execute the SQL to add the tracks_vat column
mysql Xhelo_qbo_devpos < sql/add-vat-tracking.sql
```

### Step 2: Configure Each Company
```sql
-- Identify which companies track VAT
-- Update accordingly:

-- Example: Companies 1, 5, 10 are VAT-registered
UPDATE companies SET tracks_vat = TRUE WHERE id IN (1, 5, 10);

-- Example: Companies 2, 3, 4 don't track VAT
UPDATE companies SET tracks_vat = FALSE WHERE id IN (2, 3, 4);
```

### Step 3: Verify Configuration
```sql
SELECT 
    c.id,
    c.company_name,
    c.tracks_vat,
    CASE 
        WHEN c.tracks_vat = 1 THEN 'VAT will be calculated and tracked'
        ELSE 'Totals posted without VAT tracking'
    END as behavior
FROM companies c
WHERE c.is_active = 1
ORDER BY c.id;
```

## QuickBooks Prerequisites

### For Both Company Types
1. **Default Sales Item** - Item ID = 1 must exist
2. **Default Customer** - Customer ID = 1 must exist

### Additional Requirements for VAT Companies
1. **Tax Code "TAX"** must be configured in QuickBooks
2. **Tax Rate** must be set up (e.g., 20% VAT for Albania)
3. **Customer Tax Settings** should specify default tax rate
4. **Item Tax Settings** should be taxable

## Example Scenarios

### Scenario 1: Restaurant (Non-VAT Company)
- **Business**: Small restaurant not qualified for VAT
- **Configuration**: `tracks_vat = FALSE`
- **Invoice in DevPos**: 15,000 ALL total (includes any applicable taxes)
- **Posted to QuickBooks**: 15,000 ALL as single line, no tax tracking
- **Result**: Simple bookkeeping, total revenue recorded

### Scenario 2: Software Company (VAT-Registered)
- **Business**: IT company registered for VAT
- **Configuration**: `tracks_vat = TRUE`
- **Invoice in DevPos**: 24,000 ALL total (20,000 + 4,000 VAT)
- **Posted to QuickBooks**: 24,000 ALL, QBO calculates VAT breakdown
- **Result**: 
  - Revenue: 20,000 ALL
  - VAT Collected: 4,000 ALL
  - Total: 24,000 ALL
  - VAT can be reported separately

## Applies To

This VAT tracking configuration affects:

- ✅ **Sales Invoices** - Synced from DevPos to QuickBooks
- ✅ **Purchase Invoices** - Can be configured similarly (future enhancement)
- ✅ **Bills** - Can be configured similarly (future enhancement)
- ✅ **Cash Receipts** - Can be configured similarly (future enhancement)

## Dashboard Configuration (Future)

Currently, VAT tracking is configured via SQL. Future enhancement could add:

1. **Company Settings Page** in dashboard
2. **Toggle**: "Company is VAT-registered"
3. **Auto-detection**: Check QuickBooks company preferences
4. **Bulk Update**: Set all companies at once

## Troubleshooting

### Error: "Make sure all your transactions have a VAT rate"
**Cause**: Company has `tracks_vat = TRUE` but QuickBooks doesn't have proper tax setup
**Solution**:
```sql
-- Option 1: Disable VAT tracking for this company
UPDATE companies SET tracks_vat = FALSE WHERE id = X;

-- Option 2: Configure tax rates in QuickBooks Online
-- Go to: Taxes → Manage Tax Rates → Add VAT rate
```

### Error: "Tax code NON not found"
**Cause**: Rare - QuickBooks account doesn't have NON tax code
**Solution**: 
```sql
-- Use TAX code with 0% rate instead
-- This is automatically handled by the system
```

### Wrong VAT Amount Calculated
**Cause**: QuickBooks customer or item has different tax rate
**Solution**:
1. Check customer default tax rate in QuickBooks
2. Check item tax settings in QuickBooks
3. Adjust to match expected rate (e.g., 20%)

## Migration Notes

### Existing Companies
All existing companies default to `tracks_vat = FALSE` (non-VAT mode) after migration.

**Action Required**: Review each company and set `tracks_vat = TRUE` for VAT-registered ones.

### Existing Invoices
Already-synced invoices are not affected. The setting only applies to new syncs after configuration.

## API Endpoints (Future Enhancement)

```bash
# Get company VAT status
GET /api/companies/{id}/vat-status

# Update company VAT status
PATCH /api/companies/{id}/vat-status
{
  "tracks_vat": true
}
```

## Best Practices

1. **Set up correctly from the start** - Changing mid-year complicates tax reporting
2. **Match QuickBooks company type** - If QBO account is non-VAT, use FALSE
3. **Consult with accountant** - VAT requirements vary by country and business size
4. **Document your choice** - Note in company profile why TRUE or FALSE was chosen
5. **Test first** - Try one invoice in QBO to verify correct behavior

## Support

If you need help determining whether a company should track VAT:
- Check local tax authority registration
- Review QuickBooks Online company preferences
- Consult with company's accountant
- Check annual revenue thresholds (Albania: 10M ALL for VAT registration)
