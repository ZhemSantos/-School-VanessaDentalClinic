<?php
require_once __DIR__ . '/../includes/email_config.php';
// Re-require SMTPMailer to be sure (though email_config includes it)
require_once __DIR__ . '/../includes/SMTPMailer.php';

echo "Testing Email Sending...\n";
echo "Host: " . SMTP_HOST . "\n";
echo "Port: " . SMTP_PORT . "\n";
echo "User: " . SMTP_USERNAME . "\n";

$mailer = new SMTPMailer(SMTP_HOST, SMTP_PORT, SMTP_USERNAME, SMTP_PASSWORD);
$mailer->setDebug(true);

$to = 'vanessadentalclinictesting@gmail.com'; // Send to self for testing
$subject = "Debug Test Email";
$message = "This is a test email sent from the debug script.";
$fromName = SMTP_FROM_NAME;
$fromEmail = SMTP_FROM_EMAIL;

if ($mailer->send($to, $subject, $message, $fromName, $fromEmail)) {
    echo "\nEmail sent successfully!\n";
} else {
    echo "\nEmail sending failed.\n";
}
