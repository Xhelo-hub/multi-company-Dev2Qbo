<?php

declare(strict_types=1);

namespace App\Transformers;

/**
 * Transform DevPos Purchase E-Invoices to QuickBooks Bill format
 */
class BillTransformer
{
    /**
     * Transform DevPos purchase invoice to QuickBooks bill payload
     * 
     * @param array $devposBill DevPos purchase invoice data
     * @return array QuickBooks bill payload
     */
    public static function fromDevpos(array $devposBill): array
    {
        // Debug: Log EVERY bill to see what date fields DevPos is actually returning
        error_log("=== DEBUG: DevPos Bill ===");
        error_log("Document: " . ($devposBill['documentNumber'] ?? 'N/A'));
        error_log("Available date fields: " . json_encode([
            'invoiceCreatedDate' => $devposBill['invoiceCreatedDate'] ?? 'NOT SET',
            'issueDate' => $devposBill['issueDate'] ?? 'NOT SET',
            'date' => $devposBill['date'] ?? 'NOT SET',
            'dateTimeCreated' => $devposBill['dateTimeCreated'] ?? 'NOT SET',
            'createdDate' => $devposBill['createdDate'] ?? 'NOT SET',
        ]));
        error_log("Currency fields: " . json_encode([
            'currency' => $devposBill['currency'] ?? 'NOT SET',
            'baseCurrency' => $devposBill['baseCurrency'] ?? 'NOT SET',
            'exchangeRate' => $devposBill['exchangeRate'] ?? 'NOT SET',
            'totalAmount' => $devposBill['totalAmount'] ?? 'NOT SET',
            'amountInBaseCurrency' => $devposBill['amountInBaseCurrency'] ?? 'NOT SET',
        ]));
        error_log("ALL FIELDS: " . implode(', ', array_keys($devposBill)));

        // Extract fields with fallbacks
        $documentNumber = $devposBill['documentNumber'] 
            ?? $devposBill['doc_no'] 
            ?? $devposBill['DocNumber'] 
            ?? null;
            
        // Extract date - DevPos actually returns 'invoiceCreatedDate' (verified from production logs Oct 30)
        $issueDate = $devposBill['invoiceCreatedDate']   // PRIMARY - actual API field (confirmed in logs)
            ?? $devposBill['issueDate']                  // SECONDARY fallback
            ?? $devposBill['date']                       // TERTIARY fallback
            ?? date('Y-m-d');                            // FINAL fallback - today's date
        
        // Log which field we found the date in
        $foundField = 'today (fallback)';
        if (isset($devposBill['invoiceCreatedDate'])) {
            $foundField = 'invoiceCreatedDate';
        } elseif (isset($devposBill['issueDate'])) {
            $foundField = 'issueDate';
        } elseif (isset($devposBill['date'])) {
            $foundField = 'date';
        }
        
        error_log("INFO: Found date in field '$foundField' with value: " . $issueDate);
        
        // Ensure date is in YYYY-MM-DD format for QuickBooks
        // DevPos returns ISO 8601: "2025-05-21T14:33:57+02:00"
        // QuickBooks expects: "2025-05-21"
        // Extract positions 0-9: Y(0-3)-M(5-6)-D(8-9)
        $formattedDate = substr($issueDate, 0, 10);
        
        // Extract due date if available, otherwise use issue date
        $dueDate = $devposBill['dueDate'] ?? $issueDate;
        $formattedDueDate = substr($dueDate, 0, 10);
            
        $totalAmount = $devposBill['totalAmount'] 
            ?? $devposBill['total'] 
            ?? $devposBill['amount'] 
            ?? 0;
            
        $sellerName = $devposBill['sellerName'] 
            ?? $devposBill['seller_name'] 
            ?? $devposBill['vendorName'] 
            ?? 'Unknown Vendor';
            
        $sellerNuis = $devposBill['sellerNuis'] 
            ?? $devposBill['seller_nuis'] 
            ?? '';
            
        $eic = $devposBill['eic'] 
            ?? $devposBill['EIC'] 
            ?? '';

        // Get vendor QBO ID (should be set by BillsSync before calling this)
        $vendorId = $devposBill['supplier']['qbo_id'] ?? '1';

        // Build QuickBooks bill payload
        $payload = [
            'Line' => [
                [
                    'Amount' => (float)$totalAmount,
                    'DetailType' => 'AccountBasedExpenseLineDetail',
                    'AccountBasedExpenseLineDetail' => [
                        'AccountRef' => [
                            'value' => '7', // Default expense account (adjust for your QBO)
                            'name' => 'Cost of Goods Sold'
                        ]
                    ],
                    'Description' => $documentNumber ? "Purchase: $documentNumber" : 'Purchase Invoice'
                ]
            ],
            'VendorRef' => [
                'value' => (string)$vendorId
            ],
            'TxnDate' => $formattedDate, // YYYY-MM-DD format
            'DueDate' => $formattedDueDate, // Use actual due date from DevPos
        ];
        
        // Log what we're sending to QuickBooks
        error_log("INFO: QuickBooks Bill TxnDate being set to: " . $formattedDate);
        error_log("INFO: QuickBooks Bill DueDate being set to: " . $formattedDueDate);

        // Add document number if available
        if ($documentNumber) {
            $payload['DocNumber'] = (string)$documentNumber;
        }

        // Add EIC as private note if available
        if ($eic) {
            $payload['PrivateNote'] = "EIC: $eic | Vendor NUIS: $sellerNuis";
        }

        // Multi-Currency Support
        // Check if transaction uses a currency different from base currency
        $transactionCurrency = $devposBill['currency'] ?? null;
        $baseCurrency = $devposBill['baseCurrency'] ?? null;
        $exchangeRate = $devposBill['exchangeRate'] ?? null;
        
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
