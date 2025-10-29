<?php
/**
 * Show Available DevPos Fields and QuickBooks Mapping
 * 
 * This diagnostic tool:
 * 1. Fetches sample data from DevPos API
 * 2. Shows all available fields
 * 3. Shows which fields are currently used in sync
 * 4. Shows QuickBooks field mapping
 */

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;

// Load environment
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Connect to database
$pdo = new PDO(
    "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']}",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "\n╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║              DevPos Fields → QuickBooks Mapping Diagnostic Tool              ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n\n";

// Get company info
$companyId = $argv[1] ?? 1;
$stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$company) {
    echo "❌ Company ID {$companyId} not found\n";
    exit(1);
}

echo "📋 Company: {$company['name']} (ID: {$companyId})\n";
echo "🔐 Tenant: {$company['tenant']}\n\n";

// Get DevPos token
$stmt = $pdo->prepare("SELECT access_token FROM oauth_tokens_devpos WHERE company_id = ?");
$stmt->execute([$companyId]);
$token = $stmt->fetchColumn();

if (!$token) {
    echo "❌ No DevPos token found for company {$companyId}\n";
    exit(1);
}

echo "✅ DevPos authentication found\n\n";

// Initialize HTTP client
$client = new Client(['timeout' => 30]);
$apiBase = $_ENV['DEVPOS_API_BASE'] ?? 'https://online.devpos.al/api/v3';
$fromDate = date('Y-m-d', strtotime('-7 days'));
$toDate = date('Y-m-d');

echo "📅 Fetching sample data (last 7 days: {$fromDate} to {$toDate})\n\n";

// Function to analyze fields
function analyzeFields(array $data, string $type): array
{
    $fields = [];
    
    foreach (array_keys($data) as $key) {
        $value = $data[$key];
        $fields[$key] = [
            'type' => gettype($value),
            'sample' => is_array($value) ? '[array]' : (is_bool($value) ? ($value ? 'true' : 'false') : substr(var_export($value, true), 0, 50))
        ];
    }
    
    return $fields;
}

// Function to show field mapping
function showMapping(string $entityType): array
{
    $mappings = [
        'invoice' => [
            'date_fields' => ['invoiceCreatedDate', 'dateTimeCreated', 'createdDate', 'issueDate', 'dateCreated'],
            'qbo_date' => 'TxnDate',
            'amount_fields' => ['totalAmount', 'total', 'amount'],
            'qbo_amount' => 'Line[0].Amount',
            'number_fields' => ['documentNumber', 'doc_no', 'DocNumber'],
            'qbo_number' => 'DocNumber',
            'customer_fields' => ['buyerName', 'buyer_name', 'customerName'],
            'qbo_customer' => 'CustomerRef',
            'tax_id_fields' => ['buyerNuis'],
            'eic_fields' => ['eic', 'EIC'],
            'qbo_eic' => 'CustomField[EIC]'
        ],
        'bill' => [
            'date_fields' => ['invoiceCreatedDate', 'dateTimeCreated', 'createdDate', 'issueDate', 'dateCreated'],
            'qbo_date' => 'TxnDate, DueDate',
            'amount_fields' => ['totalAmount', 'total', 'amount'],
            'qbo_amount' => 'Line[0].Amount',
            'number_fields' => ['documentNumber', 'doc_no', 'DocNumber'],
            'qbo_number' => 'DocNumber',
            'vendor_fields' => ['sellerName', 'seller_name', 'vendorName'],
            'qbo_vendor' => 'VendorRef',
            'tax_id_fields' => ['sellerNuis', 'seller_nuis'],
            'eic_fields' => ['eic', 'EIC'],
            'qbo_eic' => 'PrivateNote'
        ],
        'salesreceipt' => [
            'date_fields' => ['invoiceCreatedDate', 'dateTimeCreated', 'createdDate', 'issueDate', 'dateCreated'],
            'qbo_date' => 'TxnDate',
            'amount_fields' => ['totalAmount', 'total', 'amount'],
            'qbo_amount' => 'Line[0].Amount',
            'number_fields' => ['documentNumber', 'doc_no', 'DocNumber'],
            'qbo_number' => 'DocNumber',
            'customer_fields' => ['buyerName', 'buyer_name', 'customerName'],
            'qbo_customer' => 'CustomerRef',
            'payment_fields' => ['invoicePayments[0].paymentMethodType'],
            'qbo_payment' => 'PaymentMethodRef'
        ]
    ];
    
    return $mappings[$entityType] ?? [];
}

// ============================================================================
// 1. SALES INVOICES
// ============================================================================

echo str_repeat("=", 80) . "\n";
echo "1. SALES INVOICES (E-Invoices)\n";
echo str_repeat("=", 80) . "\n\n";

