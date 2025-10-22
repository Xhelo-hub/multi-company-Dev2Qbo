<?php
/**
 * DevPos API Connection Test with Provided Client ID
 * Tests authentication with the actual client_id
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
echo "  With Client ID\n";
echo "=================================================\n\n";

// Get credentials
$tenant = readline("Enter DevPos Tenant: ");
$username = readline("Enter DevPos Username: ");
$password = readline("Enter DevPos Password: ");

if (empty($tenant) || empty($username) || empty($password)) {
    die("✗ All credentials are required\n");
}

$clientId = 'ABs2fOPcPjdvC7EwomUNalR9HgN5rTZX2H0mVZT9o7Vk7nLmeG';
$tokenUrl = $_ENV['DEVPOS_TOKEN_URL'] ?? 'https://online.devpos.al/connect/token';
$apiBase = $_ENV['DEVPOS_API_BASE'] ?? 'https://online.devpos.al/api/v3';

echo "\nConfiguration:\n";
echo "  - Tenant: {$tenant}\n";
echo "  - Username: {$username}\n";
echo "  - Client ID: {$clientId}\n";
echo "  - Token URL: {$tokenUrl}\n";
echo "  - API Base: {$apiBase}\n\n";

$client = new Client([
    'timeout' => 30,
    'verify' => false,
    'http_errors' => false
]);

// Test different authentication methods with the provided client_id
$methods = [
    [
        'name' => 'Method 1: client_id only',
        'params' => [
            'grant_type' => 'password',
            'client_id' => $clientId,
            'username' => $tenant . '|' . $username,
            'password' => $password
        ]
    ],
    [
        'name' => 'Method 2: client_id with scope',
        'params' => [
            'grant_type' => 'password',
            'client_id' => $clientId,
            'scope' => 'api',
            'username' => $tenant . '|' . $username,
            'password' => $password
        ]
    ],
    [
        'name' => 'Method 3: client_id with offline_access scope',
        'params' => [
            'grant_type' => 'password',
            'client_id' => $clientId,
            'scope' => 'offline_access',
            'username' => $tenant . '|' . $username,
            'password' => $password
        ]
    ],
    [
        'name' => 'Method 4: client_id without pipe separator',
        'params' => [
            'grant_type' => 'password',
            'client_id' => $clientId,
            'username' => $username,
            'password' => $password,
            'tenant' => $tenant
        ]
    ],
];

foreach ($methods as $index => $method) {
    echo ($index + 1) . ". Testing: {$method['name']}\n";
    echo str_repeat("-", 60) . "\n";
    
    try {
        $response = $client->post($tokenUrl, [
            'form_params' => $method['params'],
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded'
            ]
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
                    echo "  Refresh Token: " . substr($data['refresh_token'], 0, 40) . "...\n";
                }
                
                $accessToken = $data['access_token'];
                
                // Test API call
                echo "\n  Testing API Call...\n";
                echo "  " . str_repeat("-", 56) . "\n";
                
                $fromDate = date('Y-m-d', strtotime('-7 days')) . 'T00:00:00+02:00';
                $toDate = date('Y-m-d') . 'T23:59:59+02:00';
                
                $apiResponse = $client->get($apiBase . '/sale-einvoices', [
                    'query' => [
                        'from' => $fromDate,
                        'to' => $toDate,
                        'page' => 1,
                        'size' => 5
                    ],
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                        'Accept' => 'application/json'
                    ]
                ]);
                
                $apiStatus = $apiResponse->getStatusCode();
                echo "  API Status: {$apiStatus}\n";
                
                if ($apiStatus == 200) {
                    $apiBody = $apiResponse->getBody()->getContents();
                    $apiData = json_decode($apiBody, true);
                    
                    if (isset($apiData['items'])) {
                        $count = count($apiData['items']);
                        $total = $apiData['totalItems'] ?? $apiData['totalCount'] ?? $count;
                        
                        echo "  ✓ API Call Successful!\n";
                        echo "  Found {$count} invoices (Total: {$total})\n";
                        
                        if ($count > 0) {
                            echo "\n  Sample Invoice:\n";
                            $invoice = $apiData['items'][0];
                            echo "    EIC: " . ($invoice['eic'] ?? 'N/A') . "\n";
                            echo "    Document #: " . ($invoice['documentNumber'] ?? 'N/A') . "\n";
                            echo "    Date: " . ($invoice['issueDate'] ?? 'N/A') . "\n";
                            echo "    Amount: " . ($invoice['totalAmount'] ?? 'N/A') . "\n";
                            echo "    Customer: " . ($invoice['buyerName'] ?? 'N/A') . "\n";
                        }
                    } else {
                        echo "  ⚠ Unexpected API response structure\n";
                        echo "  Keys: " . implode(', ', array_keys($apiData)) . "\n";
                    }
                } else {
                    echo "  ✗ API call failed with status {$apiStatus}\n";
                    echo "  Response: " . substr($apiResponse->getBody()->getContents(), 0, 200) . "\n";
                }
                
                // Test purchase invoices
                echo "\n  Testing Purchase Invoices Endpoint...\n";
                echo "  " . str_repeat("-", 56) . "\n";
                
                $purchaseResponse = $client->get($apiBase . '/purchase-einvoices', [
                    'query' => [
                        'from' => $fromDate,
                        'to' => $toDate,
                        'page' => 1,
                        'size' => 5
                    ],
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                        'Accept' => 'application/json'
                    ]
                ]);
                
                $purchaseStatus = $purchaseResponse->getStatusCode();
                if ($purchaseStatus == 200) {
                    $purchaseData = json_decode($purchaseResponse->getBody()->getContents(), true);
                    $purchaseCount = isset($purchaseData['items']) ? count($purchaseData['items']) : 0;
                    $purchaseTotal = $purchaseData['totalItems'] ?? $purchaseData['totalCount'] ?? $purchaseCount;
                    
                    echo "  ✓ Purchase endpoint accessible!\n";
                    echo "  Found {$purchaseCount} purchases (Total: {$purchaseTotal})\n";
                } else {
                    echo "  ⚠ Purchase endpoint returned status {$purchaseStatus}\n";
                }
                
                echo "\n=================================================\n";
                echo "✓✓✓ DEVPOS CONNECTION SUCCESSFUL! ✓✓✓\n";
                echo "=================================================\n";
                echo "\nWorking Configuration:\n";
                echo "  Client ID: {$clientId}\n";
                echo "  Grant Type: password\n";
                echo "  Username Format: {tenant}|{username}\n";
                echo "\nYou can now use this configuration in your\n";
                echo "DevPosClient implementation.\n";
                
                exit(0);
            } else {
                echo "  ✗ No access_token in response\n";
                echo "  Response: {$body}\n";
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
echo "Summary\n";
echo "=================================================\n";
echo "None of the tested methods worked.\n\n";
echo "Possible issues:\n";
echo "1. Client ID requires a client_secret\n";
echo "2. Invalid credentials (tenant/username/password)\n";
echo "3. Account locked or expired\n";
echo "4. Different authentication flow required\n";
echo "\nPlease verify:\n";
echo "- Credentials are correct\n";
echo "- Account is active\n";
echo "- Check if client_secret is needed\n";
