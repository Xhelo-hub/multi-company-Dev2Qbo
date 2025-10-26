<?php
require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$pdo = new PDO(
    "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS']
);

echo "🔍 Checking running sync jobs...\n\n";

$stmt = $pdo->query("
    SELECT id, company_id, sync_type, status, started_at, completed_at,
           TIMESTAMPDIFF(MINUTE, started_at, NOW()) as minutes_running
    FROM sync_jobs 
    WHERE status = 'running' 
    ORDER BY started_at DESC 
    LIMIT 10
");

$running = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($running)) {
    echo "✅ No running jobs found\n";
} else {
    echo "⚠️  Found " . count($running) . " running job(s):\n\n";
    foreach ($running as $job) {
        echo "Job ID: {$job['id']}\n";
        echo "  Company ID: {$job['company_id']}\n";
        echo "  Type: {$job['sync_type']}\n";
        echo "  Started: {$job['started_at']}\n";
        echo "  Running for: {$job['minutes_running']} minutes\n";
        echo "  " . str_repeat('-', 60) . "\n";
    }
}

echo "\n📊 Recent jobs (last 10):\n\n";

$stmt = $pdo->query("
    SELECT id, company_id, sync_type, status, started_at, completed_at,
           TIMESTAMPDIFF(SECOND, started_at, COALESCE(completed_at, NOW())) as duration_seconds
    FROM sync_jobs 
    ORDER BY started_at DESC 
    LIMIT 10
");

$recent = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($recent as $job) {
    $status = $job['status'];
    $icon = $status == 'completed' ? '✅' : ($status == 'running' ? '🔄' : '❌');
    $duration = round($job['duration_seconds'] / 60, 1);
    echo "$icon Job {$job['id']}: {$job['sync_type']} - $status ({$duration} min)\n";
}
