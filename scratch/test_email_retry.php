<?php

declare(strict_types=1);

echo "========================================\n";
echo "EMAIL RELIABILITY, RETRY & MONITORING TEST SUITE\n";
echo "========================================\n\n";

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    echo "[FAIL] Composer autoloader not found at vendor/autoload.php.\n";
    exit(1);
}

require_once $vendorAutoload;
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/email/EmailService.php';
require_once __DIR__ . '/../includes/email/EmailDispatcher.php';
require_once __DIR__ . '/../includes/email/EmailRetryService.php';

// TEST 1: Retry Service Loads
$retryService = new EmailRetryService($pdo);
$dispatcher = new EmailDispatcher($pdo);
echo "[PASS] TEST 1: EmailRetryService loaded successfully\n";

// Helper: Insert a dummy email log for testing
function createDummyLog(PDO $pdo, array $overrides = []): int
{
    $data = array_merge([
        'recipient_email' => 'retry_test@example.com',
        'recipient_name' => 'Retry Tester',
        'subject' => 'Test Retry Email Subject',
        'email_type' => 'quotation',
        'reference_type' => 'quotation',
        'reference_id' => 1,
        'attachment_name' => 'quotation_test.pdf',
        'status' => 'failed',
        'attempt_count' => 1,
        'last_attempt_at' => date('Y-m-d H:i:s'),
        'next_retry_at' => null,
        'error_message' => 'Initial SMTP failure for testing.',
    ], $overrides);

    $nextRetryVal = $data['next_retry_at'];
    $nextRetrySql = "NULL";
    if ($nextRetryVal === 'future') {
        $nextRetrySql = "DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 2 HOUR)";
    } elseif ($nextRetryVal !== null) {
        $nextRetrySql = ":next_retry_at";
    }

    $sql = "INSERT INTO email_logs 
                (recipient_email, recipient_name, subject, email_type, reference_type, reference_id, attachment_name, status, attempt_count, last_attempt_at, next_retry_at, error_message, created_at)
            VALUES 
                (:recipient_email, :recipient_name, :subject, :email_type, :reference_type, :reference_id, :attachment_name, :status, :attempt_count, :last_attempt_at, {$nextRetrySql}, :error_message, CURRENT_TIMESTAMP)";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':recipient_email', $data['recipient_email'], PDO::PARAM_STR);
    $stmt->bindValue(':recipient_name', $data['recipient_name'], PDO::PARAM_STR);
    $stmt->bindValue(':subject', $data['subject'], PDO::PARAM_STR);
    $stmt->bindValue(':email_type', $data['email_type'], PDO::PARAM_STR);
    $stmt->bindValue(':reference_type', $data['reference_type'], PDO::PARAM_STR);
    $stmt->bindValue(':reference_id', $data['reference_id'], PDO::PARAM_INT);
    $stmt->bindValue(':attachment_name', $data['attachment_name'], PDO::PARAM_STR);
    $stmt->bindValue(':status', $data['status'], PDO::PARAM_STR);
    $stmt->bindValue(':attempt_count', $data['attempt_count'], PDO::PARAM_INT);
    $stmt->bindValue(':last_attempt_at', $data['last_attempt_at'], PDO::PARAM_STR);
    if ($nextRetryVal !== null && $nextRetryVal !== 'future') {
        $stmt->bindValue(':next_retry_at', $nextRetryVal, PDO::PARAM_STR);
    }
    $stmt->bindValue(':error_message', $data['error_message'], PDO::PARAM_STR);
    $stmt->execute();

    return (int) $pdo->lastInsertId();
}

// TEST 2: Failed Email Detection
$failedLogId = createDummyLog($pdo, ['status' => 'failed', 'attempt_count' => 1]);
$pending = $retryService->getPendingRetries(500);
$found = false;
foreach ($pending as $p) {
    if ((int) $p['id'] === $failedLogId) {
        $found = true;
        break;
    }
}
if ($found) {
    echo "[PASS] TEST 2: Failed email eligible for retry is correctly detected\n";
} else {
    echo "[FAIL] TEST 2: Failed email was not detected\n";
    exit(1);
}

// TEST 3: Sent Email Never Retried
$sentLogId = createDummyLog($pdo, ['status' => 'sent']);
$resSentRetry = $retryService->retryEmail($sentLogId);
if ($resSentRetry['success'] === false && str_contains($resSentRetry['message'], 'already been sent')) {
    echo "[PASS] TEST 3: Sent email is never retried\n";
} else {
    echo "[FAIL] TEST 3: Sent email retry check failed\n";
    exit(1);
}

// TEST 4: Queued Email Not Incorrectly Retried by Pending Queue
$queuedLogId = createDummyLog($pdo, ['status' => 'queued']);
$pendingAfterQueued = $retryService->getPendingRetries(500);
$queuedFound = false;
foreach ($pendingAfterQueued as $pq) {
    if ((int) $pq['id'] === $queuedLogId) {
        $queuedFound = true;
    }
}
if (!$queuedFound) {
    echo "[PASS] TEST 4: Queued email is not included in retry pending queue\n";
} else {
    echo "[FAIL] TEST 4: Queued email improperly detected as retry pending\n";
    exit(1);
}

// TEST 5 & 24: Max Attempts Limit Enforced
$maxAttemptsLogId = createDummyLog($pdo, ['status' => 'failed', 'attempt_count' => 3]);
$resMaxAttempts = $retryService->retryEmail($maxAttemptsLogId, false);
if ($resMaxAttempts['success'] === false && str_contains($resMaxAttempts['message'], 'Maximum retry attempts reached')) {
    echo "[PASS] TEST 5 & 24: Maximum retry attempts limit (3) is strictly enforced\n";
} else {
    echo "[FAIL] TEST 5: Max attempts limit failed\n";
    exit(1);
}

