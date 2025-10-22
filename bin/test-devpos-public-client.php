<?php
/**
 * DevPos API Connection Test - Public Client (common client_id values)
 * Tests with typical public OAuth client IDs used by APIs
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
echo "  (Testing Common Public Client IDs)\n";
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

// Test with common public client IDs
$clientIds = [
    'web-app',
    'mobile-app',
    'desktop-app',
    'api-client',
    'public-client',
    'devpos-api',
    'devpos-web',
    'devpos-mobile',
    'oauth-client',
    'pos-client',
    'erp-client',
    'integration',
    'api',
    'webapp'
];

foreach ($clientIds as $index => $clientId) {
    echo ($index + 1) . ". Testing client_id: '{$clientId}'\n";
    echo str_repeat("-", 60) . "\n";
    
    try {
        $response = $client->post($tokenUrl, [
            'form_params' => [
                'grant_type' => 'password',
                'client_id' => $clientId,
                'username' => $tenant . '|' . $username,
                'password' => $password,
                'scope' => 'api offline_access'
            ],
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
                echo "  Working client_id: '{$clientId}'\n";
                echo "  Access Token: " . substr($data['access_token'], 0, 50) . "...\n";
                echo "  Token Type: " . ($data['token_type'] ?? 'Bearer') . "\n";
                echo "  Expires In: " . ($data['expires_in'] ?? 'unknown') . " seconds\n";
                
                if (isset($data['refresh_token'])) {
                    echo "  Refresh Token: Available\n";
                }
                
                // Test API call
                $accessToken = $data['access_token'];
                echo "\n  Testing Sales E-Invoices API...\n";
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
                    $apiData = json_decode($apiResponse->getBody()->getContents(), true);
                    if (isset($apiData['items'])) {
                        $count = count($apiData['items']);
                        $total = $apiData['totalItems'] ?? $apiData['totalCount'] ?? $count;
                        echo "  ✓ API works! Found {$count} invoices (Total: {$total})\n";
                    }
                }
                
                echo "\n=================================================\n";
                echo "✓✓✓ DEVPOS CONNECTION SUCCESSFUL! ✓✓✓\n";
                echo "=================================================\n";
                echo "\nWorking Configuration:\n";
                echo "  client_id: '{$clientId}'\n";
                echo "  grant_type: password\n";
                echo "  username: {$tenant}|{$username}\n";
                echo "  scope: api offline_access\n";
                echo "\nAdd this to .env:\n";
                echo "DEVPOS_CLIENT_ID={$clientId}\n";
                echo "\nUse this in DevPosClient implementation.\n";
                
                exit(0);
            }
        } else {
            $data = json_decode($body, true);
            $error = $data['error'] ?? 'unknown';
            
            // Only show errors that are NOT invalid_client
            if ($error !== 'invalid_client') {
                echo "  ⚠ Different error: {$error}\n";
                if (isset($data['error_description'])) {
                    echo "  Description: {$data['error_description']}\n";
                }
            } else {
                echo "  ✗ Invalid client\n";
            }
        }
        
    } catch (Exception $e) {
        echo "  ✗ Exception: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
}

echo "=================================================\n";
echo "Summary: None of the common client IDs worked\n";
echo "=================================================\n\n";

echo "DevPos requires a registered OAuth client.\n\n";
echo "Options:\n";
echo "1. Contact DevPos support for API credentials:\n";
echo "   - Request OAuth client_id (and client_secret if required)\n";
echo "   - Ask for API documentation\n";
echo "   - URL: https://online.devpos.al\n\n";
echo "2. Check if you have:\n";
echo "   - DevPos API documentation\n";
echo "   - Developer account/portal\n";
echo "   - Existing working integration code\n\n";
echo "3. Ask DevPos if they provide:\n";
echo "   - Public API access\n";
echo "   - Partner/integrator credentials\n";
echo "   - Test environment\n";
