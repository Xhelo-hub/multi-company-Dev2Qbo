<?php
/**
 * QuickBooks OAuth Connection Tool
 * Helps connect a company to QuickBooks Online
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

echo "=================================================\n";
echo "  QuickBooks OAuth Connection Setup\n";
echo "=================================================\n\n";

// Check if credentials are configured
$clientId = $_ENV['QBO_CLIENT_ID'] ?? null;
$clientSecret = $_ENV['QBO_CLIENT_SECRET'] ?? null;
$redirectUri = $_ENV['QBO_REDIRECT_URI'] ?? 'http://localhost:8081/oauth/callback';

if (empty($clientId) || empty($clientSecret)) {
    die("✗ Error: QBO_CLIENT_ID and QBO_CLIENT_SECRET must be configured in .env\n");
}

echo "QuickBooks Configuration:\n";
echo "  Client ID: " . substr($clientId, 0, 20) . "...\n";
echo "  Redirect URI: {$redirectUri}\n\n";

// Check database connection
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
    die("✗ Database connection failed: " . $e->getMessage() . "\n\n" .
        "Please start MySQL first:\n" .
        "  - Open XAMPP Control Panel\n" .
        "  - Click 'Start' for MySQL\n");
}

// List companies
echo "Available Companies:\n";
echo str_repeat("-", 60) . "\n";

$stmt = $pdo->query("SELECT id, company_code, company_name, is_active FROM companies ORDER BY id");
$companies = $stmt->fetchAll();

if (empty($companies)) {
    die("✗ No companies found in database.\n" .
        "Please add a company first using the dashboard or company-manager.php\n");
}

foreach ($companies as $company) {
    $status = $company['is_active'] ? '✓' : '✗';
    echo "  [{$company['id']}] {$status} {$company['company_code']} - {$company['company_name']}\n";
}

echo "\n";
$companyId = (int)readline("Enter Company ID to connect to QuickBooks: ");

// Verify company exists
$stmt = $pdo->prepare("SELECT id, company_code, company_name FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$company = $stmt->fetch();

if (!$company) {
    die("✗ Company ID {$companyId} not found\n");
}

echo "\nConnecting: {$company['company_name']}\n\n";

// Generate state token for security
$state = bin2hex(random_bytes(16));

// Store state in session or database temporarily
$_SESSION['oauth_state'] = $state;
$_SESSION['oauth_company_id'] = $companyId;

// Build authorization URL
$authUrl = ($_ENV['QBO_AUTH_URL'] ?? 'https://appcenter.intuit.com/connect/oauth2');
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

echo "=================================================\n";
echo "  STEP 1: Authorize with QuickBooks\n";
echo "=================================================\n\n";

echo "1. Make sure your web server is running at:\n";
echo "   {$redirectUri}\n\n";

echo "2. Open this URL in your browser:\n\n";
echo "   {$authorizationUrl}\n\n";

echo "3. Log into QuickBooks and authorize the app\n\n";

echo "4. After authorization, you'll be redirected to:\n";
echo "   {$redirectUri}?code=XXX&realmId=XXX&state=XXX\n\n";

echo "5. Copy the values and paste them here:\n\n";

// Wait for user to complete OAuth flow
$code = readline("Enter authorization code: ");
$realmId = readline("Enter realmId (Company ID): ");
$returnedState = readline("Enter state value: ");

if (empty($code) || empty($realmId)) {
    die("\n✗ Authorization code and realmId are required\n");
}

// Verify state (basic check)
if ($returnedState !== $state) {
    echo "\n⚠ Warning: State mismatch - possible CSRF attack\n";
    $continue = readline("Continue anyway? (yes/no): ");
    if (strtolower($continue) !== 'yes') {
        die("Aborted\n");
    }
}

echo "\n=================================================\n";
echo "  STEP 2: Exchange Code for Tokens\n";
echo "=================================================\n\n";

// Exchange authorization code for tokens
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
        "Error: " . ($error['error'] ?? 'unknown') . "\n" .
        "Description: " . ($error['error_description'] ?? '') . "\n");
}

$tokens = json_decode($response, true);

if (!isset($tokens['access_token'])) {
    die("✗ No access token received\n" .
        "Response: {$response}\n");
}

echo "✓ Tokens received successfully!\n";
echo "  Access Token: " . substr($tokens['access_token'], 0, 30) . "...\n";
echo "  Refresh Token: " . substr($tokens['refresh_token'], 0, 30) . "...\n";
echo "  Expires In: " . ($tokens['expires_in'] ?? 3600) . " seconds\n";
echo "  Realm ID: {$realmId}\n\n";

// Save credentials to database
echo "=================================================\n";
echo "  STEP 3: Save Credentials to Database\n";
echo "=================================================\n\n";

try {
    $pdo->beginTransaction();
    
    // Save QBO credentials
    $stmt = $pdo->prepare("
        INSERT INTO company_credentials_qbo (company_id, realm_id)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE realm_id = VALUES(realm_id)
    ");
    $stmt->execute([$companyId, $realmId]);
    echo "✓ Saved QBO credentials (realm_id)\n";
    
    // Save OAuth tokens
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
    
    echo "✓ Saved OAuth tokens\n";
    
    $pdo->commit();
    
    echo "\n=================================================\n";
    echo "✓✓✓ QUICKBOOKS CONNECTION SUCCESSFUL! ✓✓✓\n";
    echo "=================================================\n\n";
    
    echo "Company '{$company['company_name']}' is now connected to:\n";
    echo "  QuickBooks Company (Realm ID): {$realmId}\n";
    echo "  Token expires: {$expiresAt}\n\n";
    
    echo "Next steps:\n";
    echo "1. Test the connection:\n";
    echo "   php bin/test-qbo-connection.php\n\n";
    echo "2. Start syncing data:\n";
    echo "   - Use the dashboard to trigger sync\n";
    echo "   - Or use API: POST /api/sync/{$companyId}\n\n";
    
} catch (PDOException $e) {
    $pdo->rollBack();
    die("✗ Database error: " . $e->getMessage() . "\n");
}
