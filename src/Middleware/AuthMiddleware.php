<?php

declare(strict_types=1);

namespace App\Middleware;

use PDO;
use Slim\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Authentication Middleware
 * Validates user session and loads user/company context
 */
class AuthMiddleware
{
    private PDO $pdo;
    private bool $requireAdmin;

    public function __construct(PDO $pdo, bool $requireAdmin = false)
    {
        $this->pdo = $pdo;
        $this->requireAdmin = $requireAdmin;
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        // Check for session token in cookie or header
        $cookies = $request->getCookieParams();
        $token = $cookies['session_token'] ?? null;

        if (!$token) {
            // Check Authorization header
            $authHeader = $request->getHeaderLine('Authorization');
            if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                $token = $matches[1];
            }
        }

        if (!$token) {
            return $this->unauthorized('No session token provided');
        }

        // Validate session
        $stmt = $this->pdo->prepare("
            SELECT s.user_id, s.expires_at, u.email, u.full_name, u.role, u.is_active
            FROM user_sessions s
            JOIN users u ON s.user_id = u.id
            WHERE s.session_token = ? AND s.expires_at > NOW()
        ");
        $stmt->execute([$token]);
        $session = $stmt->fetch();

        if (!$session) {
            return $this->unauthorized('Invalid or expired session');
        }

        if (!$session['is_active']) {
            return $this->unauthorized('User account is inactive');
        }

        // Check admin requirement
        if ($this->requireAdmin && $session['role'] !== 'admin') {
            return $this->forbidden('Admin access required');
        }

        // Load user's company access
        $stmt = $this->pdo->prepare("
            SELECT 
                c.id as company_id,
                c.company_code,
                c.company_name,
                uca.can_view_sync,
                uca.can_run_sync,
                uca.can_edit_credentials,
                uca.can_manage_schedules
            FROM user_company_access uca
            JOIN companies c ON uca.company_id = c.id
            WHERE uca.user_id = ? AND c.is_active = 1
        ");
        $stmt->execute([$session['user_id']]);
        $companies = $stmt->fetchAll();

        // Attach user context to request
        $user = [
            'id' => $session['user_id'],
            'email' => $session['email'],
            'full_name' => $session['full_name'],
            'role' => $session['role'],
            'is_admin' => $session['role'] === 'admin',
            'companies' => $companies,
            'session_token' => $token
        ];

        $request = $request->withAttribute('user', $user);

        return $handler->handle($request);
    }

    private function unauthorized(string $message): Response
    {
        $response = new Response();
        $response->getBody()->write(json_encode([
            'error' => 'unauthorized',
            'message' => $message
        ]));
        return $response
            ->withStatus(401)
            ->withHeader('Content-Type', 'application/json');
    }

    private function forbidden(string $message): Response
    {
        $response = new Response();
        $response->getBody()->write(json_encode([
            'error' => 'forbidden',
            'message' => $message
        ]));
        return $response
            ->withStatus(403)
            ->withHeader('Content-Type', 'application/json');
    }
}
