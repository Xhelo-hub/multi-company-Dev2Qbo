# Current DevPos → QuickBooks Field Mapping

## ✅ WORKING (Fully Implemented)

### Invoice Header Fields
| DevPos Field | QuickBooks Field | Notes |
|--------------|------------------|-------|
| `eic` | Custom Field "EIC" | ✅ Truncated to 31 chars |
| `documentNumber` | `DocNumber` | ✅ Invoice number |
| `issueDate` / `dateCreated` | `TxnDate` | ✅ Transaction date |

### Line Items (Single Line)
| DevPos Field | QuickBooks Field | Notes |
|--------------|------------------|-------|
| `totalAmount` / `amount` | `Amount` | ✅ Total invoice amount |
| `documentNumber` | `Description` | ✅ "Invoice: 2/2025" |

### Tax/VAT (Conditional)
| DevPos Field | QuickBooks Field | Notes |
|--------------|------------------|-------|
| `vatRate` | `TaxCodeRef` | ✅ Only if `tracks_vat=TRUE` |
| - | Tax omitted | ✅ If `tracks_vat=FALSE` |

---

## ❌ NOT WORKING (Hardcoded/Missing)

### Customer Mapping
| DevPos Field | QuickBooks Field | Current Status | Impact |
|--------------|------------------|----------------|---------|
| `buyerName` | `CustomerRef.value` | ❌ **HARDCODED to "1"** | All invoices assigned to default customer |
| `buyerNuis` | - | ❌ **NOT USED** | Can't match customers by tax ID |

**Example from DevPos:**
```json
{
  "buyerName": "KISHA UNGJILLORE MEMALIAJ",
  "buyerNuis": "L88929571P"
}
```

**Sent to QuickBooks:**
```json
{
  "CustomerRef": {
    "value": "1"  // ← Always customer ID "1" (default/demo customer)
  }
}
```

### Product/Service Item Mapping
| DevPos Field | QuickBooks Field | Current Status | Impact |
|--------------|------------------|----------------|---------|
| Invoice lines | `ItemRef.value` | ❌ **HARDCODED to "1"** | All items show as generic "Services" |
| Product details | - | ❌ **NOT FETCHED** | No line item details |

**Sent to QuickBooks:**
```json
{
  "ItemRef": {
    "value": "1",
    "name": "Services"  // ← Always generic service item
  }
}
```

### Amount Field Issues
| DevPos Field | Value | Used? | Problem |
|--------------|-------|-------|---------|
| `amount` | 0.0 (some invoices) | ✅ YES | ❌ Some invoices show 0 |
| `totalAmount` | ??? | ✅ Fallback | ❌ Might not exist |
| Individual line items | ??? | ❌ NO | ❌ Not fetched from API |

---

## 🔍 What DevPos Returns vs What We Use

### Sales Invoice Endpoint
**API Call:** `GET /EInvoice/GetSalesInvoice?fromDate=X&toDate=Y`

**DevPos Response:**
```json
{
  "statusCanBeChanged": false,
  "eic": "a963d2d8-8945-45af-a2c9-6db284c72a71",
  "documentNumber": "2/2025",
  "invoiceCreatedDate": "2025-05-21T14:33:57+02:00",
  "dueDate": "2025-05-22T00:00:00+02:00",
  "invoiceStatus": "Pranuar",
  "amount": 0.0,                                    ← ❌ ZERO for some invoices
  "buyerNuis": "L88929571P",                        ← ❌ NOT USED
  "sellerNuis": "K43128625A",
  "buyerName": "KISHA UNGJILLORE MEMALIAJ",        ← ❌ IGNORED (hardcoded customer)
  "sellerName": "",
  "partyType": "Shitje"
}
```

