<?php
/**
 * Show a sample DevPos invoice with all fields
 */

require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$pdo = new PDO(
    'mysql:host=localhost;dbname=Xhelo_qbo_devpos',
    'Xhelo_qbo_user',
    'Albania@2030',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Get DevPos token
$stmt = $pdo->query("SELECT access_token FROM oauth_tokens_devpos WHERE company_id = 1");
$token = $stmt->fetchColumn();

// Fetch invoices
$client = new \GuzzleHttp\Client();
$response = $client->get('https://online.devpos.al/api/v3/EInvoice/GetSalesInvoice', [
    'query' => [
        'fromDate' => '2025-01-01',
        'toDate' => '2025-10-26'
    ],
    'headers' => [
        'Authorization' => 'Bearer ' . $token,
        'tenant' => 'K43128625A',
        'Accept' => 'application/json'
    ]
]);

$invoices = json_decode($response->getBody()->getContents(), true);

echo "Sample DevPos Invoice (first invoice):\n";
echo "=====================================\n\n";
echo json_encode($invoices[0], JSON_PRETTY_PRINT);
