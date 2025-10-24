<?php

declare(strict_types=1);

// Load Composer autoloader
require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Simple service container
class SimpleContainer implements ContainerInterface
{
    private array $services = [];
    private array $instances = [];

    public function set(string $id, callable $factory): void
    {
        $this->services[$id] = $factory;
    }

    public function get(string $id)
    {
        if (!isset($this->instances[$id])) {
            if (!isset($this->services[$id])) {
                throw new \Exception("Service not found: {$id}");
            }
            $this->instances[$id] = $this->services[$id]($this);
        }
        return $this->instances[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]) || isset($this->instances[$id]);
    }
}

// Create container
$container = new SimpleContainer();

// Database connection
$container->set(PDO::class, function () {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $_ENV['DB_HOST'] ?? 'localhost',
        $_ENV['DB_NAME'] ?? 'qbo_multicompany'
    );
    
    $pdo = new PDO(
        $dsn,
        $_ENV['DB_USER'] ?? 'root',
        $_ENV['DB_PASS'] ?? '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    
    return $pdo;
});

// CompanyService
$container->set(\App\Services\CompanyService::class, function ($c) {
    return new \App\Services\CompanyService(
        $c->get(PDO::class),
        $_ENV['ENCRYPTION_KEY'] ?? 'default-insecure-key'
    );
});

// MultiCompanySyncService
$container->set(\App\Services\MultiCompanySyncService::class, function ($c) {
    return new \App\Services\MultiCompanySyncService(
        $c->get(PDO::class),
        $c->get(\App\Services\CompanyService::class)
    );
});

// EmailService
$container->set(\App\Services\EmailService::class, function ($c) {
    return new \App\Services\EmailService(
        $c->get(PDO::class),
        $_ENV['ENCRYPTION_KEY'] ?? 'default-insecure-key'
    );
});

// AuthMiddleware
$container->set('AuthMiddleware', function ($c) {
    return new \App\Middleware\AuthMiddleware($c->get(PDO::class));
});

// AdminAuthMiddleware (requires admin role)
$container->set('AdminAuthMiddleware', function ($c) {
    return new \App\Middleware\AuthMiddleware($c->get(PDO::class), true);
});

// Create app
AppFactory::setContainer($container);
$app = AppFactory::create();

// Set base path if running in subdirectory - detect environment
$basePath = $_ENV['APP_BASE_PATH'] ?? (str_contains($_SERVER['REQUEST_URI'] ?? '', '/multi-company-Dev2Qbo/') ? '/multi-company-Dev2Qbo/public' : '/public');
$app->setBasePath($basePath);

// Add error middleware
$app->addErrorMiddleware(true, true, true);

// Add body parsing middleware
$app->addBodyParsingMiddleware();

return $app;
