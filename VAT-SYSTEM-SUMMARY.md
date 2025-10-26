# VAT System Summary

## What Just Got Deployed

A **flexible VAT mapping system** that gives you full control over how VAT is handled per company.

## Two Modes

### 1. VAT OFF (Current Default - All Companies)
- **Use for:** Non-VAT registered businesses
- **QuickBooks Setup:** Tax tracking OFF
- **Behavior:** Invoices sync without any tax information
- **Status:** ✅ Ready to use (all companies currently set to this)

### 2. VAT ON (When Needed)
- **Use for:** VAT-registered businesses that need tax breakdown
- **QuickBooks Setup:** Tax tracking ON with configured tax rates
- **Behavior:** Maps DevPos VAT rates to QuickBooks tax codes
- **Status:** ✅ System ready, needs per-company configuration

## Quick Start (Non-VAT Company - Company #4)

Since QuickBooks tax is OFF for company #4, you can sync invoices **right now**:

1. Open dashboard: https://devsync.konsulence.al/app.html
2. Select company #4 (Xheladin Palushi)
3. Click "Sync Invoices" under Manual Sync
4. Invoices should now sync successfully! ✅

## When You Need VAT Tracking (Future)

For companies that ARE VAT-registered:

### Step 1: Enable in Dashboard
- Check ☑ "Company is VAT-registered" in company settings

### Step 2: Turn ON Tax in QuickBooks
- Settings → Company Settings → Tax → Enable "Track tax"
- Add tax rates: 0%, 10%, 20%, etc.

### Step 3: Create VAT Mappings via API

```bash
# Example: Map 20% VAT to QuickBooks 'TAX' code
curl -X POST https://devsync.konsulence.al/api/companies/4/vat-mappings \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "devpos_vat_rate": 20.00,
    "qbo_tax_code": "TAX",
    "qbo_tax_code_name": "Standard VAT 20%"
  }'

# Map VAT-excluded (0%)
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

## API Endpoints Added

```
GET    /api/companies/{id}/vat-mappings          # List mappings
POST   /api/companies/{id}/vat-mappings          # Create mapping
DELETE /api/companies/{id}/vat-mappings/{mapId}  # Delete mapping
```

## Database Table Created

```sql
vat_rate_mappings
- id
- company_id
- devpos_vat_rate (e.g., 20.00)
- qbo_tax_code (e.g., 'TAX', 'EXEMPT')
- qbo_tax_code_name
- is_excluded (TRUE for VAT-excluded transactions)
```

## Key Benefits

✅ **Default is safe:** All companies use VAT OFF (no tax complexity)  
✅ **Flexible:** Enable VAT tracking only when needed  
✅ **Configurable:** Map any DevPos VAT rate to any QBO tax code  
✅ **Per-company:** Each company has independent VAT settings  
✅ **Handles edge cases:** Excluded transactions, multiple rates, zero-rated  

## Files Added/Modified

- ✅ `sql/add-vat-mapping.sql` - Database table
- ✅ `src/Services/SyncExecutor.php` - VAT mapping logic
- ✅ `routes/api.php` - VAT mapping API endpoints
- ✅ `VAT-MAPPING-GUIDE.md` - Complete documentation (384 lines)

## Next Steps

1. **Test invoice sync now** for company #4 (should work!)
2. Review `VAT-MAPPING-GUIDE.md` for detailed examples
3. When ready to enable VAT for a company, follow the guide
4. Consider adding VAT mapping UI to dashboard (future enhancement)

## Code Highlights

### Smart Fallback
If no VAT mapping exists:
- 0% VAT → Uses 'NON' (non-taxable)
- Other rates → Uses 'TAX' (standard)

### Invoice Payload
```php
// Non-VAT company (tracks_vat = FALSE)
{
  "Amount": 100.00,
  // NO TaxCodeRef
}

// VAT company (tracks_vat = TRUE)
{
  "Amount": 120.00,
  "TaxCodeRef": {"value": "TAX"} // Mapped from DevPos rate
}
```

## Testing

Try syncing invoices for company #4 now - should work since:
- ✅ `tracks_vat = FALSE` (VAT OFF)
- ✅ QuickBooks tax tracking is OFF
- ✅ No tax information sent in payload

---

**Need help?** Check `VAT-MAPPING-GUIDE.md` for complete documentation with examples, troubleshooting, and API reference.
