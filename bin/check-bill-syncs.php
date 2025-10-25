<?php
require __DIR__ . '/../bootstrap/app.php';
$pdo = $container->get(PDO::class);

// Get recent bill syncs
$stmt = $pdo->query("
    SELECT id, job_type, status, results_json, created_at 
    FROM sync_jobs 
    WHERE job_type IN ('bills', 'full')
    ORDER BY created_at DESC 
    LIMIT 3
");

while ($job = $stmt->fetch()) {
    echo "=== Job #" . $job['id'] . " ===\n";
    echo "Type: " . $job['job_type'] . "\n";
    echo "Status: " . $job['status'] . "\n";
    echo "Created: " . $job['created_at'] . "\n";
    
    if ($job['results_json']) {
        $results = json_decode($job['results_json'], true);
        
        if (isset($results['bills'])) {
            echo "\nBills Results:\n";
            echo "  Total: " . ($results['bills']['total'] ?? 0) . "\n";
            echo "  Created: " . ($results['bills']['bills_created'] ?? 0) . "\n";
            echo "  Skipped: " . ($results['bills']['skipped'] ?? 0) . "\n";
            echo "  Errors: " . ($results['bills']['errors'] ?? 0) . "\n";
            
            if (!empty($results['bills']['error_details'])) {
                echo "\n  Error Details:\n";
                foreach (array_slice($results['bills']['error_details'], 0, 2) as $error) {
                    echo "    - Bill: " . ($error['bill'] ?? 'unknown') . "\n";
                    echo "      Error: " . substr($error['error'] ?? '', 0, 200) . "...\n";
                }
            }
        }
    }
    echo "\n" . str_repeat('-', 80) . "\n\n";
}
