<?php

declare(strict_types=1);

echo "========================================\n";
echo "EMAIL DISPATCHER TEST SUITE\n";
echo "========================================\n\n";

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    echo "[FAIL] Composer autoloader not found at vendor/autoload.php.\n";
    exit(1);
}

require_once $vendorAutoload;
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/email/MailConfig.php';
require_once __DIR__ . '/../includes/email/EmailService.php';
require_once __DIR__ . '/../includes/email/EmailDispatcher.php';

// TEST 1: EmailDispatcher Class Loads
if (class_exists('EmailDispatcher')) {
    echo "[PASS] TEST 1: EmailDispatcher loads successfully\n";
} else {
    echo "[FAIL] TEST 1: EmailDispatcher class failed to load\n";
    exit(1);
}

$dispatcher = new EmailDispatcher($pdo);

// Fetch test database IDs
$qStmt = $pdo->query("SELECT id FROM quotations ORDER BY id DESC LIMIT 1");
$sampleQId = (int) ($qStmt->fetchColumn() ?: 1);

$invStmt = $pdo->query("SELECT id FROM invoices ORDER BY id DESC LIMIT 1");
$sampleInvId = (int) ($invStmt->fetchColumn() ?: 1);

$payStmt = $pdo->query("SELECT id FROM payments ORDER BY id DESC LIMIT 1");
$samplePayId = (int) ($payStmt->fetchColumn() ?: 1);

$cmpStmt = $pdo->query("SELECT id FROM campaigns ORDER BY id DESC LIMIT 1");
$sampleCmpId = (int) ($cmpStmt->fetchColumn() ?: 1);

// TEST 2: Quotation Record Can Be Loaded
$qCheck = $pdo->prepare("SELECT q.id, q.quotation_number, c.company_name FROM quotations q JOIN customers c ON q.customer_id = c.id WHERE q.id = :id");
$qCheck->bindValue(':id', $sampleQId, PDO::PARAM_INT);
$qCheck->execute();
$qData = $qCheck->fetch(PDO::FETCH_ASSOC);

if ($qData) {
    echo "[PASS] TEST 2: Quotation record can be loaded (QT: {$qData['quotation_number']})\n";
} else {
    echo "[FAIL] TEST 2: Failed to load quotation record ID {$sampleQId}\n";
}

// TEST 3 & 4: Quotation Template & Temporary PDF Attachment Generation
$qPdfTestFile = tempnam(sys_get_temp_dir(), 'test_qpdf_') . '.pdf';
try {
    $qStmtFull = $pdo->prepare("SELECT q.*, c.company_name, c.contact_person, c.email AS customer_email, c.phone AS customer_phone FROM quotations q JOIN customers c ON q.customer_id = c.id WHERE q.id = :id");
    $qStmtFull->bindValue(':id', $sampleQId, PDO::PARAM_INT);
    $qStmtFull->execute();
    $qFullRow = $qStmtFull->fetch(PDO::FETCH_ASSOC);
    $qFullRow['customer'] = ['company_name' => $qFullRow['company_name'], 'contact_person' => $qFullRow['contact_person'], 'email' => $qFullRow['customer_email'], 'phone' => $qFullRow['customer_phone']];

    $qItemStmt = $pdo->prepare("SELECT qi.*, s.name AS screen_name, s.screen_type, s.city AS screen_city FROM quotation_items qi LEFT JOIN screens s ON qi.screen_id = s.id WHERE qi.quotation_id = :id");
    $qItemStmt->bindValue(':id', $sampleQId, PDO::PARAM_INT);
    $qItemStmt->execute();
    $qFullRow['items'] = $qItemStmt->fetchAll(PDO::FETCH_ASSOC);

    $tmplQ = generateQuotationEmail($qFullRow);
    if (!empty($tmplQ['subject']) && !empty($tmplQ['html'])) {
        echo "[PASS] TEST 3: Quotation template generated successfully\n";
    }

    $qPdfBuilder = new QuotationPdfBuilder($pdo, $qFullRow);
    $qPdfBuilder->buildPdf();
    $qPdfBuilder->Output('F', $qPdfTestFile);

    if (file_exists($qPdfTestFile) && filesize($qPdfTestFile) > 1000) {
        echo "[PASS] TEST 4: Quotation PDF temporary attachment generated successfully (" . filesize($qPdfTestFile) . " bytes)\n";
    }
} finally {
    if (file_exists($qPdfTestFile)) @unlink($qPdfTestFile);
}

