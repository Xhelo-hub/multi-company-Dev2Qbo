#!/usr/bin/env php
<?php

/**
 * Debug Field Mapping Tool
 * 
 * This script shows:
 * 1. All fields received from DevPos API
 * 2. Which fields are mapped to QuickBooks
 * 3. What gets sent to QuickBooks API
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Http\DevposClient;
use App\Http\QboClient;
use App\Transformers\InvoiceTransformer;
use App\Transformers\SalesReceiptTransformer;
use App\Transformers\BillTransformer;

// Load environment
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

echo "\n";
echo "================================================================================\n";
echo "  DevPos to QuickBooks Field Mapping Diagnostic Tool\n";
echo "================================================================================\n";
echo "\n";

// Database connection
try {
    $pdo = new PDO(
        "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8mb4",
        $_ENV['DB_USER'],
        $_ENV['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage() . "\n");
}

// Get company credentials (use first active company)
$stmt = $pdo->query("
    SELECT c.id, c.company_code, c.company_name,
           d.tenant, d.username, d.password_encrypted,
           q.realm_id, q.access_token
    FROM companies c
    LEFT JOIN company_credentials_devpos d ON c.id = d.company_id
    LEFT JOIN company_credentials_qbo q ON c.id = q.company_id
    WHERE c.is_active = 1
    ORDER BY c.id ASC
    LIMIT 1
");
$company = $stmt->fetch();

if (!$company) {
    die("No active company found with credentials.\n");
}

echo "Using Company: {$company['company_name']} (ID: {$company['id']})\n";
echo "================================================================================\n\n";

// Initialize DevPos client
if (!$company['tenant'] || !$company['username'] || !$company['password_encrypted']) {
    die("DevPos credentials not configured for this company.\n");
}

$devpos = new DevposClient($pdo, (int)$company['id']);

// Fetch sample data from DevPos
echo "Fetching sample invoices from DevPos...\n";
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-7 days'));

try {
    $invoices = $devpos->fetchSalesEInvoices($yesterday, $today);
    
    if (empty($invoices)) {
        echo "No invoices found in the last 7 days.\n\n";
    } else {
        echo "Found " . count($invoices) . " invoice(s)\n\n";
        
        // Analyze first invoice
        $sampleInvoice = $invoices[0];
        
        echo "--- DEVPOS INVOICE STRUCTURE ---\n";
        echo "All fields received from DevPos:\n\n";
        
        $fields = array_keys($sampleInvoice);
        sort($fields);
        
        foreach ($fields as $field) {
            $value = $sampleInvoice[$field];
            $type = gettype($value);
            
            if (is_array($value)) {
                echo sprintf("  %-30s : [Array with %d items]\n", $field, count($value));
            } elseif (is_object($value)) {
                echo sprintf("  %-30s : [Object]\n", $field);
            } elseif (is_bool($value)) {
                echo sprintf("  %-30s : %s (boolean)\n", $field, $value ? 'true' : 'false');
            } elseif (is_null($value)) {
                echo sprintf("  %-30s : NULL\n", $field);
            } else {
                $displayValue = strlen((string)$value) > 50 
                    ? substr((string)$value, 0, 47) . '...' 
                    : (string)$value;
                echo sprintf("  %-30s : %s\n", $field, $displayValue);
            }
        }
        
        echo "\n--- FIELD MAPPING TO QUICKBOOKS ---\n\n";
        
        // Transform to QuickBooks format
        $qboPayload = InvoiceTransformer::fromDevpos($sampleInvoice);
        
        echo "QuickBooks Invoice Payload:\n\n";
        echo json_encode($qboPayload, JSON_PRETTY_PRINT) . "\n\n";
        
        echo "--- KEY FIELD MAPPINGS ---\n\n";
        
        // Date mapping
        echo "DATE FIELD:\n";
        $dateFields = [
            'dateTimeCreated', 'createdDate', 'issueDate', 'dateCreated', 
            'created_at', 'dateIssued', 'date', 'invoiceDate', 'documentDate'
        ];
        foreach ($dateFields as $field) {
            if (isset($sampleInvoice[$field])) {
                $marker = $field === 'dateTimeCreated' ? ' ← PRIMARY' : '';
                echo sprintf("  DevPos.%-20s = %s%s\n", $field, $sampleInvoice[$field], $marker);
            }
        }
        echo sprintf("  → QBO TxnDate              = %s\n", $qboPayload['TxnDate'] ?? 'NOT SET');
        echo "\n";
        
        // Document number mapping
        echo "DOCUMENT NUMBER:\n";
        $docFields = ['documentNumber', 'doc_no', 'DocNumber'];
        foreach ($docFields as $field) {
            if (isset($sampleInvoice[$field])) {
                echo sprintf("  DevPos.%-20s = %s\n", $field, $sampleInvoice[$field]);
            }
        }
        echo sprintf("  → QBO DocNumber            = %s\n", $qboPayload['DocNumber'] ?? 'NOT SET');
        echo "\n";
        
        // Amount mapping
        echo "AMOUNT:\n";
        $amountFields = ['totalAmount', 'total', 'amount'];
        foreach ($amountFields as $field) {
            if (isset($sampleInvoice[$field])) {
                echo sprintf("  DevPos.%-20s = %s\n", $field, $sampleInvoice[$field]);
            }
        }
        echo sprintf("  → QBO Line[0].Amount       = %s\n", $qboPayload['Line'][0]['Amount'] ?? 'NOT SET');
        echo "\n";
        
        // Customer mapping
        echo "CUSTOMER:\n";
        $customerFields = ['buyerName', 'buyer_name', 'customerName'];
        foreach ($customerFields as $field) {
            if (isset($sampleInvoice[$field])) {
                echo sprintf("  DevPos.%-20s = %s\n", $field, $sampleInvoice[$field]);
            }
        }
        echo sprintf("  → QBO CustomerRef.value    = %s (hardcoded)\n", $qboPayload['CustomerRef']['value'] ?? 'NOT SET');
        echo "\n";
        
        // EIC mapping
        echo "EIC (Invoice Identifier):\n";
        $eicFields = ['eic', 'EIC'];
        foreach ($eicFields as $field) {
            if (isset($sampleInvoice[$field])) {
                echo sprintf("  DevPos.%-20s = %s\n", $field, $sampleInvoice[$field]);
            }
        }
        if (isset($qboPayload['CustomField'])) {
            echo sprintf("  → QBO CustomField          = %s\n", $qboPayload['CustomField'][0]['StringValue'] ?? 'NOT SET');
        } else {
            echo "  → QBO CustomField          = NOT CONFIGURED\n";
        }
        echo "\n";
    }
    
    echo "================================================================================\n\n";
    
    // Now check Purchase Bills
    echo "Fetching sample purchase bills from DevPos...\n";
    
    $bills = $devpos->fetchPurchaseEInvoices($yesterday, $today);
    
    if (empty($bills)) {
        echo "No purchase bills found in the last 7 days.\n\n";
    } else {
        echo "Found " . count($bills) . " bill(s)\n\n";
        
        $sampleBill = $bills[0];
        
        echo "--- DEVPOS PURCHASE BILL STRUCTURE ---\n";
        echo "All fields received from DevPos:\n\n";
        
        $fields = array_keys($sampleBill);
        sort($fields);
        
        foreach ($fields as $field) {
            $value = $sampleBill[$field];
            
            if (is_array($value)) {
                echo sprintf("  %-30s : [Array with %d items]\n", $field, count($value));
            } elseif (is_object($value)) {
                echo sprintf("  %-30s : [Object]\n", $field);
            } elseif (is_bool($value)) {
                echo sprintf("  %-30s : %s (boolean)\n", $field, $value ? 'true' : 'false');
            } elseif (is_null($value)) {
                echo sprintf("  %-30s : NULL\n", $field);
            } else {
                $displayValue = strlen((string)$value) > 50 
                    ? substr((string)$value, 0, 47) . '...' 
                    : (string)$value;
                echo sprintf("  %-30s : %s\n", $field, $displayValue);
            }
        }
        
        echo "\n--- FIELD MAPPING TO QUICKBOOKS ---\n\n";
        
        // Add dummy supplier for transformation
        $sampleBill['supplier'] = ['qbo_id' => '1'];
        $qboPayload = BillTransformer::fromDevpos($sampleBill);
        
        echo "QuickBooks Bill Payload:\n\n";
        echo json_encode($qboPayload, JSON_PRETTY_PRINT) . "\n\n";
        
        echo "--- KEY FIELD MAPPINGS ---\n\n";
        
        // Date mapping
        echo "DATE FIELD:\n";
        foreach ($dateFields as $field) {
            if (isset($sampleBill[$field])) {
                $marker = $field === 'dateTimeCreated' ? ' ← PRIMARY' : '';
                echo sprintf("  DevPos.%-20s = %s%s\n", $field, $sampleBill[$field], $marker);
            }
        }
        echo sprintf("  → QBO TxnDate              = %s\n", $qboPayload['TxnDate'] ?? 'NOT SET');
        echo sprintf("  → QBO DueDate              = %s\n", $qboPayload['DueDate'] ?? 'NOT SET');
        echo "\n";
        
        // Vendor mapping
        echo "VENDOR:\n";
        $vendorFields = ['sellerName', 'seller_name', 'vendorName', 'sellerNuis', 'seller_nuis'];
        foreach ($vendorFields as $field) {
            if (isset($sampleBill[$field])) {
                echo sprintf("  DevPos.%-20s = %s\n", $field, $sampleBill[$field]);
            }
        }
        echo sprintf("  → QBO VendorRef.value      = %s\n", $qboPayload['VendorRef']['value'] ?? 'NOT SET');
        echo "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "================================================================================\n";
echo "Diagnostic complete!\n\n";
echo "SUMMARY:\n";
echo "- Check if DevPos is sending 'dateTimeCreated' field\n";
echo "- Verify the date format from DevPos (should be YYYY-MM-DD)\n";
echo "- Confirm 'TxnDate' is being set in QuickBooks payload\n";
echo "- If date is still wrong in QBO, check QBO API response in error logs\n";
echo "================================================================================\n\n";
