<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Get services from container
$container = $app->getContainer();
$pdo = $container->get(PDO::class);

// Create auth middleware instance
$authMiddleware = new \App\Middleware\AuthMiddleware($pdo);

// Load authentication routes (public endpoints like login)
$authRoutes = require __DIR__ . '/auth.php';
$authRoutes($app);

// Load email management routes (admin-only) - WITHOUT middleware for now to test
error_log("About to load email routes...");
try {
    $emailRoutes = require __DIR__ . '/email.php';
    if (!is_callable($emailRoutes)) {
        error_log("ERROR: email.php did not return a callable function!");
    } else {
        error_log("Email routes loaded, calling with app and container...");
        $emailRoutes($app, $container);
        error_log("Email routes registered successfully!");
        
        // Debug: List all registered routes
        $routeCollector = $app->getRouteCollector();
        $routes = $routeCollector->getRoutes();
        error_log("Total routes registered: " . count($routes));
        foreach ($routes as $route) {
            error_log("Route: " . $route->getPattern() . " [" . implode(',', $route->getMethods()) . "]");
        }
    }
} catch (\Exception $e) {
    error_log("EXCEPTION loading email routes: " . $e->getMessage());
    error_log("Stack: " . $e->getTraceAsString());
} catch (\Error $e) {
    error_log("ERROR loading email routes: " . $e->getMessage());
    error_log("Stack: " . $e->getTraceAsString());
}

// API Authentication Middleware (legacy API key auth)
$apiKeyMiddleware = function (Request $request, $handler) {
    $apiKey = $request->getHeaderLine('X-API-Key');
    
    // For development, allow if API key matches .env or skip if no key set
    $expectedKey = $_ENV['API_KEY'] ?? null;
    
    if ($expectedKey && $apiKey !== $expectedKey) {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode(['error' => 'Invalid API key']));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(401);
    }
    
    return $handler->handle($request);
};

// ============================================================================
// EMAIL TEST ENDPOINT (for admins)
// ============================================================================

// Test email configuration
$app->post('/api/admin/test-email', function (Request $request, Response $response) use ($container) {
    $emailService = $container->get(\App\Services\EmailService::class);
    $data = json_decode($request->getBody()->getContents(), true);
    $testEmail = $data['email'] ?? '';
    
    if (empty($testEmail)) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'message' => 'Email address is required'
        ]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    $result = $emailService->testConnection($testEmail);
    
    $response->getBody()->write(json_encode($result));
    return $response->withHeader('Content-Type', 'application/json');
})->add(new \App\Middleware\AuthMiddleware($pdo, true));

// ============================================================================
// COMPANIES ENDPOINTS
// ============================================================================

// List all companies
$app->get('/api/companies', function (Request $request, Response $response) use ($pdo) {
    $user = $request->getAttribute('user');
    
    // If admin, return all companies; otherwise return only user's companies
    if ($user['role'] === 'admin') {
        $stmt = $pdo->query("SELECT id as company_id, company_code, company_name, is_active FROM companies ORDER BY created_at DESC");
        $companies = $stmt->fetchAll();
    } else {
        $companies = $user['companies'] ?? [];
    }
    
    $response->getBody()->write(json_encode($companies));
    return $response->withHeader('Content-Type', 'application/json');
})->add($authMiddleware);

