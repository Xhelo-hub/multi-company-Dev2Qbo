<?php
/**
 * Scheduled Sync Runner
 * Run this via cron every hour: 0 * * * * php /path/to/run-scheduled-syncs.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Services\SyncExecutor;

// Load environment
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Database connection
$pdo = new PDO(
    "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$syncExecutor = new SyncExecutor($pdo);

echo "[" . date('Y-m-d H:i:s') . "] Starting scheduled sync check...\n";

// Find all scheduled syncs that should run now
$stmt = $pdo->query("
    SELECT 
        ss.*,
        c.company_name,
        c.company_code
    FROM scheduled_syncs ss
    JOIN companies c ON ss.company_id = c.id
    WHERE ss.enabled = TRUE
      AND (ss.next_run_at IS NULL OR ss.next_run_at <= NOW())
    ORDER BY ss.next_run_at ASC
");

$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($schedules)) {
    echo "No scheduled syncs due to run.\n";
    exit(0);
}

echo "Found " . count($schedules) . " scheduled sync(s) to run.\n\n";

foreach ($schedules as $schedule) {
    $scheduleId = $schedule['id'];
    $companyId = $schedule['company_id'];
    $companyName = $schedule['company_name'];
    $jobType = $schedule['job_type'];
    $dateRangeDays = $schedule['date_range_days'];
    
    echo "---------------------------------------------------\n";
    echo "Schedule ID: {$scheduleId}\n";
    echo "Company: {$companyName} (ID: {$companyId})\n";
    echo "Job Type: {$jobType}\n";
    echo "Date Range: Last {$dateRangeDays} days\n";
    
    try {
        // Calculate date range
        $toDate = date('Y-m-d');
        $fromDate = date('Y-m-d', strtotime("-{$dateRangeDays} days"));
        
        echo "Syncing from {$fromDate} to {$toDate}...\n";
        
        // Create sync job in database
        $stmt = $pdo->prepare("
            INSERT INTO sync_jobs (company_id, job_type, from_date, to_date, status, trigger_source, created_at)
            VALUES (?, ?, ?, ?, 'pending', 'scheduled', NOW())
        ");
        $stmt->execute([$companyId, $jobType, $fromDate, $toDate]);
        $jobId = $pdo->lastInsertId();
        
        echo "✓ Sync job #{$jobId} created\n";
        
        // Execute the job (this will use auto-refresh token logic)
        echo "Executing sync job...\n";
        $result = $syncExecutor->executeJob((int)$jobId);
        
        if ($result['success']) {
            echo "✓ Sync completed successfully!\n";
            if (isset($result['results']['sales'])) {
                echo "  Sales: {$result['results']['sales']['invoices_created']} created, ";
                echo "{$result['results']['sales']['skipped']} skipped, ";
                echo "{$result['results']['sales']['errors']} errors\n";
            }
            if (isset($result['results']['bills'])) {
                echo "  Bills: {$result['results']['bills']['bills_created']} created, ";
                echo "{$result['results']['bills']['skipped']} skipped, ";
                echo "{$result['results']['bills']['errors']} errors\n";
            }
        } else {
            echo "✗ Sync failed\n";
        }
        
        // Update schedule - calculate next run time
        $nextRun = calculateNextRunTime($schedule);
        $stmt = $pdo->prepare("
            UPDATE scheduled_syncs
            SET last_run_at = NOW(),
                next_run_at = ?
            WHERE id = ?
        ");
        $stmt->execute([$nextRun, $scheduleId]);
        
        echo "Next run scheduled for: {$nextRun}\n";
        
    } catch (Exception $e) {
        echo "✗ Error: " . $e->getMessage() . "\n";
        
        // Still update next run time even if failed
        $nextRun = calculateNextRunTime($schedule);
        $stmt = $pdo->prepare("
            UPDATE scheduled_syncs
            SET next_run_at = ?
            WHERE id = ?
        ");
        $stmt->execute([$nextRun, $scheduleId]);
    }
    
    echo "\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Scheduled sync check completed.\n";

/**
 * Calculate next run time based on frequency
 */
function calculateNextRunTime(array $schedule): string
{
    $now = new DateTime();
    $frequency = $schedule['frequency'];
    $hourOfDay = $schedule['hour_of_day'] ?? 9;
    
    switch ($frequency) {
        case 'hourly':
            // Run every hour at :00
            $next = clone $now;
            $next->modify('+1 hour');
            $next->setTime((int)$next->format('H'), 0, 0);
            break;
            
        case 'daily':
            // Run daily at specified hour
            $next = clone $now;
            $next->modify('+1 day');
            $next->setTime($hourOfDay, 0, 0);
            
            // If specified hour hasn't passed today, use today
            $today = clone $now;
            $today->setTime($hourOfDay, 0, 0);
            if ($today > $now) {
                $next = $today;
            }
            break;
            
        case 'weekly':
            // Run weekly on specified day
            $dayOfWeek = $schedule['day_of_week'] ?? 1; // Default Monday
            $next = clone $now;
            $next->modify('next ' . ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$dayOfWeek]);
            $next->setTime($hourOfDay, 0, 0);
            break;
            
        case 'monthly':
            // Run monthly on specified day
            $dayOfMonth = $schedule['day_of_month'] ?? 1;
            $next = clone $now;
            $next->modify('first day of next month');
            $next->modify('+' . ($dayOfMonth - 1) . ' days');
            $next->setTime($hourOfDay, 0, 0);
            
            // If day hasn't passed this month, use this month
            $thisMonth = clone $now;
            $thisMonth->setDate((int)$now->format('Y'), (int)$now->format('m'), $dayOfMonth);
            $thisMonth->setTime($hourOfDay, 0, 0);
            if ($thisMonth > $now) {
                $next = $thisMonth;
            }
            break;
            
        default:
            // Default to tomorrow at 9 AM
            $next = clone $now;
            $next->modify('+1 day');
            $next->setTime(9, 0, 0);
    }
    
    return $next->format('Y-m-d H:i:s');
}
