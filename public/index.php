<?php

declare(strict_types=1);

// Bootstrap the application
$app = require __DIR__ . '/../bootstrap/app.php';
$container = $app->getContainer();

// Load routes (api.php already loads auth.php internally)
require __DIR__ . '/../routes/api.php';

// Load additional route modules
$fieldMappingRoutes = require __DIR__ . '/../routes/field-mappings.php';
$fieldMappingRoutes($app, $container);

$emailProviderRoutes = require __DIR__ . '/../routes/email-providers.php';
$emailProviderRoutes($app);

$emailRoutes = require __DIR__ . '/../routes/email.php';
$emailRoutes($app, $container);

// Run application
$app->run();
