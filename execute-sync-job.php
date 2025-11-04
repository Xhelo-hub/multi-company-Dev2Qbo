<?php
/**
 * Execute sync job directly
 * This script runs in background to avoid HTTP timeout issues
 */

// Set unlimited execution time for long-running syncs
set_time_limit(0);
ini_set('memory_limit', '512M');

require __DIR__ . '/vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Database connection
$pdo = new PDO(
    sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $_ENV['DB_HOST'] ?? 'localhost',
        $_ENV['DB_NAME'] ?? 'Xhelo_qbo_devpos'
    ),
    $_ENV['DB_USER'] ?? 'Xhelo_qbo_user',
    $_ENV['DB_PASS'] ?? 'Albania@2030',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$jobId = $argv[1] ?? null;

if (!$jobId) {
    echo "Usage: php execute-sync-job.php <job_id>\n";
    exit(1);
}

echo "Executing sync job $jobId...\n\n";

$executor = new App\Services\SyncExecutor($pdo);
$executor->executeJob((int)$jobId);

echo "\n✅ Sync job executed. Checking results...\n\n";

// Show results
$stmt = $pdo->prepare("SELECT * FROM sync_jobs WHERE id = ?");
$stmt->execute([$jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

echo "Status: {$job['status']}\n";
echo "Results: {$job['results_json']}\n";
if ($job['error_message']) {
    echo "Error: {$job['error_message']}\n";
}
