# DevPos Official API Fields (From Documentation)

**Source:** Official DevPos API Documentation  
**Date:** November 1, 2025

## Invoice Fields (Section 5.3)

Based on the official API documentation, here are ALL the fields provided by DevPos for invoices:

### Basic Invoice Information

| Field | Type | Description |
|-------|------|-------------|
| `invoiceType` | string | Invoice type (cash or non-cash) |
| `selfIssuingType` | string | Determines self-issuing |
| `invoiceNumber` | string | Invoice number |
| `invoiceOrderNumber` | string | Invoice order number |
| `dateTimeCreated` | datetime | Date of invoice creation |
| `isSimplifiedInvoice` | boolean | Determines if the invoice is a simple invoice |

### Seller Information

| Field | Type | Description |
|-------|------|-------------|
| `sellerRegistrationName` | string | Seller registration name |
| `sellerName` | string | Seller name |
| `sellerNuis` | string | Seller NUIS (tax number) |
| `sellerAddress` | string | Seller address |

### Cash Register / POS Information

| Field | Type | Description |
|-------|------|-------------|
| `tcrCode` | string | Cash Register Code |
| `businessUnitCode` | string | Business unit code |
| `softCode` | string | Software code |
| `operatorCode` | string | Operator code |

### Financial Information

| Field | Type | Description |
|-------|------|-------------|
| `markUpAmount` | decimal | Mark-up amount |
| `goodsExport` | decimal | Value of products for export |
| `taxFreeAmount` | decimal | Value of products exempt from VAT |
| `totalPriceWithoutVat` | decimal | Total value without VAT |
| `totalVatAmount` | decimal | Total VAT amount |
| `totalPrice` | decimal | Total invoice value |

### Tax & Compliance

| Field | Type | Description |
|-------|------|-------------|
| `isReverseCharge` | boolean | Defines self-loading |
| `isBadDebt` | boolean | Determines if invoice is bad debt |
| `isEInvoice` | boolean | Indicates if invoice is electronic |
| `EIC` | string | Electronic Invoice Code (null if not eInvoice) |

### Payment & Dates

| Field | Type | Description |
|-------|------|-------------|
| `payDeadline` | date | Payment deadline |
| `supplyDateOrPeriodStart` | date | Supply start date |
| `supplyDateOrPeriodEnd` | date | Supply end date |

### Currency

| Field | Type | Description |
|-------|------|-------------|
| `currencyCode` | string | Currency code |
| `exchangeRate` | decimal | Exchange rate |

### Corrections

| Field | Type | Description |
|-------|------|-------------|
| `isCorrectiveInvoice` | boolean | Is this a corrective invoice? |
| `CorrectiveInvType` | string | Corrective type |
| `IICReference` | string | IIC of the invoice being corrected |
| `isACorrectedInvoice` | boolean | Was this invoice corrected earlier? |
| `badDebtIICReference` | string | Invoice reference for bad debt |

### Customer Information

| Field | Type | Description |
|-------|------|-------------|
| `Customer.idNumber` | string | Customer identification number |
| `Customer.idType` | string | Type of identification |
| `Customer.name` | string | Customer name |
| `Customer.Address` | string | Customer address |
| `Customer.Town` | string | Customer town |

### Delivery

| Field | Type | Description |
|-------|------|-------------|
| `subsequentDeliveryType` | string | Reason for delayed fiscalization |

### Invoice Products (Array)

| Field | Type | Description |
|-------|------|-------------|
| `invoiceProducts[].Name` | string | Product name |
| `invoiceProducts[].Barcode` | string | Product barcode |
| `invoiceProducts[].unitPrice` | decimal | Unit price |
| `invoiceProducts[].Quantity` | decimal | Quantity |
| `invoiceProducts[].rebatePrice` | decimal | Rebate price |
| `invoiceProducts[].isRebateReducingBasePrice` | boolean | Does rebate reduce taxable base? |
| `invoiceProducts[].Unit` | string | Product unit |
| `invoiceProducts[].vatRate` | decimal | VAT rate |
| `invoiceProducts[].isInvestment` | boolean | Is this an investment? |
| `invoiceProducts[].exemptFromVatType` | string | VAT exemption type |
| `invoiceProducts[].priceBeforeVat` | decimal | Price per unit without VAT |
| `invoiceProducts[].priceAfterVat` | decimal | Price per unit with VAT |
| `invoiceProducts[].vatAmount` | decimal | VAT amount |
| `invoiceProducts[].totalPriceBeforeVat` | decimal | Total price without VAT |
| `invoiceProducts[].totalPriceAfterVat` | decimal | Total price with VAT |

