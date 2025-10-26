<?php
/**
 * Execute sync job directly
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/bootstrap/app.php';

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
