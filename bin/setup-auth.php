#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Setup script for multi-tenant authentication system
 * Run this script to:
 * 1. Load user management schema
 * 2. Create default admin user
 * 3. Verify database connection
 */

echo "==============================================\n";
echo "Multi-Company Sync - Authentication Setup\n";
echo "==============================================\n\n";

// Load .env file
if (!file_exists(__DIR__ . '/../.env')) {
    die("❌ Error: .env file not found. Please create it from .env.example\n");
}

$env = parse_ini_file(__DIR__ . '/../.env');

// Database connection details
$host = $env['DB_HOST'] ?? 'localhost';
$dbname = $env['DB_DATABASE'] ?? 'qbo_multicompany';
$username = $env['DB_USERNAME'] ?? 'root';
$password = $env['DB_PASSWORD'] ?? '';

echo "📊 Database Configuration:\n";
echo "   Host: $host\n";
echo "   Database: $dbname\n";
echo "   Username: $username\n\n";

try {
    // Connect to MySQL server
    $pdo = new PDO(
        "mysql:host=$host",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "✅ Connected to MySQL server\n";

    // Check if database exists
    $stmt = $pdo->query("SHOW DATABASES LIKE '$dbname'");
    if ($stmt->rowCount() === 0) {
        echo "⚠️  Database '$dbname' does not exist\n";
        echo "   Creating database...\n";
        $pdo->exec("CREATE DATABASE `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        echo "✅ Database created\n";
    } else {
        echo "✅ Database exists\n";
    }

    // Switch to target database
    $pdo->exec("USE `$dbname`");

    // Check if user tables already exist
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($stmt->rowCount() > 0) {
        echo "\n⚠️  User tables already exist!\n";
        echo "   Do you want to recreate them? This will DELETE ALL USER DATA! (yes/no): ";
        
        $handle = fopen("php://stdin", "r");
        $line = trim(fgets($handle));
        fclose($handle);
        
        if (strtolower($line) !== 'yes') {
            echo "\n❌ Setup cancelled. No changes made.\n";
            exit(1);
        }
        
        echo "\n🗑️  Dropping existing tables...\n";
        $pdo->exec("DROP TABLE IF EXISTS user_sessions");
        $pdo->exec("DROP TABLE IF EXISTS user_company_access");
        $pdo->exec("DROP TABLE IF EXISTS users");
        echo "✅ Old tables dropped\n";
    }

    echo "\n📥 Loading user management schema...\n";

    // Read SQL file
    $sqlFile = __DIR__ . '/../sql/user-management.sql';
    if (!file_exists($sqlFile)) {
        die("❌ Error: SQL file not found at $sqlFile\n");
    }

    $sql = file_get_contents($sqlFile);

    // Execute SQL statements
    $pdo->exec($sql);

    echo "✅ User management schema loaded\n";

    // Verify admin user was created
    $stmt = $pdo->query("SELECT * FROM users WHERE role = 'admin'");
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($admin) {
        echo "\n✅ Default admin user created:\n";
        echo "   Email: {$admin['email']}\n";
        echo "   Password: admin123\n";
        echo "   ⚠️  PLEASE CHANGE THIS PASSWORD IMMEDIATELY!\n";
    }

    // Count existing companies
    $stmt = $pdo->query("SELECT COUNT(*) FROM companies WHERE is_active = 1");
    $companyCount = $stmt->fetchColumn();

    echo "\n📊 Companies in database: $companyCount\n";

    if ($companyCount > 0 && $admin) {
        // Grant admin access to all companies
        $stmt = $pdo->query("SELECT id, company_code, company_name FROM companies WHERE is_active = 1");
        $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo "\n🔐 Granting admin access to all companies:\n";
        
        $insertStmt = $pdo->prepare("
            INSERT IGNORE INTO user_company_access 
            (user_id, company_id, can_view_sync, can_run_sync, can_edit_credentials, can_manage_schedules)
            VALUES (?, ?, 1, 1, 1, 1)
        ");

        foreach ($companies as $company) {
            $insertStmt->execute([$admin['id'], $company['id']]);
            echo "   ✓ {$company['company_code']} - {$company['company_name']}\n";
        }
    }

    echo "\n==============================================\n";
    echo "✅ Setup completed successfully!\n";
    echo "==============================================\n\n";

    echo "🚀 Next steps:\n";
    echo "   1. Navigate to: http://localhost:8081/login.html\n";
    echo "   2. Login with: admin@devpos-sync.local / admin123\n";
    echo "   3. Configure company credentials in the dashboard\n";
    echo "   4. Start syncing!\n\n";

    echo "📝 Important:\n";
    echo "   - Change the default admin password immediately\n";
    echo "   - Create additional users from the admin panel\n";
    echo "   - Assign users to specific companies with granular permissions\n\n";

} catch (PDOException $e) {
    echo "\n❌ Database error: " . $e->getMessage() . "\n";
    echo "\nPlease check:\n";
    echo "   - MySQL server is running\n";
    echo "   - Database credentials in .env are correct\n";
    echo "   - User has permissions to create databases and tables\n\n";
    exit(1);
}
