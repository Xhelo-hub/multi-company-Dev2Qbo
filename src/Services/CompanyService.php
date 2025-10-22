<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Company Service - Manages companies and their credentials
 */
class CompanyService
{
    public function __construct(private PDO $db, private string $encryptionKey) {}

    /**
     * Get all active companies
     */
    public function getAllActive(): array
    {
        $stmt = $this->db->query("
            SELECT id, company_code, company_name, is_active, created_at, updated_at 
            FROM companies 
            WHERE is_active = 1 
            ORDER BY company_name
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get company by ID
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, company_code, company_name, is_active, created_at, updated_at 
            FROM companies 
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Get company by code
     */
    public function getByCode(string $code): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, company_code, company_name, is_active, created_at, updated_at 
            FROM companies 
            WHERE company_code = ?
        ");
        $stmt->execute([$code]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Get company with all credentials (DevPos + QBO)
     */
    public function getCompanyWithCredentials(int $companyId): array
    {
        $company = $this->getById($companyId);
        if (!$company) {
            throw new \Exception("Company not found: {$companyId}");
        }

        // Get DevPos credentials
        $stmt = $this->db->prepare("
            SELECT tenant, username, password_encrypted 
            FROM company_credentials_devpos 
            WHERE company_id = ?
        ");
        $stmt->execute([$companyId]);
        $devpos = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get QBO credentials
        $stmt = $this->db->prepare("
            SELECT realm_id, access_token, refresh_token, token_expires_at 
            FROM company_credentials_qbo 
            WHERE company_id = ?
        ");
        $stmt->execute([$companyId]);
        $qbo = $stmt->fetch(PDO::FETCH_ASSOC);

        $company['devpos'] = $devpos ?: null;
        $company['qbo'] = $qbo ?: null;

        return $company;
    }

    /**
     * Create new company
     */
    public function create(string $code, string $name): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO companies (company_code, company_name, is_active) 
            VALUES (?, ?, 1)
        ");
        $stmt->execute([$code, $name]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Update company
     */
    public function update(int $id, string $name, bool $isActive): bool
    {
        $stmt = $this->db->prepare("
            UPDATE companies 
            SET company_name = ?, is_active = ? 
            WHERE id = ?
        ");
        return $stmt->execute([$name, $isActive ? 1 : 0, $id]);
    }

    /**
     * Delete company (cascades to all related data)
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM companies WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Set DevPos credentials for company
     */
    public function setDevPosCredentials(int $companyId, string $tenant, string $username, string $password): bool
    {
        // Encrypt password using AES
        $encrypted = openssl_encrypt($password, 'AES-256-CBC', $this->encryptionKey, 0, substr(md5($this->encryptionKey), 0, 16));
        
        // Insert or update
        $stmt = $this->db->prepare("
            INSERT INTO company_credentials_devpos (company_id, tenant, username, password_encrypted) 
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE tenant = VALUES(tenant), username = VALUES(username), password_encrypted = VALUES(password_encrypted)
        ");
        return $stmt->execute([$companyId, $tenant, $username, $encrypted]);
    }

    /**
     * Set QBO credentials for company
     */
    public function setQboCredentials(int $companyId, string $realmId, ?string $accessToken = null, ?string $refreshToken = null, ?string $expiresAt = null): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO company_credentials_qbo (company_id, realm_id, access_token, refresh_token, token_expires_at) 
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE realm_id = VALUES(realm_id), access_token = VALUES(access_token), refresh_token = VALUES(refresh_token), token_expires_at = VALUES(token_expires_at)
        ");
        return $stmt->execute([$companyId, $realmId, $accessToken, $refreshToken, $expiresAt]);
    }

    /**
     * Get decrypted DevPos password
     */
    public function getDevPosPassword(int $companyId): ?string
    {
        $stmt = $this->db->prepare("
            SELECT password_encrypted 
            FROM company_credentials_devpos 
            WHERE company_id = ?
        ");
        $stmt->execute([$companyId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result || !$result['password_encrypted']) {
            return null;
        }

        return openssl_decrypt($result['password_encrypted'], 'AES-256-CBC', $this->encryptionKey, 0, substr(md5($this->encryptionKey), 0, 16));
    }

    /**
     * Check if company has DevPos credentials
     */
    public function hasDevPosCredentials(int $companyId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM company_credentials_devpos WHERE company_id = ?
        ");
        $stmt->execute([$companyId]);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Check if company has QBO credentials
     */
    public function hasQboCredentials(int $companyId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM company_credentials_qbo WHERE company_id = ?
        ");
        $stmt->execute([$companyId]);
        return $stmt->fetchColumn() > 0;
    }
}
