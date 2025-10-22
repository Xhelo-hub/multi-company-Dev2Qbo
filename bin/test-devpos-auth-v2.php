<?php
/**
 * DevPos API Authentication Test - With Client Secret
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
echo "  DevPos API Authentication Test v2\n";
echo "=================================================\n\n";

// Get credentials
$tenant = readline("Enter DevPos Tenant (e.g., K43128625A or M0141918I): ");
$username = readline("Enter DevPos Username: ");
$password = readline("Enter DevPos Password: ");

if (empty($tenant) || empty($username) || empty($password)) {
    die("✗ All credentials are required\n");
}

$tokenUrl = $_ENV['DEVPOS_TOKEN_URL'] ?? 'https://online.devpos.al/connect/token';
$apiBase = $_ENV['DEVPOS_API_BASE'] ?? 'https://online.devpos.al/api/v3';

echo "\nTesting with:\n";
echo "  - Tenant: {$tenant}\n";
echo "  - Username: {$username}\n";
echo "  - Token URL: {$tokenUrl}\n\n";

$client = new Client([
    'timeout' => 30,
    'verify' => false,
    'http_errors' => false // Don't throw exceptions on 4xx/5xx
]);

// Try with Authorization header (Basic Auth for client)
$methods = [
    [
        'name' => 'OAuth with client_secret',
        'params' => [
            'grant_type' => 'password',
            'client_id' => 'front',
            'client_secret' => 'secret', // Common default
            'username' => $tenant . '|' . $username,
            'password' => $password
        ],
        'headers' => [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded'
        ]
    ],
    [
        'name' => 'OAuth with scope',
        'params' => [
            'grant_type' => 'password',
            'client_id' => 'front',
            'scope' => 'api',
            'username' => $tenant . '|' . $username,
            'password' => $password
        ],
        'headers' => [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded'
        ]
    ],
    [
        'name' => 'Direct API key auth',
        'test_direct' => true
    ],
    [
        'name' => 'OAuth with Basic Auth header',
        'params' => [
            'grant_type' => 'password',
            'username' => $tenant . '|' . $username,
            'password' => $password
        ],
        'headers' => [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Authorization' => 'Basic ' . base64_encode('front:secret')
        ]
    ],
];

foreach ($methods as $index => $method) {
    echo "\n" . ($index + 1) . ". Testing: {$method['name']}\n";
    echo str_repeat("-", 60) . "\n";
    
    // Special test for direct API
    if (isset($method['test_direct'])) {
        echo "Testing direct API access without OAuth...\n";
        try {
            $response = $client->get($apiBase . '/sale-einvoices', [
                'query' => [
                    'from' => date('Y-m-d') . 'T00:00:00+02:00',
                    'to' => date('Y-m-d') . 'T23:59:59+02:00',
                    'page' => 1,
                    'size' => 1
                ],
                'headers' => [
                    'Accept' => 'application/json',
                    'X-Tenant' => $tenant,
                    'X-Username' => $username,
                    'X-Password' => $password
                ]
            ]);
            
            $statusCode = $response->getStatusCode();
            echo "  Response Code: {$statusCode}\n";
            
            if ($statusCode == 200) {
                echo "✓ SUCCESS with direct API!\n";
                $body = $response->getBody()->getContents();
                $data = json_decode($body, true);
                echo "  Response: " . json_encode($data, JSON_PRETTY_PRINT) . "\n";
                exit(0);
            } else {
                echo "✗ Failed (HTTP {$statusCode})\n";
                echo "  Response: " . $response->getBody()->getContents() . "\n";
            }
        } catch (Exception $e) {
            echo "✗ Exception: " . $e->getMessage() . "\n";
        }
        continue;
    }
    
    try {
        $response = $client->post($tokenUrl, [
            'form_params' => $method['params'],
            'headers' => $method['headers']
        ]);
        
        $statusCode = $response->getStatusCode();
        $body = $response->getBody()->getContents();
        $data = json_decode($body, true);
        
        echo "  Response Code: {$statusCode}\n";
        
        if ($statusCode == 200 && isset($data['access_token'])) {
            echo "✓ SUCCESS!\n";
            echo "  Access Token: " . substr($data['access_token'], 0, 30) . "...\n";
            echo "  Token Type: " . ($data['token_type'] ?? 'Bearer') . "\n";
            echo "  Expires in: " . ($data['expires_in'] ?? 'unknown') . " seconds\n";
            
            echo "\n=================================================\n";
            echo "✓✓✓ WORKING AUTHENTICATION METHOD FOUND! ✓✓✓\n";
            echo "=================================================\n";
            echo "Configuration to use:\n";
            foreach ($method['params'] as $key => $value) {
                if ($key !== 'password') {
                    echo "  {$key}: {$value}\n";
                }
            }
            
            // Test API call
            echo "\nTesting API call with token...\n";
            $testResponse = $client->get($apiBase . '/sale-einvoices', [
                'query' => [
                    'from' => date('Y-m-d', strtotime('-7 days')) . 'T00:00:00+02:00',
                    'to' => date('Y-m-d') . 'T23:59:59+02:00',
                    'page' => 1,
                    'size' => 1
                ],
                'headers' => [
                    'Authorization' => 'Bearer ' . $data['access_token'],
                    'Accept' => 'application/json'
                ]
            ]);
            
            if ($testResponse->getStatusCode() == 200) {
                echo "✓ API call successful!\n";
                $apiData = json_decode($testResponse->getBody()->getContents(), true);
                echo "  API Response: " . json_encode($apiData, JSON_PRETTY_PRINT) . "\n";
            }
            
            exit(0);
        } else {
            echo "✗ Failed\n";
            echo "  Response: " . $body . "\n";
        }
        
    } catch (Exception $e) {
        echo "✗ Exception: " . $e->getMessage() . "\n";
    }
}

echo "\n=================================================\n";
echo "✗ No working authentication method found\n";
echo "=================================================\n";
echo "\nThe DevPos API might require:\n";
echo "1. A specific client_id and client_secret pair\n";
echo "2. API registration or approval\n";
echo "3. Different authentication endpoint\n";
echo "4. Contact DevPos support for API access credentials\n";
