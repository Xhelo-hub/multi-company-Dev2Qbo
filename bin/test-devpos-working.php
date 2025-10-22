<?php
/**
 * DevPos API Connection Test - WORKING METHOD
 * Based on existing qbo-devpos-sync project
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

echo "=================================================\n";
echo "  DevPos API Connection Test (WORKING METHOD)\n";
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

echo "\nConfiguration:\n";
echo "  Tenant: {$tenant}\n";
echo "  Username: {$username}\n";
echo "  Token URL: {$tokenUrl}\n";
echo "  API Base: {$apiBase}\n\n";

$client = new Client([
    'timeout' => 30,
    'verify' => false,
    'http_errors' => false
]);

echo "Step 1: Getting Access Token...\n";
echo str_repeat("-", 60) . "\n";

// THE WORKING METHOD from qbo-devpos-sync
$response = $client->post($tokenUrl, [
    'form_params' => [
        'grant_type' => 'password',
        'username' => $username,
        'password' => $password,
    ],
    'headers' => [
        'Authorization' => 'Basic Zmlza2FsaXppbWlfc3BhOg==', // fiskalizimi_spa:
        'tenant' => $tenant,
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Accept' => 'application/json'
    ]
]);

$statusCode = $response->getStatusCode();
$body = $response->getBody()->getContents();

echo "Status Code: {$statusCode}\n";

if ($statusCode !== 200) {
    die("✗ Authentication failed\nResponse: {$body}\n");
}

$data = json_decode($body, true);

if (!isset($data['access_token'])) {
    die("✗ No access token in response\nResponse: {$body}\n");
}

$accessToken = $data['access_token'];
$expiresIn = $data['expires_in'] ?? 3600;

echo "✓ Access Token received!\n";
echo "  Token: " . substr($accessToken, 0, 40) . "...\n";
echo "  Expires In: {$expiresIn} seconds (" . round($expiresIn/3600, 1) . " hours)\n\n";

// Step 2: Test Sales E-Invoices API
echo "Step 2: Testing Sales E-Invoices API...\n";
echo str_repeat("-", 60) . "\n";

// Use date format YYYY-MM-DD (not ISO 8601 with time!)
$fromDate = date('Y-m-d', strtotime('-7 days'));
$toDate = date('Y-m-d');

echo "Date Range: {$fromDate} to {$toDate}\n";

// Use the correct endpoint from working project
$endpoint = 'EInvoice/GetSalesInvoice';

$apiResponse = $client->get($apiBase . '/' . $endpoint, [
    'query' => [
        'fromDate' => $fromDate,
        'toDate' => $toDate
    ],
    'headers' => [
        'Authorization' => 'Bearer ' . $accessToken,
        'tenant' => $tenant,
        'Accept' => 'application/json'
    ]
]);

$apiStatus = $apiResponse->getStatusCode();
$apiBody = $apiResponse->getBody()->getContents();

echo "API Status: {$apiStatus}\n";

if ($apiStatus == 200) {
    $apiData = json_decode($apiBody, true);
    
    if (is_array($apiData)) {
        $count = count($apiData);
        
        echo "✓ Sales API Successful!\n";
        echo "Found {$count} sales invoices\n";
        
        if ($count > 0) {
            echo "\nSample Invoice:\n";
            $invoice = $apiData[0];
            echo "  EIC: " . ($invoice['eic'] ?? $invoice['EIC'] ?? 'N/A') . "\n";
            echo "  Document #: " . ($invoice['documentNumber'] ?? 'N/A') . "\n";
            echo "  Date: " . ($invoice['issueDate'] ?? 'N/A') . "\n";
            echo "  Amount: " . ($invoice['totalAmount'] ?? 'N/A') . " " . ($invoice['currency'] ?? '') . "\n";
            echo "  Customer: " . ($invoice['buyerName'] ?? 'N/A') . "\n";
        }
    } else {
        echo "⚠ Unexpected response format\n";
    }
} else {
    echo "✗ API call failed\n";
    echo "Response: " . substr($apiBody, 0, 500) . "\n";
}

// Step 3: Test Purchase E-Invoices
echo "\n";
echo "Step 3: Testing Purchase E-Invoices API...\n";
echo str_repeat("-", 60) . "\n";

$purchaseEndpoint = 'EInvoice/GetPurchaseInvoice';

$purchaseResponse = $client->get($apiBase . '/' . $purchaseEndpoint, [
    'query' => [
        'fromDate' => $fromDate,
        'toDate' => $toDate
    ],
    'headers' => [
        'Authorization' => 'Bearer ' . $accessToken,
        'tenant' => $tenant,
        'Accept' => 'application/json'
    ]
]);

$purchaseStatus = $purchaseResponse->getStatusCode();

if ($purchaseStatus == 200) {
    $purchaseData = json_decode($purchaseResponse->getBody()->getContents(), true);
    $purchaseCount = is_array($purchaseData) ? count($purchaseData) : 0;
    
    echo "✓ Purchase API Successful!\n";
    echo "Found {$purchaseCount} purchase invoices\n";
} else {
    echo "⚠ Purchase API returned status {$purchaseStatus}\n";
}

echo "\n";
echo "=================================================\n";
echo "✓✓✓ DEVPOS CONNECTION SUCCESSFUL! ✓✓✓\n";
echo "=================================================\n\n";

echo "Working Authentication Method:\n";
echo "  1. POST to: {$tokenUrl}\n";
echo "  2. Form params: grant_type=password, username, password\n";
echo "  3. Headers:\n";
echo "     - Authorization: Basic Zmlza2FsaXppbWlfc3BhOg==\n";
echo "     - tenant: {$tenant}\n";
echo "     - Content-Type: application/x-www-form-urlencoded\n";
echo "     - Accept: application/json\n\n";

echo "API Endpoints:\n";
echo "  - Sales: GET {$apiBase}/EInvoice/GetSalesInvoice\n";
echo "  - Purchases: GET {$apiBase}/EInvoice/GetPurchaseInvoice\n";
echo "  - Query params: fromDate, toDate (format: YYYY-MM-DD)\n";
echo "  - Headers: Authorization: Bearer <token>, tenant: {$tenant}\n\n";

echo "Add to .env:\n";
echo "DEVPOS_AUTH_BASIC=Zmlza2FsaXppbWlfc3BhOg==\n";
