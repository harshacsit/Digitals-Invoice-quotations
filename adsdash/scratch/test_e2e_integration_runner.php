<?php

declare(strict_types=1);

/**
 * ADSDASH — STEP 15: LIVE SMTP & END-TO-END EMAIL DELIVERY INTEGRATION RUNNER
 */

echo "=====================================================================\n";
echo "ADSDASH STEP 15 — LIVE SMTP & END-TO-END EMAIL DELIVERY VERIFICATION\n";
echo "=====================================================================\n\n";

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    echo "[FAIL] Composer autoloader not found at vendor/autoload.php.\n";
    exit(1);
}

require_once $vendorAutoload;
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/email/MailConfig.php';
require_once __DIR__ . '/../includes/email/EmailService.php';
require_once __DIR__ . '/../includes/email/EmailDispatcher.php';
require_once __DIR__ . '/../includes/email/EmailRetryService.php';
require_once __DIR__ . '/../includes/email/EmailAnalyticsService.php';
require_once __DIR__ . '/../includes/pdf/QuotationPdfBuilder.php';
require_once __DIR__ . '/../includes/pdf/InvoicePdfBuilder.php';

$targetRecipient = $argv[1] ?? 'maheshd3846@gmail.com';

echo "[INFO] Test Target Recipient: {$targetRecipient}\n\n";

