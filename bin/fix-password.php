<?php

$pdo = new PDO('mysql:host=localhost;dbname=qbo_multicompany', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$newHash = password_hash('admin123', PASSWORD_DEFAULT);
$stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE email = ?');
$stmt->execute([$newHash, 'admin@devpos-sync.local']);

echo "✅ Admin password updated to: admin123\n";
echo "New hash: $newHash\n";
