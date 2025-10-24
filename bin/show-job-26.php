<?php
require __DIR__ . '/../bootstrap/app.php';
$pdo = $container->get(PDO::class);
$stmt = $pdo->query("SELECT results_json FROM sync_jobs WHERE id = 26");
$r = $stmt->fetch();
echo $r['results_json'] . "\n";
