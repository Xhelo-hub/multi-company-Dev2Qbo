<?php

declare(strict_types=1);

namespace App\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * QuickBooks Online API Client
 */
class QboClient
{
    private Client $httpClient;
    private string $baseUrl;
    private string $accessToken;
    private string $realmId;
    
    /**
     * @param string $accessToken OAuth access token
     * @param string $realmId QuickBooks company/realm ID
     * @param bool $isSandbox Whether to use sandbox environment
     */
    public function __construct(string $accessToken, string $realmId, bool $isSandbox = false)
    {
        $this->accessToken = $accessToken;
        $this->realmId = $realmId;
        $this->baseUrl = $isSandbox
            ? 'https://sandbox-quickbooks.api.intuit.com'
            : 'https://quickbooks.api.intuit.com';
        $this->httpClient = new Client([
            'timeout' => 30,  // 30 second timeout for API calls
            'connect_timeout' => 10  // 10 second connection timeout
        ]);
    }
    
    /**
     * Create an invoice in QuickBooks
     * 
     * @param array $invoiceData Invoice data in QBO format
     * @return array QuickBooks API response
     * @throws GuzzleException
     */
    public function createInvoice(array $invoiceData): array
    {
        // Log what we're sending to QuickBooks
        error_log("DEBUG: Sending to QBO Invoice API - TxnDate: " . ($invoiceData['TxnDate'] ?? 'NOT SET'));
        error_log("DEBUG: Full Invoice payload: " . json_encode($invoiceData));
        
        $response = $this->httpClient->post(
            "{$this->baseUrl}/v3/company/{$this->realmId}/invoice",
            [
                'headers' => [
                    'Authorization' => "Bearer {$this->accessToken}",
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ],
                'json' => $invoiceData
            ]
        );
        
        $result = json_decode($response->getBody()->getContents(), true);
        
        // Log what QuickBooks returned
        if (isset($result['Invoice']['TxnDate'])) {
            error_log("DEBUG: QBO returned Invoice with TxnDate: " . $result['Invoice']['TxnDate']);
        }
        
        return $result;
    }
    
    /**
     * Create a sales receipt in QuickBooks
     * 
     * @param array $receiptData Sales receipt data in QBO format
     * @return array QuickBooks API response
     * @throws GuzzleException
     */
    public function createSalesReceipt(array $receiptData): array
    {
        // Log what we're sending to QuickBooks
        error_log("DEBUG: Sending to QBO SalesReceipt API - TxnDate: " . ($receiptData['TxnDate'] ?? 'NOT SET'));
        
        $response = $this->httpClient->post(
            "{$this->baseUrl}/v3/company/{$this->realmId}/salesreceipt",
            [
                'headers' => [
                    'Authorization' => "Bearer {$this->accessToken}",
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ],
                'json' => $receiptData
            ]
        );
        
        $result = json_decode($response->getBody()->getContents(), true);
        
        // Log what QuickBooks returned
        if (isset($result['SalesReceipt']['TxnDate'])) {
            error_log("DEBUG: QBO returned SalesReceipt with TxnDate: " . $result['SalesReceipt']['TxnDate']);
        }
        
        return $result;
    }
    
    /**
     * Create a bill in QuickBooks
     * 
     * @param array $billData Bill data in QBO format
     * @return array QuickBooks API response
     * @throws GuzzleException
     */
    public function createBill(array $billData): array
    {
        // Log what we're sending to QuickBooks
        error_log("DEBUG: Sending to QBO Bill API - TxnDate: " . ($billData['TxnDate'] ?? 'NOT SET'));
        
        $response = $this->httpClient->post(
            "{$this->baseUrl}/v3/company/{$this->realmId}/bill",
            [
                'headers' => [
                    'Authorization' => "Bearer {$this->accessToken}",
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ],
                'json' => $billData
            ]
        );
        
        $result = json_decode($response->getBody()->getContents(), true);
        
        // Log what QuickBooks returned
        if (isset($result['Bill']['TxnDate'])) {
            error_log("DEBUG: QBO returned Bill with TxnDate: " . $result['Bill']['TxnDate']);
        }
        
        return $result;
    }
    
    /**
     * Create a vendor in QuickBooks
     * 
     * @param array $vendorData Vendor data in QBO format
     * @return array QuickBooks API response
     * @throws GuzzleException
     */
    public function createVendor(array $vendorData): array
    {
        $response = $this->httpClient->post(
            "{$this->baseUrl}/v3/company/{$this->realmId}/vendor",
            [
                'headers' => [
                    'Authorization' => "Bearer {$this->accessToken}",
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ],
                'json' => $vendorData
            ]
        );
        
        return json_decode($response->getBody()->getContents(), true);
    }
    
    /**
     * Upload an attachment to QuickBooks
     * 
     * @param string $entityType Entity type (e.g., 'Invoice', 'Bill', 'SalesReceipt')
     * @param string $entityId QuickBooks entity ID
     * @param string $filename Filename for the attachment
     * @param string $fileContent File content (binary)
     * @param bool $includeOnSend Whether to include attachment when sending entity
     * @return array QuickBooks API response
     * @throws GuzzleException
     */
    public function uploadAttachment(
        string $entityType,
        string $entityId,
        string $filename,
        string $fileContent,
        bool $includeOnSend = false
    ): array {
        $boundary = uniqid();
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
                    'IncludeOnSend' => $includeOnSend
                ]
            ],
            'FileName' => $filename,
            'ContentType' => 'application/pdf'
        ]);
        $body .= "{$eol}--{$boundary}{$eol}";
        $body .= "Content-Disposition: form-data; name=\"file_content_0\"; filename=\"{$filename}\"{$eol}";
        $body .= "Content-Type: application/pdf{$eol}{$eol}";
        $body .= $fileContent;
        $body .= "{$eol}--{$boundary}--{$eol}";
        
        $response = $this->httpClient->post(
            "{$this->baseUrl}/v3/company/{$this->realmId}/upload",
            [
                'headers' => [
                    'Authorization' => "Bearer {$this->accessToken}",
                    'Accept' => 'application/json',
                    'Content-Type' => "multipart/form-data; boundary={$boundary}"
                ],
                'body' => $body
            ]
        );
        
        return json_decode($response->getBody()->getContents(), true);
    }
}
