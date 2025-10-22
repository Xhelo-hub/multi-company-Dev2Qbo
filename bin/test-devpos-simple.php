<?php
/**
 * Simple DevPos API Connection Test (No Database Required)
 * Tests authentication and basic API connectivity
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

echo "=================================================\n";
echo "  DevPos API Connection Test (Simple)\n";
echo "=================================================\n\n";

// Get credentials from user input or use defaults
$tenant = readline("Enter DevPos Tenant (e.g., K43128625A): ");
$username = readline("Enter DevPos Username: ");
$password = readline("Enter DevPos Password: ");

if (empty($tenant) || empty($username) || empty($password)) {
    die("✗ All credentials are required\n");
}

$tokenUrl = $_ENV['DEVPOS_TOKEN_URL'] ?? 'https://online.devpos.al/connect/token';
$apiBase = $_ENV['DEVPOS_API_BASE'] ?? 'https://online.devpos.al/api/v3';

echo "\n";
echo "Testing with:\n";
echo "  - Tenant: {$tenant}\n";
echo "  - Username: {$username}\n";
echo "  - Token URL: {$tokenUrl}\n";
echo "  - API Base: {$apiBase}\n\n";

// Test authentication
echo "Testing DevPos Authentication...\n";
echo "----------------------------------------\n";

$client = new Client([
    'timeout' => 30,
    'verify' => false // Disable SSL verification for testing
]);

try {
    // Authenticate
    echo "Sending authentication request...\n";
    $response = $client->post($tokenUrl, [
        'form_params' => [
            'grant_type' => 'password',
            'client_id' => 'front',
            'username' => $tenant . '|' . $username,
            'password' => $password
        ],
        'headers' => [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded'
        ]
    ]);
    
    $body = $response->getBody()->getContents();
    $data = json_decode($body, true);
    
    if (!isset($data['access_token'])) {
        echo "✗ Authentication failed - no access token in response\n";
        echo "Response:\n";
        print_r($data);
        exit(1);
    }
    
    $accessToken = $data['access_token'];
    $expiresIn = $data['expires_in'] ?? 'unknown';
    
    echo "✓ Authentication successful!\n";
    echo "  - Access Token: " . substr($accessToken, 0, 30) . "...\n";
    echo "  - Token Type: " . ($data['token_type'] ?? 'Bearer') . "\n";
    echo "  - Expires in: {$expiresIn} seconds (" . round($expiresIn/3600, 2) . " hours)\n\n";
    
    // Test API call - Get sales e-invoices
    echo "Testing API - Fetching recent sales invoices...\n";
    echo "----------------------------------------\n";
    
    $fromDate = date('Y-m-d', strtotime('-7 days')) . 'T00:00:00+02:00';
    $toDate = date('Y-m-d') . 'T23:59:59+02:00';
    
    echo "Date range: {$fromDate} to {$toDate}\n\n";
    
    $apiResponse = $client->get($apiBase . '/sale-einvoices', [
        'query' => [
            'from' => $fromDate,
            'to' => $toDate,
            'page' => 1,
            'size' => 5 // Just get first 5 for testing
        ],
        'headers' => [
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json'
        ]
    ]);
    
    $apiBody = $apiResponse->getBody()->getContents();
    $apiData = json_decode($apiBody, true);
    
    echo "✓ API call successful!\n";
    echo "  - Status Code: " . $apiResponse->getStatusCode() . "\n";
    
    if (isset($apiData['items'])) {
        $count = count($apiData['items']);
        $total = $apiData['totalItems'] ?? $apiData['totalCount'] ?? $count;
        echo "  - Found {$count} invoices in response (Total: {$total})\n\n";
        
        if ($count > 0) {
            echo "Sample Invoice Details:\n";
            echo "----------------------------------------\n";
            $invoice = $apiData['items'][0];
            
            // Display all available fields
            foreach ($invoice as $key => $value) {
                if (is_array($value)) {
                    echo "  {$key}: " . json_encode($value) . "\n";
                } else {
                    echo "  {$key}: {$value}\n";
                }
            }
        }
    } else {
        echo "  - No items found or different response structure\n";
        echo "  - Response keys: " . implode(', ', array_keys($apiData)) . "\n";
        echo "  - Full response:\n";
        echo json_encode($apiData, JSON_PRETTY_PRINT);
    }
    
    // Test purchase e-invoices endpoint
    echo "\n\nTesting Purchase E-Invoices Endpoint...\n";
    echo "----------------------------------------\n";
    
    try {
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
        
        $purchaseBody = $purchaseResponse->getBody()->getContents();
        $purchaseData = json_decode($purchaseBody, true);
        
        echo "✓ Purchase endpoint accessible!\n";
        $purchaseCount = isset($purchaseData['items']) ? count($purchaseData['items']) : 0;
        $purchaseTotal = $purchaseData['totalItems'] ?? $purchaseData['totalCount'] ?? $purchaseCount;
        echo "  - Found {$purchaseCount} purchases (Total: {$purchaseTotal})\n";
        
    } catch (Exception $e) {
        echo "⚠ Purchase endpoint: " . $e->getMessage() . "\n";
    }
    
    echo "\n=================================================\n";
    echo "✓ DevPos connection test PASSED!\n";
    echo "  Server: {$apiBase}\n";
    echo "  Tenant: {$tenant}\n";
    echo "  Status: Connected and authenticated ✓\n";
    echo "=================================================\n";
    
} catch (GuzzleHttp\Exception\ClientException $e) {
    echo "✗ HTTP Client Error (" . $e->getCode() . "): " . $e->getMessage() . "\n";
    if ($e->hasResponse()) {
        $errorBody = $e->getResponse()->getBody()->getContents();
        echo "\nServer Response:\n";
        echo $errorBody . "\n";
        
        // Try to parse as JSON for better formatting
        $errorData = json_decode($errorBody, true);
        if ($errorData) {
            echo "\nParsed Error:\n";
            print_r($errorData);
        }
    }
    exit(1);
} catch (GuzzleHttp\Exception\ServerException $e) {
    echo "✗ Server Error (" . $e->getCode() . "): " . $e->getMessage() . "\n";
    if ($e->hasResponse()) {
        $errorBody = $e->getResponse()->getBody()->getContents();
        echo "Response:\n" . $errorBody . "\n";
    }
    exit(1);
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "Type: " . get_class($e) . "\n";
    exit(1);
}
