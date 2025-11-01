<?php
/**
 * Test PDF Attachment Flow
 * Tests fetching invoice from DevPos and uploading PDF to QuickBooks
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../bootstrap/app.php';

use GuzzleHttp\Client;

// Configuration
$companyId = 1; // Adjust as needed
$testEIC = null; // Will fetch from recent invoices

echo "\n=== PDF Attachment Test ===\n\n";

// 1. Get database connection
$pdo = new PDO(
    "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// 2. Get DevPos credentials
echo "1. Fetching DevPos credentials for company $companyId...\n";
$stmt = $pdo->prepare("SELECT * FROM company_credentials WHERE company_id = ? AND api_type = 'devpos'");
$stmt->execute([$companyId]);
$devposCreds = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$devposCreds) {
    die("ERROR: No DevPos credentials found for company $companyId\n");
}

echo "   ✓ Found DevPos credentials (tenant: {$devposCreds['tenant']})\n\n";

// 3. Get DevPos token
echo "2. Getting DevPos authentication token...\n";
$client = new Client(['timeout' => 15]);
$tokenResponse = $client->post('https://online.devpos.al/api/v3/Token/GetToken', [
    'json' => [
        'username' => $devposCreds['username'],
        'password' => $devposCreds['password']
    ],
    'headers' => [
        'tenant' => $devposCreds['tenant'],
        'Accept' => 'application/json'
    ]
]);

$tokenData = json_decode($tokenResponse->getBody()->getContents(), true);
$token = $tokenData['data']['token'] ?? null;

if (!$token) {
    die("ERROR: Failed to get DevPos token\n");
}

echo "   ✓ Got token: " . substr($token, 0, 20) . "...\n\n";

// 4. Fetch recent sales invoices to get a test EIC
echo "3. Fetching recent sales invoices...\n";
$invoicesResponse = $client->get('https://online.devpos.al/api/v3/EInvoice/GetSalesInvoice', [
    'query' => [
        'createdFrom' => date('Y-m-d', strtotime('-7 days')),
        'createdTo' => date('Y-m-d')
    ],
    'headers' => [
        'Authorization' => 'Bearer ' . $token,
        'tenant' => $devposCreds['tenant'],
        'Accept' => 'application/json'
    ]
]);

$invoicesData = json_decode($invoicesResponse->getBody()->getContents(), true);
$invoices = $invoicesData['data'] ?? [];

if (empty($invoices)) {
    die("ERROR: No invoices found in the last 7 days\n");
}

echo "   ✓ Found " . count($invoices) . " invoices\n";

// Pick the first invoice with an EIC
foreach ($invoices as $inv) {
    if (!empty($inv['eic']) || !empty($inv['EIC'])) {
        $testEIC = $inv['eic'] ?? $inv['EIC'];
        $docNumber = $inv['documentNumber'] ?? $inv['doc_no'] ?? 'UNKNOWN';
        echo "   ✓ Using invoice: $docNumber (EIC: $testEIC)\n\n";
        break;
    }
}

if (!$testEIC) {
    die("ERROR: No invoice with EIC found\n");
}

// 5. Check if PDF is in list response
echo "4. Checking if PDF exists in list response...\n";
$firstInvoice = null;
foreach ($invoices as $inv) {
    $invEic = $inv['eic'] ?? $inv['EIC'] ?? null;
    if ($invEic === $testEIC) {
        $firstInvoice = $inv;
        break;
    }
}

if ($firstInvoice) {
    echo "   Invoice fields: " . implode(', ', array_keys($firstInvoice)) . "\n";
    $hasPdfInList = isset($firstInvoice['pdf']) || isset($firstInvoice['PDF']);
    echo "   Has PDF in list: " . ($hasPdfInList ? 'YES' : 'NO') . "\n";
    
    if ($hasPdfInList) {
        $pdfField = $firstInvoice['pdf'] ?? $firstInvoice['PDF'];
        echo "   PDF length: " . strlen($pdfField) . " characters\n";
    }
}
echo "\n";

// 6. Fetch invoice detail by EIC
echo "5. Fetching invoice detail by EIC...\n";
try {
    // Try GET first
    $detailResponse = $client->get('https://online.devpos.al/api/v3/EInvoice', [
        'query' => ['EIC' => $testEIC],
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'tenant' => $devposCreds['tenant'],
            'Accept' => 'application/json'
        ]
    ]);
    
    $detailBody = $detailResponse->getBody()->getContents();
    $detail = json_decode($detailBody, true);
    
    // Handle array response
    if (is_array($detail) && isset($detail['data'])) {
        $detail = $detail['data'];
    }
    if (is_array($detail) && isset($detail[0])) {
        $detail = $detail[0];
    }
    
    echo "   ✓ Got detail response (HTTP " . $detailResponse->getStatusCode() . ")\n";
    echo "   Detail fields: " . implode(', ', array_keys($detail)) . "\n";
    
    // Check for PDF in multiple field names
    $pdfB64 = $detail['pdf'] ?? $detail['PDF'] ?? $detail['pdfBase64'] ?? $detail['base64Pdf'] ?? null;
    
    if ($pdfB64) {
        echo "   ✓ PDF FOUND in detail response!\n";
        echo "     Field name: " . (isset($detail['pdf']) ? 'pdf' : (isset($detail['PDF']) ? 'PDF' : 'other')) . "\n";
        echo "     Base64 length: " . strlen($pdfB64) . " characters\n";
        
        // Decode and validate
        $pdfBinary = base64_decode($pdfB64, true);
        if ($pdfBinary !== false) {
            echo "     Binary size: " . strlen($pdfBinary) . " bytes\n";
            
            // Check PDF signature
            $signature = substr($pdfBinary, 0, 4);
            if ($signature === '%PDF') {
                echo "     ✓ Valid PDF signature detected\n";
            } else {
                echo "     ⚠ Invalid PDF signature: " . bin2hex($signature) . "\n";
            }
            
            // Save to temp file for verification
            $tempFile = sys_get_temp_dir() . '/test-devpos-' . $testEIC . '.pdf';
            file_put_contents($tempFile, $pdfBinary);
            echo "     ✓ Saved to: $tempFile\n";
            
        } else {
            echo "     ✗ Failed to decode base64\n";
        }
        
    } else {
        echo "   ✗ NO PDF found in detail response\n";
        echo "   Available fields: " . json_encode(array_keys($detail), JSON_PRETTY_PRINT) . "\n";
    }
    
} catch (Exception $e) {
    echo "   ✗ ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== Test Complete ===\n\n";
