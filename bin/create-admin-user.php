#!/usr/bin/env php
<?php

/**
 * Create Admin User Script
 * 
 * Run this to create the initial admin user
 */

echo "=== Create Admin User ===\n\n";

// Load environment
if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

try {
    $pdo = new PDO(
        "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_DATABASE']};charset=utf8mb4",
        $_ENV['DB_USERNAME'],
        $_ENV['DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    echo "Connected to database.\n\n";
    
    // Get input
    echo "Enter admin email: ";
    $email = trim(fgets(STDIN));
    
    echo "Enter full name: ";
    $fullName = trim(fgets(STDIN));
    
    echo "Enter password: ";
    $password = trim(fgets(STDIN));
    
    echo "Confirm password: ";
    $confirmPassword = trim(fgets(STDIN));
    
    if ($password !== $confirmPassword) {
        die("\n❌ Passwords do not match!\n");
    }
    
    if (strlen($password) < 8) {
        die("\n❌ Password must be at least 8 characters!\n");
    }
    
    // Check if user exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    
    if ($stmt->fetch()) {
        die("\n❌ User with this email already exists!\n");
    }
    
    // Create user
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("
        INSERT INTO users (email, password_hash, full_name, role, status, is_active, created_at)
        VALUES (?, ?, ?, 'admin', 'active', 1, NOW())
    ");
    
    $stmt->execute([$email, $passwordHash, $fullName]);
    $userId = (int)$pdo->lastInsertId();
    
    echo "\n✅ Admin user created successfully!\n";
    echo "User ID: $userId\n";
    echo "Email: $email\n";
    echo "Name: $fullName\n";
    
    // Grant access to all companies
    $stmt = $pdo->query("SELECT id FROM companies WHERE is_active = 1");
    $companies = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($companies)) {
        $stmt = $pdo->prepare("
            INSERT INTO user_company_access 
            (user_id, company_id, can_view_sync, can_run_sync, can_edit_credentials, can_manage_schedules)
            VALUES (?, ?, 1, 1, 1, 1)
        ");
        
        foreach ($companies as $companyId) {
            $stmt->execute([$userId, $companyId]);
        }
        
        echo "\n✅ Granted access to " . count($companies) . " companies\n";
    }
    
    echo "\nYou can now login at:\n";
    echo "  URL: " . ($_ENV['APP_URL'] ?? 'https://devsync.konsulence.al') . "/login.html\n";
    echo "  Email: $email\n";
    echo "  Password: [the password you just entered]\n\n";
    
} catch (PDOException $e) {
    echo "❌ Database error: " . $e->getMessage() . "\n";
    exit(1);
}
