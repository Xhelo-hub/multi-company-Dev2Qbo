#!/usr/bin/env php
<?php
/**
 * Check what date fields DevPos is returning for invoices
 * Usage: php bin/check-invoice-date.php <company_id> <from_date> <to_date>
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../bootstrap/helpers.php';

use Dotenv\Dotenv;

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Get arguments
$companyId = $argv[1] ?? null;
$fromDate = $argv[2] ?? '2025-10-25';
$toDate = $argv[3] ?? '2025-10-29';

if (!$companyId) {
    echo "Usage: php bin/check-invoice-date.php <company_id> [from_date] [to_date]\n";
    echo "Example: php bin/check-invoice-date.php 1 2025-10-25 2025-10-29\n";
    exit(1);
}

echo "=== Checking DevPos Invoice Date Fields ===\n\n";
echo "Company ID: $companyId\n";
echo "Date Range: $fromDate to $toDate\n\n";

try {
    // Get database connection
    $pdo = \App\Storage\make_pdo();
    
    // Get company credentials from multi-company schema
    $stmt = $pdo->prepare("
        SELECT 
            c.id, 
            c.company_name,
            cd.tenant as devpos_tenant,
            cd.username as devpos_username,
            cd.password_encrypted as devpos_password
        FROM companies c
        JOIN company_credentials_devpos cd ON c.id = cd.company_id
        WHERE c.id = ?
    ");
    $stmt->execute([$companyId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$company) {
        echo "❌ Company ID $companyId not found!\n";
        exit(1);
    }
    
    echo "Company: {$company['company_name']}\n";
    echo "Tenant: {$company['devpos_tenant']}\n\n";
    
    // Decrypt password using the same method as CompanyService
    $key = $_ENV['ENCRYPTION_KEY'] ?? null;
    if (!$key) {
        echo "❌ ENCRYPTION_KEY not found in .env\n";
        exit(1);
    }
    
    //  Debug: Check if encrypted password is present
    $encrypted = $company['devpos_password'];
    echo "  DEBUG: Encrypted length: " . strlen($encrypted) . " bytes\n";
    echo "  DEBUG: Key length: " . strlen($key) . " bytes\n";
    echo "  DEBUG: IV: " . substr(md5($key), 0, 16) . "\n\n";
    
    // Use same decryption as CompanyService: md5 of key for IV  
    $password = openssl_decrypt(
        $encrypted, 
        'AES-256-CBC', 
        $key, 
        0, 
        substr(md5($key), 0, 16)
    );
    
    if ($password === false) {
        echo "❌ Failed to decrypt DevPos password\n";
        echo "  OpenSSL Error: " . openssl_error_string() . "\n";
        exit(1);
    }
    
    echo "✅ Credentials decrypted successfully\n\n";
    
    // Get DevPos token
    echo "1. Authenticating with DevPos...\n";
    $client = new \GuzzleHttp\Client(['base_uri' => 'https://online.devpos.al']);
    
    $tokenResponse = $client->post('/token', [
        'form_params' => [
            'grant_type' => 'password',
            'username' => $company['devpos_username'],
            'password' => $password
        ],
        'headers' => [
            'Authorization' => 'Basic Zmlza2FsaXppbWlfc3BhOg==',
            'tenant' => $company['devpos_tenant'],
            'Content-Type' => 'application/x-www-form-urlencoded'
        ]
    ]);
    
    if ($tokenResponse->getStatusCode() !== 200) {
        echo "❌ Failed to get token: " . $tokenResponse->getStatusCode() . "\n";
        exit(1);
    }
    
    $tokenData = json_decode($tokenResponse->getBody(), true);
    $accessToken = $tokenData['access_token'] ?? null;
    
    if (!$accessToken) {
        echo "❌ No access token in response\n";
        exit(1);
    }
    
    echo "✅ Authenticated successfully\n\n";
    
    // Fetch invoices from DevPos
    echo "2. Fetching invoices from DevPos API...\n";
    $response = $client->get('/api/v3/EInvoice/GetSalesInvoice', [
        'headers' => [
            'Authorization' => "Bearer $accessToken",
            'tenant' => $company['devpos_tenant'],
            'Accept' => 'application/json'
        ],
        'query' => [
            'fromDate' => $fromDate,
            'toDate' => $toDate
        ]
    ]);
    
    if ($response->getStatusCode() !== 200) {
        echo "❌ Failed to fetch invoices: " . $response->getStatusCode() . "\n";
        exit(1);
    }
    
    $invoices = json_decode($response->getBody(), true);
    
    if (!is_array($invoices)) {
        echo "❌ Invalid response format\n";
        exit(1);
    }
    
    echo "✅ Fetched " . count($invoices) . " invoices\n\n";
    
    if (count($invoices) === 0) {
        echo "No invoices found in this date range.\n";
        exit(0);
    }
    
    // Analyze date fields in each invoice
    echo "3. Analyzing date fields in invoices:\n";
    echo str_repeat("=", 100) . "\n\n";
    
    foreach ($invoices as $index => $invoice) {
        $docNumber = $invoice['documentNumber'] ?? $invoice['doc_no'] ?? 'N/A';
        $eic = $invoice['eic'] ?? $invoice['EIC'] ?? 'N/A';
        
        echo "Invoice #" . ($index + 1) . ":\n";
        echo "  Document Number: $docNumber\n";
        echo "  EIC: " . substr($eic, 0, 36) . "...\n\n";
        
        // Check all possible date fields
        $dateFields = [
            'issueDate',
            'date',
            'invoiceCreatedDate',
            'dateTimeCreated',
            'createdDate',
            'dateCreated',
            'created_at',
            'dateIssued',
            'invoiceDate',
            'documentDate'
        ];
        
        echo "  Date Fields Present:\n";
        $foundAny = false;
        foreach ($dateFields as $field) {
            if (isset($invoice[$field])) {
                $value = $invoice[$field];
                $formatted = substr($value, 0, 10);
                $marker = ($field === 'issueDate') ? '← PRIMARY (our fix uses this)' : 
                         (($field === 'date') ? '← SECONDARY fallback' : '');
                echo "    ✅ $field: $value (formatted: $formatted) $marker\n";
                $foundAny = true;
            }
        }
        
        if (!$foundAny) {
            echo "    ❌ NO DATE FIELDS FOUND - Would use today's date!\n";
        }
        
        // Show what our transformer would use
        echo "\n  What Our Transformer Would Use:\n";
        $selectedDate = $invoice['issueDate'] ?? $invoice['date'] ?? date('Y-m-d');
        $source = isset($invoice['issueDate']) ? 'issueDate' : 
                 (isset($invoice['date']) ? 'date' : 'today (fallback)');
        
        echo "    → Source: $source\n";
        echo "    → Value: $selectedDate\n";
        echo "    → QuickBooks TxnDate: " . substr($selectedDate, 0, 10) . "\n";
        
        echo "\n" . str_repeat("-", 100) . "\n\n";
    }
    
    echo "\n=== Analysis Complete ===\n\n";
    
    // Summary
    $hasIssueDate = false;
    $hasDate = false;
    $hasNeither = false;
    
    foreach ($invoices as $invoice) {
        if (isset($invoice['issueDate'])) {
            $hasIssueDate = true;
        } elseif (isset($invoice['date'])) {
            $hasDate = true;
        } else {
            $hasNeither = true;
        }
    }
    
    echo "Summary:\n";
    if ($hasIssueDate) {
        echo "  ✅ Invoices have 'issueDate' field - Fix should work!\n";
    }
    if ($hasDate) {
        echo "  ⚠️  Some invoices only have 'date' field - Using secondary fallback\n";
    }
    if ($hasNeither) {
        echo "  ❌ Some invoices have NO date fields - Would use today's date!\n";
        echo "     This is likely the cause of wrong dates.\n";
    }
    
} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
