-- Update password_reset email template to use 6-digit code
UPDATE email_templates 
SET 
    body_html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, ''Segoe UI'', Roboto, sans-serif; background-color: #f4f5f7; margin: 0; padding: 20px;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
        <tr>
            <td style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
                <h1 style="color: #ffffff; margin: 0; font-size: 28px;">🔒 Password Reset Request</h1>
            </td>
        </tr>
        <tr>
            <td style="padding: 40px 30px;">
                <h2 style="color: #333; margin-top: 0;">Hello {{name}},</h2>
                <p style="color: #555; line-height: 1.6; font-size: 16px;">
                    We received a request to reset your password. Use the 6-digit code below to reset your password.
                </p>
                <div style="background-color: #e6f7ed; padding: 30px; border-radius: 8px; margin: 30px 0; border-left: 4px solid #33cc66; text-align: center;">
                    <p style="margin: 0 0 10px 0; color: #333; font-size: 14px; text-transform: uppercase; letter-spacing: 1px;"><strong>Your Reset Code</strong></p>
                    <p style="margin: 0; font-size: 48px; font-weight: bold; color: #667eea; letter-spacing: 8px; font-family: ''Courier New'', monospace;">{{reset_code}}</p>
                </div>
                <div style="background-color: #fff3cd; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ffc107;">
                    <p style="margin: 0; color: #856404;"><strong>⏰ This code will expire in 15 minutes</strong></p>
                </div>
                <p style="color: #555; line-height: 1.6; font-size: 14px; text-align: center;">
                    Enter this code on the password reset page to create a new password.
                </p>
                <div style="background-color: #f8d7da; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #dc3545;">
                    <p style="margin: 0; color: #721c24;"><strong>⚠️ Didn''t request this?</strong> Please ignore this email and your password will remain unchanged.</p>
                </div>
            </td>
        </tr>
        <tr>
            <td style="background-color: #f8f9fa; padding: 20px 30px; text-align: center; border-radius: 0 0 10px 10px; border-top: 1px solid #e0e0e0;">
                <p style="color: #999; font-size: 12px; margin: 0;">© {{year}} DEV-QBO Sync Platform. All rights reserved.</p>
            </td>
        </tr>
    </table>
</body>
</html>',
    body_text = 'Hello {{name}}, Your password reset code is: {{reset_code}} (expires in 15 minutes). Enter this code on the password reset page to create a new password.',
    available_variables = '["name", "reset_code", "year"]'
WHERE template_key = 'password_reset';
