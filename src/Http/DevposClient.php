<?php

declare(strict_types=1);

namespace App\Http;

use GuzzleHttp\Client;
use PDO;
use RuntimeException;

/**
 * DevPos API Client (Multi-Company Version)
 * Handles authentication and API requests to DevPos
 */
class DevposClient
{
    private Client $http;
    private PDO $pdo;
    private int $companyId;
    private ?array $cachedToken = null;

    public function __construct(PDO $pdo, int $companyId)
    {
        $this->http = new Client([
            'http_errors' => false,
            'timeout' => 45,
            'verify' => false
        ]);
        $this->pdo = $pdo;
        $this->companyId = $companyId;
    }

    /**
     * Get API base URL from environment
     */
    private function base(): string
    {
        $base = rtrim($_ENV['DEVPOS_API_BASE'] ?? '', '/');
        if ($base === '') {
            throw new RuntimeException('DEVPOS_API_BASE not set in .env');
        }
        return $base;
    }

    /**
     * Get company credentials from database
     */
    private function getCredentials(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT tenant, username, password_encrypted
            FROM company_credentials_devpos
            WHERE company_id = ?
        ");
        $stmt->execute([$this->companyId]);
        $creds = $stmt->fetch();

        if (!$creds) {
            throw new RuntimeException("No DevPos credentials found for company {$this->companyId}");
        }

        // Decrypt password
        $encryptionKey = $_ENV['ENCRYPTION_KEY'] ?? '';
        if (empty($encryptionKey)) {
            throw new RuntimeException('ENCRYPTION_KEY not set in .env');
        }

        $password = openssl_decrypt(
            $creds['password_encrypted'],
            'AES-256-CBC',
            base64_decode($encryptionKey),
            0,
            substr(hash('sha256', $encryptionKey, true), 0, 16)
        );

        if ($password === false) {
            throw new RuntimeException('Failed to decrypt DevPos password');
        }

        return [
            'tenant' => $creds['tenant'],
            'username' => $creds['username'],
            'password' => $password
        ];
    }

