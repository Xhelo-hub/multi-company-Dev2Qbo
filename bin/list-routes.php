<?php

require __DIR__ . '/../vendor/autoload.php';

// Bootstrap the application
$app = require __DIR__ . '/../bootstrap/app.php';

// Load routes
require __DIR__ . '/../routes/api.php';

// List all registered routes
$routeCollector = $app->getRouteCollector();
$routes = $routeCollector->getRoutes();

echo "Registered routes:\n\n";
foreach ($routes as $route) {
    echo sprintf("%-6s %s\n", implode('|', $route->getMethods()), $route->getPattern());
}
