<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Multi-Company Sync Service - Manages sync jobs across multiple companies
 */
class MultiCompanySyncService
{
    public function __construct(
        private PDO $db,
        private CompanyService $companyService
    ) {}

    /**
     * Create a new sync job
     */
    public function createJob(
        int $companyId,
        string $jobType,
        string $fromDate,
        string $toDate,
        string $triggerSource = 'manual'
    ): int {
        $stmt = $this->db->prepare("
            INSERT INTO sync_jobs (company_id, job_type, status, trigger_source, from_date, to_date, created_at) 
            VALUES (?, ?, 'pending', ?, ?, ?, NOW())
        ");
        $stmt->execute([$companyId, $jobType, $triggerSource, $fromDate, $toDate]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Execute a sync job
     */
    public function executeJob(int $jobId): array
    {
        // Get job details
        $stmt = $this->db->prepare("
            SELECT * FROM sync_jobs WHERE id = ?
        ");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            throw new \Exception("Job not found: {$jobId}");
        }

        // Update status to running
        $stmt = $this->db->prepare("
            UPDATE sync_jobs 
            SET status = 'running', started_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$jobId]);

        try {
            // Get company with credentials
            $company = $this->companyService->getCompanyWithCredentials((int)$job['company_id']);

            // Set company-specific environment
            $this->setCompanyEnvironment($company);

            // Execute appropriate sync
            $result = match ($job['job_type']) {
                'sales' => $this->executeSalesSync($company, $job['from_date'], $job['to_date']),
                'purchases' => $this->executePurchasesSync($company, $job['from_date'], $job['to_date']),
                'bills' => $this->executeBillsSync($company, $job['from_date'], $job['to_date']),
                'full' => $this->executeFullSync($company, $job['from_date'], $job['to_date']),
                default => throw new \Exception("Unknown job type: {$job['job_type']}")
            };

            // Update job as completed
            $stmt = $this->db->prepare("
                UPDATE sync_jobs 
                SET status = 'completed', completed_at = NOW(), results_json = ? 
                WHERE id = ?
            ");
            $stmt->execute([json_encode($result), $jobId]);

            return ['success' => true, 'job_id' => $jobId, 'results' => $result];

        } catch (\Throwable $e) {
            // Update job as failed
            $stmt = $this->db->prepare("
                UPDATE sync_jobs 
                SET status = 'failed', completed_at = NOW(), error_message = ? 
                WHERE id = ?
            ");
            $stmt->execute([$e->getMessage(), $jobId]);

            return ['success' => false, 'job_id' => $jobId, 'error' => $e->getMessage()];
        }
    }

    /**
     * Execute sales sync for a company
     */
    private function executeSalesSync(array $company, string $fromDate, string $toDate): array
    {
        // Create sync dependencies with company-scoped services
        // This would normally use dependency injection from container
        // For now, return mock result - implement full integration in next phase
        return [
            'type' => 'sales',
            'company_id' => $company['id'],
            'company_code' => $company['company_code'],
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'invoices_created' => 0,
            'receipts_created' => 0,
            'skipped' => 0,
            'message' => 'Sales sync executed (placeholder - integrate SalesSync class)'
        ];
    }

    /**
     * Execute purchases sync for a company
     */
    private function executePurchasesSync(array $company, string $fromDate, string $toDate): array
    {
        return [
            'type' => 'purchases',
            'company_id' => $company['id'],
            'company_code' => $company['company_code'],
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'purchases_created' => 0,
            'skipped' => 0,
            'message' => 'Purchases sync executed (placeholder - implement purchases sync)'
        ];
    }

    /**
     * Execute bills sync for a company
     */
    private function executeBillsSync(array $company, string $fromDate, string $toDate): array
    {
        return [
            'type' => 'bills',
            'company_id' => $company['id'],
            'company_code' => $company['company_code'],
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'bills_created' => 0,
            'skipped' => 0,
            'message' => 'Bills sync executed (placeholder - integrate BillsSync class)'
        ];
    }

    /**
     * Execute full sync (sales + purchases + bills)
     */
    private function executeFullSync(array $company, string $fromDate, string $toDate): array
    {
        $sales = $this->executeSalesSync($company, $fromDate, $toDate);
        $purchases = $this->executePurchasesSync($company, $fromDate, $toDate);
        $bills = $this->executeBillsSync($company, $fromDate, $toDate);

        return [
            'type' => 'full',
            'company_id' => $company['id'],
            'company_code' => $company['company_code'],
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'sales' => $sales,
            'purchases' => $purchases,
            'bills' => $bills
        ];
    }

    /**
     * Set company-specific environment variables
     */
    private function setCompanyEnvironment(array $company): void
    {
        // Set company ID for all scoped operations
        $_ENV['CURRENT_COMPANY_ID'] = $company['id'];

        // Set DevPos credentials
        if ($company['devpos']) {
            $_ENV['DEVPOS_TENANT'] = $company['devpos']['tenant'];
            $_ENV['DEVPOS_USERNAME'] = $company['devpos']['username'];
            $_ENV['DEVPOS_PASSWORD'] = $this->companyService->getDevPosPassword((int)$company['id']);
        }

        // Set QBO credentials
        if ($company['qbo']) {
            $_ENV['QBO_REALM_ID'] = $company['qbo']['realm_id'];
            $_ENV['QBO_ACCESS_TOKEN'] = $company['qbo']['access_token'] ?? '';
            $_ENV['QBO_REFRESH_TOKEN'] = $company['qbo']['refresh_token'] ?? '';
        }
    }

    /**
     * Get recent jobs for a company
     */
    public function getRecentJobs(int $companyId, int $limit = 20): array
    {
        $stmt = $this->db->prepare("
            SELECT id, job_type, status, trigger_source, from_date, to_date, 
                   started_at, completed_at, results_json, error_message, created_at
            FROM sync_jobs 
            WHERE company_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$companyId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get company sync statistics
     */
    public function getCompanyStats(int $companyId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_jobs,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_jobs,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_jobs,
                SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running_jobs,
                MAX(completed_at) as last_sync_at
            FROM sync_jobs 
            WHERE company_id = ?
        ");
        $stmt->execute([$companyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get all pending jobs
     */
    public function getPendingJobs(): array
    {
        $stmt = $this->db->query("
            SELECT * FROM sync_jobs 
            WHERE status = 'pending' 
            ORDER BY created_at ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
