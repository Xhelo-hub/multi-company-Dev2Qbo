# DevPos Available Fields Reference

## 📋 Overview
This document lists **all available fields** in DevPos API responses that are currently **not being synced** to QuickBooks but could be mapped in future versions.

---

## 🧾 Sales Invoice (E-Invoice) - Available Fields

### Currently Synced ✅
| Field | Type | Description | QBO Mapping |
|-------|------|-------------|-------------|
| `eic` | string | Electronic Invoice Code | CustomField[EIC] |
| `documentNumber` | string | Invoice number | DocNumber |
| `issueDate` / `dateIssued` | date | Invoice date | TxnDate |
| `totalAmount` | decimal | Total invoice amount | Line[0].Amount |
| `buyerName` | string | Customer name | CustomerRef (lookup) |
| `buyerNuis` | string | Customer tax ID | Customer search key |
| `pdf` | base64 | PDF document | Attachment |

### Available But NOT Synced 🔄

#### Document Information
| Field | Type | Description | Potential QBO Mapping |
|-------|------|-------------|----------------------|
| `currency` | string | Currency code (e.g., "ALL", "EUR", "USD") | Transaction currency |
| `documentType` | int/string | Type of document | Custom field or filter logic |
| `invoiceNumber` | string | Alternative invoice number | Reference number |
| `fiscalNumber` | string | Fiscal receipt number | Custom field |
| `supplierInvoiceNumber` | string | Supplier's own invoice number | Vendor bill number |
| `operatorCode` | string | Operator/cashier code | SalesRep reference |
| `businessUnit` | string | Business unit/location | Class or Location |
| `softwareCode` | string | POS software identifier | N/A (internal tracking) |
| `correctiveInvoice` | boolean | Is correction invoice | Custom field |
| `reverseChargeFlag` | boolean | Reverse charge indicator | Tax handling |

#### Customer/Buyer Details
| Field | Type | Description | Potential QBO Mapping |
|-------|------|-------------|----------------------|
| `buyerIdType` | string | ID type (NIPT, ID card, etc.) | Custom field |
| `buyerIdNumber` | string | ID number | Custom field |
| `buyerAddress` | string | Customer street address | CustomerRef → BillAddr.Line1 |
| `buyerTown` | string | Customer city | CustomerRef → BillAddr.City |
| `buyerCountry` | string | Customer country code | CustomerRef → BillAddr.Country |
| `buyerEmail` | string | Customer email | CustomerRef → PrimaryEmailAddr |
| `buyerPhone` | string | Customer phone | CustomerRef → PrimaryPhone |
| `buyerVatNumber` | string | EU VAT number | Custom field |

#### Financial Details
| Field | Type | Description | Potential QBO Mapping |
|-------|------|-------------|----------------------|
| `totalAmountWithoutVat` | decimal | Net amount (before tax) | Subtotal calculation |
| `totalAmountVat` | decimal | Total VAT/tax amount | TxnTaxDetail.TaxLine.TaxLineDetail.TaxAmount |
| `vatRate` | decimal | VAT rate (e.g., 20.00) | TaxCode mapping |
| `totalDiscount` | decimal | Total discount amount | DiscountLineDetail |
| `discountPercent` | decimal | Discount percentage | Discount calculation |
| `totalAdvancePayment` | decimal | Advance payment applied | Deposit/prepayment |
| `totalPayable` | decimal | Amount due after discounts | Balance |
| `exchangeRate` | decimal | Currency exchange rate | ExchangeRate field |

#### Payment Information
| Field | Type | Description | Potential QBO Mapping |
|-------|------|-------------|----------------------|
| `paymentMethod` | string | Payment method name | PaymentMethodRef |
| `paymentDeadline` | date | Due date | DueDate |
| `paymentStatus` | string/int | Payment status | Custom tracking |
| `bankAccount` | string | Bank account number | Custom field |
| `paymentReference` | string | Payment reference number | PaymentRefNum |

