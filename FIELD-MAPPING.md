# DevPos to QuickBooks Online - Field Mapping Documentation

## Overview
This document describes how data fields are transformed when syncing from DevPos (Albanian fiscal system) to QuickBooks Online.

---

## 📋 Table of Contents
1. [Sales Invoices (E-Invoices)](#1-sales-invoices-e-invoices)
2. [Sales Receipts (Cash Sales)](#2-sales-receipts-cash-sales)
3. [Bills (Purchase Invoices)](#3-bills-purchase-invoices)
4. [Vendors](#4-vendors)
5. [System Tracking](#5-system-tracking)

---

## 1. Sales Invoices (E-Invoices)

**DevPos Source:** E-Invoices API (`/api/v3/Invoices`)  
**QuickBooks Destination:** Invoice entity  
**Sync Logic:** `SalesSync.php` → `InvoiceTransformer::fromDevpos()`

### Field Mappings

| DevPos Field | QuickBooks Field | Transformation | Notes |
|-------------|------------------|----------------|-------|
| `eic` (Electronic Invoice Code) | `CustomField['EIC']` | Direct copy | Unique identifier, stored in custom field |
| `totalAmount` | `Line[0].Amount` | Cast to float | Total invoice amount |
| `totalAmount` | `Line[0].SalesItemLineDetail.UnitPrice` | Cast to float | Single line item |
| `buyerName` | Customer lookup | Create/find customer | Maps to `CustomerRef.value` |
| `buyerNuis` | Customer search | NUIS-based lookup | Tax ID for customer matching |
| `documentNumber` | `DocNumber` | Direct copy | Invoice number |
| `issueDate` / `dateIssued` | `TxnDate` | Format: `Y-m-d` | Transaction date |
| `pdf` (base64) | Attachment | Upload PDF | Attached to QBO invoice |

### QuickBooks Structure Created
```json
{
  "CustomerRef": {
    "value": "1"  // Lookup/create customer by NUIS
  },
  "Line": [
    {
      "DetailType": "SalesItemLineDetail",
      "Amount": 10000.50,
      "SalesItemLineDetail": {
        "ItemRef": {"value": "1"},  // Default service item
        "Qty": 1,
        "UnitPrice": 10000.50
      }
    }
  ],
  "CustomField": [
    {
      "DefinitionId": "1",  // QBO_CF_EIC_DEF_ID
      "Name": "EIC",
      "Type": "StringType",
      "StringValue": "12345678-ABCD-EFGH"
    }
  ],
  "DocNumber": "INV-2025-001",
  "TxnDate": "2025-01-15"
}
```

### Current Limitations
- ⚠️ **Single Line Item:** All invoices created with one aggregated line
- ⚠️ **Default Customer:** Currently uses customer ID "1" (needs lookup implementation)
- ⚠️ **Default Item:** Uses item ID "1" for all sales
- 🔄 **Future:** Parse DevPos line items (`items[]` array) and map individually

---

## 2. Sales Receipts (Cash Sales)

**DevPos Source:** E-Invoices API (filtered by payment type)  
**QuickBooks Destination:** SalesReceipt entity  
**Sync Logic:** `SalesSync.php` → `SalesReceiptTransformer::fromDevpos()`

### Field Mappings

| DevPos Field | QuickBooks Field | Transformation | Notes |
|-------------|------------------|----------------|-------|
| `eic` | Tracking ID | String | Unique identifier |
| `totalAmount` | `Line[0].Amount` | Cast to float | Total receipt amount |
| `buyerName` | Customer lookup | Create/find customer | Maps to `CustomerRef.value` |
| `isSimplifiedInvoice` | Payment detection | Boolean filter | Determines if cash sale |
| `invoicePayments[].paymentMethodType` | Payment method | Filter: 0=Cash, 1=Card | Used to identify receipts |
| `documentNumber` | `DocNumber` | Direct copy | Receipt number |
| `issueDate` | `TxnDate` | Format: `Y-m-d` | Sale date |
| `pdf` | Attachment | Upload PDF | Attached to QBO receipt |

### Cash Sale Detection Logic
```php
$isSimplified = (bool)($doc['isSimplifiedInvoice'] ?? false);
$payments = $doc['invoicePayments'] ?? [];
$types = array_map(fn($p) => $p['paymentMethodType'] ?? null, $payments);

// Cash (0) or Card (1) payment types
$isCashSale = $isSimplified || in_array(0, $types) || in_array(1, $types);
```

### QuickBooks Structure Created
```json
{
  "CustomerRef": {
    "value": "1"  // Cash customer or lookup
  },
  "Line": [
    {
      "DetailType": "SalesItemLineDetail",
      "Amount": 500.00,
      "SalesItemLineDetail": {
        "ItemRef": {"value": "1"},
        "Qty": 1,
        "UnitPrice": 500.00
      }
    }
  ],
  "PaymentMethodRef": {
    "value": "1"  // Cash or Card
  },
  "DocNumber": "REC-2025-001",
  "TxnDate": "2025-01-15"
}
```

### Current Limitations
- ⚠️ **Single Line Item:** Aggregated total only
- ⚠️ **Payment Method:** Not fully mapped (needs payment method lookup)
- 🔄 **Future:** Map `invoicePayments[]` to QBO payment lines

---

## 3. Bills (Purchase Invoices)

**DevPos Source:** Purchase Invoices API (`/api/v3/PurchaseInvoices`)  
**QuickBooks Destination:** Bill entity  
**Sync Logic:** `BillsSync.php` → `BillTransformer::fromDevpos()`

### Field Mappings

| DevPos Field | QuickBooks Field | Transformation | Notes |
|-------------|------------------|----------------|-------|
| `sellerNuis` | Vendor lookup | NUIS-based | Creates vendor if missing |
| `sellerName` | Vendor name | String | Used for vendor creation |
| `amount` / `total` / `totalAmount` | `Line[0].Amount` | Cast to float | Bill amount |
| `documentNumber` | `DocNumber` | Direct copy | Bill/invoice number |
| `issueDate` / `dateIssued` | `TxnDate` | Format: `Y-m-d` | Bill date |
| `pdf` | Attachment | Upload PDF | Attached to QBO bill |
| `eic` | Tracking | Composite key | `docNumber\|vendorNuis` |

### QuickBooks Structure Created
```json
{
  "VendorRef": {
    "value": "123"  // Lookup/create by NUIS
  },
  "Line": [
    {
      "DetailType": "AccountBasedExpenseLineDetail",
      "Amount": 5000.00,
      "AccountBasedExpenseLineDetail": {
        "AccountRef": {
          "value": "1"  // QBO_DEFAULT_EXPENSE_ACCOUNT
        }
      },
      "Description": "Bill from Supplier ABC"
    }
  ],
  "DocNumber": "BILL-2025-001",
  "TxnDate": "2025-01-15"
}
```

### Current Limitations
- ⚠️ **Single Expense Account:** All bills use one default account (set via `QBO_DEFAULT_EXPENSE_ACCOUNT`)
- ⚠️ **No Line Items:** Aggregated as single expense line
- ⚠️ **Description Only:** Vendor name in description, no item details
- 🔄 **Future:** 
  - Map DevPos categories to QBO expense accounts
  - Parse line items from `items[]` array
  - Support multi-line bills with different accounts

---

## 4. Vendors

**DevPos Source:** `sellerNuis` + `sellerName` from purchase invoices  
**QuickBooks Destination:** Vendor entity  
**Sync Logic:** `CustomerVendorManager.php` (via `BillsSync.php`)

### Vendor Creation/Lookup

| DevPos Field | QuickBooks Field | Transformation | Notes |
|-------------|------------------|----------------|-------|
| `sellerNuis` | Vendor search key | String | Albanian Tax ID (NIPT) |
| `sellerName` | `DisplayName` | String | Vendor display name |
| `sellerNuis` | Custom field or notes | String | Stored for future lookups |

### Vendor Matching Logic
```php
// 1. Check local mapping table
SELECT qbo_vendor_id FROM vendor_mappings 
WHERE company_id = ? AND devpos_nuis = ?

// 2. If not found, create new vendor in QBO
POST /v3/company/{realmId}/vendor
{
  "DisplayName": "Supplier ABC (K12345678X)"
}

// 3. Store mapping for future syncs
INSERT INTO vendor_mappings (devpos_nuis, qbo_vendor_id)
```

### QuickBooks Vendor Created
```json
{
  "DisplayName": "Supplier ABC (K12345678X)",
  "PrintOnCheckName": "Supplier ABC",
  "CompanyName": "Supplier ABC"
}
```

### Current Limitations
- ⚠️ **Basic Vendor Data:** Only name and NUIS mapped
- ⚠️ **No Address/Contact:** DevPos may have this data but it's not synced
- 🔄 **Future:**
  - Map vendor address
  - Map vendor phone/email
  - Map payment terms

---

## 5. System Tracking

### Invoice Mappings Table
Tracks all synced documents for duplicate prevention.

| Local Field | Purpose | Example Value |
|------------|---------|---------------|
| `company_id` | Multi-tenant isolation | `1` |
| `devpos_eic` | DevPos EIC or composite key | `12345-ABC` or `DOC001\|K123` |
| `devpos_document_number` | DevPos doc number | `INV-2025-001` |
| `transaction_type` | Type of transaction | `invoice`, `receipt`, `bill` |
| `qbo_invoice_id` | QuickBooks entity ID | `456` |
| `qbo_doc_number` | QuickBooks doc number | `1001` |
| `amount` | Transaction amount | `10000.50` |
| `customer_name` / `vendor_name` | Party name | `Customer ABC` |
| `synced_at` | First sync timestamp | `2025-01-15 10:30:00` |
| `last_synced_at` | Last update timestamp | `2025-01-15 10:30:00` |

### Vendor Mappings Table
Caches vendor lookups to avoid repeated QBO API calls.

| Local Field | Purpose | Example Value |
|------------|---------|---------------|
| `company_id` | Multi-tenant isolation | `1` |
| `devpos_nuis` | Albanian Tax ID | `K12345678X` |
| `vendor_name` | Vendor display name | `Supplier ABC` |
| `qbo_vendor_id` | QuickBooks vendor ID | `123` |
| `created_at` | Mapping creation time | `2025-01-15 10:00:00` |

---

## 🔧 Configuration Required

### Environment Variables (.env)
```bash
# QuickBooks Custom Field for EIC
QBO_CF_EIC_DEF_ID=1

# Default expense account for bills
QBO_DEFAULT_EXPENSE_ACCOUNT=1

# QuickBooks environment
QBO_ENV=production  # or sandbox
```

### Database Tables Required
- ✅ `invoice_mappings` - Transaction tracking
- ✅ `vendor_mappings` - Vendor lookup cache
- ✅ `sync_jobs` - Job history
- ✅ `sync_cursors` - Incremental sync tracking

---

## 📊 Data Flow Diagram

```
┌─────────────┐
│   DevPos    │
│   API       │
└──────┬──────┘
       │
       │ Fetch invoices/bills
       │ by date range
       ▼
┌─────────────────────────────┐
│  Sync Service               │
│  (SalesSync/BillsSync)      │
│                             │
│  1. Check if already synced │
│  2. Transform to QBO format │
│  3. Create/lookup entities  │
└──────┬──────────────────────┘
       │
       │ POST Invoice/Bill/Receipt
       ▼
┌─────────────────────┐
│  QuickBooks Online  │
│  API                │
└──────┬──────────────┘
       │
       │ Return QBO ID
       ▼
┌─────────────────────┐
│  Store Mapping      │
│  (invoice_mappings) │
└─────────────────────┘
```

---

## 🚀 Next Steps / Roadmap

### Phase 1: Current Implementation ✅
- ✅ Basic invoice sync (single line item)
- ✅ Basic bill sync (single expense line)
- ✅ Vendor auto-creation
- ✅ Duplicate prevention
- ✅ PDF attachment support

### Phase 2: Enhanced Mapping 🔄
- [ ] Parse DevPos line items (`items[]` array)
- [ ] Map product codes to QBO items
- [ ] Support multi-line invoices
- [ ] Support multi-line bills with expense accounts
- [ ] Customer auto-creation (like vendors)
- [ ] Tax mapping (DevPos VAT to QBO tax codes)

### Phase 3: Advanced Features 🔮
- [ ] Payment status sync
- [ ] Credit notes / refunds
- [ ] Inventory sync (if applicable)
- [ ] Custom field mapping (configurable)
- [ ] Category-to-account mapping
- [ ] Multi-currency support

---

## 📝 Notes

### Transformer Classes Status
**⚠️ IMPORTANT:** The referenced transformer classes (`InvoiceTransformer`, `SalesReceiptTransformer`, `BillTransformer`) are **currently stubs** in the codebase. The actual transformation logic is implemented inline in:
- `src/Services/SyncExecutor.php` - Methods: `convertDevPosToQBOInvoice()`, `convertDevPosToQBOBill()`
- `src/Sync/SalesSync.php` - Uses transformer stubs
- `src/Sync/BillsSync.php` - Uses transformer stubs

### DevPos Data Structure
DevPos returns JSON with varying field names (Albanian vs English):
- `issueDate` or `dateIssued` → Both checked
- `eic` or `EIC` → Case-insensitive
- `totalAmount` or `total` or `amount` → Multiple fallbacks

### QuickBooks Quirks
- **Custom Fields:** Require `DefinitionId` to be pre-configured in QBO
- **Line Items:** Must have `DetailType` specified
- **Vendor/Customer IDs:** Must exist before creating transactions
- **Document Numbers:** QBO enforces uniqueness per entity type

---

## 📚 References

### DevPos API Documentation
- Base URL: `https://online.devpos.al/api/v3`
- E-Invoices: `/Invoices`
- Purchase Invoices: `/PurchaseInvoices`
- Authentication: OAuth2 (tenant-based)

### QuickBooks Online API
- Base URL: `https://quickbooks.api.intuit.com/v3`
- Sandbox: `https://sandbox-quickbooks.api.intuit.com/v3`
- Entities: `/company/{realmId}/invoice`, `/bill`, `/salesreceipt`, `/vendor`
- Authentication: OAuth2

### Related Files
- `src/Sync/SalesSync.php` - Sales sync implementation
- `src/Sync/BillsSync.php` - Bills sync implementation
- `src/Services/SyncExecutor.php` - Main sync orchestrator
- `src/Helpers/CustomerVendorManager.php` - Entity management
- `sql/multi-company-schema.sql` - Database schema

---

**Last Updated:** January 2025  
**Version:** 1.0  
**Status:** Production Ready (with documented limitations)
