<?php

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
use PDO;

class EmailService
{
    private $mailer;
    private $pdo;
    private $fromEmail;
    private $fromName;
    private $isEnabled;
    private $encryptionKey;

    public function __construct(PDO $pdo, string $encryptionKey)
    {
        $this->pdo = $pdo;
        $this->mailer = new PHPMailer(true);
        $this->encryptionKey = $encryptionKey;
        
        $this->loadConfiguration();
    }

    /**
     * Load email configuration from database
     */
    private function loadConfiguration()
    {
        try {
            $stmt = $this->pdo->query("SELECT * FROM email_config LIMIT 1");
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$config) {
                // Fallback to env if no database config
                $this->isEnabled = filter_var($_ENV['MAIL_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $this->configureFromEnv();
                return;
            }
            
            $this->isEnabled = (bool)$config['is_enabled'];
            
            if ($this->isEnabled && $config['mail_username'] && $config['mail_password']) {
                $this->configureFromDatabase($config);
            } else {
                $this->isEnabled = false;
            }
            
        } catch (\Exception $e) {
            error_log("EmailService loadConfiguration error: " . $e->getMessage());
            $this->isEnabled = false;
        }
    }

    /**
     * Configure PHPMailer from database settings
     */
    private function configureFromDatabase(array $config)
    {
        try {
            $mailDriver = $config['mail_driver'] ?? 'smtp';
            
            if ($mailDriver === 'smtp') {
                $this->mailer->isSMTP();
                $this->mailer->Host = $config['mail_host'];
                $this->mailer->SMTPAuth = true;
                $this->mailer->Username = $config['mail_username'];
                $this->mailer->Password = $this->decrypt($config['mail_password']);
                $this->mailer->SMTPSecure = $config['mail_encryption'] ?? PHPMailer::ENCRYPTION_STARTTLS;
                $this->mailer->Port = (int)$config['mail_port'];
                
                // Enable verbose debug output in development
                if (($_ENV['APP_ENV'] ?? 'production') === 'development') {
                    $this->mailer->SMTPDebug = SMTP::DEBUG_SERVER;
                }
            } elseif ($mailDriver === 'sendmail') {
                $this->mailer->isSendmail();
            } else {
                $this->mailer->isMail();
            }

            $this->fromEmail = $config['mail_from_address'];
            $this->fromName = $config['mail_from_name'];
            
            $this->mailer->setFrom($this->fromEmail, $this->fromName);
            $this->mailer->isHTML(true);
            $this->mailer->CharSet = 'UTF-8';

        } catch (Exception $e) {
            error_log("EmailService configureFromDatabase error: " . $e->getMessage());
            $this->isEnabled = false;
        }
    }

    /**
     * Fallback configuration from environment variables
     */
    private function configureFromEnv()
    {
        try {
            $mailDriver = $_ENV['MAIL_DRIVER'] ?? 'smtp';
            
            if ($mailDriver === 'smtp') {
                $this->mailer->isSMTP();
                $this->mailer->Host = $_ENV['MAIL_HOST'] ?? 'smtp.office365.com';
                $this->mailer->SMTPAuth = true;
                $this->mailer->Username = $_ENV['MAIL_USERNAME'] ?? '';
                $this->mailer->Password = $_ENV['MAIL_PASSWORD'] ?? '';
                $this->mailer->SMTPSecure = $_ENV['MAIL_ENCRYPTION'] ?? PHPMailer::ENCRYPTION_STARTTLS;
                $this->mailer->Port = (int)($_ENV['MAIL_PORT'] ?? 587);
                
                if (($_ENV['APP_ENV'] ?? 'production') === 'development') {
                    $this->mailer->SMTPDebug = SMTP::DEBUG_SERVER;
                }
            } elseif ($mailDriver === 'sendmail') {
                $this->mailer->isSendmail();
            } else {
                $this->mailer->isMail();
            }

            $this->fromEmail = $_ENV['MAIL_FROM_ADDRESS'] ?? 'devsync@konsulence.al';
            $this->fromName = $_ENV['MAIL_FROM_NAME'] ?? 'DEV-QBO Sync';
            
            $this->mailer->setFrom($this->fromEmail, $this->fromName);
            $this->mailer->isHTML(true);
            $this->mailer->CharSet = 'UTF-8';

        } catch (Exception $e) {
            error_log("EmailService configureFromEnv error: " . $e->getMessage());
            $this->isEnabled = false;
        }
    }

    /**
     * Encrypt password for storage
     */
    private function encrypt(string $data): string
    {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $this->encryptionKey, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt password from storage
     */
    private function decrypt(string $data): string
    {
        $data = base64_decode($data);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $this->encryptionKey, 0, $iv);
    }

    /**
     * Get template from database by key
     */
    private function getTemplate(string $key): ?array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM email_templates WHERE template_key = ? AND is_active = 1");
            $stmt->execute([$key]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\Exception $e) {
            error_log("Failed to load template {$key}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Substitute variables in template
     */
    private function substituteVariables(string $content, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $content = str_replace("{{" . $key . "}}", $value, $content);
        }
        
        // Add current year
        $content = str_replace("{{year}}", date('Y'), $content);
        
        return $content;
    }

    /**
     * Log email sending attempt
     */
    private function logEmail(string $recipient, string $subject, string $status, ?string $errorMessage = null): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO email_logs (recipient_email, subject, status, error_message, sent_at) 
                 VALUES (?, ?, ?, ?, NOW())"
            );
            $stmt->execute([$recipient, $subject, $status, $errorMessage]);
        } catch (\Exception $e) {
            error_log("Failed to log email: " . $e->getMessage());
        }
    }

    /**
     * Send welcome email to newly created user
     */
    public function sendWelcomeEmail(string $toEmail, string $toName, string $temporaryPassword = null): bool
    {
        if (!$this->isEnabled) {
            error_log("Email service is disabled. Skipping welcome email to: {$toEmail}");
            return false;
        }

        try {
            $template = $this->getTemplate('user_welcome');
            
            if (!$template) {
                error_log("Welcome email template not found");
                $this->logEmail($toEmail, 'Welcome Email', 'failed', 'Template not found');
                return false;
            }
            
            $loginUrl = $_ENV['APP_URL'] ?? 'http://localhost';
            $variables = [
                'name' => $toName,
                'email' => $toEmail,
                'temp_password' => $temporaryPassword ?? '(Already set)',
                'login_url' => $loginUrl
            ];
            
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($toEmail, $toName);
            
            $this->mailer->Subject = $this->substituteVariables($template['subject'], $variables);
            $this->mailer->Body = $this->substituteVariables($template['body_html'], $variables);
            $this->mailer->AltBody = $this->substituteVariables($template['body_text'], $variables);

            $result = $this->mailer->send();
            $this->logEmail($toEmail, $this->mailer->Subject, $result ? 'sent' : 'failed');
            return $result;
            
        } catch (Exception $e) {
            error_log("Failed to send welcome email to {$toEmail}: " . $e->getMessage());
            $this->logEmail($toEmail, 'Welcome Email', 'failed', $e->getMessage());
            return false;
        }
    }

    /**
     * Send password reset email with token
     */
    public function sendPasswordResetEmail(string $toEmail, string $toName, string $resetToken): bool
    {
        if (!$this->isEnabled) {
            error_log("Email service is disabled. Skipping password reset email to: {$toEmail}");
            return false;
        }

        try {
            $template = $this->getTemplate('password_reset');
            
            if (!$template) {
                error_log("Password reset email template not found");
                $this->logEmail($toEmail, 'Password Reset', 'failed', 'Template not found');
                return false;
            }
            
            $resetUrl = ($_ENV['APP_URL'] ?? 'http://localhost') . '/reset-password.html?token=' . urlencode($resetToken);
            $variables = [
                'name' => $toName,
                'reset_url' => $resetUrl
            ];
            
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($toEmail, $toName);
            
            $this->mailer->Subject = $this->substituteVariables($template['subject'], $variables);
            $this->mailer->Body = $this->substituteVariables($template['body_html'], $variables);
            $this->mailer->AltBody = $this->substituteVariables($template['body_text'], $variables);

            $result = $this->mailer->send();
            $this->logEmail($toEmail, $this->mailer->Subject, $result ? 'sent' : 'failed');
            return $result;
            
        } catch (Exception $e) {
            error_log("Failed to send password reset email to {$toEmail}: " . $e->getMessage());
            $this->logEmail($toEmail, 'Password Reset', 'failed', $e->getMessage());
            return false;
        }
    }

    /**
     * Send temporary password email (when admin resets user password)
     */
    public function sendTemporaryPasswordEmail(string $toEmail, string $toName, string $temporaryPassword): bool
    {
        if (!$this->isEnabled) {
            error_log("Email service is disabled. Skipping temporary password email to: {$toEmail}");
            return false;
        }

        try {
            $template = $this->getTemplate('temp_password');
            
            if (!$template) {
                error_log("Temporary password email template not found");
                $this->logEmail($toEmail, 'Password Reset', 'failed', 'Template not found');
                return false;
            }
            
            $loginUrl = $_ENV['APP_URL'] ?? 'http://localhost';
            $variables = [
                'name' => $toName,
                'temp_password' => $temporaryPassword,
                'login_url' => $loginUrl
            ];
            
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($toEmail, $toName);
            
            $this->mailer->Subject = $this->substituteVariables($template['subject'], $variables);
            $this->mailer->Body = $this->substituteVariables($template['body_html'], $variables);
            $this->mailer->AltBody = $this->substituteVariables($template['body_text'], $variables);

            $result = $this->mailer->send();
            $this->logEmail($toEmail, $this->mailer->Subject, $result ? 'sent' : 'failed');
            return $result;
            
        } catch (Exception $e) {
            error_log("Failed to send temporary password email to {$toEmail}: " . $e->getMessage());
            $this->logEmail($toEmail, 'Password Reset', 'failed', $e->getMessage());
            return false;
        }
    }

    /**
     * Send account modified notification
     */
    public function sendAccountModifiedEmail(string $toEmail, string $toName, array $changes): bool
    {
        if (!$this->isEnabled) {
            error_log("Email service is disabled. Skipping account modified email to: {$toEmail}");
            return false;
        }

        try {
            $template = $this->getTemplate('account_modified');
            
            if (!$template) {
                error_log("Account modified email template not found");
                $this->logEmail($toEmail, 'Account Modified', 'failed', 'Template not found');
                return false;
            }
            
            $changesList = '';
            foreach ($changes as $field => $value) {
                $changesList .= "<li><strong>" . ucfirst($field) . ":</strong> " . htmlspecialchars($value) . "</li>\n";
            }
            
            $variables = [
                'name' => $toName,
                'changes_list' => $changesList
            ];
            
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($toEmail, $toName);
            
            $this->mailer->Subject = $this->substituteVariables($template['subject'], $variables);
            $this->mailer->Body = $this->substituteVariables($template['body_html'], $variables);
            $this->mailer->AltBody = $this->substituteVariables($template['body_text'], $variables);

            $result = $this->mailer->send();
            $this->logEmail($toEmail, $this->mailer->Subject, $result ? 'sent' : 'failed');
            return $result;
            
        } catch (Exception $e) {
            error_log("Failed to send account modified email to {$toEmail}: " . $e->getMessage());
            $this->logEmail($toEmail, 'Account Modified', 'failed', $e->getMessage());
            return false;
        }
    }

    /**
     * Test email configuration
     */
    public function testConnection(string $testEmail): array
    {
        if (!$this->isEnabled) {
            return [
                'success' => false,
                'message' => 'Email service is disabled in configuration'
            ];
        }

        try {
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($testEmail);
            $this->mailer->Subject = 'Test Email - DEV-QBO Sync';
            $this->mailer->Body = '<h2>✅ Email Configuration Test</h2><p>If you received this email, your email configuration is working correctly!</p>';
            $this->mailer->AltBody = 'Email Configuration Test - If you received this email, your email configuration is working correctly!';
            
            $this->mailer->send();
            
            return [
                'success' => true,
                'message' => 'Test email sent successfully to ' . $testEmail
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to send test email: ' . $e->getMessage()
            ];
        }
    }
}
