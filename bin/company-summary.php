#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$pdo = new PDO(
    "mysql:host=localhost;dbname=qbo_multicompany;charset=utf8mb4",
    'root',
    '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "=== Company Data Summary ===\n\n";

$stmt = $pdo->query("
    SELECT 
        c.id,
        c.company_code,
        c.company_name,
        c.is_active,
        (SELECT COUNT(*) FROM invoice_mappings WHERE company_id = c.id) as synced_count,
        (SELECT COUNT(*) FROM sync_jobs WHERE company_id = c.id AND status = 'completed') as completed_jobs,
        (SELECT COUNT(*) FROM sync_jobs WHERE company_id = c.id AND status = 'failed') as failed_jobs
    FROM companies c
    ORDER BY c.id
");

$companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($companies as $company) {
    echo "Company ID: {$company['id']}\n";
    echo "Name: {$company['company_name']}\n";
    echo "Code (NIPT): {$company['company_code']}\n";
    echo "Active: " . ($company['is_active'] ? 'Yes' : 'No') . "\n";
    echo "Synced Transactions: {$company['synced_count']}\n";
    echo "Completed Sync Jobs: {$company['completed_jobs']}\n";
    echo "Failed Sync Jobs: {$company['failed_jobs']}\n";
    
    // Check credentials
    $stmt = $pdo->prepare("SELECT tenant, username FROM company_credentials_devpos WHERE company_id = ?");
    $stmt->execute([$company['id']]);
    $devpos = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("SELECT realm_id FROM company_credentials_qbo WHERE company_id = ?");
    $stmt->execute([$company['id']]);
    $qbo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "DevPos: " . ($devpos ? "✓ Configured (Tenant: {$devpos['tenant']}, User: {$devpos['username']})" : "✗ Not configured") . "\n";
    echo "QuickBooks: " . ($qbo ? "✓ Connected (Realm: {$qbo['realm_id']})" : "✗ Not connected") . "\n";
    
    // Show recent sync jobs
    if ($company['completed_jobs'] > 0 || $company['failed_jobs'] > 0) {
        echo "\nRecent Sync Jobs:\n";
        $stmt = $pdo->prepare("
            SELECT 
                id, 
                job_type, 
                status, 
                from_date, 
                to_date,
                results_json,
                completed_at
            FROM sync_jobs 
            WHERE company_id = ? 
            ORDER BY created_at DESC 
            LIMIT 3
        ");
        $stmt->execute([$company['id']]);
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($jobs as $job) {
            echo "  [{$job['id']}] {$job['job_type']} | {$job['status']} | {$job['from_date']} to {$job['to_date']}";
            if ($job['results_json']) {
                $results = json_decode($job['results_json'], true);
                if ($results) {
                    if (isset($results['bills_created'])) {
                        echo " | Bills: {$results['bills_created']}/{$results['total']}";
                    }
                    if (isset($results['synced'])) {
                        echo " | Synced: {$results['synced']}/{$results['total']}";
                    }
                }
            }
            echo "\n";
        }
    }
    
    echo "\n" . str_repeat("=", 70) . "\n\n";
}

echo "\n📊 SUMMARY\n";
echo "Total companies: " . count($companies) . "\n";
echo "Companies with transactions: " . count(array_filter($companies, fn($c) => $c['synced_count'] > 0)) . "\n";
echo "Total synced transactions: " . array_sum(array_column($companies, 'synced_count')) . "\n";

echo "\n💡 TIP: If you're viewing the transactions page and see 'No transactions synced yet',\n";
echo "   use the company dropdown to switch to a company that has synced data.\n";
echo "   Current data: Company 2 (PGROUP INC) has 3 bills synced.\n\n";
