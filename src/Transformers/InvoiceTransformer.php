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
        // Debug: Log the first invoice to see actual structure
        static $debugLogged = false;
        if (!$debugLogged) {
            error_log("=== DEBUG: DevPos Invoice Structure ===");
            error_log(json_encode($devposInvoice, JSON_PRETTY_PRINT));
            $debugLogged = true;
        }

        // Extract fields with fallbacks
        $documentNumber = $devposInvoice['documentNumber'] 
            ?? $devposInvoice['doc_no'] 
            ?? $devposInvoice['DocNumber'] 
            ?? null;
            
        // Extract date - DevPos returns 'invoiceCreatedDate' in API responses
        $issueDate = $devposInvoice['invoiceCreatedDate']   // PRIMARY - actual API field returned
            ?? $devposInvoice['dateTimeCreated']            // Alternative field name
            ?? $devposInvoice['createdDate']                // Alternative
            ?? $devposInvoice['issueDate']                  // Fallback
            ?? $devposInvoice['dateCreated']                // Fallback
            ?? $devposInvoice['created_at']                 // Fallback
            ?? $devposInvoice['dateIssued']                 // Fallback
            ?? $devposInvoice['date']                       // Fallback
            ?? $devposInvoice['invoiceDate']                // Fallback
            ?? $devposInvoice['documentDate']               // Fallback
            ?? null;
        
        // If no date found, log warning and use today's date as fallback
        if (!$issueDate) {
            error_log("WARNING: No date found in DevPos invoice");
            error_log("Available fields: " . implode(', ', array_keys($devposInvoice)));
            error_log("Full document: " . json_encode($devposInvoice));
            $issueDate = date('Y-m-d');
        } else {
            // Log which field we found the date in
            $foundField = null;
            if (isset($devposInvoice['invoiceCreatedDate'])) $foundField = 'invoiceCreatedDate';
            elseif (isset($devposInvoice['dateTimeCreated'])) $foundField = 'dateTimeCreated';
            elseif (isset($devposInvoice['createdDate'])) $foundField = 'createdDate';
            elseif (isset($devposInvoice['issueDate'])) $foundField = 'issueDate';
            elseif (isset($devposInvoice['dateCreated'])) $foundField = 'dateCreated';
            elseif (isset($devposInvoice['created_at'])) $foundField = 'created_at';
            elseif (isset($devposInvoice['dateIssued'])) $foundField = 'dateIssued';
            elseif (isset($devposInvoice['date'])) $foundField = 'date';
            elseif (isset($devposInvoice['invoiceDate'])) $foundField = 'invoiceDate';
            elseif (isset($devposInvoice['documentDate'])) $foundField = 'documentDate';
            
            error_log("INFO: Found date in field '$foundField' with value: " . $issueDate);
        }
        
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

        return $payload;
    }
}
