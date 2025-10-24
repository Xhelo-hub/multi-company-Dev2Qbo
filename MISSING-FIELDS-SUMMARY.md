# 🔍 Quick Reference: DevPos Fields NOT Currently Synced

## Most Important Missing Fields

### 💰 Currency & Exchange (HIGH PRIORITY)
```json
{
  "currency": "ALL",              // Albanian Lek, EUR, USD
  "exchangeRate": 106.50,         // Conversion rate
  "baseCurrency": "ALL",
  "amountInBaseCurrency": 10650.00
}
```
**Status:** ❌ Not mapped  
**Impact:** Cannot handle multi-currency transactions  
**QBO Field:** `CurrencyRef`, `ExchangeRate`

---

### 📦 Line Items Detail (HIGH PRIORITY)
```json
{
  "items": [
    {
      "name": "Product A",
      "code": "PROD-001",
      "description": "Product description",
      "quantity": 10,
      "unitPrice": 600.00,
      "amount": 6000.00,
      "vatRate": 20.00,
      "vatAmount": 1200.00,
      "unit": "pcs",
      "category": "Sales",
      "barcode": "1234567890"
    }
  ]
}
```
**Status:** ❌ Aggregated to single line  
**Impact:** Loss of product detail, no item-level tracking  
**QBO Field:** `Line[]` array with multiple items

---

### 💵 Tax Details (HIGH PRIORITY)
```json
{
  "totalAmountWithoutVat": 10000.00,   // Net amount
  "totalAmountVat": 2000.00,           // Total VAT
  "vatRate": 20.00,                    // VAT percentage
  "taxExemptionCode": "EXP",           // Exemption reason
  "reverseCharge": false
}
```
**Status:** 🟡 Partial (only total synced)  
**Impact:** Tax not properly calculated in QBO  
**QBO Field:** `TxnTaxDetail`, `TaxCodeRef`

---

### 👤 Customer Details (MEDIUM PRIORITY)
```json
{
  "buyerName": "Customer ABC",
  "buyerNuis": "K12345678X",
  "buyerAddress": "Rruga ABC 123",     // NOT synced
  "buyerTown": "Tirana",               // NOT synced
  "buyerCountry": "AL",                // NOT synced
  "buyerEmail": "contact@abc.al",      // NOT synced
  "buyerPhone": "+355 69 123 4567"     // NOT synced
}
```
**Status:** 🟡 Partial (only name & NUIS)  
**Impact:** Incomplete customer records in QBO  
**QBO Field:** `CustomerRef` → `BillAddr`, `PrimaryEmailAddr`, `PrimaryPhone`

---

### 🏢 Vendor Details (MEDIUM PRIORITY)
```json
{
  "sellerName": "Supplier ABC",
  "sellerNuis": "K98765432Y",
  "sellerAddress": "Rruga XYZ 456",    // NOT synced
  "sellerTown": "Durres",              // NOT synced
  "sellerEmail": "sales@supplier.al",  // NOT synced
  "sellerPhone": "+355 52 123 456"     // NOT synced
}
```
**Status:** 🟡 Partial (only name & NUIS)  
**Impact:** Incomplete vendor records in QBO  
**QBO Field:** `VendorRef` → `Addr`, `PrimaryEmailAddr`, `PrimaryPhone`

---

### 📅 Payment Terms (MEDIUM PRIORITY)
```json
{
  "dueDate": "2025-02-15",             // NOT synced
  "paymentDeadline": "2025-02-15",     // NOT synced
  "paymentTerms": "Net 30",            // NOT synced
  "paymentDeadlineDays": 30            // NOT synced
}
```
**Status:** ❌ Not mapped  
**Impact:** No payment tracking, no aging reports  
**QBO Field:** `DueDate`, `TermsRef`

---

### 💳 Payment Methods (MEDIUM PRIORITY)
```json
{
  "invoicePayments": [
    {
      "paymentMethodType": 0,          // 0=Cash, 1=Card, 2=Check
      "amount": 5000.00,
      "paymentDate": "2025-01-15",
      "cardType": "Visa",              // For card payments
      "lastFourDigits": "1234",        // Card last 4
      "transactionId": "TXN-123456"    // Payment processor ID
    }
  ]
}
```
**Status:** 🟡 Used for detection only  
**Impact:** No payment method tracking  
**QBO Field:** `PaymentMethodRef`, `PaymentRefNum`

---

### 💸 Discounts (LOW PRIORITY)
```json
{
  "totalDiscount": 500.00,             // NOT synced
  "discountPercent": 5.00,             // NOT synced
  "items[0].discount": 50.00           // NOT synced
}
```
**Status:** ❌ Not mapped  
**Impact:** Incorrect net amounts  
**QBO Field:** `DiscountLineDetail`, `Line[].DiscountRate`

---

### 📝 Notes & References (LOW PRIORITY)
```json
{
  "notes": "Payment terms: Net 30",    // NOT synced
  "internalNote": "High priority",     // NOT synced
  "memo": "Rush order",                // NOT synced
  "customerPurchaseOrder": "PO-123",   // NOT synced
  "referenceNumber": "REF-456"         // NOT synced
}
```
**Status:** ❌ Not mapped  
**Impact:** Missing context and documentation  
**QBO Field:** `CustomerMemo`, `PrivateNote`, `PONumber`

