#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Try to load environment, but use defaults if not available
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
} catch (\Exception $e) {
    // .env not found, will use hardcoded defaults
}

// Get database connection (use hardcoded values for local testing)
$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_DATABASE'] ?? 'qbo_multicompany';
$dbUser = $_ENV['DB_USERNAME'] ?? 'root';
$dbPass = $_ENV['DB_PASSWORD'] ?? '';

$pdo = new PDO(
    "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
    $dbUser,
    $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "=== Testing Bills Sync ===\n\n";

// Get first active company
$stmt = $pdo->query("SELECT id, company_code, company_name FROM companies WHERE is_active = 1 LIMIT 1");
$company = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$company) {
    die("No active companies found\n");
}

echo "Company: {$company['company_name']} ({$company['company_code']})\n";

// Check credentials
$stmt = $pdo->prepare("SELECT tenant, username FROM company_credentials_devpos WHERE company_id = ?");
$stmt->execute([$company['id']]);
$devposCreds = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT realm_id FROM company_credentials_qbo WHERE company_id = ?");
$stmt->execute([$company['id']]);
$qboCreds = $stmt->fetch(PDO::FETCH_ASSOC);

echo "DevPos configured: " . ($devposCreds ? "✓ Yes (Tenant: {$devposCreds['tenant']})" : "✗ No") . "\n";
echo "QuickBooks configured: " . ($qboCreds ? "✓ Yes (Realm: {$qboCreds['realm_id']})" : "✗ No") . "\n";

if (!$devposCreds || !$qboCreds) {
    die("\nError: Both DevPos and QuickBooks must be configured for this company\n");
}

// Create a test sync job
$fromDate = date('Y-m-d', strtotime('-7 days'));
$toDate = date('Y-m-d');

echo "\nCreating bills sync job...\n";
echo "Date range: $fromDate to $toDate\n";

$stmt = $pdo->prepare("
    INSERT INTO sync_jobs (company_id, job_type, status, from_date, to_date, trigger_source, created_at)
    VALUES (?, 'bills', 'pending', ?, ?, 'manual', NOW())
");
$stmt->execute([$company['id'], $fromDate, $toDate]);
$jobId = (int)$pdo->lastInsertId();

echo "Job created with ID: $jobId\n";

// Execute the job
echo "\nExecuting sync job...\n";

try {
    $executor = new \App\Services\SyncExecutor($pdo);
    $result = $executor->executeJob($jobId);
    
    echo "\n=== SYNC RESULTS ===\n";
    echo "Success: " . ($result['success'] ? 'Yes' : 'No') . "\n";
    
    if ($result['success']) {
        echo "\nResults:\n";
        print_r($result['results']);
    } else {
        echo "\nError: " . ($result['error'] ?? 'Unknown error') . "\n";
    }
    
    // Check job status
    $stmt = $pdo->prepare("SELECT status, error_message, results_json FROM sync_jobs WHERE id = ?");
    $stmt->execute([$jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "\nJob Status: {$job['status']}\n";
    
    if ($job['error_message']) {
        echo "Error Message: {$job['error_message']}\n";
    }
    
    if ($job['results_json']) {
        echo "\nStored Results:\n";
        print_r(json_decode($job['results_json'], true));
    }
    
} catch (\Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString() . "\n";
}

echo "\n=== Test Complete ===\n";
