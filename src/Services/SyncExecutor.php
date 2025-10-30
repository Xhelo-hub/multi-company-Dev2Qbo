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
    private function syncBills(array $job): array
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
            $billId = $bill['documentNumber'] ?? $bill['eic'] ?? 'unknown';
            $progress = ($index + 1) . "/$totalBills";
            
            try {
                error_log("[$progress] Processing bill $billId...");
                
                // Check if amount is valid
                $amount = (float)($bill['amount'] ?? $bill['total'] ?? $bill['totalAmount'] ?? 0);
                if ($amount <= 0) {
                    error_log("[$progress] Skipping bill $billId: Invalid amount");
                    $skipped++;
                    continue;
                }
                
                // Check for duplicate
                $eic = $bill['eic'] ?? $bill['EIC'] ?? null;
                $docNumber = $bill['documentNumber'] ?? $bill['id'] ?? null;
                
                if (!$docNumber) {
                    error_log("[$progress] Skipping bill $billId: No document number");
                    $skipped++;
                    continue;
                }
                
                // Check if bill already exists in QuickBooks (not just local mapping)
                $existingBillId = $this->findBillByDocNumber($companyId, $docNumber);
                if ($existingBillId) {
                    // Verify it actually exists in QuickBooks
                    $billExistsInQBO = $this->verifyBillExistsInQBO($existingBillId, $qboCreds);
                    if ($billExistsInQBO) {
                        error_log("[$progress] Skipping bill $billId: Already exists in QuickBooks");
                        $skipped++;
                        continue;
                    } else {
                        // Bill was deleted from QuickBooks or mapping is stale, remove mapping
                        error_log("[$progress] Removing stale mapping for bill $billId");
                        $this->removeBillMapping($companyId, $docNumber);
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
                    'toDate' => $toDate
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
                    'toDate' => $toDate
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
            // Verify it actually exists in QuickBooks
            $invoiceExistsInQBO = $this->verifyInvoiceExistsInQBO($existingId, $qboCreds);
            if ($invoiceExistsInQBO) {
                // Invoice already synced and exists in QBO, skip
                return;
            } else {
                // Invoice was deleted from QuickBooks or mapping is stale, remove mapping
                $this->removeInvoiceMapping($companyId, $eic);
            }
        }
        
        // Create invoice in QuickBooks
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
                    (float)($invoice['totalAmount'] ?? $invoice['amount'] ?? 0),
                    $invoice['buyerName'] ?? ''
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
        
        // Get or create customer in QuickBooks
        $customerId = $this->getOrCreateQBOCustomer($buyerName, $buyerNuis, $companyId, $qboCreds);

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
                                'value' => '1', // Default sales item (must exist in QBO)
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
                                'value' => '1', // Default sales item (must exist in QBO)
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
    private function verifyBillExistsInQBO(int $billId, array $qboCreds): bool
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
        
        // Get or create vendor
        $vendorId = $this->getOrCreateVendor(
            $bill['sellerNuis'] ?? '',
            $bill['sellerName'] ?? 'Unknown Vendor',
            $qboCreds,
            $companyId
        );
        
        // Convert bill to QBO format
        $qboBill = $this->convertDevPosToQBOBill($bill, $vendorId);
        
        // Create bill in QuickBooks
        $response = $client->post($baseUrl . '/v3/company/' . $qboCreds['realm_id'] . '/bill', [
            'headers' => [
                'Authorization' => 'Bearer ' . $qboCreds['access_token'],
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ],
            'json' => $qboBill
        ]);
        
        $result = json_decode($response->getBody()->getContents(), true);
        
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
                $bill['sellerName'] ?? ''
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
    private function getOrCreateVendor(string $nuis, string $name, array $qboCreds, int $companyId): string
    {
        // Check if vendor already exists in mappings
        $stmt = $this->pdo->prepare("
            SELECT qbo_vendor_id 
            FROM vendor_mappings 
            WHERE company_id = ? AND devpos_nuis = ?
        ");
        $stmt->execute([$companyId, $nuis]);
        $existingId = $stmt->fetchColumn();
        
        if ($existingId) {
            return (string)$existingId;
        }
        
        // Create new vendor in QuickBooks
        $client = new Client();
        $isSandbox = (($_ENV['QBO_ENV'] ?? 'production') === 'sandbox');
        $baseUrl = $isSandbox 
            ? 'https://sandbox-quickbooks.api.intuit.com'
            : 'https://quickbooks.api.intuit.com';
        
        $vendorData = [
            'DisplayName' => $name . ($nuis ? " ({$nuis})" : ''),
            'CompanyName' => $name,
            'Vendor1099' => false
        ];
        
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
            // Store vendor mapping
            $stmt = $this->pdo->prepare("
                INSERT INTO vendor_mappings (company_id, devpos_nuis, vendor_name, qbo_vendor_id, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$companyId, $nuis, $name, $vendorId]);
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
        
        return [
            'VendorRef' => [
                'value' => $vendorId
            ],
            'Line' => [
                [
                    'DetailType' => 'AccountBasedExpenseLineDetail',
                    'Amount' => $amount,
                    'AccountBasedExpenseLineDetail' => [
                        'AccountRef' => [
                            'value' => $_ENV['QBO_DEFAULT_EXPENSE_ACCOUNT'] ?? '1' // Should be configured
                        ]
                    ],
                    'Description' => 'Bill from ' . ($devposBill['sellerName'] ?? 'Vendor')
                ]
            ],
            'DocNumber' => $devposBill['documentNumber'] ?? '',
            'TxnDate' => $txnDate
        ];
    }
    
    /**
     * Store bill mapping
     */
    private function storeBillMapping(
        int $companyId, 
        string $docNumber,
        string $vendorNuis,
        int $qboId, 
        string $qboDocNumber = '',
        float $amount = 0,
        string $vendorName = ''
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
                customer_name,
                synced_at,
                last_synced_at
            )
            VALUES (?, ?, ?, 'bill', ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
                qbo_invoice_id = ?, 
                qbo_doc_number = ?,
                amount = ?,
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
            $vendorName,
            $qboId,
            $qboDocNumber,
            $amount,
            $vendorName
        ]);
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
    private function getOrCreateQBOCustomer(string $buyerName, ?string $buyerNuis, int $companyId, array $qboCreds): string
    {
        // First, check if we have a cached mapping
        $customerId = $this->findCachedCustomer($companyId, $buyerName, $buyerNuis);
        if ($customerId) {
            return $customerId;
        }
        
        // Search for customer in QuickBooks by NUIS or name
        $customerId = $this->findQBOCustomerByNuis($buyerNuis, $qboCreds);
        if (!$customerId) {
            $customerId = $this->findQBOCustomerByName($buyerName, $qboCreds);
        }
        
        // If not found, create new customer in QuickBooks
        if (!$customerId) {
            $customerId = $this->createQBOCustomer($buyerName, $buyerNuis, $qboCreds);
        }
        
        // Cache the mapping
        if ($customerId) {
            $this->cacheCustomerMapping($companyId, $buyerName, $buyerNuis, $customerId);
        }
        
        return $customerId ?: '1'; // Fallback to default customer
    }
    
    /**
     * Find cached customer mapping in database
     */
    private function findCachedCustomer(int $companyId, string $buyerName, ?string $buyerNuis): ?string
    {
        if ($buyerNuis) {
            // Try lookup by NUIS first (most reliable)
            $stmt = $this->pdo->prepare("
                SELECT qbo_customer_id 
                FROM customer_mappings 
                WHERE company_id = ? AND buyer_nuis = ?
                LIMIT 1
            ");
            $stmt->execute([$companyId, $buyerNuis]);
            $result = $stmt->fetchColumn();
            if ($result) {
                return $result;
            }
        }
        
        // Fallback to name lookup
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
    private function createQBOCustomer(string $buyerName, ?string $buyerNuis, array $qboCreds): ?string
    {
        $client = new Client([
            'timeout' => 30,
            'connect_timeout' => 10
        ]);
        
        $isSandbox = (($_ENV['QBO_ENV'] ?? 'production') === 'sandbox');
        $baseUrl = $isSandbox 
            ? 'https://sandbox-quickbooks.api.intuit.com'
            : 'https://quickbooks.api.intuit.com';
        
        $payload = [
            'DisplayName' => $buyerName
        ];
        
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
     * Fetch invoice detail with PDF from DevPos by EIC
     */
    private function fetchDevPosInvoiceDetail(string $token, string $tenant, string $eic): ?array
    {
        $client = new Client([
            'timeout' => 30,
            'http_errors' => false
        ]);
        $apiBase = $_ENV['DEVPOS_API_BASE'] ?? 'https://online.devpos.al/api/v3';
        
        try {
            // Try GET with query parameter first
            $response = $client->get($apiBase . '/EInvoice', [
                'query' => ['EIC' => $eic],
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'tenant' => $tenant,
                    'Accept' => 'application/json'
                ]
            ]);
            
            // If 405/415 Method Not Allowed, try POST with form params
            if (in_array($response->getStatusCode(), [405, 415])) {
                $response = $client->post($apiBase . '/EInvoice', [
                    'form_params' => ['EIC' => $eic],
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'tenant' => $tenant,
                        'Accept' => 'application/json'
                    ]
                ]);
            }
            
            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                $body = $response->getBody()->getContents();
                $data = json_decode($body, true);
                
                // API may return array with single item or the object directly
                if (is_array($data)) {
                    return isset($data[0]) && is_array($data[0]) ? $data[0] : $data;
                }
            }
            
            error_log("Failed to fetch invoice detail for EIC $eic: HTTP " . $response->getStatusCode());
            return null;
            
        } catch (Exception $e) {
            error_log("Error fetching invoice detail for EIC $eic: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Upload PDF attachment to QuickBooks
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
            $boundary = uniqid('boundary_');
            $eol = "\r\n";
            
            // Build multipart body
            $body = "--{$boundary}{$eol}";
            $body .= "Content-Disposition: form-data; name=\"file_metadata_0\"{$eol}";
            $body .= "Content-Type: application/json{$eol}{$eol}";
            $body .= json_encode([
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
            ]);
            $body .= "{$eol}--{$boundary}{$eol}";
            $body .= "Content-Disposition: form-data; name=\"file_content_0\"; filename=\"{$filename}\"{$eol}";
            $body .= "Content-Type: application/pdf{$eol}{$eol}";
            $body .= $pdfBinary;
            $body .= "{$eol}--{$boundary}--{$eol}";
            
            $response = $client->post($baseUrl . '/v3/company/' . $qboCreds['realm_id'] . '/upload', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $qboCreds['access_token'],
                    'Accept' => 'application/json',
                    'Content-Type' => "multipart/form-data; boundary={$boundary}"
                ],
                'body' => $body
            ]);
            
            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                error_log("✓ PDF attachment uploaded: $filename to $entityType $entityId");
                return true;
            }
            
            error_log("Failed to upload PDF attachment: HTTP " . $response->getStatusCode());
            return false;
            
        } catch (Exception $e) {
            error_log("Error uploading PDF attachment: " . $e->getMessage());
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
        // Check if PDF is already in document data
        $pdfB64 = $document['pdf'] ?? null;
        $eic = $document['eic'] ?? $document['EIC'] ?? null;
        
        error_log("Checking PDF for $entityType $entityId - EIC: " . ($eic ?? 'null') . ", Has PDF in data: " . ($pdfB64 ? 'YES' : 'NO'));
        
        // If no PDF in initial data and we have EIC, fetch full invoice detail
        if (!$pdfB64 && $eic) {
            error_log("Fetching invoice detail from DevPos for EIC: $eic");
            $detail = $this->fetchDevPosInvoiceDetail($token, $tenant, $eic);
            $pdfB64 = $detail['pdf'] ?? null;
            error_log("PDF from DevPos API: " . ($pdfB64 ? 'YES (' . strlen($pdfB64) . ' chars)' : 'NO'));
        }
        
        // Upload PDF if available
        if ($pdfB64) {
            $pdfBinary = base64_decode($pdfB64);
            
            if ($pdfBinary !== false && strlen($pdfBinary) > 0) {
                $docNumber = $document['documentNumber'] ?? $document['doc_no'] ?? $entityId;
                $filename = $docNumber . '.pdf';
                
                error_log("Uploading PDF: $filename (" . strlen($pdfBinary) . " bytes)");
                $this->uploadPDFToQBO($entityType, $entityId, $filename, $pdfBinary, $qboCreds);
            } else {
                error_log("Warning: PDF base64 decode failed or empty");
            }
        } else {
            error_log("No PDF available for $entityType $entityId");
        }
    }
}
