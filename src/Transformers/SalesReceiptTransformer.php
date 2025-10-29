<?php

declare(strict_types=1);

namespace App\Transformers;

/**
 * Transform DevPos Cash Sales to QuickBooks SalesReceipt format
 */
class SalesReceiptTransformer
{
    /**
     * Transform DevPos cash sale to QuickBooks sales receipt payload
     * 
     * @param array $devposSale DevPos cash sale/simplified invoice data
     * @return array QuickBooks sales receipt payload
     */
    public static function fromDevpos(array $devposSale): array
    {
        // Debug: Log the first cash sale to see actual structure
        static $debugLogged = false;
        if (!$debugLogged) {
            error_log("=== DEBUG: DevPos Cash Sale Structure ===");
            error_log(json_encode($devposSale, JSON_PRETTY_PRINT));
            $debugLogged = true;
        }

        // Extract fields with fallbacks
        $documentNumber = $devposSale['documentNumber'] 
            ?? $devposSale['doc_no'] 
            ?? $devposSale['DocNumber'] 
            ?? null;
            
        // Extract date - DevPos returns 'invoiceCreatedDate' in API responses
        $issueDate = $devposSale['invoiceCreatedDate']   // PRIMARY - actual API field returned
            ?? $devposSale['dateTimeCreated']            // Alternative field name
            ?? $devposSale['createdDate']                // Alternative
            ?? $devposSale['issueDate']                  // Fallback
            ?? $devposSale['dateCreated']                // Fallback
            ?? $devposSale['created_at']                 // Fallback
            ?? $devposSale['dateIssued']                 // Fallback
            ?? $devposSale['date']                       // Fallback
            ?? $devposSale['invoiceDate']                // Fallback
            ?? $devposSale['documentDate']               // Fallback
            ?? null;
        
        // If no date found, log warning and use today's date as fallback
        if (!$issueDate) {
            error_log("WARNING: No date found in DevPos cash sale");
            error_log("Available fields: " . implode(', ', array_keys($devposSale)));
            $issueDate = date('Y-m-d');
        } else {
            error_log("INFO: Using date field with value: " . $issueDate);
        }
        
        // Ensure date is in YYYY-MM-DD format for QuickBooks
        // DevPos returns ISO 8601: "2025-05-21T14:33:57+02:00"
        // QuickBooks expects: "2025-05-21"
        // Extract positions 0-9: Y(0-3)-M(5-6)-D(8-9)
        $formattedDate = substr($issueDate, 0, 10);
            
        $totalAmount = $devposSale['totalAmount'] 
            ?? $devposSale['total'] 
            ?? $devposSale['amount'] 
            ?? 0;
            
        $buyerName = $devposSale['buyerName'] 
            ?? $devposSale['buyer_name'] 
            ?? $devposSale['customerName'] 
            ?? 'Cash Customer';

        // Detect payment method
        $paymentMethod = 'Cash';
        if (isset($devposSale['invoicePayments']) && is_array($devposSale['invoicePayments']) && count($devposSale['invoicePayments']) > 0) {
            $paymentType = $devposSale['invoicePayments'][0]['paymentMethodType'] ?? 0;
            $paymentMethod = $paymentType === 1 ? 'Card' : 'Cash';
        }

        // Build QuickBooks sales receipt payload
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
                    'Description' => $documentNumber ? "Cash Sale: $documentNumber" : 'Cash Sale'
                ]
            ],
            'CustomerRef' => [
                'value' => '1' // Default customer (must exist in QBO)
            ],
            'TxnDate' => $formattedDate, // YYYY-MM-DD format
            'PaymentMethodRef' => [
                'value' => $paymentMethod === 'Card' ? '2' : '1' // Adjust based on your QBO setup
            ]
        ];
        
        // Log what we're sending to QuickBooks
        error_log("INFO: QuickBooks SalesReceipt TxnDate being set to: " . $formattedDate);

        // Add document number if available
        if ($documentNumber) {
            $payload['DocNumber'] = (string)$documentNumber;
        }

        return $payload;
    }
}