try {
    $response = $client->get($apiBase . '/EInvoice/GetSalesInvoice', [
        'query' => ['fromDate' => $fromDate, 'toDate' => $toDate],
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'tenant' => $company['tenant'],
            'Accept' => 'application/json'
        ]
    ]);
    
    $invoices = json_decode($response->getBody()->getContents(), true);
    
    if (!is_array($invoices) || count($invoices) === 0) {
        echo "⚠️  No invoices found in date range\n\n";
    } else {
        echo "✅ Found " . count($invoices) . " invoice(s)\n\n";
        
        // Analyze first invoice
        $invoice = $invoices[0];
        $fields = analyzeFields($invoice, 'invoice');
        $mapping = showMapping('invoice');
        
        $docNum = isset($invoice['documentNumber']) ? $invoice['documentNumber'] : 'N/A';
        $eic = isset($invoice['eic']) ? $invoice['eic'] : 'N/A';
        echo "📄 Sample Invoice: {$docNum}\n";
        echo "   EIC: {$eic}\n\n";
        
        echo "📋 Available DevPos Fields:\n";
        echo str_repeat("-", 80) . "\n";
        printf("%-30s %-15s %-35s\n", "Field Name", "Type", "Sample Value");
        echo str_repeat("-", 80) . "\n";
        
        foreach ($fields as $field => $info) {
            printf("%-30s %-15s %-35s\n", $field, $info['type'], $info['sample']);
        }
        
        echo "\n";
        echo "🔗 QuickBooks Field Mapping:\n";
        echo str_repeat("-", 80) . "\n";
        
        // Check date fields
        echo "\n📅 DATE FIELDS:\n";
        echo "   Checking in order: " . implode(', ', $mapping['date_fields']) . "\n";
        $foundDate = null;
        foreach ($mapping['date_fields'] as $dateField) {
            $exists = isset($invoice[$dateField]);
            $marker = $exists ? '✅' : '❌';
            $value = $exists ? $invoice[$dateField] : 'NOT FOUND';
            echo "   {$marker} {$dateField}: {$value}\n";
            if (!$foundDate && $exists) {
                $foundDate = $dateField;
            }
        }
        echo "   → Maps to QuickBooks: {$mapping['qbo_date']}\n";
        if ($foundDate) {
            echo "   → USING: {$foundDate} = {$invoice[$foundDate]}\n";
        } else {
            echo "   → ⚠️  WARNING: No date field found! Will use today's date.\n";
        }
        
        // Check amount fields
        echo "\n💰 AMOUNT FIELDS:\n";
        echo "   Checking in order: " . implode(', ', $mapping['amount_fields']) . "\n";
        $foundAmount = null;
        foreach ($mapping['amount_fields'] as $amountField) {
            $exists = isset($invoice[$amountField]);
            $marker = $exists ? '✅' : '❌';
            $value = $exists ? $invoice[$amountField] : 'NOT FOUND';
            echo "   {$marker} {$amountField}: {$value}\n";
            if (!$foundAmount && $exists) {
                $foundAmount = $amountField;
            }
        }
        echo "   → Maps to QuickBooks: {$mapping['qbo_amount']}\n";
        if ($foundAmount) {
            echo "   → USING: {$foundAmount} = {$invoice[$foundAmount]}\n";
        }
        
        // Check document number
        echo "\n🔢 DOCUMENT NUMBER:\n";
        $foundNumber = null;
        foreach ($mapping['number_fields'] as $numberField) {
            $exists = isset($invoice[$numberField]);
            $marker = $exists ? '✅' : '❌';
            $value = $exists ? $invoice[$numberField] : 'NOT FOUND';
            echo "   {$marker} {$numberField}: {$value}\n";
            if (!$foundNumber && $exists) {
                $foundNumber = $numberField;
            }
        }
        echo "   → Maps to QuickBooks: {$mapping['qbo_number']}\n";
        
        // Check customer fields
        echo "\n👤 CUSTOMER FIELDS:\n";
        foreach ($mapping['customer_fields'] as $customerField) {
            $exists = isset($invoice[$customerField]);
            $marker = $exists ? '✅' : '❌';
            $value = $exists ? $invoice[$customerField] : 'NOT FOUND';
            echo "   {$marker} {$customerField}: {$value}\n";
        }
        echo "   → Maps to QuickBooks: {$mapping['qbo_customer']}\n";
        echo "   ⚠️  Note: Currently HARDCODED to customer ID '1' (not using DevPos data)\n";
        
        // Check EIC
        echo "\n🆔 EIC FIELD:\n";
        foreach ($mapping['eic_fields'] as $eicField) {
            $exists = isset($invoice[$eicField]);
            $marker = $exists ? '✅' : '❌';
            $value = $exists ? substr($invoice[$eicField], 0, 40) : 'NOT FOUND';
            echo "   {$marker} {$eicField}: {$value}\n";
        }
        echo "   → Maps to QuickBooks: {$mapping['qbo_eic']}\n";
        
        echo "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error fetching sales invoices: " . $e->getMessage() . "\n\n";
}

// ============================================================================
// 2. PURCHASE BILLS
// ============================================================================

echo str_repeat("=", 80) . "\n";
echo "2. PURCHASE BILLS (Purchase E-Invoices)\n";
echo str_repeat("=", 80) . "\n\n";