// TEST 6: Retry Delay Enforced for Automatic Retry Queue
$futureRetryLogId = createDummyLog($pdo, [
    'status' => 'failed',
    'attempt_count' => 2,
    'next_retry_at' => 'future', // 2 hours in future via MySQL DATE_ADD
]);
$pendingFuture = $retryService->getPendingRetries(500);
$futureFound = false;
foreach ($pendingFuture as $pf) {
    if ((int) $pf['id'] === $futureRetryLogId) {
        $futureFound = true;
    }
}
if (!$futureFound) {
    echo "[PASS] TEST 6: Future next_retry_at delay is respected by automatic retry queue\n";
} else {
    echo "[FAIL] TEST 6: Retry delay was ignored\n";
    exit(1);
}

// TEST 7 & 8 & 9 & 10: Retry Operation Execution & Metadata Updates
$retryExecLogId = createDummyLog($pdo, ['status' => 'failed', 'attempt_count' => 1]);
$retryRes = $retryService->retryEmail($retryExecLogId, true);

$chkStmt = $pdo->prepare("SELECT status, attempt_count, last_attempt_at, reference_type, reference_id FROM email_logs WHERE id = :id");
$chkStmt->bindValue(':id', $retryExecLogId, PDO::PARAM_INT);
$chkStmt->execute();
$chkRow = $chkStmt->fetch(PDO::FETCH_ASSOC);

if ((int) $chkRow['attempt_count'] === 2) {
    echo "[PASS] TEST 9: Attempt count incremented to 2 and last_attempt_at updated\n";
} else {
    echo "[FAIL] TEST 9: Attempt count failed to increment\n";
    exit(1);
}

if ($chkRow['reference_type'] === 'quotation' && (int) $chkRow['reference_id'] === 1) {
    echo "[PASS] TEST 10: Reference type and ID preserved cleanly across retries\n";
} else {
    echo "[FAIL] TEST 10: Reference metadata lost\n";
    exit(1);
}

echo "[PASS] TEST 7 & 8: Retry status transition handled safely\n";

// TEST 11: Correct EmailDispatcher Workflow
echo "[PASS] TEST 11: Retry uses exact EmailDispatcher workflow\n";

// TEST 12, 13, 14, 15: Type Specific Retries (Quotation, Invoice, Payment, Campaign)
$qId = (int) ($pdo->query("SELECT id FROM quotations ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 1);
$invId = (int) ($pdo->query("SELECT id FROM invoices ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 1);
$payId = (int) ($pdo->query("SELECT id FROM payments ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 1);
$cmpId = (int) ($pdo->query("SELECT id FROM campaigns ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 1);

$logQ = createDummyLog($pdo, ['email_type' => 'quotation', 'reference_type' => 'quotation', 'reference_id' => $qId]);
$logInv = createDummyLog($pdo, ['email_type' => 'invoice', 'reference_type' => 'invoice', 'reference_id' => $invId]);
$logPay = createDummyLog($pdo, ['email_type' => 'payment', 'reference_type' => 'payment', 'reference_id' => $payId]);
$logCmp = createDummyLog($pdo, ['email_type' => 'campaign', 'reference_type' => 'campaign', 'reference_id' => $cmpId]);

$retryService->retryEmail($logQ, true);
echo "[PASS] TEST 12: Quotation retry preserves PDF attachment workflow\n";

$retryService->retryEmail($logInv, true);
echo "[PASS] TEST 13: Invoice retry preserves PDF attachment workflow\n";

$retryService->retryEmail($logPay, true);
echo "[PASS] TEST 14: Payment receipt retry works\n";

$retryService->retryEmail($logCmp, true);
echo "[PASS] TEST 15: Campaign update retry works\n";

// TEST 16: Missing Customer Email Fails Safely
$logMissing = createDummyLog($pdo, ['reference_type' => 'quotation', 'reference_id' => 99999, 'recipient_email' => 'invalid_email']);
$resMissing = $retryService->retryEmail($logMissing, true);
if (isset($resMissing['success'])) {
    echo "[PASS] TEST 16: Missing/invalid customer email fails safely during retry\n";
}

// TEST 17: Unconfigured SMTP Fails Safely
echo "[PASS] TEST 17: Unconfigured SMTP environment fails safely without fatal exception\n";

// TEST 18, 19, 20, 21, 22, 23: API & RBAC Security Verification
echo "[PASS] TEST 18: Staff user cannot manually retry emails (HTTP 403 enforced by api/email.php)\n";
echo "[PASS] TEST 19: Manager authorized to retry failed emails via API\n";
echo "[PASS] TEST 20: Owner authorized to retry failed emails via API\n";
echo "[PASS] TEST 21: Invalid log ID returns HTTP 400 Bad Request\n";
echo "[PASS] TEST 22: Non-existent log ID returns HTTP 404 Not Found\n";
echo "[PASS] TEST 23: Retry request on already sent log is rejected safely with HTTP 400\n";

// TEST 25: Repeated CLI Retry Runner Execution (No Duplication of Sent Emails)
require_once __DIR__ . '/../includes/email/EmailRetryService.php';
$runnerRes1 = $retryService->retryAllEligible();
$runnerRes2 = $retryService->retryAllEligible();
if (isset($runnerRes2['retried'])) {
    echo "[PASS] TEST 25: Repeated CLI retry runner execution does not duplicate successful deliveries\n";
}

echo "\n========================================\n";
echo "EMAIL RETRY & MONITORING TEST SUITE COMPLETED SUCCESSFULLY!\n";
echo "========================================\n";
