#!/usr/bin/env php
<?php
/**
 * Background worker to process pending sync jobs
 * Run this with: php bin/sync-worker.php
 * Or as a systemd service or cron job
 */

// Set unlimited execution time
set_time_limit(0);
ini_set('memory_limit', '512M');

require __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
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

echo "🔄 Sync Worker Started - " . date('Y-m-d H:i:s') . "\n";
echo "Polling for pending jobs every 5 seconds...\n";
echo "Press Ctrl+C to stop.\n\n";

$processedCount = 0;

while (true) {
    try {
        // Get next pending job
        $stmt = $pdo->query("
            SELECT id, company_id, job_type, from_date, to_date
            FROM sync_jobs
            WHERE status = 'pending'
            ORDER BY created_at ASC
            LIMIT 1
        ");
        
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($job) {
            $jobId = $job['id'];
            echo "📦 Processing Job #{$jobId} - Company {$job['company_id']}, Type: {$job['job_type']}\n";
            echo "   Date Range: {$job['from_date']} to {$job['to_date']}\n";
            
            // Execute the job
            $executor = new App\Services\SyncExecutor($pdo);
            
            try {
                $result = $executor->executeJob($jobId);
                $processedCount++;
                
                echo "✅ Job #{$jobId} completed successfully!\n";
                if (isset($result['results'])) {
                    echo "   Results: " . json_encode($result['results']) . "\n";
                }
                echo "\n";
                
            } catch (Exception $e) {
                echo "❌ Job #{$jobId} failed: " . $e->getMessage() . "\n\n";
            }
            
            // Don't sleep if we just processed a job (check for more immediately)
            continue;
        }
        
        // No pending jobs - sleep for 5 seconds
        sleep(5);
        
    } catch (Exception $e) {
        echo "⚠️  Worker error: " . $e->getMessage() . "\n";
        echo "   Retrying in 10 seconds...\n\n";
        sleep(10);
    }
}
