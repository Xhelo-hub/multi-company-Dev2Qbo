<?php
/**
 * Check specific sales invoice 1/2025 from 07/04/2025
 */

require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$companyId = 28; // Qendra Jonathan

echo "=== Checking Sales Invoice 1/2025 (07/04/2025) ===\n\n";

// Get credentials
$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'Xhelo_qbo_devpos';
$dbUser = $_ENV['DB_USER'] ?? 'root';
$dbPass = $_ENV['DB_PASS'] ?? '';

$db = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get DevPos credentials
$stmt = $db->prepare("SELECT tenant, username, password_encrypted FROM company_credentials_devpos WHERE company_id = ?");
$stmt->execute([$companyId]);
$devposCreds = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$devposCreds) {
    die("❌ No DevPos credentials found for company $companyId\n");
}

// Decrypt password
$key = $_ENV['ENCRYPTION_KEY'] ?? 'default-key';
$iv = substr(hash('sha256', $key), 0, 16);
$devposPassword = openssl_decrypt($devposCreds['password_encrypted'], 'AES-256-CBC', $key, 0, $iv);

if (!$devposPassword) {
    die("❌ Failed to decrypt password\n");
}

echo "Company ID: $companyId\n";
echo "DevPos Tenant: {$devposCreds['tenant']}\n";
echo "DevPos Username: {$devposCreds['username']}\n\n";

$client = new Client(['http_errors' => false]);
$apiBase = 'https://online.devpos.al/api/v3';
$tokenUrl = 'https://online.devpos.al/connect/token';

// Get token
echo "Getting DevPos token...\n";
$response = $client->post($tokenUrl, [
    'form_params' => [
        'grant_type' => 'password',
        'username' => $devposCreds['username'],
        'password' => $devposPassword,
    ],
    'headers' => [
        'Authorization' => 'Basic ' . ($_ENV['DEVPOS_AUTH_BASIC'] ?? 'Zmlza2FsaXppbWlfc3BhOg=='),
        'tenant' => $devposCreds['tenant'],
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Accept' => 'application/json'
    ]
]);

if ($response->getStatusCode() !== 200) {
    die("❌ Token API error: " . $response->getBody()->getContents() . "\n");
}

$tokenData = json_decode($response->getBody()->getContents(), true);
$token = $tokenData['access_token'] ?? null;

if (!$token) {
    die("❌ No access token received\n");
}

echo "✓ Got token\n\n";

// Fetch SALES invoices from April 2025
$fromDate = '2025-04-01';
$toDate = '2025-04-30';

echo "Fetching SALES invoices from $fromDate to $toDate...\n";

$response = $client->get($apiBase . '/EInvoice/GetSalesInvoice', [
    'query' => [
        'fromDate' => $fromDate,
        'toDate' => $toDate,
    ],
    'headers' => [
        'Authorization' => 'Bearer ' . $token,
        'tenant' => $devposCreds['tenant'],
        'Accept' => 'application/json'
    ]
]);

if ($response->getStatusCode() !== 200) {
    die("❌ GetSalesInvoice API error (HTTP {$response->getStatusCode()}): " . $response->getBody()->getContents() . "\n");
}

$invoices = json_decode($response->getBody()->getContents(), true);

if (empty($invoices)) {
    die("❌ No sales invoices returned in April 2025\n");
}

echo "✓ Got " . count($invoices) . " sales invoices in April 2025\n\n";

// Find invoice 1/2025
$targetInvoice = null;
foreach ($invoices as $invoice) {
    if (isset($invoice['documentNumber']) && strpos($invoice['documentNumber'], '1/2025') !== false) {
        $targetInvoice = $invoice;
        break;
    }
}

if (!$targetInvoice) {
    echo "❌ Invoice 1/2025 not found in the results\n\n";
    echo "Available invoices:\n";
    foreach (array_slice($invoices, 0, 10) as $inv) {
        echo "  - " . ($inv['documentNumber'] ?? 'N/A') . " (" . ($inv['invoiceCreatedDate'] ?? 'N/A') . ")\n";
    }
    die();
}

echo "✅ Found invoice 1/2025!\n\n";

echo "=== INVOICE 1/2025 STRUCTURE ===\n";
echo "Document Number: " . ($targetInvoice['documentNumber'] ?? 'N/A') . "\n";
echo "EIC: " . ($targetInvoice['eic'] ?? 'N/A') . "\n";
echo "Date: " . ($targetInvoice['invoiceCreatedDate'] ?? 'N/A') . "\n";
echo "Amount: " . ($targetInvoice['amount'] ?? 'N/A') . "\n";
echo "Status: " . ($targetInvoice['invoiceStatus'] ?? 'N/A') . "\n\n";

echo "All available fields:\n";
foreach ($targetInvoice as $field => $value) {
    $type = gettype($value);
    if ($type === 'array') {
        echo "  - $field: [array with " . count($value) . " items]\n";
    } elseif ($type === 'object') {
        echo "  - $field: [object]\n";
    } else {
        $displayValue = is_string($value) ? (strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value) : $value;
        echo "  - $field: $displayValue\n";
    }
}

echo "\n=== CHECKING FOR LINE ITEMS ===\n";

// Look for common line item field names
$lineItemFields = ['items', 'lines', 'lineItems', 'articles', 'products', 'details', 'invoiceLines'];
$foundLineItems = false;

foreach ($lineItemFields as $fieldName) {
    if (isset($targetInvoice[$fieldName])) {
        echo "✓ Found line items in field: '$fieldName'\n\n";
        $items = $targetInvoice[$fieldName];
        
        if (is_array($items) && !empty($items)) {
            echo "Number of line items: " . count($items) . "\n\n";
            echo "First line item structure:\n";
            $firstItem = $items[0];
            
            foreach ($firstItem as $itemField => $itemValue) {
                $type = gettype($itemValue);
                if ($type === 'array' || $type === 'object') {
                    echo "  - $itemField: [$type]\n";
                } else {
                    echo "  - $itemField: $itemValue\n";
                }
            }
            
            $foundLineItems = true;
        }
        break;
    }
}

if (!$foundLineItems) {
    echo "❌ No line items found in the sales invoice!\n\n";
}

// Check for currency fields
echo "\n=== CHECKING FOR CURRENCY FIELDS ===\n";
$currencyFields = ['currency', 'currencyCode', 'currency_code', 'exchangeRate', 'exchange_rate', 'vatCurrency'];
$foundCurrency = false;

foreach ($currencyFields as $field) {
    if (isset($targetInvoice[$field])) {
        echo "✓ Found '$field': " . $targetInvoice[$field] . "\n";
        $foundCurrency = true;
    }
}

if (!$foundCurrency) {
    echo "❌ No currency fields found\n";
}

echo "\n=== FULL INVOICE JSON ===\n";
echo json_encode($targetInvoice, JSON_PRETTY_PRINT) . "\n";

echo "\n=== SUMMARY ===\n";
echo "Sales Invoice 1/2025:\n";
echo "  - Line items: " . ($foundLineItems ? "✅ YES" : "❌ NO") . "\n";
echo "  - Currency info: " . ($foundCurrency ? "✅ YES" : "❌ NO") . "\n";
