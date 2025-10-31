<?php
/**
 * Test DevPos PDF Field Structure
 * This script shows exactly how DevPos provides PDF files
 */

require_once __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;

echo "\n" . str_repeat("=", 70) . "\n";
echo "DEVPOS PDF FIELD STRUCTURE TEST\n";
echo str_repeat("=", 70) . "\n\n";

// Load environment
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->safeLoad();
} catch (Exception $e) {
    echo "Note: Could not load .env file, using manual config\n\n";
}

// Configuration - get from environment or set manually
$devposUsername = $_ENV['DEVPOS_USERNAME'] ?? '';
$devposPassword = $_ENV['DEVPOS_PASSWORD'] ?? '';
$devposTenant = $_ENV['DEVPOS_TENANT'] ?? '';

// If not in .env, prompt user
if (empty($devposUsername)) {
    echo "Please provide DevPos credentials:\n";
    echo "Username: ";
    $devposUsername = trim(fgets(STDIN));
    echo "Password: ";
    $devposPassword = trim(fgets(STDIN));
    echo "Tenant: ";
    $devposTenant = trim(fgets(STDIN));
    echo "\n";
}

if (empty($devposUsername) || empty($devposPassword) || empty($devposTenant)) {
    die("ERROR: DevPos credentials are required\n");
}

$client = new Client(['timeout' => 15]);

