<?php
/**
 * Fetch and display available accounts from QuickBooks
 * Usage: php check-qbo-accounts.php <company_id>
 */

require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $_ENV['DB_HOST'] ?? 'localhost',
        $_ENV['DB_NAME'] ?? 'Xhelo_qbo_devpos'
    ),
    $_ENV['DB_USER'] ?? 'Xhelo_qbo_user',
    $_ENV['DB_PASS'] ?? 'Albania@2030',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$companyId = $argv[1] ?? null;
if (!$companyId) {
    die("Usage: php check-qbo-accounts.php <company_id>\n");
}

echo "Fetching QuickBooks accounts for company $companyId...\n\n";

// Get QBO credentials
$stmt = $pdo->prepare("SELECT realm_id, access_token, refresh_token FROM company_credentials_qbo WHERE company_id = ?");
$stmt->execute([$companyId]);
$qbo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$qbo) {
    die("QuickBooks not connected for company $companyId\n");
}

// Fetch accounts from QuickBooks
$client = new GuzzleHttp\Client();
$url = "https://quickbooks.api.intuit.com/v3/company/{$qbo['realm_id']}/query";
$query = "SELECT * FROM Account WHERE AccountType IN ('Expense', 'Cost of Goods Sold', 'Other Expense') MAXRESULTS 50";

try {
    $response = $client->request('GET', $url, [
        'query' => ['query' => $query, 'minorversion' => '65'],
        'headers' => [
            'Authorization' => 'Bearer ' . $qbo['access_token'],
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ]
    ]);
    
    $data = json_decode($response->getBody()->getContents(), true);
    
    if (isset($data['QueryResponse']['Account'])) {
        $accounts = $data['QueryResponse']['Account'];
        
        echo "Found " . count($accounts) . " expense accounts:\n\n";
        echo str_repeat('=', 80) . "\n";
        printf("%-10s %-35s %-25s %s\n", "ID", "Name", "Type", "SubAccount");
        echo str_repeat('=', 80) . "\n";
        
        foreach ($accounts as $account) {
            printf(
                "%-10s %-35s %-25s %s\n",
                $account['Id'],
                substr($account['Name'], 0, 35),
                $account['AccountType'] ?? '',
                isset($account['ParentRef']) ? 'Yes' : 'No'
            );
        }
        
        echo str_repeat('=', 80) . "\n\n";
        echo "✅ Suggested account IDs for bills:\n";
        
        // Find Cost of Goods Sold accounts
        $cogsAccounts = array_filter($accounts, fn($a) => $a['AccountType'] === 'Cost of Goods Sold');
        if ($cogsAccounts) {
            $firstCogs = reset($cogsAccounts);
            echo "  - Cost of Goods Sold: ID {$firstCogs['Id']} ({$firstCogs['Name']})\n";
        }
        
        // Find regular Expense accounts
        $expenseAccounts = array_filter($accounts, fn($a) => $a['AccountType'] === 'Expense' && !isset($a['ParentRef']));
        if ($expenseAccounts) {
            $firstExpense = reset($expenseAccounts);
            echo "  - General Expense: ID {$firstExpense['Id']} ({$firstExpense['Name']})\n";
        }
        
        echo "\nTo set default expense account, add to .env:\n";
        echo "QBO_DEFAULT_EXPENSE_ACCOUNT=" . ($firstCogs['Id'] ?? $firstExpense['Id'] ?? '1') . "\n";
        
    } else {
        echo "No accounts found or error in response\n";
        echo json_encode($data, JSON_PRETTY_PRINT) . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    if (method_exists($e, 'getResponse')) {
        echo $e->getResponse()->getBody()->getContents() . "\n";
    }
}
