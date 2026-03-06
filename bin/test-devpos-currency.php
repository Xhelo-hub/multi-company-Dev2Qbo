<?php
/**
 * Probe DevPos API for currency fields on purchase invoices.
 * Tries several potential detail endpoints and query parameters.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$companyId = (int)($argv[1] ?? 0);
if (!$companyId) {
    die("Usage: php bin/test-devpos-currency.php <company_id>\n");
}

// Load DB and get DevPos credentials for the given company
$pdo = new PDO($_ENV['DB_DSN'], $_ENV['DB_USER'], $_ENV['DB_PASS']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$key = $_ENV['ENCRYPTION_KEY'];
$iv  = substr(hash('sha256', $key), 0, 16);

$stmt = $pdo->prepare("
    SELECT tenant, username, password_encrypted
    FROM company_credentials_devpos
    WHERE company_id = ?
");
$stmt->execute([$companyId]);
$creds = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$creds || !$creds['tenant']) {
    die("No DevPos credentials found for company $companyId\n");
}

$iv = substr(hash('sha256', $key), 0, 16);
$tenant   = $creds['tenant'];
$username = $creds['username'];
$password = openssl_decrypt($creds['password_encrypted'], 'AES-256-CBC', $key, 0, $iv);
if (!$password) {
    die("Failed to decrypt DevPos password for company $companyId\n");
}

$tokenUrl = $_ENV['DEVPOS_TOKEN_URL'] ?? 'https://online.devpos.al/connect/token';
$apiBase  = $_ENV['DEVPOS_API_BASE']  ?? 'https://online.devpos.al/api/v3';

echo "Tenant: $tenant\n";
echo "API base: $apiBase\n\n";

$client = new Client(['timeout' => 30, 'verify' => false, 'http_errors' => false]);

// Get token
$tokenResp = $client->post($tokenUrl, [
    'form_params' => [
        'grant_type' => 'password',
        'username'   => $username,
        'password'   => $password,
    ],
    'headers' => [
        'Authorization' => 'Basic Zmlza2FsaXppbWlfc3BhOg==',
        'tenant'         => $tenant,
        'Content-Type'   => 'application/x-www-form-urlencoded',
        'Accept'         => 'application/json',
    ]
]);

$tokenData = json_decode($tokenResp->getBody()->getContents(), true);
$token = $tokenData['access_token'] ?? null;
if (!$token) {
    die("Failed to get token: " . json_encode($tokenData) . "\n");
}
echo "Token obtained.\n\n";

$authHeaders = [
    'Authorization' => 'Bearer ' . $token,
    'tenant'        => $tenant,
    'Accept'        => 'application/json',
];

// Fetch first purchase invoice from list
$listResp = $client->get($apiBase . '/EInvoice/GetPurchaseInvoice', [
    'headers' => $authHeaders,
    'query'   => ['fromDate' => '2024-01-01', 'toDate' => date('Y-m-d')],
]);
$list = json_decode($listResp->getBody()->getContents(), true);
if (!is_array($list) || count($list) === 0) {
    die("No purchase invoices returned from list API.\n");
}

$first = $list[0];
$eic   = $first['eic'] ?? null;
$docNo = $first['documentNumber'] ?? 'unknown';
echo "Testing with invoice: $docNo (EIC: $eic)\n";
echo "List API fields: " . implode(', ', array_keys($first)) . "\n\n";

// --- Probe 1: list with includeDetails param ---
echo "=== Probe 1: GetPurchaseInvoice?includeDetails=true ===\n";
$r = $client->get($apiBase . '/EInvoice/GetPurchaseInvoice', [
    'headers' => $authHeaders,
    'query'   => ['fromDate' => '2024-01-01', 'toDate' => date('Y-m-d'), 'includeDetails' => 'true'],
]);
$data = json_decode($r->getBody()->getContents(), true);
if (is_array($data) && count($data) > 0) {
    echo "Fields: " . implode(', ', array_keys($data[0])) . "\n";
    echo json_encode($data[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "HTTP " . $r->getStatusCode() . " — " . $r->getBody()->getContents() . "\n";
}

// --- Probe 2: single invoice by EIC via GET ---
if ($eic) {
    $probes = [
        "/EInvoice/GetPurchaseInvoice/$eic",
        "/EInvoice/$eic",
        "/EInvoice/GetPurchaseInvoiceDetails?eic=$eic",
        "/EInvoice/GetPurchaseInvoiceDetails/$eic",
        "/EInvoice/GetInvoiceDetails?eic=$eic",
        "/Invoice/$eic",
    ];

    foreach ($probes as $path) {
        echo "\n=== Probe: GET $apiBase$path ===\n";
        $r = $client->get($apiBase . $path, ['headers' => $authHeaders]);
        $status = $r->getStatusCode();
        $body   = $r->getBody()->getContents();
        $parsed = json_decode($body, true);
        echo "HTTP $status\n";
        if (is_array($parsed) && count($parsed) > 0) {
            $item = isset($parsed[0]) ? $parsed[0] : $parsed;
            echo "Fields: " . implode(', ', array_keys($item)) . "\n";
            echo json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        } else {
            echo substr($body, 0, 300) . "\n";
        }
    }
}
