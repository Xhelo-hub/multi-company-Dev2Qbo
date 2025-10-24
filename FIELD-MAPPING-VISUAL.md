<?php
require __DIR__ . '/../routes/field-mappings.php';

# 🎨 Visual Field Mapping - DevPos to QuickBooks

## 🧾 Invoice Mapping (E-Invoices)

```
┌───────────────────────────────────────────────────────┐
│          DevPos E-Invoice (Albania)                   │
└───────────────────────────────────────────────────────┘
                        │
                        │ Transform
                        ▼
┌───────────────────────────────────────────────────────┐
│        QuickBooks Online Invoice (USA)                │
└───────────────────────────────────────────────────────┘

DevPos Fields                      →    QuickBooks Fields
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

📋 Document Info
├─ eic: "12345678-ABCD-EFGH"      →    CustomField[EIC]: "12345678-ABCD-EFGH"
├─ documentNumber: "INV-2025-001" →    DocNumber: "INV-2025-001"
└─ issueDate: "2025-01-15"        →    TxnDate: "2025-01-15"

👤 Customer Info
├─ buyerName: "Customer ABC"      →    CustomerRef.value: "1" (lookup)
└─ buyerNuis: "K12345678X"        →    [Used for customer matching]

💰 Financial
├─ totalAmount: 10000.50          →    Line[0].Amount: 10000.50
├─ totalAmount: 10000.50          →    Line[0].SalesItemLineDetail.UnitPrice: 10000.50
└─ currency: "ALL"                →    [Not yet mapped]

📄 Line Items (Future)
├─ items[0].description           →    Line[0].Description
├─ items[0].quantity              →    Line[0].SalesItemLineDetail.Qty
├─ items[0].unitPrice             →    Line[0].SalesItemLineDetail.UnitPrice
└─ items[0].amount                →    Line[0].Amount

📎 Attachments
└─ pdf: "base64encodedPDF"        →    Attachment (uploaded via API)
```

---

## 🧾 Sales Receipt Mapping (Cash Sales)

```
┌───────────────────────────────────────────────────────┐
│     DevPos Cash Sale / Simplified Invoice             │
└───────────────────────────────────────────────────────┘
                        │
                        │ Filter & Transform
                        ▼
┌───────────────────────────────────────────────────────┐
│        QuickBooks Online SalesReceipt                 │
└───────────────────────────────────────────────────────┘

DevPos Fields                             →    QuickBooks Fields
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

📋 Document Info
├─ eic: "87654321-WXYZ-1234"             →    [Tracking only]
├─ documentNumber: "REC-2025-001"        →    DocNumber: "REC-2025-001"
└─ issueDate: "2025-01-15"               →    TxnDate: "2025-01-15"

👤 Customer Info
├─ buyerName: "Walk-in Customer"         →    CustomerRef.value: "1"
└─ isSimplifiedInvoice: true             →    [Used for filtering]

💰 Financial
├─ totalAmount: 500.00                   →    Line[0].Amount: 500.00
└─ totalAmount: 500.00                   →    Line[0].SalesItemLineDetail.UnitPrice: 500.00

💳 Payment Info
├─ invoicePayments[0].paymentMethodType  →    PaymentMethodRef.value
│  ├─ 0 = Cash                           →    (Payment method lookup)
│  └─ 1 = Card                           →    (Payment method lookup)
└─ invoicePayments[0].amount             →    [Not yet mapped]

📎 Attachments
└─ pdf: "base64encodedPDF"               →    Attachment (uploaded via API)
```

---

## 🧾 Bill Mapping (Purchase Invoices)

