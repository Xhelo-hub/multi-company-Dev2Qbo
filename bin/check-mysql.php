<?php
try {
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=qbo_multicompany', 'root', '');
    echo "✓ MySQL is running and qbo_multicompany database exists\n";
} catch (PDOException $e) {
    echo "✗ MySQL error: " . $e->getMessage() . "\n";
}
