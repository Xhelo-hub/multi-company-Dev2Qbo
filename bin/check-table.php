<?php

// Check companies table structure
try {
    $pdo = new PDO('mysql:host=localhost;dbname=qbo_multicompany', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Companies table structure:\n\n";
    $stmt = $pdo->query('DESCRIBE companies');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo sprintf("%-20s %-20s %s\n", $row['Field'], $row['Type'], $row['Key']);
    }
    
    echo "\n\nSample company record:\n\n";
    $stmt = $pdo->query('SELECT * FROM companies LIMIT 1');
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
    print_r($company);
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
