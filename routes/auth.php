<?php

declare(strict_types=1);

// Auth API Routes

use App\Services\AuthService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

$authRoutes = function ($app) use ($pdo) {
    $authService = new AuthService($pdo);

    // Login
    $app->post('/api/auth/login', function (Request $request, Response $response) use ($authService) {
        $data = json_decode($request->getBody()->getContents(), true);
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        if (empty($email) || empty($password)) {
            $response->getBody()->write(json_encode([
                'error' => 'validation_error',
                'message' => 'Email and password are required'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $result = $authService->login(
                $email,
                $password,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            );

            // Set session cookie (7 days, HTTP-only, same-site)
            // Detect environment for cookie path
            $basePath = ($_ENV['APP_ENV'] ?? 'development') === 'production' ? '/public' : '/multi-company-Dev2Qbo/public';
            $cookieValue = sprintf(
                'session_token=%s; Path=%s; Max-Age=%d; HttpOnly; SameSite=Lax',
                $result['session_token'],
                $basePath,
                7 * 24 * 60 * 60 // 7 days in seconds
            );

            $response->getBody()->write(json_encode($result));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Set-Cookie', $cookieValue);

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'error' => 'authentication_failed',
                'message' => $e->getMessage()
            ]));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
    });

    // Logout
    $app->post('/api/auth/logout', function (Request $request, Response $response) use ($authService) {
        $user = $request->getAttribute('user');
        $token = $user['session_token'] ?? null;

        if ($token) {
            $authService->logout($token);
        }

        // Clear session cookie
        $basePath = ($_ENV['APP_ENV'] ?? 'development') === 'production' ? '/public' : '/multi-company-Dev2Qbo/public';
        $cookieValue = "session_token=; Path={$basePath}; Max-Age=0; HttpOnly; SameSite=Lax";

        $response->getBody()->write(json_encode(['success' => true]));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Set-Cookie', $cookieValue);
    })->add(new \App\Middleware\AuthMiddleware($pdo));

    // Get current user
    $app->get('/api/auth/me', function (Request $request, Response $response) {
        $user = $request->getAttribute('user');
        $response->getBody()->write(json_encode($user));
        return $response->withHeader('Content-Type', 'application/json');
    })->add(new \App\Middleware\AuthMiddleware($pdo));

    // Admin: List all users
    $app->get('/api/auth/users', function (Request $request, Response $response) use ($pdo) {
        $stmt = $pdo->query("
            SELECT 
                u.id, u.email, u.full_name, u.role, u.is_active, u.status,
                u.last_login_at, u.created_at,
                COUNT(DISTINCT uca.company_id) as company_count
            FROM users u
            LEFT JOIN user_company_access uca ON u.id = uca.user_id
            GROUP BY u.id
            ORDER BY u.created_at DESC
        ");
        $users = $stmt->fetchAll();

        $response->getBody()->write(json_encode($users));
        return $response->withHeader('Content-Type', 'application/json');
    })->add(new \App\Middleware\AuthMiddleware($pdo, true)); // Admin only

    // Admin: Create user
    $app->post('/api/auth/users', function (Request $request, Response $response) use ($authService, $pdo) {
        $data = json_decode($request->getBody()->getContents(), true);
        $user = $request->getAttribute('user');

        try {
            $userId = $authService->createUser(
                $data['email'] ?? '',
                $data['password'] ?? '',
                $data['full_name'] ?? '',
                $data['role'] ?? 'company_user'
            );

            // Log audit
            $auditStmt = $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
                VALUES (?, 'user.create', 'user', ?, ?, ?, NOW())
            ");
            $auditStmt->execute([
                $user['id'],
                $userId,
                json_encode([
                    'email' => $data['email'] ?? '',
                    'full_name' => $data['full_name'] ?? '',
                    'role' => $data['role'] ?? 'company_user'
                ]),
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);

            $response->getBody()->write(json_encode([
                'success' => true,
                'user_id' => $userId
            ]));
            return $response->withStatus(201)->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'error' => 'user_creation_failed',
                'message' => $e->getMessage()
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    })->add(new \App\Middleware\AuthMiddleware($pdo, true));

    // Admin: Assign user to company
    $app->post('/api/auth/users/{userId}/companies/{companyId}', function (
        Request $request,
        Response $response,
        array $args
    ) use ($authService) {
        $data = json_decode($request->getBody()->getContents(), true);
        $user = $request->getAttribute('user');

        try {
            $authService->assignUserToCompany(
                (int)$args['userId'],
                (int)$args['companyId'],
                $data['can_view_sync'] ?? true,
                $data['can_run_sync'] ?? true,
                $data['can_edit_credentials'] ?? false,
                $data['can_manage_schedules'] ?? false,
                $user['id']
            );

            $response->getBody()->write(json_encode(['success' => true]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'error' => 'assignment_failed',
                'message' => $e->getMessage()
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    })->add(new \App\Middleware\AuthMiddleware($pdo, true));

    // Admin: Get user's companies
    $app->get('/api/auth/users/{userId}/companies', function (
        Request $request,
        Response $response,
        array $args
    ) use ($pdo) {
        $userId = (int)$args['userId'];
        
        $stmt = $pdo->prepare("
            SELECT 
                c.id as company_id,
                c.company_code,
                c.company_name,
                uca.can_view_sync,
                uca.can_run_sync,
                uca.can_edit_credentials,
                uca.can_manage_schedules,
                uca.assigned_at
            FROM user_company_access uca
            INNER JOIN companies c ON uca.company_id = c.id
            WHERE uca.user_id = ?
            ORDER BY c.company_name
        ");
        $stmt->execute([$userId]);
        $companies = $stmt->fetchAll();
        
        $response->getBody()->write(json_encode($companies));
        return $response->withHeader('Content-Type', 'application/json');
    })->add(new \App\Middleware\AuthMiddleware($pdo, true));

    // Admin: Update user status
    $app->patch('/api/auth/users/{userId}', function (
        Request $request,
        Response $response,
        array $args
    ) use ($pdo) {
        $userId = (int)$args['userId'];
        $data = json_decode($request->getBody()->getContents(), true);
        $user = $request->getAttribute('user');
        
        if (!isset($data['is_active'])) {
            $response->getBody()->write(json_encode(['error' => 'is_active field is required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $isActive = (int)$data['is_active'];
        
        try {
            $stmt = $pdo->prepare("UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$isActive, $userId]);
            
            // Log audit
            $auditStmt = $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
                VALUES (?, 'user.update_status', 'user', ?, ?, ?, NOW())
            ");
            $auditStmt->execute([
                $user['id'],
                $userId,
                json_encode(['is_active' => $isActive]),
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);
            
            $response->getBody()->write(json_encode(['success' => true]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    })->add(new \App\Middleware\AuthMiddleware($pdo, true));

    // Admin: Remove user from company
    $app->delete('/api/auth/users/{userId}/companies/{companyId}', function (
        Request $request,
        Response $response,
        array $args
    ) use ($authService) {
        $authService->removeUserFromCompany(
            (int)$args['userId'],
            (int)$args['companyId']
        );

        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    })->add(new \App\Middleware\AuthMiddleware($pdo, true));

    // ============================================================================
    // PUBLIC REGISTRATION & PASSWORD RECOVERY
    // ============================================================================

    // Register new user (public)
    $app->post('/api/auth/register', function (Request $request, Response $response) use ($pdo) {
        $data = json_decode($request->getBody()->getContents(), true);
        
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $fullName = trim($data['full_name'] ?? '');
        
        if (empty($email) || empty($password) || empty($fullName)) {
            $response->getBody()->write(json_encode([
                'error' => 'validation_error',
                'message' => 'All fields are required'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        // Check if email already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $response->getBody()->write(json_encode([
                'error' => 'email_exists',
                'message' => 'This email is already registered'
            ]));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        }
        
        // Create user with pending status
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            INSERT INTO users (email, password_hash, full_name, role, is_active, status, created_at, updated_at)
            VALUES (?, ?, ?, 'company_user', 0, 'pending', NOW(), NOW())
        ");
        $stmt->execute([$email, $passwordHash, $fullName]);
        $userId = $pdo->lastInsertId();
        
        // Log audit
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
            VALUES (?, 'user.register', 'user', ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $userId,
            $userId,
            json_encode(['email' => $email, 'status' => 'pending']),
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);
        
        // TODO: Send notification email to admin about new registration
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Registration successful. Your account is pending approval.',
            'user_id' => $userId
        ]));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    });

    // Password recovery request (public)
    $app->post('/api/auth/password-recovery', function (Request $request, Response $response) use ($pdo) {
        $data = json_decode($request->getBody()->getContents(), true);
        $email = trim($data['email'] ?? '');
        
        if (empty($email)) {
            $response->getBody()->write(json_encode([
                'error' => 'validation_error',
                'message' => 'Email is required'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        // Check if user exists
        $stmt = $pdo->prepare("SELECT id, email, full_name FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Generate temporary password
            $tempPassword = bin2hex(random_bytes(4)); // 8-char temp password
            $token = bin2hex(random_bytes(32));
            
            // Hash the temporary password
            $tempPasswordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
            
            // Store password reset request
            $stmt = $pdo->prepare("
                INSERT INTO password_reset_requests (user_id, token, expires_at, ip_address)
                VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), ?)
            ");
            $stmt->execute([$user['id'], $token, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);
            
            // Update user with temp password that expires
            $stmt = $pdo->prepare("
                UPDATE users 
                SET password_hash = ?, 
                    password_reset_token = ?,
                    password_reset_expires = DATE_ADD(NOW(), INTERVAL 1 HOUR)
                WHERE id = ?
            ");
            $stmt->execute([$tempPasswordHash, $token, $user['id']]);
            
            // Log audit
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
                VALUES (?, 'user.password_recovery', 'user', ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $user['id'],
                $user['id'],
                json_encode(['email' => $email]),
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);
            
            // TODO: Send email with temporary password
            // For now, log it (in production, send via email)
            error_log("Temporary password for {$email}: {$tempPassword}");
        }
        
        // Always return success to avoid email enumeration
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'If an account exists, a temporary password has been sent'
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // ============================================================================
    // USER PROFILE MANAGEMENT
    // ============================================================================

    // Update user profile
    $app->patch('/api/auth/profile', function (Request $request, Response $response) use ($pdo) {
        $user = $request->getAttribute('user');
        $data = json_decode($request->getBody()->getContents(), true);
        
        $fullName = trim($data['full_name'] ?? '');
        
        if (empty($fullName)) {
            $response->getBody()->write(json_encode([
                'error' => 'validation_error',
                'message' => 'Full name is required'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $stmt = $pdo->prepare("UPDATE users SET full_name = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$fullName, $user['id']]);
        
        // Log audit
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
            VALUES (?, 'user.update_profile', 'user', ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $user['id'],
            $user['id'],
            json_encode(['full_name' => $fullName]),
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);
        
        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    })->add(new \App\Middleware\AuthMiddleware($pdo));

    // Change password
    $app->post('/api/auth/change-password', function (Request $request, Response $response) use ($pdo) {
        $user = $request->getAttribute('user');
        $data = json_decode($request->getBody()->getContents(), true);
        
        $currentPassword = $data['current_password'] ?? '';
        $newPassword = $data['new_password'] ?? '';
        
        if (empty($currentPassword) || empty($newPassword)) {
            $response->getBody()->write(json_encode([
                'error' => 'validation_error',
                'message' => 'Both current and new password are required'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        // Get current password hash
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $currentUser = $stmt->fetch();
        
        // Verify current password
        if (!password_verify($currentPassword, $currentUser['password_hash'])) {
            $response->getBody()->write(json_encode([
                'error' => 'invalid_password',
                'message' => 'Current password is incorrect'
            ]));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        // Update password and clear any reset tokens
        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            UPDATE users 
            SET password_hash = ?, 
                password_reset_token = NULL,
                password_reset_expires = NULL,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$newPasswordHash, $user['id']]);
        
        // Log audit
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
            VALUES (?, 'user.change_password', 'user', ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $user['id'],
            $user['id'],
            json_encode(['changed_by' => 'self']),
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);
        
        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    })->add(new \App\Middleware\AuthMiddleware($pdo));

    // Request email change
    $app->post('/api/auth/request-email-change', function (Request $request, Response $response) use ($pdo) {
        $user = $request->getAttribute('user');
        $data = json_decode($request->getBody()->getContents(), true);
        
        $newEmail = trim($data['new_email'] ?? '');
        
        if (empty($newEmail)) {
            $response->getBody()->write(json_encode([
                'error' => 'validation_error',
                'message' => 'New email is required'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        // Check if email already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$newEmail, $user['id']]);
        if ($stmt->fetch()) {
            $response->getBody()->write(json_encode([
                'error' => 'email_exists',
                'message' => 'This email is already in use'
            ]));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        }
        
        // Generate 4-digit code
        $code = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        
        // Store verification code
        $stmt = $pdo->prepare("
            INSERT INTO email_verification_codes (user_id, code, new_email, expires_at)
            VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))
        ");
        $stmt->execute([$user['id'], $code, $newEmail]);
        
        // TODO: Send verification code via email
        // For now, log it (in production, send via email)
        error_log("Email verification code for {$newEmail}: {$code}");
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Verification code sent to your new email'
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    })->add(new \App\Middleware\AuthMiddleware($pdo));

    // Verify email change
    $app->post('/api/auth/verify-email-change', function (Request $request, Response $response) use ($pdo) {
        $user = $request->getAttribute('user');
        $data = json_decode($request->getBody()->getContents(), true);
        
        $code = $data['code'] ?? '';
        
        if (empty($code)) {
            $response->getBody()->write(json_encode([
                'error' => 'validation_error',
                'message' => 'Verification code is required'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        // Get verification code
        $stmt = $pdo->prepare("
            SELECT id, new_email 
            FROM email_verification_codes 
            WHERE user_id = ? AND code = ? AND expires_at > NOW() AND verified = 0
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$user['id'], $code]);
        $verification = $stmt->fetch();
        
        if (!$verification) {
            $response->getBody()->write(json_encode([
                'error' => 'invalid_code',
                'message' => 'Invalid or expired verification code'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        // Update email
        $stmt = $pdo->prepare("UPDATE users SET email = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$verification['new_email'], $user['id']]);
        
        // Mark verification as used
        $stmt = $pdo->prepare("UPDATE email_verification_codes SET verified = 1, verified_at = NOW() WHERE id = ?");
        $stmt->execute([$verification['id']]);
        
        // Log audit
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
            VALUES (?, 'user.change_email', 'user', ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $user['id'],
            $user['id'],
            json_encode(['new_email' => $verification['new_email']]),
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);
        
        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    })->add(new \App\Middleware\AuthMiddleware($pdo));

    // ============================================================================
    // ADMIN USER MANAGEMENT
    // ============================================================================

    // Admin: Approve user registration
    $app->post('/api/admin/users/{userId}/approve', function (Request $request, Response $response, array $args) use ($pdo) {
        $admin = $request->getAttribute('user');
        $userId = (int)$args['userId'];
        
        // Get user info
        $stmt = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $response->getBody()->write(json_encode(['error' => 'User not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        // Update user status
        $stmt = $pdo->prepare("UPDATE users SET status = 'active', is_active = 1, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$userId]);
        
        // Log audit
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
            VALUES (?, 'user.approve', 'user', ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $admin['id'],
            $userId,
            json_encode(['approved_by' => $admin['email'], 'user_email' => $user['email']]),
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);
        
        // TODO: Send approval email to user
        
        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    })->add(new \App\Middleware\AuthMiddleware($pdo, true));

    // Admin: Reset user password
    $app->post('/api/admin/users/{userId}/reset-password', function (Request $request, Response $response, array $args) use ($pdo) {
        $admin = $request->getAttribute('user');
        $userId = (int)$args['userId'];
        
        // Get user info
        $stmt = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $response->getBody()->write(json_encode(['error' => 'User not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        // Generate temporary password
        $tempPassword = bin2hex(random_bytes(4));
        $tempPasswordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
        
        // Update user password
        $stmt = $pdo->prepare("
            UPDATE users 
            SET password_hash = ?,
                password_reset_token = NULL,
                password_reset_expires = DATE_ADD(NOW(), INTERVAL 24 HOUR),
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$tempPasswordHash, $userId]);
        
        // Log audit
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
            VALUES (?, 'user.password_reset_by_admin', 'user', ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $admin['id'],
            $userId,
            json_encode(['reset_by' => $admin['email'], 'user_email' => $user['email']]),
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);
        
        // TODO: Send email with temporary password
        error_log("Admin reset password for {$user['email']}: {$tempPassword}");
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Password reset successfully. Temporary password has been sent to user.'
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    })->add(new \App\Middleware\AuthMiddleware($pdo, true));

    // Admin: Change user role
    $app->patch('/api/admin/users/{userId}/role', function (Request $request, Response $response, array $args) use ($pdo) {
        $admin = $request->getAttribute('user');
        $userId = (int)$args['userId'];
        $data = json_decode($request->getBody()->getContents(), true);
        
        $newRole = $data['role'] ?? '';
        
        if (!in_array($newRole, ['admin', 'company_user'])) {
            $response->getBody()->write(json_encode(['error' => 'Invalid role']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        // Get user info
        $stmt = $pdo->prepare("SELECT email, role FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $response->getBody()->write(json_encode(['error' => 'User not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        // Update role
        $stmt = $pdo->prepare("UPDATE users SET role = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$newRole, $userId]);
        
        // Log audit
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
            VALUES (?, 'user.change_role', 'user', ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $admin['id'],
            $userId,
            json_encode([
                'changed_by' => $admin['email'],
                'user_email' => $user['email'],
                'old_role' => $user['role'],
                'new_role' => $newRole
            ]),
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);
        
        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    })->add(new \App\Middleware\AuthMiddleware($pdo, true));
};

return $authRoutes;

