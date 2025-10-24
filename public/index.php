<?php

declare(strict_types=1);

// Bootstrap the application
$app = require __DIR__ . '/../bootstrap/app.php';

// Load routes
require __DIR__ . '/../routes/api.php';
require __DIR__ . '/../routes/field-mappings.php';

// Run application
$app->run();
