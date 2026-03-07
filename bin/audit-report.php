<?php
/**
 * Generate a validation audit report for a company's synced records.
 *
 * Usage:
 *   php bin/audit-report.php <company_id> [fromDate] [toDate]
 *
 * Examples:
 *   php bin/audit-report.php 1
 *   php bin/audit-report.php 1 2024-01-01 2024-12-31
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Services\VerificationService;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$companyId = (int)($argv[1] ?? 0);
if (!$companyId) {
    die("Usage: php bin/audit-report.php <company_id> [fromDate] [toDate]\n");
}

$fromDate = $argv[2] ?? date('Y-m-01');
$toDate   = $argv[3] ?? date('Y-m-d');

$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',
        $_ENV['DB_HOST'] ?? 'localhost',
        $_ENV['DB_NAME'] ?? 'Xhelo_qbo_devpos'
    ),
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$verifier = new VerificationService($pdo);

echo "Generating audit report for company $companyId ($fromDate → $toDate)...\n\n";

$report = $verifier->generateAuditReport($companyId, $fromDate, $toDate);

// Print summary
$s = $report['summary'];
echo "=== SUMMARY ===\n";
echo "Invoices synced: {$s['invoices_synced']}\n";
echo "Bills synced:    {$s['bills_synced']}\n\n";

if ($s['invoices_synced'] > 0) {
    echo "=== INVOICES ===\n";
    foreach ($report['invoices'] as $inv) {
        echo "  devpos={$inv['devpos_doc']}  qbo={$inv['qbo_id']}  currency={$inv['currency']}  amount={$inv['amount']}  customer={$inv['customer']}  synced={$inv['synced_at']}\n";
    }
    echo "\n";
}

if ($s['bills_synced'] > 0) {
    echo "=== BILLS ===\n";
    foreach ($report['bills'] as $bill) {
        echo "  devpos={$bill['devpos_doc']}  qbo={$bill['qbo_id']}  currency={$bill['currency']}  amount={$bill['amount']}  vendor={$bill['vendor']}  synced={$bill['synced_at']}\n";
    }
    echo "\n";
}

echo "\nFull JSON report written to: audit-report-{$companyId}.json\n";
file_put_contents(
    __DIR__ . "/../audit-report-{$companyId}.json",
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);
