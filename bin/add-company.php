<?php
/**
 * Quick Company Add Tool
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

try {
    $pdo = new PDO(
        $_ENV['DB_DSN'] ?? 'mysql:host=127.0.0.1;dbname=qbo_multicompany',
        $_ENV['DB_USER'] ?? 'root',
        $_ENV['DB_PASS'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    echo "Add New Company\n";
    echo str_repeat("=", 60) . "\n\n";
    
    $code = readline("Company Code (e.g., AEM, PGROUP): ");
    $name = readline("Company Name: ");
    $active = readline("Active? (yes/no) [yes]: ") ?: 'yes';
    
    if (empty($code) || empty($name)) {
        die("Company code and name are required\n");
    }
    
    $stmt = $pdo->prepare("INSERT INTO companies (company_code, company_name, is_active) VALUES (?, ?, ?)");
    $stmt->execute([$code, $name, $active === 'yes' ? 1 : 0]);
    
    $companyId = $pdo->lastInsertId();
    
    echo "\n✓ Company added!\n";
    echo "  ID: {$companyId}\n";
    echo "  Code: {$code}\n";
    echo "  Name: {$name}\n\n";
    
    echo "Next steps:\n";
    echo "1. Connect to QuickBooks:\n";
    echo "   php bin/qbo-connect-auto.php\n\n";
    echo "2. Add DevPos credentials (once you have client_id):\n";
    echo "   php bin/company-manager.php\n";
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage() . "\n");
}
