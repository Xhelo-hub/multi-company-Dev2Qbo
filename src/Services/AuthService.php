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
            SELECT id, email, password_hash, full_name, role, is_active
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

        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            throw new RuntimeException('Invalid email or password');
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

        // Get user's companies
        $companies = $this->getUserCompanies($user['id']);

        return [
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
    }

    /**
     * Logout user (invalidate session)
     */
    public function logout(string $sessionToken): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM user_sessions WHERE session_token = ?");
        $stmt->execute([$sessionToken]);
        return $stmt->rowCount() > 0;
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

        return (int)$this->pdo->lastInsertId();
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
