<?php
/**
 * Test DevPos API Connection
 * Tests authentication and basic API connectivity for Company 1
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
echo "=================================================\n\n";

// Database connection
try {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $_ENV['DB_HOST'] ?? 'localhost',
        $_ENV['DB_NAME'] ?? 'qbo_multicompany'
    );
    
    $pdo = new PDO(
        $dsn,
        $_ENV['DB_USER'] ?? 'root',
        $_ENV['DB_PASS'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    echo "✓ Database connected successfully\n\n";
} catch (PDOException $e) {
    die("✗ Database connection failed: " . $e->getMessage() . "\n");
}

// Get Company 1 credentials
echo "Fetching Company 1 (AEM) credentials...\n";
$stmt = $pdo->prepare("
    SELECT c.company_code, c.company_name, 
           d.tenant, d.username, d.password_encrypted
    FROM companies c
    LEFT JOIN company_credentials_devpos d ON c.id = d.company_id
    WHERE c.id = 1
");
$stmt->execute();
$company = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$company) {
    die("✗ Company 1 not found in database\n");
}

if (!$company['tenant']) {
    die("✗ Company 1 has no DevPos credentials configured\n");
}

echo "✓ Found company: {$company['company_name']} ({$company['company_code']})\n";
echo "  - Tenant: {$company['tenant']}\n";
echo "  - Username: {$company['username']}\n\n";

// Decrypt password
$encryptionKey = $_ENV['ENCRYPTION_KEY'] ?? 'default-insecure-key';
$password = openssl_decrypt(
    $company['password_encrypted'],
    'AES-256-CBC',
    $encryptionKey,
    0,
    substr(md5($encryptionKey), 0, 16)
);

if ($password === false) {
    die("✗ Failed to decrypt password\n");
}

echo "✓ Password decrypted successfully\n\n";

// Test authentication
echo "Testing DevPos Authentication...\n";
echo "----------------------------------------\n";

$tokenUrl = $_ENV['DEVPOS_TOKEN_URL'] ?? 'https://online.devpos.al/connect/token';
$apiBase = $_ENV['DEVPOS_API_BASE'] ?? 'https://online.devpos.al/api/v3';

echo "Token URL: {$tokenUrl}\n";
echo "API Base: {$apiBase}\n\n";

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
            'username' => $company['tenant'] . '|' . $company['username'],
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
    echo "  - Access Token: " . substr($accessToken, 0, 20) . "...\n";
    echo "  - Expires in: {$expiresIn} seconds\n\n";
    
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
        $total = $apiData['totalItems'] ?? $count;
        echo "  - Found {$count} invoices (Total: {$total})\n\n";
        
        if ($count > 0) {
            echo "Sample Invoice:\n";
            echo "----------------------------------------\n";
            $invoice = $apiData['items'][0];
            echo "  EIC: " . ($invoice['eic'] ?? 'N/A') . "\n";
            echo "  Document Number: " . ($invoice['documentNumber'] ?? 'N/A') . "\n";
            echo "  Date: " . ($invoice['issueDate'] ?? 'N/A') . "\n";
            echo "  Amount: " . ($invoice['totalAmount'] ?? 'N/A') . "\n";
            echo "  Customer: " . ($invoice['buyerName'] ?? 'N/A') . "\n";
        }
    } else {
        echo "  - Response structure:\n";
        print_r(array_keys($apiData));
    }
    
    echo "\n=================================================\n";
    echo "✓ DevPos connection test PASSED!\n";
    echo "=================================================\n";
    
} catch (GuzzleHttp\Exception\ClientException $e) {
    echo "✗ HTTP Client Error: " . $e->getMessage() . "\n";
    if ($e->hasResponse()) {
        $errorBody = $e->getResponse()->getBody()->getContents();
        echo "Response:\n" . $errorBody . "\n";
    }
    exit(1);
} catch (GuzzleHttp\Exception\ServerException $e) {
    echo "✗ Server Error: " . $e->getMessage() . "\n";
    if ($e->hasResponse()) {
        $errorBody = $e->getResponse()->getBody()->getContents();
        echo "Response:\n" . $errorBody . "\n";
    }
    exit(1);
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
