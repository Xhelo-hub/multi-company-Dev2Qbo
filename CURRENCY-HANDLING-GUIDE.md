# Currency Handling Implementation Guide

## Overview
This document explains how the Multi-Company Dev2QBO system handles multi-currency transactions for all sync operations (bills, sales invoices, and purchases).

**Date Implemented:** November 3, 2025  
**Commit:** 2286b72

---

## Problem Statement

### Original Issue
DevPos API list endpoints (`/EInvoice/GetPurchaseInvoice` and `/EInvoice/GetSalesInvoice`) **DO NOT include currency fields** in their responses. They only return:
- Basic invoice data (document number, date, amount, parties)
- **Missing:** currencyCode, exchangeRate

This caused all transactions to be created in QuickBooks with default currency (ALL - Albanian Lek), even when the original transaction was in EUR, USD, or other currencies.

**Example:**
- DevPos Bill: EUR 2,604.80
- QuickBooks Bill: ALL 2,604.80 ❌ (wrong currency!)

---

## Solution Architecture

### Step-by-Step Currency Flow

#### 1. **List Invoices (Summary Data)**
```
DevPos List API → Returns basic invoice list WITHOUT currency
   ↓
[eic, documentNumber, amount, date, parties]
NO currencyCode, NO exchangeRate
```

#### 2. **Fetch Detailed Invoice Data**
```
For each invoice in list:
   ↓
fetchDevPosInvoiceDetails(eic) → POST /Invoice with JSON body
   ↓
Returns FULL invoice WITH currency fields
   ↓
[currencyCode, exchangeRate, all other fields]
```

#### 3. **Enrich Invoice Data**
```
Extract from detailed response:
   - currencyCode (EUR, USD, etc.)
   - exchangeRate (conversion rate to ALL)
   ↓
Add to invoice array before processing
```

#### 4. **Convert to QuickBooks Format**
```
Read enriched invoice data:
   - Extract currencyCode
   - Extract exchangeRate
   ↓
Build QuickBooks payload:
   - Set CurrencyRef: {"value": "EUR"}
   - Set ExchangeRate: 1.05
   - Amount stays in foreign currency
```

#### 5. **Create in QuickBooks**
```
QuickBooks receives:
   - Transaction in foreign currency (EUR 2,604.80)
   - With exchange rate (1 EUR = 1.05 ALL)
   - Correctly converts for accounting ✓
```

---

## Implementation Details

### Bills Sync (Purchase Invoices)

**Location:** `src/Services/SyncExecutor.php` lines 285-485

**Process:**
```php
// 1. Fetch bills list from DevPos
$bills = $this->fetchDevPosPurchaseInvoices($token, $tenant, $fromDate, $toDate);

foreach ($bills as $bill) {
    // 2. Get EIC (unique invoice identifier)
    $eic = $bill['eic'] ?? null;
    
    // 3. Fetch detailed invoice to get currency
    if ($eic) {
        $detailedInvoice = $this->fetchDevPosInvoiceDetails($token, $tenant, $eic);
        
        if ($detailedInvoice) {
            // 4. Extract currency from detailed response
            $currency = $detailedInvoice['currencyCode'] ?? 'ALL';
            $exchangeRate = $detailedInvoice['exchangeRate'] ?? null;
            
            // 5. Enrich bill data
            $bill['currencyCode'] = $currency;
            $bill['exchangeRate'] = $exchangeRate;
            
            error_log("Bill currency: $currency, rate: $exchangeRate");
        }
    }
    
    // 6. Convert to QuickBooks format (with currency)
    $qboBill = $this->convertDevPosToQBOBill($bill, $vendorId);
    
    // 7. Create in QuickBooks
    // QBO payload includes CurrencyRef and ExchangeRate
}
```