// Get company details
$app->get('/api/companies/{id}', function (Request $request, Response $response, array $args) {
    $companyService = $this->get(\App\Services\CompanyService::class);
    $company = $companyService->getById((int)$args['id']);
    
    if (!$company) {
        $response->getBody()->write(json_encode(['error' => 'Company not found']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
    }
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'company' => $company
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
})->add($apiKeyMiddleware);

// Get company statistics
$app->get('/api/companies/{id}/stats', function (Request $request, Response $response, array $args) {
    $syncService = $this->get(\App\Services\MultiCompanySyncService::class);
    $stats = $syncService->getCompanyStats((int)$args['id']);
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'stats' => $stats
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
})->add($apiKeyMiddleware);

// Get company sync jobs
$app->get('/api/companies/{id}/jobs', function (Request $request, Response $response, array $args) {
    $syncService = $this->get(\App\Services\MultiCompanySyncService::class);
    $limit = (int)($request->getQueryParams()['limit'] ?? 20);
    $jobs = $syncService->getRecentJobs((int)$args['id'], $limit);
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'jobs' => $jobs
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
})->add($apiKeyMiddleware);

// Create and execute sync job
$app->post('/api/companies/{id}/sync', function (Request $request, Response $response, array $args) {
    $syncService = $this->get(\App\Services\MultiCompanySyncService::class);
    $data = $request->getParsedBody();
    
    $jobType = $data['job_type'] ?? 'sales';
    $fromDate = $data['from_date'] ?? date('Y-m-d');
    $toDate = $data['to_date'] ?? date('Y-m-d');
    
    // Create job
    $jobId = $syncService->createJob(
        (int)$args['id'],
        $jobType,
        $fromDate,
        $toDate,
        'api'
    );
    
    // Execute job
    $result = $syncService->executeJob($jobId);
    
    $response->getBody()->write(json_encode($result));
    
    return $response->withHeader('Content-Type', 'application/json');
})->add($authMiddleware);

// Create new company (admin only)
$app->post('/api/companies', function (Request $request, Response $response) use ($pdo) {
    $user = $request->getAttribute('user');
    
    if ($user['role'] !== 'admin') {
        $response->getBody()->write(json_encode(['error' => 'Admin access required']));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
    }
    
    $data = $request->getParsedBody();
    $companyCode = strtoupper(trim($data['company_code'] ?? ''));
    $companyName = trim($data['company_name'] ?? '');
    $isActive = (int)($data['is_active'] ?? 1);
    
    if (empty($companyCode) || empty($companyName)) {
        $response->getBody()->write(json_encode(['error' => 'Company code and name are required']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    // Check if company code already exists
    $stmt = $pdo->prepare("SELECT id FROM companies WHERE company_code = ?");
    $stmt->execute([$companyCode]);
    if ($stmt->fetch()) {
        $response->getBody()->write(json_encode(['error' => 'Company code already exists']));
        return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO companies (company_code, company_name, is_active, created_at, updated_at)
            VALUES (?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$companyCode, $companyName, $isActive]);
        $companyId = $pdo->lastInsertId();
        
        // Log audit
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
            VALUES (?, 'company.create', 'company', ?, ?, ?, NOW())
        ");
        $auditStmt->execute([
            $user['id'],
            $companyId,
            json_encode(['company_code' => $companyCode, 'company_name' => $companyName]),
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'company_id' => $companyId,
            'message' => 'Company created successfully'
        ]));
    } catch (\Exception $e) {
        $response->getBody()->write(json_encode(['error' => 'Failed to create company: ' . $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
    
    return $response->withHeader('Content-Type', 'application/json');
})->add($authMiddleware);

// Update company (admin only)
$app->patch('/api/companies/{id}', function (Request $request, Response $response, array $args) use ($pdo) {
    $user = $request->getAttribute('user');
    
    if ($user['role'] !== 'admin') {
        $response->getBody()->write(json_encode(['error' => 'Admin access required']));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
    }
    
    $companyId = (int)$args['id'];
    $data = $request->getParsedBody();
    
    // Get current company data
    $stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ?");
    $stmt->execute([$companyId]);
    $company = $stmt->fetch();
    
    if (!$company) {
        $response->getBody()->write(json_encode(['error' => 'Company not found']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    
    // Build update query dynamically
    $updates = [];
    $params = [];
    $changes = [];
    
    if (isset($data['company_name'])) {
        $updates[] = 'company_name = ?';
        $params[] = trim($data['company_name']);
        $changes['company_name'] = ['from' => $company['company_name'], 'to' => trim($data['company_name'])];
    }
    
    if (isset($data['is_active'])) {
        $updates[] = 'is_active = ?';
        $params[] = (int)$data['is_active'];
        $changes['is_active'] = ['from' => $company['is_active'], 'to' => (int)$data['is_active']];
    }
    
    if (isset($data['notes'])) {
        $updates[] = 'notes = ?';
        $params[] = trim($data['notes']);
        $changes['notes'] = ['from' => $company['notes'], 'to' => trim($data['notes'])];
    }
    
    if (empty($updates)) {
        $response->getBody()->write(json_encode(['error' => 'No fields to update']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    $updates[] = 'updated_at = NOW()';
    $params[] = $companyId;
    
    try {
        $stmt = $pdo->prepare("UPDATE companies SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($params);
        
        // Log audit
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
            VALUES (?, 'company.update', 'company', ?, ?, ?, NOW())
        ");
        $auditStmt->execute([
            $user['id'],
            $companyId,
            json_encode($changes),
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Company updated successfully'
        ]));
    } catch (\Exception $e) {
        $response->getBody()->write(json_encode(['error' => 'Failed to update company: ' . $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
    
    return $response->withHeader('Content-Type', 'application/json');
})->add($authMiddleware);

// Delete company (admin only, must be inactive)
$app->delete('/api/companies/{id}', function (Request $request, Response $response, array $args) use ($pdo) {
    $user = $request->getAttribute('user');
    
    if ($user['role'] !== 'admin') {
        $response->getBody()->write(json_encode(['error' => 'Admin access required']));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
    }
    
    $companyId = (int)$args['id'];
    
    // Get company data
    $stmt = $pdo->prepare("SELECT id, company_code, company_name, is_active FROM companies WHERE id = ?");
    $stmt->execute([$companyId]);
    $company = $stmt->fetch();
    
    if (!$company) {
        $response->getBody()->write(json_encode(['error' => 'Company not found']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    
    // Check if company is inactive
    if ($company['is_active']) {
        $response->getBody()->write(json_encode([
            'error' => 'Cannot delete active company',
            'message' => 'Company must be deactivated before deletion'
        ]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // Delete related records in correct order (respecting foreign keys)
        
        // 1. Delete sync schedules
        $stmt = $pdo->prepare("DELETE FROM sync_schedules WHERE company_id = ?");
        $stmt->execute([$companyId]);
        
        // 2. Delete sync jobs
        $stmt = $pdo->prepare("DELETE FROM sync_jobs WHERE company_id = ?");
        $stmt->execute([$companyId]);
        
        // 3. Delete sync cursors
        $stmt = $pdo->prepare("DELETE FROM sync_cursors WHERE company_id = ?");
        $stmt->execute([$companyId]);
        
        // 4. Delete maps
        $stmt = $pdo->prepare("DELETE FROM maps_documents WHERE company_id = ?");
        $stmt->execute([$companyId]);
        
        $stmt = $pdo->prepare("DELETE FROM maps_masterdata WHERE company_id = ?");
        $stmt->execute([$companyId]);
        
        // 5. Delete OAuth tokens
        $stmt = $pdo->prepare("DELETE FROM oauth_tokens_devpos WHERE company_id = ?");
        $stmt->execute([$companyId]);
        
        $stmt = $pdo->prepare("DELETE FROM oauth_tokens_qbo WHERE company_id = ?");
        $stmt->execute([$companyId]);
        
        // 6. Delete credentials
        $stmt = $pdo->prepare("DELETE FROM company_credentials_devpos WHERE company_id = ?");
        $stmt->execute([$companyId]);
        
        $stmt = $pdo->prepare("DELETE FROM company_credentials_qbo WHERE company_id = ?");
        $stmt->execute([$companyId]);
        
        $stmt = $pdo->prepare("DELETE FROM user_devpos_credentials WHERE company_id = ?");
        $stmt->execute([$companyId]);
        
        // 7. Delete user company access
        $stmt = $pdo->prepare("DELETE FROM user_company_access WHERE company_id = ?");
        $stmt->execute([$companyId]);
        
        // 8. Finally, delete the company
        $stmt = $pdo->prepare("DELETE FROM companies WHERE id = ?");
        $stmt->execute([$companyId]);
        
        // Log audit
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
            VALUES (?, 'company.delete', 'company', ?, ?, ?, NOW())
        ");
        $auditStmt->execute([
            $user['id'],
            $companyId,
            json_encode([
                'company_code' => $company['company_code'],
                'company_name' => $company['company_name'],
                'deleted_by' => $user['email']
            ]),
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);
        
        // Commit transaction
        $pdo->commit();
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Company and all related data deleted successfully'
        ]));
    } catch (\Exception $e) {
        // Rollback on error
        $pdo->rollBack();
        
        $response->getBody()->write(json_encode([
            'error' => 'Failed to delete company',
            'message' => $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
    
    return $response->withHeader('Content-Type', 'application/json');
})->add($authMiddleware);

// Get company users (admin only)
$app->get('/api/companies/{id}/users', function (Request $request, Response $response, array $args) use ($pdo) {
    $user = $request->getAttribute('user');
    
    if ($user['role'] !== 'admin') {
        $response->getBody()->write(json_encode(['error' => 'Admin access required']));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
    }
    
    $companyId = (int)$args['id'];
    
    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.email,
            u.full_name,
            u.role,
            uca.can_view_sync,
            uca.can_run_sync,
            uca.can_edit_credentials,
            uca.can_manage_schedules,
            uca.assigned_at
        FROM users u
        INNER JOIN user_company_access uca ON u.id = uca.user_id
        WHERE uca.company_id = ?
        ORDER BY u.full_name
    ");
    $stmt->execute([$companyId]);
    $users = $stmt->fetchAll();
    
    $response->getBody()->write(json_encode($users));
    return $response->withHeader('Content-Type', 'application/json');
})->add($authMiddleware);

// ============================================================================
// CREDENTIALS ENDPOINTS
// ============================================================================

// Get DevPos credentials
$app->get('/api/companies/{companyId}/credentials/devpos', function (Request $request, Response $response, array $args) use ($pdo) {
    $companyId = (int)$args['companyId'];
    $user = $request->getAttribute('user');
    $userId = $user['id'];
    
    // First, try to get user-specific credentials
    $stmt = $pdo->prepare("
        SELECT tenant, username, 'user' as credential_type
        FROM user_devpos_credentials 
        WHERE company_id = ? AND user_id = ?
    ");
    $stmt->execute([$companyId, $userId]);
    $creds = $stmt->fetch();
    
    // If no user-specific credentials, fallback to company-level credentials
    if (!$creds) {
        $stmt = $pdo->prepare("
            SELECT tenant, username, 'company' as credential_type
            FROM company_credentials_devpos 
            WHERE company_id = ?
        ");
        $stmt->execute([$companyId]);
        $creds = $stmt->fetch();
    }
    
    if ($creds) {
        $response->getBody()->write(json_encode($creds));
    } else {
        $response->getBody()->write(json_encode(['tenant' => '', 'username' => '', 'credential_type' => 'none']));
    }
    
    return $response->withHeader('Content-Type', 'application/json');
})->add($authMiddleware);

// Save DevPos credentials
$app->post('/api/companies/{companyId}/credentials/devpos', function (Request $request, Response $response, array $args) use ($pdo) {
    $companyId = (int)$args['companyId'];
    $user = $request->getAttribute('user');
    $userId = $user['id'];
    $data = $request->getParsedBody();
    
    $tenant = $data['tenant'] ?? '';
    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';
    $scope = $data['scope'] ?? 'user'; // 'user' or 'company'
    
    if (empty($tenant) || empty($username)) {
        $response->getBody()->write(json_encode(['error' => 'Tenant and username are required']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    // Encrypt password if provided
    $encryptedPassword = null;
    if (!empty($password)) {
        $encryptedPassword = openssl_encrypt(
            $password,
            'AES-256-CBC',
            $_ENV['ENCRYPTION_KEY'] ?? 'default-key',
            0,
            substr(hash('sha256', $_ENV['ENCRYPTION_KEY'] ?? 'default-key'), 0, 16)
        );
    }
    
    // Save based on scope (user-specific or company-wide)
    if ($scope === 'company' && $user['role'] === 'admin') {
        // Admin can set company-level credentials
        $stmt = $pdo->prepare("SELECT id FROM company_credentials_devpos WHERE company_id = ?");
        $stmt->execute([$companyId]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Update
            if (!empty($password)) {
                $stmt = $pdo->prepare("
                    UPDATE company_credentials_devpos 
                    SET tenant = ?, username = ?, password_encrypted = ?, updated_at = NOW()
                    WHERE company_id = ?
                ");
                $stmt->execute([$tenant, $username, $encryptedPassword, $companyId]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE company_credentials_devpos 
                    SET tenant = ?, username = ?, updated_at = NOW()
                    WHERE company_id = ?
                ");
                $stmt->execute([$tenant, $username, $companyId]);
            }
        } else {
            // Insert
            if (empty($password)) {
                $response->getBody()->write(json_encode(['error' => 'Password is required for new credentials']));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
            $stmt = $pdo->prepare("
                INSERT INTO company_credentials_devpos (company_id, tenant, username, password_encrypted)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$companyId, $tenant, $username, $encryptedPassword]);
        }
    } else {
        // Save user-specific credentials
        $stmt = $pdo->prepare("SELECT id FROM user_devpos_credentials WHERE company_id = ? AND user_id = ?");
        $stmt->execute([$companyId, $userId]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Update
            if (!empty($password)) {
                $stmt = $pdo->prepare("
                    UPDATE user_devpos_credentials 
                    SET tenant = ?, username = ?, password_encrypted = ?, updated_at = NOW()
                    WHERE company_id = ? AND user_id = ?
                ");
                $stmt->execute([$tenant, $username, $encryptedPassword, $companyId, $userId]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE user_devpos_credentials 
                    SET tenant = ?, username = ?, updated_at = NOW()
                    WHERE company_id = ? AND user_id = ?
                ");
                $stmt->execute([$tenant, $username, $companyId, $userId]);
            }
        } else {
            // Insert
            if (empty($password)) {
                $response->getBody()->write(json_encode(['error' => 'Password is required for new credentials']));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
            $stmt = $pdo->prepare("
                INSERT INTO user_devpos_credentials (company_id, user_id, tenant, username, password_encrypted)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$companyId, $userId, $tenant, $username, $encryptedPassword]);
        }
    }
    
    $response->getBody()->write(json_encode(['success' => true, 'scope' => $scope]));
    return $response->withHeader('Content-Type', 'application/json');
})->add($authMiddleware);

// Test DevPos connection
$app->get('/api/companies/{companyId}/credentials/devpos/test', function (Request $request, Response $response, array $args) use ($pdo) {
    $companyId = (int)$args['companyId'];
    $user = $request->getAttribute('user');
    $userId = $user['id'];
    
    // First, try to get user-specific credentials
    $stmt = $pdo->prepare("
        SELECT tenant, username, password_encrypted, 'user' as credential_type
        FROM user_devpos_credentials 
        WHERE company_id = ? AND user_id = ?
    ");
    $stmt->execute([$companyId, $userId]);
    $creds = $stmt->fetch();
    
    // If no user-specific credentials, fallback to company-level credentials
    if (!$creds) {
        $stmt = $pdo->prepare("
            SELECT tenant, username, password_encrypted, 'company' as credential_type
            FROM company_credentials_devpos 
            WHERE company_id = ?
        ");
        $stmt->execute([$companyId]);
        $creds = $stmt->fetch();
    }
    
    if (!$creds) {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'No credentials configured']));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    try {
        // Decrypt password
        $password = openssl_decrypt(
            $creds['password_encrypted'],
            'AES-256-CBC',
            $_ENV['ENCRYPTION_KEY'] ?? 'default-key',
            0,
            substr(hash('sha256', $_ENV['ENCRYPTION_KEY'] ?? 'default-key'), 0, 16)
        );
        
        // Test connection using OAuth2 password grant (the CORRECT method)
        $client = new \GuzzleHttp\Client(['http_errors' => false]);
        $tokenUrl = $_ENV['DEVPOS_TOKEN_URL'] ?? 'https://online.devpos.al/connect/token';
        
        $authResponse = $client->post($tokenUrl, [
            'form_params' => [
                'grant_type' => 'password',
                'username' => $creds['username'],
                'password' => $password,
            ],
            'headers' => [
                'Authorization' => 'Basic ' . ($_ENV['DEVPOS_AUTH_BASIC'] ?? 'Zmlza2FsaXppbWlfc3BhOg=='),
                'tenant' => $creds['tenant'],
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json'
            ]
        ]);
        
        $statusCode = $authResponse->getStatusCode();
        
        if ($statusCode !== 200) {
            $errorBody = $authResponse->getBody()->getContents();
            $response->getBody()->write(json_encode([
                'success' => false, 
                'message' => 'DevPos authentication failed',
                'error' => $errorBody
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }
        
        $authData = json_decode($authResponse->getBody()->getContents(), true);
        
        if (isset($authData['access_token'])) {
            $expiresIn = $authData['expires_in'] ?? 3600;
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => '✅ DevPos connection successful!',
                'expires_in' => $expiresIn,
                'expires_in_hours' => round($expiresIn / 3600, 1)
            ]));
        } else {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Invalid response from DevPos']));
        }
        
    } catch (\Exception $e) {
        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Connection error: ' . $e->getMessage()]));
    }
    
    return $response->withHeader('Content-Type', 'application/json');
})->add($authMiddleware);

// Get QuickBooks credentials status
$app->get('/api/companies/{companyId}/credentials/qbo', function (Request $request, Response $response, array $args) use ($pdo) {
    $companyId = (int)$args['companyId'];
    
    $stmt = $pdo->prepare("SELECT realm_id FROM company_credentials_qbo WHERE company_id = ?");
    $stmt->execute([$companyId]);
    $creds = $stmt->fetch();
    
    if ($creds) {
        $response->getBody()->write(json_encode($creds));
    } else {
        $response->getBody()->write(json_encode(['realm_id' => null]));
    }
    
    return $response->withHeader('Content-Type', 'application/json');
})->add($authMiddleware);

// ============================================================================
// SYNC ENDPOINTS
// ============================================================================

// Run sync job
$app->post('/api/sync/{companyId}/{type}', function (Request $request, Response $response, array $args) use ($pdo) {
    $companyId = (int)$args['companyId'];
    $type = $args['type'];
    $data = $request->getParsedBody();
    
    $fromDate = $data['fromDate'] ?? date('Y-m-d');
    $toDate = $data['toDate'] ?? date('Y-m-d');
    
    // Create job record
    $stmt = $pdo->prepare("
        INSERT INTO sync_jobs (company_id, job_type, status, from_date, to_date, trigger_source, created_at)
        VALUES (?, ?, 'pending', ?, ?, 'manual', NOW())
    ");
    $stmt->execute([$companyId, $type, $fromDate, $toDate]);
    $jobId = $pdo->lastInsertId();
    
    // Execute job immediately (for now - should be queued in production)
    try {
        $executor = new \App\Services\SyncExecutor($pdo);
        $executor->executeJob((int)$jobId);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'job_id' => $jobId,
            'message' => 'Sync job completed successfully!'
        ]));
    } catch (\Exception $e) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'job_id' => $jobId,
            'message' => 'Sync job failed: ' . $e->getMessage()
        ]));
    }
    
    return $response->withHeader('Content-Type', 'application/json');
})->add($authMiddleware);

// Get sync jobs for company
$app->get('/api/sync/{companyId}/jobs', function (Request $request, Response $response, array $args) use ($pdo) {
    $companyId = (int)$args['companyId'];
    
    $stmt = $pdo->prepare("
        SELECT 
            id,
            job_type,
            status,
            from_date,
            to_date,
            started_at,
            completed_at,
            error_message,
            created_at
        FROM sync_jobs
        WHERE company_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$companyId]);
    $jobs = $stmt->fetchAll();
    
    $response->getBody()->write(json_encode($jobs));
    return $response->withHeader('Content-Type', 'application/json');
})->add($authMiddleware);

// Get synced transactions for company
$app->get('/api/sync/{companyId}/transactions', function (Request $request, Response $response, array $args) use ($pdo) {
    $companyId = (int)$args['companyId'];
    $params = $request->getQueryParams();
    $limit = (int)($params['limit'] ?? 100);
    $offset = (int)($params['offset'] ?? 0);
    $type = $params['type'] ?? 'all'; // all, invoice, purchase, bill
    
    // Build WHERE clause based on type
    $whereClause = 'WHERE im.company_id = ?';
    $queryParams = [$companyId];
    
    if ($type !== 'all') {
        $whereClause .= ' AND im.transaction_type = ?';
        $queryParams[] = $type;
    }
    
    // Get total count
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM invoice_mappings im
        $whereClause
    ");
    $countStmt->execute($queryParams);
    $totalCount = (int)$countStmt->fetchColumn();
    
    // Get transactions
    $stmt = $pdo->prepare("
        SELECT 
            im.id,
            im.devpos_eic,
            im.devpos_document_number,
            im.transaction_type,
            im.qbo_invoice_id,
            im.qbo_doc_number,
            im.amount,
            im.customer_name,
            im.synced_at,
            im.last_synced_at
        FROM invoice_mappings im
        $whereClause
        ORDER BY im.synced_at DESC
        LIMIT ? OFFSET ?
    ");
    $queryParams[] = $limit;
    $queryParams[] = $offset;
    $stmt->execute($queryParams);
    $transactions = $stmt->fetchAll();
    
    $response->getBody()->write(json_encode([
        'transactions' => $transactions,
        'total' => $totalCount,
        'limit' => $limit,
        'offset' => $offset,
        'has_more' => ($offset + $limit) < $totalCount
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
})->add($authMiddleware);

// Get sync statistics for company
$app->get('/api/sync/{companyId}/stats', function (Request $request, Response $response, array $args) use ($pdo) {
    $companyId = (int)$args['companyId'];
    
    // Get counts by transaction type
    $stmt = $pdo->prepare("
        SELECT 
            transaction_type,
            COUNT(*) as count,
            SUM(amount) as total_amount,
            MAX(synced_at) as last_synced
        FROM invoice_mappings
        WHERE company_id = ?
        GROUP BY transaction_type
    ");
    $stmt->execute([$companyId]);
    $stats = $stmt->fetchAll();
    
    // Get recent sync jobs summary
    $jobsStmt = $pdo->prepare("
        SELECT 
            status,
            COUNT(*) as count
        FROM sync_jobs
        WHERE company_id = ?
        GROUP BY status
    ");
    $jobsStmt->execute([$companyId]);
    $jobStats = $jobsStmt->fetchAll();
    
    // Format response
    $statsByType = [];
    $totalTransactions = 0;
    $totalAmount = 0;
    
    foreach ($stats as $stat) {
        $statsByType[$stat['transaction_type']] = [
            'count' => (int)$stat['count'],
            'total_amount' => (float)$stat['total_amount'],
            'last_synced' => $stat['last_synced']
        ];
        $totalTransactions += (int)$stat['count'];
        $totalAmount += (float)$stat['total_amount'];
    }
    
    $jobStatusCounts = [];
    foreach ($jobStats as $js) {
        $jobStatusCounts[$js['status']] = (int)$js['count'];
    }
    
    $response->getBody()->write(json_encode([
        'total_transactions' => $totalTransactions,
        'total_amount' => $totalAmount,
        'by_type' => $statsByType,
        'job_stats' => $jobStatusCounts
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
})->add($authMiddleware);

// ============================================================================
// QUICKBOOKS OAUTH ENDPOINTS
// ============================================================================

// Get QuickBooks OAuth authorization URL
$app->get('/api/companies/{companyId}/qbo/auth-url', function (Request $request, Response $response, array $args) use ($pdo) {
    $companyId = (int)$args['companyId'];
    
    // Get company info
    $stmt = $pdo->prepare("SELECT company_code FROM companies WHERE id = ?");
    $stmt->execute([$companyId]);
    $company = $stmt->fetch();
    
    if (!$company) {
        $response->getBody()->write(json_encode(['error' => 'Company not found']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    
    // Build OAuth URL
    $clientId = $_ENV['QBO_CLIENT_ID'] ?? '';
    $redirectUri = ($_ENV['QBO_REDIRECT_URI'] ?? 'http://localhost/multi-company-Dev2Qbo/public/oauth/callback');
    
    // Generate state token with company ID
    $state = base64_encode(json_encode([
        'company_id' => $companyId,
        'timestamp' => time(),
        'token' => bin2hex(random_bytes(16))
    ]));
    
    // Store state in session for verification
    $stmt = $pdo->prepare("
        INSERT INTO oauth_state_tokens (company_id, state_token, created_at, expires_at)
        VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 10 MINUTE))
        ON DUPLICATE KEY UPDATE state_token = ?, created_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE)
    ");
    $stmt->execute([$companyId, $state, $state]);
    
    // Use sandbox URL for development, production URL for production
    $isSandbox = (($_ENV['QBO_ENV'] ?? 'production') === 'sandbox');
    $authBaseUrl = $isSandbox 
        ? 'https://appcenter.intuit.com/connect/oauth2' 
        : 'https://appcenter.intuit.com/connect/oauth2';
    
    $authUrl = $authBaseUrl . '?' . http_build_query([
        'client_id' => $clientId,
        'scope' => 'com.intuit.quickbooks.accounting',
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'state' => $state
    ]);
    
    $response->getBody()->write(json_encode([
        'auth_url' => $authUrl,
        'state' => $state,
        'environment' => $isSandbox ? 'sandbox' : 'production'
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
})->add($authMiddleware);

// QuickBooks OAuth callback (public, no auth required)
$app->get('/oauth/callback', function (Request $request, Response $response) use ($pdo) {
    $params = $request->getQueryParams();
    $code = $params['code'] ?? null;
    $state = $params['state'] ?? null;
    $realmId = $params['realmId'] ?? null;
    
    if (!$code || !$state || !$realmId) {
        $response->getBody()->write('<html><body><h1>OAuth Error</h1><p>Missing required parameters</p><script>setTimeout(() => window.close(), 3000);</script></body></html>');
        return $response->withHeader('Content-Type', 'text/html');
    }
    
    try {
        // Verify state token
        $stateData = json_decode(base64_decode($state), true);
        $companyId = $stateData['company_id'] ?? null;
        
        if (!$companyId) {
            throw new Exception('Invalid state token');
        }
        
        $stmt = $pdo->prepare("SELECT state_token FROM oauth_state_tokens WHERE company_id = ? AND expires_at > NOW()");
        $stmt->execute([$companyId]);
        $storedState = $stmt->fetchColumn();
        
        if ($storedState !== $state) {
            throw new Exception('State token mismatch');
        }
        
        // Exchange code for tokens
        $client = new \GuzzleHttp\Client();
        $tokenResponse = $client->post('https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer', [
            'form_params' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $_ENV['QBO_REDIRECT_URI'] ?? 'http://localhost/multi-company-Dev2Qbo/public/oauth/callback'
            ],
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode(($_ENV['QBO_CLIENT_ID'] ?? '') . ':' . ($_ENV['QBO_CLIENT_SECRET'] ?? '')),
                'Content-Type' => 'application/x-www-form-urlencoded'
            ]
        ]);
        
        $tokens = json_decode($tokenResponse->getBody()->getContents(), true);
        
        // Save tokens to database
        $stmt = $pdo->prepare("
            INSERT INTO company_credentials_qbo (company_id, realm_id, created_at, updated_at)
            VALUES (?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE realm_id = ?, updated_at = NOW()
        ");
        $stmt->execute([$companyId, $realmId, $realmId]);
        
        $stmt = $pdo->prepare("
            INSERT INTO oauth_tokens_qbo (company_id, access_token, refresh_token, expires_at, created_at)
            VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())
            ON DUPLICATE KEY UPDATE 
                access_token = ?, 
                refresh_token = ?, 
                expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                created_at = NOW()
        ");
        $stmt->execute([
            $companyId,
            $tokens['access_token'],
            $tokens['refresh_token'],
            $tokens['expires_in'] ?? 3600,
            $tokens['access_token'],
            $tokens['refresh_token'],
            $tokens['expires_in'] ?? 3600
        ]);
        
        // Clean up state token
        $stmt = $pdo->prepare("DELETE FROM oauth_state_tokens WHERE company_id = ?");
        $stmt->execute([$companyId]);
        
        // Return success page
        $response->getBody()->write('
            <html>
            <head>
                <title>QuickBooks Connected</title>
                <style>
                    body { font-family: Arial, sans-serif; text-align: center; padding: 50px; background: #f0f0f0; }
                    .success { background: white; padding: 40px; border-radius: 10px; max-width: 500px; margin: 0 auto; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                    h1 { color: #2ca01c; }
                </style>
            </head>
            <body>
                <div class="success">
                    <h1>✅ QuickBooks Connected!</h1>
                    <p>Realm ID: ' . htmlspecialchars($realmId) . '</p>
                    <p>This window will close in 3 seconds...</p>
                </div>
                <script>
                    if (window.opener) {
                        window.opener.postMessage({ type: "qbo-connected", realmId: "' . $realmId . '" }, "*");
                    }
                    setTimeout(() => window.close(), 3000);
                </script>
            </body>
            </html>
        ');
        
        return $response->withHeader('Content-Type', 'text/html');
        
    } catch (Exception $e) {
        $response->getBody()->write('
            <html>
            <body>
                <h1>OAuth Error</h1>
                <p>' . htmlspecialchars($e->getMessage()) . '</p>
                <script>setTimeout(() => window.close(), 5000);</script>
            </body>
            </html>
        ');
        return $response->withHeader('Content-Type', 'text/html');
    }
});

// ============================================================================
// DASHBOARD ENDPOINT
// ============================================================================

// Serve dashboard
$app->get('/dashboard', function (Request $request, Response $response) {
    $dashboardPath = __DIR__ . '/../public/dashboard.html';
    
    if (!file_exists($dashboardPath)) {
        $response->getBody()->write('Dashboard not found');
        return $response->withStatus(404);
    }
    
    $html = file_get_contents($dashboardPath);
    
    // Inject API key if set
    if (isset($_ENV['API_KEY'])) {
        $html = str_replace('YOUR_API_KEY_HERE', $_ENV['API_KEY'], $html);
    }
    
    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'application/json');
});

// ============================================================================
// AUDIT LOG ENDPOINTS
// ============================================================================

// Get audit logs (admin only)
$app->get('/api/audit/logs', function (Request $request, Response $response) use ($pdo) {
    $user = $request->getAttribute('user');
    
    if ($user['role'] !== 'admin') {
        $response->getBody()->write(json_encode(['error' => 'Admin access required']));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
    }
    
    $params = $request->getQueryParams();
    $limit = (int)($params['limit'] ?? 100);
    $offset = (int)($params['offset'] ?? 0);
    $action = $params['action'] ?? null;
    $userId = $params['user_id'] ?? null;
    $companyId = $params['company_id'] ?? null;
    $dateFrom = $params['date_from'] ?? null;
    $dateTo = $params['date_to'] ?? null;
    
    // Build WHERE clause
    $where = [];
    $queryParams = [];
    
    if ($action && $action !== 'all') {
        $where[] = 'al.action LIKE ?';
        $queryParams[] = $action . '%';
    }
    
    if ($userId) {
        $where[] = 'al.user_id = ?';
        $queryParams[] = (int)$userId;
    }
    
    if ($companyId && $companyId !== 'all') {
        $where[] = 'al.entity_type = "company" AND al.entity_id = ?';
        $queryParams[] = (int)$companyId;
    }
    
    if ($dateFrom) {
        $where[] = 'al.created_at >= ?';
        $queryParams[] = $dateFrom . ' 00:00:00';
    }
    
    if ($dateTo) {
        $where[] = 'al.created_at <= ?';
        $queryParams[] = $dateTo . ' 23:59:59';
    }
    
    $whereClause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);
    
    // Get total count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs al $whereClause");
    $countStmt->execute($queryParams);
    $totalCount = (int)$countStmt->fetchColumn();
    
    // Get logs
    $queryParams[] = $limit;
    $queryParams[] = $offset;
    
    $stmt = $pdo->prepare("
        SELECT 
            al.id,
            al.action,
            al.entity_type,
            al.entity_id,
            al.details,
            al.ip_address,
            al.created_at,
            u.email as user_email,
            u.full_name as user_name
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.id
        $whereClause
        ORDER BY al.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($queryParams);
    $logs = $stmt->fetchAll();
    
    // Parse details JSON
    foreach ($logs as &$log) {
        $log['details'] = json_decode($log['details'], true);
    }
    
    $response->getBody()->write(json_encode([
        'logs' => $logs,
        'total' => $totalCount,
        'limit' => $limit,
        'offset' => $offset,
        'has_more' => ($offset + $limit) < $totalCount
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
})->add($authMiddleware);

// Get recent activity summary (for audit log page)
$app->get('/api/admin/recent-activity', function (Request $request, Response $response) use ($pdo) {
    $user = $request->getAttribute('user');
    
    if ($user['role'] !== 'admin') {
        $response->getBody()->write(json_encode(['error' => 'Admin access required']));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
    }
    
    // Get sync jobs with company info
    $stmt = $pdo->query("
        SELECT 
            sj.*,
            c.company_code,
            c.company_name,
            u.email as triggered_by_email
        FROM sync_jobs sj
        INNER JOIN companies c ON sj.company_id = c.id
        LEFT JOIN user_sessions us ON us.created_at <= sj.created_at 
            AND (us.expires_at >= sj.created_at OR us.expires_at IS NULL)
        LEFT JOIN users u ON us.user_id = u.id
        ORDER BY sj.created_at DESC
        LIMIT 50
    ");
    $syncJobs = $stmt->fetchAll();
    
    // Get audit logs
    $stmt = $pdo->query("
        SELECT 
            al.*,
            u.email as user_email,
            u.full_name as user_name
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.id
        ORDER BY al.created_at DESC
        LIMIT 50
    ");
    $auditLogs = $stmt->fetchAll();
    
    $response->getBody()->write(json_encode([
        'sync_jobs' => $syncJobs,
        'audit_logs' => $auditLogs
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
})->add($authMiddleware);

// =============================================================================
// SYNC SCHEDULE MANAGEMENT
// =============================================================================

// Get all schedules for a company
$app->get('/api/companies/{companyId}/schedules', function (Request $request, Response $response, array $args) use ($pdo) {
    $companyId = (int)$args['companyId'];
    
    $stmt = $pdo->prepare("
        SELECT 
            id,
            company_id,
            schedule_name,
            job_type,
            frequency,
            cron_expression,
            time_of_day,
            day_of_week,
            day_of_month,
            is_active,
            last_run_at,
            next_run_at,
            created_at,
            updated_at
        FROM sync_schedules
        WHERE company_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$companyId]);
    $schedules = $stmt->fetchAll();
    
    $response->getBody()->write(json_encode($schedules));
    return $response->withHeader('Content-Type', 'application/json');
})->add($authMiddleware);

// Create a new schedule
$app->post('/api/companies/{companyId}/schedules', function (Request $request, Response $response, array $args) use ($pdo) {
    $companyId = (int)$args['companyId'];
    $data = $request->getParsedBody();
    
    $scheduleName = $data['schedule_name'] ?? '';
    $jobType = $data['job_type'] ?? 'full';
    $frequency = $data['frequency'] ?? 'daily';
    $timeOfDay = $data['time_of_day'] ?? '02:00:00';
    $dayOfWeek = $data['day_of_week'] ?? null;
    $dayOfMonth = $data['day_of_month'] ?? null;
    $cronExpression = $data['cron_expression'] ?? null;
    
    if (empty($scheduleName)) {
        $response->getBody()->write(json_encode(['error' => 'Schedule name is required']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    // Calculate next_run_at based on frequency
    $nextRunAt = null;
    switch ($frequency) {
        case 'hourly':
            $nextRunAt = date('Y-m-d H:00:00', strtotime('+1 hour'));
            break;
        case 'daily':
            $nextRunAt = date('Y-m-d ' . $timeOfDay, strtotime('tomorrow'));
            break;
        case 'weekly':
            // Calculate next occurrence of day_of_week
            $targetDay = $dayOfWeek ?? 1; // Default Monday
            $daysUntil = ($targetDay - date('N') + 7) % 7;
            if ($daysUntil == 0) $daysUntil = 7; // Next week if today
            $nextRunAt = date('Y-m-d ' . $timeOfDay, strtotime("+{$daysUntil} days"));
            break;
        case 'monthly':
            // Calculate next occurrence of day_of_month
            $targetDay = $dayOfMonth ?? 1;
            $nextMonth = date('Y-m-01', strtotime('first day of next month'));
            $nextRunAt = date('Y-m-' . str_pad($targetDay, 2, '0', STR_PAD_LEFT) . ' ' . $timeOfDay, strtotime($nextMonth));
            break;
        case 'custom':
            // For custom cron, set to next hour for now (proper cron parser would be better)
            $nextRunAt = date('Y-m-d H:00:00', strtotime('+1 hour'));
            break;
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO sync_schedules 
            (company_id, schedule_name, job_type, frequency, cron_expression, time_of_day, day_of_week, day_of_month, next_run_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $companyId,
            $scheduleName,
            $jobType,
            $frequency,
            $cronExpression,
            $timeOfDay,
            $dayOfWeek,
            $dayOfMonth,
            $nextRunAt
        ]);
        
        $scheduleId = (int)$pdo->lastInsertId();
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'schedule_id' => $scheduleId,
            'next_run_at' => $nextRunAt
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (\PDOException $e) {
        $response->getBody()->write(json_encode(['error' => 'Failed to create schedule: ' . $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add($authMiddleware);

// Update schedule
$app->put('/api/companies/{companyId}/schedules/{scheduleId}', function (Request $request, Response $response, array $args) use ($pdo) {
    $scheduleId = (int)$args['scheduleId'];
    $data = $request->getParsedBody();
    
    $isActive = isset($data['is_active']) ? (int)$data['is_active'] : null;
    
    if ($isActive !== null) {
        $stmt = $pdo->prepare("UPDATE sync_schedules SET is_active = ? WHERE id = ?");
        $stmt->execute([$isActive, $scheduleId]);
        
        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    $response->getBody()->write(json_encode(['error' => 'No valid update data provided']));
    return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
})->add($authMiddleware);

// Delete schedule
$app->delete('/api/companies/{companyId}/schedules/{scheduleId}', function (Request $request, Response $response, array $args) use ($pdo) {
    $scheduleId = (int)$args['scheduleId'];
    
    $stmt = $pdo->prepare("DELETE FROM sync_schedules WHERE id = ?");
    $stmt->execute([$scheduleId]);
    
    $response->getBody()->write(json_encode(['success' => true]));
    return $response->withHeader('Content-Type', 'application/json');
})->add($authMiddleware);

// Health check
$app->get('/health', function (Request $request, Response $response) {
    $response->getBody()->write(json_encode([
        'status' => 'ok',
        'timestamp' => date('Y-m-d H:i:s')
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
});