#### Line Items (items[] array)
| Field | Type | Description | Potential QBO Mapping |
|-------|------|-------------|----------------------|
| `items[].name` | string | Product/service name | Line[].Description |
| `items[].code` | string | Product/item code | Line[].SalesItemLineDetail.ItemRef |
| `items[].description` | string | Item description | Line[].Description |
| `items[].quantity` | decimal | Quantity | Line[].SalesItemLineDetail.Qty |
| `items[].unitPrice` | decimal | Unit price | Line[].SalesItemLineDetail.UnitPrice |
| `items[].amount` | decimal | Line total | Line[].Amount |
| `items[].vatRate` | decimal | VAT rate for item | Line[].TaxCodeRef |
| `items[].vatAmount` | decimal | VAT amount for item | Tax calculation |
| `items[].discount` | decimal | Line discount | Line[].DiscountRate |
| `items[].unit` | string | Unit of measure | Line[].SalesItemLineDetail.UnitOfMeasure |
| `items[].barcode` | string | Product barcode | Custom field/item lookup |
| `items[].category` | string | Item category | Class or custom field |
| `items[].exemptionCode` | string | Tax exemption code | Tax handling |

#### Invoice Metadata
| Field | Type | Description | Potential QBO Mapping |
|-------|------|-------------|----------------------|
| `issueDateTime` | datetime | Full timestamp | Timestamp custom field |
| `verificationUrl` | string | Online verification URL | Memo or custom field |
| `qrCode` | string | QR code for verification | Custom field |
| `notValidBefore` | datetime | Valid from date | Custom field |
| `notValidAfter` | datetime | Valid until date | Custom field |
| `notes` | string | Invoice notes/memo | CustomerMemo |
| `internalNote` | string | Internal notes | PrivateNote |
| `referenceNumber` | string | External reference | DocNumber prefix/suffix |

#### Special Cases
| Field | Type | Description | Potential QBO Mapping |
|-------|------|-------------|----------------------|
| `isSimplifiedInvoice` | boolean | Simplified invoice flag | Detect cash sales |
| `isSelfIssuing` | boolean | Self-issued invoice | Custom field |
| `invoicePayments[]` | array | Payment details | PaymentLine mapping |
| `invoicePayments[].amount` | decimal | Payment amount | PaymentLine.Amount |
| `invoicePayments[].paymentMethodType` | int | 0=Cash, 1=Card, etc. | PaymentMethod lookup |
| `invoicePayments[].paymentDate` | date | Payment date | PaymentLine.TxnDate |

---

## 🧾 Purchase Invoice (Bill) - Available Fields

### Currently Synced ✅
| Field | Type | Description | QBO Mapping |
|-------|------|-------------|-------------|
| `sellerName` | string | Vendor name | VendorRef (create/lookup) |
| `sellerNuis` | string | Vendor tax ID | Vendor search key |
| `documentNumber` | string | Bill number | DocNumber |
| `issueDate` / `dateIssued` | date | Bill date | TxnDate |
| `amount` / `total` / `totalAmount` | decimal | Bill total | Line[0].Amount |
| `pdf` | base64 | PDF document | Attachment |

### Available But NOT Synced 🔄

#### Vendor/Seller Details
| Field | Type | Description | Potential QBO Mapping |
|-------|------|-------------|----------------------|
| `sellerAddress` | string | Vendor street address | VendorRef → Addr.Line1 |
| `sellerTown` | string | Vendor city | VendorRef → Addr.City |
| `sellerCountry` | string | Vendor country | VendorRef → Addr.Country |
| `sellerEmail` | string | Vendor email | VendorRef → PrimaryEmailAddr |
| `sellerPhone` | string | Vendor phone | VendorRef → PrimaryPhone |
| `sellerVatNumber` | string | EU VAT number | Custom field |
| `sellerBusinessName` | string | Legal business name | VendorRef → CompanyName |

#### Document Information
| Field | Type | Description | Potential QBO Mapping |
|-------|------|-------------|----------------------|
| `currency` | string | Currency code | Transaction currency |
| `invoiceType` | string | Type of purchase | Account mapping |
| `referenceNumber` | string | Reference/PO number | PrivateNote or custom field |
| `deliveryNote` | string | Delivery note number | Custom field |

