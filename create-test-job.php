<?php
/**
 * Create a test sync job
 */

require __DIR__ . '/vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Database connection
$pdo = new PDO(
    'mysql:host=localhost;dbname=Xhelo_qbo_devpos',
    'Xhelo_qbo_user',
    'Albania@2030',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$stmt = $pdo->prepare("
    INSERT INTO sync_jobs (company_id, job_type, from_date, to_date, status, created_at)
    VALUES (1, 'sales', '2025-01-01', '2025-10-26', 'pending', NOW())
");
$stmt->execute();

$jobId = $pdo->lastInsertId();
echo "✅ Created sync job ID: $jobId\n";
