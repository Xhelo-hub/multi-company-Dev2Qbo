<?php

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class EmailService
{
    private $mailer;
    private $fromEmail;
    private $fromName;
    private $isEnabled;

    public function __construct()
    {
        $this->mailer = new PHPMailer(true);
        $this->isEnabled = filter_var($_ENV['MAIL_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN);
        
        if ($this->isEnabled) {
            $this->configure();
        }
    }

    /**
     * Configure PHPMailer based on environment settings
     */
    private function configure()
    {
        try {
            $mailDriver = $_ENV['MAIL_DRIVER'] ?? 'smtp'; // smtp, sendmail, mail
            
            if ($mailDriver === 'smtp') {
                // SMTP Configuration
                $this->mailer->isSMTP();
                $this->mailer->Host = $_ENV['MAIL_HOST'] ?? 'smtp.gmail.com';
                $this->mailer->SMTPAuth = true;
                $this->mailer->Username = $_ENV['MAIL_USERNAME'] ?? '';
                $this->mailer->Password = $_ENV['MAIL_PASSWORD'] ?? '';
                $this->mailer->SMTPSecure = $_ENV['MAIL_ENCRYPTION'] ?? PHPMailer::ENCRYPTION_STARTTLS;
                $this->mailer->Port = (int)($_ENV['MAIL_PORT'] ?? 587);
                
                // Enable verbose debug output (disable in production)
                if ($_ENV['APP_ENV'] === 'development') {
                    $this->mailer->SMTPDebug = SMTP::DEBUG_SERVER;
                }
            } elseif ($mailDriver === 'sendmail') {
                $this->mailer->isSendmail();
            } else {
                $this->mailer->isMail();
            }

            // From address
            $this->fromEmail = $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@devpos-sync.local';
            $this->fromName = $_ENV['MAIL_FROM_NAME'] ?? 'DEV-QBO Sync';
            
            // Set defaults
            $this->mailer->setFrom($this->fromEmail, $this->fromName);
            $this->mailer->isHTML(true);
            $this->mailer->CharSet = 'UTF-8';

        } catch (Exception $e) {
            error_log("EmailService configuration error: " . $e->getMessage());
            $this->isEnabled = false;
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
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($toEmail, $toName);
            
            $this->mailer->Subject = 'Welcome to DEV-QBO Sync Platform';
            
            $body = $this->getWelcomeEmailTemplate($toName, $toEmail, $temporaryPassword);
            $this->mailer->Body = $body;
            $this->mailer->AltBody = strip_tags(str_replace(['<br>', '<br/>'], "\n", $body));

            return $this->mailer->send();
            
        } catch (Exception $e) {
            error_log("Failed to send welcome email to {$toEmail}: " . $e->getMessage());
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
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($toEmail, $toName);
            
            $this->mailer->Subject = 'Password Reset Request - DEV-QBO Sync';
            
            $body = $this->getPasswordResetEmailTemplate($toName, $resetToken);
            $this->mailer->Body = $body;
            $this->mailer->AltBody = strip_tags(str_replace(['<br>', '<br/>'], "\n", $body));

            return $this->mailer->send();
            
        } catch (Exception $e) {
            error_log("Failed to send password reset email to {$toEmail}: " . $e->getMessage());
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
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($toEmail, $toName);
            
            $this->mailer->Subject = 'Your Password Has Been Reset - DEV-QBO Sync';
            
            $body = $this->getTemporaryPasswordEmailTemplate($toName, $temporaryPassword);
            $this->mailer->Body = $body;
            $this->mailer->AltBody = strip_tags(str_replace(['<br>', '<br/>'], "\n", $body));

            return $this->mailer->send();
            
        } catch (Exception $e) {
            error_log("Failed to send temporary password email to {$toEmail}: " . $e->getMessage());
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
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($toEmail, $toName);
            
            $this->mailer->Subject = 'Your Account Has Been Updated - DEV-QBO Sync';
            
            $body = $this->getAccountModifiedEmailTemplate($toName, $changes);
            $this->mailer->Body = $body;
            $this->mailer->AltBody = strip_tags(str_replace(['<br>', '<br/>'], "\n", $body));

            return $this->mailer->send();
            
        } catch (Exception $e) {
            error_log("Failed to send account modified email to {$toEmail}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Email template for welcome message
     */
    private function getWelcomeEmailTemplate(string $name, string $email, ?string $temporaryPassword): string
    {
        $baseUrl = $_ENV['BASE_URL'] ?? 'http://localhost/multi-company-Dev2Qbo/public';
        $loginUrl = rtrim($baseUrl, '/') . '/login.html';
        
        $passwordInfo = '';
        if ($temporaryPassword) {
            $passwordInfo = "
            <tr>
                <td style='padding: 20px; background-color: #fff3cd; border-left: 4px solid #ffc107; margin: 20px 0;'>
                    <h3 style='color: #856404; margin-top: 0;'>🔐 Your Temporary Password</h3>
                    <p style='margin: 10px 0; color: #333;'><strong>Temporary Password:</strong> <code style='background: #f8f9fa; padding: 5px 10px; border-radius: 4px; font-size: 16px; color: #d63384;'>{$temporaryPassword}</code></p>
                    <p style='color: #856404; margin-bottom: 0;'><strong>⚠️ Important:</strong> Please change this password immediately after your first login for security reasons.</p>
                </td>
            </tr>";
        }

        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        </head>
        <body style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Oxygen, Ubuntu, sans-serif; background-color: #f4f5f7; margin: 0; padding: 0;'>
            <table width='100%' cellpadding='0' cellspacing='0' style='background-color: #f4f5f7; padding: 20px;'>
                <tr>
                    <td align='center'>
                        <table width='600' cellpadding='0' cellspacing='0' style='background-color: #ffffff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);'>
                            <!-- Header -->
                            <tr>
                                <td style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;'>
                                    <h1 style='color: #ffffff; margin: 0; font-size: 28px;'>✨ Welcome to DEV-QBO Sync!</h1>
                                </td>
                            </tr>
                            
                            <!-- Body -->
                            <tr>
                                <td style='padding: 40px 30px;'>
                                    <h2 style='color: #333; margin-top: 0;'>Hello {$name}! 👋</h2>
                                    <p style='color: #555; line-height: 1.6; font-size: 16px;'>
                                        Your account has been successfully created on the <strong>DEV-QBO Sync Platform</strong>. 
                                        You can now synchronize your DevPos data with QuickBooks Online seamlessly.
                                    </p>
                                    
                                    <div style='background-color: #e6f7ed; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #33cc66;'>
                                        <p style='margin: 5px 0; color: #333;'><strong>📧 Email:</strong> {$email}</p>
                                    </div>
                                    
                                    {$passwordInfo}
                                    
                                    <div style='text-align: center; margin: 30px 0;'>
                                        <a href='{$loginUrl}' style='display: inline-block; background-color: #667eea; color: #ffffff; padding: 15px 40px; text-decoration: none; border-radius: 5px; font-weight: 600; font-size: 16px;'>
                                            🚀 Login to Your Account
                                        </a>
                                    </div>
                                    
                                    <p style='color: #555; line-height: 1.6; font-size: 14px; margin-top: 30px;'>
                                        If you have any questions or need assistance, please don't hesitate to contact our support team.
                                    </p>
                                </td>
                            </tr>
                            
                            <!-- Footer -->
                            <tr>
                                <td style='background-color: #f8f9fa; padding: 20px 30px; text-align: center; border-radius: 0 0 10px 10px; border-top: 1px solid #e0e0e0;'>
                                    <p style='color: #999; font-size: 12px; margin: 0;'>
                                        © " . date('Y') . " DEV-QBO Sync Platform. All rights reserved.
                                    </p>
                                    <p style='color: #999; font-size: 12px; margin: 10px 0 0 0;'>
                                        This is an automated message. Please do not reply to this email.
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>";
    }

    /**
     * Email template for password reset
     */
    private function getPasswordResetEmailTemplate(string $name, string $resetToken): string
    {
        $baseUrl = $_ENV['BASE_URL'] ?? 'http://localhost/multi-company-Dev2Qbo/public';
        $resetUrl = rtrim($baseUrl, '/') . '/password-recovery.html?token=' . urlencode($resetToken);
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        </head>
        <body style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Oxygen, Ubuntu, sans-serif; background-color: #f4f5f7; margin: 0; padding: 0;'>
            <table width='100%' cellpadding='0' cellspacing='0' style='background-color: #f4f5f7; padding: 20px;'>
                <tr>
                    <td align='center'>
                        <table width='600' cellpadding='0' cellspacing='0' style='background-color: #ffffff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);'>
                            <!-- Header -->
                            <tr>
                                <td style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;'>
                                    <h1 style='color: #ffffff; margin: 0; font-size: 28px;'>🔒 Password Reset Request</h1>
                                </td>
                            </tr>
                            
                            <!-- Body -->
                            <tr>
                                <td style='padding: 40px 30px;'>
                                    <h2 style='color: #333; margin-top: 0;'>Hello {$name},</h2>
                                    <p style='color: #555; line-height: 1.6; font-size: 16px;'>
                                        We received a request to reset your password for your DEV-QBO Sync account. 
                                        Click the button below to create a new password.
                                    </p>
                                    
                                    <div style='background-color: #fff3cd; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ffc107;'>
                                        <p style='margin: 0; color: #856404;'>
                                            <strong>⏰ This link will expire in 1 hour</strong> for security reasons.
                                        </p>
                                    </div>
                                    
                                    <div style='text-align: center; margin: 30px 0;'>
                                        <a href='{$resetUrl}' style='display: inline-block; background-color: #667eea; color: #ffffff; padding: 15px 40px; text-decoration: none; border-radius: 5px; font-weight: 600; font-size: 16px;'>
                                            🔑 Reset My Password
                                        </a>
                                    </div>
                                    
                                    <p style='color: #555; line-height: 1.6; font-size: 14px;'>
                                        Or copy and paste this URL into your browser:
                                    </p>
                                    <p style='background-color: #f8f9fa; padding: 15px; border-radius: 5px; word-break: break-all; font-size: 12px; color: #667eea;'>
                                        {$resetUrl}
                                    </p>
                                    
                                    <div style='background-color: #f8d7da; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #dc3545;'>
                                        <p style='margin: 0; color: #721c24;'>
                                            <strong>⚠️ Didn't request this?</strong><br>
                                            If you didn't request a password reset, please ignore this email. Your password will remain unchanged.
                                        </p>
                                    </div>
                                </td>
                            </tr>
                            
                            <!-- Footer -->
                            <tr>
                                <td style='background-color: #f8f9fa; padding: 20px 30px; text-align: center; border-radius: 0 0 10px 10px; border-top: 1px solid #e0e0e0;'>
                                    <p style='color: #999; font-size: 12px; margin: 0;'>
                                        © " . date('Y') . " DEV-QBO Sync Platform. All rights reserved.
                                    </p>
                                    <p style='color: #999; font-size: 12px; margin: 10px 0 0 0;'>
                                        This is an automated message. Please do not reply to this email.
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>";
    }

    /**
     * Email template for temporary password (admin reset)
     */
    private function getTemporaryPasswordEmailTemplate(string $name, string $temporaryPassword): string
    {
        $baseUrl = $_ENV['BASE_URL'] ?? 'http://localhost/multi-company-Dev2Qbo/public';
        $loginUrl = rtrim($baseUrl, '/') . '/login.html';
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        </head>
        <body style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Oxygen, Ubuntu, sans-serif; background-color: #f4f5f7; margin: 0; padding: 0;'>
            <table width='100%' cellpadding='0' cellspacing='0' style='background-color: #f4f5f7; padding: 20px;'>
                <tr>
                    <td align='center'>
                        <table width='600' cellpadding='0' cellspacing='0' style='background-color: #ffffff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);'>
                            <!-- Header -->
                            <tr>
                                <td style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;'>
                                    <h1 style='color: #ffffff; margin: 0; font-size: 28px;'>🔐 Password Reset by Administrator</h1>
                                </td>
                            </tr>
                            
                            <!-- Body -->
                            <tr>
                                <td style='padding: 40px 30px;'>
                                    <h2 style='color: #333; margin-top: 0;'>Hello {$name},</h2>
                                    <p style='color: #555; line-height: 1.6; font-size: 16px;'>
                                        Your account password has been reset by an administrator. 
                                        You can now log in using the temporary password below.
                                    </p>
                                    
                                    <div style='background-color: #fff3cd; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ffc107;'>
                                        <h3 style='color: #856404; margin-top: 0;'>🔑 Your Temporary Password</h3>
                                        <p style='margin: 10px 0; text-align: center;'>
                                            <code style='background: #f8f9fa; padding: 10px 20px; border-radius: 5px; font-size: 18px; color: #d63384; font-weight: bold; display: inline-block;'>{$temporaryPassword}</code>
                                        </p>
                                    </div>
                                    
                                    <div style='background-color: #f8d7da; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #dc3545;'>
                                        <p style='margin: 0; color: #721c24;'>
                                            <strong>⚠️ Security Notice:</strong><br>
                                            This is a temporary password valid for 24 hours. Please change it immediately after logging in to ensure your account security.
                                        </p>
                                    </div>
                                    
                                    <div style='text-align: center; margin: 30px 0;'>
                                        <a href='{$loginUrl}' style='display: inline-block; background-color: #667eea; color: #ffffff; padding: 15px 40px; text-decoration: none; border-radius: 5px; font-weight: 600; font-size: 16px;'>
                                            🚀 Login Now
                                        </a>
                                    </div>
                                    
                                    <p style='color: #555; line-height: 1.6; font-size: 14px;'>
                                        After logging in, go to your profile settings to set a new, secure password.
                                    </p>
                                </td>
                            </tr>
                            
                            <!-- Footer -->
                            <tr>
                                <td style='background-color: #f8f9fa; padding: 20px 30px; text-align: center; border-radius: 0 0 10px 10px; border-top: 1px solid #e0e0e0;'>
                                    <p style='color: #999; font-size: 12px; margin: 0;'>
                                        © " . date('Y') . " DEV-QBO Sync Platform. All rights reserved.
                                    </p>
                                    <p style='color: #999; font-size: 12px; margin: 10px 0 0 0;'>
                                        This is an automated message. Please do not reply to this email.
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>";
    }

    /**
     * Email template for account modifications
     */
    private function getAccountModifiedEmailTemplate(string $name, array $changes): string
    {
        $changesList = '';
        foreach ($changes as $field => $change) {
            $fieldName = ucwords(str_replace('_', ' ', $field));
            $changesList .= "<li style='margin: 10px 0; color: #333;'><strong>{$fieldName}:</strong> {$change['old']} → {$change['new']}</li>";
        }
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        </head>
        <body style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Oxygen, Ubuntu, sans-serif; background-color: #f4f5f7; margin: 0; padding: 0;'>
            <table width='100%' cellpadding='0' cellspacing='0' style='background-color: #f4f5f7; padding: 20px;'>
                <tr>
                    <td align='center'>
                        <table width='600' cellpadding='0' cellspacing='0' style='background-color: #ffffff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);'>
                            <!-- Header -->
                            <tr>
                                <td style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;'>
                                    <h1 style='color: #ffffff; margin: 0; font-size: 28px;'>ℹ️ Account Updated</h1>
                                </td>
                            </tr>
                            
                            <!-- Body -->
                            <tr>
                                <td style='padding: 40px 30px;'>
                                    <h2 style='color: #333; margin-top: 0;'>Hello {$name},</h2>
                                    <p style='color: #555; line-height: 1.6; font-size: 16px;'>
                                        Your account information has been updated by an administrator. 
                                        Here are the changes that were made:
                                    </p>
                                    
                                    <div style='background-color: #d1ecf1; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #17a2b8;'>
                                        <h3 style='color: #0c5460; margin-top: 0;'>📝 Changes Made:</h3>
                                        <ul style='margin: 10px 0; padding-left: 20px;'>
                                            {$changesList}
                                        </ul>
                                    </div>
                                    
                                    <div style='background-color: #f8d7da; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #dc3545;'>
                                        <p style='margin: 0; color: #721c24;'>
                                            <strong>⚠️ Security Notice:</strong><br>
                                            If you did not authorize these changes or believe your account has been compromised, 
                                            please contact your administrator immediately.
                                        </p>
                                    </div>
                                </td>
                            </tr>
                            
                            <!-- Footer -->
                            <tr>
                                <td style='background-color: #f8f9fa; padding: 20px 30px; text-align: center; border-radius: 0 0 10px 10px; border-top: 1px solid #e0e0e0;'>
                                    <p style='color: #999; font-size: 12px; margin: 0;'>
                                        © " . date('Y') . " DEV-QBO Sync Platform. All rights reserved.
                                    </p>
                                    <p style='color: #999; font-size: 12px; margin: 10px 0 0 0;'>
                                        This is an automated message. Please do not reply to this email.
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>";
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