try {
    $response = $client->get($apiBase . '/EInvoice/GetPurchaseInvoice', [
        'query' => ['fromDate' => $fromDate, 'toDate' => $toDate],
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'tenant' => $company['tenant'],
            'Accept' => 'application/json'
        ]
    ]);
    
    $bills = json_decode($response->getBody()->getContents(), true);
    
    if (!is_array($bills) || count($bills) === 0) {
        echo "⚠️  No purchase bills found in date range\n\n";
    } else {
        echo "✅ Found " . count($bills) . " bill(s)\n\n";
        
        $bill = $bills[0];
        $fields = analyzeFields($bill, 'bill');
        $mapping = showMapping('bill');
        
        $docNum = isset($bill['documentNumber']) ? $bill['documentNumber'] : 'N/A';
        $eic = isset($bill['eic']) ? $bill['eic'] : 'N/A';
        echo "📄 Sample Bill: {$docNum}\n";
        echo "   EIC: {$eic}\n\n";
        
        echo "📋 Available DevPos Fields:\n";
        echo str_repeat("-", 80) . "\n";
        printf("%-30s %-15s %-35s\n", "Field Name", "Type", "Sample Value");
        echo str_repeat("-", 80) . "\n";
        
        foreach ($fields as $field => $info) {
            printf("%-30s %-15s %-35s\n", $field, $info['type'], $info['sample']);
        }
        
        echo "\n";
        echo "🔗 QuickBooks Field Mapping:\n";
        echo str_repeat("-", 80) . "\n";
        
        // Check date fields
        echo "\n📅 DATE FIELDS:\n";
        echo "   Checking in order: " . implode(', ', $mapping['date_fields']) . "\n";
        $foundDate = null;
        foreach ($mapping['date_fields'] as $dateField) {
            $exists = isset($bill[$dateField]);
            $marker = $exists ? '✅' : '❌';
            $value = $exists ? $bill[$dateField] : 'NOT FOUND';
            echo "   {$marker} {$dateField}: {$value}\n";
            if (!$foundDate && $exists) {
                $foundDate = $dateField;
            }
        }
        echo "   → Maps to QuickBooks: {$mapping['qbo_date']}\n";
        if ($foundDate) {
            echo "   → USING: {$foundDate} = {$bill[$foundDate]}\n";
        } else {
            echo "   → ⚠️  WARNING: No date field found! Will use today's date.\n";
        }
        
        // Check vendor fields
        echo "\n🏢 VENDOR FIELDS:\n";
        foreach ($mapping['vendor_fields'] as $vendorField) {
            $exists = isset($bill[$vendorField]);
            $marker = $exists ? '✅' : '❌';
            $value = $exists ? $bill[$vendorField] : 'NOT FOUND';
            echo "   {$marker} {$vendorField}: {$value}\n";
        }
        echo "   → Maps to QuickBooks: {$mapping['qbo_vendor']}\n";
        
        echo "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error fetching purchase bills: " . $e->getMessage() . "\n\n";
}

// ============================================================================
// 3. SUMMARY
// ============================================================================

echo str_repeat("=", 80) . "\n";
echo "📊 SUMMARY\n";
echo str_repeat("=", 80) . "\n\n";

echo "✅ KEY FINDINGS:\n\n";

echo "1. DATE FIELD MAPPING:\n";
echo "   The transformers now check these fields in order:\n";
echo "   • invoiceCreatedDate (PRIMARY - actual API field)\n";
echo "   • dateTimeCreated (alternative)\n";
echo "   • createdDate, issueDate, dateCreated (fallbacks)\n\n";

echo "2. AMOUNT FIELD MAPPING:\n";
echo "   • Checks: totalAmount → total → amount\n";
echo "   • ⚠️  Some invoices may have 0.0 in list responses\n";
echo "   • Consider fetching full invoice details via getEInvoiceByEIC()\n\n";

echo "3. CUSTOMER/VENDOR MAPPING:\n";
echo "   • DevPos provides: buyerName, buyerNuis (for invoices)\n";
echo "   • DevPos provides: sellerName, sellerNuis (for bills)\n";
echo "   • ⚠️  Currently HARDCODED to ID '1' - not using actual names/tax IDs\n";
echo "   • Recommendation: Implement customer/vendor lookup and auto-creation\n\n";

echo "4. LINE ITEMS:\n";
echo "   • ⚠️  List API endpoints don't include line item details\n";
echo "   • Current: Single line with total amount and generic 'Services' item\n";
echo "   • Recommendation: Fetch full invoice via getEInvoiceByEIC() for line items\n\n";

echo "5. PDF ATTACHMENTS:\n";
echo "   • DevPos may include 'pdf' field (base64 encoded)\n";
echo "   • If not in list, call getEInvoiceByEIC() to get PDF\n";
echo "   • Upload to QuickBooks using uploadAttachment()\n\n";

echo "📝 NEXT STEPS:\n\n";
echo "1. Run a sync job and verify dates are now correct\n";
echo "2. Implement customer/vendor mapping (CRITICAL)\n";
echo "3. Add line item support (fetch full invoice details)\n";
echo "4. Test PDF attachment upload\n\n";

echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                              Diagnostic Complete                             ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n\n";
