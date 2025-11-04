<?php
/**
 * Check what DevPos returns for a specific bill
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

if (!isset($argv[1]) || !isset($argv[2])) {
    echo "Usage: php check-devpos-bill.php <company_id> <bill_doc_number>\n";
    echo "Example: php check-devpos-bill.php 28 1308/2025\n";
    exit(1);
}

$companyId = (int)$argv[1];
$billDocNumber = $argv[2];

echo "🔍 Checking DevPos data for Bill: $billDocNumber (Company $companyId)\n\n";

// Get DevPos credentials
$stmt = $pdo->prepare("SELECT * FROM company_credentials_devpos WHERE company_id = ? LIMIT 1");
$stmt->execute([$companyId]);
$devposCreds = $stmt->fetch();

if (!$devposCreds) {
    echo "❌ No DevPos credentials found for company $companyId\n";
    exit(1);
}

try {
    $client = new Client(['timeout' => 30]);
    $apiBase = $_ENV['DEVPOS_API_BASE'] ?? 'https://online.devpos.al/api/v3';
    
    // Get token
    echo "🔐 Getting DevPos token...\n";
    $tokenResponse = $client->post($apiBase . '/Token', [
        'form_params' => [
            'username' => $devposCreds['username'],
            'password' => $devposCreds['password'],
            'grant_type' => 'password'
        ]
    ]);
    
    $tokenData = json_decode($tokenResponse->getBody()->getContents(), true);
    $token = $tokenData['access_token'];
    echo "✅ Token obtained\n\n";
    
    // Get purchase invoices
    echo "📋 Fetching purchase invoices...\n";
    $response = $client->get($apiBase . '/PurchaseEInvoice', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json'
        ],
        'query' => [
            'TenantID' => $devposCreds['tenant'],
            'fromDate' => '2025-01-01',
            'toDate' => '2025-12-31'
        ]
    ]);
    
    $bills = json_decode($response->getBody()->getContents(), true);
    
    // Find our bill
    $targetBill = null;
    foreach ($bills as $bill) {
        if (($bill['documentNumber'] ?? '') === $billDocNumber) {
            $targetBill = $bill;
            break;
        }
    }
    
    if (!$targetBill) {
        echo "❌ Bill $billDocNumber not found in DevPos list API\n";
        exit(1);
    }
    
    echo "✅ Found bill in list API\n\n";
    echo "=== BILL DATA FROM LIST API ===\n";
    echo json_encode($targetBill, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    echo "\n\n";
    
    // Check if we have EIC to fetch detailed invoice
    $eic = $targetBill['eic'] ?? $targetBill['EIC'] ?? null;
    
    if ($eic) {
        echo "🔍 Fetching detailed invoice for EIC: $eic\n\n";
        
        // Try to get detailed invoice
        $detailResponse = $client->get($apiBase . '/EInvoice/' . $eic, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'TenantID' => $devposCreds['tenant'],
                'Accept' => 'application/json'
            ]
        ]);
        
        $detailedBill = json_decode($detailResponse->getBody()->getContents(), true);
        
        if ($detailedBill && !empty($detailedBill)) {
            echo "✅ Found detailed invoice\n\n";
            echo "=== DETAILED INVOICE DATA ===\n";
            echo json_encode($detailedBill, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            echo "\n\n";
            
            // Highlight currency fields
            echo "=== CURRENCY FIELDS COMPARISON ===\n";
            echo "List API:\n";
            echo "  - currencyCode: " . ($targetBill['currencyCode'] ?? 'NOT SET') . "\n";
            echo "  - currency: " . ($targetBill['currency'] ?? 'NOT SET') . "\n";
            echo "  - amount: " . ($targetBill['amount'] ?? 'NOT SET') . "\n";
            echo "\nDetailed API:\n";
            echo "  - currencyCode: " . ($detailedBill['currencyCode'] ?? 'NOT SET') . "\n";
            echo "  - currency: " . ($detailedBill['currency'] ?? 'NOT SET') . "\n";
            echo "  - vatCurrency: " . ($detailedBill['vatCurrency'] ?? 'NOT SET') . "\n";
            echo "  - VATCurrency: " . ($detailedBill['VATCurrency'] ?? 'NOT SET') . "\n";
            echo "  - baseCurrency: " . ($detailedBill['baseCurrency'] ?? 'NOT SET') . "\n";
            echo "  - exchangeRate: " . ($detailedBill['exchangeRate'] ?? 'NOT SET') . "\n";
            echo "  - amount: " . ($detailedBill['amount'] ?? 'NOT SET') . "\n";
        } else {
            echo "⚠️  Detailed invoice returned empty\n";
        }
    } else {
        echo "⚠️  No EIC found, cannot fetch detailed invoice\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