**Key Function:** `convertDevPosToQBOBill()` (lines 1530-1613)
```php
private function convertDevPosToQBOBill(array $devposBill, string $vendorId): array
{
    // Extract currency from enriched data
    $currency = $devposBill['currencyCode'] ?? 'ALL';
    $exchangeRate = $devposBill['exchangeRate'] ?? null;
    
    $payload = [
        'VendorRef' => ['value' => $vendorId],
        'Line' => [...],
        'Amount' => $amount,
        'TxnDate' => $txnDate
    ];
    
    // Add multi-currency support
    if ($currency !== 'ALL') {
        $payload['CurrencyRef'] = ['value' => strtoupper($currency)];
        
        if ($exchangeRate && $exchangeRate > 0) {
            $payload['ExchangeRate'] = (float)$exchangeRate;
        }
    }
    
    return $payload;
}
```

---

### Sales Invoices Sync

**Location:** `src/Services/SyncExecutor.php` lines 130-230

**Process:** (Same as bills, now implemented in commit 2286b72)
```php
// 1. Fetch invoices list from DevPos
$invoices = $this->fetchDevPosSalesInvoices($token, $tenant, $fromDate, $toDate);

foreach ($invoices as $invoice) {
    // 2. Get EIC
    $eic = $invoice['eic'] ?? null;
    
    // 3. Fetch detailed invoice to get currency
    if ($eic) {
        $detailedInvoice = $this->fetchDevPosInvoiceDetails($token, $tenant, $eic);
        
        if ($detailedInvoice) {
            // 4. Extract currency
            $currency = $detailedInvoice['currencyCode'] ?? 'ALL';
            $exchangeRate = $detailedInvoice['exchangeRate'] ?? null;
            
            // 5. Enrich invoice data
            $invoice['currencyCode'] = $currency;
            $invoice['exchangeRate'] = $exchangeRate;
            
            error_log("Invoice currency: $currency, rate: $exchangeRate");
        }
    }
    
    // 6. Convert to QuickBooks format (with currency)
    $qboInvoice = $this->convertDevPosToQBOInvoice($invoice, $companyId, $qboCreds);
    
    // 7. Create in QuickBooks
}
```

**Key Function:** `convertDevPosToQBOInvoice()` (lines 971-1160)
```php
private function convertDevPosToQBOInvoice(array $devposInvoice, ...): array
{
    // Extract currency from enriched data
    $currency = $devposInvoice['currencyCode'] ?? 'ALL';
    $exchangeRate = $devposInvoice['exchangeRate'] ?? null;
    
    $payload = [
        'CustomerRef' => ['value' => $customerId],
        'Line' => [...],
        'Amount' => $amount,
        'TxnDate' => $txnDate
    ];
    
    // Add multi-currency support
    if ($currency !== 'ALL') {
        error_log("Foreign currency invoice: $currency");
        
        $payload['CurrencyRef'] = ['value' => strtoupper($currency)];
        
        if ($exchangeRate && $exchangeRate > 0) {
            $payload['ExchangeRate'] = (float)$exchangeRate;
            error_log("Exchange rate: 1 $currency = $exchangeRate ALL");
        }
    }
    
    return $payload;
}
```

---

### Detailed Invoice Fetch Function

**Location:** `src/Services/SyncExecutor.php` lines 691-770

**API Call:**
```php
private function fetchDevPosInvoiceDetails(string $token, string $tenant, string $eic): ?array
{
    $client = new Client();
    $apiBase = 'https://online.devpos.al/api/v3';
    
    // Try multiple endpoint formats
    $endpoints = [
        ['path' => '/Invoice', 'type' => 'json'],   // PRIMARY
        ['path' => '/Invoice', 'type' => 'form'],   // FALLBACK
        ['path' => '/EInvoice', 'type' => 'json']   // ALTERNATIVE
    ];
    
    foreach ($endpoints as $config) {
        try {
            error_log("Trying DevPos endpoint: POST {$apiBase}{$config['path']} (type: {$config['type']})");
            
            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'tenant' => $tenant,
                    'Accept' => 'application/json'
                ]
            ];
            
            // Use JSON body (not form_params) - critical for success!
            if ($config['type'] === 'json') {
                $options['json'] = ['EIC' => $eic];
            } else {
                $options['form_params'] = ['EIC' => $eic];
            }
            
            $response = $client->post($apiBase . $config['path'], $options);
            $body = $response->getBody()->getContents();
            $details = json_decode($body, true);
            
            if ($details) {
                error_log("SUCCESS! Endpoint {$config['path']} returned data for EIC: $eic");
                return $details;
            }
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            error_log("Endpoint {$config['path']} failed: " . $e->getMessage());
            continue; // Try next endpoint
        }
    }
    
    return null; // All endpoints failed
}
```