try {
    // Step 1: Authenticate
    echo "[1/5] Authenticating with DevPos API...\n";
    $tokenResponse = $client->post('https://online.devpos.al/api/v3/Token/GetToken', [
        'json' => [
            'username' => $devposUsername,
            'password' => $devposPassword
        ],
        'headers' => [
            'tenant' => $devposTenant,
            'Accept' => 'application/json'
        ]
    ]);
    
    $tokenData = json_decode($tokenResponse->getBody()->getContents(), true);
    $token = $tokenData['data']['token'] ?? null;
    
    if (!$token) {
        die("ERROR: Failed to get authentication token\n");
    }
    echo "      ✓ Authentication successful\n\n";
    
    // Step 2: Fetch recent sales invoices (LIST endpoint)
    echo "[2/5] Fetching sales invoices from LIST endpoint...\n";
    echo "      Endpoint: GET /EInvoice/GetSalesInvoice\n";
    
    $invoicesResponse = $client->get('https://online.devpos.al/api/v3/EInvoice/GetSalesInvoice', [
        'query' => [
            'createdFrom' => date('Y-m-d', strtotime('-14 days')),
            'createdTo' => date('Y-m-d')
        ],
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'tenant' => $devposTenant,
            'Accept' => 'application/json'
        ]
    ]);
    
    $invoicesData = json_decode($invoicesResponse->getBody()->getContents(), true);
    $invoices = $invoicesData['data'] ?? [];
    
    if (empty($invoices)) {
        die("ERROR: No invoices found in the last 14 days\n");
    }
    
    echo "      ✓ Found " . count($invoices) . " invoices\n\n";
    
    // Step 3: Analyze first invoice from LIST
    echo "[3/5] Analyzing first invoice from LIST response...\n";
    $firstInvoice = $invoices[0];
    $eic = $firstInvoice['eic'] ?? $firstInvoice['EIC'] ?? null;
    $docNumber = $firstInvoice['documentNumber'] ?? $firstInvoice['doc_no'] ?? 'UNKNOWN';
    
    echo "      Invoice: $docNumber\n";
    echo "      EIC: " . ($eic ?? 'NOT FOUND') . "\n";
    echo "      Total fields in LIST response: " . count($firstInvoice) . "\n";
    
    // Check all possible PDF field names
    $pdfFieldsInList = [];
    foreach (['pdf', 'PDF', 'pdfBase64', 'base64Pdf', 'pdfDocument', 'document'] as $field) {
        if (isset($firstInvoice[$field])) {
            $pdfFieldsInList[$field] = strlen($firstInvoice[$field]);
        }
    }
    
    if (!empty($pdfFieldsInList)) {
        echo "      ✓ PDF fields found in LIST:\n";
        foreach ($pdfFieldsInList as $field => $length) {
            echo "        - '$field': $length characters\n";
        }
    } else {
        echo "      ✗ NO PDF fields found in LIST response\n";
    }
    
    echo "      All fields in LIST: " . implode(', ', array_keys($firstInvoice)) . "\n\n";
    
    // Step 4: Fetch DETAIL by EIC
    if ($eic) {
        echo "[4/5] Fetching invoice DETAIL by EIC...\n";
        echo "      Endpoint: GET /EInvoice?EIC=$eic\n";
        
        $detailResponse = $client->get('https://online.devpos.al/api/v3/EInvoice', [
            'query' => ['EIC' => $eic],
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'tenant' => $devposTenant,
                'Accept' => 'application/json'
            ]
        ]);
        
        $detailBody = $detailResponse->getBody()->getContents();
        $detail = json_decode($detailBody, true);
        
        // Handle response structure
        if (isset($detail['data'])) {
            $detail = $detail['data'];
        }
        if (is_array($detail) && isset($detail[0])) {
            $detail = $detail[0];
        }
        
        echo "      ✓ Got DETAIL response (HTTP " . $detailResponse->getStatusCode() . ")\n";
        echo "      Total fields in DETAIL response: " . count($detail) . "\n";
        
        // Check all possible PDF field names
        $pdfFieldsInDetail = [];
        foreach (['pdf', 'PDF', 'pdfBase64', 'base64Pdf', 'pdfDocument', 'document', 'file'] as $field) {
            if (isset($detail[$field])) {
                $pdfFieldsInDetail[$field] = strlen($detail[$field]);
            }
        }
        
        if (!empty($pdfFieldsInDetail)) {
            echo "      ✓ PDF fields found in DETAIL:\n";
            foreach ($pdfFieldsInDetail as $field => $length) {
                echo "        - '$field': $length characters\n";
                
                // Try to decode and validate
                $pdfData = $detail[$field];
                $decoded = base64_decode($pdfData, true);
                if ($decoded !== false && strlen($decoded) > 0) {
                    $signature = substr($decoded, 0, 5);
                    if (strpos($signature, '%PDF') === 0) {
                        echo "          ✓ Valid base64-encoded PDF (size: " . strlen($decoded) . " bytes)\n";
                        
                        // Save sample
                        $sampleFile = __DIR__ . "/sample-{$docNumber}.pdf";
                        file_put_contents($sampleFile, $decoded);
                        echo "          ✓ Sample saved: $sampleFile\n";
                    } else {
                        echo "          ✗ Invalid PDF signature: " . bin2hex($signature) . "\n";
                    }
                } else {
                    echo "          ✗ Not valid base64 data\n";
                }
            }
        } else {
            echo "      ✗ NO PDF fields found in DETAIL response\n";
        }
        
        echo "      All fields in DETAIL: " . implode(', ', array_keys($detail)) . "\n\n";
        
        // Step 5: Summary and recommendations
        echo "[5/5] SUMMARY\n";
        echo str_repeat("-", 70) . "\n";
        
        if (!empty($pdfFieldsInList)) {
            echo "✓ PDF IS available in LIST endpoint\n";
            echo "  Field name(s): " . implode(', ', array_keys($pdfFieldsInList)) . "\n";
            echo "  Recommendation: Use PDF directly from GetSalesInvoice response\n";
        } else {
            echo "✗ PDF NOT available in LIST endpoint\n";
            echo "  You must fetch individual invoice details by EIC\n";
        }
        
        echo "\n";
        
        if (!empty($pdfFieldsInDetail)) {
            echo "✓ PDF IS available in DETAIL endpoint\n";
            echo "  Field name(s): " . implode(', ', array_keys($pdfFieldsInDetail)) . "\n";
            echo "  Recommendation: Use getEInvoiceByEIC(eic) to fetch PDF\n";
        } else {
            echo "✗ PDF NOT available in DETAIL endpoint either\n";
            echo "  Contact DevPos support about PDF access\n";
        }
        
        echo "\n" . str_repeat("=", 70) . "\n";
        echo "CONCLUSION:\n";
        
        if (!empty($pdfFieldsInDetail) || !empty($pdfFieldsInList)) {
            $pdfField = array_keys($pdfFieldsInDetail)[0] ?? array_keys($pdfFieldsInList)[0];
            echo "✓ PDF files ARE available from DevPos API\n";
            echo "✓ Use field name: '$pdfField'\n";
            echo "✓ Format: base64-encoded string\n";
            echo "✓ Your sync code should work with this field\n";
        } else {
            echo "✗ PDF files are NOT available in API responses\n";
            echo "Possible reasons:\n";
            echo "  1. Your DevPos account doesn't have PDF access\n";
            echo "  2. PDFs need to be requested with a special parameter\n";
            echo "  3. PDFs are only available for certain invoice types\n";
            echo "Contact DevPos support for assistance\n";
        }
        
    } else {
        echo "[4/5] SKIPPED - No EIC found in invoice\n";
        echo "[5/5] Cannot test DETAIL endpoint without EIC\n";
    }
    
} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    if (method_exists($e, 'getResponse')) {
        $response = $e->getResponse();
        if ($response) {
            echo "HTTP Status: " . $response->getStatusCode() . "\n";
            echo "Response: " . $response->getBody()->getContents() . "\n";
        }
    }
    echo "\n";
}

echo "\n";
