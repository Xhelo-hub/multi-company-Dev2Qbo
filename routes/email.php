<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\EmailService;
use App\Middleware\AuthMiddleware;

return function ($app, $container) {
    
    // Get email configuration
    $app->get('/api/admin/email/config', function (Request $request, Response $response) use ($container) {
        try {
            $pdo = $container->get(PDO::class);
            
            $stmt = $pdo->query("SELECT * FROM email_config LIMIT 1");
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$config) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Email configuration not found'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }
            
            // Don't send the encrypted password to the client
            unset($config['mail_password']);
            unset($config['updated_by']);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'config' => $config
            ]));
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            error_log("Get email config error: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Failed to retrieve email configuration: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    })->add(new AuthMiddleware('admin'));
    
    // Update email configuration
    $app->put('/api/admin/email/config', function (Request $request, Response $response) use ($container) {
        try {
            $data = $request->getParsedBody();
            $pdo = $container->get(PDO::class);
            $encryptionKey = $_ENV['ENCRYPTION_KEY'] ?? 'default-insecure-key';
            
            // Validate required fields
            $required = ['mail_host', 'mail_port', 'mail_username', 'mail_from_address', 'mail_from_name'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'message' => "Missing required field: $field"
                    ]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
                }
            }
            
            // Encrypt password if provided
            $encryptedPassword = null;
            if (!empty($data['mail_password'])) {
                $iv = random_bytes(16);
                $encrypted = openssl_encrypt($data['mail_password'], 'AES-256-CBC', $encryptionKey, 0, $iv);
                $encryptedPassword = base64_encode($iv . $encrypted);
            }
            
            // Get current user from session
            $userId = $_SESSION['user_id'] ?? null;
            
            // Check if config exists
            $stmt = $pdo->query("SELECT id FROM email_config LIMIT 1");
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // Update existing config
                $sql = "UPDATE email_config SET 
                        mail_driver = ?, 
                        mail_host = ?, 
                        mail_port = ?, 
                        mail_username = ?, 
                        " . ($encryptedPassword ? "mail_password = ?, " : "") . "
                        mail_encryption = ?, 
                        mail_from_address = ?, 
                        mail_from_name = ?, 
                        is_enabled = ?, 
                        updated_by = ?, 
                        updated_at = NOW() 
                        WHERE id = ?";
                
                $params = [
                    $data['mail_driver'] ?? 'smtp',
                    $data['mail_host'],
                    (int)$data['mail_port'],
                    $data['mail_username']
                ];
                
                if ($encryptedPassword) {
                    $params[] = $encryptedPassword;
                }
                
                $params[] = $data['mail_encryption'] ?? 'tls';
                $params[] = $data['mail_from_address'];
                $params[] = $data['mail_from_name'];
                $params[] = isset($data['is_enabled']) ? (int)$data['is_enabled'] : 1;
                $params[] = $userId;
                $params[] = $existing['id'];
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                
            } else {
                // Insert new config
                if (!$encryptedPassword) {
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'message' => 'Password is required for new configuration'
                    ]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
                }
                
                $sql = "INSERT INTO email_config (
                        mail_driver, mail_host, mail_port, mail_username, mail_password, 
                        mail_encryption, mail_from_address, mail_from_name, is_enabled, updated_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $params = [
                    $data['mail_driver'] ?? 'smtp',
                    $data['mail_host'],
                    (int)$data['mail_port'],
                    $data['mail_username'],
                    $encryptedPassword,
                    $data['mail_encryption'] ?? 'tls',
                    $data['mail_from_address'],
                    $data['mail_from_name'],
                    isset($data['is_enabled']) ? (int)$data['is_enabled'] : 1,
                    $userId
                ];
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            }
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Email configuration updated successfully'
            ]));
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            error_log("Update email config error: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Failed to update email configuration: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    })->add(new AuthMiddleware('admin'));
    
    // Test email configuration
    $app->post('/api/admin/email/config/test', function (Request $request, Response $response) use ($container) {
        try {
            $data = $request->getParsedBody();
            $testEmail = $data['email'] ?? $_SESSION['user_email'] ?? null;
            
            if (!$testEmail) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Test email address is required'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
            
            $emailService = $container->get(EmailService::class);
            $result = $emailService->testConnection($testEmail);
            
            $response->getBody()->write(json_encode($result));
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            error_log("Test email error: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Failed to send test email: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    })->add(new AuthMiddleware('admin'));
    
    // Get all email templates
    $app->get('/api/admin/email/templates', function (Request $request, Response $response) use ($container) {
        try {
            $pdo = $container->get(PDO::class);
            
            $stmt = $pdo->query("SELECT * FROM email_templates ORDER BY template_name");
            $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'templates' => $templates
            ]));
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            error_log("Get templates error: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Failed to retrieve templates: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    })->add(new AuthMiddleware('admin'));
    
    // Get single email template
    $app->get('/api/admin/email/templates/{key}', function (Request $request, Response $response, array $args) use ($container) {
        try {
            $pdo = $container->get(PDO::class);
            $key = $args['key'];
            
            $stmt = $pdo->prepare("SELECT * FROM email_templates WHERE template_key = ?");
            $stmt->execute([$key]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$template) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Template not found'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'template' => $template
            ]));
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            error_log("Get template error: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Failed to retrieve template: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    })->add(new AuthMiddleware('admin'));
    
    // Update email template
    $app->put('/api/admin/email/templates/{key}', function (Request $request, Response $response, array $args) use ($container) {
        try {
            $pdo = $container->get(PDO::class);
            $key = $args['key'];
            $data = $request->getParsedBody();
            
            // Validate required fields
            if (empty($data['subject']) || empty($data['body_html']) || empty($data['body_text'])) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Subject, body_html, and body_text are required'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
            
            $sql = "UPDATE email_templates SET 
                    subject = ?, 
                    body_html = ?, 
                    body_text = ?, 
                    is_active = ?, 
                    updated_at = NOW() 
                    WHERE template_key = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $data['subject'],
                $data['body_html'],
                $data['body_text'],
                isset($data['is_active']) ? (int)$data['is_active'] : 1,
                $key
            ]);
            
            if ($stmt->rowCount() === 0) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Template not found'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Template updated successfully'
            ]));
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            error_log("Update template error: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Failed to update template: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    })->add(new AuthMiddleware('admin'));
    
    // Preview email template
    $app->post('/api/admin/email/templates/{key}/preview', function (Request $request, Response $response, array $args) use ($container) {
        try {
            $pdo = $container->get(PDO::class);
            $key = $args['key'];
            $data = $request->getParsedBody();
            
            // Get template
            $stmt = $pdo->prepare("SELECT * FROM email_templates WHERE template_key = ?");
            $stmt->execute([$key]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$template) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Template not found'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }
            
            // Sample data for preview
            $sampleData = [
                'name' => 'John Doe',
                'email' => 'john.doe@example.com',
                'temp_password' => 'Sample123!',
                'login_url' => 'https://devsync.konsulence.al/public/login.html',
                'reset_url' => 'https://devsync.konsulence.al/public/password-recovery.html?token=sample-token-123',
                'changes_list' => '<li><strong>Email:</strong> old@example.com</li><li><strong>Name:</strong> Old Name</li>',
                'year' => date('Y')
            ];
            
            // Merge with provided data
            $variables = array_merge($sampleData, $data['variables'] ?? []);
            
            // Substitute variables
            $subject = $template['subject'];
            $bodyHtml = $template['body_html'];
            $bodyText = $template['body_text'];
            
            foreach ($variables as $varKey => $value) {
                $subject = str_replace("{{" . $varKey . "}}", $value, $subject);
                $bodyHtml = str_replace("{{" . $varKey . "}}", $value, $bodyHtml);
                $bodyText = str_replace("{{" . $varKey . "}}", $value, $bodyText);
            }
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'preview' => [
                    'subject' => $subject,
                    'body_html' => $bodyHtml,
                    'body_text' => $bodyText
                ]
            ]));
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            error_log("Preview template error: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Failed to preview template: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    })->add(new AuthMiddleware('admin'));
    
    // Send test email with template
    $app->post('/api/admin/email/templates/{key}/test', function (Request $request, Response $response, array $args) use ($container) {
        try {
            $pdo = $container->get(PDO::class);
            $key = $args['key'];
            $data = $request->getParsedBody();
            
            $testEmail = $data['email'] ?? $_SESSION['user_email'] ?? null;
            if (!$testEmail) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Test email address is required'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
            
            // Get email service
            $emailService = $container->get(EmailService::class);
            
            // Send test based on template type
            $result = false;
            switch ($key) {
                case 'user_welcome':
                    $result = $emailService->sendWelcomeEmail($testEmail, $_SESSION['user_name'] ?? 'Test User', 'TestPassword123!');
                    break;
                case 'password_reset':
                    $result = $emailService->sendPasswordResetEmail($testEmail, $_SESSION['user_name'] ?? 'Test User', 'sample-reset-token-123');
                    break;
                case 'temp_password':
                    $result = $emailService->sendTemporaryPasswordEmail($testEmail, $_SESSION['user_name'] ?? 'Test User', 'TempPass123!');
                    break;
                case 'account_modified':
                    $result = $emailService->sendAccountModifiedEmail($testEmail, $_SESSION['user_name'] ?? 'Test User', ['email' => 'old@example.com', 'name' => 'Old Name']);
                    break;
                default:
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'message' => 'Unknown template type'
                    ]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
            
            $response->getBody()->write(json_encode([
                'success' => $result,
                'message' => $result ? "Test email sent to {$testEmail}" : 'Failed to send test email'
            ]));
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            error_log("Send test email error: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Failed to send test email: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    })->add(new AuthMiddleware('admin'));
    
    // Get email logs
    $app->get('/api/admin/email/logs', function (Request $request, Response $response) use ($container) {
        try {
            $pdo = $container->get(PDO::class);
            $params = $request->getQueryParams();
            
            $limit = isset($params['limit']) ? (int)$params['limit'] : 50;
            $offset = isset($params['offset']) ? (int)$params['offset'] : 0;
            $status = $params['status'] ?? null;
            
            $sql = "SELECT * FROM email_logs";
            $whereClauses = [];
            $queryParams = [];
            
            if ($status) {
                $whereClauses[] = "status = ?";
                $queryParams[] = $status;
            }
            
            if (!empty($whereClauses)) {
                $sql .= " WHERE " . implode(" AND ", $whereClauses);
            }
            
            $sql .= " ORDER BY sent_at DESC LIMIT ? OFFSET ?";
            $queryParams[] = $limit;
            $queryParams[] = $offset;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($queryParams);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total count
            $countSql = "SELECT COUNT(*) FROM email_logs";
            if (!empty($whereClauses)) {
                $countSql .= " WHERE " . implode(" AND ", $whereClauses);
            }
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute(array_slice($queryParams, 0, -2)); // Remove limit and offset
            $totalCount = $countStmt->fetchColumn();
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'logs' => $logs,
                'pagination' => [
                    'total' => (int)$totalCount,
                    'limit' => $limit,
                    'offset' => $offset
                ]
            ]));
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            error_log("Get email logs error: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Failed to retrieve email logs: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    })->add(new AuthMiddleware('admin'));
};
