<?php
/**
 * Check DevPos PDF format in API responses
 * Test all possible PDF-related fields and endpoint patterns
 */

require_once __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;

// Load environment
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Company 28 DevPos credentials (encrypted)
$pdo = new PDO(
    'mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'],
    $_ENV['DB_USER'],
    $_ENV['DB_PASS']
);

// Get company credentials from separate table
$stmt = $pdo->prepare("
    SELECT tenant, username, password_encrypted 
    FROM company_credentials_devpos 
    WHERE company_id = 4
");
$stmt->execute();
$company = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$company || !$company['password_encrypted']) {
    die("Company 4 not found or no DevPos credentials\n");
}

// Decrypt password (same method as SyncExecutor)
$key = $_ENV['ENCRYPTION_KEY'] ?? 'default-key';
$iv = substr(hash('sha256', $key), 0, 16);
$password = openssl_decrypt($company['password_encrypted'], 'AES-256-CBC', $key, 0, $iv);

echo "===== DevPos PDF Format Check =====\n\n";
echo "Company 4: Jonathan\n";
echo "Tenant: {$company['tenant']}\n";
echo "Username: {$company['username']}\n\n";

// Get OAuth2 token
$client = new Client();

try {
    echo "1. Getting OAuth2 token...\n";
    $tokenResponse = $client->post('https://online.devpos.al/connect/token', [
        'form_params' => [
            'grant_type' => 'password',
            'username' => $company['username'],
            'password' => $password,
        ],
        'headers' => [
            'Authorization' => 'Basic ' . ($_ENV['DEVPOS_AUTH_BASIC'] ?? 'Zmlza2FsaXppbWlfc3BhOg=='),
            'tenant' => $company['tenant'],
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json'
        ]
    ]);

    $tokenData = json_decode($tokenResponse->getBody()->getContents(), true);
    $token = $tokenData['access_token'] ?? null;

    if (!$token) {
        die("Failed to get access token\n");
    }

    echo "✓ Token obtained\n\n";

    // Test 1: Get Sales Invoice with includePdf parameter
    echo "2. Testing GetSalesInvoice with includePdf=true...\n";
    $salesResponse = $client->get('https://online.devpos.al/api/v3/EInvoice/GetSalesInvoice', [
        'query' => [
            'fromDate' => '2025-04-01',
            'toDate' => '2025-04-30',
            'includePdf' => true, // Boolean true
        ],
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'tenant' => $company['tenant'],
        ]
    ]);

    $salesInvoices = json_decode($salesResponse->getBody()->getContents(), true);
    
    if (!empty($salesInvoices)) {
        $firstInvoice = $salesInvoices[0];
        echo "✓ Found " . count($salesInvoices) . " invoices\n";
        echo "First invoice: {$firstInvoice['documentNumber']} (EIC: {$firstInvoice['eic']})\n\n";
        
        echo "ALL FIELDS IN RESPONSE:\n";
        foreach ($firstInvoice as $key => $value) {
            $valueStr = is_array($value) ? json_encode($value) : (string)$value;
            if (strlen($valueStr) > 100) {
                $valueStr = substr($valueStr, 0, 100) . '... [truncated]';
            }
            echo "  - $key: $valueStr\n";
        }
        echo "\n";

        // Check for PDF-related fields
        $pdfFields = ['pdf', 'pdfUrl', 'pdf_url', 'pdfLink', 'pdfContent', 'pdfBase64', 'documentUrl', 'fileUrl', 'attachmentUrl'];
        $foundPdfFields = array_intersect($pdfFields, array_keys($firstInvoice));
        
        if (!empty($foundPdfFields)) {
            echo "✓ FOUND PDF FIELDS: " . implode(', ', $foundPdfFields) . "\n\n";
        } else {
            echo "❌ NO PDF FIELDS FOUND\n\n";
        }

        // Test 2: Try with string "true" instead
        echo "3. Testing with includePdf='true' (string)...\n";
        $salesResponse2 = $client->get('https://online.devpos.al/api/v3/EInvoice/GetSalesInvoice', [
            'query' => [
                'fromDate' => '2025-04-01',
                'toDate' => '2025-04-30',
                'includePdf' => 'true', // String "true"
            ],
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'tenant' => $company['tenant'],
            ]
        ]);

        $salesInvoices2 = json_decode($salesResponse2->getBody()->getContents(), true);
        if (!empty($salesInvoices2)) {
            $firstInvoice2 = $salesInvoices2[0];
            $foundPdfFields2 = array_intersect($pdfFields, array_keys($firstInvoice2));
            
            if (!empty($foundPdfFields2)) {
                echo "✓ FOUND PDF FIELDS: " . implode(', ', $foundPdfFields2) . "\n\n";
            } else {
                echo "❌ NO PDF FIELDS FOUND\n\n";
            }
        }

        // Test 3: Try with numeric 1
        echo "4. Testing with includePdf=1 (numeric)...\n";
        $salesResponse3 = $client->get('https://online.devpos.al/api/v3/EInvoice/GetSalesInvoice', [
            'query' => [
                'fromDate' => '2025-04-01',
                'toDate' => '2025-04-30',
                'includePdf' => 1, // Numeric 1
            ],
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'tenant' => $company['tenant'],
            ]
        ]);

        $salesInvoices3 = json_decode($salesResponse3->getBody()->getContents(), true);
        if (!empty($salesInvoices3)) {
            $firstInvoice3 = $salesInvoices3[0];
            $foundPdfFields3 = array_intersect($pdfFields, array_keys($firstInvoice3));
            
            if (!empty($foundPdfFields3)) {
                echo "✓ FOUND PDF FIELDS: " . implode(', ', $foundPdfFields3) . "\n\n";
            } else {
                echo "❌ NO PDF FIELDS FOUND\n\n";
            }
        }

        // Test 4: Try individual invoice endpoint with EIC
        $eic = $firstInvoice['eic'];
        echo "5. Testing individual invoice endpoint: /EInvoice/$eic...\n";
        
        $possibleEndpoints = [
            "/api/v3/EInvoice/$eic",
            "/api/v3/EInvoice/$eic/pdf",
            "/api/v3/EInvoice/$eic/download",
            "/api/v3/EInvoice/GetSalesInvoice/$eic",
        ];

        foreach ($possibleEndpoints as $endpoint) {
            try {
                echo "  Trying: $endpoint\n";
                $detailResponse = $client->get("https://online.devpos.al$endpoint", [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'tenant' => $company['tenant'],
                    ],
                    'http_errors' => false,
                ]);

                $statusCode = $detailResponse->getStatusCode();
                $contentType = $detailResponse->getHeaderLine('Content-Type');
                $bodySize = strlen($detailResponse->getBody()->getContents());

                echo "    Status: $statusCode, Content-Type: $contentType, Size: $bodySize bytes\n";

                if ($statusCode === 200) {
                    if (strpos($contentType, 'application/pdf') !== false) {
                        echo "    ✓ RETURNS PDF FILE!\n";
                    } elseif (strpos($contentType, 'application/json') !== false) {
                        $detailResponse->getBody()->rewind();
                        $detailData = json_decode($detailResponse->getBody()->getContents(), true);
                        echo "    Returns JSON with fields: " . implode(', ', array_keys($detailData)) . "\n";
                        
                        // Check for PDF fields in detail response
                        $foundPdfFieldsDetail = array_intersect($pdfFields, array_keys($detailData));
                        if (!empty($foundPdfFieldsDetail)) {
                            echo "    ✓ FOUND PDF FIELDS: " . implode(', ', $foundPdfFieldsDetail) . "\n";
                        }
                    }
                }
            } catch (Exception $e) {
                echo "    Error: " . $e->getMessage() . "\n";
            }
            echo "\n";
        }

    } else {
        echo "No invoices found in April 2025\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
        echo "Response: " . $e->getResponse()->getBody()->getContents() . "\n";
    }
}

echo "\n===== END =====\n";
