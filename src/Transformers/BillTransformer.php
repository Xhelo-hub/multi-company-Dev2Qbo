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
        // Extract fields with fallbacks
        $documentNumber = $devposBill['documentNumber'] 
            ?? $devposBill['doc_no'] 
            ?? $devposBill['DocNumber'] 
            ?? null;
            
        // Try multiple date field variations from DevPos API
        $issueDate = $devposBill['issueDate'] 
            ?? $devposBill['dateCreated'] 
            ?? $devposBill['created_at']
            ?? $devposBill['dateIssued']
            ?? $devposBill['date']
            ?? $devposBill['invoiceDate']
            ?? null;
        
        // If no date found, throw error instead of using today's date
        if (!$issueDate) {
            error_log("WARNING: No date found in DevPos bill: " . json_encode($devposBill));
            $issueDate = date('Y-m-d'); // Last resort fallback
        }
            
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
            'TxnDate' => substr($issueDate, 0, 10), // YYYY-MM-DD format
            'DueDate' => substr($issueDate, 0, 10), // Use same date as issue date
        ];

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
