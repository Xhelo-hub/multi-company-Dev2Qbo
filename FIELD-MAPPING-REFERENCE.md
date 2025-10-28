# DevPos to QuickBooks Field Mapping Reference

## Date Fields

### DevPos → QuickBooks Date Mapping

**Current Implementation Priority (checked in order):**

1. ✅ `dateTimeCreated` ← **PRIMARY** (Official DevPos API field per documentation section 5.3)
2. `createdDate` (Used in e-invoice query responses)
3. `issueDate` (Legacy fallback)
4. `dateCreated` (Legacy fallback)
5. `created_at` (Legacy fallback)
6. `dateIssued` (Legacy fallback)
7. `date` (Legacy fallback)
8. `invoiceDate` (Legacy fallback)
9. `documentDate` (Legacy fallback)

**Maps to QuickBooks:**
- **Invoice**: `TxnDate` field
- **Bill**: `TxnDate` and `DueDate` fields  
- **SalesReceipt**: `TxnDate` field

---

## Invoice Fields (Sales)

### DevPos Invoice → QuickBooks Invoice

| DevPos Field | Checked Fields (priority order) | QuickBooks Field | Notes |
|--------------|--------------------------------|------------------|-------|
| Document Number | `documentNumber`, `doc_no`, `DocNumber` | `DocNumber` | Optional |
| Date | `dateTimeCreated`, `createdDate`, `issueDate`, ... | `TxnDate` | **KEY FIELD** |
| Total Amount | `totalAmount`, `total`, `amount` | `Line[0].Amount` | Required |
| Customer Name | `buyerName`, `buyer_name`, `customerName` | (Not mapped) | Hardcoded to customer ID 1 |
| EIC | `eic`, `EIC` | `CustomField[0].StringValue` | If configured |

**Example Payload Sent to QuickBooks:**
```json
{
  "Line": [{
    "Amount": 1500.00,
    "DetailType": "SalesItemLineDetail",
    "SalesItemLineDetail": {
      "ItemRef": { "value": "1", "name": "Services" },
      "UnitPrice": 1500.00,
      "Qty": 1
    },
    "Description": "Invoice: 12345"
  }],
  "CustomerRef": { "value": "1" },
  "TxnDate": "2025-10-15",
  "DocNumber": "12345",
  "CustomField": [{
    "DefinitionId": "...",
    "Name": "EIC",
    "Type": "StringType",
    "StringValue": "abc123..."
  }]
}
```

---

## Bill Fields (Purchases)

### DevPos Purchase Invoice → QuickBooks Bill

| DevPos Field | Checked Fields (priority order) | QuickBooks Field | Notes |
|--------------|--------------------------------|------------------|-------|
| Document Number | `documentNumber`, `doc_no`, `DocNumber` | `DocNumber` | Optional |
| Date | `dateTimeCreated`, `createdDate`, `issueDate`, ... | `TxnDate`, `DueDate` | **KEY FIELD** |
| Total Amount | `totalAmount`, `total`, `amount` | `Line[0].Amount` | Required |
| Vendor Name | `sellerName`, `seller_name`, `vendorName` | (Not mapped) | Looked up in mapping table |
| Vendor NUIS | `sellerNuis`, `seller_nuis` | `PrivateNote` | Included in notes |
| EIC | `eic`, `EIC` | `PrivateNote` | Included in notes |

**Example Payload Sent to QuickBooks:**
```json
{
  "Line": [{
    "Amount": 2500.00,
    "DetailType": "AccountBasedExpenseLineDetail",
    "AccountBasedExpenseLineDetail": {
      "AccountRef": { "value": "7", "name": "Cost of Goods Sold" }
    },
    "Description": "Purchase: 67890"
  }],
  "VendorRef": { "value": "5" },
  "TxnDate": "2025-10-15",
  "DueDate": "2025-10-15",
  "DocNumber": "67890",
  "PrivateNote": "EIC: xyz789... | Vendor NUIS: K12345678A"
}
```

---

## Sales Receipt Fields (Cash Sales)

### DevPos Cash Sale → QuickBooks SalesReceipt