```
┌───────────────────────────────────────────────────────┐
│        DevPos Purchase Invoice                        │
└───────────────────────────────────────────────────────┘
                        │
                        │ Transform & Create Vendor
                        ▼
┌───────────────────────────────────────────────────────┐
│           QuickBooks Online Bill                      │
└───────────────────────────────────────────────────────┘

DevPos Fields                            →    QuickBooks Fields
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

📋 Document Info
├─ documentNumber: "BILL-2025-001"       →    DocNumber: "BILL-2025-001"
├─ issueDate: "2025-01-15"               →    TxnDate: "2025-01-15"
└─ eic: "composite-key"                  →    [Tracking: docNum|vendorNuis]

🏢 Vendor Info
├─ sellerName: "Supplier ABC"            →    VendorRef.value: "123"
│                                             (Create if not exists)
└─ sellerNuis: "K98765432Y"              →    [Stored in vendor_mappings]

💰 Financial
├─ amount / total / totalAmount: 5000.00 →    Line[0].Amount: 5000.00
├─ [expense category]                    →    AccountBasedExpenseLineDetail.AccountRef
│                                             value: "1" (QBO_DEFAULT_EXPENSE_ACCOUNT)
└─ sellerName: "Supplier ABC"            →    Line[0].Description: "Bill from Supplier ABC"

📄 Line Items (Future)
├─ items[0].description                  →    Line[0].Description
├─ items[0].amount                       →    Line[0].Amount
└─ items[0].expenseCategory              →    Line[0].AccountBasedExpenseLineDetail.AccountRef

📎 Attachments
└─ pdf: "base64encodedPDF"               →    Attachment (uploaded via API)
```

---

## 🏢 Vendor Auto-Creation

```
┌───────────────────────────────────────────────────────┐
│   DevPos Vendor Data (from Purchase Invoice)         │
└───────────────────────────────────────────────────────┘
                        │
                        │ 1. Check vendor_mappings table
                        │ 2. If not found, create in QBO
                        │ 3. Store mapping
                        ▼
┌───────────────────────────────────────────────────────┐
│           QuickBooks Online Vendor                    │
└───────────────────────────────────────────────────────┘

DevPos Fields                     →    QuickBooks Fields
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

🏢 Vendor Identity
├─ sellerName: "Supplier ABC"    →    DisplayName: "Supplier ABC (K98765432Y)"
├─ sellerNuis: "K98765432Y"      →    [Stored in local mapping table]
└─ [composite display name]      →    PrintOnCheckName: "Supplier ABC"

📊 Local Mapping Stored
├─ company_id: 1                 →    Multi-tenant isolation
├─ devpos_nuis: "K98765432Y"     →    Albanian Tax ID
├─ vendor_name: "Supplier ABC"   →    Display name
└─ qbo_vendor_id: 123            →    QuickBooks vendor ID

🔍 Lookup Logic
┌──────────────────────────────────────────────────┐
│  1. Query: SELECT qbo_vendor_id                  │
│     FROM vendor_mappings                         │
│     WHERE company_id = ? AND devpos_nuis = ?     │
│                                                  │
│  2. If found → Use existing QBO vendor ID        │
│                                                  │
│  3. If not found:                                │
│     a. Create vendor in QuickBooks               │
│     b. Store mapping in vendor_mappings          │
│     c. Return new vendor ID                      │
└──────────────────────────────────────────────────┘
```

---

## 📦 Complete Data Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                         SYNC PROCESS                            │
└─────────────────────────────────────────────────────────────────┘

Step 1: Fetch from DevPos
┌──────────────────────┐
│  DevPos API          │
│  /api/v3/Invoices    │    Filters:
│  /PurchaseInvoices   │    ├─ Date range (from_date → to_date)
│  /api/v3/SalesReceipts│   ├─ Company tenant ID
└──────┬───────────────┘    └─ Document type
       │                    Returns: JSON array of documents
       ▼
┌──────────────────────┐
│  Raw DevPos Data     │
│  [{invoice1}, ...]   │
└──────┬───────────────┘
       │
       │
Step 2: Check for Duplicates
       ▼
┌─────────────────────────────────────────┐
│  Query: invoice_mappings table          │
│  WHERE company_id = ? AND devpos_eic = ?│
└──────┬──────────────────────────────────┘
       │
       ├─ IF EXISTS → Skip (already synced)
       │
       └─ IF NOT EXISTS → Continue
                ▼
