<?php
/**
 * DevPos API Authentication Test - Try Different Methods
 * Tests various authentication approaches
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

echo "=================================================\n";
echo "  DevPos API Authentication Methods Test\n";
echo "=================================================\n\n";

// Get credentials
$tenant = readline("Enter DevPos Tenant (e.g., K43128625A): ");
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
    'verify' => false
]);

// Try different authentication methods
$methods = [
    [
        'name' => 'Method 1: client_id=front (default)',
        'params' => [
            'grant_type' => 'password',
            'client_id' => 'front',
            'username' => $tenant . '|' . $username,
            'password' => $password
        ]
    ],
    [
        'name' => 'Method 2: client_id=web',
        'params' => [
            'grant_type' => 'password',
            'client_id' => 'web',
            'username' => $tenant . '|' . $username,
            'password' => $password
        ]
    ],
    [
        'name' => 'Method 3: client_id=api',
        'params' => [
            'grant_type' => 'password',
            'client_id' => 'api',
            'username' => $tenant . '|' . $username,
            'password' => $password
        ]
    ],
    [
        'name' => 'Method 4: No client_id',
        'params' => [
            'grant_type' => 'password',
            'username' => $tenant . '|' . $username,
            'password' => $password
        ]
    ],
    [
        'name' => 'Method 5: Separate tenant/username',
        'params' => [
            'grant_type' => 'password',
            'client_id' => 'front',
            'tenant' => $tenant,
            'username' => $username,
            'password' => $password
        ]
    ],
    [
        'name' => 'Method 6: client_id=devpos',
        'params' => [
            'grant_type' => 'password',
            'client_id' => 'devpos',
            'username' => $tenant . '|' . $username,
            'password' => $password
        ]
    ],
];

foreach ($methods as $index => $method) {
    echo "\n" . ($index + 1) . ". Testing: {$method['name']}\n";
    echo str_repeat("-", 60) . "\n";
    
    try {
        $response = $client->post($tokenUrl, [
            'form_params' => $method['params'],
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded'
            ]
        ]);
        
        $body = $response->getBody()->getContents();
        $data = json_decode($body, true);
        
        if (isset($data['access_token'])) {
            echo "✓ SUCCESS!\n";
            echo "  Access Token: " . substr($data['access_token'], 0, 30) . "...\n";
            echo "  Expires in: " . ($data['expires_in'] ?? 'unknown') . " seconds\n";
            
            // Save this configuration
            echo "\n=================================================\n";
            echo "✓✓✓ WORKING AUTHENTICATION METHOD FOUND! ✓✓✓\n";
            echo "=================================================\n";
            echo "Use these parameters:\n";
            foreach ($method['params'] as $key => $value) {
                if ($key !== 'password') {
                    echo "  {$key}: {$value}\n";
                }
            }
            echo "\nThis method should be used in your DevPosClient class.\n";
            exit(0);
        } else {
            echo "✗ No access token in response\n";
            echo "Response: " . json_encode($data) . "\n";
        }
        
    } catch (GuzzleHttp\Exception\ClientException $e) {
        $statusCode = $e->getCode();
        $errorBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'No response';
        $errorData = json_decode($errorBody, true);
        
        echo "✗ Failed (HTTP {$statusCode})\n";
        echo "  Error: " . ($errorData['error'] ?? $errorBody) . "\n";
        
    } catch (Exception $e) {
        echo "✗ Exception: " . $e->getMessage() . "\n";
    }
}

echo "\n=================================================\n";
echo "✗ No working authentication method found\n";
echo "=================================================\n";
echo "\nPlease check:\n";
echo "1. Credentials are correct\n";
echo "2. DevPos server is accessible\n";
echo "3. Tenant ID format is correct\n";
echo "4. Account is not locked or expired\n";
