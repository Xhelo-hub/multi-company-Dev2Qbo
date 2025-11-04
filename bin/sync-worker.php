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

// Function to create/reconnect PDO
function createPDO() {
    return new PDO(
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
            PDO::ATTR_TIMEOUT => 5,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION wait_timeout=28800",
        ]
    );
}

// Initial database connection
$pdo = createPDO();

echo "🔄 Sync Worker Started - " . date('Y-m-d H:i:s') . "\n";
echo "Polling for pending jobs every 5 seconds...\n";
echo "Press Ctrl+C to stop.\n\n";

$processedCount = 0;
$lastHealthCheck = time();

while (true) {
    try {
        // Health check - reconnect every 5 minutes to avoid timeout
        if (time() - $lastHealthCheck > 300) {
            try {
                $pdo->query("SELECT 1");
            } catch (Exception $e) {
                echo "🔌 Reconnecting to database...\n";
                $pdo = createPDO();
            }
            $lastHealthCheck = time();
        }
        
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
            
            // Execute the job with fresh connection
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
            
            // Reconnect after processing job to avoid stale connection
            $pdo = createPDO();
            $lastHealthCheck = time();
            
            // Don't sleep if we just processed a job (check for more immediately)
            continue;
        }
        
        // No pending jobs - sleep for 5 seconds
        sleep(5);
        
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'MySQL server has gone away') !== false || 
            strpos($e->getMessage(), 'Lost connection') !== false) {
            echo "🔌 Database connection lost. Reconnecting...\n";
            try {
                $pdo = createPDO();
                $lastHealthCheck = time();
                echo "✅ Reconnected successfully\n\n";
            } catch (Exception $reconnectError) {
                echo "❌ Reconnection failed: " . $reconnectError->getMessage() . "\n";
                echo "   Retrying in 10 seconds...\n\n";
                sleep(10);
            }
        } else {
            echo "⚠️  Database error: " . $e->getMessage() . "\n";
            echo "   Retrying in 10 seconds...\n\n";
            sleep(10);
        }
    } catch (Exception $e) {
        echo "⚠️  Worker error: " . $e->getMessage() . "\n";
        echo "   Retrying in 10 seconds...\n\n";
        sleep(10);
    }
}
