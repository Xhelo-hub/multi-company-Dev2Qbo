<?php
require __DIR__ . '/vendor/autoload.php';

echo '<h2>Server Diagnostic</h2>';
echo '<p>PHP Version: ' . phpversion() . '</p>';

if (file_exists(__DIR__ . '/.env')) {
    echo '<p>✅ .env file exists</p>';
    try {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
        $dotenv->load();
        echo '<p>✅ .env loaded successfully</p>';
        echo '<p>DB_USER: ' . ($_ENV['DB_USER'] ?? 'NOT SET') . '</p>';
        echo '<p>DB_DSN: ' . ($_ENV['DB_DSN'] ?? 'NOT SET') . '</p>';
        echo '<p>APP_ENV: ' . ($_ENV['APP_ENV'] ?? 'NOT SET') . '</p>';
        
        // Test database connection
        $pdo = new PDO($_ENV['DB_DSN'], $_ENV['DB_USER'], $_ENV['DB_PASS']);
        echo '<p style="color:green">✅ Database connection successful!</p>';
        
        $stmt = $pdo->query('SELECT COUNT(*) as count FROM companies');
        $result = $stmt->fetch();
        echo '<p>Companies in database: ' . $result['count'] . '</p>';
        
    } catch (Exception $e) {
        echo '<p style="color:red">❌ Error: ' . $e->getMessage() . '</p>';
    }
} else {
    echo '<p style="color:red">❌ .env file not found</p>';
}
