<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Exception;
use GuzzleHttp\Client;

class SyncExecutor
{
    private PDO $pdo;
    
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    
    /**
     * Process a pending sync job
     */
    public function executeJob(int $jobId): array
    {
        // Get job details
        $stmt = $this->pdo->prepare("
            SELECT sj.*, c.company_code, c.company_name
            FROM sync_jobs sj
            JOIN companies c ON sj.company_id = c.id
            WHERE sj.id = ? AND sj.status = 'pending'
        ");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();
        
        if (!$job) {
            throw new Exception('Job not found or already processed');
        }
        
        // Update status to running
        $this->updateJobStatus($jobId, 'running');
        
        try {
            $results = [];
            
            // Execute based on job type
            switch ($job['job_type']) {
                case 'sales':
                    $results = $this->syncSales($job);
                    break;
                    
                case 'purchases':
                    $results = $this->syncPurchases($job);
                    break;
                    
                case 'bills':
                    $results = $this->syncBills($job);
                    break;
                    
                case 'full':
                    $results['sales'] = $this->syncSales($job);
                    $results['purchases'] = $this->syncPurchases($job);
                    $results['bills'] = $this->syncBills($job);
                    break;
                    
                default:
                    throw new Exception('Invalid job type: ' . $job['job_type']);
            }
            
            // Mark as completed
            $this->completeJob($jobId, $results);
            
            return [
                'success' => true,
                'job_id' => $jobId,
                'results' => $results
            ];
            
        } catch (Exception $e) {
            // Mark as failed
            $this->failJob($jobId, $e->getMessage());
            
            throw $e;
        }
    }
    
    /**
     * Sync sales invoices from DevPos to QuickBooks
     */
    private function syncSales(array $job): array
    {
        $companyId = (int)$job['company_id'];
        
        // Get DevPos credentials
        $devposCreds = $this->getDevPosCredentials($companyId);
        if (!$devposCreds) {
            throw new Exception('DevPos credentials not configured');
        }
        
        // Get QuickBooks credentials
        $qboCreds = $this->getQBOCredentials($companyId);
        if (!$qboCreds) {
            throw new Exception('QuickBooks not connected');
        }
        
        // Get DevPos access token
        $devposToken = $this->getDevPosToken($devposCreds);
        
        // Fetch sales invoices from DevPos
        $invoices = $this->fetchDevPosSalesInvoices(
            $devposToken,
            $devposCreds['tenant'],
            $job['from_date'],
            $job['to_date']
        );
        
        $synced = 0;
        $errors = [];
        
        foreach ($invoices as $invoice) {
            try {
                // Sync to QuickBooks
                $this->syncInvoiceToQBO($invoice, $qboCreds, $companyId);
                $synced++;
            } catch (Exception $e) {
                $errors[] = [
                    'invoice' => $invoice['eic'] ?? $invoice['documentNumber'] ?? 'unknown',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return [
            'total' => count($invoices),
            'synced' => $synced,
            'errors' => count($errors),
            'error_details' => $errors
        ];
    }
    
    /**
     * Sync purchase invoices
     */
    private function syncPurchases(array $job): array
    {
        $companyId = (int)$job['company_id'];
        
        $devposCreds = $this->getDevPosCredentials($companyId);
        if (!$devposCreds) {
            throw new Exception('DevPos credentials not configured');
        }
        
        $qboCreds = $this->getQBOCredentials($companyId);
        if (!$qboCreds) {
            throw new Exception('QuickBooks not connected');
        }
        
        $devposToken = $this->getDevPosToken($devposCreds);
        
        $invoices = $this->fetchDevPosPurchaseInvoices(
            $devposToken,
            $devposCreds['tenant'],
            $job['from_date'],
            $job['to_date']
        );
        
        $synced = 0;
        $errors = [];
        
        foreach ($invoices as $invoice) {
            try {
                $this->syncPurchaseToQBO($invoice, $qboCreds, $companyId);
                $synced++;
            } catch (Exception $e) {
                $errors[] = [
                    'invoice' => $invoice['eic'] ?? 'unknown',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return [
            'total' => count($invoices),
            'synced' => $synced,
            'errors' => count($errors),
            'error_details' => $errors
        ];
    }
    
    /**
     * Sync bills (placeholder)
     */
    private function syncBills(array $job): array
    {
        // Bills sync logic would go here
        return [
            'total' => 0,
            'synced' => 0,
            'errors' => 0,
            'message' => 'Bills sync not yet implemented'
        ];
    }
    
    /**
     * Get DevPos credentials for company
     */
    private function getDevPosCredentials(int $companyId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT tenant, username, password_encrypted
            FROM company_credentials_devpos
            WHERE company_id = ?
        ");
        $stmt->execute([$companyId]);
        $creds = $stmt->fetch();
        
        if (!$creds) {
            return null;
        }
        
        // Decrypt password
        $password = openssl_decrypt(
            $creds['password_encrypted'],
            'AES-256-CBC',
            $_ENV['ENCRYPTION_KEY'] ?? 'default-key',
            0,
            substr(hash('sha256', $_ENV['ENCRYPTION_KEY'] ?? 'default-key'), 0, 16)
        );
        
        return [
            'tenant' => $creds['tenant'],
            'username' => $creds['username'],
            'password' => $password
        ];
    }
    
    /**
     * Get QuickBooks credentials
     */
    private function getQBOCredentials(int $companyId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                c.realm_id,
                t.access_token,
                t.refresh_token,
                t.expires_at
            FROM company_credentials_qbo c
            LEFT JOIN oauth_tokens_qbo t ON c.company_id = t.company_id
            WHERE c.company_id = ?
        ");
        $stmt->execute([$companyId]);
        $creds = $stmt->fetch();
        
        if (!$creds || !$creds['realm_id']) {
            return null;
        }
        
        // Check if token needs refresh
        if (strtotime($creds['expires_at']) < time() + 300) {
            // Token expires in less than 5 minutes, refresh it
            $this->refreshQBOToken($companyId, $creds['refresh_token']);
            
            // Re-fetch credentials
            $stmt->execute([$companyId]);
            $creds = $stmt->fetch();
        }
        
        return $creds;
    }
    
    /**
     * Get DevPos access token
     */
    private function getDevPosToken(array $credentials): string
    {
        $client = new Client(['http_errors' => false]);
        $tokenUrl = $_ENV['DEVPOS_TOKEN_URL'] ?? 'https://online.devpos.al/connect/token';
        
        $response = $client->post($tokenUrl, [
            'form_params' => [
                'grant_type' => 'password',
                'username' => $credentials['username'],
                'password' => $credentials['password'],
            ],
            'headers' => [
                'Authorization' => 'Basic ' . ($_ENV['DEVPOS_AUTH_BASIC'] ?? 'Zmlza2FsaXppbWlfc3BhOg=='),
                'tenant' => $credentials['tenant'],
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json'
            ]
        ]);
        
        if ($response->getStatusCode() !== 200) {
            throw new Exception('DevPos authentication failed');
        }
        
        $data = json_decode($response->getBody()->getContents(), true);
        
        if (!isset($data['access_token'])) {
            throw new Exception('No access token received from DevPos');
        }
        
        return $data['access_token'];
    }
    
    /**
     * Fetch sales invoices from DevPos
     */
    private function fetchDevPosSalesInvoices(string $token, string $tenant, string $fromDate, string $toDate): array
    {
        $client = new Client();
        $apiBase = $_ENV['DEVPOS_API_BASE'] ?? 'https://online.devpos.al/api/v3';
        
        $response = $client->get($apiBase . '/EInvoice/GetSalesInvoice', [
            'query' => [
                'fromDate' => $fromDate,
                'toDate' => $toDate
            ],
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'tenant' => $tenant,
                'Accept' => 'application/json'
            ]
        ]);
        
        $invoices = json_decode($response->getBody()->getContents(), true);
        
        return is_array($invoices) ? $invoices : [];
    }
    
    /**
     * Fetch purchase invoices from DevPos
     */
    private function fetchDevPosPurchaseInvoices(string $token, string $tenant, string $fromDate, string $toDate): array
    {
        $client = new Client();
        $apiBase = $_ENV['DEVPOS_API_BASE'] ?? 'https://online.devpos.al/api/v3';
        
        $response = $client->get($apiBase . '/EInvoice/GetPurchaseInvoice', [
            'query' => [
                'fromDate' => $fromDate,
                'toDate' => $toDate
            ],
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'tenant' => $tenant,
                'Accept' => 'application/json'
            ]
        ]);
        
        $invoices = json_decode($response->getBody()->getContents(), true);
        
        return is_array($invoices) ? $invoices : [];
    }
    
    /**
     * Sync invoice to QuickBooks (simplified)
     */
    private function syncInvoiceToQBO(array $invoice, array $qboCreds, int $companyId): void
    {
        $client = new Client();
        $isSandbox = (($_ENV['QBO_ENV'] ?? 'production') === 'sandbox');
        $baseUrl = $isSandbox 
            ? 'https://sandbox-quickbooks.api.intuit.com'
            : 'https://quickbooks.api.intuit.com';
        
        // Check if invoice already exists
        $eic = $invoice['eic'] ?? $invoice['EIC'] ?? null;
        if (!$eic) {
            throw new Exception('Invoice missing EIC');
        }
        
        $existingId = $this->findQBOInvoiceByEIC($eic, $companyId);
        
        if ($existingId) {
            // Invoice already synced, skip
            return;
        }
        
        // Create invoice in QuickBooks
        $qboInvoice = $this->convertDevPosToQBOInvoice($invoice);
        
        $response = $client->post($baseUrl . '/v3/company/' . $qboCreds['realm_id'] . '/invoice', [
            'headers' => [
                'Authorization' => 'Bearer ' . $qboCreds['access_token'],
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ],
            'json' => $qboInvoice
        ]);
        
        $result = json_decode($response->getBody()->getContents(), true);
        
        // Store mapping
        if (isset($result['Invoice']['Id'])) {
            $this->storeInvoiceMapping(
                $companyId, 
                $eic, 
                (int)$result['Invoice']['Id'],
                'invoice',
                $invoice['documentNumber'] ?? '',
                $result['Invoice']['DocNumber'] ?? '',
                (float)($invoice['totalAmount'] ?? 0),
                $invoice['buyerName'] ?? ''
            );
        }
    }
    
    /**
     * Sync purchase to QuickBooks (simplified)
     */
    private function syncPurchaseToQBO(array $invoice, array $qboCreds, int $companyId): void
    {
        // Similar to syncInvoiceToQBO but creates a Purchase or Bill in QBO
        // Placeholder implementation
    }
    
    /**
     * Convert DevPos invoice to QuickBooks format
     */
    private function convertDevPosToQBOInvoice(array $devposInvoice): array
    {
        // Simplified conversion - you'll need to map all fields properly
        return [
            'CustomerRef' => [
                'value' => '1' // Default customer, should lookup/create customer
            ],
            'Line' => [
                [
                    'DetailType' => 'SalesItemLineDetail',
                    'Amount' => $devposInvoice['totalAmount'] ?? 0,
                    'SalesItemLineDetail' => [
                        'ItemRef' => ['value' => '1'], // Default item
                        'Qty' => 1,
                        'UnitPrice' => $devposInvoice['totalAmount'] ?? 0
                    ]
                ]
            ],
            'CustomField' => [
                [
                    'DefinitionId' => $_ENV['QBO_CF_EIC_DEF_ID'] ?? '1',
                    'Name' => 'EIC',
                    'Type' => 'StringType',
                    'StringValue' => $devposInvoice['eic'] ?? $devposInvoice['EIC'] ?? ''
                ]
            ]
        ];
    }
    
    /**
     * Find QuickBooks invoice by EIC
     */
    private function findQBOInvoiceByEIC(string $eic, int $companyId): ?int
    {
        $stmt = $this->pdo->prepare("
            SELECT qbo_invoice_id
            FROM invoice_mappings
            WHERE company_id = ? AND devpos_eic = ?
        ");
        $stmt->execute([$companyId, $eic]);
        
        $result = $stmt->fetchColumn();
        return $result ? (int)$result : null;
    }
    
    /**
     * Store invoice mapping
     */
    private function storeInvoiceMapping(
        int $companyId, 
        string $eic, 
        int $qboId, 
        string $type = 'invoice',
        string $devposDocNumber = '',
        string $qboDocNumber = '',
        float $amount = 0,
        string $customerName = ''
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO invoice_mappings (
                company_id, 
                devpos_eic, 
                transaction_type,
                devpos_document_number,
                qbo_invoice_id, 
                qbo_doc_number,
                amount,
                customer_name,
                synced_at,
                last_synced_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
                qbo_invoice_id = ?, 
                qbo_doc_number = ?,
                amount = ?,
                customer_name = ?,
                last_synced_at = NOW()
        ");
        $stmt->execute([
            $companyId, 
            $eic, 
            $type,
            $devposDocNumber,
            $qboId, 
            $qboDocNumber,
            $amount,
            $customerName,
            $qboId,
            $qboDocNumber,
            $amount,
            $customerName
        ]);
    }
    
    /**
     * Refresh QuickBooks token
     */
    private function refreshQBOToken(int $companyId, string $refreshToken): void
    {
        $client = new Client();
        
        $response = $client->post('https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer', [
            'form_params' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken
            ],
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode(($_ENV['QBO_CLIENT_ID'] ?? '') . ':' . ($_ENV['QBO_CLIENT_SECRET'] ?? '')),
                'Content-Type' => 'application/x-www-form-urlencoded'
            ]
        ]);
        
        $tokens = json_decode($response->getBody()->getContents(), true);
        
        // Update tokens in database
        $stmt = $this->pdo->prepare("
            UPDATE oauth_tokens_qbo
            SET access_token = ?,
                refresh_token = ?,
                expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
            WHERE company_id = ?
        ");
        $stmt->execute([
            $tokens['access_token'],
            $tokens['refresh_token'],
            $tokens['expires_in'] ?? 3600,
            $companyId
        ]);
    }
    
    /**
     * Update job status
     */
    private function updateJobStatus(int $jobId, string $status): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE sync_jobs
            SET status = ?,
                started_at = CASE WHEN ? = 'running' THEN NOW() ELSE started_at END
            WHERE id = ?
        ");
        $stmt->execute([$status, $status, $jobId]);
    }
    
    /**
     * Mark job as completed
     */
    private function completeJob(int $jobId, array $results): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE sync_jobs
            SET status = 'completed',
                completed_at = NOW(),
                results_json = ?
            WHERE id = ?
        ");
        $stmt->execute([json_encode($results), $jobId]);
    }
    
    /**
     * Mark job as failed
     */
    private function failJob(int $jobId, string $error): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE sync_jobs
            SET status = 'failed',
                completed_at = NOW(),
                error_message = ?
            WHERE id = ?
        ");
        $stmt->execute([$error, $jobId]);
    }
}
