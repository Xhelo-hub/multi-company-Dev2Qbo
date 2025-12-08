<?php

declare(strict_types=1);

// Bootstrap the application
$app = require __DIR__ . '/bootstrap/app.php';
$container = $app->getContainer();

// Load routes
// Note: api.php already loads auth.php and email.php internally
require __DIR__ . '/routes/api.php';
$fieldMappingRoutes = require __DIR__ . '/routes/field-mappings.php';
$fieldMappingRoutes($app, $container);
$emailProviderRoutes = require __DIR__ . '/routes/email-providers.php';
$emailProviderRoutes($app);

// Run application
$app->run();