#### Financial Details
| Field | Type | Description | Potential QBO Mapping |
|-------|------|-------------|----------------------|
| `totalWithoutVat` | decimal | Net amount | Subtotal |
| `totalVat` | decimal | Total VAT | Tax amount |
| `vatRate` | decimal | VAT percentage | TaxCode |
| `totalDiscount` | decimal | Discount amount | Discount calculation |
| `additionalCosts` | decimal | Shipping/handling | Additional expense line |
| `withholdingTax` | decimal | Tax withheld | Tax line |

#### Line Items (items[] array)
| Field | Type | Description | Potential QBO Mapping |
|-------|------|-------------|----------------------|
| `items[].description` | string | Item description | Line[].Description |
| `items[].code` | string | Product/service code | Item lookup |
| `items[].quantity` | decimal | Quantity purchased | Quantity field |
| `items[].unitPrice` | decimal | Unit cost | UnitPrice |
| `items[].amount` | decimal | Line total | Line[].Amount |
| `items[].vatRate` | decimal | VAT rate | Tax calculation |
| `items[].category` | string | Expense category | AccountBasedExpenseLineDetail.AccountRef |
| `items[].unit` | string | Unit of measure | UnitOfMeasure |

#### Payment Terms
| Field | Type | Description | Potential QBO Mapping |
|-------|------|-------------|----------------------|
| `dueDate` | date | Payment due date | DueDate |
| `paymentTerms` | string | Payment terms description | TermsRef |
| `paymentDeadlineDays` | int | Days until due | Terms calculation |
| `paymentStatus` | string | Paid/unpaid status | Custom tracking |

#### Metadata
| Field | Type | Description | Potential QBO Mapping |
|-------|------|-------------|----------------------|
| `receivedDate` | date | Date bill received | MetaData or custom field |
| `verificationUrl` | string | Online verification | Memo |
| `notes` | string | Bill notes | PrivateNote or Memo |
| `internalReference` | string | Internal tracking number | Custom field |

---

## 💳 Cash Sales / Sales Receipts - Additional Fields

### Payment-Specific Fields
| Field | Type | Description | Potential QBO Mapping |
|-------|------|-------------|----------------------|
| `invoicePayments[]` | array | Payment transactions | Multiple payment lines |
| `invoicePayments[].paymentMethodType` | int | 0=Cash, 1=Card, 2=Check, 3=Bank Transfer | PaymentMethodRef lookup |
| `invoicePayments[].amount` | decimal | Amount paid | PaymentLine.Amount |
| `invoicePayments[].paymentDate` | datetime | When paid | PaymentLine.TxnDate |
| `invoicePayments[].bankAccount` | string | Bank account used | DepositToAccountRef |
| `invoicePayments[].cardType` | string | Card type (Visa, MC) | Memo or custom field |
| `invoicePayments[].lastFourDigits` | string | Card last 4 digits | Memo |
| `invoicePayments[].transactionId` | string | Payment processor ID | PaymentRefNum |
| `cashRegisterId` | string | Cash register/POS ID | Custom field |
| `cashierName` | string | Cashier/operator name | SalesRep |
| `shift` | string | Work shift identifier | Custom field |

---

## 🌍 Multi-Currency Fields

### Currency Exchange
| Field | Type | Description | Potential QBO Mapping |
|-------|------|-------------|----------------------|
| `currency` | string | Transaction currency (ALL, EUR, USD) | CurrencyRef |
| `exchangeRate` | decimal | Exchange rate to base currency | ExchangeRate |
| `baseCurrency` | string | Company base currency | Home currency |
| `amountInBaseCurrency` | decimal | Converted amount | Home currency amount |

**Example Values:**
```json
{
  "currency": "EUR",
  "exchangeRate": 106.50,
  "baseCurrency": "ALL",
  "totalAmount": 100.00,
  "amountInBaseCurrency": 10650.00
}
```

