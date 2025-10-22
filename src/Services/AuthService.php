<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * Authentication Service
 * Handles user login, logout, and session management
 */
class AuthService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Authenticate user and create session
     */
    public function login(string $email, string $password, string $ipAddress = null, string $userAgent = null): array
    {
        // Find user
        $stmt = $this->pdo->prepare("
            SELECT id, email, password_hash, full_name, role, is_active, status, 
                   password_reset_token, password_reset_expires
            FROM users
            WHERE email = ?
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            throw new RuntimeException('Invalid email or password');
        }

        if (!$user['is_active']) {
            throw new RuntimeException('Account is inactive');
        }

        // Check if account is pending approval
        if ($user['status'] === 'pending') {
            throw new RuntimeException('Your account is pending admin approval. Please wait for approval before logging in.');
        }

        // Check if account is suspended
        if ($user['status'] === 'suspended') {
            throw new RuntimeException('Your account has been suspended. Please contact an administrator.');
        }

        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            throw new RuntimeException('Invalid email or password');
        }

        // Check if user is using a temporary password
        $requiresPasswordChange = false;
        if ($user['password_reset_token'] && $user['password_reset_expires']) {
            $expiresAt = strtotime($user['password_reset_expires']);
            if ($expiresAt > time()) {
                $requiresPasswordChange = true;
            }
        }

        // Create session token
        $sessionToken = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));

        $stmt = $this->pdo->prepare("
            INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, expires_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user['id'],
            $sessionToken,
            $ipAddress,
            $userAgent,
            $expiresAt
        ]);

        // Update last login
        $stmt = $this->pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);

        // Log audit
        $stmt = $this->pdo->prepare("
            INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent, created_at)
            VALUES (?, 'user.login', 'user', ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $user['id'],
            $user['id'],
            json_encode(['email' => $email, 'requires_password_change' => $requiresPasswordChange]),
            $ipAddress,
            $userAgent
        ]);

        // Get user's companies
        $companies = $this->getUserCompanies($user['id']);

        $result = [
            'session_token' => $sessionToken,
            'expires_at' => $expiresAt,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'full_name' => $user['full_name'],
                'role' => $user['role'],
                'is_admin' => $user['role'] === 'admin',
                'companies' => $companies
            ]
        ];

        // Add flag if password change is required
        if ($requiresPasswordChange) {
            $result['requires_password_change'] = true;
        }

        return $result;
    }

    /**
     * Logout user (invalidate session)
     */
    public function logout(string $sessionToken): bool
    {
        // Get user_id before deleting session
        $stmt = $this->pdo->prepare("SELECT user_id FROM user_sessions WHERE session_token = ?");
        $stmt->execute([$sessionToken]);
        $session = $stmt->fetch();
        
        $stmt = $this->pdo->prepare("DELETE FROM user_sessions WHERE session_token = ?");
        $stmt->execute([$sessionToken]);
        $deleted = $stmt->rowCount() > 0;
        
        // Log audit if session was found
        if ($deleted && $session) {
            $stmt = $this->pdo->prepare("
                INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
                VALUES (?, 'user.logout', 'user', ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $session['user_id'],
                $session['user_id'],
                json_encode(['session_token' => substr($sessionToken, 0, 10) . '...']),
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);
        }
        
        return $deleted;
    }

    /**
     * Get user's accessible companies
     */
    public function getUserCompanies(int $userId): array
    {
        // Check if admin
        $stmt = $this->pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if ($user && $user['role'] === 'admin') {
            // Admin can see all companies
            $stmt = $this->pdo->query("
                SELECT id as company_id, company_code, company_name, is_active,
                       1 as can_view_sync, 1 as can_run_sync, 
                       1 as can_edit_credentials, 1 as can_manage_schedules
                FROM companies
                WHERE is_active = 1
                ORDER BY company_code
            ");
            return $stmt->fetchAll();
        }

        // Regular user - only assigned companies
        $stmt = $this->pdo->prepare("
            SELECT 
                c.id as company_id,
                c.company_code,
                c.company_name,
                c.is_active,
                uca.can_view_sync,
                uca.can_run_sync,
                uca.can_edit_credentials,
                uca.can_manage_schedules
            FROM user_company_access uca
            JOIN companies c ON uca.company_id = c.id
            WHERE uca.user_id = ? AND c.is_active = 1
            ORDER BY c.company_code
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /**
     * Check if user has access to a specific company
     */
    public function canAccessCompany(int $userId, int $companyId): bool
    {
        $companies = $this->getUserCompanies($userId);
        foreach ($companies as $company) {
            if ($company['company_id'] == $companyId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if user has specific permission for a company
     */
    public function hasPermission(int $userId, int $companyId, string $permission): bool
    {
        $companies = $this->getUserCompanies($userId);
        foreach ($companies as $company) {
            if ($company['company_id'] == $companyId) {
                return (bool)($company[$permission] ?? false);
            }
        }
        return false;
    }

    /**
     * Create new user (admin only)
     */
    public function createUser(string $email, string $password, string $fullName, string $role = 'company_user'): int
    {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $this->pdo->prepare("
            INSERT INTO users (email, password_hash, full_name, role, is_active)
            VALUES (?, ?, ?, ?, 1)
        ");
        $stmt->execute([$email, $passwordHash, $fullName, $role]);

        $userId = (int)$this->pdo->lastInsertId();
        
        // Log audit - we don't have the admin user ID here, so skip or pass it from controller
        // The controller should log this action
        
        return $userId;
    }

    /**
     * Assign user to company with permissions
     */
    public function assignUserToCompany(
        int $userId,
        int $companyId,
        bool $canViewSync = true,
        bool $canRunSync = true,
        bool $canEditCredentials = false,
        bool $canManageSchedules = false,
        int $assignedBy = null
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO user_company_access 
            (user_id, company_id, can_view_sync, can_run_sync, can_edit_credentials, can_manage_schedules, assigned_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                can_view_sync = VALUES(can_view_sync),
                can_run_sync = VALUES(can_run_sync),
                can_edit_credentials = VALUES(can_edit_credentials),
                can_manage_schedules = VALUES(can_manage_schedules)
        ");
        $stmt->execute([
            $userId,
            $companyId,
            $canViewSync ? 1 : 0,
            $canRunSync ? 1 : 0,
            $canEditCredentials ? 1 : 0,
            $canManageSchedules ? 1 : 0,
            $assignedBy
        ]);
        
        // Log audit
        if ($assignedBy) {
            $stmt = $this->pdo->prepare("
                INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
                VALUES (?, 'user.assign_company', 'user_company_access', ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $assignedBy,
                $userId,
                json_encode([
                    'user_id' => $userId,
                    'company_id' => $companyId,
                    'permissions' => [
                        'can_view_sync' => $canViewSync,
                        'can_run_sync' => $canRunSync,
                        'can_edit_credentials' => $canEditCredentials,
                        'can_manage_schedules' => $canManageSchedules
                    ]
                ]),
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);
        }
    }

    /**
     * Remove user access to company
     */
    public function removeUserFromCompany(int $userId, int $companyId): bool
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM user_company_access 
            WHERE user_id = ? AND company_id = ?
        ");
        $stmt->execute([$userId, $companyId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Clean up expired sessions
     */
    public function cleanExpiredSessions(): int
    {
        $stmt = $this->pdo->query("DELETE FROM user_sessions WHERE expires_at < NOW()");
        return $stmt->rowCount();
    }
}
