<?php
/**
 * Read invoices from specific DevPos tenant
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../bootstrap/app.php';

// Tenant to query
$tenant = 'K43128625A';
$fromDate = '2025-10-25';
$toDate = date('Y-m-d'); // Today

echo "=== DevPos Invoice Reader ===\n";
echo "Tenant: $tenant\n";
echo "Date Range: $fromDate to $toDate\n\n";

try {
    // Get PDO from container
    $pdo = $container->get(PDO::class);
    
    // Find company with this tenant
    $stmt = $pdo->prepare("
        SELECT c.id, c.company_code, c.company_name, ccd.tenant, ccd.username
        FROM companies c
        LEFT JOIN company_credentials_devpos ccd ON c.id = ccd.company_id
        WHERE ccd.tenant = ?
    ");
    $stmt->execute([$tenant]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$company) {
        die("ERROR: No company found with tenant $tenant\n");
    }
    
    echo "Found company: {$company['company_name']} (ID: {$company['id']}, Code: {$company['company_code']})\n";
    echo "Username: {$company['username']}\n\n";
    
    // Initialize DevPos client with company ID
    $client = new \App\Http\DevposClient($pdo, (int)$company['id']);
    
    echo "\n✓ Authenticated with DevPos\n\n";
    
    // Fetch sales invoices
    echo "=== SALES INVOICES ===\n";
    $fromIso = $fromDate . 'T00:00:00+02:00';
    $toIso = $toDate . 'T23:59:59+02:00';
    
    $salesInvoices = $client->fetchSalesEInvoices($fromIso, $toIso);
    
    echo "Found " . count($salesInvoices) . " sales invoice(s)\n\n";
    
    if (!empty($salesInvoices)) {
        // Show first invoice in detail
        echo "--- First Sales Invoice (detailed) ---\n";
        echo json_encode($salesInvoices[0], JSON_PRETTY_PRINT) . "\n\n";
        
        // Show summary of all invoices
        echo "--- All Sales Invoices (summary) ---\n";
        foreach ($salesInvoices as $idx => $invoice) {
            $docNum = $invoice['documentNumber'] ?? 'N/A';
            $date = $invoice['invoiceCreatedDate'] ?? $invoice['issueDate'] ?? 'NO DATE';
            $amount = $invoice['totalAmount'] ?? $invoice['amount'] ?? 0;
            $buyer = $invoice['buyerName'] ?? 'Unknown';
            $eic = $invoice['eic'] ?? 'NO EIC';
            
            echo sprintf(
                "%2d. Doc#: %-15s | Date: %-25s | Amount: %10.2f | Buyer: %s\n",
                $idx + 1,
                $docNum,
                $date,
                $amount,
                $buyer
            );
            echo "    EIC: $eic\n";
            
            // Show available date fields
            $dateFields = [];
            foreach ($invoice as $key => $value) {
                if (stripos($key, 'date') !== false || stripos($key, 'created') !== false) {
                    $dateFields[] = "$key: $value";
                }
            }
            if (!empty($dateFields)) {
                echo "    Date fields: " . implode(', ', $dateFields) . "\n";
            }
            echo "\n";
        }
    }
    
    // Fetch purchase invoices (bills)
    echo "\n=== PURCHASE INVOICES (BILLS) ===\n";
    $purchaseInvoices = $client->fetchPurchaseEInvoices($fromIso, $toIso);
    
    echo "Found " . count($purchaseInvoices) . " purchase invoice(s)\n\n";
    
    if (!empty($purchaseInvoices)) {
        // Show first bill in detail
        echo "--- First Purchase Invoice (detailed) ---\n";
        echo json_encode($purchaseInvoices[0], JSON_PRETTY_PRINT) . "\n\n";
        
        // Show summary of all bills
        echo "--- All Purchase Invoices (summary) ---\n";
        foreach ($purchaseInvoices as $idx => $bill) {
            $docNum = $bill['documentNumber'] ?? 'N/A';
            $date = $bill['invoiceCreatedDate'] ?? $bill['issueDate'] ?? 'NO DATE';
            $amount = $bill['totalAmount'] ?? $bill['amount'] ?? 0;
            $seller = $bill['sellerName'] ?? 'Unknown';
            $eic = $bill['eic'] ?? 'NO EIC';
            
            echo sprintf(
                "%2d. Doc#: %-15s | Date: %-25s | Amount: %10.2f | Seller: %s\n",
                $idx + 1,
                $docNum,
                $date,
                $amount,
                $seller
            );
            echo "    EIC: $eic\n";
            
            // Show available date fields
            $dateFields = [];
            foreach ($bill as $key => $value) {
                if (stripos($key, 'date') !== false || stripos($key, 'created') !== false) {
                    $dateFields[] = "$key: $value";
                }
            }
            if (!empty($dateFields)) {
                echo "    Date fields: " . implode(', ', $dateFields) . "\n";
            }
            echo "\n";
        }
    }
    
    // Summary
    echo "\n=== SUMMARY ===\n";
    echo "Total Sales Invoices: " . count($salesInvoices) . "\n";
    echo "Total Purchase Invoices: " . count($purchaseInvoices) . "\n";
    
    // Check for invoiceCreatedDate field
    echo "\n=== DATE FIELD ANALYSIS ===\n";
    
    $allInvoices = array_merge($salesInvoices, $purchaseInvoices);
    $hasInvoiceCreatedDate = 0;
    $hasOtherDateFields = [];
    
    foreach ($allInvoices as $invoice) {
        if (isset($invoice['invoiceCreatedDate'])) {
            $hasInvoiceCreatedDate++;
        }
        
        foreach (array_keys($invoice) as $key) {
            if (stripos($key, 'date') !== false || stripos($key, 'created') !== false) {
                if (!isset($hasOtherDateFields[$key])) {
                    $hasOtherDateFields[$key] = 0;
                }
                $hasOtherDateFields[$key]++;
            }
        }
    }
    
    echo "Documents with 'invoiceCreatedDate': $hasInvoiceCreatedDate / " . count($allInvoices) . "\n";
    echo "\nAll date-related fields found:\n";
    foreach ($hasOtherDateFields as $field => $count) {
        echo "  - $field: present in $count document(s)\n";
    }
    
    echo "\n✓ Complete!\n";
    
} catch (\Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