Step 3: Transform Data
┌─────────────────────────────────────────┐
│  Field Mapping                          │
│  ├─ Map DevPos fields → QBO fields      │
│  ├─ Lookup/create customer/vendor       │
│  ├─ Format dates, amounts               │
│  └─ Build QBO JSON structure            │
└──────┬──────────────────────────────────┘
       │
       │
Step 4: Create in QuickBooks
       ▼
┌─────────────────────────────────────────┐
│  QuickBooks API                         │
│  POST /v3/company/{id}/invoice          │
│  POST /v3/company/{id}/bill             │
│  POST /v3/company/{id}/salesreceipt     │
└──────┬──────────────────────────────────┘
       │
       │ Returns: {"Invoice": {"Id": 123, ...}}
       ▼
Step 5: Store Mapping
┌─────────────────────────────────────────┐
│  INSERT INTO invoice_mappings           │
│  (devpos_eic, qbo_invoice_id, ...)      │
└──────┬──────────────────────────────────┘
       │
       │
Step 6: Attach PDF (if available)
       ▼
┌─────────────────────────────────────────┐
│  QuickBooks Attachments API             │
│  POST /v3/company/{id}/upload           │
│  AttachableRef → Invoice/Bill ID        │
└─────────────────────────────────────────┘
```

---

## 🔄 Sync Types Comparison

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           SYNC TYPE MATRIX                                  │
└─────────────────────────────────────────────────────────────────────────────┘

Sync Type       | DevPos Source           | QBO Destination | Key Field
─────────────────────────────────────────────────────────────────────────────
SALES           | E-Invoices              | Invoice         | eic
(Credit sales)  | (Non-cash payments)     | (Accounts       | (Electronic
                |                         |  Receivable)    |  Invoice Code)
─────────────────────────────────────────────────────────────────────────────
CASH            | E-Invoices              | SalesReceipt    | eic
(Cash sales)    | (Cash/Card payments)    | (Deposit to     | (Electronic
                | isSimplifiedInvoice     |  checking)      |  Invoice Code)
─────────────────────────────────────────────────────────────────────────────
BILLS           | Purchase Invoices       | Bill            | docNumber|nuis
(Purchases)     | (Vendor invoices)       | (Accounts       | (Composite key
                |                         |  Payable)       |  for tracking)
─────────────────────────────────────────────────────────────────────────────

Detection Logic:

SALES → Invoice
  ✓ NOT isSimplifiedInvoice
  ✓ Payment method ≠ Cash (0) or Card (1)

CASH → SalesReceipt
  ✓ isSimplifiedInvoice = true, OR
  ✓ Payment method = Cash (0) or Card (1)

BILLS → Bill
  ✓ Source: /PurchaseInvoices endpoint
  ✓ Has sellerNuis and sellerName
```

---

## 📊 Database Relationship Diagram

```
┌─────────────────────────┐
│      companies          │
│  ─────────────────────  │
│  id (PK)                │
│  company_code           │
│  name                   │
└──────────┬──────────────┘
           │ 1:N
           │
┌──────────▼──────────────────────────────────────┐
│      invoice_mappings (Transaction Tracking)    │
│  ──────────────────────────────────────────────│
│  id (PK)                                        │
│  company_id (FK) ──┐                            │
│  devpos_eic        │ Composite unique key       │
│  devpos_document_number                         │
│  transaction_type  │ 'invoice', 'receipt', 'bill'
│  qbo_invoice_id                                 │
│  qbo_doc_number                                 │
│  amount                                         │
│  customer_name / vendor_name                    │
│  synced_at                                      │
│  last_synced_at                                 │
└─────────────────────────────────────────────────┘

┌──────────▼──────────────────────────────────────┐
│      vendor_mappings (Vendor Lookup Cache)      │
│  ──────────────────────────────────────────────│
│  id (PK)                                        │
│  company_id (FK) ──┐                            │
│  devpos_nuis       │ Unique per company         │
│  vendor_name                                    │
│  qbo_vendor_id                                  │
│  created_at                                     │
└─────────────────────────────────────────────────┘

Purpose:
├─ invoice_mappings → Prevent duplicate syncs
├─ vendor_mappings  → Cache QBO vendor IDs
└─ Both scoped by company_id for multi-tenant isolation
```

