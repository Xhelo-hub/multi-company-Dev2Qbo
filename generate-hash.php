<?php
// Generate password hash for admin123
$password = 'admin123';
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "Password: $password\n";
echo "Hash: $hash\n";

// Verify it works
$verify = password_verify($password, $hash);
echo "Verify: " . ($verify ? 'SUCCESS' : 'FAILED') . "\n";

// Update the admin user with this hash
require 'vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$pdo = new PDO($_ENV['DB_DSN'], $_ENV['DB_USER'], $_ENV['DB_PASS']);

$stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = 'admin@devpos-sync.local'");
$stmt->execute([$hash]);

echo "Updated admin user password hash\n";

// Also update kontakt@konsulence.al with Albania@2030
$hash2 = password_hash('Albania@2030', PASSWORD_DEFAULT);
$stmt2 = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = 'kontakt@konsulence.al'");
$stmt2->execute([$hash2]);

echo "Updated kontakt@konsulence.al password hash\n";
