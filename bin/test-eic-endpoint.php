<?php
/**
 * Test DevPos EIC Endpoint for Currency Data
 *
 * This script tests the getEInvoiceByEIC endpoint to see:
 * 1. If it works at all
 * 2. What currency fields are returned
 */

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;

// Load environment
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Connect to database
$pdo = new PDO(
    "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']}",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$companyId = $argv[1] ?? 1;
$specificEic = $argv[2] ?? null;

echo "\n╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║            DevPos EIC Endpoint & Currency Field Test                        ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n\n";

// Get company and credentials
$stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$company) {
    echo "❌ Company ID {$companyId} not found\n";
    exit(1);
}

echo "📋 Company: {$company['name']} (ID: {$companyId})\n";

// Get DevPos token
$stmt = $pdo->prepare("SELECT access_token, tenant FROM oauth_tokens_devpos o
                       JOIN company_credentials_devpos c ON o.company_id = c.company_id
                       WHERE o.company_id = ?");
$stmt->execute([$companyId]);
$tokenData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tokenData) {
    // Try to get from credentials table
    $stmt = $pdo->prepare("SELECT tenant FROM company_credentials_devpos WHERE company_id = ?");
    $stmt->execute([$companyId]);
    $creds = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT access_token FROM oauth_tokens_devpos WHERE company_id = ?");
    $stmt->execute([$companyId]);
    $token = $stmt->fetchColumn();

    if (!$token) {
        echo "❌ No DevPos token found for company {$companyId}\n";
        exit(1);
    }

    $tokenData = ['access_token' => $token, 'tenant' => $creds['tenant'] ?? ''];
}

$token = $tokenData['access_token'];
$tenant = $tokenData['tenant'] ?? $company['tenant'] ?? '';

echo "🔐 Tenant: {$tenant}\n\n";

$client = new Client([
    'timeout' => 30,
    'verify' => false
]);

$apiBase = $_ENV['DEVPOS_API_BASE'] ?? 'https://online.devpos.al/api/v3';

// First, get a list of invoices to find an EIC to test
if (!$specificEic) {
    echo "📅 Fetching recent invoices to find an EIC to test...\n\n";

    $fromDate = date('Y-m-d', strtotime('-30 days'));
    $toDate = date('Y-m-d');

    try {
        $response = $client->get($apiBase . '/EInvoice/GetSalesInvoice', [
            'query' => ['fromDate' => $fromDate, 'toDate' => $toDate],
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'tenant' => $tenant,
                'Accept' => 'application/json'
            ]
        ]);

        $invoices = json_decode($response->getBody()->getContents(), true);

        if (!is_array($invoices) || count($invoices) === 0) {
            echo "⚠️  No invoices found in last 30 days\n";
            exit(1);
        }

        echo "✅ Found " . count($invoices) . " invoice(s)\n\n";

        // Show first 3 invoices and their currency from list API
        echo "📋 Invoices from List API:\n";
        echo str_repeat("-", 80) . "\n";
        printf("%-40s %-15s %-15s\n", "EIC", "DocNumber", "Currency");
        echo str_repeat("-", 80) . "\n";

        for ($i = 0; $i < min(5, count($invoices)); $i++) {
            $inv = $invoices[$i];
            $eic = $inv['eic'] ?? $inv['EIC'] ?? 'N/A';
            $docNum = $inv['documentNumber'] ?? $inv['DocNumber'] ?? 'N/A';
            $currency = $inv['currencyCode'] ?? $inv['currency'] ?? $inv['Currency'] ?? 'NOT SET';
            printf("%-40s %-15s %-15s\n", substr($eic, 0, 38), $docNum, $currency);
        }
        echo "\n";

        // Pick the first invoice to test
        $testInvoice = $invoices[0];
        $specificEic = $testInvoice['eic'] ?? $testInvoice['EIC'];

    } catch (Exception $e) {
        echo "❌ Error fetching invoices: " . $e->getMessage() . "\n";
        exit(1);
    }
}

if (!$specificEic) {
    echo "❌ No EIC available to test\n";
    exit(1);
}

echo "🔍 Testing EIC endpoint with: {$specificEic}\n\n";

