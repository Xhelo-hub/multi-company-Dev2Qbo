<?php
/**
 * Test getEInvoiceByEIC fix - verify currency data is returned
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Http\DevposClient;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$pdo = new PDO(
    "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']}",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$companyId = (int)($argv[1] ?? 1);
$specificEic = $argv[2] ?? null;

echo "\n";
echo "=============================================================================\n";
echo "  Testing getEInvoiceByEIC Fix - Currency Data Retrieval                    \n";
echo "=============================================================================\n\n";

// Get company info
$stmt = $pdo->prepare("SELECT company_name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$company) {
    echo "Company ID {$companyId} not found\n";
    exit(1);
}

echo "Company: {$company['company_name']} (ID: {$companyId})\n\n";

try {
    $client = new DevposClient($pdo, $companyId);

    // If no EIC specified, fetch recent invoices to find one
    if (!$specificEic) {
        echo "Fetching recent invoices to find an EIC to test...\n";

        $fromDate = date('Y-m-d', strtotime('-30 days'));
        $toDate = date('Y-m-d');

        $invoices = $client->fetchSalesEInvoices($fromDate, $toDate);

        if (empty($invoices)) {
            echo "No invoices found in the last 30 days\n";
            exit(1);
        }

        echo "Found " . count($invoices) . " invoice(s)\n\n";

        // Show first few invoices
        echo "Recent invoices from list API:\n";
        echo str_repeat("-", 70) . "\n";
        printf("%-36s %-15s\n", "EIC", "Document #");
        echo str_repeat("-", 70) . "\n";

        for ($i = 0; $i < min(5, count($invoices)); $i++) {
            $inv = $invoices[$i];
            $eic = $inv['eic'] ?? $inv['EIC'] ?? 'N/A';
            $docNum = $inv['documentNumber'] ?? $inv['DocNumber'] ?? 'N/A';
            printf("%-36s %-15s\n", substr($eic, 0, 34), $docNum);
        }
        echo "\n";

        $specificEic = $invoices[0]['eic'] ?? $invoices[0]['EIC'];
    }

    echo "Testing getEInvoiceByEIC with EIC: {$specificEic}\n";
    echo str_repeat("=", 75) . "\n\n";

    $detail = $client->getEInvoiceByEIC($specificEic);

    echo "SUCCESS! Got detailed invoice data.\n\n";

    // Check for currency fields
    echo "CURRENCY-RELATED FIELDS:\n";
    echo str_repeat("-", 50) . "\n";

    $currencyFields = [
        'currencyCode',
        'CurrencyCode',
        'currency',
        'Currency',
        'exchangeRate',
        'ExchangeRate',
        'vatCurrency',
        'baseCurrency'
    ];

    $foundCurrency = false;
    foreach ($currencyFields as $field) {
        if (isset($detail[$field])) {
            echo "  [FOUND] {$field}: {$detail[$field]}\n";
            $foundCurrency = true;
        }
    }

    if (!$foundCurrency) {
        echo "  [WARNING] No currency fields found!\n";

        // Search all fields for currency-related keywords
        echo "\n  Searching all fields for currency-related data...\n";
        foreach ($detail as $key => $value) {
            if (stripos($key, 'curr') !== false || stripos($key, 'rate') !== false ||
                stripos($key, 'exchange') !== false || stripos($key, 'monedh') !== false) {
                $displayVal = is_array($value) ? json_encode($value) : $value;
                echo "  [MATCH] {$key}: {$displayVal}\n";
            }
        }
    }

    echo "\n";
    echo "ALL FIELDS IN RESPONSE:\n";
    echo str_repeat("-", 50) . "\n";

    foreach ($detail as $key => $value) {
        if (is_array($value)) {
            echo "  {$key}: [array with " . count($value) . " items]\n";
        } else {
            $displayVal = $value;
            if (is_string($value) && strlen($value) > 50) {
                $displayVal = substr($value, 0, 47) . '...';
            }
            echo "  {$key}: {$displayVal}\n";
        }
    }

    echo "\n=============================================================================\n";
    echo "  Test Complete                                                            \n";
    echo "=============================================================================\n\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
