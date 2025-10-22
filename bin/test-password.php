<?php

try {
    $pdo = new PDO('mysql:host=localhost;dbname=qbo_multicompany', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->query("SELECT password_hash FROM users WHERE email = 'admin@devpos-sync.local'");
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "Password hash: " . $user['password_hash'] . "\n\n";
        
        $testPassword = 'admin123';
        $verified = password_verify($testPassword, $user['password_hash']);
        
        echo "Testing password '$testPassword': " . ($verified ? "✅ VERIFIED" : "❌ FAILED") . "\n";
    } else {
        echo "User not found!\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
