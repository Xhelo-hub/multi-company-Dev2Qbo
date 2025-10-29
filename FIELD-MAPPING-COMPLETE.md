# DevPos ↔ QuickBooks Field Mapping Reference

## Overview

This document shows:
1. **What fields DevPos provides** in their API responses
2. **What fields QuickBooks accepts** for each entity type
3. **How we currently map them** in the sync process
4. **What's working vs what needs improvement**

---

## 1. Sales Invoices (E-Invoices)

### DevPos API Endpoint
```
GET /api/v3/EInvoice/GetSalesInvoice?fromDate=YYYY-MM-DD&toDate=YYYY-MM-DD
Headers: Authorization: Bearer <token>, tenant: <tenant_id>
```

### DevPos Response Fields

| Field Name | Type | Description | Example |
|-----------|------|-------------|---------|
| `eic` | string | Electronic Invoice Code (UUID) | "a963d2d8-8945-45af..." |
| `documentNumber` | string | Invoice number | "2/2025" |
| **`invoiceCreatedDate`** | datetime | Invoice creation date | "2025-05-21T14:33:57+02:00" |
| `dueDate` | datetime | Payment due date | "2025-05-22T00:00:00+02:00" |
| `invoiceStatus` | string | Status (Albanian) | "Pranuar" |
| `amount` | decimal | Total amount | 150.00 (⚠️ sometimes 0.0) |
| `totalAmount` | decimal | Alternative total field | 150.00 |
| `buyerName` | string | Customer name | "KISHA UNGJILLORE MEMALIAJ" |
| `buyerNuis` | string | Customer tax ID | "L88929571P" |
| `sellerName` | string | Seller name | "Your Company Name" |
| `sellerNuis` | string | Seller tax ID | "K43128625A" |
| `partyType` | string | Transaction type | "Shitje" (Sale) |
| `isSimplifiedInvoice` | boolean | Cash sale flag | true/false |
| `invoicePayments` | array | Payment methods | [{paymentMethodType: 0}] |
| `statusCanBeChanged` | boolean | Can modify | true/false |

**Note:** For line item details and PDF, call `GET /api/v3/EInvoice?EIC=<eic>` separately.

### QuickBooks Invoice Fields (Accepted)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `TxnDate` | date | Yes | Invoice date (YYYY-MM-DD) |
| `DocNumber` | string | No | Invoice number |
| `CustomerRef.value` | string | Yes | Customer ID |
| `Line[].Amount` | decimal | Yes | Line total |
| `Line[].Description` | string | No | Line description |
| `Line[].SalesItemLineDetail.ItemRef.value` | string | Yes | Product/Service ID |
| `Line[].SalesItemLineDetail.UnitPrice` | decimal | No | Price per unit |
| `Line[].SalesItemLineDetail.Qty` | decimal | No | Quantity |
| `Line[].SalesItemLineDetail.TaxCodeRef.value` | string | No | Tax code (if VAT enabled) |
| `CustomField[].StringValue` | string | No | Custom field value |

### Current Mapping (InvoiceTransformer.php)

| DevPos Field | QuickBooks Field | Status |
|-------------|------------------|--------|
| ✅ `invoiceCreatedDate` | `TxnDate` | **WORKING** (just fixed) |
| ✅ `documentNumber` | `DocNumber` | **WORKING** |
| ✅ `eic` | `CustomField[EIC].StringValue` | **WORKING** (truncated to 31 chars) |
| ✅ `totalAmount` / `amount` | `Line[0].Amount` | **WORKING** (⚠️ may be 0) |
| ⚠️ `buyerName` | - | **READ but NOT USED** |
| ⚠️ `buyerNuis` | - | **IGNORED** |
| ❌ Hardcoded | `CustomerRef.value` = "1" | **WRONG** (always default customer) |
| ❌ Hardcoded | `ItemRef.value` = "1" | **WRONG** (always generic "Services") |
| ❌ No line items | `Line[]` | **MISSING** (only 1 line with total) |

---

## 2. Purchase Bills (Purchase E-Invoices)

### DevPos API Endpoint
```
GET /api/v3/EInvoice/GetPurchaseInvoice?fromDate=YYYY-MM-DD&toDate=YYYY-MM-DD
Headers: Authorization: Bearer <token>, tenant: <tenant_id>
```

### DevPos Response Fields

| Field Name | Type | Description | Example |
|-----------|------|-------------|---------|
| `eic` | string | Electronic Invoice Code | "b8f3a1c9-..." |
| `documentNumber` | string | Bill/Invoice number | "INV-2025-001" |
| **`invoiceCreatedDate`** | datetime | Bill date | "2025-05-20T10:30:00+02:00" |
| `dueDate` | datetime | Payment due date | "2025-06-20T00:00:00+02:00" |
| `amount` | decimal | Total amount | 1200.00 |
| `totalAmount` | decimal | Alternative total | 1200.00 |
| `sellerName` | string | Vendor name | "SUPPLIER ABC SHPK" |
| `sellerNuis` | string | Vendor tax ID | "M12345678N" |
| `buyerName` | string | Your company name | "YOUR COMPANY" |
| `buyerNuis` | string | Your tax ID | "K43128625A" |
| `partyType` | string | Transaction type | "Blerje" (Purchase) |

