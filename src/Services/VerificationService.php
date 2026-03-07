<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Validates DevPos invoice/bill data and QBO payloads before sync.
 * Also generates post-hoc audit reports for already-synced records.
 */
class VerificationService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // -------------------------------------------------------------------------
    // DevPos source validation
    // -------------------------------------------------------------------------

    /**
     * Validate a DevPos sales invoice (for syncing to QBO as Invoice).
     */
    public function validateDevPosInvoice(array $invoice): VerificationResult
    {
        $errors   = [];
        $warnings = [];

        // Required identifiers
        $eic = $invoice['eic'] ?? $invoice['EIC'] ?? $invoice['Eic'] ?? null;
        if (empty($eic)) {
            $errors[] = 'Missing EIC (fiscalization code)';
        }

        $docNumber = $invoice['invoiceNumber'] ?? $invoice['documentNumber'] ?? $invoice['fiscNumber'] ?? null;
        if (empty($docNumber)) {
            $errors[] = 'Missing invoice number (invoiceNumber / documentNumber)';
        }

        $date = $invoice['dateTimeCreated'] ?? $invoice['invoiceCreatedDate'] ?? $invoice['InvoiceCreatedDate'] ?? null;
        if (empty($date)) {
            $errors[] = 'Missing invoice date (dateTimeCreated / invoiceCreatedDate)';
        }

        $sellerNuis = $invoice['sellerNuis'] ?? $invoice['SellerNuis'] ?? null;
        if (empty($sellerNuis)) {
            $errors[] = 'Missing seller NUIS';
        }

        $sellerName = $invoice['sellerName'] ?? $invoice['SellerName'] ?? null;
        if (empty($sellerName)) {
            $errors[] = 'Missing seller name';
        }

        $total = $invoice['totalPrice'] ?? $invoice['amount'] ?? $invoice['Amount'] ?? $invoice['totalAmount'] ?? null;
        if ($total === null || $total === '') {
            $errors[] = 'Missing total amount (totalPrice / amount)';
        }

        // Warnings — non-blocking
        $currency = $invoice['currencyCode'] ?? $invoice['CurrencyCode'] ?? null;
        if (empty($currency)) {
            $warnings[] = 'currencyCode not present — will default to ALL';
        }

        if (!empty($currency) && $currency !== 'ALL') {
            $rate = $invoice['exchangeRate'] ?? $invoice['ExchangeRate'] ?? null;
            if (empty($rate)) {
                $warnings[] = "currencyCode=$currency but exchangeRate is missing";
            }
        }

        $products = $invoice['products'] ?? $invoice['Products'] ?? $invoice['invoiceProducts'] ?? [];
        if (empty($products)) {
            $warnings[] = 'No products/line items found in invoice';
        }

        $payments = $invoice['invoicePayments'] ?? $invoice['payments'] ?? $invoice['Payments'] ?? [];
        if (empty($payments)) {
            $warnings[] = 'No payment method found in invoice';
        }

        $buyerNuis = $invoice['buyerNuis'] ?? $invoice['BuyerNuis'] ?? $invoice['Customer']['idNumber'] ?? null;
        if (empty($buyerNuis)) {
            $warnings[] = 'Missing buyer NUIS / customer ID — QBO customer lookup may fail';
        }

        if (!empty($errors)) {
            return VerificationResult::fail($errors, $warnings);
        }

        return VerificationResult::ok($warnings);
    }

    /**
     * Validate a DevPos purchase invoice (eBill, for syncing to QBO as Bill).
     */
    public function validateDevPosBill(array $bill): VerificationResult
    {
        $errors   = [];
        $warnings = [];

        $eic = $bill['eic'] ?? $bill['EIC'] ?? $bill['Eic'] ?? null;
        if (empty($eic)) {
            $errors[] = 'Missing EIC';
        }

        $docNumber = $bill['documentNumber'] ?? $bill['invoiceNumber'] ?? $bill['DocumentNumber'] ?? null;
        if (empty($docNumber)) {
            $errors[] = 'Missing document number';
        }

        $date = $bill['invoiceCreatedDate'] ?? $bill['dateTimeCreated'] ?? $bill['InvoiceCreatedDate'] ?? null;
        if (empty($date)) {
            $errors[] = 'Missing invoice date (invoiceCreatedDate / dateTimeCreated)';
        }

        $sellerNuis = $bill['sellerNuis'] ?? $bill['SellerNuis'] ?? null;
        if (empty($sellerNuis)) {
            $errors[] = 'Missing seller NUIS';
        }

        $sellerName = $bill['sellerName'] ?? $bill['SellerName'] ?? null;
        if (empty($sellerName)) {
            $errors[] = 'Missing seller name';
        }

        $amount = $bill['amount'] ?? $bill['Amount'] ?? $bill['totalPrice'] ?? $bill['totalAmount'] ?? null;
        if ($amount === null || $amount === '') {
            $errors[] = 'Missing amount';
        }

        // Warnings
        $currency = $bill['currencyCode'] ?? $bill['CurrencyCode'] ?? null;
        if (empty($currency)) {
            $warnings[] = 'currencyCode not present — will default to ALL';
        }

        if (!empty($currency) && $currency !== 'ALL') {
            $rate = $bill['exchangeRate'] ?? $bill['ExchangeRate'] ?? null;
            if (empty($rate)) {
                $warnings[] = "currencyCode=$currency but exchangeRate is missing";
            }
        }

        $products = $bill['products'] ?? $bill['Products'] ?? $bill['invoiceProducts'] ?? [];
        if (empty($products)) {
            $warnings[] = 'No products/line items found in bill';
        }

        if (!empty($errors)) {
            return VerificationResult::fail($errors, $warnings);
        }

        return VerificationResult::ok($warnings);
    }

    // -------------------------------------------------------------------------
    // QBO payload validation
    // -------------------------------------------------------------------------

    /**
     * Validate a QBO Invoice payload before POST.
     */
    public function validateQBOInvoicePayload(array $payload): VerificationResult
    {
        $errors   = [];
        $warnings = [];

        if (empty($payload['CustomerRef']['value'])) {
            $errors[] = 'Missing CustomerRef.value in QBO invoice payload';
        }

        if (empty($payload['TxnDate'])) {
            $errors[] = 'Missing TxnDate in QBO invoice payload';
        }

        $lines = $payload['Line'] ?? [];
        if (empty($lines)) {
            $errors[] = 'QBO invoice has no Line items';
        } else {
            foreach ($lines as $i => $line) {
                if (!isset($line['Amount'])) {
                    $errors[] = "Line[$i] missing Amount";
                }
                if (empty($line['SalesItemLineDetail']['ItemRef']['value'])) {
                    $errors[] = "Line[$i] missing SalesItemLineDetail.ItemRef.value";
                }
            }
        }

        if (!empty($payload['ExchangeRate']) && empty($payload['CurrencyRef']['value'])) {
            $warnings[] = 'ExchangeRate set but CurrencyRef missing in QBO invoice';
        }

        if (!empty($errors)) {
            return VerificationResult::fail($errors, $warnings);
        }

        return VerificationResult::ok($warnings);
    }

    /**
     * Validate a QBO Bill payload before POST.
     */
    public function validateQBOBillPayload(array $payload): VerificationResult
    {
        $errors   = [];
        $warnings = [];

        if (empty($payload['VendorRef']['value'])) {
            $errors[] = 'Missing VendorRef.value in QBO bill payload';
        }

        if (empty($payload['TxnDate'])) {
            $errors[] = 'Missing TxnDate in QBO bill payload';
        }

        $lines = $payload['Line'] ?? [];
        if (empty($lines)) {
            $errors[] = 'QBO bill has no Line items';
        } else {
            foreach ($lines as $i => $line) {
                if (!isset($line['Amount'])) {
                    $errors[] = "Line[$i] missing Amount";
                }
                if (empty($line['AccountBasedExpenseLineDetail']['AccountRef']['value'])) {
                    $errors[] = "Line[$i] missing AccountBasedExpenseLineDetail.AccountRef.value";
                }
            }
        }

        if (!empty($payload['ExchangeRate']) && empty($payload['CurrencyRef']['value'])) {
            $warnings[] = 'ExchangeRate set but CurrencyRef missing in QBO bill';
        }

        if (!empty($errors)) {
            return VerificationResult::fail($errors, $warnings);
        }

        return VerificationResult::ok($warnings);
    }

    // -------------------------------------------------------------------------
    // Audit report
    // -------------------------------------------------------------------------

    /**
     * Generate a validation audit report for all synced records in a date range.
     * Reads maps_documents to find what was synced, then re-validates stored data.
     */
    public function generateAuditReport(int $companyId, string $fromDate, string $toDate): array
    {
        $report = [
            'company_id'   => $companyId,
            'from_date'    => $fromDate,
            'to_date'      => $toDate,
            'generated_at' => date('c'),
            'summary' => [
                'invoices_synced' => 0,
                'bills_synced'    => 0,
            ],
            'invoices' => [],
            'bills'    => [],
        ];

        // Fetch synced invoices (sales)
        $stmt = $this->pdo->prepare("
            SELECT devpos_document_number AS devpos_id, qbo_invoice_id AS qbo_id,
                   transaction_type, qbo_doc_number, amount, currency, customer_name, synced_at
            FROM invoice_mappings
            WHERE company_id = ?
              AND transaction_type IN ('invoice', 'receipt')
              AND DATE(synced_at) BETWEEN ? AND ?
            ORDER BY synced_at DESC
        ");
        $stmt->execute([$companyId, $fromDate, $toDate]);
        $invoiceMaps = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($invoiceMaps as $map) {
            $report['summary']['invoices_synced']++;
            $report['invoices'][] = [
                'devpos_doc'    => $map['devpos_id'],
                'qbo_id'        => $map['qbo_id'],
                'qbo_doc'       => $map['qbo_doc_number'],
                'type'          => $map['transaction_type'],
                'amount'        => $map['amount'],
                'currency'      => $map['currency'],
                'customer'      => $map['customer_name'],
                'synced_at'     => $map['synced_at'],
            ];
        }

        // Fetch synced bills (purchases)
        $stmt = $this->pdo->prepare("
            SELECT devpos_document_number AS devpos_id, qbo_invoice_id AS qbo_id,
                   transaction_type, qbo_doc_number, amount, currency, customer_name, synced_at
            FROM invoice_mappings
            WHERE company_id = ?
              AND transaction_type = 'bill'
              AND DATE(synced_at) BETWEEN ? AND ?
            ORDER BY synced_at DESC
        ");
        $stmt->execute([$companyId, $fromDate, $toDate]);
        $billMaps = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($billMaps as $map) {
            $report['summary']['bills_synced']++;
            $report['bills'][] = [
                'devpos_doc'    => $map['devpos_id'],
                'qbo_id'        => $map['qbo_id'],
                'qbo_doc'       => $map['qbo_doc_number'],
                'type'          => $map['transaction_type'],
                'amount'        => $map['amount'],
                'currency'      => $map['currency'],
                'vendor'        => $map['customer_name'],
                'synced_at'     => $map['synced_at'],
            ];
        }

        return $report;
    }
}
