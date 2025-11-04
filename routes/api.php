<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Get services from container
$container = $app->getContainer();
$pdo = $container->get(PDO::class);

// Redirect root URL to login page
$app->get('/', function (Request $request, Response $response) {
    return $response
        ->withHeader('Location', '/login.html')
        ->withStatus(302);
});

// Create auth middleware instance
$authMiddleware = new \App\Middleware\AuthMiddleware($pdo);

// Load authentication routes (public endpoints like login)
$authRoutes = require __DIR__ . '/auth.php';
$authRoutes($app);

// Load email management routes (admin-only)
$emailRoutes = require __DIR__ . '/email.php';
$emailRoutes($app, $container);

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

// Cancel all running/pending jobs for a company (admin only)
$app->post('/api/companies/{id}/cancel-jobs', function (Request $request, Response $response, array $args) use ($pdo) {
    $user = $request->getAttribute('user');
    
    if ($user['role'] !== 'admin') {
        $response->getBody()->write(json_encode([
            'success' => false,
            'message' => 'Admin access required'
        ]));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
    }
    
    $companyId = (int)$args['id'];
    $data = $request->getParsedBody();
    $cancelPending = (bool)($data['cancel_pending'] ?? true); // Default: cancel both running and pending
    
    try {
        // Build WHERE clause based on what to cancel
        $statuses = ['running'];
        if ($cancelPending) {
            $statuses[] = 'pending';
        }
        
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '?'));
        
        // Cancel running/pending jobs for this company
        $stmt = $pdo->prepare("
            UPDATE sync_jobs 
            SET status = 'cancelled',
                error_message = CONCAT('Cancelled by admin user (was ', status, ')'),
                completed_at = NOW()
            WHERE company_id = ? 
            AND status IN ($statusPlaceholders)
        ");
        
        $params = array_merge([$companyId], $statuses);
        $stmt->execute($params);
        $cancelledCount = $stmt->rowCount();
        
        $statusesStr = implode(' and ', $statuses);
        $message = $cancelledCount > 0 
            ? "Successfully cancelled {$cancelledCount} {$statusesStr} job(s)" 
            : "No {$statusesStr} jobs found for this company";
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'cancelled_count' => $cancelledCount,
            'cancelled_statuses' => $statuses,
            'message' => $message
        ]));
        
    } catch (\PDOException $e) {
        error_log("Cancel jobs error: " . $e->getMessage());
        $response->getBody()->write(json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
    
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
    $userId = $user['id'] ?? null;
    
    // Debug: Check what tables exist
    $debugInfo = ['company_id' => $companyId, 'user_id' => $userId];
    
    // First, try to get user-specific credentials (if user_devpos_credentials table exists)
    $creds = null;
    try {
        if ($userId) {
            $stmt = $pdo->prepare("
                SELECT tenant, username, password_encrypted, 'user' as credential_type
                FROM user_devpos_credentials 
                WHERE company_id = ? AND user_id = ?
            ");
            $stmt->execute([$companyId, $userId]);
            $creds = $stmt->fetch();
            $debugInfo['user_credentials_checked'] = true;
            $debugInfo['user_credentials_found'] = $creds ? true : false;
        }
    } catch (PDOException $e) {
        // Table might not exist, continue to company-level
        $debugInfo['user_credentials_table_error'] = $e->getMessage();
    }
    
    // If no user-specific credentials, fallback to company-level credentials
    if (!$creds) {
        try {
            $stmt = $pdo->prepare("
                SELECT tenant, username, password_encrypted, 'company' as credential_type
                FROM company_credentials_devpos 
                WHERE company_id = ?
            ");
            $stmt->execute([$companyId]);
            $creds = $stmt->fetch();
            $debugInfo['company_credentials_checked'] = true;
            $debugInfo['company_credentials_found'] = $creds ? true : false;
        } catch (PDOException $e) {
            $debugInfo['company_credentials_error'] = $e->getMessage();
        }
    }
    
    if (!$creds) {
        $response->getBody()->write(json_encode([
            'success' => false, 
            'message' => 'No credentials configured',
            'debug' => $debugInfo
        ]));
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
            $errorData = json_decode($errorBody, true);
            $errorMessage = $errorData['error_description'] ?? $errorData['error'] ?? $errorBody;
            
            $response->getBody()->write(json_encode([
                'success' => false, 
                'message' => "DevPos authentication failed (HTTP $statusCode)",
                'error' => $errorMessage,
                'status_code' => $statusCode,
                'tenant' => $creds['tenant'],
                'username' => $creds['username']
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }
        
        $authData = json_decode($authResponse->getBody()->getContents(), true);
        
        if (isset($authData['access_token'])) {
            // Store the token in the database for future use
            $expiresAt = time() + (int)($authData['expires_in'] ?? 28800);
            
            try {
                // Check if token already exists for this company
                $checkStmt = $pdo->prepare("SELECT id FROM oauth_tokens_devpos WHERE company_id = ?");
                $checkStmt->execute([$companyId]);
                $existingToken = $checkStmt->fetch();
                
                if ($existingToken) {
                    // Update existing token
                    $stmt = $pdo->prepare("
                        UPDATE oauth_tokens_devpos 
                        SET access_token = ?, token_type = ?, expires_at = FROM_UNIXTIME(?), updated_at = CURRENT_TIMESTAMP
                        WHERE company_id = ?
                    ");
                    $stmt->execute([
                        $authData['access_token'],
                        $authData['token_type'] ?? 'Bearer',
                        $expiresAt,
                        $companyId
                    ]);
                } else {
                    // Insert new token
                    $stmt = $pdo->prepare("
                        INSERT INTO oauth_tokens_devpos (company_id, access_token, token_type, expires_at)
                        VALUES (?, ?, ?, FROM_UNIXTIME(?))
                    ");
                    $stmt->execute([
                        $companyId,
                        $authData['access_token'],
                        $authData['token_type'] ?? 'Bearer',
                        $expiresAt
                    ]);
                }
                
                $debugInfo['token_stored'] = true;
                $debugInfo['token_action'] = $existingToken ? 'updated' : 'inserted';
            } catch (PDOException $e) {
                $debugInfo['token_storage_error'] = $e->getMessage();
            }
            
            $expiresIn = $authData['expires_in'] ?? 3600;
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => '✅ DevPos connection successful! Token stored.',
                'expires_in' => $expiresIn,
                'expires_in_hours' => round($expiresIn / 3600, 1),
                'debug' => $debugInfo
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

// Cancel all pending jobs (before they start) - admin only
$app->post('/api/sync/jobs/cancel-pending', function (Request $request, Response $response) use ($pdo) {
    try {
        $data = $request->getParsedBody();
        $companyId = isset($data['company_id']) ? (int)$data['company_id'] : null;
        
        if ($companyId) {
            // Cancel pending jobs for specific company
            $stmt = $pdo->prepare("
                SELECT id, job_type, created_at, company_id
                FROM sync_jobs 
                WHERE status = 'pending' AND company_id = ?
                ORDER BY created_at ASC
            ");
            $stmt->execute([$companyId]);
        } else {
            // Cancel all pending jobs (global)
            $stmt = $pdo->query("
                SELECT id, job_type, created_at, company_id
                FROM sync_jobs 
                WHERE status = 'pending'
                ORDER BY created_at ASC
            ");
        }
        
        $pendingJobs = $stmt->fetchAll();
        
        if (empty($pendingJobs)) {
            $message = $companyId 
                ? "No pending jobs found for company ID $companyId" 
                : "No pending jobs found";
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => $message,
                'cancelled_count' => 0
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }
        
        // Cancel all pending jobs
        if ($companyId) {
            $updateStmt = $pdo->prepare("
                UPDATE sync_jobs 
                SET status = 'cancelled',
                    error_message = 'Cancelled before execution by admin',
                    completed_at = NOW()
                WHERE status = 'pending' AND company_id = ?
            ");
            $updateStmt->execute([$companyId]);
        } else {
            $updateStmt = $pdo->query("
                UPDATE sync_jobs 
                SET status = 'cancelled',
                    error_message = 'Cancelled before execution by admin',
                    completed_at = NOW()
                WHERE status = 'pending'
            ");
        }
        
        $cancelledCount = $updateStmt->rowCount();
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => "Cancelled {$cancelledCount} pending job(s)",
            'cancelled_count' => $cancelledCount,
            'company_id' => $companyId,
            'jobs' => $pendingJobs
        ]));
        return $response->withHeader('Content-Type', 'application/json');
        
    } catch (\Exception $e) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'message' => 'Failed to cancel pending jobs: ' . $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add($authMiddleware);

// Cancel all running/stuck sync jobs (admin utility) - MUST be before pattern routes
$app->post('/api/sync/jobs/cancel-stuck', function (Request $request, Response $response) use ($pdo) {
    try {
        $data = $request->getParsedBody();
        $minutesThreshold = (int)($data['minutes'] ?? 30); // Default: jobs running > 30 minutes
        
        // Find stuck jobs
        $stmt = $pdo->prepare("
            SELECT id, job_type, started_at, TIMESTAMPDIFF(MINUTE, started_at, NOW()) as minutes_running
            FROM sync_jobs 
            WHERE status = 'running' 
            AND started_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $stmt->execute([$minutesThreshold]);
        $stuckJobs = $stmt->fetchAll();
        
        if (empty($stuckJobs)) {
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'No stuck jobs found',
                'cancelled_count' => 0
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }
        
        // Cancel them with 'cancelled' status
        $stmt = $pdo->prepare("
            UPDATE sync_jobs 
            SET status = 'cancelled',
                error_message = CONCAT('Job timeout - exceeded ', ?, ' minutes (automatic cancellation)'),
                completed_at = NOW()
            WHERE status = 'running' 
            AND started_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $stmt->execute([$minutesThreshold, $minutesThreshold]);
        $cancelledCount = $stmt->rowCount();
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => "Cancelled {$cancelledCount} stuck job(s)",
            'cancelled_count' => $cancelledCount,
            'threshold_minutes' => $minutesThreshold,
            'jobs' => $stuckJobs
        ]));
        return $response->withHeader('Content-Type', 'application/json');
        
    } catch (\Exception $e) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'message' => 'Failed to cancel stuck jobs: ' . $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add($authMiddleware);

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

// Cancel a specific sync job
$app->post('/api/sync/jobs/{jobId}/cancel', function (Request $request, Response $response, array $args) use ($pdo) {
    $jobId = (int)$args['jobId'];
    
    try {
        // Get job info first
        $stmt = $pdo->prepare("SELECT id, status, job_type FROM sync_jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();
        
        if (!$job) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Job not found'
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        if ($job['status'] !== 'running' && $job['status'] !== 'pending') {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => "Cannot cancel job with status: {$job['status']}"
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        // Cancel the job with proper 'cancelled' status
        $stmt = $pdo->prepare("
            UPDATE sync_jobs 
            SET status = 'cancelled',
                error_message = 'Manually cancelled by user',
                completed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$jobId]);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Job cancelled successfully',
            'job_id' => $jobId,
            'previous_status' => $job['status']
        ]));
        return $response->withHeader('Content-Type', 'application/json');
        
    } catch (\Exception $e) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'message' => 'Failed to cancel job: ' . $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add($authMiddleware);

// Get all running sync jobs (admin utility)
$app->get('/api/sync/jobs/running', function (Request $request, Response $response) use ($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                sj.id,
                sj.company_id,
                c.company_name,
                sj.job_type,
                sj.status,
                sj.from_date,
                sj.to_date,
                sj.started_at,
                sj.created_at,
                TIMESTAMPDIFF(MINUTE, sj.started_at, NOW()) as minutes_running
            FROM sync_jobs sj
            LEFT JOIN companies c ON sj.company_id = c.company_id
            WHERE sj.status = 'running'
            ORDER BY sj.started_at ASC
        ");
        $stmt->execute();
        $runningJobs = $stmt->fetchAll();
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'jobs' => $runningJobs,
            'count' => count($runningJobs)
        ]));
        return $response->withHeader('Content-Type', 'application/json');
        
    } catch (\Exception $e) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'message' => 'Failed to fetch running jobs: ' . $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add($authMiddleware);

// Get synced transactions for company
$app->get('/api/sync/{companyId}/transactions', function (Request $request, Response $response, array $args) use ($pdo) {
    try {
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
    } catch (PDOException $e) {
        $response->getBody()->write(json_encode([
            'error' => 'Database error',
            'message' => $e->getMessage(),
            'transactions' => [],
            'total' => 0,
            'limit' => $limit ?? 100,
            'offset' => $offset ?? 0,
            'has_more' => false
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    }
})->add($authMiddleware);

// Get sync statistics for company
$app->get('/api/sync/{companyId}/stats', function (Request $request, Response $response, array $args) use ($pdo) {
    try {
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
    } catch (PDOException $e) {
        error_log("Stats API error: " . $e->getMessage());
        $response->getBody()->write(json_encode([
            'error' => 'Database error',
            'message' => $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add($authMiddleware);

// ============================================================================
// QUICKBOOKS OAUTH ENDPOINTS
// ============================================================================

// Clean up expired OAuth state tokens (run periodically or on-demand)
$app->delete('/api/oauth/cleanup-tokens', function (Request $request, Response $response) use ($pdo) {
    try {
        $stmt = $pdo->prepare("DELETE FROM oauth_state_tokens WHERE expires_at < NOW()");
        $stmt->execute();
        $deleted = $stmt->rowCount();
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'deleted' => $deleted,
            'message' => "Cleaned up $deleted expired state tokens"
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add($authMiddleware);

// Get QuickBooks OAuth authorization URL
$app->get('/api/companies/{companyId}/qbo/auth-url', function (Request $request, Response $response, array $args) use ($pdo) {
    try {
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
        
        if (empty($clientId)) {
            $response->getBody()->write(json_encode(['error' => 'QBO_CLIENT_ID not configured']));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
        
        // Generate state token with company ID
        $state = base64_encode(json_encode([
            'company_id' => $companyId,
            'timestamp' => time(),
            'token' => bin2hex(random_bytes(16))
        ]));
        
        // Store state in session for verification (create table if not exists)
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS oauth_state_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                company_id INT NOT NULL,
                state_token TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NOT NULL,
                UNIQUE KEY unique_company (company_id),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            
            // Delete any existing state tokens for this company (important for reconnection)
            $stmt = $pdo->prepare("DELETE FROM oauth_state_tokens WHERE company_id = ?");
            $stmt->execute([$companyId]);
            
            // Insert new state token
            $stmt = $pdo->prepare("
                INSERT INTO oauth_state_tokens (company_id, state_token, created_at, expires_at)
                VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 10 MINUTE))
            ");
            $stmt->execute([$companyId, $state]);
        } catch (PDOException $e) {
            error_log("OAuth state token storage failed: " . $e->getMessage());
            // Continue anyway - state verification is optional
        }
        
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
    } catch (Exception $e) {
        error_log("QBO auth-url error: " . $e->getMessage());
        $response->getBody()->write(json_encode(['error' => 'Internal server error: ' . $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
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
            throw new Exception('Invalid state token: company ID not found');
        }
        
        // Get stored state token for this company
        $stmt = $pdo->prepare("
            SELECT state_token, created_at 
            FROM oauth_state_tokens 
            WHERE company_id = ? AND expires_at > NOW()
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$companyId]);
        $storedStateRow = $stmt->fetch();
        
        if (!$storedStateRow) {
            throw new Exception('State token expired or not found. Please try connecting again.');
        }
        
        $storedState = $storedStateRow['state_token'];
        
        if ($storedState !== $state) {
            error_log("OAuth state mismatch - Expected: " . substr($storedState, 0, 50) . "... Got: " . substr($state, 0, 50) . "...");
            throw new Exception('State token mismatch. Please close this window and try connecting again.');
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
        
        // Save tokens to database (both tables for compatibility)
        // Calculate token expiration timestamp
        $expiresIn = $tokens['expires_in'] ?? 3600; // Default 1 hour
        
        // Update company_credentials_qbo with tokens and expiration
        $stmt = $pdo->prepare("
            INSERT INTO company_credentials_qbo (company_id, realm_id, access_token, refresh_token, token_expires_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
                realm_id = ?, 
                access_token = ?,
                refresh_token = ?,
                token_expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                updated_at = NOW()
        ");
        $stmt->execute([
            $companyId,
            $realmId,
            $tokens['access_token'],
            $tokens['refresh_token'],
            $expiresIn,
            $realmId,
            $tokens['access_token'],
            $tokens['refresh_token'],
            $expiresIn
        ]);
        
        // Also store in oauth_tokens_qbo if that table exists
        try {
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
                $expiresIn,
                $tokens['access_token'],
                $tokens['refresh_token'],
                $expiresIn
            ]);
        } catch (Exception $e) {
            // Table might not exist, that's okay
            error_log("oauth_tokens_qbo table insert failed (might not exist): " . $e->getMessage());
        }
        
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

// OAuth Refresh Token endpoint (with auth)
$app->post('/oauth/refresh/{company_id}', function (Request $request, Response $response, array $args) use ($pdo) {
    try {
        $companyId = (int)$args['company_id'];
        
        // Get current refresh token
        $stmt = $pdo->prepare("
            SELECT refresh_token, token_expires_at
            FROM company_credentials_qbo
            WHERE company_id = ?
        ");
        $stmt->execute([$companyId]);
        $creds = $stmt->fetch();
        
        if (!$creds || !$creds['refresh_token']) {
            throw new Exception('No refresh token found. Please reconnect QuickBooks.');
        }
        
        // Check if token is already valid for more than 10 minutes
        if ($creds['token_expires_at']) {
            $expiresAt = strtotime($creds['token_expires_at']);
            $now = time();
            $timeRemaining = $expiresAt - $now;
            
            if ($timeRemaining > 600) { // More than 10 minutes remaining
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'message' => 'Token still valid',
                    'expires_in' => $timeRemaining
                ]));
                return $response->withHeader('Content-Type', 'application/json');
            }
        }
        
        // Refresh the token
        $client = new \GuzzleHttp\Client();
        $tokenResponse = $client->post('https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer', [
            'form_params' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $creds['refresh_token']
            ],
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode(($_ENV['QBO_CLIENT_ID'] ?? '') . ':' . ($_ENV['QBO_CLIENT_SECRET'] ?? '')),
                'Content-Type' => 'application/x-www-form-urlencoded'
            ]
        ]);
        
        $tokens = json_decode($tokenResponse->getBody()->getContents(), true);
        $expiresIn = $tokens['expires_in'] ?? 3600;
        
        // Update tokens in database
        $stmt = $pdo->prepare("
            UPDATE company_credentials_qbo
            SET access_token = ?,
                refresh_token = ?,
                token_expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                updated_at = NOW()
            WHERE company_id = ?
        ");
        $stmt->execute([
            $tokens['access_token'],
            $tokens['refresh_token'],
            $expiresIn,
            $companyId
        ]);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Token refreshed successfully',
            'expires_in' => $expiresIn
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
        
    } catch (\GuzzleHttp\Exception\ClientException $e) {
        $statusCode = $e->getResponse()->getStatusCode();
        $errorBody = $e->getResponse()->getBody()->getContents();
        error_log("OAuth refresh failed: " . $errorBody);
        
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Token refresh failed. Please reconnect QuickBooks.',
            'details' => $errorBody
        ]));
        return $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
        
    } catch (Exception $e) {
        error_log("OAuth refresh error: " . $e->getMessage());
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add($authMiddleware);

// Disconnect QuickBooks for a company
$app->delete('/api/companies/{id}/qbo/disconnect', function (Request $request, Response $response, array $args) use ($pdo) {
    try {
        $companyId = (int)$args['id'];
        
        // Delete QuickBooks OAuth tokens
        $stmt = $pdo->prepare("DELETE FROM oauth_tokens_qbo WHERE company_id = ?");
        $stmt->execute([$companyId]);
        
        // Delete QuickBooks credentials
        $stmt = $pdo->prepare("DELETE FROM company_credentials_qbo WHERE company_id = ?");
        $stmt->execute([$companyId]);
        
        error_log("Disconnected QuickBooks for company $companyId");
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'QuickBooks disconnected successfully'
        ]));
        return $response->withHeader('Content-Type', 'application/json');
        
    } catch (Exception $e) {
        error_log("QuickBooks disconnect error: " . $e->getMessage());
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add($authMiddleware);

// Get company connection status
$app->get('/companies/{id}/status', function (Request $request, Response $response, array $args) use ($pdo) {
    try {
        $companyId = (int)$args['id'];
        
        $status = [
            'quickbooks' => [
                'connected' => false,
                'status' => 'disconnected',
                'expires_at' => null,
                'expires_in_days' => null,
                'realm_id' => null
            ],
            'devpos' => [
                'connected' => false,
                'status' => 'disconnected'
            ]
        ];
        
        // Check QuickBooks connection
        $stmt = $pdo->prepare("
            SELECT realm_id, access_token, token_expires_at
            FROM company_credentials_qbo
            WHERE company_id = ?
        ");
        $stmt->execute([$companyId]);
        $qboCreds = $stmt->fetch();
        
        if ($qboCreds && $qboCreds['access_token']) {
            $status['quickbooks']['connected'] = true;
            $status['quickbooks']['realm_id'] = $qboCreds['realm_id'];
            
            if ($qboCreds['token_expires_at']) {
                $expiresAt = strtotime($qboCreds['token_expires_at']);
                $now = time();
                $timeRemaining = $expiresAt - $now;
                $daysRemaining = floor($timeRemaining / 86400);
                
                $status['quickbooks']['expires_at'] = $qboCreds['token_expires_at'];
                $status['quickbooks']['expires_in_days'] = $daysRemaining;
                
                if ($timeRemaining <= 0) {
                    $status['quickbooks']['status'] = 'expired';
                } elseif ($daysRemaining <= 7) {
                    $status['quickbooks']['status'] = 'expiring_soon';
                } else {
                    $status['quickbooks']['status'] = 'connected';
                }
            } else {
                $status['quickbooks']['status'] = 'connected';
            }
        }
        
        // Check DevPos connection
        $stmt = $pdo->prepare("
            SELECT tenant, username
            FROM company_credentials_devpos
            WHERE company_id = ?
        ");
        $stmt->execute([$companyId]);
        $devposCreds = $stmt->fetch();
        
        if ($devposCreds && $devposCreds['tenant'] && $devposCreds['username']) {
            $status['devpos']['connected'] = true;
            $status['devpos']['status'] = 'connected';
            $status['devpos']['tenant'] = $devposCreds['tenant'];
        }
        
        $response->getBody()->write(json_encode($status));
        return $response->withHeader('Content-Type', 'application/json');
        
    } catch (Exception $e) {
        error_log("Connection status error: " . $e->getMessage());
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add($authMiddleware);

// ============================================================================
// VAT TRACKING CONFIGURATION
// ============================================================================

// Get VAT tracking setting for a company
$app->get('/api/companies/{id}/vat-tracking', function (Request $request, Response $response, array $args) use ($pdo) {
    try {
        $companyId = (int)$args['id'];
        
        $stmt = $pdo->prepare("SELECT tracks_vat FROM companies WHERE id = ?");
        $stmt->execute([$companyId]);
        $tracksVat = $stmt->fetchColumn();
        
        if ($tracksVat === false) {
            $response->getBody()->write(json_encode(['error' => 'Company not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        $response->getBody()->write(json_encode([
            'company_id' => $companyId,
            'tracks_vat' => (bool)$tracksVat
        ]));
        return $response->withHeader('Content-Type', 'application/json');
        
    } catch (Exception $e) {
        error_log("Get VAT tracking error: " . $e->getMessage());
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add($authMiddleware);

// Update VAT tracking setting for a company
$app->patch('/api/companies/{id}/vat-tracking', function (Request $request, Response $response, array $args) use ($pdo) {
    try {
        $companyId = (int)$args['id'];
        $data = json_decode($request->getBody()->getContents(), true);
        
        $tracksVat = isset($data['tracks_vat']) ? (bool)$data['tracks_vat'] : false;
        
        $stmt = $pdo->prepare("UPDATE companies SET tracks_vat = ? WHERE id = ?");
        $stmt->execute([$tracksVat ? 1 : 0, $companyId]);
        
        if ($stmt->rowCount() === 0) {
            $response->getBody()->write(json_encode(['error' => 'Company not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        error_log("Updated company $companyId VAT tracking to: " . ($tracksVat ? 'TRUE' : 'FALSE'));
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'company_id' => $companyId,
            'tracks_vat' => $tracksVat,
            'message' => $tracksVat 
                ? 'VAT tracking enabled - QuickBooks will calculate and track VAT separately'
                : 'VAT tracking disabled - Totals will be posted without VAT breakdown'
        ]));
        return $response->withHeader('Content-Type', 'application/json');
        
    } catch (Exception $e) {
        error_log("Update VAT tracking error: " . $e->getMessage());
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add($authMiddleware);

// ============================================================================
// VAT RATE MAPPING ENDPOINTS
// ============================================================================

// Get VAT rate mappings for a company
$app->get('/api/companies/{id}/vat-mappings', function (Request $request, Response $response, array $args) use ($pdo) {
    try {
        $companyId = (int)$args['id'];
        
        $stmt = $pdo->prepare("
            SELECT * FROM vat_rate_mappings
            WHERE company_id = ?
            ORDER BY devpos_vat_rate ASC
        ");
        $stmt->execute([$companyId]);
        $mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Convert is_excluded to boolean
        foreach ($mappings as &$mapping) {
            $mapping['is_excluded'] = (bool)$mapping['is_excluded'];
        }
        
        $response->getBody()->write(json_encode($mappings));
        return $response->withHeader('Content-Type', 'application/json');
        
    } catch (Exception $e) {
        error_log("Get VAT mappings error: " . $e->getMessage());
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add($authMiddleware);

// Create or update VAT rate mapping
$app->post('/api/companies/{id}/vat-mappings', function (Request $request, Response $response, array $args) use ($pdo) {
    try {
        $companyId = (int)$args['id'];
        $data = json_decode($request->getBody()->getContents(), true);
        
        $devposVatRate = (float)($data['devpos_vat_rate'] ?? 0);
        $qboTaxCode = $data['qbo_tax_code'] ?? 'TAX';
        $qboTaxCodeName = $data['qbo_tax_code_name'] ?? null;
        $isExcluded = isset($data['is_excluded']) ? (bool)$data['is_excluded'] : false;
        
        $stmt = $pdo->prepare("
            INSERT INTO vat_rate_mappings 
            (company_id, devpos_vat_rate, qbo_tax_code, qbo_tax_code_name, is_excluded)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                qbo_tax_code = VALUES(qbo_tax_code),
                qbo_tax_code_name = VALUES(qbo_tax_code_name),
                is_excluded = VALUES(is_excluded)
        ");
        $stmt->execute([
            $companyId, 
            $devposVatRate, 
            $qboTaxCode, 
            $qboTaxCodeName, 
            $isExcluded ? 1 : 0
        ]);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'VAT rate mapping saved',
            'mapping' => [
                'company_id' => $companyId,
                'devpos_vat_rate' => $devposVatRate,
                'qbo_tax_code' => $qboTaxCode,
                'is_excluded' => $isExcluded
            ]
        ]));
        return $response->withHeader('Content-Type', 'application/json');
        
    } catch (Exception $e) {
        error_log("Create VAT mapping error: " . $e->getMessage());
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add($authMiddleware);

// Delete VAT rate mapping
$app->delete('/api/companies/{id}/vat-mappings/{mappingId}', function (Request $request, Response $response, array $args) use ($pdo) {
    try {
        $companyId = (int)$args['id'];
        $mappingId = (int)$args['mappingId'];
        
        $stmt = $pdo->prepare("
            DELETE FROM vat_rate_mappings
            WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([$mappingId, $companyId]);
        
        if ($stmt->rowCount() === 0) {
            $response->getBody()->write(json_encode(['error' => 'Mapping not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'VAT rate mapping deleted'
        ]));
        return $response->withHeader('Content-Type', 'application/json');
        
    } catch (Exception $e) {
        error_log("Delete VAT mapping error: " . $e->getMessage());
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add($authMiddleware);

// ============================================================================
// SCHEDULED SYNC ENDPOINTS
// ============================================================================

// Get scheduled syncs for a company
$app->get('/companies/{id}/schedules', function (Request $request, Response $response, array $args) use ($pdo) {
    try {
        $companyId = (int)$args['id'];
        
        $stmt = $pdo->prepare("
            SELECT * FROM scheduled_syncs
            WHERE company_id = ?
            ORDER BY id ASC
        ");
        $stmt->execute([$companyId]);
        $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $response->getBody()->write(json_encode($schedules));
        return $response->withHeader('Content-Type', 'application/json');
        
    } catch (Exception $e) {
        error_log("Get schedules error: " . $e->getMessage());
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add($authMiddleware);

// Create or update scheduled sync
$app->post('/companies/{id}/schedules', function (Request $request, Response $response, array $args) use ($pdo) {
    try {
        $companyId = (int)$args['id'];
        $data = json_decode($request->getBody()->getContents(), true);
        
        $jobType = $data['job_type'] ?? 'full';
        $frequency = $data['frequency'] ?? 'daily';
        $hourOfDay = $data['hour_of_day'] ?? 9;
        $dayOfWeek = $data['day_of_week'] ?? null;
        $dayOfMonth = $data['day_of_month'] ?? null;
        $dateRangeDays = $data['date_range_days'] ?? 30;
        $enabled = $data['enabled'] ?? true;
        
        // Calculate next run time
        $nextRun = calculateNextRun($frequency, $hourOfDay, $dayOfWeek, $dayOfMonth);
        
        $stmt = $pdo->prepare("
            INSERT INTO scheduled_syncs 
            (company_id, job_type, frequency, hour_of_day, day_of_week, day_of_month, date_range_days, enabled, next_run_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                job_type = VALUES(job_type),
                frequency = VALUES(frequency),
                hour_of_day = VALUES(hour_of_day),
                day_of_week = VALUES(day_of_week),
                day_of_month = VALUES(day_of_month),
                date_range_days = VALUES(date_range_days),
                enabled = VALUES(enabled),
                next_run_at = VALUES(next_run_at)
        ");
        $stmt->execute([
            $companyId, $jobType, $frequency, $hourOfDay, 
            $dayOfWeek, $dayOfMonth, $dateRangeDays, $enabled ? 1 : 0, $nextRun
        ]);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Scheduled sync configured',
            'next_run' => $nextRun
        ]));
        return $response->withHeader('Content-Type', 'application/json');
        
    } catch (Exception $e) {
        error_log("Create schedule error: " . $e->getMessage());
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add($authMiddleware);

// Enable/disable scheduled sync
$app->patch('/schedules/{id}/toggle', function (Request $request, Response $response, array $args) use ($pdo) {
    try {
        $scheduleId = (int)$args['id'];
        $data = json_decode($request->getBody()->getContents(), true);
        $enabled = $data['enabled'] ?? true;
        
        $stmt = $pdo->prepare("
            UPDATE scheduled_syncs
            SET enabled = ?
            WHERE id = ?
        ");
        $stmt->execute([$enabled ? 1 : 0, $scheduleId]);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => $enabled ? 'Schedule enabled' : 'Schedule disabled'
        ]));
        return $response->withHeader('Content-Type', 'application/json');
        
    } catch (Exception $e) {
        error_log("Toggle schedule error: " . $e->getMessage());
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add($authMiddleware);

// Delete scheduled sync
$app->delete('/schedules/{id}', function (Request $request, Response $response, array $args) use ($pdo) {
    try {
        $scheduleId = (int)$args['id'];
        
        $stmt = $pdo->prepare("DELETE FROM scheduled_syncs WHERE id = ?");
        $stmt->execute([$scheduleId]);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Schedule deleted'
        ]));
        return $response->withHeader('Content-Type', 'application/json');
        
    } catch (Exception $e) {
        error_log("Delete schedule error: " . $e->getMessage());
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add($authMiddleware);

/**
 * Helper function to calculate next run time
 */
function calculateNextRun(string $frequency, int $hourOfDay, ?int $dayOfWeek, ?int $dayOfMonth): string
{
    $now = new DateTime();
    
    switch ($frequency) {
        case 'hourly':
            $next = clone $now;
            $next->modify('+1 hour');
            $next->setTime((int)$next->format('H'), 0, 0);
            break;
            
        case 'daily':
            $next = clone $now;
            $next->modify('+1 day');
            $next->setTime($hourOfDay, 0, 0);
            
            $today = clone $now;
            $today->setTime($hourOfDay, 0, 0);
            if ($today > $now) {
                $next = $today;
            }
            break;
            
        case 'weekly':
            $dayOfWeek = $dayOfWeek ?? 1;
            $next = clone $now;
            $next->modify('next ' . ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$dayOfWeek]);
            $next->setTime($hourOfDay, 0, 0);
            break;
            
        case 'monthly':
            $dayOfMonth = $dayOfMonth ?? 1;
            $next = clone $now;
            $next->modify('first day of next month');
            $next->modify('+' . ($dayOfMonth - 1) . ' days');
            $next->setTime($hourOfDay, 0, 0);
            
            $thisMonth = clone $now;
            $thisMonth->setDate((int)$now->format('Y'), (int)$now->format('m'), $dayOfMonth);
            $thisMonth->setTime($hourOfDay, 0, 0);
            if ($thisMonth > $now) {
                $next = $thisMonth;
            }
            break;
            
        default:
            $next = clone $now;
            $next->modify('+1 day');
            $next->setTime(9, 0, 0);
    }
    
    return $next->format('Y-m-d H:i:s');
}

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
