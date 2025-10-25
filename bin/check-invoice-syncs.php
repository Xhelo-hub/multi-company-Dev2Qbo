<?php
/**
 * Check recent invoice sync results
 */

require __DIR__ . '/../vendor/autoload.php';

// Load environment
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Database connection
$pdo = new PDO(
    "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS']
);

// Get recent sync jobs with sales/full type
$stmt = $pdo->prepare("
    SELECT id, job_type, status, created_at, results_json
    FROM sync_jobs
    WHERE job_type IN ('sales', 'full')
    ORDER BY created_at DESC
    LIMIT 5
");
$stmt->execute();
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($jobs as $job) {
    echo "=== Job #{$job['id']} ===\n";
    echo "Type: {$job['job_type']}\n";
    echo "Status: {$job['status']}\n";
    echo "Created: {$job['created_at']}\n\n";
    
    $results = json_decode($job['results_json'], true);
    
    if (isset($results['sales'])) {
        echo "Sales Results:\n";
        echo "  Total: " . ($results['sales']['total'] ?? 0) . "\n";
        echo "  Created: " . ($results['sales']['invoices_created'] ?? 0) . "\n";
        echo "  Skipped: " . ($results['sales']['skipped'] ?? 0) . "\n";
        echo "  Errors: " . ($results['sales']['errors'] ?? 0) . "\n";
        
        if (!empty($results['sales']['error_details'])) {
            echo "\n  Error Details:\n";
            foreach ($results['sales']['error_details'] as $error) {
                echo "    - Invoice: " . ($error['invoice'] ?? 'unknown') . "\n";
                echo "      Error: " . ($error['error'] ?? 'unknown') . "\n";
            }
        }
    }
    
    echo "\n" . str_repeat('-', 80) . "\n\n";
}

// Check invoice mappings
echo "=== Invoice Mappings ===\n";
$stmt = $pdo->query("
    SELECT COUNT(*) as total,
           SUM(CASE WHEN transaction_type = 'invoice' THEN 1 ELSE 0 END) as invoices,
           SUM(CASE WHEN transaction_type = 'sales_receipt' THEN 1 ELSE 0 END) as sales_receipts
    FROM invoice_mappings
");
$counts = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Total mappings: {$counts['total']}\n";
echo "Invoices: {$counts['invoices']}\n";
echo "Sales Receipts: {$counts['sales_receipts']}\n";

// Show recent invoice mappings
echo "\n=== Recent Invoice Mappings ===\n";
$stmt = $pdo->query("
    SELECT devpos_eic, qbo_invoice_id, transaction_type, created_at
    FROM invoice_mappings
    WHERE transaction_type IN ('invoice', 'sales_receipt')
    ORDER BY created_at DESC
    LIMIT 10
");
$mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($mappings as $mapping) {
    echo "EIC: {$mapping['devpos_eic']} -> QBO ID: {$mapping['qbo_invoice_id']} ({$mapping['transaction_type']}) - {$mapping['created_at']}\n";
}
