<?php
// API routes for email provider presets

return function ($app) {
    
    // Get all available email provider presets
    $app->get('/api/email/providers', function ($request, $response) {
        try {
            $db = $this->get('db');
            
            $stmt = $db->query("
                SELECT id, provider_key, provider_name, mail_host, mail_port, 
                       mail_encryption, description, setup_instructions, sort_order
                FROM email_provider_presets 
                WHERE is_active = 1 
                ORDER BY sort_order ASC, provider_name ASC
            ");
            
            $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'providers' => $providers
            ]));
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (Exception $e) {
            error_log("Get email providers error: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Failed to load email providers'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });
    
    // Get specific provider preset by key
    $app->get('/api/email/providers/{key}', function ($request, $response, $args) {
        try {
            $providerKey = $args['key'];
            $db = $this->get('db');
            
            $stmt = $db->prepare("
                SELECT id, provider_key, provider_name, mail_host, mail_port, 
                       mail_encryption, description, setup_instructions
                FROM email_provider_presets 
                WHERE provider_key = ? AND is_active = 1
            ");
            $stmt->execute([$providerKey]);
            
            $provider = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$provider) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Provider not found'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'provider' => $provider
            ]));
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (Exception $e) {
            error_log("Get email provider error: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Failed to load email provider'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });
    
    // Apply provider preset to email configuration
    $app->post('/api/email/apply-preset', function ($request, $response) {
        try {
            $data = $request->getParsedBody();
            $providerKey = $data['provider_key'] ?? null;
            $username = $data['username'] ?? '';
            $password = $data['password'] ?? '';
            $fromAddress = $data['from_address'] ?? $username;
            $fromName = $data['from_name'] ?? 'DEV-QBO Sync';
            
            if (!$providerKey) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Provider key is required'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
            
            $db = $this->get('db');
            $encryptionKey = $_ENV['ENCRYPTION_KEY'];
            
            // Get provider preset
            $stmt = $db->prepare("
                SELECT * FROM email_provider_presets 
                WHERE provider_key = ? AND is_active = 1
            ");
            $stmt->execute([$providerKey]);
            $preset = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$preset) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Invalid provider preset'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }
            
            // For custom provider, allow host override
            $mailHost = $preset['mail_host'];
            $mailPort = $preset['mail_port'];
            $mailEncryption = $preset['mail_encryption'];
            
            if ($providerKey === 'custom') {
                $mailHost = $data['custom_host'] ?? '';
                $mailPort = $data['custom_port'] ?? 587;
                $mailEncryption = $data['custom_encryption'] ?? 'tls';
                
                if (!$mailHost) {
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'message' => 'SMTP host is required for custom provider'
                    ]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
                }
            }
            
            // Encrypt password
            $iv = random_bytes(16);
            $encrypted = openssl_encrypt($password, 'AES-256-CBC', $encryptionKey, 0, $iv);
            $encryptedPassword = base64_encode($iv . $encrypted);
            
            // Update or insert email configuration
            $stmt = $db->prepare("
                INSERT INTO email_config (
                    id, provider_preset_id, provider_key, is_enabled,
                    mail_driver, mail_host, mail_port, mail_encryption,
                    mail_username, mail_password, mail_from_address, mail_from_name
                ) VALUES (
                    1, ?, ?, 1,
                    'smtp', ?, ?, ?,
                    ?, ?, ?, ?
                )
                ON DUPLICATE KEY UPDATE
                    provider_preset_id = VALUES(provider_preset_id),
                    provider_key = VALUES(provider_key),
                    is_enabled = VALUES(is_enabled),
                    mail_driver = VALUES(mail_driver),
                    mail_host = VALUES(mail_host),
                    mail_port = VALUES(mail_port),
                    mail_encryption = VALUES(mail_encryption),
                    mail_username = VALUES(mail_username),
                    mail_password = VALUES(mail_password),
                    mail_from_address = VALUES(mail_from_address),
                    mail_from_name = VALUES(mail_from_name)
            ");
            
            $stmt->execute([
                $preset['id'],
                $providerKey,
                $mailHost,
                $mailPort,
                $mailEncryption,
                $username,
                $encryptedPassword,
                $fromAddress,
                $fromName
            ]);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Email configuration updated successfully',
                'provider' => $preset['provider_name']
            ]));
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (Exception $e) {
            error_log("Apply email preset error: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Failed to apply email configuration: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });
};
