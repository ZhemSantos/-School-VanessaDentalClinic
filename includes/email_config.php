<?php
/**
 * Email Configuration for Vanessa Nicolas Dental Clinic
 * Gmail SMTP Settings
 */

// Gmail SMTP Configuration
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'vanessadentalclinictesting@gmail.com');
define('SMTP_PASSWORD', 'ghkc pque gncp lbcq');
define('SMTP_FROM_EMAIL', 'vanessadentalclinictesting@gmail.com');
define('SMTP_FROM_NAME', 'Vanessa Nicolas Dental Clinic');

// Email Settings
define('EMAIL_ENABLED', true);
define('EMAIL_CHARSET', 'UTF-8');

require_once __DIR__ . '/SMTPMailer.php';

/**
 * Send email using SMTPMailer with Gmail SMTP
 */
function sendEmail($to, $subject, $message, $isHTML = true) {
    if (!EMAIL_ENABLED) {
        error_log("Email sending is disabled. Would send to: $to, Subject: $subject");
        return false;
    }
    
    // Validate email address
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("Invalid email address: $to");
        return false;
    }
    
    if ($isHTML) {
        // Wrap message in HTML template
        $message = getEmailTemplate($subject, $message);
    }
    
    $mailer = new SMTPMailer(SMTP_HOST, SMTP_PORT, SMTP_USERNAME, SMTP_PASSWORD);
    $result = $mailer->send($to, $subject, $message, SMTP_FROM_NAME, SMTP_FROM_EMAIL);
    
    if (!$result) {
        $GLOBALS['LAST_SMTP_ERROR'] = $mailer->getLastError();
    }
    
    return $result;
}

/**
 * Get HTML email template
 */
function getEmailTemplate($subject, $content) {
    return '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($subject) . '</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .email-container {
            background-color: #ffffff;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header {
            background: linear-gradient(135deg, #6A1B9A 0%, #8a11d4 100%);
            color: white;
            padding: 20px;
            border-radius: 10px 10px 0 0;
            margin: -30px -30px 30px -30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        .content {
            padding: 20px 0;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            text-align: center;
            color: #666;
            font-size: 12px;
        }
        .button {
            display: inline-block;
            padding: 12px 24px;
            background-color: #6A1B9A;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 10px 0;
        }
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
        }
        .alert-warning {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            color: #856404;
        }
        .alert-info {
            background-color: #d1ecf1;
            border-left: 4px solid #17a2b8;
            color: #0c5460;
        }
        .alert-success {
            background-color: #d4edda;
            border-left: 4px solid #28a745;
            color: #155724;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <h1>Vanessa Nicolas Dental Clinic</h1>
        </div>
        <div class="content">
            ' . $content . '
        </div>
        <div class="footer">
            <p>This is an automated message from Vanessa Nicolas Dental Clinic.</p>
            <p>Please do not reply to this email. For inquiries, contact us directly.</p>
            <p>&copy; ' . date('Y') . ' Vanessa Nicolas Dental Clinic. All rights reserved.</p>
        </div>
    </div>
</body>
</html>';
}

