<?php
/**
 * Diagnostic script to check available items in QuickBooks for invoices
 */

require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;

// Load environment
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Database connection
$pdo = new PDO(
    sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $_ENV['DB_HOST'] ?? 'localhost',
        $_ENV['DB_NAME'] ?? 'Xhelo_qbo_devpos'
    ),
    $_ENV['DB_USER'] ?? 'Xhelo_qbo_user',
    $_ENV['DB_PASS'] ?? 'Albania@2030',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

if (!isset($argv[1])) {
    echo "Usage: php check-qbo-items.php <company_id>\n";
    echo "Example: php check-qbo-items.php 36\n";
    exit(1);
}

$companyId = (int)$argv[1];

// Get QBO credentials
$stmt = $pdo->prepare("SELECT * FROM company_credentials_qbo WHERE company_id = ? LIMIT 1");
$stmt->execute([$companyId]);
$qboCreds = $stmt->fetch();

if (!$qboCreds) {
    echo "❌ No QuickBooks credentials found for company $companyId\n";
    exit(1);
}

echo "🔍 Checking QuickBooks items for Company $companyId\n\n";

try {
    $client = new Client(['timeout' => 15]);
    $isSandbox = (($_ENV['QBO_ENV'] ?? 'production') === 'sandbox');
    $baseUrl = $isSandbox 
        ? 'https://sandbox-quickbooks.api.intuit.com'
        : 'https://quickbooks.api.intuit.com';
    
    // Query for service items
    $query = "SELECT * FROM Item WHERE Type = 'Service' AND Active = true MAXRESULTS 100";
    
    echo "📊 Querying: $query\n\n";
    
    $response = $client->get($baseUrl . '/v3/company/' . $qboCreds['realm_id'] . '/query', [
        'query' => ['query' => $query],
        'headers' => [
            'Authorization' => 'Bearer ' . $qboCreds['access_token'],
            'Accept' => 'application/json'
        ]
    ]);
    
    $data = json_decode($response->getBody()->getContents(), true);
    
    if (empty($data['QueryResponse']['Item'])) {
        echo "⚠️  No service items found in QuickBooks\n";
        exit(0);
    }
    
    $items = $data['QueryResponse']['Item'];
    echo "✅ Found " . count($items) . " service items:\n\n";
    
    foreach ($items as $item) {
        echo "  • ID: {$item['Id']} - Name: {$item['Name']}\n";
        if (isset($item['Description'])) {
            echo "    Description: {$item['Description']}\n";
        }
        if (isset($item['UnitPrice'])) {
            echo "    Unit Price: {$item['UnitPrice']}\n";
        }
        if (isset($item['IncomeAccountRef'])) {
            echo "    Income Account: {$item['IncomeAccountRef']['name']} (ID: {$item['IncomeAccountRef']['value']})\n";
        }
        if (isset($item['Taxable'])) {
            echo "    Taxable: " . ($item['Taxable'] ? 'Yes' : 'No') . "\n";
        }
        echo "\n";
    }
    
    echo "💡 The first item (ID: {$items[0]['Id']}) will be used as default for invoices\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
