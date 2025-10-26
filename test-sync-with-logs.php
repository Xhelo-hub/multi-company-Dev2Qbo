<?php
/**
 * Test sync with logging
 */

require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$companyId = $argv[1] ?? 1;
$syncType = $argv[2] ?? 'sales';
$fromDate = $argv[3] ?? '2025-01-01';
$toDate = $argv[4] ?? '2025-10-26';

echo "🚀 Starting test sync...\n";
echo "   Company ID: $companyId\n";
echo "   Type: $syncType\n";
echo "   Date range: $fromDate to $toDate\n";
echo "\n";
echo "📝 Watch logs in another terminal:\n";
echo "   ssh root@78.46.201.151\n";
echo "   journalctl -u php8.3-fpm -f --no-pager | grep -E 'sync|invoice'\n";
echo "\n";
echo str_repeat('=', 80) . "\n";

// Database connection
$pdo = new PDO(
    "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS']
);

// Create sync job
$stmt = $pdo->prepare("
    INSERT INTO sync_jobs (company_id, job_type, from_date, to_date, trigger_source, status)
    VALUES (?, ?, ?, ?, 'manual', 'pending')
");
$stmt->execute([$companyId, $syncType, $fromDate, $toDate]);
$jobId = $pdo->lastInsertId();

echo "✅ Sync job created: ID $jobId\n";
echo "🔄 Triggering sync via API...\n\n";

// Trigger the sync via API
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "http://localhost/api/sync/execute/$jobId",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json']
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Response Code: $httpCode\n";
echo "Response: $response\n\n";

// Check job status
echo str_repeat('=', 80) . "\n";
echo "📊 Final Job Status:\n\n";

$stmt = $pdo->prepare("SELECT * FROM sync_jobs WHERE id = ?");
$stmt->execute([$jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

echo "Status: {$job['status']}\n";
echo "Started: {$job['started_at']}\n";
echo "Completed: {$job['completed_at']}\n";
echo "Results: {$job['results_json']}\n";
if ($job['error_message']) {
    echo "Error: {$job['error_message']}\n";
}

echo "\n✅ Test completed. Check logs above for detailed progress.\n";
