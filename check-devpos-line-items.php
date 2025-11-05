<?php
/**
 * Check if DevPos sends line items (articles, quantities, unit prices) in SALES invoices
 */

require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$companyId = 28; // Qendra Jonathan

echo "=== Checking DevPos Line Items Data (SALES INVOICES) ===\n\n";

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

// Fetch SALES invoices (try October to November)
$fromDate = '2025-10-01';
$toDate = '2025-11-05';

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
    die("❌ No sales invoices returned\n");
}

echo "✓ Got " . count($invoices) . " sales invoices\n\n";

// Examine first invoice in detail
$firstInvoice = $invoices[0];

echo "=== FIRST INVOICE STRUCTURE ===\n";
echo "Document Number: " . ($firstInvoice['documentNumber'] ?? 'N/A') . "\n";
echo "EIC: " . ($firstInvoice['eic'] ?? 'N/A') . "\n";
echo "Amount: " . ($firstInvoice['amount'] ?? 'N/A') . "\n\n";

echo "All available fields:\n";
foreach ($firstInvoice as $field => $value) {
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
    if (isset($firstInvoice[$fieldName])) {
        echo "✓ Found line items in field: '$fieldName'\n\n";
        $items = $firstInvoice[$fieldName];
        
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
            
            // Check for specific fields we need
            echo "\n=== CHECKING FOR REQUIRED FIELDS ===\n";
            $requiredFields = [
                'quantity' => ['quantity', 'qty', 'amount', 'sasi'],
                'unitPrice' => ['unitPrice', 'unit_price', 'price', 'cmimi'],
                'description' => ['description', 'name', 'item', 'pershkrim', 'emertim'],
                'total' => ['total', 'totalAmount', 'vlera', 'shuma'],
                'vat' => ['vat', 'vatAmount', 'tvsh', 'tax'],
            ];
            
            foreach ($requiredFields as $fieldType => $possibleNames) {
                $found = false;
                foreach ($possibleNames as $name) {
                    if (isset($firstItem[$name])) {
                        echo "  ✓ $fieldType: Found as '$name' = " . $firstItem[$name] . "\n";
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    echo "  ❌ $fieldType: NOT FOUND\n";
                }
            }
            
            $foundLineItems = true;
        }
        break;
    }
}

if (!$foundLineItems) {
    echo "❌ No line items found in the invoice!\n";
    echo "This means DevPos GetSalesInvoice API does NOT return line item details.\n\n";
    
    echo "Full invoice JSON:\n";
    echo json_encode($firstInvoice, JSON_PRETTY_PRINT) . "\n";
}

echo "\n=== SUMMARY ===\n";
if ($foundLineItems) {
    echo "✅ DevPos DOES send line item data (articles, quantities, prices) for SALES invoices\n";
} else {
    echo "❌ DevPos does NOT send line item data in GetSalesInvoice response\n";
    echo "   You may need to:\n";
    echo "   1. Use a different API endpoint for detailed invoice data\n";
    echo "   2. Query your other system that has complete sales data\n";
    echo "   3. Contact DevPos support about getting line item details\n";
}
