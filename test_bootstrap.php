<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/bootstrap/app.php';

echo "Bootstrap loaded successfully\n";
echo "PDO class available: " . (class_exists('PDO') ? 'YES' : 'NO') . "\n";
echo "EmailService class available: " . (class_exists('App\Services\EmailService') ? 'YES' : 'NO') . "\n";
