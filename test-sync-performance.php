<?php
/**
 * Test sync performance to identify bottlenecks
 */

require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$companyId = $argv[1] ?? 1;
$limit = $argv[2] ?? 5; // Test with first N invoices

$pdo = new PDO(
    "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS']
);

echo "⏱️  Performance Test for Sync Operations\n";
echo str_repeat('=', 80) . "\n\n";

// Timing function
function timeIt($label, $callback) {
    $start = microtime(true);
    $result = $callback();
    $elapsed = round((microtime(true) - $start) * 1000, 2);
    echo sprintf("%-50s %10s ms\n", $label, $elapsed);
    return [$result, $elapsed];
}

// 1. Get company credentials
[$company] = timeIt("1. Fetch company & credentials", function() use ($pdo, $companyId) {
    $stmt = $pdo->prepare("
        SELECT c.*, dc.tenant, dc.username 
        FROM companies c 
        LEFT JOIN company_credentials_devpos dc ON c.id = dc.company_id 
        WHERE c.id = ?
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
});

if (!$company) {
    echo "❌ Company not found\n";
    exit(1);
}

echo "\nCompany: {$company['company_name']}\n\n";

// 2. Get DevPos token
[$token] = timeIt("2. Fetch DevPos token", function() use ($pdo, $companyId) {
    $stmt = $pdo->prepare("SELECT access_token FROM oauth_tokens_devpos WHERE company_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['access_token'] ?? null;
});

if (!$token) {
    echo "❌ No DevPos token found\n";
    exit(1);
}

// 3. Fetch invoices from DevPos
$client = new Client();
$apiBase = $_ENV['DEVPOS_API_BASE'] ?? 'https://online.devpos.al/api/v3';
$fromDate = '2025-01-01';
$toDate = '2025-10-26';

echo "\n📅 Fetching invoices from $fromDate to $toDate...\n\n";

[$invoices, $fetchTime] = timeIt("3. DevPos API: GetSalesInvoice", function() use ($client, $apiBase, $token, $company, $fromDate, $toDate) {
    $response = $client->get($apiBase . '/EInvoice/GetSalesInvoice', [
        'query' => ['fromDate' => $fromDate, 'toDate' => $toDate],
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'tenant' => $company['tenant'],
            'Accept' => 'application/json'
        ]
    ]);
    return json_decode($response->getBody()->getContents(), true);
});

$totalInvoices = count($invoices);
echo "\n✅ Found $totalInvoices invoices\n";

if ($totalInvoices == 0) {
    echo "⚠️  No invoices to test\n";
    exit(0);
}

// 4. Get QBO credentials
[$qboCreds] = timeIt("4. Fetch QBO credentials", function() use ($pdo, $companyId) {
    $stmt = $pdo->prepare("SELECT * FROM company_credentials_qbo WHERE company_id = ?");
    $stmt->execute([$companyId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
});

if (!$qboCreds) {
    echo "❌ No QuickBooks credentials\n";
    exit(1);
}

// 5. Test processing first N invoices
$testLimit = min($limit, $totalInvoices);
echo "\n🔬 Testing processing for first $testLimit invoice(s)...\n\n";

$processTimes = [];

for ($i = 0; $i < $testLimit; $i++) {
    $invoice = $invoices[$i];
    $invoiceId = $invoice['eic'] ?? $invoice['documentNumber'] ?? "Invoice #" . ($i + 1);
    
    echo "Invoice $invoiceId:\n";
    
    // Time: Convert DevPos to QBO format
    [$qboInvoice, $convertTime] = timeIt("  - Convert to QBO format", function() use ($invoice, $companyId, $pdo) {
        // Simplified conversion - just measure structure building
        return [
            'Line' => array_map(function($line) {
                return [
                    'Amount' => $line['value'] ?? 0,
                    'Description' => $line['name'] ?? '',
                ];
            }, $invoice['items'] ?? [])
        ];
    });
    
    $processTimes[] = $convertTime;
    
    echo "\n";
}

echo str_repeat('=', 80) . "\n";
echo "📊 Performance Summary:\n\n";

echo sprintf("Total invoices available: %d\n", $totalInvoices);
echo sprintf("DevPos API fetch time: %d ms\n", $fetchTime);
echo sprintf("Average per invoice: %.2f ms\n", $fetchTime / $totalInvoices);
echo "\n";

if (count($processTimes) > 0) {
    $avgProcess = array_sum($processTimes) / count($processTimes);
    $totalEstimated = ($fetchTime + ($avgProcess * $totalInvoices)) / 1000;
    
    echo sprintf("Average processing time: %.2f ms per invoice\n", $avgProcess);
    echo sprintf("Estimated total time for all $totalInvoices invoices: %.1f seconds (%.1f minutes)\n", 
        $totalEstimated, $totalEstimated / 60);
}

echo "\n⚠️  Note: This doesn't include QuickBooks API calls which add significant time\n";
echo "   Each QBO API call typically takes 200-500ms\n";
echo "   For $totalInvoices invoices, expect 2-4 minutes additional for QBO API calls\n";

// 6. Estimate with QBO calls
if ($totalInvoices > 0) {
    $avgQboCallTime = 350; // ms average
    $totalWithQbo = ($fetchTime + ($avgProcess * $totalInvoices) + ($avgQboCallTime * $totalInvoices)) / 1000;
    
    echo "\n📈 Estimated TOTAL sync time (with QBO API): %.1f minutes\n";
    echo sprintf("   For %d invoices: %.1f minutes\n", $totalInvoices, $totalWithQbo / 60);
}
