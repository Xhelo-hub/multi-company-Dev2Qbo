<?php
/**
 * Quick diagnostic - test if production API is responding with valid JSON
 */

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    require_once __DIR__ . '/vendor/autoload.php';
    
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
    
    // Test database connection
    $pdo = new PDO(
        'mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'],
        $_ENV['DB_USER'],
        $_ENV['DB_PASS']
    );
    
    // Test query
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $userCount = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Test if tables exist
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    $hasSessions = in_array('user_sessions', $tables);
    $hasAccess = in_array('user_company_access', $tables);
    $hasAudit = in_array('audit_logs', $tables);
    
    echo json_encode([
        'status' => 'ok',
        'database' => 'connected',
        'users' => $userCount['count'],
        'tables' => [
            'user_sessions' => $hasSessions,
            'user_company_access' => $hasAccess,
            'audit_logs' => $hasAudit,
            'total' => count($tables)
        ],
        'php_version' => PHP_VERSION,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_PRETTY_PRINT);
}