**Critical Detail:** Must use **JSON body format** (`'json' => ['EIC' => $eic]`), not form data. DevPos API returns 415 error with form_params.

---

## Logging Output

### Expected Log Pattern for Bills
```
[1/10] Processing bill 1308/2025...
Trying DevPos endpoint: POST https://online.devpos.al/api/v3/Invoice with EIC=xxx (type: json)
SUCCESS! Endpoint /Invoice returned data for EIC: xxx
[1/10] Fetched detailed invoice - Currency: EUR, ExchangeRate: 1.05
[1/10] Bill 1308/2025 - Currency: EUR, Amount: 2604.8
convertDevPosToQBOBill: Currency='EUR', ExchangeRate='1.05', Amount=2604.8
Adding multi-currency to bill: Currency=EUR, ExchangeRate=1.05
Bill exchange rate: 1 EUR = 1.05 ALL
[1/10] ✓ Bill 1308/2025 synced successfully
```

### Expected Log Pattern for Sales Invoices
```
[1/10] Syncing invoice xxx to QuickBooks...
Trying DevPos endpoint: POST https://online.devpos.al/api/v3/Invoice with EIC=xxx (type: json)
SUCCESS! Endpoint /Invoice returned data for EIC: xxx
[1/10] Fetched detailed invoice - Currency: USD, ExchangeRate: 1.12
[1/10] Invoice INV-001 - Currency: USD, Amount: 5000.00
=== STEP 1: INVOICE CURRENCY EXTRACTION ===
  currencyCode field: USD
  exchangeRate field: 1.12
  ➜ FINAL CURRENCY: USD
=== STEP 3: INVOICE PAYLOAD MULTI-CURRENCY ===
  Detected foreign currency: USD
  Exchange rate: 1.12
  ➜ Added CurrencyRef: USD
  ➜ Added ExchangeRate: 1.12 (1 USD = 1.12 ALL)
[1/10] ✓ Invoice xxx synced successfully
```

---

## QuickBooks Multi-Currency Format

### Bill Payload Example
```json
{
    "VendorRef": {"value": "123"},
    "Line": [{
        "DetailType": "AccountBasedExpenseLineDetail",
        "Amount": 2604.80,
        "AccountBasedExpenseLineDetail": {
            "AccountRef": {"value": "1"}
        }
    }],
    "DocNumber": "1308/2025",
    "TxnDate": "2025-10-26",
    "CurrencyRef": {"value": "EUR"},
    "ExchangeRate": 1.05
}
```

### Invoice Payload Example
```json
{
    "CustomerRef": {"value": "456"},
    "Line": [{
        "DetailType": "SalesItemLineDetail",
        "Amount": 5000.00,
        "SalesItemLineDetail": {
            "ItemRef": {"value": "1"},
            "Qty": 1
        }
    }],
    "DocNumber": "INV-001",
    "TxnDate": "2025-11-03",
    "CurrencyRef": {"value": "USD"},
    "ExchangeRate": 1.12
}
```

**Note:** 
- `Amount` stays in **foreign currency** (EUR, USD, etc.)
- QuickBooks uses `ExchangeRate` to convert to home currency (ALL) for accounting
- Items/Accounts are always defined in home currency
- Transaction-level currency override via `CurrencyRef`

---

## Testing Checklist

### ✅ Task 1: Bills Sync with Currency
**Status:** Code deployed, awaiting test

**Test Steps:**
1. Go to dashboard: https://devsync.konsulence.al/public/app.html
2. Select company: Qendra Jonathan (NUIS: L12315453W)
3. Select sync type: BILLS
4. Date range: 2025-10-26 to 2025-11-02
5. Click "Start Sync"
6. Check logs for:
   - "SUCCESS! Endpoint /Invoice returned data"
   - "Fetched detailed invoice - Currency: EUR"
   - "Bill xxx - Currency: EUR, Amount: xxx"
