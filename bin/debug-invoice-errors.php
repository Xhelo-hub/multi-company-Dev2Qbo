<?php
require __DIR__ . '/../bootstrap/app.php';

// Get PDO from container
$pdo = $container->get(PDO::class);

// Get the latest sync job with invoice errors
$stmt = $pdo->query("
    SELECT results_json 
    FROM sync_jobs 
    WHERE job_type IN ('sales', 'full') 
    AND results_json LIKE '%error_details%'
    ORDER BY created_at DESC 
    LIMIT 1
");

$job = $stmt->fetch(PDO::FETCH_ASSOC);

if ($job) {
    $results = json_decode($job['results_json'], true);
    
    if (isset($results['sales']['error_details'])) {
        echo "=== INVOICE SYNC ERRORS ===\n\n";
        
        foreach (array_slice($results['sales']['error_details'], 0, 2) as $idx => $error) {
            echo "Error #" . ($idx + 1) . ":\n";
            echo "Invoice EIC: " . $error['invoice'] . "\n";
            echo "Error: " . $error['error'] . "\n";
            echo str_repeat('-', 80) . "\n\n";
        }
    }
} else {
    echo "No invoice errors found in recent sync jobs\n";
}