**QBO Mapping (Future):**
```json
{
  "CurrencyRef": {"value": "EUR"},
  "ExchangeRate": 106.50,
  "Line": [{
    "Amount": 100.00
  }],
  "HomeBalance": 10650.00
}
```

---

## 📊 Tax & VAT Fields

### Tax Details
| Field | Type | Description | Potential QBO Mapping |
|-------|------|-------------|----------------------|
| `vatRate` | decimal | Standard VAT rate (e.g., 20.00) | TaxCodeRef |
| `vatAmount` | decimal | Total VAT calculated | TxnTaxDetail.TotalTax |
| `taxExemptionCode` | string | Exemption reason code | TaxExemptionRef |
| `reverseCharge` | boolean | Reverse charge VAT | Tax handling special case |
| `withholdingTaxRate` | decimal | Withholding tax % | Additional tax line |
| `withholdingTaxAmount` | decimal | Amount withheld | Tax calculation |

### Per-Line Tax
| Field | Type | Description | Potential QBO Mapping |
|-------|------|-------------|----------------------|
| `items[].vatRate` | decimal | Line item VAT rate | Line[].TaxCodeRef |
| `items[].vatAmount` | decimal | Line VAT amount | Line tax calculation |
| `items[].taxExempt` | boolean | Is tax exempt | TaxCodeRef "NON" |
| `items[].exemptionReason` | string | Why exempt | Memo or custom field |

**Albania VAT Rates:**
- Standard: 20%
- Reduced: 6% (specific goods/services)
- Zero-rated: 0% (exports)
- Exempt: No VAT

---

## 🏷️ Classification & Tracking

### Business Dimensions
| Field | Type | Description | Potential QBO Mapping |
|-------|------|-------------|----------------------|
| `businessUnit` | string | Department/division | Class |
| `locationCode` | string | Store/warehouse location | Location |
| `costCenter` | string | Cost center code | Class |
| `project` | string | Project code | Customer:Job |
| `department` | string | Department name | Class |
| `salesChannel` | string | Sales channel (online, retail) | Custom field |
| `region` | string | Geographic region | Custom field |

---

## 📝 Notes & References

### Documentation Fields
| Field | Type | Description | Potential QBO Mapping |
|-------|------|-------------|----------------------|
| `notes` | string | Customer-facing notes | CustomerMemo |
| `internalNote` | string | Private/internal notes | PrivateNote |
| `memo` | string | General memo | Memo field |
| `terms` | string | Terms and conditions | TermsRef or custom field |
| `deliveryInstructions` | string | Delivery notes | ShipAddr or memo |
| `specialInstructions` | string | Special handling | Memo |

### Reference Numbers
| Field | Type | Description | Potential QBO Mapping |
|-------|------|-------------|----------------------|
| `referenceNumber` | string | External reference | DocNumber or custom field |
| `customerPurchaseOrder` | string | Customer PO number | PONumber |
| `contractNumber` | string | Contract reference | Custom field |
| `projectNumber` | string | Project number | Customer:Job |
| `deliveryNoteNumber` | string | Delivery note | ShipMethodRef or custom field |

---

## 🔗 Related Documents

### Document Linking
| Field | Type | Description | Potential QBO Mapping |
|-------|------|-------------|----------------------|
| `originalInvoiceNumber` | string | For credit notes | LinkedTxn |
| `correctedInvoiceNumber` | string | Invoice being corrected | LinkedTxn |
| `relatedDocuments[]` | array | Related document IDs | LinkedTxn array |
| `parentDocumentId` | string | Parent document reference | ParentRef |

---

## 📈 Priority Mapping Recommendations

### High Priority 🔴
1. **`currency`** - Essential for multi-currency support
2. **`items[]` array** - Enable line-item detail
3. **`buyerAddress`, `buyerEmail`, `buyerPhone`** - Complete customer records
4. **`sellerAddress`, `sellerEmail`, `sellerPhone`** - Complete vendor records
5. **`vatRate`, `vatAmount`** - Proper tax handling
6. **`dueDate`, `paymentTerms`** - Payment tracking
7. **`notes`, `memo`** - Better documentation

