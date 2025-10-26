<?php
/**
 * Test script to fetch invoices from DevPos API
 * Usage: php test-devpos-api.php <company_id>
 */

require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Get company ID from command line
$companyId = $argv[1] ?? null;

if (!$companyId) {
    echo "Usage: php test-devpos-api.php <company_id>\n";
    exit(1);
}

// Database connection
$pdo = new PDO(
    "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS']
);

// Fetch company credentials
$stmt = $pdo->prepare("
    SELECT c.*, dc.tenant, dc.api_key, dc.api_secret 
    FROM companies c 
    LEFT JOIN company_credentials_devpos dc ON c.id = dc.company_id 
    WHERE c.id = ?
");
$stmt->execute([$companyId]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$company) {
    echo "❌ Company not found with ID: $companyId\n";
    exit(1);
}

if (!$company['tenant']) {
    echo "❌ No DevPos tenant configured for company: {$company['company_name']}\n";
    exit(1);
}

echo "✅ Company found: {$company['company_name']}\n";
echo "   Tenant: {$company['tenant']}\n\n";

// Fetch DevPos token
$stmt = $pdo->prepare("SELECT access_token FROM oauth_tokens_devpos WHERE company_id = ? ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$companyId]);
$tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tokenRow) {
    echo "❌ No DevPos token found for company\n";
    exit(1);
}

$token = $tokenRow['access_token'];
echo "✅ DevPos token found (length: " . strlen($token) . ")\n\n";

// Test API call
$client = new Client();
$apiBase = $_ENV['DEVPOS_API_BASE'] ?? 'https://online.devpos.al/api/v3';

// Date range (last 30 days)
$toDate = date('Y-m-d');
$fromDate = date('Y-m-d', strtotime('-30 days'));

echo "📅 Date range: $fromDate to $toDate\n";
echo "🌐 API endpoint: $apiBase/EInvoice/GetSalesInvoice\n\n";

try {
    echo "🔄 Fetching invoices from DevPos...\n\n";
    
    $response = $client->get($apiBase . '/EInvoice/GetSalesInvoice', [
        'query' => [
            'fromDate' => $fromDate,
            'toDate' => $toDate
        ],
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'tenant' => $company['tenant'],
            'Accept' => 'application/json'
        ]
    ]);
    
    $statusCode = $response->getStatusCode();
    echo "✅ HTTP Status: $statusCode\n\n";
    
    $body = $response->getBody()->getContents();
    echo "📦 Response (first 1000 chars):\n";
    echo str_repeat('-', 80) . "\n";
    echo substr($body, 0, 1000) . "\n";
    echo str_repeat('-', 80) . "\n\n";
    
    $invoices = json_decode($body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "❌ JSON decode error: " . json_last_error_msg() . "\n";
        exit(1);
    }
    
    if (!is_array($invoices)) {
        echo "⚠️  Response is not an array. Type: " . gettype($invoices) . "\n";
        echo "Response value: " . print_r($invoices, true) . "\n";
        exit(1);
    }
    
    echo "✅ Parsed " . count($invoices) . " invoices\n\n";
    
    if (count($invoices) > 0) {
        echo "📄 First invoice sample:\n";
        echo str_repeat('-', 80) . "\n";
        echo json_encode($invoices[0], JSON_PRETTY_PRINT) . "\n";
        echo str_repeat('-', 80) . "\n";
    } else {
        echo "⚠️  No invoices found in date range\n";
        echo "   Try expanding the date range or check if invoices exist in DevPos\n";
    }
    
} catch (\GuzzleHttp\Exception\ClientException $e) {
    echo "❌ DevPos API Client Error (4xx):\n";
    echo "   Status: " . $e->getResponse()->getStatusCode() . "\n";
    echo "   Message: " . $e->getMessage() . "\n";
    echo "   Response: " . $e->getResponse()->getBody()->getContents() . "\n";
    exit(1);
} catch (\GuzzleHttp\Exception\ServerException $e) {
    echo "❌ DevPos API Server Error (5xx):\n";
    echo "   Status: " . $e->getResponse()->getStatusCode() . "\n";
    echo "   Message: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✅ Test completed successfully!\n";
