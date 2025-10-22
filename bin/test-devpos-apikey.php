<?php
/**
 * DevPos API Connection Test - API Key Authentication
 * Tests if DevPos uses API Key instead of OAuth
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
echo "  (API Key Authentication)\n";
echo "=================================================\n\n";

// Get credentials
$tenant = readline("Enter DevPos Tenant: ");
$username = readline("Enter DevPos Username: ");
$password = readline("Enter DevPos Password: ");
$apiKey = readline("Enter API Key (if you have one, or press Enter to skip): ");

if (empty($tenant) || empty($username) || empty($password)) {
    die("✗ Tenant, username, and password are required\n");
}

$apiBase = $_ENV['DEVPOS_API_BASE'] ?? 'https://online.devpos.al/api/v3';

echo "\nConfiguration:\n";
echo "  - Tenant: {$tenant}\n";
echo "  - Username: {$username}\n";
echo "  - API Base: {$apiBase}\n";
echo "  - Has API Key: " . (empty($apiKey) ? 'No' : 'Yes') . "\n\n";

$client = new Client([
    'timeout' => 30,
    'verify' => false,
    'http_errors' => false
]);

$fromDate = date('Y-m-d', strtotime('-7 days')) . 'T00:00:00+02:00';
$toDate = date('Y-m-d') . 'T23:59:59+02:00';

echo "Testing Date Range: {$fromDate} to {$toDate}\n\n";

// Test different API Key authentication methods
$methods = [
    [
        'name' => 'Method 1: X-API-Key header',
        'headers' => [
            'X-API-Key' => $apiKey ?: 'test-key',
            'Accept' => 'application/json'
        ]
    ],
    [
        'name' => 'Method 2: API-Key header',
        'headers' => [
            'API-Key' => $apiKey ?: 'test-key',
            'Accept' => 'application/json'
        ]
    ],
    [
        'name' => 'Method 3: Authorization: ApiKey header',
        'headers' => [
            'Authorization' => 'ApiKey ' . ($apiKey ?: 'test-key'),
            'Accept' => 'application/json'
        ]
    ],
    [
        'name' => 'Method 4: Basic Auth (username:password)',
        'auth' => [$tenant . '|' . $username, $password],
        'headers' => [
            'Accept' => 'application/json'
        ]
    ],
    [
        'name' => 'Method 5: Basic Auth with API Key',
        'auth' => [$apiKey ?: 'test-key', ''],
        'headers' => [
            'Accept' => 'application/json'
        ]
    ],
    [
        'name' => 'Method 6: API Key as query parameter',
        'headers' => [
            'Accept' => 'application/json'
        ],
        'query_key' => 'api_key'
    ],
    [
        'name' => 'Method 7: API Key as apiKey parameter',
        'headers' => [
            'Accept' => 'application/json'
        ],
        'query_key' => 'apiKey'
    ],
    [
        'name' => 'Method 8: Tenant/Username/Password headers',
        'headers' => [
            'X-Tenant' => $tenant,
            'X-Username' => $username,
            'X-Password' => $password,
            'Accept' => 'application/json'
        ]
    ],
    [
        'name' => 'Method 9: Custom DevPos headers',
        'headers' => [
            'DevPos-Tenant' => $tenant,
            'DevPos-User' => $username,
            'DevPos-Pass' => $password,
            'Accept' => 'application/json'
        ]
    ],
    [
        'name' => 'Method 10: Bearer token (user credentials)',
        'headers' => [
            'Authorization' => 'Bearer ' . base64_encode("{$tenant}|{$username}:{$password}"),
            'Accept' => 'application/json'
        ]
    ],
];

foreach ($methods as $index => $method) {
    echo ($index + 1) . ". Testing: {$method['name']}\n";
    echo str_repeat("-", 60) . "\n";
    
    try {
        $options = [
            'headers' => $method['headers']
        ];
        
        // Add Basic Auth if specified
        if (isset($method['auth'])) {
            $options['auth'] = $method['auth'];
        }
        
        // Build query parameters
        $queryParams = [
            'from' => $fromDate,
            'to' => $toDate,
            'page' => 1,
            'size' => 5
        ];
        
        // Add API key to query if specified
        if (isset($method['query_key']) && !empty($apiKey)) {
            $queryParams[$method['query_key']] = $apiKey;
        }
        
        $options['query'] = $queryParams;
        
        // Try sales-einvoices endpoint
        $response = $client->get($apiBase . '/sale-einvoices', $options);
        
        $statusCode = $response->getStatusCode();
        $body = $response->getBody()->getContents();
        $contentType = $response->getHeaderLine('Content-Type');
        
        echo "  Status Code: {$statusCode}\n";
        echo "  Content-Type: {$contentType}\n";
        
        if ($statusCode == 200) {
            // Check if response is JSON
            if (strpos($contentType, 'application/json') !== false) {
                $data = json_decode($body, true);
                
                if (isset($data['items'])) {
                    $count = count($data['items']);
                    $total = $data['totalItems'] ?? $data['totalCount'] ?? $count;
                    
                    echo "  ✓✓✓ SUCCESS! ✓✓✓\n";
                    echo "  Found {$count} invoices (Total: {$total})\n";
                    
                    if ($count > 0) {
                        echo "\n  Sample Invoice:\n";
                        $invoice = $data['items'][0];
                        echo "    EIC: " . ($invoice['eic'] ?? 'N/A') . "\n";
                        echo "    Document #: " . ($invoice['documentNumber'] ?? 'N/A') . "\n";
                        echo "    Date: " . ($invoice['issueDate'] ?? 'N/A') . "\n";
                        echo "    Amount: " . ($invoice['totalAmount'] ?? 'N/A') . "\n";
                    }
                    
                    echo "\n=================================================\n";
                    echo "✓✓✓ DEVPOS CONNECTION SUCCESSFUL! ✓✓✓\n";
                    echo "=================================================\n";
                    echo "\nWorking Configuration:\n";
                    echo "  Method: {$method['name']}\n";
                    echo "  Endpoint: {$apiBase}/sale-einvoices\n";
                    
                    if (isset($method['auth'])) {
                        echo "  Auth Type: Basic Authentication\n";
                        echo "  Username: {$method['auth'][0]}\n";
                    }
                    
                    echo "\nHeaders:\n";
                    foreach ($method['headers'] as $key => $value) {
                        if (!in_array($key, ['X-Password', 'DevPos-Pass'])) {
                            echo "  {$key}: {$value}\n";
                        }
                    }
                    
                    echo "\nThis configuration should be used in DevPosClient.\n";
                    exit(0);
                    
                } elseif (isset($data['error'])) {
                    echo "  ✗ API Error: {$data['error']}\n";
                    if (isset($data['error_description'])) {
                        echo "  Description: {$data['error_description']}\n";
                    }
                } else {
                    echo "  ⚠ Unexpected JSON response\n";
                    echo "  Keys: " . implode(', ', array_keys($data)) . "\n";
                }
            } else {
                // HTML response - likely login page
                if (strpos($body, 'login') !== false || strpos($body, '<form') !== false) {
                    echo "  ✗ Redirected to login page (authentication failed)\n";
                } else {
                    echo "  ⚠ Non-JSON response: " . substr($body, 0, 100) . "...\n";
                }
            }
        } elseif ($statusCode == 401) {
            echo "  ✗ Unauthorized (401) - Invalid credentials or API key\n";
            $data = @json_decode($body, true);
            if ($data && isset($data['message'])) {
                echo "  Message: {$data['message']}\n";
            }
        } elseif ($statusCode == 403) {
            echo "  ✗ Forbidden (403) - Access denied\n";
        } elseif ($statusCode == 404) {
            echo "  ✗ Not Found (404) - Wrong endpoint?\n";
        } else {
            echo "  ✗ Failed\n";
            $data = @json_decode($body, true);
            if ($data && isset($data['error'])) {
                echo "  Error: {$data['error']}\n";
            }
        }
        
    } catch (Exception $e) {
        echo "  ✗ Exception: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
}

echo "=================================================\n";
echo "Summary: None of the tested methods worked\n";
echo "=================================================\n\n";

echo "Next steps:\n";
echo "1. Check DevPos dashboard for API settings:\n";
echo "   - Log into https://online.devpos.al\n";
echo "   - Look for 'API', 'Integrations', or 'Settings' section\n";
echo "   - Check if there's an API key or OAuth credentials\n\n";
echo "2. Contact DevPos support:\n";
echo "   - Ask: 'How do I authenticate with the REST API?'\n";
echo "   - Ask: 'Do you provide API key or OAuth credentials?'\n";
echo "   - Request: API documentation\n\n";
echo "3. Check if API access requires:\n";
echo "   - Paid subscription level\n";
echo "   - Special activation\n";
echo "   - Partner/developer account\n";