---

### 🏷️ Classification (LOW PRIORITY)
```json
{
  "businessUnit": "Retail",            // NOT synced
  "department": "Sales",               // NOT synced
  "locationCode": "TIR-001",           // NOT synced
  "costCenter": "CC-100",              // NOT synced
  "project": "PRJ-2025-001"            // NOT synced
}
```
**Status:** ❌ Not mapped  
**Impact:** No departmental tracking  
**QBO Field:** `Class`, `Location`, `Customer:Job`

---

### 📎 Additional Metadata (INFORMATIONAL)
```json
{
  "verificationUrl": "https://...",    // NOT synced
  "qrCode": "base64...",               // NOT synced
  "fiscalNumber": "FN-123456",         // NOT synced
  "operatorCode": "OP-01",             // NOT synced
  "cashRegisterId": "POS-001",         // NOT synced
  "softwareCode": "DevPOS-v3"          // NOT synced
}
```
**Status:** ❌ Not mapped  
**Impact:** Minimal - mostly for audit trail  
**QBO Field:** Custom fields or memo

---

## 📊 Summary Statistics

| Category | Total Fields | Currently Synced | Not Synced | Sync Rate |
|----------|--------------|------------------|------------|-----------|
| **Document Info** | 15 | 7 | 8 | 47% |
| **Customer Details** | 10 | 2 | 8 | 20% |
| **Vendor Details** | 10 | 2 | 8 | 20% |
| **Financial** | 12 | 1 | 11 | 8% |
| **Line Items** | 12 | 0 | 12 | 0% |
| **Payment Info** | 10 | 0 | 10 | 0% |
| **Tax/VAT** | 8 | 0 | 8 | 0% |
| **Classification** | 6 | 0 | 6 | 0% |
| **Metadata** | 10 | 1 | 9 | 10% |
| **TOTAL** | **93** | **13** | **80** | **14%** |

---

## 🎯 Priority Implementation Order

### Phase 1: Core Financial (Critical)
1. ✅ **Currency** (`currency`, `exchangeRate`)
2. ✅ **Line Items** (`items[]` array parsing)
3. ✅ **Tax Details** (`vatRate`, `vatAmount`, `totalAmountWithoutVat`)
4. ✅ **Due Dates** (`dueDate`, `paymentTerms`)

### Phase 2: Contact Information (High)
5. ✅ **Customer Details** (address, email, phone)
6. ✅ **Vendor Details** (address, email, phone)
7. ✅ **Payment Methods** (proper tracking)

### Phase 3: Business Intelligence (Medium)
8. ⏳ **Discounts** (proper net calculations)
9. ⏳ **Classification** (departments, locations)
10. ⏳ **Notes & References** (better documentation)

### Phase 4: Advanced (Low)
11. ⏳ **Multiple Payments** (split payments)
12. ⏳ **Related Documents** (credit notes linking)
13. ⏳ **Operational Metadata** (POS, operator info)

---

## 💡 Quick Example: Currency Field

### DevPos Response
```json
{
  "eic": "12345-ABC",
  "totalAmount": 100.00,
  "currency": "EUR",
  "exchangeRate": 106.50,
  "amountInBaseCurrency": 10650.00
}
```

### Current QBO (Missing Currency)
```json
{
  "Line": [{"Amount": 100.00}],
  "TxnDate": "2025-01-15"
}
```
❌ Problem: QBO assumes base currency (ALL), exchange not recorded

### Future QBO (With Currency)
```json
{
  "CurrencyRef": {"value": "EUR"},
  "ExchangeRate": 106.50,
  "Line": [{"Amount": 100.00}],
  "HomeBalance": 10650.00,
  "TxnDate": "2025-01-15"
}
```
✅ Solution: Proper multi-currency support

---

## 🔍 How to See All Available Fields

### Enable Debug Mode
Edit `src/Sync/SalesSync.php` - already has this code:
```php
error_log("DEBUG DevPos Invoice: ".json_encode($doc, JSON_PRETTY_PRINT));
```

### Run Sync & Check Logs
```bash
# Check Apache error log
tail -f c:/xampp/apache/logs/error.log

# Or PHP error log
tail -f c:/xampp/php/logs/php_error_log
```

### Test Single Invoice
```bash
php bin/test-devpos-working.php
# Enter credentials and check output
```

---

## 📚 Related Documentation
- **DEVPOS-AVAILABLE-FIELDS.md** - Complete field reference (this is summary)
- **FIELD-MAPPING.md** - Current mappings explained
- **FIELD-MAPPING-VISUAL.md** - Visual diagrams

---

**Quick Answer to Your Question:**
**YES, `currency` field is available in DevPos!** It's one of the most important missing fields. DevPos returns `currency` (e.g., "ALL", "EUR", "USD"), `exchangeRate`, and `amountInBaseCurrency` for every transaction, but we're **not currently syncing it** to QuickBooks.

**Other important available fields NOT synced:**
- ✅ Line items detail (`items[]` array)
- ✅ Tax breakdown (`vatRate`, `vatAmount`)
- ✅ Customer/vendor addresses and contact info
- ✅ Payment terms and due dates
- ✅ Discounts
- ✅ Payment method details
- ✅ Notes and memos

See **DEVPOS-AVAILABLE-FIELDS.md** for complete details! 📖
