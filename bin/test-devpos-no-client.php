<?php
/**
 * DevPos API Connection Test - No Client Credentials
 * Tests authentication without client_id/client_secret
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
echo "  (No Client ID/Secret Required)\n";
echo "=================================================\n\n";

// Get credentials
$tenant = readline("Enter DevPos Tenant: ");
$username = readline("Enter DevPos Username: ");
$password = readline("Enter DevPos Password: ");

if (empty($tenant) || empty($username) || empty($password)) {
    die("✗ All credentials are required\n");
}

$tokenUrl = $_ENV['DEVPOS_TOKEN_URL'] ?? 'https://online.devpos.al/connect/token';
$apiBase = $_ENV['DEVPOS_API_BASE'] ?? 'https://online.devpos.al/api/v3';

echo "\nConfiguration:\n";
echo "  - Tenant: {$tenant}\n";
echo "  - Username: {$username}\n";
echo "  - Token URL: {$tokenUrl}\n";
echo "  - API Base: {$apiBase}\n\n";

$client = new Client([
    'timeout' => 30,
    'verify' => false,
    'http_errors' => false
]);

// Test different authentication methods WITHOUT client_id
$methods = [
    [
        'name' => 'Method 1: grant_type=password only',
        'params' => [
            'grant_type' => 'password',
            'username' => $tenant . '|' . $username,
            'password' => $password
        ]
    ],
    [
        'name' => 'Method 2: grant_type=password with scope=api',
        'params' => [
            'grant_type' => 'password',
            'username' => $tenant . '|' . $username,
            'password' => $password,
            'scope' => 'api'
        ]
    ],
    [
        'name' => 'Method 3: Separate tenant field',
        'params' => [
            'grant_type' => 'password',
            'tenant' => $tenant,
            'username' => $username,
            'password' => $password
        ]
    ],
    [
        'name' => 'Method 4: Resource Owner Password Flow',
        'params' => [
            'grant_type' => 'password',
            'username' => $tenant . '|' . $username,
            'password' => $password,
            'scope' => 'openid profile offline_access'
        ]
    ],
    [
        'name' => 'Method 5: Simple username (no pipe)',
        'params' => [
            'grant_type' => 'password',
            'username' => $username,
            'password' => $password
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
                echo "  Expires In: " . ($data['expires_in'] ?? 'unknown') . " seconds (" . round(($data['expires_in'] ?? 0)/3600, 1) . " hours)\n";
                
                if (isset($data['refresh_token'])) {
                    echo "  Refresh Token: Available\n";
                }
                
                $accessToken = $data['access_token'];
                
                // Test API call - Sales E-Invoices
                echo "\n  Testing Sales E-Invoices API...\n";
                echo "  " . str_repeat("-", 56) . "\n";
                
                $fromDate = date('Y-m-d', strtotime('-7 days')) . 'T00:00:00+02:00';
                $toDate = date('Y-m-d') . 'T23:59:59+02:00';
                
                echo "  Date Range: {$fromDate} to {$toDate}\n";
                
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
                        
                        echo "  ✓ Sales API Successful!\n";
                        echo "  Found {$count} invoices (Total: {$total})\n";
                        
                        if ($count > 0) {
                            echo "\n  Sample Invoice:\n";
                            $invoice = $apiData['items'][0];
                            echo "    EIC: " . ($invoice['eic'] ?? 'N/A') . "\n";
                            echo "    Document #: " . ($invoice['documentNumber'] ?? 'N/A') . "\n";
                            echo "    Date: " . ($invoice['issueDate'] ?? 'N/A') . "\n";
                            echo "    Amount: " . ($invoice['totalAmount'] ?? 'N/A') . " " . ($invoice['currency'] ?? '') . "\n";
                            echo "    Customer: " . ($invoice['buyerName'] ?? 'N/A') . "\n";
                        }
                    } else {
                        echo "  ⚠ Unexpected response structure\n";
                        echo "  Keys: " . implode(', ', array_keys($apiData)) . "\n";
                    }
                } else {
                    echo "  ✗ API call failed (HTTP {$apiStatus})\n";
                }
                
                // Test Purchase E-Invoices
                echo "\n  Testing Purchase E-Invoices API...\n";
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
                    
                    echo "  ✓ Purchase API accessible!\n";
                    echo "  Found {$purchaseCount} purchases (Total: {$purchaseTotal})\n";
                } else {
                    echo "  ⚠ Purchase API returned status {$purchaseStatus}\n";
                }
                
                echo "\n=================================================\n";
                echo "✓✓✓ DEVPOS CONNECTION SUCCESSFUL! ✓✓✓\n";
                echo "=================================================\n";
                echo "\nWorking Configuration:\n";
                echo "  Grant Type: password\n";
                echo "  Username Format: {$tenant}|{$username}\n";
                echo "  No client_id/client_secret required\n";
                echo "\nAuthentication Parameters:\n";
                foreach ($method['params'] as $key => $value) {
                    if ($key !== 'password') {
                        echo "  {$key}: {$value}\n";
                    }
                }
                echo "\nThis configuration should be used in DevPosClient.\n";
                
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
echo "1. Invalid credentials (tenant/username/password)\n";
echo "2. Account locked, expired, or not activated\n";
echo "3. Different authentication endpoint required\n";
echo "4. DevPos API might use a different auth mechanism\n";
echo "\nNext steps:\n";
echo "1. Verify credentials are correct\n";
echo "2. Check if account is active in DevPos dashboard\n";
echo "3. Contact DevPos support for API access\n";
echo "4. Review DevPos API documentation\n";
