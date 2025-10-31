<?php
/**
 * Simple PDF Test - Direct API call without bootstrap
 */

require_once __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;

// Manual configuration - UPDATE THESE VALUES
$devposUsername = ''; // Your DevPos username
$devposPassword = ''; // Your DevPos password  
$devposTenant = ''; // Your tenant ID

echo "\n=== Simple PDF Test ===\n\n";

if (empty($devposUsername) || empty($devposPassword) || empty($devposTenant)) {
    die("ERROR: Please edit this file and add your DevPos credentials at the top\n");
}

$client = new Client(['timeout' => 15]);

try {
    // 1. Get token
    echo "1. Getting DevPos token...\n";
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
        die("ERROR: Failed to get token\n");
    }
    
    echo "   ✓ Got token\n\n";
    
    // 2. Fetch recent invoices
    echo "2. Fetching recent sales invoices...\n";
    $invoicesResponse = $client->get('https://online.devpos.al/api/v3/EInvoice/GetSalesInvoice', [
        'query' => [
            'createdFrom' => date('Y-m-d', strtotime('-7 days')),
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
        die("ERROR: No invoices found\n");
    }
    
    echo "   ✓ Found " . count($invoices) . " invoices\n\n";
    
    // 3. Check first invoice
    $firstInvoice = $invoices[0];
    $eic = $firstInvoice['eic'] ?? $firstInvoice['EIC'] ?? null;
    $docNumber = $firstInvoice['documentNumber'] ?? $firstInvoice['doc_no'] ?? 'UNKNOWN';
    
    echo "3. Checking first invoice:\n";
    echo "   Doc Number: $docNumber\n";
    echo "   EIC: " . ($eic ?? 'NOT FOUND') . "\n";
    echo "   Fields in list response: " . implode(', ', array_keys($firstInvoice)) . "\n";
    
    $hasPdfInList = isset($firstInvoice['pdf']) || isset($firstInvoice['PDF']);
    echo "   Has PDF in list: " . ($hasPdfInList ? 'YES' : 'NO') . "\n";
    
    if ($hasPdfInList) {
        $pdfB64 = $firstInvoice['pdf'] ?? $firstInvoice['PDF'];
        echo "   PDF base64 length: " . strlen($pdfB64) . " chars\n";
    }
    echo "\n";
    
    // 4. Fetch detail by EIC if available
    if ($eic) {
        echo "4. Fetching invoice detail by EIC...\n";
        
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
        
        // Extract data from response structure
        if (isset($detail['data'])) {
            $detail = $detail['data'];
        }
        if (is_array($detail) && isset($detail[0])) {
            $detail = $detail[0];
        }
        
        echo "   HTTP Status: " . $detailResponse->getStatusCode() . "\n";
        echo "   Fields in detail response: " . implode(', ', array_keys($detail)) . "\n";
        
        // Try multiple field names
        $pdfB64 = $detail['pdf'] ?? $detail['PDF'] ?? $detail['pdfBase64'] ?? $detail['base64Pdf'] ?? null;
        
        if ($pdfB64) {
            echo "   ✓ PDF FOUND in detail!\n";
            echo "     Base64 length: " . strlen($pdfB64) . " chars\n";
            
            // Decode and check
            $pdfBinary = base64_decode($pdfB64, true);
            if ($pdfBinary !== false && strlen($pdfBinary) > 0) {
                echo "     Binary size: " . strlen($pdfBinary) . " bytes\n";
                
                // Check PDF signature  
                $sig = substr($pdfBinary, 0, 5);
                if (strpos($sig, '%PDF') === 0) {
                    echo "     ✓ Valid PDF file!\n";
                    
                    // Save it
                    $filename = "test-invoice-{$docNumber}.pdf";
                    file_put_contents(__DIR__ . '/' . $filename, $pdfBinary);
                    echo "     ✓ Saved to: bin/$filename\n";
                    
                } else {
                    echo "     ✗ Invalid PDF signature: " . bin2hex(substr($pdfBinary, 0, 10)) . "\n";
                }
            } else {
                echo "     ✗ Failed to decode base64\n";
            }
        } else {
            echo "   ✗ NO PDF in detail response\n";
        }
    } else {
        echo "4. Skipping detail fetch - no EIC available\n";
    }
    
} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    if (method_exists($e, 'getResponse') && $e->getResponse()) {
        echo "Response: " . $e->getResponse()->getBody()->getContents() . "\n";
    }
}

echo "\n=== Test Complete ===\n\n";
