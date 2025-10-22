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
            $cookieValue = sprintf(
                'session_token=%s; Path=/multi-company-Dev2Qbo/public; Max-Age=%d; HttpOnly; SameSite=Lax',
                $result['session_token'],
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
        $cookieValue = 'session_token=; Path=/multi-company-Dev2Qbo/public; Max-Age=0; HttpOnly; SameSite=Lax';

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
                u.id, u.email, u.full_name, u.role, u.is_active,
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
    $app->post('/api/auth/users', function (Request $request, Response $response) use ($authService) {
        $data = json_decode($request->getBody()->getContents(), true);

        try {
            $userId = $authService->createUser(
                $data['email'] ?? '',
                $data['password'] ?? '',
                $data['full_name'] ?? '',
                $data['role'] ?? 'company_user'
            );

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
};

return $authRoutes;
