<?php

declare(strict_types=1);

echo "========================================\n";
echo "SMTP REAL DELIVERY & DIAGNOSTIC VERIFIER\n";
echo "========================================\n\n";

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    echo "[FAIL] Composer autoloader not found at vendor/autoload.php.\n";
    exit(1);
}

require_once $vendorAutoload;
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/email/MailConfig.php';
require_once __DIR__ . '/../includes/email/EmailService.php';

$mailConfig = new MailConfig();
$configData = $mailConfig->getConfig();
$isConfigured = $mailConfig->isConfigured();

echo "SMTP Configured: " . ($isConfigured ? 'YES' : 'NO') . "\n";
echo "SMTP Host: " . ($configData['host'] ? $configData['host'] : '[None]') . "\n";
echo "SMTP Port: " . $configData['port'] . "\n";
echo "SMTP Encryption: " . strtoupper($configData['encryption']) . "\n";
echo "Sender Address: " . ($configData['from_address'] ? $configData['from_address'] : '[None]') . "\n\n";

// Function to safely mask email address for console display
function maskEmail(string $email): string
{
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) {
        return '***';
    }
    $name = $parts[0];
    $domain = $parts[1];

    if (strlen($name) <= 2) {
        $maskedName = substr($name, 0, 1) . '*';
    } else {
        $maskedName = substr($name, 0, 1) . str_repeat('*', max(1, strlen($name) - 2)) . substr($name, -1);
    }

    return $maskedName . '@' . $domain;
}

$recipientArg = $argv[1] ?? null;

if (!$isConfigured) {
    echo "========================================\n";
    echo "RESULT: SMTP configuration incomplete — real delivery test skipped.\n";
    echo "Notice: Real SMTP credentials (MAIL_HOST, MAIL_USERNAME, MAIL_PASSWORD) are not present in .env.\n";
    echo "The application is operating safely with default unconfigured error handling.\n";
    echo "========================================\n";
    exit(0);
}

if (empty($recipientArg) || filter_var($recipientArg, FILTER_VALIDATE_EMAIL) === false) {
    echo "[INFO] Real SMTP credentials ARE configured.\n";
    echo "Usage to run a real inbox delivery test: php scratch/test_smtp_delivery.php recipient@example.com\n";
    exit(0);
}

$recipientEmail = trim($recipientArg);
$maskedRecipient = maskEmail($recipientEmail);

echo "Attempting real SMTP email delivery...\n";
echo "Recipient: {$maskedRecipient}\n";

$emailService = new EmailService(null, $pdo);

$sendResult = $emailService->send([
    'to' => $recipientEmail,
    'recipient_name' => 'Real Delivery Tester',
    'subject' => 'AdsDash Real SMTP Verification Test',
    'html' => '<h3>AdsDash Real SMTP Verification</h3><p>This is a real test email sent from Bhimavaram Digitals AdsDash application.</p>',
    'text' => "AdsDash Real SMTP Verification\n\nThis is a real test email sent from Bhimavaram Digitals AdsDash application.",
    'email_type' => 'system',
    'reference_type' => 'system',
]);

// Verify database log entry status
$logStmt = $pdo->prepare("SELECT id, status, message_id, error_message FROM email_logs WHERE recipient_email = :email ORDER BY id DESC LIMIT 1");
$logStmt->bindValue(':email', $recipientEmail, PDO::PARAM_STR);
$logStmt->execute();
$logRow = $logStmt->fetch(PDO::FETCH_ASSOC);

echo "\n----------------------------------------\n";
if ($sendResult['success'] === true) {
    echo "Send Result: SUCCESS\n";
    echo "Log Status: " . ($logRow['status'] ?? 'unknown') . "\n";
    echo "Message ID: " . ($logRow['message_id'] ?? 'N/A') . "\n";
    echo "----------------------------------------\n";
    echo "[PASS] Real SMTP delivery verified successfully!\n";
} else {
    echo "Send Result: FAILED\n";
    echo "Log Status: " . ($logRow['status'] ?? 'failed') . "\n";
    echo "Public Response Message: " . $sendResult['message'] . "\n";
    echo "----------------------------------------\n";
    echo "[FAIL] Real SMTP delivery failed. Detailed technical error recorded safely in error_log and email_logs.\n";
}
echo "========================================\n";
