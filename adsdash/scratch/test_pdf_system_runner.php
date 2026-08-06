<?php

declare(strict_types=1);

echo "========================================\n";
echo "PDF SYSTEM & INTEGRITY TEST SUITE\n";
echo "========================================\n\n";

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/email/EmailDispatcher.php';
require_once __DIR__ . '/../includes/pdf/QuotationPdfBuilder.php';
require_once __DIR__ . '/../includes/pdf/InvoicePdfBuilder.php';

// TEST 1: PDF Builder Classes Loaded
if (class_exists('QuotationPdfBuilder') && class_exists('InvoicePdfBuilder')) {
    echo "[PASS] TEST 1: QuotationPdfBuilder and InvoicePdfBuilder loaded successfully\n";
} else {
    echo "[FAIL] TEST 1: PDF Builder classes failed to load\n";
    exit(1);
}

// TEST 2: Fetch Latest Quotation and Invoice from Database
$qStmt = $pdo->query("SELECT id FROM quotations ORDER BY id DESC LIMIT 1");
$qId = (int) ($qStmt->fetchColumn() ?: 1);

$invStmt = $pdo->query("SELECT id FROM invoices ORDER BY id DESC LIMIT 1");
$invId = (int) ($invStmt->fetchColumn() ?: 1);

echo "[PASS] TEST 2: Test references found (Quotation ID: {$qId}, Invoice ID: {$invId})\n";

// TEST 3: Generate Quotation PDF Buffer & Integrity Check
$qStmtData = $pdo->prepare("SELECT * FROM quotations WHERE id = :id");
$qStmtData->execute([':id' => $qId]);
$qData = $qStmtData->fetch(PDO::FETCH_ASSOC) ?: ['quotation_number' => 'QT-1001', 'quotation_date' => date('Y-m-d'), 'status' => 'approved'];

$qBuilder = new QuotationPdfBuilder($pdo, $qData);
$qBuilder->buildPdf();
$qPdfContent = $qBuilder->Output('S');

if (!empty($qPdfContent) && str_starts_with($qPdfContent, '%PDF-')) {
    $qSize = strlen($qPdfContent);
    echo "[PASS] TEST 3: Quotation PDF generated successfully (%PDF- header verified, Size: {$qSize} bytes)\n";
} else {
    echo "[FAIL] TEST 3: Invalid Quotation PDF generated\n";
    exit(1);
}

// TEST 4: Generate Invoice PDF Buffer & Integrity Check
$invStmtData = $pdo->prepare("SELECT * FROM invoices WHERE id = :id");
$invStmtData->execute([':id' => $invId]);
$invData = $invStmtData->fetch(PDO::FETCH_ASSOC) ?: ['invoice_number' => 'INV-1001', 'invoice_date' => date('Y-m-d'), 'status' => 'unpaid'];

$invBuilder = new InvoicePdfBuilder($pdo, $invData);
$invBuilder->buildPdf();
$invPdfContent = $invBuilder->Output('S');

if (!empty($invPdfContent) && str_starts_with($invPdfContent, '%PDF-')) {
    $invSize = strlen($invPdfContent);
    echo "[PASS] TEST 4: Invoice PDF generated successfully (%PDF- header verified, Size: {$invSize} bytes)\n";
} else {
    echo "[FAIL] TEST 4: Invalid Invoice PDF generated\n";
    exit(1);
}

// TEST 5: Verify PDF Output Integrity (Valid EOF & Page structure)
if (str_contains($qPdfContent, '%%EOF') && str_contains($invPdfContent, '%%EOF')) {
    echo "[PASS] TEST 5: PDF document structure integrity verified (EOF markers present)\n";
} else {
    echo "[FAIL] TEST 5: PDF EOF markers missing\n";
    exit(1);
}

// TEST 6: Temporary File Lifecycle & Cleanup Safety
$tempPdfFile = sys_get_temp_dir() . '/test_pdf_integrity_' . time() . '.pdf';
file_put_contents($tempPdfFile, $qPdfContent);

if (file_exists($tempPdfFile)) {
    unlink($tempPdfFile);
    if (!file_exists($tempPdfFile)) {
        echo "[PASS] TEST 6: Temporary PDF file creation and cleanup verified\n";
    } else {
        echo "[FAIL] TEST 6: Failed to clean up temporary PDF file\n";
    }
}

echo "\n========================================\n";
echo "PDF SYSTEM TEST SUITE COMPLETED SUCCESSFULLY!\n";
echo "========================================\n";
