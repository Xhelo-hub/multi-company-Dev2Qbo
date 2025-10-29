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
        // Debug: Log the first bill to see actual structure
        static $debugLogged = false;
        if (!$debugLogged) {
            error_log("=== DEBUG: DevPos Purchase Bill Structure ===");
            error_log(json_encode($devposBill, JSON_PRETTY_PRINT));
            $debugLogged = true;
        }

        // Extract fields with fallbacks
        $documentNumber = $devposBill['documentNumber'] 
            ?? $devposBill['doc_no'] 
            ?? $devposBill['DocNumber'] 
            ?? null;
            
        // Extract date - DevPos returns 'invoiceCreatedDate' in API responses
        $issueDate = $devposBill['invoiceCreatedDate']   // PRIMARY - actual API field returned
            ?? $devposBill['dateTimeCreated']            // Alternative field name
            ?? $devposBill['createdDate']                // Alternative
            ?? $devposBill['issueDate']                  // Fallback
            ?? $devposBill['dateCreated']                // Fallback
            ?? $devposBill['created_at']                 // Fallback
            ?? $devposBill['dateIssued']                 // Fallback
            ?? $devposBill['date']                       // Fallback
            ?? $devposBill['invoiceDate']                // Fallback
            ?? null;
        
        // If no date found, throw error instead of using today's date
        if (!$issueDate) {
            error_log("WARNING: No date found in DevPos bill");
            error_log("Available fields: " . implode(', ', array_keys($devposBill)));
            $issueDate = date('Y-m-d'); // Last resort fallback
        } else {
            error_log("INFO: Using date field with value: " . $issueDate);
        }
        
        // Ensure date is in YYYY-MM-DD format for QuickBooks
        // DevPos returns ISO 8601: "2025-05-21T14:33:57+02:00"
        // QuickBooks expects: "2025-05-21"
        // Extract positions 0-9: Y(0-3)-M(5-6)-D(8-9)
        $formattedDate = substr($issueDate, 0, 10);
            
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
            'DueDate' => $formattedDate, // Use same date as issue date
        ];
        
        // Log what we're sending to QuickBooks
        error_log("INFO: QuickBooks Bill TxnDate being set to: " . $formattedDate);

        // Add document number if available
        if ($documentNumber) {
            $payload['DocNumber'] = (string)$documentNumber;
        }

        // Add EIC as private note if available
        if ($eic) {
            $payload['PrivateNote'] = "EIC: $eic | Vendor NUIS: $sellerNuis";
        }

        return $payload;
    }
}
