<?php
/**
 * Check bill 1308/2025 for any EUR currency information
 */

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'Xhelo_qbo_devpos';
$dbUser = $_ENV['DB_USER'] ?? 'root';
$dbPass = $_ENV['DB_PASS'] ?? '';

try {
    $db = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("❌ Database connection failed: " . $e->getMessage() . "\n");
}

echo "=== Checking Bill 1308/2025 for EUR Currency ===\n\n";

// Check invoice_mappings table
echo "1. Invoice Mappings Table:\n";
$stmt = $db->query("
    SELECT 
        id, 
        company_id, 
        devpos_document_number, 
        transaction_type,
        currency,
        vat_currency,
        exchange_rate,
        amount,
        devpos_eic,
        qbo_id,
        sync_status,
        created_at,
        last_sync_at
    FROM invoice_mappings 
    WHERE devpos_document_number LIKE '%1308%' 
    AND company_id = 28
    ORDER BY id DESC
");

$bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($bills)) {
    echo "   ❌ No records found for bill 1308/2025\n\n";
} else {
    foreach ($bills as $bill) {
        echo "   Record ID: {$bill['id']}\n";
        echo "   Document Number: {$bill['devpos_document_number']}\n";
        echo "   Transaction Type: {$bill['transaction_type']}\n";
        echo "   Currency: {$bill['currency']}\n";
        echo "   VAT Currency: {$bill['vat_currency']}\n";
        echo "   Exchange Rate: " . ($bill['exchange_rate'] ?: 'NULL') . "\n";
        echo "   Amount: {$bill['amount']}\n";
        echo "   DevPos EIC: {$bill['devpos_eic']}\n";
        echo "   QBO ID: {$bill['qbo_id']}\n";
        echo "   Sync Status: {$bill['sync_status']}\n";
        echo "   Created: {$bill['created_at']}\n";
        echo "   Last Sync: " . ($bill['last_sync_at'] ?: 'Never') . "\n";
        echo "   " . str_repeat('-', 60) . "\n";
    }
}

// Check for any EUR references in the entire database
echo "\n2. Searching for EUR currency in all invoice_mappings:\n";
$stmt = $db->query("
    SELECT COUNT(*) as count
    FROM invoice_mappings 
    WHERE (currency = 'EUR' OR vat_currency = 'EUR')
    AND company_id = 28
");
$eurCount = $stmt->fetch(PDO::FETCH_ASSOC);
echo "   Found {$eurCount['count']} records with EUR currency for company 28\n";

if ($eurCount['count'] > 0) {
    echo "\n   Sample EUR records:\n";
    $stmt = $db->query("
        SELECT 
            devpos_document_number,
            transaction_type,
            currency,
            vat_currency,
            exchange_rate,
            amount
        FROM invoice_mappings 
        WHERE (currency = 'EUR' OR vat_currency = 'EUR')
        AND company_id = 28
        LIMIT 5
    ");
    $eurRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($eurRecords as $record) {
        echo "   - {$record['devpos_document_number']}: {$record['currency']}/{$record['vat_currency']}, Rate: {$record['exchange_rate']}, Amount: {$record['amount']}\n";
    }
}

// Check QuickBooks bill details
echo "\n3. Checking if bill exists in QuickBooks:\n";
$billMapping = $bills[0] ?? null;
if ($billMapping && $billMapping['qbo_id']) {
    echo "   QBO Bill ID: {$billMapping['qbo_id']}\n";
    echo "   You can verify the currency in QuickBooks by checking bill #{$billMapping['qbo_id']}\n";
} else {
    echo "   ❌ No QuickBooks ID found - bill may not be synced yet\n";
}

// Check recent worker logs for this bill
echo "\n4. Searching worker logs for bill 1308/2025:\n";
$logFile = __DIR__ . '/logs/worker.log';
if (file_exists($logFile)) {
    $logs = shell_exec("grep -i '1308/2025' " . escapeshellarg($logFile) . " | tail -20");
    if ($logs) {
        echo "   Recent log entries:\n";
        echo "   " . str_replace("\n", "\n   ", trim($logs)) . "\n";
    } else {
        echo "   No log entries found for bill 1308/2025\n";
    }
} else {
    echo "   ❌ Worker log file not found\n";
}

echo "\n";
echo str_repeat('=', 70) . "\n";
echo "Summary:\n";
echo str_repeat('=', 70) . "\n";

if (!empty($bills)) {
    $bill = $bills[0];
    if ($bill['currency'] === 'EUR' || $bill['vat_currency'] === 'EUR') {
        echo "✅ Bill 1308/2025 IS stored with EUR currency!\n";
        echo "   Currency: {$bill['currency']}\n";
        echo "   VAT Currency: {$bill['vat_currency']}\n";
        echo "   Exchange Rate: " . ($bill['exchange_rate'] ?: 'NOT SET') . "\n";
    } else {
        echo "❌ Bill 1308/2025 is stored as: {$bill['currency']} / {$bill['vat_currency']}\n";
        echo "   This should be EUR according to DevPos!\n";
        echo "\n";
        echo "Possible reasons:\n";
        echo "   1. DevPos API doesn't return currency in GetPurchaseInvoice response\n";
        echo "   2. Detailed invoice fetch is failing\n";
        echo "   3. Currency detection logic defaulting to ALL\n";
    }
} else {
    echo "❌ Bill 1308/2025 not found in database\n";
}