**What We Extract:**
- ✅ `eic` → Custom field (truncated)
- ✅ `documentNumber` → DocNumber
- ✅ `invoiceCreatedDate` → TxnDate
- ⚠️ `amount` → Amount (but it's 0 for some!)
- ❌ `buyerName` → Read but not used (hardcoded to customer "1")
- ❌ `buyerNuis` → Completely ignored

---

## 🚨 Critical Issues

### Issue 1: Zero Amount Invoices
**Problem:** DevPos returns `amount: 0.0` for invoice 2/2025
**Impact:** QuickBooks invoice created with $0.00
**Root Cause:** DevPos list endpoint doesn't include line item details
**Solution Needed:** Call detail endpoint or use different amount field

### Issue 2: All Invoices Go to Default Customer
**Problem:** `CustomerRef.value` is hardcoded to "1"
**Impact:** All invoices assigned to "Amy's Bird Sanctuary" (sandbox) or first customer (production)
**Root Cause:** No customer mapping/lookup implemented
**Solution Needed:** 
- Look up customer by `buyerNuis` or `buyerName`
- Create customer if doesn't exist
- Map DevPos buyer to QBO customer

### Issue 3: All Items Show as "Services"
**Problem:** `ItemRef.value` is hardcoded to "1" 
**Impact:** No line item detail, everything is generic "Services"
**Root Cause:** No line items from DevPos
**Solution Needed:** Fetch invoice details with line items

### Issue 4: Sandbox vs Production
**Problem:** `.env` has `QBO_ENV=sandbox`
**Impact:** Syncing to demo QuickBooks company, not real company
**Status:** User needs to decide sandbox or production

---

## 📋 Required Fixes (Priority Order)

### Priority 1: Customer Mapping (CRITICAL)
**Without this, all invoices go to wrong customer!**

```php
// Current (WRONG):
'CustomerRef' => [
    'value' => '1' // Hardcoded
]

// Needed:
'CustomerRef' => [
    'value' => $this->getOrCreateCustomer($buyerName, $buyerNuis, $companyId)
]
```

**Implementation needed:**
1. `getOrCreateCustomer()` method
2. Customer mapping table (DevPos buyer → QBO customer ID)
3. Fallback to default customer only if creation fails

### Priority 2: Invoice Line Items (HIGH)
**Current:** Single line with total amount
**Needed:** Actual product/service lines from invoice

Options:
- Call DevPos detail endpoint: `GET /EInvoice/GetByEIC/{eic}`
- Parse line items and map products
- Sum line items for total

### Priority 3: Amount Field (HIGH)
**Current:** Uses `amount` field which is sometimes 0
**Investigation needed:**
- Check if DevPos has `totalAmount` field
- Check if detail endpoint has better amount data
- Parse line items and calculate total

### Priority 4: Product/Service Mapping (MEDIUM)
**Current:** Everything mapped to item ID "1" (Services)
**Needed:**
- Map DevPos products to QBO items
- Create items if missing
- Use actual item descriptions

---

## 🎯 What Works Well

✅ **VAT handling** - Conditional tax code mapping based on company setting
✅ **EIC tracking** - Custom field stores truncated EIC for reference
✅ **Document numbers** - Properly mapped
✅ **Date handling** - Transaction dates correct
✅ **Timeout protection** - 30s timeout prevents hangs
✅ **Progress logging** - Detailed sync progress tracking
✅ **Error handling** - Captures and logs QuickBooks validation errors

---

## 🔧 Recommended Next Steps

1. **Decide Environment:**
   - Change `QBO_ENV=sandbox` to `production` OR
   - Accept that you're testing in sandbox

2. **Implement Customer Mapping:**
   - Add `getOrCreateCustomer()` method
   - Create customer mapping table
   - Handle customer lookup/creation

3. **Fix Amount Issues:**
   - Investigate DevPos detail endpoint
   - Find correct amount field
   - Implement line item parsing

4. **Test with Real Data:**
   - Sync 1-2 invoices
   - Verify in QuickBooks
   - Adjust mappings as needed

5. **Add Line Item Support:**
   - Fetch invoice details
   - Map product lines
   - Handle multi-line invoices

---

## 📊 Summary

| Feature | Status | Impact | Fix Difficulty |
|---------|--------|--------|----------------|
| EIC tracking | ✅ Working | Low | - |
| Document numbers | ✅ Working | Low | - |
| Transaction dates | ✅ Working | Low | - |
| VAT handling | ✅ Working | Low | - |
| Customer mapping | ❌ Broken | **CRITICAL** | Medium |
| Invoice amounts | ⚠️ Partial | **HIGH** | Medium |
| Line items | ❌ Missing | High | High |
| Product mapping | ❌ Missing | Medium | High |

**System Status:** ⚠️ **PARTIALLY FUNCTIONAL** - Invoices sync but with wrong customer and sometimes wrong amounts.