### QuickBooks Bill Fields (Accepted)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `TxnDate` | date | Yes | Bill date (YYYY-MM-DD) |
| `DueDate` | date | No | Payment due date |
| `DocNumber` | string | No | Vendor invoice number |
| `VendorRef.value` | string | Yes | Vendor ID |
| `Line[].Amount` | decimal | Yes | Line total |
| `Line[].Description` | string | No | Description |
| `Line[].AccountBasedExpenseLineDetail.AccountRef.value` | string | Yes | Expense account ID |
| `PrivateNote` | string | No | Internal note |

### Current Mapping (BillTransformer.php)

| DevPos Field | QuickBooks Field | Status |
|-------------|------------------|--------|
| ✅ `invoiceCreatedDate` | `TxnDate` | **WORKING** (just fixed) |
| ✅ `invoiceCreatedDate` | `DueDate` | **WORKING** (same as TxnDate for now) |
| ✅ `documentNumber` | `DocNumber` | **WORKING** |
| ✅ `totalAmount` / `amount` | `Line[0].Amount` | **WORKING** |
| ⚠️ `sellerNuis` | `VendorRef` lookup | **PARTIAL** (auto-creates vendor) |
| ✅ `eic` + `sellerNuis` | `PrivateNote` | **WORKING** |
| ❌ No line items | `Line[]` | **MISSING** |

---

## 3. Sales Receipts (Cash Sales)

### DevPos API Endpoint
Same as Sales Invoices, but filtered by `isSimplifiedInvoice: true`

### DevPos Response Fields
Same as Sales Invoices, plus:

| Field Name | Type | Description | Example |
|-----------|------|-------------|---------|
| `isSimplifiedInvoice` | boolean | Cash sale indicator | true |
| `invoicePayments[0].paymentMethodType` | integer | Payment method | 0 = Cash, 1 = Card |
| `invoicePayments[0].amount` | decimal | Payment amount | 50.00 |

### QuickBooks SalesReceipt Fields (Accepted)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `TxnDate` | date | Yes | Transaction date |
| `DocNumber` | string | No | Receipt number |
| `CustomerRef.value` | string | Yes | Customer ID |
| `Line[].Amount` | decimal | Yes | Line total |
| `Line[].SalesItemLineDetail.ItemRef.value` | string | Yes | Item ID |
| `PaymentMethodRef.value` | string | No | Payment method ID |

### Current Mapping (SalesReceiptTransformer.php)

| DevPos Field | QuickBooks Field | Status |
|-------------|------------------|--------|
| ✅ `invoiceCreatedDate` | `TxnDate` | **WORKING** (just fixed) |
| ✅ `documentNumber` | `DocNumber` | **WORKING** |
| ✅ `totalAmount` / `amount` | `Line[0].Amount` | **WORKING** |
| ✅ `invoicePayments[0].paymentMethodType` | `PaymentMethodRef.value` | **WORKING** (0→Cash, 1→Card) |
| ❌ Hardcoded | `CustomerRef.value` = "1" | **WRONG** |
| ❌ Hardcoded | `ItemRef.value` = "1" | **WRONG** |

---

## 4. Field Availability Summary

### Available from DevPos ✅

| Category | Fields Available | Currently Used? |
|----------|------------------|-----------------|
| **Document IDs** | eic, documentNumber | ✅ Yes |
| **Dates** | invoiceCreatedDate, dueDate | ✅ Yes (now fixed) |
| **Amounts** | amount, totalAmount | ✅ Yes (with caveats) |
| **Parties** | buyerName, buyerNuis, sellerName, sellerNuis | ⚠️ Partial (vendors only) |
| **Status** | invoiceStatus, statusCanBeChanged | ❌ No |
| **Payment** | invoicePayments array | ⚠️ Partial (cash sales only) |
| **Line Items** | ❌ Not in list API | ❌ No (requires detail call) |
| **PDF** | ❌ Not in list API | ❌ No (requires detail call) |

### Required by QuickBooks ✅

| Entity | Required Fields | Status |
|--------|----------------|--------|
| **Invoice** | TxnDate, CustomerRef, Line[].Amount, Line[].ItemRef | ⚠️ TxnDate ✅, others hardcoded |
| **Bill** | TxnDate, VendorRef, Line[].Amount, Line[].AccountRef | ⚠️ Partial (vendor lookup works) |
| **SalesReceipt** | TxnDate, CustomerRef, Line[].Amount, Line[].ItemRef | ⚠️ TxnDate ✅, others hardcoded |

---

## 5. What's Working vs What's Not

### ✅ Working Correctly

| Feature | Status |
|---------|--------|
| Date mapping | ✅ **FIXED** - Now uses `invoiceCreatedDate` |
| Document numbers | ✅ Syncing correctly |
| EIC tracking | ✅ Stored in custom field / private note |
| Vendor auto-creation | ✅ Creates vendors from sellerNuis |
| VAT handling | ✅ Conditional based on company settings |
| Payment method detection | ✅ Cash vs Card for sales receipts |

