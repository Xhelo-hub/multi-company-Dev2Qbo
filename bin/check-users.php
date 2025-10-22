<?php

try {
    $pdo = new PDO('mysql:host=localhost;dbname=qbo_multicompany', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Users in database:\n\n";
    $stmt = $pdo->query('SELECT id, email, role, is_active, created_at FROM users');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        print_r($row);
    }
    
    echo "\n\nUser company access:\n\n";
    $stmt = $pdo->query('SELECT * FROM user_company_access');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        print_r($row);
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