| DevPos Field | Checked Fields (priority order) | QuickBooks Field | Notes |
|--------------|--------------------------------|------------------|-------|
| Document Number | `documentNumber`, `doc_no`, `DocNumber` | `DocNumber` | Optional |
| Date | `dateTimeCreated`, `createdDate`, `issueDate`, ... | `TxnDate` | **KEY FIELD** |
| Total Amount | `totalAmount`, `total`, `amount` | `Line[0].Amount` | Required |
| Customer Name | `buyerName`, `buyer_name`, `customerName` | (Not mapped) | Hardcoded to customer ID 1 |
| Payment Method | `invoicePayments[0].paymentMethodType` | `PaymentMethodRef` | 0=Cash, 1=Card |

**Example Payload Sent to QuickBooks:**
```json
{
  "Line": [{
    "Amount": 750.00,
    "DetailType": "SalesItemLineDetail",
    "SalesItemLineDetail": {
      "ItemRef": { "value": "1", "name": "Services" },
      "UnitPrice": 750.00,
      "Qty": 1
    },
    "Description": "Cash Sale: 99999"
  }],
  "CustomerRef": { "value": "1" },
  "TxnDate": "2025-10-15",
  "PaymentMethodRef": { "value": "1" },
  "DocNumber": "99999"
}
```

---

## Current Date Issue Diagnosis

### Expected Behavior:
1. DevPos API returns invoice with `dateTimeCreated = "2025-10-15"`
2. Transformer extracts this date
3. Sets `TxnDate = "2025-10-15"` in payload
4. Sends to QuickBooks API
5. QuickBooks creates transaction with date 2025-10-15

### What's Happening:
**QuickBooks is creating transactions with today's date instead of the document date**

### Possible Causes:

#### 1. DevPos Not Sending `dateTimeCreated`
- **Check**: Look for logs with `"WARNING: No date found in DevPos invoice"`
- **Solution**: Already implemented fallbacks to check 9 different date fields

#### 2. Date Format Issue
- **Expected**: `YYYY-MM-DD` (e.g., "2025-10-15")
- **Check**: Look for logs with `"INFO: Using date field with value: ..."`
- **Solution**: Using `substr($issueDate, 0, 10)` to ensure YYYY-MM-DD format

#### 3. TxnDate Not Being Sent
- **Check**: Look for logs with `"DEBUG: Sending to QBO ... API - TxnDate: ..."`
- **Solution**: Already logging what we send to QBO

#### 4. QuickBooks Ignoring TxnDate
- **Check**: Look for logs with `"DEBUG: QBO returned ... with TxnDate: ..."`
- **Solution**: Compare sent vs received dates

---

## How to Debug

### Step 1: Run a Sync Job
Go to the dashboard and run a manual sync for any company.

### Step 2: Check Logs
```bash
ssh root@78.46.201.151 "grep 'TxnDate\|dateTimeCreated\|DevPos Invoice' /var/log/apache2/error.log | tail -50"
```

### Step 3: Look for These Log Patterns

**DevPos Field Check:**
```
INFO: Using date field with value: 2025-10-15
```

**Transformer Output:**
```
INFO: QuickBooks Invoice TxnDate being set to: 2025-10-15
```

**API Request:**
```
DEBUG: Sending to QBO Invoice API - TxnDate: 2025-10-15
DEBUG: Full Invoice payload: {"TxnDate":"2025-10-15",...}
```

**API Response:**
```
DEBUG: QBO returned Invoice with TxnDate: 2025-10-15
```

### Step 4: Compare
- If DevPos date is different from QBO date → Field mapping issue
- If sent date is different from returned date → QuickBooks is overriding it
- If no date found in DevPos → DevPos API issue

---

## Code Locations

**Transformers** (where date is extracted and mapped):
- `src/Transformers/InvoiceTransformer.php` (lines 33-52)
- `src/Transformers/BillTransformer.php` (lines 33-52)
- `src/Transformers/SalesReceiptTransformer.php` (lines 33-52)

**API Client** (where data is sent to QBO):
- `src/Http/QboClient.php` (lines 45-70, 80-110, 120-140)

**Sync Services** (orchestration):
- `src/Sync/SalesSync.php`
- `src/Sync/BillsSync.php`

---

## Quick Fix Verification

Run this command after a sync to see the complete flow:
```bash
ssh root@78.46.201.151 "tail -500 /var/log/apache2/error.log | grep -E 'Using date field|TxnDate being set|Sending to QBO.*TxnDate|QBO returned.*TxnDate'"
```

This will show:
1. What date was found in DevPos
2. What date was set in the transformer
3. What date was sent to QuickBooks
4. What date QuickBooks returned
