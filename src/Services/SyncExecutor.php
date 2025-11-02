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
                        $results = $this->syncSales($job, $jobId);
                        break;
                        
                    case 'purchases':
                        $results = $this->syncPurchases($job, $jobId);
                        break;
                        
                    case 'bills':
                        $results = $this->syncBills($job, $jobId);
                        break;
                        
                    case 'full':
                        $results['sales'] = $this->syncSales($job, $jobId);
                        if ($this->isJobCancelled($jobId)) {
                            throw new Exception('Job cancelled by user');
                        }
                        $results['purchases'] = $this->syncPurchases($job, $jobId);
                        if ($this->isJobCancelled($jobId)) {
                            throw new Exception('Job cancelled by user');
                        }
                        $results['bills'] = $this->syncBills($job, $jobId);
                        break;
                        
                    default:
                        throw new Exception('Invalid job type: ' . $job['job_type']);
                }
                
                // Check one final time before completing
                if ($this->isJobCancelled($jobId)) {
                    throw new Exception('Job cancelled by user');
                }
                
                // Mark as completed
                $this->completeJob($jobId, $results);
                
                return [
                    'success' => true,
                    'job_id' => $jobId,
                    'results' => $results
                ];
                
            } catch (Exception $e) {
                // Check if it was a cancellation
                if (strpos($e->getMessage(), 'cancelled') !== false) {
                    // Mark as cancelled
                    $this->cancelJob($jobId, $e->getMessage());
                } else {
                    // Mark as failed
                    $this->failJob($jobId, $e->getMessage());
                }
                
                throw $e;
            }
        }
        
        /**
         * Check if job has been cancelled
         */
        private function isJobCancelled(int $jobId): bool
        {
            $stmt = $this->pdo->prepare("SELECT status FROM sync_jobs WHERE id = ?");
            $stmt->execute([$jobId]);
            $status = $stmt->fetchColumn();
            
            return $status === 'cancelled';
        }
        
        /**
         * Mark job as cancelled
         */
        private function cancelJob(int $jobId, string $message): void
        {
            $stmt = $this->pdo->prepare("
                UPDATE sync_jobs 
                SET status = 'cancelled',
                    error_message = ?,
                    completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$message, $jobId]);
            error_log("Job $jobId cancelled: $message");
        }
        
        /**
         * Sync sales invoices from DevPos to QuickBooks
         */
        private function syncSales(array $job, int $jobId): array
        {
            $companyId = (int)$job['company_id'];
            
            // Get DevPos credentials
            $devposCreds = $this->getDevPosCredentials($companyId);
            if (!$devposCreds) {
                throw new Exception('DevPos credentials not configured');
            }
            
            // Get QuickBooks credentials and auto-refresh if needed
            $qboCreds = $this->getQBOCredentials($companyId);
            if (!$qboCreds) {
                throw new Exception('QuickBooks not connected');
            }
            $qboCreds = $this->ensureFreshToken($qboCreds, $companyId);
            
            // Get DevPos access token
            $devposToken = $this->getDevPosToken($devposCreds);
            
            // Fetch sales invoices from DevPos
            $invoices = $this->fetchDevPosSalesInvoices(
                $devposToken,
                $devposCreds['tenant'],
                $job['from_date'],
                $job['to_date']
            );
            
            $totalInvoices = count($invoices);
            error_log("Starting sales sync for company $companyId: $totalInvoices invoices to process");
            
            $synced = 0;
            $errors = [];
            
            foreach ($invoices as $index => $invoice) {
                // Check for cancellation every 10 invoices
                if ($index % 10 === 0 && $this->isJobCancelled($jobId)) {
                    error_log("Sales sync cancelled at invoice $index/$totalInvoices");
                    throw new Exception("Job cancelled by user after processing $synced invoices");
                }
                
                $invoiceId = $invoice['eic'] ?? $invoice['documentNumber'] ?? 'unknown';
                $progress = ($index + 1) . "/$totalInvoices";
                
                try {
                    error_log("[$progress] Syncing invoice $invoiceId to QuickBooks...");
                    
                    // Sync to QuickBooks
                    $this->syncInvoiceToQBO($invoice, $qboCreds, $companyId);
                    $synced++;
                    
                    error_log("[$progress] ✓ Invoice $invoiceId synced successfully");
                } catch (Exception $e) {
                    error_log("[$progress] ✗ Invoice $invoiceId failed: " . $e->getMessage());
                    $errors[] = [
                        'invoice' => $invoiceId,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            error_log("Sales sync completed for company $companyId: $synced/$totalInvoices synced, " . count($errors) . " errors");
            
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
        private function syncPurchases(array $job, int $jobId): array
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
            
            $totalInvoices = count($invoices);
            error_log("Starting purchases sync for company $companyId: $totalInvoices invoices to process");
            
            $synced = 0;
            $errors = [];
            
            foreach ($invoices as $index => $invoice) {
                $invoiceId = $invoice['eic'] ?? 'unknown';
                $progress = ($index + 1) . "/$totalInvoices";
                
                try {
                    error_log("[$progress] Syncing purchase invoice $invoiceId to QuickBooks...");
                    
                    $this->syncPurchaseToQBO($invoice, $qboCreds, $companyId);
                    $synced++;
                    
                    error_log("[$progress] ✓ Purchase invoice $invoiceId synced successfully");
                } catch (Exception $e) {
                    error_log("[$progress] ✗ Purchase invoice $invoiceId failed: " . $e->getMessage());
                    $errors[] = [
                        'invoice' => $invoiceId,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            error_log("Purchases sync completed for company $companyId: $synced/$totalInvoices synced, " . count($errors) . " errors");
            
            return [
                'total' => count($invoices),
                'synced' => $synced,
                'errors' => count($errors),
                'error_details' => $errors
            ];
        }
        
        /**
         * Sync bills from DevPos to QuickBooks
         */
        private function syncBills(array $job, int $jobId): array
        {
            $companyId = (int)$job['company_id'];
            
            // Get DevPos credentials
            $devposCreds = $this->getDevPosCredentials($companyId);
            if (!$devposCreds) {
                throw new Exception('DevPos credentials not configured');
            }
            
            // Get QuickBooks credentials and auto-refresh if needed
            $qboCreds = $this->getQBOCredentials($companyId);
            if (!$qboCreds) {
                throw new Exception('QuickBooks not connected');
            }
            $qboCreds = $this->ensureFreshToken($qboCreds, $companyId);
            
            // Get DevPos access token
            $devposToken = $this->getDevPosToken($devposCreds);
            
            // Fetch purchase invoices (bills) from DevPos
            $bills = $this->fetchDevPosPurchaseInvoices(
                $devposToken,
                $devposCreds['tenant'],
                $job['from_date'],
                $job['to_date']
            );
            
            $totalBills = count($bills);
            error_log("Starting bills sync for company $companyId: $totalBills bills to process");
            
            $synced = 0;
            $skipped = 0;
            $errors = [];
            
            foreach ($bills as $index => $bill) {
                // Check for cancellation every 10 bills
                if ($index % 10 === 0 && $this->isJobCancelled($jobId)) {
                    error_log("Bills sync cancelled at bill $index/$totalBills");
                    throw new Exception("Job cancelled by user after processing $synced bills");
                }
                
                $billId = $bill['documentNumber'] ?? $bill['eic'] ?? 'unknown';
                $progress = ($index + 1) . "/$totalBills";
                
                try {
                    error_log("[$progress] Processing bill $billId...");
                    
                    // DEBUG: Log first bill's full structure, then just currency for others
                    if ($synced + $skipped === 0) {
                        error_log("[$progress] === FIRST BILL RAW DATA ===");
                        error_log("[$progress] Available fields: " . implode(', ', array_keys($bill)));
                        error_log("[$progress] Full bill data: " . json_encode($bill, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        error_log("[$progress] ====================");
                    }
                    
                    // Check if amount is valid
                    $amount = (float)($bill['amount'] ?? $bill['total'] ?? $bill['totalAmount'] ?? 0);
                    if ($amount <= 0) {
                        error_log("[$progress] Skipping bill $billId: Invalid amount");
                        $skipped++;
                        continue;
                    }
                    
                    // Get bill identifiers
                    $eic = $bill['eic'] ?? $bill['EIC'] ?? null;
                    $docNumber = $bill['documentNumber'] ?? $bill['id'] ?? null;
                    $vendorNuis = $bill['sellerNuis'] ?? '';
                    
                    if (!$docNumber) {
                        error_log("[$progress] Skipping bill $billId: No document number");
                        $skipped++;
                        continue;
                    }
                    
                    // Get current bill data for comparison
                    $currentAmount = (float)($bill['amount'] ?? $bill['total'] ?? $bill['totalAmount'] ?? 0);
                    
                    // Fetch detailed invoice info to get currency (list API doesn't include it)
                    $currentCurrency = 'ALL'; // Default
                    $exchangeRate = null;
                    
                    if ($eic) {
                        $detailedInvoice = $this->fetchDevPosInvoiceDetails($devposToken, $devposCreds['tenant'], $eic);
                        if ($detailedInvoice) {
                            // Extract currency from detailed invoice
                            $currentCurrency = $detailedInvoice['currencyCode'] 
                                ?? $detailedInvoice['currency'] 
                                ?? $detailedInvoice['Currency'] 
                                ?? $detailedInvoice['CurrencyCode']
                                ?? 'ALL';
                            
                            // Extract exchange rate if available
                            $exchangeRate = $detailedInvoice['exchangeRate'] 
                                ?? $detailedInvoice['ExchangeRate']
                                ?? $detailedInvoice['rate']
                                ?? null;
                            
                            error_log("[$progress] Fetched detailed invoice - Currency: $currentCurrency, ExchangeRate: " . ($exchangeRate ?? 'NULL'));
                        } else {
                            error_log("[$progress] Could not fetch detailed invoice for EIC: $eic, using default currency ALL");
                        }
                    }
                    
                    error_log("[$progress] Bill $docNumber - Currency: $currentCurrency, Amount: $currentAmount");
                    
                    // Enrich bill data with currency and exchange rate from detailed invoice
                    if ($currentCurrency !== 'ALL') {
                        $bill['currencyCode'] = $currentCurrency;
                        if ($exchangeRate) {
                            $bill['exchangeRate'] = $exchangeRate;
                        }
                    }
                    
                    // Check if bill already exists in mapping
                    $existingMapping = $this->getBillMapping($companyId, $docNumber, $vendorNuis);
                    
                    if ($existingMapping) {
                        $existingBillId = $existingMapping['qbo_invoice_id'];
                        
                        // Verify it actually exists in QuickBooks
                        $billExistsInQBO = $this->verifyBillExistsInQBO($existingBillId, $qboCreds);
                        if (!$billExistsInQBO) {
                            // Bill was deleted from QuickBooks, remove stale mapping and recreate
                            error_log("[$progress] Bill $docNumber was deleted from QuickBooks, will recreate");
                            $this->removeBillMapping($companyId, $docNumber);
                        } else {
                            // Check if amount or currency changed
                            $storedAmount = (float)($existingMapping['amount'] ?? 0);
                            $storedCurrency = $existingMapping['currency'] ?? 'ALL';
                            
                            $amountChanged = abs($currentAmount - $storedAmount) > 0.01;
                            $currencyChanged = $currentCurrency !== $storedCurrency;
                            
                            if ($amountChanged || $currencyChanged) {
                                error_log("[$progress] Bill $docNumber has changes - Amount: $storedAmount->$currentAmount, Currency: $storedCurrency->$currentCurrency");
                                
                                // Get vendor and convert bill
                                $vendorId = $this->getOrCreateVendor(
                                    $vendorNuis,
                                    $bill['sellerName'] ?? 'Unknown Vendor',
                                    $qboCreds,
                                    $companyId,
                                    $currentCurrency
                                );
                                
                                $qboBill = $this->convertDevPosToQBOBill($bill, $vendorId);
                                
                                // Update the bill in QuickBooks
                                $result = $this->updateQBOBill($existingBillId, $qboBill, $qboCreds);
                                
                                // Update mapping with new values
                                if (isset($result['Bill']['Id'])) {
                                    $this->storeBillMapping(
                                        $companyId,
                                        $docNumber,
                                        $vendorNuis,
                                        (int)$existingBillId,
                                        $result['Bill']['DocNumber'] ?? '',
                                        $currentAmount,
                                        $bill['sellerName'] ?? '',
                                        $currentCurrency
                                    );
                                }
                                
                                error_log("[$progress] ✓ Bill $docNumber updated successfully in QuickBooks");
                                $synced++;
                                continue;
                            } else {
                                // No changes, skip
                                error_log("[$progress] Bill $docNumber unchanged, skipping");
                                $skipped++;
                                continue;
                            }
                        }
                    }
                    
                    error_log("[$progress] Creating bill $billId in QuickBooks...");
                    
                    // DEBUG: Log all bill fields to identify date field
                    error_log("[$progress] Bill fields: " . json_encode(array_keys($bill)));
                    error_log("[$progress] Bill data sample: " . json_encode(array_slice($bill, 0, 15)));
                    
                    // Create bill in QuickBooks
                    $this->syncBillToQBO($bill, $qboCreds, $companyId);
                    $synced++;
                    
                    error_log("[$progress] ✓ Bill $billId synced successfully");
                    
                } catch (Exception $e) {
                    error_log("[$progress] ✗ Bill $billId failed: " . $e->getMessage());
                    $errors[] = [
                        'bill' => $billId,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            error_log("Bills sync completed for company $companyId: $synced/$totalBills synced, $skipped skipped, " . count($errors) . " errors");
            
            return [
                'total' => count($bills),
                'bills_created' => $synced,
                'skipped' => $skipped,
                'errors' => count($errors),
                'error_details' => $errors
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
                    realm_id,
                    access_token,
                    refresh_token,
                    token_expires_at
                FROM company_credentials_qbo
                WHERE company_id = ?
            ");
            $stmt->execute([$companyId]);
            $creds = $stmt->fetch();
            
            if (!$creds || !$creds['realm_id']) {
                return null;
            }
            
            return [
                'realm_id' => $creds['realm_id'],
                'access_token' => $creds['access_token'],
                'refresh_token' => $creds['refresh_token'],
                'token_expires_at' => $creds['token_expires_at']
            ];
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
            
            error_log("Fetching DevPos sales invoices: fromDate=$fromDate, toDate=$toDate, tenant=$tenant");
            
            try {
                $response = $client->get($apiBase . '/EInvoice/GetSalesInvoice', [
                    'query' => [
                        'fromDate' => $fromDate,
                        'toDate' => $toDate,
                        'includePdf' => true  // Request PDF field in response
                    ],
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'tenant' => $tenant,
                        'Accept' => 'application/json'
                    ]
                ]);
                
                $body = $response->getBody()->getContents();
                error_log("DevPos API Response (first 500 chars): " . substr($body, 0, 500));
                
                $invoices = json_decode($body, true);
                
                if (!is_array($invoices)) {
                    error_log("DevPos API did not return an array. Response type: " . gettype($invoices));
                    return [];
                }
                
                error_log("DevPos returned " . count($invoices) . " sales invoices");
                
                // DEBUG: Log first invoice structure to check for currency fields
                if (count($invoices) > 0) {
                    error_log("=== FIRST SALES INVOICE FROM DEVPOS API ===");
                    error_log(json_encode($invoices[0], JSON_PRETTY_PRINT));
                    error_log("=== AVAILABLE FIELDS: " . implode(', ', array_keys($invoices[0])) . " ===");
                }
                
                return $invoices;
                
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                error_log("DevPos API Client Error: " . $e->getMessage());
                error_log("DevPos API Error Response: " . $e->getResponse()->getBody()->getContents());
                throw $e;
            } catch (Exception $e) {
                error_log("DevPos API Error: " . $e->getMessage());
                throw $e;
            }
        }
        
        /**
         * Fetch purchase invoices from DevPos
         */
        private function fetchDevPosPurchaseInvoices(string $token, string $tenant, string $fromDate, string $toDate): array
        {
            $client = new Client();
            $apiBase = $_ENV['DEVPOS_API_BASE'] ?? 'https://online.devpos.al/api/v3';
            
            error_log("Fetching DevPos purchase invoices: fromDate=$fromDate, toDate=$toDate, tenant=$tenant");
            
            try {
                $response = $client->get($apiBase . '/EInvoice/GetPurchaseInvoice', [
                    'query' => [
                        'fromDate' => $fromDate,
                        'toDate' => $toDate,
                        'includePdf' => true  // Request PDF field in response
                    ],
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'tenant' => $tenant,
                        'Accept' => 'application/json'
                    ]
                ]);
                
                $body = $response->getBody()->getContents();
                error_log("DevPos Purchase API Response (first 500 chars): " . substr($body, 0, 500));
                
                $invoices = json_decode($body, true);
                
                if (!is_array($invoices)) {
                    error_log("DevPos Purchase API did not return an array. Response type: " . gettype($invoices));
                    return [];
                }
                
                error_log("DevPos returned " . count($invoices) . " purchase invoices");
                
                // DEBUG: Log first invoice structure to check for currency fields
                if (count($invoices) > 0) {
                    error_log("=== FIRST PURCHASE INVOICE FROM DEVPOS API ===");
                    error_log(json_encode($invoices[0], JSON_PRETTY_PRINT));
                    error_log("=== AVAILABLE FIELDS: " . implode(', ', array_keys($invoices[0])) . " ===");
                }
                
                return $invoices;
                
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                error_log("DevPos Purchase API Client Error: " . $e->getMessage());
                error_log("DevPos Purchase API Error Response: " . $e->getResponse()->getBody()->getContents());
                throw $e;
            } catch (Exception $e) {
                error_log("DevPos Purchase API Error: " . $e->getMessage());
                throw $e;
            }
        }
        
        /**
         * Fetch full invoice details from DevPos by EIC
         * This endpoint returns detailed invoice information including currency
         * Try multiple API endpoints to find the working one
         */
        private function fetchDevPosInvoiceDetails(string $token, string $tenant, string $eic): ?array
        {
            $client = new Client();
            $apiBase = $_ENV['DEVPOS_API_BASE'] ?? 'https://online.devpos.al/api/v3';
            
            // Try different endpoint variations since /EInvoice returns 405
            $endpoints = [
                '/EInvoice/Invoice',  // Try Invoice subpath
                '/EInvoice/GetInvoice',  // Try Get method
                '/Invoice',  // Try simplified path
            ];
            
            foreach ($endpoints as $endpoint) {
                try {
                    error_log("Trying DevPos endpoint: POST $apiBase$endpoint with EIC=$eic");
                    
                    $response = $client->post($apiBase . $endpoint, [
                        'form_params' => [
                            'EIC' => $eic
                        ],
                        'headers' => [
                            'Authorization' => 'Bearer ' . $token,
                            'tenant' => $tenant,
                            'Accept' => 'application/json'
                        ]
                    ]);
                    
                    $body = $response->getBody()->getContents();
                    $details = json_decode($body, true);
                    
                    if ($details) {
                        error_log("SUCCESS! Endpoint $endpoint returned data for EIC: $eic");
                        return $details;
                    }
                } catch (\GuzzleHttp\Exception\ClientException $e) {
                    error_log("DevPos endpoint $endpoint failed with: " . $e->getMessage());
                    continue; // Try next endpoint
                } catch (Exception $e) {
                    error_log("DevPos endpoint $endpoint error: " . $e->getMessage());
                    continue; // Try next endpoint
                }
            }
            
            // If all POST attempts failed, try GET as fallback
            try {
                error_log("All POST endpoints failed, trying GET $apiBase/EInvoice/$eic");
                $response = $client->get($apiBase . '/EInvoice/' . $eic, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'tenant' => $tenant,
                        'Accept' => 'application/json'
                    ]
                ]);
                
                $body = $response->getBody()->getContents();
                $details = json_decode($body, true);
                
                if (!$details) {
                    error_log("DevPos Invoice Details API returned empty response for EIC: $eic");
                    return null;
                }
                
                return $details;
                
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                error_log("DevPos Invoice Details API Client Error for EIC $eic: " . $e->getMessage());
                if ($e->hasResponse()) {
                    error_log("DevPos Invoice Details API Error Response: " . $e->getResponse()->getBody()->getContents());
                }
                return null;
            } catch (Exception $e) {
                error_log("DevPos Invoice Details API Error for EIC $eic: " . $e->getMessage());
                return null;
            }
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
            
            // Get current DevPos invoice data
            $currentAmount = (float)($invoice['totalAmount'] ?? $invoice['amount'] ?? 0);
            $currentCurrency = $invoice['currencyCode'] ?? $invoice['currency'] ?? 'ALL';
            
            $existingMapping = $this->getInvoiceMapping($companyId, $eic);
            
            if ($existingMapping) {
                $existingId = $existingMapping['qbo_invoice_id'];
                
                // Verify it actually exists in QuickBooks
                $invoiceExistsInQBO = $this->verifyInvoiceExistsInQBO($existingId, $qboCreds);
                if (!$invoiceExistsInQBO) {
                    // Invoice was deleted from QuickBooks, remove stale mapping and recreate
                    error_log("Invoice EIC $eic was deleted from QuickBooks, will recreate");
                    $this->removeInvoiceMapping($companyId, $eic);
                } else {
                    // Check if amount or currency changed
                    $storedAmount = (float)($existingMapping['amount'] ?? 0);
                    $storedCurrency = $existingMapping['currency'] ?? 'ALL';
                    
                    $amountChanged = abs($currentAmount - $storedAmount) > 0.01; // Tolerance for float comparison
                    $currencyChanged = $currentCurrency !== $storedCurrency;
                    
                    if ($amountChanged || $currencyChanged) {
                        error_log("Invoice EIC $eic has changes - Amount: $storedAmount->$currentAmount, Currency: $storedCurrency->$currentCurrency");
                        
                        // Build update payload with only changed fields
                        $qboInvoice = $this->convertDevPosToQBOInvoice($invoice, $companyId, $qboCreds);
                        
                        // Update the invoice in QuickBooks
                        $result = $this->updateQBOInvoice($existingId, $qboInvoice, $qboCreds);
                        
                        // Update mapping with new values
                        if (isset($result['Invoice']['Id'])) {
                            $this->storeInvoiceMapping(
                                $companyId, 
                                $eic, 
                                (int)$existingId,
                                'invoice',
                                $invoice['documentNumber'] ?? '',
                                $result['Invoice']['DocNumber'] ?? '',
                                $currentAmount,
                                $invoice['buyerName'] ?? '',
                                $currentCurrency
                            );
                        }
                        
                        error_log("Invoice EIC $eic updated successfully in QuickBooks");
                        return;
                    } else {
                        // No changes, skip
                        error_log("Invoice EIC $eic unchanged, skipping");
                        return;
                    }
                }
            }
            
            // Create new invoice in QuickBooks
            $qboInvoice = $this->convertDevPosToQBOInvoice($invoice, $companyId, $qboCreds);
            
            try {
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
                    $qboInvoiceId = (string)$result['Invoice']['Id'];
                    
                    $this->storeInvoiceMapping(
                        $companyId, 
                        $eic, 
                        (int)$qboInvoiceId,
                        'invoice',
                        $invoice['documentNumber'] ?? '',
                        $result['Invoice']['DocNumber'] ?? '',
                        $currentAmount,
                        $invoice['buyerName'] ?? '',
                        $currentCurrency
                    );
                    
                    // Attach PDF if available
                    try {
                        $devposCreds = $this->getDevPosCredentials($companyId);
                        if ($devposCreds) {
                            $devposToken = $this->getDevPosToken($devposCreds);
                            $this->attachPDFIfAvailable(
                                $invoice,
                                'Invoice',
                                $qboInvoiceId,
                                $devposToken,
                                $devposCreds['tenant'],
                                $qboCreds
                            );
                        }
                    } catch (Exception $pdfError) {
                        // Don't fail invoice sync if PDF attachment fails
                        error_log("Warning: Failed to attach PDF to invoice: " . $pdfError->getMessage());
                    }
                }
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                // Get the full error response from QuickBooks
                $errorBody = $e->getResponse()->getBody()->getContents();
                $errorData = json_decode($errorBody, true);
                
                $errorDetail = 'QuickBooks API Error';
                if (isset($errorData['Fault']['Error'][0])) {
                    $error = $errorData['Fault']['Error'][0];
                    $errorDetail = $error['Message'] ?? 'Unknown error';
                    if (isset($error['Detail'])) {
                        $errorDetail .= ': ' . $error['Detail'];
                    }
                }
                
                // Log the payload that failed for debugging
                error_log("QuickBooks Invoice Creation Failed");
                error_log("Error: " . $errorDetail);
                error_log("Payload sent: " . json_encode($qboInvoice, JSON_PRETTY_PRINT));
                
                throw new Exception($errorDetail);
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
         * Handles both VAT-tracking and non-VAT companies
         * For VAT companies, uses configurable VAT rate mappings
         */
        private function convertDevPosToQBOInvoice(array $devposInvoice, int $companyId, array $qboCreds): array
        {
            // Check if company tracks VAT separately
            $stmt = $this->pdo->prepare("SELECT tracks_vat FROM companies WHERE id = ?");
            $stmt->execute([$companyId]);
            $tracksVat = (bool)$stmt->fetchColumn();
            
            $documentNumber = $devposInvoice['documentNumber'] 
                ?? $devposInvoice['doc_no'] 
                ?? $devposInvoice['DocNumber'] 
                ?? null;
                
            $issueDate = $devposInvoice['invoiceCreatedDate']  // PRIMARY - actual field from DevPos API
                ?? $devposInvoice['issueDate']                 // SECONDARY fallback 
                ?? $devposInvoice['dateCreated']               // TERTIARY fallback
                ?? $devposInvoice['created_at']                // QUATERNARY fallback
                ?? date('Y-m-d');                              // FINAL fallback - today's date
                
            $totalAmount = (float)($devposInvoice['totalAmount'] 
                ?? $devposInvoice['total'] 
                ?? $devposInvoice['amount'] 
                ?? 0);
                
            $buyerName = $devposInvoice['buyerName'] 
                ?? $devposInvoice['buyer_name'] 
                ?? $devposInvoice['customerName'] 
                ?? 'Walk-in Customer';
                
            $buyerNuis = $devposInvoice['buyerNuis'] 
                ?? $devposInvoice['buyer_nuis'] 
                ?? $devposInvoice['customerNuis'] 
                ?? null;
                
            $eic = $devposInvoice['eic'] 
                ?? $devposInvoice['EIC'] 
                ?? '';
                
            // Extract VAT rate from DevPos invoice (if available)
            $vatRate = (float)($devposInvoice['vatRate'] 
                ?? $devposInvoice['vat_rate'] 
                ?? $devposInvoice['taxRate'] 
                ?? 0);

            $totalWithVat = floatval($totalAmount);
            
            // STEP 1: Extract currency from invoice
            error_log("=== STEP 1: INVOICE CURRENCY EXTRACTION ===");
            error_log("Invoice document: " . ($devposInvoice['documentNumber'] ?? 'NO DOC NUMBER'));
            error_log("Buyer: " . ($buyerName ?? 'UNKNOWN'));
            
            $currencyCode = $devposInvoice['currencyCode'] ?? null;
            $currency = $devposInvoice['currency'] ?? null;
            $baseCurrency = $devposInvoice['baseCurrency'] ?? null;
            $exchangeRate = $devposInvoice['exchangeRate'] ?? null;
            $amountInBaseCurrency = $devposInvoice['amountInBaseCurrency'] ?? null;
            
            error_log("  currencyCode field: " . ($currencyCode ?? 'NOT SET'));
            error_log("  currency field: " . ($currency ?? 'NOT SET'));
            error_log("  baseCurrency field: " . ($baseCurrency ?? 'NOT SET'));
            error_log("  exchangeRate field: " . ($exchangeRate ?? 'NOT SET'));
            error_log("  totalAmount: $totalAmount");
            error_log("  amountInBaseCurrency: " . ($amountInBaseCurrency ?? 'NOT SET'));
            
            $finalCurrency = $currencyCode ?? $currency ?? 'ALL';
            error_log("  ➜ FINAL CURRENCY: $finalCurrency");
            error_log("==========================================");
            
            // STEP 2: Get or create customer with currency
            error_log("=== STEP 2: CUSTOMER CREATION ===");
            error_log("  Customer NUIS: " . ($buyerNuis ?? 'NOT SET'));
            error_log("  Customer Name: $buyerName");
            error_log("  Currency for customer: $finalCurrency");
            
            $customerId = $this->getOrCreateQBOCustomer($buyerName, $buyerNuis, $companyId, $qboCreds, $finalCurrency);
            
            error_log("  ➜ Customer ID: $customerId");
            error_log("==================================");

            // Note: Income items are always in home currency (ALL)
            // QuickBooks handles multi-currency through CurrencyRef + ExchangeRate on the transaction
            
            // Build QuickBooks invoice payload based on company VAT tracking preference
            
            if ($tracksVat) {
                // VAT-registered company: Use VAT rate mappings to determine tax code
                $taxCode = $this->getQBOTaxCodeForVATRate($companyId, $vatRate);
                
                $payload = [
                    'Line' => [
                        [
                            'Amount' => $totalWithVat,
                            'DetailType' => 'SalesItemLineDetail',
                            'SalesItemLineDetail' => [
                                'ItemRef' => [
                                    'value' => '1', // Default item - always home currency
                                    'name' => 'Services'
                                ],
                                'UnitPrice' => $totalWithVat,
                                'Qty' => 1,
                                'TaxCodeRef' => [
                                    'value' => $taxCode // Mapped tax code from VAT rate
                                ]
                            ],
                            'Description' => $documentNumber ? "Invoice: $documentNumber - VAT: {$vatRate}%" : 'Sales Invoice'
                        ]
                    ],
                    'CustomerRef' => [
                        'value' => $customerId // Dynamic customer lookup
                    ],
                    'TxnDate' => substr($issueDate, 0, 10) // YYYY-MM-DD format
                ];
            } else {
                // Non-VAT company: QuickBooks has tax OFF - send amount without any tax information
                // Completely omit TaxCodeRef and any tax-related fields
                $payload = [
                    'Line' => [
                        [
                            'Amount' => $totalWithVat,
                            'DetailType' => 'SalesItemLineDetail',
                            'SalesItemLineDetail' => [
                                'ItemRef' => [
                                    'value' => '1', // Default item - always home currency
                                    'name' => 'Services'
                                ],
                                'UnitPrice' => $totalWithVat,
                                'Qty' => 1
                                // NO TaxCodeRef when tax tracking is OFF in QuickBooks
                            ],
                            'Description' => $documentNumber ? "Invoice: $documentNumber" : 'Sales Invoice'
                        ]
                    ],
                    'CustomerRef' => [
                        'value' => $customerId // Dynamic customer lookup
                    ],
                    'TxnDate' => substr($issueDate, 0, 10) // YYYY-MM-DD format
                    // NO GlobalTaxCalculation or TxnTaxDetail when tax is OFF
                ];
            }

            // Add document number if available
            if ($documentNumber) {
                $payload['DocNumber'] = (string)$documentNumber;
            }

            // Add EIC as custom field ONLY if configured
            if ($eic && !empty($_ENV['QBO_CF_EIC_DEF_ID'])) {
                // QuickBooks custom fields have 31 char limit - truncate EIC if needed
                $eicValue = strlen($eic) > 31 ? substr($eic, 0, 31) : $eic;
                
                $payload['CustomField'] = [
                    [
                        'DefinitionId' => $_ENV['QBO_CF_EIC_DEF_ID'],
                        'Name' => 'EIC',
                        'Type' => 'StringType',
                        'StringValue' => $eicValue
                    ]
                ];
            }

            // STEP 3: Multi-Currency Support
            error_log("=== STEP 3: INVOICE PAYLOAD MULTI-CURRENCY ===");
            $exchangeRate = $devposInvoice['exchangeRate'] ?? null;
            
            if ($finalCurrency !== 'ALL') {
                error_log("  Detected foreign currency: $finalCurrency");
                error_log("  Exchange rate: " . ($exchangeRate ?? 'NOT SET'));
                
                // Set currency reference
                $payload['CurrencyRef'] = ['value' => strtoupper($finalCurrency)];
                error_log("  ➜ Added CurrencyRef: $finalCurrency");
                
                // Set exchange rate if available
                if ($exchangeRate && $exchangeRate > 0) {
                    $payload['ExchangeRate'] = (float)$exchangeRate;
                    error_log("  ➜ Added ExchangeRate: $exchangeRate (1 $finalCurrency = $exchangeRate ALL)");
                } else {
                    error_log("  ⚠️  WARNING: Foreign currency but no exchange rate!");
                }
            } else {
                error_log("  Home currency (ALL) - no CurrencyRef needed");
            }
            
            error_log("  ➜ Final Invoice Payload:");
            error_log(json_encode($payload, JSON_PRETTY_PRINT));
            error_log("================================================");

            return $payload;
        }
        
        /**
         * Get QuickBooks tax code for a given DevPos VAT rate
         * Uses the vat_rate_mappings table for company-specific configuration
         * Falls back to 'TAX' if no mapping exists
         */
        private function getQBOTaxCodeForVATRate(int $companyId, float $vatRate): string
        {
            // Look up the tax code mapping for this company and VAT rate
            $stmt = $this->pdo->prepare("
                SELECT qbo_tax_code, is_excluded
                FROM vat_rate_mappings
                WHERE company_id = ? AND devpos_vat_rate = ?
            ");
            $stmt->execute([$companyId, $vatRate]);
            $mapping = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($mapping) {
                // Found a specific mapping for this VAT rate
                return $mapping['qbo_tax_code'];
            }
            
            // No mapping found - use intelligent defaults
            if ($vatRate == 0) {
                // 0% VAT could be exempt or excluded
                // Check if company has any "excluded" mappings configured
                $stmt = $this->pdo->prepare("
                    SELECT qbo_tax_code
                    FROM vat_rate_mappings
                    WHERE company_id = ? AND is_excluded = TRUE
                    LIMIT 1
                ");
                $stmt->execute([$companyId]);
                $excludedCode = $stmt->fetchColumn();
                
                return $excludedCode ?: 'NON'; // Default to 'NON' (non-taxable)
            }
            
            // Default to 'TAX' for non-zero VAT rates
            return 'TAX';
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
         * Verify if an invoice actually exists in QuickBooks
         */
        private function verifyInvoiceExistsInQBO(int $invoiceId, array $qboCreds): bool
        {
            try {
                $client = new Client();
                $isSandbox = (($_ENV['QBO_ENV'] ?? 'production') === 'sandbox');
                $baseUrl = $isSandbox 
                    ? 'https://sandbox-quickbooks.api.intuit.com'
                    : 'https://quickbooks.api.intuit.com';
                
                $response = $client->get(
                    "{$baseUrl}/v3/company/{$qboCreds['realm_id']}/invoice/{$invoiceId}",
                    [
                        'headers' => [
                            'Authorization' => "Bearer {$qboCreds['access_token']}",
                            'Accept' => 'application/json',
                        ],
                        'query' => ['minorversion' => 65]
                    ]
                );
                
                return $response->getStatusCode() === 200;
            } catch (Exception $e) {
                // If we get 404 or any error, assume invoice doesn't exist
                return false;
            }
        }
        
        /**
         * Remove invoice mapping from database
         */
        private function removeInvoiceMapping(int $companyId, string $eic): void
        {
            $stmt = $this->pdo->prepare("
                DELETE FROM invoice_mappings
                WHERE company_id = ? AND devpos_eic = ?
            ");
            $stmt->execute([$companyId, $eic]);
        }
        
        /**
         * Verify if a bill actually exists in QuickBooks
         */
        private function verifyBillExistsInQBO(int|string $billId, array $qboCreds): bool
        {
            try {
                $client = new Client();
                $isSandbox = (($_ENV['QBO_ENV'] ?? 'production') === 'sandbox');
                $baseUrl = $isSandbox 
                    ? 'https://sandbox-quickbooks.api.intuit.com'
                    : 'https://quickbooks.api.intuit.com';
                
                $response = $client->get(
                    "{$baseUrl}/v3/company/{$qboCreds['realm_id']}/bill/{$billId}",
                    [
                        'headers' => [
                            'Authorization' => "Bearer {$qboCreds['access_token']}",
                            'Accept' => 'application/json',
                        ],
                        'query' => ['minorversion' => 65]
                    ]
                );
                
                return $response->getStatusCode() === 200;
            } catch (Exception $e) {
                // If we get 404 or any error, assume bill doesn't exist
                return false;
            }
        }
        
        /**
         * Remove bill mapping from database
         */
        private function removeBillMapping(int $companyId, string $docNumber): void
        {
            $stmt = $this->pdo->prepare("
                DELETE FROM invoice_mappings
                WHERE company_id = ? AND devpos_document_number = ? AND transaction_type = 'bill'
            ");
            $stmt->execute([$companyId, $docNumber]);
        }
        
        /**
         * Find QuickBooks bill by document number
         */
        private function findBillByDocNumber(int $companyId, string $docNumber): ?int
        {
            $stmt = $this->pdo->prepare("
                SELECT qbo_invoice_id
                FROM invoice_mappings
                WHERE company_id = ? AND devpos_document_number = ? AND transaction_type = 'bill'
            ");
            $stmt->execute([$companyId, $docNumber]);
            
            $result = $stmt->fetchColumn();
            return $result ? (int)$result : null;
        }
        
        /**
         * Sync bill to QuickBooks
         */
        private function syncBillToQBO(array $bill, array $qboCreds, int $companyId): void
        {
            $client = new Client();
            $isSandbox = (($_ENV['QBO_ENV'] ?? 'production') === 'sandbox');
            $baseUrl = $isSandbox 
                ? 'https://sandbox-quickbooks.api.intuit.com'
                : 'https://quickbooks.api.intuit.com';
            
            // STEP 1: Extract currency from bill
            error_log("=== STEP 1: CURRENCY EXTRACTION ===");
            error_log("Bill document: " . ($bill['documentNumber'] ?? 'NO DOC NUMBER'));
            error_log("Seller: " . ($bill['sellerName'] ?? 'UNKNOWN'));
            
            // Check all possible currency fields
            $currencyCode = $bill['currencyCode'] ?? null;
            $currency = $bill['currency'] ?? null;
            $baseCurrency = $bill['baseCurrency'] ?? null;
            $exchangeRate = $bill['exchangeRate'] ?? null;
            $totalAmount = $bill['totalAmount'] ?? $bill['total'] ?? $bill['amount'] ?? null;
            $amountInBaseCurrency = $bill['amountInBaseCurrency'] ?? null;
            
            error_log("  currencyCode field: " . ($currencyCode ?? 'NOT SET'));
            error_log("  currency field: " . ($currency ?? 'NOT SET'));
            error_log("  baseCurrency field: " . ($baseCurrency ?? 'NOT SET'));
            error_log("  exchangeRate field: " . ($exchangeRate ?? 'NOT SET'));
            error_log("  totalAmount: " . ($totalAmount ?? 'NOT SET'));
            error_log("  amountInBaseCurrency: " . ($amountInBaseCurrency ?? 'NOT SET'));
            
            // Determine final currency
            $finalCurrency = $currencyCode ?? $currency ?? 'ALL';
            error_log("  ➜ FINAL CURRENCY: $finalCurrency");
            error_log("=================================");
            
            // STEP 2: Get or create vendor with currency
            error_log("=== STEP 2: VENDOR CREATION ===");
            error_log("  Vendor NUIS: " . ($bill['sellerNuis'] ?? 'NOT SET'));
            error_log("  Vendor Name: " . ($bill['sellerName'] ?? 'Unknown Vendor'));
            error_log("  Currency for vendor: $finalCurrency");
            
            $vendorId = $this->getOrCreateVendor(
                $bill['sellerNuis'] ?? '',
                $bill['sellerName'] ?? 'Unknown Vendor',
                $qboCreds,
                $companyId,
                $finalCurrency
            );
            
            error_log("  ➜ Vendor ID: $vendorId");
            error_log("================================");
            
            // STEP 3: Convert bill to QBO format
            error_log("=== STEP 3: BILL PAYLOAD CREATION ===");
            $qboBill = $this->convertDevPosToQBOBill($bill, $vendorId);
            
            error_log("  ➜ Generated QBO Payload:");
            error_log(json_encode($qboBill, JSON_PRETTY_PRINT));
            error_log("========================================");
            
            // STEP 4: Send to QuickBooks
            error_log("=== STEP 4: SENDING TO QUICKBOOKS ===");
            $response = $client->post($baseUrl . '/v3/company/' . $qboCreds['realm_id'] . '/bill', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $qboCreds['access_token'],
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ],
                'json' => $qboBill
            ]);
            
            $result = json_decode($response->getBody()->getContents(), true);
            
            error_log("  ➜ QuickBooks Response:");
            error_log(json_encode($result, JSON_PRETTY_PRINT));
            
            // STEP 5: Check what QBO returned
            if (isset($result['Bill'])) {
                error_log("=== STEP 5: QBO BILL VERIFICATION ===");
                error_log("  Bill ID: " . ($result['Bill']['Id'] ?? 'NOT SET'));
                error_log("  CurrencyRef: " . ($result['Bill']['CurrencyRef']['value'] ?? 'NOT SET'));
                error_log("  ExchangeRate: " . ($result['Bill']['ExchangeRate'] ?? 'NOT SET'));
                error_log("  TotalAmt: " . ($result['Bill']['TotalAmt'] ?? 'NOT SET'));
                error_log("  Balance: " . ($result['Bill']['Balance'] ?? 'NOT SET'));
                error_log("  HomeBalance: " . ($result['Bill']['HomeBalance'] ?? 'NOT SET'));
                error_log("=========================================");
            }
            
            // Store mapping
            if (isset($result['Bill']['Id'])) {
                $qboBillId = (string)$result['Bill']['Id'];
                
                $this->storeBillMapping(
                    $companyId, 
                    $bill['documentNumber'] ?? '',
                    $bill['sellerNuis'] ?? '',
                    (int)$qboBillId,
                    $result['Bill']['DocNumber'] ?? '',
                    (float)($bill['amount'] ?? $bill['total'] ?? $bill['totalAmount'] ?? 0),
                    $bill['sellerName'] ?? '',
                    $finalCurrency
                );
                
                // Attach PDF if available
                try {
                    $devposCreds = $this->getDevPosCredentials($companyId);
                    if ($devposCreds) {
                        $devposToken = $this->getDevPosToken($devposCreds);
                        $this->attachPDFIfAvailable(
                            $bill,
                            'Bill',
                            $qboBillId,
                            $devposToken,
                            $devposCreds['tenant'],
                            $qboCreds
                        );
                    }
                } catch (Exception $pdfError) {
                    // Don't fail bill sync if PDF attachment fails
                    error_log("Warning: Failed to attach PDF to bill: " . $pdfError->getMessage());
                }
            }
        }
        
        /**
         * Get or create vendor in QuickBooks
         */
        private function getOrCreateVendor(string $nuis, string $name, array $qboCreds, int $companyId, string $currency = 'ALL'): string
        {
            // Normalize currency code
            $currency = strtoupper($currency);
            
            // For multi-currency, append currency to name for lookup
            $lookupKey = $nuis;
            if ($currency !== 'ALL') {
                $lookupKey = $nuis . '_' . $currency;
            }
            
            // Check if vendor already exists in mappings
            $stmt = $this->pdo->prepare("
                SELECT qbo_vendor_id 
                FROM vendor_mappings 
                WHERE company_id = ? AND devpos_nuis = ?
            ");
            $stmt->execute([$companyId, $lookupKey]);
            $existingId = $stmt->fetchColumn();
            
            if ($existingId) {
                error_log("Found existing vendor: $name ($currency) => QBO ID: $existingId");
                return (string)$existingId;
            }
            
            // Create new vendor in QuickBooks
            $client = new Client();
            $isSandbox = (($_ENV['QBO_ENV'] ?? 'production') === 'sandbox');
            $baseUrl = $isSandbox 
                ? 'https://sandbox-quickbooks.api.intuit.com'
                : 'https://quickbooks.api.intuit.com';
            
            // Append currency to display name if not home currency
            $displayName = $name;
            if ($currency !== 'ALL') {
                $displayName = $name . ' - ' . $currency;
            }
            if ($nuis) {
                $displayName .= " ({$nuis})";
            }
            
            $vendorData = [
                'DisplayName' => $displayName,
                'CompanyName' => $name,
                'Vendor1099' => false
            ];
            
            // Set currency if not home currency (ALL)
            if ($currency !== 'ALL') {
                $vendorData['CurrencyRef'] = ['value' => $currency];
                error_log("Creating vendor with currency: $currency");
            }
            
            if ($nuis) {
                $vendorData['TaxIdentifier'] = $nuis;
            }
            
            $response = $client->post($baseUrl . '/v3/company/' . $qboCreds['realm_id'] . '/vendor', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $qboCreds['access_token'],
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ],
                'json' => $vendorData
            ]);
            
            $result = json_decode($response->getBody()->getContents(), true);
            $vendorId = (string)($result['Vendor']['Id'] ?? '');
            
            if ($vendorId) {
                // Store vendor mapping with currency-specific key
                $stmt = $this->pdo->prepare("
                    INSERT INTO vendor_mappings (company_id, devpos_nuis, vendor_name, qbo_vendor_id, created_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$companyId, $lookupKey, $name, $vendorId]);
                error_log("Created vendor: $displayName => QBO ID: $vendorId");
            }
            
            return $vendorId;
        }
        
        /**
         * Convert DevPos bill to QuickBooks format
         */
        private function convertDevPosToQBOBill(array $devposBill, string $vendorId): array
        {
            $amount = (float)($devposBill['amount'] ?? $devposBill['total'] ?? $devposBill['totalAmount'] ?? 0);
            
            // Extract bill date - try multiple possible field names
            $billDate = $devposBill['invoiceCreatedDate']  // PRIMARY - actual field from DevPos API
                ?? $devposBill['issueDate']                // SECONDARY fallback
                ?? $devposBill['dateIssued']               // TERTIARY fallback
                ?? $devposBill['date']                     // QUATERNARY fallback
                ?? $devposBill['transactionDate']          // QUINARY fallback
                ?? null;
            
            if (!$billDate) {
                error_log("WARNING: No date found in DevPos bill. Available fields: " . implode(', ', array_keys($devposBill)));
                $billDate = 'now';
            }
            
            $txnDate = date('Y-m-d', strtotime($billDate));
            error_log("Bill date: " . $txnDate . " (from field value: " . ($billDate === 'now' ? 'MISSING' : $billDate) . ")");
            
            // Get currency from bill - try all possible field names
            $currency = $devposBill['currencyCode'] 
                ?? $devposBill['currency'] 
                ?? $devposBill['Currency']
                ?? $devposBill['CurrencyCode']
                ?? $devposBill['currencyType']
                ?? $devposBill['exchangeCurrency']
                ?? 'ALL';
            
            $exchangeRate = $devposBill['exchangeRate'] 
                ?? $devposBill['ExchangeRate']
                ?? $devposBill['rate']
                ?? null;
            
            error_log("convertDevPosToQBOBill: Currency='$currency', ExchangeRate='" . ($exchangeRate ?? 'NULL') . "', Amount=$amount");
            
            // Note: Expense accounts are always in home currency (ALL)
            // QuickBooks handles multi-currency through CurrencyRef + ExchangeRate on the transaction
            $expenseAccountId = $_ENV['QBO_DEFAULT_EXPENSE_ACCOUNT'] ?? '1';
            
            $payload = [
                'VendorRef' => [
                    'value' => $vendorId
                ],
                'Line' => [
                    [
                        'DetailType' => 'AccountBasedExpenseLineDetail',
                        'Amount' => $amount,
                        'AccountBasedExpenseLineDetail' => [
                            'AccountRef' => [
                                'value' => $expenseAccountId // Always home currency account
                            ]
                        ],
                        'Description' => 'Bill from ' . ($devposBill['sellerName'] ?? 'Vendor')
                    ]
                ],
                'DocNumber' => $devposBill['documentNumber'] ?? '',
                'TxnDate' => $txnDate
            ];
            
            // Multi-Currency Support
            // Add currency reference and exchange rate if not home currency
            if ($currency !== 'ALL') {
                error_log("Adding multi-currency to bill: Currency=$currency, ExchangeRate=$exchangeRate");
                
                // Set currency reference
                $payload['CurrencyRef'] = ['value' => strtoupper($currency)];
                
                // Set exchange rate if available
                if ($exchangeRate && $exchangeRate > 0) {
                    $payload['ExchangeRate'] = (float)$exchangeRate;
                    error_log("Bill exchange rate: 1 $currency = $exchangeRate ALL");
                } else {
                    error_log("WARNING: Foreign currency bill ($currency) but no exchange rate provided!");
                }
            } else {
                error_log("Home currency bill (ALL) - no CurrencyRef needed");
            }
            
            return $payload;
        }
        
        /**
         * Store bill mapping
         */
        /**
         * Get bill mapping data for change detection
         */
        private function getBillMapping(int $companyId, string $docNumber, string $vendorNuis): ?array
        {
            $compositeKey = $docNumber . '|' . $vendorNuis;
            
            $stmt = $this->pdo->prepare("
                SELECT qbo_invoice_id, amount, currency 
                FROM invoice_mappings 
                WHERE company_id = ? AND devpos_eic = ? AND transaction_type = 'bill'
            ");
            $stmt->execute([$companyId, $compositeKey]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        }
        
        private function storeBillMapping(
            int $companyId, 
            string $docNumber,
            string $vendorNuis,
            int $qboId, 
            string $qboDocNumber = '',
            float $amount = 0,
            string $vendorName = '',
            string $currency = 'ALL'
        ): void {
            $compositeKey = $docNumber . '|' . $vendorNuis;
            
            $stmt = $this->pdo->prepare("
                INSERT INTO invoice_mappings (
                    company_id, 
                    devpos_eic,
                    devpos_document_number,
                    transaction_type,
                    qbo_invoice_id, 
                    qbo_doc_number,
                    amount,
                    currency,
                    customer_name,
                    synced_at,
                    last_synced_at
                )
                VALUES (?, ?, ?, 'bill', ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE 
                    qbo_invoice_id = ?, 
                    qbo_doc_number = ?,
                    amount = ?,
                    currency = ?,
                    customer_name = ?,
                    last_synced_at = NOW()
            ");
            $stmt->execute([
                $companyId, 
                $compositeKey, // Use composite key as EIC placeholder
                $docNumber,
                $qboId, 
                $qboDocNumber,
                $amount,
                $currency,
                $vendorName,
                $qboId,
                $qboDocNumber,
                $amount,
                $currency,
                $vendorName
            ]);
        }
        
        /**
         * Store invoice mapping
         */
        /**
         * Get invoice mapping data for change detection
         */
        private function getInvoiceMapping(int $companyId, string $eic): ?array
        {
            $stmt = $this->pdo->prepare("
                SELECT qbo_invoice_id, amount, currency 
                FROM invoice_mappings 
                WHERE company_id = ? AND devpos_eic = ?
            ");
            $stmt->execute([$companyId, $eic]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        }
        
        /**
         * Fetch invoice from QuickBooks to get SyncToken and current data
         */
        private function getQBOInvoice(string $invoiceId, array $qboCreds): ?array
        {
            $client = new Client(['http_errors' => false]);
            $isSandbox = (($_ENV['QBO_ENV'] ?? 'production') === 'sandbox');
            $baseUrl = $isSandbox 
                ? 'https://sandbox-quickbooks.api.intuit.com'
                : 'https://quickbooks.api.intuit.com';
            
            $response = $client->get($baseUrl . '/v3/company/' . $qboCreds['realm_id'] . '/invoice/' . $invoiceId, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $qboCreds['access_token'],
                    'Accept' => 'application/json'
                ]
            ]);
            
            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody()->getContents(), true);
                return $data['Invoice'] ?? null;
            }
            
            return null;
        }
        
        /**
         * Fetch bill from QuickBooks to get SyncToken and current data
         */
        private function getQBOBill(string $billId, array $qboCreds): ?array
        {
            $client = new Client(['http_errors' => false]);
            $isSandbox = (($_ENV['QBO_ENV'] ?? 'production') === 'sandbox');
            $baseUrl = $isSandbox 
                ? 'https://sandbox-quickbooks.api.intuit.com'
                : 'https://quickbooks.api.intuit.com';
            
            $response = $client->get($baseUrl . '/v3/company/' . $qboCreds['realm_id'] . '/bill/' . $billId, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $qboCreds['access_token'],
                    'Accept' => 'application/json'
                ]
            ]);
            
            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody()->getContents(), true);
                return $data['Bill'] ?? null;
            }
            
            return null;
        }
        
        /**
         * Update existing invoice in QuickBooks (sparse update)
         */
        private function updateQBOInvoice(string $invoiceId, array $updatePayload, array $qboCreds): array
        {
            $client = new Client();
            $isSandbox = (($_ENV['QBO_ENV'] ?? 'production') === 'sandbox');
            $baseUrl = $isSandbox 
                ? 'https://sandbox-quickbooks.api.intuit.com'
                : 'https://quickbooks.api.intuit.com';
            
            // Fetch current invoice to get SyncToken
            $existingInvoice = $this->getQBOInvoice($invoiceId, $qboCreds);
            if (!$existingInvoice) {
                throw new Exception("Invoice $invoiceId not found in QuickBooks");
            }
            
            // Merge update payload with required fields
            $updatePayload['Id'] = $invoiceId;
            $updatePayload['SyncToken'] = $existingInvoice['SyncToken'];
            $updatePayload['sparse'] = true; // Sparse update - only update provided fields
            
            error_log("Updating invoice $invoiceId in QuickBooks with currency/amount changes");
            error_log("Update payload: " . json_encode($updatePayload, JSON_PRETTY_PRINT));
            
            try {
                $response = $client->post($baseUrl . '/v3/company/' . $qboCreds['realm_id'] . '/invoice', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $qboCreds['access_token'],
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json'
                    ],
                    'json' => $updatePayload
                ]);
                
                $result = json_decode($response->getBody()->getContents(), true);
                error_log("Invoice $invoiceId updated successfully");
                
                return $result;
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                $errorBody = $e->getResponse()->getBody()->getContents();
                $errorData = json_decode($errorBody, true);
                
                $errorDetail = 'QuickBooks API Error';
                if (isset($errorData['Fault']['Error'][0])) {
                    $error = $errorData['Fault']['Error'][0];
                    $errorDetail = $error['Message'] ?? 'Unknown error';
                    if (isset($error['Detail'])) {
                        $errorDetail .= ': ' . $error['Detail'];
                    }
                }
                
                error_log("QuickBooks Invoice Update Failed: " . $errorDetail);
                throw new Exception($errorDetail);
            }
        }
        
        /**
         * Update existing bill in QuickBooks (sparse update)
         */
        private function updateQBOBill(string $billId, array $updatePayload, array $qboCreds): array
        {
            $client = new Client();
            $isSandbox = (($_ENV['QBO_ENV'] ?? 'production') === 'sandbox');
            $baseUrl = $isSandbox 
                ? 'https://sandbox-quickbooks.api.intuit.com'
                : 'https://quickbooks.api.intuit.com';
            
            // Fetch current bill to get SyncToken
            $existingBill = $this->getQBOBill($billId, $qboCreds);
            if (!$existingBill) {
                throw new Exception("Bill $billId not found in QuickBooks");
            }
            
            // Merge update payload with required fields
            $updatePayload['Id'] = $billId;
            $updatePayload['SyncToken'] = $existingBill['SyncToken'];
            $updatePayload['sparse'] = true; // Sparse update - only update provided fields
            
            error_log("Updating bill $billId in QuickBooks with currency/amount changes");
            error_log("Update payload: " . json_encode($updatePayload, JSON_PRETTY_PRINT));
            
            try {
                $response = $client->post($baseUrl . '/v3/company/' . $qboCreds['realm_id'] . '/bill', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $qboCreds['access_token'],
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json'
                    ],
                    'json' => $updatePayload
                ]);
                
                $result = json_decode($response->getBody()->getContents(), true);
                error_log("Bill $billId updated successfully");
                
                return $result;
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                $errorBody = $e->getResponse()->getBody()->getContents();
                $errorData = json_decode($errorBody, true);
                
                $errorDetail = 'QuickBooks API Error';
                if (isset($errorData['Fault']['Error'][0])) {
                    $error = $errorData['Fault']['Error'][0];
                    $errorDetail = $error['Message'] ?? 'Unknown error';
                    if (isset($error['Detail'])) {
                        $errorDetail .= ': ' . $error['Detail'];
                    }
                }
                
                error_log("QuickBooks Bill Update Failed: " . $errorDetail);
                throw new Exception($errorDetail);
            }
        }
        
        private function storeInvoiceMapping(
            int $companyId, 
            string $eic, 
            int $qboId, 
            string $type = 'invoice',
            string $devposDocNumber = '',
            string $qboDocNumber = '',
            float $amount = 0,
            string $customerName = '',
            string $currency = 'ALL'
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
                    currency,
                    customer_name,
                    synced_at,
                    last_synced_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE 
                    qbo_invoice_id = ?, 
                    qbo_doc_number = ?,
                    amount = ?,
                    currency = ?,
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
                $currency,
                $customerName,
                $qboId,
                $qboDocNumber,
                $amount,
                $currency,
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
         * Ensure QuickBooks token is fresh (auto-refresh if needed)
         */
        private function ensureFreshToken(array $qboCreds, int $companyId): array
        {
            // Check if token expires soon (within 10 minutes)
            if (isset($qboCreds['token_expires_at'])) {
                $expiresAt = strtotime($qboCreds['token_expires_at']);
                $now = time();
                $timeRemaining = $expiresAt - $now;
                
                // If more than 10 minutes remaining, token is still good
                if ($timeRemaining > 600) {
                    return $qboCreds;
                }
                
                error_log("QuickBooks token expiring soon for company {$companyId}, refreshing...");
            }
            
            // Token expired or expiring soon, refresh it
            if (!isset($qboCreds['refresh_token']) || empty($qboCreds['refresh_token'])) {
                throw new Exception('No refresh token available. Please reconnect QuickBooks.');
            }
            
            try {
                $client = new Client();
                $tokenResponse = $client->post('https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer', [
                    'form_params' => [
                        'grant_type' => 'refresh_token',
                        'refresh_token' => $qboCreds['refresh_token']
                    ],
                    'headers' => [
                        'Authorization' => 'Basic ' . base64_encode(($_ENV['QBO_CLIENT_ID'] ?? '') . ':' . ($_ENV['QBO_CLIENT_SECRET'] ?? '')),
                        'Content-Type' => 'application/x-www-form-urlencoded'
                    ]
                ]);
                
                $tokens = json_decode($tokenResponse->getBody()->getContents(), true);
                $expiresIn = $tokens['expires_in'] ?? 3600;
                
                // Update tokens in database
                $stmt = $this->pdo->prepare("
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
                
                error_log("QuickBooks token refreshed successfully for company {$companyId}");
                
                // Return updated credentials
                return [
                    'realm_id' => $qboCreds['realm_id'],
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'],
                    'token_expires_at' => date('Y-m-d H:i:s', time() + $expiresIn)
                ];
                
            } catch (Exception $e) {
                error_log("Failed to refresh QuickBooks token for company {$companyId}: " . $e->getMessage());
                throw new Exception('Failed to refresh QuickBooks token. Please reconnect QuickBooks.');
            }
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
        
        /**
         * Get or create QuickBooks customer from DevPos buyer information
         * 
         * @param string $buyerName Buyer/customer name from DevPos
         * @param string|null $buyerNuis Buyer tax ID (NUIS) from DevPos
         * @param int $companyId Company ID
         * @param array $qboCreds QuickBooks credentials
         * @return string QuickBooks customer ID
         */
        private function getOrCreateQBOCustomer(string $buyerName, ?string $buyerNuis, int $companyId, array $qboCreds, string $currency = 'ALL'): string
        {
            // Normalize currency
            $currency = strtoupper($currency);
            
            // For multi-currency, append currency to name for lookup
            $searchName = $buyerName;
            if ($currency !== 'ALL') {
                $searchName = $buyerName . ' - ' . $currency;
            }
            
            // First, check if we have a cached mapping (including currency)
            $customerId = $this->findCachedCustomer($companyId, $searchName, $buyerNuis, $currency);
            if ($customerId) {
                error_log("Found cached customer: $searchName => QBO ID: $customerId");
                return $customerId;
            }
            
            // Search for customer in QuickBooks by name (with currency suffix if applicable)
            $customerId = $this->findQBOCustomerByName($searchName, $qboCreds);
            if (!$customerId && $buyerNuis) {
                // Try by NUIS only if name search failed
                $customerId = $this->findQBOCustomerByNuis($buyerNuis, $qboCreds);
            }
            
            // If not found, create new customer in QuickBooks
            if (!$customerId) {
                $customerId = $this->createQBOCustomer($searchName, $buyerNuis, $qboCreds, $currency);
            }
            
            // Cache the mapping
            if ($customerId) {
                $this->cacheCustomerMapping($companyId, $searchName, $buyerNuis, $customerId);
            }
            
            return $customerId ?: '1'; // Fallback to default customer
        }
        
        /**
         * Find cached customer mapping in database
         */
        private function findCachedCustomer(int $companyId, string $buyerName, ?string $buyerNuis, string $currency = 'ALL'): ?string
        {
            // For multi-currency, we stored the name with currency suffix
            // So search by the full name (which already includes currency if applicable)
            $stmt = $this->pdo->prepare("
                SELECT qbo_customer_id 
                FROM customer_mappings 
                WHERE company_id = ? AND buyer_name = ?
                LIMIT 1
            ");
            $stmt->execute([$companyId, $buyerName]);
            $result = $stmt->fetchColumn();
            
            return $result ?: null;
        }
        
        /**
         * Find QuickBooks customer by NUIS (tax ID)
         */
        private function findQBOCustomerByNuis(?string $buyerNuis, array $qboCreds): ?string
        {
            if (!$buyerNuis) {
                return null;
            }
            
            $client = new Client([
                'timeout' => 30,
                'connect_timeout' => 10
            ]);
            
            $isSandbox = (($_ENV['QBO_ENV'] ?? 'production') === 'sandbox');
            $baseUrl = $isSandbox 
                ? 'https://sandbox-quickbooks.api.intuit.com'
                : 'https://quickbooks.api.intuit.com';
            
            try {
                // Query customers by ResaleNum (tax ID field in QuickBooks)
                $query = "SELECT * FROM Customer WHERE ResaleNum = '{$buyerNuis}'";
                
                $response = $client->get($baseUrl . '/v3/company/' . $qboCreds['realm_id'] . '/query', [
                    'query' => ['query' => $query],
                    'headers' => [
                        'Authorization' => 'Bearer ' . $qboCreds['access_token'],
                        'Accept' => 'application/json'
                    ]
                ]);
                
                $data = json_decode($response->getBody()->getContents(), true);
                
                if (!empty($data['QueryResponse']['Customer'][0]['Id'])) {
                    return $data['QueryResponse']['Customer'][0]['Id'];
                }
            } catch (Exception $e) {
                error_log("Error finding QBO customer by NUIS: " . $e->getMessage());
            }
            
            return null;
        }
        
        /**
         * Find QuickBooks customer by name
         */
        private function findQBOCustomerByName(string $buyerName, array $qboCreds): ?string
        {
            $client = new Client([
                'timeout' => 30,
                'connect_timeout' => 10
            ]);
            
            $isSandbox = (($_ENV['QBO_ENV'] ?? 'production') === 'sandbox');
            $baseUrl = $isSandbox 
                ? 'https://sandbox-quickbooks.api.intuit.com'
                : 'https://quickbooks.api.intuit.com';
            
            try {
                // Escape single quotes in name for query
                $safeName = str_replace("'", "\\'", $buyerName);
                $query = "SELECT * FROM Customer WHERE DisplayName = '{$safeName}'";
                
                $response = $client->get($baseUrl . '/v3/company/' . $qboCreds['realm_id'] . '/query', [
                    'query' => ['query' => $query],
                    'headers' => [
                        'Authorization' => 'Bearer ' . $qboCreds['access_token'],
                        'Accept' => 'application/json'
                    ]
                ]);
                
                $data = json_decode($response->getBody()->getContents(), true);
                
                if (!empty($data['QueryResponse']['Customer'][0]['Id'])) {
                    return $data['QueryResponse']['Customer'][0]['Id'];
                }
            } catch (Exception $e) {
                error_log("Error finding QBO customer by name: " . $e->getMessage());
            }
            
            return null;
        }
        
        /**
         * Create new customer in QuickBooks
         */
        private function createQBOCustomer(string $buyerName, ?string $buyerNuis, array $qboCreds, string $currency = 'ALL'): ?string
        {
            $client = new Client([
                'timeout' => 30,
                'connect_timeout' => 10
            ]);
            
            $isSandbox = (($_ENV['QBO_ENV'] ?? 'production') === 'sandbox');
            $baseUrl = $isSandbox 
                ? 'https://sandbox-quickbooks.api.intuit.com'
                : 'https://quickbooks.api.intuit.com';
            
            // Note: buyerName already has currency suffix appended if needed
            $payload = [
                'DisplayName' => $buyerName
            ];
            
            // Set currency if not home currency (ALL)
            if ($currency !== 'ALL') {
                $payload['CurrencyRef'] = ['value' => $currency];
                error_log("Creating customer with currency: $currency");
            }
            
            // Add tax ID if available
            if ($buyerNuis) {
                $payload['ResaleNum'] = $buyerNuis; // Tax ID field
            }
            
            try {
                error_log("Creating new QBO customer: $buyerName" . ($buyerNuis ? " (NUIS: $buyerNuis)" : ""));
                
                $response = $client->post($baseUrl . '/v3/company/' . $qboCreds['realm_id'] . '/customer', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $qboCreds['access_token'],
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json'
                    ],
                    'json' => $payload
                ]);
                
                $data = json_decode($response->getBody()->getContents(), true);
                
                if (!empty($data['Customer']['Id'])) {
                    $customerId = $data['Customer']['Id'];
                    error_log("✓ Created QBO customer ID: $customerId for $buyerName");
                    return $customerId;
                }
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                $errorBody = $e->getResponse()->getBody()->getContents();
                error_log("QuickBooks Customer Creation Failed: " . $e->getMessage());
                error_log("Error Response: " . $errorBody);
                
                // Check if error is duplicate name
                if (strpos($errorBody, 'already exists') !== false) {
                    // Try to find the existing customer
                    return $this->findQBOCustomerByName($buyerName, $qboCreds);
                }
            } catch (Exception $e) {
                error_log("Error creating QBO customer: " . $e->getMessage());
            }
            
            return null;
        }
        
        /**
         * Cache customer mapping in database
         */
        private function cacheCustomerMapping(int $companyId, string $buyerName, ?string $buyerNuis, string $qboCustomerId): void
        {
            try {
                $stmt = $this->pdo->prepare("
                    INSERT INTO customer_mappings 
                    (company_id, buyer_name, buyer_nuis, qbo_customer_id, last_synced_at)
                    VALUES (?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        qbo_customer_id = VALUES(qbo_customer_id),
                        last_synced_at = NOW()
                ");
                $stmt->execute([$companyId, $buyerName, $buyerNuis, $qboCustomerId]);
                
                error_log("Cached customer mapping: $buyerName → QBO ID $qboCustomerId");
            } catch (Exception $e) {
                error_log("Warning: Failed to cache customer mapping: " . $e->getMessage());
                // Non-fatal error - continue without caching
            }
        }

        /**
         * Get currency-specific income item from QuickBooks (for invoices)
         * Returns item ID that matches the currency, or default item
         */
        private function getCurrencyIncomeItem(string $currency, array $qboCreds, int $companyId): string
        {
            // For home currency, use default item
            if ($currency === 'ALL') {
                return '1'; // Default item
            }
            
            // Check cache first (in-memory for this request)
            static $itemCache = [];
            $cacheKey = $companyId . '_' . $currency;
            
            if (isset($itemCache[$cacheKey])) {
                return $itemCache[$cacheKey];
            }
            
            try {
                $client = new Client(['timeout' => 15]);
                $isSandbox = (($_ENV['QBO_ENV'] ?? 'production') === 'sandbox');
                $baseUrl = $isSandbox 
                    ? 'https://sandbox-quickbooks.api.intuit.com'
                    : 'https://quickbooks.api.intuit.com';
                
                // Query for items with this currency
                // QuickBooks API: Items don't have currency, but we can search by name pattern
                // Look for items like "Services-USD", "Income-EUR", etc.
                $query = "SELECT * FROM Item WHERE Type = 'Service' AND Active = true MAXRESULTS 100";
                
                $response = $client->get($baseUrl . '/v3/company/' . $qboCreds['realm_id'] . '/query', [
                    'query' => ['query' => $query],
                    'headers' => [
                        'Authorization' => 'Bearer ' . $qboCreds['access_token'],
                        'Accept' => 'application/json'
                    ]
                ]);
                
                $data = json_decode($response->getBody()->getContents(), true);
                
                if (!empty($data['QueryResponse']['Item'])) {
                    // Look for item with currency in name (e.g., "Services-USD", "Income EUR")
                    foreach ($data['QueryResponse']['Item'] as $item) {
                        $name = $item['Name'] ?? '';
                        // Check if currency code appears in item name
                        if (stripos($name, $currency) !== false || stripos($name, '-' . $currency) !== false) {
                            $itemId = $item['Id'];
                            $itemCache[$cacheKey] = $itemId;
                            error_log("Found currency-specific item: $name (ID: $itemId) for $currency");
                            return $itemId;
                        }
                    }
                }
                
                // Fallback: Use default item and log warning
                error_log("WARNING: No currency-specific item found for $currency, using default item ID 1");
                $itemCache[$cacheKey] = '1';
                return '1';
                
            } catch (Exception $e) {
                error_log("Error querying QBO items for currency $currency: " . $e->getMessage());
                return '1'; // Fallback to default
            }
        }
        
        /**
         * Get currency-specific expense account from QuickBooks (for bills)
         * Returns account ID that matches the currency, or default account
         */
        private function getCurrencyExpenseAccount(string $currency, array $qboCreds, int $companyId): string
        {
            // For home currency, use default account
            if ($currency === 'ALL') {
                return $_ENV['QBO_DEFAULT_EXPENSE_ACCOUNT'] ?? '1';
            }
            
            // Check cache first
            static $accountCache = [];
            $cacheKey = $companyId . '_' . $currency;
            
            if (isset($accountCache[$cacheKey])) {
                return $accountCache[$cacheKey];
            }
            
            try {
                $client = new Client(['timeout' => 15]);
                $isSandbox = (($_ENV['QBO_ENV'] ?? 'production') === 'sandbox');
                $baseUrl = $isSandbox 
                    ? 'https://sandbox-quickbooks.api.intuit.com'
                    : 'https://quickbooks.api.intuit.com';
                
                // Query for expense accounts with matching currency
                $query = "SELECT * FROM Account WHERE AccountType = 'Expense' AND Active = true MAXRESULTS 100";
                
                $response = $client->get($baseUrl . '/v3/company/' . $qboCreds['realm_id'] . '/query', [
                    'query' => ['query' => $query],
                    'headers' => [
                        'Authorization' => 'Bearer ' . $qboCreds['access_token'],
                        'Accept' => 'application/json'
                    ]
                ]);
                
                $data = json_decode($response->getBody()->getContents(), true);
                
                if (!empty($data['QueryResponse']['Account'])) {
                    // Look for account with matching currency
                    foreach ($data['QueryResponse']['Account'] as $account) {
                        $currencyRef = $account['CurrencyRef']['value'] ?? null;
                        
                        // Direct currency match on account
                        if ($currencyRef === $currency) {
                            $accountId = $account['Id'];
                            $accountName = $account['Name'] ?? $accountId;
                            $accountCache[$cacheKey] = $accountId;
                            error_log("Found currency-specific expense account: $accountName (ID: $accountId) for $currency");
                            return $accountId;
                        }
                    }
                    
                    // Fallback: Look for currency in account name
                    foreach ($data['QueryResponse']['Account'] as $account) {
                        $name = $account['Name'] ?? '';
                    if (stripos($name, $currency) !== false || stripos($name, '-' . $currency) !== false) {
                        $accountId = $account['Id'];
                        $accountCache[$cacheKey] = $accountId;
                        error_log("Found expense account by name pattern: $name (ID: $accountId) for $currency");
                        return $accountId;
                    }
                }
            }
            
            // Fallback: Use default and log warning
            $defaultAccount = $_ENV['QBO_DEFAULT_EXPENSE_ACCOUNT'] ?? '1';
            error_log("WARNING: No currency-specific expense account found for $currency, using default account ID $defaultAccount");
            $accountCache[$cacheKey] = $defaultAccount;
            return $defaultAccount;
            
        } catch (Exception $e) {
            error_log("Error querying QBO accounts for currency $currency: " . $e->getMessage());
            return $_ENV['QBO_DEFAULT_EXPENSE_ACCOUNT'] ?? '1';
        }
    }
    
    /**
     * Fetch invoice detail with PDF from DevPos by EIC
     */
    private function fetchDevPosInvoiceDetail(string $token, string $tenant, string $eic): ?array
    {
        // Debug log helper
        $debugLog = function($msg) {
            $logFile = __DIR__ . '/../../storage/pdf-debug.log';
            @file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] $msg" . PHP_EOL, FILE_APPEND);
            error_log($msg);
        };
        
        $client = new Client([
            'timeout' => 30,
            'http_errors' => false
        ]);
        $apiBase = $_ENV['DEVPOS_API_BASE'] ?? 'https://online.devpos.al/api/v3';
        
        try {
            $url = $apiBase . '/EInvoice';
            
            // Try multiple approaches to fetch invoice
            $attempts = [
                ['method' => 'GET', 'params' => ['query' => ['EIC' => $eic]], 'desc' => 'GET with EIC parameter (uppercase)'],
                ['method' => 'GET', 'params' => ['query' => ['eic' => $eic]], 'desc' => 'GET with eic parameter (lowercase)'],
                ['method' => 'POST', 'params' => ['form_params' => ['EIC' => $eic]], 'desc' => 'POST with EIC form param'],
                ['method' => 'POST', 'params' => ['json' => ['EIC' => $eic]], 'desc' => 'POST with EIC JSON body'],
            ];
            
            $response = null;
            $statusCode = 0;
            
            foreach ($attempts as $attempt) {
                $debugLog("Attempting {$attempt['desc']}...");
                
                $options = array_merge($attempt['params'], [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'tenant' => $tenant,
                        'Accept' => 'application/json'
                    ]
                ]);
                
                $response = $client->request($attempt['method'], $url, $options);
                $statusCode = $response->getStatusCode();
                $debugLog("Response: HTTP $statusCode");
                
                // If success, break
                if ($statusCode >= 200 && $statusCode < 300) {
                    $debugLog("✓ Success with: {$attempt['desc']}");
                    break;
                }
                
                // If not method error, break (might be auth or other issue)
                if (!in_array($statusCode, [405, 415])) {
                    break;
                }
            }
            
            if ($statusCode >= 200 && $statusCode < 300) {
                $body = $response->getBody()->getContents();
                $debugLog("Response body length: " . strlen($body) . " bytes");
                
                $data = json_decode($body, true);
                
                if ($data === null) {
                    $debugLog("ERROR: Failed to decode JSON response");
                    $debugLog("Response preview: " . substr($body, 0, 200));
                    return null;
                }
                
                // API may return array with single item or the object directly
                if (is_array($data)) {
                    $result = isset($data[0]) && is_array($data[0]) ? $data[0] : $data;
                    $debugLog("Parsed invoice data with " . count($result) . " fields");
                    return $result;
                }
            }
            
            $body = $response->getBody()->getContents();
            $debugLog("Failed to fetch invoice: HTTP $statusCode - " . substr($body, 0, 200));
            return null;
            
        } catch (Exception $e) {
            $debugLog("Exception fetching invoice detail: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Upload PDF attachment to QuickBooks using Guzzle's native multipart
     */
    private function uploadPDFToQBO(
        string $entityType,
        string $entityId,
        string $filename,
        string $pdfBinary,
        array $qboCreds
    ): bool {
        $client = new Client(['timeout' => 30]);
        $isSandbox = (($_ENV['QBO_ENV'] ?? 'production') === 'sandbox');
        $baseUrl = $isSandbox 
            ? 'https://sandbox-quickbooks.api.intuit.com'
            : 'https://quickbooks.api.intuit.com';
        
        try {
            // Use Guzzle's native multipart support - more reliable
            $response = $client->post($baseUrl . '/v3/company/' . $qboCreds['realm_id'] . '/upload', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $qboCreds['access_token'],
                    'Accept' => 'application/json'
                    // Don't set Content-Type - Guzzle will set it with boundary
                ],
                'multipart' => [
                    [
                        'name' => 'file_metadata_0',
                        'contents' => json_encode([
                            'AttachableRef' => [
                                [
                                    'EntityRef' => [
                                        'type' => $entityType,
                                        'value' => $entityId
                                    ],
                                    'IncludeOnSend' => false
                                ]
                            ],
                            'FileName' => $filename,
                            'ContentType' => 'application/pdf'
                        ]),
                        'headers' => [
                            'Content-Type' => 'application/json'
                        ]
                    ],
                    [
                        'name' => 'file_content_0',
                        'contents' => $pdfBinary,
                        'filename' => $filename,
                        'headers' => [
                            'Content-Type' => 'application/pdf'
                        ]
                    ]
                ]
            ]);
            
            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                $msg = "✓ PDF attachment uploaded: $filename to $entityType $entityId";
                error_log($msg);
                // Also write to debug log
                $logFile = __DIR__ . '/../../storage/pdf-debug.log';
                @file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] $msg" . PHP_EOL, FILE_APPEND);
                return true;
            }
            
            $responseBody = $response->getBody()->getContents();
            $msg = "Failed to upload PDF attachment: HTTP " . $response->getStatusCode() . " - Response: " . substr($responseBody, 0, 500);
            error_log($msg);
            $logFile = __DIR__ . '/../../storage/pdf-debug.log';
            @file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] $msg" . PHP_EOL, FILE_APPEND);
            return false;
            
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $msg = "Error uploading PDF attachment: " . $e->getMessage();
            error_log($msg);
            $logFile = __DIR__ . '/../../storage/pdf-debug.log';
            @file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] $msg" . PHP_EOL, FILE_APPEND);
            
            $responseBody = $e->getResponse()->getBody()->getContents();
            $errMsg = "QBO API Error Response: " . substr($responseBody, 0, 500);
            error_log($errMsg);
            @file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] $errMsg" . PHP_EOL, FILE_APPEND);
            
            return false;
        } catch (Exception $e) {
            $msg = "Error uploading PDF attachment: " . $e->getMessage();
            error_log($msg);
            $logFile = __DIR__ . '/../../storage/pdf-debug.log';
            @file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] $msg" . PHP_EOL, FILE_APPEND);
            return false;
        }
    }

    /**
     * Attach PDF to invoice/bill if available from DevPos
     */
    private function attachPDFIfAvailable(
        array $document,
        string $entityType,
        string $entityId,
        string $token,
        string $tenant,
        array $qboCreds
    ): void {
        // Debug log helper
        $debugLog = function($msg) {
            $logFile = __DIR__ . '/../../storage/pdf-debug.log';
            $logDir = dirname($logFile);
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            $timestamp = date('Y-m-d H:i:s');
            @file_put_contents($logFile, "[$timestamp] $msg" . PHP_EOL, FILE_APPEND);
            error_log($msg);
        };
        
        // Check if PDF is already in document data
        $pdfB64 = $document['pdf'] ?? null;
        $pdfUrl = $document['pdfUrl'] ?? $document['pdf_url'] ?? $document['pdfLink'] ?? null;
        $eic = $document['eic'] ?? $document['EIC'] ?? null;
        
        // DEBUG: Log all available fields in document
        $debugLog("Available document fields: " . implode(', ', array_keys($document)));
        
        // Construct PDF URL from EIC if not explicitly provided
        // Try multiple possible PDF endpoint patterns
        if (!$pdfUrl && $eic) {
            $possibleUrls = [
                'https://online.devpos.al/api/v3/EInvoice/' . $eic . '/pdf',
                'https://online.devpos.al/api/v3/EInvoice/' . $eic . '/download',
                'https://online.devpos.al/' . $eic . '/pdf',
                'https://online.devpos.al/' . $eic . '/download',
                'https://online.devpos.al/' . $eic, // This returns HTML page!
            ];
            $pdfUrl = $possibleUrls;
        }
        
        // Remove blob: prefix if present (blob URLs are for browser only)
        if (is_string($pdfUrl) && strpos($pdfUrl, 'blob:') === 0) {
            $pdfUrl = substr($pdfUrl, 5); // Remove "blob:" prefix
        }
        
        $debugLog("Checking PDF for $entityType $entityId - EIC: " . ($eic ?? 'null') . ", Has PDF: " . ($pdfB64 ? 'YES' : 'NO'));
        
        // If we have a PDF URL (or array of URLs to try), download and validate it
        if (!$pdfB64 && $pdfUrl) {
            $urls = is_array($pdfUrl) ? $pdfUrl : [$pdfUrl];
            
            foreach ($urls as $tryUrl) {
                $debugLog("Trying PDF URL: $tryUrl");  
                try {
                    $client = new \GuzzleHttp\Client(['http_errors' => false]);
                    $response = $client->get($tryUrl, [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $token,
                            'tenant' => $tenant,
                        ]
                    ]);
                    
                    $statusCode = $response->getStatusCode();
                    if ($statusCode === 200) {
                        $pdfBinary = $response->getBody()->getContents();
                        if ($pdfBinary && strlen($pdfBinary) > 0) {
                            // Validate that it's actually a PDF (should start with %PDF)
                            $header = substr($pdfBinary, 0, 4);
                            $contentType = $response->getHeaderLine('Content-Type');
                            
                            $debugLog("  HTTP $statusCode - Downloaded " . strlen($pdfBinary) . " bytes, Content-Type: $contentType");
                            $debugLog("  First 4 bytes: " . bin2hex($header) . " (should be 25504446 for %PDF)");
                            
                            if ($header === '%PDF') {
                                $pdfB64 = base64_encode($pdfBinary);
                                $debugLog("✓ SUCCESS: Valid PDF from $tryUrl (" . strlen($pdfBinary) . " bytes)");

                                // Persist Base64 string for inspection/debugging
                                try {
                                    $dumpDir = __DIR__ . '/../../storage/pdf-b64';
                                    if (!is_dir($dumpDir)) {
                                        @mkdir($dumpDir, 0755, true);
                                    }
                                    $dumpPath = $dumpDir . '/' . preg_replace('/[^a-z0-9_\-]/i', '_', $entityType . '-' . $entityId) . '.b64';
                                    @file_put_contents($dumpPath, $pdfB64);
                                    $debugLog("  Saved Base64 PDF to: $dumpPath");
                                } catch (\Exception $e) {
                                    $debugLog("  Failed to write Base64 dump: " . $e->getMessage());
                                }
                                
                                break; // Stop trying other URLs, we got a valid PDF

                            } else {
                                $debugLog("  ❌ NOT a PDF - Content starts with: " . substr($pdfBinary, 0, 50));
                                // Try next URL
                            }
                        } else {
                            $debugLog("  Empty response");
                        }
                    } else {
                        $debugLog("  HTTP $statusCode - skipping");
                    }
                } catch (\Exception $e) {
                    $debugLog("  Error: " . $e->getMessage());
                }
            }
            
            if (!$pdfB64) {
                $debugLog("❌ FAILED: Could not download valid PDF from any URL");
            }
        }
        
        // Upload PDF if available
        if ($pdfB64) {
            $pdfBinary = base64_decode($pdfB64);
            
            if ($pdfBinary !== false && strlen($pdfBinary) > 0) {
                $docNumber = $document['documentNumber'] ?? $document['doc_no'] ?? $entityId;
                $filename = $docNumber . '.pdf';
                
                $debugLog("Uploading PDF: $filename (" . strlen($pdfBinary) . " bytes)");
                $this->uploadPDFToQBO($entityType, $entityId, $filename, $pdfBinary, $qboCreds);
            } else {
                $debugLog("Warning: PDF base64 decode failed or empty");
            }
        } else {
            $debugLog("No PDF available for $entityType $entityId");
        }
    }
}
