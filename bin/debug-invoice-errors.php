<?php
require __DIR__ . '/../bootstrap/app.php';

// Get PDO from container
$pdo = $container->get(PDO::class);

// Get the latest sync job (any type)
$stmt = $pdo->query("
    SELECT id, job_type, status, results_json, error_message, created_at
    FROM sync_jobs 
    ORDER BY created_at DESC 
    LIMIT 1
");

$job = $stmt->fetch(PDO::FETCH_ASSOC);

if ($job) {
    echo "=== LATEST SYNC JOB ===\n";
    echo "Job ID: " . $job['id'] . "\n";
    echo "Type: " . $job['job_type'] . "\n";
    echo "Status: " . $job['status'] . "\n";
    echo "Created: " . $job['created_at'] . "\n";
    echo "\n";
    
    if ($job['error_message']) {
        echo "Error Message: " . $job['error_message'] . "\n\n";
    }
    
    if ($job['results_json']) {
        $results = json_decode($job['results_json'], true);
        
        if (isset($results['sales'])) {
            echo "=== SALES SYNC RESULTS ===\n";
            echo "Total: " . ($results['sales']['total'] ?? 0) . "\n";
            echo "Synced: " . ($results['sales']['synced'] ?? 0) . "\n";
            echo "Errors: " . ($results['sales']['errors'] ?? 0) . "\n\n";
            
            if (!empty($results['sales']['error_details'])) {
                echo "First 3 errors:\n";
                foreach (array_slice($results['sales']['error_details'], 0, 3) as $idx => $error) {
                    echo "\n--- Error #" . ($idx + 1) . " ---\n";
                    echo "Invoice: " . ($error['invoice'] ?? 'unknown') . "\n";
                    echo "Error: " . ($error['error'] ?? 'no error message') . "\n";
                }
            }
        }
        
        if (isset($results['bills'])) {
            echo "\n=== BILLS SYNC RESULTS ===\n";
            echo "Total: " . ($results['bills']['total'] ?? 0) . "\n";
            echo "Created: " . ($results['bills']['bills_created'] ?? 0) . "\n";
            echo "Skipped: " . ($results['bills']['skipped'] ?? 0) . "\n";
            echo "Errors: " . ($results['bills']['errors'] ?? 0) . "\n";
        }
    }
} else {
    echo "No sync jobs found\n";
}
