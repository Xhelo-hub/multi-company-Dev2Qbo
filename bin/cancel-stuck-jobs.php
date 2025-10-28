#!/usr/bin/env php
<?php

/**
 * Cancel Stuck Sync Jobs
 * 
 * This script should be run periodically (e.g., every 15 minutes via cron)
 * to automatically cancel sync jobs that have been running too long.
 */

require __DIR__ . '/../vendor/autoload.php';

// Load environment
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Configuration
$TIMEOUT_MINUTES = 30; // Jobs running longer than this will be cancelled

try {
    // Database connection
    $pdo = new PDO(
        "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8mb4",
        $_ENV['DB_USER'],
        $_ENV['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Find stuck jobs
    $stmt = $pdo->prepare("
        SELECT id, company_id, job_type, started_at, 
               TIMESTAMPDIFF(MINUTE, started_at, NOW()) as minutes_running
        FROM sync_jobs 
        WHERE status = 'running' 
        AND started_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ORDER BY started_at ASC
    ");
    
    $stmt->execute([$TIMEOUT_MINUTES]);
    $stuckJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($stuckJobs)) {
        echo "[" . date('Y-m-d H:i:s') . "] No stuck jobs found.\n";
        exit(0);
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] Found " . count($stuckJobs) . " stuck job(s):\n";
    
    foreach ($stuckJobs as $job) {
        echo "  - Job #{$job['id']} (Company {$job['company_id']}, {$job['job_type']}): ";
        echo "Running for {$job['minutes_running']} minutes\n";
    }
    
    // Cancel them
    $stmt = $pdo->prepare("
        UPDATE sync_jobs 
        SET status = 'failed',
            error_message = CONCAT('Job timeout - exceeded ', ?, ' minutes'),
            completed_at = NOW()
        WHERE status = 'running' 
        AND started_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
    ");
    
    $stmt->execute([$TIMEOUT_MINUTES, $TIMEOUT_MINUTES]);
    $cancelledCount = $stmt->rowCount();
    
    echo "[" . date('Y-m-d H:i:s') . "] Successfully cancelled {$cancelledCount} job(s).\n";
    
    // Log to audit (if audit_logs table exists)
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
            VALUES (NULL, 'sync.auto_timeout', 'sync_job', NULL, ?, '127.0.0.1', NOW())
        ");
        
        $stmt->execute([json_encode([
            'cancelled_count' => $cancelledCount,
            'timeout_minutes' => $TIMEOUT_MINUTES,
            'jobs' => array_column($stuckJobs, 'id')
        ])]);
    } catch (PDOException $e) {
        // Audit table might not exist, ignore
    }
    
    exit(0);
    
} catch (PDOException $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: Database error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
