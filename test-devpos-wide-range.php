<?php
/**
 * Test DevPos API with a wider date range
 */

require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$companyId = $argv[1] ?? 1;

// Database connection
$pdo = new PDO(
    "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS']
);

// Fetch company and token
$stmt = $pdo->prepare("
    SELECT c.*, dc.tenant 
    FROM companies c 
    LEFT JOIN company_credentials_devpos dc ON c.id = dc.company_id 
    WHERE c.id = ?
");
$stmt->execute([$companyId]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT access_token FROM oauth_tokens_devpos WHERE company_id = ? ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$companyId]);
$tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tokenRow || !$company) {
    echo "❌ Missing company or token\n";
    exit(1);
}

$token = $tokenRow['access_token'];
$client = new Client();
$apiBase = $_ENV['DEVPOS_API_BASE'] ?? 'https://online.devpos.al/api/v3';

// Test different date ranges
$dateRanges = [
    ['2025-01-01', '2025-10-26', 'Year 2025'],
    ['2024-01-01', '2024-12-31', 'Year 2024'],
    ['2023-01-01', '2023-12-31', 'Year 2023'],
    ['2022-01-01', '2025-10-26', 'Last 3 years'],
];

echo "Company: {$company['company_name']} (Tenant: {$company['tenant']})\n";
echo str_repeat('=', 80) . "\n\n";

foreach ($dateRanges as [$fromDate, $toDate, $label]) {
    echo "Testing: $label ($fromDate to $toDate)... ";
    
    try {
        $response = $client->get($apiBase . '/EInvoice/GetSalesInvoice', [
            'query' => ['fromDate' => $fromDate, 'toDate' => $toDate],
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'tenant' => $company['tenant'],
                'Accept' => 'application/json'
            ]
        ]);
        
        $invoices = json_decode($response->getBody()->getContents(), true);
        $count = is_array($invoices) ? count($invoices) : 0;
        
        if ($count > 0) {
            echo "✅ Found $count invoices!\n";
            echo "   First invoice date: " . ($invoices[0]['date'] ?? $invoices[0]['issueDate'] ?? 'N/A') . "\n";
            echo "   First invoice ID: " . ($invoices[0]['id'] ?? $invoices[0]['invoiceNumber'] ?? 'N/A') . "\n";
        } else {
            echo "❌ 0 invoices\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

echo "\n" . str_repeat('=', 80) . "\n";
echo "Testing Purchase Invoices (last 30 days)...\n";

try {
    $response = $client->get($apiBase . '/EInvoice/GetPurchaseInvoice', [
        'query' => ['fromDate' => date('Y-m-d', strtotime('-30 days')), 'toDate' => date('Y-m-d')],
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'tenant' => $company['tenant'],
            'Accept' => 'application/json'
        ]
    ]);
    
    $invoices = json_decode($response->getBody()->getContents(), true);
    $count = is_array($invoices) ? count($invoices) : 0;
    echo "Purchase invoices: $count\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n✅ Test completed\n";