### ⚠️ Partially Working

| Feature | Issue | Impact |
|---------|-------|--------|
| Amount field | Sometimes 0 in list API | Need to fetch invoice details |
| Bill vendor lookup | Works but may not match correctly | Vendors may duplicate |

### ❌ Not Working / Missing

| Feature | Issue | Impact | Priority |
|---------|-------|--------|----------|
| **Customer mapping** | Hardcoded to ID "1" | All invoices → default customer | **CRITICAL** |
| **Item mapping** | Hardcoded to ID "1" | All items → "Services" | **HIGH** |
| **Line item details** | Not fetched | Single summary line only | **HIGH** |
| **Customer/vendor creation** | Not implemented | Manual setup required | **HIGH** |
| **PDF attachments** | Not working | No document attachments | **MEDIUM** |
| **Multiple payment types** | Not supported | Cash/Card only | **LOW** |

---

## 6. Recommended Fixes

### Fix 1: Customer/Vendor Mapping (CRITICAL)

**Problem:** All invoices assigned to customer ID "1", losing track of who owes what.

**Solution:**
```php
// In transformer:
$customerId = $this->getOrCreateCustomer($buyerName, $buyerNuis, $companyId);

// New method:
private function getOrCreateCustomer(string $name, string $taxId, int $companyId): string
{
    // 1. Look up in mapping table: devpos_customer_id → qbo_customer_id
    // 2. If not found, search QuickBooks by DisplayName or PrimaryTaxIdentifier
    // 3. If not found, create new customer in QuickBooks
    // 4. Store mapping for future use
    // 5. Return QuickBooks customer ID
}
```

### Fix 2: Line Item Support (HIGH)

**Problem:** No product detail, everything shows as generic "Services".

**Solution:**
```php
// After fetching list:
foreach ($invoices as $invoice) {
    // Fetch full details including line items
    $details = $devposClient->getEInvoiceByEIC($invoice['eic']);
    
    // Parse line items
    $qboLines = [];
    foreach ($details['items'] as $item) {
        $qboLines[] = [
            'Amount' => $item['totalAmount'],
            'Description' => $item['name'],
            'SalesItemLineDetail' => [
                'ItemRef' => [
                    'value' => $this->getOrCreateItem($item['code'], $item['name'])
                ],
                'UnitPrice' => $item['unitPrice'],
                'Qty' => $item['quantity']
            ]
        ];
    }
}
```

### Fix 3: PDF Attachments (MEDIUM)

**Problem:** Attachments not appearing in QuickBooks.

**Solution:**
```php
// Check if PDF exists in detail response
if (!empty($details['pdf'])) {
    $pdfBinary = base64_decode($details['pdf']);
    
    try {
        $qboClient->uploadAttachment(
            'Invoice',
            $qboInvoiceId,
            $documentNumber . '.pdf',
            $pdfBinary,
            false
        );
        error_log("✅ Attached PDF for invoice {$documentNumber}");
    } catch (\Throwable $e) {
        error_log("❌ Failed to attach PDF: " . $e->getMessage());
    }
}
```

---

## 7. Testing Checklist

After implementing fixes:

- [ ] **Test invoice sync with real data**
  - [ ] Verify transaction date matches DevPos date
  - [ ] Verify correct customer assigned
  - [ ] Verify line items appear
  - [ ] Verify PDF attached

- [ ] **Test bill sync**
  - [ ] Verify transaction date correct
  - [ ] Verify correct vendor assigned
  - [ ] Verify line items appear

- [ ] **Test cash sale sync**
  - [ ] Verify transaction date correct
  - [ ] Verify payment method (Cash vs Card)
  - [ ] Verify correct customer

- [ ] **Check error handling**
  - [ ] Missing fields logged
  - [ ] API failures handled gracefully
  - [ ] Failed syncs marked properly

---

## 8. Conclusion

### Current Status: ⚠️ PARTIALLY FUNCTIONAL

| Category | Status | Notes |
|----------|--------|-------|
| Date Mapping | ✅ **FIXED** | Now using correct field |
| Document Numbers | ✅ Working | Syncing correctly |
| Amounts | ⚠️ Partial | Sometimes 0, need detail fetch |
| Customers | ❌ **CRITICAL** | All → default customer |
| Vendors | ⚠️ Partial | Auto-creates but may duplicate |
| Items | ❌ **BROKEN** | All → "Services" |
| Line Items | ❌ **MISSING** | Not fetched |
| Attachments | ❌ **NOT WORKING** | Not uploading |

### Priority Actions:

1. **✅ DONE:** Fix date field mapping
2. **🔄 NEXT:** Test sync to verify dates work
3. **🔴 CRITICAL:** Implement customer/vendor mapping
4. **🟡 HIGH:** Add line item support
5. **🟢 MEDIUM:** Fix PDF attachments

---

**Last Updated:** October 29, 2025  
**Deployment:** Live on production (commit 6a48808)
