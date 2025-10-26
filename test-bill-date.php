<?php
/**
 * Test script to check DevPos bill date fields
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/bootstrap/app.php';

use GuzzleHttp\Client;

// Get company 2 credentials
$stmt = $pdo->prepare("SELECT tenant, username, password_encrypted FROM company_credentials_devpos WHERE company_id = 2");
$stmt->execute();
$creds = $stmt->fetch();

if (!$creds) {
    die("No credentials found for company 2\n");
}

// Decrypt password
$key = base64_decode($_ENV['ENCRYPTION_KEY'] ?? '');
$decoded = base64_decode($creds['password_encrypted']);
$iv = substr($decoded, 0, 16);
$ciphertext = substr($decoded, 16);
$password = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

echo "Getting DevPos token...\n";

// Get DevPos token
$client = new Client();
$tokenResponse = $client->post($_ENV['DEVPOS_TOKEN_URL'], [
    'form_params' => [
        'grant_type' => 'password',
        'username' => $creds['username'],
        'password' => $password,
        'tenant' => $creds['tenant']
    ],
    'headers' => [
        'Authorization' => 'Basic ' . $_ENV['DEVPOS_AUTH_BASIC'],
        'Content-Type' => 'application/x-www-form-urlencoded'
    ]
]);

$tokenData = json_decode($tokenResponse->getBody()->getContents(), true);
$token = $tokenData['access_token'];

echo "Fetching purchase invoices...\n";

// Fetch bills
$response = $client->get($_ENV['DEVPOS_API_BASE'] . '/EInvoice/GetPurchaseInvoice', [
    'query' => [
        'fromDate' => '2025-09-01',
        'toDate' => '2025-10-26'
    ],
    'headers' => [
        'Authorization' => 'Bearer ' . $token,
        'tenant' => $creds['tenant'],
        'Accept' => 'application/json'
    ]
]);

$bills = json_decode($response->getBody()->getContents(), true);

if (!$bills || count($bills) === 0) {
    die("No bills found\n");
}

echo "Found " . count($bills) . " bills\n\n";

// Show first bill's structure
$firstBill = $bills[0];
echo "=== FIRST BILL STRUCTURE ===\n";
echo "Available fields:\n";
foreach ($firstBill as $key => $value) {
    $displayValue = is_array($value) ? '[Array]' : (strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value);
    echo "  - $key: $displayValue\n";
}

echo "\n=== DATE FIELDS ===\n";
$dateFields = ['issueDate', 'dateIssued', 'date', 'transactionDate', 'createdDate', 'documentDate'];
foreach ($dateFields as $field) {
    if (isset($firstBill[$field])) {
        echo "  ✓ $field: " . $firstBill[$field] . "\n";
    } else {
        echo "  ✗ $field: NOT FOUND\n";
    }
}

echo "\n";