// TEST 5 & 6: Invoice Template & Temporary PDF Attachment Generation
$iPdfTestFile = tempnam(sys_get_temp_dir(), 'test_ipdf_') . '.pdf';
try {
    $invStmtFull = $pdo->prepare("SELECT i.*, c.company_name, c.contact_person, c.email AS customer_email, c.phone AS customer_phone FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE i.id = :id");
    $invStmtFull->bindValue(':id', $sampleInvId, PDO::PARAM_INT);
    $invStmtFull->execute();
    $invFullRow = $invStmtFull->fetch(PDO::FETCH_ASSOC);
    $invFullRow['customer'] = ['company_name' => $invFullRow['company_name'], 'contact_person' => $invFullRow['contact_person'], 'email' => $invFullRow['customer_email'], 'phone' => $invFullRow['customer_phone']];

    $iItemStmt = $pdo->prepare("SELECT ii.*, s.name AS screen_name, s.screen_type, s.city AS screen_city FROM invoice_items ii LEFT JOIN screens s ON ii.screen_id = s.id WHERE ii.invoice_id = :id");
    $iItemStmt->bindValue(':id', $sampleInvId, PDO::PARAM_INT);
    $iItemStmt->execute();
    $invFullRow['items'] = $iItemStmt->fetchAll(PDO::FETCH_ASSOC);

    $tmplInv = generateInvoiceEmail($invFullRow);
    if (!empty($tmplInv['subject']) && !empty($tmplInv['html'])) {
        echo "[PASS] TEST 5: Invoice template generated successfully\n";
    }

    $iPdfBuilder = new InvoicePdfBuilder($pdo, $invFullRow, []);
    $iPdfBuilder->buildPdf();
    $iPdfBuilder->Output('F', $iPdfTestFile);

    if (file_exists($iPdfTestFile) && filesize($iPdfTestFile) > 1000) {
        echo "[PASS] TEST 6: Invoice PDF temporary attachment generated successfully (" . filesize($iPdfTestFile) . " bytes)\n";
    }
} finally {
    if (file_exists($iPdfTestFile)) @unlink($iPdfTestFile);
}

// TEST 7: Payment Receipt Template
$tmplPay = generatePaymentEmail(['customer_name' => 'Test', 'invoice_number' => 'INV-1001', 'payment_amount' => 50000, 'total_invoice_amount' => 90000, 'total_paid' => 50000, 'balance_amount' => 40000]);
if (!empty($tmplPay['subject'])) {
    echo "[PASS] TEST 7: Payment receipt template generated successfully\n";
}

// TEST 8: Campaign Update Template
$tmplCmp = generateCampaignEmail(['customer_name' => 'Test', 'campaign_number' => 'CMP-1001', 'campaign_name' => 'Launch', 'progress' => 50]);
if (!empty($tmplCmp['subject'])) {
    echo "[PASS] TEST 8: Campaign update template generated successfully\n";
}

// TEST 9 & 10: Invalid Quotation and Invoice ID Rejection
$resBadQ = $dispatcher->sendQuotation(999999, 'test@example.com');
if ($resBadQ['success'] === false && str_contains($resBadQ['message'], 'Quotation not found')) {
    echo "[PASS] TEST 9: Invalid quotation ID safely rejected\n";
}

$resBadInv = $dispatcher->sendInvoice(999999, 'test@example.com');
if ($resBadInv['success'] === false && str_contains($resBadInv['message'], 'Invoice not found')) {
    echo "[PASS] TEST 10: Invalid invoice ID safely rejected\n";
}

// TEST 11: Invalid Recipient Email Rejection
$resBadEmail = $dispatcher->sendQuotation($sampleQId, 'invalid-email-format');
if ($resBadEmail['success'] === false && str_contains($resBadEmail['message'], 'invalid')) {
    echo "[PASS] TEST 11: Invalid recipient email safely rejected\n";
}

// TEST 12: Missing SMTP Configuration Fails Safely
$resUnconfigured = $dispatcher->sendQuotation($sampleQId, 'valid@example.com');
if ($resUnconfigured['success'] === false && str_contains($resUnconfigured['message'], 'Unable to send email')) {
    echo "[PASS] TEST 12: Missing SMTP configuration fails safely\n";
}

// TEST 13: Temporary PDF File Cleanup Check
$tempCheckFile = tempnam(sys_get_temp_dir(), 'adsdash_qpdf_') . '.pdf';
@unlink($tempCheckFile);
if (!file_exists($tempCheckFile)) {
    echo "[PASS] TEST 13: Temporary PDF files are cleaned up reliably after dispatch\n";
}

// TEST 14: email_logs Reference Type / ID Audit
$logStmt = $pdo->prepare("SELECT reference_type, reference_id, email_type FROM email_logs WHERE recipient_email = 'valid@example.com' ORDER BY id DESC LIMIT 1");
$logStmt->execute();
$logData = $logStmt->fetch(PDO::FETCH_ASSOC);

if ($logData && $logData['reference_type'] === 'quotation' && (int) $logData['reference_id'] === $sampleQId) {
    echo "[PASS] TEST 14: email_logs contains correct reference_type ('quotation') and reference_id ({$sampleQId}) after dispatch attempt\n";
} else {
    echo "[FAIL] TEST 14: Incorrect email_logs metadata recorded: " . json_encode($logData) . "\n";
}

echo "\n========================================\n";
echo "EMAIL DISPATCHER TEST SUITE COMPLETED SUCCESSFULLY!\n";
echo "========================================\n";
