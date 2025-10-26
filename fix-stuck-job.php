<?php
require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$pdo = new PDO(
    "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS']
);

$jobId = $argv[1] ?? null;

if (!$jobId) {
    echo "Usage: php fix-stuck-job.php <job_id>\n";
    exit(1);
}

// Get job details
$stmt = $pdo->prepare("SELECT * FROM sync_jobs WHERE id = ?");
$stmt->execute([$jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    echo "❌ Job not found: $jobId\n";
    exit(1);
}

echo "Job Details:\n";
echo "  ID: {$job['id']}\n";
echo "  Company: {$job['company_id']}\n";
echo "  Type: {$job['job_type']}\n";
echo "  Status: {$job['status']}\n";
echo "  Started: {$job['started_at']}\n";
echo "  Completed: {$job['completed_at']}\n";
echo "\n";

if ($job['status'] === 'running') {
    $minutes = round((time() - strtotime($job['started_at'])) / 60, 1);
    echo "⚠️  Job has been running for $minutes minutes\n";
    echo "   Marking as failed (timeout)...\n";
    
    $stmt = $pdo->prepare("
        UPDATE sync_jobs 
        SET status = 'failed', 
            completed_at = NOW(),
            error_message = CONCAT('Job timed out after ', ?, ' minutes')
        WHERE id = ?
    ");
    $stmt->execute([$minutes, $jobId]);
    
    echo "✅ Job marked as failed\n";
} else {
    echo "✅ Job is not stuck (status: {$job['status']})\n";
}