### Invoice Fees (Array)

| Field | Type | Description |
|-------|------|-------------|
| `invoiceFees[].feeType` | string | Fee type |
| `invoiceFees[].Amount` | decimal | Fee amount |

### Invoice Payments (Array)

| Field | Type | Description |
|-------|------|-------------|
| `invoicePayments[].paymentMethodType` | int | Payment method type (0=Cash, 1=Card, etc.) |
| `invoicePayments[].Amount` | decimal | Amount paid |
| `invoicePayments[].companyCard` | string | Company card code |
| `invoicePayments[].Voucher` | array | List of vouchers |

### Process & Fiscalization

| Field | Type | Description |
|-------|------|-------------|
| `Process` | string | Process for eInvoice issuance |
| `documentType` | string | Document type (eInvoice) |
| `fiscNumber` | string | Fiscalization number from Tax Administration |
| `Iic` | string | NSLF number from devPOS system |
| `verificationUrl` | string | Invoice verification URL (for QR Code) |

### PDF Attachment (CONFIRMED)

| Field | Type | Description |
|-------|------|-------------|
| `pdf` | string | Base64-encoded PDF file |

**Example API Response:**
```json
{
  "pdf": "base64_encoded_pdf_file",
  "statusCanBeChanged": false,
  "eic": "b8f4472d-a50c-4412-8927-9e10c5348793",
  "documentNumber": "214/2021",
  "createdDate": "2021-04-17T20:14:58+02:00",
  "dueDate": "2021-05-17T00:00:00+02:00",
  "status": "Dërguar",
  "amount": 5,
  "partyType": "Shitje"
}
```

---

## ✅ CONFIRMED: PDF FIELD EXISTS

**The DevPos API DOES include a `pdf` field with base64-encoded PDF data.**

### Implementation Notes

#### Option 1: Check if DevPos has a PDF download endpoint
```
GET /v3/Invoice/DownloadPDF?invoiceNumber={number}
GET /v3/Invoice/GetPDF?EIC={eic}
```

#### Option 2: Use the verification URL
The `verificationUrl` field provides a way to verify/view the invoice. This URL might:
- Display the invoice as HTML/PDF
- Provide a download link for PDF
- Be scrapable to extract PDF content

#### Option 3: Contact DevPos Support
Ask DevPos:
- "How do we download invoice PDFs programmatically via API?"
- "Is there a separate endpoint for PDF download?"
- "Can we get base64-encoded PDFs in the invoice response?"

#### Option 4: Generate PDFs Locally
If DevPos doesn't provide PDFs:
- Generate PDF from invoice data using PHP libraries (TCPDF, mPDF)
- Include all invoice details, QR code from verificationUrl
- Attach the generated PDF to QuickBooks

---

## Recommended Next Steps

1. **Run the test script** (`bin/test-devpos-pdf-structure.php`) to confirm PDFs are NOT in API responses
2. **Check DevPos API documentation** for PDF download endpoints
3. **Contact DevPos support** about PDF access
4. **Consider local PDF generation** as a fallback solution

---

## Related Documentation

- [DEVPOS-FIELD-MAPPING.md](DEVPOS-FIELD-MAPPING.md) - Current field mapping (assumes `pdf` field exists)
- [DEVPOS-AVAILABLE-FIELDS.md](DEVPOS-AVAILABLE-FIELDS.md) - Additional fields we could sync

**Status:** This document reflects the ACTUAL DevPos API specification
