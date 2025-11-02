<?php

declare(strict_types=1);

namespace App\Transformers;

/**
 * Transform DevPos E-Invoices to QuickBooks Invoice format
 */
class InvoiceTransformer
{
    /**
     * Transform DevPos invoice to QuickBooks invoice payload
     * 
     * @param array $devposInvoice DevPos invoice data
     * @return array QuickBooks invoice payload
     */
    public static function fromDevpos(array $devposInvoice): array
    {
        // Debug: Log EVERY invoice to see what date fields DevPos is actually returning
        error_log("=== DEBUG: DevPos Invoice ===");
        error_log("Document: " . ($devposInvoice['documentNumber'] ?? 'N/A'));
        error_log("Available date fields: " . json_encode([
            'invoiceCreatedDate' => $devposInvoice['invoiceCreatedDate'] ?? 'NOT SET',
            'issueDate' => $devposInvoice['issueDate'] ?? 'NOT SET',
            'date' => $devposInvoice['date'] ?? 'NOT SET',
            'dateTimeCreated' => $devposInvoice['dateTimeCreated'] ?? 'NOT SET',
            'createdDate' => $devposInvoice['createdDate'] ?? 'NOT SET',
        ]));
        error_log("Currency fields: " . json_encode([
            'currency' => $devposInvoice['currency'] ?? 'NOT SET',
            'baseCurrency' => $devposInvoice['baseCurrency'] ?? 'NOT SET',
            'exchangeRate' => $devposInvoice['exchangeRate'] ?? 'NOT SET',
            'totalAmount' => $devposInvoice['totalAmount'] ?? 'NOT SET',
            'amountInBaseCurrency' => $devposInvoice['amountInBaseCurrency'] ?? 'NOT SET',
        ]));
        error_log("ALL FIELDS: " . implode(', ', array_keys($devposInvoice)));

        // Extract fields with fallbacks
        $documentNumber = $devposInvoice['documentNumber'] 
            ?? $devposInvoice['doc_no'] 
            ?? $devposInvoice['DocNumber'] 
            ?? null;
            
        // Extract date - DevPos actually returns 'invoiceCreatedDate' (verified from production logs Oct 30)
        $issueDate = $devposInvoice['invoiceCreatedDate']   // PRIMARY - actual API field (confirmed in logs)
            ?? $devposInvoice['issueDate']                  // SECONDARY fallback
            ?? $devposInvoice['date']                       // TERTIARY fallback
            ?? date('Y-m-d');                               // FINAL fallback - today's date
        
        // Log which field we found the date in
        $foundField = 'today (fallback)';
        if (isset($devposInvoice['invoiceCreatedDate'])) {
            $foundField = 'invoiceCreatedDate';
        } elseif (isset($devposInvoice['issueDate'])) {
            $foundField = 'issueDate';
        } elseif (isset($devposInvoice['date'])) {
            $foundField = 'date';
        }
        
        error_log("INFO: Found date in field '$foundField' with value: " . $issueDate);
        
        // Ensure date is in YYYY-MM-DD format for QuickBooks
        // DevPos returns ISO 8601: "2025-05-21T14:33:57+02:00"
        // QuickBooks expects: "2025-05-21"
        // Extract positions 0-10: Y(0-4)-M(6-7)-D(9-10)
        $formattedDate = substr($issueDate, 0, 10);
            
        $totalAmount = $devposInvoice['totalAmount'] 
            ?? $devposInvoice['total'] 
            ?? $devposInvoice['amount'] 
            ?? 0;
            
        $buyerName = $devposInvoice['buyerName'] 
            ?? $devposInvoice['buyer_name'] 
            ?? $devposInvoice['customerName'] 
            ?? 'Walk-in Customer';
            
        $eic = $devposInvoice['eic'] 
            ?? $devposInvoice['EIC'] 
            ?? '';

        // Build QuickBooks invoice payload
        $payload = [
            'Line' => [
                [
                    'Amount' => (float)$totalAmount,
                    'DetailType' => 'SalesItemLineDetail',
                    'SalesItemLineDetail' => [
                        'ItemRef' => [
                            'value' => '1', // Default sales item (must exist in QBO)
                            'name' => 'Services'
                        ],
                        'UnitPrice' => (float)$totalAmount,
                        'Qty' => 1
                    ],
                    'Description' => $documentNumber ? "Invoice: $documentNumber" : 'Sales Invoice'
                ]
            ],
            'CustomerRef' => [
                'value' => '1' // Default customer (must exist in QBO)
            ],
            'TxnDate' => $formattedDate, // YYYY-MM-DD format
        ];
        
        // Log what we're sending to QuickBooks
        error_log("INFO: QuickBooks Invoice TxnDate being set to: " . $formattedDate);

        // Add document number if available
        if ($documentNumber) {
            $payload['DocNumber'] = (string)$documentNumber;
        }

        // Add EIC as custom field if configured
        if ($eic && !empty($_ENV['QBO_CF_EIC_DEF_ID'])) {
            // QuickBooks custom fields have 31 char limit - truncate EIC if needed
            $eicValue = strlen($eic) > 31 ? substr($eic, 0, 31) : $eic;
            
            $payload['CustomField'] = [
                [
                    'DefinitionId' => $_ENV['QBO_CF_EIC_DEF_ID'],
                    'Name' => 'EIC',
                    'Type' => 'StringType',
                    'StringValue' => $eicValue
                ]
            ];
        }

        // Multi-Currency Support
        // Check if transaction uses a currency different from base currency
        $transactionCurrency = $devposInvoice['currency'] ?? null;
        $baseCurrency = $devposInvoice['baseCurrency'] ?? null;
        $exchangeRate = $devposInvoice['exchangeRate'] ?? null;
        
        if ($transactionCurrency && $baseCurrency && $transactionCurrency !== $baseCurrency) {
            error_log("INFO: Multi-currency transaction detected - Currency: $transactionCurrency, Base: $baseCurrency");
            
            // Set currency reference (ISO 4217 code: ALL, EUR, USD, etc.)
            $payload['CurrencyRef'] = ['value' => strtoupper($transactionCurrency)];
            
            // Set exchange rate if available
            // QuickBooks expects: 1 foreign currency = X home currency
            // Example: 1 EUR = 106.50 ALL means exchangeRate = 106.50
            if ($exchangeRate && $exchangeRate > 0) {
                $payload['ExchangeRate'] = (float)$exchangeRate;
                error_log("INFO: Exchange rate set: 1 $transactionCurrency = $exchangeRate $baseCurrency");
            }
            
            // Note: QuickBooks will automatically calculate HomeBalance using:
            // HomeBalance = TotalAmount * ExchangeRate
            // We don't need to set it explicitly
        } else {
            error_log("INFO: Single currency transaction (or currency fields not available)");
        }

        return $payload;
    }
}
