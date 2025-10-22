<?php
/**
 * DevPos API Connection Test with Client ID and Secret
 * Tests authentication with client credentials
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

echo "=================================================\n";
echo "  DevPos API Connection Test\n";
echo "  With Client ID & Secret\n";
echo "=================================================\n\n";

// Get credentials
$tenant = readline("Enter DevPos Tenant: ");
$username = readline("Enter DevPos Username: ");
$password = readline("Enter DevPos Password: ");

if (empty($tenant) || empty($username) || empty($password)) {
    die("✗ All credentials are required\n");
}

$clientId = 'ABs2fOPcPjdvC7EwomUNalR9HgN5rTZX2H0mVZT9o7Vk7nLmeG';
$clientSecret = 'QxGvji4ELImKTjrOUGR44qR9bD7nnlWXKlaNsyAt';
$tokenUrl = $_ENV['DEVPOS_TOKEN_URL'] ?? 'https://online.devpos.al/connect/token';
$apiBase = $_ENV['DEVPOS_API_BASE'] ?? 'https://online.devpos.al/api/v3';

echo "\nConfiguration:\n";
echo "  - Tenant: {$tenant}\n";
echo "  - Username: {$username}\n";
echo "  - Client ID: " . substr($clientId, 0, 20) . "...\n";
echo "  - Client Secret: " . substr($clientSecret, 0, 10) . "...\n";
echo "  - Token URL: {$tokenUrl}\n";
echo "  - API Base: {$apiBase}\n\n";

$client = new Client([
    'timeout' => 30,
    'verify' => false,
    'http_errors' => false
]);

// Test different authentication methods
$methods = [
    [
        'name' => 'Method 1: client_id + client_secret in body',
        'params' => [
            'grant_type' => 'password',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'username' => $tenant . '|' . $username,
            'password' => $password
        ],
        'headers' => [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded'
        ]
    ],
    [
        'name' => 'Method 2: Basic Auth header (client credentials)',
        'params' => [
            'grant_type' => 'password',
            'username' => $tenant . '|' . $username,
            'password' => $password
        ],
        'headers' => [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret)
        ]
    ],
    [
        'name' => 'Method 3: client_secret with scope',
        'params' => [
            'grant_type' => 'password',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => 'api offline_access',
            'username' => $tenant . '|' . $username,
            'password' => $password
        ],
        'headers' => [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded'
        ]
    ],
    [
        'name' => 'Method 4: Without pipe separator',
        'params' => [
            'grant_type' => 'password',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'username' => $username,
            'password' => $password,
            'tenant' => $tenant
        ],
        'headers' => [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded'
        ]
    ],
];

foreach ($methods as $index => $method) {
    echo ($index + 1) . ". Testing: {$method['name']}\n";
    echo str_repeat("-", 60) . "\n";
    
    try {
        $response = $client->post($tokenUrl, [
            'form_params' => $method['params'],
            'headers' => $method['headers']
        ]);
        
        $statusCode = $response->getStatusCode();
        $body = $response->getBody()->getContents();
        
        echo "  Status Code: {$statusCode}\n";
        
        if ($statusCode == 200) {
            $data = json_decode($body, true);
            
            if (isset($data['access_token'])) {
                echo "  ✓✓✓ SUCCESS! ✓✓✓\n";
                echo "  Access Token: " . substr($data['access_token'], 0, 40) . "...\n";
                echo "  Token Type: " . ($data['token_type'] ?? 'Bearer') . "\n";
                echo "  Expires In: " . ($data['expires_in'] ?? 'unknown') . " seconds\n";
                
                if (isset($data['refresh_token'])) {
                    echo "  Refresh Token: Yes\n";
                }
                
                $accessToken = $data['access_token'];
                
                // Test API calls
                echo "\n  Testing API Endpoints...\n";
                echo "  " . str_repeat("-", 56) . "\n";
                
                $fromDate = date('Y-m-d', strtotime('-7 days')) . 'T00:00:00+02:00';
                $toDate = date('Y-m-d') . 'T23:59:59+02:00';
                
                // Test 1: Sales E-Invoices
                echo "  1. Sales E-Invoices: ";
                $salesResponse = $client->get($apiBase . '/sale-einvoices', [
                    'query' => ['from' => $fromDate, 'to' => $toDate, 'page' => 1, 'size' => 5],
                    'headers' => ['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json']
                ]);
                
                if ($salesResponse->getStatusCode() == 200) {
                    $salesData = json_decode($salesResponse->getBody()->getContents(), true);
                    $count = isset($salesData['items']) ? count($salesData['items']) : 0;
                    $total = $salesData['totalItems'] ?? $salesData['totalCount'] ?? $count;
                    echo "✓ ({$count}/{$total} invoices)\n";
                    
                    if ($count > 0) {
                        $invoice = $salesData['items'][0];
                        echo "     Sample: EIC=" . ($invoice['eic'] ?? 'N/A') . 
                             ", Amount=" . ($invoice['totalAmount'] ?? 'N/A') . "\n";
                    }
                } else {
                    echo "✗ (HTTP " . $salesResponse->getStatusCode() . ")\n";
                }
                
                // Test 2: Purchase E-Invoices
                echo "  2. Purchase E-Invoices: ";
                $purchaseResponse = $client->get($apiBase . '/purchase-einvoices', [
                    'query' => ['from' => $fromDate, 'to' => $toDate, 'page' => 1, 'size' => 5],
                    'headers' => ['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json']
                ]);
                
                if ($purchaseResponse->getStatusCode() == 200) {
                    $purchaseData = json_decode($purchaseResponse->getBody()->getContents(), true);
                    $pCount = isset($purchaseData['items']) ? count($purchaseData['items']) : 0;
                    $pTotal = $purchaseData['totalItems'] ?? $purchaseData['totalCount'] ?? $pCount;
                    echo "✓ ({$pCount}/{$pTotal} purchases)\n";
                } else {
                    echo "✗ (HTTP " . $purchaseResponse->getStatusCode() . ")\n";
                }
                
                // Test 3: Cash Sales
                echo "  3. Cash Sales: ";
                $cashResponse = $client->get($apiBase . '/cash-sales', [
                    'query' => ['from' => $fromDate, 'to' => $toDate, 'page' => 1, 'size' => 5],
                    'headers' => ['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json']
                ]);
                
                if ($cashResponse->getStatusCode() == 200) {
                    $cashData = json_decode($cashResponse->getBody()->getContents(), true);
                    $cCount = isset($cashData['items']) ? count($cashData['items']) : 0;
                    $cTotal = $cashData['totalItems'] ?? $cashData['totalCount'] ?? $cCount;
                    echo "✓ ({$cCount}/{$cTotal} sales)\n";
                } else {
                    echo "✗ (HTTP " . $cashResponse->getStatusCode() . ")\n";
                }
                
                echo "\n=================================================\n";
                echo "✓✓✓ DEVPOS CONNECTION SUCCESSFUL! ✓✓✓\n";
                echo "=================================================\n";
                echo "\nWorking Authentication Configuration:\n";
                echo "-------------------------------------\n";
                echo "Grant Type: password\n";
                echo "Client ID: {$clientId}\n";
                echo "Client Secret: {$clientSecret}\n";
                echo "Username Format: {tenant}|{username}\n";
                echo "\nRequest Parameters:\n";
                foreach ($method['params'] as $key => $value) {
                    if (!in_array($key, ['password', 'client_secret'])) {
                        echo "  {$key}: {$value}\n";
                    }
                }
                echo "\nThis configuration should be used in DevPosClient!\n";
                
                exit(0);
            } else {
                echo "  ✗ No access_token in response\n";
                echo "  Response: " . substr($body, 0, 200) . "\n";
            }
        } else {
            $data = json_decode($body, true);
            $error = $data['error'] ?? 'unknown';
            $errorDesc = $data['error_description'] ?? '';
            
            echo "  ✗ Failed\n";
            echo "  Error: {$error}\n";
            if ($errorDesc) {
                echo "  Description: {$errorDesc}\n";
            }
        }
        
    } catch (Exception $e) {
        echo "  ✗ Exception: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
}

echo "=================================================\n";
echo "Connection Test Failed\n";
echo "=================================================\n";
echo "\nThe provided client_id and client_secret do not work\n";
echo "with the DevPos API. These credentials appear to be\n";
echo "for QuickBooks OAuth, not DevPos.\n\n";
echo "DevPos likely uses different OAuth credentials.\n";
echo "Please check:\n";
echo "1. DevPos API documentation\n";
echo "2. DevPos developer portal/account\n";
echo "3. Contact DevPos support for API credentials\n";
echo "4. Check existing working code for DevPos credentials\n";