### Medium Priority 🟡
8. **`items[].category`** - Expense account mapping
9. **`paymentMethod`, `paymentStatus`** - Payment tracking
10. **`businessUnit`, `department`** - Class/location tracking
11. **`customerPurchaseOrder`** - PO number
12. **`discounts`** - Proper pricing
13. **`exchangeRate`** - Currency conversion

### Low Priority 🟢
14. **`qrCode`, `verificationUrl`** - Nice to have
15. **`operatorCode`, `cashRegisterId`** - Internal tracking
16. **`barcode`** - Product matching
17. **`shift`, `cashierName`** - Operational data

---

## 🎯 Implementation Roadmap

### Phase 1: Essential Fields (Q1 2025)
- ✅ Currency support
- ✅ Line items parsing
- ✅ Customer/vendor details (address, email, phone)
- ✅ Tax rates and amounts

### Phase 2: Enhanced Details (Q2 2025)
- ⏳ Payment terms and due dates
- ⏳ Discount handling
- ⏳ Multiple payment methods
- ⏳ Expense category mapping

### Phase 3: Advanced Features (Q3 2025)
- ⏳ Multi-currency with exchange rates
- ⏳ Class/location tracking
- ⏳ Document linking
- ⏳ Custom field mapping configuration

---

## 💡 Usage Example

### Current Sync (Simplified)
```json
{
  "eic": "12345-ABC",
  "totalAmount": 12000.00,
  "buyerName": "Customer ABC"
}
```
**→ Creates single-line invoice in QBO**

### Future Sync (Full Detail)
```json
{
  "eic": "12345-ABC",
  "documentNumber": "INV-2025-001",
  "issueDate": "2025-01-15",
  "currency": "ALL",
  "buyerName": "Customer ABC",
  "buyerNuis": "K12345678X",
  "buyerAddress": "Rruga ABC 123",
  "buyerTown": "Tirana",
  "buyerEmail": "contact@abc.al",
  "totalAmount": 12000.00,
  "totalAmountWithoutVat": 10000.00,
  "totalAmountVat": 2000.00,
  "vatRate": 20.00,
  "dueDate": "2025-02-15",
  "notes": "Payment terms: Net 30",
  "items": [
    {
      "code": "PROD-001",
      "description": "Product A",
      "quantity": 10,
      "unitPrice": 600.00,
      "amount": 6000.00,
      "vatRate": 20.00
    },
    {
      "code": "SERV-001",
      "description": "Service B",
      "quantity": 4,
      "unitPrice": 1000.00,
      "amount": 4000.00,
      "vatRate": 20.00
    }
  ]
}
```
**→ Creates detailed multi-line invoice with customer info, tax details, and due date**

---

## 🔍 How to Find Available Fields

### Method 1: API Response Inspection
Enable debug logging in `SalesSync.php` (already exists):
```php
error_log("DEBUG DevPos Invoice: ".json_encode($doc, JSON_PRETTY_PRINT));
```

Check error logs after running sync to see complete DevPos response structure.

### Method 2: Test Script
Run test script with verbose output:
```bash
php bin/test-devpos-working.php
```

### Method 3: DevPos API Documentation
- Official Docs: Contact DevPos support for API documentation
- Base URL: `https://online.devpos.al/api/v3`
- Swagger/OpenAPI: May be available at `/swagger` endpoint

### Method 4: Query Single Invoice
```php
$detail = $devposClient->getEInvoiceByEIC("12345-ABC-DEFG");
var_dump($detail); // See all fields
```

---

## 📚 Related Documentation
- `FIELD-MAPPING.md` - Current field mappings
- `FIELD-MAPPING-VISUAL.md` - Visual mapping diagrams
- `src/Http/DevposClient.php` - DevPos API client
- `src/Sync/SalesSync.php` - Sales sync implementation
- `src/Sync/BillsSync.php` - Bills sync implementation

---

**Last Updated:** January 2025  
**Version:** 1.0  
**Status:** Reference Document (Not Yet Implemented)