// Try different endpoint variations
$endpoints = [
    [
        'method' => 'GET',
        'url' => $apiBase . '/EInvoice',
        'params' => ['EIC' => $specificEic]
    ],
    [
        'method' => 'GET',
        'url' => $apiBase . '/EInvoice/' . $specificEic,
        'params' => []
    ],
    [
        'method' => 'GET',
        'url' => $apiBase . '/EInvoice/GetByEIC',
        'params' => ['eic' => $specificEic]
    ],
    [
        'method' => 'GET',
        'url' => $apiBase . '/EInvoice/GetSalesInvoice/' . $specificEic,
        'params' => []
    ],
    [
        'method' => 'POST',
        'url' => $apiBase . '/EInvoice',
        'params' => ['EIC' => $specificEic]
    ]
];

foreach ($endpoints as $i => $endpoint) {
    echo "────────────────────────────────────────────────────────────────────────────────\n";
    echo "Test " . ($i + 1) . ": {$endpoint['method']} {$endpoint['url']}\n";
    echo "────────────────────────────────────────────────────────────────────────────────\n";

    try {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'tenant' => $tenant,
                'Accept' => 'application/json'
            ]
        ];

        if ($endpoint['method'] === 'GET' && !empty($endpoint['params'])) {
            $options['query'] = $endpoint['params'];
        } else if ($endpoint['method'] === 'POST') {
            $options['form_params'] = $endpoint['params'];
        }

        $response = $client->request($endpoint['method'], $endpoint['url'], $options);
        $status = $response->getStatusCode();
        $body = $response->getBody()->getContents();

        echo "Status: {$status}\n\n";

        if ($status >= 200 && $status < 300) {
            $data = json_decode($body, true);

            if (is_array($data)) {
                // If it's a list, get first item
                if (isset($data[0])) {
                    $data = $data[0];
                }

                echo "✅ SUCCESS! Response fields:\n\n";

                // Show all fields with their values
                echo "📋 ALL FIELDS:\n";
                echo str_repeat("-", 80) . "\n";

                foreach ($data as $key => $value) {
                    if (is_array($value)) {
                        echo "  {$key}: [array with " . count($value) . " items]\n";
                    } else {
                        $displayVal = $value;
                        if (is_string($value) && strlen($value) > 60) {
                            $displayVal = substr($value, 0, 57) . '...';
                        }
                        echo "  {$key}: {$displayVal}\n";
                    }
                }

                // Highlight currency-related fields
                echo "\n💰 CURRENCY-RELATED FIELDS:\n";
                echo str_repeat("-", 80) . "\n";

                $currencyKeywords = ['currency', 'monedh', 'exchange', 'rate', 'base', 'home', 'local', 'foreign'];
                $foundCurrencyFields = false;

                foreach ($data as $key => $value) {
                    $isRelevant = false;
                    foreach ($currencyKeywords as $keyword) {
                        if (stripos($key, $keyword) !== false) {
                            $isRelevant = true;
                            break;
                        }
                    }

                    if ($isRelevant) {
                        $foundCurrencyFields = true;
                        if (is_array($value)) {
                            echo "  ✅ {$key}: " . json_encode($value) . "\n";
                        } else {
                            echo "  ✅ {$key}: {$value}\n";
                        }
                    }
                }

                if (!$foundCurrencyFields) {
                    echo "  ⚠️  No currency-related fields found in response!\n";
                }

                // Check specific currency field names
                echo "\n🔍 CHECKING SPECIFIC CURRENCY FIELDS:\n";
                $currencyFields = [
                    'currency',
                    'Currency',
                    'currencyCode',
                    'CurrencyCode',
                    'vatCurrency',
                    'VATCurrency',
                    'baseCurrency',
                    'BaseCurrency',
                    'homeCurrency',
                    'localCurrency',
                    'exchangeRate',
                    'ExchangeRate',
                    'amountInBaseCurrency',
                    'amountInHomeCurrency',
                    'totalInBaseCurrency'
                ];

                foreach ($currencyFields as $field) {
                    $exists = isset($data[$field]);
                    $marker = $exists ? '✅' : '❌';
                    $value = $exists ? $data[$field] : 'NOT FOUND';
                    echo "  {$marker} {$field}: {$value}\n";
                }

                echo "\n";
                break; // Found a working endpoint
            } else {
                echo "⚠️  Response is not an array: " . substr($body, 0, 200) . "\n\n";
            }
        } else {
            echo "❌ Error: HTTP {$status}\n";
            echo "Response: " . substr($body, 0, 300) . "\n\n";
        }

    } catch (Exception $e) {
        echo "❌ Exception: " . $e->getMessage() . "\n\n";
    }
}

echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                                Test Complete                                 ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n\n";