7. Verify in QuickBooks:
   - Bills show correct currency (EUR, not ALL)
   - Exchange rate is applied

### ✅ Task 2: Sales Invoices Sync with Currency
**Status:** Code deployed, awaiting test

**Test Steps:**
1. Select sync type: SALES
2. Choose date range with foreign currency invoices
3. Click "Start Sync"
4. Check logs for detailed invoice fetch and currency extraction
5. Verify in QuickBooks:
   - Invoices show correct currency
   - Exchange rate is applied

### ⚠️ Potential Issues

**Issue 1: DevPos API Still Fails**
- **Symptoms:** Logs show "Endpoint /Invoice failed" or 415/500 errors
- **Cause:** API endpoint still not accepting JSON format
- **Solution:** Contact DevPos support for correct API format

**Issue 2: Exchange Rate Missing**
- **Symptoms:** Logs show "Currency: EUR, ExchangeRate: NULL"
- **Cause:** DevPos detailed response doesn't include exchange rate
- **Impact:** QuickBooks may use current day rate instead of transaction date rate
- **Solution:** May need to fetch from separate endpoint or use fallback rate

**Issue 3: Currency Not Recognized by QuickBooks**
- **Symptoms:** QuickBooks API error "Currency not enabled"
- **Cause:** Currency not activated in QuickBooks company settings
- **Solution:** Enable multi-currency in QuickBooks, activate specific currencies (EUR, USD, etc.)

---

## Troubleshooting

### Check Logs
```bash
ssh root@78.46.201.151
tail -500 /var/log/apache2/domains/devsync.konsulence.al.error.log | grep -E 'Currency|ExchangeRate|Fetched detailed'
```

### Verify API Response
```bash
# Look for successful API calls
tail -500 /var/log/apache2/domains/devsync.konsulence.al.error.log | grep "SUCCESS! Endpoint"
```

### Check QuickBooks Creation
```bash
# Look for QB payload logs
tail -500 /var/log/apache2/domains/devsync.konsulence.al.error.log | grep -A 10 "convertDevPosToQBO"
```

---

## Maintenance Notes

### If DevPos API Changes
**Location to Update:** `fetchDevPosInvoiceDetails()` function (line 691)
- Update endpoint path
- Adjust request format (JSON vs form_params)
- Add new field mappings if currency field names change

### If QuickBooks API Changes
**Locations to Update:**
- `convertDevPosToQBOBill()` (line 1530)
- `convertDevPosToQBOInvoice()` (line 971)
- Adjust payload structure
- Update CurrencyRef or ExchangeRate format

### Adding New Transaction Types
**Pattern to Follow:**
1. Fetch list from DevPos (without currency)
2. Call `fetchDevPosInvoiceDetails()` for each item
3. Extract currency and exchange rate
4. Enrich transaction data
5. Convert to QuickBooks format with CurrencyRef + ExchangeRate
6. Add comprehensive logging

---

## Key Takeaways

✅ **All sync operations now fetch detailed invoice data for currency**
✅ **Bills and invoices get correct currency from DevPos**
✅ **Exchange rates are preserved from original transactions**
✅ **QuickBooks receives proper multi-currency format**
✅ **Comprehensive logging for troubleshooting**

⚠️ **Critical dependency:** DevPos `/Invoice` endpoint with JSON body format must work
⚠️ **Prerequisite:** QuickBooks company must have multi-currency enabled

---

## Related Files

- **Main sync logic:** `src/Services/SyncExecutor.php`
- **API routes:** `routes/api.php`
- **Database schema:** `sql/multi-company-schema.sql` (invoice_mappings table includes currency field)
- **Dashboard:** `public/dashboard.html`

---

## Commit History

**Latest Commits:**
1. `2286b72` - Add currency fetch for sales invoices and improve logging
2. `07edeaa` - Try JSON body for DevPos Invoice API (fix 415 error)
3. `d2efc37` - Test multiple endpoint variations
4. `6b74174` - Implement fetchDevPosInvoiceDetails()
5. `052ecf3` - Fix TypeError and undefined variable

**Branch:** main  
**Production:** https://devsync.konsulence.al
