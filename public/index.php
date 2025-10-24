<?php

declare(strict_types=1);

// Bootstrap the application
$app = require __DIR__ . '/../bootstrap/app.php';
$container = $app->getContainer();

// Load routes
require __DIR__ . '/../routes/api.php';
$fieldMappingRoutes = require __DIR__ . '/../routes/field-mappings.php';
$fieldMappingRoutes($app, $container);

// Run application
$app->run();
