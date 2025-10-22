#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Database connection
$pdo = new PDO(
    "mysql:host=localhost;dbname=qbo_multicompany;charset=utf8mb4",
    'root',
    '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "=== Testing Transactions API ===\n\n";

// Get all companies
$stmt = $pdo->query("SELECT id, company_code, company_name FROM companies WHERE is_active = 1");
$companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Active Companies:\n";
foreach ($companies as $company) {
    echo "  [{$company['id']}] {$company['company_name']} ({$company['company_code']})\n";
}

echo "\n";

// For each company, check transactions
foreach ($companies as $company) {
    echo "Company: {$company['company_name']}\n";
    echo str_repeat("-", 50) . "\n";
    
    // Get transactions count
    $stmt = $pdo->prepare("
        SELECT 
            transaction_type,
            COUNT(*) as count,
            SUM(amount) as total_amount
        FROM invoice_mappings
        WHERE company_id = ?
        GROUP BY transaction_type
    ");
    $stmt->execute([$company['id']]);
    $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($stats)) {
        echo "  No transactions synced\n";
    } else {
        foreach ($stats as $stat) {
            echo "  {$stat['transaction_type']}: {$stat['count']} transactions, " . 
                 number_format((float)$stat['total_amount'], 2) . " ALL\n";
        }
        
        // Show sample transactions
        $stmt = $pdo->prepare("
            SELECT 
                id,
                devpos_document_number,
                transaction_type,
                qbo_doc_number,
                amount,
                customer_name,
                synced_at
            FROM invoice_mappings
            WHERE company_id = ?
            ORDER BY synced_at DESC
            LIMIT 5
        ");
        $stmt->execute([$company['id']]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "\n  Recent Transactions:\n";
        foreach ($transactions as $tx) {
            echo "    [{$tx['id']}] {$tx['transaction_type']} - Doc: {$tx['devpos_document_number']} -> QBO: {$tx['qbo_doc_number']} | ";
            echo number_format((float)$tx['amount'], 2) . " ALL | {$tx['customer_name']} | {$tx['synced_at']}\n";
        }
    }
    
    echo "\n";
}

// Test the API endpoint simulation
echo "\n=== Simulating API Call ===\n";
$companyId = $companies[0]['id'] ?? 1;
echo "GET /api/sync/{$companyId}/transactions?limit=10&offset=0&type=all\n\n";

$stmt = $pdo->prepare("
    SELECT 
        id,
        devpos_eic,
        devpos_document_number,
        transaction_type,
        qbo_invoice_id,
        qbo_doc_number,
        amount,
        customer_name,
        synced_at,
        last_synced_at
    FROM invoice_mappings
    WHERE company_id = ?
    ORDER BY synced_at DESC
    LIMIT 10
");
$stmt->execute([$companyId]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM invoice_mappings WHERE company_id = ?");
$stmt->execute([$companyId]);
$total = (int)$stmt->fetchColumn();

echo "Response:\n";
echo json_encode([
    'transactions' => $transactions,
    'total' => $total,
    'limit' => 10,
    'offset' => 0,
    'has_more' => count($transactions) >= 10
], JSON_PRETTY_PRINT);

echo "\n\n=== Test Complete ===\n";
