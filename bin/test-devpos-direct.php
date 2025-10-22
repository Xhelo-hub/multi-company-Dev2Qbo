<?php
/**
 * DevPos API Direct Access Test
 * Tests direct API access without OAuth
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

echo "=================================================\n";
echo "  DevPos Direct API Access Test\n";
echo "=================================================\n\n";

// Get credentials
$tenant = readline("Enter DevPos Tenant: ");
$username = readline("Enter DevPos Username: ");
$password = readline("Enter DevPos Password: ");

if (empty($tenant) || empty($username) || empty($password)) {
    die("✗ All credentials are required\n");
}

$apiBase = $_ENV['DEVPOS_API_BASE'] ?? 'https://online.devpos.al/api/v3';

echo "\nTesting direct API access to: {$apiBase}\n\n";

$client = new Client([
    'timeout' => 30,
    'verify' => false,
    'http_errors' => false,
    'debug' => false
]);

// Try different authentication headers
$tests = [
    [
        'name' => 'Basic Auth (username:password)',
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($username . ':' . $password),
            'X-Tenant' => $tenant,
            'Accept' => 'application/json'
        ]
    ],
    [
        'name' => 'Basic Auth (tenant|username:password)',
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($tenant . '|' . $username . ':' . $password),
            'Accept' => 'application/json'
        ]
    ],
    [
        'name' => 'Custom Headers (X-Tenant, X-Auth)',
        'headers' => [
            'X-Tenant' => $tenant,
            'X-Username' => $username,
            'X-Password' => $password,
            'Accept' => 'application/json'
        ]
    ],
    [
        'name' => 'Bearer token (username:password base64)',
        'headers' => [
            'Authorization' => 'Bearer ' . base64_encode($tenant . '|' . $username . ':' . $password),
            'Accept' => 'application/json'
        ]
    ],
];

$fromDate = date('Y-m-d', strtotime('-3 days')) . 'T00:00:00+02:00';
$toDate = date('Y-m-d') . 'T23:59:59+02:00';

foreach ($tests as $index => $test) {
    echo ($index + 1) . ". {$test['name']}\n";
    echo str_repeat("-", 60) . "\n";
    
    try {
        $response = $client->get($apiBase . '/sale-einvoices', [
            'query' => [
                'from' => $fromDate,
                'to' => $toDate,
                'page' => 1,
                'size' => 5
            ],
            'headers' => $test['headers']
        ]);
        
        $statusCode = $response->getStatusCode();
        $body = $response->getBody()->getContents();
        $contentType = $response->getHeader('Content-Type')[0] ?? 'unknown';
        
        echo "  Status: {$statusCode}\n";
        echo "  Content-Type: {$contentType}\n";
        echo "  Body Length: " . strlen($body) . " bytes\n";
        
        if ($statusCode == 200) {
            $data = json_decode($body, true);
            
            if ($data === null && $body !== 'null') {
                echo "  ⚠ Response is not valid JSON\n";
                echo "  Raw Response: " . substr($body, 0, 200) . "\n";
            } elseif (isset($data['items'])) {
                $count = count($data['items']);
                $total = $data['totalItems'] ?? $data['totalCount'] ?? $count;
                
                echo "  ✓ SUCCESS! Found {$count} items (Total: {$total})\n";
                
                if ($count > 0) {
                    echo "\n  Sample Invoice:\n";
                    $invoice = $data['items'][0];
                    echo "    EIC: " . ($invoice['eic'] ?? 'N/A') . "\n";
                    echo "    Doc#: " . ($invoice['documentNumber'] ?? 'N/A') . "\n";
                    echo "    Date: " . ($invoice['issueDate'] ?? 'N/A') . "\n";
                    echo "    Amount: " . ($invoice['totalAmount'] ?? 'N/A') . "\n";
                }
                
                echo "\n✓✓✓ WORKING METHOD! ✓✓✓\n";
                echo "Use these headers in DevPosClient:\n";
                foreach ($test['headers'] as $key => $value) {
                    if ($key !== 'X-Password' && !str_contains($key, 'assword')) {
                        echo "  {$key}: {$value}\n";
                    } else {
                        echo "  {$key}: [secret]\n";
                    }
                }
                exit(0);
                
            } else {
                echo "  ⚠ Unexpected response structure\n";
                echo "  Response keys: " . implode(', ', array_keys($data ?: [])) . "\n";
                echo "  Sample: " . substr(json_encode($data), 0, 200) . "\n";
            }
        } elseif ($statusCode == 401) {
            echo "  ✗ Unauthorized - invalid credentials\n";
        } elseif ($statusCode == 403) {
            echo "  ✗ Forbidden - no access\n";
        } else {
            echo "  ✗ Failed\n";
            echo "  Response: " . substr($body, 0, 200) . "\n";
        }
        
    } catch (Exception $e) {
        echo "  ✗ Exception: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
}

echo "=================================================\n";
echo "Summary:\n";
echo "=================================================\n";
echo "Unable to find a working authentication method.\n\n";
echo "The DevPos API appears to use OAuth 2.0 authentication\n";
echo "but requires a valid client_id and client_secret.\n\n";
echo "Next steps:\n";
echo "1. Check if you have API documentation from DevPos\n";
echo "2. Verify the client_id and client_secret with DevPos support\n";
echo "3. Check if there's an API key or token provided separately\n";
echo "4. Review any existing working code that connects to DevPos\n";
