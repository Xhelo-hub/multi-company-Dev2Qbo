<?php
/**
 * QuickBooks OAuth Connection Tool - Automated
 * Starts a local server to handle OAuth callback automatically
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

echo "=================================================\n";
echo "  QuickBooks OAuth Setup (Automated)\n";
echo "=================================================\n\n";

// Check configuration
$clientId = $_ENV['QBO_CLIENT_ID'] ?? null;
$clientSecret = $_ENV['QBO_CLIENT_SECRET'] ?? null;
$redirectUri = $_ENV['QBO_REDIRECT_URI'] ?? 'http://localhost:8081/oauth/callback';

if (empty($clientId) || empty($clientSecret)) {
    die("✗ Error: QBO_CLIENT_ID and QBO_CLIENT_SECRET must be configured in .env\n");
}

// Parse redirect URI to get port
$urlParts = parse_url($redirectUri);
$port = $urlParts['port'] ?? 80;
$path = $urlParts['path'] ?? '/oauth/callback';

echo "Configuration:\n";
echo "  Redirect URI: {$redirectUri}\n";
echo "  Local Server: Port {$port}, Path {$path}\n\n";

// Check database
try {
    $dsn = $_ENV['DB_DSN'] ?? 'mysql:host=127.0.0.1;dbname=qbo_multicompany;charset=utf8mb4';
    $user = $_ENV['DB_USER'] ?? 'root';
    $pass = $_ENV['DB_PASS'] ?? '';
    
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    echo "✓ Database connected\n\n";
} catch (PDOException $e) {
    die("✗ Database error: " . $e->getMessage() . "\n" .
        "Start MySQL in XAMPP Control Panel first.\n");
}

// List companies
$stmt = $pdo->query("SELECT id, company_code, company_name, is_active FROM companies ORDER BY id");
$companies = $stmt->fetchAll();

if (empty($companies)) {
    die("✗ No companies found. Add a company first.\n");
}

echo "Companies:\n";
foreach ($companies as $c) {
    $status = $c['is_active'] ? '✓' : '✗';
    echo "  [{$c['id']}] {$status} {$c['company_code']} - {$c['company_name']}\n";
}

echo "\n";
$companyId = (int)readline("Enter Company ID: ");

$stmt = $pdo->prepare("SELECT id, company_code, company_name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();

if (!$company) {
    die("✗ Company {$companyId} not found\n");
}

echo "\n=================================================\n";
echo "  Starting OAuth Flow for: {$company['company_name']}\n";
echo "=================================================\n\n";

// Generate state
$state = bin2hex(random_bytes(16));

// Build authorization URL
$authUrl = $_ENV['QBO_AUTH_URL'] ?? 'https://appcenter.intuit.com/connect/oauth2';
$scopes = [
    'com.intuit.quickbooks.accounting',
    'openid',
    'profile',
    'email'
];

$params = [
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => implode(' ', $scopes),
    'state' => $state
];

$authorizationUrl = $authUrl . '?' . http_build_query($params);

echo "INSTRUCTIONS:\n";
echo "1. A browser will open with QuickBooks login\n";
echo "2. Log in and select your company\n";
echo "3. Click 'Connect' to authorize\n";
echo "4. This script will automatically receive the callback\n\n";

echo "Press Enter to open browser and start OAuth flow...";
readline();

// Try to open browser
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    pclose(popen("start " . escapeshellarg($authorizationUrl), "r"));
} elseif (PHP_OS === 'Darwin') {
    exec("open " . escapeshellarg($authorizationUrl));
} else {
    exec("xdg-open " . escapeshellarg($authorizationUrl));
}

echo "\n✓ Browser opened\n";
echo "If browser didn't open, visit this URL:\n{$authorizationUrl}\n\n";

echo "Starting local server on port {$port}...\n";
echo "Waiting for OAuth callback...\n";
echo "(Press Ctrl+C to cancel)\n\n";

// Start simple HTTP server to handle callback
$sock = @stream_socket_server("tcp://0.0.0.0:{$port}", $errno, $errstr);

if (!$sock) {
    die("✗ Could not start server on port {$port}: {$errstr}\n" .
        "Make sure the port is not in use and try again.\n");
}

echo "✓ Server listening on http://localhost:{$port}\n";
echo "Waiting for QuickBooks callback...\n\n";

// Wait for connection (with timeout)
stream_set_timeout($sock, 300); // 5 minutes timeout

$conn = @stream_socket_accept($sock, -1);

if (!$conn) {
    fclose($sock);
    die("✗ Timeout waiting for callback\n");
}

// Read HTTP request
$request = '';
while ($line = fgets($conn)) {
    $request .= $line;
    if (trim($line) === '') break;
}

// Parse request
preg_match('/GET\s+([^\s]+)/', $request, $matches);
$requestUri = $matches[1] ?? '';

// Parse query string
$queryStart = strpos($requestUri, '?');
if ($queryStart === false) {
    fclose($conn);
    fclose($sock);
    die("✗ Invalid callback - no query parameters\n");
}

$queryString = substr($requestUri, $queryStart + 1);
parse_str($queryString, $params);

$code = $params['code'] ?? null;
$realmId = $params['realmId'] ?? null;
$returnedState = $params['state'] ?? null;

// Send response to browser
$htmlResponse = "HTTP/1.1 200 OK\r\n";
$htmlResponse .= "Content-Type: text/html; charset=UTF-8\r\n";
$htmlResponse .= "Connection: close\r\n\r\n";

if ($code && $realmId) {
    $htmlResponse .= "<!DOCTYPE html><html><head><title>QuickBooks Connected</title></head>";
    $htmlResponse .= "<body style='font-family:Arial; padding:50px; text-align:center;'>";
    $htmlResponse .= "<h1 style='color:green;'>✓ Connected Successfully!</h1>";
    $htmlResponse .= "<p>Company ID: {$realmId}</p>";
    $htmlResponse .= "<p>You can close this window and return to the terminal.</p>";
    $htmlResponse .= "</body></html>";
} else {
    $htmlResponse .= "<!DOCTYPE html><html><head><title>Error</title></head>";
    $htmlResponse .= "<body style='font-family:Arial; padding:50px; text-align:center;'>";
    $htmlResponse .= "<h1 style='color:red;'>✗ Connection Failed</h1>";
    $htmlResponse .= "<p>Missing authorization code or company ID.</p>";
    $htmlResponse .= "</body></html>";
}

fwrite($conn, $htmlResponse);
fclose($conn);
fclose($sock);

if (!$code || !$realmId) {
    die("\n✗ OAuth failed - missing code or realmId\n");
}

echo "\n✓ Received OAuth callback\n";
echo "  Code: " . substr($code, 0, 20) . "...\n";
echo "  Realm ID: {$realmId}\n\n";

// Exchange code for tokens
echo "Exchanging authorization code for tokens...\n";

$tokenUrl = $_ENV['QBO_TOKEN_URL'] ?? 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';

$ch = curl_init($tokenUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
        'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret)
    ],
    CURLOPT_POSTFIELDS => http_build_query([
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $redirectUri
    ])
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    $error = json_decode($response, true);
    die("✗ Token exchange failed (HTTP {$httpCode})\n" .
        "Error: " . ($error['error'] ?? 'unknown') . "\n");
}

$tokens = json_decode($response, true);

if (!isset($tokens['access_token'])) {
    die("✗ No access token received\n");
}

echo "✓ Tokens received\n\n";

// Save to database
echo "Saving credentials...\n";

try {
    $pdo->beginTransaction();
    
    // Save realm_id
    $stmt = $pdo->prepare("
        INSERT INTO company_credentials_qbo (company_id, realm_id)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE realm_id = VALUES(realm_id)
    ");
    $stmt->execute([$companyId, $realmId]);
    
    // Save tokens
    $expiresAt = date('Y-m-d H:i:s', time() + ($tokens['expires_in'] ?? 3600));
    
    $stmt = $pdo->prepare("
        INSERT INTO oauth_tokens_qbo (
            company_id, access_token, refresh_token, expires_at
        )
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            access_token = VALUES(access_token),
            refresh_token = VALUES(refresh_token),
            expires_at = VALUES(expires_at)
    ");
    
    $stmt->execute([
        $companyId,
        $tokens['access_token'],
        $tokens['refresh_token'],
        $expiresAt
    ]);
    
    $pdo->commit();
    
    echo "✓ Credentials saved\n\n";
    
    echo "=================================================\n";
    echo "✓✓✓ QUICKBOOKS CONNECTED SUCCESSFULLY! ✓✓✓\n";
    echo "=================================================\n\n";
    
    echo "Company: {$company['company_name']}\n";
    echo "QuickBooks Company ID: {$realmId}\n";
    echo "Token expires: {$expiresAt}\n\n";
    
    echo "Ready to sync!\n";
    
} catch (PDOException $e) {
    $pdo->rollBack();
    die("✗ Database error: " . $e->getMessage() . "\n");
}
