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
            
        // Extract date - based on DevPos API documentation (section 5.3)
        // The actual field returned is 'dateTimeCreated' for invoice responses
        $issueDate = $devposSale['dateTimeCreated']      // PRIMARY - official API field
            ?? $devposSale['createdDate']                // For e-invoice queries
            ?? $devposSale['issueDate']                  // Legacy fallback
            ?? $devposSale['dateCreated']                // Legacy fallback
            ?? $devposSale['created_at']                 // Legacy fallback
            ?? $devposSale['dateIssued']                 // Legacy fallback
            ?? $devposSale['date']                       // Legacy fallback
            ?? $devposSale['invoiceDate']                // Legacy fallback
            ?? $devposSale['documentDate']               // Legacy fallback
            ?? null;
        
        // If no date found, log warning and use today's date as fallback
        if (!$issueDate) {
            error_log("WARNING: No date found in DevPos cash sale");
            error_log("Available fields: " . implode(', ', array_keys($devposSale)));
            $issueDate = date('Y-m-d');
        } else {
            error_log("INFO: Using date field with value: " . $issueDate);
        }
            
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
            'TxnDate' => substr($issueDate, 0, 10), // YYYY-MM-DD format
            'PaymentMethodRef' => [
                'value' => $paymentMethod === 'Card' ? '2' : '1' // Adjust based on your QBO setup
            ]
        ];
        
        // Log what we're sending to QuickBooks
        error_log("INFO: QuickBooks SalesReceipt TxnDate being set to: " . substr($issueDate, 0, 10));

        // Add document number if available
        if ($documentNumber) {
            $payload['DocNumber'] = (string)$documentNumber;
        }

        return $payload;
    }
}
