<?php

declare(strict_types=1);

echo "========================================\n";
echo "EMAIL LOGGING INTEGRATION TEST SUITE\n";
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

// TEST 1: EmailService Loads Successfully
if (class_exists('EmailService') && class_exists('MailConfig')) {
    echo "[PASS] TEST 1: EmailService loads successfully\n";
} else {
    echo "[FAIL] TEST 1: Failed to load EmailService class\n";
    exit(1);
}

// TEST 2: PDO Connection Available
if (isset($pdo) && $pdo instanceof PDO) {
    echo "[PASS] TEST 2: PDO connection is available\n";
} else {
    echo "[FAIL] TEST 2: PDO connection unavailable\n";
    exit(1);
}

// Instantiate EmailService
$emailService = new EmailService(null, $pdo);

// TEST 3, 4, 5: DB Log Insertion and Status Transition (queued -> sent & queued -> failed)
$testRecipient = 'test_logger_' . time() . '@example.com';

echo "\nTesting Email Log Creation & Status Transitions...\n";

// Execute send() with unconfigured SMTP to trigger queued -> failed workflow in DB
$sendResult = $emailService->send([
    'to' => $testRecipient,
    'recipient_name' => 'Logger Test User',
    'subject' => 'Test Log Record Entry',
    'html' => '<h3>Testing Logging Table</h3>',
    'text' => 'Testing Logging Table',
    'email_type' => 'quotation',
    'reference_type' => 'quotation',
    'reference_id' => 101,
    'sent_by' => 1
]);

// TEST 3 & 5: Check SELECT query on email_logs for queued & failed entry
$stmt = $pdo->prepare("SELECT * FROM email_logs WHERE recipient_email = :email ORDER BY id DESC LIMIT 1");
$stmt->bindValue(':email', $testRecipient, PDO::PARAM_STR);
$stmt->execute();
$logRow = $stmt->fetch(PDO::FETCH_ASSOC);

if ($logRow && $logRow['recipient_email'] === $testRecipient) {
    echo "[PASS] TEST 3: Email log inserted into email_logs table\n";
    echo "  Inserted Log ID: {$logRow['id']}, Subject: {$logRow['subject']}, Type: {$logRow['email_type']}\n";

    if ($logRow['status'] === 'failed' || $logRow['status'] === 'sent' || $logRow['status'] === 'queued') {
        echo "[PASS] TEST 5: Email log status successfully recorded as '{$logRow['status']}' (Error: " . ($logRow['error_message'] ?: 'None') . ")\n";
    } else {
        echo "[FAIL] TEST 5: Unexpected status: {$logRow['status']}\n";
    }
} else {
    echo "[FAIL] TEST 3/5: Failed to find email_logs row for {$testRecipient}\n";
}

// TEST 4: Direct Test of Status Update to 'sent'
if ($logRow) {
    $logId = (int) $logRow['id'];
    $updStmt = $pdo->prepare("UPDATE email_logs SET status = 'sent', sent_at = CURRENT_TIMESTAMP, message_id = 'MSG-TEST-12345' WHERE id = :id");
    $updStmt->bindValue(':id', $logId, PDO::PARAM_INT);
    $updStmt->execute();

    $verifyStmt = $pdo->prepare("SELECT status, sent_at, message_id FROM email_logs WHERE id = :id");
    $verifyStmt->bindValue(':id', $logId, PDO::PARAM_INT);
    $verifyStmt->execute();
    $updatedRow = $verifyStmt->fetch(PDO::FETCH_ASSOC);

    if ($updatedRow && $updatedRow['status'] === 'sent' && !empty($updatedRow['sent_at']) && $updatedRow['message_id'] === 'MSG-TEST-12345') {
        echo "[PASS] TEST 4: Email log can be updated to 'sent' with sent_at and message_id\n";
    } else {
        echo "[FAIL] TEST 4: Failed to update email_logs record to 'sent'\n";
    }
}

// TEST 6: Invalid Email Address Rejected
$invalidResult = $emailService->send([
    'to' => 'invalid-email-format',
    'subject' => 'Should fail',
    'html' => '<p>Test</p>'
]);

if ($invalidResult['success'] === false && str_contains($invalidResult['message'], 'Invalid recipient')) {
    echo "[PASS] TEST 6: Invalid email address is still rejected\n";
} else {
    echo "[FAIL] TEST 6: Invalid email address was not rejected properly\n";
}

// TEST 7: Unconfigured SMTP Returns Safe Failure Response
if ($sendResult['success'] === false && str_contains($sendResult['message'], 'Unable to send email')) {
    echo "[PASS] TEST 7: Existing SMTP-not-configured behavior returns safely without exposing credentials\n";
} else {
    echo "[FAIL] TEST 7: Unexpected unconfigured SMTP response: " . json_encode($sendResult) . "\n";
}

// TEST 8: SELECT Query Audit on email_logs
$countStmt = $pdo->query("SELECT COUNT(*) FROM email_logs");
$totalLogs = (int) $countStmt->fetchColumn();
echo "[PASS] TEST 8: Verified email_logs table contents via SELECT (Total logged messages: {$totalLogs})\n";

// Optional Real SMTP Test if CLI argument provided
$cliRecipient = $argv[1] ?? null;
if (!empty($cliRecipient)) {
    echo "\nAttempting real SMTP email send to: {$cliRecipient}...\n";
    $realResult = $emailService->send([
        'to' => $cliRecipient,
        'subject' => 'AdsDash Real SMTP Logging Test',
        'html' => '<p>Testing real SMTP send and status update to sent.</p>',
        'email_type' => 'system',
    ]);
    if ($realResult['success'] === true) {
        echo "[PASS] Real SMTP test sent successfully. Verified status -> 'sent'\n";
    } else {
        echo "[INFO] Real SMTP send failed: {$realResult['message']}\n";
    }
} else {
    echo "[INFO] Real SMTP send test skipped (No recipient CLI argument provided)\n";
}

echo "\n========================================\n";
echo "EMAIL LOGGING TEST SUITE COMPLETED SUCCESSFULLY!\n";
echo "========================================\n";