---

## 🎯 Field Mapping Status Legend

```
Symbol Key:
✅ Fully Mapped    - Field is completely transformed and synced
🟡 Partial         - Field is mapped but with limitations
❌ Not Mapped      - Field exists in DevPos but not synced
🔄 Planned         - Field mapping planned for future release
⚠️  Manual Setup   - Requires configuration in .env or QBO

Current Status:
┌───────────────────────────────────────────────────────────┐
│ Document Identifiers           ✅ eic, documentNumber     │
│ Financial Amounts              ✅ totalAmount, total      │
│ Dates                          ✅ issueDate, dateIssued   │
│ Customer/Vendor Names          ✅ buyerName, sellerName   │
│ Customer/Vendor Tax IDs        ✅ buyerNuis, sellerNuis   │
│ PDF Attachments                ✅ pdf (base64)            │
│                                                           │
│ Line Item Details              🟡 Aggregated to single    │
│ Product/Service Mapping        ❌ Not implemented         │
│ Tax Codes (VAT)                ❌ Not implemented         │
│ Expense Account Mapping        🟡 Uses default account    │
│ Payment Terms                  ❌ Not implemented         │
│ Custom Fields                  🟡 Only EIC mapped         │
│ Multi-Currency                 ❌ Not implemented         │
│                                                           │
│ Line Items Array               🔄 Planned for Phase 2    │
│ Category-to-Account Mapping    🔄 Planned for Phase 2    │
│ Customer Auto-Creation         🔄 Planned for Phase 2    │
│ Tax Mapping                    🔄 Planned for Phase 2    │
└───────────────────────────────────────────────────────────┘
```

---

## 🛠️ Quick Reference: Configuration

```bash
# .env Configuration

# QuickBooks Custom Field IDs
QBO_CF_EIC_DEF_ID=1              # ⚠️ Must create custom field in QBO first

# Default Accounts
QBO_DEFAULT_EXPENSE_ACCOUNT=1    # ⚠️ Bills use this account
QBO_DEFAULT_INCOME_ACCOUNT=1     # Future: Sales revenue account

# Environment
QBO_ENV=production               # or 'sandbox' for testing

# Multi-Currency (Future)
QBO_DEFAULT_CURRENCY=ALL         # Albanian Lek
```

```sql
-- Required Database Tables

CREATE TABLE invoice_mappings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    devpos_eic VARCHAR(255),
    devpos_document_number VARCHAR(100),
    transaction_type ENUM('invoice', 'receipt', 'bill'),
    qbo_invoice_id INT,
    qbo_doc_number VARCHAR(100),
    amount DECIMAL(15,2),
    customer_name VARCHAR(255),
    synced_at DATETIME,
    last_synced_at DATETIME,
    UNIQUE KEY unique_mapping (company_id, devpos_eic)
);

CREATE TABLE vendor_mappings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    devpos_nuis VARCHAR(50),
    vendor_name VARCHAR(255),
    qbo_vendor_id VARCHAR(50),
    created_at DATETIME,
    UNIQUE KEY unique_vendor (company_id, devpos_nuis)
);
```

---

## 📞 Support & References

**Need Help?**
- 📖 See `FIELD-MAPPING.md` for detailed documentation
- 🔍 See `README.md` for API setup
- 🚀 See `QUICK-START.md` for getting started

**Code Files:**
- `src/Sync/SalesSync.php` - Invoice & receipt sync
- `src/Sync/BillsSync.php` - Bill sync
- `src/Services/SyncExecutor.php` - Main orchestrator
- `src/Helpers/CustomerVendorManager.php` - Entity management

---

**Version:** 1.0  
**Last Updated:** January 2025  
**Visual Style:** ASCII Diagrams for universal compatibility

<button class="btn btn-primary" onclick="window.location.href='admin-field-mappings.html'">
    <span class="icon-circle"><i class="fas fa-code-branch"></i></span>
    Field Mappings
</button>