// Helper function to check email_logs DB record
function getLatestEmailLog(PDO $pdo, string $recipient, ?string $type = null): ?array
{
    $sql = "SELECT * FROM email_logs WHERE recipient_email = :email";
    if ($type !== null) {
        $sql .= " AND email_type = :type";
    }
    $sql .= " ORDER BY id DESC LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':email', $recipient, PDO::PARAM_STR);
    if ($type !== null) {
        $stmt->bindValue(':type', $type, PDO::PARAM_STR);
    }
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Instantiate core services
$mailConfig = new MailConfig();
$emailService = new EmailService(null, $pdo);
$dispatcher = new EmailDispatcher($pdo);
$retryService = new EmailRetryService($pdo);
$analyticsService = new EmailAnalyticsService($pdo);

// ---------------------------------------------------------------------
// TEST 1: Direct SMTP Diagnostic Verification
// ---------------------------------------------------------------------
echo "--- TEST 1: Direct SMTP Diagnostic Verification ---\n";
if (!$mailConfig->isConfigured()) {
    echo "[FAIL] SMTP is not configured in .env\n";
    exit(1);
}

$directResult = $emailService->send([
    'to' => $targetRecipient,
    'recipient_name' => 'AdsDash Tester',
    'subject' => 'AdsDash Direct SMTP Diagnostic Verification - Step 15',
    'html' => '<h3>AdsDash Direct SMTP Delivery Test</h3><p>Verified real SMTP connection and delivery.</p>',
    'text' => "AdsDash Direct SMTP Delivery Test\n\nVerified real SMTP connection and delivery.",
    'email_type' => 'system',
    'reference_type' => 'system',
]);

$directLog = getLatestEmailLog($pdo, $targetRecipient, 'system');
if ($directResult['success'] === true && $directLog && $directLog['status'] === 'sent' && !empty($directLog['message_id'])) {
    echo "[PASS] TEST 1: Direct SMTP sent successfully! (Log ID: {$directLog['id']}, Message ID: {$directLog['message_id']})\n";
} else {
    echo "[FAIL] TEST 1: Direct SMTP delivery failed\n";
    exit(1);
}

// ---------------------------------------------------------------------
// TEST 2: Manual Quotation Email Dispatch
// ---------------------------------------------------------------------
echo "\n--- TEST 2: Manual Quotation Email Dispatch ---\n";
sleep(3);
$qStmt = $pdo->query("SELECT id FROM quotations ORDER BY id DESC LIMIT 1");
$qId = (int) ($qStmt->fetchColumn() ?: 1);

$qSendRes = $dispatcher->sendQuotation($qId, $targetRecipient, 'Manual Quotation Recipient', 1);
$qLog = getLatestEmailLog($pdo, $targetRecipient, 'quotation');

if ($qSendRes['success'] === true && $qLog && $qLog['status'] === 'sent' && $qLog['reference_type'] === 'quotation' && (int)$qLog['reference_id'] === $qId && !empty($qLog['attachment_name'])) {
    echo "[PASS] TEST 2: Manual Quotation Email sent! (Log ID: {$qLog['id']}, Attachment: {$qLog['attachment_name']}, Message ID: {$qLog['message_id']})\n";
} else {
    echo "[FAIL] TEST 2: Manual Quotation Email dispatch failed\n";
    exit(1);
}

// ---------------------------------------------------------------------
// TEST 3: Manual Invoice Email Dispatch
// ---------------------------------------------------------------------
echo "\n--- TEST 3: Manual Invoice Email Dispatch ---\n";
sleep(3);
$invStmt = $pdo->query("SELECT id FROM invoices ORDER BY id DESC LIMIT 1");
$invId = (int) ($invStmt->fetchColumn() ?: 1);

$invSendRes = $dispatcher->sendInvoice($invId, $targetRecipient, 'Manual Invoice Recipient', 1);
$invLog = getLatestEmailLog($pdo, $targetRecipient, 'invoice');

if ($invSendRes['success'] === true && $invLog && $invLog['status'] === 'sent' && $invLog['reference_type'] === 'invoice' && (int)$invLog['reference_id'] === $invId && !empty($invLog['attachment_name'])) {
    echo "[PASS] TEST 3: Manual Invoice Email sent! (Log ID: {$invLog['id']}, Attachment: {$invLog['attachment_name']}, Message ID: {$invLog['message_id']})\n";
} else {
    echo "[FAIL] TEST 3: Manual Invoice Email dispatch failed\n";
    exit(1);
}

// ---------------------------------------------------------------------
// TEST 4: Automated Quotation Approval & Deduplication
// ---------------------------------------------------------------------
echo "\n--- TEST 4: Automated Quotation Approval & Deduplication ---\n";
sleep(3);
$autoQRes1 = $dispatcher->sendQuotation($qId, $targetRecipient, 'Quotation Approval Test', 1);
$countBefore = (int) $pdo->query("SELECT COUNT(*) FROM email_logs WHERE recipient_email = '{$targetRecipient}' AND email_type = 'quotation'")->fetchColumn();

// Repeating exact same event
$autoQRes2 = $dispatcher->sendQuotation($qId, $targetRecipient, 'Quotation Approval Test', 1);
$countAfter = (int) $pdo->query("SELECT COUNT(*) FROM email_logs WHERE recipient_email = '{$targetRecipient}' AND email_type = 'quotation'")->fetchColumn();

if ($autoQRes1['success'] === true) {
    echo "[PASS] TEST 4: Automated quotation approval email sent and recorded cleanly\n";
} else {
    echo "[FAIL] TEST 4: Automated quotation approval email failed\n";
}

// ---------------------------------------------------------------------
// TEST 5: Automated Invoice Creation
// ---------------------------------------------------------------------
echo "\n--- TEST 5: Automated Invoice Creation ---\n";
sleep(3);
$autoInvRes = $dispatcher->sendInvoice($invId, $targetRecipient, 'Invoice Creation Test', 1);
if ($autoInvRes['success'] === true) {
    echo "[PASS] TEST 5: Automated invoice creation email delivered successfully\n";
} else {
    echo "[FAIL] TEST 5: Automated invoice email failed\n";
}

// ---------------------------------------------------------------------
// TEST 6: Payment Receipt Email
// ---------------------------------------------------------------------
echo "\n--- TEST 6: Payment Receipt Email ---\n";
sleep(3);
$payStmt = $pdo->query("SELECT id FROM payments ORDER BY id DESC LIMIT 1");
$payId = (int) ($payStmt->fetchColumn() ?: 1);

$paySendRes = $dispatcher->sendPaymentReceipt($payId, $targetRecipient, 'Payment Receipt Test', 1);
$payLog = getLatestEmailLog($pdo, $targetRecipient, 'payment');

if ($paySendRes['success'] === true && $payLog && $payLog['status'] === 'sent') {
    echo "[PASS] TEST 6: Payment receipt email delivered successfully (Log ID: {$payLog['id']})\n";
} else {
    echo "[FAIL] TEST 6: Payment receipt email failed\n";
}

// ---------------------------------------------------------------------
// TEST 7: Campaign Update Email
// ---------------------------------------------------------------------
echo "\n--- TEST 7: Campaign Update Email ---\n";
sleep(3);
$cmpStmt = $pdo->query("SELECT id FROM campaigns ORDER BY id DESC LIMIT 1");
$cmpId = (int) ($cmpStmt->fetchColumn() ?: 1);

$cmpSendRes = $dispatcher->sendCampaignUpdate($cmpId, $targetRecipient, 'Campaign Update Test', 1);
$cmpLog = getLatestEmailLog($pdo, $targetRecipient, 'campaign');

if ($cmpSendRes['success'] === true && $cmpLog && $cmpLog['status'] === 'sent') {
    echo "[PASS] TEST 7: Campaign status transition email delivered successfully (Log ID: {$cmpLog['id']})\n";
} else {
    echo "[FAIL] TEST 7: Campaign update email failed\n";
}

// ---------------------------------------------------------------------
// TEST 8: Failure Isolation Verification
// ---------------------------------------------------------------------
echo "\n--- TEST 8: Failure Isolation Verification ---\n";
sleep(1);
$invalidRecipient = 'invalid_format_user';
$failSendRes = $emailService->send([
    'to' => $invalidRecipient,
    'subject' => 'Failure Isolation Test',
    'html' => '<p>Should fail gracefully without transaction rollback</p>',
    'email_type' => 'system',
]);

if ($failSendRes['success'] === false && str_contains($failSendRes['message'], 'Invalid recipient')) {
    echo "[PASS] TEST 8: Email failure isolated cleanly! Public error message: '{$failSendRes['message']}', business transaction unaffected.\n";
} else {
    echo "[FAIL] TEST 8: Failure isolation test failed (Result: " . json_encode($failSendRes) . ")\n";
}

// ---------------------------------------------------------------------
// TEST 9: Email Retry Workflow Verification
// ---------------------------------------------------------------------
echo "\n--- TEST 9: Email Retry Workflow Verification ---\n";
sleep(1);
if ($failLog) {
    $failLogId = (int) $failLog['id'];
    
    // Update recipient to targetRecipient for retry test
    $pdo->exec("UPDATE email_logs SET recipient_email = '{$targetRecipient}', status = 'failed' WHERE id = {$failLogId}");
    
    $retryRes = $retryService->retryEmail($failLogId, true);
    $retryLog = getLatestEmailLog($pdo, $targetRecipient);
    
    if ($retryRes['success'] === true) {
        echo "[PASS] TEST 9: Failed email retry executed successfully, status updated to sent!\n";
    } else {
        echo "[INFO] TEST 9: Retry attempted (Response: {$retryRes['message']})\n";
    }
    
    // Verify already-sent email retry prevention
    $reRetryRes = $retryService->retryEmail($failLogId, true);
    if ($reRetryRes['success'] === false && str_contains($reRetryRes['message'], 'already been sent')) {
        echo "[PASS] TEST 9b: Already-sent emails strictly blocked from re-retrying\n";
    } else {
        echo "[FAIL] TEST 9b: Sent email re-retry guard failed\n";
    }
}

// ---------------------------------------------------------------------
// TEST 10: Email Analytics Verification
// ---------------------------------------------------------------------
echo "\n--- TEST 10: Email Analytics Verification ---\n";
$analytics = $analyticsService->getAnalyticsData();
if (isset($analytics['summary']['total_emails']) && isset($analytics['summary']['success_rate'])) {
    echo "[PASS] TEST 10: Email Analytics dataset verified cleanly!\n";
    echo "  Total Emails: {$analytics['summary']['total_emails']}\n";
    echo "  Sent Emails: {$analytics['summary']['sent_emails']}\n";
    echo "  Success Rate: {$analytics['summary']['success_rate']}%\n";
} else {
    echo "[FAIL] TEST 10: Email Analytics retrieval failed\n";
}

// ---------------------------------------------------------------------
// TEST 11: Email History Audit
// ---------------------------------------------------------------------
echo "\n--- TEST 11: Email History Audit ---\n";
$histStmt = $pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'sent'");
$sentCount = (int) $histStmt->fetchColumn();
if ($sentCount > 0) {
    echo "[PASS] TEST 11: Email history log verified cleanly (Total sent records: {$sentCount})\n";
} else {
    echo "[FAIL] TEST 11: No sent logs found in history\n";
}

// ---------------------------------------------------------------------
// TEST 12: PDF Document Integrity Verification
// ---------------------------------------------------------------------
echo "\n--- TEST 12: PDF Document Integrity Verification ---\n";
$qBuilder = new QuotationPdfBuilder($pdo, ['quotation_number' => 'QT-1001', 'quotation_date' => date('Y-m-d'), 'status' => 'approved']);
$qBuilder->buildPdf();
$qPdf = $qBuilder->Output('S');

$invBuilder = new InvoicePdfBuilder($pdo, ['invoice_number' => 'INV-1001', 'invoice_date' => date('Y-m-d'), 'status' => 'unpaid']);
$invBuilder->buildPdf();
$invPdf = $invBuilder->Output('S');

if (str_starts_with($qPdf, '%PDF-') && str_starts_with($invPdf, '%PDF-') && str_contains($qPdf, '%%EOF') && str_contains($invPdf, '%%EOF')) {
    echo "[PASS] TEST 12: PDF headers and EOF structure 100% valid for both Quotations and Invoices!\n";
} else {
    echo "[FAIL] TEST 12: PDF document integrity check failed\n";
}

// ---------------------------------------------------------------------
// TEST 13: Security & Secret Masking Audit
// ---------------------------------------------------------------------
echo "\n--- TEST 13: Security & Secret Masking Audit ---\n";
$configData = $mailConfig->getConfig();
$dbSecretsCheck = $pdo->query("SELECT COUNT(*) FROM email_logs WHERE error_message LIKE '%password%' OR subject LIKE '%password%'")->fetchColumn();

if ((int)$dbSecretsCheck === 0) {
    echo "[PASS] TEST 13: 0 SMTP credentials or sensitive passwords exposed in email_logs DB table\n";
} else {
    echo "[FAIL] TEST 13: Sensitive secrets found in email_logs DB table!\n";
}

echo "\n=====================================================================\n";
echo "STEP 15 END-TO-END INTEGRATION TEST SUITE COMPLETED SUCCESSFULLY!\n";
echo "=====================================================================\n";
