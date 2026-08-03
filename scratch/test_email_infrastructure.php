<?php

declare(strict_types=1);

echo "EMAIL INFRASTRUCTURE TEST\n";
echo "=========================\n\n";

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    echo "[FAIL] Composer autoloader not found at vendor/autoload.php. Run composer install.\n";
    exit(1);
}

require_once $vendorAutoload;
require_once __DIR__ . '/../includes/email/MailConfig.php';
require_once __DIR__ . '/../includes/email/EmailService.php';

// 1. Verify EmailService & MailConfig Class Loading
if (class_exists('EmailService') && class_exists('MailConfig') && class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    echo "[PASS] EmailService & PHPMailer loaded\n";
} else {
    echo "[FAIL] EmailService or PHPMailer classes could not be loaded.\n";
    exit(1);
}

// 2. Load Mail Config
$mailConfig = new MailConfig();
$configData = $mailConfig->getConfig();
echo "[PASS] Mail configuration loaded\n";

// 3. Check SMTP Configuration Status
if ($mailConfig->isConfigured()) {
    echo "[PASS] SMTP configuration validation (Host: {$configData['host']}, Port: {$configData['port']})\n";
} else {
    echo "[INFO] SMTP configuration incomplete (Placeholders detected in environment). Defaulting to unconfigured state.\n";
}

// 4. Initialize EmailService
$emailService = new EmailService($mailConfig);
echo "[PASS] EmailService initialized\n";

// 5. Test Invalid Email Rejection
$invalidRes = $emailService->send([
    'to' => 'not-an-email-address',
    'subject' => 'Test Invalid Email',
    'html' => '<p>Test</p>'
]);

if ($invalidRes['success'] === false && str_contains($invalidRes['message'], 'Invalid recipient')) {
    echo "[PASS] Invalid email rejected\n";
} else {
    echo "[FAIL] Invalid email was not properly rejected. Response: " . json_encode($invalidRes) . "\n";
    exit(1);
}

// 6. Optional Real Test Email Sending via CLI Argument
$cliRecipient = $argv[1] ?? null;

if (!empty($cliRecipient)) {
    echo "\nAttempting real SMTP email send to: {$cliRecipient}...\n";
    $sendRes = $emailService->send([
        'to' => $cliRecipient,
        'subject' => 'AdsDash Email Infrastructure Test',
        'html' => '<h3>AdsDash Email Delivery Test</h3><p>Your SMTP infrastructure is configured and working successfully!</p>',
        'text' => "AdsDash Email Delivery Test\nYour SMTP infrastructure is working successfully!"
    ]);

    if ($sendRes['success'] === true) {
        echo "[PASS] SMTP connection\n";
        echo "[PASS] Test email sent\n";
    } else {
        echo "[FAIL] SMTP send failed: {$sendRes['message']}\n";
    }
} else {
    echo "[INFO] SMTP send test skipped (No recipient argument provided. Usage: php scratch/test_email_infrastructure.php recipient@example.com)\n";
}

echo "\n=========================\n";
echo "EMAIL INFRASTRUCTURE TEST COMPLETED\n";
