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
        // Extract fields with fallbacks
        $documentNumber = $devposInvoice['documentNumber'] 
            ?? $devposInvoice['doc_no'] 
            ?? $devposInvoice['DocNumber'] 
            ?? null;
            
        $issueDate = $devposInvoice['issueDate'] 
            ?? $devposInvoice['dateCreated'] 
            ?? $devposInvoice['created_at'] 
            ?? date('Y-m-d');
            
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
            'TxnDate' => substr($issueDate, 0, 10), // YYYY-MM-DD format
        ];

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