    /**
     * Ensure we have a valid access token (fetch new if expired)
     */
    private function ensureAccessToken(): array
    {
        // Check if we have a cached token
        if ($this->cachedToken && $this->cachedToken['expires_at'] > time() + 60) {
            return $this->cachedToken;
        }

        // Check database for existing token
        $stmt = $this->pdo->prepare("
            SELECT access_token, token_type, expires_at
            FROM oauth_tokens_devpos
            WHERE company_id = ? AND expires_at > NOW()
        ");
        $stmt->execute([$this->companyId]);
        $token = $stmt->fetch();

        if ($token && strtotime($token['expires_at']) > time() + 60) {
            $this->cachedToken = [
                'access_token' => $token['access_token'],
                'token_type' => $token['token_type'] ?? 'Bearer',
                'expires_at' => strtotime($token['expires_at'])
            ];
            return $this->cachedToken;
        }

        // Fetch new token
        return $this->fetchNewToken();
    }

    /**
     * Fetch a new access token from DevPos
     */
    private function fetchNewToken(): array
    {
        $creds = $this->getCredentials();
        $tokenUrl = $_ENV['DEVPOS_TOKEN_URL'] ?? 'https://online.devpos.al/connect/token';
        $authBasic = $_ENV['DEVPOS_AUTH_BASIC'] ?? 'Zmlza2FsaXppbWlfc3BhOg==';

        $response = $this->http->post($tokenUrl, [
            'form_params' => [
                'grant_type' => 'password',
                'username' => $creds['username'],
                'password' => $creds['password'],
            ],
            'headers' => [
                'Authorization' => 'Basic ' . $authBasic,
                'tenant' => $creds['tenant'],
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json'
            ]
        ]);

        $status = $response->getStatusCode();
        $body = $response->getBody()->getContents();

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("DevPos token error: {$status} - {$body}");
        }

        $data = json_decode($body, true);
        if (empty($data['access_token'])) {
            throw new RuntimeException("No access token in response: {$body}");
        }

        $expiresAt = time() + (int)($data['expires_in'] ?? 28800);

        // Save token to database
        $stmt = $this->pdo->prepare("
            INSERT INTO oauth_tokens_devpos (company_id, access_token, token_type, expires_at)
            VALUES (?, ?, ?, FROM_UNIXTIME(?))
            ON DUPLICATE KEY UPDATE
                access_token = VALUES(access_token),
                token_type = VALUES(token_type),
                expires_at = VALUES(expires_at)
        ");
        $stmt->execute([
            $this->companyId,
            $data['access_token'],
            $data['token_type'] ?? 'Bearer',
            $expiresAt
        ]);

        $this->cachedToken = [
            'access_token' => $data['access_token'],
            'token_type' => $data['token_type'] ?? 'Bearer',
            'expires_at' => $expiresAt
        ];

        return $this->cachedToken;
    }

    /**
     * Make an authenticated request to DevPos API
     */
    private function request(string $method, string $path, array $options = [])
    {
        $token = $this->ensureAccessToken();
        $creds = $this->getCredentials();
        $url = $this->base() . '/' . ltrim($path, '/');

        $options['headers'] = array_merge([
            'Authorization' => $token['token_type'] . ' ' . $token['access_token'],
            'tenant' => $creds['tenant'],
            'Accept' => 'application/json'
        ], $options['headers'] ?? []);

        return $this->http->request($method, $url, $options);
    }

    /**
     * Decode response or throw exception
     */
    private function decodeOrThrow($response, string $context): array
    {
        $status = $response->getStatusCode();
        $body = $response->getBody()->getContents();

        if ($status >= 200 && $status < 300) {
            $json = json_decode($body, true);
            if (!is_array($json)) {
                throw new RuntimeException("{$context}: Invalid JSON - {$body}");
            }
            return $json;
        }

        throw new RuntimeException("{$context}: HTTP {$status} - {$body}");
    }

    /**
     * Fetch Sales E-Invoices
     */
    public function fetchSalesEInvoices(string $fromIso, string $toIso): array
    {
        // DevPos expects YYYY-MM-DD format (not full ISO 8601)
        $fromDate = substr($fromIso, 0, 10);
        $toDate = substr($toIso, 0, 10);

        $response = $this->request('GET', 'EInvoice/GetSalesInvoice', [
            'query' => [
                'fromDate' => $fromDate,
                'toDate' => $toDate
            ]
        ]);

        return $this->decodeOrThrow($response, 'Fetch Sales E-Invoices');
    }

    /**
     * Fetch Purchase E-Invoices
     */
    public function fetchPurchaseEInvoices(string $fromIso, string $toIso): array
    {
        $fromDate = substr($fromIso, 0, 10);
        $toDate = substr($toIso, 0, 10);

        $response = $this->request('GET', 'EInvoice/GetPurchaseInvoice', [
            'query' => [
                'fromDate' => $fromDate,
                'toDate' => $toDate
            ]
        ]);

        return $this->decodeOrThrow($response, 'Fetch Purchase E-Invoices');
    }

    /**
     * Get single E-Invoice by EIC (often includes PDF)
     */
    public function getEInvoiceByEIC(string $eic): array
    {
        // Try GET with query parameter
        $response = $this->request('GET', 'EInvoice', [
            'query' => ['EIC' => $eic]
        ]);

        // If 405/415, fallback to POST with form params
        if (in_array($response->getStatusCode(), [405, 415])) {
            $response = $this->request('POST', 'EInvoice', [
                'form_params' => ['EIC' => $eic]
            ]);
        }

        $data = $this->decodeOrThrow($response, 'Get E-Invoice by EIC');

        // API may return array with single item or the object directly
        return (isset($data[0]) && is_array($data[0])) ? $data[0] : $data;
    }

    /**
     * Fetch Cash Sales (simplified invoices or cash/card payments)
     */
    public function fetchCashSales(string $fromIso, string $toIso): array
    {
        $rows = $this->fetchSalesEInvoices($fromIso, $toIso);
        $out = [];

        foreach ($rows as $r) {
            $eic = $r['eic'] ?? $r['EIC'] ?? null;
            if (!$eic) continue;

            try {
                $detail = $this->getEInvoiceByEIC((string)$eic);
            } catch (\Throwable $e) {
                continue;
            }

            $isSimplified = (bool)($detail['isSimplifiedInvoice'] ?? $detail['SimplifiedInvoice'] ?? false);
            $payments = $detail['invoicePayments'] ?? [];
            $types = array_map(fn($p) => $p['paymentMethodType'] ?? null, is_array($payments) ? $payments : []);
            
            // Cash (0) or Card (1) payment types
            $cashLike = $isSimplified || in_array(0, $types, true) || in_array(1, $types, true);

            if ($cashLike) {
                $out[] = array_merge($r, $detail);
            }
        }

        return $out;
    }
}
