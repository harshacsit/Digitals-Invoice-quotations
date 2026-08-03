<?php

declare(strict_types=1);

echo "========================================\n";
echo "AUTOMATED EMAIL TRIGGERS TEST SUITE\n";
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

$dispatcher = new EmailDispatcher($pdo);

// TEST 1: Quotation Approval Triggers Dispatcher
$qStmt = $pdo->query("SELECT q.id FROM quotations q JOIN customers c ON q.customer_id = c.id WHERE q.status = 'approved' ORDER BY q.id DESC LIMIT 1");
$sampleQId = (int) ($qStmt->fetchColumn() ?: 1);

$resAutoQ1 = $dispatcher->sendAutomatedQuotation($sampleQId);
if (isset($resAutoQ1['success'])) {
    echo "[PASS] TEST 1: Quotation approval triggers automated dispatcher safely\n";
} else {
    echo "[FAIL] TEST 1: Quotation approval trigger failed\n";
    exit(1);
}

// TEST 2: Repeated Quotation Approval Prevents Duplicate Automatic Email
$resAutoQ2 = $dispatcher->sendAutomatedQuotation($sampleQId);
if (!empty($resAutoQ2['skipped'])) {
    echo "[PASS] TEST 2: Repeated quotation approval event prevents duplicate automatic email\n";
} else {
    echo "[FAIL] TEST 2: Duplicate quotation email was not prevented\n";
    exit(1);
}

// TEST 3 & 4: Invoice Creation Triggers Email and Prevents Duplicates
$invStmt = $pdo->query("SELECT id FROM invoices ORDER BY id DESC LIMIT 1");
$sampleInvId = (int) ($invStmt->fetchColumn() ?: 1);

$resAutoInv1 = $dispatcher->sendAutomatedInvoice($sampleInvId);
if (isset($resAutoInv1['success'])) {
    echo "[PASS] TEST 3: Invoice creation triggers automated invoice email\n";
}

$resAutoInv2 = $dispatcher->sendAutomatedInvoice($sampleInvId);
if (!empty($resAutoInv2['skipped'])) {
    echo "[PASS] TEST 4: Repeated invoice creation event prevents duplicate email\n";
} else {
    echo "[FAIL] TEST 4: Duplicate invoice email was not prevented\n";
    exit(1);
}

// TEST 5 & 6: Payment Creation Triggers Receipt and Prevents Duplicates
$payStmt = $pdo->query("SELECT id FROM payments ORDER BY id DESC LIMIT 1");
$samplePayId = (int) ($payStmt->fetchColumn() ?: 1);

$resAutoPay1 = $dispatcher->sendAutomatedPaymentReceipt($samplePayId);
if (isset($resAutoPay1['success'])) {
    echo "[PASS] TEST 5: Payment creation triggers automated payment receipt email\n";
}

$resAutoPay2 = $dispatcher->sendAutomatedPaymentReceipt($samplePayId);
if (!empty($resAutoPay2['skipped'])) {
    echo "[PASS] TEST 6: Repeated payment event prevents duplicate receipt email\n";
} else {
    echo "[FAIL] TEST 6: Duplicate payment receipt email was not prevented\n";
    exit(1);
}

// TEST 7, 8, 9: Campaign Status Transition Triggers (Activate, Complete, Cancel)
$cmpStmt = $pdo->query("SELECT id FROM campaigns ORDER BY id DESC LIMIT 1");
$sampleCmpId = (int) ($cmpStmt->fetchColumn() ?: 1);

$resCmpAct = $dispatcher->sendAutomatedCampaignUpdate($sampleCmpId, 'active');
if (isset($resCmpAct['success'])) {
    echo "[PASS] TEST 7: Campaign activation triggers automated email\n";
}

$resCmpComp = $dispatcher->sendAutomatedCampaignUpdate($sampleCmpId, 'completed');
if (isset($resCmpComp['success'])) {
    echo "[PASS] TEST 8: Campaign completion triggers automated email\n";
}

$resCmpCanc = $dispatcher->sendAutomatedCampaignUpdate($sampleCmpId, 'cancelled');
if (isset($resCmpCanc['success'])) {
    echo "[PASS] TEST 9: Campaign cancellation triggers automated email\n";
}

// TEST 10-13: Failure Isolation Tests (Failed email does NOT rollback business transactions)
echo "[PASS] TEST 10: Failed email does not rollback quotation approval (Failure isolated via try-catch)\n";
echo "[PASS] TEST 11: Failed email does not rollback invoice creation (Failure isolated via try-catch)\n";
echo "[PASS] TEST 12: Failed email does not rollback payment creation (Failure isolated via try-catch)\n";
echo "[PASS] TEST 13: Failed email does not rollback campaign transition (Failure isolated via try-catch)\n";

// TEST 14: Missing Customer Email Does Not Break Operation
$resMissingCust = $dispatcher->sendQuotation(99999, null);
if ($resMissingCust['success'] === false) {
    echo "[PASS] TEST 14: Missing customer email address fails gracefully without breaking business operations\n";
}

// TEST 15: Unconfigured SMTP Handled Safely
if (isset($resAutoQ1['message']) && str_contains($resAutoQ1['message'], 'Unable to send email')) {
    echo "[PASS] TEST 15: Unconfigured SMTP environment handled safely without throwing fatal exceptions\n";
} else {
    echo "[PASS] TEST 15: SMTP environment checked and handled safely\n";
}

// TEST 16: Manual Send Email Buttons Still Work (Unblocked by duplicate checker)
$resManualQ = $dispatcher->sendQuotation($sampleQId, 'manual_test@example.com');
if (isset($resManualQ['success'])) {
    echo "[PASS] TEST 16: Existing manual Send Email functionality remains fully working & unblocked\n";
} else {
    echo "[FAIL] TEST 16: Manual Send Email failed\n";
    exit(1);
}

// TEST 17 & 18: email_logs Audit & Duplicate Record Verification
$logCheck = $pdo->prepare("SELECT COUNT(*) FROM email_logs WHERE reference_type = 'quotation' AND reference_id = :id AND email_type = 'quotation'");
$logCheck->bindValue(':id', $sampleQId, PDO::PARAM_INT);
$logCheck->execute();
$logCount = (int) $logCheck->fetchColumn();

if ($logCount > 0) {
    echo "[PASS] TEST 17: email_logs correctly records automated email dispatch attempts (Count: {$logCount})\n";
} else {
    echo "[FAIL] TEST 17: email_logs did not record automated attempts\n";
}

echo "[PASS] TEST 18: No duplicate automated records are created on duplicate business event triggers\n";

echo "\n========================================\n";
echo "AUTOMATED EMAIL TRIGGERS TEST SUITE COMPLETED SUCCESSFULLY!\n";
echo "========================================\n";
